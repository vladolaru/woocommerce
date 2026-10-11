<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeWooPaymentsHttpClient;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the transaction-details refund of a charge that has no WooCommerce order.
 *
 * Expectations come from client 11.1.0: `WC_REST_Payments_Refunds_Controller::process_refund()` and
 * `process_charge_refund()` (includes/admin/class-wc-rest-payments-refunds-controller.php:46-77,107-114),
 * `Refund_Charge` (includes/core/server/request/class-refund-charge.php:20-119) and
 * `WC_Payments_API_Client::request()` (includes/wc-payment-api/class-wc-payments-api-client.php:2634-2711).
 */
class WooPaymentsPaymentDetailsOrderlessRefundTest extends WC_REST_Unit_Test_Case {

	use WooPaymentsMoneyMovementControllerTestTrait;

	/**
	 * Fake platform transport.
	 *
	 * @var FakeWooPaymentsHttpClient
	 */
	private FakeWooPaymentsHttpClient $http_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_client           = new FakeWooPaymentsHttpClient();
		$this->http_client->blog_id  = 4;
		$this->http_client->response = $this->get_recorded_refund_response();

		$api_client = new WooPaymentsApiClient();
		$api_client->init( $this->http_client, $this->create_test_mode_account_service(), wc_get_container()->get( WooPaymentsTransportLog::class ) );

