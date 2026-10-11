<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyLocalizationService class.
 */
class MultiCurrencyLocalizationServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should return a currency format shape for known currencies.
	 */
	public function test_returns_currency_format_shape_for_known_currency(): void {
		$service = new MultiCurrencyLocalizationService();
		$format  = $service->get_currency_format( 'USD' );

		$this->assertArrayHasKey( 'currency_pos', $format );
		$this->assertArrayHasKey( 'thousand_sep', $format );
		$this->assertArrayHasKey( 'decimal_sep', $format );
		$this->assertArrayHasKey( 'num_decimals', $format );
	}

	/**
	 * @testdox Should return the plugin-compatible fallback for unknown currencies.
	 */
	public function test_returns_fallback_format_for_unknown_currency(): void {
		$service = new MultiCurrencyLocalizationService();

		$this->assertSame(
			array(
				'currency_pos' => 'left',
				'thousand_sep' => ',',
				'decimal_sep'  => '.',
				'num_decimals' => 2,
			),
			$service->get_currency_format( 'ZZZ' )
		);
	}

	/**
	 * @testdox Should preserve the currency-specific format filter.
	 */
	public function test_preserves_currency_specific_format_filter(): void {
		$service = new MultiCurrencyLocalizationService();
		$filter  = static function ( array $format, string $locale ): array {
			$format['currency_pos'] = 'right_space';
			$format['locale']       = $locale;

			return $format;
		};

		add_filter( 'wcpay_usd_format', $filter, 10, 2 );
		$format = $service->get_currency_format( 'USD' );
		remove_filter( 'wcpay_usd_format', $filter, 10 );

		$this->assertSame( 'right_space', $format['currency_pos'] );
		$this->assertSame( get_user_locale(), $format['locale'] );
	}

	/**
	 * @testdox Should format EUR with a comma decimal separator and a right-spaced symbol from the bundled default locale entry.
	 *
	 * Client 11.1.0 bundles the same CLDR-derived default for EUR (no locale-specific override):
	 * decimal_sep ',', currency_pos 'right_space' (i18n/currency-info.php:78-84, :362), which is
	 * "8,00 €".
	 */
	public function test_eur_format_uses_comma_decimal_and_right_space_symbol(): void {
		switch_to_locale( 'en_US' );

		try {
			$service = new MultiCurrencyLocalizationService();
			$format  = $service->get_currency_format( 'EUR' );
		} finally {
			restore_previous_locale();
		}

		$this->assertSame( ',', $format['decimal_sep'] );
		$this->assertSame( 'right_space', $format['currency_pos'] );
	}

	/**
	 * @testdox Should return locale data for known countries.
	 */
	public function test_returns_country_locale_data(): void {
		$service = new MultiCurrencyLocalizationService();
		$data    = $service->get_country_locale_data( 'US' );

		$this->assertSame( 'USD', $data['currency_code'] );
	}
}
