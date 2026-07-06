<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyStateBuilder class.
 */
class MultiCurrencyStateBuilderTest extends WC_Unit_Test_Case {

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Option keys touched by these tests.
	 *
	 * @var string[]
	 */
	private array $option_keys = array(
		'wcpay_multi_currency_enabled_currencies',
		'wcpay_multi_currency_exchange_rate_gbp',
		'wcpay_multi_currency_manual_rate_gbp',
		'wcpay_multi_currency_price_rounding_gbp',
		'wcpay_multi_currency_price_charm_gbp',
		'wcpay_multi_currency_exchange_rate_eur',
		'wcpay_multi_currency_manual_rate_eur',
		'wcpay_multi_currency_price_rounding_eur',
		'wcpay_multi_currency_price_charm_eur',
		'wcpay_multi_currency_exchange_rate_jpy',
		'wcpay_multi_currency_manual_rate_jpy',
		'wcpay_multi_currency_price_rounding_jpy',
		'wcpay_multi_currency_price_charm_jpy',
		'wcpay_multi_currency_stored_customer_currencies',
		MultiCurrencyCacheInterface::CURRENCIES_KEY,
	);

	/**
	 * Set up test state.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_currency = get_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_currency', 'USD' );
		$this->delete_options();
		wp_set_current_user( 0 );
	}

	/**
	 * Clean up test state.
	 */
	public function tear_down(): void {
		$this->delete_options();
		remove_all_filters( 'woocommerce_currency' );
		remove_all_filters( 'wcpay_multi_currency_override_selected_currency' );
		remove_all_filters( 'wcpay_multi_currency_should_return_store_currency' );
		update_option( 'woocommerce_currency', $this->original_currency );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * @testdox Should build default-only state when no currencies are enabled.
	 */
	public function test_builds_default_only_state_without_enabled_option(): void {
		$state = $this->create_builder()->build();

		$this->assertSame( array( 'USD' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( array( 'USD' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 'USD', $state->get_default_currency()->get_code() );
		$this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should build manual enabled currencies without a provider.
	 */
	public function test_builds_manual_enabled_currencies_without_provider(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR', 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		update_option( 'wcpay_multi_currency_price_rounding_gbp', '0.50' );
		update_option( 'wcpay_multi_currency_price_charm_gbp', '-0.10' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.91' );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '151' );

		$state = $this->create_builder()->build();

		$this->assertSame( array( 'USD', 'EUR', 'JPY', 'GBP' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( array( 'USD', 'EUR', 'JPY', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.8, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertSame( 0.91, $state->get_enabled_currencies()['EUR']->get_rate() );
		$this->assertSame( '0.50', $state->get_enabled_currencies()['GBP']->get_rounding() );
		$this->assertSame( -0.1, $state->get_enabled_currencies()['GBP']->get_charm() );
		$this->assertSame( '100', $state->get_enabled_currencies()['JPY']->get_rounding() );
	}

	/**
	 * @testdox Should not re-read per-currency rate options across build loops.
	 */
	public function test_does_not_reread_per_currency_rate_options_across_build_loops(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );

		$exchange_rate_reads = 0;
		$manual_rate_reads   = 0;
		add_filter(
			'option_wcpay_multi_currency_exchange_rate_gbp',
			static function ( $value ) use ( &$exchange_rate_reads ) {
				++$exchange_rate_reads;

				return $value;
			}
		);
		add_filter(
			'option_wcpay_multi_currency_manual_rate_gbp',
			static function ( $value ) use ( &$manual_rate_reads ) {
				++$manual_rate_reads;

				return $value;
			}
		);

		$this->create_builder()->build();

		remove_all_filters( 'option_wcpay_multi_currency_exchange_rate_gbp' );
		remove_all_filters( 'option_wcpay_multi_currency_manual_rate_gbp' );

		$this->assertSame(
			1,
			$manual_rate_reads,
			'A manual currency should resolve its rate once, not once per build loop.'
		);
		$this->assertLessThanOrEqual(
			2,
			$exchange_rate_reads,
			'The manual-rate flag and rate resolution should not both re-read the exchange-rate option per loop.'
		);
	}

	/**
	 * @testdox Should refresh automatic currencies from the available provider.
	 */
	public function test_refreshes_automatic_currencies_from_available_provider(): void {
		$registry = new CurrencyRateProviderRegistry();
		$registry->register( $this->create_available_rate_provider() );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$state  = $this->create_builder( $registry )->build();
		$cached = get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertIsArray( $cached );
		$this->assertSame( array( 'gbp' => 0.82 ), $cached['data']['currencies'] );
		$this->assertIsInt( $cached['data']['updated'] );
	}

	/**
	 * @testdox Should filter automatic currencies by provider-supported currencies.
	 */
	public function test_filters_automatic_currencies_by_provider_supported_currencies(): void {
		$registry = new CurrencyRateProviderRegistry();
		$registry->register(
			$this->create_available_rate_provider(
				array(
					'gbp' => 0.82,
					'eur' => 0.91,
				),
				array( 'GBP' )
			)
		);
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$state = $this->create_builder( $registry )->build();

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
	}

	/**
	 * @testdox Should skip automatic currencies when no provider rate is available.
	 */
	public function test_skips_automatic_currency_without_provider_rate(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$state = $this->create_builder()->build();

		$this->assertSame( array( 'USD' ), array_keys( $state->get_enabled_currencies() ) );
	}

	/**
	 * @testdox Should build automatic currencies from the preserved cache.
	 */
	public function test_builds_automatic_currencies_from_preserved_cache(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array(
						'gbp' => 0.82,
						'eur' => 0.91,
					),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);

		$state = $this->create_builder()->build();

		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertSame( 123456, $state->get_enabled_currencies()['GBP']->get_last_updated() );
	}

	/**
	 * @testdox Should select the enabled user-meta currency.
	 */
	public function test_selects_enabled_user_meta_currency(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );

		$state = $this->create_builder()->build();

		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should keep store default currency when WooCommerce currency is already filtered.
	 */
	public function test_keeps_store_default_currency_when_woocommerce_currency_is_filtered(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		add_filter(
			'woocommerce_currency',
			static function () {
				return 'GBP';
			},
			900
		);

		$state = $this->create_builder()->build();

		$this->assertSame( 'USD', $state->get_default_currency()->get_code() );
		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
		$this->assertSame( 0.8, $state->get_selected_currency()->get_rate() );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
	}

	/**
	 * @testdox Should select compatibility override currency when enabled.
	 */
	public function test_selects_compatibility_override_currency_when_enabled(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () {
				return 'GBP';
			}
		);

		$state = $this->create_builder()->build();

		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should force store currency when compatibility filter requests it.
	 */
	public function test_forces_store_currency_when_compatibility_filter_requests_it(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );

		$state = $this->create_builder()->build();

		$this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should fall back to default when compatibility override is not enabled.
	 */
	public function test_falls_back_to_default_when_compatibility_override_is_not_enabled(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () {
				return 'EUR';
			}
		);

		$state = $this->create_builder()->build();

		$this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should fall back to default when stored currency is not enabled.
	 */
	public function test_falls_back_to_default_when_stored_currency_is_not_enabled(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'EUR' );

		$state = $this->create_builder()->build();

		$this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should read stored customer currencies without mutating them.
	 */
	public function test_reads_stored_customer_currencies(): void {
		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'gbp', 'JPY', 'bad-code' ) );

		$state = $this->create_builder()->build();

		$this->assertSame( array( 'GBP', 'JPY' ), $state->get_customer_currencies() );
		$this->assertSame( array( 'gbp', 'JPY', 'bad-code' ), get_option( 'wcpay_multi_currency_stored_customer_currencies' ) );
	}

	/**
	 * @testdox Should return the same memoized state instance on repeated calls.
	 */
	public function test_memoizes_state_across_repeated_calls(): void {
		$cache   = $this->create_counting_cache();
		$builder = $this->create_builder( null, $cache );

		$first  = $builder->build();
		$second = $builder->build();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $cache->get_call_count );
	}

	/**
	 * @testdox Should rebuild the state after reset is called.
	 */
	public function test_reset_rebuilds_state(): void {
		$cache   = $this->create_counting_cache();
		$builder = $this->create_builder( null, $cache );

		$first = $builder->build();
		$builder->reset();
		$second = $builder->build();

		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $cache->get_call_count );
	}

	/**
	 * @testdox Should reflect a changed enabled-currencies option after reset.
	 */
	public function test_reset_reflects_updated_enabled_currencies(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		$builder = $this->create_builder();

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $builder->build()->get_enabled_currencies() ) );

		update_option( 'wcpay_multi_currency_enabled_currencies', array() );

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $builder->build()->get_enabled_currencies() ) );

		$builder->reset();

		$this->assertSame( array( 'USD' ), array_keys( $builder->build()->get_enabled_currencies() ) );
	}

