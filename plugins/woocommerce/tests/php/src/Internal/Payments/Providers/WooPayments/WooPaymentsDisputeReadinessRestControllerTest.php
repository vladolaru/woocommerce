<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeReadinessRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeReadinessService;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests for the native WooPayments dispute readiness REST controller.
 */
class WooPaymentsDisputeReadinessRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDisputeReadinessRestController
	 */
	private $sut;

	/**
	 * Recording dispute readiness service.
	 *
	 * @var WooPaymentsDisputeReadinessService
	 */
	private $service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		delete_option( '_wcpay_feature_dispute_readiness_overview' );
		$this->service = $this->create_service();
		$this->sut     = $this->create_controller();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( '_wcpay_feature_dispute_readiness_overview' );
		remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this->sut, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
		wp_set_current_user( 0 );

		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Dispute readiness routes are registered under wc/v3 when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_routes_when_builtin_owns_runtime(): void {
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsDisputeReadinessRestController::class, $this->sut );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/dispute-readiness', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/dispute-readiness/dismiss', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/dispute-readiness/statement-descriptor/confirm', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/dispute-readiness'], WP_REST_Server::READABLE );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/dispute-readiness/dismiss'], WP_REST_Server::CREATABLE );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/dispute-readiness/statement-descriptor/confirm'], WP_REST_Server::CREATABLE );
	}

	/**
	 * @testdox Routes require manage_woocommerce before calling the service.
	 */
	public function test_routes_require_manage_woocommerce(): void {
		update_option( '_wcpay_feature_dispute_readiness_overview', 'yes' );
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/dispute-readiness' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( '', $this->service->last_call );
	}

	/**
	 * @testdox GET answers the overview the feature flag selects, never an error: $_dataName.
	 * @dataProvider provide_feature_flag_overviews
	 *
	 * @param string|null $flag          Stored feature flag, or null when absent.
	 * @param string      $expected_call Service method that builds the answer.
	 */
	public function test_get_returns_the_overview_for_the_feature_flag( ?string $flag, string $expected_call ): void {
		if ( null !== $flag ) {
			update_option( '_wcpay_feature_dispute_readiness_overview', $flag );
		}
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/dispute-readiness' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected_call, $this->service->last_call );
		$this->assertSame( $this->service->$expected_call(), $response->get_data() );
	}

	/**
	 * Feature flag values and the overview each one selects.
	 *
	 * @return array<string,array{string|null,string}>
	 */
	public function provide_feature_flag_overviews(): array {
		return array(
			'flag absent' => array( null, 'get_overview_payload' ),
			'flag yes'    => array( 'yes', 'get_overview_payload' ),
			'flag 0'      => array( '0', 'get_disabled_overview_payload' ),
		);
	}

	/**
	 * @testdox Mutating routes return forbidden when the feature is disabled.
	 * @dataProvider mutating_route_data
	 *
	 * @param string $route Route path.
	 */
	public function test_mutating_routes_return_forbidden_when_feature_is_disabled( string $route ): void {
		update_option( '_wcpay_feature_dispute_readiness_overview', '0' );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $route ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpay_dispute_readiness_disabled', $response->get_data()['code'] );
		$this->assertSame( '', $this->service->last_call );
	}

	/**
	 * @testdox POST $_dataName returns the updated overview when the feature is enabled.
	 * @dataProvider provide_enabled_mutating_routes
	 *
	 * @param string $route         Route path.
	 * @param string $expected_call Service method the route calls.
	 */
	public function test_mutating_route_returns_updated_overview_when_feature_is_enabled( string $route, string $expected_call ): void {
		update_option( '_wcpay_feature_dispute_readiness_overview', 'yes' );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', $route ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected_call, $this->service->last_call );
		$this->assertSame( $this->service->$expected_call(), $response->get_data() );
	}

	/**
	 * Mutating routes and the service method each one calls.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provide_enabled_mutating_routes(): array {
		return array(
			'dismiss route' => array( '/wc/v3/payments/dispute-readiness/dismiss', 'dismiss_overview_card' ),
			'confirm route' => array( '/wc/v3/payments/dispute-readiness/statement-descriptor/confirm', 'confirm_statement_descriptor' ),
		);
	}

	/**
	 * Data provider for mutating routes.
	 *
	 * @return array<string,array{string}>
	 */
	public function mutating_route_data(): array {
		return array(
			'dismiss route'              => array( '/wc/v3/payments/dispute-readiness/dismiss' ),
			'statement descriptor route' => array( '/wc/v3/payments/dispute-readiness/statement-descriptor/confirm' ),
		);
	}

	/**
	 * Create a native dispute readiness REST controller.
	 *
	 * @return WooPaymentsDisputeReadinessRestController
	 */
	private function create_controller(): WooPaymentsDisputeReadinessRestController {
		$controller = new WooPaymentsDisputeReadinessRestController();
		$controller->init( $this->service );

		return $controller;
	}

	/**
	 * Create a recording service.
	 *
	 * @return WooPaymentsDisputeReadinessService
	 */
	private function create_service(): WooPaymentsDisputeReadinessService {
		return new class() extends WooPaymentsDisputeReadinessService {

			/**
			 * Last called method.
			 *
			 * @var string
			 */
			public string $last_call = '';

			/**
			 * Return disabled overview payload.
			 *
			 * @return array<string,mixed>
			 */
			public function get_disabled_overview_payload(): array {
				$this->last_call = __FUNCTION__;

				return array(
					'overview' => array(
						'enabled'             => false,
						'hidden'              => true,
						'score'               => 0,
						'total'               => 0,
						'state'               => 'incomplete',
						'isDismissed'         => true,
						'completeSignalIds'   => array(),
						'incompleteSignalIds' => array(),
						'signals'             => array(),
						'dismissal'           => array(
							'isDismissed'       => true,
							'isStoredDismissal' => false,
							'reappearReason'    => 'feature_disabled',
						),
					),
				);
			}

			/**
			 * Return enabled overview payload.
			 *
			 * @return array<string,mixed>
			 */
			public function get_overview_payload(): array {
				$this->last_call = __FUNCTION__;

				return array(
					'overview' => array(
						'enabled'             => true,
						'hidden'              => false,
						'score'               => 1,
						'total'               => 4,
						'state'               => 'incomplete',
						'isDismissed'         => false,
						'completeSignalIds'   => array( 'statement_descriptor' ),
						'incompleteSignalIds' => array( 'refund_policy', 'support_contact', 'terms_and_conditions' ),
						'signals'             => array(
							array(
								'id'     => 'statement_descriptor',
								'reason' => 'looks_recognizable',
							),
						),
						'dismissal'           => array(
							'isDismissed'       => false,
							'isStoredDismissal' => false,
							'reappearReason'    => null,
						),
					),
				);
			}

			/**
			 * Return dismissed overview payload.
			 *
			 * @return array<string,mixed>
			 */
			public function dismiss_overview_card(): array {
				$this->last_call = __FUNCTION__;
				$payload         = $this->get_overview_payload();
				$this->last_call = __FUNCTION__;

				$payload['overview']['isDismissed'] = true;

				return $payload;
			}

			/**
			 * Return confirmed overview payload.
			 *
			 * @return array<string,mixed>
			 */
			public function confirm_statement_descriptor(): array {
				$this->last_call = __FUNCTION__;
				$payload         = $this->get_overview_payload();
				$this->last_call = __FUNCTION__;

				$payload['overview']['signals'][0]['reason'] = 'confirmed';

				return $payload;
			}
		};
	}

	/**
	 * Assert that a route handler supports a method.
	 *
	 * @param array<int,array<string,mixed>> $handlers Route handlers.
	 * @param string                         $method   HTTP method.
	 */
	private function assertRouteHasMethod( array $handlers, string $method ): void {
		foreach ( $handlers as $handler ) {
			if ( isset( $handler['methods'][ $method ] ) && $handler['methods'][ $method ] ) {
				$this->assertNotEmpty( $handler['methods'][ $method ], "Route handler should enable the {$method} method." );
				return;
			}
		}

		$this->fail( "Route does not support {$method}." );
	}
}
