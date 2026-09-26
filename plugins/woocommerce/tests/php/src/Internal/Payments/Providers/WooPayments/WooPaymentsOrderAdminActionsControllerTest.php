<?php
/**
 * WooPaymentsOrderAdminActionsController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderInternalStatus;
use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingProvider;
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
	 * @testdox Saving Cancelled for a captured order persists one status change without provider action.
	 */
	public function test_order_data_save_cancels_captured_order_without_provider_action(): void {
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';
		require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-order-data.php';

		$order              = $this->create_authorized_order( OrderPaymentStore::GATEWAY_ID, 'succeeded', OrderStatus::PROCESSING );
		$recording_provider = new RecordingProvider( new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_authorized' ) );
		$provider           = new class( $recording_provider ) extends WooPaymentsProvider {
			/** @var RecordingProvider */
			private RecordingProvider $recorder;

			/**
			 * @param RecordingProvider $recorder Recording provider.
			 */
			public function __construct( RecordingProvider $recorder ) {
				$this->recorder = $recorder;
			}

			/**
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				return $this->recorder->cancel( $context, $idempotency_key );
			}

			/**
			 * @param PaymentContext $context         Payment context.
			 * @param string         $idempotency_key Idempotency key.
			 * @return PaymentOutcome
			 */
			public function refund( PaymentContext $context, string $idempotency_key ): PaymentOutcome {
				return $this->recorder->refund( $context, $idempotency_key );
			}

			/**
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				return $outcome;
			}

			/**
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation.
			 */
			public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void {
			}
		};
		$this->sut          = $this->create_controller( true, wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();
		$status_hook_count = did_action( 'woocommerce_order_status_cancelled' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Simulates the nonce-verified order meta box save.
		$original_post = $_POST;

		$_POST = array(
			'order_status'    => OrderInternalStatus::CANCELLED,
			'_payment_method' => $order->get_payment_method(),
			'customer_user'   => 0,
		);

		try {
			\WC_Meta_Box_Order_Data::save( $order->get_id() );
		} finally {
			$_POST = $original_post;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$stored_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stored_order );
		$this->assertSame( OrderStatus::CANCELLED, $stored_order->get_status() );
		$this->assertSame( $status_hook_count + 1, did_action( 'woocommerce_order_status_cancelled' ) );
		$this->assertSame( 0, $recording_provider->cancel_calls );
		$this->assertSame( 0, $recording_provider->refund_calls );
		$this->assertCount( 0, $stored_order->get_refunds() );
		$this->assertSame( 0.0, (float) $stored_order->get_total_refunded() );
		$status_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static function ( $note ): bool {
				return str_contains( (string) $note->content, 'Order status changed from Processing to Cancelled.' );
			}
		);
		$this->assertCount( 1, $status_notes );
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
	 * @testdox The capture-charge order action should send one capture request for the authorized total and mark the order processing.
	 *
	 * T.3 Task 3 (`plan-task-t3.md`): joins the request-shape half
	 * ({@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapterTest::test_capture_sends_context_amount_to_native_transport})
	 * and the on-hold half ({@see \Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLifecycleServiceTest::test_authorized_event_moves_order_on_hold})
	 * by driving the real "Capture charge" order action through
	 * {@see WooPaymentsOrderAdminActionsController}, {@see PaymentProcessingService::capture},
	 * the real {@see WooPaymentsProvider}, {@see WooPaymentsProviderGatewayAdapter} and
	 * {@see WooPaymentsApiClient} over a fake transport queued with REC-CAP's recorded
	 * `capture_full_amount` response (`Fixtures/rec-t3-manual-capture.json`,
	 * `data/rec-t3-api-recordings.md`): a 10.99 USD manual-capture authorization captured
	 * for its full amount. Client parity: the capture note is the exact `was
	 * <strong>successfully captured</strong> using WooPayments` wording plus the intent id
	 * (`os:2226-2236`).
	 *
	 * The no-repeat-POST assertion below is NOT a client-parity claim. The client 11.1.0
	 * only hides the "Capture charge" order action once the intention leaves
	 * `requires_capture` (`gw:3938`); its own `capture_charge()` handler (`gw:562`
	 * wires it to the hook, `gw:3964` is the handler body) carries no status guard of its
	 * own and would re-issue the capture request to Stripe if invoked directly. Native's
	 * eligibility guard sits inside the handler itself
	 * ({@see WooPaymentsOrderAdminActionsController::is_authorized_woopayments_order()},
	 * controller `:120`, `:198-207`), which is stricter than the client. This is a T.7
	 * note (native-only hardening, not a parity gap): the client's note-once dedup
	 * (`os:1663`) is what it relies on instead. Because the controller guard stops the
	 * repeated order action before any capture code runs, it cannot exercise that dedup;
	 * the actual client-parity claim is proven separately below by calling
	 * {@see PaymentProcessingService::capture()} directly a second time (bypassing the
	 * controller), which drives a genuine second capture through the same stack and
	 * proves the note stays at one.
	 */
	public function test_capture_charge_action_captures_authorized_total_once_and_marks_processing(): void {
		$order = $this->create_recorded_manual_capture_order();

		$recorded_capture      = $this->load_recorded_manual_capture_entry( 'capture_full_amount' );
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->response = array(
			'response' => array( 'code' => $recorded_capture['http_status'] ),
			'headers'  => array( 'content-type' => $recorded_capture['content_type'] ),
			'body'     => wp_json_encode( $recorded_capture['body'] ),
		);

		$provider  = $this->create_native_provider_over_fake_transport( $http_client );
		$this->sut = $this->create_controller( true, wc_get_container()->get( PaymentProcessingService::class ), $provider );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );

		$this->assertCount( 1, $http_client->requests, 'The capture action must dispatch exactly one transport request.' );
		$this->assertSame( 'POST', $http_client->requests[0]['method'] );
		$this->assertStringContainsString( 'intentions/pi_3UJhOgBzWlxcwgpP0AOgQE2z/capture', $http_client->requests[0]['path'] );

		$sent_body = json_decode( (string) $http_client->requests[0]['body'], true );
		$this->assertIsArray( $sent_body, 'The request body sent over the fake transport must be valid JSON.' );
		$this->assertSame( 1099, $sent_body['amount_to_capture'] ?? null, 'The capture request must send the recorded full-total minor-unit amount.' );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( OrderStatus::PROCESSING, $order->get_status(), 'The captured order must move to processing.' );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );

		$capture_note_matches = static fn( $note ): bool =>
			str_contains( $note->content, 'was <strong>successfully captured</strong> using WooPayments' )
			&& str_contains( $note->content, 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );

		$capture_notes = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes, "Exactly one success note matching the client's `was <strong>successfully captured</strong> using WooPayments` wording (os:2226-2236) and the recorded intent id should reference the capture." );

		// A second invocation on the now-settled order sends no second POST. This is a
		// NATIVE-ONLY guard, not a client-parity claim (see the class docblock above):
		// the client 11.1.0 handler has no status check of its own and would call Stripe
		// again; only its order-actions list hides the action once the intention leaves
		// `requires_capture` (gw:3938). The client-parity claim for a repeated invocation
		// is instead its note-once dedup (os:1663), asserted below.
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising the public WooCommerce order action hook.
		do_action( 'woocommerce_order_action_capture_charge', $order );
		$this->assertCount( 1, $http_client->requests, 'A second capture invocation on an already-captured order must not repeat the POST (native-only guard, not client parity).' );

		$capture_notes_after_repeat = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes_after_repeat, "The capture note stays exactly one after the second invocation, matching the client's note-once dedup (os:1663)." );

		// The controller guard above stops the repeat before any capture code runs at
		// all, so it cannot exercise WooPaymentsOrderNoteService::add_note_once()'s
		// dedup. Call PaymentProcessingService::capture() directly, bypassing the
		// controller, to drive a genuine second capture through the same real
		// provider/adapter/API client stack (fed the same recorded response again),
		// and prove the client's note-once dedup (os:1663) for real.
		$second_outcome = wc_get_container()->get( PaymentProcessingService::class )->capture(
			PaymentContext::for_capture( $order, OrderPaymentStore::GATEWAY_ID, (float) $order->get_total() ),
			$provider
		);

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $second_outcome->get_status(), 'The direct second capture call must still complete against the recorded response.' );
		$this->assertCount( 2, $http_client->requests, 'A direct second capture call, bypassing the controller guard, must dispatch its own transport request.' );

		$capture_notes_after_direct_repeat = array_values( array_filter( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), $capture_note_matches ) );
		$this->assertCount( 1, $capture_notes_after_direct_repeat, "A genuine second capture must not duplicate the capture note, matching the client's note-once dedup (os:1663)." );
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

	/**
	 * Create a saved order matching REC-CAP's authorized state: on-hold, manual-capture
	 * intent `requires_capture`, a physical line item so the captured order needs
	 * processing (matching the client's post-capture status).
	 *
	 * @return WC_Order
	 */
	private function create_recorded_manual_capture_order(): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->add_product( \WC_Helper_Product::create_simple_product(), 1 );
		$order->set_total( '10.99' );
		$order->set_status( 'on-hold' );
		$order->set_transaction_id( 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intent_id', 'pi_3UJhOgBzWlxcwgpP0AOgQE2z' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();

		return $order;
	}

	/**
	 * Load one REC-CAP recorded entry's HTTP status, content type and response body by pair key.
	 *
	 * @param string $pair Fixture pair key (`authorize_manual_capture` or `capture_full_amount`).
	 * @return array{http_status:int,content_type:string,body:array<string,mixed>}
	 */
	private function load_recorded_manual_capture_entry( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$contents = file_get_contents( __DIR__ . '/Fixtures/rec-t3-manual-capture.json' );
		$this->assertIsString( $contents );
		$decoded = json_decode( $contents, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return array(
					'http_status'  => (int) $entry['response']['http_status'],
					'content_type' => (string) $entry['response']['content_type'],
					'body'         => $entry['response']['body'],
				);
			}
		}

		$this->fail( "REC-CAP fixture has no entry for pair '$pair'." );
	}

	/**
	 * Build a real WooPayments provider wired to the real gateway adapter and API client
	 * over a fake transport, the pattern used by
	 * {@see \Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsProviderGatewayAdapterTest::create_provider_over_fake_transport()}.
	 * The legacy gateway bridge is left unset because native transport (the fake client's
	 * default `is_available()` true) always takes precedence for capture.
	 *
	 * @param FakeWooPaymentsHttpClient $http_client Fake transport queued with the recorded response(s).
	 * @return WooPaymentsProvider
	 */
	private function create_native_provider_over_fake_transport( FakeWooPaymentsHttpClient $http_client ): WooPaymentsProvider {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'get_cached_account_data', 'get_account_default_currency', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key, $fallback = null ) => $fallback
		);
		$account_service->method( 'get_cached_account_data' )->willReturn( array( 'country' => 'US' ) );
		$account_service->method( 'get_account_default_currency' )->willReturn( 'usd' );
		$account_service->method( 'get_account_country' )->willReturn( 'US' );

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $account_service );

		$legacy_runtime = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyProxyWithGateway( null ) );

		$adapter = new WooPaymentsProviderGatewayAdapter();
		$adapter->init(
			$legacy_runtime,
			$api_client,
			$this->createMock( WooPaymentsCustomerService::class ),
			$this->createMock( WooPaymentsIntentRequestBuilder::class ),
			$account_service,
			new WooPaymentsOrderDataService(),
			wc_get_container()->get( WooPaymentsOrderNoteService::class ),
			$this->createMock( WooPaymentsSettingsService::class )
		);

		$provider = new WooPaymentsProvider();
		$provider->init(
			$adapter,
			$api_client,
			$account_service,
			null,
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $provider;
	}
}
