<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use WC_Helper_Product;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsLevel3Service class.
 *
 * Expected values follow client 11.3.0's tests/unit/src/Internal/Service/Level3ServiceTest.php.
 */
class WooPaymentsLevel3ServiceTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_store_postcode' );
		remove_all_filters( 'wcpay_payment_request_level3_data' );
		parent::tearDown();
	}

	/**
	 * @testdox Should send no Level 3 data for non-US merchant accounts.
	 */
	public function test_returns_nothing_for_non_us_accounts(): void {
		$order = wc_create_order();

		$this->assertSame( array(), $this->make_sut( 'RO' )->get_data_from_order( $order ) );
	}

	/**
	 * @testdox Should build the full Level 3 payload for a US account, ZIP codes included when valid.
	 */
	public function test_builds_level3_payload_for_us_account(): void {
		update_option( 'woocommerce_store_postcode', '94103' );

		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '10.00' ) );
		$order   = wc_create_order();
		$item_id = $order->add_product( $product, 2 );
		$order->set_shipping_postcode( '10001' );
		$order->calculate_totals();
		// Set after calculate_totals(): recalculation derives shipping from
		// shipping items, and this order deliberately has none; it would also
		// recalculate the line tax with no tax rates and drop it.
		$order->set_shipping_total( '5.00' );
		$item = $order->get_item( $item_id, false );
		$item->set_taxes(
			array(
				'total'    => array( 1 => '1.23' ),
				'subtotal' => array( 1 => '1.23' ),
			)
		);
		$item->save();
		$order->set_total( '26.23' );
		$order->save();

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertSame( (string) $order->get_id(), $level3['merchant_reference'] );
		$this->assertSame( (string) $order->get_id(), $level3['customer_reference'] );
		$this->assertSame( 500, $level3['shipping_amount'] );
		$this->assertSame( '10001', $level3['shipping_address_zip'] );
		$this->assertSame( '94103', $level3['shipping_from_zip'] );
		$this->assertCount( 1, $level3['line_items'] );
		$line_item = $level3['line_items'][0];
		$this->assertSame( (string) $product->get_id(), $line_item->product_code );
		$this->assertSame( 2, $line_item->quantity );
		$this->assertSame( 1000, $line_item->unit_cost );
		$this->assertSame( 0, $line_item->discount_amount );
		$this->assertSame( 123, $line_item->tax_amount, 'A 1.23 USD line tax should be sent in cents.' );
	}

	/**
	 * Client 11.3.0 tests "unit cost rounded up is balanced with a discount", "lines rounded separately are balanced against
	 * the order total", "no level3 data when the order total differs from its items", "no level3 data when an order without
	 * items charges more than shipping" and "no level3 data when a zero quantity item carries money".
	 *
	 * @testdox Should balance the line items against the order total once, or send nothing when they cannot match: $_dataName.
	 * @dataProvider provide_payloads_reconciled_with_the_order_total
	 *
	 * @param array<int,array{0:string,1:int,2:float,3:float}> $lines    Rows of name, quantity, line amount and line tax.
	 * @param float                                            $total    Order total; shipping is 30.00 plus 8.00 tax.
	 * @param array<int,array<string,mixed>>|null              $expected Expected line items, or null for no Level 3 data.
	 */
	public function test_payloads_are_reconciled_with_the_order_total( array $lines, float $total, ?array $expected ): void {
		$level3 = $this->make_sut( 'US' )->get_data_from_order( $this->make_order( $lines, $total ) );

		if ( null === $expected ) {
			$this->assertSame( array(), $level3 );
			return;
		}
		$this->assertEquals( array_map( fn( array $line ) => (object) $line, $expected ), $level3['line_items'] );
	}

	/**
	 * Orders whose line items need balancing, or cannot be balanced.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_payloads_reconciled_with_the_order_total(): array {
		return array(
			'a unit cost rounded up is balanced with a discount' => array(
				array( array( 'Beanie', 2, 59.83, 0.0 ) ),
				97.83,
				array(
					$this->line( 'Beanie', 2992, 2 ),
					array(
						'product_code'        => 'rounding-fix',
						'product_description' => 'Rounding fix',
						'unit_cost'           => 0,
						'quantity'            => 1,
						'tax_amount'          => 0,
						'discount_amount'     => 1,
					),
				),
			),
			'lines rounded separately are balanced once against the order total' => array(
				array(
					array( 'Product 0', 1, 16.491, 0.0 ),
					array( 'Product 1', 1, 31.991, 0.0 ),
					array( 'Product 2', 1, 25.191, 0.0 ),
					array( 'Product 3', 1, 26.391, 0.0 ),
					array( 'Product 4', 1, 29.591, 0.0 ),
				),
				167.66,
				array(
					$this->line( 'Product 0', 1649, 1 ),
					$this->line( 'Product 1', 3199, 1 ),
					$this->line( 'Product 2', 2519, 1 ),
					$this->line( 'Product 3', 2639, 1 ),
					$this->line( 'Product 4', 2959, 1 ),
					$this->rounding_fix( 1 ),
				),
			),
			'two lines of a third get one rounding line for the order' => array(
				array(
					array( 'First', 3, 10.0, 0.0 ),
					array( 'Second', 3, 10.0, 0.0 ),
				),
				58.0,
				array(
					$this->line( 'First', 333, 3 ),
					$this->line( 'Second', 333, 3 ),
					$this->rounding_fix( 2 ),
				),
			),
			'an order total below its items, as a deposit' => array(
				array( array( 'Catering', 1, 600.0, 0.0 ) ),
				180.0,
				null,
			),
			'an order without items that charges more than shipping' => array(
				array(),
				43.0,
				null,
			),
			'an order without items two cents above shipping' => array(
				array(),
				38.02,
				null,
			),
			'a zero-quantity line that carries money'      => array(
				array( array( 'Beanie', 0, 18.0, 2.7 ) ),
				58.7,
				null,
			),
		);
	}

	/**
	 * Client 11.3.0 test provider_level3_data_stripe_rejects.
	 *
	 * @testdox Should send no Level 3 data when a filter returns values the provider refuses: $_dataName.
	 * @dataProvider provide_filtered_payloads_the_provider_refuses
	 *
	 * @param callable $break_level3_data Filter callback.
	 */
	public function test_filtered_payloads_the_provider_refuses_are_dropped( callable $break_level3_data ): void {
		add_filter( 'wcpay_payment_request_level3_data', $break_level3_data );

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $this->make_order( array( array( 'Beanie', 1, 18.0, 2.7 ) ), 58.7 ) );

		$this->assertSame( array(), $level3 );
	}

	/**
	 * Filter callbacks returning Level 3 data the provider refuses.
	 *
	 * @return array<string,array<int,callable>>
	 */
	public function provide_filtered_payloads_the_provider_refuses(): array {
		return array(
			'no line items'         => array(
				static function () {
					return array( 'shipping_amount' => 3800 );
				},
			),
			'negative shipping'     => array(
				static function ( $level3_data ) {
					$level3_data['shipping_amount'] = -100;
					return $level3_data;
				},
			),
			'empty description'     => array(
				static function ( $level3_data ) {
					$level3_data['line_items'][0]->product_description = '';
					return $level3_data;
				},
			),
			'product code not text' => array(
				static function ( $level3_data ) {
					$level3_data['line_items'][0]->product_code = null;
					return $level3_data;
				},
			),
			'fractional unit cost'  => array(
				static function ( $level3_data ) {
					$level3_data['line_items'][0]->unit_cost = 1799.5;
					return $level3_data;
				},
			),
		);
	}

	/**
	 * @testdox Should keep filtered Level 3 data that balances with the order total.
	 *
	 * Client 11.3.0 test "filtered level3 data that balances is kept": a gift card paid 10.00 outside the line items.
	 */
	public function test_filtered_level3_data_that_balances_is_kept(): void {
		add_filter(
			'wcpay_payment_request_level3_data',
			static function ( $level3_data ) {
				$level3_data['line_items'][] = (object) array(
					'product_code'        => 'gift-card',
					'product_description' => 'Gift card',
					'unit_cost'           => 0,
					'quantity'            => 1,
					'tax_amount'          => 0,
					'discount_amount'     => 1000,
				);
				return $level3_data;
			}
		);

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $this->make_order( array( array( 'Beanie', 1, 18.0, 2.7 ) ), 48.7 ) );

		$this->assertCount( 2, $level3['line_items'] );
		$this->assertSame( 'gift-card', $level3['line_items'][1]->product_code );
	}

	/**
	 * @testdox Should cut a long filtered description to 26 characters and keep the payload.
	 *
	 * Client 11.3.0 test "long filtered descriptions are truncated".
	 */
	public function test_long_filtered_descriptions_are_truncated(): void {
		add_filter(
			'wcpay_payment_request_level3_data',
			static function ( $level3_data ) {
				$level3_data['line_items'][0]->product_description = 'Écharpe en laine mérinos extra douce';
				return $level3_data;
			}
		);

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $this->make_order( array( array( 'Beanie', 1, 18.0, 2.7 ) ), 58.7 ) );

		$this->assertSame( 'Écharpe en laine mérinos e', $level3['line_items'][0]->product_description );
		$this->assertSame( 1800, $level3['line_items'][0]->unit_cost );
	}

	/**
	 * @testdox Should re-add cents lost to per-unit rounding as one rounding-fix line item.
	 */
	public function test_adds_rounding_fix_item_when_division_drops_cents(): void {
		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '3.3333' ) );
		$order   = wc_create_order();
		$order->add_product(
			$product,
			3,
			array(
				'subtotal' => 10.00,
				'total'    => 10.00,
			)
		);
		$order->set_total( '10.00' );
		$order->save();

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 2, $level3['line_items'] );
		$this->assertSame( 'rounding-fix', $level3['line_items'][1]->product_code );
		$this->assertSame( 1, $level3['line_items'][1]->unit_cost );
		$this->assertSame(
			1000,
			$level3['line_items'][0]->unit_cost * 3 + $level3['line_items'][1]->unit_cost,
			'Line items must still sum to the charged amount.'
		);
	}

	/**
	 * @testdox Should bundle line items beyond the 200-item network limit into a single tail item with a code of at most 12 characters.
	 */
	public function test_bundles_line_items_beyond_the_network_limit(): void {
		$order = wc_create_order();
		for ( $i = 0; $i < 205; $i++ ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Fee ' . $i );
			$fee->set_total( '1.00' );
			$order->add_item( $fee );
		}
		$order->set_total( '205.00' );

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 200, $level3['line_items'] );
		$this->assertSame( '6 more items', $level3['line_items'][199]->product_description );
		$this->assertLessThanOrEqual( 12, strlen( $level3['line_items'][199]->product_code ) );
	}

	/**
	 * @testdox Should represent negative-priced items as free items with a discount.
	 */
	public function test_represents_negative_priced_items_as_discounts(): void {
		$order = $this->make_order( array( array( 'Beanie', 1, 10.0, 0.0 ) ), 7.5, 0.0, 0.0 );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Loyalty credit' );
		$fee->set_total( '-2.50' );
		$order->add_item( $fee );

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertSame( 0, $level3['line_items'][1]->unit_cost );
		$this->assertSame( 250, $level3['line_items'][1]->discount_amount );
	}

	/**
	 * @testdox Should discount a negative-priced line by its whole total, whatever its quantity.
	 *
	 * Client 11.3.0 test "negative price product is discounted for the whole line".
	 */
	public function test_negative_price_product_is_discounted_for_the_whole_line(): void {
		$order = $this->make_order(
			array(
				array( 'Beanie with Logo', 2, 18.0, 2.7 ),
				array( 'Negative Product Price', 2, -18.99, 2.7 ),
				array( 'Negative Product Price+Tax', 2, -18.99, -2.7 ),
			),
			20.72
		);

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 3, $level3['line_items'] );
		$this->assertSame( 0, $level3['line_items'][1]->unit_cost );
		$this->assertSame( 1899, $level3['line_items'][1]->discount_amount );
	}

	/**
	 * @testdox Should give a fee with a blank name the codes the provider accepts.
	 *
	 * Client 11.3.0 test "items without a name get a description and product code". Core shows an empty fee name as "Fee",
	 * so a blank name is the shape that reaches the fallbacks here.
	 */
	public function test_items_without_a_name_get_a_description_and_product_code(): void {
		$order = $this->make_order( array(), 48.0 );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( '   ' );
		$fee->set_total( '10.00' );
		$order->add_item( $fee );

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertSame( 'fee', $level3['line_items'][0]->product_code );
		$this->assertSame( 'Item', $level3['line_items'][0]->product_description );
	}

	/**
	 * @testdox Should leave out a line with no units.
	 *
	 * Client 11.3.0 test "zero quantity items are left out".
	 */
	public function test_zero_quantity_items_are_left_out(): void {
		$order = $this->make_order(
			array(
				array( 'Beanie', 1, 18.0, 0.0 ),
				array( 'Removed', 0, 0.0, 0.0 ),
			),
			56.0
		);

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 1, $level3['line_items'] );
		$this->assertSame( 'Beanie', $level3['line_items'][0]->product_description );
	}

	/**
	 * @testdox Should ship the synthetic empty-order item for an order of shipping only.
	 *
	 * Client 11.3.0 test "level3 data for an order with only shipping", with the request's placeholder
	 * (includes/core/server/request/trait-level3.php:20-37).
	 */
	public function test_normalizes_empty_line_items_to_placeholder(): void {
		$level3 = $this->make_sut( 'US' )->get_data_from_order( $this->make_order( array(), 38.0 ) );

		$this->assertSame( 3800, $level3['shipping_amount'] );
		$this->assertCount( 1, $level3['line_items'] );
		$this->assertSame( 'empty-order', $level3['line_items'][0]->product_code );
	}

	/**
	 * Build an unsaved USD order from product lines, with a stored total that the test sets.
	 *
	 * @param array<int,array{0:string,1:int,2:float,3:float}> $lines        Rows of name, quantity, line amount (subtotal and total) and line tax.
	 * @param float                                            $total        Order total.
	 * @param float                                            $shipping     Shipping total.
	 * @param float                                            $shipping_tax Shipping tax.
	 * @return WC_Order
	 */
	private function make_order( array $lines, float $total, float $shipping = 30.0, float $shipping_tax = 8.0 ): WC_Order {
		$order = new WC_Order();
		$order->set_currency( 'USD' );
		foreach ( $lines as list( $name, $quantity, $amount, $tax ) ) {
			$item = new WC_Order_Item_Product();
			$item->set_name( $name );
			$item->set_quantity( $quantity );
			$item->set_subtotal( $amount );
			$item->set_total( $amount );
			$item->set_taxes(
				array(
					'total'    => array( 1 => $tax ),
					'subtotal' => array( 1 => $tax ),
				)
			);
			$order->add_item( $item );
		}
		$order->set_shipping_total( $shipping );
		$order->set_shipping_tax( $shipping_tax );
		$order->set_total( $total );

		return $order;
	}

	/**
	 * Expected Level 3 line for one of make_order()'s untaxed product lines.
	 *
	 * @param string $name      Line name.
	 * @param int    $unit_cost Unit cost in cents.
	 * @param int    $quantity  Quantity.
	 * @return array<string,mixed>
	 */
	private function line( string $name, int $unit_cost, int $quantity ): array {
		return array(
			'product_code'        => '0',
			'product_description' => $name,
			'unit_cost'           => $unit_cost,
			'quantity'            => $quantity,
			'tax_amount'          => 0,
			'discount_amount'     => 0,
		);
	}

	/**
	 * Expected rounding line adding cents to the line items.
	 *
	 * @param int $unit_cost Cents added.
	 * @return array<string,mixed>
	 */
	private function rounding_fix( int $unit_cost ): array {
		return array(
			'product_code'        => 'rounding-fix',
			'product_description' => 'Rounding fix',
			'unit_cost'           => $unit_cost,
			'quantity'            => 1,
			'tax_amount'          => 0,
			'discount_amount'     => 0,
		);
	}

	/**
	 * Create the service under test with a fixed account country.
	 *
	 * @param string $country Account country code.
	 * @return WooPaymentsLevel3Service
	 */
	private function make_sut( string $country ): WooPaymentsLevel3Service {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( $country );

		$sut = new WooPaymentsLevel3Service();
		$sut->init( $account_service );

		return $sut;
	}
}
