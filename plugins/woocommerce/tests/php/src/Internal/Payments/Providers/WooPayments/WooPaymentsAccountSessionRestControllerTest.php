<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountSessionRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEmbeddedAccountSessionService;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsAccountSessionRestController class.
 */
class WooPaymentsAccountSessionRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsAccountSessionRestController
	 */
	private WooPaymentsAccountSessionRestController $sut;

	/**
	 * Recording service.
	 *
	 * @var WooPaymentsEmbeddedAccountSessionService
	 */
	private WooPaymentsEmbeddedAccountSessionService $service;

	/**
	 * Recording account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service         = $this->create_service();
		$this->account_service = $this->create_account_service();
		$this->sut             = $this->create_controller( true );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		remove_all_filters( 'woocommerce_logging_class' );
		parent::tearDown();
	}

	/**
	 * @testdox Account session route is registered under wc/v3 when native owns runtime.
	 */
	public function test_registers_route_when_native_owns_runtime(): void {
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/accounts', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/accounts'], WP_REST_Server::READABLE );
		$this->assertArrayHasKey( '/wc/v3/payments/accounts/session', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/accounts/session'], WP_REST_Server::READABLE );
	}

	/**
	 * @testdox Account session route is not registered when native does not own runtime.
	 */
	public function test_registers_no_route_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox Account session route requires manage_woocommerce before calling the service.
	 */
	public function test_route_requires_manage_woocommerce(): void {
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts/session' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( '', $this->service->last_call );
	}

	/**
	 * @testdox Accounts route requires manage_woocommerce before reading account data.
	 */
	public function test_accounts_route_requires_manage_woocommerce(): void {
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( '', $this->account_service->last_call );
	}

	/**
	 * @testdox Accounts route returns cached account data with plugin-compatible flags.
	 */
	public function test_accounts_route_returns_cached_account_payload(): void {
		$this->account_service->account_data = array(
			'account_id'            => 'acct_native',
			'card_present_eligible' => true,
			'country'               => 'NL',
			'status'                => 'complete',
			'store_currencies'      => array(
				'default'   => 'eur',
				'supported' => array( 'eur', 'usd' ),
			),
		);
		$this->account_service->test_mode    = true;
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_cached_account_data', $this->account_service->last_call );
		$this->assertSame(
			array(
				'account_id'            => 'acct_native',
				'card_present_eligible' => false,
				'country'               => 'NL',
				'status'                => 'complete',
				'store_currencies'      => array(
					'default'   => 'eur',
					'supported' => array( 'eur', 'usd' ),
				),
				'test_mode'             => true,
				'test_mode_onboarding'  => false,
			),
			$response->get_data()
		);
	}

	/**
	 * @testdox Accounts route returns the plugin fallback shape when no account data exists.
	 */
	public function test_accounts_route_returns_no_account_fallback_payload(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'woocommerce_default_country', 'US:CA' );
		$this->account_service->account_data = array();
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'card_present_eligible'    => false,
				'country'                  => 'US',
				'current_deadline'         => null,
				'has_overdue_requirements' => false,
				'has_pending_requirements' => false,
				'statement_descriptor'     => '',
				'status'                   => 'NOACCOUNT',
				'store_currencies'         => array(
					'default'   => 'USD',
					'supported' => array( 'USD' ),
				),
				'customer_currencies'      => array(
					'supported' => array( 'USD' ),
				),
				'test_mode'                => false,
				'test_mode_onboarding'     => false,
			),
			$response->get_data()
		);
	}

	/**
	 * @testdox Account session route returns the mapped service payload.
	 */
	public function test_route_returns_service_payload(): void {
		$this->service->response = array(
			'clientSecret'   => 'cs_test',
			'expiresAt'      => 1781740800,
			'accountId'      => 'acct_native',
			'isLive'         => false,
			'publishableKey' => 'pk_test_native',
			'locale'         => get_user_locale(),
		);
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts/session' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'create_session', $this->service->last_call );
		$this->assertSame( $this->service->response, $response->get_data() );
	}

	/**
	 * @testdox Account session route returns a sanitized 500 when the service throws.
	 */
	public function test_route_returns_sanitized_error_when_service_throws(): void {
		$this->service->exception = new \RuntimeException( 'secret platform failure: sk_test_123' );
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts/session' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_woopayments_account_session_error', $response->as_error()->get_error_code() );
		$this->assertStringNotContainsString( 'sk_test_123', wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'secret platform failure', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * @testdox Account session route logs the failure instead of silently discarding it, keeping the sanitized 500.
	 */
	public function test_route_logs_error_when_service_throws(): void {
		$this->service->exception = new \RuntimeException( 'secret platform failure: sk_test_123' );
		$logger                   = $this->create_recording_logger();
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ): object {
				return $logger;
			}
		);
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/accounts/session' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_woopayments_account_session_error', $response->as_error()->get_error_code() );

		$this->assertCount( 1, $logger->entries );
		$this->assertSame( 'error', $logger->entries[0]['level'] );
		$this->assertSame( 'woopayments-account-session', $logger->entries[0]['context']['source'] );
		$this->assertStringContainsString( 'secret platform failure', $logger->entries[0]['message'] );
	}

	/**
	 * Create a native account-session REST controller.
	 *
	 * @param bool $native_register Whether native should own route registration.
	 * @return WooPaymentsAccountSessionRestController
	 */
	private function create_controller( bool $native_register ): WooPaymentsAccountSessionRestController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$controller = new WooPaymentsAccountSessionRestController();
		$controller->init( $arbiter, $this->service, $this->account_service );

		return $controller;
	}

	/**
	 * Create a recording account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service(): WooPaymentsAccountService {
		return new class() extends WooPaymentsAccountService {

			/**
			 * Last called method.
			 *
			 * @var string
			 */
			public string $last_call = '';

			/**
			 * Cached account data.
			 *
			 * @var array<string,mixed>
			 */
			public array $account_data = array();

			/**
			 * Whether WooPayments is in test mode.
			 *
			 * @var bool
			 */
			public bool $test_mode = false;

			/**
			 * Whether WooPayments is using test-mode onboarding.
			 *
			 * @var bool
			 */
			public bool $test_mode_onboarding = false;

			/**
			 * Get cached account data.
			 *
			 * @param bool $force_refresh Whether to force refresh.
			 * @return array<string,mixed>
			 */
			public function get_cached_account_data( bool $force_refresh = false ): array {
				unset( $force_refresh );

				$this->last_call = __FUNCTION__;

				return $this->account_data;
			}

			/**
			 * Tell whether WooPayments is in test mode.
			 *
			 * @return bool
			 */
			public function is_test_mode_enabled(): bool {
				return $this->test_mode;
			}

			/**
			 * Tell whether WooPayments is using test-mode onboarding.
			 *
			 * @return bool
			 */
			public function is_test_mode_onboarding_enabled(): bool {
				return $this->test_mode_onboarding;
			}
		};
	}

	/**
	 * Create a recording service.
	 *
	 * @return WooPaymentsEmbeddedAccountSessionService
	 */
	private function create_service(): WooPaymentsEmbeddedAccountSessionService {
		return new class() extends WooPaymentsEmbeddedAccountSessionService {

			/**
			 * Last called method.
			 *
			 * @var string
			 */
			public string $last_call = '';

			/**
			 * Response returned by the service.
			 *
			 * @var array<string,mixed>
			 */
			public array $response = array();

			/**
			 * Optional exception thrown by the next call.
			 *
			 * @var \Throwable|null
			 */
			public ?\Throwable $exception = null;

			/**
			 * Create an embedded account session.
			 *
			 * @return array<string,mixed>
			 * @throws \Throwable When configured.
			 */
			public function create_session(): array {
				$this->last_call = __FUNCTION__;

				if ( null !== $this->exception ) {
					throw $this->exception;
				}

				return $this->response;
			}
		};
	}

	/**
	 * Create a recording logger test double.
	 *
	 * Implements WC_Logger_Interface so it can be injected through the
	 * woocommerce_logging_class filter.
	 *
	 * @return object
	 */
	private function create_recording_logger(): object {
		return new class() implements \WC_Logger_Interface {
			/**
			 * Logged entries.
			 *
			 * @var array<int,array{level:string,message:string,context:array<string,mixed>}>
			 */
			public array $entries = array();

			/**
			 * Add a log entry.
			 *
			 * @param string $handle  File handle.
			 * @param string $message Log message.
			 * @param string $level   Log level.
			 * @return bool
			 */
			public function add( $handle, $message, $level = \WC_Log_Levels::NOTICE ) {
				$this->record( $level, $message, array( 'source' => $handle ) );

				return true;
			}

			/**
			 * Add a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function log( $level, $message, $context = array() ) {
				$this->record( $level, $message, $context );
			}

			/**
			 * Record an emergency log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function emergency( $message, $context = array() ) {
				$this->record( 'emergency', $message, $context );
			}

			/**
			 * Record an alert log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function alert( $message, $context = array() ) {
				$this->record( 'alert', $message, $context );
			}

			/**
			 * Record a critical log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function critical( $message, $context = array() ) {
				$this->record( 'critical', $message, $context );
			}

			/**
			 * Record an error log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function error( $message, $context = array() ) {
				$this->record( 'error', $message, $context );
			}

			/**
			 * Record a warning log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function warning( $message, $context = array() ) {
				$this->record( 'warning', $message, $context );
			}

			/**
			 * Record a notice log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function notice( $message, $context = array() ) {
				$this->record( 'notice', $message, $context );
			}

			/**
			 * Record an info log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function info( $message, $context = array() ) {
				$this->record( 'info', $message, $context );
			}

			/**
			 * Record a debug log entry.
			 *
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			public function debug( $message, $context = array() ) {
				$this->record( 'debug', $message, $context );
			}

			/**
			 * Record a log entry.
			 *
			 * @param string              $level   Log level.
			 * @param string              $message Log message.
			 * @param array<string,mixed> $context Log context.
			 */
			private function record( string $level, string $message, array $context ): void {
				$this->entries[] = array(
					'level'   => $level,
					'message' => $message,
					'context' => $context,
				);
			}
		};
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
