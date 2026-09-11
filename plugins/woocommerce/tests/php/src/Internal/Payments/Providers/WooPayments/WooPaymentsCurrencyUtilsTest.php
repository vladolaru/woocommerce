<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCurrencyUtils class.
 */
class WooPaymentsCurrencyUtilsTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Minor-unit amounts use the provider currency's decimal semantics.
	 */
	public function test_amount_from_minor_units_uses_currency_decimal_semantics(): void {
		$this->assertSame( 12.34, WooPaymentsCurrencyUtils::amount_from_minor_units( 1234, 'USD' ) );
		$this->assertSame( 1234.0, WooPaymentsCurrencyUtils::amount_from_minor_units( 1234, 'jpy' ) );
	}

	/**
	 * @testdox Decimal amounts convert to provider minor units through the same currency authority.
	 */
	public function test_amount_to_minor_units_uses_currency_decimal_semantics(): void {
		$this->assertSame( 1234, WooPaymentsCurrencyUtils::amount_to_minor_units( 12.34, 'USD' ) );
		$this->assertSame( 1234, WooPaymentsCurrencyUtils::amount_to_minor_units( 1234.0, 'jpy' ) );
	}
}
