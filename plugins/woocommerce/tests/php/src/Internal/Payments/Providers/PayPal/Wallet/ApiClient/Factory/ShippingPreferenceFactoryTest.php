<?php
/**
 * Tests for the shipping preference factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\ExperienceContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PurchaseUnit;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Shipping;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Cart;
use WC_Helper_Product;
use WC_Helper_Shipping;
use WC_Order;

/**
 * Derives the shipping preference of the PayPal order from the purchase unit, the context, the funding source and
 * whether the cart or the order needs shipping.
 *
 * The cart and the order are real: the store has a flat rate, so an order or cart with a physical product needs
 * shipping and one with a virtual product does not. The purchase unit and its shipping node stay mocks.
 *
 * @group paypal-wallet
 */
class ShippingPreferenceFactoryTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ShippingPreferenceFactory
	 */
	private $sut;

	/**
	 * Give the store a flat rate (so shipping is on) and an empty cart.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
		WC_Helper_Shipping::create_simple_flat_rate();

		$this->sut = new ShippingPreferenceFactory();
	}

	/**
	 * Take the flat rate away again. The base class empties the cart.
	 */
	public function tearDown(): void {
		try {
			WC_Helper_Shipping::delete_simple_flat_rate();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A mock of a purchase unit.
	 *
	 * @param bool          $contains_physical_goods Whether the unit has physical goods.
	 * @param Shipping|null $shipping                The shipping node.
	 * @return PurchaseUnit
	 */
	private function make_purchase_unit( bool $contains_physical_goods, ?Shipping $shipping ): PurchaseUnit {
		$purchase_unit = $this->mock( PurchaseUnit::class );
		$purchase_unit->shouldReceive( 'contains_physical_goods' )->andReturn( $contains_physical_goods );
		$purchase_unit->shouldReceive( 'shipping' )->andReturn( $shipping );

		return $purchase_unit;
	}

	/**
	 * A mock of a shipping node that does or does not hold an address.
	 *
	 * @param bool $with_address Whether it has an address.
	 * @return Shipping
	 */
	private function make_shipping( bool $with_address ): Shipping {
		$shipping = $this->mock( Shipping::class );
		$shipping->shouldReceive( 'address' )->andReturn( $with_address ? $this->mock( Address::class ) : null );

		return $shipping;
	}

	/**
	 * The cart with a product that does or does not need shipping.
	 *
	 * @param bool $needs_shipping Whether the product is physical.
	 * @return WC_Cart
	 */
	private function make_cart( bool $needs_shipping ): WC_Cart {
		$product = WC_Helper_Product::create_simple_product( true, array( 'virtual' => ! $needs_shipping ) );
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		return WC()->cart;
	}

	/**
	 * An order with a product that does or does not need shipping.
	 *
	 * @param bool $needs_shipping Whether the product is physical.
	 * @return WC_Order
	 */
	private function make_order( bool $needs_shipping ): WC_Order {
		$order = wc_create_order();
		$order->add_product( WC_Helper_Product::create_simple_product( true, array( 'virtual' => ! $needs_shipping ) ), 1 );

		return $order;
	}

	/**
	 * Given a purchase unit, the cart or order state, the context and the funding source, the matching shipping
	 * preference of the experience context is returned.
	 *
	 * @testdox Should derive the shipping preference from the purchase unit, the context, the funding source and the shipping need.
	 *
	 * @dataProvider data_from_state
	 *
	 * @param bool      $physical      Whether the purchase unit has physical goods.
	 * @param string    $shipping_node The shipping node: 'address', 'no_address' (options only) or 'none'.
	 * @param string    $context       The context.
	 * @param bool|null $cart_needs    Whether the cart needs shipping, or null for no cart.
	 * @param string    $funding       The funding source.
	 * @param bool|null $order_needs   Whether the order needs shipping, or null for no order.
	 * @param string    $expected      The expected shipping preference.
	 */
	public function test_from_state( bool $physical, string $shipping_node, string $context, ?bool $cart_needs, string $funding, ?bool $order_needs, string $expected ): void {
		$shipping      = 'none' === $shipping_node ? null : $this->make_shipping( 'address' === $shipping_node );
		$purchase_unit = $this->make_purchase_unit( $physical, $shipping );
		$cart          = null === $cart_needs ? null : $this->make_cart( $cart_needs );
		$wc_order      = null === $order_needs ? null : $this->make_order( $order_needs );

		$result = $this->sut->from_state( $purchase_unit, $context, $cart, $funding, $wc_order );

		$this->assertEquals( $expected, $result );
	}

	/**
	 * The purchase unit, cart, order and funding source combinations of the extension, each with a name that says what
	 * it covers.
	 *
	 * @return array<string, array>
	 */
	public function data_from_state(): array {
		$provided = ExperienceContext::SHIPPING_PREFERENCE_SET_PROVIDED_ADDRESS;
		$none     = ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING;
		$file     = ExperienceContext::SHIPPING_PREFERENCE_GET_FROM_FILE;

		return array(
			'checkout, physical goods, address, cart needs shipping' => array( true, 'address', 'checkout', true, '', null, $provided ),
			'checkout, no physical goods'                  => array( false, 'address', 'checkout', false, '', null, $none ),
			'checkout, physical goods, no shipping node'   => array( true, 'none', 'checkout', true, '', null, $none ),
			'checkout, card funding, address'              => array( true, 'address', 'checkout', true, 'card', null, $provided ),
			'product, no shipping node, no cart'           => array( true, 'none', 'product', null, '', null, $none ),
			'pay-now, venmo, order needs no shipping'      => array( true, 'none', 'pay-now', null, 'venmo', false, $none ),
			'pay-now, venmo, order needs shipping, address' => array( true, 'address', 'pay-now', null, 'venmo', true, $provided ),
			'pay-now, card, order needs shipping, address' => array( true, 'address', 'pay-now', null, 'card', true, $provided ),
			'pay-now, card, order needs no shipping'       => array( true, 'none', 'pay-now', null, 'card', false, $none ),
			'checkout with options-only shipping node falls back to no shipping' => array( true, 'no_address', 'checkout', true, '', null, $none ),
			'pay-now with options-only shipping node falls back to no shipping' => array( true, 'no_address', 'pay-now', null, 'venmo', true, $none ),
			'card funding source with options-only shipping node falls back to no shipping' => array( true, 'no_address', 'product', true, 'card', null, $none ),
			'product context returns get-from-file regardless of shipping address presence' => array( true, 'no_address', 'product', true, '', null, $file ),
		);
	}

	/**
	 * @testdox Should use the string a filter returns as the shipping preference and hand it the purchase unit, the context, the cart and the funding source.
	 */
	public function test_from_state_returns_the_shipping_preference_of_the_filter(): void {
		$purchase_unit = $this->make_purchase_unit( true, null );
		$cart          = $this->make_cart( true );
		$calls         = $this->spy_filter( 'woocommerce_paypal_payments_shipping_preference', ExperienceContext::SHIPPING_PREFERENCE_GET_FROM_FILE );

		$result = $this->sut->from_state( $purchase_unit, 'cart', $cart, 'paypal' );

		$this->assertSame( ExperienceContext::SHIPPING_PREFERENCE_GET_FROM_FILE, $result );
		$this->assertCount( 1, $calls );
		$this->assertNull( $calls[0][0] );
		$this->assertSame( $purchase_unit, $calls[0][1] );
		$this->assertSame( 'cart', $calls[0][2] );
		$this->assertSame( $cart, $calls[0][3] );
		$this->assertSame( 'paypal', $calls[0][4] );
	}

	/**
	 * @testdox Should ignore a filter result that is not a string.
	 */
	public function test_from_state_ignores_a_filter_result_that_is_not_a_string(): void {
		$purchase_unit = $this->make_purchase_unit( false, null );
		$this->spy_filter( 'woocommerce_paypal_payments_shipping_preference', true );

		$result = $this->sut->from_state( $purchase_unit, 'checkout' );

		$this->assertSame( ExperienceContext::SHIPPING_PREFERENCE_NO_SHIPPING, $result );
	}
}
