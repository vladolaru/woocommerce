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
	 * @testdox Decimal amounts convert to provider minor units through the same currency authority ($description).
	 * @dataProvider amount_to_minor_units_provider
	 *
	 * @param string    $description Case.
	 * @param int|float $amount      Decimal amount.
	 * @param string    $currency    Currency code.
	 * @param int       $expected    Minor units.
	 */
	public function test_amount_to_minor_units_uses_currency_decimal_semantics( string $description, $amount, string $currency, int $expected ): void {
		unset( $description );

		$this->assertSame( $expected, WooPaymentsCurrencyUtils::amount_to_minor_units( $amount, $currency ) );
	}

	/**
	 * Provide minor-unit rows: case, amount, currency, minor units. Three-decimal currencies use two decimals, as client
	 * 11.1.0 `WC_Payments_Utils::prepare_amount()` does (includes/class-wc-payments-utils.php:148-156).
	 *
	 * @return array<string,array{0:string,1:int|float,2:string,3:int}>
	 */
	public function amount_to_minor_units_provider(): array {
		return array(
			'two-decimal'             => array( 'two-decimal', 12.34, 'USD', 1234 ),
			'two-decimal, negative'   => array( 'two-decimal, negative', -3.5, 'usd', -350 ),
			'integer amount'          => array( 'integer amount', 12, 'USD', 1200 ),
			'zero-decimal'            => array( 'zero-decimal', 1234.0, 'jpy', 1234 ),
			'zero-decimal, half up'   => array( 'zero-decimal, half up', 1234.5, 'JPY', 1235 ),
			'zero-decimal, lowercase' => array( 'zero-decimal, lowercase', 5000.0, 'xof', 5000 ),
			'three-decimal'           => array( 'three-decimal', 1.234, 'KWD', 123 ),
			'Stripe two-decimal case' => array( 'Stripe two-decimal case', 100.0, 'HUF', 10000 ),
		);
	}

	/**
	 * @testdox An amount in a payment note is formatted as usual when a currency format callback forgets its return.
	 *
	 * The payment, refund, Fee details and webhook notes format their amounts through the currency format, with or
	 * without Multi-Currency.
	 */
	public function test_format_currency_ignores_a_currency_format_callback_with_no_return(): void {
		$expected = WooPaymentsCurrencyUtils::format_currency( 10.0, 'USD' );
		$callback = static function ( $format ) {
			unset( $format );
		};
		add_filter( 'wcpay_usd_format', $callback );

		try {
			$formatted = WooPaymentsCurrencyUtils::format_currency( 10.0, 'USD' );
		} finally {
			remove_filter( 'wcpay_usd_format', $callback );
		}

		$this->assertSame( $expected, $formatted );
	}
}
