<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFrontendCurrenciesController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundAmbiguityService;
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

	/** @var array Original gateway registry. */
	private array $original_gateways;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_gateways                     = WC()->payment_gateways()->payment_gateways;
		$gateway                                     = new WooPaymentsGateway();
		WC()->payment_gateways()->payment_gateways[] = $gateway;

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

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Restore the site's gateway registry.
	 */
	public function tearDown(): void {
		WC()->payment_gateways()->payment_gateways = $this->original_gateways;
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
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
	 * @testdox A direct charge refund sends each caller's digest in both the header and refund metadata.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $has_holder Whether a WooPayments order holds the charge.
	 */
	public function test_caller_key_reaches_both_direct_refund_sends( bool $has_holder ): void {
		$order = $has_holder ? $this->create_charge_order() : null;
		if ( $order ) {
			$held_at_send                = array();
			$this->http_client->response = function () use ( $order, &$held_at_send ) {
				$held_at_send[] = $this->is_locked( $order );
				return $this->get_recorded_refund_response();
			};
		}

		foreach ( array( 'K1', 'K2', '0' ) as $key ) {
			$response = $this->dispatch_keyed_refund( $key );
			$this->assertSame( 200, $response->get_status() );
			$expected = 'wc-refund-' . hash( 'sha256', $key );
			$body     = json_decode( (string) $this->http_client->last_body, true );
			$this->assertSame( $expected, $this->http_client->last_headers['Idempotency-Key'] );
			$this->assertSame( $expected, $body['metadata']['refund_attempt'] );
		}

		$this->assertSame( 3, $this->http_client->request_count );
		if ( $order ) {
			$this->assertSame( array( true, true, true ), $held_at_send );
			$this->assertFalse( $this->is_locked( $order ) );
		}
	}

	/**
	 * @testdox Two direct refunds with an empty key get fresh UUIDs and omit the refund attempt metadata.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $has_holder Whether a WooPayments order holds the charge.
	 */
	public function test_empty_key_keeps_direct_refunds_independent( bool $has_holder ): void {
		if ( $has_holder ) {
			$this->create_charge_order();
		}
		$first  = $this->dispatch_keyed_refund( '' );
		$second = $this->dispatch_keyed_refund( '' );
		$this->assertSame( array( 200, 200 ), array( $first->get_status(), $second->get_status() ) );

		$this->assertCount( 2, $this->http_client->requests );
		$keys = array();
		foreach ( $this->http_client->requests as $request ) {
			$key    = $request['headers']['Idempotency-Key'];
			$keys[] = $key;
			$this->assertTrue( wp_is_uuid( $key, 4 ), $key );
			$body = json_decode( $request['body'], true );
			$this->assertArrayNotHasKey( 'refund_attempt', $body['metadata'] );
		}
		$this->assertNotSame( $keys[0], $keys[1] );
	}

	/**
	 * @testdox A refund through the order's gateway uses the gateway's private attempt key and links its refund row.
	 */
	public function test_order_refund_keeps_the_gateway_attempt_key(): void {
		$this->reset_container_resolutions();
		$order      = $this->create_charge_order();
		$api_client = wc_get_container()->get( Client::class );
		$api_client->init( $this->http_client, $this->create_test_mode_account_service(), wc_get_container()->get( TransportLog::class ) );
		$lock_key                    = ( new WooPaymentsPersistenceVocabulary() )->get_order_lock_key( $order );
		$attempt_at_send             = null;
		$this->http_client->response = function () use ( $lock_key, $order, &$attempt_at_send ) {
			$attempt_at_send = get_transient( $lock_key );
			return $this->refund_response( $this->refund_answer( $order, 200 ) );
		};

		$response = $this->dispatch_keyed_refund( 'merchant-refund-1', 200, $order );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->assertSame( $order->get_id(), $response->get_data()['order_id'] );
		$this->assertSame( 1, $this->http_client->request_count );
		$body = json_decode( (string) $this->http_client->last_body, true );
		$key  = $this->http_client->last_headers['Idempotency-Key'];
		$this->assertSame( 'woocommerce_core', $body['metadata']['refund_source'] );
		$this->assertTrue( wp_is_uuid( $key, 4 ), $key );
		$this->assertNotSame( 'wc-refund-' . hash( 'sha256', 'merchant-refund-1' ), $key );
		$this->assertSame( $attempt_at_send, $key );
		$this->assertSame( $key, $body['metadata']['refund_attempt'] );
		$refund = wc_get_order( $response->get_data()['id'] );
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( 're_answer', $refund->get_meta( '_wcpay_refund_id' ) );
	}

	/**
	 * @testdox The direct refund route preserves the platform error text when the platform only reports a bad request.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $concurrent Model a concurrent request using the platform's generic error envelope.
	 */
	public function test_keyed_refund_preserves_generic_platform_error( bool $concurrent ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/Fixtures/platform-refund-errors.json' ), true );
		$pair    = array_column( $fixture['entries'], null, 'pair' )['reused_key_different_amount'];
		$error   = $pair['response'];
		if ( $concurrent ) {
			$error['body']['message'] = 'There is currently another request using this Idempotency Key. Please try again later.';
		}
		$this->http_client->response = array(
			'response' => array( 'code' => $error['http_status'] ),
			'headers'  => array( 'content-type' => $error['content_type'] ),
			'body'     => $concurrent ? wp_json_encode( $error['body'] ) : $error['raw_body'],
		);
		$before                      = $this->count_local_orders_and_refunds();
		$refund_actions              = did_action( 'woocommerce_create_refund' );

		$response = $this->dispatch_keyed_refund( 'K1' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_refund_payment', $response->get_data()['code'] );
		$this->assertSame( 'Error: ' . $error['body']['message'], $response->get_data()['message'] );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertSame( $before, $this->count_local_orders_and_refunds() );
		$this->assertSame( $refund_actions, did_action( 'woocommerce_create_refund' ) );
	}

	/**
	 * @testdox REST and the refund Ability share a platform key but identify their own source.
	 */
	public function test_shared_charge_refund_key_is_independent_of_source(): void {
		foreach ( array( 'transaction_details_no_order', 'woopayments_ability' ) as $source ) {
			$result = $this->sut->refund_charge_without_order( 'ch_refund', 200, null, $source, 'K1' );
			$this->assertIsArray( $result );
			$expected = 'wc-refund-' . hash( 'sha256', 'K1' );
			$body     = json_decode( (string) $this->http_client->last_body, true );
			$this->assertSame( $expected, $this->http_client->last_headers['Idempotency-Key'] );
			$this->assertSame( $expected, $body['metadata']['refund_attempt'] );
			$this->assertSame( $source, $body['metadata']['refund_source'] );
		}
		$this->assertSame( 2, $this->http_client->request_count );
	}

	/**
	 * @testdox A replayed partial refund returns the same ID and creates only one refund on the order.
	 */
	public function test_replayed_keyed_partial_refund_records_one_row(): void {
		$order                       = $this->create_charge_order();
		$this->http_client->response = $this->refund_response( $this->refund_answer( $order, 200 ) );
		foreach ( array( 1, 2 ) as $attempt ) {
			$response = $this->dispatch_keyed_refund( 'K1' );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 're_answer', $response->get_data()['id'] );
			$this->assertSame( $attempt, $this->http_client->request_count );
		}
		$this->assertSame( $this->http_client->requests[0]['headers']['Idempotency-Key'], $this->http_client->requests[1]['headers']['Idempotency-Key'] );
		$rows = $order->get_refunds();
		$this->assertCount( 1, $rows );
		$this->assertSame( 're_answer', $rows[0]->get_meta( '_wcpay_refund_id' ) );
		$this->assertSame( 2.0, (float) $rows[0]->get_amount() );
	}

	/**
	 * @testdox A recorded full refund exhausts the order balance before the caller can send the same key again.
	 */
	public function test_repeated_keyed_full_refund_is_refused_before_send(): void {
		$order                       = $this->create_charge_order();
		$this->http_client->response = $this->refund_response( $this->refund_answer( $order ) );
		$this->assertSame( 200, $this->dispatch_keyed_refund( 'K1', 1099 )->get_status() );

		$response = $this->dispatch_keyed_refund( 'K1', 1099 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_refund_invalid_amount', $response->get_data()['code'] );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertCount( 1, $order->get_refunds() );
	}

	/**
	 * @testdox Both refund branches reject every non-string JSON key before the order lock or any refund write.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $with_order_id Whether to request a gateway refund by order ID.
	 */
	public function test_non_string_refund_keys_are_refused_before_claim( bool $with_order_id ): void {
		$order      = $this->create_charge_order();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = $lock->claim( $order, $vocabulary, null, 'checkout' );
		$this->assertNotNull( $token );
		$before = did_action( 'woocommerce_create_refund' );
		try {
			foreach ( array( null, 5, 1.25, true, false, array( 'key' ), (object) array( 'key' => 'value' ) ) as $invalid ) {
				$response = $this->dispatch_keyed_refund( $invalid, 200, $with_order_id ? $order : null );
				$data     = $response->get_data();
				$this->assertSame( 400, $response->get_status(), (string) wp_json_encode( $invalid ) );
				$this->assertSame( 'rest_invalid_param', $data['code'] );
				$this->assertStringContainsString( 'string', $data['data']['params']['idempotency_key'] );
			}
			$this->assertSame( 0, $this->http_client->request_count );
			$this->assertSame( $before, did_action( 'woocommerce_create_refund' ) );
			$this->assertCount( 0, $order->get_refunds() );
			$this->assertTrue( $this->is_locked( $order ) );
		} finally {
			$lock->release( $order, $vocabulary, $token );
		}
	}

	/**
	 * Dispatch a JSON refund with the caller's exact key value, including explicit null.
	 *
	 * @param mixed         $key    JSON key value.
	 * @param int           $amount Refund amount in minor units.
	 * @param WC_Order|null $order  Order selecting the gateway refund branch.
	 * @return \WP_REST_Response
	 */
	private function dispatch_keyed_refund( $key, int $amount = 200, ?WC_Order $order = null ): \WP_REST_Response {
		$params = array(
			'charge_id'       => 'ch_refund',
			'amount'          => $amount,
			'idempotency_key' => $key,
		);
		if ( $order ) {
			$params['order_id'] = $order->get_id();
		}
		return $this->server->dispatch( $this->create_refund_request( $params ) );
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
			$logger   = RecordingWcLogger::install();
			$response = $this->dispatch_charge_refund();
			$this->assertCount( 1, array_filter( $logger->contexts, static fn( $context ) => 'refund' === ( $context['refused_operation'] ?? null ) ) );
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
		$refund = $this->sut->refund_charge_without_order( 'ch_refund', null, null, 'woopayments_ability', '' );

		if ( is_wp_error( $refund ) ) {
			$this->fail( $refund->get_error_message() );
		}
		$this->assertSame( 're_3UJZZBBzWlxcwgpP0MauAsJ6', $refund['id'] );
		$body = json_decode( (string) $this->http_client->last_body, true );
		$this->assertArrayHasKey( 'amount', $body );
		$this->assertNull( $body['amount'] );
		$this->assertSame( 'woopayments_ability', $body['metadata']['refund_source'] );
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
	 * Build the platform answer with the charge that the event will carry.
	 *
	 * @param WC_Order $order        Charge holder.
	 * @param int      $amount       Refund in minor units.
	 * @param string   $status       Provider status.
	 * @param int      $charge_total Original charge in minor units.
	 * @param string   $currency     Charge and refund currency.
	 * @return array
	 */
	private function refund_answer( WC_Order $order, int $amount = 1099, string $status = 'succeeded', int $charge_total = 1099, string $currency = 'usd' ): array {
		$refund = array(
			'id'       => 're_answer',
			'status'   => $status,
			'amount'   => $amount,
			'currency' => $currency,
			'charge'   => 'ch_refund',
		);
		$charge = array(
			'id'       => 'ch_refund',
			'status'   => 'succeeded',
			'captured' => true,
			'amount'   => $charge_total,
			'currency' => $currency,
			'refunds'  => array( 'data' => array( $refund ) ),
			'metadata' => array(
				'order_key' => $order->get_order_key(),
				'order_id'  => $order->get_id(),
			),
		);
		return $refund + array( 'payment_intent' => array( 'charges' => array( 'data' => array( $charge ) ) ) );
	}

	/**
	 * Serve the specified refund through the fake transport.
	 *
	 * @param array $answer Platform refund.
	 * @return array
	 */
	private function refund_response( array $answer ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $answer ),
		);
	}

	/**
	 * @testdox An in-person Interac payment goes through the online refund endpoint and records the accepted answer.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $accepted Whether the platform accepts the online refund.
	 */
	public function test_online_refund_of_an_interac_payment( bool $accepted ): void {
		$order = $this->create_charge_order();
		$order->update_meta_data( '_wcpay_payment_method_details', wp_json_encode( array( 'type' => 'interac_present' ) ) );
		$order->save();
		$this->http_client->response = $this->refund_response( $this->refund_answer( $order ) );
		if ( ! $accepted ) {
			$this->http_client->response['response']['code'] = 400;
			$this->http_client->response['body']             = wp_json_encode( array( 'error' => array( 'message' => 'Refund declined.' ) ) );
		}

		$this->assertSame( $accepted ? 200 : 500, $this->dispatch_charge_refund()->get_status() );
		$this->assertSame( 1, $this->http_client->request_count );
		$this->assertCount( $accepted ? 1 : 0, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox Repeating a keyless partial refund records both answers until the order has no balance left.
	 */
	public function test_repeated_partial_refund_within_the_balance(): void {
		$order = $this->create_charge_order();
		$order->set_total( '4.00' );
		$order->save();
		$answer = $this->refund_answer( $order, 200 );
		$answer['payment_intent']['charges']['data'][0]['amount'] = 400;
		foreach ( array( 're_first', 're_second' ) as $id ) {
			$answer['id'] = $id;
			$answer['payment_intent']['charges']['data'][0]['refunds']['data'][0]['id'] = $id;
			$this->http_client->response = $this->refund_response( $answer );
			$request                     = $this->create_refund_request(
				array(
					'charge_id' => 'ch_refund',
					'amount'    => 200,
				)
			);
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		}
		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
		$this->assertSame( 2, $this->http_client->request_count );
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 2, $order->get_refunds() );
		$this->assertSame( 4.0, (float) $order->get_total_refunded() );
	}

	/**
	 * @testdox The refund answer is classified by the merchant path and a creation replay preserves the row and its note.
	 * @testWith ["succeeded", "successful"]
	 *           ["pending", "pending"]
	 *           ["requires_action", "pending"]
	 *           ["", "successful"]
	 *           ["failed", ""]
	 *           ["canceled", ""]
	 *
	 * @param string $status   Provider answer status.
	 * @param string $expected Expected stored status.
	 */
	public function test_records_the_answer_before_returning( string $status, string $expected ): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order, 1099, $status );
		$this->http_client->response = $this->refund_response( $answer );
		$response                    = $this->dispatch_charge_refund();
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $answer, $response->get_data() );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( $expected, $order->get_meta( '_wcpay_refund_status' ) );
		$this->assertCount( '' === $expected ? 0 : 1, $order->get_refunds() );
		if ( '' !== $expected ) {
			$this->assertSame( 're_answer', $order->get_refunds()[0]->get_meta( '_wcpay_refund_id' ) );
			$this->assertSame( 'refunded', $order->get_status() );
			$before = wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' );
			wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $answer['payment_intent']['charges']['data'][0] );
			$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
			$this->assertSame( $before, wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'id' ) );
		}
	}

	/**
	 * @testdox Refusing a held charge sends nothing and leaves the earlier attempt unchanged.
	 * @testWith ["uncaptured", "wcpay_refund_uncaptured_payment", 409]
	 *           ["amount", "wcpay_refund_invalid_amount", 400]
	 *           ["missing", "wcpay_refund_amount_required", 400]
	 *           ["unsupported", "order_payment_refund_missing_charge", 409]
	 *           ["currency", "wcpay_refund_currency_mismatch", 409]
	 *           ["unsettled", "wcpay_order_refund_unresolved", 409]
	 *           ["linked", "wcpay_order_refund_unresolved", 409]
	 *           ["expired", "wcpay_order_refund_unresolved", 409]
	 *
	 * @param string $condition Refusal.
	 * @param string $code      Error code.
	 * @param int    $status    HTTP status.
	 */
	public function test_local_refusals( string $condition, string $code, int $status ): void {
		$order  = $this->create_charge_order();
		$amount = 1099;
		$record = '';
		switch ( $condition ) {
			case 'missing':
				$amount = null;
				break;
			case 'amount':
				++$amount;
				break;
			case 'uncaptured':
				$order->update_meta_data( '_intention_status', 'requires_capture' );
				break;
			case 'currency':
				$order->update_meta_data( '_wcpay_intent_currency', 'jpy' );
				break;
			case 'unsupported':
				wc_get_payment_gateway_by_order( $order )->supports = array();
				break;
			default:
				$record = array(
					'order_id'        => $order->get_id(),
					'charge_id'       => 'ch_refund',
					'key'             => 'merchant-key',
					'request'         => array( 'charge' => 'ch_refund' ),
					'sent'            => true,
					'found_refund_id' => 'linked' === $condition ? 're_earlier' : '',
					'failed_at'       => 'expired' === $condition ? 1 : time(),
				);
				$order->update_meta_data( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, $record );
		}
		$order->save_meta_data();
		$result = $this->sut->refund_charge_without_order( 'ch_refund', $amount, null, 'transaction_details_no_order', '' );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertFalse( $this->is_locked( $order ) );
		$this->assertSame( $record, wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META ) );
	}

	/**
	 * @testdox The locked amount check sees another request's partial refund after the reader cached the original balance.
	 */
	public function test_cached_remaining_amount_is_read_under_the_lock(): void {
		$order = $this->create_charge_order();
		$order->set_total( '10.00' );
		$order->save();
		$lock = wc_get_container()->get( OrderPaymentLock::class );
		$spy  = $this->createPartialMock( OrderPaymentLock::class, array( 'claim', 'mark_money_request_unsaved', 'release' ) );
		$spy->method( 'claim' )->willReturnCallback(
			function ( $loaded, $vocabulary, $value, $operation ) use ( $lock ) {
				global $wp_object_cache;
				$this->assertSame( 10.0, (float) $loaded->get_remaining_refund_amount() );
				$reader_cache    = $wp_object_cache;
				$wp_object_cache = clone $reader_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate the other request's cache.
				try {
					$this->assertInstanceOf(
						\WC_Order_Refund::class,
						wc_create_refund(
							array(
								'order_id' => $loaded->get_id(),
								'amount'   => 3,
							)
						)
					);
				} finally {
					$wp_object_cache = $reader_cache; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the reader.
				}
				$this->assertSame( 10.0, (float) $loaded->get_remaining_refund_amount() );
				return $lock->claim( $loaded, $vocabulary, $value, $operation );
			}
		);
		$spy->method( 'mark_money_request_unsaved' )->willReturnCallback( array( $lock, 'mark_money_request_unsaved' ) );
		$spy->method( 'release' )->willReturnCallback( array( $lock, 'release' ) );
		wc_get_container()->replace( OrderPaymentLock::class, $spy );
		$result = $this->server->dispatch(
			$this->create_refund_request(
				array(
					'charge_id' => 'ch_refund',
					'amount'    => 800,
				)
			)
		);
		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'wcpay_refund_invalid_amount', $result->get_data()['code'] );
		$this->assertSame( 0, $this->http_client->request_count );
		$this->assertSame( 7.0, (float) $order->get_remaining_refund_amount(), 'The locked re-read must also refresh the balance used by the writer.' );
	}

	/**
	 * @testdox The lock survives shutdown cleanup during the write, and a delivery during the send must retry.
	 */
	public function test_delivery_during_the_hold_replays_after_the_answer(): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order );
		$charge                      = $answer['payment_intent']['charges']['data'][0];
		$handler                     = wc_get_container()->get( RefundEventHandler::class );
		$observed                    = false;
		$observe                     = function () use ( $order, &$observed ) {
			wc_get_container()->get( OrderPaymentLock::class )->release_held_claims();
			$observed = $this->is_locked( $order );
		};
		$this->http_client->response = function () use ( $handler, $charge, $order, $answer ) {
			try {
				$handler->process( 'charge.refunded', $charge );
				$this->fail( 'The refund event must retry while the route holds the lock.' );
			} catch ( OrderPaymentLockRefusedException $refused ) {
				$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
			}
			return $this->refund_response( $answer );
		};
		add_action( 'woocommerce_create_refund', $observe );
		try {
			$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		} finally {
			remove_action( 'woocommerce_create_refund', $observe );
		}
		$this->assertTrue( $observed );
		$this->assertFalse( $this->is_locked( $order ) );
		$handler->process( 'charge.refunded', $charge );
		$update = array(
			'charge'   => 'ch_refund',
			'id'       => 're_answer',
			'status'   => 'succeeded',
			'amount'   => 1099,
			'currency' => 'usd',
		);
		$handler->process( 'charge.refund.updated', $update );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$handler->process( 'charge.refund.updated', $update );
		$this->assertEquals( $notes, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A missing charge expansion leaves recording to the event.
	 */
	public function test_missing_expansion_leaves_the_event_to_record_the_refund(): void {
		$order  = $this->create_charge_order();
		$answer = $this->refund_answer( $order );
		$charge = $answer['payment_intent']['charges']['data'][0];
		unset( $answer['payment_intent'] );
		$logger                      = RecordingWcLogger::install();
		$this->http_client->response = $this->refund_response( $answer );
		$this->assertSame( $answer, $this->dispatch_charge_refund()->get_data() );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
		$this->assertCount( 0, $logger->get_errors() );
		wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $charge );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A failed or canceled update before or after the answer prevents a creation replay for that refund.
	 * @testWith ["failed", true]
	 *           ["canceled", true]
	 *           ["failed", false]
	 *           ["canceled", false]
	 *
	 * @param string $status Update status.
	 * @param bool   $first  Whether the update finished before the route.
	 */
	public function test_failure_delivery_and_answer( string $status, bool $first ): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order, 1099, 'pending' );
		$this->http_client->response = $this->refund_response( $answer );
		$handler                     = wc_get_container()->get( RefundEventHandler::class );
		$update                      = array_merge( $answer, array( 'status' => $status ) );
		if ( $first ) {
			$handler->process( 'charge.refund.updated', $update );
		}
		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertCount( $first ? 0 : 1, wc_get_order( $order->get_id() )->get_refunds() );
		if ( ! $first ) {
			$handler->process( 'charge.refund.updated', $update );
			$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_status() );
		}
		$handler->process( 'charge.refunded', $answer['payment_intent']['charges']['data'][0] );
		$this->assertCount( 0, wc_get_order( $order->get_id() )->get_refunds() );
		$this->assertSame( 'failed', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status' ) );
	}

	/**
	 * @testdox Redelivery after deletion and an interrupted status save recognizes the earlier cancellation note.
	 */
	public function test_failure_replay_after_deletion_with_an_existing_note(): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order );
		$this->http_client->response = $this->refund_response( $answer );
		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );

		$price     = WooPaymentsCurrencyUtils::format_explicit_order_price( 10.99, 'usd', $order );
		$notes     = wc_get_container()->get( WooPaymentsOrderNoteService::class )->extension_failed_refund_notes( $order, $price, 10.99, 'usd', 're_answer', true, '' );
		$handler   = wc_get_container()->get( RefundEventHandler::class );
		$update    = array_merge( $answer, array( 'status' => 'canceled' ) );
		$interrupt = static function ( $saving ) use ( $order ) {
			if ( $saving->get_id() === $order->get_id() && $saving->has_status( \Automattic\WooCommerce\Enums\OrderStatus::FAILED ) ) {
				throw new \RuntimeException( 'Interrupted before saving the failed order status.' );
			}
		};
		$order->add_order_note( $notes[0] );
		add_action( 'woocommerce_before_order_object_save', $interrupt );
		try {
			$handler->process( 'charge.refund.updated', $update );
			$this->fail( 'The interrupted delivery must report the failed save.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Could not save the order status after the refund failed.', $exception->getMessage() );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $interrupt );
		}

		$handler->process( 'charge.refund.updated', $update );
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status' ) );
	}

	/**
	 * @testdox An untagged extension cancellation identifies its own refund while an amount-only note cannot.
	 * @testWith ["same", 0]
	 *           ["other", 1]
	 *           ["amount only", 1]
	 *
	 * @param string $kind     Prior note.
	 * @param int    $expected Expected refund rows.
	 */
	public function test_prior_extension_note_on_pending_answer( string $kind, int $expected ): void {
		$order = $this->create_charge_order();
		$price = WooPaymentsCurrencyUtils::format_explicit_order_price( 10.99, 'usd', $order );
		$id    = 'other' === $kind ? 're_older' : 're_answer';
		$notes = wc_get_container()->get( WooPaymentsOrderNoteService::class )->extension_failed_refund_notes( $order, $price, 10.99, 'usd', $id, true, '' );
		$order->add_order_note( 'amount only' === $kind ? 'Refund of ' . $price . ' <strong>failed</strong> due to insufficient funds in your WooPayments balance.' : $notes[0] );
		$this->http_client->response = $this->refund_response( $this->refund_answer( $order, 1099, 'pending' ) );
		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertCount( $expected, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A failure replay for a different refund preserves the later refund in either delivery order.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $first Whether the replay precedes the route.
	 */
	public function test_earlier_failure_leaves_the_new_refund_alone( bool $first ): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order );
		$this->http_client->response = $this->refund_response( $answer );
		$handler                     = wc_get_container()->get( RefundEventHandler::class );
		$older                       = array_merge(
			$answer,
			array(
				'id'     => 're_older',
				'status' => 'failed',
			)
		);
		$handler->process( 'charge.refund.updated', $older );
		if ( $first ) {
			$handler->process( 'charge.refund.updated', $older );
		}
		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		if ( ! $first ) {
			$handler->process( 'charge.refund.updated', $older );
		}
		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 1, $order->get_refunds() );
		$this->assertSame( 're_answer', $order->get_refunds()[0]->get_meta( '_wcpay_refund_id' ) );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status' ) );
		$this->assertSame( 'refunded', $order->get_status() );
	}

	/**
	 * @testdox A full refund returns the order's remaining units while a final installment omits the lines.
	 * @testWith [0]
	 *           [100]
	 *
	 * @param int $prior Already refunded cents.
	 */
	public function test_full_and_final_installment_lines( int $prior ): void {
		$order = $this->create_charge_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( 'Refundable item' );
		$item->set_quantity( 2 );
		$item->set_total( 10.99 );
		$order->add_item( $item );
		$order->save();
		if ( $prior ) {
			$this->assertInstanceOf(
				\WC_Order_Refund::class,
				wc_create_refund(
					array(
						'order_id' => $order->get_id(),
						'amount'   => 1,
					)
				)
			);
		}
		$amount                      = 1099 - $prior;
		$answer                      = $this->refund_answer( $order, $amount );
		$this->http_client->response = $this->refund_response( $answer );
		$this->assertSame( $answer, $this->sut->refund_charge_without_order( 'ch_refund', $amount, '', 'transaction_details_no_order', '' ) );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'refunded', $order->get_status() );
		$linked = array_values( array_filter( $order->get_refunds(), static fn( $refund ) => 're_answer' === $refund->get_meta( '_wcpay_refund_id' ) ) );
		$this->assertCount( 1, $linked );
		$this->assertSame( $prior ? 0 : -2, (int) $linked[0]->get_item_count() );
	}

	/**
	 * @testdox A whole-dollar refund reverses both fifty-cent lines using the existing writer's store precision.
	 * @testWith ["route"]
	 *           ["event"]
	 *
	 * @param string $source Where the refund is first recorded.
	 */
	public function test_whole_dollar_refund_of_two_fifty_cent_lines( string $source ): void {
		$original_decimals = get_option( 'woocommerce_price_num_decimals' );
		$original_currency = get_option( 'woocommerce_currency' );
		try {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'woocommerce_price_num_decimals', 2 );
			$order = $this->create_charge_order();
			foreach ( array( 'First item', 'Second item' ) as $name ) {
				$item = new \WC_Order_Item_Product();
				$item->set_name( $name );
				$item->set_quantity( 1 );
				$item->set_subtotal( '0.50' );
				$item->set_total( '0.50' );
				$order->add_item( $item );
			}
			$order->set_total( '1.00' );
			$order->save();
			update_option( 'woocommerce_price_num_decimals', 0 );
			$answer = $this->refund_answer( $order, 100 );
			$answer['payment_intent']['charges']['data'][0]['amount'] = 100;
			if ( 'route' === $source ) {
				$this->http_client->response = $this->refund_response( $answer );
				$response                    = $this->server->dispatch(
					$this->create_refund_request(
						array(
							'charge_id' => 'ch_refund',
							'amount'    => 100,
						)
					)
				);
				$this->assertSame( 200, $response->get_status() );
				$this->assertSame( 1, $this->http_client->request_count );
			} else {
				wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $answer['payment_intent']['charges']['data'][0] );
			}
			$rows = wc_get_order( $order->get_id() )->get_refunds();
			$this->assertCount( 1, $rows );
			$this->assertSame( 're_answer', $rows[0]->get_meta( '_wcpay_refund_id' ) );
			$this->assertSame( 1.0, (float) $rows[0]->get_amount() );
			$this->assertSame( array( -1.0, -1.0 ), array_values( array_map( static fn( $line ) => (float) $line->get_total( 'edit' ), $rows[0]->get_items() ) ) );
		} finally {
			update_option( 'woocommerce_price_num_decimals', $original_decimals );
			update_option( 'woocommerce_currency', $original_currency );
		}
	}

	/**
	 * @testdox A partial refund on a USD order records the cents returned by the platform.
	 * @testWith [2]
	 *           [0]
	 *
	 * @param int $decimals Store price setting.
	 */
	public function test_refund_row_matches_the_platform_amount( int $decimals ): void {
		$original_decimals = get_option( 'woocommerce_price_num_decimals' );
		$original_currency = get_option( 'woocommerce_currency' );
		try {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'woocommerce_price_num_decimals', $decimals );
			$order = $this->create_charge_order();
			$order->set_total( '10.00' );
			$order->update_meta_data( '_wcpay_intent_currency', 'usd' );
			$order->save();
			$answer = $this->refund_answer( $order, 449 );
			$answer['payment_intent']['charges']['data'][0]['amount'] = 1000;
			$this->http_client->response                              = $this->refund_response( $answer );
			$before   = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$response = $this->server->dispatch(
				$this->create_refund_request(
					array(
						'charge_id' => 'ch_refund',
						'amount'    => 449,
					)
				)
			);
			if ( 0 === $decimals ) {
				$this->assertSame( 400, $response->get_status() );
				$this->assertSame( 'This store records refunds to 0 decimal places. Enter an amount it can record exactly.', $response->get_data()['message'] );
				$this->assertSame( 0, $this->http_client->request_count );
				$fresh = wc_get_order( $order->get_id() );
				$this->assertCount( 0, $fresh->get_refunds() );
				$this->assertSame( $order->get_status(), $fresh->get_status() );
				$this->assertSame( '', $fresh->get_meta( '_wcpay_refund_status' ) );
				$this->assertEquals( $before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
				return;
			}
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 1, $this->http_client->request_count );
			$this->assertSame( 449, $response->get_data()['amount'] );
			$refunds = wc_get_order( $order->get_id() )->get_refunds();
			$this->assertCount( 1, $refunds );
			$this->assertSame( 're_answer', $refunds[0]->get_meta( '_wcpay_refund_id' ) );
			$this->assertSame( 4.49, (float) $refunds[0]->get_amount(), 'The linked row must equal the refund the route returned.' );
		} finally {
			update_option( 'woocommerce_price_num_decimals', $original_decimals );
			update_option( 'woocommerce_currency', $original_currency );
		}
	}

	/**
	 * @testdox An interrupted write returns the answer, releases the lock and leaves the event to finish the same refund.
	 * @testWith ["note"]
	 *           ["before row"]
	 *           ["admin row"]
	 *
	 * @param string $stage Stage interrupted.
	 */
	public function test_event_repairs_interrupted_write( string $stage ): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order );
		$logger                      = RecordingWcLogger::install();
		$pending                     = null;
		$hook                        = 'note' === $stage ? 'woocommerce_new_order_note_data' : 'woocommerce_before_order_refund_object_save';
		$stop                        = static function ( $value ) use ( $stage ) {
			if ( 'before row' === $stage || ( 'note' === $stage && false !== strpos( $value['comment_content'], 're_answer' ) ) ) {
				throw new \Error( 'Interrupted local recording.' );
			}
			return $value;
		};
		$this->http_client->response = function () use ( $answer, $order, $stage, &$pending ) {
			if ( 'admin row' === $stage ) {
				$pending = wc_create_refund(
					array(
						'amount'   => 1,
						'order_id' => $order->get_id(),
					)
				);
				$this->assertInstanceOf( \WC_Order_Refund::class, $pending );
			}
			return $this->refund_response( $answer );
		};
		add_filter( $hook, $stop );
		try {
			$this->assertSame( $answer, $this->dispatch_charge_refund()->get_data() );
		} finally {
			remove_filter( $hook, $stop );
		}
		$this->assertFalse( $this->is_locked( $order ) );
		$this->assertCount( 1, array_filter( $logger->get_errors(), static fn( $line ) => false !== strpos( $line[1], 'Failed to record the refund from the REST API.' ) ) );
		if ( $pending ) {
			$pending->delete( true );
		}
		$rows = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 'note' === $stage ? 1 : 0, $rows );
		wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $answer['payment_intent']['charges']['data'][0] );
		$after = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $after );
		$this->assertSame( 're_answer', $after[0]->get_meta( '_wcpay_refund_id' ) );
		if ( $rows ) {
			$this->assertSame( $rows[0]->get_id(), $after[0]->get_id() );
		}
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, array_filter( $notes, static fn( $note ) => false !== strpos( $note->content, 're_answer' ) ) );
	}

	/**
	 * @testdox The locked answer sees a row another writer linked after the route first read the refund list.
	 */
	public function test_late_link_in_another_request(): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order, 200 );
		$this->http_client->response = function () use ( $order, $answer ) {
			global $wp_object_cache;
			$this->assertCount( 0, $order->get_refunds() );
			$reader = $wp_object_cache;
			try {
				$wp_object_cache = clone $reader; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Model separate request caches and restore the reader.
				$row             = wc_create_refund(
					array(
						'amount'   => 2,
						'order_id' => $order->get_id(),
					)
				);
				$this->assertInstanceOf( \WC_Order_Refund::class, $row );
				$row->update_meta_data( '_wcpay_refund_id', 're_answer' );
				$row->save();
			} finally {
				$wp_object_cache = $reader; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Model separate request caches and restore the reader.
			}
			return $this->refund_response( $answer );
		};
		$this->assertSame( $answer, $this->sut->refund_charge_without_order( 'ch_refund', 200, null, 'transaction_details_no_order', '' ) );
		$rows = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $rows );
		$this->assertSame( 're_answer', $rows[0]->get_meta( '_wcpay_refund_id' ) );
	}

	/**
	 * @testdox The event resolves the charge again after the answer, including a copy with a different key.
	 * @testWith ["no holder", 0, 0]
	 *           ["wrong key", 0, 0]
	 *           ["shared", 1, 1]
	 *           ["other gateway", 0, 1]
	 *           ["other payment", 0, 0]
	 *           ["copy same key", 1, 2]
	 *           ["copy different key", 1, 1]
	 *
	 * @param string $history Charge ownership history.
	 * @param int    $at_answer Total rows after the answer.
	 * @param int    $at_event Total rows after delivery.
	 */
	public function test_route_and_event_resolve_the_charge( string $history, int $at_answer, int $at_event ): void {
		$metadata = $this->create_charge_order( 'woocommerce_payments', 'no holder' === $history || 'wrong key' === $history ? 'ch_unrelated' : 'ch_refund' );
		$metadata->set_date_created( time() - HOUR_IN_SECONDS );
		$metadata->save();
		$holder = $metadata;
		if ( in_array( $history, array( 'wrong key', 'shared', 'other gateway' ), true ) ) {
			$holder = $this->create_charge_order( 'other gateway' === $history ? 'bacs' : 'woocommerce_payments' );
			$holder->set_transaction_id( 'ch_refund' );
			$holder->set_date_paid( time() );
			$holder->save();
		} elseif ( 'other payment' === $history ) {
			$holder->set_transaction_id( 'ch_different' );
			$holder->set_date_paid( time() );
			$holder->save();
		}
		$answer                      = $this->refund_answer( 'wrong key' === $history ? $metadata : $holder, 200 );
		$charge                      = $answer['payment_intent']['charges']['data'][0];
		$handler                     = wc_get_container()->get( RefundEventHandler::class );
		$this->http_client->response = function () use ( $answer, $holder, $history ) {
			$this->assertSame( ! in_array( $history, array( 'no holder', 'other gateway' ), true ), $this->is_locked( $holder ) );
			return $this->refund_response( $answer );
		};
		$this->assertSame( $answer, $this->sut->refund_charge_without_order( 'ch_refund', 200, null, 'transaction_details_no_order', '' ) );
		$this->assertCount( $at_answer, $holder->get_refunds() );
		$orders = array_unique( array( $holder->get_id(), $metadata->get_id() ) );
		if ( 0 === strpos( $history, 'copy ' ) ) {
			$copy = $this->create_charge_order();
			if ( 'copy same key' === $history ) {
				$copy->set_order_key( $holder->get_order_key() );
				$copy->save();
			}
			$orders[] = $copy->get_id();
		}
		$handler->process( 'charge.refunded', $charge );
		$total = 0;
		foreach ( $orders as $id ) {
			$total += count( wc_get_order( $id )->get_refunds() );
		}
		$this->assertSame( $at_event, $total );
		if ( 0 === $at_event ) {
			$notes = wc_get_order_notes( array( 'order_id' => $metadata->get_id() ) );
			$this->assertCount( 1, array_filter( $notes, static fn( $note ) => false !== strpos( $note->content, 're_answer' ) ) );
		}
	}

	/**
	 * @testdox The route asks the same resolver for the charge alone and then for the exact expanded charge.
	 */
	public function test_resolver_receives_the_answer_charge(): void {
		$order  = $this->create_charge_order();
		$answer = $this->refund_answer( $order );
		$spy    = $this->createPartialMock( EventOrderResolver::class, array( 'find_order_by_charge_id' ) );
		$spy->expects( $this->exactly( 2 ) )->method( 'find_order_by_charge_id' )
			->withConsecutive( array( 'ch_refund' ), array( 'ch_refund', $answer['payment_intent']['charges']['data'][0] ) )
			->willReturn( $order );
		wc_get_container()->replace( EventOrderResolver::class, $spy );
		$this->http_client->response = $this->refund_response( $answer );
		$this->assertSame( $answer, $this->dispatch_charge_refund()->get_data() );
	}

	/**
	 * @testdox Only an eligible request is marked, once, after the read-only check and immediately before transport.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $refused Whether the previous attempt is unsettled.
	 */
	public function test_mark_after_last_refusal( bool $refused ): void {
		$order = $this->create_charge_order();
		$calls = array();
		$lock  = wc_get_container()->get( OrderPaymentLock::class );
		$spy   = $this->createPartialMock( OrderPaymentLock::class, array( 'claim', 'mark_money_request_unsaved', 'release' ) );
		$spy->method( 'claim' )->willReturnCallback( array( $lock, 'claim' ) );
		$spy->method( 'release' )->willReturnCallback( array( $lock, 'release' ) );
		$spy->method( 'mark_money_request_unsaved' )->willReturnCallback(
			static function ( $token, $unsaved ) use ( &$calls, $lock ) {
				$calls[] = $unsaved ? 'mark' : 'clear';
				$lock->mark_money_request_unsaved( $token, $unsaved );
			}
		);
		wc_get_container()->replace( OrderPaymentLock::class, $spy );
		$service = $this->createPartialMock( WooPaymentsRefundAmbiguityService::class, array( 'decide', 'has_unsettled_attempt' ) );
		$service->expects( $this->never() )->method( 'decide' );
		$service->method( 'has_unsettled_attempt' )->willReturnCallback(
			static function () use ( &$calls, $refused ) {
				$calls[] = 'check';
				return $refused;
			}
		);
		wc_get_container()->replace( WooPaymentsRefundAmbiguityService::class, $service );
		$this->http_client->response = function () use ( &$calls, $order ) {
			$calls[] = 'send';
			return $this->refund_response( $this->refund_answer( $order ) );
		};
		$this->assertSame( $refused ? 409 : 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertSame( $refused ? array( 'check' ) : array( 'check', 'mark', 'send' ), $calls );
	}

	/**
	 * @testdox A malformed merchant record does not prevent a refund and stays available to the merchant path.
	 */
	public function test_malformed_attempt_at_send(): void {
		$order = $this->create_charge_order();
		$order->update_meta_data( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META, 'malformed' );
		$order->save_meta_data();
		$this->http_client->response = function () use ( $order ) {
			$this->assertTrue( $this->is_locked( $order ) );
			return $this->refund_response( $this->refund_answer( $order ) );
		};
		$this->assertSame( 200, $this->dispatch_charge_refund()->get_status() );
		$this->assertSame( 'malformed', wc_get_order( $order->get_id() )->get_meta( WooPaymentsPaymentOperationService::REFUND_AMBIGUITY_META ) );
	}

	/**
	 * @testdox An unlinked early save can be refunded again only if enough money remains.
	 * @testWith [200]
	 *           [1099]
	 *
	 * @param int $amount Amount in minor units.
	 */
	public function test_event_after_early_save( int $amount ): void {
		$order                       = $this->create_charge_order();
		$answer                      = $this->refund_answer( $order, $amount );
		$charge                      = $answer['payment_intent']['charges']['data'][0];
		$this->http_client->response = function () use ( $order, $answer ) {
			$this->assertTrue( $this->is_locked( $order ) );
			unset( $answer['payment_intent'] );
			return $this->refund_response( $answer );
		};
		$result                      = $this->sut->refund_charge_without_order( 'ch_refund', $amount, null, 'transaction_details_no_order', '' );
		$this->assertSame( 're_answer', $result['id'] );
		$residue = wc_create_refund(
			array(
				'amount'   => $amount / 100,
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $residue );
		$this->assertSame( '', $residue->get_meta( '_wcpay_refund_id' ) );
		try {
			wc_get_container()->get( RefundEventHandler::class )->process( 'charge.refunded', $charge );
			$this->assertSame( 200, $amount );
		} catch ( \RuntimeException $failure ) {
			$this->assertSame( 1099, $amount );
			$this->assertStringContainsString( 'Invalid refund amount', $failure->getMessage() );
		}
		$this->assertCount( 200 === $amount ? 2 : 1, wc_get_order( $order->get_id() )->get_refunds() );
	}

	/**
	 * @testdox A success update before the pending answer does not create a row, and a later update completes the linked refund.
	 */
	public function test_success_update_before_pending_link(): void {
		$order   = $this->create_charge_order();
		$answer  = $this->refund_answer( $order, 1099, 'pending' );
		$handler = wc_get_container()->get( RefundEventHandler::class );
		$update  = array_merge( $answer, array( 'status' => 'succeeded' ) );
		$handler->process( 'charge.refund.updated', $update );
		$this->assertCount( 0, $order->get_refunds() );
		$this->http_client->response = $this->refund_response( $answer );
		$this->assertSame( $answer, $this->dispatch_charge_refund()->get_data() );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status' ) );
		$handler->process( 'charge.refund.updated', $update );
		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status' ) );
		$this->assertCount( 1, $order->get_refunds() );
	}

	/**
	 * @testdox The first save following the refund creation hook carries the provider ID before the writer adds its note.
	 */
	public function test_link_saved_before_the_order_refunded_hook(): void {
		$order   = $this->create_charge_order();
		$answer  = $this->refund_answer( $order );
		$seen    = array();
		$observe = static function ( $order_id, $refund_id ) use ( &$seen ) {
			$seen[] = wc_get_order( $refund_id )->get_meta( '_wcpay_refund_id' );
		};
		wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\RefundRowCapture::class )->register();
		add_action( 'woocommerce_order_refunded', $observe, 10, 2 );
		$this->http_client->response = $this->refund_response( $answer );
		try {
			$this->assertSame( $answer, $this->dispatch_charge_refund()->get_data() );
		} finally {
			remove_action( 'woocommerce_order_refunded', $observe );
		}
		$this->assertSame( array( 're_answer' ), $seen );
	}

	/**
	 * @testdox A whole-dollar request still has to fit after an earlier refund recorded with cents.
	 * @testWith [700, 200]
	 *           [800, 400]
	 *
	 * @param int $requested Requested minor units.
	 * @param int $status Expected response status.
	 */
	public function test_precision_change_with_an_earlier_refund( int $requested, int $status ): void {
		$previous = get_option( 'woocommerce_price_num_decimals' );
		try {
			update_option( 'woocommerce_price_num_decimals', 2 );
			$order = $this->create_charge_order();
			$order->set_total( '10.00' );
			$order->save();
			$row = wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => '2.40',
				)
			);
			$this->assertInstanceOf( \WC_Order_Refund::class, $row );
			$this->assertSame( 2.4, (float) $row->get_amount() );
			update_option( 'woocommerce_price_num_decimals', 0 );
			$answer = $this->refund_answer( $order, $requested );
			$answer['payment_intent']['charges']['data'][0]['amount'] = 1000;
			$this->http_client->response                              = $this->refund_response( $answer );
			$response = $this->server->dispatch(
				$this->create_refund_request(
					array(
						'charge_id' => 'ch_refund',
						'amount'    => $requested,
					)
				)
			);
			$fresh    = wc_get_order( $order->get_id() );
			$this->assertSame( $status, $response->get_status(), sprintf( 'Calls: %d. Order total: %s. Total refunded: %s. Rows: %d.', $this->http_client->request_count, $fresh->get_total(), $fresh->get_total_refunded(), count( $fresh->get_refunds() ) ) );
			$this->assertSame( 200 === $status ? 1 : 0, $this->http_client->request_count );
			if ( 400 === $status ) {
				$this->assertSame( 'wcpay_refund_invalid_amount', $response->get_data()['code'] );
				$this->assertCount( 1, $fresh->get_refunds() );
				$this->assertSame( $row->get_id(), $fresh->get_refunds()[0]->get_id() );
				$this->assertSame( 2.4, (float) $fresh->get_total_refunded() );
			}
		} finally {
			update_option( 'woocommerce_price_num_decimals', $previous );
		}
	}

	/**
	 * @testdox An admin refund of a euro order can send only the amount the writer can record.
	 */
	public function test_admin_refund_checks_the_writers_remaining_amount(): void {
		$server = $_SERVER;
		try {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
			update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
			update_option( 'woocommerce_price_num_decimals', 2 );
			$order = $this->create_charge_order();
			$order->set_currency( 'EUR' );
			$order->set_total( '10.40' );
			$order->update_meta_data( '_wcpay_intent_currency', 'eur' );
			$order->save();
			$this->assertSame( '10.40', $order->get_total() );

			update_option( 'woocommerce_price_num_decimals', 0 );
			$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=wc-admin' );
			$_SERVER['REQUEST_URI']  = '/wp-json/wc/v3/payments/refund';
			$this->assertTrue( wc_get_container()->get( MultiCurrencyRuntimeArbiter::class )->is_builtin_owner() );
			$this->assertTrue( wc_get_container()->get( RequestContext::class )->is_admin_api_request() );
			$frontend = wc_get_container()->get( MultiCurrencyFrontendCurrenciesController::class );
			$frontend->register();
			$this->assertFalse( has_filter( 'wc_get_price_decimals', array( $frontend, 'get_price_decimals' ) ) );
			$before = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
			$status = $order->get_status();

			$dispatch = function ( int $amount ) use ( $order ) {
				$this->http_client->response = $this->refund_response( $this->refund_answer( $order, $amount, 'succeeded', 1040, 'eur' ) );
				return $this->server->dispatch(
					$this->create_refund_request(
						array(
							'charge_id' => 'ch_refund',
							'amount'    => $amount,
						)
					)
				);
			};
			$response = $dispatch( 1040 );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'wcpay_refund_invalid_amount', $response->get_data()['code'] );
			$this->assertSame( 'This order cannot record that refund amount. The largest amount it can record is 10.00 EUR.', $response->get_data()['message'] );
			$this->assertSame( 0, $this->http_client->request_count );
			$fresh = wc_get_order( $order->get_id() );
			$this->assertSame( $status, $fresh->get_status() );
			$this->assertSame( '', $fresh->get_meta( '_wcpay_refund_status' ) );
			$this->assertCount( 0, $fresh->get_refunds() );
			$this->assertEquals( $before, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
			$this->assertFalse( $this->is_locked( $order ) );

			$this->assertSame( 200, $dispatch( 1000 )->get_status() );
			$row = wc_get_order( $order->get_id() )->get_refunds()[0];
			$this->assertSame( 10.0, (float) $row->get_amount() );
			$this->assertSame( 're_answer', $row->get_meta( '_wcpay_refund_id' ) );
			$response = $dispatch( 40 );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'This order cannot record that refund amount. The largest amount it can record is 0.00 EUR.', $response->get_data()['message'] );
			$this->assertSame( 1, $this->http_client->request_count );
			$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );
		} finally {
			$_SERVER = $server;
		}
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
		$order->set_currency( 'USD' );
		$order->set_total( '10.99' );
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
