<?php
/**
 * WooPaymentsAdminRestRouteRegistrar tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMoneyMovementOrderService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPaySessionController;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use WC_REST_Unit_Test_Case;

/**
 * Tests for deferred native WooPayments REST registration on admin requests.
 */
class WooPaymentsAdminRestRouteRegistrarTest extends WC_REST_Unit_Test_Case {

	/**
	 * @testdox Should register native routes when an admin page dispatches an internal REST request.
	 */
	public function test_registers_routes_for_internal_requests_with_admin_screen(): void {
		global $current_screen;

		$previous_screen = $current_screen;
		$controller      = $this->create_payment_details_controller();
		$container       = $this->create_container( NativePaymentsState::CONNECTED, $controller );
		$bootstrap       = new NativePaymentsBootstrap(
			array( WooPaymentsProvider::class, 'get_bootstrap_root_matrix' ),
			static fn(): array => array()
		);
		$roots_for       = new ReflectionMethod( NativePaymentsBootstrap::class, 'roots_for' );
		$register_roots  = new ReflectionMethod( NativePaymentsBootstrap::class, 'register_roots' );
		$roots_for->setAccessible( true );
		$register_roots->setAccessible( true );
		set_current_screen( 'edit-page' );

		try {
			$roots = $roots_for->invoke( $bootstrap, NativePaymentsState::CONNECTED, 'admin' );
			$register_roots->invoke( $bootstrap, $container, $roots );
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Exercising WordPress's internal REST initialization boundary.
			do_action( 'rest_api_init', $this->server );

			$this->assertSame( 'edit-page', get_current_screen()->id );
			$this->assertArrayHasKey( '/wc/v3/payments/charges/(?P<charge_id>\w+)', $this->server->get_routes() );
		} finally {
			if ( $container->registrar ) {
				remove_action( 'rest_api_init', array( $container->registrar, 'register_rest_controllers' ), 0 );
			}
			remove_action( 'rest_api_init', array( $controller, 'register_routes' ) );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the screen snapshot taken above.
			$current_screen = $previous_screen;
		}
	}

	/**
	 * @testdox Should resolve only route controllers for the effective native state.
	 * @dataProvider effective_state_provider
	 *
	 * @param string $state Effective native state.
	 */
	public function test_resolves_only_route_controllers_for_effective_state( string $state ): void {
		$controller = $this->create_payment_details_controller();
		$container  = $this->create_container( $state, $controller );
		$registrar  = new WooPaymentsAdminRestRouteRegistrar();
		$registrar->init( $container );

		try {
			$registrar->register_rest_controllers();

			$expected = array( NativePaymentsState::class );
			if ( in_array( $state, array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true ) ) {
				$expected = array_merge( $expected, WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() );
			}
			if ( NativePaymentsState::ACTIVE === $state ) {
				$expected[] = WooPaymentsWooPaySessionController::class;
			}

			$this->assertSame( $expected, $container->resolved );
			$this->assertNotContains( WooPaymentsAccountService::class, $container->resolved );
		} finally {
			remove_action( 'rest_api_init', array( $controller, 'register_routes' ) );
		}
	}

	/** @return array<string,array{string}> */
	public static function effective_state_provider(): array {
		return array(
			'available' => array( NativePaymentsState::AVAILABLE ),
			'connected' => array( NativePaymentsState::CONNECTED ),
			'active'    => array( NativePaymentsState::ACTIVE ),
		);
	}

	/**
	 * Create a route controller whose registration is owned by native.
	 *
	 * @return WooPaymentsPaymentDetailsRestController
	 */
	private function create_payment_details_controller(): WooPaymentsPaymentDetailsRestController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( true );

		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $arbiter, new WooPaymentsApiClient(), new WooPaymentsMoneyMovementOrderService() );

		return $controller;
	}

	/**
	 * Create a container that exposes one real route owner and records the other deferred roots as no-ops.
	 *
	 * @param string                                  $state      Effective native state.
	 * @param WooPaymentsPaymentDetailsRestController $controller Real route owner.
	 * @return ContainerInterface&object{registrar:?WooPaymentsAdminRestRouteRegistrar,resolved:array<int,string>}
	 */
	private function create_container( string $state, WooPaymentsPaymentDetailsRestController $controller ): ContainerInterface {
		return new class( $state, $controller ) implements ContainerInterface {
			/** @var array<int,string> */
			public array $resolved = array();

			/** @var WooPaymentsAdminRestRouteRegistrar|null */
			public ?WooPaymentsAdminRestRouteRegistrar $registrar = null;

			/** @var string */
			private $state;

			/** @var WooPaymentsPaymentDetailsRestController */
			private $controller;

			/**
			 * Initialize the controlled container.
			 *
			 * @param string                                  $state      Effective native state.
			 * @param WooPaymentsPaymentDetailsRestController $controller Real route owner.
			 */
			public function __construct( string $state, WooPaymentsPaymentDetailsRestController $controller ) {
				$this->state      = $state;
				$this->controller = $controller;
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
						$this->registrar->init( $this );
					}

					return $this->registrar;
				}

				if ( NativePaymentsState::class === $id ) {
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
						public function get_state(): string {
							return $this->state;
						}
					};
				}

				if ( WooPaymentsPaymentDetailsRestController::class === $id ) {
					return $this->controller;
				}

				return new class() {
					/** Register a non-target route owner without side effects. */
					public function register(): void {
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
	}
}
