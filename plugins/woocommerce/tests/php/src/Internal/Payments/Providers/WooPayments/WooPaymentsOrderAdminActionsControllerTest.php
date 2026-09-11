<?php
/**
 * WooPaymentsOrderAdminActionsController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use RuntimeException;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderAdminActionsController class.
 */
class WooPaymentsOrderAdminActionsControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsOrderAdminActionsController|null
	 */
	private $sut;

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsOrderAdminActionsController ) {
			remove_filter( 'woocommerce_order_actions', array( $this->sut, 'handle_woocommerce_order_actions' ) );
			remove_action( 'woocommerce_order_action_capture_charge', array( $this->sut, 'handle_woocommerce_order_action_capture_charge' ) );
			remove_action( 'woocommerce_order_action_cancel_authorization', array( $this->sut, 'handle_woocommerce_order_action_cancel_authorization' ) );
			remove_action( 'woocommerce_order_status_completed', array( $this->sut, 'handle_woocommerce_order_status_completed' ) );
			remove_action( 'woocommerce_order_status_cancelled', array( $this->sut, 'handle_woocommerce_order_status_cancelled' ) );
		}

		global $theorder;
		$theorder = null;

		parent::tearDown();
	}

	/**
	 * @testdox Register should wire each order workflow hook once when native owns the runtime.
	 */
	public function test_registers_order_workflow_hooks_idempotently_for_native_runtime(): void {
		$this->sut = $this->create_controller( true );

		$this->sut->register();
		$this->sut->register();

		$this->assertSame( 10, has_filter( 'woocommerce_order_actions', array( $this->sut, 'handle_woocommerce_order_actions' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_action_capture_charge', array( $this->sut, 'handle_woocommerce_order_action_capture_charge' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_action_cancel_authorization', array( $this->sut, 'handle_woocommerce_order_action_cancel_authorization' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_completed', array( $this->sut, 'handle_woocommerce_order_status_completed' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_order_status_cancelled', array( $this->sut, 'handle_woocommerce_order_status_cancelled' ) ) );
	}

	/**
	 * @testdox Register should wire no order workflow hooks when the plugin owns the runtime.
	 */
	public function test_registers_nothing_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false );

		$this->sut->register();

		$this->assertFalse( has_filter( 'woocommerce_order_actions', array( $this->sut, 'handle_woocommerce_order_actions' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_action_capture_charge', array( $this->sut, 'handle_woocommerce_order_action_capture_charge' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_action_cancel_authorization', array( $this->sut, 'handle_woocommerce_order_action_cancel_authorization' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_completed', array( $this->sut, 'handle_woocommerce_order_status_completed' ) ) );
		$this->assertFalse( has_action( 'woocommerce_order_status_cancelled', array( $this->sut, 'handle_woocommerce_order_status_cancelled' ) ) );
	}

	/**
	 * @testdox Authorized base and split-gateway orders should expose capture and cancel actions before existing actions.
	 * @dataProvider eligible_gateway_provider
	 *
	 * @param string $gateway_id Payment gateway ID.
	 */
	public function test_adds_actions_for_eligible_woopayments_gateway_orders( string $gateway_id ): void {
		global $theorder;
		$theorder  = $this->create_authorized_order( $gateway_id );
		$this->sut = $this->create_controller( true );

		$actions = $this->sut->handle_woocommerce_order_actions( array( 'email_order_details' => 'Email order details' ) );

		$this->assertSame(
			array(
				'capture_charge'       => 'Capture charge',
				'cancel_authorization' => 'Cancel authorization',
				'email_order_details'  => 'Email order details',
			),
			$actions
		);
	}

	/**
	 * Data provider for eligible WooPayments gateway IDs.
	 *
	 * @return array<string,array{string}>
	 */
	public static function eligible_gateway_provider(): array {
		return array(
			'base gateway'  => array( OrderPaymentStore::GATEWAY_ID ),
			'split gateway' => array( OrderPaymentStore::GATEWAY_ID . '_link' ),
		);
	}

	/**
	 * @testdox Ineligible orders should preserve existing actions without adding authorization actions.
	 * @dataProvider ineligible_order_provider
	 *
	 * @param string $gateway_id    Payment gateway ID.
	 * @param string $intent_status Intent status.
	 * @param string $order_status  Order status.
	 */
	public function test_preserves_actions_for_ineligible_orders( string $gateway_id, string $intent_status, string $order_status ): void {
		global $theorder;
		$theorder  = $this->create_authorized_order( $gateway_id, $intent_status, $order_status );
		$this->sut = $this->create_controller( true );
		$existing  = array( 'email_order_details' => 'Email order details' );

		$this->assertSame( $existing, $this->sut->handle_woocommerce_order_actions( $existing ) );
	}

	/**
	 * Data provider for ineligible orders.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public static function ineligible_order_provider(): array {
		return array(
			'other gateway'    => array( 'cod', 'requires_capture', 'on-hold' ),
			'already captured' => array( OrderPaymentStore::GATEWAY_ID, 'succeeded', 'on-hold' ),
			'paid order'       => array( OrderPaymentStore::GATEWAY_ID, 'requires_capture', 'processing' ),
		);
	}

	/**
	 * @testdox A missing or invalid global order should preserve existing actions.
	 */
	public function test_preserves_actions_when_global_order_is_invalid(): void {
		global $theorder;
		$theorder  = new \stdClass();
		$this->sut = $this->create_controller( true );
		$existing  = array( 'email_order_details' => 'Email order details' );

		$this->assertSame( $existing, $this->sut->handle_woocommerce_order_actions( $existing ) );
	}

	/**
	 * @testdox Manual capture should delegate the full order total and retain shared success effects.
	 */
	public function test_manual_capture_delegates_full_order_total_and_retains_success_effects(): void {
		$order                = $this->create_authorized_order();
		$last_capture_context = null;
		$processing_service   = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willReturnCallback(
				static function ( PaymentContext $context ) use ( &$last_capture_context ): PaymentOutcome {
					$last_capture_context = $context;
					$context->get_order()->add_order_note( 'Shared capture success note.' );
					$context->get_order()->update_meta_data( '_intention_status', 'succeeded' );
					$context->get_order()->save_meta_data();

					return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture' );
				}
			);
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );

		$this->assertInstanceOf( PaymentContext::class, $last_capture_context );
		$this->assertSame( $order->get_id(), $last_capture_context->get_order_id() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $last_capture_context->get_gateway_id() );
		$this->assertSame( (float) $order->get_total(), $last_capture_context->get_amount() );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $order, 'Shared capture success note.' );
	}

	/**
	 * @testdox Manual cancellation should delegate through the shared cancel context.
	 */
	public function test_manual_cancel_delegates_through_shared_processing(): void {
		$order               = $this->create_authorized_order();
		$last_cancel_context = null;
		$processing_service  = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'cancel' )
			->willReturnCallback(
				static function ( PaymentContext $context ) use ( &$last_cancel_context ): PaymentOutcome {
					$last_cancel_context = $context;

					return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_cancel' );
				}
			);
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_cancel_authorization', $order );

		$this->assertInstanceOf( PaymentContext::class, $last_cancel_context );
		$this->assertSame( $order->get_id(), $last_cancel_context->get_order_id() );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $last_cancel_context->get_gateway_id() );
	}

	/**
	 * @testdox Completing an authorized order should trigger the same full-total capture path.
	 */
	public function test_completed_status_triggers_capture(): void {
		$order                = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID, 'requires_capture', 'completed' );
		$last_capture_context = null;
		$processing_service   = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willReturnCallback(
				static function ( PaymentContext $context ) use ( &$last_capture_context ): PaymentOutcome {
					$last_capture_context = $context;

					return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_capture' );
				}
			);
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action(
			'woocommerce_order_status_completed',
			$order->get_id(),
			$order,
			array(
				'from' => 'on-hold',
				'to'   => 'completed',
			)
		);

		$this->assertInstanceOf( PaymentContext::class, $last_capture_context );
		$this->assertSame( (float) $order->get_total(), $last_capture_context->get_amount() );
	}

	/**
	 * @testdox Cancelling an authorized order should trigger the shared cancel path.
	 */
	public function test_cancelled_status_triggers_cancel(): void {
		$order               = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID . '_link', 'requires_capture', 'cancelled' );
		$last_cancel_context = null;
		$processing_service  = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'cancel' )
			->willReturnCallback(
				static function ( PaymentContext $context ) use ( &$last_cancel_context ): PaymentOutcome {
					$last_cancel_context = $context;

					return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_cancel' );
				}
			);
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action(
			'woocommerce_order_status_cancelled',
			$order->get_id(),
			$order,
			array(
				'from' => 'on-hold',
				'to'   => 'cancelled',
			)
		);

		$this->assertInstanceOf( PaymentContext::class, $last_cancel_context );
		$this->assertSame( $order->get_id(), $last_cancel_context->get_order_id() );
	}

	/**
	 * @testdox Automatic status actions should ignore captured and other-gateway orders.
	 */
	public function test_status_actions_ignore_ineligible_orders(): void {
		$captured_order     = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID, 'succeeded', 'completed' );
		$foreign_order      = $this->create_authorized_order( 'cod', 'requires_capture', 'cancelled' );
		$processing_service = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->never() )->method( 'capture' );
		$processing_service->expects( $this->never() )->method( 'cancel' );
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_completed', $captured_order->get_id(), $captured_order, array() );
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_cancelled', $foreign_order->get_id(), $foreign_order, array() );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox A shared capture failure should leave an authorized order on hold and retain its failure note.
	 */
	public function test_capture_failure_preserves_authorized_order_and_failure_note(): void {
		$order     = $this->create_authorized_order();
		$provider  = $this->create_fixed_outcome_provider(
			new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'pi_authorized',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_NOTE => 'Shared capture failure note.' )
			)
		);
		$this->sut = $this->create_controller( true, wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'on-hold', $reloaded->get_status() );
		$this->assertSame( 'requires_capture', $reloaded->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $reloaded, 'Shared capture failure note.' );
	}

	/**
	 * @testdox An automatic cancel failure should preserve status, add the generic oracle note, and not escape.
	 */
	public function test_automatic_cancel_failure_preserves_status_and_adds_generic_note(): void {
		$order     = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID, 'requires_capture', 'cancelled' );
		$provider  = $this->create_fixed_outcome_provider( new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_authorized' ) );
		$this->sut = $this->create_controller( true, wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_cancelled', $order->get_id(), $order, array() );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'cancelled', $reloaded->get_status() );
		$this->assertSame( 'requires_capture', $reloaded->get_meta( '_intention_status', true ) );
		$this->assert_order_has_note_containing( $reloaded, 'Canceling authorization <strong>failed</strong> to complete.' );
	}

	/**
	 * @testdox Automatic capture exceptions should add the generic oracle note without escaping the status hook.
	 */
	public function test_automatic_capture_exception_adds_generic_note_without_escaping(): void {
		$order              = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID, 'requires_capture', 'completed' );
		$processing_service = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willThrowException( new RuntimeException( 'Simulated capture exception.' ) );
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce status hook.
		do_action( 'woocommerce_order_status_completed', $order->get_id(), $order, array() );

		$this->assert_order_has_note_containing( $order, 'Capture authorization <strong>failed</strong> to complete.' );
	}

	/**
	 * @testdox Manual capture exceptions should leave a generic failure note instead of failing silently.
	 */
	public function test_manual_capture_exception_adds_generic_note(): void {
		$order              = $this->create_authorized_order();
		$processing_service = $this->createMock( PaymentProcessingService::class );
		$processing_service->expects( $this->once() )
			->method( 'capture' )
			->willThrowException( new RuntimeException( 'Simulated capture exception.' ) );
		$this->sut = $this->create_controller( true, $processing_service );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );

		$this->assert_order_has_note_containing( $order, 'Capture authorization <strong>failed</strong> to complete.' );
	}

	/**
	 * Create the controller with explicit dependencies.
	 *
	 * @param bool                          $native_owner       Whether native owns the runtime.
	 * @param PaymentProcessingService|null $processing_service Processing service override.
	 * @param WooPaymentsProvider|null      $provider           Provider override.
	 * @return WooPaymentsOrderAdminActionsController
	 */
	private function create_controller( bool $native_owner, ?PaymentProcessingService $processing_service = null, ?WooPaymentsProvider $provider = null ): WooPaymentsOrderAdminActionsController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_owner );

		$controller = new WooPaymentsOrderAdminActionsController();
		$controller->init(
			$arbiter,
			$processing_service ?? $this->createMock( PaymentProcessingService::class ),
			$provider ?? new WooPaymentsProvider()
		);

		return $controller;
	}

	/**
	 * Create a saved order with the requested authorization facts.
	 *
	 * @param string $gateway_id    Payment gateway ID.
	 * @param string $intent_status Intent status.
	 * @param string $order_status  Order status.
	 * @return WC_Order
	 */
	private function create_authorized_order( string $gateway_id = OrderPaymentStore::GATEWAY_ID, string $intent_status = 'requires_capture', string $order_status = 'on-hold' ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $gateway_id );
		$order->set_currency( 'USD' );
		$order->set_total( '42.50' );
		$order->set_status( $order_status );
		$order->set_transaction_id( 'pi_authorized' );
		$order->update_meta_data( '_intent_id', 'pi_authorized' );
		$order->update_meta_data( '_intention_status', $intent_status );
		$order->save();

		return $order;
	}

	/**
	 * Create a WooPayments provider that returns a fixed capture/cancel outcome.
	 *
	 * @param PaymentOutcome $fixed_outcome Fixed operation outcome.
	 * @return WooPaymentsProvider
	 */
	private function create_fixed_outcome_provider( PaymentOutcome $fixed_outcome ): WooPaymentsProvider {
		return new class( $fixed_outcome ) extends WooPaymentsProvider {
			/**
			 * Fixed operation outcome.
			 *
			 * @var PaymentOutcome
			 */
			private PaymentOutcome $fixed_outcome;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome $fixed_outcome Fixed operation outcome.
			 */
			public function __construct( PaymentOutcome $fixed_outcome ) {
				$this->fixed_outcome = $fixed_outcome;
			}

			/**
			 * Return the WooPayments provider ID.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return OrderPaymentStore::GATEWAY_ID;
			}

			/**
			 * Return the WooPayments persistence profile.
			 *
			 * @return ProviderPersistenceProfile
			 */
			public function get_persistence_profile(): ProviderPersistenceProfile {
				return new WooPaymentsPersistenceProfile();
			}

			/**
			 * Return the fixed capture outcome.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->fixed_outcome;
			}

			/**
			 * Return the fixed cancel outcome.
			 *
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				unset( $context, $idempotency_key );

				return $this->fixed_outcome;
			}

			/**
			 * Preserve the fixed outcome through provider-effect application.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $operation );

				return $outcome;
			}

			/**
			 * Skip post-lifecycle effects for the fixed outcome.
			 *
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 */
			public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void {
				unset( $context, $outcome, $operation );
			}
		};
	}

	/**
	 * Assert that an order has a note containing text.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $expected Expected note fragment.
	 */
	private function assert_order_has_note_containing( WC_Order $order, string $expected ): void {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( (string) $note->content, $expected ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( "Missing order note containing: {$expected}" );
	}
}
