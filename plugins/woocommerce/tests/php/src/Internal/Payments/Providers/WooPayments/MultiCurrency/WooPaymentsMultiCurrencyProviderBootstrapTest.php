<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\MultiCurrencyProviderAccountResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsCurrencyRateProviderRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsLegacyApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsLegacyAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExtensionRuntime;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsMultiCurrencyProviderBootstrap class.
 */
class WooPaymentsMultiCurrencyProviderBootstrapTest extends WC_Unit_Test_Case {

	/**
	 * Drop the container replacements and the services resolved with them.
	 */
	public function tearDown(): void {
		try {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox Should configure legacy adapters when the WooPayments plugin owns runtime.
	 */
	public function test_configures_legacy_adapters_when_plugin_owns_runtime(): void {
		$context = $this->create_bootstrap_context( false, true );

		$context['bootstrap']->register();

		$this->assertTrue( $context['account_resolver']->is_provider_connected(), 'Resolver should delegate plugin-owned connection checks to the legacy adapter.' );
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
		$this->assertSame( array(), $context['provider_registry_factory']->create()->get_providers(), 'Provider registry should stay empty without a runtime owner.' );
	}

	/**
	 * @testdox Should tell the payment fields that Multi-Currency is on, so the Blocks totals show the currency code.
	 */
	public function test_marks_multi_currency_on_in_the_payment_fields_config(): void {
		$context = $this->create_bootstrap_context( true, false );

		$context['bootstrap']->register();

		// Client 11.1.0 adds this key whenever Multi-Currency runs (`includes/multi-currency/MultiCurrency.php:290`, `:478-480`).
		$config = apply_filters( 'wcpay_payment_fields_js_config', array( 'publishableKey' => 'pk_test_123' ) );
		$this->assertSame(
			array(
				'publishableKey'         => 'pk_test_123',
				'isMultiCurrencyEnabled' => true,
			),
			$config
		);
	}

	/**
	 * @testdox Should turn the Analytics report cache off only while the built-in WooPayments owns payments in dev mode, read when the report runs.
	 * @dataProvider provide_analytics_report_cache_cases
	 *
	 * @param bool $native_owner Whether the built-in WooPayments owns payments.
	 * @param bool $dev_mode     Whether WooPayments reports dev mode once every plugin has loaded.
	 * @param bool $earlier_veto Whether an earlier listener already turned the cache off.
	 * @param bool $expected     Whether Analytics reports use the cache.
	 */
	public function test_analytics_report_cache_follows_dev_mode_while_the_builtin_owns_payments( bool $native_owner, bool $dev_mode, bool $earlier_veto, bool $expected ): void {
		wc_get_container()->replace( WooPaymentsRuntimeArbiter::class, $this->create_arbiter( $native_owner, false ) );
		wc_get_container()->reset_all_resolved();
		$load_time_reads    = 0;
		$load_time_listener = static function () use ( &$load_time_reads ): bool {
			++$load_time_reads;
			return false;
		};
		add_filter( 'wcpay_dev_mode', $load_time_listener );

		wc_get_container()->get( WooPaymentsMultiCurrencyProviderBootstrap::class )->register();

		// A plugin that loads after WooCommerce decides dev mode, so registration must not read it.
		$this->assertSame( 0, $load_time_reads, 'Registration should not read WooPayments dev mode.' );
		remove_filter( 'wcpay_dev_mode', $load_time_listener );
		// The test environment's type is fixed for the run, so the dev-mode filter that ends the same reader sets the mode.
		add_filter( 'wcpay_dev_mode', $dev_mode ? '__return_true' : '__return_false' );
		if ( $earlier_veto ) {
			add_filter( 'woocommerce_analytics_report_should_use_cache', '__return_false', 5 );
		}

		// Client 11.1.0 turns the cache off whenever its Multi-Currency runs in WooPayments dev mode (`includes/multi-currency/Analytics.php:75-77`).
		$this->assertSame( $expected, apply_filters( 'woocommerce_analytics_report_should_use_cache', true, 'woocommerce_orders_stats_query' ) );
	}

	/**
	 * Cases for the Analytics report cache.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:bool}>
	 */
	public function provide_analytics_report_cache_cases(): array {
		return array(
			'built-in owner in dev mode'          => array( true, true, false, false ),
			'built-in owner outside dev mode'     => array( true, false, false, true ),
			'built-in owner outside dev mode, earlier veto' => array( true, false, true, false ),
			'no payments owner, staging dev mode' => array( false, true, false, true ),
		);
	}

	/**
	 * Create a bootstrap test context.
	 *
	 * @param bool $native_owner Whether native owns runtime.
	 * @param bool $plugin_owner Whether the plugin owns runtime.
	 * @return array<string,mixed>
	 */
	private function create_bootstrap_context( bool $native_owner, bool $plugin_owner ): array {
		$account_resolver  = new MultiCurrencyProviderAccountResolver();
		$arbiter           = $this->create_arbiter( $native_owner, $plugin_owner );
		$extension_runtime = new WooPaymentsExtensionRuntime();
		$legacy_account    = $this->create_legacy_account();
		$legacy_api_client = $this->create_legacy_api_client();
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => fn( $class_name ) => 'WC_Payments' === ltrim( (string) $class_name, '\\' ) || class_exists( $class_name ),
			)
		);
		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments' => array(
					'get_account_service'     => fn() => $legacy_account,
					'get_payments_api_client' => fn() => $legacy_api_client,
				),
			)
		);
		$extension_runtime->init( wc_get_container()->get( LegacyProxy::class ) );

		$legacy_account_adapter = new WooPaymentsLegacyAccountAdapter();
		$legacy_account_adapter->init( $extension_runtime );

		$legacy_api_client_adapter = new WooPaymentsLegacyApiClientAdapter();
		$legacy_api_client_adapter->init( $extension_runtime );

		$native_account_adapter = new WooPaymentsNativeAccountAdapter();
		$native_account_adapter->init( $this->create_native_account_service() );
		wc_get_container()->replace( WooPaymentsNativeAccountAdapter::class, $native_account_adapter );

		$native_api_client_adapter = new WooPaymentsNativeApiClientAdapter();
		$native_api_client_adapter->init( $this->create_native_api_client() );
		wc_get_container()->replace( WooPaymentsNativeApiClientAdapter::class, $native_api_client_adapter );

		$provider_registry_factory = new CurrencyRateProviderRegistryFactory();
		$provider_registrar        = new WooPaymentsCurrencyRateProviderRegistrar();

		$bootstrap = new WooPaymentsMultiCurrencyProviderBootstrap();
		$bootstrap->init(
			$account_resolver,
			$arbiter,
			$legacy_account_adapter,
			$legacy_api_client_adapter,
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
	 * Create a payments owner arbiter with fixed answers.
	 *
	 * @param bool $native_owner Whether native owns runtime.
	 * @param bool $plugin_owner Whether the plugin owns runtime.
	 * @return WooPaymentsRuntimeArbiter
	 */
	private function create_arbiter( bool $native_owner, bool $plugin_owner ): WooPaymentsRuntimeArbiter {
		return new class( $native_owner, $plugin_owner ) extends WooPaymentsRuntimeArbiter {
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
			public function is_builtin_owner(): bool {
				return $this->native_owner;
			}

			/**
			 * Tell whether the plugin owns runtime.
			 *
			 * @return bool
			 */
			public function is_extension_owner(): bool {
				return $this->plugin_owner;
			}
		};
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
