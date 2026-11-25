<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Utilities;

use Automattic\WooCommerce\Internal\Utilities\CurrencyUtil;
use WC_Unit_Test_Case;

/**
 * Tests for the CurrencyUtil class.
 */
class CurrencyUtilTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Amounts round to the store's decimals in the store currency and never more coarsely in another currency.
	 *
	 * @testWith ["USD", 0, "USD", 0]
	 *           ["JPY", 0, "USD", 2]
	 *           ["USD", 2, "JPY", 2]
	 *           ["USD", 2, "KWD", 3]
	 *           ["JPY", 0, "XYZ", 0]
	 *
	 * @param string $store_currency Store currency.
	 * @param int    $store_decimals Store price decimals.
	 * @param string $currency       Currency of the amount.
	 * @param int    $expected       Expected decimals.
	 */
	public function test_get_price_decimals( string $store_currency, int $store_decimals, string $currency, int $expected ): void {
		update_option( 'woocommerce_currency', $store_currency );
		update_option( 'woocommerce_price_num_decimals', $store_decimals );

		$this->assertSame( $expected, CurrencyUtil::get_price_decimals( $currency ), "Decimals for {$currency} on a {$store_currency} store with {$store_decimals} decimals." );
	}
}
