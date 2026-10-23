<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderListeners;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FixedHeldOrders;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use WC_Order;

/**
 * Tests for the listeners that pin each order to its app and enter it for the order's calls.
 *
 * @group paypal-wallet
 */
class OrderListenersTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * Boot the wallet with the fake transport.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		return $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
	}

	/**
	 * A wallet order with a PayPal order ID, pinned to an app or not.
	 *
	 * @param string|null $pin The pinned app, or null for none.
	 * @return WC_Order
	 */
	private function wallet_order( ?string $pin ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( PayPalGateway::ID );
		$order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-OLD' );
		if ( null !== $pin ) {
			OrderPin::record( $order, $pin );
		}
		$order->save();

		return $order;
	}

	/**
	 * @testdox Should neither pin nor enter an order on a store the platform does not serve.
	 */
	public function test_store_not_served_by_the_platform_is_left_alone(): void {
		$container = $this->boot();
		$context   = $container->get( 'collecting.order-app-context' );
		$wc_order  = $this->wallet_order( null );

		do_action( 'woocommerce_paypal_wallet_order_context', $wc_order );
		$container->get( 'wcgateway.order-processor' )->add_paypal_meta( $wc_order, new Order( 'PP-NEW', array(), new OrderStatus( OrderStatus::CREATED ) ), $container->get( 'settings.environment' ) );

		$this->assertFalse( $context->is_entered() );
		$this->assertFalse( OrderPin::is_pinned( wc_get_order( $wc_order->get_id() ) ) );
	}

	/**
	 * @testdox Should leave the order context after the order processor's run and ignore an order context that is not an order.
	 */
	public function test_context_is_left_after_the_order_processor_and_bad_values_are_ignored(): void {
		$this->set_wallet_option(
			Options::COLLECTING,
			array(
				'payee_email' => 'payee@example.com',
				'tracking_id' => 'abc',
				'environment' => 'sandbox',
				'payee_bound' => false,
			)
		);
		$container = $this->boot();
		$context   = $container->get( 'collecting.order-app-context' );
		$wc_order  = $this->wallet_order( PlatformTransport::APP_PLATFORM );

		do_action( 'woocommerce_paypal_wallet_order_context', $wc_order );
		$this->assertSame( PlatformTransport::APP_PLATFORM, $context->current() );
		do_action( 'woocommerce_paypal_wallet_order_context', 'not an order' );
		$this->assertSame( PlatformTransport::APP_PLATFORM, $context->current(), 'A value that is not an order changes nothing' );
		do_action( 'woocommerce_paypal_payments_after_order_processor', $wc_order, new Order( 'PP-OLD', array(), new OrderStatus( OrderStatus::COMPLETED ) ) );

		$this->assertFalse( $context->is_entered() );
	}

	/**
	 * Make the order screen's PayPal order fetch, as the wallet's void button makes it on `admin_enqueue_scripts`, for a
	 * platform-connected store and an order pinned to the merchant app.
	 *
	 * @param bool $run_listener Whether to run the module's `admin_enqueue_scripts` callback before the fetch.
	 * @return array{0: string[], 1: int|null} The Authorization headers, and the priority of the module's callback.
	 */
	private function order_screen_fetch( bool $run_listener ): array {
		global $theorder;
		$this->set_wallet_option(
			Options::PLATFORM,
			array(
				'merchant_id' => 'M2',
				'tracking_id' => 'abc',
				'payee_email' => 'payee@example.com',
				'environment' => 'sandbox',
			)
		);
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport( array( 'pick' => PlatformTransport::APP_PLATFORM ) ) ) ) );
		$wc_order  = $this->wallet_order( PlatformTransport::APP_MERCHANT_APP );
		$wc_order->set_total( '10.00' );
		$wc_order->save();
		$authorizations = array();
		$this->stub_http(
			function ( $request, $url ) use ( &$authorizations ) {
				if ( false !== strpos( (string) $url, 'v2/checkout/orders/PP-OLD' ) ) {
					$authorizations[] = $request['headers']['Authorization'] ?? '';
				}
				return $this->http_response( 404, '' );
			}
		);

		$priority = null;
		$listener = null;
		foreach ( $GLOBALS['wp_filter']['admin_enqueue_scripts']->callbacks ?? array() as $hook_priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof OrderListeners ) {
					$priority = (int) $hook_priority;
					$listener = $callback['function'];
				}
			}
		}

		$saved_gateways  = WC()->payment_gateways()->payment_gateways;
		$previous_order  = $theorder;
		$previous_screen = $GLOBALS['current_screen'] ?? null;

		WC()->payment_gateways()->payment_gateways = array( PayPalGateway::ID => $container->get( 'wcgateway.paypal-gateway' ) );

		$theorder = wc_get_order( $wc_order->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The order screen's global, as WooCommerce sets it.
		set_current_screen( 'shop_order' );
		try {
			if ( $run_listener && is_callable( $listener ) ) {
				$listener();
			}
			$container->get( 'wcgateway.void-button.assets' )->should_register();
		} finally {
			WC()->payment_gateways()->payment_gateways = $saved_gateways;

			$theorder = $previous_order; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring test state.
			if ( null === $previous_screen ) {
				unset( $GLOBALS['current_screen'] );
			} else {
				set_current_screen( $previous_screen );
			}
		}

		return array( $authorizations, $priority );
	}

	/**
	 * @testdox Should fetch a pinned order's PayPal order on the order screen through the pinned app once platform connected.
	 */
	public function test_order_screen_fetch_uses_the_pinned_app(): void {
		list( $authorizations, $priority ) = $this->order_screen_fetch( true );

		$this->assertNotNull( $priority, 'The module listens on admin_enqueue_scripts' );
		$this->assertLessThan( 10, $priority, 'Before the wallet\'s void button callback at 10' );
		$this->assertSame( array( 'Bearer token-' . PlatformTransport::APP_MERCHANT_APP ), $authorizations );
	}

	/**
	 * @testdox Should fetch through the platform app's pick when the order context is not entered, which the platform app cannot see.
	 */
	public function test_order_screen_fetch_without_the_context_uses_the_pick(): void {
		list( $authorizations ) = $this->order_screen_fetch( false );

		$this->assertSame( array( 'Bearer token-' . PlatformTransport::APP_PLATFORM ), $authorizations );
	}

	/**
	 * A Pay with Crypto order, as the gateway leaves it before it writes the PayPal order ID, saved once.
	 *
	 * @param string $payment_method The payment method.
	 * @return WC_Order
	 */
	private function crypto_order( string $payment_method = GatewayIds::PWC ): WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->save();

		return $order;
	}

	/**
	 * Listeners over the fake transport, for the handler called directly.
	 *
	 * @return OrderListeners
	 */
	private function listeners(): OrderListeners {
		return new OrderListeners( new ConnectionState(), new OrderAppContext(), new FakePlatformTransport(), new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), new RecordingLogger() );
	}

	/**
	 * @testdox Should pin a Pay with Crypto order to the platform app in the save that writes its PayPal order ID, with no second save.
	 */
	public function test_pins_a_crypto_order_in_the_save_that_writes_its_paypal_order_id(): void {
		$this->set_platform_connected();
		$this->boot();
		$wc_order = $this->crypto_order();
		$this->assertFalse( OrderPin::is_pinned( $wc_order ), 'Not pinned before it has a PayPal order ID' );
		$saves = $this->spy_filter( 'woocommerce_after_order_object_save' );

		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-CRYPTO' );
		$wc_order->save();

		$this->assertCount( 1, $saves, 'One save' );
		$this->assertSame( PlatformTransport::APP_PLATFORM, wc_get_order( $wc_order->get_id() )->get_meta( OrderAppContext::ORDER_APP_META_KEY ) );
	}

	/**
	 * @testdox Should leave a PayPal button order, and a crypto order saved without a PayPal order ID, unpinned: $description.
	 * @testWith ["a PayPal button order", "ppcp-gateway", "PP-1"]
	 *           ["a crypto order without a PayPal order ID", "ppcp-pwc", ""]
	 *
	 * @param string $description    What the order is.
	 * @param string $payment_method The payment method.
	 * @param string $paypal_id      The PayPal order ID, or empty for none.
	 */
	public function test_leaves_other_orders_unpinned( string $description, string $payment_method, string $paypal_id ): void {
		unset( $description );
		$this->set_platform_connected();
		$this->boot();
		$wc_order = $this->crypto_order( $payment_method );

		if ( '' !== $paypal_id ) {
			$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, $paypal_id );
		}
		$wc_order->save();

		$this->assertFalse( OrderPin::is_pinned( wc_get_order( $wc_order->get_id() ) ) );
	}

	/**
	 * @testdox Should leave a crypto order that is getting its PayPal order ID unpinned unless the store is platform connected: $state.
	 * @testWith ["collecting"]
	 *           ["first_party"]
	 *           ["dormant"]
	 *
	 * @param string $state The store state.
	 */
	public function test_pins_only_on_a_platform_connected_store( string $state ): void {
		if ( 'collecting' === $state ) {
			$this->set_collecting();
		} elseif ( 'first_party' === $state ) {
			$this->set_platform_connected();
			$this->set_first_party_connected();
		}
		$wc_order = $this->crypto_order();
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-CRYPTO' );

		$this->listeners()->handle_woocommerce_before_order_object_save( $wc_order );

		$this->assertFalse( OrderPin::is_pinned( $wc_order ) );
	}

	/**
	 * @testdox Should replace an older pin with the platform app when a new PayPal order ID is written, as on a pay-for-order retry.
	 */
	public function test_a_new_paypal_order_id_replaces_an_older_pin(): void {
		$this->set_platform_connected();
		$wc_order = $this->crypto_order();
		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-BUTTON' );
		OrderPin::record( $wc_order, PlatformTransport::APP_MERCHANT_APP );
		$wc_order->save();

		$wc_order->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-CRYPTO' );
		$this->listeners()->handle_woocommerce_before_order_object_save( $wc_order );

		$this->assertSame( PlatformTransport::APP_PLATFORM, OrderPin::app( $wc_order ) );
	}

	/**
	 * @testdox Should leave the pin, or its absence, alone on a save that does not write the PayPal order ID, such as an edit in the admin.
	 */
	public function test_a_save_without_a_new_paypal_order_id_changes_no_pin(): void {
		$this->set_platform_connected();
		$pinned = $this->crypto_order();
		$pinned->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-OLD' );
		OrderPin::record( $pinned, PlatformTransport::APP_MERCHANT_APP );
		$pinned->save();
		$unpinned = $this->crypto_order();
		$unpinned->update_meta_data( PayPalGateway::ORDER_ID_META_KEY, 'PP-LEGACY' );
		$unpinned->save();

		$edited_pinned   = wc_get_order( $pinned->get_id() );
		$edited_unpinned = wc_get_order( $unpinned->get_id() );
		$edited_pinned->set_customer_note( 'Edited in the admin' );
		$edited_unpinned->set_customer_note( 'Edited in the admin' );

		$this->listeners()->handle_woocommerce_before_order_object_save( $edited_pinned );
		$this->listeners()->handle_woocommerce_before_order_object_save( $edited_unpinned );

		$this->assertSame( PlatformTransport::APP_MERCHANT_APP, OrderPin::app( $edited_pinned ) );
		$this->assertFalse( OrderPin::is_pinned( $edited_unpinned ), 'A legacy order is not pinned by an edit' );
	}

	/**
	 * @testdox Should read nothing but the payment method of an order that is not a Pay with Crypto order, and ignore a value that is not an order.
	 */
	public function test_reads_only_the_payment_method_of_other_orders(): void {
		$connection_state = $this->mock( ConnectionState::class );
		$connection_state->shouldNotReceive( 'is_served_by_platform' );
		$connection_state->shouldNotReceive( 'resolve' );
		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldReceive( 'get_payment_method' )->once()->andReturn( 'cod' );
		$wc_order->shouldNotReceive( 'get_meta' );
		$wc_order->shouldNotReceive( 'get_meta_data' );
		$transport = new FakePlatformTransport();
		$sut       = new OrderListeners( $connection_state, new OrderAppContext(), $transport, new CollectingState( new Options(), new FixedHeldOrders( 0 ) ), new RecordingLogger() );

		$sut->handle_woocommerce_before_order_object_save( $wc_order );
		$sut->handle_woocommerce_before_order_object_save( 'not an order' );

		$this->assertSame( array(), $transport->calls );
	}
}
