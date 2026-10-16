<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapitalRestController;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the native WooPayments Capital REST controller.
 */
class WooPaymentsCapitalRestControllerTest extends WC_REST_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCapitalRestController
	 */
	private WooPaymentsCapitalRestController $sut;

	/**
	 * Recording API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = $this->create_api_client();
		$this->sut        = $this->create_controller( true );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_rest_api_get_rest_namespaces', array( $this->sut, 'handle_woocommerce_rest_api_get_rest_namespaces' ) );
		remove_action( 'admin_init', array( $this->sut, 'redirect_loan_offer_request' ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'allowed_redirect_hosts' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'woocommerce_logging_class' );
		unset( $_GET['wcpay-loan-offer'] );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Capital routes are registered under wc/v3 when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_capital_routes_when_builtin_owns_runtime(): void {
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsCapitalRestController::class, $this->sut );
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$this->assertCapitalRoutesRegistered();
		// The loan-offer redirect runs from LegacyAdminLinkHandler, which loads on admin pages.
		$this->assertFalse( has_action( 'admin_init', array( $this->sut, 'redirect_loan_offer_request' ) ) );
	}

	/**
	 * @testdox The $route route requires manage_woocommerce before calling the platform API.
	 * @testWith ["active_loan_summary"]
	 *           ["loan_offer"]
	 *           ["loans"]
	 *
	 * @param string $route Capital route.
	 */
	public function test_routes_require_manage_woocommerce( string $route ): void {
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/' . $route ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( '', $this->api_client->last_call );
	}

	/**
	 * @testdox $_dataName returns the raw platform envelope.
	 * @dataProvider provide_raw_envelope_routes
	 *
	 * @param string              $handler       Controller handler.
	 * @param string              $route         Route path.
	 * @param string              $response_prop API client fake property holding the platform answer.
	 * @param array<string,mixed> $envelope      Platform answer.
	 * @param string              $expected_call Expected API client call.
	 */
	public function test_returns_the_raw_platform_envelope( string $handler, string $route, string $response_prop, array $envelope, string $expected_call ): void {
		$this->api_client->{$response_prop} = $envelope;

		$response = $this->sut->{$handler}( new WP_REST_Request( 'GET', $route ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $expected_call, $this->api_client->last_call );
		$this->assertSame( $envelope, $response->get_data() );
	}

	/**
	 * Provide the routes that pass the platform answer through: handler, route, fake property, answer, expected call.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:array<string,mixed>,4:string}>
	 */
	public function provide_raw_envelope_routes(): array {
		return array(
			'Active loan summary route' => array(
				'get_active_loan_summary',
				'/wc/v3/payments/capital/active_loan_summary',
				'active_loan_summary_response',
				array(
					'loan_id' => 'loan_test',
					'status'  => 'active',
				),
				'get_capital_active_loan_summary',
			),
			'Loans route'               => array(
				'get_loans',
				'/wc/v3/payments/capital/loans',
				'loans_response',
				array(
					'data' => array(
						array(
							'id' => 'loan_test',
						),
					),
				),
				'get_capital_loans',
			),
		);
	}

	/**
	 * @testdox Loan offer route redirects to the returned Capital link URL.
	 */
	public function test_loan_offer_route_redirects_to_returned_capital_link_url(): void {
		$this->api_client->capital_link_response = array(
			'url' => 'https://capital.example.test/view-offer',
		);
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/loan_offer' ) );

		$this->assertSame( 'create_capital_link', $this->api_client->last_call );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( 'https://capital.example.test/view-offer', $response->get_headers()['Location'] );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', rawurldecode( $this->api_client->last_return_url ) );
		$this->assertStringContainsString( 'admin.php?wcpay-loan-offer', $this->api_client->last_refresh_url );
	}

	/**
	 * @testdox The WooPayments extension's loan offer query arg sends any manage_woocommerce user ($role) to a fresh Capital link, with no Capital eligibility check.
	 * @testWith ["administrator"]
	 *           ["shop_manager"]
	 *
	 * @param string $role Current user role.
	 */
	public function test_loan_offer_query_arg_redirects_to_returned_capital_link_url( string $role ): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => $role ) ) );
		$this->api_client->capital_link_response = array(
			'url' => 'https://connect.stripe.com/capital/view-offer',
		);
		$_GET['wcpay-loan-offer']                = '';

		$this->assertSame( 'https://connect.stripe.com/capital/view-offer', $this->run_loan_offer_redirect() );
		$this->assertSame( 'create_capital_link', $this->api_client->last_call );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', rawurldecode( $this->api_client->last_return_url ) );
		$this->assertStringContainsString( 'admin.php?wcpay-loan-offer', $this->api_client->last_refresh_url );
	}

	/**
	 * @testdox The WooPayments extension's loan offer query arg is ignored: $_dataName.
	 * @dataProvider provide_ignored_loan_offer_contexts
	 *
	 * @param string $context Request context: ajax, customer, no query arg or not owner.
	 */
	public function test_loan_offer_query_arg_is_ignored( string $context ): void {
		if ( 'no query arg' === $context ) {
			$this->api_client->capital_link_response = array(
				'url' => 'https://connect.stripe.com/capital/view-offer',
			);
		} else {
			$_GET['wcpay-loan-offer'] = '';
		}
		if ( 'ajax' === $context ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		} elseif ( 'customer' === $context ) {
			wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		} elseif ( 'not owner' === $context ) {
			$this->sut = $this->create_controller( false );
		}

		$this->assertNull( $this->run_loan_offer_redirect() );
		$this->assertSame( '', $this->api_client->last_call );
	}

	/**
	 * Provide the requests the loan offer redirect ignores.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_ignored_loan_offer_contexts(): array {
		return array(
			'during AJAX requests'                         => array( 'ajax' ),
			'for users without manage_woocommerce'         => array( 'customer' ),
			'without the query arg, with no platform call' => array( 'no query arg' ),
			'when the built-in WooPayments does not own the runtime' => array( 'not owner' ),
		);
	}

	/**
	 * @testdox Loan offer link lands on the overview error notice when the platform refuses the Capital link.
	 */
	public function test_loan_offer_query_arg_redirects_to_overview_error_when_platform_fails(): void {
		$this->api_client->exception = new WooPaymentsApiException( 'No active offer.', 'wcpay_capital_offer_unavailable', 400 );
		$_GET['wcpay-loan-offer']    = '';

		$location = rawurldecode( (string) $this->run_loan_offer_redirect() );

		$this->assertSame( 'create_capital_link', $this->api_client->last_call );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $location );
		$this->assertStringContainsString( 'wcpay-loan-offer-error=1', $location );
	}

	/**
	 * @testdox Loan offer route redirects to the overview error notice when $_dataName.
	 * @dataProvider provide_capital_link_failures
	 *
	 * @param WooPaymentsApiException|null $exception     Platform failure, or null.
	 * @param array<string,mixed>          $link_response Capital link answer when the platform answers.
	 */
	public function test_loan_offer_route_redirects_to_overview_error_on_failure( ?WooPaymentsApiException $exception, array $link_response ): void {
		$this->api_client->exception             = $exception;
		$this->api_client->capital_link_response = $link_response;
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/loan_offer' ) );
		$location = rawurldecode( (string) ( $response->get_headers()['Location'] ?? '' ) );

		$this->assertSame( 'create_capital_link', $this->api_client->last_call );
		$this->assertSame( 302, $response->get_status() );
		$this->assertStringContainsString( 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview', $location );
		$this->assertStringContainsString( 'wcpay-loan-offer-error=1', $location );
	}

	/**
	 * Provide the Capital link failures: exception, link answer.
	 *
	 * @return array<string,array{0:WooPaymentsApiException|null,1:array<string,mixed>}>
	 */
	public function provide_capital_link_failures(): array {
		return array(
			'the API request fails'    => array( new WooPaymentsApiException( 'Capital unavailable.', 'capital_unavailable', 503 ), array() ),
			'the response omits a URL' => array( null, array( 'id' => 'link_without_url' ) ),
		);
	}

	/**
	 * @testdox API exceptions preserve the Capital REST error envelope and include the upstream HTTP status.
	 */
	public function test_api_exceptions_preserve_error_envelope_with_status(): void {
		$this->api_client->exception = new WooPaymentsApiException( 'Capital unavailable.', 'capital_unavailable', 503 );

		$response = $this->sut->get_active_loan_summary( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/active_loan_summary' ) );

		$this->assertSame( 'capital_unavailable', $response->get_error_code() );
		$this->assertSame( 'Capital unavailable.', $response->get_error_message() );

		$error_data = $response->get_error_data();
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertSame( 503, $error_data['status'] );
	}

	/**
	 * Faked failures are the shape the API client builds from a platform error body and its HTTP status: an encode failure
	 * carries none (0) and an unreadable body keeps the answer's status, 200 included (wcpay_unparseable_or_null_body).
	 *
	 * @testdox API exceptions with platform status $platform_status fall back to a 500 server error status.
	 * @testWith [0]
	 *           [200]
	 *
	 * @param int $platform_status Platform HTTP status.
	 */
	public function test_api_exceptions_without_http_code_default_to_server_error_status( int $platform_status ): void {
		$this->api_client->exception = new WooPaymentsApiException( 'Capital unavailable.', 'capital_unavailable', $platform_status );

		$response = $this->sut->get_loans( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/loans' ) );

		$error_data = $response->get_error_data();
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertSame( 500, $error_data['status'] );
	}

	/**
	 * @testdox Loan offer route logs the Capital link failure while still redirecting to the overview error notice.
	 */
	public function test_loan_offer_route_logs_capital_link_failures(): void {
		$logger                      = RecordingWcLogger::install();
		$this->api_client->exception = self::make_provider_error();
		$this->sut->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/capital/loan_offer' ) );
		$location = rawurldecode( (string) ( $response->get_headers()['Location'] ?? '' ) );

		$this->assertSame( 302, $response->get_status() );
		$this->assertStringContainsString( 'wcpay-loan-offer-error=1', $location );
		$this->assertCount( 1, $logger->lines );
		// The platform's message stays out of the log; its status and code go in.
		$context = $this->get_logged_context_at_level( $logger, 'error' );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * Run the loan offer redirect and return the intercepted location, or null when it did not redirect.
	 *
	 * @return string|null
	 */
	private function run_loan_offer_redirect(): ?string {
		add_filter( 'allowed_redirect_hosts', array( $this, 'allow_stripe_redirect_host' ) );
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );

		try {
			$this->sut->redirect_loan_offer_request();
		} catch ( \RuntimeException $exception ) {
			return (string) preg_replace( '/^wp_redirect intercepted: /', '', $exception->getMessage() );
		}

		return null;
	}

	/**
	 * Add the Stripe redirect host for redirect tests.
	 *
	 * @param string[] $hosts Allowed hosts.
	 * @return string[]
	 */
	public function allow_stripe_redirect_host( array $hosts ): array {
		$hosts[] = 'connect.stripe.com';

		return $hosts;
	}

	/**
	 * Intercept redirects so production exit paths do not stop the test runner.
	 *
	 * @param string $location Redirect target.
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect( string $location ): void {
		throw new \RuntimeException( 'wp_redirect intercepted: ' . esc_url_raw( $location ) );
	}

	/**
	 * Create a native Capital REST controller.
	 *
	 * @param bool $builtin_owner Whether the built-in WooPayments owns the runtime.
	 * @return WooPaymentsCapitalRestController
	 */
	private function create_controller( bool $builtin_owner ): WooPaymentsCapitalRestController {
		$arbiter = $this->getMockBuilder( WooPaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_builtin_owner' ) )
			->getMock();
		$arbiter->method( 'is_builtin_owner' )->willReturn( $builtin_owner );

		$controller = new WooPaymentsCapitalRestController();
		$controller->init( $arbiter, $this->api_client );

		return $controller;
	}

	/**
	 * Create a recording API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function create_api_client(): WooPaymentsApiClient {
		return new class() extends WooPaymentsApiClient {

			/**
			 * Last called method.
			 *
			 * @var string
			 */
			public string $last_call = '';

			/**
			 * Active loan summary response.
			 *
			 * @var array<string,mixed>
			 */
			public array $active_loan_summary_response = array();

			/**
			 * Loans response.
			 *
			 * @var array<string,mixed>
			 */
			public array $loans_response = array();

			/**
			 * Capital link response.
			 *
			 * @var array<string,mixed>
			 */
			public array $capital_link_response = array();

			/**
			 * Last return URL sent to the API client.
			 *
			 * @var string
			 */
			public string $last_return_url = '';

			/**
			 * Last refresh URL sent to the API client.
			 *
			 * @var string
			 */
			public string $last_refresh_url = '';

			/**
			 * Optional exception thrown by the next call.
			 *
			 * @var WooPaymentsApiException|null
			 */
			public ?WooPaymentsApiException $exception = null;

			/**
			 * Get active loan summary.
			 *
			 * @return array<string,mixed>
			 */
			public function get_capital_active_loan_summary(): array {
				$this->last_call = __FUNCTION__;
				$this->throw_if_configured();

				return $this->active_loan_summary_response;
			}

			/**
			 * Get Capital loans.
			 *
			 * @return array<string,mixed>
			 */
			public function get_capital_loans(): array {
				$this->last_call = __FUNCTION__;
				$this->throw_if_configured();

				return $this->loans_response;
			}

			/**
			 * Create a Capital link.
			 *
			 * @param string $return_url  Return URL.
			 * @param string $refresh_url Refresh URL.
			 * @return array<string,mixed>
			 */
			public function create_capital_link( string $return_url, string $refresh_url ): array {
				$this->last_call        = __FUNCTION__;
				$this->last_return_url  = $return_url;
				$this->last_refresh_url = $refresh_url;
				$this->throw_if_configured();

				return $this->capital_link_response;
			}

			/**
			 * Throw configured exception.
			 *
			 * @throws WooPaymentsApiException When configured.
			 */
			private function throw_if_configured(): void {
				if ( null !== $this->exception ) {
					throw $this->exception;
				}
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

	/**
	 * Assert Capital REST routes are registered.
	 */
	private function assertCapitalRoutesRegistered(): void {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/capital/active_loan_summary', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/capital/loans', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/capital/loan_offer', $routes );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/capital/active_loan_summary'], WP_REST_Server::READABLE );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/capital/loans'], WP_REST_Server::READABLE );
		$this->assertRouteHasMethod( $routes['/wc/v3/payments/capital/loan_offer'], WP_REST_Server::READABLE );
	}
}
