<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\MultiCurrencyProviderAccountResolver;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProviderRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsLegacyApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsLegacyAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\LegacyRuntimeProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsMultiCurrencyProviderBootstrap class.
 */
class WooPaymentsMultiCurrencyProviderBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should configure legacy adapters when the WooPayments plugin owns runtime.
	 */
	public function test_configures_legacy_adapters_when_plugin_owns_runtime(): void {
		$context = $this->create_bootstrap_context( false, true );

		$context['bootstrap']->register();

		$this->assertTrue( $context['account_resolver']->is_provider_connected(), 'Resolver should delegate plugin-owned connection checks to the legacy adapter.' );
		$this->assertSame( 'https://example.test/onboarding', $context['account_resolver']->get_provider_onboarding_page_url(), 'Resolver should delegate plugin-owned onboarding URL lookups to the legacy adapter.' );
		$this->assertSame(
			array( 'eur' => 0.82 ),
			$context['provider_registry_factory']->create()->get_provider( WooPaymentsCurrencyRateProvider::PROVIDER_ID )->get_currency_rates( 'usd', array( 'eur' ) ),
			'Provider registry should use the legacy API adapter while the plugin owns runtime.'
		);
	}

	/**
	 * @testdox Should configure native adapters when the native runtime owns runtime.
	 */
	public function test_configures_native_adapters_when_native_owns_runtime(): void {
		$context = $this->create_bootstrap_context( true, false );

		$context['bootstrap']->register();

		$this->assertTrue( $context['account_resolver']->is_provider_connected(), 'Resolver should delegate native-owned connection checks to the native adapter.' );
		$this->assertStringContainsString( '/woopayments/onboarding', rawurldecode( $context['account_resolver']->get_provider_onboarding_page_url() ) );
		$this->assertSame(
			array( 'eur' => 0.91 ),
			$context['provider_registry_factory']->create()->get_provider( WooPaymentsCurrencyRateProvider::PROVIDER_ID )->get_currency_rates( 'usd', array( 'eur' ) ),
			'Provider registry should use the native API adapter while native owns runtime.'
		);
	}

	/**
	 * @testdox Should not configure adapters when neither runtime owns WooPayments.
	 */
	public function test_does_not_configure_adapters_when_no_runtime_owns_woopayments(): void {
		$context = $this->create_bootstrap_context( false, false );

		$context['bootstrap']->register();

		$this->assertFalse( $context['account_resolver']->is_provider_connected(), 'Resolver should stay disconnected without a runtime owner.' );
		$this->assertSame( '', $context['account_resolver']->get_provider_onboarding_page_url(), 'Resolver should stay unconfigured without a runtime owner.' );
		$this->assertSame( array(), $context['provider_registry_factory']->create()->get_providers(), 'Provider registry should stay empty without a runtime owner.' );
	}

	/**
	 * Create a bootstrap test context.
	 *
	 * @param bool $native_owner Whether native owns runtime.
	 * @param bool $plugin_owner Whether the plugin owns runtime.
	 * @return array<string,mixed>
	 */
	private function create_bootstrap_context( bool $native_owner, bool $plugin_owner ): array {
		$account_resolver = new MultiCurrencyProviderAccountResolver();
		$arbiter          = new class( $native_owner, $plugin_owner ) extends NativePaymentsRuntimeArbiter {
			/**
			 * Whether native owns runtime.
			 *
			 * @var bool
			 */
			private bool $native_owner;

			/**
			 * Whether the plugin owns runtime.
			 *
			 * @var bool
			 */
			private bool $plugin_owner;

			/**
			 * Constructor.
			 *
			 * @param bool $native_owner Whether native owns runtime.
			 * @param bool $plugin_owner Whether the plugin owns runtime.
			 */
			public function __construct( bool $native_owner, bool $plugin_owner ) {
				$this->native_owner = $native_owner;
				$this->plugin_owner = $plugin_owner;
			}

			/**
			 * Tell whether native code may register.
			 *
			 * @return bool
			 */
			public function should_native_register(): bool {
				return $this->native_owner;
			}

			/**
			 * Tell whether the plugin owns runtime.
			 *
			 * @return bool
			 */
			public function is_plugin_runtime_active(): bool {
				return $this->plugin_owner;
			}
		};
		$legacy_runtime   = new WooPaymentsLegacyRuntime();
		$legacy_runtime->init( new LegacyRuntimeProxy( true, null, $this->create_legacy_account(), $this->create_legacy_api_client() ) );

		$legacy_account_adapter = new WooPaymentsLegacyAccountAdapter();
		$legacy_account_adapter->init( $legacy_runtime );

		$legacy_api_client_adapter = new WooPaymentsLegacyApiClientAdapter();
		$legacy_api_client_adapter->init( $legacy_runtime );

		$native_account_adapter = new WooPaymentsNativeAccountAdapter();
		$native_account_adapter->init( $this->create_native_account_service() );

		$native_api_client_adapter = new WooPaymentsNativeApiClientAdapter();
		$native_api_client_adapter->init( $this->create_native_api_client() );

		$provider_registry_factory = new CurrencyRateProviderRegistryFactory();
		$provider_registrar        = new WooPaymentsCurrencyRateProviderRegistrar();

		$bootstrap = new WooPaymentsMultiCurrencyProviderBootstrap();
		$bootstrap->init(
			$account_resolver,
			$arbiter,
			$legacy_account_adapter,
			$legacy_api_client_adapter,
			$native_account_adapter,
			$native_api_client_adapter,
			$provider_registry_factory,
			$provider_registrar
		);

		return array(
			'account_resolver'          => $account_resolver,
			'bootstrap'                 => $bootstrap,
			'provider_registry_factory' => $provider_registry_factory,
		);
	}

	/**
	 * Create a recording legacy account test double.
	 *
	 * @return object
	 */
	private function create_legacy_account(): object {
		return new class() {
			/**
			 * Tell whether the provider account is connected.
			 *
			 * @param bool $on_error Error fallback.
			 * @return bool
			 */
			public function is_provider_connected( bool $on_error = false ): bool {
				unset( $on_error );

				return true;
			}

			/**
			 * Get the provider onboarding URL.
			 *
			 * @return string
			 */
			public function get_provider_onboarding_page_url(): string {
				return 'https://example.test/onboarding';
			}

			/**
			 * Tell whether the account is rejected.
			 *
			 * @return bool
			 */
			public function is_account_rejected(): bool {
				return false;
			}
		};
	}

	/**
	 * Create a recording legacy API-client test double.
	 *
	 * @return object
	 */
	private function create_legacy_api_client(): object {
		return new class() {
			/**
			 * Tell whether the server is connected.
			 *
			 * @return bool
			 */
			public function is_server_connected(): bool {
				return true;
			}

			/**
			 * Get currency rates.
			 *
			 * @param string        $currency_from Source currency.
			 * @param string[]|null $currencies_to Target currencies.
			 * @return array<string,float>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return array( 'eur' => 0.82 );
			}
		};
	}

	/**
	 * Create a recording native account service test double.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_native_account_service(): WooPaymentsAccountService {
		return new class() extends WooPaymentsAccountService {
			/**
			 * Tell whether WooPayments has an account cache entry.
			 *
			 * @return bool
			 */
			public function has_account(): bool {
				return true;
			}

			/**
			 * Tell whether the cached account is rejected.
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
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				unset( $force_refresh );

				return array(
					'account_id'          => 'acct_native',
					'customer_currencies' => array(
						'supported' => array( 'EUR' ),
					),
				);
			}
		};
	}

	/**
	 * Create a recording native API-client test double.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_native_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {
			/**
			 * Tell whether the transport is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Get currency rates.
			 *
			 * @param string        $currency_from Currency to convert from.
			 * @param string[]|null $currencies_to Currencies to convert into, or null for all supported.
			 * @return array<string,float>
			 */
			public function get_currency_rates( string $currency_from, ?array $currencies_to = null ): array {
				unset( $currency_from, $currencies_to );

				return array( 'eur' => 0.91 );
			}
		};
	}
}
