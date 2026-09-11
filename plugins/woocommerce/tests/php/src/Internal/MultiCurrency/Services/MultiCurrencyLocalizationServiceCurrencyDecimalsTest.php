<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyLocalizationService per-currency decimal counts.
 *
 * MultiCurrencyLocalizationServiceTest proves the format shape and the
 * unknown-currency fallback; these tests pin the real locale-data decimal
 * counts the multi-currency pricing configuration smoke depends on — GBP and
 * CHF as two-decimal currencies and JPY as a zero-decimal currency. The
 * zero-decimal detection in MultiCurrencyCurrency is otherwise only proven
 * against a hand-written localization double.
 */
class MultiCurrencyLocalizationServiceCurrencyDecimalsTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var MultiCurrencyLocalizationService
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 *
	 * The service caches the locale data in transients; delete them so the
	 * assertions run against the bundled locale files, not a stale cache
	 * another test or environment may have left behind.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->delete_locale_transients();
		$this->sut = new MultiCurrencyLocalizationService();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->delete_locale_transients();
		parent::tearDown();
	}

	/**
	 * @testdox Should report the currency's decimal count from the bundled locale data.
	 *
	 * @dataProvider currency_decimals_provider
	 *
	 * @param string $currency_code     Currency code.
	 * @param int    $expected_decimals Expected decimal count.
	 */
	public function test_reports_currency_decimal_count( string $currency_code, int $expected_decimals ): void {
		$format = $this->sut->get_currency_format( $currency_code );

		$this->assertSame(
			$expected_decimals,
			$format['num_decimals'],
			"{$currency_code} should format with {$expected_decimals} decimals"
		);
	}

	/**
	 * Get currency decimal cases.
	 *
	 * @return array<string,array{string,int}>
	 */
	public function currency_decimals_provider(): array {
		return array(
			'GBP is a two-decimal currency'  => array( 'GBP', 2 ),
			'CHF is a two-decimal currency'  => array( 'CHF', 2 ),
			'JPY is a zero-decimal currency' => array( 'JPY', 0 ),
		);
	}

	/**
	 * Delete the localization cache transients.
	 */
	private function delete_locale_transients(): void {
		delete_transient( MultiCurrencyLocalizationService::CURRENCY_FORMAT_TRANSIENT );
		delete_transient( MultiCurrencyLocalizationService::LOCALE_INFO_TRANSIENT );
	}
}
