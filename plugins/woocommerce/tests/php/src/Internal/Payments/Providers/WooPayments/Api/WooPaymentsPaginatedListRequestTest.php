<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsDisputesListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsFraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsReportingBalanceSummaryRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransactionsListRequest;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsPaginatedListRequest backward-compatibility behavior.
 */
class WooPaymentsPaginatedListRequestTest extends WC_Unit_Test_Case {

	/**
	 * The original API client registered in the container.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $original_api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_api_client = wc_get_container()->get( WooPaymentsApiClient::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->replace( WooPaymentsApiClient::class, $this->original_api_client );

		parent::tearDown();
	}

	/**
	 * @testdox The filtered request is what the request's own hook returns: the request itself, a changed request or another request.
	 */
	public function test_get_filtered_request_returns_what_the_request_hook_returns(): void {
		$request = new WooPaymentsTransactionsListRequest();
		$seen    = null;
		$filter  = static function ( $filtered ) use ( &$seen ) {
			$seen = $filtered;
			$filtered->set_param( 'match', 'any' );
			return $filtered;
		};
		add_filter( 'wcpay_list_transactions_request', $filter );

		$this->assertSame( $request, $request->get_filtered_request() );
		$this->assertSame( $request, $seen );
		$this->assertSame( 'any', $request->get_param( 'match' ) );

		remove_filter( 'wcpay_list_transactions_request', $filter );
		$replacement = new WooPaymentsDisputesListRequest();
		$replace     = static fn() => $replacement;
		add_filter( 'wcpay_list_transactions_request', $replace );

		$this->assertSame( $replacement, $request->get_filtered_request() );

		remove_filter( 'wcpay_list_transactions_request', $replace );
	}

	/**
	 * @testdox A hook that returns something other than a request stops the request with a 500 that names the hook it was filtered under.
	 */
	public function test_get_filtered_request_refuses_a_non_request(): void {
		$request = new WooPaymentsFraudOutcomeTransactionsListRequest();
		$request->assign_hook( 'wcpay_list_fraud_outcome_transactions_request' );
		add_filter( 'wcpay_list_fraud_outcome_transactions_request', '__return_empty_array' );

		try {
			$request->get_filtered_request();
			$this->fail( 'A request filter that returns no request must stop the request.' );
		} catch ( WooPaymentsApiException $exception ) {
			$this->assertSame( 'wcpay_invalid_filtered_request', $exception->get_error_code() );
			$this->assertSame( 500, $exception->get_http_code() );
			$this->assertSame( 'Invalid WooPayments request returned by wcpay_list_fraud_outcome_transactions_request.', $exception->getMessage() );
		} finally {
			remove_filter( 'wcpay_list_fraud_outcome_transactions_request', '__return_empty_array' );
		}
	}

	/**
	 * @testdox Should expose a callable send() on transactions list requests for filter backward compatibility.
	 */
	public function test_send_is_callable_on_transactions_list_request(): void {
		$request = WooPaymentsTransactionsListRequest::from_rest_request( new WP_REST_Request() );

		$this->assertTrue( method_exists( $request, 'send' ), 'send() must exist on native list-request filter objects.' );
		$this->assertTrue( is_callable( array( $request, 'send' ) ), 'send() must be callable on native list-request filter objects.' );
	}

	/**
	 * @testdox Should expose a callable send() on the reporting balance summary request for filter backward compatibility.
	 */
	public function test_send_is_callable_on_reporting_balance_summary_request(): void {
		$request = WooPaymentsReportingBalanceSummaryRequest::from_params( array() );

		$this->assertTrue( method_exists( $request, 'send' ), 'send() must exist on native list-request filter objects.' );
		$this->assertTrue( is_callable( array( $request, 'send' ) ), 'send() must be callable on native list-request filter objects.' );
	}

	/**
	 * @testdox Should delegate send() to the API client, forwarding the request params, API path, and method.
	 */
	public function test_send_delegates_to_api_client_and_returns_decoded_body(): void {
		$http_client           = new FakeWooPaymentsHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => array( array( 'id' => 'txn_test' ) ) ) ),
		);

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $http_client, $this->create_account_service( false ), wc_get_container()->get( WooPaymentsTransportLog::class ) );
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );

		$rest_request = new WP_REST_Request();
		$rest_request->set_param( 'page', 2 );
		$rest_request->set_param( 'pagesize', 50 );

		$request = WooPaymentsTransactionsListRequest::from_rest_request( $rest_request );

		$result = $request->send();

		$this->assertSame( array( array( 'id' => 'txn_test' ) ), $result['data'], 'send() should return the decoded transport body.' );
		$this->assertSame( 'GET', $http_client->last_method, 'send() should forward the request method to the transport.' );
		$this->assertStringStartsWith( '/sites/123/wcpay/transactions', $http_client->last_path, 'send() should forward the request API path to the transport.' );
		$this->assertStringContainsString( 'page=2', $http_client->last_path, 'send() should forward the request params to the transport.' );
		$this->assertStringContainsString( 'pagesize=50', $http_client->last_path, 'send() should forward the request params to the transport.' );
	}

	/**
	 * Build a WooPayments account service test double.
	 *
	 * @param bool $test_mode Whether WooPayments should run in test mode.
	 * @return WooPaymentsAccountService
	 */
	private function create_account_service( bool $test_mode ): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'is_test_mode_onboarding_enabled' ) )
			->getMock();

		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'is_test_mode_onboarding_enabled' )->willReturn( $test_mode );

		return $account_service;
	}
}
