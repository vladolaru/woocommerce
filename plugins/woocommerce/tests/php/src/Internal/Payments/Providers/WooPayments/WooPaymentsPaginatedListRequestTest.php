<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportingBalanceSummaryRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
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
		$api_client->init( $http_client, $this->create_account_service( false ) );
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
