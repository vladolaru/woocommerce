<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use WC_Helper_Product;
use WC_Order_Item_Fee;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsLevel3Service class.
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
		$order->add_product( $product, 2 );
		$order->set_shipping_postcode( '10001' );
		$order->calculate_totals();
		// Set after calculate_totals(): recalculation derives shipping from
		// shipping items, and this order deliberately has none.
		$order->set_shipping_total( '5.00' );
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
	}

	/**
	 * @testdox Should re-add cents lost to per-unit rounding as a rounding-fix line item.
	 */
	public function test_adds_rounding_fix_item_when_division_drops_cents(): void {
		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '3.3333' ) );
		$order   = wc_create_order();
		$item_id = $order->add_product(
			$product,
			3,
			array(
				'subtotal' => 10.00,
				'total'    => 10.00,
			)
		);
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
	 * @testdox Should bundle line items beyond the 200-item network limit into a single tail item.
	 */
	public function test_bundles_line_items_beyond_the_network_limit(): void {
		$order = wc_create_order();
		for ( $i = 0; $i < 205; $i++ ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( 'Fee ' . $i );
			$fee->set_total( '1.00' );
			$order->add_item( $fee );
		}

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 200, $level3['line_items'] );
		$this->assertSame( '6 more items', $level3['line_items'][199]->product_description );
	}

	/**
	 * @testdox Should represent negative-priced items as free items with a discount.
	 */
	public function test_represents_negative_priced_items_as_discounts(): void {
		$order = wc_create_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Loyalty credit' );
		$fee->set_total( '-2.50' );
		$order->add_item( $fee );

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertSame( 0, $level3['line_items'][0]->unit_cost );
		$this->assertSame( 250, $level3['line_items'][0]->discount_amount );
	}

	/**
	 * @testdox Should ship the synthetic empty-order item instead of an empty line-item list.
	 */
	public function test_normalizes_empty_line_items_to_placeholder(): void {
		$order = wc_create_order();

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertCount( 1, $level3['line_items'] );
		$this->assertSame( 'empty-order', $level3['line_items'][0]->product_code );
	}

	/**
	 * @testdox Should never divide by zero for zero-quantity line items.
	 */
	public function test_handles_zero_quantity_items(): void {
		$product = WC_Helper_Product::create_simple_product( true, array( 'regular_price' => '5.00' ) );
		$order   = wc_create_order();
		$order->add_product(
			$product,
			0,
			array(
				'subtotal' => 0.0,
				'total'    => 0.0,
			)
		);
		$order->save();

		$level3 = $this->make_sut( 'US' )->get_data_from_order( $order );

		$this->assertSame( 1, $level3['line_items'][0]->quantity );
		$this->assertSame( 0, $level3['line_items'][0]->unit_cost );
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
		$sut->init( $account_service, new WooPaymentsOrderDataService() );

		return $sut;
	}
}
