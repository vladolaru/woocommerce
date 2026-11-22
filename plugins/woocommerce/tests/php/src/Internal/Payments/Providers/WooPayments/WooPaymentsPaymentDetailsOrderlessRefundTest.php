<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for charge refunds requested without a WooPayments order ID.
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
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $http_client;

	/**
	 * Controller used by both refund entry points.
	 *
	 * @var WooPaymentsPaymentDetailsRestController
	 */
	private WooPaymentsPaymentDetailsRestController $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->http_client           = new class() extends FakeHttpClient {
			/**
			 * Run a test's observations while the refund request is in progress.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           API path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Request body.
			 * @param int         $timeout        Timeout in seconds.
			 * @param bool        $use_user_token Whether to sign with the user token.
			 * @param bool        $blocking       Whether to wait for the response.
			 * @return mixed
			 */
			public function request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				$response = parent::request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
				return $response instanceof \Closure ? $response() : $response;
			}
		};
		$this->http_client->blog_id  = 4;
		$this->http_client->response = $this->get_recorded_refund_response();

		$api_client = new Client();
		$api_client->init( $this->http_client, $this->create_test_mode_account_service(), wc_get_container()->get( TransportLog::class ) );

		$this->sut = new WooPaymentsPaymentDetailsRestController();
		$this->sut->init( $api_client, $this->create_order_service(), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$this->sut->register_routes();

		$this->login_as_administrator();
	}

	/**
	 * @testdox Refund route refuses users without manage_woocommerce and sends nothing to the platform.
	 */
	public function test_refund_route_requires_manage_woocommerce(): void {
		$this->login_as_role( 'customer' );

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
	 * @testdox Refund with an order of another payment method that $stores refunds the charge at the platform and writes nothing locally.
	 * @testWith ["stores the charge", "ch_3UJZZBBzWlxcwgpP0BZvfjOj"]
	 *           ["stores no charge", ""]
	 *
	 * Claiming the order just because its ID was supplied would lock another gateway's order.
	 * Client 11.3.0 refunds the order through its gateway whatever charge_id says
	 * (includes/admin/class-wc-rest-payments-refunds-controller.php:46-77).
	 *
	 * @param string $stores           What the order stores.
	 * @param string $stored_charge_id The order's stored charge ID.
	 */
	public function test_refund_with_an_order_of_another_payment_method_refunds_the_charge( string $stores, string $stored_charge_id ): void {
		unset( $stores );
		$order = $this->create_charge_order( 'bacs', $stored_charge_id );
		$order->set_currency( 'USD' );
		$order->set_total( '10.99' );
		$order->save();
		$this->http_client->response = function () use ( $order ) {
			$this->assertFalse( $this->is_locked( $order ), 'Only a WooPayments order should be locked.' );
			return $this->get_recorded_refund_response();
		};

		$orders_before = $this->count_local_orders_and_refunds();

		$response = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_3UJZZBBzWlxcwgpP0BZvfjOj',
					'amount'    => 1099,
					'order_id'  => $order->get_id(),
				)
			)
		);
		$body     = json_decode( (string) $this->http_client->last_body, true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertSame( '/sites/4/wcpay/refunds', $this->http_client->last_path );
		$this->assertSame( 'ch_3UJZZBBzWlxcwgpP0BZvfjOj', $body['charge'] ?? null );
		$this->assertSame( 'transaction_details_no_order', $body['metadata']['refund_source'] ?? null );
		$this->assertSame( $orders_before, $this->count_local_orders_and_refunds(), 'No refund row is created.' );
		$this->assertInstanceOf( WC_Order::class, wc_get_order( $order->get_id() ) );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
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
	 * A release only on success would leave the order locked after a platform failure.
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $has_owner Whether the charge belongs to a WooPayments order.
	 */
	public function test_refund_without_order_maps_platform_errors( bool $has_owner ): void {
		$order = $has_owner ? $this->create_charge_order( 'woocommerce_payments', 'ch_3UJZZBBzWlxcwgpP0BZvfjOj' ) : null;

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
		if ( $order ) {
			$this->assertFalse( $this->is_locked( $order ) );
		}
	}

	/**
	 * @testdox A refund cannot send money or release the lock when $holder already holds it.
	 * @testWith ["checkout"]
	 *           ["capture"]
	 *           ["refund"]
	 *
	 * @param string $holder Operation already running.
	 */
	public function test_refund_respects_existing_claim( string $holder ): void {
		$order      = $this->create_charge_order();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $lock->claim( $order, $vocabulary, null, $holder );
		$this->assertNotNull( $token );

		try {
			$response = $this->dispatch_charge_refund();
			$this->assertSame( 409, $response->get_status() );
			$this->assertSame( 'order_payment_refund_locked', $response->get_data()['code'] );
			$this->assertSame( 'A payment operation is already in progress for this order.', $response->get_data()['message'] );
			$this->assertSame( 0, $this->http_client->request_count );
			$this->assertTrue( $this->is_locked( $order ) );
		} finally {
			$lock->release( $order, $vocabulary, $token );
		}
	}

	/**
	 * @testdox A direct refund holds the lock only during the request and leaves refunds, notes and stock alone.
	 */
	public function test_refund_holds_lock_without_changing_order(): void {
		$order   = $this->create_charge_order();
		$product = \WC_Helper_Product::create_simple_product(
			true,
			array(
				'manage_stock'   => true,
				'stock_quantity' => 8,
			)
		);
		$order->add_product( $product, 2 );
		$order->calculate_totals();
		wc_reduce_stock_levels( $order );
		$stock   = ( new \WC_Product_Simple( $product->get_id() ) )->get_stock_quantity();
		$refunds = count( $order->get_refunds() );
		$notes   = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$this->http_client->response = function () use ( $order ) {
			$this->assertTrue( $this->is_locked( $order ) );
			return $this->get_recorded_refund_response();
		};
		$response                    = $this->dispatch_charge_refund();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertFalse( $this->is_locked( $order ) );
		$this->assertCount( $refunds, ( new WC_Order( $order->get_id() ) )->get_refunds() );
		$this->assertCount( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( $stock, ( new \WC_Product_Simple( $product->get_id() ) )->get_stock_quantity() );
	}

	/**
	 * @testdox A refund request that dies after sending would release the order lock at shutdown if the request were unmarked.
	 */
	public function test_shutdown_keeps_the_order_lock_while_the_refund_is_in_flight(): void {
		$order                       = $this->create_charge_order();
		$this->http_client->response = function () use ( $order ) {
			wc_get_container()->get( OrderPaymentLock::class )->release_held_claims();
			$this->assertTrue( $this->is_locked( $order ), 'Shutdown must keep the order locked while its refund awaits an answer.' );
			return $this->get_recorded_refund_response();
		};

		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertFalse( $this->is_locked( $order ) );
	}

	/**
	 * @testdox A thrown error cannot strand the refund lock.
	 */
	public function test_refund_releases_claim_on_thrown_error(): void {
		$order                       = $this->create_charge_order();
		$this->http_client->response = function () use ( $order ) {
			$this->assertTrue( $this->is_locked( $order ) );
			throw new \Error( 'Interrupted refund' );
		};

		try {
			$this->dispatch_charge_refund();
			$this->fail( 'The transport error should propagate.' );
		} catch ( \Error $error ) {
			$this->assertSame( 'Interrupted refund', $error->getMessage() );
		}
		$this->assertFalse( $this->is_locked( $order ) );
	}

	/**
	 * @testdox The shared refund method preserves the Ability source and a null full-refund amount.
	 */
	public function test_full_refund_from_ability(): void {
		$order                       = $this->create_charge_order();
		$this->http_client->response = function () use ( $order ) {
			$this->assertTrue( $this->is_locked( $order ) );
			return $this->get_recorded_refund_response();
		};
		$refund                      = $this->sut->refund_charge_without_order( 'ch_refund', null, null, 'woopayments_ability' );

		if ( is_wp_error( $refund ) ) {
			$this->fail( $refund->get_error_message() );
		}
		$this->assertSame( 're_3UJZZBBzWlxcwgpP0MauAsJ6', $refund['id'] );
		$body = json_decode( (string) $this->http_client->last_body, true );
		$this->assertArrayHasKey( 'amount', $body );
		$this->assertNull( $body['amount'] );
		$this->assertSame( 'woopayments_ability', $body['metadata']['refund_source'] );
		$this->assertFalse( $this->is_locked( $order ) );
	}

	/**
	 * @testdox A shared charge cannot lock an older order or an order of another gateway.
	 * @testWith ["woocommerce_payments", "bacs", false]
	 *           ["bacs", "woocommerce_payments", true]
	 *
	 * @param string $older_gateway Older order's gateway.
	 * @param string $newer_gateway Newer order's gateway.
	 * @param bool   $claim_newer   Expected lock on the newer order.
	 */
	public function test_shared_charge_uses_newest_order( string $older_gateway, string $newer_gateway, bool $claim_newer ): void {
		$older = $this->create_charge_order( $older_gateway );
		$older->set_date_created( time() - HOUR_IN_SECONDS );
		$older->save();
		$newer                       = $this->create_charge_order( $newer_gateway );
		$this->http_client->response = function () use ( $older, $newer, $claim_newer ) {
			$this->assertFalse( $this->is_locked( $older ) );
			$this->assertSame( $claim_newer, $this->is_locked( $newer ) );
			return $this->get_recorded_refund_response();
		};

		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertFalse( $this->is_locked( $newer ) );
	}

	/**
	 * Create an order holding the charge used by the lock tests.
	 *
	 * @param string $gateway   Payment gateway.
	 * @param string $charge_id Stored charge ID.
	 * @return WC_Order
	 */
	private function create_charge_order( string $gateway = 'woocommerce_payments', string $charge_id = 'ch_refund' ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( $gateway );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();
		return $order;
	}

	/**
	 * Check the order payment lock while observing the transport.
	 *
	 * @param WC_Order $order Order to check.
	 * @return bool
	 */
	private function is_locked( WC_Order $order ): bool {
		return wc_get_container()->get( OrderPaymentLock::class )->is_held( $order, new WooPaymentsPersistenceVocabulary() );
	}

	/**
	 * Refund the fixture charge without supplying an order ID.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch_charge_refund(): \WP_REST_Response {
		return $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_refund',
					'amount'    => 1099,
				)
			)
		);
	}

	/**
	 * Build a refund route request with a JSON body, as apiFetch sends it.
	 *
	 * @param array<string,mixed> $params Body params.
	 * @return WP_REST_Request<array>
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
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-refunds.json' ), true );
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
