<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistry;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDatabaseCache;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsNativeApiClientAdapter class.
 */
class WooPaymentsNativeApiClientAdapterTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should delegate to the native API client.
	 */
	public function test_delegates_to_native_api_client(): void {
		$api_client = new RecordingNativeApiClient( true, array( 'eur' => 0.91 ) );
		$sut        = new WooPaymentsNativeApiClientAdapter();
		$sut->init( $api_client );

		$this->assertTrue( $sut->is_server_connected(), 'The adapter should use the native API availability signal.' );
		$this->assertSame( array( 'eur' => 0.91 ), $sut->get_currency_rates( 'usd', array( 'eur' ) ) );
		$this->assertSame( 'usd', $api_client->last_currency_from );
		$this->assertSame( array( 'eur' ), $api_client->last_currencies_to );
	}

	/**
	 * @testdox Should report the server as disconnected and pass a failed rate fetch on when the native API client throws.
	 */
	public function test_passes_a_failed_rate_fetch_on(): void {
		$sut = new WooPaymentsNativeApiClientAdapter();
		$sut->init( new ThrowingNativeApiClient() );

		$this->assertFalse( $sut->is_server_connected(), 'Server connection checks should fail closed.' );
		$this->expectException( \RuntimeException::class );
		$sut->get_currency_rates( 'usd', array( 'eur' ) );
	}

	/**
	 * @testdox Should keep the last fetched rates and mark the cache entry errored when a later rate fetch fails.
	 */
	public function test_failed_rate_fetch_keeps_the_last_fetched_rates(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		// A good fetch two days ago, past the storefront's 12-hour lifetime.
		update_option(
			MultiCurrencyCacheInterface::CURRENCIES_KEY,
			array(
				'data'               => array(
					'currencies' => array( 'gbp' => 0.82 ),
					'updated'    => 123456,
				),
				'fetched'            => time() - 2 * DAY_IN_SECONDS,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
		$adapter = new WooPaymentsNativeApiClientAdapter();
		$adapter->init( new FailingRatesNativeApiClient() );
		$registry = new CurrencyRateProviderRegistry();
		$registry->register( new WooPaymentsCurrencyRateProvider( new ConnectedMultiCurrencyAccount(), $adapter ) );
		$builder = new MultiCurrencyStateBuilder( new MultiCurrencyLocalizationService(), new MultiCurrencyRateService( $registry ), new MultiCurrencyDatabaseCache() );

		$enabled = $builder->build()->get_enabled_currencies();
		$stored  = get_option( MultiCurrencyCacheInterface::CURRENCIES_KEY );

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $enabled ) );
		$this->assertSame( 0.82, $enabled['GBP']->get_rate() );
		$this->assertSame( array( 'gbp' => 0.82 ), $stored['data']['currencies'] );
		$this->assertTrue( $stored['errored'] );
		$this->assertSame( 1, $stored['consecutive_errors'] );
	}
}

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- Test doubles live next to the tests they support.
	/**
	 * Recording native API-client test double.
	 */
class RecordingNativeApiClient extends WooPaymentsApiClient {

	/**
	 * Whether the API client is available.
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
	 * Last source currency.
	 *
	 * @var string|null
	 */
	public ?string $last_currency_from = null;

	/**
	 * Last target currencies.
	 *
	 * @var string[]|null
	 */
	public ?array $last_currencies_to = null;

	/**
	 * Constructor.
	 *
	 * @param bool                $available Whether the API client is available.
	 * @param array<string,mixed> $rates     Rates to return.
	 */
	public function __construct( bool $available, array $rates ) {
		$this->available = $available;
		$this->rates     = $rates;
	}

	/**
	 * Tell whether the transport is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Get currency rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 */
	public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
		$this->last_currency_from = $currency_from;
		$this->last_currencies_to = $currencies_to;

		return $this->rates;
	}
}

	/**
	 * Throwing native API-client test double.
	 */
class ThrowingNativeApiClient extends WooPaymentsApiClient {

	/**
	 * Throw when checking availability.
	 *
	 * @throws \RuntimeException Always thrown.
	 */
	public function is_available(): bool {
		throw new \RuntimeException( 'API failed' );
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
	/**
	 * Throw when fetching rates.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Always thrown.
	 */
	public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
		unset( $currency_from, $currencies_to );

		throw new \RuntimeException( 'API failed' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}

	/**
	 * Native API-client test double that is reachable but fails every rate fetch.
	 */
class FailingRatesNativeApiClient extends WooPaymentsApiClient {

	/**
	 * Report the transport as available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
	/**
	 * Fail the rate fetch as the transport does on a server error.
	 *
	 * @param string        $currency_from Currency to convert from.
	 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
	 * @return array<string,mixed>
	 * @throws \RuntimeException Always thrown.
	 */
	public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
		unset( $currency_from, $currencies_to );

		throw new \RuntimeException( 'Server error' );
	}
	// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
}

	/**
	 * Connected account test double.
	 */
class ConnectedMultiCurrencyAccount implements MultiCurrencyAccountInterface {

	/**
	 * Report the account as connected.
	 *
	 * @param bool $on_error Value to return on error.
	 * @return bool
	 */
	public function is_provider_connected( bool $on_error = false ): bool {
		unset( $on_error );

		return true;
	}

	/**
	 * Report the account as not rejected.
	 *
	 * @return bool
	 */
	public function is_account_rejected(): bool {
		return false;
	}

	/**
	 * Return no cached account data.
	 *
	 * @param bool $force_refresh Whether to refresh.
	 * @return array<string,mixed>
	 */
	public function get_cached_account_data( bool $force_refresh = false ) {
		unset( $force_refresh );

		return array();
	}

	/**
	 * Return no account customer currencies.
	 *
	 * @return string[]
	 */
	public function get_account_customer_supported_currencies(): array {
		return array();
	}

	/**
	 * Return no supported countries.
	 *
	 * @return array<string,string>
	 */
	public function get_supported_countries(): array {
		return array();
	}

	/**
	 * Return no onboarding URL.
	 *
	 * @return string
	 */
	public function get_provider_onboarding_page_url(): string {
		return '';
	}
}
	// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName
