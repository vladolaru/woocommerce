<?php
/**
 * Tests for the amount factory.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Amount;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Item;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\AmountFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ItemFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\MoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\CurrencyGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\CartTotals;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Entity\Money as StoreApiMoney;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Cart;
use WC_Coupon;
use WC_Helper_Product;
use WC_Helper_Shipping;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;

/**
 * Builds the PayPal amount and its breakdown from a cart, a Store API cart, an order or a PayPal response. The total
 * WooCommerce reports is the amount PayPal charges, so any gap between it and the breakdown goes into the tax, or into
 * the discount when the gap is deeper than the tax.
 *
 * The extension's mocked carts and orders are real objects here: the cases that need totals which do not add up (a
 * plugin that reduced the total behind WooCommerce's back) set the totals with WC_Cart::set_totals() and the order
 * setters, and the cases that need a real shopper walk the cart.
 *
 * @group paypal-wallet
 */
class AmountFactoryTest extends WalletTestCase {

	/**
	 * The currency of the test shop.
	 */
	private const CURRENCY = 'EUR';

	/**
	 * The mocked item factory the order cases hand their items through.
	 *
	 * @var ItemFactory
	 */
	private $item_factory;

	/**
	 * The System Under Test.
	 *
	 * @var AmountFactory
	 */
	private $sut;

	/**
	 * Set the shop currency and build the factory with the real money factory and currency getter.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->set_wallet_option( 'woocommerce_currency', self::CURRENCY );
		$this->set_wallet_option( 'woocommerce_price_num_decimals', 2 );

		$this->item_factory = $this->mock( ItemFactory::class );
		$this->sut          = new AmountFactory( $this->item_factory, new MoneyFactory(), new CurrencyGetter() );
	}

	/**
	 * A cart that is not WC()->cart, with exactly the totals the factory reads.
	 *
	 * @param float $subtotal The items before discounts.
	 * @param float $fees     The fees.
	 * @param float $shipping The shipping.
	 * @param float $tax      The tax.
	 * @param float $discount The discount.
	 * @param float $total    The total WooCommerce reports.
	 * @return WC_Cart
	 */
	private function make_cart_with_totals( float $subtotal, float $fees, float $shipping, float $tax, float $discount, float $total ): WC_Cart {
		$cart = new WC_Cart();
		$cart->set_totals(
			array(
				'subtotal'       => $subtotal,
				'fee_total'      => $fees,
				'shipping_total' => $shipping,
				'total_tax'      => $tax,
				'discount_total' => $discount,
				'total'          => $total,
			)
		);

		return $cart;
	}

	/**
	 * An order paid through the PayPal gateway, with exactly the totals the factory reads and no free trial.
	 *
	 * @param float $subtotal The items before discounts.
	 * @param float $tax      The tax.
	 * @param float $shipping The shipping.
	 * @param float $discount The discount.
	 * @param float $total    The total WooCommerce reports.
	 * @param float $fees     The fees.
	 * @return WC_Order
	 */
	private function make_order_with_totals( float $subtotal, float $tax, float $shipping, float $discount, float $total, float $fees = 0.0 ): WC_Order {
		$order = wc_create_order();
		$order->set_currency( self::CURRENCY );
		$order->set_payment_method( PayPalGateway::ID );

		if ( 0.0 !== $subtotal ) {
			$line = new WC_Order_Item_Product();
			$line->set_name( 'Line' );
			$line->set_quantity( 1 );
			$line->set_subtotal( $subtotal );
			$line->set_total( $subtotal );
			$order->add_item( $line );
		}
		if ( 0.0 !== $fees ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Fee' );
			$fee->set_total( $fees );
			$order->add_item( $fee );
		}

		$order->set_shipping_total( $shipping );
		$order->set_cart_tax( $tax );
		$order->set_discount_total( $discount );
		$order->set_total( $total );

		return $order;
	}

	/**
	 * A mock of a PayPal item.
	 *
	 * @param float $unit_amount The unit amount.
	 * @param int   $quantity    The quantity.
	 * @return Item
	 */
	private function make_item( float $unit_amount, int $quantity = 1 ): Item {
		$money = $this->mock( Money::class );
		$money->shouldReceive( 'value' )->andReturn( $unit_amount );
		$item = $this->mock( Item::class );
		$item->shouldReceive( 'quantity' )->andReturn( $quantity );
		$item->shouldReceive( 'unit_amount' )->andReturn( $money );

		return $item;
	}

	/**
	 * The sum of the breakdown of an amount (items, shipping and tax, less the discount), formatted like the amount.
	 *
	 * @param Amount $amount The amount.
	 * @return string
	 */
	private function breakdown_sum( Amount $amount ): string {
		$breakdown = $amount->breakdown();
		$sum       = (float) $breakdown->item_total()->value_str()
			+ (float) $breakdown->shipping()->value_str()
			+ (float) $breakdown->tax_total()->value_str();
		if ( $breakdown->discount() ) {
			$sum -= (float) $breakdown->discount()->value_str();
		}

		return number_format( $sum, 2, '.', '' );
	}

