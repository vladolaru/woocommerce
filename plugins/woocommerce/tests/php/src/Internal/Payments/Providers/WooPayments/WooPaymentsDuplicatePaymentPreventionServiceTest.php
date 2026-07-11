<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDuplicatePaymentPreventionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use WC_Order;
use WC_Payment_Gateway;
use WC_Unit_Test_Case;
use WP_Error;

/**
 * Tests for the WooPaymentsDuplicatePaymentPreventionService class.
 */
class WooPaymentsDuplicatePaymentPreventionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should redirect duplicate pending orders to a paid session order with matching cart content.
	 */
	public function test_check_against_session_processing_order_redirects_to_paid_matching_session_order(): void {
		$session        = $this->create_session();
		$sut            = $this->create_service( $session );
		$same_cart_hash = 'same-cart-hash';
		$customer_id    = self::factory()->user->create();
		$session_order  = $this->create_order( $same_cart_hash, 'completed', $customer_id );
		$current_order  = $this->create_order( $same_cart_hash, 'pending', $customer_id );
		$current_id     = $current_order->get_id();
		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $session_order->get_id() );

		$result = $sut->check_against_session_processing_order( $current_order, $this->create_gateway() );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_paid_for_previous_order=yes', $result['redirect'] );
		$this->assertStringContainsString( (string) $session_order->get_id(), $result['redirect'] );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );

		$notes = wc_get_order_notes( array( 'order_id' => $session_order->get_id() ) );
		$this->assertStringContainsString( 'detected and deleted order ID ' . $current_id, (string) $notes[0]->content );

		$deleted_order = wc_get_order( $current_id );
		$this->assertInstanceOf( WC_Order::class, $deleted_order );
		$this->assertSame( 'trash', $deleted_order->get_status() );
	}

	/**
	 * @testdox Should continue processing when the session order is not a paid matching duplicate.
	 *
	 * @dataProvider session_processing_order_mismatch_data
	 *
	 * @param string $session_cart_hash Session order cart hash.
	 * @param string $session_status    Session order status.
	 * @param string $current_cart_hash Current order cart hash.
	 */
	public function test_check_against_session_processing_order_returns_null_for_mismatches( string $session_cart_hash, string $session_status, string $current_cart_hash ): void {
		$session       = $this->create_session();
		$sut           = $this->create_service( $session );
		$customer_id   = self::factory()->user->create();
		$session_order = $this->create_order( $session_cart_hash, $session_status, $customer_id );
		$current_order = $this->create_order( $current_cart_hash, 'pending', $customer_id );
		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $session_order->get_id() );

		$result = $sut->check_against_session_processing_order( $current_order, $this->create_gateway() );

		$this->assertNull( $result );
	}

	/**
	 * Data provider for session-order mismatch cases.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function session_processing_order_mismatch_data(): array {
		return array(
			'different cart hash with completed session order' => array( 'session-hash', 'completed', 'current-hash' ),
			'different cart hash with processing session order' => array( 'session-hash', 'processing', 'current-hash' ),
			'same cart hash with pending session order'   => array( 'same-hash', 'pending', 'same-hash' ),
			'same cart hash with cancelled session order' => array( 'same-hash', 'cancelled', 'same-hash' ),
		);
	}

	/**
	 * @testdox Should store and remove the processing order ID in the extension-compatible session key.
	 */
	public function test_session_processing_order_roundtrip_uses_extension_session_key(): void {
		$session = $this->create_session();
		$sut     = $this->create_service( $session );

		$sut->maybe_update_session_processing_order( 123 );
		$this->assertSame( 123, $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );

		$sut->remove_session_processing_order( 456 );
		$this->assertSame( 123, $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );

		$sut->remove_session_processing_order( 123 );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );
	}

	/**
	 * @testdox Should clear the processing order when WooCommerce completes its payment lifecycle.
	 */
	public function test_payment_complete_hook_clears_the_matching_processing_order(): void {
		$session = $this->create_session();
		$sut     = $this->create_service( $session );
		$order   = $this->create_order();
		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $order->get_id() );
		$enable_native = static fn(): bool => true;
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $enable_native );

		$this->assertTrue( method_exists( $sut, 'register' ), 'The duplicate-payment service should own its completion hook.' );

		try {
			$sut->register();
			$this->assertNotFalse( has_action( 'woocommerce_payment_complete', array( $sut, 'handle_woocommerce_payment_complete' ) ) );

			do_action( 'woocommerce_payment_complete', $order->get_id() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercise the registered completion callback.

			$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );
		} finally {
			remove_action( 'woocommerce_payment_complete', array( $sut, 'handle_woocommerce_payment_complete' ) );
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $enable_native );
		}
	}

	/**
	 * @testdox Should not register session cleanup when native does not own the payments runtime.
	 */
	public function test_register_skips_payment_complete_hook_when_native_does_not_own_runtime(): void {
		$sut            = $this->create_service();
		$disable_native = static fn(): bool => false;
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $disable_native );

		try {
			$sut->register();

			$this->assertFalse( has_action( 'woocommerce_payment_complete', array( $sut, 'handle_woocommerce_payment_complete' ) ) );
		} finally {
			remove_action( 'woocommerce_payment_complete', array( $sut, 'handle_woocommerce_payment_complete' ) );
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $disable_native );
		}
	}

	/**
	 * @testdox Should ignore orders without an attached PaymentIntent ID.
	 */
	public function test_check_payment_intent_attached_to_order_succeeded_ignores_missing_or_setup_intents(): void {
		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->never() )
			->method( 'get_payment_intention' );

		$sut   = $this->create_service( $this->create_session(), $api_client );
		$order = $this->create_order();
		$order->update_meta_data( '_intent_id', 'seti_existing' );
		$order->save();

		$this->assertNull( $sut->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() ) );
	}

	/**
	 * @testdox Should apply a successful attached PaymentIntent and redirect without creating another charge.
	 */
	public function test_check_payment_intent_attached_to_order_succeeded_returns_redirect_and_applies_lifecycle(): void {
		$session = $this->create_session();
		$order   = $this->create_order( 'hash', 'pending' );
		$order->set_total( '12.00' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();
		$session->set( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER, $order->get_id() );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_existing' )
			->willReturn( $this->create_intent_response( $order, 'succeeded', 1200 ) );

		$sut = $this->create_service( $session, $api_client );

		$result = $sut->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] );
		$this->assertNull( $session->get( WooPaymentsDuplicatePaymentPreventionService::SESSION_KEY_PROCESSING_ORDER ) );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertContains( $order->get_status(), wc_get_is_paid_statuses() );
		$this->assertSame( 'pi_existing', $order->get_transaction_id() );
		$this->assertSame( 'ch_existing', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'pm_existing', $order->get_meta( '_payment_method_id', true ) );
		$this->assertStringContainsString(
			'successfully charged',
			implode( ' ', array_map( static fn( object $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) ) )
		);
	}

	/**
	 * @testdox Attached intent recovery does not persist effects when lifecycle ownership is unavailable.
	 */
	public function test_attached_intent_does_not_persist_effects_before_lifecycle_application(): void {
		$order = $this->create_order( 'hash', 'pending' );
		$order->set_payment_method_title( 'Card' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client->method( 'get_payment_intention' )
			->willReturn( $this->create_intent_response( $order, 'succeeded', 1200 ) );
		$lifecycle_service = $this->getMockBuilder( OrderPaymentLifecycleService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'apply' ) )
			->getMock();
		$lifecycle_service->expects( $this->once() )->method( 'apply' );

		$result   = $this->create_service( $this->create_session(), $api_client, $lifecycle_service )
			->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() );
		$reloaded = wc_get_order( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertInstanceOf( WC_Order::class, $reloaded );
		$this->assertSame( 'Card', $reloaded->get_payment_method_title() );
		$this->assertSame( '', $reloaded->get_meta( '_charge_id', true ) );
	}

	/**
	 * @testdox Authorized attached PaymentIntent statuses redirect without creating another charge.
	 *
	 * @dataProvider authorized_attached_intent_statuses
	 *
	 * @param string $intent_status Provider intent status.
	 */
	public function test_check_payment_intent_attached_to_order_succeeded_redirects_for_every_authorized_status( string $intent_status ): void {
		$order = $this->create_order( 'hash', 'pending' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_existing' )
			->willReturn( $this->create_intent_response( $order, $intent_status, 1200 ) );

		$result = $this->create_service( $this->create_session(), $api_client )
			->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertSame( 'success', $result['result'] );
		$this->assertStringContainsString( 'wcpay_previous_successful_intent=yes', $result['redirect'] );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'pi_existing', $order->get_transaction_id() );
		$this->assertSame( $intent_status, $order->get_meta( '_intention_status', true ) );
	}

	/**
	 * Authorized attached intent statuses not already covered by the succeeded-specific test.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function authorized_attached_intent_statuses(): array {
		return array(
			'processing'       => array( 'processing' ),
			'requires capture' => array( 'requires_capture' ),
		);
	}

	/**
	 * @testdox Non-authorized or wrong-order attached PaymentIntents continue normal processing.
	 *
	 * @dataProvider invalid_attached_intent_data
	 *
	 * @param string $intent_status       Provider intent status.
	 * @param bool   $use_current_order_id Whether intent metadata owns the current order.
	 */
	public function test_check_payment_intent_attached_to_order_succeeded_rejects_invalid_status_or_order_ownership( string $intent_status, bool $use_current_order_id ): void {
		$order = $this->create_order( 'hash', 'pending' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();
		$intent                         = $this->create_intent_response( $order, $intent_status, 1200 );
		$intent['metadata']['order_id'] = $use_current_order_id ? (string) $order->get_id() : (string) ( $order->get_id() + 1 );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->expects( $this->once() )
			->method( 'get_payment_intention' )
			->with( 'pi_existing' )
			->willReturn( $intent );

		$result = $this->create_service( $this->create_session(), $api_client )
			->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() );
		$order  = wc_get_order( $order->get_id() );

		$this->assertNull( $result );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( '', $order->get_transaction_id() );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/**
	 * Invalid attached intent status and ownership fixtures from the extension contract.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function invalid_attached_intent_data(): array {
		return array(
			'requires action for current order' => array( 'requires_action', true ),
			'requires action for another order' => array( 'requires_action', false ),
			'succeeded for another order'       => array( 'succeeded', false ),
		);
	}

	/**
	 * @testdox Should return an amount-mismatch error when the attached PaymentIntent total differs from the order total.
	 */
	public function test_check_payment_intent_attached_to_order_succeeded_returns_amount_mismatch_error(): void {
		$order = $this->create_order( 'hash', 'pending' );
		$order->set_total( '15.00' );
		$order->update_meta_data( '_intent_id', 'pi_existing' );
		$order->save();

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_payment_intention' ) )
			->getMock();
		$api_client
			->method( 'get_payment_intention' )
			->with( 'pi_existing' )
			->willReturn( $this->create_intent_response( $order, 'succeeded', 1000 ) );

		$sut = $this->create_service( $this->create_session(), $api_client );

		$result = $sut->check_payment_intent_attached_to_order_succeeded( $order, $this->create_gateway() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'duplicate_payment_amount_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'This order was already paid for', $result->get_error_message() );
	}

	/**
	 * Create a duplicate-payment prevention service.
	 *
	 * @param \WC_Session|null                  $session    Optional WooCommerce session.
	 * @param WooPaymentsApiClient|null         $api_client Optional API client.
	 * @param OrderPaymentLifecycleService|null $lifecycle_service Optional lifecycle service.
	 * @return WooPaymentsDuplicatePaymentPreventionService
	 */
	private function create_service( ?\WC_Session $session = null, ?WooPaymentsApiClient $api_client = null, ?OrderPaymentLifecycleService $lifecycle_service = null ): WooPaymentsDuplicatePaymentPreventionService {
		$service = new WooPaymentsDuplicatePaymentPreventionService( $session ?? $this->create_session() );
		$service->init(
			$api_client ?? $this->createStub( WooPaymentsApiClient::class ),
			$lifecycle_service ?? wc_get_container()->get( OrderPaymentLifecycleService::class ),
			new WooPaymentsOrderDataService(),
			null,
			wc_get_container()->get( WooPaymentsOrderEffectApplier::class )
		);

		return $service;
	}

	/**
	 * Create an order for duplicate-payment tests.
	 *
	 * @param string $cart_hash   Cart hash.
	 * @param string $status      Order status.
	 * @param int    $customer_id Customer ID.
	 * @return WC_Order
	 */
	private function create_order( string $cart_hash = 'cart-hash', string $status = 'pending', int $customer_id = 0 ): WC_Order {
		$order = wc_create_order();
		$order->set_cart_hash( $cart_hash );
		$order->set_customer_id( $customer_id );
		$order->set_total( '12.00' );
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * Create a PaymentIntent response.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status Intent status.
	 * @param int      $amount Intent amount in minor units.
	 * @return array<string,mixed>
	 */
	private function create_intent_response( WC_Order $order, string $status, int $amount ): array {
		return array(
			'id'       => 'pi_existing',
			'status'   => $status,
			'amount'   => $amount,
			'currency' => strtolower( $order->get_currency() ),
			'customer' => 'cus_existing',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array(
						'id'             => 'ch_existing',
						'payment_method' => 'pm_existing',
					),
				),
			),
		);
	}

	/**
	 * Create a gateway test double.
	 *
	 * @return WC_Payment_Gateway
	 */
	private function create_gateway(): WC_Payment_Gateway {
		return new class() extends WC_Payment_Gateway {
			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id = 'woocommerce_payments';
			}

			/**
			 * Get return URL.
			 *
			 * @param WC_Order|null $order Order.
			 * @return string
			 */
			public function get_return_url( $order = null ) {
				return 'https://example.test/order-received/' . ( $order instanceof WC_Order ? $order->get_id() : '0' );
			}
		};
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return \WC_Session
	 */
	private function create_session(): \WC_Session {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}
}
