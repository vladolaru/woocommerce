<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateInvalidator;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RateServices;
use WC_Unit_Test_Case;

/**
 * Tests for the StateBuilder class.
 */
class StateBuilderTest extends WC_Unit_Test_Case {

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
		DatabaseCache::CURRENCIES_KEY,
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
	 * @testdox Should not use a null array offset when no selected currency is stored.
	 */
	public function test_does_not_use_null_array_offset_without_stored_currency(): void {
		wp_set_current_user( 0 );
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->__unset( StateBuilder::CURRENCY_STORAGE_KEY );
		}

		$null_offset_deprecations = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Scoped handler asserting that PHP 8.5 emits no null-offset deprecation; restored in finally below.
		set_error_handler(
			static function ( $error_number, $error_message ) use ( &$null_offset_deprecations ) {
				unset( $error_number );
				if ( false !== stripos( (string) $error_message, 'null as an array offset' ) ) {
					$null_offset_deprecations[] = (string) $error_message;

					return true;
				}

				return false;
			},
			E_DEPRECATED
		);

		try {
			$state = $this->create_builder()->build();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'USD', $state->get_selected_currency()->get_code() );
		$this->assertSame( array(), $null_offset_deprecations );
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
	 * @testdox Should refresh automatic currencies from the available provider and cache them under the codes the WooPayments extension reads.
	 *
	 * The extension shares the rates cache and reads a rate as `$cache_data['currencies'][ $currency_code ] ?? 1.0` with an
	 * upper-case code, so a miss prices the currency at 1.0 (client 11.1.0 includes/multi-currency/MultiCurrency.php:1719).
	 */
	public function test_refreshes_automatic_currencies_from_available_provider(): void {
		$provider = $this->create_available_rate_provider( array( 'GBP' => 0.82 ) );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$state  = $this->create_builder( $provider )->build();
		$cached = get_option( DatabaseCache::CURRENCIES_KEY );

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertIsArray( $cached );
		$this->assertSame( array( 'GBP' => 0.82 ), $cached['data']['currencies'] );
		$this->assertSame( 0.82, $cached['data']['currencies']['GBP'] ?? 1.0, 'The extension must find the cached GBP rate, not fall back to 1.0.' );
		$this->assertIsInt( $cached['data']['updated'] );
	}

