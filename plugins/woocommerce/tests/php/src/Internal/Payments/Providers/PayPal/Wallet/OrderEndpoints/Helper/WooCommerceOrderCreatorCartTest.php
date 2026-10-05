<?php
/**
 * Tests for building a WooCommerce order from a real shopping cart with the PayPal wallet order creator.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Order;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\WooCommerceOrderCreator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Helper_Product;
use WC_Order_Item_Product;
use WC_Product;

/**
 * The creator receives the real cart, turns it into cart data through the real cart data factory and builds the order
 * from it. The tests that exist for the creator hand it prepared cart data; these start from a cart with products in it,
 * as the approve-order request does.
 *
 * @group paypal-wallet
 */
class WooCommerceOrderCreatorCartTest extends WalletTestCase {

	/**
	 * Give the test an empty cart. The base class empties it again after the test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();
	}

	/**
	 * A virtual product, so that the cart needs no shipping address, with the given price.
	 *
	 * @param string $name  The name of the product.
	 * @param string $price The regular price.
	 * @return WC_Product
	 */
	private function virtual_product( string $name, string $price ): WC_Product {
		$product = WC_Helper_Product::create_simple_product( false );
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_virtual( true );
		$product->save();

		return $product;
	}

	/**
	 * The creator over the real cart data factory, with a PayPal session that has no funding source chosen.
	 *
	 * @return WooCommerceOrderCreator
	 */
	private function create_order_creator(): WooCommerceOrderCreator {
		$session_handler = $this->mock( SessionHandler::class );
		$session_handler->shouldReceive( 'funding_source' )->andReturn( null );

		$subscription_helper = $this->mock( SubscriptionHelper::class );
		$subscription_helper->shouldReceive( 'plugin_is_active' )->andReturn( false );

		return new WooCommerceOrderCreator(
			$this->mock( FundingSourceRenderer::class ),
			$session_handler,
			$subscription_helper,
			new CartDataFactory(),
			$this->mock( ShippingFactory::class ),
			$this->mock( PayerFactory::class )
		);
	}

	/**
	 * @testdox Should create an order with one line item for each of the two products in the cart, and the cart's total.
	 */
	public function test_cart_with_two_products_creates_an_order_with_two_line_items_and_the_cart_totals(): void {
		$first  = $this->virtual_product( 'First product', '12.50' );
		$second = $this->virtual_product( 'Second product', '7.25' );

		$cart = WC()->cart;
		$cart->add_to_cart( $first->get_id(), 2 );
		$cart->add_to_cart( $second->get_id(), 1 );
		$cart->calculate_totals();

		$paypal_order = $this->mock( Order::class );
		$paypal_order->shouldReceive( 'payer' )->andReturn( null );
		$paypal_order->shouldReceive( 'purchase_units' )->andReturn( array() );

		$wc_order = $this->create_order_creator()->create_from_paypal_order( $paypal_order, $cart );

		$saved = wc_get_order( $wc_order->get_id() );
		$items = array_values( $saved->get_items() );
		$this->assertCount( 2, $items, 'The order should have one line item for each product in the cart' );

		$by_product = array();
		foreach ( $items as $item ) {
			$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
			$by_product[ $item->get_product_id() ] = $item;
		}
		$this->assertSame( 2, $by_product[ $first->get_id() ]->get_quantity() );
		$this->assertSame( 'First product', $by_product[ $first->get_id() ]->get_name() );
		$this->assertEquals( 25.0, (float) $by_product[ $first->get_id() ]->get_total(), 'The first line should be priced for two units' );
		$this->assertSame( 1, $by_product[ $second->get_id() ]->get_quantity() );
		$this->assertSame( 'Second product', $by_product[ $second->get_id() ]->get_name() );
		$this->assertEquals( 7.25, (float) $by_product[ $second->get_id() ]->get_total() );

		$this->assertEquals( 32.25, (float) $saved->get_total(), 'The order total should be the cart total' );
		$this->assertEquals( (float) $cart->get_total( 'edit' ), (float) $saved->get_total() );
		$this->assertEquals( (float) $cart->get_subtotal(), (float) $saved->get_subtotal() );
		$this->assertSame( $cart->get_cart_hash(), $saved->get_cart_hash(), 'The order should carry the hash of the cart it was built from' );
		$this->assertSame( PayPalGateway::ID, $saved->get_payment_method() );
	}
}
