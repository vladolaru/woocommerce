<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
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
	 * @testdox Should fail closed when the native API client throws.
	 */
	public function test_fails_closed_when_native_api_client_throws(): void {
		$sut = new WooPaymentsNativeApiClientAdapter();
		$sut->init( new ThrowingNativeApiClient() );

		$this->assertFalse( $sut->is_server_connected(), 'Server connection checks should fail closed.' );
		$this->assertSame( array(), $sut->get_currency_rates( 'usd', array( 'eur' ) ), 'Rate lookups should fail closed.' );
	}
}

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
}