	/**
	 * Delete options touched by these tests.
	 */
	private function delete_options(): void {
		foreach ( $this->option_keys as $option_key ) {
			delete_option( $option_key );
		}
	}

	/**
	 * Create the state builder.
	 *
	 * @param CurrencyRateProviderRegistry|null $registry Rate provider registry.
	 * @param MultiCurrencyCacheInterface|null  $cache    Multi-currency cache.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_builder( ?CurrencyRateProviderRegistry $registry = null, ?MultiCurrencyCacheInterface $cache = null ): MultiCurrencyStateBuilder {
		return new MultiCurrencyStateBuilder(
			$this->create_localization(),
			new MultiCurrencyRateService( $registry ?? new CurrencyRateProviderRegistry() ),
			$cache ?? new MultiCurrencyDatabaseCache()
		);
	}

	/**
	 * Create a cache double that counts get() calls.
	 *
	 * @return MultiCurrencyCacheInterface
	 */
	private function create_counting_cache(): MultiCurrencyCacheInterface {
		return new class() implements MultiCurrencyCacheInterface {
			/**
			 * Number of get() calls.
			 *
			 * @var int
			 */
			public int $get_call_count = 0;

			/**
			 * Get a value from cache.
			 *
			 * @param string $key   Cache key.
			 * @param bool   $force Whether to return cached data without checking expiry.
			 * @return mixed
			 */
			public function get( string $key, bool $force = false ) {
				unset( $key, $force );
				++$this->get_call_count;

				return null;
			}

			/**
			 * Get a value from cache or regenerate and store it.
			 *
			 * @param string   $key           Cache key.
			 * @param callable $generator     Regenerates missing data.
			 * @param callable $validate_data Validates cached data.
			 * @param bool     $force_refresh Whether to force regeneration.
			 * @param bool     $refreshed     Set true when cache is refreshed successfully.
			 * @return mixed|null
			 */
			public function get_or_add( string $key, callable $generator, callable $validate_data, bool $force_refresh = false, bool &$refreshed = false ) {
				unset( $key, $generator, $validate_data, $force_refresh, $refreshed );

				return null;
			}

			/**
			 * Delete a cache value.
			 *
			 * @param string $key Cache key.
			 */
			public function delete( string $key ): void {
				unset( $key );
			}
		};
	}

