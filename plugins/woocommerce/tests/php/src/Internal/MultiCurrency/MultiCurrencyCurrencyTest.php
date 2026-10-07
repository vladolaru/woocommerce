<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyCurrency class.
 */
class MultiCurrencyCurrencyTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should normalize currency codes.
	 */
	public function test_normalizes_currency_codes(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'usd', 1.0 );

		$this->assertSame( 'USD', $currency->get_code() );
		$this->assertSame( 'usd', $currency->get_id() );
	}

	/**
	 * @testdox Should use constructor default flag.
	 */
	public function test_uses_constructor_default_flag(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'EUR', 1.2, true );

		$this->assertTrue( $currency->get_is_default() );
	}

	/**
	 * @testdox Should detect zero-decimal currencies from localization.
	 */
	public function test_detects_zero_decimal_currencies(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'JPY', 151.0 );

		$this->assertTrue( $currency->get_is_zero_decimal() );
	}

	/**
	 * @testdox Should expose mutable rate adjustment settings.
	 */
	public function test_exposes_mutable_rate_adjustment_settings(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'GBP', 0.75 );

		$this->assertSame( 0.0, $currency->get_charm() );
		$this->assertSame( '0', $currency->get_rounding() );
		$this->assertNull( $currency->get_last_updated() );

		$currency->set_charm( '-0.01' );
		$currency->set_rounding( '0.25' );
		$currency->set_rate( '0.8' );
		$currency->set_last_updated( 123456 );

		$this->assertSame( -0.01, $currency->get_charm() );
		$this->assertSame( '0.25', $currency->get_rounding() );
		$this->assertSame( 0.8, $currency->get_rate() );
		$this->assertSame( 123456, $currency->get_last_updated() );
	}

	/**
	 * @testdox Should keep the rate as a decimal string and read the float from it.
	 */
	public function test_keeps_the_rate_as_a_canonical_decimal_string(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'GBP', 0.1 );
		$this->assertSame( '0.1', $currency->get_rate_decimal() );

		$currency->set_rate( ' 0.90000000 ' );
		$this->assertSame( '0.90000000', $currency->get_rate_decimal(), 'A stored option string is kept as the merchant saved it.' );
		$this->assertSame( 0.9, $currency->get_rate() );

		$currency->set_rate( 0.123456789012345678 );
		$this->assertSame( wc_float_to_string( 0.123456789012345678 ), $currency->get_rate_decimal() );
		$this->assertSame( (float) $currency->get_rate_decimal(), $currency->get_rate(), 'The float is read from the string, never the reverse.' );
	}

	/**
	 * @testdox Should serialize the currency state.
	 *
	 * Source: client 11.1.0 includes/multi-currency/Currency.php:264-277 (decoded name and symbol, flag from CountryFlags.php:251, :287-300).
	 */
	public function test_serializes_currency_state(): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), 'USD', 1.0, true, 123456 );
		$currency->set_charm( -0.01 );
		$currency->set_rounding( '1.00' );

		$data = $currency->jsonSerialize();

		$this->assertSame( 'usd', $data['id'] );
		$this->assertSame( 'USD', $data['code'] );
		$this->assertSame( 'United States (US) dollar', $data['name'] );
		$this->assertSame( 1.0, $data['rate'] );
		$this->assertSame( '$', $data['symbol'] );
		$this->assertSame( 'left', $data['symbol_position'] );
		$this->assertFalse( $data['is_zero_decimal'] );
		$this->assertTrue( $data['is_default'] );
		$this->assertSame( '🇺🇸', $data['flag'] );
		$this->assertSame( '', ( new MultiCurrencyCurrency( $this->create_localization(), 'XOF', 1.0 ) )->jsonSerialize()['flag'] );
		$this->assertSame( -0.01, $data['charm'] );
		$this->assertSame( '1.00', $data['rounding'] );
		$this->assertSame( 123456, $data['last_updated'] );
	}

	/**
	 * Create a localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				$formats = array(
					'JPY' => array(
						'currency_pos' => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'num_decimals' => 0,
					),
					'GBP' => array(
						'currency_pos' => 'left',
						'thousand_sep' => ',',
						'decimal_sep'  => '.',
						'num_decimals' => 2,
					),
				);

				return $formats[ strtoupper( (string) $currency_code ) ] ?? array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				return array();
			}
		};
	}
}
