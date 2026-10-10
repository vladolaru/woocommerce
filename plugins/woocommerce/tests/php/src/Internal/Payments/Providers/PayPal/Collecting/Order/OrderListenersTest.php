<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderListeners;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order\OrderPin;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
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
}