	/**
	 * @testdox Should build the amount from the totals of a cart.
	 */
	public function test_from_wc_cart_default(): void {
		// The components sum to 13 (5 + 3 + 2 + 4 - 1), matching the cart's own total.
		$cart = $this->make_cart_with_totals( 5.0, 3.0, 2.0, 4.0, 1.0, 13.0 );

		$result = $this->sut->from_wc_cart( $cart );

		$this->assertEquals( self::CURRENCY, $result->currency_code() );
		$this->assertEquals( (float) 13, $result->value() );
		$this->assertEquals( (float) 1, $result->breakdown()->discount()->value() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->discount()->currency_code() );
		$this->assertEquals( (float) 2, $result->breakdown()->shipping()->value() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->shipping()->currency_code() );
		$this->assertEquals( (float) 8, $result->breakdown()->item_total()->value() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->item_total()->currency_code() );
		$this->assertEquals( (float) 4, $result->breakdown()->tax_total()->value() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->tax_total()->currency_code() );
	}

	/**
	 * @testdox Should leave the discount out when the cart has none.
	 */
	public function test_from_wc_cart_no_discount(): void {
		// The components sum to 11 (5 + 0 + 2 + 4), matching the cart's own total.
		$cart = $this->make_cart_with_totals( 5.0, 0.0, 2.0, 4.0, 0.0, 11.0 );

		$result = $this->sut->from_wc_cart( $cart );

		$this->assertNull( $result->breakdown()->discount() );
		$this->assertEquals( (float) 11, $result->value() );
		$this->assertEquals( (float) 2, $result->breakdown()->shipping()->value() );
		$this->assertEquals( (float) 5, $result->breakdown()->item_total()->value() );
		$this->assertEquals( (float) 4, $result->breakdown()->tax_total()->value() );
	}

	/**
	 * The critical invariant: amount.value must always equal the formatted sum of its breakdown parts. This pins the
	 * fix for the PATCH rejection that happened when per-item tax rounding made the cart total diverge by one cent.
	 *
	 * @testdox Should always make the total equal the sum of the breakdown for a cart.
	 *
	 * @dataProvider data_breakdown_rounding_cases
	 *
	 * @param float $subtotal The items before discounts.
	 * @param float $fees     The fees.
	 * @param float $shipping The shipping.
	 * @param float $tax      The tax.
	 * @param float $discount The discount.
	 * @param float $wc_total The total WooCommerce reports.
	 */
	public function test_from_wc_cart_total_always_equals_breakdown_sum( float $subtotal, float $fees, float $shipping, float $tax, float $discount, float $wc_total ): void {
		$cart = $this->make_cart_with_totals( $subtotal, $fees, $shipping, $tax, $discount, $wc_total );

		$result = $this->sut->from_wc_cart( $cart );

		$this->assertSame( $this->breakdown_sum( $result ), $result->value_str(), 'amount.value_str() must equal the formatted breakdown sum (PayPal PATCH invariant)' );
	}

	/**
	 * Carts whose totals and component sum agree, differ by one cent either way, or include fees and a discount.
	 *
	 * @return array<string, array<float>>
	 */
	public function data_breakdown_rounding_cases(): array {
		return array(
			// Classic tax-rounding edge case: 8% tax on 13.33 is 1.0664. The total matches the component sum (19.40).
			'tax_rounding_edge_case'                => array( 13.33, 0.0, 5.00, 1.07, 0.0, 19.40 ),
			// Three items at 3.336 each: the item total rounds differently at cart and at item level.
			'multi_item_tax_rounding'               => array( 10.01, 0.0, 4.99, 1.50, 0.0, 16.50 ),
			'with_discount'                         => array( 20.00, 2.00, 3.50, 2.25, 1.75, 26.00 ),
			'with_fees_no_discount'                 => array( 15.50, 4.50, 6.00, 2.60, 0.0, 28.60 ),
			'large_order'                           => array( 199.99, 10.00, 12.95, 22.30, 15.00, 230.24 ),
			'digital_no_shipping'                   => array( 49.99, 0.0, 0.0, 4.50, 0.0, 54.49 ),
			// The total is 1 cent above the component sum (19.40): the gap lands in tax.
			'wc_total_one_cent_above_component_sum' => array( 13.33, 0.0, 5.00, 1.07, 0.0, 19.41 ),
			// The total is 1 cent below the component sum (19.40): tax absorbs the gap.
			'wc_total_one_cent_below_component_sum' => array( 13.33, 0.0, 5.00, 1.07, 0.0, 19.39 ),
		);
	}

