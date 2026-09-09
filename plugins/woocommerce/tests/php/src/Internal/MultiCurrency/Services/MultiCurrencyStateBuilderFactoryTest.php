<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyCacheInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySelectedCurrencyController;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsMultiCurrencyProviderBootstrap;
use WC_Session;
use WC_Unit_Test_Case;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ClassFileName.NoMatch,SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

/**
 * In-memory GBP session handler for state-builder readiness tests.
 */
class MultiCurrencyStateBuilderFactorySessionTestDouble extends WC_Session {

	/**
	 * Session initialization count.
	 *
	 * @var int
	 */
	public int $init_calls = 0;

	/**
	 * Persistent session-cookie request arguments.
	 *
	 * @var bool[]
	 */
	public array $cookie_write_arguments = array();

	/**
	 * Initialize the in-memory selected currency.
	 *
	 * @internal
	 */
	final public function init(): void {
		++$this->init_calls;
		$this->set( 'wcpay_currency', 'GBP' );
	}

	/**
	 * Record persistent session-cookie requests without writing a cookie.
	 *
	 * @param bool $set Whether the session cookie should be set.
	 */
	public function set_customer_session_cookie( bool $set ): void {
		$this->cookie_write_arguments[] = $set;
	}
}

/**
 * Tests for the MultiCurrencyStateBuilderFactory class.
 */
class MultiCurrencyStateBuilderFactoryTest extends WC_Unit_Test_Case {

	/**
	 * Original store currency.
	 *
	 * @var string
	 */
	private string $original_currency;

	/**
	 * Original WooCommerce session.
	 *
	 * @var mixed
	 */
	private $original_session;

	/**
	 * Original WooCommerce cart.
	 *
	 * @var mixed
	 */
	private $original_cart;

	/**
	 * Original current user ID.
	 *
	 * @var int
	 */
	private int $original_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_currency = (string) get_option( 'woocommerce_currency', 'USD' );
		$this->original_session  = WC()->session;
		$this->original_cart     = WC()->cart;
		$this->original_user_id  = get_current_user_id();
		update_option( 'woocommerce_currency', 'USD' );
		$this->delete_options();
		$this->reset_rate_provider_registry_factory();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->delete_options();
		$this->reset_rate_provider_registry_factory();
		$this->reset_legacy_proxy_mocks();
		update_option( 'woocommerce_currency', $this->original_currency );

		parent::tearDown();

		WC()->session = $this->original_session;
		WC()->cart    = $this->original_cart;
		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * @testdox Should build automatic currencies from the WooPayments provider registry.
	 */
	public function test_builds_automatic_currencies_from_woopayments_provider_registry(): void {
		$this->mock_woopayments_runtime(
			$this->create_recording_account(),
			$this->create_recording_api_client()
		);
		$this->register_woopayments_provider_boundaries();
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'automatic' );

