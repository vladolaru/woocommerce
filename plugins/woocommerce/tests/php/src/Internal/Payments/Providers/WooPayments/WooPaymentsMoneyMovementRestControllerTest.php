<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockWithClaimHook;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\FraudOutcomeTransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentOperationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransactionsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsRestController;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
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
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Transactions routes register.
	 */
	public function test_transactions_routes_register(): void {
		$controller = $this->create_transactions_controller();
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsTransactionsRestController::class, $controller );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/transactions', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/summary', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/search', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/fraud_outcomes/(?P<id>\\w+)/latest', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/transactions/(?P<transaction_id>\\w+)', $routes );
	}

	/**
	 * @testdox Payment detail routes register.
	 */
	public function test_payment_detail_routes_register(): void {
		$controller = $this->create_payment_details_controller();
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsPaymentDetailsRestController::class, $controller );
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
		$this->create_payment_details_controller()->register_routes();

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
	 * @testdox The charge detail's order says whether its payment method is WooPayments: $expected for $payment_method.
	 * @testWith ["bacs", false]
	 *           ["woocommerce_payments", true]
	 *           ["woocommerce_payments_sepa_debit", true]
	 *
	 * @param string $payment_method Order payment method.
	 * @param bool   $expected       Expected field value.
	 */
	public function test_payment_detail_order_says_whether_its_payment_method_is_woopayments( string $payment_method, bool $expected ): void {
		$order = $this->create_order_with_charge( 'ch_test', 'pi_test' );
		$order->set_payment_method( $payment_method );
		$order->save();
		$this->api_client->response = array( 'id' => 'ch_test' );
		$this->create_payment_details_controller()->register_routes();

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/charges/ch_test' ) )->get_data();

		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertSame( $expected, $data['order']['has_woopayments_payment_method'] );
	}

	/**
	 * @testdox Payment detail charge-from-order route requires manage_woocommerce.
	 */
	public function test_payment_detail_charge_from_order_route_requires_manage_woocommerce(): void {
		$order = $this->create_order_for_generated_charge();
		$this->create_payment_details_controller()->register_routes();
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
		$this->create_payment_details_controller()->register_routes();

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
		$this->create_payment_details_controller()->register_routes();

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
		$this->create_payment_details_controller()->register_routes();

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
	 * Oracle: WooPayments 11.1.0 `WC_Payments_API_Client::add_additional_info_to_charge()` (class-wc-payments-api-client.php:2316-2349)
	 * formats the billing address of every charge it returns, which the payment method card prints.
	 *
	 * @testdox Payment detail charge and payment intent routes add the formatted billing address to the charge.
	 */
	public function test_payment_detail_routes_add_formatted_billing_address(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		$this->create_order_with_charge( 'ch_test', 'pi_test' );
		$charge = array(
			'id'                  => 'ch_test',
			'balance_transaction' => array( 'id' => 'txn_test' ),
			'billing_details'     => array(
				'name'    => 'Test customer',
				'address' => array(
					'line1'       => '60 29th Street',
					'line2'       => null,
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94110',
					'country'     => 'US',
				),
			),
		);
		$this->create_payment_details_controller()->register_routes();

		$this->api_client->response = $charge;
		$charge_data                = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/charges/ch_test' ) )->get_data();

		$this->api_client->response = array(
			'id'      => 'pi_test',
			'charges' => array( 'data' => array( $charge ) ),
		);
		$intent_data                = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/payment_intents/pi_test' ) )->get_data();

		$this->assertSame( '60 29th Street<br/>San Francisco, CA 94110', $charge_data['billing_details']['formatted_address'] );
		$this->assertSame( '60 29th Street<br/>San Francisco, CA 94110', $intent_data['charges']['data'][0]['billing_details']['formatted_address'] );
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
		$this->create_payment_details_controller()->register_routes();

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
		$order = $this->create_order_for_payment_intent();
		$this->create_payment_details_controller()->register_routes();
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
		$order = $this->create_order_for_payment_intent();

		$this->api_client->response = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();

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
	 * @testdox Payment detail create-intent route sends Level 3 data for a $country account's order: $sent.
	 *
	 * Client 11.1.0 sets the Level 3 data on the request (includes/admin/class-wc-rest-payments-payment-intents-controller.php:137),
	 * which the request leaves out when it is empty (includes/core/server/request/class-create-intention.php:153-159); the Level 3
	 * service returns data only for a US account.
	 *
	 * @testWith ["US", true]
	 *           ["PL", false]
	 *
	 * @param string $country Account country.
	 * @param bool   $sent    Whether the request carries Level 3 data.
	 */
	public function test_payment_detail_create_intent_route_sends_level3_data_for_us_accounts( string $country, bool $sent ): void {
		$order = $this->create_order_for_payment_intent();
		$order->add_product( \WC_Helper_Product::create_simple_product(), 2 );
		// Level 3 data is sent only when its lines add up to the order total: two units at 10.00.
		$order->set_total( '20.00' );
		$order->save();
		// The platform's create route answers with Stripe's PaymentIntent (wpcom `wcpay/class-intentions-controller.php:389-487`, :481,
		// proxied through `wcpay/class-base-controller.php:476-491`); the route reads only id, amount and charges, so the
		// other PaymentIntent fields are left out.
		$this->api_client->response = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$account_service            = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_account_country' )->willReturn( $country );
		$level3_service = new WooPaymentsLevel3Service();
		$level3_service->init( $account_service );
		$order_service = new WooPaymentsOrderDetailsService();
		$order_service->init( new WooPaymentsOrderDataService(), $level3_service );
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $this->api_client, $order_service, wc_get_container()->get( WooPaymentsAccountService::class ) );
		$controller->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$request_data = $this->api_client->last_call['request_data'];
		$this->assertSame( $sent, array_key_exists( 'level3', $request_data ) );
		if ( $sent ) {
			$level3 = $level3_service->get_data_from_order( wc_get_order( $order->get_id() ) );
			$this->assertNotEmpty( $level3['line_items'] ?? array(), 'The order has line items to send.' );
			// The line items are objects, so they compare by value.
			$this->assertEquals( $level3, $request_data['level3'], 'The request carries the whole Level 3 data, line items included.' );
		}
	}

	/**
	 * @testdox Payment detail create-intent route sends a fresh idempotency key on every request for the same order.
	 *
	 * Client 11.1.0 sets no key on this request, so its transport mints a UUID per request
	 * (includes/wc-payment-api/class-wc-payments-api-client.php:2660-2690); the platform forwards the key to Stripe, which
	 * would replay the first answer, or refuse another payment method, under a fixed key.
	 */
	public function test_payment_detail_create_intent_route_sends_a_fresh_idempotency_key_per_request(): void {
		$order       = $this->create_order_for_payment_intent();
		$http_client = new FakeHttpClient();

		// A nonterminal answer leaves this order payable for the next request.
		$http_client->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode(
				array(
					'id'      => 'pi_created',
					'status'  => 'processing',
					'amount'  => 1234,
					'charges' => array( 'data' => array() ),
				)
			),
		);

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$api_client = new Client();
		$api_client->init( $http_client, $account_service, wc_get_container()->get( TransportLog::class ) );
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $api_client, $this->create_order_service(), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$controller->register_routes();

		foreach ( array( 'pm_declined', 'pm_other' ) as $payment_method ) {
			$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
			$request->set_body_params(
				array(
					'order_id'       => $order->get_id(),
					'customer'       => 'cus_test',
					'payment_method' => $payment_method,
				)
			);
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		}

		$keys = array_map( static fn( array $sent ): string => (string) ( $sent['headers']['Idempotency-Key'] ?? '' ), $http_client->requests );
		$this->assertCount( 2, $keys );
		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $key );
		}
		$this->assertNotSame( $keys[0], $keys[1], 'Each request carries its own key.' );
	}

	/**
	 * Register the real client and serve only its HTTP response locally.
	 *
	 * @param array<string,mixed> $body   Platform JSON body.
	 * @param int                 $status Platform HTTP status.
	 */
	private function register_intent_transport( array $body, int $status = 200 ): FakeHttpClient {
		$http           = new FakeHttpClient();
		$http->response = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);

		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$client  = new Client();
		$client->init( $http, $account, wc_get_container()->get( TransportLog::class ) );
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $client, $this->create_order_service(), $account );
		$controller->register_routes();
		return $http;
	}

	/**
	 * @testdox The intent route reuses the header for an identical key and distinguishes strings that differ by one byte.
	 */
	public function test_caller_key_intent_headers(): void {
		$order = $this->create_order_for_payment_intent();
		$http  = $this->register_intent_transport( $this->card_answer( $order, 'processing' ) );
		$keys  = array( 'a', 'a', ' a', "a\n", '0' );
		foreach ( $keys as $key ) {
			$request = $this->create_payment_intent_request( $order );
			$request->set_param( 'idempotency_key', $key );
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		}

		$this->assertCount( count( $keys ), $http->requests );
		foreach ( $keys as $i => $key ) {
			$this->assertSame( 'wc-intent-' . hash( 'sha256', $key ), $http->requests[ $i ]['headers']['Idempotency-Key'] );
		}
	}

	/**
	 * @testdox The intent route mints a fresh UUID for both an omitted and an explicitly empty caller key.
	 */
	public function test_caller_key_empty_and_absent_intent_headers(): void {
		$order = $this->create_order_for_payment_intent();
		$http  = $this->register_intent_transport( $this->card_answer( $order, 'processing' ) );

		$omitted = $this->create_payment_intent_request( $order );
		$empty   = $this->create_payment_intent_request( $order );
		$empty->set_param( 'idempotency_key', '' );
		foreach ( array( $omitted, $empty ) as $request ) {
			$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );
		}

		$this->assertCount( 2, $http->requests );
		foreach ( $http->requests as $sent ) {
			$this->assertTrue( wp_is_uuid( $sent['headers']['Idempotency-Key'], 4 ), 'A keyless intent must use a UUIDv4.' );
		}
		$this->assertNotSame( $http->requests[0]['headers']['Idempotency-Key'], $http->requests[1]['headers']['Idempotency-Key'] );
	}

	/**
	 * @testdox A non-string JSON key is refused before the intent route claims the order or sends a charge.
	 *
	 * @testWith [null]
	 *           [5]
	 *           [true]
	 *           [false]
	 *           [["a"]]
	 *           [{"key":"a"}]
	 *
	 * @param mixed $key Non-string JSON value.
	 */
	public function test_caller_key_invalid_json_types( $key ): void {
		$order = $this->create_order_for_payment_intent();
		$http  = $this->register_intent_transport( $this->card_answer( $order, 'processing' ) );
		$lock  = new OrderPaymentLockWithClaimHook( '__return_null' );

		$container = $GLOBALS['wc_container'];
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( OrderPaymentLock::class, $lock );

		$request = $this->create_payment_intent_request( $order );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array_merge( $request->get_body_params(), array( 'idempotency_key' => $key ) ) ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertStringContainsString( 'string', $response->get_data()['data']['params']['idempotency_key'] );
		$this->assertFalse( $lock->hook_ran, 'Invalid keys cannot claim the order.' );
		$this->assertCount( 0, $http->requests );
	}

	/**
	 * @testdox Only structured intent conflict codes and types get a 409, with distinct instructions for a request in progress.
	 *
	 * @testWith ["idempotency_error", null, 409, "This idempotency key was already used for a different request."]
	 *           ["invalid_request_error", "idempotency_error", 409, "This idempotency key was already used for a different request."]
	 *           ["idempotency_error", "parameter_invalid", 409, "This idempotency key was already used for a different request."]
	 *           ["invalid_request_error", "parameter_invalid", 500, null]
	 *           ["invalid_request_error", "idempotency_key_in_use", 409, "A request with this idempotency key is already in progress. Retry the same request with the same key."]
	 *           ["idempotency_error", "idempotency_key_in_use", 409, "A request with this idempotency key is already in progress. Retry the same request with the same key."]
	 *
	 * @param string      $type           Stripe error type.
	 * @param string|null $code           Stripe error code, omitted for a different-request conflict.
	 * @param int         $status         Expected REST response status.
	 * @param string|null $mapped_message Expected translated error or null for the original platform message.
	 */
	public function test_caller_key_intent_conflict_response( string $type, ?string $code, int $status, ?string $mapped_message ): void {
		$order   = $this->order_for_card_answer( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$before  = $this->card_snapshot( $order, array() );
		$message = 'Keys for idempotent requests can only be used with the same parameters they were first used with.';
		$error   = array(
			'type'    => $type,
			'message' => $message,
		);
		if ( null !== $code ) {
			$error['code'] = $code;
		}
		$http    = $this->register_intent_transport( array( 'error' => $error ), 'idempotency_key_in_use' === $code ? 409 : 400 );
		$request = $this->create_payment_intent_request( $order );
		$request->set_param( 'idempotency_key', 'intent-first-attempt' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( 409 === $status ? 'wcpay_idempotency_key_conflict' : 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( $mapped_message ?? 'Error: ' . $message, $response->get_data()['message'] );
		$this->assertCount( 1, $http->requests, 'A provider refusal must not trigger a second send.' );
		$this->assertSame( $before, $this->card_snapshot( $order, array() ), 'The refused attempt must leave the payment untouched.' );
	}

	/**
	 * @testdox Repeating a keyed request after its successful charge is recorded returns the paid refusal before any platform request.
	 */
	public function test_caller_key_retry_after_recorded_intent(): void {
		$order   = $this->order_for_card_answer( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$http    = $this->register_intent_transport( $this->card_answer( $order ) );
		$request = $this->create_payment_intent_request( $order );
		$request->set_param( 'idempotency_key', 'intent-first-attempt' );

		$first = $this->server->dispatch( clone $request );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 'pi_answer', $this->stored_card_order( $order )->get_transaction_id() );

		$repeat = $this->server->dispatch( $request );
		$this->assertSame( 409, $repeat->get_status() );
		$this->assertSame( 'wcpay_order_already_paid', $repeat->get_data()['code'] );
		$this->assertCount( 1, $http->requests );
	}

	/**
	 * @testdox The public REST index describes optional caller keys and the replay and concurrent-request outcomes for each route.
	 */
	public function test_caller_key_rest_index(): void {
		$this->create_payment_details_controller()->register_routes();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3' ) );
		$this->assertSame( 200, $response->get_status() );
		$routes       = $response->get_data()['routes'];
		$descriptions = array(
			'payment_intents' => 'Optional. Any non-empty string. Send the same key with the same request again and no second charge is made: you get the first answer back, or a refusal that says the order is already paid. Empty means no key. A concurrent request with the same key gets HTTP 409. Retry the same request with the same key.',
			'refund'          => 'Optional. Any non-empty string. Honoured when this route refunds the charge directly: no order_id, an order_id that does not resolve, or the order_id of an order of another payment method. Not used when order_id names a WooPayments order: that refund goes through the order, which keeps its own protection. Send the same key with the same request again and no second refund is made: you get the first answer back, or a refusal that says why when the first refund is already recorded on the order. Empty means no key. Concurrent requests for the same charge can return HTTP 409 while the order is locked, or HTTP 500 if the payment provider refuses a concurrent request.',
		);
		foreach ( $descriptions as $route => $description ) {
			$endpoint = $routes[ '/wc/v3/payments/' . $route ]['endpoints'][0];
			$this->assertContains( 'POST', $endpoint['methods'] );
			$this->assertArrayHasKey( 'idempotency_key', $endpoint['args'] );
			$key = $endpoint['args']['idempotency_key'];
			$this->assertSame( 'string', $key['type'] );
			$this->assertFalse( $key['required'] );
			$this->assertSame( $description, $key['description'] );
			foreach ( array( 'pattern', 'minLength', 'maxLength' ) as $limit ) {
				$this->assertArrayNotHasKey( $limit, $key );
			}
		}
	}

	/**
	 * The extension logs any failure creating the intent and answers its generic error with the message
	 * (class-wc-rest-payments-payment-intents-controller.php:149-152). The faked failure is the API client's exception
	 * from a platform card error body and its HTTP status.
	 *
	 * @testdox Payment detail create intent route answers a platform failure with the logged 500 wcpay_server_error.
	 */
	public function test_payment_detail_create_intent_route_answers_a_platform_failure_with_the_generic_error(): void {
		$order                       = $this->create_order_for_payment_intent();
		$this->api_client->exception = new ApiException( 'Your card was declined.', 'card_declined', 402 );
		$logger                      = RecordingWcLogger::install();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->create_payment_details_controller()->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( 'Your card was declined.', $response->get_data()['message'] );
		$this->assertSame( array( array( 'error', 'Failed to create an intention via the REST API.', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * The create-intent route answers with the projection client 11.1.0 `prepare_item_for_response()` builds; for the
	 * recorded REC-3 intent, the client's own output is in `Fixtures/payment-intent-rest-response.json`.
	 */
	public function test_payment_detail_create_intent_route_answers_with_the_client_projection(): void {
		$order    = $this->create_order_for_payment_intent();
		$fixtures = __DIR__ . '/Fixtures/';
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local immutable test fixtures.
		$this->api_client->response = json_decode( (string) file_get_contents( $fixtures . 'platform-eur-charge.json' ), true )['entries'][0]['response']['body'];
		$expected                   = json_decode( (string) file_get_contents( $fixtures . 'payment-intent-rest-response.json' ), true )['client_projection'];
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->create_payment_details_controller()->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_UsIeTbmGHPc9jY',
				'payment_method' => 'pm_1UJWs2BzWlxcwgpPrrobU4Cv',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $expected, $response->get_data() );
	}

	/**
	 * @testdox Payment detail create-intent route preserves the plugin-compatible missing-order error shape.
	 */
	public function test_payment_detail_create_intent_route_rejects_missing_order(): void {
		$this->create_payment_details_controller()->register_routes();

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
	 * A payment status update holds the order, as a webhook or a shopper's confirmation does while it applies an event.
	 *
	 * @testdox Payment detail create-intent route answers 409 and sends nothing while another payment operation holds the order.
	 */
	public function test_payment_detail_create_intent_route_is_refused_while_the_order_payment_lock_is_held(): void {
		$order        = $this->create_order_for_payment_intent();
		$lock         = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary   = new WooPaymentsPersistenceVocabulary();
		$holder_token = $lock->claim( $order, $vocabulary, 'pi_order', 'payment status update' );
		$this->assertNotNull( $holder_token );
		$logger = RecordingWcLogger::install();
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_order_payment_locked', $response->get_data()['code'] );
		$this->assertSame( 'A payment operation is already in progress for this order.', $response->get_data()['message'] );
		$this->assertSame( array(), $this->api_client->calls, 'Nothing is sent to the platform.' );
		$this->assertCount( 1, $logger->lines );
		$this->assertMatchesRegularExpression( '/^order payment lock refused: order ' . $order->get_id() . ', refused payment intent creation, held by payment status update for \d+s$/', $logger->lines[0][1] );
		$this->assertTrue( $lock->is_held( $order, $vocabulary ), 'The refused call leaves the holder its lock.' );

		$lock->release( $order, $vocabulary, $holder_token );
	}

	/**
	 * The second call arrives while the first one's platform request is still running.
	 *
	 * @testdox Payment detail create-intent route refuses a second call for the order while the first is in flight.
	 */
	public function test_payment_detail_create_intent_route_refuses_a_second_call_while_the_first_is_in_flight(): void {
		$order = $this->create_order_for_payment_intent();

		$this->api_client = new class() extends RecordingMoneyMovementApiClient {
			/**
			 * Run once inside the next create request, before it answers.
			 *
			 * @var callable|null
			 */
			public $during_create = null;

			/**
			 * Record the create request, run the callback, then answer.
			 *
			 * @param array<string,mixed> $request_data    Request data.
			 * @param string              $idempotency_key Idempotency key.
			 * @return array<string,mixed>
			 */
			public function create_and_confirm_payment_intention( array $request_data, string $idempotency_key ): array {
				$answer   = parent::create_and_confirm_payment_intention( $request_data, $idempotency_key );
				$callback = $this->during_create;
				if ( null !== $callback ) {
					$this->during_create = null;
					$callback();
				}

				return $answer;
			}
		};

		$this->api_client->response = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();
		$second_response                 = null;
		$this->api_client->during_create = function () use ( $order, &$second_response ): void {
			$second_response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		};

		$first_response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertSame( 200, $first_response->get_status() );
		$this->assertNotNull( $second_response );
		$this->assertSame( 409, $second_response->get_status() );
		$this->assertSame( 'wcpay_order_payment_locked', $second_response->get_data()['code'] );
		$this->assertCount( 1, $this->api_client->calls, 'Only the first call reaches the platform.' );
		$this->assertFalse( wc_get_container()->get( OrderPaymentLock::class )->is_held( $order, new WooPaymentsPersistenceVocabulary() ), 'The first call releases the order.' );
	}

	/**
	 * With no other payment operation on the order, the call sends the same request and gives the same answer as before
	 * the route held the lock, and leaves the order free whether the platform answers or fails.
	 *
	 * @testdox Payment detail create-intent route on a free order: $label.
	 *
	 * @testWith ["platform answers", false]
	 *           ["platform fails", true]
	 *
	 * @param string $label          Case label.
	 * @param bool   $platform_fails Whether the platform request fails.
	 */
	public function test_payment_detail_create_intent_route_on_a_free_order_is_unchanged( string $label, bool $platform_fails ): void {
		$order    = $this->create_order_for_payment_intent();
		$fixtures = __DIR__ . '/Fixtures/';
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local immutable test fixtures.
		$this->api_client->response = json_decode( (string) file_get_contents( $fixtures . 'platform-eur-charge.json' ), true )['entries'][0]['response']['body'];
		$projection                 = json_decode( (string) file_get_contents( $fixtures . 'payment-intent-rest-response.json' ), true )['client_projection'];
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $platform_fails ) {
			$this->api_client->exception = new ApiException( 'Your card was declined.', 'card_declined', 402 );
		}
		$logger = RecordingWcLogger::install();
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertCount( 1, $this->api_client->calls );
		$this->assertSame( 'create_and_confirm_payment_intention', $this->api_client->calls[0]['method'] );
		$this->assertSame( '', $this->api_client->calls[0]['idempotency_key'] );
		$this->assertEquals( $this->create_order_service()->build_create_payment_intent_request_from_order( $order, 'cus_test', 'pm_test', false ), $this->api_client->calls[0]['request_data'] );
		if ( $platform_fails ) {
			$this->assertSame( 500, $response->get_status() );
			$this->assertSame( array( 'code', 'message', 'data' ), array_keys( $response->get_data() ) );
			$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
			$this->assertSame( 'Your card was declined.', $response->get_data()['message'] );
		} else {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $projection, $response->get_data() );
			$this->assertSame( array(), $logger->lines, 'Nothing is logged.' );
		}
		$this->assertFalse( wc_get_container()->get( OrderPaymentLock::class )->is_held( $order, new WooPaymentsPersistenceVocabulary() ), 'The order is free again.' );
	}

	/**
	 * Catches a route that reads the attached intent before deciding whether checkout accepts payment.
	 *
	 * @testWith ["processing"]
	 *           ["completed"]
	 *
	 * @param string $status Paid order status.
	 */
	public function test_payment_detail_create_intent_route_refuses_paid_status_before_reading_the_intent( string $status ): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_status( $status );
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->save();
		$this->api_client->responses['get_payment_intention'] = $this->create_intent_answer( $order, 'succeeded' );
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_already_paid', $order );
		$this->assertSame( array(), $this->api_client->calls, 'Nothing is read or sent.' );
	}

	/**
	 * A route that treats a paid date as proof of payment skips the attached intent read.
	 */
	public function test_payment_detail_create_intent_route_reads_succeeded_intent_on_a_pending_order_with_a_paid_date(): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_date_paid( time() );
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->save();
		$this->api_client->responses['get_payment_intention'] = $this->create_intent_answer( $order, 'succeeded' );
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_payment_holds_money', $order );
		$this->assertSame( array( 'get_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * A route that treats a paid date as proof of payment refuses the first card charge.
	 */
	public function test_payment_detail_create_intent_route_charges_a_payable_order_with_a_stale_paid_date(): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_date_paid( time() );
		$order->save();
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * A route using checkout's message asks the caller to retry. Checking subscription type first hides an order that does not need payment.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $payable Whether the subscription needs payment.
	 *
	 * @testdox Create-and-confirm refuses a subscription passed as the order without sending a payment.
	 */
	public function test_payment_detail_create_intent_route_refuses_a_subscription_passed_as_the_order( bool $payable ): void {
		$order = $this->create_order_for_payment_intent();
		if ( ! $payable ) {
			$order->set_status( 'on-hold' );
			$order->save();
		}
		$this->create_payment_details_controller()->register_routes();
		WooCommerceSubscriptionsDoubles::load_subscription_detector();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] = array( $order->get_id() );
		try {
			$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		} finally {
			unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ] );
		}

		$this->assert_refused_with_the_order_free( $response, $payable ? 'wcpay_order_is_subscription' : 'wcpay_order_not_payable', $order );
		$this->assertSame( $payable ? "A subscription can't be charged directly. Charge one of its orders instead." : 'This order does not need payment.', $response->get_data()['message'] );
		$this->assertSame( array(), $this->api_client->calls );
	}

	/**
	 * A route that skips needs_payment() charges an on-hold order while its voucher is payable.
	 */
	public function test_payment_detail_create_intent_route_refuses_an_on_hold_voucher_before_reading_it(): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_status( 'on-hold' );
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->save();
		$intent                           = $this->create_intent_answer( $order, 'requires_action' );
		$intent['payment_method_options'] = array( 'multibanco' => array() );
		$this->api_client->responses['get_payment_intention'] = $intent;
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_not_payable', $order );
		$this->assertSame( array(), $this->api_client->calls );
	}

	/**
	 * Skipping checkout's note hides a prevented duplicate. A generic refusal leaves callers without a way to pay the balance.
	 * R6's zero-call expectation applies only when no intent is attached. With an attached intent, checkout makes one GET.
	 * Both cases return 409 without sending a charge, and the attached intent and charge IDs are preserved.
	 *
	 * @testWith [true, false]
	 *           [false, false]
	 *           [false, true]
	 *           [true, true, true]
	 *
	 * @param bool $prevent        Whether checkout should prevent payment for the paid status.
	 * @param bool $attached       Whether an earlier payment intent is attached.
	 * @param bool $payable_status Whether the store declares Processing payable.
	 */
	public function test_payment_detail_create_intent_route_respects_checkout_for_a_paid_order_made_payable( bool $prevent, bool $attached, bool $payable_status = false ): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_status( 'processing' );
		if ( $attached ) {
			$order->update_meta_data( '_intent_id', 'pi_earlier' );
			$order->update_meta_data( '_charge_id', 'ch_earlier' );
			$this->api_client->responses['get_payment_intention'] = array(
				'id'     => 'pi_earlier',
				'status' => 'requires_payment_method',
			);
		}
		$order->save();
		$this->create_payment_details_controller()->register_routes();
		$paid_filter      = $prevent ? '__return_true' : '__return_false';
		$payable_hook     = $payable_status ? 'woocommerce_valid_order_statuses_for_payment' : 'woocommerce_order_needs_payment';
		$payable_callback = $payable_status ? static fn( $statuses ) => array_merge( $statuses, array( 'processing' ) ) : '__return_true';
		add_filter( $payable_hook, $payable_callback );
		add_filter( 'wcpay_should_prevent_payment_for_paid_order', $paid_filter );
		try {
			$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		} finally {
			remove_filter( 'wcpay_should_prevent_payment_for_paid_order', $paid_filter );
			remove_filter( $payable_hook, $payable_callback );
		}

		$checkout_refuses = $prevent && ! $payable_status;
		$this->assert_refused_with_the_order_free( $response, $checkout_refuses ? 'wcpay_order_already_paid' : 'wcpay_order_paid_status_payable', $order );
		$this->assertSame( $checkout_refuses ? 'This order is already paid.' : "Pay the balance through this order's pay page.", $response->get_data()['message'] );
		$notes = array_keys( $this->card_notes( $order ), 'WooPayments: detected and prevented a second payment for this order, which had already been paid.', true );
		$this->assertCount( $checkout_refuses ? 1 : 0, $notes );
		if ( $attached ) {
			$fresh = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $fresh );
			$this->assertSame( 'pi_earlier', $fresh->get_meta( '_intent_id' ) );
			$this->assertSame( 'ch_earlier', $fresh->get_meta( '_charge_id' ) );
		}
		$this->assertSame( $attached ? array( 'get_payment_intention' ) : array(), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * A route that trusts every stored key refuses an order carrying a copy of another order's attempt.
	 */
	public function test_payment_detail_create_intent_route_ignores_a_copied_charge_key(): void {
		$source = $this->create_order_for_payment_intent();
		$order  = $this->create_order_for_payment_intent();
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'wc-charge-source' );
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_AMBIGUITY_META, array( 'order_id' => $source->get_id() ) );
		$order->save();
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
		$this->assertSame( '', $this->api_client->calls[0]['idempotency_key'] );
	}

	/**
	 * An attached intent that holds money and names the order, on a live read, is refused. Catches a version that narrows
	 * which statuses hold money, or decides on the stored status, which is a failed attempt here.
	 *
	 * @testdox Payment detail create-intent route refuses an order whose attached intent is $live_status on a live read.
	 *
	 * @testWith ["succeeded"]
	 *           ["succeeded", "refunded"]
	 *           ["succeeded", "disputed"]
	 *           ["succeeded", "amount differs"]
	 *           ["requires_capture"]
	 *           ["processing"]
	 *
	 * @param string $live_status Intent status the read answers.
	 * @param string $condition   Condition on the attached payment.
	 */
	public function test_payment_detail_create_intent_route_refuses_an_attached_payment_that_holds_money( string $live_status, string $condition = '' ): void {
		$order = $this->create_order_for_payment_intent();
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->update_meta_data( '_intention_status', 'requires_payment_method' );
		$order->save();
		$intent = $this->create_intent_answer( $order, $live_status );
		if ( 'amount differs' === $condition ) {
			$intent['amount'] = 100;
		} elseif ( '' !== $condition ) {
			$intent['charges']['data'][] = array( $condition => true );
		}
		$this->api_client->responses['get_payment_intention'] = $intent;
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_payment_holds_money', $order );
		$this->assertSame( 'pi_attached', $response->get_data()['data']['intent_id'] );
		$this->assertSame( array( 'get_payment_intention' ), array_column( $this->api_client->calls, 'method' ), 'One read and no charge.' );
	}

	/**
	 * Control: an attached intent that holds no money, or that names another order, lets the charge go through. The order
	 * stores `requires_capture`, so a version that decides on the stored status refuses; a version that drops the order
	 * check refuses the intent of another order.
	 *
	 * @testdox Payment detail create-intent route charges an order whose attached intent is $label.
	 *
	 * @testWith ["requires_payment_method", "requires_payment_method", false]
	 *           ["canceled", "canceled", false]
	 *           ["succeeded for another order", "succeeded", true]
	 *
	 * @param string $label         Case label.
	 * @param string $live_status   Intent status the read answers.
	 * @param bool   $another_order Whether the intent names another order.
	 */
	public function test_payment_detail_create_intent_route_charges_over_an_attached_payment_that_holds_no_money( string $label, string $live_status, bool $another_order ): void {
		$order = $this->create_order_for_payment_intent();
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$this->api_client->responses['get_payment_intention'] = $this->create_intent_answer( $another_order ? $this->create_order_for_payment_intent() : $order, $live_status );
		$this->api_client->response                           = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'get_payment_intention', 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * The live read decides, not the stored status. Catches a version that decides on the stored status.
	 *
	 * @testdox Payment detail create-intent route decides on the live read: stored $stored_status, live $live_status, $expected.
	 *
	 * @testWith ["requires_payment_method", "succeeded", "refused"]
	 *           ["succeeded", "canceled", "charged"]
	 *
	 * @param string $stored_status The order's stored `_intention_status`.
	 * @param string $live_status   Intent status the read answers.
	 * @param string $expected      "refused" or "charged".
	 */
	public function test_payment_detail_create_intent_route_decides_on_the_live_read( string $stored_status, string $live_status, string $expected ): void {
		$order = $this->create_order_for_payment_intent();
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->update_meta_data( '_intention_status', $stored_status );
		$order->save();
		$this->api_client->responses['get_payment_intention'] = $this->create_intent_answer( $order, $live_status );
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		if ( 'refused' === $expected ) {
			$this->assert_refused_with_the_order_free( $response, 'wcpay_order_payment_holds_money', $order );
			return;
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'get_payment_intention', 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * An attached intent that cannot be read is decided by the checkout's own rules: a PHP Error or a stored `succeeded`
	 * refuses in any mode. A started bank step refuses when its outcome is unknown. A version that treats a definite
	 * missing payment as unknown refuses a fresh charge (case e). Catches a version that skips the outright refusal (cases a, b), and one that
	 * skips the stored-record tiers or their mode check (cases d, e, g, h).
	 *
	 * @testdox Payment detail create-intent route over an unreadable attached intent, $label: $expected.
	 *
	 * @testWith ["(a) a PHP error, stored requires_payment_method", "a PHP error", "requires_payment_method", true, "refused"]
	 *           ["(b) a server error, stored succeeded, another mode", "a server error", "succeeded", false, "refused"]
	 *           ["(c) a not-found, stored succeeded, same mode", "a not-found", "succeeded", true, "refused"]
	 *           ["(i) a timeout, stored requires_action, same mode", "a timeout", "requires_action", true, "refused"]
	 *           ["(d) a server error, stored requires_action, same mode", "a server error", "requires_action", true, "refused"]
	 *           ["(e) charges after a definite missing intent for a started bank step", "a not-found", "requires_action", true, "charged"]
	 *           ["(f) a server error, stored requires_payment_method", "a server error", "requires_payment_method", true, "charged"]
	 *           ["(g) an empty answer, stored requires_capture, another mode", "an empty answer", "requires_capture", false, "charged"]
	 *           ["(h) an empty answer, stored processing, same mode", "an empty answer", "processing", true, "refused"]
	 *
	 * @param string $label         Case label.
	 * @param string $failure       How the read fails.
	 * @param string $stored_status The order's stored `_intention_status`.
	 * @param bool   $same_mode     Whether the order stores this store's mode.
	 * @param string $expected      "refused" or "charged".
	 */
	public function test_payment_detail_create_intent_route_over_an_unreadable_attached_payment( string $label, string $failure, string $stored_status, bool $same_mode, string $expected ): void {
		$store_mode = wc_get_container()->get( WooPaymentsAccountService::class )->get_order_mode();
		$order      = $this->create_order_for_payment_intent();
		$order->update_meta_data( '_intent_id', 'pi_attached' );
		$order->update_meta_data( '_intention_status', $stored_status );
		$order->update_meta_data( '_wcpay_mode', $same_mode ? $store_mode : ( 'test' === $store_mode ? 'prod' : 'test' ) );
		$order->save();

		$this->api_client = new class() extends RecordingMoneyMovementApiClient {
			/**
			 * Failure the next intent read throws, if any.
			 *
			 * @var \Throwable|null
			 */
			public ?\Throwable $read_failure = null;

			/**
			 * Record the read, then fail it when told to.
			 *
			 * @param string $intent_id Intent ID.
			 * @return array<string,mixed>
			 * @throws \Throwable When told to.
			 */
			public function get_payment_intention( string $intent_id ): array {
				$answer = parent::get_payment_intention( $intent_id );
				if ( null !== $this->read_failure ) {
					throw $this->read_failure;
				}

				return $answer;
			}
		};

		$failures                       = array(
			'a PHP error'    => new \TypeError( 'A callback on the request failed.' ),
			'a timeout'      => new \RuntimeException( 'The read timed out.' ),
			'a server error' => new ApiException( 'Internal server error.', 'api_error', 500 ),
			'a not-found'    => new ApiException( 'No such payment_intent.', 'resource_missing', 404, 'invalid_request_error' ),
		);
		$this->api_client->read_failure = $failures[ $failure ] ?? null;
		$this->api_client->responses['get_payment_intention'] = array();
		$this->api_client->response                           = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		if ( 'refused' === $expected ) {
			$this->assert_refused_with_the_order_free( $response, 'wcpay_order_payment_unreadable', $order );
			$this->assertSame( array( 'get_payment_intention' ), array_column( $this->api_client->calls, 'method' ), 'One read and no charge.' );
			return;
		}
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'get_payment_intention', 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
	}

	/**
	 * A checkout charge whose key the order keeps is unresolved, so the route sends nothing. Catches a version with no
	 * check on the kept key.
	 *
	 * @testdox Payment detail create-intent route refuses an order with an unresolved checkout charge and sends nothing.
	 */
	public function test_payment_detail_create_intent_route_refuses_an_unresolved_checkout_charge(): void {
		$order = $this->create_order_for_payment_intent();
		$order->update_meta_data( WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META, 'wc-charge-kept' );
		$order->save();
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_payment_unresolved', $order );
		$this->assertSame( array(), $this->api_client->calls, 'Nothing is read or sent.' );
	}

	/**
	 * Another request pays the order just before the route claims it. The route decides on the order as read again under
	 * the lock, so a version that keeps the order it loaded first charges a paid order.
	 *
	 * @testdox Payment detail create-intent route decides on the order as read again under the order payment lock.
	 */
	public function test_payment_detail_create_intent_route_decides_on_the_order_read_under_the_lock(): void {
		$order = $this->create_order_for_payment_intent();
		$lock  = new OrderPaymentLockWithClaimHook(
			function ( WC_Order $locked_order ): void {
				$stored = wc_get_order( $locked_order->get_id() );
				$this->assertInstanceOf( WC_Order::class, $stored );
				$stored->set_status( 'processing' );
				$stored->save();
			}
		);
		$GLOBALS['wc_container']->replace( OrderPaymentLock::class, $lock );
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		$this->assertTrue( $lock->hook_ran );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_order_already_paid', $response->get_data()['code'] );
		$this->assertSame( array(), $this->api_client->calls, 'Nothing is sent.' );
	}

	/**
	 * A refunded order that does not need payment is refused, as the pay page refuses it, with the page path's text. When
	 * a store makes refunded orders payable, the same order charges. Catches a version with no refunded check, and one
	 * that ignores whether the order needs payment.
	 *
	 * @testdox Payment detail create-intent route on a refunded order with no paid date and no attached intent: $label.
	 *
	 * @testWith ["refused", false]
	 *           ["charged when the store makes refunded orders payable", true]
	 *
	 * @param string $label   Case label.
	 * @param bool   $payable Whether a filter adds refunded to the statuses valid for payment.
	 */
	public function test_payment_detail_create_intent_route_on_a_refunded_order( string $label, bool $payable ): void {
		$order = $this->create_order_for_payment_intent();
		$order->set_status( 'refunded' );
		$order->save();
		$this->assertNull( $order->get_date_paid() );
		if ( $payable ) {
			add_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'add_refunded_to_statuses' ) );
		}
		$this->api_client->response = array(
			'id'      => 'pi_created',
			'amount'  => 1234,
			'charges' => array( 'data' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );

		remove_filter( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'add_refunded_to_statuses' ) );
		if ( $payable ) {
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( array( 'create_and_confirm_payment_intention' ), array_column( $this->api_client->calls, 'method' ) );
			return;
		}
		$this->assert_refused_with_the_order_free( $response, 'wcpay_order_refunded', $order );
		$this->assertSame( "This order's payment was refunded, so it can't be paid again. Please place a new order.", $response->get_data()['message'] );
		$this->assertSame( array(), $this->api_client->calls, 'Nothing is read or sent.' );
	}

	/**
	 * Add refunded to the order statuses valid for payment.
	 *
	 * @param string[] $statuses Statuses.
	 * @return string[]
	 */
	public function add_refunded_to_statuses( $statuses ): array {
		return array_merge( (array) $statuses, array( 'refunded' ) );
	}

	/**
	 * Assert a 409 refusal with the given code, and that the refusal left the order payment lock free.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param string            $code     Expected error code.
	 * @param WC_Order          $order    Order.
	 */
	private function assert_refused_with_the_order_free( \WP_REST_Response $response, string $code, WC_Order $order ): void {
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
		$this->assertFalse( wc_get_container()->get( OrderPaymentLock::class )->is_held( $order, new WooPaymentsPersistenceVocabulary() ), 'The refusal leaves the order free.' );
	}

	/**
	 * Build the platform's answer for an intent naming an order.
	 *
	 * @param WC_Order $order  Order the intent names.
	 * @param string   $status Intent status.
	 * @return array<string,mixed>
	 */
	private function create_intent_answer( WC_Order $order, string $status ): array {
		return array(
			'id'       => 'pi_attached',
			'status'   => $status,
			'amount'   => 1234,
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);
	}

	/**
	 * Build a create-intent request for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return WP_REST_Request<array<string,mixed>>
	 */
	private function create_payment_intent_request( WC_Order $order ): WP_REST_Request {
		/** @var WP_REST_Request<array<string,mixed>> $request */
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_body_params(
			array(
				'order_id'       => $order->get_id(),
				'customer'       => 'cus_test',
				'payment_method' => 'pm_test',
			)
		);

		return $request;
	}

	/**
	 * A charge sent before the card gateway is saved leaves its order with the wrong method during the call.
	 * Saving an already selected gateway delays the send without changing the order.
	 *
	 * @testWith [""]
	 *           ["bacs"]
	 *           ["woocommerce_payments_sepa"]
	 *           ["woocommerce_payments"]
	 *           ["woocommerce_payments", "failed"]
	 *
	 * @param string $method Previous method.
	 * @param string $status Payable order status.
	 */
	public function test_create_answer_records_the_payment_under_the_selected_gateway( string $method, string $status = 'pending' ): void {
		$order = $this->order_for_card_answer( $method );
		$order->update_status( $status );
		$saves = 0;
		$count = static function ( $saving ) use ( $order, &$saves ): void {
			if ( $order->get_id() === $saving->get_id() ) {
				++$saves;
			}
		};
		add_action( 'woocommerce_before_order_object_save', $count );
		$seen = array();
		try {
			$response = $this->dispatch_card_answer(
				$order,
				$this->card_answer( $order ),
				function () use ( $order, &$seen, &$saves ): void {
					$fresh = $this->stored_card_order( $order );
					$seen  = array( $fresh->get_payment_method(), $fresh->get_payment_method_title(), $saves );
				}
			);
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $count );
		}
		$this->assertSame( array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'WooPayments card', WooPaymentsPersistenceVocabulary::GATEWAY_ID === $method ? 0 : 1 ), $seen );
		$this->assertSame( 200, $response->get_status() );
		$fresh = $this->stored_card_order( $order );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 'pi_answer', $fresh->get_transaction_id() );
		$this->assertSame( 'pi_answer', $fresh->get_meta( '_intent_id' ) );
		$this->assertSame( 'ch_answer', $fresh->get_meta( '_charge_id' ) );
		$this->assertCount( 1, array_filter( $this->card_notes( $order ), static fn( $note ) => false !== strpos( $note, 'successfully charged' ) ) );
		$this->assertSame( '0.59', $fresh->get_meta( '_wcpay_transaction_fee' ) );
		$this->assertSame( '11.75', $fresh->get_meta( '_wcpay_net' ) );
		$repeat = $this->server->dispatch( $this->create_payment_intent_request( $fresh ) );
		$this->assertSame( 409, $repeat->get_status() );
		$this->assertSame( 'wcpay_order_already_paid', $repeat->get_data()['code'] );
	}

	/**
	 * Selecting the gateway before checking eligibility changes the method on an order the route refuses.
	 *
	 * @testWith ["on-hold"]
	 *           ["processing"]
	 *           ["refunded"]
	 *
	 * @param string $status Order status.
	 */
	public function test_create_refusal_keeps_the_bank_transfer_method( string $status ): void {
		$order = $this->order_for_card_answer( 'bacs' );
		$order->set_status( $status );
		$order->save();
		$this->create_payment_details_controller()->register_routes();
		$methods = array();
		$observe = static function ( $saving ) use ( $order, &$methods ): void {
			if ( $saving->get_id() === $order->get_id() ) {
				$methods[] = $saving->get_payment_method( 'edit' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $observe );
		try {
			$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $observe );
		}
		$this->assertSame( array(), $methods );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'bacs', $this->stored_card_order( $order )->get_payment_method() );
		$this->assertSame( array(), $this->api_client->calls );
	}

	/** Sending without a registered gateway leaves the payment without its card title. */
	public function test_create_without_a_card_gateway_stops_before_the_send(): void {
		$order                                     = $this->order_for_card_answer( 'bacs' );
		WC()->payment_gateways()->payment_gateways = array();
		$this->create_payment_details_controller()->register_routes();
		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( 'bacs', $this->stored_card_order( $order )->get_payment_method() );
		$this->assertSame( array(), $this->api_client->calls );
	}

	/**
	 * Checking the event's paid flag refuses a payable order. Clearing its earlier paid date changes its payment history.
	 *
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $reopened Whether the order was previously in a paid status.
	 */
	public function test_create_records_a_payable_order_with_an_earlier_paid_date_or_paid_filter( bool $reopened ): void {
		$order = $this->order_for_card_answer( '' );
		if ( $reopened ) {
			$order->update_status( 'processing' );
			$order->set_date_paid( 1600000000 );
			$order->update_status( 'pending' );
		} else {
			add_filter( 'woocommerce_order_is_paid', '__return_true' );
		}
		try {
			$response = $this->dispatch_card_answer( $order, $this->card_answer( $order ) );
		} finally {
			remove_filter( 'woocommerce_order_is_paid', '__return_true' );
		}
		$this->assertSame( 200, $response->get_status() );
		$fresh = $this->stored_card_order( $order );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( 'pi_answer', $fresh->get_transaction_id() );
		if ( $reopened ) {
			$this->assertNotNull( $fresh->get_date_paid() );
			$this->assertSame( 1600000000, $fresh->get_date_paid()->getTimestamp() );
		}
	}

	/**
	 * A second application sends another notification, adds another note or schedules another fee job.
	 * An event accepted during the charge changes the order before the route records its answer.
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $during Whether the event also arrives while the route holds the order.
	 */
	public function test_richer_success_event_fills_fees_once_the_create_lock_is_free( bool $during ): void {
		$order  = $this->order_for_card_answer( 'bacs' );
		$answer = $this->card_answer( $order );
		unset( $answer['charges']['data'][0]['application_fee_amount'] );
		$event                   = $answer;
		$event['payment_method'] = array( 'id' => 'pm_card' );
		$event['charges']['data'][0]['payment_method_details']['card']['mandate'] = 'mandate_card';
		$event['charges']['data'][0]['fee_breakdown_v1']['totals']                = array(
			'fee' => array(
				'currency' => 'usd',
				'amount'   => 59,
			),
			'net' => array(
				'currency' => 'usd',
				'amount'   => 1175,
			),
		);
		$writer = wc_get_container()->get( PaymentIntentEventHandler::class );
		$mail   = array();
		$record = static function ( $sent, $message ) use ( &$mail ) {
			$mail[] = $message;
			return true;
		};
		add_filter( 'pre_wp_mail', $record, 10, 2 );
		try {
			$refused  = false;
			$response = $this->dispatch_card_answer(
				$order,
				$answer,
				function () use ( $during, $writer, $event, $order, &$refused, &$mail ): void {
					if ( $during ) {
						$before = $this->card_snapshot( $order, $mail );
						try {
							$writer->process( 'payment_intent.succeeded', $event );
						} catch ( OrderPaymentLockRefusedException $exception ) {
							$refused = true;
						}
						$this->assertSame( $before, $this->card_snapshot( $order, $mail ) );
					}
				}
			);
			$this->assertSame( $during, $refused );
			$this->assertSame( 200, $response->get_status() );
			$before = $this->card_snapshot( $order, $mail );
			$this->assertSame( 'processing', $before['status'] );
			$this->assertCount( 1, $before['jobs'] );
			$this->assertSame( '', $this->stored_card_order( $order )->get_meta( '_wcpay_transaction_fee' ) );
			$writer->process( 'payment_intent.succeeded', $event );
			$this->assertSame( $before, $this->card_snapshot( $order, $mail ) );
			$fresh = $this->stored_card_order( $order );
			$this->assertSame( '0.59', $fresh->get_meta( '_wcpay_transaction_fee' ) );
			$this->assertSame( '11.75', $fresh->get_meta( '_wcpay_net' ) );
		} finally {
			remove_filter( 'pre_wp_mail', $record );
		}
	}

	/**
	 * Applying an authorization to the original order object reopens a cancellation made during the call.
	 */
	public function test_authorization_answer_rereads_a_concurrent_cancellation(): void {
		$order    = $this->order_for_card_answer( 'bacs' );
		$response = $this->dispatch_card_answer(
			$order,
			$this->card_answer( $order, 'requires_capture' ),
			function () use ( $order ): void {
				$this->stored_card_order( $order )->update_status( 'cancelled' );
			}
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', $this->stored_card_order( $order )->get_status() );
		$this->assertSame( array(), array_filter( $this->card_notes( $order ), static fn( $note ) => false !== stripos( $note, 'authoriz' ) ) );
	}

	/** Reporting a writer error as a failed payment invites another charge. */
	public function test_writer_failure_returns_the_intent_and_logs_one_error(): void {
		$order  = $this->order_for_card_answer( 'bacs' );
		$writer = $this->getMockBuilder( PaymentIntentEventHandler::class )->disableOriginalConstructor()->onlyMethods( array( 'apply_succeeded_payment_intent_under_lock' ) )->getMock();
		$writer->expects( $this->once() )->method( 'apply_succeeded_payment_intent_under_lock' )->willThrowException( new \RuntimeException( 'Order write failed.' ) );
		$GLOBALS['wc_container']->replace( PaymentIntentEventHandler::class, $writer );
		$logger   = RecordingWcLogger::install();
		$response = $this->dispatch_card_answer( $order, $this->card_answer( $order ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pi_answer', $response->get_data()['id'] );
		$this->assertSame( array( array( 'error', 'Failed to record the payment intent from the REST API.', 'woopayments' ) ), $logger->get_errors() );
		$this->assert_card_lock_free( $order );
	}

	/** A missing marker or one cleared before the write releases the order while the payment is unsettled. */
	public function test_marker_keeps_the_claim_through_the_send_and_the_writer(): void {
		$order      = $this->order_for_card_answer( 'bacs' );
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$held       = array();
		$check      = static function () use ( $order, $lock, $vocabulary, &$held ): void {
			$lock->release_held_claims();
			$held[] = $lock->is_held( $order, $vocabulary );
		};
		$writer     = $this->getMockBuilder( PaymentIntentEventHandler::class )->disableOriginalConstructor()->onlyMethods( array( 'apply_succeeded_payment_intent_under_lock' ) )->getMock();
		$writer->method( 'apply_succeeded_payment_intent_under_lock' )->willReturnCallback( $check );
		$GLOBALS['wc_container']->replace( PaymentIntentEventHandler::class, $writer );
		$response = $this->dispatch_card_answer( $order, $this->card_answer( $order ), $check );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( true, true ), $held );
		$this->assert_card_lock_free( $order );
	}

	/**
	 * Leaving the authorization to its event misses the hold at the answer. Ignoring an earlier stored intent puts the wrong payment on hold.
	 *
	 * @testWith ["bank"]
	 *           ["old date"]
	 *           ["another intent"]
	 *
	 * @param string $state Earlier order state.
	 */
	public function test_authorization_answer_and_later_event_share_the_same_decision( string $state ): void {
		$order = $this->order_for_card_answer( 'bacs' );
		if ( 'old date' === $state ) {
			$order->set_date_paid( 1600000000 );
		} elseif ( 'another intent' === $state ) {
			$order->update_meta_data( '_intent_id', 'pi_other' );
		}
		$order->save();
		$answer = $this->card_answer( $order, 'requires_capture' );
		if ( 'another intent' === $state ) {
			$reader = $this->getMockBuilder( RecordingMoneyMovementApiClient::class )->onlyMethods( array( 'get_payment_intention' ) )->getMock();
			$reader->expects( $this->once() )->method( 'get_payment_intention' )->with( 'pi_other' )->willThrowException( new ApiException( 'No such payment intent.', 'resource_missing', 404, 'invalid_request_error' ) );
			$this->api_client = $reader;
		}
		$this->api_client->response = $answer;
		$this->create_payment_details_controller()->register_routes();
		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		$this->assertSame( 200, $response->get_status() );
		$fresh = $this->stored_card_order( $order );
		if ( 'another intent' === $state ) {
			$this->assertSame( 'pending', $fresh->get_status() );
			$this->assertCount( 1, array_filter( $this->card_notes( $order ), static fn( $note ) => 1 === preg_match( '/pi_answer.*was authorized, but this order is waiting on payment.*pi_other/', $note ) ) );
			return;
		}
		$this->assertSame( 'on-hold', $fresh->get_status() );
		$this->assertSame( 'pi_answer', $fresh->get_transaction_id() );
		$before = $this->card_snapshot( $order, array() );
		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'payment_intent.amount_capturable_updated', $answer );
		$this->assertSame( $before, $this->card_snapshot( $order, array() ) );
	}

	/** Treating a processing answer as success pays an order before the payment has settled. */
	public function test_processing_answer_leaves_the_order_pending(): void {
		$order    = $this->order_for_card_answer( 'bacs' );
		$response = $this->dispatch_card_answer( $order, $this->card_answer( $order, 'processing' ) );
		$this->assertSame( 200, $response->get_status() );
		$fresh = $this->stored_card_order( $order );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( '', $fresh->get_transaction_id() );
		$this->assertSame( '', $fresh->get_meta( '_intent_id' ) );
	}

	/**
	 * Restoring the earlier method after a decline discards the gateway used for the attempt. Keeping the lock prevents another payment.
	 *
	 * @testWith [""]
	 *           ["bacs"]
	 *
	 * @param string $method Earlier method.
	 */
	public function test_definite_decline_leaves_the_card_selected_and_releases_the_lock( string $method ): void {
		$order                       = $this->order_for_card_answer( $method );
		$this->api_client->exception = new ApiException( 'Declined.', 'card_declined', 402 );
		$this->create_payment_details_controller()->register_routes();
		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		$this->assertSame( 500, $response->get_status() );
		$fresh = $this->stored_card_order( $order );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $fresh->get_payment_method( 'edit' ) );
		$this->assertSame( 'WooPayments card', $fresh->get_payment_method_title( 'edit' ) );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertNull( $fresh->get_date_paid() );
		$this->assertSame( '', $fresh->get_meta( '_intent_id' ) );
		$this->assert_card_lock_free( $order );
	}

	/**
	 * Restoring the bank gateway after an uncertain answer prevents the event from recording the charge.
	 *
	 * @testWith [0, "http_request_failed"]
	 *           [500, "api_error"]
	 *           [200, "invalid_response"]
	 *
	 * @param int    $code HTTP status.
	 * @param string $reason Error code.
	 */
	public function test_uncertain_create_keeps_the_card_method_for_the_event( int $code, string $reason ): void {
		$order = $this->order_for_card_answer( 'bacs' );
		if ( 200 === $code ) {
			$response = $this->dispatch_card_answer( $order, 'unreadable JSON' );
		} else {
			$this->api_client->exception = new ApiException( 'No usable answer.', $reason, $code );
			$this->create_payment_details_controller()->register_routes();
			$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $this->stored_card_order( $order )->get_payment_method( 'edit' ) );
		$this->assert_card_lock_free( $order );
	}

	/** Restoring bank transfer when the builder fails discards the card selection. Keeping the lock blocks the next attempt. */
	public function test_before_send_failure_keeps_the_card_method_and_releases_the_lock(): void {
		$order   = $this->order_for_card_answer( 'bacs' );
		$builder = $this->getMockBuilder( WooPaymentsOrderDetailsService::class )->onlyMethods( array( 'build_create_payment_intent_request_from_order' ) )->getMock();
		$builder->expects( $this->once() )->method( 'build_create_payment_intent_request_from_order' )->willThrowException( new \RuntimeException( 'Request construction failed.' ) );
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $this->api_client, $builder, wc_get_container()->get( WooPaymentsAccountService::class ) );
		$controller->register_routes();
		$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array(), $this->api_client->calls );
		$fresh = $this->stored_card_order( $order );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $fresh->get_payment_method() );
		$this->assertSame( 'WooPayments card', $fresh->get_payment_method_title() );
		$this->assert_card_lock_free( $order );
	}

	/** Restoring the old method when a response filter throws loses the card selection after the charge was sent. */
	public function test_unread_response_keeps_the_card_method_and_releases_the_lock(): void {
		$order = $this->order_for_card_answer( 'bacs' );
		$fail  = static function ( $response, $method, $url, $api ) {
			if ( 'POST' === $method && 'intentions' === $api ) {
				throw new ApiException( 'Unable to process the response.', 'invalid_response', 400 );
			}
			return $response;
		};
		add_filter( 'wcpay_api_request_response', $fail, 10, 4 );
		try {
			$response = $this->dispatch_card_answer( $order, $this->card_answer( $order ) );
		} finally {
			remove_filter( 'wcpay_api_request_response', $fail );
		}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'Unable to process the response.', $response->get_data()['message'] );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $this->stored_card_order( $order )->get_payment_method( 'edit' ) );
		$this->assert_card_lock_free( $order );
	}

	/** Retrying a failed method save writes the order again when the request should already have stopped. */
	public function test_failed_method_save_stops_before_the_charge(): void {
		$order = $this->order_for_card_answer( 'bacs' );
		$this->create_payment_details_controller()->register_routes();
		$saves = 0;
		$fail  = static function ( $saving ) use ( $order, &$saves ): void {
			if ( $order->get_id() === $saving->get_id() ) {
				++$saves;
				throw new \RuntimeException( 'Failed method save.' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $fail );
		try {
			$response = $this->server->dispatch( $this->create_payment_intent_request( $order ) );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $fail );
		}
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 1, $saves );
		$this->assertSame( array(), $this->api_client->calls );
		$this->assert_card_lock_free( $order );
	}

	/**
	 * Create a shippable order with the given payment method.
	 *
	 * @param string $method Previous method.
	 * @return WC_Order
	 */
	private function order_for_card_answer( string $method ): WC_Order {
		$order = $this->create_order_for_payment_intent();
		$order->add_product( \WC_Helper_Product::create_simple_product() );
		$order->set_total( '12.34' );
		$order->set_payment_method( $method );
		if ( '' !== $method ) {
			$order->set_payment_method_title( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $method ? 'WooPayments card' : 'Bank transfer' );
		}
		$order->save();
		return $order;
	}

	/**
	 * Build the raw intent that the create route receives before preparing its REST response.
	 *
	 * @param WC_Order $order Order named by the payment.
	 * @param string   $status Intent status.
	 * @return array<string,mixed>
	 */
	private function card_answer( WC_Order $order, string $status = 'succeeded' ): array {
		return array(
			'id'             => 'pi_answer',
			'status'         => $status,
			'amount'         => 1234,
			'currency'       => 'usd',
			'customer'       => 'cus_test',
			'payment_method' => 'pm_card',
			'metadata'       => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			'charges'        => array(
				'data' => array(
					array(
						'id'                     => 'ch_answer',
						'application_fee_amount' => 59,
						'payment_method_details' => array( 'type' => 'card' ),
					),
				),
			),
		);
	}

	/**
	 * Dispatch through the API client and run a callback inside its fake HTTP request.
	 *
	 * @param WC_Order      $order  Order being charged.
	 * @param array|string  $answer JSON data or an undecodable response body.
	 * @param callable|null $during Work during the send.
	 * @return \WP_REST_Response
	 */
	private function dispatch_card_answer( WC_Order $order, $answer, ?callable $during = null ): \WP_REST_Response {
		$http = $this->getMockBuilder( FakeHttpClient::class )->onlyMethods( array( 'request' ) )->getMock();
		$http->expects( $this->once() )->method( 'request' )->willReturnCallback(
			static function () use ( $answer, $during ): array {
				if ( null !== $during ) {
					$during();
				}
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => is_string( $answer ) ? $answer : wp_json_encode( $answer ),
				);
			}
		);
		$account = wc_get_container()->get( WooPaymentsAccountService::class );
		$client  = new Client();
		$client->init( $http, $account, wc_get_container()->get( TransportLog::class ) );
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $client, $this->create_order_service(), $account );
		$controller->register_routes();
		return $this->server->dispatch( $this->create_payment_intent_request( $order ) );
	}

	/**
	 * Read the order as another caller would see it.
	 *
	 * @param WC_Order $order Order to reload.
	 * @return WC_Order
	 */
	private function stored_card_order( WC_Order $order ): WC_Order {
		$fresh = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * Read every note for the payment.
	 *
	 * @param WC_Order $order Order being checked.
	 * @return string[]
	 */
	private function card_notes( WC_Order $order ): array {
		return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
	}

	/**
	 * Snapshot the order, fee jobs and observed mail before another event delivery.
	 *
	 * @param WC_Order $order Order to check.
	 * @param array    $mail  Observed emails.
	 * @return array<string,mixed>
	 */
	private function card_snapshot( WC_Order $order, array $mail ): array {
		$fresh = $this->stored_card_order( $order );
		$date  = $fresh->get_date_paid();
		return array(
			'status' => $fresh->get_status(),
			'txn'    => $fresh->get_transaction_id(),
			'date'   => $date ? $date->getTimestamp() : null,
			'intent' => $fresh->get_meta( '_intent_id' ),
			'charge' => $fresh->get_meta( '_charge_id' ),
			'notes'  => $this->card_notes( $fresh ),
			'jobs'   => as_get_scheduled_actions(
				array(
					'hook'     => WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
					'per_page' => -1,
				),
				'ids'
			),
			'mail'   => $mail,
		);
	}

	/**
	 * Check that the route released the order.
	 *
	 * @param WC_Order $order Order being checked.
	 */
	private function assert_card_lock_free( WC_Order $order ): void {
		$this->assertFalse( wc_get_container()->get( OrderPaymentLock::class )->is_held( $order, new WooPaymentsPersistenceVocabulary() ) );
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
		$this->create_payment_details_controller()->register_routes();

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
		$this->create_payment_details_controller()->register_routes();

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
	 * @testdox Payment detail timeline for $intention_id reads the manual fraud outcome of the order holding pi_Exact only for the exact ID: $expected.
	 * @testWith ["pi_Exact", true]
	 *           ["pi_exact", false]
	 *
	 * The meta lookup follows the table collation; core's download lookup requires an exact match of the stored value
	 * (WC_Customer_Download_Data_Store::get_downloads()). The platform names no order for the intent here.
	 *
	 * @param string $intention_id Payment intent ID the timeline asks for.
	 * @param bool   $expected     Whether the order's manual outcome is added.
	 */
	public function test_payment_detail_timeline_finds_the_order_only_for_the_exact_intent_id( string $intention_id, bool $expected ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->update_meta_data( '_intent_id', 'pi_Exact' );
		$order->update_meta_data(
			'_wcpay_fraud_outcome_manual_entry',
			array(
				'type'     => 'fraud_outcome_manual_approve',
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
				),
			),
			'get_payment_intention' => array( 'metadata' => array() ),
		);
		$this->create_payment_details_controller()->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/timeline/' . $intention_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			$expected ? array( 'fraud_outcome_manual_approve', 'fraud_outcome_review' ) : array( 'fraud_outcome_review' ),
			wp_list_pluck( $response->get_data()['data'], 'type' )
		);
	}

	/**
	 * The admin routes answer a platform failure with its status when it is a 4xx or 5xx, and 500 for anything else.
	 *
	 * Faked failures are ApiException( message, code, status ), the shape the API client builds from a platform
	 * error body and its HTTP status: an unreadable body keeps the answer's status, 200 included
	 * (wcpay_unparseable_or_null_body), and an encode failure carries none (wcpay_client_unable_to_encode_json, status 0).
	 *
	 * The charge route answers the client's own code with no status (class-wc-rest-payments-charges-controller.php:62-63).
	 *
	 * @testdox Admin route "$path" answers a "$code" platform failure with status $platform_status as $expected_status $expected_code.
	 * @testWith ["transactions", "/wc/v3/payments/transactions/summary", "wcpay_unparseable_or_null_body", 200, 500, "wcpay_unparseable_or_null_body"]
	 *           ["payment details", "/wc/v3/payments/payment_intents/pi_1", "wcpay_client_unable_to_encode_json", 0, 500, "wcpay_client_unable_to_encode_json"]
	 *           ["payment details", "/wc/v3/payments/timeline/pi_1", "wcpay_client_unable_to_encode_json", 0, 500, "wcpay_client_unable_to_encode_json"]
	 *           ["payment details", "/wc/v3/payments/charges/ch_missing", "wcpay_missing_charge", 404, 500, "wcpay_get_charge"]
	 *           ["transactions", "/wc/v3/payments/transactions/summary", "wcpay_bad_request", 400, 400, "wcpay_bad_request"]
	 *
	 * @param string $controller      Controller under test.
	 * @param string $path            Route path.
	 * @param string $code            Platform error code.
	 * @param int    $platform_status Platform HTTP status.
	 * @param int    $expected_status Expected answer status.
	 * @param string $expected_code   Expected answer code.
	 */
	public function test_admin_routes_answer_platform_failures_with_a_4xx_or_5xx( string $controller, string $path, string $code, int $platform_status, int $expected_status, string $expected_code ): void {
		$this->api_client->exception = new ApiException( 'Platform failure.', $code, $platform_status );
		if ( 'transactions' === $controller ) {
			$this->create_transactions_controller()->register_routes();
		} else {
			$this->create_payment_details_controller()->register_routes();
		}

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $path ) );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( $expected_code, $response->get_data()['code'] );
	}

	/**
	 * @testdox Payment detail refund route creates an order-backed WooCommerce refund through the order gateway.
	 */
	public function test_payment_detail_refund_route_creates_order_backed_refund(): void {
		$gateway = $this->register_refund_gateway( true );
		$order   = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller()->register_routes();

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
	 * @testdox Payment detail refund route rejects charge IDs that do not belong to the order.
	 */
	public function test_payment_detail_refund_route_rejects_charge_order_mismatch(): void {
		$this->register_refund_gateway( true );
		$order = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller()->register_routes();

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
		$this->create_payment_details_controller()->register_routes();

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
	 * Orders in a currency with more decimals than the store's prices, the requested refund, and what is refunded.
	 *
	 * @return array<string,array{0:string,1:int,2:float|null}>
	 */
	public function provide_refunds_on_a_store_with_whole_prices(): array {
		return array(
			// The full amount the Transactions page posts (client/data/payment-intents/actions.ts in the extension).
			'full refund of 10.50'          => array( '10.50', 1050, 10.5 ),
			'partial refund of 5.25'        => array( '10.25', 525, 5.25 ),
			'one cent'                      => array( '10.00', 1, 0.01 ),
			// WooCommerce rounds the remaining amount to the store's decimals (10), so 10.25 is above it on both stores.
			'full refund of 10.25, refused' => array( '10.25', 1025, null ),
		);
	}

	/**
	 * @testdox On a store whose prices have no decimals, a payment details refund of $amount minor units on a $total USD order refunds the converted amount unrounded.
	 *
	 * Client 11.1.0 passes WC_Payments_Utils::interpret_stripe_amount() of the amount in the order currency to wc_create_refund()
	 * (includes/admin/class-wc-rest-payments-refunds-controller.php:87-96, includes/class-wc-payments-utils.php:166-174).
	 *
	 * @dataProvider provide_refunds_on_a_store_with_whole_prices
	 *
	 * @param string     $total    Order total, in USD.
	 * @param int        $amount   Requested refund, in minor units.
	 * @param float|null $refunded Amount refunded, or null when the refund is refused.
	 */
	public function test_payment_detail_refund_route_refunds_the_unrounded_amount( string $total, int $amount, ?float $refunded ): void {
		$gateway = $this->register_refund_gateway( true );
		$order   = $this->create_refundable_order_with_charge( $total, 'ch_order' );
		// The order was placed in USD with its own decimals, as core Multi-Currency stores it; the store's prices have none.
		update_option( 'woocommerce_price_num_decimals', 0 );
		$this->create_payment_details_controller()->register_routes();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/refund' );
		$request->set_body_params(
			array(
				'charge_id' => 'ch_order',
				'amount'    => $amount,
				'order_id'  => $order->get_id(),
			)
		);
		$response = $this->server->dispatch( $request );

		if ( null === $refunded ) {
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( array(), $gateway->refund_calls );
			return;
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $refunded, (float) $order->get_refunds()[0]->get_amount() );
		$this->assertSame( $refunded, $gateway->refund_calls[0]['amount'] );
	}

	/**
	 * @testdox Payment detail refund route returns gateway failures without keeping a local refund row.
	 */
	public function test_payment_detail_refund_route_returns_gateway_failure(): void {
		$this->register_refund_gateway( new \WP_Error( 'gateway_failed', 'Gateway failed.' ) );
		$order = $this->create_refundable_order_with_charge( '50.00', 'ch_order' );
		$this->create_payment_details_controller()->register_routes();

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

		$this->assertSame( 500, $response->get_status(), 'A gateway failure is a server fault: wc_create_refund() rewraps it without a status.' );
		$this->assertSame( 'wcpay_refund_payment_failed', $data['code'] );
		$this->assertStringContainsString( 'Gateway failed.', $data['message'] );
		$this->assertCount( 0, $order->get_refunds() );
	}

	/**
	 * @testdox Transactions list preserves reference query names and the legacy request filter.
	 */
	public function test_transactions_list_preserves_filter_contract(): void {
		$this->create_transactions_controller()->register_routes();

		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ): TransactionsListRequest {
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
		$this->create_transactions_controller()->register_routes();

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
	 * @testdox Transactions list maps a merchant's order search before the list request filter, so a callback reads the charge ID.
	 *
	 * Client 11.3.0 maps the terms in List_Transactions::set_search() (class-list-transactions.php:170-175), reached from
	 * from_rest_request() (:101, :104), before Request::send() applies the filter.
	 */
	public function test_transactions_list_filter_reads_the_mapped_order_search(): void {
		$order = $this->create_order_with_charge( 'ch_n', 'pi_n' );
		$this->create_transactions_controller()->register_routes();
		$seen = array();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( &$seen ): TransactionsListRequest {
				$seen[] = $request->get_param( 'search' );

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' );
		$request->set_query_params( array( 'search' => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ) ) );
		$this->server->dispatch( $request );

		$this->assertSame( array( array( 'ch_n' ) ), $seen );
		$this->assertSame( array( 'ch_n' ), $this->api_client->last_call['query']['search'] );
	}

	/**
	 * @testdox Transactions list sends a search term a callback sets through set_param() as written, as client 11.3.0 does.
	 *
	 * Client 11.3.0 runs nothing after the filter (class-wc-rest-payments-transactions-controller.php:144-149), and its
	 * set_param() does not map.
	 */
	public function test_transactions_list_sends_a_callback_set_param_search_as_written(): void {
		$order = $this->create_order_with_charge( 'ch_m', 'pi_m' );
		$term  = __( 'Order #', 'woocommerce' ) . $order->get_id();
		$this->create_transactions_controller()->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( $term ): TransactionsListRequest {
				$request->set_param( 'search', array( $term ) );

				return $request;
			}
		);

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) );

		$this->assertSame( array( $term ), $this->api_client->last_call['query']['search'] );
	}

	/**
	 * @testdox Transactions list maps a search term a callback sets through set_search() exactly once, as client 11.3.0 does.
	 */
	public function test_transactions_list_maps_a_callback_set_search_term_once(): void {
		$order = $this->create_order_with_charge( 'ch_m', 'pi_m' );
		$spy   = new class() extends WooPaymentsOrderDetailsService {
			/**
			 * Number of search mapping calls.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Count the call and map.
			 *
			 * @param array<string,mixed> $params Transaction request params.
			 * @return array<string,mixed>
			 */
			public function map_transaction_search_params( array $params ): array {
				++$this->calls;

				return parent::map_transaction_search_params( $params );
			}
		};
		$spy->init( new WooPaymentsOrderDataService(), wc_get_container()->get( WooPaymentsLevel3Service::class ) );
		wc_get_container()->replace( WooPaymentsOrderDetailsService::class, $spy );
		$controller = new WooPaymentsTransactionsRestController();
		$controller->init( $this->api_client, $spy );
		$controller->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( $order ): TransactionsListRequest {
				$request->set_search( array( __( 'Order #', 'woocommerce' ) . $order->get_id() ) );

				return $request;
			}
		);

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) );

		$this->assertSame( array( 'ch_m' ), $this->api_client->last_call['query']['search'] );
		$this->assertSame( 1, $spy->calls, 'The callback term should be mapped exactly once.' );
	}

	/**
	 * @testdox Transactions list sends GET transactions with the returned request's params, whatever API or method that request names.
	 *
	 * The list request filter fires in the controller and only the returned request's params are kept (register row 523).
	 */
	public function test_transactions_list_ignores_a_returned_request_api_and_method(): void {
		$this->create_transactions_controller()->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function () {
				$other = new class() extends TransactionsListRequest {
					/**
					 * Another API.
					 *
					 * @return string
					 */
					public function get_api(): string {
						return 'deposits';
					}

					/**
					 * Another method.
					 *
					 * @return string
					 */
					public function get_method(): string {
						return 'POST';
					}
				};
				$other->set_param( 'extension_custom_param', 'other-request' );

				return $other;
			}
		);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );
		$this->assertSame( 'other-request', $this->api_client->last_call['query']['extension_custom_param'] );
	}

	/**
	 * @testdox The Payments > Transactions page's list, summary and export requests reach the platform unchanged ($scenario).
	 * @testWith ["without a search", false]
	 *           ["with an order search", true]
	 *
	 * The queries are the ones the page builds (admin/money-movement/transactions-list.tsx:138-145, query.ts:332-336).
	 *
	 * @param string $scenario   Case.
	 * @param bool   $with_order Whether the merchant searches for an order.
	 */
	public function test_transactions_page_requests_reach_the_platform_unchanged( string $scenario, bool $with_order ): void {
		unset( $scenario );
		$order = $this->create_order_with_charge( 'ch_page', 'pi_page' );
		$this->create_transactions_controller()->register_routes();
		$query = array(
			'page'          => '1',
			'pagesize'      => '25',
			'sort'          => 'date',
			'direction'     => 'desc',
			'user_timezone' => '+00:00',
		);
		if ( $with_order ) {
			$query['search'] = array( __( 'Order #', 'woocommerce' ) . $order->get_id() );
		}
		$search = $with_order ? array( 'search' => array( 'ch_page' ) ) : array();

		$list = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' );
		$list->set_query_params( $query );
		$this->server->dispatch( $list );
		$this->assertSame(
			array(
				'page'      => 1,
				'pagesize'  => 25,
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => 100,
			) + $search,
			$this->api_client->last_call['query']
		);

		$summary = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/summary' );
		$summary->set_query_params( $query );
		$this->server->dispatch( $summary );
		$this->assertSame( 'get_transactions_summary', $this->api_client->last_call['method'] );
		$this->assertSame( $search, $this->api_client->last_call['filters'] );

		$export = new WP_REST_Request( 'POST', '/wc/v3/payments/transactions/download' );
		$export->set_body_params( $query + array( 'user_email' => 'merchant@example.com' ) );
		$this->server->dispatch( $export );
		$this->assertSame( 'start_transactions_export', $this->api_client->last_call['method'] );
		$this->assertSame( $search, $this->api_client->last_call['filters'] );
	}

	/**
	 * @testdox Transactions list flags a payment whose order holds an early fraud warning, and leaves the key out otherwise.
	 *
	 * Client 11.2.0 class-list-transactions.php:309-316 (tests/unit/core/server/request/test-class-list-transactions-request.php:238-290).
	 * The meta has the shape the early fraud warning webhook writes (EarlyFraudWarningEventHandler).
	 */
	public function test_transactions_list_flags_an_early_fraud_warning(): void {
		$warned = $this->create_order_with_charge( 'ch_warned', 'pi_warned' );
		$warned->update_meta_data(
			WooPaymentsPersistenceVocabulary::EARLY_FRAUD_WARNING_META_KEY,
			array(
				'efw_id'         => 'issfr_warned',
				'efw_actionable' => true,
				'efw_type'       => 'card_never_received',
				'created'        => 1752969600,
			)
		);
		$warned->save();
		$this->create_order_with_charge( 'ch_clean', 'pi_clean' );
		$this->api_client->response = array(
			'data' => array(
				array(
					'id'        => 'txn_warned',
					'charge_id' => 'ch_warned',
				),
				array(
					'id'        => 'txn_clean',
					'charge_id' => 'ch_clean',
				),
			),
		);
		$this->create_transactions_controller()->register_routes();

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' ) )->get_data();

		$this->assertSame(
			array(
				'actionable' => true,
				'fraud_type' => 'card_never_received',
			),
			$data['data'][0]['early_fraud_warning'] ?? null
		);
		$this->assertArrayHasKey( 'order', $data['data'][1] );
		$this->assertArrayNotHasKey( 'early_fraud_warning', $data['data'][1] );
	}

	/**
	 * @testdox Transactions list and summary expose the same provider-owned row and totals to the admin client.
	 */
	public function test_transactions_list_and_summary_preserve_rows_and_totals(): void {
		$this->create_transactions_controller()->register_routes();
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
		$this->create_transactions_controller()->register_routes();

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
		$this->assertSame( 'start_transactions_export', $this->api_client->last_call['method'] );
		$this->assertSame( 'usd', $this->api_client->last_call['filters']['store_currency_is'] );
		$this->assertSame( array( 'charge', 'refund' ), $this->api_client->last_call['filters']['type_is_in'] );
		$this->assertArrayNotHasKey( 'ignored', $this->api_client->last_call['filters'] );
		$this->assertSame( 'merchant@example.com', $this->api_client->last_call['user_email'] );
		$this->assertSame( 'en_US', $this->api_client->last_call['locale'] );
	}

	/**
	 * @testdox Transactions summary and export send the filters with order searches mapped, no paging, and run no list filter.
	 *
	 * Client 11.1.0 builds both from get_transactions_filters() (class-wc-rest-payments-transactions-controller.php:177-224) and
	 * maps order searches in its API client (class-wc-payments-api-client.php get_transactions_summary(), get_transactions_export()).
	 */
	public function test_transactions_summary_and_export_send_only_filters(): void {
		$order = $this->create_order_with_charge( 'ch_order', 'pi_order' );
		$this->create_transactions_controller()->register_routes();
		$list_filter_calls = 0;
		add_filter(
			'wcpay_list_transactions_request',
			static function ( $request ) use ( &$list_filter_calls ) {
				++$list_filter_calls;

				return $request;
			}
		);
		$query = array(
			'store_currency_is' => 'eur',
			'page'              => 2,
			'pagesize'          => 50,
			'sort'              => 'amount',
			'search'            => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
		);

		$summary = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/summary' );
		$summary->set_query_params( $query );
		$this->server->dispatch( $summary );
		$summary_filters = $this->api_client->last_call['filters'];

		$export = new WP_REST_Request( 'POST', '/wc/v3/payments/transactions/download' );
		$export->set_body_params( $query + array( 'user_email' => 'merchant@example.com' ) );
		$this->server->dispatch( $export );

		$expected = array(
			'store_currency_is' => 'eur',
			'search'            => array( 'ch_order' ),
		);
		$this->assertSame( $expected, $summary_filters );
		$this->assertSame( $expected, $this->api_client->last_call['filters'] );
		$this->assertSame( 0, $list_filter_calls );
	}

	/**
	 * A list request filter callback that returns something other than the request stops the request with the API client
	 * path's error, as the extension's request classes end it with a 500 (includes/core/server/class-request.php:528-569, :770-783).
	 *
	 * @testdox A $hook callback returning no request on $path answers 500 wcpay_invalid_filtered_request.
	 * @testWith ["/wc/v3/payments/transactions", "wcpay_list_transactions_request", false]
	 *           ["/wc/v3/payments/transactions", "wcpay_list_transactions_request", true]
	 *           ["/wc/v3/payments/transactions/fraud-outcomes?status=block", "wcpay_list_fraud_outcome_transactions_request", false]
	 *
	 * @param string $path      Route path.
	 * @param string $hook      Request filter.
	 * @param bool   $as_object Whether the callback returns an object of another class.
	 */
	public function test_list_filter_returning_no_request_fails_the_request( string $path, string $hook, bool $as_object ): void {
		$this->create_transactions_controller()->register_routes();
		add_filter(
			$hook,
			static function () use ( $as_object ) {
				return $as_object ? new \stdClass() : null;
			}
		);

		$parts   = explode( '?', $path );
		$request = new WP_REST_Request( 'GET', $parts[0] );
		if ( isset( $parts[1] ) ) {
			parse_str( $parts[1], $query );
			$request->set_query_params( $query );
		}
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_filtered_request', $response->get_data()['code'] );
		$this->assertSame( "Invalid WooPayments request returned by {$hook}.", $response->get_data()['message'] );
	}

	/**
	 * @testdox Transaction dates are shifted from the merchant's time zone to the store's for the list, summary and export.
	 *
	 * Client 11.1.0 format_transaction_date_with_timestamp() (class-wc-rest-payments-transactions-controller.php:275-293): a
	 * store in Chicago (UTC-5 in June) and a browser at +03:00 move 10:00 by eight hours.
	 */
	public function test_transaction_dates_shift_by_the_merchant_time_zone(): void {
		update_option( 'timezone_string', 'America/Chicago' );
		$this->create_transactions_controller()->register_routes();
		$query = array(
			'date_after'    => '2026-06-18 10:00:00',
			'date_between'  => array( '2026-06-18 10:00:00', '2026-06-19 10:00:00' ),
			'user_timezone' => '+03:00',
		);

		$list = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions' );
		$list->set_query_params( $query );
		$this->server->dispatch( $list );
		$list_query = $this->api_client->last_call['query'];

		$summary = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/summary' );
		$summary->set_query_params( $query );
		$this->server->dispatch( $summary );
		$summary_filters = $this->api_client->last_call['filters'];

		$export = new WP_REST_Request( 'POST', '/wc/v3/payments/transactions/download' );
		$export->set_body_params( $query + array( 'user_email' => 'merchant@example.com' ) );
		$this->server->dispatch( $export );

		foreach ( array( $list_query, $summary_filters, $this->api_client->last_call['filters'] ) as $sent ) {
			$this->assertSame( '2026-06-18 18:00:00', $sent['date_after'] );
			$this->assertSame( array( '2026-06-18 18:00:00', '2026-06-19 18:00:00' ), $sent['date_between'] );
		}
	}

	/**
	 * @testdox The $route route requires manage_woocommerce before API calls.
	 * @testWith ["/wc/v3/payments/transactions"]
	 *           ["/wc/v3/payments/fraud_outcomes/pi_test/latest"]
	 *
	 * @param string $route Route requested without a user.
	 */
	public function test_routes_require_manage_woocommerce( string $route ): void {
		$this->create_transactions_controller()->register_routes();
		wp_set_current_user( 0 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $route ) );

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
		$this->create_transactions_controller()->register_routes();

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
		$this->create_transactions_controller()->register_routes();

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
		$this->create_transactions_controller()->register_routes();

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
		$this->create_transactions_controller()->register_routes();
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
				static function ( FraudOutcomeTransactionsListRequest $request ) use ( &$hooks_seen, $hook ): FraudOutcomeTransactionsListRequest {
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
	 * @testdox A fraud outcome request handed to a filter carries its hook, so a callback can send it, and refuses an unknown status.
	 *
	 * Client 11.1.0 requests are sent under their assigned hook, and get_api() rejects any status but allow, block and review
	 * (class-list-fraud-outcome-transactions.php:40-45).
	 */
	public function test_fraud_outcome_request_carries_its_hook_and_refuses_unknown_status(): void {
		$this->create_transactions_controller()->register_routes();
		$filtered = null;
		add_filter(
			'wcpay_list_fraud_outcome_transactions_request',
			static function ( $request ) use ( &$filtered ) {
				$filtered = $request;

				return $request;
			}
		);
		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' );
		$request->set_query_params( array( 'status' => 'review' ) );
		$this->server->dispatch( $request );

		$this->assertInstanceOf( FraudOutcomeTransactionsListRequest::class, $filtered );
		$this->assertSame( 'wcpay_list_fraud_outcome_transactions_request', $filtered->get_hook() );
		$this->assertSame( 'fraud_outcomes/status/review', $filtered->get_api() );

		$filtered->set_status( 'review/../../accounts' );
		$this->expectException( ApiException::class );
		$filtered->get_api();
	}

	/**
	 * @testdox Fraud outcome routes shape every platform row, like client 11.1.0 List_Fraud_Outcome_Transactions::format_response().
	 */
	public function test_fraud_outcome_routes_enrich_every_platform_row(): void {
		$order = $this->create_fraud_outcome_order( 'Ada', 'block', 'canceled', 1.00, false );
		$this->create_transactions_controller()->register_routes();
		$logger                     = RecordingWcLogger::install();
		$this->api_client->response = array(
			'data' => array_fill( 0, 1001, array( 'order_id' => $order->get_id() ) ),
		);

		$counts = array();
		foreach ( array( '', '/summary', '/search', '/download' ) as $suffix ) {
			$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' . $suffix );
			$request->set_query_params( array( 'status' => 'block' ) );
			$response = $this->server->dispatch( $request );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status(), 'fraud-outcomes' . $suffix );
			$counts[ $suffix ] = '/summary' === $suffix ? $data['count'] : count( '/search' === $suffix ? $data : $data['data'] );
		}

		$this->assertSame(
			array(
				''          => 25,
				'/summary'  => 1001,
				'/search'   => 1001,
				'/download' => 1001,
			),
			$counts,
			'Every platform row must reach shaping; only the list route pages.'
		);
		$this->assertSame( array(), $logger->lines );
	}

	/**
	 * @testdox Fraud outcome routes keep only the rows the status and search ask for, sorted and paged, like client 11.1.0.
	 * @dataProvider fraud_outcome_shaping_provider
	 *
	 * Client 11.1.0 List_Fraud_Outcome_Transactions::format_response() filters by status and search and sorts
	 * (class-list-fraud-outcome-transactions.php:120-275); its API client pages, sums and builds the autocomplete
	 * (class-wc-payments-api-client.php:323-410).
	 *
	 * @param string              $suffix   Route suffix after /payments/transactions/fraud-outcomes.
	 * @param array<string,mixed> $query    Query params; '#bob' in a search term becomes Bob's order number.
	 * @param array<int,string>   $expected Orders expected in the answer, in order; for the summary, the expected count.
	 */
	public function test_fraud_outcome_routes_shape_rows_like_the_list_request( string $suffix, array $query, array $expected ): void {
		$orders = array(
			'ada' => $this->create_fraud_outcome_order( 'Ada', 'review', 'requires_capture', 10.50, false ),
			'bob' => $this->create_fraud_outcome_order( 'Bob', 'block', 'canceled', 7.25, false ),
			'cy'  => $this->create_fraud_outcome_order( 'Cy', 'review_blocked', 'canceled', 3.00, false ),
			'dee' => $this->create_fraud_outcome_order( 'Dee', 'review', 'requires_capture', 1.00, true ),
		);
		$refund = wc_create_refund(
			array(
				'order_id' => $orders['dee']->get_id(),
				'amount'   => 0,
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$this->api_client->response = array(
			array(
				'order_id'          => $orders['ada']->get_id(),
				'payment_intent_id' => 'pi_platform',
				'created'           => 300,
			),
			array(
				'order_id' => $orders['bob']->get_id(),
				'created'  => 100,
			),
			array(
				'order_id' => $orders['cy']->get_id(),
				'created'  => 200,
			),
			array(
				'order_id' => $orders['dee']->get_id(),
				'created'  => 400,
			),
			array(
				'order_id' => $refund->get_id(),
				'created'  => 500,
			),
			array(
				'order_id' => PHP_INT_MAX,
				'created'  => 600,
			),
		);
		$this->create_transactions_controller()->register_routes();

		if ( isset( $query['search'] ) ) {
			$query['search'] = str_replace( '#bob', '#' . $orders['bob']->get_id(), $query['search'] );
		}
		if ( isset( $query['search_term'] ) ) {
			$query['search_term'] = (string) $orders[ $query['search_term'] ]->get_id();
		}

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/transactions/fraud-outcomes' . $suffix );
		$request->set_query_params( $query );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$ids = array_map(
			static function ( string $name ) use ( $orders ): int {
				return $orders[ $name ]->get_id();
			},
			$expected
		);

		if ( '/summary' === $suffix ) {
			$total = 0;
			foreach ( $expected as $name ) {
				$total += (int) round( (float) $orders[ $name ]->get_total() * 100 );
			}
			$this->assertSame(
				array(
					'count'      => count( $expected ),
					'total'      => $total,
					'currencies' => array( 'usd' ),
				),
				$data
			);
			return;
		}

		if ( '/search' === $suffix ) {
			$keys = array_map(
				static function ( int $id ): string {
					return 'customer-' . $id;
				},
				$ids
			);
			array_unshift( $keys, 'order-' . $query['search_term'] );
			$this->assertSame( $keys, array_column( $data, 'key' ) );
			$this->assertSame( __( 'Order #', 'woocommerce' ) . $query['search_term'], $data[0]['label'] );
			$this->assertSame( 'Ada Probe', $data[ array_search( 'customer-' . $orders['ada']->get_id(), $keys, true ) ]['label'] );
			return;
		}

		$rows = $data['data'];
		$this->assertSame( $ids, array_column( $rows, 'order_id' ) );
		foreach ( $rows as $row ) {
			$this->assertArrayNotHasKey( 'manual_review', $row );
			$this->assertArrayNotHasKey( 'payment_intent_id', $row );
		}
	}

	/**
	 * Fraud outcome shaping rows.
	 *
	 * @return array<string,array{string,array<string,mixed>,array<int,string>}>
	 */
	public function fraud_outcome_shaping_provider(): array {
		return array(
			'review keeps pending reviews only'     => array( '', array( 'status' => 'review' ), array( 'ada' ) ),
			'block keeps block and review_blocked'  => array( '', array( 'status' => 'block' ), array( 'cy', 'bob' ) ),
			'allow keeps every order, newest first' => array( '', array( 'status' => 'allow' ), array( 'dee', 'ada', 'cy', 'bob' ) ),
			'ascending date'                        => array(
				'',
				array(
					'status'    => 'allow',
					'direction' => 'asc',
				),
				array( 'bob', 'cy', 'ada', 'dee' ),
			),
			'sort by amount'                        => array(
				'',
				array(
					'status' => 'allow',
					'sort'   => 'amount',
				),
				array( 'ada', 'bob', 'cy', 'dee' ),
			),
			'search by order number'                => array(
				'',
				array(
					'status' => 'allow',
					'search' => array( '#bob' ),
				),
				array( 'bob' ),
			),
			'search by name, any case'              => array(
				'',
				array(
					'status' => 'allow',
					'search' => array( 'ADA' ),
				),
				array( 'ada' ),
			),
			'second page'                           => array(
				'',
				array(
					'status'   => 'allow',
					'page'     => 2,
					'pagesize' => 2,
				),
				array( 'cy', 'bob' ),
			),
			'download is not paged'                 => array(
				'/download',
				array(
					'status'   => 'allow',
					'pagesize' => 1,
				),
				array( 'dee', 'ada', 'cy', 'bob' ),
			),
			'summary of allowed orders'             => array( '/summary', array( 'status' => 'allow' ), array( 'dee', 'ada', 'cy', 'bob' ) ),
			'summary of blocked orders'             => array( '/summary', array( 'status' => 'block' ), array( 'cy', 'bob' ) ),
			'autocomplete with an order number'     => array(
				'/search',
				array(
					'status'      => 'allow',
					'search_term' => 'ada',
				),
				array( 'dee', 'ada', 'cy', 'bob' ),
			),
		);
	}

	/**
	 * Create an order a fraud outcome row points at.
	 *
	 * @param string $first_name    Billing first name.
	 * @param string $box_type      Fraud meta box type.
	 * @param string $intent_status Payment intent status.
	 * @param float  $total         Order total.
	 * @param bool   $reviewed      Whether a merchant already reviewed it.
	 * @return WC_Order
	 */
	private function create_fraud_outcome_order( string $first_name, string $box_type, string $intent_status, float $total, bool $reviewed ): WC_Order {
		$order = $this->create_order_with_charge( 'ch_' . strtolower( $first_name ), 'pi_' . strtolower( $first_name ) );
		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( 'Probe' );
		$order->set_total( $total );
		$order->update_meta_data( '_intention_status', $intent_status );
		$order->update_meta_data( '_wcpay_fraud_meta_box_type', $box_type );
		if ( $reviewed ) {
			$order->update_meta_data( '_wcpay_fraud_outcome_manual_entry', array( 'type' => 'fraud_outcome_manual_approve' ) );
		}
		$order->save();

		return $order;
	}

	/**
	 * Create a transactions controller.
	 *
	 * @return WooPaymentsTransactionsRestController
	 */
	private function create_transactions_controller(): WooPaymentsTransactionsRestController {
		$controller = new WooPaymentsTransactionsRestController();
		$controller->init( $this->api_client, $this->create_order_service() );

		return $controller;
	}

	/**
	 * Create a payment details controller.
	 *
	 * @return WooPaymentsPaymentDetailsRestController
	 */
	private function create_payment_details_controller(): WooPaymentsPaymentDetailsRestController {
		$controller = new WooPaymentsPaymentDetailsRestController();
		$controller->init( $this->api_client, $this->create_order_service(), wc_get_container()->get( WooPaymentsAccountService::class ) );

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
	 * Create an unpaid order for create-intent route tests, with no attached intent.
	 *
	 * @return WC_Order
	 */
	private function create_order_for_payment_intent(): WC_Order {
		$gateway        = new class() extends \WC_Payment_Gateway {};
		$gateway->id    = WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		$gateway->title = 'WooPayments card';
		WC()->payment_gateways()->payment_gateways[ $gateway->id ] = $gateway;

		$order = $this->create_order_for_generated_charge();
		$order->delete_meta_data( '_intent_id' );
		$order->delete_meta_data( '_intent_status' );
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
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->update_meta_data( '_intent_id', 'pi_order' );
		$order->save();

		return $order;
	}

	/**
	 * Register a local refundable gateway, standing in for the WooPayments gateway, for refund route tests.
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
				$this->id            = WooPaymentsPersistenceVocabulary::GATEWAY_ID;
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
