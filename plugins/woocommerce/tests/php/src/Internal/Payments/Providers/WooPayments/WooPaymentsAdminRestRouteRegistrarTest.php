<?php
/**
 * WooPaymentsAdminRestRouteRegistrar tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionController;
use ReflectionMethod;
use WC_REST_Unit_Test_Case;
use WP_REST_Server;

/**
 * Tests for the WooPayments REST route registration in every request type.
 */
class WooPaymentsAdminRestRouteRegistrarTest extends WC_REST_Unit_Test_Case {

	/**
	 * @testdox Should register routes when an admin page dispatches an internal REST request.
	 */
	public function test_registers_routes_for_internal_requests_with_admin_screen(): void {
		global $current_screen;

		$previous_screen  = $current_screen;
		$controller       = $this->create_payment_details_controller();
		$fixture          = $this->create_container( WooPaymentsSetupTier::CONNECTED, $controller );
		$container        = $fixture['container'];
		$runtime          = $fixture['runtime'];
		$bootstrap        = new PaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		);
		$register_classes = new ReflectionMethod( PaymentsBootstrap::class, 'register_classes' );
		$register_classes->setAccessible( true );
		set_current_screen( 'edit-page' );

		try {
			$classes = WooPaymentsProvider::get_classes_by_setup_tier()[ WooPaymentsSetupTier::CONNECTED ]['admin'];
			$register_classes->invoke( $bootstrap, $container, $classes );
			// WooCommerce's REST server registers the routes of the instance its container holds.
			wc_get_container()->replace( WooPaymentsPaymentDetailsRestController::class, $controller );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising WordPress's internal REST initialization boundary.
			do_action( 'rest_api_init', $this->server );

			$this->assertSame( 'edit-page', get_current_screen()->id );
			$this->assertArrayHasKey( '/wc/v3/payments/charges/(?P<charge_id>\w+)', $this->server->get_routes() );
		} finally {
			if ( $runtime->registrar ) {
				remove_action( 'rest_api_init', array( $runtime->registrar, 'register_rest_controllers' ), 0 );
			}
			remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $controller, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
			wc_get_container()->reset_all_replacements();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen snapshot taken above.
			$current_screen = $previous_screen;
		}
	}

	/**
	 * @testdox Should resolve only route controllers for the effective state.
	 * @dataProvider effective_state_provider
	 *
	 * @param string $state Effective native state.
	 */
	public function test_resolves_only_route_controllers_for_effective_state( string $state ): void {
		$controller = $this->create_payment_details_controller();
		$fixture    = $this->create_container( $state, $controller );
		$container  = $fixture['container'];
		$runtime    = $fixture['runtime'];
		$registrar  = new WooPaymentsAdminRestRouteRegistrar();
		$registrar->init( $container );

		try {
			$registrar->register_rest_controllers();

			$expected = array( WooPaymentsSetupTier::class );
			if ( in_array( $state, array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true ) ) {
				$expected = array_merge( $expected, WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() );
			}
			if ( WooPaymentsSetupTier::ACTIVE === $state ) {
				$expected[] = SessionController::class;
			}

			$this->assertSame( $expected, $runtime->resolved );
			$this->assertNotContains( WooPaymentsAccountService::class, $runtime->resolved );
		} finally {
			remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $controller, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
			wc_get_container()->reset_all_replacements();
		}
	}

	/** @return array<string,array{string}> */
	public static function effective_state_provider(): array {
		return array(
			'available' => array( WooPaymentsSetupTier::AVAILABLE ),
			'connected' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'    => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * @testdox On a $_dataName request, an internal REST dispatch finds the provider's routes, each method with one handler.
	 * @dataProvider set_up_request_provider
	 *
	 * @param string $state        Effective setup tier.
	 * @param string $request_type Request type.
	 */
	public function test_routes_answer_an_internal_dispatch_in_every_request_type( string $state, string $request_type ): void {
		$this->replace_documents_controller();
		$fixture = $this->create_container( $state, $this->create_payment_details_controller(), self::get_route_controllers() );

		try {
			$this->register_request_classes( $fixture['container'], $state, $request_type );
			$routes = $this->start_rest_server()->get_routes();

			$this->assertArrayHasKey( '/wc/v3/orders', $routes, 'WooCommerce registers its own routes in every request type.' );
			$this->assertArrayHasKey( '/wc/v3/payments/charges/(?P<charge_id>\w+)', $routes );
			$this->assertArrayHasKey( '/wc/v3/payments/webhook', $routes );
			$this->assertSame( WooPaymentsSetupTier::ACTIVE === $state, array_key_exists( '/payments/woopay/session', $routes ), 'The WooPay session route registers only on an active store.' );
			foreach ( $routes as $route => $handlers ) {
				if ( 0 !== strpos( $route, '/wc/v3/payments' ) && '/payments/woopay/session' !== $route ) {
					continue;
				}
				$methods = array();
				foreach ( $handlers as $handler ) {
					$methods = array_merge( $methods, array_keys( array_filter( $handler['methods'] ) ) );
				}
				$this->assertSame( array_values( array_unique( $methods ) ), $methods, "$route must have one handler per method." );
			}
		} finally {
			$this->restore_rest_server();
			wc_get_container()->reset_all_replacements();
		}
	}

	/** @return array<string,array{string,string}> */
	public static function set_up_request_provider(): array {
		$cases = array();
		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'cli', 'cron', 'ajax', 'front', 'admin', 'rest' ) as $request_type ) {
				$cases[ "$state $request_type" ] = array( $state, $request_type );
			}
		}

		return $cases;
	}

	/**
	 * @testdox A front request resolves the registrar and no route controller until WordPress starts its REST server.
	 */
	public function test_front_request_resolves_route_controllers_only_when_rest_starts(): void {
		$fixture     = $this->create_container( WooPaymentsSetupTier::CONNECTED, $this->create_payment_details_controller(), self::get_route_controllers() );
		$runtime     = $fixture['runtime'];
		$controllers = WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots();

		try {
			$this->register_request_classes( $fixture['container'], WooPaymentsSetupTier::CONNECTED, 'front' );

			$this->assertContains( WooPaymentsAdminRestRouteRegistrar::class, $runtime->resolved );
			$this->assertSame( array(), array_values( array_intersect( $controllers, $runtime->resolved ) ), 'No route controller resolves before the REST server starts.' );

			$this->start_rest_server();

			$this->assertSame( $controllers, array_values( array_unique( array_intersect( $controllers, $runtime->resolved ) ) ) );
		} finally {
			$this->restore_rest_server();
			wc_get_container()->reset_all_replacements();
		}
	}

	/**
	 * The controllers the registrar resolves on an active store.
	 *
	 * @return array<int,string>
	 */
	private static function get_route_controllers(): array {
		return array_merge( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots(), array( SessionController::class ) );
	}

	/**
	 * Register the provider's classes for one setup tier and request type, as WooCommerce does while it loads.
	 *
	 * @param Container $container    Controlled container.
	 * @param string    $state        Effective setup tier.
	 * @param string    $request_type Request type.
	 */
	private function register_request_classes( Container $container, string $state, string $request_type ): void {
		$bootstrap        = new PaymentsBootstrap(
			static fn( $container, string $request_type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $request_type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		);
		$register_classes = new ReflectionMethod( PaymentsBootstrap::class, 'register_classes' );
		$register_classes->setAccessible( true );
		$register_classes->invoke( $bootstrap, $container, WooPaymentsProvider::get_classes_by_setup_tier()[ $state ][ $request_type ] );
	}

	/**
	 * Start a fresh REST server, as rest_get_server() does on the first REST call of a request.
	 *
	 * @return WP_REST_Server
	 */
	private function start_rest_server(): WP_REST_Server {
		global $wp_rest_server;

		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore_rest_server() puts the test's server back.
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising WordPress's internal REST initialization boundary.
		do_action( 'rest_api_init', $wp_rest_server );

		return $wp_rest_server;
	}

	/**
	 * Put the test's REST server back.
	 */
	private function restore_rest_server(): void {
		global $wp_rest_server;

		$wp_rest_server = $this->server; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the server WC_REST_Unit_Test_Case set up.
	}

	/**
	 * Read Documents as enabled, which a disconnected test account cannot, so its routes register too.
	 */
	private function replace_documents_controller(): void {
		$account = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_documents_enabled' ) )
			->getMock();
		$account->method( 'is_documents_enabled' )->willReturn( true );
		$documents = new WooPaymentsDocumentsRestController();
		$documents->init( new Client(), $account );
		wc_get_container()->replace( WooPaymentsDocumentsRestController::class, $documents );
	}

	/**
	 * Create a route controller whose registration is owned by native.
	 *
	 * @return WooPaymentsPaymentDetailsRestController
	 */
	private function create_payment_details_controller(): WooPaymentsPaymentDetailsRestController {
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( new Client(), new WooPaymentsOrderDetailsService(), wc_get_container()->get( WooPaymentsAccountService::class ) );

		return $controller;
	}

	/**
	 * Create a container that exposes one real route owner and records the other deferred roots as no-ops.
	 *
	 * @param string                                  $state      Effective native state.
	 * @param WooPaymentsPaymentDetailsRestController $controller Real route owner.
	 * @param array<int,string>                       $real       Services resolved from WooCommerce's container instead of a no-op.
	 * @return array{container:Container,runtime:RuntimeContainer&object{registrar:?WooPaymentsAdminRestRouteRegistrar,resolved:array<int,string>}}
	 */
	private function create_container( string $state, WooPaymentsPaymentDetailsRestController $controller, array $real = array() ): array {
		$container = new Container();
		$runtime   = new class( $container, $state, $controller, $real ) extends RuntimeContainer {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var WooPaymentsAdminRestRouteRegistrar|null */
			public ?WooPaymentsAdminRestRouteRegistrar $registrar = null;

			/** @var Container */
			private Container $container;

			/** @var string */
			private $state;

			/** @var WooPaymentsPaymentDetailsRestController */
			private $controller;

			/** @var array<int,string> */
			private array $real;

			/**
			 * Initialize the controlled container.
			 *
			 * @param Container                               $container  WooCommerce dependency container.
			 * @param string                                  $state      Effective native state.
			 * @param WooPaymentsPaymentDetailsRestController $controller Real route owner.
			 * @param array<int,string>                       $real       Services resolved from WooCommerce's container.
			 */
			public function __construct( Container $container, string $state, WooPaymentsPaymentDetailsRestController $controller, array $real ) {
				parent::__construct( array( Container::class => $container ) );
				$this->container  = $container;
				$this->state      = $state;
				$this->controller = $controller;
				$this->real       = $real;
			}

			/**
			 * Resolve a controlled service.
			 *
			 * @param string $id Service ID.
			 * @return object Controlled service.
			 */
			public function get( $id ) {
				$this->resolved[] = $id;
				if ( WooPaymentsAdminRestRouteRegistrar::class === $id ) {
					if ( null === $this->registrar ) {
						$this->registrar = new WooPaymentsAdminRestRouteRegistrar();
						$this->registrar->init( $this->container );
					}

					return $this->registrar;
				}

				if ( WooPaymentsSetupTier::class === $id ) {
					return new class( $this->state ) {
						/** @var string */
						private $state;

						/**
						 * Initialize the state value.
						 *
						 * @param string $state Effective native state.
						 */
						public function __construct( string $state ) {
							$this->state = $state;
						}

						/** Return the effective native state. */
						public function get_effective_tier(): string {
							return $this->state;
						}
					};
				}

				if ( in_array( $id, $this->real, true ) ) {
					return wc_get_container()->get( $id );
				}

				if ( WooPaymentsPaymentDetailsRestController::class === $id ) {
					return $this->controller;
				}

				return new class() {
					/** Register a non-target route owner without side effects. */
					public function register(): void {
					}

					/**
					 * Accept the gateway registry's lazy provider without building it.
					 *
					 * @param callable $resolver                 Provider resolver.
					 * @param callable $should_register_gateways Provider gateway check.
					 */
					public function set_provider( callable $resolver, callable $should_register_gateways ): void {
						unset( $resolver, $should_register_gateways );
					}
				};
			}

			/**
			 * Report that every controlled service is available.
			 *
			 * @param string $id Service ID.
			 * @return bool
			 */
			public function has( $id ): bool {
				unset( $id );
				return true;
			}
		};

		$inner_container = new \ReflectionProperty( Container::class, 'container' );
		$inner_container->setAccessible( true );
		$inner_container->setValue( $container, $runtime );

		return array(
			'container' => $container,
			'runtime'   => $runtime,
		);
	}
}
