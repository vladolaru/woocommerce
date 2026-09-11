<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsMobileRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use WC_Helper_Order;
use WC_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the native WooPayments mobile and IPP REST controller.
 */
class WooPaymentsMobileRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsMobileRestController
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
		$this->account_refresh_calls = 0;
		$this->sut                   = $this->create_controller( true );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( 'wcpay_store_terminal_readers' );
		delete_transient( 'wcpay_store_terminal_locations' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		delete_transient( 'wcpay_store_terminal_readers' );
		delete_transient( 'wcpay_store_terminal_locations' );
		parent::tearDown();
	}

	/**
	 * @testdox The mobile and IPP routes are registered under wc/v3 when native owns runtime.
	 */
	public function test_registers_mobile_ipp_routes_when_native_owns_runtime(): void {
		$this->sut->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		foreach ( $this->get_expected_routes() as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $methods as $method ) {
				$this->assertRouteHasMethod( $routes[ $route ], $method );
			}
		}
	}

	/**
	 * @testdox Mobile and IPP routes are not registered when native does not own runtime.
	 */
	public function test_registers_no_routes_when_native_does_not_own_runtime(): void {
		$this->sut = $this->create_controller( false );
		$this->sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox Mobile and IPP routes require manage_woocommerce.
	 */
	public function test_routes_require_manage_woocommerce(): void {
		$this->sut->register_routes();
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/connection_tokens' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
	}

	/**
	 * @testdox Connection-token responses include the WooPayments test-mode flag for mobile clients.
	 */
	public function test_connection_token_response_appends_test_mode(): void {
		$this->api_client->connection_token_response = array( 'secret' => 'cnctok_test_secret' );

		$response = $this->sut->create_connection_token( new WP_REST_Request( 'POST', '/wc/v3/payments/connection_tokens' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame(
			array(
				'secret'    => 'cnctok_test_secret',
				'test_mode' => true,
			),
			$response->get_data()
		);
	}

	/**
	 * @testdox Terminal intent creation sends the order amount, lower-case currency, metadata, and card-present defaults.
	 */
	public function test_create_terminal_intent_builds_reference_payload(): void {
		$order = $this->create_order( 12.34, 'USD' );

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
		$this->assertSame( $order->get_order_number(), $this->api_client->last_terminal_intent_payload['metadata']['order_number'] );
		$this->assertSame(
			\Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentRequestBuilder::intent_description( (string) $order->get_order_number() ),
			$this->api_client->last_terminal_intent_payload['description']
		);
		$this->assertStringContainsString( 'Online Payment for Order #' . $order->get_order_number(), $this->api_client->last_terminal_intent_payload['description'] );
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
	 * @testdox Reader registration forwards app-supplied metadata verbatim like the reference client.
	 */
	public function test_register_reader_forwards_metadata_verbatim(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_store' );
		$request->set_param( 'registration_code', 'puppies-plug-could' );
		$request->set_param(
			'metadata',
			array(
				'readerID'    => 'rdr_ABC',
				'pos.session' => 'session-42',
			)
		);

		$this->api_client->terminal_reader_response = array( 'id' => 'tmr_registered' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$metadata = $this->api_client->last_registered_reader['metadata'];

		$this->assertSame( 'rdr_ABC', $metadata['readerID'] );
		$this->assertSame( 'session-42', $metadata['pos.session'] );
	}

	/**
	 * @testdox Terminal location creation does not forward metadata, like the reference client.
	 */
	public function test_create_terminal_location_does_not_forward_metadata(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/terminal/locations' );
		$request->set_param( 'display_name', 'Warehouse' );
		$request->set_param( 'address', array( 'country' => 'US' ) );
		$request->set_param( 'metadata', array( 'ignored' => 'yes' ) );

		$response = $this->sut->create_terminal_location( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), $this->api_client->last_created_location['metadata'] );
	}

	/**
	 * @testdox Registering a reader refreshes the cached account data like the reference client.
	 */
	public function test_register_reader_refreshes_account_data(): void {
		$this->api_client->terminal_reader_response = array(
			'id'          => 'tmr_registered',
			'livemode'    => false,
			'device_type' => 'bbpos_wisepos_e',
			'label'       => 'Front desk',
			'location'    => 'tml_1',
			'metadata'    => array(),
			'status'      => 'online',
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_1' );
		$request->set_param( 'registration_code', 'puppies-plug-could' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $this->account_refresh_calls, 'A successful reader registration must refresh the cached account data.' );
	}

	/**
	 * @testdox A failed reader registration does not refresh the cached account data.
	 */
	public function test_failed_reader_registration_does_not_refresh_account_data(): void {
		$this->api_client->register_reader_exception = new WooPaymentsApiException( 'Bad code.', 'wcpay_invalid_registration_code', 400 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_1' );
		$request->set_param( 'registration_code', 'bad-code' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 0, $this->account_refresh_calls );
	}

	/**
	 * @testdox Terminal intent creation rejects invalid payment method payloads like the reference mobile route.
	 */
	public function test_create_terminal_intent_rejects_invalid_payment_method_payload(): void {
		$order   = $this->create_order();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_methods', 'card_present' );

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->last_terminal_intent_payload );
	}

	/**
	 * @testdox Terminal intent creation rejects unsupported payment methods instead of silently defaulting.
	 */
	public function test_create_terminal_intent_rejects_unsupported_payment_method(): void {
		$order   = $this->create_order();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_methods', array( 'card' ) );

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->last_terminal_intent_payload );
	}

	/**
	 * @testdox Terminal intent creation rejects unsupported capture methods instead of silently defaulting.
	 */
	public function test_create_terminal_intent_rejects_invalid_capture_method(): void {
		$order   = $this->create_order();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/create_terminal_intent' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'capture_method', 'later' );

		$response = $this->sut->create_terminal_intent( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_server_error', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( array(), $this->api_client->last_terminal_intent_payload );
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
	 * @testdox Missing required params fail with the WordPress args-schema code like the reference client.
	 */
	public function test_terminal_routes_require_params_via_args_schemas(): void {
		$this->sut->register_routes();

		$order = $this->create_order( 12.34, 'USD' );

		$capture_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' )
		);
		$this->assertSame( 400, $capture_response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $capture_response->get_data()['code'] );

		$prepare_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/prepare_terminal_payment' )
		);
		$this->assertSame( 400, $prepare_response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $prepare_response->get_data()['code'] );

		$reader_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/readers' )
		);
		$this->assertSame( 400, $reader_response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $reader_response->get_data()['code'] );

		$location_response = $this->server->dispatch(
			new WP_REST_Request( 'POST', '/wc/v3/payments/terminal/locations' )
		);
		$this->assertSame( 400, $location_response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $location_response->get_data()['code'] );
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
	 * @testdox Reader listing uses the preserved transient and annotates the active reader.
	 */
	public function test_get_readers_uses_preserved_transient_and_active_status(): void {
		$this->api_client->terminal_readers_response      = array(
			'data' => array(
				array(
					'id'          => 'tmr_active',
					'livemode'    => false,
					'device_type' => 'bbpos_wisepos_e',
					'label'       => 'Counter',
					'location'    => 'tml_store',
					'metadata'    => array(),
					'status'      => 'online',
				),
			),
		);
		$this->api_client->reader_charge_summary_response = array(
			array(
				'reader_id' => 'tmr_active',
				'status'    => 'active',
			),
		);

		$response = $this->sut->get_readers( new WP_REST_Request( 'GET', '/wc/v3/payments/readers' ) );
		$cached   = get_transient( 'wcpay_store_terminal_readers' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'tmr_active', $data[0]['id'] );
		$this->assertTrue( $data[0]['is_active'] );
		$this->assertSame( $data, $cached );
	}

	/**
	 * @testdox An empty cached reader list is treated as a miss and re-fetched, like the reference client.
	 */
	public function test_get_readers_refetches_when_cached_list_is_empty(): void {
		set_transient( 'wcpay_store_terminal_readers', array() );
		$this->api_client->terminal_readers_response = array(
			'data' => array(
				array(
					'id'          => 'tmr_new',
					'livemode'    => false,
					'device_type' => 'bbpos_wisepos_e',
					'label'       => 'Front desk',
					'location'    => 'tml_1',
					'metadata'    => array(),
					'status'      => 'online',
				),
			),
		);

		$response = $this->sut->get_readers( new WP_REST_Request( 'GET', '/wc/v3/payments/readers' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $response->get_data(), 'A reader registered elsewhere must appear despite the cached empty list.' );
		$this->assertSame( 'tmr_new', $response->get_data()[0]['id'] );
	}

	/**
	 * @testdox An empty cached location list is treated as a miss and re-fetched, like the reference client.
	 */
	public function test_get_terminal_locations_refetches_when_cached_list_is_empty(): void {
		set_transient( 'wcpay_store_terminal_locations', array() );
		$this->api_client->terminal_locations_response = array(
			'data' => array(
				array(
					'id'           => 'tml_new',
					'display_name' => 'Warehouse',
					'address'      => array( 'country' => 'US' ),
					'livemode'     => false,
				),
			),
		);

		$response = $this->sut->get_terminal_locations( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( 'tml_new', $response->get_data()[0]['id'] );
	}

	/**
	 * @testdox Reader charge summary uses the source transaction creation date.
	 */
	public function test_get_reader_charge_summary_uses_transaction_created_date(): void {
		$this->api_client->transaction_response           = array(
			'id'      => 'txn_test',
			'created' => strtotime( '2026-06-01 12:00:00 UTC' ),
		);
		$this->api_client->reader_charge_summary_response = array(
			array(
				'reader_id' => 'tmr_active',
				'status'    => 'active',
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/charges/txn_test' );
		$request->set_param( 'transaction_id', 'txn_test' );

		$response = $this->sut->get_reader_charge_summary( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $this->api_client->reader_charge_summary_response, $response->get_data() );
		$this->assertSame(
			array(
				array(
					'charge_date'    => '2026-06-01',
					'transaction_id' => 'txn_test',
				),
			),
			$this->api_client->reader_charge_summary_calls
		);
	}

	/**
	 * @testdox Reader charge summary returns an empty response when the source transaction is missing.
	 */
	public function test_get_reader_charge_summary_returns_empty_when_transaction_is_missing(): void {
		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/charges/txn_missing' );
		$request->set_param( 'transaction_id', 'txn_missing' );

		$response = $this->sut->get_reader_charge_summary( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( array(), $this->api_client->reader_charge_summary_calls );
	}

	/**
	 * @testdox Receipt preview accepts the preserved camelCase settings payload.
	 */
	public function test_preview_print_receipt_uses_preserved_settings_payload(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers/receipts/preview' );
		$request->set_body_params(
			array(
				'accountBusinessName'           => 'Receipt Lab',
				'accountBusinessSupportAddress' => array(
					'line1'       => '1 Support Way',
					'line2'       => 'Suite 2',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94107',
					'country'     => 'US',
				),
				'accountBusinessSupportPhone'   => '+1 555 0100',
				'accountBusinessSupportEmail'   => 'support@example.com',
			)
		);

		$response = $this->sut->preview_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Receipt Lab', $html );
		$this->assertStringContainsString( '1 Support Way', $html );
		$this->assertStringContainsString( '+1 555 0100 support@example.com', $html );
		$this->assertStringContainsString( 'Sample', $html );
		$this->assertStringContainsString( 'Application name', $html );
		$this->assertStringContainsString( 'AID', $html );
		$this->assertStringContainsString( 'Powered by WooCommerce', $html );
	}

	/**
	 * @testdox Generated receipts include order lines and terminal receipt fields.
	 */
	public function test_generate_print_receipt_uses_order_and_charge_receipt_data(): void {
		$order = $this->create_order( 12.34, 'USD' );

		$this->gateway_settings                       = array(
			'account_business_name'            => 'Generated Receipt Lab',
			'account_business_support_address' => array(
				'line1'       => '123 Support St',
				'city'        => 'San Francisco',
				'state'       => 'CA',
				'postal_code' => '94107',
				'country'     => 'US',
			),
			'account_business_support_phone'   => '+1 555 0200',
			'account_business_support_email'   => 'generated@example.com',
		);
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_receipt',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_receipt' ),
				),
			),
		);
		$this->api_client->charge_response            = array(
			'id'                     => 'ch_receipt',
			'amount_captured'        => 1234,
			'currency'               => 'usd',
			'order'                  => array(
				'number' => $order->get_id(),
			),
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'network' => 'eftpos_au',
					'last4'   => '0978',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Generated Receipt Lab', $html );
		$this->assertStringContainsString( '123 Support St', $html );
		$this->assertStringContainsString( '+1 555 0200 generated@example.com', $html );
		$this->assertStringContainsString( 'Order ' . $order->get_id(), $html );
		$this->assertStringContainsString( 'AMOUNT PAID', $html );
		$this->assertStringContainsString( 'eftpos - 0978', $html );
		$this->assertStringNotContainsString( 'Visa - 0978', $html );
		$this->assertStringContainsString( 'Visa credit', $html );
		$this->assertStringContainsString( 'A0000000031010', $html );
		$this->assertStringContainsString( 'Credit', $html );
	}

	/**
	 * @testdox Generated receipts embed the account branding logo like the reference client.
	 */
	public function test_generate_print_receipt_embeds_account_branding_logo(): void {
		$order = $this->create_order( 12.34, 'USD' );

		$this->gateway_settings                       = array(
			'account_business_name' => 'Logo Receipt Lab',
			'account_branding_logo' => 'file_logo_123',
		);
		$this->api_client->file_contents_response     = array(
			'content_type' => 'image/png',
			'file_content' => base64_encode( 'logo-bytes' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture image payload.
		);
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_receipt',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_receipt' ),
				),
			),
		);
		$this->api_client->charge_response            = array(
			'id'                     => 'ch_receipt',
			'amount_captured'        => 1234,
			'currency'               => 'usd',
			'order'                  => array(
				'number' => $order->get_id(),
			),
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '0978',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'file_logo_123', false ), $this->api_client->last_file_contents_request, 'The logo must be fetched platform-side (as_account false) like the reference client.' );
		$this->assertStringContainsString( 'class="branding-logo"', $html );
		$this->assertStringContainsString( 'data:image/png;base64,' . base64_encode( 'logo-bytes' ), $html ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture image payload.
	}

	/**
	 * @testdox Print receipts render through the theme-overridable WooCommerce template.
	 */
	public function test_print_receipt_renders_through_the_overridable_template(): void {
		$override = get_temp_dir() . 'wcpay-receipt-override-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $override, '<?php echo "THEME-OVERRIDE-RECEIPT"; ?>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.

		$locate = static function ( $template, $template_name ) use ( $override ) {
			return 'html-in-person-payment-receipt.php' === $template_name ? $override : $template;
		};
		add_filter( 'woocommerce_locate_template', $locate, 10, 2 );

		try {
			$response = $this->sut->preview_print_receipt( new WP_REST_Request( 'POST', '/wc/v3/payments/readers/receipts/preview' ) );
		} finally {
			remove_filter( 'woocommerce_locate_template', $locate, 10 );
			unlink( $override ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		}

		$this->assertSame( 'THEME-OVERRIDE-RECEIPT', $response->get_data()['html_content'] );
	}

	/**
	 * @testdox Generated receipts keep the preserved error envelope when the payment intent is invalid.
	 */
	public function test_generate_print_receipt_wraps_invalid_intent_in_preserved_error(): void {
		$this->api_client->payment_intention_response = array(
			'id'     => 'pi_receipt',
			'status' => 'requires_payment_method',
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'generate_print_receipt_error', $response->get_error_code() );
		$this->assertSame( 'Invalid payment intent', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	/**
	 * @testdox Store-location lookup creates a terminal location from the WooCommerce base address when none exists.
	 */
	public function test_get_store_location_creates_location_from_store_address(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );
		update_option( 'woocommerce_store_address_2', '' );

		$response = $this->sut->get_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'tml_created', $data['id'] );
		$this->assertSame( $this->get_site_location_name(), $this->api_client->last_created_location['display_name'] );
		$this->assertSame( 'US', $this->api_client->last_created_location['address']['country'] );
		$this->assertSame( 'CA', $this->api_client->last_created_location['address']['state'] );
		$this->assertSame( '123 Main St', $this->api_client->last_created_location['address']['line1'] );
		$this->assertSame( array(), $this->api_client->last_created_location['metadata'], 'The auto-created store location must carry no metadata, like the reference client.' );
	}

	/**
	 * @testdox Store-location address honors the woocommerce_countries_base_* filters like the reference client.
	 */
	public function test_get_store_location_address_honors_base_address_filters(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_address_2', 'Floor 2' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );

		add_filter( 'woocommerce_countries_base_address', fn() => '456 Warehouse Rd' );
		add_filter( 'woocommerce_countries_base_address_2', fn() => 'Dock 9' );
		add_filter( 'woocommerce_countries_base_city', fn() => 'Oakland' );
		add_filter( 'woocommerce_countries_base_postcode', fn() => '94607' );

		try {
			$response = $this->sut->get_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );
		} finally {
			remove_all_filters( 'woocommerce_countries_base_address' );
			remove_all_filters( 'woocommerce_countries_base_address_2' );
			remove_all_filters( 'woocommerce_countries_base_city' );
			remove_all_filters( 'woocommerce_countries_base_postcode' );
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$created_address = $this->api_client->last_created_location['address'];
		$this->assertSame( '456 Warehouse Rd', $created_address['line1'] );
		$this->assertSame( 'Dock 9', $created_address['line2'] );
		$this->assertSame( 'Oakland', $created_address['city'] );
		$this->assertSame( '94607', $created_address['postal_code'] );
	}

	/**
	 * @testdox Store-location lookup reuses hostname-named locations created by the reference client.
	 */
	public function test_get_store_location_reuses_matching_hostname_location(): void {
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );
		update_option( 'woocommerce_store_address_2', '' );

		$this->api_client->terminal_locations_response = array(
			'data' => array(
				array(
					'id'           => 'tml_existing',
					'display_name' => $this->get_site_location_name(),
					'address'      => array(
						'country'     => 'US',
						'state'       => 'CA',
						'city'        => 'San Francisco',
						'postal_code' => '94107',
						'line1'       => '123 Main St',
					),
					'livemode'     => false,
				),
			),
		);

		$response = $this->sut->get_store_location( new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/store' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'tml_existing', $response->get_data()['id'] );
		$this->assertSame( array(), $this->api_client->last_created_location );
	}

	/**
	 * @testdox Terminal location lookup falls back to the direct location endpoint on cache misses.
	 */
	public function test_get_terminal_location_falls_back_to_direct_lookup_on_cache_miss(): void {
		$this->api_client->terminal_locations_response = array( 'data' => array() );
		$this->api_client->terminal_location_response  = array(
			'id'           => 'tml_direct',
			'display_name' => 'Direct',
			'address'      => array(
				'country' => 'US',
				'line1'   => '456 Market',
			),
			'livemode'     => false,
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/terminal/locations/tml_direct' );
		$request->set_param( 'location_id', 'tml_direct' );

		$response = $this->sut->get_terminal_location( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'tml_direct', $response->get_data()['id'] );
		$this->assertSame( array( 'tml_direct' ), $this->api_client->terminal_location_calls );
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
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( 'WooCommerce In-Person Payments', $order->get_payment_method_title() );
		$this->assertSame( 'pi_terminal', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'ch_terminal', $order->get_meta( '_charge_id', true ) );
		$this->assertSame( 'succeeded', $order->get_meta( '_intention_status', true ) );
		$this->assertSame( $order->get_meta( '_wcpay_payment_method_details', true ), $order->get_meta( '_wcpay_raw_payment_method_details', true ) );
		$this->assertStringContainsString( '/wc/v3/payments/readers/receipts/pi_terminal', (string) $order->get_meta( 'receipt_url', true ) );
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
	 * @testdox Terminal capture merges order-derived metadata under the intent's own, like the reference client.
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
	 * @testdox Terminal capture does not write an IPP channel the plugin does not recognize.
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
	 * @testdox An amount-too-small capture failure notes the provider minimum like the reference client.
	 */
	public function test_capture_failure_note_carries_the_amount_too_small_minimum(): void {
		$order = $this->create_order( 0.30, 'USD' );
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
		$this->api_client->captured_intention_exception     = new WooPaymentsApiException(
			'Amount must be at least $0.50 usd',
			'amount_too_small',
			400,
			'',
			'',
			array(
				'minimum_amount' => 50,
				'currency'       => 'usd',
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
		$this->assertStringContainsString( '0.50', wp_strip_all_tags( $note ) );
		$this->assertStringContainsString( 'USD', wp_strip_all_tags( $note ) );
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
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_intention_status', true ), 'A pre-check failure must not stamp capture-failure state on the order.' );
		$this->assertSame( '', $this->get_order_note_containing( $order, 'capture' ), 'A pre-check failure must not leave a capture note on the order.' );
	}

	/**
	 * @testdox Terminal capture rejects intents without matching order metadata.
	 */
	public function test_capture_terminal_payment_rejects_intent_without_order_metadata(): void {
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
		$this->assertNotSame( OrderPaymentStore::GATEWAY_ID, $order->get_payment_method() );
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
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
		$this->assertSame( '', $order->get_meta( '_intent_id', true ) );
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
		$this->api_client->captured_intention_exception     = new WooPaymentsApiException( 'The card was declined at capture.', 'wcpay_capture_error', 402 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );
		$order    = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'on-hold', $order->get_status(), 'A plain capture failure must leave the original authorization active.' );
		$this->assertNotEmpty( $this->get_order_note_containing( $order, 'The card was declined at capture.' ) );
	}

	/**
	 * @testdox Terminal capture preserves the amount-too-small machine-readable error code.
	 */
	public function test_capture_terminal_payment_returns_amount_too_small_error_details(): void {
		$order                                        = $this->create_order( 12.34, 'USD' );
		$error_details                                = array(
			'minimum_amount'          => 50,
			'minimum_amount_currency' => 'USD',
		);
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);
		$this->api_client->captured_intention_response = array(
			'id'            => 'pi_terminal',
			'status'        => 'requires_capture',
			'http_code'     => 400,
			'error_code'    => 'amount_too_small',
			'extra_details' => $error_details,
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_terminal' );

		$response = $this->sut->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'wcpay_capture_error_amount_too_small', $response->get_error_code() );
		$this->assertSame( esc_html( wp_json_encode( $error_details ) ), $response->get_error_message() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
	}

	/**
	 * @testdox Terminal capture converts a thrown amount-too-small API error into the machine-readable payload.
	 */
	public function test_capture_terminal_payment_converts_amount_too_small_exception_to_error_details(): void {
		$order                                        = $this->create_order( 0.30, 'USD' );
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_terminal',
			'status'   => 'requires_capture',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);
		$this->api_client->captured_intention_exception = new WooPaymentsApiException(
			'Amount must be at least $0.50 usd',
			'amount_too_small',
			400,
			'',
			'',
			array(
				'minimum_amount' => 50,
				'currency'       => 'usd',
			)
		);

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
	 * Create a native mobile REST controller.
	 *
	 * @param bool $native_register Whether native should own route registration.
	 * @return WooPaymentsMobileRestController
	 */
	private function create_controller( bool $native_register ): WooPaymentsMobileRestController {
		$arbiter = $this->getMockBuilder( NativePaymentsRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_native_register' ) )
			->getMock();
		$arbiter->method( 'should_native_register' )->willReturn( $native_register );

		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'refresh_account_data' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( true );
		$account_service->method( 'refresh_account_data' )->willReturnCallback(
			function (): array {
				++$this->account_refresh_calls;

				return array();
			}
		);
		$account_service->method( 'get_mode' )->willReturn( 'test' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			function ( string $key, $fallback = null ) {
				return array_key_exists( $key, $this->gateway_settings ) ? $this->gateway_settings[ $key ] : $fallback;
			}
		);

		$customer_service = new WooPaymentsCustomerService();
		$customer_service->init( $this->api_client, $account_service, new WooPaymentsSessionService() );

		$controller = new WooPaymentsMobileRestController();
		$controller->init( $arbiter, $this->api_client, $account_service, $customer_service, new WooPaymentsOrderDataService(), new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService() );

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

	/**
	 * Expected route map and HTTP methods.
	 *
	 * @return array<string,string[]>
	 */
	private function get_expected_routes(): array {
		return array(
			'/wc/v3/payments/connection_tokens'        => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/orders/(?P<order_id>\\w+)/capture_terminal_payment' => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/orders/(?P<order_id>\\w+)/prepare_terminal_payment' => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/orders/(?P<order_id>\\w+)/create_terminal_intent' => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/orders/(?P<order_id>\\d+)/create_customer' => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/readers'                  => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/readers/charges/(?P<transaction_id>\\w+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/readers/receipts/preview' => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/readers/receipts/(?P<payment_intent_id>\\w+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/terminal/locations/store' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/terminal/locations'       => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/terminal/locations/(?P<location_id>\\w+)' => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE, WP_REST_Server::DELETABLE ),
		);
	}

	/**
	 * Assert a route handler supports a method.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 * @param string                         $method         Method constant.
	 */
	private function assertRouteHasMethod( array $route_handlers, string $method ): void {
		foreach ( $route_handlers as $handler ) {
			if ( isset( $handler['methods'][ $method ] ) && true === $handler['methods'][ $method ] ) {
				return;
			}
		}

		$this->fail( 'Route does not accept method ' . $method . '.' );
	}

	/**
	 * Get the current test site's hostname.
	 *
	 * @return string
	 */
	private function get_site_location_name(): string {
		return str_replace( array( 'https://', 'http://' ), '', get_site_url() );
	}
}
