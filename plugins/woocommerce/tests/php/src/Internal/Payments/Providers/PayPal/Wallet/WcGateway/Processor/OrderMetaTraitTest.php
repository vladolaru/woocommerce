<?php
/**
 * Tests for the PayPal wallet order meta trait.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PaymentSource;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderMetaTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionMethod;
use WC_Order;

/**
 * The card brand and last digits the PayPal order reports are copied onto the WooCommerce order as meta.
 *
 * @group paypal-wallet
 */
class OrderMetaTraitTest extends WalletTestCase {

	/**
	 * @testdox Should store the brand and last digits of a card source that carries them at the top level.
	 */
	public function test_stores_flat_card_details(): void {
		$order = $this->order_with_payment_source(
			new PaymentSource( 'card', (object) array( 'brand' => 'VISA', 'last_digits' => '1234' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldReceive( 'update_meta_data' )->once()->with( PayPalGateway::ORDER_CARD_BRAND_META_KEY, 'VISA' );
		$wc_order->shouldReceive( 'update_meta_data' )->once()->with( PayPalGateway::ORDER_CARD_LAST_DIGITS_META_KEY, '1234' );

		$this->invoke( $wc_order, $order );
	}

	/**
	 * @testdox Should store no card details for a PayPal account source.
	 */
	public function test_paypal_source_stores_no_card_details(): void {
		$order = $this->order_with_payment_source(
			new PaymentSource( 'paypal', (object) array( 'email_address' => 'john@example.com' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldNotReceive( 'update_meta_data' );

		$this->invoke( $wc_order, $order );
	}

	/**
	 * @testdox Should store only the key the source provides when the card details are partial.
	 */
	public function test_partial_card_details_store_only_the_available_key(): void {
		$order = $this->order_with_payment_source(
			new PaymentSource( 'card', (object) array( 'brand' => 'VISA' ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldReceive( 'update_meta_data' )->once()->with( PayPalGateway::ORDER_CARD_BRAND_META_KEY, 'VISA' );

		$this->invoke( $wc_order, $order );
	}

	/**
	 * @testdox Should store nothing when the PayPal order has no payment source.
	 */
	public function test_no_payment_source_stores_nothing(): void {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'payment_source' )->andReturnNull();

		$wc_order = $this->mock( WC_Order::class );
		$wc_order->shouldNotReceive( 'update_meta_data' );

		$this->invoke( $wc_order, $order );
	}

	/**
	 * A PayPal order mock that reports the given payment source.
	 *
	 * @param PaymentSource $payment_source The payment source the order should return.
	 * @return Order
	 */
	private function order_with_payment_source( PaymentSource $payment_source ): Order {
		$order = $this->mock( Order::class );
		$order->shouldReceive( 'payment_source' )->andReturn( $payment_source );

		return $order;
	}

	/**
	 * Call the trait's private add_card_details_meta() on a throwaway object that uses the trait.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @param Order    $order    The PayPal order.
	 */
	private function invoke( WC_Order $wc_order, Order $order ): void {
		$fixture = new class() {
			use OrderMetaTrait;
		};

		$method = new ReflectionMethod( $fixture, 'add_card_details_meta' );
		$method->setAccessible( true );
		$method->invoke( $fixture, $wc_order, $order );
	}
}
