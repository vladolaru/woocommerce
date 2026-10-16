<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsDepositsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDepositsRestController;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the native WooPayments deposits REST controller.
 */
class WooPaymentsDepositsRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDepositsRestController
	 */
	private WooPaymentsDepositsRestController $sut;

	/**
	 * Recording API client.
	 *
	 * @var RecordingDepositsApiClient
	 */
	private RecordingDepositsApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new RecordingDepositsApiClient();
		$this->sut        = $this->create_controller();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this->sut, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
		remove_all_filters( 'wcpay_list_deposits_request' );
		remove_all_filters( 'woocommerce_logging_class' );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Deposits routes are registered under wc/v3 when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_deposits_routes_when_builtin_owns_runtime(): void {
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsDepositsRestController::class, $this->sut );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		foreach ( $this->get_expected_routes() as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $methods as $method ) {
				$this->assertRouteHasMethod( $routes[ $route ], $method );
			}
		}
	}

	/**
	 * @testdox Every payout route, the instant payout included, refuses visitors and customers before calling the platform.
	 *
	 * @dataProvider provide_payout_route_requests
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Route path.
	 * @param array<string,mixed> $params Body arguments.
	 */
	public function test_routes_require_manage_woocommerce( string $method, string $path, array $params = array() ): void {
		$this->sut->register_routes();

		foreach ( array( 0, $this->factory->user->create( array( 'role' => 'customer' ) ) ) as $user_id ) {
			wp_set_current_user( $user_id );
			$request = new WP_REST_Request( $method, $path );
			$request->set_body_params( $params );
			$response = $this->server->dispatch( $request );

			$this->assertSame( rest_authorization_required_code(), $response->get_status(), "User {$user_id} on {$method} {$path}." );
		}
		$this->assertNull( $this->api_client->last_deposits_query );
		$this->assertNull( $this->api_client->last_deposits_summary_query );
		$this->assertNull( $this->api_client->last_deposit_id );
		$this->assertNull( $this->api_client->last_export_query );
		$this->assertNull( $this->api_client->last_export_id );
		$this->assertNull( $this->api_client->last_manual_deposit );
	}

	/**
	 * One request per payout route and method.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_payout_route_requests(): array {
		return array(
			'list'           => array( 'GET', '/wc/v3/payments/deposits' ),
			'instant payout' => array(
				'POST',
				'/wc/v3/payments/deposits',
				array(
					'type'     => 'instant',
					'currency' => 'usd',
				),
			),
			'export'         => array( 'POST', '/wc/v3/payments/deposits/download' ),
			'export url'     => array( 'GET', '/wc/v3/payments/deposits/download/export_test' ),
			'summary'        => array( 'GET', '/wc/v3/payments/deposits/summary' ),
			'overview'       => array( 'GET', '/wc/v3/payments/deposits/overview-all' ),
			'detail'         => array( 'GET', '/wc/v3/payments/deposits/po_test' ),
		);
	}

	/**
	 * @testdox Overview route returns the raw payout overview envelope.
	 */
	public function test_get_deposits_overview_returns_raw_envelope(): void {
		$this->api_client->deposits_overview_response = array(
			'balance' => array(
				'available' => array(
					array(
						'amount'   => 1000,
						'currency' => 'usd',
					),
				),
				'pending'   => array(
					array(
						'amount'   => 250,
						'currency' => 'usd',
					),
				),
			),
			'account' => array(
				'default_currency' => 'usd',
			),
		);

		$response = $this->sut->get_deposits_overview( new WP_REST_Request( 'GET', '/wc/v3/payments/deposits/overview-all' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $this->api_client->deposits_overview_response, $response->get_data() );
	}

	/**
	 * @testdox Deposits list forwards only the known platform query parameters.
	 */
	public function test_get_deposits_forwards_allow_listed_query_parameters(): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/deposits' );
		$request->set_query_params(
			array(
				'page'              => '2',
				'pagesize'          => '25',
				'sort'              => 'date',
				'direction'         => 'desc',
				'match'             => 'all',
				'store_currency_is' => 'usd',
				'date_before'       => '2026-06-18',
				'date_after'        => '2026-06-01',
				'date_between'      => '2026-06-01...2026-06-18',
				'status_is'         => 'paid',
				'status_is_not'     => 'failed',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'page'              => 2,
				'pagesize'          => 25,
				'sort'              => 'date',
				'direction'         => 'desc',
				'limit'             => 100,
				'match'             => 'all',
				'store_currency_is' => 'usd',
				'date_before'       => '2026-06-18',
				'date_after'        => '2026-06-01',
				'date_between'      => array( '2026-06-01...2026-06-18' ),
				'status_is'         => 'paid',
				'status_is_not'     => 'failed',
			),
			$this->api_client->last_deposits_query
		);
		$this->assertSame( $this->api_client->deposits_response, $response->get_data() );
	}

	/**
	 * @testdox Deposits list preserves the WooPayments extension request filter contract.
	 */
	public function test_get_deposits_preserves_the_woopayments_extension_request_filter_contract(): void {
		$this->sut->register_routes();
		$observed_page_size = null;
		$observed_api       = null;

			add_filter(
				'wcpay_list_deposits_request',
				static function ( WooPaymentsDepositsListRequest $request ) use ( &$observed_api, &$observed_page_size ): WooPaymentsDepositsListRequest {
					$observed_api       = $request->get_api();
					$observed_page_size = $request->get_param( 'pagesize' );
					$request->set_page_size( 50 );
					$request->set_date_after( '2026-06-01 00:00:00' );
					$request->set_date_before( '2026-06-18 23:59:59' );
					$request->set_date_between( array( '2026-06-01 00:00:00', '2026-06-18 23:59:59' ) );
					$request->set_status_is( 'failed' );
					$request->set_param( 'status_is_not', 'canceled' );
					$request->set( 'extension_custom_param', 'deposits-custom' );

					return $request;
				},
				10
			);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/deposits' );
		$request->set_query_params(
			array(
				'page'      => '2',
				'pagesize'  => '25',
				'status_is' => 'paid',
			)
		);

		$response = $this->server->dispatch( $request );

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'deposits', $observed_api );
			$this->assertSame( 25, $observed_page_size );
			$this->assertSame(
				array(
					'page'                   => 2,
					'pagesize'               => 50,
					'sort'                   => 'created',
					'direction'              => 'desc',
					'limit'                  => 100,
					'status_is'              => 'failed',
					'date_after'             => '2026-06-01 00:00:00',
					'date_before'            => '2026-06-18 23:59:59',
					'date_between'           => array( '2026-06-01 00:00:00', '2026-06-18 23:59:59' ),
					'status_is_not'          => 'canceled',
					'extension_custom_param' => 'deposits-custom',
				),
				$this->api_client->last_deposits_query
			);
	}

	/**
	 * @testdox Deposits summary forwards only the known filter parameters.
	 */
	public function test_get_deposits_summary_forwards_filter_parameters(): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/deposits/summary' );
		$request->set_query_params(
			array(
				'page'              => '2',
				'pagesize'          => '25',
				'sort'              => 'date',
				'direction'         => 'desc',
				'match'             => 'all',
				'store_currency_is' => 'usd',
				'status_is'         => 'paid',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'match'             => 'all',
				'store_currency_is' => 'usd',
				'status_is'         => 'paid',
			),
			$this->api_client->last_deposits_summary_query
		);
	}

	/**
	 * @testdox A payout resource route forwards its route ID to the platform and answers the platform response.
	 * @dataProvider provide_resource_id_routes
	 *
	 * @param string              $path              Route path.
	 * @param string              $response_property API client property holding the platform response.
	 * @param array<string,mixed> $platform_response Platform response.
	 * @param string              $id_property       API client property recording the forwarded ID.
	 * @param string              $expected_id       Expected forwarded ID.
	 */
	public function test_resource_route_forwards_route_identifier( string $path, string $response_property, array $platform_response, string $id_property, string $expected_id ): void {
		$this->sut->register_routes();
		$this->api_client->{$response_property} = $platform_response;

		$request  = new WP_REST_Request( 'GET', $path );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected_id, $this->api_client->{$id_property} );
		$this->assertSame( $platform_response, $response->get_data() );
	}

	/**
	 * Payout routes that carry a resource ID.
	 *
	 * @return array<string,array{string,string,array<string,mixed>,string,string}>
	 */
	public static function provide_resource_id_routes(): array {
		return array(
			'deposit detail' => array(
				'/wc/v3/payments/deposits/po_test',
				'deposit_response',
				array(
					'id'     => 'po_test',
					'amount' => 1234,
				),
				'last_deposit_id',
				'po_test',
			),
			'export polling' => array(
				'/wc/v3/payments/deposits/download/poexp_test',
				'payouts_export_url_response',
				array(
					'url' => 'https://example.com/export.csv',
				),
				'last_export_id',
				'poexp_test',
			),
		);
	}

	/**
	 * @testdox Deposits export forwards filters, email, and locale.
	 */
	public function test_get_deposits_export_forwards_filters_email_and_locale(): void {
		$this->sut->register_routes();
		$this->api_client->deposits_export_response = array(
			'exported_deposits' => 42,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/deposits/download' );
		$request->set_body_params(
			array(
				'user_email' => 'merchant@example.com',
				'locale'     => 'en_US',
			)
		);
		$request->set_query_params(
			array(
				'store_currency_is' => 'usd',
				'status_is'         => 'paid',
				'page'              => '2',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'store_currency_is' => 'usd',
				'status_is'         => 'paid',
			),
			$this->api_client->last_export_query
		);
		$this->assertSame( 'merchant@example.com', $this->api_client->last_export_user_email );
		$this->assertSame( 'en_US', $this->api_client->last_export_locale );
		$this->assertSame( $this->api_client->deposits_export_response, $response->get_data() );
	}

	/**
	 * @testdox Manual payout forwards type and currency.
	 */
	public function test_manual_deposit_forwards_type_and_currency(): void {
		$this->sut->register_routes();
		$this->api_client->manual_deposit_response = array(
			'id'       => 'po_instant',
			'type'     => 'instant',
			'currency' => 'usd',
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/deposits' );
		$request->set_body_params(
			array(
				'type'     => 'instant',
				'currency' => 'usd',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'type'     => 'instant',
				'currency' => 'usd',
			),
			$this->api_client->last_manual_deposit
		);
		$this->assertSame( $this->api_client->manual_deposit_response, $response->get_data() );
	}

	/**
	 * @testdox Manual payout requires type and currency.
	 */
	public function test_manual_deposit_requires_type_and_currency(): void {
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/deposits' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_missing_manual_deposit_data', $response->get_data()['code'] );
		$this->assertNull( $this->api_client->last_manual_deposit );
	}

	/**
	 * @testdox Manual payout logs a safe audit trail on success.
	 */
	public function test_manual_deposit_logs_success_audit_trail(): void {
		$this->sut->register_routes();
		$logger                                    = RecordingWcLogger::install();
		$this->api_client->manual_deposit_response = array( 'id' => 'po_manual' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/deposits' );
		$request->set_body_params(
			array(
				'type'     => 'instant',
				'currency' => 'usd',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $logger->lines );
		$this->assertSame( 'info', $logger->lines[0][0] );
		$this->assertSame( 'woopayments', $logger->lines[0][2] );
		$this->assertSame( 'instant', $logger->contexts[0]['type'] );
		$this->assertSame( 'usd', $logger->contexts[0]['currency'] );
		$this->assertSame( 'info', $logger->lines[1][0] );
	}

	/**
	 * @testdox Manual payout logs safe upstream failure context.
	 */
	public function test_manual_deposit_logs_failure_audit_trail(): void {
		$this->sut->register_routes();
		$logger = RecordingWcLogger::install();
		// A platform error code is free text to this store; one the logger does not list is logged as unknown_error.
		$this->api_client->exception = new WooPaymentsApiException( 'Ambiguous payout failure.', 'https://pay.example.test/r?key=sk_test_leak123', 504 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/deposits' );
		$request->set_body_params(
			array(
				'type'     => 'instant',
				'currency' => 'usd',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 504, $response->get_status(), 'A 5xx platform status reaches the answer.' );
		$this->assertCount( 2, $logger->lines );
		$this->assertSame( 'error', $logger->lines[1][0] );
		$this->assertSame( 'woopayments', $logger->lines[1][2] );
		$this->assertSame( 'instant', $logger->contexts[1]['type'] );
		$this->assertSame( 'usd', $logger->contexts[1]['currency'] );
		$this->assertSame( 'unknown_error', $logger->contexts[1]['api_code'] );
		$this->assertSame( 504, $logger->contexts[1]['http_status'] );
		$this->assertStringNotContainsString( 'sk_test_leak123', (string) wp_json_encode( array( $logger->lines, $logger->contexts ) ) );
	}

	/**
	 * A 4xx or 5xx platform status reaches the answer. The faked failure is the shape the API client builds from a platform
	 * error body and its HTTP status.
	 *
	 * @testdox A "resource_missing" platform failure with status 404 on a payout answers 404.
	 */
	public function test_api_exceptions_answer_a_4xx_or_5xx_status(): void {
		$this->sut->register_routes();
		$this->api_client->exception = new WooPaymentsApiException( 'Platform failure.', 'resource_missing', 404 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/deposits/po_missing' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'resource_missing', $response->get_data()['code'] );
	}

	/**
	 * A list request filter callback that returns something other than the request stops the request with the API client
	 * path's error, as the extension's request classes end it with a 500 (includes/core/server/class-request.php:528-569, :770-783).
	 *
	 * @testdox A wcpay_list_deposits_request callback returning no request answers 500 wcpay_invalid_filtered_request.
	 */
	public function test_list_filter_returning_no_request_fails_the_request(): void {
		$this->sut->register_routes();
		add_filter( 'wcpay_list_deposits_request', '__return_null' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/deposits' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_filtered_request', $response->get_data()['code'] );
		$this->assertSame( 'Invalid WooPayments request returned by wcpay_list_deposits_request.', $response->get_data()['message'] );
	}

	/**
	 * Create a native deposits REST controller.
	 *
	 * @return WooPaymentsDepositsRestController
	 */
	private function create_controller(): WooPaymentsDepositsRestController {
		$controller = new WooPaymentsDepositsRestController();
		$controller->init( $this->api_client );

		return $controller;
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

	/**
	 * Get expected route methods.
	 *
	 * @return array<string,string[]>
	 */
	private function get_expected_routes(): array {
		return array(
			'/wc/v3/payments/deposits'              => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/deposits/summary'      => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/deposits/overview-all' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/deposits/download'     => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/deposits/download/(?P<export_id>.*)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/deposits/(?P<deposit_id>[\w]+)' => array( WP_REST_Server::READABLE ),
		);
	}
}