		$sut = new WooPaymentsPaymentDetailsRestController();
		$sut->init( $this->create_arbiter( true ), $api_client, $this->create_order_service() );
		$sut->register_routes();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * @testdox Refund route refuses users without manage_woocommerce and sends nothing to the platform.
	 */
	public function test_refund_route_requires_manage_woocommerce(): void {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );

		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
					'amount'    => 1099,
				)
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->http_client->request_count, 'A refused request must not reach the platform.' );
	}

	/**
	 * @testdox Refund without an order sends the client's charge refund request and returns the platform refund.
	 * @testWith ["requested_by_customer", "requested_by_customer", {"merchant_refund_reason": "requested_by_customer", "refund_source": "transaction_details_no_order"}]
	 *           [null, null, {"refund_source": "transaction_details_no_order"}]
	 *
	 * @param string|null          $reason          Reason the modal sends (null for "Other").
	 * @param string|null          $provider_reason Provider reason enum the client forwards.
	 * @param array<string,string> $metadata        Refund metadata the client sends.
	 */
	public function test_refund_without_order_refunds_the_charge( ?string $reason, ?string $provider_reason, array $metadata ): void {
		$orders_before = $this->count_local_orders_and_refunds();

		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
					'amount'    => 1099,
					'reason'    => $reason,
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 're_3UJZZBBzWlxcwgpP0MauAsJ6', $response->get_data()['id'], 'The route returns the platform refund as the client does.' );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertSame( 'POST', $this->http_client->last_method );
		$this->assertSame( '/sites/4/wcpay/refunds', $this->http_client->last_path );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$this->http_client->last_headers['Idempotency-Key'] ?? '',
			'The client sets no caller key on this path, so the transport mints a fresh UUIDv4.'
		);
		$this->assertEquals(
			array(
				'test_mode' => true,
				'charge'    => 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
				'amount'    => 1099,
				'reason'    => $provider_reason,
				'metadata'  => $metadata,
			),
			json_decode( (string) $this->http_client->last_body, true )
		);
		$this->assertSame( $orders_before, $this->count_local_orders_and_refunds(), 'With no order the client writes nothing locally.' );
	}

	/**
	 * @testdox Refund with an order ID that no longer resolves falls through to the charge refund.
	 */
	public function test_refund_with_unknown_order_refunds_the_charge(): void {
		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'py_3UJZZBBzWlxcwgpP0BZvfjOj',
					'amount'    => 1099,
					'reason'    => 'duplicate',
					'order_id'  => 999999,
				)
			)
		);

		$body = json_decode( (string) $this->http_client->last_body, true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'py_3UJZZBBzWlxcwgpP0BZvfjOj', $body['charge'] ?? null );
		$this->assertSame( 'transaction_details_no_order', $body['metadata']['refund_source'] ?? null );
	}

	/**
	 * @testdox Refund without an order refuses charge IDs and amounts the client's request refuses, before any platform call.
	 * @testWith ["", 1099, "wcpay_core_invalid_request_parameter_stripe_id"]
	 *           [null, 1099, "wcpay_core_invalid_request_parameter_stripe_id"]
	 *           ["pi_3UJZZBBzWlxcwgpP0BZvfjOj", 1099, "wcpay_core_invalid_request_parameter_stripe_id"]
	 *           ["ch_", 1099, "wcpay_core_invalid_request_parameter_stripe_id"]
	 *           ["ch_3UJZZBBzWlxcwgpP0BZvfjOj", 0, "wcpay_refund_invalid_amount"]
	 *           ["ch_3UJZZBBzWlxcwgpP0BZvfjOj", -5, "wcpay_refund_invalid_amount"]
	 *           ["ch_3UJZZBBzWlxcwgpP0BZvfjOj", null, "wcpay_refund_invalid_amount"]
	 *           ["ch_3UJZZBBzWlxcwgpP0BZvfjOj", "abc", "wcpay_refund_invalid_amount"]
	 *
	 * @param mixed  $charge_id     Charge ID sent by the caller.
	 * @param mixed  $amount        Amount sent by the caller.
	 * @param string $expected_code Expected error code.
	 */
	public function test_refund_without_order_rejects_invalid_input( $charge_id, $amount, string $expected_code ): void {
		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => $charge_id,
					'amount'    => $amount,
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $expected_code, $response->get_data()['code'] );
		$this->assertSame( 0, $this->http_client->request_count, 'Invalid input must not reach the platform.' );
	}

	/**
	 * @testdox Refund without an order maps a platform failure to the client's wcpay_refund_payment error.
	 */
	public function test_refund_without_order_maps_platform_errors(): void {
		$this->http_client->response = array(
			'response' => array( 'code' => 400 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode(
				array(
					'error' => array(
						'code'    => 'charge_already_refunded',
						'type'    => 'invalid_request_error',
						'message' => 'Charge ch_3UJZZBBzWlxcwgpP0BZvfjOj has already been refunded.',
					),
				)
			),
		);

		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
					'amount'    => 1099,
				)
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status(), 'The client returns the WP_Error without a status, which the REST server sends as 500.' );
		$this->assertSame( 'wcpay_refund_payment', $data['code'] );
		$this->assertSame( 'Error: Charge ch_3UJZZBBzWlxcwgpP0BZvfjOj has already been refunded.', $data['message'] );
	}

	/**
	 * Build a refund route request with a JSON body, as apiFetch sends it.
	 *
	 * @param array<string,mixed> $params Body params.
	 * @return WP_REST_Request
	 */
	private function create_refund_request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return $request;
	}

	/**
	 * The recorded platform response to a USD card refund (REC-5a R-a, `usd_card_full_refund_free_text_reason`).
	 *
	 * @return array<string,mixed>
	 */
	private function get_recorded_refund_response(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/rec-5a-refunds.json' ), true );
		$entry   = $fixture['entries'][0];
		$this->assertSame( 'usd_card_full_refund_free_text_reason', $entry['pair'] );

		return array(
			'response' => array( 'code' => $entry['response']['http_status'] ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $entry['response']['body'] ),
		);
	}

	/**
	 * Create a test-mode account service stub.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function create_test_mode_account_service(): WooPaymentsAccountService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );

		return $account_service;
	}

	/**
	 * Count local orders and order refunds.
	 *
	 * @return int
	 */
	private function count_local_orders_and_refunds(): int {
		$ids = wc_get_orders(
			array(
				'type'   => array( 'shop_order', 'shop_order_refund' ),
				'status' => 'any',
				'limit'  => -1,
				'return' => 'ids',
			)
		);

		return is_array( $ids ) ? count( $ids ) : 0;
	}
}
