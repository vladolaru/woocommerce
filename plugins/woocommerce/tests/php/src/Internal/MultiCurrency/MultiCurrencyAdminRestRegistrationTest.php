<?php
/**
 * Multi-Currency admin REST registration tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRestController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyUsageDetector;
use WC_REST_Unit_Test_Case;

/**
 * Tests deferred Multi-Currency REST registration for admin-originated requests.
 */
class MultiCurrencyAdminRestRegistrationTest extends WC_REST_Unit_Test_Case {

	/**
	 * @testdox Should defer the currencies route until internal REST initialization for every Core-owned data tier.
	 * @dataProvider core_owned_data_tier_provider
	 *
	 * @param bool   $configured Whether additional currencies are configured.
	 * @param string $historical Cached historical-order signal.
	 */
	public function test_registers_currencies_route_for_internal_admin_request( bool $configured, string $historical ): void {
		global $current_screen;

		$previous_screen         = $current_screen;
		$previous_store_currency = get_option( 'woocommerce_currency', 'USD' );
		$arbiter                 = $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_CORE );
		$controller              = $this->create_rest_controller( $arbiter );
		$container               = $this->create_container( $arbiter, $controller );
		$bootstrap               = new MultiCurrencyBootstrap( static fn(): array => array() );
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', $configured ? array( 'EUR' ) : array() );
		set_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT, $historical, HOUR_IN_SECONDS );
		set_current_screen( 'edit-page' );

		try {
			$bootstrap->register( $container, '__return_false' );

			$this->assertNotContains( MultiCurrencyRestController::class, $container->resolved, 'Ordinary admin bootstrap must not construct the REST controller.' );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising WordPress's internal REST initialization boundary.
			do_action( 'rest_api_init', $this->server );

			$this->assertSame( 'edit-page', get_current_screen()->id );
			$this->assertArrayHasKey( '/wc/v3/payments/multi-currency/currencies', $this->server->get_routes() );
			$this->assertSame( 0, has_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ) ) );
			$this->assertSame( 1, count( array_keys( $container->resolved, MultiCurrencyRestController::class, true ) ) );
		} finally {
			remove_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ), 0 );
			remove_action( 'rest_api_init', array( $controller, 'handle_rest_api_init' ) );
			update_option( 'woocommerce_currency', $previous_store_currency );
			delete_option( 'wcpay_multi_currency_enabled_currencies' );
			delete_transient( MultiCurrencyUsageDetector::HAS_MC_ORDERS_TRANSIENT );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen snapshot taken above.
			$current_screen = $previous_screen;
		}
	}

	/**
	 * @testdox Should not defer native Multi-Currency routes without Core ownership.
	 * @dataProvider non_core_owner_provider
	 *
	 * @param string $owner Runtime owner.
	 */
	public function test_does_not_defer_routes_without_core_ownership( string $owner ): void {
		global $current_screen;

		$previous_screen = $current_screen;
		$arbiter         = $this->create_arbiter( $owner );
		$controller      = $this->create_rest_controller( $arbiter );
		$container       = $this->create_container( $arbiter, $controller );
		$bootstrap       = new MultiCurrencyBootstrap( static fn(): array => array() );
		set_current_screen( 'edit-page' );

		try {
			$bootstrap->register( $container, '__return_false' );

			$this->assertFalse( has_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ) ) );
			$this->assertNotContains( MultiCurrencyRestController::class, $container->resolved );
		} finally {
			remove_action( 'rest_api_init', array( $bootstrap, 'register_admin_rest_controller' ), 0 );
			remove_action( 'rest_api_init', array( $controller, 'handle_rest_api_init' ) );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen snapshot taken above.
			$current_screen = $previous_screen;
		}
	}

	/** @return array<string,array{bool,string}> */
	public static function core_owned_data_tier_provider(): array {
		return array(
			'configured' => array( true, '0' ),
			'historical' => array( false, '1' ),
			'empty'      => array( false, '0' ),
		);
	}

	/** @return array<string,array{string}> */
	public static function non_core_owner_provider(): array {
		return array(
			'plugin' => array( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN ),
			'none'   => array( MultiCurrencyRuntimeArbiter::OWNER_NONE ),
		);
	}

	/**
	 * Create a deterministic runtime arbiter.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		$arbiter = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_runtime_owner', 'should_core_register' ) )
			->getMock();
		$arbiter->method( 'get_runtime_owner' )->willReturn( $owner );
		$arbiter->method( 'should_core_register' )->willReturn( MultiCurrencyRuntimeArbiter::OWNER_CORE === $owner );

		return $arbiter;
	}

	/**
	 * Create a real route controller with deterministic route-manifest dependencies.
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter Runtime owner arbiter.
	 * @return MultiCurrencyRestController
	 */
	private function create_rest_controller( MultiCurrencyRuntimeArbiter $arbiter ): MultiCurrencyRestController {
		$frontend_projection = $this->getMockBuilder( MultiCurrencyFrontendProjectionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_cache_optimized_mode' ) )
			->getMock();
		$frontend_projection->method( 'is_cache_optimized_mode' )->willReturn( false );

		$controller = new MultiCurrencyRestController();
		$controller->init(
			$arbiter,
			wc_get_container()->get( MultiCurrencyStateBuilderFactory::class ),
			wc_get_container()->get( MultiCurrencyProjectionServiceFactory::class )
		);
		$controller->set_frontend_projection_service( $frontend_projection );

		return $controller;
	}

	/**
	 * Create a controlled container that records deferred controller resolution.
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param MultiCurrencyRestController $controller Real route controller.
	 * @return RuntimeContainer&object{resolved:array<int,string>}
	 */
	private function create_container( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyRestController $controller ): RuntimeContainer {
		return new class( $arbiter, $controller ) extends RuntimeContainer {
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

				if ( MultiCurrencyUsageDetector::class === $class_name ) {
					return new MultiCurrencyUsageDetector();
				}

				if ( MultiCurrencyRestController::class === $class_name ) {
					return $this->controller;
				}

				return new class() {
					/** Register a non-target root without side effects. */
					public function register(): void {
					}
				};
			}
		};
	}
}
