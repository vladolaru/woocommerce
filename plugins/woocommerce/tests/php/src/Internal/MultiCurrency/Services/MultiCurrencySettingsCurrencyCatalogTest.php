<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySettingsCurrencyCatalog class.
 */
class MultiCurrencySettingsCurrencyCatalogTest extends WC_Unit_Test_Case {

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
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
	}

	/**
	 * Clean up test fixtures.
	 */
	public function tear_down(): void {
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		update_option( 'woocommerce_currency', $this->original_currency );

		parent::tear_down();
	}

	/**
	 * @testdox Should expose every WooCommerce currency with the store currency first.
	 */
	public function test_exposes_the_static_woocommerce_currency_catalog_with_nullable_rates(): void {
		$catalog    = $this->create_catalog();
		$currencies = $catalog->get_store_currencies();
		$expected   = array_merge( array( 'USD' ), array_diff( array_keys( get_woocommerce_currencies() ), array( 'USD' ) ) );

		$this->assertSame( $expected, array_keys( $currencies['available'] ) );
		$this->assertSame( 1.0, $currencies['available']['USD']['rate'] );
		$this->assertNull( $currencies['available']['GBP']['rate'] );
		$this->assertSame( 'gbp', $currencies['available']['GBP']['id'] );
		$this->assertSame( get_woocommerce_currencies()['GBP'], $currencies['available']['GBP']['name'] );
		$this->assertSame( get_woocommerce_currency_symbol( 'GBP' ), $currencies['available']['GBP']['symbol'] );
		$this->assertTrue( $catalog->contains( 'GBP' ) );
		$this->assertFalse( $catalog->contains( 'XYZ' ) );
	}

	/**
	 * @testdox Should retain known configured currencies that have no usable runtime rate.
	 */
	public function test_retains_known_configured_inactive_currencies_and_discards_unknown_codes(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'gbp', 'XYZ', '', 'GBP' ) );

		$catalog    = $this->create_catalog();
		$currencies = $catalog->get_store_currencies();

		$this->assertSame( array( 'GBP' ), $catalog->get_configured_currency_codes() );
		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $currencies['enabled'] ) );
		$this->assertNull( $currencies['enabled']['GBP']['rate'] );
		$this->assertArrayNotHasKey( 'XYZ', $currencies['enabled'] );
	}

	/**
	 * @testdox Should reuse positive runtime currency metadata in the static catalog.
	 */
	public function test_reuses_runtime_currency_metadata_when_a_currency_has_a_usable_rate(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$catalog    = $this->create_catalog( array( 'USD', 'EUR' ), array( 'USD', 'EUR' ) );
		$currencies = $catalog->get_store_currencies();

		$this->assertSame( 0.91, $currencies['available']['EUR']['rate'] );
		$this->assertSame( 0.5, $currencies['available']['EUR']['charm'] );
		$this->assertSame( '1.00', $currencies['available']['EUR']['rounding'] );
		$this->assertSame( 123456, $currencies['available']['EUR']['last_updated'] );
		$this->assertSame( $currencies['available']['EUR'], $currencies['enabled']['EUR'] );
	}

	/**
	 * @testdox Should describe absent, unavailable, and available automatic rate sources.
	 */
	public function test_describes_automatic_rate_source_availability_without_conflating_an_outage_with_absence(): void {
		$this->assertSame(
			array(
				'available' => false,
				'source'    => null,
			),
			$this->create_catalog()->get_automatic_rates_descriptor()
		);
		$this->assertSame(
			array(
				'available' => false,
				'source'    => 'outage',
			),
			$this->create_catalog( array( 'USD' ), array( 'USD' ), false )->get_automatic_rates_descriptor()
		);
		$this->assertSame(
			array(
				'available' => true,
				'source'    => 'available',
			),
			$this->create_catalog( array( 'USD' ), array( 'USD' ), true )->get_automatic_rates_descriptor()
		);
	}

	/**
	 * Create the catalog under test.
	 *
	 * @param string[]  $available_codes Runtime available currency codes.
	 * @param string[]  $enabled_codes   Runtime enabled currency codes.
	 * @param bool|null $provider_state  Whether a registered provider is available, or null when none exists.
	 * @return MultiCurrencySettingsCurrencyCatalog
	 */
	private function create_catalog( array $available_codes = array( 'USD' ), array $enabled_codes = array( 'USD' ), ?bool $provider_state = null ): MultiCurrencySettingsCurrencyCatalog {
		$registry = new CurrencyRateProviderRegistry();
		if ( null !== $provider_state ) {
			$registry->register( $this->create_provider( $provider_state ) );
		}

		return new MultiCurrencySettingsCurrencyCatalog(
			$this->create_localization(),
			$this->create_state_builder( $available_codes, $enabled_codes ),
			new MultiCurrencyRateService( $registry )
		);
	}

	/**
	 * Create a fixed state builder.
	 *
	 * @param string[] $available_codes Available currency codes.
	 * @param string[] $enabled_codes   Enabled currency codes.
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder( array $available_codes, array $enabled_codes ): MultiCurrencyStateBuilder {
		$localization = $this->create_localization();
		$available    = array();
		foreach ( $available_codes as $currency_code ) {
			$currency = new MultiCurrencyCurrency( $localization, $currency_code, 'USD' === $currency_code ? 1.0 : 0.91, 'USD' === $currency_code, 'USD' === $currency_code ? null : 123456 );
			$currency->set_charm( 0.5 );
			$currency->set_rounding( '1.00' );
			$available[ $currency_code ] = $currency;
		}

		$enabled = array();
		foreach ( $enabled_codes as $currency_code ) {
			$enabled[ $currency_code ] = $available[ $currency_code ];
		}

		$state = new MultiCurrencyState( $available, $enabled, $available['USD'], $enabled['EUR'] ?? $available['USD'] );

		return new class( $state ) extends MultiCurrencyStateBuilder {
			/** @var MultiCurrencyState */
			private MultiCurrencyState $state;

			/**
			 * @param MultiCurrencyState $state State snapshot.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};
	}

	/**
	 * Create a rate provider.
	 *
	 * @param bool $available Whether the provider is available.
	 * @return CurrencyRateProvider
	 */
	private function create_provider( bool $available ): CurrencyRateProvider {
		return new class( $available ) implements CurrencyRateProvider {
			/** @var bool */
			private bool $available;

			/**
			 * @param bool $available Whether the provider is available.
			 */
			public function __construct( bool $available ) {
				$this->available = $available;
			}

			/** @return string */
			public function get_id(): string {
				return $this->available ? 'available' : 'outage';
			}

			/** @return bool */
			public function is_available(): bool {
				return $this->available;
			}

			/** @return string[] */
			public function get_supported_currencies(): array {
				return array();
			}

			/**
			 * @param string        $currency_from Source currency.
			 * @param string[]|null $currencies_to Target currencies.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return array();
			}
		};
	}

	/**
	 * Create a localization boundary.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				return array(
					'currency_pos' => 'left',
					'num_decimals' => 'JPY' === strtoupper( (string) $currency_code ) ? 0 : 2,
				);
			}

			/**
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}
}
