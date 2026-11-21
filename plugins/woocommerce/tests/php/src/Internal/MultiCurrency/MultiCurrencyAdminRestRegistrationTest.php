<?php
/**
 * Multi-Currency REST registration tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\UsageDetector;
use Automattic\WooCommerce\Internal\MultiCurrency\Shadow\MultiCurrencyShadowMode;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests that the Multi-Currency REST routes register whenever WordPress starts a REST server, in every request type of a
 * store where WooCommerce runs Multi-Currency, as client 11.3.0 registers them (`includes/multi-currency/MultiCurrency.php:350`).
 */
class MultiCurrencyAdminRestRegistrationTest extends WC_REST_Unit_Test_Case {

	private const PROVIDER_ROOT = 'ProviderRoot';

	/** The admin routes, each with a path that reaches it and the methods it answers. */
	private const ROUTES = array(
		'/wc/v3/payments/multi-currency/currencies'      => array( '/wc/v3/payments/multi-currency/currencies', array( 'GET' ) ),
		'/wc/v3/payments/multi-currency/update-enabled-currencies' => array( '/wc/v3/payments/multi-currency/update-enabled-currencies', array( 'POST' ) ),
		'/wc/v3/payments/multi-currency/currencies/(?P<currency_code>[A-Za-z]{3})' => array( '/wc/v3/payments/multi-currency/currencies/EUR', array( 'GET', 'POST' ) ),
		'/wc/v3/payments/multi-currency/get-settings'    => array( '/wc/v3/payments/multi-currency/get-settings', array( 'GET' ) ),
		'/wc/v3/payments/multi-currency/update-settings' => array( '/wc/v3/payments/multi-currency/update-settings', array( 'POST' ) ),
	);

	private const PUBLIC_CONFIG_ROUTE = '/wc/v3/payments/multi-currency/public/config';

	/** Clear the request signals a case set, and the services and replacements a real-container case left behind. */
	public function tearDown(): void {
		Constants::clear_single_constant( 'WP_CLI' );
		unset( $_REQUEST['action'] );
		wc_get_container()->reset_all_replacements();
		wc_get_container()->reset_all_resolved();
		parent::tearDown();
	}

	/**
	 * @testdox $request request, $usage store, payments owner $payments_owner: the routes register once, and only, when a REST server starts.
	 *
	 * Before the REST server starts, the request has loaded neither the REST controller nor, on a store that loads only the
	 * explicit-price filter for this request type, the provider's Multi-Currency classes. When it starts, each route gets
	 * one handler per method and is the first route its path matches, beside WooCommerce's own routes; the provider's
	 * classes register ahead of the controller where the load did not register them, and nothing registers twice, also when
	 * an Action Scheduler action runs later in the request.
	 *
	 * @dataProvider every_request_type_of_a_builtin_store
	 *
	 * @param string $request          Request type, or `async runner` for Action Scheduler's loopback runner.
	 * @param string $usage            How far the store uses Multi-Currency: configured, historical or empty.
	 * @param string $payments_owner   Payments owner: builtin or none.
	 * @param bool   $provider_at_load Whether the load registers the provider's Multi-Currency classes.
	 * @param bool   $cache_mode       Whether prices render in the cache-optimized mode.
	 */
	public function test_routes_register_once_when_a_server_starts( string $request, string $usage, string $payments_owner, bool $provider_at_load, bool $cache_mode ): void {
		$this->arrange_usage( $usage );
		$container = $this->create_container( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $payments_owner ), $cache_mode );
		$bootstrap = new MultiCurrencyBootstrap( static fn(): array => array( self::PROVIDER_ROOT ) );

		$routes = $this->as_request(
			$request,
			function () use ( $bootstrap, $container, $request, $provider_at_load ): array {
				$bootstrap->register( $container, $this->get_request_type( $request ) );
				$at_load = $container->resolved;

				$this->assertNotContains( MultiCurrencyRestController::class, $at_load, 'The load must not resolve the REST controller.' );
				$this->assertSame( $provider_at_load, in_array( self::PROVIDER_ROOT, $at_load, true ), 'The load registers the provider classes only where its classes need them.' );
				$this->assertSame( 0, has_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ) ), 'The routes must hook the first priority of the server start.' );

				$routes = $this->start_rest_server()->get_routes();

				$this->assertSame(
					array_merge( $provider_at_load ? array() : array( self::PROVIDER_ROOT ), array( MultiCurrencyRestController::class ) ),
					array_values( array_intersect( array_slice( $container->resolved, count( $at_load ) ), array( self::PROVIDER_ROOT, MultiCurrencyRestController::class ) ) ),
					'The server start must register the provider classes the load did not, then the controller.'
				);
				// An Action Scheduler action run later in the request registers the cron classes it lacks, not these again.
				do_action( 'action_scheduler_before_execute', 1, 'WP Cron' );
				$this->assertSame( 1, count( array_keys( $container->resolved, self::PROVIDER_ROOT, true ) ), 'The provider classes must register once in the request.' );
				$this->assertSame( 1, count( array_keys( $container->resolved, MultiCurrencyRestController::class, true ) ), 'The controller must register once in the request.' );

