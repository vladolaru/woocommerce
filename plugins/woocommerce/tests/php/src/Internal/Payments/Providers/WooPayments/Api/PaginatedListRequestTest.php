<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\DisputesListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\FraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransactionsListRequest;
use WC_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the PaginatedListRequest backward-compatibility behavior.
 */
class PaginatedListRequestTest extends WC_Unit_Test_Case {

	/**
	 * The original API client registered in the container.
	 *
	 * @var Client
	 */
	private Client $original_api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_api_client = wc_get_container()->get( Client::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->replace( Client::class, $this->original_api_client );

		parent::tearDown();
	}

	/**
	 * @testdox The filtered request is what the request's own hook returns: the request itself, a changed request or another request.
	 */
	public function test_get_filtered_request_returns_what_the_request_hook_returns(): void {
		$request = new TransactionsListRequest();
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
		$replacement = new DisputesListRequest();
		$replace     = static fn() => $replacement;
		add_filter( 'wcpay_list_transactions_request', $replace );

		$this->assertSame( $replacement, $request->get_filtered_request() );

		remove_filter( 'wcpay_list_transactions_request', $replace );
	}

	/**
	 * @testdox A hook that returns something other than a request stops the request with a 500 that names the hook it was filtered under.
	 */
	public function test_get_filtered_request_refuses_a_non_request(): void {
		$request = new FraudOutcomeTransactionsListRequest();
		$request->assign_hook( 'wcpay_list_fraud_outcome_transactions_request' );
		add_filter( 'wcpay_list_fraud_outcome_transactions_request', '__return_empty_array' );

		try {
			$request->get_filtered_request();
			$this->fail( 'A request filter that returns no request must stop the request.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'wcpay_invalid_filtered_request', $exception->get_error_code() );
			$this->assertSame( 500, $exception->get_http_code() );
			$this->assertSame( 'Invalid WooPayments request returned by wcpay_list_fraud_outcome_transactions_request.', $exception->getMessage() );
		} finally {
			remove_filter( 'wcpay_list_fraud_outcome_transactions_request', '__return_empty_array' );
		}
	}

	/**
	 * @testdox Should delegate send() to the API client, forwarding the request params, API path, and method.
	 */
	public function test_send_delegates_to_api_client_and_returns_decoded_body(): void {
		$http_client           = new FakeHttpClient();
		$http_client->blog_id  = 123;
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => array( array( 'id' => 'txn_test' ) ) ) ),
		);

		$api_client = new Client();
		$api_client->init( $http_client, $this->create_account_service( false ), wc_get_container()->get( TransportLog::class ) );
		wc_get_container()->replace( Client::class, $api_client );

		$rest_request = new WP_REST_Request();
		$rest_request->set_param( 'page', 2 );
		$rest_request->set_param( 'pagesize', 50 );

		$request = TransactionsListRequest::from_rest_request( $rest_request );

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