	/**
	 * Create an available rate provider.
	 *
	 * @param array<string,mixed> $rates                Rates to return.
	 * @param string[]            $supported_currencies Supported currency codes.
	 * @return CurrencyRateProvider
	 */
	private function create_available_rate_provider( array $rates = array( 'gbp' => 0.82 ), array $supported_currencies = array() ): CurrencyRateProvider {
		return new class( $rates, $supported_currencies ) implements CurrencyRateProvider {
			/**
			 * Rates to return.
			 *
			 * @var array<string,mixed>
			 */
			private array $rates;

			/**
			 * Supported currency codes.
			 *
			 * @var string[]
			 */
			private array $supported_currencies;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $rates                Rates to return.
			 * @param string[]            $supported_currencies Supported currency codes.
			 */
			public function __construct( array $rates, array $supported_currencies ) {
				$this->rates                = $rates;
				$this->supported_currencies = $supported_currencies;
			}

			/**
			 * Get the provider identifier.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'available-provider';
			}

			/**
			 * Tell whether this provider is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Get supported currencies.
			 *
			 * @return string[]
			 */
			public function get_supported_currencies(): array {
				return $this->supported_currencies;
			}

			/**
			 * Get provider rates.
			 *
			 * @param string        $currency_from Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return $this->rates;
			}
		};
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