	/**
	 * @testdox Should rebuild a state built without a rate provider once the provider is set.
	 */
	public function test_rebuilds_the_state_once_a_rate_provider_is_set(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		$invalidator  = new StateInvalidator();
		$rate_service = RateServices::with( null, $invalidator );
		$builder      = new StateBuilder( $this->create_localization(), $rate_service, new DatabaseCache(), $invalidator );

		$this->assertSame( array( 'USD' ), array_keys( $builder->build()->get_enabled_currencies() ), 'Without a provider an automatic currency has no rate.' );

		$rate_service->set_provider( $this->create_available_rate_provider( array( 'GBP' => 0.82 ) ) );

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $builder->build()->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $builder->build()->get_enabled_currencies()['GBP']->get_rate() );
	}

	/**
	 * @testdox Should filter automatic currencies by provider-supported currencies.
	 */
	public function test_filters_automatic_currencies_by_provider_supported_currencies(): void {
		$provider = $this->create_available_rate_provider(
			array(
				'gbp' => 0.82,
				'eur' => 0.91,
			),
			array( 'GBP' )
		);
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$state = $this->create_builder( $provider )->build();

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
	 * @testdox Should ignore preserved automatic currencies when no provider is registered.
	 */
	public function test_ignores_cached_automatic_rates_when_no_provider_is_registered(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option(
			DatabaseCache::CURRENCIES_KEY,
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

		$this->assertSame( array( 'USD' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( array( 'USD' ), array_keys( $state->get_enabled_currencies() ) );
	}

	/**
	 * @testdox Should retain preserved automatic currencies when a registered provider is unavailable.
	 */
	public function test_uses_cached_automatic_rates_when_a_registered_provider_is_unavailable(): void {
		$provider = $this->create_unavailable_rate_provider();
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option(
			DatabaseCache::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array( 'gbp' => 0.82 ),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);

		$state = $this->create_builder( $provider )->build();

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertSame( 123456, $state->get_enabled_currencies()['GBP']->get_last_updated() );
	}

	/**
	 * @testdox Should offer no other currencies when WooCommerce no longer knows the store currency.
	 */
	public function test_offers_no_other_currencies_when_the_store_currency_is_unknown(): void {
		// A custom currency the store used was removed; the client then runs with no currencies (client 11.1.0 `MultiCurrency.php:302-319`).
		update_option( 'woocommerce_currency', 'XYZ' );
		// The plugin stores the enabled list and manual rates as these options (client `MultiCurrency.php:767-783`, `:1757-1770`).
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );

		$state = $this->create_builder()->build();

		$this->assertSame( array( 'XYZ' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( array( 'XYZ' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( 'XYZ', $state->get_selected_currency()->get_code() );
		$this->assertFalse( $state->has_additional_currencies_enabled() );
	}

	/**
	 * @testdox Should keep the stored customer currencies while the store currency is unknown, so they survive the next order.
	 */
	public function test_keeps_customer_currencies_while_the_store_currency_is_unknown(): void {
		update_option( 'woocommerce_currency', 'XYZ' );
		// The plugin stores the list as currency codes (client 11.1.0 `includes/multi-currency/MultiCurrency.php:700-717`).
		update_option( 'wcpay_multi_currency_stored_customer_currencies', array( 'EUR', 'GBP' ) );

		$this->assertSame( array( 'EUR', 'GBP' ), $this->create_builder()->build()->get_customer_currencies() );
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
	 * @testdox Should use the configured store currency for automatic provider rates when the display currency is filtered.
	 */
	public function test_uses_the_configured_store_currency_for_automatic_provider_rates_when_the_display_currency_is_filtered(): void {
		$currency_from = array();
		$provider      = $this->create_recording_available_rate_provider( $currency_from );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		$currency_filter = static function ( string $currency ): string {
			unset( $currency );

			return 'EUR';
		};
		add_filter( 'woocommerce_currency', $currency_filter, 1 );

		try {
			$state = $this->create_builder( $provider )->build();
		} finally {
			remove_filter( 'woocommerce_currency', $currency_filter, 1 );
		}

		$this->assertSame( 'USD', $state->get_default_currency()->get_code() );
		$this->assertTrue( $state->get_default_currency()->get_is_default() );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_available_currencies() ) );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( array( 'usd' ), $currency_from );
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
	 * @testdox Should keep the shopper's selected currency when a compatibility filter asks for the store currency code.
	 */
	public function test_keeps_selected_currency_when_compatibility_filter_asks_for_the_store_currency_code(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.8' );
		add_filter( 'wcpay_multi_currency_should_return_store_currency', '__return_true' );

		$state = $this->create_builder()->build();

		// The client reads this filter only for the woocommerce_currency code (client 11.1.0 `includes/multi-currency/FrontendCurrencies.php:191-199`).
		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Should apply a selected-currency override added after the state was built.
	 */
	public function test_applies_a_selected_currency_override_on_each_lookup(): void {
		$user_id = self::factory()->user->create();
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wcpay_currency', 'GBP' );
		// The plugin stores the enabled list and per-currency rate settings as these options
		// (client 11.1.0 `includes/multi-currency/MultiCurrency.php:767-783`, `:1757-1770`).
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		foreach ( array(
			'gbp' => '0.8',
			'eur' => '0.9',
		) as $code => $rate ) {
			update_option( 'wcpay_multi_currency_exchange_rate_' . $code, 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_' . $code, $rate );
		}
		$state = $this->create_builder()->build();
		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );

		// The client applies the override on every lookup (client 11.1.0 `includes/multi-currency/MultiCurrency.php:802-810`).
		$override = static fn() => 'EUR';
		add_filter( 'wcpay_multi_currency_override_selected_currency', $override );
		$this->assertSame( 'EUR', $state->get_selected_currency()->get_code() );

		remove_filter( 'wcpay_multi_currency_override_selected_currency', $override );
		$this->assertSame( 'GBP', $state->get_selected_currency()->get_code() );
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
	 * @testdox Should select a subscription's currency the merchant disabled, at the rate it is known by, and no other disabled currency.
	 *
	 * A renewal, resubscribe or switch cart overrides the selection with its subscription's currency, which holds the
	 * subscription's stored amounts, and marks it as that order's currency. A recorded store run with GBP disabled charged a
	 * 7.45 GBP renewal as 7.45 USD. Disabling a currency in the settings deletes its rate settings, which leaves the provider's
	 * cached rate; a merchant who only edited the enabled list keeps them. Any other override that names a disabled currency
	 * gets the store currency, as client 11.1.0 resolves it (`includes/multi-currency/MultiCurrency.php:802-811`).
	 *
	 * @dataProvider disabled_override_currencies
	 *
	 * @param array<string,mixed> $options    Options the store holds.
	 * @param bool                $marked     Whether the override is marked as an order's currency.
	 * @param string|null         $later_code Code a later callback returns instead, if any.
	 * @param array<int,mixed>    $expected   Expected code, rate, charm and rounding.
	 */
	public function test_selects_a_disabled_currency_only_for_an_order( array $options, bool $marked, ?string $later_code, array $expected ): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () use ( $marked ) {
				if ( $marked ) {
					StateBuilder::set_order_currency_override( 'GBP' );
				}

				return 'GBP';
			}
		);
		if ( null !== $later_code ) {
			add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => $later_code, 60 );
		}
		// A registered provider that cannot be reached keeps serving its cached rates.
		$provider = $this->create_unavailable_rate_provider();

		$selected = $this->create_builder( $provider )->build()->get_selected_currency();

		$this->assertSame( $expected, array( $selected->get_code(), $selected->get_rate(), $selected->get_charm(), $selected->get_rounding() ) );
	}

	/**
	 * @testdox Should not honour an order currency mark left by a filter run the builder did not start.
	 *
	 * Code outside the builder can apply the override filter, and the Subscriptions compatibility marks its code whenever the
	 * filter runs; it returns an earlier callback's code unmarked.
	 */
	public function test_ignores_an_order_currency_mark_left_by_another_filter_run(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		update_option( DatabaseCache::CURRENCIES_KEY, $this->disabled_override_currencies()['order currency, cached provider rate'][0][ DatabaseCache::CURRENCIES_KEY ] );
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function ( $selected_currency ) {
				if ( $selected_currency ) {
					return $selected_currency;
				}
				StateBuilder::set_order_currency_override( 'GBP' );

				return 'GBP';
			},
			50
		);
		/** This filter is documented in src/Internal/MultiCurrency/Services/StateBuilder.php */
		apply_filters( 'wcpay_multi_currency_override_selected_currency', false );
		add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'GBP', 10 );
		$provider = $this->create_unavailable_rate_provider();

		$this->assertSame( 'USD', $this->create_builder( $provider )->build()->get_selected_currency()->get_code() );
	}

	/**
	 * Rate sources left for GBP after it was disabled, and overrides that are not an order's currency.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:string|null,3:array<int,mixed>}>
	 */
	public function disabled_override_currencies(): array {
		$cached_rates = array(
			DatabaseCache::CURRENCIES_KEY => array(
				'data'               => array(
					'currencies' => array(
						'gbp' => 0.79,
						'eur' => 0.91,
					),
					'updated'    => 123456,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			),
		);
		$manual_rate  = array(
			'wcpay_multi_currency_exchange_rate_gbp'  => 'manual',
			'wcpay_multi_currency_manual_rate_gbp'    => '0.73',
			'wcpay_multi_currency_price_rounding_gbp' => '0.50',
			'wcpay_multi_currency_price_charm_gbp'    => '-0.05',
		);
		$store        = array( 'USD', 1.0, 0.0, '0' );

		return array(
			'order currency, cached provider rate'      => array( $cached_rates, true, null, array( 'GBP', 0.79, 0.0, '1.00' ) ),
			'order currency, manual rate kept'          => array( $manual_rate, true, null, array( 'GBP', 0.73, -0.05, '0.50' ) ),
			'order currency, manual rate before cached' => array( $manual_rate + $cached_rates, true, null, array( 'GBP', 0.73, -0.05, '0.50' ) ),
			'another plugin\'s override'                => array( $cached_rates, false, null, $store ),
			'order currency replaced by a later one'    => array( $cached_rates, true, 'EUR', $store ),
		);
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
	 * @testdox Should rebuild a missing customer currency list from the currencies orders were placed in.
	 */
	public function test_rebuilds_missing_customer_currencies_from_orders(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		foreach ( array(
			'gbp' => '0.8',
			'eur' => '0.9',
		) as $code => $rate ) {
			update_option( 'wcpay_multi_currency_exchange_rate_' . $code, 'manual' );
			update_option( 'wcpay_multi_currency_manual_rate_' . $code, $rate );
		}
		foreach ( array( 'EUR', 'JPY' ) as $currency ) {
			$order = \WC_Helper_Order::create_order();
			$order->set_currency( $currency );
			$order->save();
		}

		$currencies = $this->create_builder()->build()->get_customer_currencies();

		// The client keeps only currencies it offers, sorted, and stores the list (client 11.1.0 `includes/multi-currency/MultiCurrency.php:1559-1601`).
		$this->assertSame( array( 'EUR' ), $currencies );
		$this->assertSame( array( 'EUR' ), get_option( 'wcpay_multi_currency_stored_customer_currencies' ) );
	}

	/**
	 * @testdox Should read stored customer currencies only when asked for them, not while building state for a render.
	 */
	public function test_reads_stored_customer_currencies_only_on_demand(): void {
		$reads      = 0;
		$count_read = static function ( $pre ) use ( &$reads ) {
			++$reads;
			return $pre;
		};
		add_filter( 'pre_option_wcpay_multi_currency_stored_customer_currencies', $count_read );

		try {
			$state = $this->create_builder()->build();
			$state->get_enabled_currencies();
			$state->get_selected_currency();
			$reads_during_render = $reads;
			$state->get_customer_currencies();
			$state->get_customer_currencies();
		} finally {
			remove_filter( 'pre_option_wcpay_multi_currency_stored_customer_currencies', $count_read );
		}

		$this->assertSame( 0, $reads_during_render );
		$this->assertSame( 1, $reads );
	}

	/**
	 * @testdox Should return the same memoized state instance on repeated calls.
	 */
	public function test_memoizes_state_across_repeated_calls(): void {
		$cache    = $this->create_counting_cache();
		$provider = $this->create_unavailable_rate_provider();
		$builder  = $this->create_builder( $provider, $cache );

		$first  = $builder->build();
		$second = $builder->build();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $cache->get_call_count );
	}

	/**
	 * @testdox Should rebuild the state after reset is called.
	 */
	public function test_reset_rebuilds_state(): void {
		$cache    = $this->create_counting_cache();
		$provider = $this->create_unavailable_rate_provider();
		$builder  = $this->create_builder( $provider, $cache );

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
	 * @param CurrencyRateProviderInterface|null $provider Automatic-rate provider, or null for none.
	 * @param DatabaseCache|null                 $cache    Multi-currency cache.
	 * @return StateBuilder
	 */
	private function create_builder( ?CurrencyRateProviderInterface $provider = null, ?DatabaseCache $cache = null ): StateBuilder {
		return new StateBuilder(
			$this->create_localization(),
			RateServices::with( $provider ),
			$cache ?? new DatabaseCache()
		);
	}

	/**
	 * Create a cache double that counts get() calls.
	 *
	 * @return DatabaseCache
	 */
	private function create_counting_cache(): DatabaseCache {
		return new class() extends DatabaseCache {
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
		};
	}

	/**
	 * Create an available rate provider.
	 *
	 * @param array<string,mixed> $rates                Rates to return.
	 * @param string[]            $supported_currencies Supported currency codes.
	 * @return CurrencyRateProviderInterface
	 */
	private function create_available_rate_provider( array $rates = array( 'gbp' => 0.82 ), array $supported_currencies = array() ): CurrencyRateProviderInterface {
		return new class( $rates, $supported_currencies ) implements CurrencyRateProviderInterface {
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
			 * Tell whether the provider's account is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
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
	 * Create an available rate provider that records its source currency.
	 *
	 * @param string[] $currency_from Source currencies captured by reference.
	 * @return CurrencyRateProviderInterface
	 */
	private function create_recording_available_rate_provider( array &$currency_from ): CurrencyRateProviderInterface {
		return new class( $currency_from ) implements CurrencyRateProviderInterface {
			/** @var string[] */
			private array $currency_from;

			/**
			 * Constructor.
			 *
			 * @param string[] $currency_from Source currencies captured by reference.
			 */
			public function __construct( array &$currency_from ) {
				$this->currency_from = &$currency_from;
			}

			/**
			 * Get the provider identifier.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'recording-provider';
			}

			/**
			 * Tell whether automatic rates are available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Tell whether the provider's account is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}

			/**
			 * Get supported currencies.
			 *
			 * @return string[]
			 */
			public function get_supported_currencies(): array {
				return array();
			}

			/**
			 * Get rates and record the source currency.
			 *
			 * @param string        $source Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into.
			 * @return array<string,float>
			 */
			public function get_currency_rates( string $source, ?array $currencies_to = null ): array {
				unset( $currencies_to );
				$this->currency_from[] = $source;

				return array( 'gbp' => 0.82 );
			}
		};
	}

	/**
	 * Create an unavailable rate provider.
	 *
	 * @return CurrencyRateProviderInterface
	 */
	private function create_unavailable_rate_provider(): CurrencyRateProviderInterface {
		return new class() implements CurrencyRateProviderInterface {
			/**
			 * Get the provider identifier.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return 'unavailable-provider';
			}

			/**
			 * Tell whether automatic rates are currently available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return false;
			}

			/**
			 * Tell whether the provider's account is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return true;
			}

			/**
			 * Get supported currencies.
			 *
			 * @return string[]
			 */
			public function get_supported_currencies(): array {
				return array();
			}

			/**
			 * Get currency rates.
			 *
			 * @param string        $currency_from Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return array();
			}
		};
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
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
