<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\LocalizationService;
use WC_Unit_Test_Case;

/**
 * Tests for the LocalizationService class.
 */
class LocalizationServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should return a currency format shape for known currencies.
	 */
	public function test_returns_currency_format_shape_for_known_currency(): void {
		$service = new LocalizationService();
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
		$service = new LocalizationService();

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
		$service = new LocalizationService();
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
	 * @testdox Should format a currency from its bundled default locale entry: $_dataName.
	 *
	 * Client 11.1.0 bundles the same CLDR-derived defaults (i18n/currency-info.php): EUR with a comma decimal separator and
	 * a right-spaced symbol (:78-84, :362), "8,00 €"; CHF with the apostrophe as its thousands separator, a dot decimal
	 * separator and a left-spaced symbol, and i18n/locale-info.php's CH decimals, the files WooPayments 11.1.0's
	 * WC_Payments_Localization_Service::load_locale_data() reads. The multi-currency pricing settings smoke depends on the
	 * CHF modal rendering this typographic thousands separator, not a plain comma.
	 * @dataProvider provide_bundled_currency_formats
	 *
	 * @param string              $currency Currency code.
	 * @param array<string,mixed> $expected Position, separators and decimals.
	 */
	public function test_formats_currencies_from_the_bundled_default_locale_entry( string $currency, array $expected ): void {
		switch_to_locale( 'en_US' );

		try {
			$service = new LocalizationService();
			$format  = $service->get_currency_format( $currency );
		} finally {
			restore_previous_locale();
		}

		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $format[ $key ], $key );
		}
	}

	/**
	 * Bundled default formats.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public static function provide_bundled_currency_formats(): array {
		return array(
			'EUR' => array(
				'EUR',
				array(
					'currency_pos' => 'right_space',
					'thousand_sep' => '.',
					'decimal_sep'  => ',',
					'num_decimals' => 2,
				),
			),
			'CHF' => array(
				'CHF',
				array(
					'currency_pos' => 'left_space',
					'thousand_sep' => "'",
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				),
			),
		);
	}

	/**
	 * @testdox Should return locale data for known countries.
	 */
	public function test_returns_country_locale_data(): void {
		$service = new LocalizationService();
		$data    = $service->get_country_locale_data( 'US' );

		$this->assertSame( 'USD', $data['currency_code'] );
	}
}
