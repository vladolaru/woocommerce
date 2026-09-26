<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMoneyMovementOrderService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsRestController;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for native WooPayments money movement REST controllers.
 */
class WooPaymentsMoneyMovementRestControllerTest extends WC_REST_Unit_Test_Case {

	use WooPaymentsMoneyMovementControllerTestTrait;

	/**
	 * Recording API client.
	 *
	 * @var RecordingMoneyMovementApiClient
	 */
	private RecordingMoneyMovementApiClient $api_client;

	/**
	 * Test refund gateway initializer callback.
	 *
	 * @var callable|null
	 */
	private $refund_gateway_initializer = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new RecordingMoneyMovementApiClient();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_list_transactions_request' );
		remove_all_filters( 'wcpay_list_fraud_outcome_transactions_request' );
		remove_all_filters( 'wcpay_list_fraud_outcome_transactions_summary_request' );
		remove_all_filters( 'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' );
		remove_all_filters( 'wcpay_get_fraud_outcome_transactions_export_request' );
		remove_all_filters( 'woocommerce_logging_class' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		if ( null !== $this->refund_gateway_initializer ) {
			remove_action( 'wc_payment_gateways_initialized', $this->refund_gateway_initializer, 100 );
			$this->refund_gateway_initializer = null;
		}
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			WC()->payment_gateways()->payment_gateways = array();
			WC()->payment_gateways()->init();
		}
		parent::tearDown();
	}

	/**
	 * @testdox Transactions routes register only when native owns runtime.
	 */
	public function test_transactions_routes_register_only_when_native_owns_runtime(): void {
		$controller = $this->create_transactions_controller( true );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/transactions', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/summary', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/search', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/fraud_outcomes/(?P<id>\\w+)/latest', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/(?P<transaction_id>\\w+)', $routes );

		$controller = $this->create_transactions_controller( false );
		$controller->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );
	}

	/**
	 * @testdox Payment detail routes register only when native owns runtime.
	 */
	public function test_payment_detail_routes_register_only_when_native_owns_runtime(): void {
		$controller = $this->create_payment_details_controller( true );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/charges/(?P<charge_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/charges/order/(?P<order_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/payment_intents', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/payment_intents/(?P<payment_intent_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/timeline/(?P<intention_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/refund', $routes );

		$controller = $this->create_payment_details_controller( false );
		$controller->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );
	}

	/**
	 * @testdox Payment detail charge route proxies the charge ID.
	 */
	public function test_payment_detail_charge_route_proxies_charge_id(): void {
		$order = $this->create_order_with_charge( 'ch_test', 'pi_test' );

		$this->api_client->response = array(
			'id'                  => 'ch_test',
			'balance_transaction' => array( 'id' => 'txn_test' ),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/charges/ch_test' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_charge', $this->api_client->last_call['method'] );
		$this->assertSame( 'ch_test', $this->api_client->last_call['charge_id'] );
		$this->assertSame( 'txn_test', $data['balance_transaction']['id'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
	}

	/**
	 * @testdox Payment detail charge-from-order route requires manage_woocommerce.
	 */
	public function test_payment_detail_charge_from_order_route_requires_manage_woocommerce(): void {
		$order = $this->create_order_for_generated_charge();
		$this->create_payment_details_controller( true )->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/charges/order/' . $order->get_id() ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Payment detail charge-from-order route returns a local charge-like object.
	 */
	public function test_payment_detail_charge_from_order_route_generates_charge_from_order(): void {
		$order = $this->create_order_for_generated_charge();
		$this->create_payment_details_controller( true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/charges/order/' . $order->get_id() ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
		$this->assertSame( $order->get_id(), $data['id'] );
		$this->assertSame( 1234, $data['amount'] );
		$this->assertSame( 0, $data['amount_captured'] );
		$this->assertSame( 0, $data['amount_refunded'] );
		$this->assertSame( 0, $data['application_fee_amount'] );
		$this->assertSame( 'USD', $data['currency'] );
		$this->assertSame( 'USD', $data['balance_transaction']['currency'] );
		$this->assertSame( 1234, $data['balance_transaction']['amount'] );
		$this->assertSame( 0, $data['balance_transaction']['fee'] );
		$this->assertSame( 'pi_order', $data['payment_intent'] );
		$this->assertSame( 'requires_capture', $data['status'] );
		$this->assertFalse( $data['disputed'] );
		$this->assertFalse( $data['outcome'] );
		$this->assertFalse( $data['paid'] );
		$this->assertFalse( $data['refunded'] );
		$this->assertNull( $data['paydown'] );
		$this->assertNull( $data['refunds'] );
		$this->assertSame( 'card', $data['payment_method_details']['type'] );
		$this->assertSame( 'US', $data['payment_method_details']['card']['country'] );
		$this->assertSame( array(), $data['payment_method_details']['card']['checks'] );
		$this->assertSame( '', $data['payment_method_details']['card']['network'] );
		$this->assertSame( 'ada@example.com', $data['billing_details']['email'] );
		$this->assertSame( '1 Main Street', $data['billing_details']['address']['line1'] );
		$this->assertStringContainsString( '1 Main Street', $data['billing_details']['formatted_address'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertSame( 'Ada Lovelace', $data['order']['customer_name'] );
		$this->assertSame( $order->get_date_created()->getTimestamp(), $data['created'] );
	}

	/**
	 * @testdox Payment detail charge-from-order route returns the plugin-compatible missing-order error.
	 */
	public function test_payment_detail_charge_from_order_route_rejects_missing_order(): void {
		$this->create_payment_details_controller( true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/charges/order/999999' ) );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpay_missing_order', $data['code'] );
		$this->assertSame( 'Order not found', $data['message'] );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Payment detail payment intent route proxies the intent ID.
	 */
	public function test_payment_detail_intent_route_proxies_intent_id(): void {
		$order = $this->create_order_with_charge( 'ch_test', 'pi_test' );

		$this->api_client->response = array(
			'id'      => 'pi_test',
			'charges' => array(
				'data' => array(
					array(
						'id'                  => 'ch_test',
						'balance_transaction' => array( 'id' => 'txn_test' ),
					),
				),
			),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/payment_intents/pi_test' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_payment_intention', $this->api_client->last_call['method'] );
		$this->assertSame( 'pi_test', $this->api_client->last_call['intent_id'] );
		$this->assertSame( 'txn_test', $data['charges']['data'][0]['balance_transaction']['id'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertSame( $order->get_id(), $data['charges']['data'][0]['order']['id'] );
	}

	/**
	 * Oracle: WooPayments 11.1.0 client/payment-details/summary/index.tsx plus charge and balance_transaction fields from the recorded M2 platform response.
	 *
	 * @testdox Payment detail intent route preserves shopper and settlement money independently.
	 */
	public function test_payment_detail_intent_route_preserves_shopper_and_settlement_money(): void {
		$this->create_order_with_charge( 'ch_fx', 'pi_fx' );

		$this->api_client->response = array(
			'id'       => 'pi_fx',
			'amount'   => 1234,
			'currency' => 'eur',
			'charges'  => array(
				'data' => array(
					array(
						'id'                  => 'ch_fx',
						'payment_intent'      => 'pi_fx',
						'status'              => 'succeeded',
						'amount'              => 1234,
						'currency'            => 'eur',
						'captured'            => true,
						'balance_transaction' => array(
							'id'            => 'txn_fx',
							'amount'        => 1415,
							'fee'           => 86,
							'net'           => 1329,
							'currency'      => 'usd',
							'exchange_rate' => 1.14667,
						),
					),
				),
			),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/payment_intents/pi_fx' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1234, $data['amount'] );
		$this->assertSame( 'eur', $data['currency'] );
		$this->assertSame( 1234, $data['charges']['data'][0]['amount'] );
		$this->assertSame( 'eur', $data['charges']['data'][0]['currency'] );
		$this->assertSame( 1415, $data['charges']['data'][0]['balance_transaction']['amount'] );
		$this->assertSame( 86, $data['charges']['data'][0]['balance_transaction']['fee'] );
		$this->assertSame( 1329, $data['charges']['data'][0]['balance_transaction']['net'] );
		$this->assertSame( 'usd', $data['charges']['data'][0]['balance_transaction']['currency'] );
		$this->assertSame( 1.14667, $data['charges']['data'][0]['balance_transaction']['exchange_rate'] );
	}

	/**
	 * @testdox Payment detail create-intent route requires manage_woocommerce.
	 */
	public function test_payment_detail_create_intent_route_requires_manage_woocommerce(): void {
		$order = $this->create_order_for_generated_charge();
		$this->create_payment_details_controller( true )->register_routes();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Payment detail create-intent route creates an off-session card intent from the order.
	 */
	public function test_payment_detail_create_intent_route_creates_order_intent(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'manual_capture' => 'yes' ) );
		$order = $this->create_order_for_generated_charge();

		$this->api_client->response = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pi_created', $data['id'] );
		$this->assertSame( 'create_and_confirm_payment_intention', $this->api_client->last_call['method'] );
		$this->assertSame( 'payment_intent_order_' . $order->get_id(), $this->api_client->last_call['idempotency_key'] );

		$request_data = $this->api_client->last_call['request_data'];
		$this->assertSame( 1234, $request_data['amount'] );
		$this->assertSame( 'usd', $request_data['currency'] );
		$this->assertSame( 'cus_test', $request_data['customer'] );
		$this->assertSame( 'pm_test', $request_data['payment_method'] );
		$this->assertSame( array( 'card' ), $request_data['payment_method_types'] );
		$this->assertTrue( $request_data['off_session'] );
		$this->assertSame( 'manual', $request_data['capture_method'] );
		$this->assertSame( $order->get_id(), $request_data['metadata']['order_id'] );
		$this->assertSame( $order->get_order_number(), $request_data['metadata']['order_number'] );
		$this->assertSame( 'single', (string) $request_data['metadata']['payment_type'] );
		$this->assertSame( 'no', $request_data['metadata']['subscription_payment'] );
	}

	/**
	 * @testdox Payment detail create-intent route preserves the plugin-compatible missing-order error shape.
	 */
	public function test_payment_detail_create_intent_route_rejects_missing_order(): void {
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => 999999,
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $data['code'] );
		$this->assertSame( 'Order not found', $data['message'] );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Payment detail timeline route proxies the intention ID.
	 */
	public function test_payment_detail_timeline_route_proxies_intention_id(): void {
		$this->api_client->response = array(
			'data' => array(
				array(
					'type'    => 'captured',
					'message' => 'Payment captured.',
				),
			),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/timeline/pi_test' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_timeline', $this->api_client->last_call['method'] );
		$this->assertSame( 'pi_test', $this->api_client->last_call['intention_id'] );
		$this->assertSame( 'captured', $data['data'][0]['type'] );
	}

	/**
	 * @testdox Payment detail timeline adds local manual fraud outcomes for review timelines.
	 */
	public function test_payment_detail_timeline_adds_manual_fraud_outcome(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->add_meta_data(
			'_wcpay_fraud_outcome_manual_entry',
			array(
				'type'     => 'fraud_outcome_manual_approve',
				'user'     => array(
					'id'       => 1,
					'username' => 'admin',
				),
				'action'   => 'approved',
				'datetime' => 1781712200,
			)
		);
		$order->save();

		$this->api_client->responses = array(
			'get_timeline'          => array(
				'data' => array(
					array(
						'type'     => 'fraud_outcome_review',
						'datetime' => 1781712000,
					),
					array(
						'type'     => 'captured',
						'datetime' => 1781711900,
					),
				),
			),
			'get_payment_intention' => array(
				'id'       => 'pi_review',
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
				),
			),
		);
		$this->create_payment_details_controller( true )->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/timeline/pi_review' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_timeline', $this->api_client->calls[0]['method'] );
		$this->assertSame( 'pi_review', $this->api_client->calls[0]['intention_id'] );
		$this->assertSame( 'get_payment_intention', $this->api_client->calls[1]['method'] );
		$this->assertSame( 'pi_review', $this->api_client->calls[1]['intent_id'] );
		$this->assertSame(
			array( 'fraud_outcome_manual_approve', 'fraud_outcome_review', 'captured' ),
			wp_list_pluck( $data['data'], 'type' )
		);
		$this->assertSame( 'approved', $data['data'][0]['action'] );
	}

	/**
	 * @testdox Payment detail routes preserve API error status codes.
	 */
	public function test_payment_detail_routes_preserve_api_error_status_codes(): void {
		$this->api_client->exception = new WooPaymentsApiException( 'Charge not found.', 'wcpay_missing_charge', 404 );
		$this->create_payment_details_controller( true )->register_routes();

		$request  = new WP_REST_Request( 'GET', '/wc/v3/payments/charges/ch_missing' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpay_missing_charge', $data['code'] );
	}

	/**
	 * @testdox Payment detail refund route creates an order-backed WooCommerce refund through the order gateway.
	 */
	public function test_payment_detail_refund_route_creates_order_backed_refund(): void {
		$gateway = $this->register_refund_gateway( true );
		$order   = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 5000,
				'reason'    => 'requested_by_customer',
				'order_id'  => $order->get_id(),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();
		$refunds  = $order->get_refunds();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $refunds );
		$this->assertSame( 50.0, (float) $refunds[0]->get_amount() );
		$this->assertSame( 'requested_by_customer', $refunds[0]->get_reason() );
		$this->assertTrue( $refunds[0]->get_refunded_payment() );
		$this->assertSame( $refunds[0]->get_id(), $data['id'] );
		$this->assertSame( $order->get_id(), $data['order_id'] );
		$this->assertSame( '50.00', $data['amount'] );
		$this->assertSame(
			array(
				array(
					'order_id' => $order->get_id(),
					'amount'   => 50.0,
					'reason'   => 'requested_by_customer',
				),
			),
			$gateway->refund_calls
		);
	}

	/**
	 * @testdox Payment detail refund route rejects refunds without a WooCommerce order.
	 */
	public function test_payment_detail_refund_route_rejects_missing_order(): void {
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 5000,
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_refund_missing_order', $data['code'] );
	}

	/**
	 * @testdox Payment detail refund route rejects unknown orders with its own error code.
	 */
	public function test_payment_detail_refund_route_rejects_unknown_order(): void {
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 5000,
				'order_id'  => 999999,
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpay_refund_missing_order', $data['code'] );
	}

	/**
	 * @testdox Payment detail refund route rejects charge IDs that do not belong to the order.
	 */
	public function test_payment_detail_refund_route_rejects_charge_order_mismatch(): void {
		$this->register_refund_gateway( true );
		$order = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_other',
				'amount'    => 5000,
				'reason'    => 'requested_by_customer',
				'order_id'  => $order->get_id(),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_refund_charge_order_mismatch', $data['code'] );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox Payment detail refund route validates the requested amount before creating a refund.
	 */
	public function test_payment_detail_refund_route_rejects_invalid_amounts(): void {
		$this->register_refund_gateway( true );
		$order = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 0,
				'order_id'  => $order->get_id(),
			)
		);

		$zero_response = $this->server->dispatch( $request );
		$zero_data     = $zero_response->get_data();

		$this->assertSame( 400, $zero_response->get_status() );
		$this->assertSame( 'wcpay_refund_invalid_amount', $zero_data['code'] );

		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 5100,
				'order_id'  => $order->get_id(),
			)
		);

		$too_large_response = $this->server->dispatch( $request );
		$too_large_data     = $too_large_response->get_data();

		$this->assertSame( 400, $too_large_response->get_status() );
		$this->assertSame( 'wcpay_refund_invalid_amount', $too_large_data['code'] );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox Payment detail refund route returns gateway failures without keeping a local refund row.
	 */
	public function test_payment_detail_refund_route_returns_gateway_failure(): void {
		$this->register_refund_gateway( new \WP_Error( 'gateway_failed', 'Gateway failed.' ) );
		$order = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller( true )->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => 5000,
				'order_id'  => $order->get_id(),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_refund_payment_failed', $data['code'] );
		$this->assertStringContainsString( 'Gateway failed.', $data['message'] );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox Transactions list preserves reference query names and the legacy request filter.
	 */
	public function test_transactions_list_preserves_filter_contract(): void {
		$this->create_transactions_controller( true )->register_routes();

		add_filter(
			'wcpay_list_transactions_request',
			static function ( WooPaymentsTransactionsListRequest $request ): WooPaymentsTransactionsListRequest {
				$request->set_page_size( 50 );
				$request->set_deposit_id( 'po_filtered' );
				$request->set( 'extension_custom_param', 'transactions-custom' );

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' );
		$request->set_query_params(
			array(
				'page'              => '2',
				'pagesize'          => '25',
				'store_currency_is' => 'usd',
				'type_is_in'        => array( 'charge', 'refund' ),
				'search'            => array( 'Ada' ),
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'page'                   => 2,
				'pagesize'               => 50,
				'sort'                   => 'date',
				'direction'              => 'desc',
				'limit'                  => 100,
				'type_is_in'             => array( 'charge', 'refund' ),
				'store_currency_is'      => 'usd',
				'search'                 => array( 'Ada' ),
				'deposit_id'             => 'po_filtered',
				'extension_custom_param' => 'transactions-custom',
			),
			$this->api_client->last_call['query']
		);
	}

	/**
	 * @testdox Transactions list maps order search tokens to charge IDs and adds legacy order context.
	 */
	public function test_transactions_list_maps_order_search_and_enriches_response(): void {
		$order = $this->create_order_with_charge( 'ch_order', 'pi_order' );

		$this->api_client->response = array(
			'data' => array(
				array(
					'id'        => 'txn_order',
					'charge_id' => 'ch_order',
				),
				array(
					'id'        => 'txn_missing',
					'charge_id' => 'ch_missing',
				),
			),
		);
		$this->create_transactions_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' );
		$request->set_query_params(
			array(
				'search' => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ch_order' ), $this->api_client->last_call['query']['search'] );
		$this->assertSame( $order->get_order_number(), $data['data'][0]['order']['number'] );
		$this->assertSame( 'pi_order', $data['data'][0]['payment_intent_id'] );
		$this->assertArrayNotHasKey( 'order', $data['data'][1] );
	}

	/**
	 * @testdox Transactions list and summary expose the same provider-owned row and totals to the admin client.
	 */
	public function test_transactions_list_and_summary_preserve_rows_and_totals(): void {
		$this->create_transactions_controller( true )->register_routes();
		$this->api_client->response = array(
			'data'        => array(
				array(
					'id'       => 'txn_first',
					'amount'   => 2500,
					'net'      => 2397,
					'currency' => 'usd',
				),
			),
			'total_count' => 1,
		);

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) );

		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( $this->api_client->response, $list->get_data() );
		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );

		$this->api_client->response = array(
			'count'    => 1,
			'total'    => 2500,
			'currency' => 'usd',
		);

		$summary = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/summary' ) );

		$this->assertSame( 200, $summary->get_status() );
		$this->assertSame( $this->api_client->response, $summary->get_data() );
		$this->assertSame( 'get_transactions_summary', $this->api_client->last_call['method'] );
	}

	/**
	 * @testdox Transactions summary and export use the preserved list filter normalization.
	 */
	public function test_transactions_summary_and_export_normalize_reference_filters(): void {
		$this->create_transactions_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/summary' );
		$request->set_query_params(
			array(
				'page'              => '1',
				'store_currency_is' => 'eur',
				'date_after'        => '2026-06-18 10:00:00',
				'user_timezone'     => 'UTC',
				'ignored'           => 'drop-me',
				'deposit_id'        => 'po_test',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_transactions_summary', $this->api_client->last_call['method'] );
		$this->assertArrayHasKey( 'date_after', $this->api_client->last_call['filters'] );
		$this->assertSame( 'eur', $this->api_client->last_call['filters']['store_currency_is'] );
		$this->assertArrayNotHasKey( 'ignored', $this->api_client->last_call['filters'] );
		$this->assertSame( 'po_test', $this->api_client->last_call['deposit_id'] );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/transactions/download' );
		$request->set_body_params(
			array(
				'store_currency_is' => 'usd',
				'type_is_in'        => array( 'charge', 'refund' ),
				'user_email'        => 'merchant@example.com',
				'locale'            => 'en_US',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_transactions_export', $this->api_client->last_call['method'] );
		$this->assertSame( 'usd', $this->api_client->last_call['filters']['store_currency_is'] );
		$this->assertSame( array( 'charge', 'refund' ), $this->api_client->last_call['filters']['type_is_in'] );
		$this->assertArrayNotHasKey( 'ignored', $this->api_client->last_call['filters'] );
		$this->assertSame( 'merchant@example.com', $this->api_client->last_call['user_email'] );
		$this->assertSame( 'en_US', $this->api_client->last_call['locale'] );
	}

	/**
	 * @testdox Transactions routes require manage_woocommerce before API calls.
	 */
	public function test_transactions_routes_require_manage_woocommerce(): void {
		$this->create_transactions_controller( true )->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Latest fraud outcome route requires manage_woocommerce before API calls.
	 */
	public function test_latest_fraud_outcome_route_requires_manage_woocommerce(): void {
		$this->create_transactions_controller( true )->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/fraud_outcomes/pi_test/latest' ) );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Latest fraud outcome route delegates to the preserved API-client method.
	 */
	public function test_latest_fraud_outcome_route_delegates_to_api_client(): void {
		$this->api_client->response = array(
			'id'                => 'fo_latest',
			'payment_intent_id' => 'pi_test',
			'outcome'           => 'review',
		);
		$this->create_transactions_controller( true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/fraud_outcomes/pi_test/latest' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_latest_fraud_outcome', $this->api_client->last_call['method'] );
		$this->assertSame( 'pi_test', $this->api_client->last_call['id'] );
		$this->assertSame( $this->api_client->response, $response->get_data() );
	}

	/**
	 * @testdox Fraud outcome routes reject requests without a valid status.
	 */
	public function test_fraud_outcome_routes_reject_missing_status(): void {
		$this->create_transactions_controller( true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_fraud_outcome_status', $response->get_data()['code'] );
	}

	/**
	 * @testdox Fraud outcome routes preserve legacy local order-derived data.
	 */
	public function test_fraud_outcome_routes_preserve_legacy_order_data(): void {
		$order = $this->create_order_with_charge( 'ch_review', 'pi_order_meta' );
		$order->set_total( 10.50 );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', 'review' );
		$order->save();

		$this->api_client->response = array(
			array(
				'order_id'          => $order->get_id(),
				'payment_intent_id' => 'pi_platform',
				'created'           => 123,
			),
		);
		$this->create_transactions_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' );
		$request->set_query_params( array( 'status' => 'review' ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_fraud_outcomes', $this->api_client->last_call['method'] );
		$this->assertSame( 'review', $this->api_client->last_call['query']['status'] );
		$this->assertSame( $order->get_id(), $data['data'][0]['order_id'] );
		$this->assertSame( 1050, $data['data'][0]['amount'] );
		$this->assertSame( 'USD', $data['data'][0]['currency'] );
		$this->assertSame( 'pi_platform', $data['data'][0]['payment_intent']['id'] );
		$this->assertSame( 'requires_capture', $data['data'][0]['payment_intent']['status'] );
		$this->assertArrayNotHasKey( 'manual_review', $data['data'][0] );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes/summary' );
		$request->set_query_params( array( 'status' => 'review' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertSame( 1050, $response->get_data()['total'] );
		$this->assertSame( array( 'usd' ), $response->get_data()['currencies'] );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes/search' );
		$request->set_query_params(
			array(
				'status'      => 'review',
				'search_term' => (string) $order->get_id(),
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'order-' . $order->get_id(), $response->get_data()[0]['key'] );
		$this->assertSame( __( 'Order #', 'woocommerce' ) . $order->get_id(), $response->get_data()[0]['label'] );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes/download' );
		$request->set_query_params( array( 'status' => 'review' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order->get_id(), $response->get_data()['data'][0]['order_id'] );
	}

	/**
	 * @testdox Fraud outcome routes preserve legacy request hooks and cap page size.
	 */
	public function test_fraud_outcome_routes_preserve_legacy_request_hooks_and_cap_page_size(): void {
		$this->create_transactions_controller( true )->register_routes();
		$hooks_seen = array();
		$hooks      = array(
			'wcpay_list_fraud_outcome_transactions_request'                  => '/wc/v3/payments/transactions/fraud-outcomes',
			'wcpay_list_fraud_outcome_transactions_summary_request'          => '/wc/v3/payments/transactions/fraud-outcomes/summary',
			'wcpay_get_fraud_outcome_transactions_search_autocomplete_request' => '/wc/v3/payments/transactions/fraud-outcomes/search',
			'wcpay_get_fraud_outcome_transactions_export_request'            => '/wc/v3/payments/transactions/fraud-outcomes/download',
		);

		foreach ( array_keys( $hooks ) as $hook ) {
			add_filter(
				$hook,
				static function ( WooPaymentsFraudOutcomeTransactionsListRequest $request ) use ( &$hooks_seen, $hook ): WooPaymentsFraudOutcomeTransactionsListRequest {
					$hooks_seen[] = $hook;
					$request->set_page_size( 999 );
					$request->set_search( array( 'Ada' ) );
					$request->set( 'extension_custom_param', $hook );

					return $request;
				}
			);
		}

		foreach ( $hooks as $hook => $route ) {
			$request = new WP_REST_Request( 'GET', $route );
			$request->set_query_params( array( 'status' => 'allow' ) );

			$response = $this->server->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), $hook . ' route should remain successful' );
			$this->assertSame( $hook, end( $hooks_seen ) );
			$this->assertSame( 100, $this->api_client->last_call['query']['pagesize'] );
			$this->assertSame( array( 'Ada' ), $this->api_client->last_call['query']['search'] );
			$this->assertSame( $hook, $this->api_client->last_call['query']['extension_custom_param'] );
		}
	}

	/**
	 * @testdox Fraud outcome enrichment truncates oversized platform responses and logs the degraded path.
	 */
	public function test_fraud_outcome_routes_cap_local_enrichment_rows(): void {
		$order_service = new class() extends WooPaymentsMoneyMovementOrderService {
			/**
			 * Number of rows received by local formatting.
			 *
			 * @var int
			 */
			public int $row_count = 0;

			/**
			 * Format raw fraud outcome platform rows with local WooCommerce order context.
			 *
			 * @param array<string|int,mixed> $response Platform response.
			 * @param array<string,mixed>     $params   Request params.
			 * @return array<int,array<string,mixed>>
			 */
			public function format_fraud_outcome_transactions( array $response, array $params ): array {
				$rows            = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
				$this->row_count = count( $rows );

				return array();
			}
		};
		$this->create_transactions_controller( true, $order_service )->register_routes();
		$logger = $this->create_recording_logger();
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ): object {
				return $logger;
			}
		);
		$this->api_client->response = array(
			'data' => array_fill( 0, 1001, array( 'order_id' => 1 ) ),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' );
		$request->set_query_params( array( 'status' => 'allow' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1000, $order_service->row_count );
		$this->assertSame( 'warning', $logger->entries[0]['level'] );
		$this->assertSame( 'woopayments-fraud-outcomes', $logger->entries[0]['context']['source'] );
		$this->assertSame( 1001, $logger->entries[0]['context']['rows'] );
		$this->assertSame( 1000, $logger->entries[0]['context']['max_rows'] );
	}

	/**
	 * Create a transactions controller.
	 *
	 * @param bool                                      $native_register Whether native should own routes.
	 * @param WooPaymentsMoneyMovementOrderService|null $order_service   Optional order service.
	 * @return WooPaymentsTransactionsRestController
	 */
	private function create_transactions_controller( bool $native_register, ?WooPaymentsMoneyMovementOrderService $order_service = null ): WooPaymentsTransactionsRestController {
		$controller = new WooPaymentsTransactionsRestController();
		$controller->init( $this->create_arbiter( $native_register ), $this->api_client, $order_service ?? $this->create_order_service() );

		return $controller;
	}

	/**
	 * Create a payment details controller.
	 *
	 * @param bool $native_register Whether native should own routes.
	 * @return WooPaymentsPaymentDetailsRestController
	 */
	private function create_payment_details_controller( bool $native_register ): WooPaymentsPaymentDetailsRestController {
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $this->create_arbiter( $native_register ), $this->api_client, $this->create_order_service() );

		return $controller;
	}

	/**
	 * Create an order for charge-from-order route tests.
	 *
	 * @return WC_Order
	 */
	private function create_order_for_generated_charge(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_total( '12.34' );
		$order->set_currency( 'USD' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->set_billing_phone( '+15555550100' );
		$order->set_billing_address_1( '1 Main Street' );
		$order->set_billing_city( 'San Francisco' );
		$order->set_billing_state( 'CA' );
		$order->set_billing_postcode( '94107' );
		$order->set_billing_country( 'US' );
		$order->set_customer_ip_address( '127.0.0.1' );
		$order->update_meta_data( '_intent_id', 'pi_order' );
		$order->update_meta_data( '_intent_status', 'requires_capture' );
		$order->save();

		return $order;
	}

	/**
	 * Create a refundable WooPayments order.
	 *
	 * @param string $total     Order total.
	 * @param string $charge_id WooPayments charge ID.
	 * @return WC_Order
	 */
	private function create_refundable_order_with_charge( string $total, string $charge_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_total( $total );
		$order->set_currency( 'USD' );
		$order->set_payment_method( 'native_test_refund_gateway' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intent_id', 'pi_order' );
		$order->save();

		return $order;
	}

	/**
	 * Register a local refundable gateway for refund route tests.
	 *
	 * @param bool|\WP_Error $refund_result Gateway refund result.
	 * @return \WC_Payment_Gateway&object{refund_calls: array<int,array<string,mixed>>}
	 */
	private function register_refund_gateway( $refund_result ): \WC_Payment_Gateway {
		$gateway = new class( $refund_result ) extends \WC_Payment_Gateway {
			/**
			 * Refund result.
			 *
			 * @var bool|\WP_Error
			 */
			private $refund_result;

			/**
			 * Recorded refund calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $refund_calls = array();

			/**
			 * Constructor.
			 *
			 * @param bool|\WP_Error $refund_result Refund result.
			 */
			public function __construct( $refund_result ) {
				$this->id            = 'native_test_refund_gateway';
				$this->method_title  = 'Native test refund gateway';
				$this->title         = 'Native test refund gateway';
				$this->supports      = array( 'refunds' );
				$this->refund_result = $refund_result;
			}

			/**
			 * Process refund.
			 *
			 * @param int        $order_id Order ID.
			 * @param float|null $amount   Amount.
			 * @param string     $reason   Reason.
			 * @return bool|\WP_Error
			 */
			public function process_refund( $order_id, $amount = null, $reason = '' ) {
				$this->refund_calls[] = array(
					'order_id' => (int) $order_id,
					'amount'   => null === $amount ? null : (float) $amount,
					'reason'   => (string) $reason,
				);

				return $this->refund_result;
			}
		};

		$this->refund_gateway_initializer = static function ( \WC_Payment_Gateways $wc_payment_gateways ) use ( $gateway ): void {
			$wc_payment_gateways->payment_gateways = array( $gateway );
		};

		add_action( 'wc_payment_gateways_initialized', $this->refund_gateway_initializer, 100 );

		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();

		return $gateway;
	}
}
