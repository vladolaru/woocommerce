<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RateServices;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyRateService class.
 */
class MultiCurrencyRateServiceTest extends WC_Unit_Test_Case {

	/**
	 * Option keys touched by these tests.
	 *
	 * @var string[]
	 */
	private array $option_keys = array(
		'wcpay_multi_currency_exchange_rate_eur',
		'wcpay_multi_currency_manual_rate_eur',
	);

	/**
	 * Clean up options before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->delete_rate_options();
	}

	/**
	 * Clean up options after each test.
	 */
	public function tear_down(): void {
		$this->delete_rate_options();

		parent::tear_down();
	}

	/**
	 * @testdox Should return manual rates without a provider.
	 */
	public function test_returns_manual_rate_without_provider(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '1.25' );

		$service = RateServices::with();

		$this->assertSame( 1.25, $service->get_rate( 'USD', 'EUR' ) );
	}

	/**
	 * @testdox Should return null for invalid manual rates.
	 *
	 * @dataProvider invalid_manual_rate_provider
	 *
	 * @param mixed $manual_rate Manual rate option value.
	 */
	public function test_returns_null_for_invalid_manual_rates( $manual_rate ): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', $manual_rate );

		$service = RateServices::with();

		$this->assertNull( $service->get_rate( 'USD', 'EUR' ) );
	}

	/**
	 * Get invalid manual rate cases.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function invalid_manual_rate_provider(): array {
		return array(
			'zero'     => array( '0' ),
			'negative' => array( '-1' ),
			'text'     => array( 'not-a-rate' ),
			'missing'  => array( null ),
		);
	}

	/**
	 * @testdox Should resolve automatic rates from the available provider.
	 */
	public function test_resolves_automatic_rate_from_available_provider(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$provider = $this->create_provider( true, array( 'eur' => 1.2 ) );
		$service  = RateServices::with( $provider );

		$this->assertSame( 1.2, $service->get_rate( 'USD', 'EUR' ) );
	}

	/**
	 * @testdox Should accept uppercase provider rate keys.
	 */
	public function test_accepts_uppercase_provider_rate_keys(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$provider = $this->create_provider( true, array( 'EUR' => 1.3 ) );
		$service  = RateServices::with( $provider );

		$this->assertSame( 1.3, $service->get_rate( 'USD', 'EUR' ) );
	}

	/**
	 * @testdox Should resolve bulk automatic rates from the available provider.
	 */
	public function test_resolves_bulk_automatic_rates_from_available_provider(): void {
		$provider = $this->create_provider(
			true,
			array(
				'currencies' => array(
					'eur' => '1.2',
					'GBP' => 0.82,
					'bad' => 'not-a-rate',
					'jpy' => 0,
				),
			)
		);
		$service  = RateServices::with( $provider );

		$this->assertTrue( $service->has_available_provider() );
		$this->assertSame(
			array(
				'EUR' => 1.2,
				'GBP' => 0.82,
			),
			$service->get_rates( 'USD' )
		);
	}

	/**
	 * @testdox Should return null for bulk rates without an available provider.
	 */
	public function test_returns_null_for_bulk_rates_without_available_provider(): void {
		$provider = $this->create_provider( false, array( 'eur' => 1.2 ) );
		$service  = RateServices::with( $provider );

		$this->assertFalse( $service->has_available_provider() );
		$this->assertNull( $service->get_rates( 'USD' ) );
	}

	/**
	 * @testdox Should tell whether a provider is set and available, and keep its source while it is unavailable: $label.
	 *
	 * @dataProvider provider_states
	 *
	 * @param string    $label     Case label.
	 * @param bool|null $available Whether the set provider is available, or null for no provider.
	 * @param bool      $has       Expected has_provider().
	 * @param bool      $is_up     Expected has_available_provider().
	 * @param ?string   $source    Expected automatic rate source.
	 */
	public function test_reports_the_provider_and_the_automatic_rate_source( string $label, ?bool $available, bool $has, bool $is_up, ?string $source ): void {
		unset( $label );
		$service = RateServices::with( null === $available ? null : $this->create_provider( $available, array(), array(), $available ? 'available' : 'outage' ) );

		$this->assertSame( $has, $service->has_provider() );
		$this->assertSame( $is_up, $service->has_available_provider() );
		$this->assertSame( $source, $service->get_automatic_rate_source_id() );
	}

	/**
	 * Provider states.
	 *
	 * @return array<string,array{string,bool|null,bool,bool,string|null}>
	 */
	public function provider_states(): array {
		return array(
			'available provider'   => array( 'available provider', true, true, true, 'available' ),
			'unavailable provider' => array( 'unavailable provider', false, true, false, 'outage' ),
			'no provider'          => array( 'no provider', null, false, false, null ),
		);
	}

	/**
	 * @testdox Should answer from the provider set last.
	 */
	public function test_answers_from_the_provider_set_last(): void {
		$service = RateServices::with( $this->create_provider( false, array(), array(), 'first' ) );

		$service->set_provider( $this->create_provider( true, array(), array(), 'second' ) );

		$this->assertTrue( $service->has_available_provider() );
		$this->assertSame( 'second', $service->get_automatic_rate_source_id() );
	}

	/**
	 * @testdox Should tell whether the provider's account is connected, false without a provider or when it fails: $label.
	 *
	 * @dataProvider connection_states
	 *
	 * @param string      $label     Case label.
	 * @param string|null $connected 'yes', 'no', 'throws', or null for no provider.
	 * @param bool        $expected  Expected is_provider_connected().
	 */
	public function test_tells_whether_the_provider_account_is_connected( string $label, ?string $connected, bool $expected ): void {
		unset( $label );
		$provider = null;
		if ( null !== $connected ) {
			$provider = $this->createMock( CurrencyRateProvider::class );
			if ( 'throws' === $connected ) {
				$provider->method( 'is_connected' )->willThrowException( new \RuntimeException( 'Account error' ) );
			} else {
				$provider->method( 'is_connected' )->willReturn( 'yes' === $connected );
			}
		}

		$this->assertSame( $expected, RateServices::with( $provider )->is_provider_connected() );
	}

	/**
	 * Provider account connection states.
	 *
	 * @return array<string,array{string,string|null,bool}>
	 */
	public function connection_states(): array {
		return array(
			'connected'     => array( 'connected', 'yes', true ),
			'not connected' => array( 'not connected', 'no', false ),
			'fails'         => array( 'fails', 'throws', false ),
			'no provider'   => array( 'no provider', null, false ),
		);
	}

	/**
	 * @testdox Should return normalized supported currency codes from provider.
	 */
	public function test_returns_normalized_supported_currency_codes_from_provider(): void {
		$provider = $this->create_provider( true, array(), array( 'gbp', 'EUR', 'bad-code' ) );
		$service  = RateServices::with( $provider );

		$this->assertSame( array( 'GBP', 'EUR' ), $service->get_supported_currency_codes() );
	}

	/**
	 * @testdox Should return all WooCommerce currency codes when provider support is empty.
	 */
	public function test_returns_all_woocommerce_currency_codes_when_provider_support_is_empty(): void {
		$provider = $this->create_provider( true, array(), array() );
		$service  = RateServices::with( $provider );

		$this->assertContains( 'USD', $service->get_supported_currency_codes() );
		$this->assertContains( 'GBP', $service->get_supported_currency_codes() );
	}

	/**
	 * @testdox Should return null for automatic rates when no provider is available.
	 */
	public function test_returns_null_for_automatic_rate_without_provider(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$provider = $this->create_provider( false, array( 'eur' => 1.2 ) );
		$service  = RateServices::with( $provider );

		$this->assertNull( $service->get_rate( 'USD', 'EUR' ) );
	}

	/**
	 * @testdox Should return no automatic rate when the provider's rate fetch fails.
	 */
	public function test_returns_null_for_automatic_rate_when_the_fetch_fails(): void {
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );
		$provider = $this->createMock( CurrencyRateProvider::class );
		$provider->method( 'is_available' )->willReturn( true );
		$provider->method( 'get_currency_rates' )->willThrowException( new \RuntimeException( 'Server error' ) );
		$service = RateServices::with( $provider );

		$this->assertNull( $service->get_rate( 'USD', 'EUR' ) );
		$this->assertNull( $service->get_rates( 'USD' ) );
	}

	/**
	 * Delete rate options touched by these tests.
	 */
	private function delete_rate_options(): void {
		foreach ( $this->option_keys as $option_key ) {
			delete_option( $option_key );
		}
	}

	/**
	 * Create a fake provider.
	 *
	 * @param bool                $available            Whether the provider is available.
	 * @param array<string,mixed> $rates                Rates to return.
	 * @param string[]            $supported_currencies Supported currency codes.
	 * @param string              $id                   Provider identifier.
	 * @return CurrencyRateProvider
	 */
	private function create_provider( bool $available, array $rates, array $supported_currencies = array(), string $id = 'fake' ): CurrencyRateProvider {
		return new class( $available, $rates, $supported_currencies, $id ) implements CurrencyRateProvider {
			/**
			 * Whether the provider is available.
			 *
			 * @var bool
			 */
			private bool $available;

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
			 * Provider identifier.
			 *
			 * @var string
			 */
			private string $id;

			/**
			 * Constructor.
			 *
			 * @param bool                $available            Whether the provider is available.
			 * @param array<string,mixed> $rates                Rates to return.
			 * @param string[]            $supported_currencies Supported currency codes.
			 * @param string              $id                   Provider identifier.
			 */
			public function __construct( bool $available, array $rates, array $supported_currencies, string $id ) {
				$this->available            = $available;
				$this->rates                = $rates;
				$this->supported_currencies = $supported_currencies;
				$this->id                   = $id;
			}

			/**
			 * Get the provider identifier.
			 *
			 * @return string
			 */
			public function get_id(): string {
				return $this->id;
			}

			/**
			 * Tell whether automatic rates are currently available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Tell whether the provider's account is connected.
			 *
			 * @return bool
			 */
			public function is_connected(): bool {
				return $this->available;
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
			 * Get currency rates.
			 *
			 * @param string        $currency_from Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
			 * @return array<string,mixed>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				return $this->rates;
			}
		};
	}
}
