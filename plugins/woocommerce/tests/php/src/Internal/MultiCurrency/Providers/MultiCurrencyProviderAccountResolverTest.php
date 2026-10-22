<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Providers;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyAccountInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\MultiCurrencyProviderAccountResolver;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyProviderAccountResolver class.
 */
class MultiCurrencyProviderAccountResolverTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should resolve from the container.
	 */
	public function test_resolves_from_container(): void {
		$sut = wc_get_container()->get( MultiCurrencyProviderAccountResolver::class );

		$this->assertInstanceOf( MultiCurrencyProviderAccountResolver::class, $sut );
		$this->assertFalse( $sut->is_provider_connected(), 'A missing legacy account should fail closed.' );
	}

	/**
	 * @testdox Should delegate provider state to the configured account boundary.
	 */
	public function test_delegates_provider_state_to_configured_account_boundary(): void {
		$sut = new MultiCurrencyProviderAccountResolver();
		$sut->set_account( $this->create_account( true ) );

		$this->assertTrue( $sut->is_provider_connected() );
	}

	/**
	 * @testdox Should return defensive defaults when the configured account throws.
	 */
	public function test_returns_defensive_defaults_when_configured_account_throws(): void {
		$sut = new MultiCurrencyProviderAccountResolver();
		$sut->set_account( $this->create_account( true, true ) );

		$this->assertFalse( $sut->is_provider_connected(), 'Provider connection checks should fail closed on account errors.' );
	}

	/**
	 * Create a static account boundary.
	 *
	 * @param bool $connected Whether the provider account is connected.
	 * @param bool $throws    Whether account methods should throw.
	 * @return MultiCurrencyAccountInterface
	 */
	private function create_account( bool $connected, bool $throws = false ): MultiCurrencyAccountInterface {
		return new class( $connected, $throws ) implements MultiCurrencyAccountInterface {

			/**
			 * Whether the provider account is connected.
			 *
			 * @var bool
			 */
			private bool $connected;

			/**
			 * Whether account methods should throw.
			 *
			 * @var bool
			 */
			private bool $throws;

			/**
			 * Constructor.
			 *
			 * @param bool $connected Whether the provider account is connected.
			 * @param bool $throws    Whether account methods should throw.
			 */
			public function __construct( bool $connected, bool $throws ) {
				$this->connected = $connected;
				$this->throws    = $throws;
			}

			/**
			 * Tell whether the rate provider account is connected.
			 *
			 * @param bool $on_error Value to return on provider errors.
			 * @return bool
			 */
			public function is_provider_connected( bool $on_error = false ): bool {
				if ( $this->throws ) {
					throw new \RuntimeException( 'Provider connection unavailable.' );
				}

				return $this->connected;
			}

			/**
			 * Tell whether the connected account is rejected.
			 *
			 * @return bool
			 */
			public function is_account_rejected(): bool {
				return false;
			}

			/**
			 * Get cached provider account data.
			 *
			 * @param bool $force_refresh Whether to force-refresh provider data.
			 * @return array<string,mixed>|bool
			 */
			public function get_cached_account_data( bool $force_refresh = false ) {
				return false;
			}

			/**
			 * Get account-supported customer currencies.
			 *
			 * @return string[]
			 */
			public function get_account_customer_supported_currencies(): array {
				return array();
			}
		};
	}
}
