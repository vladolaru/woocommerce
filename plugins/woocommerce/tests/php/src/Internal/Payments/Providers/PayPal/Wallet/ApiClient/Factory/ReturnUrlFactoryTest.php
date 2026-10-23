<?php
/**
 * Tests for the return URL factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ReturnUrlFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Picks the page the shopper returns to, from the context the payment started in. The WooCommerce URLs are the real
 * ones of the test site: each case compares against wc_get_cart_url(), wc_get_checkout_url() or the real order.
 *
 * @group paypal-wallet
 */
class ReturnUrlFactoryTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ReturnUrlFactory
	 */
	private $testee;

	/**
	 * The cart page.
	 *
	 * @var int
	 */
	private $cart_page_id;

	/**
	 * The checkout page.
	 *
	 * @var int
	 */
	private $checkout_page_id;

	/**
	 * Give the store a cart page and a checkout page of its own, so the two URLs can be told apart, and set up the
	 * factory.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->cart_page_id     = $this->factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Cart',
			)
		);
		$this->checkout_page_id = $this->factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Checkout',
			)
		);
		$this->set_wallet_option( 'woocommerce_cart_page_id', $this->cart_page_id );
		$this->set_wallet_option( 'woocommerce_checkout_page_id', $this->checkout_page_id );

		$this->testee = new ReturnUrlFactory();
	}

	/**
	 * @testdox Should return the cart URL for a cart, a cart block and a mini cart.
	 *
	 * @dataProvider data_cart_context
	 *
	 * @param string $context The context.
	 */
	public function test_from_context_returns_cart_url( string $context ): void {
		$result = $this->testee->from_context( $context );

		$this->assertEquals( wc_get_cart_url(), $result );
		$this->assertSame( get_permalink( $this->cart_page_id ), $result );
	}

	/**
	 * The contexts that return to the cart.
	 *
	 * @return array<string, array<string>>
	 */
	public function data_cart_context(): array {
		return array(
			'cart context'       => array( 'cart' ),
			'cart-block context' => array( 'cart-block' ),
			'mini-cart context'  => array( 'mini-cart' ),
		);
	}

	/**
	 * @testdox Should return the URL of the first item for a product.
	 */
	public function test_from_context_product_returns_product_url(): void {
		$request_data = array(
			'purchase_units' => array(
				array(
					'items' => array(
						array( 'url' => 'https://example.com/product/123' ),
					),
				),
			),
		);

		$result = $this->testee->from_context( 'product', $request_data );

		$this->assertEquals( 'https://example.com/product/123', $result );
	}

	/**
	 * @testdox Should throw for a product whose item has no URL.
	 */
	public function test_from_context_product_throws_exception_when_no_url(): void {
		$request_data = array(
			'purchase_units' => array(
				array(
					'items' => array(
						array( 'name' => 'Product without URL' ),
					),
				),
			),
		);

		$this->expectException( RuntimeException::class );

		$this->testee->from_context( 'product', $request_data );
	}

	/**
	 * @testdox Should return the payment URL of the order for pay-now.
	 */
	public function test_from_context_pay_now_returns_order_payment_url(): void {
		$order = wc_create_order();

		$result = $this->testee->from_context( 'pay-now', array( 'order_id' => $order->get_id() ) );

		$this->assertEquals( $order->get_checkout_payment_url(), $result );
		$this->assertStringContainsString( 'order-pay', $result );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $result );
	}

	/**
	 * @testdox Should throw for pay-now when the order is not found.
	 */
	public function test_from_context_pay_now_throws_exception_when_order_not_found(): void {
		$this->expectException( RuntimeException::class );

		$this->testee->from_context( 'pay-now', array( 'order_id' => 999999 ) );
	}

	/**
	 * @testdox Should return the checkout URL for the checkout.
	 */
	public function test_from_context_checkout_returns_checkout_url(): void {
		$result = $this->testee->from_context( 'checkout' );

		$this->assertEquals( wc_get_checkout_url(), $result );
		$this->assertSame( get_permalink( $this->checkout_page_id ), $result );
	}

	/**
	 * @testdox Should return the checkout URL for a context it does not know.
	 */
	public function test_from_context_default_returns_checkout_url(): void {
		$result = $this->testee->from_context( 'unknown-context' );

		$this->assertEquals( wc_get_checkout_url(), $result );
	}
}
