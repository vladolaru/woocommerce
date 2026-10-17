<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalPaymentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeeDetailsNoteController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\SubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Helper_Order;
use WC_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsTerminalPaymentsRestController class.
 */
class WooPaymentsTerminalPaymentsRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsTerminalPaymentsRestController
	 */
	private $sut;

	/**
	 * Recording API client.
	 *
	 * @var RecordingTerminalApiClient
	 */
	private RecordingTerminalApiClient $api_client;

	/**
	 * Mocked WooPayments gateway settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $gateway_settings = array();

	/**
	 * Cached account data the account service double answers.
	 *
	 * @var array<string,mixed>
	 */
	private array $account_data = array();

	/**
	 * Number of account-data refreshes requested from the account service.
	 *
	 * @var int
	 */
	private int $account_refresh_calls = 0;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client            = new RecordingTerminalApiClient();
		$this->gateway_settings      = array();
		$this->account_data          = array();
		$this->account_refresh_calls = 0;
		$this->sut                   = $this->create_controller();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		unset( $_GET['change_payment_method'], $GLOBALS['wcpay_test_subscription_ids'] );
		// Guest customer creation stores the customer in the shared session; leave none for later classes.
		if ( WC()->session ) {
			WC()->session->set( 'wcpay_customer_id', null );
		}
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Terminal intent creation sends the order amount, lower-case currency, metadata, and card-present defaults.
	 */
	public function test_create_terminal_intent_builds_reference_payload(): void {
		$order = $this->create_order( 12.34, 'USD' );
		// A store order number that differs from the order ID, as sequential order number plugins set it.
		$order_number = 'T-' . $order->get_id();
		add_filter(
			'woocommerce_order_number',
			static function () use ( $order_number ) {
				return $order_number;
			}
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'customer_id', 'cus_terminal' );
		$request->set_param( 'metadata', array( 'channel' => 'mobile' ) );

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'pi_terminal' ), $response->get_data() );
		$this->assertSame( 1234, $this->api_client->last_terminal_intent_payload['amount'] );
		$this->assertSame( 'usd', $this->api_client->last_terminal_intent_payload['currency'] );
		$this->assertSame( 'cus_terminal', $this->api_client->last_terminal_intent_payload['customer'] );
		$this->assertSame( 'mobile', $this->api_client->last_terminal_intent_payload['metadata']['channel'] );
		$this->assertSame( (string) $order->get_id(), $this->api_client->last_terminal_intent_payload['metadata']['order_id'] );
		$this->assertSame( $order_number, $this->api_client->last_terminal_intent_payload['metadata']['order_number'] );
		// The extension's description (includes/core/server/request/trait-intention.php:36-46), with no blog id, as no site is connected here.
		$this->assertSame(
			sprintf( 'Online Payment for Order #%s for %s', $order_number, str_replace( array( 'https://', 'http://' ), '', get_site_url() ) ),
			$this->api_client->last_terminal_intent_payload['description']
		);
		$this->assertSame( array( 'card_present' ), $this->api_client->last_terminal_intent_payload['payment_method_types'] );
		$this->assertSame( 'manual', $this->api_client->last_terminal_intent_payload['capture_method'] );
	}

	/**
	 * @testdox Terminal intent creation forwards app-supplied metadata verbatim like the reference mobile route.
	 */
	public function test_create_terminal_intent_forwards_metadata_verbatim(): void {
		$order = $this->create_order( 12.34, 'USD' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param(
			'metadata',
			array(
				'readerID'    => 'rdr_ABC',
				'pos.session' => 'session-42',
				'channel'     => 'mobile',
			)
		);

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$metadata = $this->api_client->last_terminal_intent_payload['metadata'];

		// Mixed-case and dotted keys must reach the provider unrenamed - the apps
		// reconcile on the exact metadata names, and the platform is the boundary.
		$this->assertSame( 'rdr_ABC', $metadata['readerID'] );
		$this->assertSame( 'session-42', $metadata['pos.session'] );
		$this->assertSame( 'mobile', $metadata['channel'] );

		// Internal order metadata is still appended.
		$this->assertSame( (string) $order->get_id(), $metadata['order_id'] );
		$this->assertSame( $order->get_order_number(), $metadata['order_number'] );
	}

	/**
	 * Like the reference mobile route, an invalid payment method payload or an unsupported payment or capture method is refused
	 * instead of silently defaulting.
	 *
	 * @testdox Terminal intent creation refuses $_dataName and creates no intent.
	 * @dataProvider provide_invalid_terminal_intent_params
	 *
	 * @param string $param Request parameter.
	 * @param mixed  $value Its invalid value.
	 */
	public function test_create_terminal_intent_refuses_an_invalid_param( string $param, $value ): void {
		$order   = $this->create_order();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( $param, $value );

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->last_terminal_intent_payload );
	}

	/**
	 * Invalid terminal intent parameters.
	 *
	 * @return array<string,array{0:string,1:mixed}>
	 */
	public function provide_invalid_terminal_intent_params(): array {
		return array(
			'payment methods sent as a string' => array( 'payment_methods', 'card_present' ),
			'an unsupported payment method'    => array( 'payment_methods', array( 'card' ) ),
			'an unsupported capture method'    => array( 'capture_method', 'later' ),
		);
	}

	/**
	 * @testdox Order-scoped terminal routes match numeric order IDs and reject non-numeric ones.
	 */
	public function test_order_scoped_terminal_routes_match_the_reference_route_split(): void {
		$this->sut->register_routes();

		$order = $this->create_order( 12.34, 'USD' );

		// A valid numeric order ID still routes to the handler.
		$numeric_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' )
		);
		$this->assertSame( 200, $numeric_response->get_status() );

		// The reference client uses \w+ on the terminal order routes, so a
		// non-numeric ID reaches the handler and yields wcpay_missing_order.
		$non_numeric_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/orders/notnumeric/create_terminal_intent' )
		);
		$this->assertSame( 404, $non_numeric_response->get_status() );
		$this->assertSame( 'wcpay_missing_order', $non_numeric_response->get_data()['code'] );

		// create_customer is the one route the reference client pins to \d+.
		$customer_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/orders/notnumeric/create_customer' )
		);
		$this->assertSame( 404, $customer_response->get_status() );
		$this->assertSame( 'rest_no_route', $customer_response->get_data()['code'] );
	}

	/**
	 * @testdox Terminal preparation rejects invalid intent IDs before forwarding to WPCOM.
	 */
	public function test_prepare_terminal_payment_rejects_invalid_intent_id(): void {
		$order   = $this->create_order();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/prepare_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', '../pi_bad' );

		$response = $this->sut->prepare_terminal_payment( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_invalid_payment_intent_id', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->prepared_terminal_payments );
	}

	/**
	 * @testdox Customer creation updates an existing Stripe customer stored on the order.
	 */
	public function test_create_customer_updates_existing_order_customer(): void {
		$order = $this->create_order();
		$order->set_billing_email( 'ada@example.com' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_existing' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_existing' ), $response->get_data() );
		$this->assertSame( 'cus_existing', $this->api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'ada@example.com', $this->api_client->updated_customers[0]['customer_data']['email'] );
	}

	/**
	 * @testdox Customer creation recreates a missing Stripe customer stored on the order.
	 */
	public function test_create_customer_recreates_missing_existing_order_customer(): void {
		$order = $this->create_order();
		$order->set_billing_email( 'missing@example.com' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_missing' );
		$order->save();

		$this->api_client->created_customer_id       = 'cus_recreated';
		$this->api_client->update_customer_exception = new WooPaymentsApiException( 'No such customer', 'resource_missing', 404 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_recreated' ), $response->get_data() );
		$this->assertSame( 'cus_recreated', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cus_missing', $this->api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'missing@example.com', $this->api_client->created_customers[0]['email'] );
	}

	/**
	 * @testdox Customer creation answers the user's own customer when the platform does not find the order's, and recreates the user's when that is the one missing: $_dataName.
	 *
	 * Client 11.1.0 reads the order's customer first and, when the platform does not find it, deletes and recreates the
	 * user's customer (includes/admin/class-wc-rest-payments-orders-controller.php:480-492,
	 * includes/class-wc-payments-customer-service.php:200-222, :375-385). Here the user's stored customer is used instead
	 * when it is not the missing one, so a customer another plugin left on the order does not cost the user their saved cards.
	 *
	 * @dataProvider provider_missing_order_customers
	 *
	 * @param string $order_customer_id Customer on the order, which the platform does not find.
	 * @param string $expected_customer Customer answered and stored on the order and the user.
	 */
	public function test_create_customer_uses_the_users_customer_when_the_order_customer_is_missing( string $order_customer_id, string $expected_customer ): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );

		$order = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->update_meta_data( '_stripe_customer_id', $order_customer_id );
		$order->save();

		$this->api_client->created_customer_id       = 'cus_recreated';
		$this->api_client->update_customer_exception = new WooPaymentsApiException( "No such customer: '$order_customer_id'", 'resource_missing', 404 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => $expected_customer ), $response->get_data() );
		$this->assertSame( $expected_customer, $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertCount( 'cus_recreated' === $expected_customer ? 1 : 0, $this->api_client->created_customers );
		$this->assertSame( $expected_customer, get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * A customer another plugin left on the order, and the user's own customer gone from the platform.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_missing_order_customers(): array {
		return array(
			'a Stripe Gateway customer on the order' => array( 'cus_StripeGatewayLeftover', 'cus_user' ),
			"the user's own customer"                => array( 'cus_user', 'cus_recreated' ),
		);
	}

	/**
	 * @testdox Customer creation for a guest order creates a guest customer and leaves the logged-in merchant's customer alone.
	 *
	 * The app's request is the merchant's, so the payer comes from the order, as client 11.1.0's route reads it
	 * (includes/admin/class-wc-rest-payments-orders-controller.php:479-494).
	 */
	public function test_create_customer_for_a_guest_order_does_not_use_the_logged_in_merchants_customer(): void {
		$merchant_id = get_current_user_id();
		update_user_option( $merchant_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_merchant' );
		$order = $this->create_order();
		$order->set_customer_id( 0 );
		$order->save();
		$this->api_client->created_customer_id = 'cus_guest_created';

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_guest_created' ), $response->get_data() );
		$this->assertSame( array(), $this->api_client->updated_customers );
		$this->assertStringEndsWith( ', Guest', $this->api_client->created_customers[0]['description'] );
		$this->assertSame( 'cus_merchant', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $merchant_id ) );
	}

	/**
	 * @testdox Customer creation updates a cached user customer with the order billing data.
	 */
	public function test_create_customer_updates_cached_user_customer_with_order_data(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );

		$order = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'order@example.com' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_user' ), $response->get_data() );
		$this->assertSame( 'cus_user', $order->get_meta( '_stripe_customer_id', true ) );
		$this->assertSame( 'cus_user', $this->api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'order@example.com', $this->api_client->updated_customers[0]['customer_data']['email'] );
		$this->assertSame( 'cus_user', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Customer creation updates the customer the order carries and leaves the user's own customer stored.
	 *
	 * Client 11.1.0 updates the customer it read from the order without storing it on the user
	 * (includes/class-wc-payments-customer-service.php:200-222); storing it would point the user at another customer.
	 */
	public function test_create_customer_keeps_the_users_customer_when_the_order_carries_another(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );

		$order = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->update_meta_data( '_stripe_customer_id', 'cus_earlier_payer' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_earlier_payer' ), $response->get_data() );
		$this->assertSame( 'cus_earlier_payer', $this->api_client->updated_customers[0]['customer_id'] );
		$this->assertSame( 'cus_user', get_user_option( WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, $user_id ) );
	}

	/**
	 * @testdox Customer creation should update a cached user customer despite an unrelated subscription request value.
	 */
	public function test_create_customer_updates_cached_user_customer_with_an_unrelated_subscription_request_value(): void {
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_option( $user_id, WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_user' );

		$order = $this->create_order();
		$order->set_customer_id( $user_id );
		$order->set_billing_email( 'ordinary-order@example.com' );
		$order->save();

		$this->fake_wcs_is_subscription();
		$GLOBALS['wcpay_test_subscription_ids'] = array( $order->get_id() + 1 );
		$_GET['change_payment_method']          = (string) ( $order->get_id() + 1 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_customer' );
		$request->set_param( 'order_id', $order->get_id() );

		$response = $this->sut->create_customer( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'id' => 'cus_user' ), $response->get_data() );
		$this->assertCount( 1, $this->api_client->updated_customers );
		$this->assertSame( 'ordinary-order@example.com', $this->api_client->updated_customers[0]['customer_data']['email'] );
	}

	/**
	 * Define a test-only wcs_is_subscription() backed by $GLOBALS['wcpay_test_subscription_ids'].
	 */
	private function fake_wcs_is_subscription(): void {
		if ( function_exists( 'wcs_is_subscription' ) ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test-only shim for the WooCommerce Subscriptions predicate.
		eval( 'namespace { function wcs_is_subscription( $subscription_id ) { $subscription_id = is_object( $subscription_id ) && method_exists( $subscription_id, "get_id" ) ? $subscription_id->get_id() : $subscription_id; return in_array( $subscription_id, $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ) || in_array( absint( $subscription_id ), $GLOBALS["wcpay_test_subscription_ids"] ?? array(), true ); } }' );
	}

	/**
	 * @testdox Capturing a terminal payment preserves WooPayments order meta and receipt URL.
	 */
	public function test_capture_terminal_payment_updates_order_state_and_receipt_url(): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array(
						'id'                     => 'ch_terminal',
						'payment_method'         => 'pm_terminal',
						'payment_method_details' => array(
							'type'         => 'card_present',
							'card_present' => array(
								'brand' => 'visa',
								'last4' => '4242',
							),
						),
					),
				),
			),
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array(
				'data' => array(
					array(
						'id'             => 'ch_terminal',
						'payment_method' => 'pm_terminal',
					),
				),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'status' => 'succeeded',
				'id'     => 'pi_terminal',
			),
			$response->get_data()
		);
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'WooCommerce In-Person Payments', $order->get_payment_method_title() );
		$this->assertSame( 'pi_terminal', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_terminal', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $order->get_meta( '_wcpay_payment_method_details', true ), $order->get_meta( '_wcpay_raw_payment_method_details', true ) );
		$this->assertStringContainsString( '/wc/v3/payments/readers/receipts/pi_terminal', (string) $order->get_meta( 'receipt_url', true ) );
		$this->assertSame( 'test', $order->get_meta( '_wcpay_mode', true ) );
		// Plugin 11.1.0 stores the intent model's uppercased currency (class-wc-rest-payments-orders-controller.php:215, class-wc-payments-api-payment-intention.php:93).
		$this->assertSame( 'USD', $order->get_meta( '_wcpay_intent_currency', true ) );
	}

	/**
	 * @testdox Terminal capture on a live account stores the WooPayments extension's live order mode.
	 *
	 * Plugin 11.1.0 stores `Order_Mode::PRODUCTION` (`prod`), not the account mode `live` (class-order-mode.php:21).
	 */
	public function test_capture_terminal_payment_on_live_account_stores_prod_order_mode(): void {
		$this->sut                                    = $this->create_controller( false );
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal_live',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
			'charges'  => array( 'data' => array( array( 'id' => 'ch_terminal_live' ) ) ),
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal_live',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'charges'  => array( 'data' => array( array( 'id' => 'ch_terminal_live' ) ) ),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal_live' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'prod', $order->get_meta( '_wcpay_mode', true ) );
	}

	/**
	 * A card-reader capture writes the client's authorized and capture notes and schedules the Fee details job; an
	 * intent the reader already captured (Interac) writes the payment note and schedules it too
	 * (class-wc-rest-payments-orders-controller.php:217, :228; class-wc-payments-order-service.php:1582, :1612-1628, :1659-1685).
	 *
	 * @dataProvider terminal_capture_paths
	 *
	 * @param string   $intent_status Status of the intent the app sends.
	 * @param string[] $note_kinds    Notes the client writes, in order.
	 */
	public function test_capture_terminal_payment_writes_woopayments_extension_notes_and_schedules_fee_details_job( string $intent_status, array $note_kinds ): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$intent                                       = array(
			'id'       => 'pi_terminal_notes',
			'status'   => $intent_status,
			'currency' => 'usd',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
			'charges'  => array( 'data' => array( array( 'id' => 'ch_terminal_notes' ) ) ),
		);
		$this->api_client->payment_intention_response = $intent;
		$this->api_client->captured_intention_response = array_merge( $intent, array( 'status' => 'succeeded' ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal_notes' );
		$this->assertInstanceOf( WP_REST_Response::class, $this->sut->capture_terminal_payment( $request ) );

		$order        = wc_get_order( $order->get_id() );
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$expected     = array(
			'authorized' => $note_service->format_payment_authorized_note_candidates( $order, 'pi_terminal_notes', 'ch_terminal_notes' )[0],
			'captured'   => $note_service->format_capture_success_note_candidates( $order, 'pi_terminal_notes', 'ch_terminal_notes' )[0],
			// The client never sets `_wcpay_mode` on a terminal order before this note, so it reads as a live payment.
			'paid'       => $note_service->format_payment_success_note_candidates( $order, 'pi_terminal_notes', 'ch_terminal_notes', '', '' )[0],
		);
		$contents     = array_reverse( wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
		$written      = array_values( array_intersect( $contents, $expected ) );
		$this->assertSame( array_map( static fn( string $kind ): string => $expected[ $kind ], $note_kinds ), $written );
		$this->assertTrue(
			as_has_scheduled_action(
				WooPaymentsFeeDetailsNoteController::ADD_FEE_BREAKDOWN_TO_ORDER_NOTES_ACTION,
				array(
					'order_id'     => $order->get_id(),
					'intent_id'    => 'pi_terminal_notes',
					'is_test_mode' => false,
				),
				'woocommerce_payments'
			)
		);
	}

	/**
	 * A card-reader capture stores the fee its capture response carries, alone, as the client's capture does; an
	 * intent the reader already captured stores none (class-wc-rest-payments-orders-controller.php:228,
	 * class-wc-payments-order-service.php:1681, :1775-1781).
	 *
	 * @dataProvider terminal_capture_fee_cases
	 *
	 * @param string      $intent_status Status of the intent the app sends.
	 * @param string|null $expected_fee  Fee meta the client stores, or null for none.
	 */
	public function test_capture_terminal_payment_stores_the_woopayments_extension_fee_meta( string $intent_status, ?string $expected_fee ): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$charge                                       = array(
			'id'                     => 'ch_terminal_fee',
			'captured'               => true,
			'currency'               => 'usd',
			'amount'                 => 1234,
			'application_fee_amount' => 61,
		);
		$intent                                       = array(
			'id'       => 'pi_terminal_fee',
			'status'   => $intent_status,
			'currency' => 'usd',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
			'charges'  => array( 'data' => array( $charge ) ),
		);
		$this->api_client->payment_intention_response = $intent;
		$this->api_client->captured_intention_response = array_merge( $intent, array( 'status' => 'succeeded' ) );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal_fee' );
		$this->assertInstanceOf( WP_REST_Response::class, $this->sut->capture_terminal_payment( $request ) );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( null !== $expected_fee, $order->meta_exists( '_wcpay_transaction_fee' ) );
		if ( null !== $expected_fee ) {
			$this->assertSame( $expected_fee, $order->get_meta( '_wcpay_transaction_fee', true ) );
		}
		$this->assertFalse( $order->meta_exists( '_wcpay_net' ) );
	}

	/**
	 * Terminal capture paths and the fee meta the client stores on each.
	 *
	 * @return array<string,array{string,string|null}>
	 */
	public function terminal_capture_fee_cases(): array {
		return array(
			'card reader authorization, captured by the store' => array( 'requires_capture', '0.61' ),
			'Interac, captured by the reader' => array( 'succeeded', null ),
		);
	}

	/**
	 * Terminal capture paths and the notes the client writes on each.
	 *
	 * @return array<string,array{string,string[]}>
	 */
	public function terminal_capture_paths(): array {
		return array(
			'card reader authorization, captured by the store' => array( 'requires_capture', array( 'authorized', 'captured' ) ),
			'Interac, captured by the reader' => array( 'succeeded', array( 'paid' ) ),
		);
	}

	/**
	 * @testdox Terminal capture persists the IPP channel before the order completes, so POS email suppression sees it.
	 */
	public function test_capture_terminal_payment_writes_ipp_channel_before_completion(): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id'    => (string) $order->get_id(),
				'ipp_channel' => 'mobile_pos',
			),
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id'    => (string) $order->get_id(),
				'ipp_channel' => 'mobile_pos',
			),
		);

		$channel_at_completion = null;
		$capture_channel       = function ( $order_id, $completed_order ) use ( &$channel_at_completion ) {
			unset( $order_id );
			$channel_at_completion = $completed_order->get_meta( '_wcpay_ipp_channel', true );
		};
		add_action( 'woocommerce_order_status_completed', $capture_channel, 10, 2 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		remove_action( 'woocommerce_order_status_completed', $capture_channel, 10 );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'mobile_pos', $channel_at_completion, 'The IPP channel must be on the order when the completion transition fires.' );
		$this->assertSame( 'mobile_pos', $order->get_meta( '_wcpay_ipp_channel', true ) );
	}

	/**
	 * @testdox Terminal capture merges order-derived metadata under the intent's own.
	 */
	public function test_capture_terminal_payment_merges_order_metadata_with_intent_metadata(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.com' );
		$order->save();

		$intent_metadata                               = array(
			'order_id'      => (string) $order->get_id(),
			'ipp_channel'   => 'mobile_pos',
			'reader_ID'     => 'rdr_123',
			'customer_name' => 'App Override',
		);
		$this->api_client->payment_intention_response  = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => $intent_metadata,
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => $intent_metadata,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$sent = $this->api_client->last_capture_metadata;
		$this->assertSame( 'ada@example.com', $sent['customer_email'] );
		$this->assertSame( $order->get_order_key(), $sent['order_key'] );
		$this->assertSame( esc_url( get_site_url() ), $sent['site_url'] );
		$this->assertSame( 'single', (string) $sent['payment_type'] );
		$this->assertSame( 'rdr_123', $sent['reader_ID'] );
		$this->assertSame( 'mobile_pos', $sent['ipp_channel'] );
		$this->assertSame( 'App Override', $sent['customer_name'], 'Intent metadata must override order-derived keys (mobile app priority).' );
	}

	/**
	 * @testdox Terminal capture does not write an IPP channel the WooPayments extension does not recognize.
	 */
	public function test_capture_terminal_payment_ignores_unrecognized_ipp_channel(): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id'    => (string) $order->get_id(),
				'ipp_channel' => 'carrier_pigeon',
			),
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id'    => (string) $order->get_id(),
				'ipp_channel' => 'carrier_pigeon',
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( '', $order->get_meta( '_wcpay_ipp_channel', true ) );
	}

	/**
	 * @testdox An amount-too-small capture failure in $currency notes the provider minimum.
	 *
	 * Client 11.1.0 appends the minimum with WC_Payments_Utils::format_explicit_currency( interpret_stripe_amount( ... ) )
	 * (includes/class-wc-payment-gateway-wcpay.php:4003-4009): wc_price() in the currency's locale-info format, then the code
	 * when the result lacks it. For EUR that is a comma decimal and the symbol on the right after a space
	 * (WC_Payments_Utils::get_woocommerce_price_format() 'right_space', includes/class-wc-payments-utils.php:1147-1148).
	 *
	 * @dataProvider provide_amount_too_small_minimums
	 *
	 * @param string $currency          Order and intent currency.
	 * @param string $provider_message  Platform error message.
	 * @param string $expected_sentence The minimum sentence the note carries.
	 */
	public function test_capture_failure_note_carries_the_amount_too_small_minimum( string $currency, string $provider_message, string $expected_sentence ): void {
		$order = $this->create_order( 0.30, $currency );
		$this->api_client->payment_intention_response_queue = array(
			array(
				'id'       => 'pi_terminal',
				'status'   => 'requires_capture',
				'currency' => strtolower( $currency ),
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
				),
			),
			array(
				'id'     => 'pi_terminal',
				'status' => 'requires_capture',
			),
		);
		$this->api_client->captured_intention_exception     = new WooPaymentsApiException(
			$provider_message,
			'amount_too_small',
			400,
			'',
			'',
			array(
				'minimum_amount' => 50,
				'currency'       => strtolower( $currency ),
			)
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$note = $this->get_order_note_containing( $order, 'The minimum amount to capture is' );
		$this->assertNotEmpty( $note, 'The failure note must carry the appended minimum-amount sentence.' );
		$this->assertStringContainsString( $provider_message . ' ' . $expected_sentence, wp_strip_all_tags( $note ) );
	}

	/**
	 * Minimum-amount refusals and the sentence the client writes for each.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provide_amount_too_small_minimums(): array {
		return array(
			'USD' => array( 'USD', 'Amount must be at least $0.50 usd', 'The minimum amount to capture is $0.50 USD.' ),
			'EUR' => array( 'EUR', 'Amount must be at least €0.50 eur', 'The minimum amount to capture is 0,50 € EUR.' ),
		);
	}

	/**
	 * @testdox Provider error markup arrives inert in the capture-failure note.
	 */
	public function test_capture_failure_note_escapes_provider_markup(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response_queue = array(
			array(
				'id'       => 'pi_terminal',
				'status'   => 'requires_capture',
				'currency' => 'usd',
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
				),
			),
			array(
				'id'     => 'pi_terminal',
				'status' => 'requires_capture',
			),
		);
		$this->api_client->captured_intention_exception     = new WooPaymentsApiException( 'Declined <a href="https://evil.example">verify</a>.', 'wcpay_capture_error', 402 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$note = $this->get_order_note_containing( $order, 'Declined' );
		$this->assertNotEmpty( $note );
		$this->assertStringContainsString( '&lt;a href=', $note, 'Provider markup must be encoded inert, matching the reference esc_html().' );
		$this->assertStringNotContainsString( '<a href="https://evil.example">', $note );
		$this->assertStringNotContainsString( '<a href=', $response->get_error_message(), 'The answer escapes the provider message as the client does.' );
	}

	/**
	 * @testdox A pre-check intent fetch failure returns the error without marking the order.
	 */
	public function test_precheck_fetch_failure_does_not_mark_the_order(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->update_status( 'on-hold' );
		$this->api_client->payment_intention_exception = new WooPaymentsApiException( 'Connection lost.', 'wcpay_fetch_error', 500 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		// Client 11.1.0 lets the intent read's exception reach its Throwable catch (class-wc-rest-payments-orders-controller.php:289-291).
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'A pre-check failure must not stamp capture-failure state on the order.' );
		$this->assertSame( '', $this->get_order_note_containing( $order, 'capture' ), 'A pre-check failure must not leave a capture note on the order.' );
	}

	/**
	 * @testdox Terminal capture rejects intents without matching order metadata.
	 */
	public function test_capture_terminal_payment_rejects_intent_without_order_metadata(): void {
		$logger = RecordingWcLogger::install();
		$this->enable_terminal_debug_logging();
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_intent_order_mismatch', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		// Client 11.1.0 logs the rejection (class-wc-rest-payments-orders-controller.php:204-205).
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
		$this->assertNotSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
	}

	/**
	 * @testdox Terminal capture rejects a capture request for an intent that does not match the order's already-stored intent.
	 *
	 * Client parity: `class-wc-rest-payments-orders-controller.php:187-193`
	 * (`WC_Payments_Order_Service::INTENT_ID_META_KEY` on the order not matching the requested
	 * `payment_intent_id` returns `wcpay_payment_uncapturable`, 409) before ever calling the API.
	 */
	public function test_capture_terminal_payment_rejects_a_different_stored_intent(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->update_meta_data( '_intent_id', 'pi_stored' );
		$order->save();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_requested' );

		$response = $this->sut->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_payment_uncapturable', $response->get_error_code() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->last_capture_metadata, 'No capture must be attempted once the stored intent mismatch is found.' );
	}

	/**
	 * @testdox Terminal capture returns an error when the capture result does not succeed.
	 */
	public function test_capture_terminal_payment_returns_error_when_capture_result_is_not_succeeded(): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);
		$this->api_client->captured_intention_response = array(
			'id'        => 'pi_terminal',
			'status'    => 'requires_capture',
			'message'   => 'Capture failed.',
			'http_code' => 400,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_capture_error', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertStringContainsString( 'Capture failed.', $response->get_error_message() );
		// Client 11.1.0 stores the intent before its capture (includes/admin/class-wc-rest-payments-orders-controller.php:211), so it stays after a failure.
		$this->assertSame( 'pi_terminal', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status', true ), 'A failed capture must record the still-capturable authorization like the plugin.' );
		$this->assertNotEmpty( $this->get_order_note_containing( $order, 'failed' ), 'A failed capture must leave a failure note on the order.' );
	}

	/**
	 * @testdox A terminal capture attempted against an expired authorization fails the order with the expired note.
	 */
	public function test_capture_terminal_payment_expired_authorization_fails_the_order(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->update_status( 'on-hold' );
		$this->api_client->payment_intention_response_queue = array(
			array(
				'id'       => 'pi_terminal',
				'status'   => 'requires_capture',
				'currency' => 'usd',
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
				),
			),
			array(
				'id'     => 'pi_terminal',
				'status' => 'canceled',
			),
		);
		$this->api_client->captured_intention_exception     = new WooPaymentsApiException( 'Capture failed.', 'wcpay_capture_error', 402 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'failed', $order->get_status(), 'An expired authorization must fail the order like the charge.expired webhook.' );
		$this->assertSame( 'canceled', $order->get_meta( '_intention_status', true ) );
		$this->assertNotEmpty( $this->get_order_note_containing( $order, 'expired' ) );
	}

	/**
	 * @testdox A terminal capture exception with a still-live authorization keeps the order status and adds the failure note.
	 */
	public function test_capture_terminal_payment_exception_with_live_intent_adds_failure_note(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->update_status( 'on-hold' );
		$this->api_client->payment_intention_response_queue = array(
			array(
				'id'       => 'pi_terminal',
				'status'   => 'requires_capture',
				'currency' => 'usd',
				'metadata' => array(
					'order_id' => (string) $order->get_id(),
				),
			),
			array(
				'id'     => 'pi_terminal',
				'status' => 'requires_capture',
			),
		);
		// The platform's capture error envelope: code, message and HTTP status (client class-wc-payments-api-client.php:2852-2871, :2906-2909).
		$this->api_client->captured_intention_exception = new WooPaymentsApiException( 'The card was declined at capture.', 'card_declined', 402 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		// Client 11.1.0 answers every failed capture with its own code and prefixed message (class-wc-rest-payments-orders-controller.php:228-252).
		$this->assertSame( 'wcpay_capture_error', $response->get_error_code() );
		$this->assertSame( 'Payment capture failed to complete with the following message: The card was declined at capture.', $response->get_error_message() );
		$this->assertSame( 402, $response->get_error_data()['status'] );
		$this->assertSame( 'on-hold', $order->get_status(), 'A plain capture failure must leave the original authorization active.' );
		$this->assertNotEmpty( $this->get_order_note_containing( $order, 'The card was declined at capture.' ) );
	}

	/**
	 * @testdox Terminal capture answers the amount-too-small minimum as the machine-readable payload: $_dataName.
	 * @dataProvider provide_amount_too_small_captures
	 *
	 * @param float                        $total             Order total.
	 * @param array<string,mixed>|null     $captured_response Capture answer, or null when the capture throws.
	 * @param WooPaymentsApiException|null $capture_exception Capture exception, or null when the capture answers.
	 */
	public function test_capture_terminal_payment_answers_the_amount_too_small_minimum( float $total, ?array $captured_response, ?WooPaymentsApiException $capture_exception ): void {
		$order                                        = $this->create_order( $total, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);
		if ( null !== $captured_response ) {
			$this->api_client->captured_intention_response = $captured_response;
		}
		if ( null !== $capture_exception ) {
			$this->api_client->captured_intention_exception = $capture_exception;
		}

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_capture_error_amount_too_small', $response->get_error_code() );
		$this->assertSame(
			esc_html(
				(string) wp_json_encode(
					array(
						'minimum_amount'          => 50,
						'minimum_amount_currency' => 'USD',
					)
				)
			),
			$response->get_error_message()
		);
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * Amount-too-small capture failures, answered or thrown.
	 *
	 * @return array<string,array{0:float,1:?array<string,mixed>,2:?WooPaymentsApiException}>
	 */
	public function provide_amount_too_small_captures(): array {
		return array(
			'a capture answer that did not succeed' => array(
				12.34,
				array(
					'id'            => 'pi_terminal',
					'status'        => 'requires_capture',
					'http_code'     => 400,
					'error_code'    => 'amount_too_small',
					'extra_details' => array(
						'minimum_amount'          => 50,
						'minimum_amount_currency' => 'USD',
					),
				),
				null,
			),
			'a thrown amount_too_small error'       => array(
				0.30,
				null,
				new WooPaymentsApiException(
					'Amount must be at least $0.50 usd',
					'amount_too_small',
					400,
					'',
					'',
					array(
						'minimum_amount' => 50,
						'currency'       => 'usd',
					)
				),
			),
		);
	}

	/**
	 * @testdox A failure after the platform captured a terminal payment is logged even with logging off, and the app gets the WooPayments extension's generic error.
	 *
	 * Client 11.1.0 logs every throwable before answering wcpay_server_error (class-wc-rest-payments-orders-controller.php:289-290).
	 */
	public function test_capture_terminal_payment_logs_a_failure_after_the_capture(): void {
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		add_filter(
			'wcpay_terminal_payment_completed_order_status',
			static function () {
				throw new \RuntimeException( 'status filter failed' );
			}
		);

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( array( 'error', 'Terminal payment captured, but recording it on the order failed.', 'woopayments' ), $errors[0] );
		$context = $logger->contexts[ array_search( $errors[0], $logger->lines, true ) ];
		$this->assertSame( $order->get_id(), $context['order_id'] );
		$this->assertSame( 'pi_terminal', $context['intent_id'] );
		$this->assertSame( 'RuntimeException', $context['exception'] );
		$this->assertNotNull( wc_get_container()->get( OrderPaymentLock::class )->claim( $order, new WooPaymentsPersistenceVocabulary(), 'pi_terminal', 'payment status update' ), 'A failed capture releases the lock.' );
	}

	/**
	 * @testdox A failure recording a payment the reader already captured is logged as a captured payment.
	 *
	 * Client 11.1.0 links an intent the app captured itself, such as Interac (class-wc-rest-payments-orders-controller.php:219-228).
	 * WC_Order::save() turns an Exception from its hooks into an order note, so only a PHP Error escapes this first save.
	 */
	public function test_capture_terminal_payment_logs_a_failure_recording_a_reader_captured_payment(): void {
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$this->api_client->payment_intention_response['status'] = 'succeeded';
		add_action(
			'woocommerce_before_order_object_save',
			static function () {
				throw new \Error( 'save failed' );
			}
		);

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( array( array( 'error', 'Terminal payment captured, but recording it on the order failed.', 'woopayments' ) ), $logger->get_errors() );
		$this->assertSame( array(), $this->api_client->captures );
	}

	/**
	 * @testdox A failure before a terminal capture reaches the platform is logged when logging is on.
	 *
	 * Client 11.1.0 logs it through its gated Logger::error (class-wc-rest-payments-orders-controller.php:289-290).
	 */
	public function test_capture_terminal_payment_logs_a_failure_before_the_capture(): void {
		$logger = RecordingWcLogger::install();
		$this->enable_terminal_debug_logging();
		$order = $this->create_order( 12.34, 'USD' );
		$this->api_client->payment_intention_exception = new \RuntimeException( 'intent read failed' );

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( array( array( 'error', 'Failed to capture a terminal payment via the REST API.', 'woopayments' ) ), $logger->get_errors() );
		$this->assertSame( array(), $this->api_client->last_capture_metadata, 'No capture was requested.' );
	}

	/**
	 * @testdox Customer creation and terminal intent creation log an unexpected PHP error before the WooPayments extension's generic answer.
	 *
	 * Client 11.1.0 logs both before answering wcpay_server_error (class-wc-rest-payments-orders-controller.php:502-503, :544-545).
	 *
	 * @dataProvider provide_mobile_routes_with_unexpected_errors
	 *
	 * @param string $route Controller method under test.
	 */
	public function test_mobile_routes_log_an_unexpected_error( string $route ): void {
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( 12.34, 'USD' );
		$order->update_meta_data( '_stripe_customer_id', 'cus_existing' );
		$order->save();
		$throw = static function () {
			throw new \Error( 'unexpected' );
		};
		// The customer update reads the order's billing name and the intent request its order number; both go through these filters.
		add_filter( 'woocommerce_order_get_billing_first_name', $throw );
		add_filter( 'woocommerce_order_number', $throw );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() );
		$request->set_param( 'order_id', $order->get_id() );
		$response = $this->sut->$route( $request );

		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
	}

	/**
	 * @testdox Terminal capture waits for a short-lived order payment lock holder and then captures once.
	 *
	 * The likely holder is the webhook applying an event for the same intent; client 11.1.0 takes no lock here at all.
	 */
	public function test_capture_terminal_payment_waits_for_the_order_payment_lock(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$store        = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary   = new WooPaymentsPersistenceVocabulary();
		$holder_token = $store->claim( $order, $vocabulary, 'pi_terminal', 'payment status update' );
		$this->assertNotNull( $holder_token );
		$sut = $this->create_lock_waiting_controller(
			static function ( int $wait ) use ( $store, $order, $vocabulary, $holder_token ): void {
				if ( 2 === $wait ) {
					$store->release( $order, $vocabulary, $holder_token );
				}
			}
		);

		$claims_during_capture        = array();
		$claim                        = static function () use ( $store, $order, $vocabulary, &$claims_during_capture ): void {
			$claims_during_capture[] = $store->claim( $order, $vocabulary, 'pi_other', 'competing operation' );
		};
		$this->api_client->on_capture = $claim;
		add_action( 'woocommerce_order_status_completed', $claim );

		$response = $sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'succeeded', $response->get_data()['status'] );
		$this->assertSame( array( null, null ), $claims_during_capture, 'The lock is held through the platform capture and the completion.' );
		$this->assertSame( array( 'pi_terminal' ), $this->api_client->payment_intention_requests );
		$this->assertSame( 2, $sut->waits );
		$this->assertSame(
			array(
				array(
					'intent_id' => 'pi_terminal',
					'amount'    => 1234,
				),
			),
			$this->api_client->captures
		);
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertNotNull( $store->claim( $order, $vocabulary, 'pi_terminal', 'payment status update' ), 'The capture released the lock.' );
	}

	/**
	 * @testdox Terminal capture refuses with a retryable capture error, and captures nothing, when the order payment lock stays held.
	 *
	 * The Android app treats wcpay_payment_uncapturable as already captured and reports a sale (woocommerce-android
	 * PaymentManager.kt:277), so the refusal uses wcpay_capture_error, which it retries on the same intent.
	 */
	public function test_capture_terminal_payment_refuses_while_the_order_payment_lock_stays_held(): void {
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$store        = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary   = new WooPaymentsPersistenceVocabulary();
		$holder_token = $store->claim( $order, $vocabulary, 'pi_terminal', 'payment status update' );
		$sut          = $this->create_lock_waiting_controller();

		$response = $sut->capture_terminal_payment( $this->make_capture_request( $order ) );
		$store->release( $order, $vocabulary, (string) $holder_token );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_capture_error', $response->get_error_code() );
		$this->assertSame( 'The payment is still being processed. Try again.', $response->get_error_message() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( 3, $sut->waits );
		$this->assertSame( array(), $this->api_client->captures );
		$this->assertSame( 'pending', wc_get_order( $order->get_id() )->get_status() );
		$refusals = array_filter( $logger->lines, static fn( array $line ): bool => 'warning' === $line[0] && false !== strpos( $line[1], 'refused terminal capture' ) );
		$this->assertCount( 1, $refusals );
	}

	/**
	 * @testdox Terminal capture reads the order again under the lock and does not capture a payment another request completed while it waited.
	 */
	public function test_capture_terminal_payment_rechecks_the_order_under_the_lock(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$store        = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary   = new WooPaymentsPersistenceVocabulary();
		$holder_token = $store->claim( $order, $vocabulary, 'pi_terminal', 'payment status update' );
		$order_id     = $order->get_id();
		$sut          = $this->create_lock_waiting_controller(
			static function () use ( $store, $order, $vocabulary, $holder_token, $order_id ): void {
				$completed = wc_get_order( $order_id );
				$completed->update_meta_data( '_intention_status', 'succeeded' );
				$completed->save();
				$store->release( $order, $vocabulary, $holder_token );
			}
		);

		$response = $sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_payment_uncapturable', $response->get_error_code() );
		$this->assertSame( array(), $this->api_client->captures );
	}

	/**
	 * @testdox Terminal preparation logs an unexpected PHP error and answers the WooPayments extension's generic error.
	 *
	 * Client 11.1.0 logs it before answering wcpay_server_error (class-wc-rest-payments-orders-controller.php:355-356).
	 */
	public function test_prepare_terminal_payment_logs_an_unexpected_error(): void {
		$logger = RecordingWcLogger::install();
		$order  = $this->create_order( 12.34, 'USD' );
		$this->api_client->prepare_terminal_payment_exception = new \Error( 'unexpected' );

		$response = $this->sut->prepare_terminal_payment( $this->make_prepare_request( $order ) );

		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
	}

	/**
	 * @testdox Terminal preparation answers the WooPayments extension's fallback code and status for a platform error that carries neither.
	 */
	public function test_prepare_terminal_payment_falls_back_to_the_woopayments_extension_error_code(): void {
		$order = $this->create_order( 12.34, 'USD' );
		// A platform error envelope without a code or HTTP status (client class-wc-rest-payments-orders-controller.php:346-353).
		$this->api_client->prepare_terminal_payment_exception = new WooPaymentsApiException( 'Prepare failed.', '', 0 );

		$response = $this->sut->prepare_terminal_payment( $this->make_prepare_request( $order ) );

		$this->assertSame( 'wcpay_prepare_terminal_payment_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	/**
	 * @testdox Terminal capture refuses an order whose stored payment is already processed, with the WooPayments extension's answer and no capture.
	 *
	 * Client 11.1.0 checks the stored intent status before reading the intent (class-wc-rest-payments-orders-controller.php:181-194).
	 *
	 * @dataProvider provide_processed_intent_statuses
	 *
	 * @param string $stored_status Stored intent status.
	 */
	public function test_capture_terminal_payment_refuses_a_processed_order( string $stored_status ): void {
		$order = $this->create_order( 12.34, 'USD' );
		$order->update_meta_data( '_intention_status', $stored_status );
		$order->save();
		$this->set_capturable_terminal_intent( $order );

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertSame( 'wcpay_payment_uncapturable', $response->get_error_code() );
		$this->assertSame( 'The payment cannot be captured for completed or processed orders.', $response->get_error_message() );
		$this->assertSame( 409, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->captures );
	}

	/**
	 * Stored intent statuses the client treats as already processed.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_processed_intent_statuses(): array {
		return array(
			'succeeded'  => array( 'succeeded' ),
			'canceled'   => array( 'canceled' ),
			'processing' => array( 'processing' ),
		);
	}

	/**
	 * @testdox Terminal capture refuses an intent that is not authorized, with the WooPayments extension's answer and no capture.
	 */
	public function test_capture_terminal_payment_refuses_an_intent_that_is_not_authorized(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$this->api_client->payment_intention_response['status'] = 'requires_payment_method';

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		// Client 11.1.0 class-wc-rest-payments-orders-controller.php:208-210.
		$this->assertSame( 'wcpay_payment_uncapturable', $response->get_error_code() );
		$this->assertSame( 'The payment cannot be captured', $response->get_error_message() );
		$this->assertSame( array(), $this->api_client->captures );
	}

	/**
	 * @testdox Terminal capture sends a processing intent to the capture call, as the WooPayments extension's authorized check does.
	 */
	public function test_capture_terminal_payment_sends_a_processing_intent_to_the_capture_call(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$this->api_client->payment_intention_response['status'] = 'processing';

		$this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertCount( 1, $this->api_client->captures );
	}

	/**
	 * @testdox Terminal capture and preparation refuse a partially refunded order with the WooPayments extension's answers and no platform call.
	 *
	 * Client 11.1.0 class-wc-rest-payments-orders-controller.php:172-179 and :321-327.
	 */
	public function test_terminal_routes_refuse_a_refunded_order(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1.00,
			)
		);

		$capture = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );
		$prepare = $this->sut->prepare_terminal_payment( $this->make_prepare_request( $order ) );

		$this->assertSame( 'wcpay_refunded_order_uncapturable', $capture->get_error_code() );
		$this->assertSame( 'Payment cannot be captured for partially or fully refunded orders.', $capture->get_error_message() );
		$this->assertSame( 400, $capture->get_error_data()['status'] );
		$this->assertSame( 'wcpay_refunded_order_unpreparable', $prepare->get_error_code() );
		$this->assertSame( 'Terminal payments cannot be prepared for partially or fully refunded orders.', $prepare->get_error_message() );
		$this->assertSame( array(), $this->api_client->captures );
		$this->assertSame( array(), $this->api_client->prepared_terminal_payments );
	}

	/**
	 * @testdox A zero-amount refund row does not stop terminal capture or preparation, since the check reads the refunded total.
	 *
	 * Client 11.1.0 class-wc-rest-payments-orders-controller.php:173 and :322 read get_total_refunded().
	 */
	public function test_terminal_routes_ignore_a_zero_amount_refund(): void {
		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 0,
			)
		);
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_refunds() );

		$prepare = $this->sut->prepare_terminal_payment( $this->make_prepare_request( $order ) );
		$capture = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertInstanceOf( WP_REST_Response::class, $prepare );
		$this->assertInstanceOf( WP_REST_Response::class, $capture );
		$this->assertCount( 1, $this->api_client->captures );
	}

	/**
	 * @testdox Terminal capture sends the platform the intent and the order total in the currency's minor units.
	 *
	 * @dataProvider provide_capture_amounts
	 *
	 * @param float  $total    Order total.
	 * @param string $currency Order currency.
	 * @param int    $amount   Expected amount in minor units.
	 */
	public function test_capture_terminal_payment_captures_the_order_total( float $total, string $currency, int $amount ): void {
		$order = $this->create_order( $total, $currency );
		$this->set_capturable_terminal_intent( $order );

		$this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertSame(
			array(
				array(
					'intent_id' => 'pi_terminal',
					'amount'    => $amount,
				),
			),
			$this->api_client->captures
		);
	}

	/**
	 * Order totals and their minor-unit amounts.
	 *
	 * @return array<string,array{0:float,1:string,2:int}>
	 */
	public function provide_capture_amounts(): array {
		return array(
			'USD, two decimals' => array( 12.34, 'USD', 1234 ),
			'JPY, zero decimal' => array( 1234.0, 'JPY', 1234 ),
		);
	}

	/**
	 * @testdox An in-person subscription purchase saves the reader's generated card and turns automatic renewal on unless the store requires manual renewal.
	 *
	 * Client 11.1.0 class-wc-rest-payments-orders-controller.php:256-280.
	 *
	 * @dataProvider provide_manual_renewal_settings
	 *
	 * @param bool $manual_renewal_required Whether the store turns off automatic payments.
	 * @param bool $expected_manual         Whether the subscription still renews manually.
	 */
	public function test_capture_terminal_payment_saves_the_generated_card_for_subscriptions( bool $manual_renewal_required, bool $expected_manual ): void {
		$GLOBALS[ WooCommerceSubscriptionsDoubles::MANUAL_RENEWAL_REQUIRED ] = $manual_renewal_required;
		list( $order, $subscription_id, $user_id )                           = $this->set_up_in_person_subscription_purchase();
		$token = $this->create_card_token( $user_id );
		$this->replace_token_service( $this->once(), $token );
		$renewal_at_capture           = array();
		$this->api_client->on_capture = static function () use ( $subscription_id, &$renewal_at_capture ): void {
			$subscription       = wc_get_order( $subscription_id );
			$renewal_at_capture = array( $subscription->get_payment_method(), $subscription->is_manual() );
		};

		try {
			$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( array( 'cod', true ), $renewal_at_capture, 'Nothing changes before the capture succeeds.' );
			$this->assertContains( $token->get_id(), wc_get_order( $order->get_id() )->get_payment_tokens() );
			$subscription = wc_get_order( $subscription_id );
			$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, $subscription->get_payment_method() );
			$this->assertContains( $token->get_id(), $subscription->get_payment_tokens() );
			$this->assertSame( $expected_manual, $subscription->is_manual() );
		} finally {
			$this->reset_subscription_doubles();
		}
	}

	/**
	 * Store renewal settings and whether the subscription keeps renewing manually.
	 *
	 * @return array<string,array{0:bool,1:bool}>
	 */
	public function provide_manual_renewal_settings(): array {
		return array(
			'automatic payments allowed' => array( false, false ),
			'manual renewal required'    => array( true, true ),
		);
	}

	/**
	 * @testdox A failed in-person capture leaves the subscription's card and renewal setting alone.
	 *
	 * Client 11.1.0 returns on a failed capture (class-wc-rest-payments-orders-controller.php:230-252) before the card step.
	 */
	public function test_capture_terminal_payment_saves_no_card_when_the_capture_fails(): void {
		list( $order, $subscription_id ) = $this->set_up_in_person_subscription_purchase();
		$this->replace_token_service( $this->never(), null );
		// The platform's capture error envelope (client class-wc-payments-api-client.php:2852-2871, :2906-2909).
		$this->api_client->captured_intention_exception = new WooPaymentsApiException( 'The card was declined at capture.', 'card_declined', 402 );

		try {
			$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

			$this->assertSame( 'wcpay_capture_error', $response->get_error_code() );
			$subscription = wc_get_order( $subscription_id );
			$this->assertSame( 'cod', $subscription->get_payment_method() );
			$this->assertTrue( $subscription->is_manual() );
		} finally {
			$this->reset_subscription_doubles();
		}
	}

	/**
	 * @testdox A generated card that cannot be saved for a registered customer fails the request and is logged.
	 *
	 * Client 11.1.0 fetches the payment method without a catch (class-wc-payments-token-service.php:135-138), so the
	 * failure reaches the route's catch and answers wcpay_server_error (class-wc-rest-payments-orders-controller.php:289-291).
	 */
	public function test_capture_terminal_payment_fails_when_the_generated_card_cannot_be_saved(): void {
		$logger                          = RecordingWcLogger::install();
		list( $order, $subscription_id ) = $this->set_up_in_person_subscription_purchase();
		// The token service answers null when it cannot read the payment method's details.
		$this->replace_token_service( $this->once(), null );

		try {
			$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

			$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
			$this->assertSame( array( array( 'error', 'Terminal payment captured, but recording it on the order failed.', 'woopayments' ) ), $logger->get_errors() );
			$this->assertTrue( wc_get_order( $subscription_id )->is_manual() );
		} finally {
			$this->reset_subscription_doubles();
		}
	}

	/**
	 * @testdox After a capture whose card cannot be saved fails the request, a capture of another intent for the order is refused and a retry of the same intent completes it.
	 *
	 * Client 11.1.0 refuses a capture when the order holds another intent (includes/admin/class-wc-rest-payments-orders-controller.php:187-194,
	 * tests/unit/admin/test-class-wc-rest-payments-orders-controller.php:1860-1902), and it stores the intent before its capture (:211), so a
	 * failure after the capture cannot let a second intent charge the order again.
	 */
	public function test_capture_after_a_failed_recording_refuses_another_intent_and_completes_on_a_retry(): void {
		global $wp_rest_server;

		list( $order, , $user_id ) = $this->set_up_in_person_subscription_purchase();
		$wp_rest_server            = new WP_REST_Server();
		$this->sut->register_routes();
		$capture = function ( \WC_Order $order, string $intent_id ) use ( &$wp_rest_server ) {
			return $wp_rest_server->dispatch( $this->make_route_capture_request( $order, $intent_id ) );
		};

		try {
			// The token service answers null when it cannot read the payment method's details.
			$this->replace_token_service( $this->once(), null );
			$first = $capture( $order, 'pi_terminal' );
			$this->assertSame( 500, $first->get_status() );
			$this->assertSame( 'wcpay_server_error', $first->get_data()['code'] );
			$this->assertSame( 'pi_terminal', wc_get_order( $order->get_id() )->get_meta( '_intent_id' ) );

			$second = $capture( $order, 'pi_another' );
			$this->assertSame( 409, $second->get_status() );
			$this->assertSame( 'wcpay_payment_uncapturable', $second->get_data()['code'] );
			$this->assertSame( array( 'pi_terminal' ), array_column( $this->api_client->captures, 'intent_id' ), 'The other intent is never captured.' );

			// The app retries the same intent, which the platform now reads as captured.
			$this->api_client->payment_intention_response['status'] = 'succeeded';
			$this->replace_token_service( $this->once(), $this->create_card_token( $user_id ) );
			$retry = $capture( $order, 'pi_terminal' );
			$this->assertSame( 200, $retry->get_status() );
			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 'completed', $order->get_status() );
			$this->assertSame( 'succeeded', $order->get_meta( '_intention_status' ) );
			$this->assertStringEndsWith( '/payments/readers/receipts/pi_terminal', (string) $order->get_meta( 'receipt_url' ) );
			$this->assertSame( array( 'pi_terminal' ), array_column( $this->api_client->captures, 'intent_id' ), 'The retry captures nothing again.' );
		} finally {
			$this->reset_subscription_doubles();
		}
	}

	/**
	 * @testdox After a declined capture the order holds the intent, so a capture of another intent is refused and the same intent can be captured again.
	 *
	 * Client 11.1.0 stores the intent before its capture (includes/admin/class-wc-rest-payments-orders-controller.php:211) and
	 * refuses another intent while the stored one is requires_capture (:187-194; tests/unit/admin/test-class-wc-rest-payments-orders-controller.php:1894,
	 * the row pi_abc requires_capture, pi_xyz, added with "Prevent double-charging of terminal orders", woocommerce-payments #6670).
	 */
	public function test_capture_after_a_declined_capture_refuses_another_intent(): void {
		global $wp_rest_server;

		$order = $this->create_order( 12.34, 'USD' );
		$this->set_capturable_terminal_intent( $order );
		$this->api_client->captured_intention_response = array(
			'id'      => 'pi_terminal',
			'status'  => 'requires_capture',
			'message' => 'The card was declined at capture.',
		);
		$wp_rest_server                                = new WP_REST_Server();
		$this->sut->register_routes();

		$first = $wp_rest_server->dispatch( $this->make_route_capture_request( $order, 'pi_terminal' ) );
		$this->assertSame( 'wcpay_capture_error', $first->get_data()['code'] );
		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pi_terminal', $order->get_meta( '_intent_id' ) );
		$this->assertSame( 'requires_capture', $order->get_meta( '_intention_status' ) );

		$second = $wp_rest_server->dispatch( $this->make_route_capture_request( $order, 'pi_another' ) );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'wcpay_payment_uncapturable', $second->get_data()['code'] );

		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
		);
		$retry = $wp_rest_server->dispatch( $this->make_route_capture_request( $order, 'pi_terminal' ) );
		$this->assertSame( 200, $retry->get_status() );
		$this->assertSame( array( 'pi_terminal', 'pi_terminal' ), array_column( $this->api_client->captures, 'intent_id' ), 'Only the stored intent is captured.' );
	}

	/**
	 * @testdox An in-person purchase without subscriptions saves no generated card.
	 */
	public function test_capture_terminal_payment_saves_no_card_without_subscriptions(): void {
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$order = $this->create_order( 12.34, 'USD' );
		$this->replace_token_service( $this->never(), null );
		$this->set_capturable_terminal_intent( $order );
		$this->set_generated_card_charge();

		$response = $this->sut->capture_terminal_payment( $this->make_capture_request( $order ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), wc_get_order( $order->get_id() )->get_payment_tokens() );
	}

	/**
	 * Set up a customer's order holding a manually renewing subscription, with a capturable intent whose charge generated a card.
	 *
	 * @return array{0:\WC_Order,1:int,2:int} Order, subscription ID and customer user ID.
	 */
	private function set_up_in_person_subscription_purchase(): array {
		WooCommerceSubscriptionsDoubles::load();
		WooCommerceSubscriptionsDoubles::load_order_detector();
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		$order   = $this->create_order( 12.34, 'USD' );
		$order->set_customer_id( $user_id );
		$order->save();
		$subscription = new SubscriptionDouble();
		$subscription->set_payment_method( 'cod' );
		$subscription->set_customer_id( $user_id );
		$subscription->set_requires_manual_renewal( true );
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][]                      = $subscription->get_id();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ][ $order->get_id() ] = array( 'parent' => array( $subscription->get_id() ) );
		$this->set_capturable_terminal_intent( $order );
		$this->set_generated_card_charge();

		return array( $order, $subscription->get_id(), $user_id );
	}

	/**
	 * Give the fake intent a card_present charge that generated a reusable card.
	 *
	 * The client reads the last entry of the intent's charges.data (class-wc-payments-api-client.php:2403) and its
	 * payment_method_details.card_present.generated_card (class-wc-rest-payments-orders-controller.php:258).
	 */
	private function set_generated_card_charge(): void {
		$this->api_client->payment_intention_response['charges'] = array(
			'total_count' => 1,
			'data'        => array(
				array(
					'id'                     => 'ch_terminal',
					'payment_method_details' => array(
						'type'         => 'card_present',
						'card_present' => array( 'generated_card' => 'pm_generated' ),
					),
				),
			),
		);
	}

	/**
	 * Create a saved card token for a customer.
	 *
	 * @param int $user_id Customer user ID.
	 * @return \WC_Payment_Token_CC
	 */
	private function create_card_token( int $user_id ): \WC_Payment_Token_CC {
		$token = new \WC_Payment_Token_CC();
		$token->set_token( 'pm_generated' );
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $user_id );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
	}

	/**
	 * Replace the container's token service with one whose token lookup is expected as given.
	 *
	 * @param \PHPUnit\Framework\MockObject\Rule\InvocationOrder $expected_calls Expected lookups.
	 * @param \WC_Payment_Token|null                             $token          Token the lookup answers.
	 */
	private function replace_token_service( $expected_calls, ?\WC_Payment_Token $token ): void {
		$token_service = $this->getMockBuilder( WooPaymentsTokenService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_or_create_token_for_user' ) )
			->getMock();
		$token_service->expects( $expected_calls )->method( 'get_or_create_token_for_user' )->with( 'pm_generated' )->willReturn( $token );
		wc_get_container()->replace( WooPaymentsTokenService::class, $token_service );
	}

	/**
	 * Clear the Subscriptions doubles' registries.
	 */
	private function reset_subscription_doubles(): void {
		unset( $GLOBALS[ WooCommerceSubscriptionsDoubles::MANUAL_RENEWAL_REQUIRED ], $GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ], $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] );
	}

	/**
	 * Mobile routes whose unexpected errors are logged.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_mobile_routes_with_unexpected_errors(): array {
		return array(
			'customer'        => array( 'create_customer' ),
			'terminal intent' => array( 'create_terminal_intent' ),
		);
	}

	/**
	 * Turn WooPayments logging on, so gated lines are written.
	 */
	private function enable_terminal_debug_logging(): void {
		$this->gateway_settings['enable_logging'] = 'yes';
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
	}

	/**
	 * Make the fake platform return a capturable terminal intent for an order and a succeeded capture.
	 *
	 * Shapes follow the PaymentIntent the client reads in capture_terminal_payment() (class-wc-rest-payments-orders-controller.php:195-213).
	 *
	 * @param \WC_Order $order Order.
	 */
	private function set_capturable_terminal_intent( \WC_Order $order ): void {
		$this->api_client->payment_intention_response  = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);
		$this->api_client->captured_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'succeeded',
			'currency' => 'usd',
		);
	}

	/**
	 * Build a prepare request for an order and the pi_terminal intent.
	 *
	 * @param \WC_Order $order Order.
	 * @return WP_REST_Request
	 */
	private function make_prepare_request( \WC_Order $order ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/prepare_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		return $request;
	}

	/**
	 * Build a capture request for an order and the pi_terminal intent.
	 *
	 * @param \WC_Order $order Order.
	 * @return WP_REST_Request
	 */
	private function make_capture_request( \WC_Order $order ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		return $request;
	}

	/**
	 * Build a capture request for the registered route, with the intent in the body as the apps send it.
	 *
	 * @param \WC_Order $order     Order.
	 * @param string    $intent_id Intent ID.
	 * @return WP_REST_Request
	 */
	private function make_route_capture_request( \WC_Order $order, string $intent_id ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_body_params( array( 'payment_intent_id' => $intent_id ) );

		return $request;
	}

	/**
	 * Create a controller whose lock waits are counted instead of slept, with a callback run on each wait.
	 *
	 * @param callable|null $on_wait Called with the wait number.
	 * @return WooPaymentsTerminalPaymentsRestController&object{waits:int}
	 */
	private function create_lock_waiting_controller( ?callable $on_wait = null ): WooPaymentsTerminalPaymentsRestController {
		$controller = new class( $on_wait ) extends WooPaymentsTerminalPaymentsRestController {
			/**
			 * Number of waits.
			 *
			 * @var int
			 */
			public int $waits = 0;

			/**
			 * Callback run on each wait.
			 *
			 * @var callable|null
			 */
			private $on_wait;

			/**
			 * Constructor.
			 *
			 * @param callable|null $on_wait Callback run on each wait.
			 */
			public function __construct( ?callable $on_wait ) {
				$this->on_wait = $on_wait;
			}

			/**
			 * Count the wait instead of sleeping.
			 */
			protected function wait_before_lock_retry(): void {
				++$this->waits;
				if ( null !== $this->on_wait ) {
					( $this->on_wait )( $this->waits );
				}
			}
		};

		return $this->create_controller( true, $controller );
	}

	/**
	 * Create the controller under test.
	 *
	 * @param bool                                           $test_mode  Whether the account runs in test mode.
	 * @param WooPaymentsTerminalPaymentsRestController|null $controller Controller instance to initialize, a new one when null.
	 * @return WooPaymentsTerminalPaymentsRestController
	 */
	private function create_controller( bool $test_mode = true, ?WooPaymentsTerminalPaymentsRestController $controller = null ): WooPaymentsTerminalPaymentsRestController {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'refresh_account_data', 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			function (): array {
				return $this->account_data;
			}
		);
		$account_service->method( 'refresh_account_data' )->willReturnCallback(
			function (): array {
				++$this->account_refresh_calls;

				return array();
			}
		);
		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			function ( string $key, $fallback = null ) {
				return array_key_exists( $key, $this->gateway_settings ) ? $this->gateway_settings[ $key ] : $fallback;
			}
		);

		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $this->api_client, $account_service, new WooPaymentsSessionService() );

		$controller = $controller ?? new WooPaymentsTerminalPaymentsRestController();
		$controller->init( $this->api_client, $account_service, $customer_service, new WooPaymentsOrderDataService(), wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService::class ) );

		return $controller;
	}

	/**
	 * Get the first order note containing a string.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $needle   Needle to search for (case-insensitive, tags stripped).
	 * @return string
	 */
	private function get_order_note_containing( \WC_Order $order, string $needle ): string {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( false !== stripos( wp_strip_all_tags( (string) $note->content ), $needle ) ) {
				return (string) $note->content;
			}
		}

		return '';
	}

	/**
	 * The card reader token answer tells the app the store's mode, so it pairs test readers in test mode and live readers in
	 * live mode: client 11.1.0 adds WC_Payments::mode()->is_test() to the platform's token
	 * (includes/admin/class-wc-rest-payments-connection-tokens-controller.php:42-55).
	 *
	 * @testdox The connection token answer in $mode mode carries test_mode $expected_test_mode.
	 * @dataProvider provide_connection_token_modes
	 *
	 * @param string $mode               Store mode.
	 * @param bool   $expected_test_mode Expected test_mode flag.
	 */
	public function test_connection_token_tells_the_app_the_store_mode( string $mode, bool $expected_test_mode ): void {
		$this->sut = $this->create_controller( 'test' === $mode );
		// A Stripe ConnectionToken, which the platform proxies (https://docs.stripe.com/api/terminal/connection_tokens/object).
		$this->api_client->connection_token_response = array(
			'object' => 'terminal.connection_token',
			'secret' => 'pst_terminal',
		);

		$response = $this->sut->create_connection_token( new WP_REST_Request( 'POST', '/wc/v3/payments/connection_tokens' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'object'    => 'terminal.connection_token',
				'secret'    => 'pst_terminal',
				'test_mode' => $expected_test_mode,
			),
			$response->get_data()
		);
	}

	/**
	 * Store modes for the connection token answer.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provide_connection_token_modes(): array {
		return array(
			'test' => array( 'test', true ),
			'live' => array( 'live', false ),
		);
	}

	/**
	 * The mobile routes answer a failure as the extension's controllers do, so the apps read the same status and code.
	 *
	 * Faked failures are WooPaymentsApiException( message, code, status ), the shape the API client builds from a platform
	 * error body and its HTTP status; status 0 is the API client's encode failure (wcpay_client_unable_to_encode_json),
	 * which carries no HTTP status. A status of 0 reaches the app as HTTP 200, because WordPress's status_header( 0 )
	 * sends no status line; the answer's own status is asserted here.
	 *
	 * @testdox Mobile route failure "$scenario" answers status $expected_status with code $expected_code.
	 * @dataProvider provide_mobile_route_failures
	 *
	 * @param string $scenario        Scenario key.
	 * @param int    $expected_status Expected answer status.
	 * @param string $expected_code   Expected error code.
	 */
	public function test_mobile_route_failures_answer_as_the_extension_does( string $scenario, int $expected_status, string $expected_code ): void {
		$encode_failure  = new WooPaymentsApiException( 'Unable to encode the WooPayments request body.', 'wcpay_client_unable_to_encode_json', 0 );
		$refused_request = new WooPaymentsApiException( 'Invalid request.', 'invalid_request_error', 400 );
		$order           = $this->create_order( 12.34, 'USD' );
		$request         = new WP_REST_Request( 'POST', '/wc/v3/payments/test' );
		$request->set_param( 'order_id', $order->get_id() );

		switch ( $scenario ) {
			case 'connection token, platform 400':
				$this->api_client->method_exceptions['create_terminal_connection_token'] = $refused_request;
				$response = $this->sut->create_connection_token( $request );
				break;
			case 'terminal intent, platform 400':
				$this->api_client->method_exceptions['create_terminal_payment_intention'] = $refused_request;
				$response = $this->sut->create_terminal_intent( $request );
				break;
			case 'terminal capture, empty intent id':
				$request->set_param( 'payment_intent_id', '' );
				$response = $this->sut->capture_terminal_payment( $request );
				break;
			case 'terminal capture, capture fails with status 0':
				$order->update_status( 'on-hold' );
				// The Stripe PaymentIntent the platform proxies from GET intentions/{id} (wpcom class-intentions-controller.php
				// get_intent_data() :755-778), trimmed to the fields the capture reads: id, status, currency, metadata.order_id.
				$this->api_client->payment_intention_response_queue = array(
					array(
						'id'       => 'pi_terminal',
						'status'   => 'requires_capture',
						'currency' => 'usd',
						'metadata' => array( 'order_id' => (string) $order->get_id() ),
					),
					array(
						'id'     => 'pi_terminal',
						'status' => 'requires_capture',
					),
				);
				$this->api_client->captured_intention_exception     = $encode_failure;
				$request->set_param( 'payment_intent_id', 'pi_terminal' );
				$response = $this->sut->capture_terminal_payment( $request );
				break;
			case 'terminal capture, capture returns a processing intent':
				$order->update_status( 'on-hold' );
				// The intent read and the capture answer are the platform's PaymentIntent (wpcom class-intentions-controller.php
				// get_intent_data() :755-778 and capture_intention()), trimmed to id, status, currency and metadata.order_id.
				$this->api_client->payment_intention_response  = array(
					'id'       => 'pi_terminal',
					'status'   => 'requires_capture',
					'currency' => 'usd',
					'metadata' => array( 'order_id' => (string) $order->get_id() ),
				);
				$this->api_client->captured_intention_response = array(
					'id'     => 'pi_terminal',
					'status' => 'processing',
				);
				$request->set_param( 'payment_intent_id', 'pi_terminal' );
				$response = $this->sut->capture_terminal_payment( $request );
				break;
			default:
				$this->fail( "Unknown scenario {$scenario}." );
		}

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( $expected_code, $response->get_error_code() );
		$this->assertSame( $expected_status, rest_convert_error_to_response( $response )->get_status() );
	}

	/**
	 * Mobile route failures and the extension's answers (class-wc-rest-payments-orders-controller.php,
	 * class-wc-rest-payments-reader-controller.php, class-wc-rest-payments-terminal-locations-controller.php,
	 * class-wc-rest-payments-connection-tokens-controller.php).
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function provide_mobile_route_failures(): array {
		$cases = array(
			array( 'connection token, platform 400', 500, 'invalid_request_error' ),
			array( 'terminal intent, platform 400', 500, 'wcpay_server_error' ),
			array( 'terminal capture, empty intent id', 500, 'wcpay_server_error' ),
			array( 'terminal capture, capture fails with status 0', 0, 'wcpay_capture_error' ),
			// A capture that did not succeed answers 502, as the extension's failure branch sets it (class-wc-payment-gateway-wcpay.php:4045-4049).
			array( 'terminal capture, capture returns a processing intent', 502, 'wcpay_capture_error' ),
		);

		return array_combine( array_column( $cases, 0 ), $cases );
	}

	/**
	 * Create a minimal order for terminal-route tests.
	 *
	 * @param float  $total    Order total.
	 * @param string $currency Order currency.
	 * @return \WC_Order
	 */
	private function create_order( float $total = 10.0, string $currency = 'USD' ): \WC_Order {
		$order = WC_Helper_Order::create_order();
		$order->set_total( $total );
		$order->set_currency( $currency );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}
}
