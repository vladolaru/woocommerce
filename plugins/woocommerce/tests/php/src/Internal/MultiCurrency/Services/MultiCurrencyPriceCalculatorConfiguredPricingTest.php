<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceCalculator;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyPriceCalculator merchant-configured pricing paths.
 *
 * MultiCurrencyPriceCalculatorTest covers the combined rounding-plus-charm
 * product path (rounding '0.50' with charm -0.10). These tests pin the
 * configuration permutations that path leaves open — the zero-rounding
 * product path (round to the currency's decimals), charm applied to an
 * unrounded conversion, ceiling rounding without charm, and the zero-decimal
 * zero-rounding path — using the exact amounts the multi-currency pricing
 * configuration smoke asserts on the storefront.
 */
class MultiCurrencyPriceCalculatorConfiguredPricingTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var MultiCurrencyPriceCalculator
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new MultiCurrencyPriceCalculator( $this->create_localization() );
	}

	/**
	 * @testdox Should apply configured rate, rounding, and charm to product prices.
	 *
	 * @dataProvider configured_pricing_provider
	 *
	 * @param string $code     Currency code.
	 * @param float  $rate     Exchange rate.
	 * @param string $rounding Rounding setting.
	 * @param float  $charm    Charm setting.
	 * @param float  $expected Expected converted product price.
	 */
	public function test_applies_configured_pricing_to_product_prices( string $code, float $rate, string $rounding, float $charm, float $expected ): void {
		$currency = new MultiCurrencyCurrency( $this->create_localization(), $code, $rate );
		$currency->set_rounding( $rounding );
		$currency->set_charm( $charm );

		$this->assertSame(
			$expected,
			$this->sut->get_price( '1234.56', 'product', $currency ),
			"A {$code} product price with rate {$rate}, rounding {$rounding}, and charm {$charm} should be {$expected}"
		);
	}

	/**
	 * Get configured pricing cases.
	 *
	 * @return array<string,array{string,float,string,float,float}>
	 */
	public function configured_pricing_provider(): array {
		return array(
			'manual rate, zero rounding rounds to currency decimals' => array( 'CHF', 1.25, '0', 0.0, 1543.20 ),
			'charm applies to the unrounded converted price' => array( 'CHF', 1.0, '0', -0.01, 1234.55 ),
			'ceiling rounding without charm'              => array( 'CHF', 1.20, '0.50', 0.0, 1481.50 ),
			'zero-decimal currency rounds to whole units' => array( 'JPY', 150.1, '0', 0.0, 185307.0 ),
		);
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
