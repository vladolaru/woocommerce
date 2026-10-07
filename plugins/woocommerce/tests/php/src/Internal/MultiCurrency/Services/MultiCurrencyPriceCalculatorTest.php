<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceCalculator;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyPriceCalculator class.
 */
class MultiCurrencyPriceCalculatorTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should return the original amount for unsupported price types.
	 */
	public function test_returns_original_amount_for_unsupported_price_types(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( 'GBP', 0.708099, false );

		$this->assertSame( 10.0, $calculator->get_price( '10.0', 'unsupported', $currency ) );
	}

	/**
	 * @testdox Should return the original amount for the default currency.
	 */
	public function test_returns_original_amount_for_default_currency(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( 'USD', 1.0, true );

		$this->assertSame( 10.0, $calculator->get_price( '10.0', 'product', $currency ) );
	}

	/**
	 * @testdox Should apply rounding and charm to product prices.
	 */
	public function test_applies_rounding_and_charm_to_product_prices(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( 'GBP', 0.708099, false, '0.50', -0.10 );

		$this->assertSame( 7.4, $calculator->get_price( '10.0', 'product', $currency ) );
	}

	/**
	 * @testdox Should round an amount already in the currency to its decimals and add the charm, for the store currency too.
	 */
	public function test_adjusts_an_amount_already_in_the_currency(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );

		// Zero rounding rounds to the currency's decimals before the charm (client 11.1.0 `includes/multi-currency/MultiCurrency.php:1657-1683`).
		$this->assertEqualsWithDelta( 8.11, $calculator->get_adjusted_amount( '8.214', $this->create_currency( 'GBP', 0.82, false, '0', -0.10 ) ), 0.000001 );
		$this->assertSame( 10.21, $calculator->get_adjusted_amount( '10.214', $this->create_currency( 'USD', 1.0, true ) ) );
		$this->assertSame( 1235.0, $calculator->get_adjusted_amount( '1234.6', $this->create_currency( 'JPY', 150.0, false ) ) );
	}

	/**
	 * @testdox Should apply shipping charm only when configured.
	 */
	public function test_applies_shipping_charm_only_when_configured(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( 'GBP', 0.708099, false, '0.50', -0.10 );

		$this->assertSame( 7.5, $calculator->get_price( '10.0', 'shipping', $currency, true ) );
		$this->assertSame( 7.4, $calculator->get_price( '10.0', 'shipping', $currency, false ) );
	}

	/**
	 * @testdox Should charge the price the async renderer shows: $label.
	 *
	 * @dataProvider rendered_price_provider
	 *
	 * @param string $label             Case label.
	 * @param string $code              Currency code.
	 * @param float  $rate              Exchange rate.
	 * @param string $rounding          Rounding step.
	 * @param float  $charm             Charm.
	 * @param string $price             Price in the store currency.
	 * @param string $type              Price type.
	 * @param bool   $charm_on_products Whether charm applies to products only.
	 * @param string $rendered          Price the renderer shows, as plain digits.
	 */
	public function test_charges_the_rendered_price( string $label, string $code, float $rate, string $rounding, float $charm, string $price, string $type, bool $charm_on_products, string $rendered ): void {
		unset( $label );
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( $code, $rate, false, $rounding, $charm );
		$decimals   = 'JPY' === $code ? 0 : 2;

		$this->assertSame( $rendered, number_format( $calculator->get_price( $price, $type, $currency, $charm_on_products ), $decimals, '.', '' ) );
	}

	/**
	 * Cases shared with the async renderer test (client/legacy/js/frontend/test/multi-currency-async-renderer.test.js), whose expected
	 * values are the client renderer's output (client 11.1.0 includes/multi-currency/client/async-renderer/index.ts:233-301).
	 *
	 * @return array<string,array{string,string,float,string,float,string,string,bool,string}>
	 */
	public function rendered_price_provider(): array {
		return array(
			'product, rounding 1, charm -0.01' => array( 'product, rounding 1, charm -0.01', 'EUR', 0.85, '1', -0.01, '10.00', 'product', true, '8.99' ),
			'half cent, rounding 0'            => array( 'half cent, rounding 0', 'GBP', 0.5, '0', 0.0, '20.15', 'product', true, '10.08' ),
			'step crossing, rounding 0.10'     => array( 'step crossing, rounding 0.10', 'GBP', 0.1, '0.1', 0.0, '3.00', 'product', true, '0.30' ),
			'shipping, charm on products only' => array( 'shipping, charm on products only', 'GBP', 0.82, '0.5', -0.1, '10.00', 'shipping', true, '8.50' ),
			'shipping, charm on shipping too'  => array( 'shipping, charm on shipping too', 'GBP', 0.82, '0.5', -0.1, '10.00', 'shipping', false, '8.40' ),
			'coupon, half cent'                => array( 'coupon, half cent', 'GBP', 0.5, '0.5', -0.1, '20.15', 'coupon', true, '10.08' ),
			'zero-decimal currency'            => array( 'zero-decimal currency', 'JPY', 151.0, '0', 0.0, '10.50', 'product', true, '1586' ),
			'charm below zero clamps to zero'  => array( 'charm below zero clamps to zero', 'GBP', 0.5, '0', -1.0, '1.00', 'product', true, '0.00' ),
			'rate written with an exponent'    => array( 'rate written with an exponent', 'BTC', 1e-7, '0', 0.0, '123456789', 'product', true, '12.35' ),
		);
	}

	/**
	 * @testdox Should round precise price types without charm.
	 *
	 * @dataProvider precise_price_type_provider
	 *
	 * @param string $type Price type.
	 */
	public function test_rounds_precise_price_types_without_charm( string $type ): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$currency   = $this->create_currency( 'GBP', 0.708099, false, '0.50', -0.10 );

		$this->assertSame( 7.08, $calculator->get_price( '10.0', $type, $currency, false ) );
	}

	/**
	 * Get precise price types.
	 *
	 * @return array<string,array{string}>
	 */
	public function precise_price_type_provider(): array {
		return array(
			'coupon'        => array( 'coupon' ),
			'tax'           => array( 'tax' ),
			'exchange_rate' => array( 'exchange_rate' ),
		);
	}

	/**
	 * @testdox Should convert raw amounts between enabled currencies.
	 */
	public function test_converts_raw_amounts_between_enabled_currencies(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );
		$enabled    = array(
			'USD' => $this->create_currency( 'USD', 1.0, true ),
			'GBP' => $this->create_currency( 'GBP', 0.708099, false ),
			'CAD' => $this->create_currency( 'CAD', 1.259881, false ),
		);

		$this->assertSame( 10.0 * ( 0.708099 / 1.259881 ), $calculator->get_raw_conversion( 10.0, 'GBP', 'CAD', $enabled ) );
	}

	/**
	 * @testdox Should throw when raw conversion currencies are missing.
	 */
	public function test_throws_when_raw_conversion_currencies_are_missing(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );

		$this->expectException( InvalidCurrencyException::class );

		$calculator->get_raw_conversion(
			10.0,
			'EUR',
			'USD',
			array(
				'USD' => $this->create_currency( 'USD', 1.0, true ),
			)
		);
	}

	/**
	 * @testdox Should throw when source rate is invalid.
	 */
	public function test_throws_when_source_rate_is_invalid(): void {
		$calculator = new MultiCurrencyPriceCalculator( $this->create_localization() );

		$this->expectException( InvalidCurrencyRateException::class );

		$calculator->get_raw_conversion(
			10.0,
			'GBP',
			'USD',
			array(
				'USD' => $this->create_currency( 'USD', 0.0, true ),
				'GBP' => $this->create_currency( 'GBP', 0.708099, false ),
			)
		);
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether the currency is default.
	 * @param string $rounding   Rounding amount.
	 * @param float  $charm      Charm amount.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default, string $rounding = '0', float $charm = 0.0 ): MultiCurrencyCurrency {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
		$currency->set_rounding( $rounding );
		$currency->set_charm( $charm );

		return $currency;
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
				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 'JPY' === strtoupper( (string) $currency_code ) ? 0 : 2,
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
