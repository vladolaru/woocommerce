<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStoreCurrencyLifecycleService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyStoreCurrencyLifecycleService class.
 */
class MultiCurrencyStoreCurrencyLifecycleServiceTest extends WC_Unit_Test_Case {

	private const STORE_CURRENCY_OPTION = 'wcpay_multi_currency_store_currency';
	private const NOTICE_OPTION         = 'wcpay_multi_currency_show_store_currency_changed_notice';

	/**
	 * Custom currency code used by isolated filter tests.
	 */
	private const CUSTOM_CURRENCY = 'XTS';

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_currency = get_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency', 'USD' );
		$this->delete_options();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		$this->delete_options();
		update_option( 'woocommerce_currency', $this->original_currency );

		parent::tear_down();
	}

	/**
	 * @testdox Should seed missing store currency without clearing cache.
	 */
	public function test_seeds_missing_store_currency_without_clearing_cache(): void {
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertIsArray( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertFalse( get_option( self::NOTICE_OPTION, false ) );
	}

	/**
	 * @testdox Should keep options when store currency is unchanged.
	 */
	public function test_keeps_options_when_store_currency_is_unchanged(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertIsArray( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
	}

	/**
	 * @testdox Should ignore shopper currency filters when the configured store currency is unchanged.
	 */
	public function test_ignores_shopper_currency_filter_when_configured_currency_is_unchanged(): void {
		$cache_value             = $this->get_currencies_cache_fixture();
		$shopper_currency_filter = static fn(): string => 'GBP';

		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $cache_value, false );
		add_filter( 'woocommerce_currency', $shopper_currency_filter, 900 );

		try {
			$changed = $this->create_service()->synchronize_store_currency();
		} finally {
			remove_filter( 'woocommerce_currency', $shopper_currency_filter, 900 );
		}

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertSame( $cache_value, get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertFalse( get_option( self::NOTICE_OPTION, false ) );
	}

	/**
	 * @testdox Should not mutate lifecycle state when configured currency is missing or empty.
	 * @dataProvider missing_or_empty_configured_currency_data
	 *
	 * @param bool $configured_option_exists Whether the configured currency option exists with an empty value.
	 * @param bool $tracked_option_exists    Whether the tracked store-currency option exists.
	 */
	public function test_does_not_mutate_state_when_configured_currency_is_missing_or_empty(
		bool $configured_option_exists,
		bool $tracked_option_exists
	): void {
		$cache_value  = $this->get_currencies_cache_fixture();
		$notice_value = array( 'Pound sterling' );

		if ( $configured_option_exists ) {
			update_option( 'woocommerce_currency', '' );
		} else {
			delete_option( 'woocommerce_currency' );
		}

		if ( $tracked_option_exists ) {
			update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		} else {
			delete_option( self::STORE_CURRENCY_OPTION );
		}

		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $cache_value, false );
		update_option( self::NOTICE_OPTION, $notice_value, false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame(
			$tracked_option_exists ? 'USD' : false,
			get_option( self::STORE_CURRENCY_OPTION, false )
		);
		$this->assertSame( $cache_value, get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertSame( $notice_value, get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * Data provider for missing and empty configured currency states.
	 *
	 * @return array<string, array{bool, bool}>
	 */
	public static function missing_or_empty_configured_currency_data(): array {
		return array(
			'missing configuration and missing tracked state' => array( false, false ),
			'missing configuration and existing tracked state' => array( false, true ),
			'empty configuration and missing tracked state'   => array( true, false ),
			'empty configuration and existing tracked state'  => array( true, true ),
		);
	}

	/**
	 * @testdox Should honor a custom configured currency admitted by WooCommerce.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_honors_custom_configured_currency_admitted_by_woocommerce(): void {
		$currencies_filter = $this->get_custom_currencies_filter();

		$this->expose_fresh_woocommerce_currencies_to_service();
		add_filter( 'woocommerce_currencies', $currencies_filter );

		try {
			update_option( 'woocommerce_currency', self::CUSTOM_CURRENCY );
			update_option( self::STORE_CURRENCY_OPTION, 'USD' );
			update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $this->get_currencies_cache_fixture(), false );

			$changed = $this->create_service()->synchronize_store_currency();
		} finally {
			remove_filter( 'woocommerce_currencies', $currencies_filter );
		}

		$this->assertTrue( $changed );
		$this->assertSame( self::CUSTOM_CURRENCY, get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertFalse( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, false ) );
	}

	/**
	 * @testdox Should ignore an admitted custom currency used only for shopper presentation.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_ignores_admitted_custom_currency_used_only_for_shopper_presentation(): void {
		$cache_value             = $this->get_currencies_cache_fixture();
		$notice_value            = array( 'Pound sterling' );
		$currencies_filter       = $this->get_custom_currencies_filter();
		$shopper_currency_filter = static fn(): string => self::CUSTOM_CURRENCY;

		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $cache_value, false );
		update_option( self::NOTICE_OPTION, $notice_value, false );
		$this->expose_fresh_woocommerce_currencies_to_service();
		add_filter( 'woocommerce_currencies', $currencies_filter );
		add_filter( 'woocommerce_currency', $shopper_currency_filter, 900 );

		try {
			$changed = $this->create_service()->synchronize_store_currency();
		} finally {
			remove_filter( 'woocommerce_currency', $shopper_currency_filter, 900 );
			remove_filter( 'woocommerce_currencies', $currencies_filter );
		}

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertSame( $cache_value, get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
		$this->assertSame( $notice_value, get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * @testdox Should update store currency, clear cache, and write manual rate notice.
	 */
	public function test_updates_store_currency_clears_cache_and_writes_manual_rate_notice(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'CAD', 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_cad', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_cad', '1.47' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.84' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, array( 'data' => array() ), false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertTrue( $changed );
		$this->assertSame( 'EUR', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertFalse( get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, false ) );
		$this->assertSame( array( 'Canadian dollar', 'Pound sterling' ), get_option( self::NOTICE_OPTION ) );
	}

	/**
	 * @testdox Should not write notice when changed currencies have no manual rates.
	 */
	public function test_does_not_write_notice_when_changed_currencies_have_no_manual_rates(): void {
		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertTrue( $changed );
		$this->assertFalse( get_option( self::NOTICE_OPTION, false ) );
	}

	/**
	 * @testdox Should skip unknown store currency without mutating state.
	 */
	public function test_skips_unknown_store_currency_without_mutating_state(): void {
		$cache_value = $this->get_currencies_cache_fixture();

		update_option( self::STORE_CURRENCY_OPTION, 'USD' );
		update_option( 'woocommerce_currency', 'XYZ' );
		update_option( MultiCurrencyCacheInterface::CURRENCIES_KEY, $cache_value, false );

		$changed = $this->create_service()->synchronize_store_currency();

		$this->assertFalse( $changed );
		$this->assertSame( 'USD', get_option( self::STORE_CURRENCY_OPTION ) );
		$this->assertSame( $cache_value, get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY ) );
	}

	/**
	 * Get a recognizable currencies cache value.
	 *
	 * @return array<string, mixed>
	 */
	private function get_currencies_cache_fixture(): array {
		return array(
			'data'               => array(
				'currencies' => array(
					'eur' => 0.88,
					'gbp' => 0.75,
				),
				'updated'    => 123456,
			),
			'fetched'            => 123456,
			'errored'            => false,
			'consecutive_errors' => 0,
		);
	}

	/**
	 * Get a filter that adds the custom test currency.
	 *
	 * @return callable(array<string, string>): array<string, string>
	 */
	private function get_custom_currencies_filter(): callable {
		return static function ( array $currencies ): array {
			$currencies[ self::CUSTOM_CURRENCY ] = 'Test currency';

			return $currencies;
		};
	}

	/**
	 * Expose a fresh filtered currency list to the service in an isolated process.
	 */
	private function expose_fresh_woocommerce_currencies_to_service(): void {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- The global currency list is memoized during test bootstrap; this isolated shim reapplies its public filter for the service call.
		eval( 'namespace Automattic\WooCommerce\Internal\MultiCurrency\Services; function get_woocommerce_currencies() { return apply_filters( "woocommerce_currencies", \get_woocommerce_currencies() ); }' );
	}

	/**
	 * Create the lifecycle service.
	 *
	 * @return MultiCurrencyStoreCurrencyLifecycleService
	 */
	private function create_service(): MultiCurrencyStoreCurrencyLifecycleService {
		$cache = new MultiCurrencyDatabaseCache();

		return new MultiCurrencyStoreCurrencyLifecycleService(
			$cache,
			new MultiCurrencyStateBuilder(
				new MultiCurrencyLocalizationService(),
				new MultiCurrencyRateService( new CurrencyRateProviderRegistry() ),
				$cache
			)
		);
	}

	/**
	 * Delete options touched by the tests.
	 */
	private function delete_options(): void {
		foreach (
			array(
				self::STORE_CURRENCY_OPTION,
				self::NOTICE_OPTION,
				'wcpay_multi_currency_enabled_currencies',
				'wcpay_multi_currency_exchange_rate_cad',
				'wcpay_multi_currency_manual_rate_cad',
				'wcpay_multi_currency_exchange_rate_gbp',
				'wcpay_multi_currency_manual_rate_gbp',
				MultiCurrencyCacheInterface::CURRENCIES_KEY,
			) as $option_name
		) {
			delete_option( $option_name );
		}
	}
}
