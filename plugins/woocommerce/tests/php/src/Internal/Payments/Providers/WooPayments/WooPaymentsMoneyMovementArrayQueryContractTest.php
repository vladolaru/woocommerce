<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsRestController;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * JS-to-PHP boundary contract for money movement array filters.
 *
 * Replays the request paths the admin data helpers build (`money-movement-array-queries.json`, written by
 * `client/admin/client/woopayments/admin/test/money-movement-array-query-contract.test.ts`) through PHP query
 * parsing and the REST controllers, and asserts every array value reaches the platform request. Repeated bare
 * keys (`search=a&search=b`) collapse to the last value in PHP; the client's `addQueryArgs` sends `search[]`.
 */
class WooPaymentsMoneyMovementArrayQueryContractTest extends WC_REST_Unit_Test_Case {

	use WooPaymentsMoneyMovementControllerTestTrait;

	/**
	 * Recording API client.
	 *
	 * @var RecordingMoneyMovementApiClient
	 */
	private RecordingMoneyMovementApiClient $api_client;

	/**
	 * Recorded request fixture.
	 *
	 * @var array{filters:array<string,array<int,string>>,requests:array<string,string>}
	 */
	private array $fixture;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new RecordingMoneyMovementApiClient();
		$this->fixture    = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/money-movement-array-queries.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @testdox Settled transaction array filters reach the platform request whole.
	 */
	public function test_transactions_array_filters_reach_the_platform(): void {
		$controller = new WooPaymentsTransactionsRestController();
		$controller->init( $this->create_arbiter( true ), $this->api_client, $this->create_order_service() );
		$controller->register_routes();

		$this->dispatch_fixture_request( 'transactions' );

		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );
		$this->assertSame( $this->fixture['filters']['search'], $this->api_client->last_call['query']['search'] );
		$this->assertCount( 2, (array) $this->api_client->last_call['query']['date_between'] );
	}

	/**
	 * @testdox Blocked and review search terms reach the platform request whole.
	 */
	public function test_fraud_outcome_array_filters_reach_the_platform(): void {
		$controller = new WooPaymentsTransactionsRestController();
		$controller->init( $this->create_arbiter( true ), $this->api_client, $this->create_order_service() );
		$controller->register_routes();

		$this->dispatch_fixture_request( 'fraud_outcomes' );

		$this->assertSame( 'get_fraud_outcomes', $this->api_client->last_call['method'] );
		$this->assertSame( $this->fixture['filters']['search'], $this->api_client->last_call['query']['search'] );
	}

	/**
	 * @testdox Dispute array filters reach the platform request whole.
	 */
	public function test_dispute_array_filters_reach_the_platform(): void {
		$controller = new WooPaymentsDisputesRestController();
		$controller->init( $this->create_arbiter( true ), $this->api_client, $this->create_order_service(), new WooPaymentsDisputeCacheService() );
		$controller->register_routes();

		$this->dispatch_fixture_request( 'disputes' );

		$this->assertSame( 'get_disputes', $this->api_client->last_call['method'] );
		$filters = $this->api_client->last_call['filters'];
		$this->assertSame( $this->fixture['filters']['search'], $filters['search'] );
		$this->assertSame( $this->fixture['filters']['status_is'], $filters['status_is'] );
		$this->assertSame( $this->fixture['filters']['date_between'], $filters['created_between'] );
	}

	/**
	 * @testdox Uncaptured authorization array filters reach the platform request whole.
	 */
	public function test_authorization_array_filters_reach_the_platform(): void {
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $this->create_arbiter( true ), $this->api_client, new PaymentProcessingService(), new WooPaymentsProvider() );
		$controller->register_routes();

		$this->dispatch_fixture_request( 'authorizations' );

		$this->assertSame( 'get_authorizations', $this->api_client->last_call['method'] );
		$this->assertSame( $this->fixture['filters']['search'], $this->api_client->last_call['query']['search'] );
		$this->assertSame( $this->fixture['filters']['date_between'], $this->api_client->last_call['query']['date_between'] );
	}

	/**
	 * Dispatch one recorded path, parsing its query string the way PHP parses a real request.
	 *
	 * @param string $name Fixture request name.
	 */
	private function dispatch_fixture_request( string $name ): void {
		$url   = wp_parse_url( $this->fixture['requests'][ $name ] );
		$query = array();
		wp_parse_str( (string) ( $url['query'] ?? '' ), $query );

		$request = new WP_REST_Request( 'GET', (string) $url['path'] );
		$request->set_query_params( $query );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
	}
}
