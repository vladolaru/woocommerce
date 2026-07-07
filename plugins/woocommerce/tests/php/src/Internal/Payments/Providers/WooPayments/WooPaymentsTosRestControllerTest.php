<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTosRestController;
use RuntimeException;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsTosRestController class.
 */
class WooPaymentsTosRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsTosRestController|null
	 */
	private ?WooPaymentsTosRestController $sut = null;

	/**
	 * Recording API client.
	 *
	 * @var RecordingTosApiClient
	 */
	private RecordingTosApiClient $api_client;

	/**
	 * Recording account service.
	 *
	 * @var RecordingTosAccountService
	 */
	private RecordingTosAccountService $account_service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client      = new RecordingTosApiClient();
		$this->account_service = new RecordingTosAccountService();

		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'        => 'no',
				'manual_capture' => 'yes',
			)
		);

		wp_set_current_user(
			$this->factory->user->create(
				array(
					'role'       => 'administrator',
					'user_login' => 'merchant_admin',
				)
			)
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( null !== $this->sut ) {
			remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		}

		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( '_wcpay_onboarding_stripe_connected' );

		parent::tearDown();
	}

	/**
	 * @testdox ToS routes are registered under wc/v3 when native owns runtime.
	 */
	public function test_registers_routes_when_native_owns_runtime(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/tos', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/tos'], WP_REST_Server::CREATABLE );
		$this->assertArrayHasKey( '/wc/v3/payments/tos/reactivate', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/tos/reactivate'], WP_REST_Server::CREATABLE );
		$this->assertArrayHasKey( '/wc/v3/payments/tos/stripe_track_connected', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/tos/stripe_track_connected'], WP_REST_Server::CREATABLE );
	}

	/**
	 * @testdox ToS routes are not registered when native does not own runtime.
	 */
	public function test_registers_no_routes_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox ToS route requires manage_woocommerce before changing gateway state.
	 */
	public function test_tos_route_requires_manage_woocommerce(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( $this->create_tos_request( array( 'accept' => true ) ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->agreements );
		$this->assertSame( 0, $this->account_service->refresh_count );
		$this->assertSame( 'no', $this->get_gateway_enabled_setting() );
	}

	/**
	 * @testdox ToS route returns bad_request when accept is missing.
	 */
	public function test_tos_route_rejects_missing_accept(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$response = $this->server->dispatch( $this->create_tos_request( array() ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'result' => 'bad_request' ), $response->get_data() );
		$this->assertSame( 'no', $this->get_gateway_enabled_setting() );
	}

	/**
	 * @testdox ToS route records acceptance, enables the gateway, and refreshes account data.
	 */
	public function test_tos_route_accepts_terms(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$response = $this->server->dispatch( $this->create_tos_request( array( 'accept' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame( 'yes', $this->get_gateway_enabled_setting() );
		$this->assertSame(
			array(
				array(
					'source'    => 'settings-popup',
					'user_name' => 'merchant_admin',
				),
			),
			$this->api_client->agreements
		);
		$this->assertSame( 1, $this->account_service->refresh_count );
	}

	/**
	 * @testdox ToS route disables the gateway when terms are declined.
	 */
	public function test_tos_route_declines_terms(): void {
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'        => 'yes',
				'manual_capture' => 'yes',
			)
		);
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$response = $this->server->dispatch( $this->create_tos_request( array( 'accept' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame( 'no', $this->get_gateway_enabled_setting() );
		$this->assertSame( array(), $this->api_client->agreements );
		$this->assertSame( 0, $this->account_service->refresh_count );
	}

	/**
	 * @testdox Reactivate route enables the gateway after a ToS decline.
	 */
	public function test_reactivate_route_enables_gateway(): void {
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/tos/reactivate' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame( 'yes', $this->get_gateway_enabled_setting() );
	}

	/**
	 * @testdox Stripe tracking route deletes the connected tracking option.
	 */
	public function test_stripe_track_connected_route_deletes_tracking_option(): void {
		update_option( '_wcpay_onboarding_stripe_connected', array( 'tracked' => true ) );
		$this->sut = $this->create_controller( true );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/tos/stripe_track_connected' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertFalse( get_option( '_wcpay_onboarding_stripe_connected' ) );
	}

	/**
	 * Create a ToS REST controller.
	 *
	 * @param bool $native_register Whether native should own route registration.
	 * @return WooPaymentsTosRestController
	 */
	private function create_controller( bool $native_register ): WooPaymentsTosRestController {
		$this->assertTrue( class_exists( WooPaymentsTosRestController::class ), 'WooPaymentsTosRestController should exist.' );

		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$controller = new WooPaymentsTosRestController();
		$controller->init( $arbiter, $this->api_client, $this->account_service );

		return $controller;
	}

	/**
	 * Create a JSON ToS request.
	 *
	 * @param array<string,mixed> $body Request body.
	 * @return WP_REST_Request
	 */
	private function create_tos_request( array $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/tos' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return $request;
	}

	/**
	 * Get the persisted gateway enabled setting.
	 *
	 * @return string
	 */
	private function get_gateway_enabled_setting(): string {
		$settings = get_option( 'woocommerce_woocommerce_payments_settings', array() );

		return is_array( $settings ) ? (string) ( $settings['enabled'] ?? '' ) : '';
	}

	/**
	 * Assert a route handler supports a method.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 * @param string                         $method         Method constant.
	 */
	private function assertRouteHasMethod( array $route_handlers, string $method ): void {
		foreach ( $route_handlers as $handler ) {
			if ( isset( $handler['methods'][ $method ] ) && true === $handler['methods'][ $method ] ) {
				return;
			}
		}

		$this->fail( 'Route does not accept method ' . $method . '.' );
	}
}

/**
 * Recording ToS API client.
 */
class RecordingTosApiClient extends WooPaymentsApiClient {

	/**
	 * Recorded ToS agreement requests.
	 *
	 * @var array<int,array{source:string,user_name:string}>
	 */
	public array $agreements = array();

	/**
	 * Whether agreement recording should fail.
	 *
	 * @var bool
	 */
	public bool $throw_on_agreement = false;

	/**
	 * Record a ToS agreement request.
	 *
	 * @param string $source Source that collected the agreement.
	 * @param string $user_name Current WordPress user login.
	 * @return array<string,bool>
	 */
	public function add_account_tos_agreement( string $source, string $user_name ): array {
		if ( $this->throw_on_agreement ) {
			throw new RuntimeException( 'ToS agreement failed.' );
		}

		$this->agreements[] = array(
			'source'    => $source,
			'user_name' => $user_name,
		);

		return array( 'success' => true );
	}
}

/**
 * Recording ToS account service.
 */
class RecordingTosAccountService extends WooPaymentsAccountService {

	/**
	 * Number of account refreshes.
	 *
	 * @var int
	 */
	public int $refresh_count = 0;

	/**
	 * Whether refreshing should fail.
	 *
	 * @var bool
	 */
	public bool $throw_on_refresh = false;

	/**
	 * Record an account refresh.
	 *
	 * @return array<string,bool>
	 */
	public function refresh_account_data(): array {
		if ( $this->throw_on_refresh ) {
			throw new RuntimeException( 'Account refresh failed.' );
		}

		++$this->refresh_count;

		return array( 'refreshed' => true );
	}
}