				return $routes;
			}
		);

		$this->assertArrayHasKey( '/wc/v3/orders', $routes, 'WooCommerce registered its own routes on the same server.' );
		foreach ( self::ROUTES as $route => list( $path, $methods ) ) {
			$this->assertArrayHasKey( $route, $routes, $route );
			foreach ( $methods as $method ) {
				$this->assertSame( 1, $this->count_handlers( $routes[ $route ], $method ), $method . ' ' . $route . ' must have one handler.' );
			}
			$this->assertSame( $route, $this->get_first_matching_route( $routes, $path ), $path . ' must reach its own route.' );
		}
		$this->assertSame( $cache_mode, isset( $routes[ self::PUBLIC_CONFIG_ROUTE ] ), 'The public config exists only in the cache-optimized mode.' );
	}

	/**
	 * Each request type on each usage with the built-in owning payments, the same on a configured store without a payments owner,
	 * and one cell in the cache-optimized mode.
	 *
	 * @return array<string,array{string,string,string,bool,bool}>
	 */
	public static function every_request_type_of_a_builtin_store(): array {
		// Request types that load only the explicit-price filter on a store without additional currencies.
		$explicit_only = array(
			'historical' => array( 'cli', 'ajax', 'front' ),
			'empty'      => array( 'cli', 'cron', 'async runner', 'ajax', 'front', 'rest' ),
		);
		$cases         = array();
		foreach ( array( 'configured', 'historical', 'empty' ) as $usage ) {
			foreach ( self::request_types() as $request ) {
				$cases[ $usage . ' ' . $request ] = array( $request, $usage, 'builtin', ! in_array( $request, $explicit_only[ $usage ] ?? array(), true ), false );
			}
		}
		foreach ( self::request_types() as $request ) {
			$cases[ 'configured ' . $request . ', no payments owner' ] = array( $request, 'configured', 'none', true, false );
		}
		$cases['configured front, cache-optimized mode'] = array( 'front', 'configured', 'builtin', true, true );

		return $cases;
	}

	/**
	 * @testdox $request request, Multi-Currency owner $owner (shadow mode $shadow): nothing hooks the REST server and no route registers.
	 * @dataProvider every_request_type_without_core_ownership
	 *
	 * @param string $request Request type.
	 * @param string $owner   Multi-Currency runtime owner.
	 * @param bool   $shadow  Whether the read-only shadow mode runs.
	 */
	public function test_no_route_registers_without_core_ownership( string $request, string $owner, bool $shadow ): void {
		$this->arrange_usage( 'configured' );
		$container = $this->create_container( $this->create_arbiter( $owner, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION === $owner ? 'extension' : 'none' ), true );
		$bootstrap = new MultiCurrencyBootstrap( static fn(): array => array( self::PROVIDER_ROOT ) );
		add_filter( MultiCurrencyShadowMode::FILTER_SHADOW_ENABLED, $shadow ? '__return_true' : '__return_false' );

		$routes = $this->as_request(
			$request,
			function () use ( $bootstrap, $container, $request ): array {
				$bootstrap->register( $container, $this->get_request_type( $request ) );
				$this->assertFalse( has_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ) ) );

				return $this->start_rest_server()->get_routes();
			}
		);

		$this->assertNotContains( MultiCurrencyRestController::class, $container->resolved );
		$this->assertSame( array(), preg_grep( '#^/wc/v3/payments/multi-currency#', array_keys( $routes ) ) );
		$this->assertArrayHasKey( '/wc/v3/orders', $routes, 'WooCommerce registered its own routes on the same server.' );
	}

	/** @return array<string,array{string,string,bool}> */
	public static function every_request_type_without_core_ownership(): array {
		$cases = array();
		foreach ( self::request_types() as $request ) {
			$cases[ $request . ', no owner' ]                   = array( $request, MultiCurrencyRuntimeArbiter::OWNER_NONE, false );
			$cases[ $request . ', extension' ]                  = array( $request, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, false );
			$cases[ $request . ', extension with shadow mode' ] = array( $request, MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true );
		}

		return $cases;
	}

	/**
	 * @testdox A $request request on a store without additional currencies answers the currencies route from the provider's rates, and refuses visitors and customers.
	 *
	 * The available list is the provider's supported currencies and the store currency, as client 11.3.0's routes answer from
	 * the account's currencies in every context. Without the provider's classes it would list every WooCommerce currency.
	 *
	 * @testWith ["cli"]
	 *           ["rest"]
	 *
	 * @param string $request Request type.
	 */
	public function test_currencies_route_answers_from_the_provider_in_the_request( string $request ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array() );
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, '0', HOUR_IN_SECONDS );
		wc_get_container()->reset_all_resolved();
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 'builtin' ) );
		wc_get_container()->replace( self::PROVIDER_ROOT, $this->create_provider_root( array( 'EUR', 'GBP' ) ) );
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$customer      = self::factory()->user->create( array( 'role' => 'customer' ) );
		$responses     = array();

		$this->as_request(
			$request,
			function () use ( $request, $administrator, $customer, &$responses ): void {
				( new MultiCurrencyBootstrap( static fn(): array => array( self::PROVIDER_ROOT ) ) )->register( wc_get_container(), $this->get_request_type( $request ) );
				$this->start_rest_server();
				foreach ( array(
					'visitor'       => 0,
					'customer'      => $customer,
					'administrator' => $administrator,
				) as $who => $user_id ) {
					wp_set_current_user( $user_id );
					$responses[ $who ] = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/payments/multi-currency/currencies' ) );
				}
			}
		);

		$this->assertSame( array( 401, 'rest_forbidden' ), array( $responses['visitor']->get_status(), $responses['visitor']->as_error()->get_error_code() ) );
		$this->assertSame( array( 403, 'rest_forbidden' ), array( $responses['customer']->get_status(), $responses['customer']->as_error()->get_error_code() ) );
		$this->assertSame( 200, $responses['administrator']->get_status() );
		$data = $responses['administrator']->get_data();
		$this->assertSame( array( 'USD', 'EUR', 'GBP' ), array_keys( $data['available'] ) );
		$this->assertSame(
			array(
				'available' => true,
				'source'    => 'test-provider',
			),
			$data['automatic_rates']
		);
	}

	/**
	 * The request types the cases run as.
	 *
	 * @return array<int,string>
	 */
	private static function request_types(): array {
		return array( 'cli', 'cron', 'async runner', 'ajax', 'front', 'admin', 'rest' );
	}

	/**
	 * Get the request type WooCommerce classifies a request as.
	 *
	 * @param string $request Request type, or `async runner`, a cron request over admin-ajax.
	 * @return string
	 */
	private function get_request_type( string $request ): string {
		return 'async runner' === $request ? 'cron' : $request;
	}

	/**
	 * Run work with the WordPress signals of a request type set, as WooCommerce reads them to classify it.
	 *
	 * @param string   $request Request type: cli, cron, `async runner` (Action Scheduler's loopback runner), ajax (a
	 *                          visitor's admin-ajax request), front, admin or rest.
	 * @param callable $work    Work to run.
	 * @return mixed The work's result.
	 */
	private function as_request( string $request, callable $work ) {
		if ( 'cli' === $request ) {
			Constants::set_constant( 'WP_CLI', true );
		}
		if ( 'async runner' === $request ) {
			$_REQUEST['action'] = 'as_async_request_queue_runner';
		}
		$signals = array(
			'cron'         => array( 'cron' => true ),
			'async runner' => array(
				'ajax'  => true,
				'admin' => true,
			),
			'ajax'         => array( 'ajax' => true ),
			'admin'        => array( 'admin' => true ),
		)[ $request ] ?? array();

		return RequestSignals::run( $signals, $work );
	}

	/**
	 * Start a REST server as WordPress does for an internal dispatch.
	 *
	 * @return WP_REST_Server
	 */
	private function start_rest_server(): WP_REST_Server {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh server for this request; the base clears it after the case.

		return rest_get_server();
	}

	/**
	 * Store the options that decide how far the store uses Multi-Currency.
	 *
	 * @param string $usage Configured, historical or empty.
	 */
	private function arrange_usage( string $usage ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', 'configured' === $usage ? array( 'EUR' ) : array() );
		set_transient( UsageDetector::HAS_MC_ORDERS_TRANSIENT, 'historical' === $usage ? '1' : '0', HOUR_IN_SECONDS );
	}

	/**
	 * Count a route's handlers that answer a method.
	 *
	 * @param array<int,array<string,mixed>> $handlers Route handlers.
	 * @param string                         $method   HTTP method.
	 * @return int
	 */
	private function count_handlers( array $handlers, string $method ): int {
		return count( array_filter( $handlers, static fn( array $handler ): bool => ! empty( $handler['methods'][ $method ] ) ) );
	}

	/**
	 * Get the first registered route whose pattern matches a path, as the server tries them.
	 *
	 * @param array<string,mixed> $routes Registered routes, in registration order.
	 * @param string              $path   Request path.
	 * @return string|null
	 */
	private function get_first_matching_route( array $routes, string $path ): ?string {
		foreach ( array_keys( $routes ) as $route ) {
			if ( preg_match( '@^' . $route . '$@i', $path ) ) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Create a runtime arbiter that answers with the given owners.
	 *
	 * @param string $owner          Multi-Currency runtime owner.
	 * @param string $payments_owner Payments owner: builtin, extension or none.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner, string $payments_owner ): MultiCurrencyRuntimeArbiter {
		$arbiter = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_runtime_owner', 'is_payments_extension_owner', 'is_payments_builtin_owner', 'is_builtin_owner', 'feature_is_enabled' ) )
			->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$arbiter->method( 'is_payments_extension_owner' )->willReturn( 'extension' === $payments_owner );
		$arbiter->method( 'is_payments_builtin_owner' )->willReturn( 'builtin' === $payments_owner );
		$arbiter->method( 'is_builtin_owner' )->willReturn( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $owner );
		$arbiter->method( 'feature_is_enabled' )->willReturn( MultiCurrencyRuntimeArbiter::OWNER_NONE !== $owner );

		return $arbiter;
	}

	/**
	 * Create a provider root whose registration sets an available rate provider, as the provider's Multi-Currency classes do.
	 *
	 * @param array<int,string> $supported Currencies the provider supports.
	 * @return object
	 */
	private function create_provider_root( array $supported ): object {
		$provider = new class( $supported ) implements CurrencyRateProviderInterface {
			/** @var array<int,string> */
			private array $supported;

			/**
			 * Constructor.
			 *
			 * @param array<int,string> $supported Supported currencies.
			 */
			public function __construct( array $supported ) {
				$this->supported = $supported;
			}

			/** @return string */
			public function get_id(): string {
				return 'test-provider';
			}

			/** @return bool */
			public function is_available(): bool {
				return true;
			}

			/** @return bool */
			public function is_connected(): bool {
				return true;
			}

			/** @return string[] */
			public function get_supported_currencies(): array {
				return $this->supported;
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

		return new class( $provider ) {
			/** @var CurrencyRateProviderInterface */
			private CurrencyRateProviderInterface $provider;

			/**
			 * Constructor.
			 *
			 * @param CurrencyRateProviderInterface $provider Rate provider.
			 */
			public function __construct( CurrencyRateProviderInterface $provider ) {
				$this->provider = $provider;
			}

			/** Set the rate provider. */
			public function register(): void {
				wc_get_container()->get( RateService::class )->set_provider( $this->provider );
			}
		};
	}

	/**
	 * Create a real route controller with a fixed rendering mode.
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param bool                        $cache_mode Whether prices render in the cache-optimized mode.
	 * @return MultiCurrencyRestController
	 */
	private function create_rest_controller( MultiCurrencyRuntimeArbiter $arbiter, bool $cache_mode ): MultiCurrencyRestController {
		$display_service = $this->getMockBuilder( DisplayService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_cache_optimized_mode' ) )
			->getMock();
		$display_service->method( 'is_cache_optimized_mode' )->willReturn( $cache_mode );

		$controller = new MultiCurrencyRestController();
		$controller->init(
			$arbiter,
			wc_get_container()->get( StateBuilderFactory::class ),
			ServiceFactories::with( $this, array( 'create_display_service' => $display_service ) )
		);

		return $controller;
	}

	/**
	 * Create a container that serves the arbiter, the usage detector and a real route controller, records every class it
	 * resolves, and serves every other class as a registrar that does nothing.
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param bool                        $cache_mode Whether prices render in the cache-optimized mode.
	 * @return RuntimeContainer&object{resolved:array<int,string>}
	 */
	private function create_container( MultiCurrencyRuntimeArbiter $arbiter, bool $cache_mode ): RuntimeContainer {
		return new class( $arbiter, $this->create_rest_controller( $arbiter, $cache_mode ) ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var MultiCurrencyRuntimeArbiter */
			private $arbiter;

			/** @var MultiCurrencyRestController */
			private $controller;

			/**
			 * Initialize the controlled container.
			 *
			 * @param MultiCurrencyRuntimeArbiter $arbiter    Runtime owner arbiter.
			 * @param MultiCurrencyRestController $controller Real route controller.
			 */
			public function __construct( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRestController $controller ) {
				parent::__construct( array() );
				$this->arbiter    = $arbiter;
				$this->controller = $controller;
			}

			/**
			 * Resolve a controlled service.
			 *
			 * @param string $class_name Requested class.
			 * @return object Controlled service.
			 */
			public function get( string $class_name ) {
				$this->resolved[] = $class_name;
				if ( MultiCurrencyRuntimeArbiter::class === $class_name ) {
					return $this->arbiter;
				}

				if ( UsageDetector::class === $class_name ) {
					return new UsageDetector();
				}

				if ( MultiCurrencyRestController::class === $class_name ) {
					return $this->controller;
				}

				return new class() {
					/** Register a class whose hooks the case does not need. */
					public function register(): void {
					}
				};
			}
		};
	}
}