	/**
	 * Given a cart whose breakdown components sum to 19.40 but whose own total is 19.39 (a third-party plugin reduced
	 * the total directly), the amount equals the cart's total and the tax absorbs the one-cent gap.
	 *
	 * @testdox Should use the cart's own total and let the tax absorb the gap.
	 */
	public function test_from_wc_cart_total_reconciles_against_wc_get_total(): void {
		// The components sum to 19.40 (1333 + 500 + 107 cents).
		$cart = $this->make_cart_with_totals( 13.33, 0.0, 5.00, 1.07, 0.0, 19.39 );

		$result = $this->sut->from_wc_cart( $cart );

		$this->assertSame( '19.39', $result->value_str() );
		$this->assertSame( '13.33', $result->breakdown()->item_total()->value_str() );
		$this->assertSame( '5.00', $result->breakdown()->shipping()->value_str() );
		$this->assertSame( '1.06', $result->breakdown()->tax_total()->value_str() );
	}

	/**
	 * @testdox Should build the amount from the totals of the Store API cart.
	 *
	 * @dataProvider data_from_store_api_cart
	 *
	 * @param int         $items             The items, in cents.
	 * @param int         $fees              The fees, in cents.
	 * @param int         $shipping          The shipping, in cents.
	 * @param int         $tax               The tax, in cents.
	 * @param int         $discount          The discount, in cents.
	 * @param int         $total_price_minor The total price, in cents.
	 * @param string      $expected_total    The expected amount.
	 * @param string      $expected_items    The expected item total.
	 * @param string      $expected_shipping The expected shipping.
	 * @param string      $expected_tax      The expected tax.
	 * @param string|null $expected_discount The expected discount, or null for none.
	 */
	public function test_from_store_api_cart( int $items, int $fees, int $shipping, int $tax, int $discount, int $total_price_minor, string $expected_total, string $expected_items, string $expected_shipping, string $expected_tax, ?string $expected_discount ): void {
		$totals = $this->make_store_api_totals( $items, $fees, $shipping, $tax, $discount, $total_price_minor );

		$result    = $this->sut->from_store_api_cart( $totals );
		$breakdown = $result->breakdown();

		$this->assertSame( $expected_total, $result->value_str(), 'amount.value_str' );
		$this->assertSame( $expected_items, $breakdown->item_total()->value_str(), 'item_total includes fees' );
		$this->assertSame( $expected_shipping, $breakdown->shipping()->value_str(), 'shipping' );
		$this->assertSame( $expected_tax, $breakdown->tax_total()->value_str(), 'tax_total' );
		if ( null === $expected_discount ) {
			$this->assertNull( $breakdown->discount(), 'discount should be null' );
		} else {
			$this->assertSame( $expected_discount, $breakdown->discount()->value_str(), 'discount' );
		}
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ), 'breakdown invariant' );
	}

	/**
	 * Store API totals in cents, in USD.
	 *
	 * @param int $items    The items.
	 * @param int $fees     The fees.
	 * @param int $shipping The shipping.
	 * @param int $tax      The tax.
	 * @param int $discount The discount.
	 * @param int $total    The total price.
	 * @return CartTotals
	 */
	private function make_store_api_totals( int $items, int $fees, int $shipping, int $tax, int $discount, int $total ): CartTotals {
		$make = static function ( int $value ): StoreApiMoney {
			return new StoreApiMoney( (string) $value, 'USD', 2 );
		};

		return new CartTotals( $make( $items ), $make( 0 ), $make( $fees ), $make( 0 ), $make( $discount ), $make( 0 ), $make( $shipping ), $make( 0 ), $make( $total ), $make( $tax ) );
	}

	/**
	 * Store API carts with and without fees and a discount. The total price is authoritative for the amount.
	 *
	 * @return array<string, array>
	 */
	public function data_from_store_api_cart(): array {
		return array(
			// Items 1000, shipping 500, tax 180, total 1680.
			'no_fees_no_discount' => array( 1000, 0, 500, 180, 0, 1680, '16.80', '10.00', '5.00', '1.80', null ),
			// Fees are part of the item total: 1000 + 200 = 1200.
			'with_fees'           => array( 1000, 200, 500, 180, 0, 1880, '18.80', '12.00', '5.00', '1.80', null ),
			// A discount reduces the total.
			'with_discount'       => array( 2000, 0, 500, 225, 150, 2575, '25.75', '20.00', '5.00', '2.25', '1.50' ),
			// Fees and a discount together.
			'fees_and_discount'   => array( 1500, 300, 700, 250, 100, 2650, '26.50', '18.00', '7.00', '2.50', '1.00' ),
		);
	}

	/**
	 * @testdox Should add the extra discount a filter reports to the discount of the Store API cart.
	 */
	public function test_from_store_api_cart_applies_extra_discount_filter(): void {
		$totals = $this->make_store_api_totals( 10000, 0, 1000, 0, 0, 8500 );
		$calls  = $this->spy_filter( 'woocommerce_paypal_payments_store_api_cart_extra_discount', 2500 );

		$result = $this->sut->from_store_api_cart( $totals );

		$this->assertCount( 1, $calls );
		$this->assertSame( 0, $calls[0][0] );
		$this->assertSame( $totals, $calls[0][1] );
		$this->assertSame( '85.00', $result->value_str() );
		$this->assertSame( '25.00', $result->breakdown()->discount()->value_str() );
		$this->assertSame( '85.00', $this->breakdown_sum( $result ) );
	}

	/**
	 * @testdox Should build the amount from the totals of an order.
	 */
	public function test_from_wc_order_default(): void {
		$order = $this->make_order_with_totals( 6.0, 2.0, 1.0, 3.0, 10.0, 4.0 );
		$this->item_factory->expects( 'from_wc_order' )->with( $order )->andReturn( array( $this->make_item( 3.0, 2 ) ) );

		$result = $this->sut->from_wc_order( $order );

		$this->assertEquals( (float) 3, $result->breakdown()->discount()->value() );
		$this->assertEquals( (float) 10, $result->breakdown()->item_total()->value() );
		$this->assertEquals( (float) 1, $result->breakdown()->shipping()->value() );
		$this->assertEquals( (float) 10, $result->value() );
		$this->assertEquals( (float) 2, $result->breakdown()->tax_total()->value() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->discount()->currency_code() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->item_total()->currency_code() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->shipping()->currency_code() );
		$this->assertEquals( self::CURRENCY, $result->breakdown()->tax_total()->currency_code() );
		$this->assertEquals( self::CURRENCY, $result->currency_code() );
	}

	/**
	 * @testdox Should leave the discount out when the order has none.
	 */
	public function test_from_wc_order_discount_is_null(): void {
		$order = $this->make_order_with_totals( 6.0, 2.0, 1.0, 0.0, 9.0 );
		$this->item_factory->expects( 'from_wc_order' )->with( $order )->andReturn( array( $this->make_item( 3.0, 2 ) ) );

		$result = $this->sut->from_wc_order( $order );

		$this->assertNull( $result->breakdown()->discount() );
	}

	/**
	 * @testdox Should use the order total and leave the breakdown unchanged when the components match it.
	 */
	public function test_from_wc_order_uses_get_total_when_components_match(): void {
		// The components sum to 19.40 (1333 + 500 + 107 cents).
		$order = $this->make_order_with_totals( 13.33, 1.07, 5.00, 0.0, 19.40 );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result = $this->sut->from_wc_order( $order );

		$this->assertSame( '19.40', $result->value_str() );
		$this->assertSame( '13.33', $result->breakdown()->item_total()->value_str() );
		$this->assertSame( '5.00', $result->breakdown()->shipping()->value_str() );
		$this->assertSame( '1.07', $result->breakdown()->tax_total()->value_str() );
		$this->assertNull( $result->breakdown()->discount() );
	}

	/**
	 * The core regression fix: when the order total differs from the component sum by one cent (inclusive-tax
	 * rounding), amount.value equals the order total and the tax absorbs the gap so the breakdown still sums up.
	 * Concretely, the subtotal 13.90 and the tax 2.65 sum to 16.55 but WooCommerce stored 16.54. PayPal must capture
	 * 16.54 so a full refund of 16.54 is accepted without REFUND_AMOUNT_EXCEEDED.
	 *
	 * @testdox Should let the tax absorb a one-cent gap between the order total and the component sum.
	 */
	public function test_from_wc_order_adjusts_tax_breakdown_when_get_total_differs_from_component_sum(): void {
		$order = $this->make_order_with_totals( 13.90, 2.65, 0.0, 0.0, 16.54 );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result    = $this->sut->from_wc_order( $order );
		$breakdown = $result->breakdown();

		$this->assertSame( '16.54', $result->value_str(), 'amount.value must equal the order total' );
		$this->assertSame( '2.64', $breakdown->tax_total()->value_str(), 'tax adjusted down by 1 cent' );
		$this->assertSame( '13.90', $breakdown->item_total()->value_str() );
		$this->assertSame( '0.00', $breakdown->shipping()->value_str() );
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ), 'breakdown invariant' );
	}

	/**
	 * @testdox Should always make the breakdown of an order sum to the order total.
	 *
	 * @dataProvider data_order_breakdown_invariant_cases
	 *
	 * @param float $subtotal The items before discounts.
	 * @param float $fees     The fees.
	 * @param float $shipping The shipping.
	 * @param float $tax      The tax.
	 * @param float $discount The discount.
	 * @param float $wc_total The total WooCommerce reports.
	 */
	public function test_from_wc_order_breakdown_always_sums_to_get_total( float $subtotal, float $fees, float $shipping, float $tax, float $discount, float $wc_total ): void {
		$order = $this->make_order_with_totals( $subtotal, $tax, $shipping, $discount, $wc_total, $fees );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result = $this->sut->from_wc_order( $order );

		$this->assertSame( number_format( $wc_total, 2, '.', '' ), $result->value_str(), 'amount.value must equal the order total' );
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ), 'breakdown invariant: sum(breakdown) must equal amount.value' );
	}

	/**
	 * Orders whose total matches the component sum, or differs by one cent either way, with discounts and fees.
	 *
	 * @return array<string, array<float>>
	 */
	public function data_order_breakdown_invariant_cases(): array {
		return array(
			'no_delta'            => array( 13.33, 0.0, 5.00, 1.07, 0.0, 19.40 ),
			'delta_plus_one'      => array( 13.90, 0.0, 0.0, 2.65, 0.0, 16.56 ),
			'delta_minus_one'     => array( 13.90, 0.0, 0.0, 2.65, 0.0, 16.54 ),
			'with_discount'       => array( 20.00, 2.00, 3.50, 2.25, 1.75, 26.00 ),
			'with_discount_delta' => array( 20.00, 2.00, 3.50, 2.26, 1.75, 26.00 ),
			'with_fees_delta'     => array( 15.50, 4.50, 6.00, 2.60, 0.0, 28.59 ),
		);
	}

	/**
	 * An unreported discount (the total set directly, or an order read before the discount is stored) used to push the
	 * whole gap into the tax.
	 *
	 * @testdox Should restore the tax and book an unreported discount as a discount.
	 *
	 * @dataProvider data_unreported_discount_cases
	 *
	 * @param float  $subtotal          The items before discounts.
	 * @param float  $tax               The tax.
	 * @param float  $shipping          The shipping.
	 * @param float  $reported_discount The discount the order reports.
	 * @param float  $wc_total          The total WooCommerce reports.
	 * @param string $expected_tax      The expected tax.
	 * @param string $expected_discount The expected discount.
	 */
	public function test_from_wc_order_restores_tax_and_books_unreported_discount( float $subtotal, float $tax, float $shipping, float $reported_discount, float $wc_total, string $expected_tax, string $expected_discount ): void {
		$order = $this->make_order_with_totals( $subtotal, $tax, $shipping, $reported_discount, $wc_total );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result    = $this->sut->from_wc_order( $order );
		$breakdown = $result->breakdown();

		$this->assertSame( $expected_tax, $breakdown->tax_total()->value_str() );
		$this->assertSame( $expected_discount, $breakdown->discount()->value_str() );
		$this->assertSame( number_format( $wc_total, 2, '.', '' ), $this->breakdown_sum( $result ) );
	}

	/**
	 * Gaps deeper than the tax.
	 *
	 * @return array<string, array>
	 */
	public function data_unreported_discount_cases(): array {
		return array(
			// The first two rows must produce the same split, reported or not.
			'unreported_discount_smaller_than_tax'      => array( 100.0, 19.0, 0.0, 0.0, 69.0, '19.00', '50.00' ),
			'reported_discount_matches_unreported_case' => array( 100.0, 19.0, 0.0, 50.0, 69.0, '19.00', '50.00' ),
			'zero_tax_whole_item_total_discounted'      => array( 45.01, 0.0, 0.0, 0.0, 0.01, '0.00', '45.00' ),
			// A negative fee can make WooCommerce report negative tax.
			'negative_wc_tax_is_floored_at_zero'        => array( 100.0, -5.0, 0.0, 0.0, 60.0, '0.00', '40.00' ),
		);
	}

	/**
	 * Guards the behaviour that predates the fallback: the delta_minus_one case above, asserted on the split rather
	 * than on the invariant.
	 *
	 * @testdox Should keep a small rounding gap in the tax and invent no discount.
	 */
	public function test_from_wc_order_small_rounding_delta_stays_in_tax_not_discount(): void {
		$order = $this->make_order_with_totals( 13.90, 2.65, 0.0, 0.0, 16.54 );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result    = $this->sut->from_wc_order( $order );
		$breakdown = $result->breakdown();

		$this->assertSame( '2.64', $breakdown->tax_total()->value_str() );
		$this->assertNull( $breakdown->discount() );
	}

	/**
	 * A manually created order with a negative fee must not be undercharged on the PayPal side. The negative fee is
	 * surfaced as a discount and filtered out of the items sent to PayPal, so it must not also be netted out of the
	 * item total, or it is counted twice. The order: a product of 100 and a fee of -10, a real total of 90.
	 *
	 * @testdox Should not count a negative fee twice.
	 */
	public function test_from_wc_order_negative_fee_not_double_counted(): void {
		$order = $this->make_order_with_totals( 100.0, 0.0, 0.0, 0.0, 90.0, -10.0 );
		$this->item_factory
			->expects( 'from_wc_order' )
			->with( $order )
			->andReturn( array( $this->make_item( 100.0 ), $this->make_item( -10.0 ) ) );

		$result = $this->sut->from_wc_order( $order );

		// The item total matches the positive item sent to PayPal, the negative fee is only a discount of 10.
		$this->assertSame( '100.00', $result->breakdown()->item_total()->value_str() );
		$this->assertSame( '10.00', $result->breakdown()->discount()->value_str() );
		$this->assertSame( '90.00', $result->value_str() );
	}

	/**
	 * Plugins like WooCommerce Gift Cards apply discounts through WC_Cart::set_total() rather than coupons or fees.
	 * The filter lets them contribute the missing amount so the PayPal total matches the cart total.
	 *
	 * @testdox Should add the extra discount a filter reports to the discount of the cart.
	 */
	public function test_from_wc_cart_applies_extra_discount_filter(): void {
		// The components (100 + 10 - 25 extra discount) sum to the cart's own total of 85.
		$cart  = $this->make_cart_with_totals( 100.0, 0.0, 10.0, 0.0, 0.0, 85.0 );
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_cart_extra_discount', 25.0 );

		$result    = $this->sut->from_wc_cart( $cart );
		$breakdown = $result->breakdown();

		$this->assertCount( 1, $calls );
		$this->assertSame( 0.0, $calls[0][0] );
		$this->assertSame( $cart, $calls[0][1] );
		$this->assertSame( '85.00', $result->value_str() );
		$this->assertSame( '25.00', $breakdown->discount()->value_str() );
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ) );
	}

	/**
	 * Given a cart whose total was reduced directly (a discount-rules plugin calling WC_Cart::set_total() without a
	 * coupon or a fee), so the discount total still reports zero: the amount equals the cart's real total, the tax is
	 * not driven negative and the unreported gap shows up as the discount.
	 *
	 * @testdox Should book the unreported gap below the cart total as a discount.
	 */
	public function test_from_wc_cart_unreported_discount_below_total_surfaces_as_discount(): void {
		// The components sum to 119 (100 + 0 + 19), the cart's own total reflects the real, discounted price of 69.
		$cart = $this->make_cart_with_totals( 100.0, 0.0, 0.0, 19.0, 0.0, 69.0 );

		$result    = $this->sut->from_wc_cart( $cart );
		$breakdown = $result->breakdown();

		$this->assertSame( '69.00', $result->value_str() );
		$this->assertSame( '19.00', $breakdown->tax_total()->value_str() );
		$this->assertSame( '50.00', $breakdown->discount()->value_str() );
	}

	/**
	 * The same undiscounted-total shape as above, read through the Store API cart totals of the block-based checkout:
	 * the amount equals the total price, the tax is not driven negative and the gap shows up as the discount.
	 *
	 * @testdox Should book the unreported gap below the Store API total as a discount.
	 */
	public function test_from_store_api_cart_unreported_discount_below_total_surfaces_as_discount(): void {
		$totals = $this->make_store_api_totals( 10000, 0, 0, 1900, 0, 6900 );

		$result    = $this->sut->from_store_api_cart( $totals );
		$breakdown = $result->breakdown();

		$this->assertSame( '69.00', $result->value_str() );
		$this->assertSame( '19.00', $breakdown->tax_total()->value_str() );
		$this->assertSame( '50.00', $breakdown->discount()->value_str() );
	}

	/**
	 * @testdox Should keep a gap smaller than the tax in the tax and invent no discount.
	 */
	public function test_from_wc_cart_gap_smaller_than_tax_stays_in_tax_not_discount(): void {
		// The components sum to 119 (100 + 0 + 19), the cart total is 115: a gap of 4.
		$cart = $this->make_cart_with_totals( 100.0, 0.0, 0.0, 19.0, 0.0, 115.0 );

		$result    = $this->sut->from_wc_cart( $cart );
		$breakdown = $result->breakdown();

		$this->assertSame( '115.00', $result->value_str() );
		$this->assertSame( '15.00', $breakdown->tax_total()->value_str() );
		$this->assertNull( $breakdown->discount() );
	}

	/**
	 * @testdox Should adjust nothing when the cart total matches the component sum.
	 */
	public function test_from_wc_cart_matching_total_leaves_breakdown_unadjusted(): void {
		// The components sum to exactly 59, nothing to reconcile.
		$cart = $this->make_cart_with_totals( 50.0, 0.0, 5.0, 4.0, 0.0, 59.0 );

		$result    = $this->sut->from_wc_cart( $cart );
		$breakdown = $result->breakdown();

		$this->assertSame( '59.00', $result->value_str() );
		$this->assertSame( '50.00', $breakdown->item_total()->value_str() );
		$this->assertSame( '5.00', $breakdown->shipping()->value_str() );
		$this->assertSame( '4.00', $breakdown->tax_total()->value_str() );
		$this->assertNull( $breakdown->discount() );
	}

	/**
	 * Given a cart total above the component sum (a plugin adding a surcharge directly to the cart total), the amount
	 * equals the higher total and the extra amount lands in the tax.
	 *
	 * @testdox Should put a surcharge above the component sum into the tax.
	 */
	public function test_from_wc_cart_surcharge_above_component_sum_lands_in_tax(): void {
		// The components sum to 110 (100 + 0 + 10), the cart total is 2 higher.
		$cart = $this->make_cart_with_totals( 100.0, 0.0, 0.0, 10.0, 0.0, 112.0 );

		$result    = $this->sut->from_wc_cart( $cart );
		$breakdown = $result->breakdown();

		$this->assertSame( '112.00', $result->value_str() );
		$this->assertSame( '12.00', $breakdown->tax_total()->value_str() );
		$this->assertNull( $breakdown->discount() );
	}

	/**
	 * @testdox Should add the extra discount a filter reports to the discount of the order.
	 */
	public function test_from_wc_order_applies_extra_discount_filter(): void {
		$order = $this->make_order_with_totals( 100.0, 0.0, 10.0, 0.0, 85.0 );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_order_extra_discount', 25.0 );

		$result    = $this->sut->from_wc_order( $order );
		$breakdown = $result->breakdown();

		$this->assertCount( 1, $calls );
		$this->assertSame( 0.0, $calls[0][0] );
		$this->assertSame( $order, $calls[0][1] );
		$this->assertSame( '85.00', $result->value_str() );
		$this->assertSame( '25.00', $breakdown->discount()->value_str() );
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ) );
	}

	/**
	 * @testdox Should charge the zero total of a card-funded PayPal order as it is when no subscription makes it a free trial.
	 */
	public function test_from_wc_order_card_order_without_subscription_keeps_its_total(): void {
		$order = $this->make_order_with_totals( 0.0, 0.0, 0.0, 0.0, 0.0 );
		$order->update_meta_data( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY, 'card' );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result = $this->sut->from_wc_order( $order );

		$this->assertSame( '0.00', $result->value_str() );
	}

	/**
	 * A real shopper: two products of 10.00 in the cart with a flat rate of 5.00 shipping, a 10% tax and a coupon of
	 * 3.00 off the cart. The cart total WooCommerce computes is the amount, and the breakdown adds up to it.
	 *
	 * @testdox Should build the amount from a real cart with a coupon, shipping and tax.
	 */
	public function test_from_wc_cart_real_cart_with_coupon_shipping_and_tax(): void {
		if ( ! WC()->cart ) {
			wc_load_cart();
		}
		$this->use_own_wc_session();
		$this->set_wallet_option( 'woocommerce_calc_taxes', 'yes' );
		$this->set_wallet_option( 'woocommerce_prices_include_tax', 'no' );
		$this->insert_flat_tax_rate( '10.0000' );
		WC_Helper_Shipping::create_simple_flat_rate( 5 );

		try {
			WC()->customer->set_shipping_country( 'US' );
			WC()->customer->set_shipping_state( 'NY' );
			WC()->customer->set_shipping_postcode( '10001' );

			$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => 10 ) );
			$coupon  = new WC_Coupon();
			$coupon->set_code( 'minus3' );
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( 3 );
			$coupon->save();

			WC()->cart->add_to_cart( $product->get_id(), 2 );
			WC()->cart->apply_coupon( 'minus3' );
			WC()->cart->calculate_totals();

			$result = $this->sut->from_wc_cart( WC()->cart );
		} finally {
			WC_Helper_Shipping::delete_simple_flat_rate();
		}

		$breakdown = $result->breakdown();

		// Items 20.00 - 3.00 discount + 5.00 shipping, taxed at 10%: 1.70 on the items and 0.50 on the shipping.
		$this->assertSame( '20.00', $breakdown->item_total()->value_str() );
		$this->assertSame( '3.00', $breakdown->discount()->value_str() );
		$this->assertSame( '5.00', $breakdown->shipping()->value_str() );
		$this->assertSame( '2.20', $breakdown->tax_total()->value_str() );
		$this->assertSame( '24.20', $result->value_str() );
		$this->assertSame( $result->value_str(), $this->breakdown_sum( $result ) );
	}

	/**
	 * @testdox Should build the amount from a real order with products, a coupon discount and shipping.
	 */
	public function test_from_wc_order_real_order(): void {
		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => 10 ) );
		$order   = wc_create_order();
		$order->set_currency( self::CURRENCY );
		$order->set_payment_method( PayPalGateway::ID );
		$order->add_product( $product, 3 );
		$order->set_shipping_total( 4 );
		$order->set_discount_total( 5 );
		$order->set_total( 29 );
		$this->item_factory->shouldReceive( 'from_wc_order' )->andReturn( array() );

		$result    = $this->sut->from_wc_order( $order );
		$breakdown = $result->breakdown();

		$this->assertSame( '30.00', $breakdown->item_total()->value_str() );
		$this->assertSame( '4.00', $breakdown->shipping()->value_str() );
		$this->assertSame( '5.00', $breakdown->discount()->value_str() );
		$this->assertSame( '0.00', $breakdown->tax_total()->value_str() );
		$this->assertSame( '29.00', $result->value_str() );
		$this->assertSame( self::CURRENCY, $result->currency_code() );
	}

	/**
	 * @testdox Should build the amount and its breakdown from a PayPal response, or throw when the response is malformed.
	 *
	 * @dataProvider data_from_paypal_response
	 *
	 * @param object $response         The amount object PayPal sent.
	 * @param bool   $expects_exception Whether the response is malformed.
	 */
	public function test_from_pay_pal_response( $response, bool $expects_exception ): void {
		if ( $expects_exception ) {
			$this->expectException( RuntimeException::class );
		}

		$result = $this->sut->from_paypal_response( $response );

		if ( $expects_exception ) {
			return;
		}

		$this->assertEquals( $response->value, $result->value() );
		$this->assertEquals( $response->currency_code, $result->currency_code() );
		$breakdown = $result->breakdown();
		if ( ! isset( $response->breakdown ) ) {
			$this->assertNull( $breakdown );
			return;
		}
		foreach ( self::BREAKDOWN_KEYS as $key => $getter ) {
			$part = $breakdown->{$getter}();
			if ( $part ) {
				$this->assertEquals( $response->breakdown->{$key}->value, $part->value(), $key );
				$this->assertEquals( $response->breakdown->{$key}->currency_code, $part->currency_code(), $key );
			} else {
				$this->assertFalse( isset( $response->breakdown->{$key} ), $key );
			}
		}
	}

	/**
	 * The breakdown parts of a PayPal amount, each with the getter that reads it back.
	 */
	private const BREAKDOWN_KEYS = array(
		'discount'          => 'discount',
		'shipping_discount' => 'shipping_discount',
		'insurance'         => 'insurance',
		'handling'          => 'handling',
		'tax_total'         => 'tax_total',
		'shipping'          => 'shipping',
		'item_total'        => 'item_total',
	);

	/**
	 * A complete amount, the amount with each breakdown part left out in turn, and the malformed amounts.
	 *
	 * @return array<string, array>
	 */
	public function data_from_paypal_response(): array {
		$currencies = array(
			'discount'          => 'B',
			'shipping_discount' => 'C',
			'insurance'         => 'D',
			'handling'          => 'E',
			'tax_total'         => 'F',
			'shipping'          => 'G',
			'item_total'        => 'H',
		);
		$values     = array(
			'discount'          => 2.0,
			'shipping_discount' => 3.0,
			'insurance'         => 4.0,
			'handling'          => 5.0,
			'tax_total'         => 6.0,
			'shipping'          => 7.0,
			'item_total'        => 8.0,
		);

		$breakdown = static function ( array $without = array() ) use ( $currencies, $values ): object {
			$parts = array();
			foreach ( $currencies as $key => $currency ) {
				if ( in_array( $key, $without, true ) ) {
					continue;
				}
				$parts[ $key ] = (object) array(
					'value'         => $values[ $key ],
					'currency_code' => $currency,
				);
			}
			return (object) $parts;
		};

		$amount = static function ( array $without = array() ) use ( $breakdown ): object {
			return (object) array(
				'value'         => 1.0,
				'currency_code' => 'A',
				'breakdown'     => $breakdown( $without ),
			);
		};

		return array(
			'no_value'                      => array( (object) array( 'currency_code' => 'A' ), true ),
			'no_currency_code'              => array( (object) array( 'value' => 1.0 ), true ),
			'no_value_in_breakdown'         => array(
				(object) array(
					'value'         => 1.0,
					'currency_code' => 'A',
					'breakdown'     => (object) array( 'discount' => (object) array( 'currency_code' => 'B' ) ),
				),
				true,
			),
			'no_currency_code_in_breakdown' => array(
				(object) array(
					'value'         => 1.0,
					'currency_code' => 'A',
					'breakdown'     => (object) array( 'discount' => (object) array( 'value' => 2.0 ) ),
				),
				true,
			),
			'default'                       => array( $amount(), false ),
			'no_item_total'                 => array( $amount( array( 'item_total' ) ), false ),
			'no_tax_total'                  => array( $amount( array( 'tax_total' ) ), false ),
			'no_handling'                   => array( $amount( array( 'handling' ) ), false ),
			'no_insurance'                  => array( $amount( array( 'insurance' ) ), false ),
			'no_shipping_discount'          => array( $amount( array( 'shipping_discount' ) ), false ),
			'no_discount'                   => array( $amount( array( 'discount' ) ), false ),
			'no_shipping'                   => array( $amount( array( 'shipping' ) ), false ),
		);
	}
}
