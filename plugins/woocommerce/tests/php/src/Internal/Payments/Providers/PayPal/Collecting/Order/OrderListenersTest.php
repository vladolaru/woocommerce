<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Order;

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
}
