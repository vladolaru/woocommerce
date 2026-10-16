<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDocumentsRestController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests for the native WooPayments Documents REST controller.
 */
class WooPaymentsDocumentsRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDocumentsRestController
	 */
	private WooPaymentsDocumentsRestController $sut;

	/**
	 * Recording API client.
	 *
	 * @var RecordingDocumentsApiClient
	 */
	private RecordingDocumentsApiClient $api_client;

	/**
	 * Captured Tracks URL.
	 *
	 * @var string
	 */
	private string $captured_tracks_url = '';

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new RecordingDocumentsApiClient();
		$this->sut        = $this->create_controller( true, false );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this->sut, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
		remove_filter( 'rest_pre_serve_request', array( $this->sut, 'serve_raw_document_response' ) );
		remove_all_filters( 'wcpay_list_documents_request' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'wp_doing_ajax' );
		$this->captured_tracks_url = '';
		wp_set_current_user( 0 );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Documents and VAT routes are registered only when the built-in WooPayments owns the runtime and Documents are enabled.
	 */
	public function test_registers_routes_when_builtin_owns_runtime_and_documents_are_enabled(): void {
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsDocumentsRestController::class, $this->sut );
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
	 * @testdox Documents routes are registered when the controller registers while plugins load and the cached account has Documents enabled.
	 */
	public function test_registers_routes_when_registered_while_plugins_load(): void {
		update_option(
			'wcpay_account_data',
			array(
				'data'               => array(
					'account_id'           => 'acct_documents',
					'live_publishable_key' => 'pk_live_documents',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'is_documents_enabled' => true,
				),
				'fetched'            => time(),
				'errored'            => false,
				'consecutive_errors' => 0,
			)
		);
		$platform  = $this->create_platform_api_client();
		$this->sut = $this->create_controller_with_account_service( $this->create_account_service( $platform ) );

		// WordPress defines get_userdata() in pluggable.php only after plugins load, so Jetpack's connection-owner check throws until then.
		$this->register_legacy_proxy_function_mocks(
			array(
				'function_exists' => static fn( string $name ): bool => 'get_userdata' !== $name && function_exists( $name ),
			)
		);
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsDocumentsRestController::class, $this->sut );
		$this->sut->register();
		$this->reset_legacy_proxy_mocks();
		$platform->available = true;
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		foreach ( array_keys( $this->get_expected_routes() ) as $route ) {
			$this->assertArrayHasKey( $route, $routes, 'Client 11.1.0 checks Documents when REST initializes (class-wc-payments.php:2407).' );
		}
	}

	/**
	 * @testdox Documents routes are not registered when the account is not document eligible.
	 */
	public function test_registers_no_routes_when_documents_are_disabled(): void {
		$this->sut = $this->create_controller( false, false );

		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsDocumentsRestController::class, $this->sut );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		foreach ( array_keys( $this->get_expected_routes() ) as $route ) {
			$this->assertArrayNotHasKey( $route, $routes );
		}
	}

	/**
	 * @testdox Every Documents and VAT route refuses visitors and customers before calling the platform API.
	 *
	 * @dataProvider provide_document_route_requests
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
		$this->assertNull( $this->api_client->last_documents_query );
		$this->assertNull( $this->api_client->last_document_id );
		$this->assertNull( $this->api_client->last_documents_summary_query );
		$this->assertNull( $this->api_client->last_vat_number );
		$this->assertNull( $this->api_client->last_saved_vat_details );
	}

	/**
	 * One request per Documents and VAT route, with the required arguments WordPress validates before the permission check.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_document_route_requests(): array {
		return array(
			'list'         => array( 'GET', '/wc/v3/payments/documents' ),
			'summary'      => array( 'GET', '/wc/v3/payments/documents/summary' ),
			'download'     => array( 'GET', '/wc/v3/payments/documents/vat_invoice-123' ),
			'validate VAT' => array( 'GET', '/wc/v3/payments/vat/RO123456' ),
			'save VAT'     => array(
				'POST',
				'/wc/v3/payments/vat',
				array(
					'name'    => 'ACME SRL',
					'address' => '1 Market Street',
				),
			),
		);
	}

	/**
	 * @testdox Documents list forwards only the known platform query parameters.
	 */
	public function test_get_documents_forwards_allow_listed_query_parameters(): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/documents' );
		$request->set_query_params(
			array(
				'page'         => '2',
				'pagesize'     => '50',
				'sort'         => 'date',
				'direction'    => 'desc',
				'match'        => 'all',
				'date_before'  => '2026-06-18',
				'date_after'   => '2026-06-01',
				'date_between' => '2026-06-01...2026-06-18',
				'type_is'      => 'vat_invoice',
				'type_is_not'  => 'statement',
				'ignored'      => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'page'         => '2',
				'pagesize'     => '50',
				'sort'         => 'date',
				'direction'    => 'desc',
				'match'        => 'all',
				'date_before'  => '2026-06-18',
				'date_after'   => '2026-06-01',
				'date_between' => '2026-06-01...2026-06-18',
				'type_is'      => 'vat_invoice',
				'type_is_not'  => 'statement',
			),
			$this->api_client->last_documents_query
		);
		$this->assertSame( $this->api_client->documents_response, $response->get_data() );
	}

	/**
	 * @testdox Documents list leaves the request-object filter to the API client boundary.
	 */
	public function test_get_documents_does_not_apply_the_woopayments_extension_request_filter_in_controller(): void {
		$filter_count = 0;
		add_filter(
			'wcpay_list_documents_request',
			static function ( $request ) use ( &$filter_count ) {
				++$filter_count;

				return $request;
			}
		);
		$this->sut->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/documents' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $filter_count, 'The API client applies the legacy documents filter exactly once.' );
	}

	/**
	 * @testdox Documents summary forwards filters only.
	 */
	public function test_get_documents_summary_forwards_filter_only_parameters(): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/documents/summary' );
		$request->set_query_params(
			array(
				'page'         => '2',
				'pagesize'     => '50',
				'sort'         => 'date',
				'direction'    => 'asc',
				'match'        => 'all',
				'date_before'  => '2026-06-18',
				'date_after'   => '2026-06-01',
				'date_between' => array( '2026-06-01', '2026-06-18' ),
				'type_is'      => 'vat_invoice',
				'type_is_not'  => 'statement',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'match'        => 'all',
				'date_before'  => '2026-06-18',
				'date_after'   => '2026-06-01',
				'date_between' => array( '2026-06-01', '2026-06-18' ),
				'type_is'      => 'vat_invoice',
				'type_is_not'  => 'statement',
			),
			$this->api_client->last_documents_summary_query
		);
	}

	/**
	 * @testdox Invalid document IDs are rejected before calling the platform API.
	 */
	public function test_get_document_rejects_invalid_document_id(): void {
		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/documents/../doc_123' );
		$request->set_param( 'document_id', '../doc_123' );

		$response = $this->sut->get_document( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_route_validation_failure', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertNull( $this->api_client->last_document_id );
	}

	/**
	 * @testdox A document download served by the REST server sends the document bytes, status and headers, not JSON, and is never cacheable.
	 *
	 * The REST server turns a plain WP_HTTP_Response into a new WP_REST_Response (rest_ensure_response(), rest-api.php),
	 * so only a real serve proves the bytes reach the browser. Client 11.1.0 streams them itself (documents controller :113-125)
	 * and calls nocache_headers() before streaming (:114-115), so a site that turns off the REST no-cache headers changes nothing.
	 */
	public function test_get_document_streams_through_the_rest_server(): void {
		$this->api_client->document_response = array(
			'response' => array(
				'code'    => 201,
				'message' => 'Created',
			),
			'headers'  => array(
				'content-type'        => 'application/pdf',
				'content-disposition' => 'attachment; filename="invoice.pdf"',
			),
			'body'     => '%PDF invoice',
		);
		add_filter( 'rest_send_nocache_headers', '__return_false' );
		// WordPress's spy server records headers and buffers the body instead of sending them.
		$GLOBALS['wp_rest_server'] = new \Spy_REST_Server();
		$server                    = $GLOBALS['wp_rest_server'];
		$this->sut->register_routes();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$server->serve_request( '/wc/v3/payments/documents/vat_invoice-123' );

		$this->assertSame( '%PDF invoice', $server->sent_body );
		$this->assertSame( 201, $server->status );
		$this->assertSame( 'application/pdf', $server->sent_headers['Content-Type'] );
		$this->assertSame( 'attachment; filename="invoice.pdf"', $server->sent_headers['Content-Disposition'] );
		$this->assertStringContainsString( 'no-store', $server->sent_headers['Cache-Control'] );
		$this->assertSame( 'vat_invoice-123', $this->api_client->last_document_id );
	}

	/**
	 * @testdox A document the platform cannot serve shows an error page naming the document.
	 *
	 * The merchant opens the download URL in a browser window; client 11.1.0 answers a failure with wp_die() (documents controller :91-98).
	 */
	public function test_get_document_failure_shows_the_document_error_page(): void {
		// The platform's answer for a missing document (wpcom class-documents-controller.php:259-263), with markup a message could carry.
		$this->api_client->exception = new WooPaymentsApiException( 'Document <b>not</b> found.', 'wcpay_document_not_found', 404 );
		$request                     = new WP_REST_Request( 'GET', '/wc/v3/payments/documents/vat_invoice-123' );
		$request->set_param( 'document_id', 'vat_invoice-123' );
		$die = array();
		add_filter(
			'wp_die_handler',
			static function () use ( &$die ) {
				return static function ( $message, $title, $args ) use ( &$die ) {
					$die = array( $message, $args );
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double: the message is asserted, never rendered.
					throw new \WPDieException( (string) $message );
				};
			}
		);

		try {
			$this->sut->get_document( $request );
			$this->fail( 'The failure must end the request with wp_die().' );
		} catch ( \WPDieException $exception ) {
			$this->assertSame( 'There was an error accessing document vat_invoice-123. Document &lt;b&gt;not&lt;/b&gt; found.', $die[0] );
			$this->assertSame( 404, $die[1]['response'] );
		}
	}

	/**
	 * @testdox Malformed raw document responses fail closed before streaming or tracking.
	 */
	public function test_get_document_rejects_raw_responses_without_valid_http_status(): void {
		$this->api_client->document_response = array(
			'response' => array(
				'code'    => 0,
				'message' => '',
			),
			'headers'  => array(),
			'body'     => 'transport failure body',
		);
		$request                             = new WP_REST_Request( 'GET', '/wc/v3/payments/documents/vat_invoice-123' );
		$request->set_param( 'document_id', 'vat_invoice-123' );

		$response = $this->sut->get_document( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_api_error', $response->get_error_code() );
		$this->assertSame( 'Unable to retrieve document.', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertFalse( has_filter( 'rest_pre_serve_request', array( $this->sut, 'serve_raw_document_response' ) ) );
		$this->assertSame( '', $this->captured_tracks_url );
	}

	/**
	 * @testdox Successful document downloads record the WooPayments extension's Tracks event.
	 */
	public function test_get_document_records_tracks_event_after_successful_download(): void {
		if ( ! function_exists( 'wc_admin_record_tracks_event' ) && defined( 'WC_ABSPATH' ) ) {
			require_once WC_ABSPATH . 'includes/react-admin/core-functions.php';
		}
		update_option( 'woocommerce_allow_tracking', 'yes' );
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product' );
		}
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) {
				$this->captured_tracks_url = $url;

				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/documents/vat_invoice-123' );
		$request->set_param( 'document_id', 'vat_invoice-123' );

		$this->sut->get_document( $request );

		$this->assertNotSame( '', $this->captured_tracks_url );
		parse_str( (string) wp_parse_url( $this->captured_tracks_url, PHP_URL_QUERY ), $pixel_args );
		$this->assertSame( 'wcadmin_wcpay_document_downloaded', $pixel_args['_en'] );
		$this->assertSame( 'vat_invoice-123', $pixel_args['document_id'] );
		$this->assertSame( 'live', $pixel_args['mode'] );
	}

	/**
	 * @testdox VAT validation forwards the decoded VAT route parameter.
	 */
	public function test_validate_vat_forwards_vat_number(): void {
		$this->sut->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/vat/RO123456' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'RO123456', $this->api_client->last_vat_number );
		$this->assertSame( $this->api_client->validate_vat_response, $response->get_data() );
	}

	/**
	 * @testdox VAT save requires company name and address.
	 */
	public function test_save_vat_requires_name_and_address(): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/vat' );
		$request->set_body_params( array( 'vat_number' => 'RO123456' ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( $this->api_client->last_saved_vat_details );
	}

	/**
	 * @testdox Saving VAT details forwards the optional VAT number with the company name and address, without markup: $_dataName.
	 *
	 * Client 11.1.0 declares text-field on the VAT number and name, which also flattens line breaks, and textarea-field on
	 * the address, which keeps them (class-wc-rest-payments-vat-controller.php:43-58).
	 *
	 * @dataProvider provide_vat_company_details
	 *
	 * @param array<string,string> $posted    Posted company details.
	 * @param array<string,string> $forwarded Company details the platform receives.
	 */
	public function test_save_vat_forwards_company_details( array $posted, array $forwarded ): void {
		$this->sut->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/vat' );
		$request->set_body_params( $posted );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $forwarded, $this->api_client->last_saved_vat_details );
		$this->assertSame( $this->api_client->save_vat_response, $response->get_data() );
	}

	/**
	 * Provide posted and forwarded VAT company details.
	 *
	 * @return array<string,array{0:array<string,string>,1:array<string,string>}>
	 */
	public function provide_vat_company_details(): array {
		return array(
			'plain details'       => array(
				array(
					'vat_number' => 'RO123456',
					'name'       => 'ACME SRL',
					'address'    => '1 Market Street',
				),
				array(
					'vat_number' => 'RO123456',
					'name'       => 'ACME SRL',
					'address'    => '1 Market Street',
				),
			),
			'details with markup' => array(
				array(
					'vat_number' => "<b>RO123</b>\n456",
					'name'       => "<script>alert(1)</script>ACME\nSRL",
					'address'    => "1 Market Street\n<i>Floor 2</i>",
				),
				array(
					'vat_number' => 'RO123 456',
					'name'       => 'ACME SRL',
					'address'    => "1 Market Street\nFloor 2",
				),
			),
		);
	}

	/**
	 * @testdox API exceptions become REST errors that keep an HTTP error status and answer 500 for any other: $_dataName.
	 * @dataProvider provide_api_exception_statuses
	 *
	 * @param int    $http_code       Exception HTTP code.
	 * @param string $code            Exception error code.
	 * @param string $message         Exception message.
	 * @param int    $expected_status Expected REST error status.
	 */
	public function test_api_exceptions_become_rest_errors( int $http_code, string $code, string $message, int $expected_status ): void {
		$this->api_client->exception = new WooPaymentsApiException( $message, $code, $http_code );

		$response = $this->sut->get_documents( new WP_REST_Request( 'GET', '/wc/v3/payments/documents' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( $code, $response->get_error_code() );
		$this->assertSame( $message, $response->get_error_message() );
		$this->assertSame( $expected_status, $response->get_error_data()['status'] );
	}

	/**
	 * Provide API exceptions: HTTP code, error code, message, expected REST status.
	 *
	 * @return array<string,array{0:int,1:string,2:string,3:int}>
	 */
	public function provide_api_exception_statuses(): array {
		return array(
			'error status 503'       => array( 503, 'provider_unavailable', 'Provider unavailable.', 503 ),
			'no status'              => array( 0, 'wcpay_server_request_error', 'Transport failed.', 500 ),
			'success status 200'     => array( 200, 'wcpay_unparseable_or_null_body', 'Malformed response.', 500 ),
			'redirect status 302'    => array( 302, 'wcpay_unparseable_or_null_body', 'Malformed response.', 500 ),
			'out-of-band status 700' => array( 700, 'wcpay_unparseable_or_null_body', 'Malformed response.', 500 ),
		);
	}

	/**
	 * Create a native Documents REST controller.
	 *
	 * @param bool $documents_enabled      Whether Documents are enabled for the account.
	 * @param bool $test_mode              Whether WooPayments should run in test mode.
	 * @return WooPaymentsDocumentsRestController
	 */
	private function create_controller( bool $documents_enabled, bool $test_mode ): WooPaymentsDocumentsRestController {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_documents_enabled' ) )
			->getMock();
		$account_service->method( 'is_documents_enabled' )->willReturn( $documents_enabled );
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );

		$controller = new WooPaymentsDocumentsRestController();
		$controller->init( $this->api_client, $account_service );

		return $controller;
	}

	/**
	 * Create a controller that native registers, backed by the given account service.
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @return WooPaymentsDocumentsRestController
	 */
	private function create_controller_with_account_service( WooPaymentsAccountService $account_service ): WooPaymentsDocumentsRestController {
		$controller = new WooPaymentsDocumentsRestController();
		$controller->init( $this->api_client, $account_service );

		return $controller;
	}

	/**
	 * Create an account service that reads the account cache through the given platform client.
	 *
	 * @param WooPaymentsApiClient $platform Platform API client.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( WooPaymentsApiClient $platform ): WooPaymentsAccountService {
		$account_service = new class( $platform ) extends WooPaymentsAccountService {
			/**
			 * Platform API client.
			 *
			 * @var WooPaymentsApiClient
			 */
			private WooPaymentsApiClient $platform;

			/**
			 * Constructor.
			 *
			 * @param WooPaymentsApiClient $platform Platform API client.
			 */
			public function __construct( WooPaymentsApiClient $platform ) {
				$this->platform = $platform;
			}

			/**
			 * Get the platform API client.
			 *
			 * @return WooPaymentsApiClient|null
			 */
			protected function get_api_client(): ?WooPaymentsApiClient {
				return $this->platform;
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( wc_get_container()->get( LegacyProxy::class ) );
		$account_service->init( wc_get_container()->get( LegacyProxy::class ), $database_cache );

		return $account_service;
	}

	/**
	 * Create a platform API client whose connection starts unavailable.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_platform_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {
			/**
			 * Whether the platform connection reads as available.
			 *
			 * @var bool
			 */
			public bool $available = false;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Tell whether the platform connection reads as available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
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
		$methods = array_map( 'trim', explode( ',', $method ) );

		foreach ( $route_handlers as $handler ) {
			$route_methods = isset( $handler['methods'] ) && is_array( $handler['methods'] ) ? $handler['methods'] : array();
			$missing       = array_filter(
				$methods,
				static function ( string $method_name ) use ( $route_methods ): bool {
					return ! isset( $route_methods[ $method_name ] ) || true !== $route_methods[ $method_name ];
				}
			);

			if ( empty( $missing ) ) {
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
			'/wc/v3/payments/documents'         => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/documents/summary' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/documents/(?P<document_id>[\w-]+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/vat/(?P<vat_number>[\w\.\%]+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/vat'               => array( WP_REST_Server::EDITABLE ),
		);
	}
}
