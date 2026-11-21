<?php
declare( strict_types = 1 );

/**
 * Tests for the WC_Order_Refund class.
 *
 * @package WooCommerce\Tests\Orders
 */
class WC_Order_Refund_Test extends WC_Unit_Test_Case {

	/**
	 * @testdox set_total() rounds the refund total to the decimals of the refund's currency.
	 *
	 * @testWith ["JPY", 0, "USD", "", "0.00"]
	 *           ["JPY", 0, "USD", -5, "-5.00"]
	 *           ["JPY", 0, "USD", -5.256, "-5.26"]
	 *           ["JPY", 0, "USD", "-5.25", "-5.25"]
	 *           ["USD", 2, "KWD", -5.125, "-5.125"]
	 *           ["USD", 0, "USD", "-5.25", "-5"]
	 *
	 * @param string           $store_currency Store currency.
	 * @param int              $store_decimals Store price decimals.
	 * @param string           $currency       Refund currency.
	 * @param string|float|int $value          Value passed to the setter.
	 * @param string           $expected       Expected refund total.
	 */
	public function test_set_total_uses_refund_currency_decimals( string $store_currency, int $store_decimals, string $currency, $value, string $expected ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );

		$refund = new WC_Order_Refund();
		$refund->set_currency( $currency );
		$refund->set_total( $value );

		$this->assertSame( $expected, $refund->get_total(), "A {$currency} refund total set to " . wp_json_encode( $value ) . " on a {$store_currency} store with {$store_decimals} decimals." );
	}

	/**
	 * @testdox set_total() with the deprecated total type still sets that total through the legacy setter.
	 */
	public function test_set_total_with_deprecated_total_type_sets_that_total(): void {
		$this->setExpectedDeprecated( 'total_type' );

		$refund = new WC_Order_Refund();
		$refund->set_currency( 'USD' );
		$refund->set_total( 3, 'shipping' );

		$this->assertSame( '3', $refund->get_shipping_total(), 'The deprecated shipping type should set the shipping total.' );
		$this->assertSame( 0.0, (float) $refund->get_total(), 'The deprecated shipping type should leave the refund total alone.' );
	}
}
