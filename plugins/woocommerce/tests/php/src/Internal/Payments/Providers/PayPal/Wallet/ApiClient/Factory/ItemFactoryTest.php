<?php
/**
 * Tests for the item factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ItemFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Helper_Product;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Product;

/**
 * Builds the PayPal items of an order from a cart, an order or a PayPal response. A fractional quantity (0.3 of a
 * product, from plugins like Measurement Price Calculator) becomes one unit at the full line price, because PayPal
 * rejects a quantity below one.
 *
 * The cart and the order are real: products are created with WC_Helper_Product, the cart is filled with
 * WC_Cart::add_to_cart() and the orders come from wc_create_order().
 *
 * @group paypal-wallet
 */
class ItemFactoryTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ItemFactory
	 */
	private $sut;

	/**
	 * Set the shop currency, give WooCommerce a fresh session (the factory reads the fees from it) and a cart.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_option( 'woocommerce_currency', 'USD' );

		$this->use_own_wc_session();
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		WC()->cart->empty_cart();

		$this->sut = new ItemFactory( new CurrencyGetter() );
	}

	/**
	 * A saved product.
	 *
	 * @param array $props Properties that replace the defaults.
	 * @return WC_Product
	 */
	private function make_product( array $props = array() ): WC_Product {
		return WC_Helper_Product::create_simple_product(
			true,
			array_merge(
				array(
					'name'        => 'name',
					'description' => 'description',
					'sku'         => 'sku',
				),
				$props
			)
		);
	}

	/**
	 * Put products into the cart and calculate it.
	 *
	 * @param WC_Product $product  The product.
	 * @param int|float  $quantity The quantity.
	 */
	private function fill_cart( WC_Product $product, $quantity ): void {
		WC()->cart->add_to_cart( $product->get_id(), $quantity );
		WC()->cart->calculate_totals();
	}

	/**
	 * Let WooCommerce keep a fractional quantity, as plugins like Measurement Price Calculator do.
	 */
	private function allow_fractional_quantities(): void {
		remove_filter( 'woocommerce_stock_amount', 'intval' );
		add_filter( 'woocommerce_stock_amount', 'floatval' );
	}

	/**
	 * An order in USD with the given line items.
	 *
	 * @param WC_Product|null $product  The product to add, or null for none.
	 * @param int|float       $quantity The quantity.
	 * @return WC_Order
	 */
	private function make_order( ?WC_Product $product = null, $quantity = 1 ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		if ( $product ) {
			$order->add_product( $product, $quantity );
		}

		return $order;
	}

	/**
	 * @testdox Should build the items from a cart with the product's name, description, SKU, link and the price per unit.
	 */
	public function test_from_cart_default(): void {
		$product = $this->make_product(
			array(
				'regular_price' => 42,
				'price'         => 42,
			)
		);
		$this->fill_cart( $product, 2 );

		$result = $this->sut->from_wc_cart( WC()->cart );

		$this->assertCount( 1, $result );
		$item = current( $result );
		$this->assertInstanceOf( Item::class, $item );
		$this->assertEquals( Item::PHYSICAL_GOODS, $item->category() );
		$this->assertEquals( 'description', $item->description() );
		$this->assertEquals( 2, $item->quantity() );
		$this->assertEquals( 'name', $item->name() );
		$this->assertEquals( 'sku', $item->sku() );
		$this->assertEquals( 42, $item->unit_amount()->value() );
		$this->assertSame( 'USD', $item->unit_amount()->currency_code() );
		$this->assertSame( $product->get_permalink(), $item->url() );
		$this->assertSame( $product->get_id(), $item->product_id() );
		$this->assertNotEmpty( $item->cart_item_key() );
	}

	/**
	 * @testdox Should turn a fractional quantity into one unit at the full line price.
	 */
	public function test_from_cart_fractional_quantity_is_normalized_to_one(): void {
		$this->allow_fractional_quantities();
		$product = $this->make_product(
			array(
				'regular_price' => 100,
				'price'         => 100,
			)
		);
		$this->fill_cart( $product, 0.3 );

		$result = $this->sut->from_wc_cart( WC()->cart );

		$item = current( $result );
		$this->assertEquals( 1, $item->quantity() );
		$this->assertEquals( 30.00, $item->unit_amount()->value() );
	}

	/**
	 * @testdox Should mark a virtual product of a cart as digital goods.
	 */
	public function test_from_cart_digital_good(): void {
		$product = $this->make_product( array( 'virtual' => true ) );
		$this->fill_cart( $product, 1 );

		$result = $this->sut->from_wc_cart( WC()->cart );

		$item = current( $result );
		$this->assertEquals( Item::DIGITAL_GOODS, $item->category() );
	}

	/**
	 * @testdox Should carry the unit tax and the line discount of a cart item.
	 */
	public function test_from_cart_carries_tax_and_discount_of_the_line(): void {
		$this->set_wallet_option( 'woocommerce_calc_taxes', 'yes' );
		$this->insert_flat_tax_rate( '10.0000' );
		// Tax rates only apply to a customer who has a country.
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_state( 'NY' );
		WC()->customer->set_shipping_postcode( '10001' );
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'half' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 50 );
		$coupon->save();

		$product = $this->make_product(
			array(
				'regular_price' => 20,
				'price'         => 20,
			)
		);
		$this->fill_cart( $product, 2 );
		WC()->cart->apply_coupon( 'half' );
		WC()->cart->calculate_totals();

		$result = $this->sut->from_wc_cart( WC()->cart );

		$item = current( $result );
		$this->assertEquals( 20.00, $item->unit_amount()->value() );
		// Two units at 20.00 are 40.00, halved by the coupon to 20.00, which makes 2.00 of tax, 1.00 per unit.
		$this->assertEquals( 1.00, $item->tax()->value() );
		$this->assertEquals( 20.00, $item->discount()->value() );
	}

	/**
	 * @testdox Should add the fees the session holds as items of one unit each.
	 */
	public function test_from_cart_adds_the_fees_of_the_session(): void {
		$this->fill_cart( $this->make_product(), 1 );
		WC()->session->set(
			'ppcp_fees',
			array(
				(object) array(
					'name'   => 'Gift wrap',
					'amount' => '2.50',
				),
			)
		);

		$result = $this->sut->from_wc_cart( WC()->cart );

		$this->assertCount( 2, $result );
		$fee = end( $result );
		$this->assertSame( 'Gift wrap', $fee->name() );
		$this->assertEquals( 2.50, $fee->unit_amount()->value() );
		$this->assertSame( 1, $fee->quantity() );
	}

	/**
	 * @testdox Should build the items from an order.
	 */
	public function test_from_wc_order_default(): void {
		$product = $this->make_product(
			array(
				'regular_price' => 1,
				'price'         => 1,
			)
		);
		$order   = $this->make_order( $product, 1 );

		$result = $this->sut->from_wc_order( $order );

		$this->assertCount( 1, $result );
		$item = current( $result );
		$this->assertInstanceOf( Item::class, $item );
		$this->assertEquals( 'name', $item->name() );
		$this->assertEquals( 'description', $item->description() );
		$this->assertEquals( 1, $item->quantity() );
		$this->assertEquals( Item::PHYSICAL_GOODS, $item->category() );
		$this->assertEquals( 1, $item->unit_amount()->value() );
	}

	/**
	 * @testdox Should turn a fractional quantity of an order into one unit at the full line price.
	 */
	public function test_from_wc_order_fractional_quantity_is_normalized_to_one(): void {
		$this->allow_fractional_quantities();
		$product = $this->make_product(
			array(
				'regular_price' => 100,
				'price'         => 100,
			)
		);
		$order   = $this->make_order( $product, 0.3 );

		$result = $this->sut->from_wc_order( $order );

		$item = current( $result );
		$this->assertEquals( 1, $item->quantity() );
		$this->assertEquals( 30.00, $item->unit_amount()->value() );
	}

	/**
	 * @testdox Should mark a virtual product of an order as digital goods.
	 */
	public function test_from_wc_order_digital_good(): void {
		$order = $this->make_order( $this->make_product( array( 'virtual' => true ) ), 1 );

		$result = $this->sut->from_wc_order( $order );

		$item = current( $result );
		$this->assertEquals( Item::DIGITAL_GOODS, $item->category() );
	}

	/**
	 * @testdox Should cut the name and the description of an order item at 127 characters.
	 */
	public function test_from_wc_order_max_string_length(): void {
		$name        = 'öawjetöagrjjaglörjötairgjaflkögjöalfdgjöalfdjblköajtlkfjdbljslkgjfklösdgjalkerjtlrajglkfdajblköajflköbjsdgjadfgjaöfgjaölkgjkladjgfköajgjaflgöjafdlgjafdögjdsflkgjö4jwegjfsdbvxj öskögjtaeröjtrgt';
		$description = 'öawjetöagrjjaglörjötairgjaflkögjöalfdgjöalfdjblköajtlkfjdbljslkgjfklösdgjalkerjtlrajglkfdajblköajflköbjsdgjadfgjaöfgjaölkgjkladjgfköajgjaflgöjafdlgjafdögjdsflkgjö4jwegjfsdbvxj öskögjtaeröjtrgt';
		$product     = $this->make_product(
			array(
				'name'        => $name,
				'description' => $description,
				'virtual'     => true,
			)
		);
		$order       = $this->make_order( $product, 1 );

		$result = $this->sut->from_wc_order( $order );

		$item = current( $result );
		$this->assertEquals( substr( strip_shortcodes( wp_strip_all_tags( $name ) ), 0, 127 ), $item->name() );
		$this->assertEquals( substr( strip_shortcodes( wp_strip_all_tags( $description ) ), 0, 127 ), $item->description() );
		$this->assertLessThanOrEqual( 127, strlen( $item->name() ) );
		$this->assertLessThanOrEqual( 127, strlen( $item->description() ) );
	}

	/**
	 * @testdox Should carry the unit tax and the line discount of an order item.
	 */
	public function test_from_wc_order_carries_tax_and_discount_of_the_line(): void {
		$order = $this->make_order();
		$line  = new WC_Order_Item_Product();
		$line->set_name( 'Taxed line' );
		$line->set_quantity( 2 );
		$line->set_subtotal( 50 );
		$line->set_total( 40 );
		$line->set_taxes(
			array(
				'total'    => array( 1 => 3.0 ),
				'subtotal' => array( 1 => 3.0 ),
			)
		);
		$order->add_item( $line );

		$result = $this->sut->from_wc_order( $order );

		$item = current( $result );
		$this->assertSame( 'Taxed line', $item->name() );
		$this->assertEquals( 25.00, $item->unit_amount()->value() );
		$this->assertEquals( 1.50, $item->tax()->value() );
		$this->assertEquals( 10.00, $item->discount()->value() );
	}

	/**
	 * @testdox Should still build an item when the product of the order line is gone.
	 */
	public function test_from_wc_order_item_without_a_product(): void {
		$order = $this->make_order();
		$line  = new WC_Order_Item_Product();
		$line->set_name( 'Removed product' );
		$line->set_quantity( 1 );
		$line->set_subtotal( 9 );
		$line->set_total( 9 );
		$order->add_item( $line );

		$result = $this->sut->from_wc_order( $order );

		$item = current( $result );
		$this->assertSame( 'Removed product', $item->name() );
		$this->assertSame( '', $item->description() );
		$this->assertSame( '', $item->sku() );
		$this->assertSame( '', $item->url() );
		$this->assertEquals( Item::PHYSICAL_GOODS, $item->category() );
		$this->assertEquals( 9.00, $item->unit_amount()->value() );
		$this->assertNull( $item->product_id() );
	}

	/**
	 * @testdox Should add the fees of an order as items of one unit each.
	 */
	public function test_from_wc_order_adds_the_fees(): void {
		$order = $this->make_order( $this->make_product(), 1 );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Handling' );
		$fee->set_amount( 7.5 );
		$fee->set_total( 7.5 );
		$order->add_item( $fee );

		$result = $this->sut->from_wc_order( $order );

		$this->assertCount( 2, $result );
		$fee_item = end( $result );
		$this->assertSame( 'Handling', $fee_item->name() );
		$this->assertEquals( 7.5, $fee_item->unit_amount()->value() );
		$this->assertSame( 'USD', $fee_item->unit_amount()->currency_code() );
		$this->assertSame( 1, $fee_item->quantity() );
	}

	/**
	 * @testdox Should build the item from a PayPal response.
	 */
	public function test_from_pay_pal_response(): void {
		$response = (object) array(
			'name'        => 'name',
			'description' => 'description',
			'quantity'    => 1,
			'unit_amount' => (object) array(
				'value'         => 1,
				'currency_code' => 'EUR',
			),
		);

		$item = $this->sut->from_paypal_response( $response );

		$this->assertInstanceOf( Item::class, $item );
		$this->assertEquals( 'name', $item->name() );
		$this->assertEquals( 'description', $item->description() );
		$this->assertEquals( 1, $item->quantity() );
		$this->assertEquals( Item::PHYSICAL_GOODS, $item->category() );
		$this->assertEquals( 1, $item->unit_amount()->value() );
		$this->assertNull( $item->tax() );
	}

	/**
	 * @testdox Should read the category from the PayPal response.
	 */
	public function test_from_pay_pal_response_digital_good(): void {
		$response = (object) array(
			'name'        => 'name',
			'description' => 'description',
			'quantity'    => 1,
			'unit_amount' => (object) array(
				'value'         => 1,
				'currency_code' => 'USD',
			),
			'category'    => Item::DIGITAL_GOODS,
		);

		$item = $this->sut->from_paypal_response( $response );

		$this->assertEquals( Item::DIGITAL_GOODS, $item->category() );
	}

	/**
	 * @testdox Should read the tax from the PayPal response.
	 */
	public function test_from_pay_pal_response_has_tax(): void {
		$response = (object) array(
			'name'        => 'name',
			'description' => 'description',
			'quantity'    => 1,
			'unit_amount' => (object) array(
				'value'         => 1,
				'currency_code' => 'EUR',
			),
			'tax'         => (object) array(
				'value'         => 100,
				'currency_code' => 'EUR',
			),
		);

		$item = $this->sut->from_paypal_response( $response );

		$this->assertEquals( 100, $item->tax()->value() );
	}

	/**
	 * @testdox Should throw when the PayPal response has no name, no quantity, a quantity that is not a number or no usable unit amount.
	 *
	 * @dataProvider data_from_pay_pal_response_throws
	 *
	 * @param array $overrides What replaces or removes (null) parts of an otherwise complete response.
	 */
	public function test_from_pay_pal_response_throws( array $overrides ): void {
		$response = (object) array_merge(
			array(
				'name'        => 'name',
				'description' => 'description',
				'quantity'    => 1,
				'unit_amount' => (object) array(
					'value'         => 1,
					'currency_code' => 'EUR',
				),
				'tax'         => (object) array(
					'value'         => 100,
					'currency_code' => 'EUR',
				),
			),
			$overrides
		);
		foreach ( $overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $response->{$key} );
			}
		}

		$this->expectException( RuntimeException::class );
		$this->sut->from_paypal_response( $response );
	}

	/**
	 * The malformed responses. Names follow the extension's cases: without name, without quantity, with a string in the
	 * quantity and with a wrong unit amount.
	 *
	 * @return array<string, array>
	 */
	public function data_from_pay_pal_response_throws(): array {
		return array(
			'without_name'            => array( array( 'name' => null ) ),
			'without_quantity'        => array( array( 'quantity' => null ) ),
			'with_string_in_quantity' => array( array( 'quantity' => 'should-not-be-a-string' ) ),
			'with_wrong_unit_amount'  => array( array( 'unit_amount' => (object) array() ) ),
		);
	}
}