		$state = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )->create()->build();

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertArrayNotHasKey( 'EUR', $state->get_enabled_currencies() );
	}

	/**
	 * @testdox Should create state builders with supplied localization and cache boundaries.
	 */
	public function test_create_uses_supplied_localization_and_cache_boundaries(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'automatic' );

		$state = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class )
			->create(
				$this->create_custom_localization(),
				$this->create_cached_rates_cache()
			)
			->build();

		$this->assertSame( array( 'USD', 'GBP' ), array_keys( $state->get_enabled_currencies() ) );
		$this->assertSame( 0.82, $state->get_enabled_currencies()['GBP']->get_rate() );
		$this->assertSame( 'right', $state->get_enabled_currencies()['GBP']->get_symbol_position() );
	}

	/**
	 * @testdox Resetting one factory-built state invalidates every request-local projection snapshot.
	 */
	public function test_factory_built_states_share_invalidation(): void {
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.80' );

		$user_id = $this->factory()->user->create();
		wp_set_current_user( $user_id );

		$factory             = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class );
		$frontend_builder    = $factory->create();
		$persistence_builder = $factory->create();
		$persistence         = new MultiCurrencySelectedCurrencyPersistenceService( $persistence_builder );

		$this->assertSame( 'USD', $frontend_builder->build()->get_selected_currency()->get_code() );
		$this->assertTrue( $persistence->update_selected_currency( 'GBP' ) );
		$this->assertSame( 'GBP', $frontend_builder->build()->get_selected_currency()->get_code() );
	}

	/**
	 * @testdox Resetting selected-currency state after classic session readiness rebuilds the shared frontend projection.
	 */
	public function test_classic_session_readiness_rebuilds_cached_frontend_state(): void {
		list( $frontend_builder, $persistence ) = $this->create_shared_projection_graph();

		$this->assertSame( 'USD', $frontend_builder->build()->get_selected_currency()->get_code(), 'The first frontend projection should use store currency before session readiness.' );

		$session = new MultiCurrencyStateBuilderFactorySessionTestDouble();
		$session->init();
		WC()->session = $session;

		$this->assertSame( 'USD', $frontend_builder->build()->get_selected_currency()->get_code(), 'The same frontend builder should retain its cached projection until readiness invalidates it.' );
		$this->assertTrue( method_exists( $persistence, 'reset_selected_currency_state' ), 'The request-local selected-currency reset method should exist.' );
		$persistence->reset_selected_currency_state();

		$this->assertSame( 'GBP', $frontend_builder->build()->get_selected_currency()->get_code(), 'The same frontend builder should rebuild from the ready GBP session.' );
		$this->assertSame( array(), $session->cookie_write_arguments, 'Request-local invalidation should not request any session-cookie mutation.' );
	}

	/**
	 * @testdox Store API checkout pre-dispatch invalidates a cached pre-session projection once before cart and order work.
	 */
	public function test_store_api_checkout_pre_dispatch_rebuilds_cached_frontend_state_once(): void {
		list( $frontend_builder, $persistence ) = $this->create_shared_projection_graph();
		$controller                             = $this->create_selected_currency_controller( $persistence );
		$initial_state                          = $frontend_builder->build();
		$post_woocommerce_init_state            = $frontend_builder->build();
		$result                                 = null;
		$request                                = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$duplicate_request                      = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$cart                                   = WC()->cart;

		$this->assertSame( 'USD', $initial_state->get_selected_currency()->get_code(), 'The initial checkout projection should use store currency before session readiness.' );
		$this->assertSame( $initial_state, $post_woocommerce_init_state, 'A second pre-session read should remain the same cached USD projection.' );

		$session_handler_filter = static function ( $current_session_handler ) {
			unset( $current_session_handler );

			return MultiCurrencyStateBuilderFactorySessionTestDouble::class;
		};

		add_filter( 'woocommerce_session_handler', $session_handler_filter, 10, 1 );

		try {
			$controller->register();
			$this->assertSame( 10, has_filter( 'rest_pre_dispatch', array( $controller, 'handle_store_api_rest_pre_dispatch' ) ), 'The Store API session-readiness filter should register at priority 10.' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Triggering an existing WordPress filter, not defining one.
			$first_result        = apply_filters( 'rest_pre_dispatch', $result, rest_get_server(), $request );
			$initialized_session = WC()->session;
			$gbp_state           = $frontend_builder->build();
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Triggering an existing WordPress filter, not defining one.
			$second_result = apply_filters( 'rest_pre_dispatch', $result, rest_get_server(), $duplicate_request );

			$this->assertNull( $first_result, 'Store API readiness should preserve the normal null pre-dispatch result.' );
			$this->assertNull( $second_result, 'Duplicate Store API dispatch should preserve the normal null pre-dispatch result.' );
			$this->assertInstanceOf( MultiCurrencyStateBuilderFactorySessionTestDouble::class, $initialized_session, 'WooCommerce should initialize the exact in-memory session handler.' );
			$this->assertSame( $initialized_session, WC()->session, 'Duplicate Store API dispatch should preserve the initialized session identity.' );
			$this->assertSame( 1, $initialized_session->init_calls, 'Duplicate Store API dispatch should not initialize the session twice.' );
			$this->assertSame( array(), $initialized_session->cookie_write_arguments, 'Store API readiness should not request any session-cookie mutation.' );
			$this->assertSame( 'GBP', $gbp_state->get_selected_currency()->get_code(), 'The same frontend builder should expose GBP before cart or order work.' );
			$this->assertSame( $gbp_state, $frontend_builder->build(), 'Duplicate Store API dispatch should not invalidate the rebuilt projection again.' );
			$this->assertSame( $cart, WC()->cart, 'Store API readiness should not initialize or replace the cart.' );
		} finally {
			remove_filter( 'woocommerce_session_handler', $session_handler_filter, 10 );
			$this->remove_selected_currency_controller_hooks( $controller );
			$this->assertFalse( has_filter( 'woocommerce_session_handler', $session_handler_filter ), 'The exact checkout session-handler filter should not leak.' );
			WC()->session = $this->original_session;
			WC()->cart    = $this->original_cart;
		}
	}

	/**
	 * @testdox Store API products pre-dispatch rebuilds selected currency without constructing a cart.
	 */
	public function test_store_api_products_pre_dispatch_rebuilds_cached_frontend_state_without_cart(): void {
		list( $frontend_builder, $persistence ) = $this->create_shared_projection_graph();
		$controller                             = $this->create_selected_currency_controller( $persistence );
		$result                                 = null;
		$request                                = new \WP_REST_Request( 'GET', '/wc/store/v1/products' );
		$cart                                   = null;
		$cart_load_count                        = did_action( 'woocommerce_load_cart_from_session' );

		WC()->cart = $cart;

		$this->assertSame( 'USD', $frontend_builder->build()->get_selected_currency()->get_code(), 'The products projection should use store currency before session readiness.' );

		$session_handler_filter = static function ( $current_session_handler ) {
			unset( $current_session_handler );

			return MultiCurrencyStateBuilderFactorySessionTestDouble::class;
		};

		add_filter( 'woocommerce_session_handler', $session_handler_filter, 10, 1 );

		try {
			$controller->register();
			$this->assertSame( 10, has_filter( 'rest_pre_dispatch', array( $controller, 'handle_store_api_rest_pre_dispatch' ) ), 'The Store API session-readiness filter should register at priority 10.' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Triggering an existing WordPress filter, not defining one.
			$actual_result = apply_filters( 'rest_pre_dispatch', $result, rest_get_server(), $request );

			$this->assertNull( $actual_result, 'Store API readiness should preserve the normal null pre-dispatch result.' );
			$this->assertInstanceOf( MultiCurrencyStateBuilderFactorySessionTestDouble::class, WC()->session, 'WooCommerce should initialize the exact in-memory session handler.' );
			$this->assertSame( array(), WC()->session->cookie_write_arguments, 'Cartless Store API readiness should not request any session-cookie mutation.' );
			$this->assertSame( 'GBP', $frontend_builder->build()->get_selected_currency()->get_code(), 'The same cartless products projection should rebuild from the ready GBP session.' );
			$this->assertSame( $cart, WC()->cart, 'Cartless Store API readiness should preserve exact cart identity.' );
			$this->assertSame( $cart_load_count, did_action( 'woocommerce_load_cart_from_session' ), 'Cartless Store API readiness should not load a cart from session.' );
		} finally {
			remove_filter( 'woocommerce_session_handler', $session_handler_filter, 10 );
			$this->remove_selected_currency_controller_hooks( $controller );
			$this->assertFalse( has_filter( 'woocommerce_session_handler', $session_handler_filter ), 'The exact products session-handler filter should not leak.' );
			WC()->session = $this->original_session;
			WC()->cart    = $this->original_cart;
		}
	}

	/**
	 * Create frontend and persistence projections sharing the real request invalidator.
	 *
	 * @return array{MultiCurrencyStateBuilder,MultiCurrencySelectedCurrencyPersistenceService}
	 */
	private function create_shared_projection_graph(): array {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.80' );
		wp_set_current_user( 0 );
		WC()->session = null;

		$factory             = wc_get_container()->get( MultiCurrencyStateBuilderFactory::class );
		$frontend_builder    = $factory->create();
		$persistence_builder = $factory->create();

		return array(
			$frontend_builder,
			new MultiCurrencySelectedCurrencyPersistenceService( $persistence_builder ),
		);
	}

	/**
	 * Create a Core-owned controller with the real runtime factory and persistence graph.
	 *
	 * @param MultiCurrencySelectedCurrencyPersistenceService $persistence Persistence service.
	 * @return MultiCurrencySelectedCurrencyController
	 */
	private function create_selected_currency_controller( MultiCurrencySelectedCurrencyPersistenceService $persistence ): MultiCurrencySelectedCurrencyController {
		$controller = new MultiCurrencySelectedCurrencyController();
		$controller->init(
			$this->create_core_owning_arbiter(),
			wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )
		);
		$controller->set_persistence_service( $persistence );
		$controller->set_request_context( $this->create_store_api_request_context() );

		return $controller;
	}

	/**
	 * Create a Store API request context that registers selected-currency entry hooks.
	 *
	 * @return MultiCurrencyRequestContext
	 */
	private function create_store_api_request_context(): MultiCurrencyRequestContext {
		return new class() extends MultiCurrencyRequestContext {
			/**
			 * Tell whether selected-currency entry hooks should register.
			 *
			 * @return bool
			 */
			public function should_register_selected_currency_entry_hooks(): bool {
				return true;
			}

			/**
			 * Tell whether the current request uses the Store API.
			 *
			 * @return bool
			 */
			public function is_store_api_request(): bool {
				return true;
			}
		};
	}

	/**
	 * Remove and verify every hook registered by one selected-currency controller.
	 *
	 * @param MultiCurrencySelectedCurrencyController $controller Selected-currency controller.
	 */
	private function remove_selected_currency_controller_hooks( MultiCurrencySelectedCurrencyController $controller ): void {
		remove_filter( 'rest_pre_dispatch', array( $controller, 'handle_store_api_rest_pre_dispatch' ), 10 );
		remove_action( 'init', array( $controller, 'handle_init' ), 11 );
		remove_action( 'init', array( $controller, 'handle_geolocation_init' ), 12 );
		remove_action( 'woocommerce_created_customer', array( $controller, 'handle_woocommerce_created_customer' ), 10 );
		remove_action( 'woocommerce_edit_account_form', array( $controller, 'handle_woocommerce_edit_account_form' ), 10 );
		remove_action( 'woocommerce_save_account_details', array( $controller, 'handle_woocommerce_save_account_details' ), 10 );

		$this->assertFalse( has_filter( 'rest_pre_dispatch', array( $controller, 'handle_store_api_rest_pre_dispatch' ) ), 'The controller Store API readiness filter should not leak.' );
		$this->assertFalse( has_action( 'init', array( $controller, 'handle_init' ) ), 'The controller URL action should not leak.' );
		$this->assertFalse( has_action( 'init', array( $controller, 'handle_geolocation_init' ) ), 'The controller geolocation action should not leak.' );
		$this->assertFalse( has_action( 'woocommerce_created_customer', array( $controller, 'handle_woocommerce_created_customer' ) ), 'The controller customer-creation action should not leak.' );
		$this->assertFalse( has_action( 'woocommerce_edit_account_form', array( $controller, 'handle_woocommerce_edit_account_form' ) ), 'The controller account-form action should not leak.' );
		$this->assertFalse( has_action( 'woocommerce_save_account_details', array( $controller, 'handle_woocommerce_save_account_details' ) ), 'The controller account-save action should not leak.' );
	}

	/**
	 * Create a Core-owning runtime arbiter.
	 *
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_core_owning_arbiter(): MultiCurrencyRuntimeArbiter {
		return new class() extends MultiCurrencyRuntimeArbiter {
			/**
			 * Get the active multi-currency runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return MultiCurrencyRuntimeArbiter::OWNER_CORE;
			}

			/**
			 * Tell whether Core may register multi-currency hooks.
			 *
			 * @return bool
			 */
			public function should_core_register(): bool {
				return true;
			}
		};
	}

	/**
	 * Delete options touched by these tests.
	 */
	private function delete_options(): void {
		foreach (
			array(
				'wcpay_multi_currency_enabled_currencies',
				'wcpay_multi_currency_exchange_rate_gbp',
				'wcpay_multi_currency_exchange_rate_eur',
				'wcpay_multi_currency_manual_rate_gbp',
				MultiCurrencyCacheInterface::CURRENCIES_KEY,
			) as $option_key
		) {
			delete_option( $option_key );
		}
	}

	/**
	 * Register WooPayments provider boundaries for tests that exercise provider-backed rates.
	 */
	private function register_woopayments_provider_boundaries(): void {
		wc_get_container()->get( WooPaymentsMultiCurrencyProviderBootstrap::class )->register();
	}

	/**
	 * Reset the shared rate-provider registry factory.
	 */
	private function reset_rate_provider_registry_factory(): void {
		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars( array() );
	}

	/**
	 * Mock WooPayments account and API-client access.
	 *
	 * @param object $account    Account service.
	 * @param object $api_client API client.
	 */
	private function mock_woopayments_runtime( object $account, object $api_client ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => function ( $class_name, $autoload = true ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return true;
					}
					return class_exists( $class_name, $autoload );
				},
				'get_option'   => static function ( $name, $default_value = false ) {
					if ( 'active_plugins' === $name ) {
						return array( NativePaymentsRuntimeArbiter::PLUGIN_FILE );
					}

					return get_option( $name, $default_value );
				},
			)
		);

		$this->register_legacy_proxy_static_mocks(
			array(
				'WC_Payments' => array(
					'get_account_service'     => static fn() => $account,
					'get_payments_api_client' => static fn() => $api_client,
				),
			)
		);
	}

	/**
	 * Create a recording legacy account test double.
	 *
	 * @return object
	 */
	private function create_recording_account(): object {
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

			/**
			 * Get cached account data.
			 *
			 * @param bool $force_refresh Whether to refresh.
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				unset( $force_refresh );

				return array( 'customer_currencies' => array( 'supported' => array( 'GBP' ) ) );
			}

			/**
			 * Get account-supported customer currencies.
			 *
			 * @return string[]
			 */
			public function get_account_customer_supported_currencies(): array {
				return array( 'GBP' );
			}
		};
	}

	/**
	 * Create a recording legacy API client test double.
	 *
	 * @return object
	 */
	private function create_recording_api_client(): object {
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

				return array(
					'gbp' => 0.82,
					'eur' => 0.91,
				);
			}
		};
	}

	/**
	 * Create a custom localization test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_custom_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

				return array(
					'currency_pos' => 'right',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get locale data for a country.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}

	/**
	 * Create a cached-rate cache test double.
	 *
	 * @return MultiCurrencyCacheInterface
	 */
	private function create_cached_rates_cache(): MultiCurrencyCacheInterface {
		return new class() implements MultiCurrencyCacheInterface {
			/**
			 * Get a value from cache.
			 *
			 * @param string $key   Cache key.
			 * @param bool   $force Whether to return cached data.
			 * @return mixed
			 */
			public function get( string $key, bool $force = false ) {
				unset( $force );

				if ( MultiCurrencyCacheInterface::CURRENCIES_KEY !== $key ) {
					return null;
				}

				return array(
					'currencies' => array(
						'gbp' => 0.82,
					),
					'updated'    => 123,
				);
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
				unset( $generator, $validate_data, $force_refresh, $refreshed );

				return $this->get( $key );
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
}
