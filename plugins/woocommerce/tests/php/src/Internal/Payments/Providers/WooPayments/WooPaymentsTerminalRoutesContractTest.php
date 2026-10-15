<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminRestRouteRegistrar;
use WC_Helper_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Pins the in-person payment routes the WooCommerce mobile apps call: each route's methods and arguments, who may call it,
 * and its answer, through the provider's production REST registration. It names no controller class.
 */
class WooPaymentsTerminalRoutesContractTest extends WC_REST_Unit_Test_Case {

	/**
	 * Recording platform client the controllers resolve from the container.
	 *
	 * @var RecordingTerminalApiClient
	 */
	private RecordingTerminalApiClient $api_client;

	/**
	 * Connected account in the shape the platform answers (Fixtures/platform-test-drive-account.json `account`), trimmed
	 * to what these routes read.
	 *
	 * @var array<string,mixed>
	 */
	private const ACCOUNT = array(
		'account_id'       => 'acct_terminal_routes',
		'is_live'          => true,
		'business_profile' => array(
			'name'            => 'Terminal Routes Store',
			'support_address' => array(
				'city'        => 'San Francisco',
				'country'     => 'US',
				'line1'       => '123 Support St',
				'line2'       => '',
				'postal_code' => '94107',
				'state'       => 'CA',
			),
			'support_email'   => 'support@example.test',
			'support_phone'   => '+1 555 0100',
		),
		'branding'         => array(
			'logo' => null,
		),
	);

	/**
	 * Register the provider's REST controllers the way a connected store's request does, on a fresh REST server.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = new class() extends RecordingTerminalApiClient {
			/**
			 * The test store is connected.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Answer the connected account.
			 *
			 * @param string $woocommerce_store_id Store ID.
			 * @return array<string,mixed>
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				unset( $woocommerce_store_id );

				return WooPaymentsTerminalRoutesContractTest::get_account();
			}
		};
		wc_get_container()->replace( WooPaymentsApiClient::class, $this->api_client );
		wc_get_container()->reset_all_resolved();

		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		update_option(
			'wcpay_account_data',
			array(
				'data'    => self::ACCOUNT,
				'fetched' => time(),
				'errored' => false,
			)
		);
		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'woocommerce_store_address', '123 Main St' );
		update_option( 'woocommerce_store_address_2', '' );
		update_option( 'woocommerce_store_city', 'San Francisco' );
		update_option( 'woocommerce_store_postcode', '94107' );
		delete_transient( 'wcpay_store_terminal_readers' );
		delete_transient( 'wcpay_store_terminal_locations' );

		foreach ( WooPaymentsAdminRestRouteRegistrar::get_connected_controller_roots() as $root ) {
			wc_get_container()->get( $root )->register();
		}
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A fresh REST server with the provider's routes; the base test case resets it.
		$GLOBALS['wp_rest_server'] = new WP_REST_Server();
		$this->server              = $GLOBALS['wp_rest_server'];
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Initializing the REST server in a test.
		do_action( 'rest_api_init', $this->server );
	}

	/**
	 * Drop the recording client and the controllers built with it.
	 */
	public function tearDown(): void {
		try {
			wc_get_container()->reset_all_replacements();
			wc_get_container()->reset_all_resolved();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Get the connected account.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_account(): array {
		return self::ACCOUNT;
	}

	/**
	 * Every route keeps its arguments, refuses customers and visitors before reaching the platform, lets administrators and
	 * shop managers in, and answers an administrator as the WooPayments extension does
	 * (includes/admin/class-wc-rest-payments-orders-controller.php, class-wc-rest-payments-connection-tokens-controller.php,
	 * class-wc-rest-payments-reader-controller.php, class-wc-rest-payments-terminal-locations-controller.php).
	 *
	 * @testdox The $method $route route keeps its arguments, its callers and its answer.
	 * @dataProvider provide_routes
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Registered route pattern.
	 * @param array<string,mixed> $args   Expected route arguments.
	 */
	public function test_route_keeps_its_arguments_callers_and_answer( string $method, string $route, array $args ): void {
		$order   = $this->create_order();
		$request = $this->arrange( $method, $route, $order );

		$handler = $this->get_handler( $route, $method );
		$this->assertSame( $args, $handler['args'], 'The route arguments are unchanged.' );

		$users = array(
			'administrator' => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'shop manager'  => self::factory()->user->create( array( 'role' => 'shop_manager' ) ),
			'customer'      => self::factory()->user->create( array( 'role' => 'customer' ) ),
			'visitor'       => 0,
		);
		foreach ( $users as $label => $user_id ) {
			wp_set_current_user( $user_id );
			$this->assertSame( in_array( $label, array( 'administrator', 'shop manager' ), true ), call_user_func( $handler['permission_callback'], $request ), "Permission for the {$label}." );
		}

		foreach ( array( 'customer', 'visitor' ) as $label ) {
			wp_set_current_user( $users[ $label ] );
			$response = $this->server->dispatch( $request );
			$this->assertSame( rest_authorization_required_code(), $response->get_status(), "The {$label} is refused." );
		}
		$this->assertSame( array(), $this->get_platform_calls(), 'A refused request reaches no platform method.' );

		wp_set_current_user( $users['administrator'] );
		$response = $this->server->dispatch( $request );
		$this->assert_answer( $method, $route, $order, $response->get_status(), $response->get_data() );
	}

	/**
	 * One row per route and method.
	 *
	 * @return array<string,array{0:string,1:string,2:array<string,mixed>}>
	 */
	public function provide_routes(): array {
		$intent_id_arg = array( 'payment_intent_id' => array( 'required' => true ) );

		return array(
			'connection token' => array( 'POST', '/wc/v3/payments/connection_tokens', array() ),
			'capture'          => array( 'POST', '/wc/v3/payments/orders/(?P<order_id>\w+)/capture_terminal_payment', $intent_id_arg ),
			'prepare'          => array(
				'POST',
				'/wc/v3/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment',
				array(
					'order_id'          => array(
						'required' => true,
						'type'     => 'integer',
					),
					'payment_intent_id' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			),
			'terminal intent'  => array( 'POST', '/wc/v3/payments/orders/(?P<order_id>\w+)/create_terminal_intent', array() ),
			'customer'         => array( 'POST', '/wc/v3/payments/orders/(?P<order_id>\d+)/create_customer', array() ),
			'readers list'     => array( 'GET', '/wc/v3/payments/readers', array() ),
			'reader register'  => array(
				'POST',
				'/wc/v3/payments/readers',
				array(
					'location'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'registration_code' => array(
						'type'     => 'string',
						'required' => true,
					),
					'label'             => array( 'type' => 'string' ),
					'metadata'          => array( 'type' => 'object' ),
				),
			),
			'reader charges'   => array( 'GET', '/wc/v3/payments/readers/charges/(?P<transaction_id>\w+)', array() ),
			'receipt preview'  => array( 'POST', '/wc/v3/payments/readers/receipts/preview', array() ),
			'receipt'          => array( 'GET', '/wc/v3/payments/readers/receipts/(?P<payment_intent_id>\w+)', array() ),
			'store location'   => array( 'GET', '/wc/v3/payments/terminal/locations/store', array() ),
			'locations list'   => array( 'GET', '/wc/v3/payments/terminal/locations', array() ),
			'location create'  => array(
				'POST',
				'/wc/v3/payments/terminal/locations',
				array(
					'display_name' => array(
						'type'     => 'string',
						'required' => true,
					),
					'address'      => array(
						'type'     => 'object',
						'required' => true,
					),
				),
			),
			'location read'    => array( 'GET', '/wc/v3/payments/terminal/locations/(?P<location_id>\w+)', array() ),
			'location update'  => array(
				'POST',
				'/wc/v3/payments/terminal/locations/(?P<location_id>\w+)',
				array(
					'display_name' => array(
						'type'     => 'string',
						'required' => false,
					),
					'address'      => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			),
			'location delete'  => array( 'DELETE', '/wc/v3/payments/terminal/locations/(?P<location_id>\w+)', array() ),
		);
	}

	/**
	 * @testdox A request without a required argument is refused with the WordPress argument error: $method $path.
	 * @testWith ["POST", "/wc/v3/payments/orders/{order_id}/capture_terminal_payment"]
	 *           ["POST", "/wc/v3/payments/orders/{order_id}/prepare_terminal_payment"]
	 *           ["POST", "/wc/v3/payments/readers"]
	 *           ["POST", "/wc/v3/payments/terminal/locations"]
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Request path.
	 */
	public function test_required_arguments_are_enforced( string $method, string $path ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$path = str_replace( '{order_id}', (string) $this->create_order()->get_id(), $path );

		$response = $this->server->dispatch( new WP_REST_Request( $method, $path ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
		$this->assertSame( array(), $this->get_platform_calls() );
	}

	/**
	 * @testdox The in-person payment routes register in the same order, so each path reaches the same route.
	 */
	public function test_routes_register_in_order(): void {
		$expected = array_values( array_unique( array_column( $this->provide_routes(), 1 ) ) );
		$actual   = array_values( array_intersect( array_keys( $this->server->get_routes() ), $expected ) );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Set the platform answers a route reads and build its request.
	 *
	 * The readers and locations lists are bare lists: the WooPayments extension iterates the platform's answer directly
	 * (includes/admin/class-wc-rest-payments-reader-controller.php:254-268, class-wc-rest-payments-terminal-locations-controller.php:310-319).
	 *
	 * @param string    $method HTTP method.
	 * @param string    $route  Registered route pattern.
	 * @param \WC_Order $order  Order for the order routes.
	 * @return WP_REST_Request
	 */
	private function arrange( string $method, string $route, \WC_Order $order ): WP_REST_Request {
		$path   = preg_replace(
			array( '/\(\?P<order_id>[^)]+\)/', '/\(\?P<transaction_id>[^)]+\)/', '/\(\?P<payment_intent_id>[^)]+\)/', '/\(\?P<location_id>[^)]+\)/' ),
			array( (string) $order->get_id(), 'txn_terminal', 'pi_receipt', 'tml_store' ),
			$route
		);
		$params = array();

		switch ( $method . ' ' . $route ) {
			case 'POST /wc/v3/payments/connection_tokens':
				$this->api_client->connection_token_response = array(
					'object' => 'terminal.connection_token',
					'secret' => 'pst_test_terminal',
				);
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\w+)/capture_terminal_payment':
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
				$params                                        = array( 'payment_intent_id' => 'pi_terminal' );
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment':
				$params = array( 'payment_intent_id' => 'pi_terminal' );
				break;
			case 'GET /wc/v3/payments/readers':
				$this->api_client->terminal_readers_response      = array( $this->get_platform_reader() );
				$this->api_client->reader_charge_summary_response = array(
					array(
						'reader_id' => 'tmr_counter',
						'status'    => 'active',
						'count'     => 3,
					),
				);
				break;
			case 'POST /wc/v3/payments/readers':
				$this->api_client->terminal_reader_response = $this->get_platform_reader();
				$params                                     = array(
					'location'          => 'tml_store',
					'registration_code' => 'simulated-wpe',
					'label'             => 'Counter',
				);
				break;
			case 'GET /wc/v3/payments/readers/charges/(?P<transaction_id>\w+)':
				$this->api_client->transaction_response           = array( 'created' => 1760486400 );
				$this->api_client->reader_charge_summary_response = array(
					array(
						'reader_id' => 'tmr_counter',
						'status'    => 'active',
						'count'     => 1,
					),
				);
				break;
			case 'POST /wc/v3/payments/readers/receipts/preview':
				$params = array( 'accountBusinessName' => 'Preview Store' );
				break;
			case 'GET /wc/v3/payments/readers/receipts/(?P<payment_intent_id>\w+)':
				// The charge carries no order number, so the order comes from the intent's metadata (client
				// includes/admin/class-wc-rest-payments-reader-controller.php:283-295).
				$this->api_client->payment_intention_response = array(
					'id'       => 'pi_receipt',
					'status'   => 'succeeded',
					'currency' => 'usd',
					'amount'   => 1234,
					'metadata' => array( 'order_id' => (string) $order->get_id() ),
					'charges'  => array( 'data' => array( array( 'id' => 'ch_receipt' ) ) ),
				);
				$this->api_client->charge_response            = array(
					'id'                     => 'ch_receipt',
					'amount_captured'        => 1234,
					'currency'               => 'usd',
					'payment_method_details' => array(
						'card_present' => array(
							'brand'   => 'visa',
							'last4'   => '0978',
							'receipt' => array(
								'application_preferred_name' => 'visa credit',
								'dedicated_file_name' => 'a0000000031010',
								'account_type'        => 'credit',
							),
						),
					),
				);
				break;
			case 'GET /wc/v3/payments/terminal/locations/store':
			case 'GET /wc/v3/payments/terminal/locations':
			case 'GET /wc/v3/payments/terminal/locations/(?P<location_id>\w+)':
				$this->api_client->terminal_locations_response = array( $this->get_platform_location() );
				break;
			case 'POST /wc/v3/payments/terminal/locations':
				$params = array(
					'display_name' => 'Warehouse',
					'address'      => array(
						'country'     => 'US',
						'city'        => 'Oakland',
						'line1'       => '9 Dock Rd',
						'postal_code' => '94601',
					),
				);
				break;
			case 'POST /wc/v3/payments/terminal/locations/(?P<location_id>\w+)':
				$params = array( 'display_name' => 'Front counter' );
				break;
		}

		$request = new WP_REST_Request( $method, $path );
		if ( array() !== $params ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return $request;
	}

	/**
	 * Assert an administrator's answer, as the WooPayments extension gives it.
	 *
	 * @param string    $method HTTP method.
	 * @param string    $route  Registered route pattern.
	 * @param \WC_Order $order  Order for the order routes.
	 * @param int       $status Answer status.
	 * @param mixed     $data   Answer data.
	 */
	private function assert_answer( string $method, string $route, \WC_Order $order, int $status, $data ): void {
		$this->assertSame( 200, $status, 'The administrator is answered. ' . wp_json_encode( $data ) );
		$location = array(
			'id'           => 'tml_store',
			'address'      => $this->get_platform_location()['address'],
			'display_name' => $this->get_site_host(),
			'livemode'     => false,
		);

		switch ( $method . ' ' . $route ) {
			case 'POST /wc/v3/payments/connection_tokens':
				// The client adds the test mode flag to the platform's token (class-wc-rest-payments-connection-tokens-controller.php:42-55).
				$this->assertSame(
					array(
						'object'    => 'terminal.connection_token',
						'secret'    => 'pst_test_terminal',
						'test_mode' => true,
					),
					$data
				);
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\w+)/capture_terminal_payment':
				// class-wc-rest-payments-orders-controller.php:282-288.
				$this->assertSame(
					array(
						'status' => 'succeeded',
						'id'     => 'pi_terminal',
					),
					$data
				);
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\w+)/prepare_terminal_payment':
				// The platform's prepared PaymentIntent, passed through (class-wc-rest-payments-orders-controller.php:330-345).
				$this->assertSame(
					array(
						'id'     => 'pi_terminal',
						'object' => 'payment_intent',
						'status' => 'requires_confirmation',
					),
					$data
				);
				$this->assertSame(
					array(
						array(
							'intent_id' => 'pi_terminal',
							'order_id'  => $order->get_id(),
						),
					),
					$this->api_client->prepared_terminal_payments
				);
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\w+)/create_terminal_intent':
				// class-wc-rest-payments-orders-controller.php:540-545.
				$this->assertSame( array( 'id' => 'pi_terminal' ), $data );
				$this->assertSame( 1234, $this->api_client->last_terminal_intent_payload['amount'] ?? null );
				break;
			case 'POST /wc/v3/payments/orders/(?P<order_id>\d+)/create_customer':
				// class-wc-rest-payments-orders-controller.php:492-498.
				$this->assertSame( array( 'id' => 'cus_created' ), $data );
				break;
			case 'GET /wc/v3/payments/readers':
				// The client's fields, and is_active from the charge summary (class-wc-rest-payments-reader-controller.php:254-268).
				$this->assertSame( array( $this->sorted( $this->get_answered_reader() + array( 'is_active' => true ) ) ), array_map( array( $this, 'sorted' ), $data ) );
				break;
			case 'POST /wc/v3/payments/readers':
				// class-wc-rest-payments-reader-controller.php:201-207.
				$this->assertSame( $this->sorted( $this->get_answered_reader() ), $this->sorted( $data ) );
				break;
			case 'GET /wc/v3/payments/readers/charges/(?P<transaction_id>\w+)':
				// The platform's summary, passed through (class-wc-rest-payments-reader-controller.php:151-170).
				$this->assertSame(
					array(
						array(
							'reader_id' => 'tmr_counter',
							'status'    => 'active',
							'count'     => 1,
						),
					),
					$data
				);
				break;
			case 'POST /wc/v3/payments/readers/receipts/preview':
				$this->assertSame( array( 'html_content' ), array_keys( $data ) );
				$this->assertStringContainsString( 'Preview Store', $data['html_content'] );
				$this->assertStringContainsString( 'support@example.test', $data['html_content'] );
				break;
			case 'GET /wc/v3/payments/readers/receipts/(?P<payment_intent_id>\w+)':
				$this->assertSame( array( 'html_content' ), array_keys( $data ) );
				$this->assertStringContainsString( 'Terminal Routes Store', $data['html_content'] );
				$this->assertStringContainsString( 'Order ' . $order->get_id(), $data['html_content'] );
				$this->assertStringContainsString( '0978', $data['html_content'] );
				break;
			case 'GET /wc/v3/payments/terminal/locations/store':
			case 'GET /wc/v3/payments/terminal/locations/(?P<location_id>\w+)':
				// The matching location's fields (class-wc-rest-payments-terminal-locations-controller.php:153-163, :236-256, :295-303).
				$this->assertSame( $location, $data );
				break;
			case 'GET /wc/v3/payments/terminal/locations':
				// class-wc-rest-payments-terminal-locations-controller.php:281-287.
				$this->assertSame( array( $location ), $data );
				break;
			case 'POST /wc/v3/payments/terminal/locations':
				// class-wc-rest-payments-terminal-locations-controller.php:263-272.
				$this->assertSame(
					array(
						'id'           => 'tml_created',
						'display_name' => 'Warehouse',
						'address'      => array(
							'country'     => 'US',
							'city'        => 'Oakland',
							'line1'       => '9 Dock Rd',
							'postal_code' => '94601',
						),
						'livemode'     => false,
					),
					$data
				);
				break;
			case 'POST /wc/v3/payments/terminal/locations/(?P<location_id>\w+)':
				// class-wc-rest-payments-terminal-locations-controller.php:218-229.
				$this->assertSame(
					array(
						'id'           => 'tml_store',
						'address'      => array( 'country' => 'US' ),
						'display_name' => 'Front counter',
						'livemode'     => false,
					),
					$data
				);
				break;
			case 'DELETE /wc/v3/payments/terminal/locations/(?P<location_id>\w+)':
				// The platform's answer, passed through (class-wc-rest-payments-terminal-locations-controller.php:200-211).
				$this->assertSame(
					array(
						'id'      => 'tml_store',
						'object'  => 'terminal.location',
						'deleted' => true,
					),
					$data
				);
				break;
			default:
				$this->fail( "No expected answer for {$method} {$route}." );
		}
	}

	/**
	 * Get the registered handler for a route and method.
	 *
	 * @param string $route  Registered route pattern.
	 * @param string $method HTTP method.
	 * @return array<string,mixed>
	 */
	private function get_handler( string $route, string $method ): array {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $route, $routes, 'The route is registered.' );
		foreach ( $routes[ $route ] as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) {
				return $handler;
			}
		}

		$this->fail( "The route does not accept {$method}." );
	}

	/**
	 * Get every platform call the recording client saw.
	 *
	 * @return array<string,mixed>
	 */
	private function get_platform_calls(): array {
		return array_filter(
			array(
				'terminal intent'  => $this->api_client->last_terminal_intent_payload,
				'prepare'          => $this->api_client->prepared_terminal_payments,
				'reader register'  => $this->api_client->last_registered_reader,
				'charge summaries' => $this->api_client->reader_charge_summary_calls,
				'location reads'   => $this->api_client->terminal_location_calls,
				'location create'  => $this->api_client->last_created_location,
				'location updates' => $this->api_client->updated_locations,
				'location deletes' => $this->api_client->deleted_locations,
				'intent reads'     => $this->api_client->payment_intention_requests,
				'captures'         => $this->api_client->captures,
				'customer updates' => $this->api_client->updated_customers,
				'customer creates' => $this->api_client->created_customers,
			)
		);
	}

	/**
	 * Get a Stripe Terminal Reader as the platform proxies it, trimmed (https://docs.stripe.com/api/terminal/readers/object).
	 *
	 * @return array<string,mixed>
	 */
	private function get_platform_reader(): array {
		return array(
			'id'                => 'tmr_counter',
			'object'            => 'terminal.reader',
			'device_sw_version' => '2.37.2.0',
			'device_type'       => 'bbpos_wisepos_e',
			'ip_address'        => '192.168.2.2',
			'label'             => 'Counter',
			'livemode'          => false,
			'location'          => 'tml_store',
			'metadata'          => array(),
			'serial_number'     => 'WSC513105000001',
			'status'            => 'online',
		);
	}

	/**
	 * Get the reader fields the extension answers (class-wc-rest-payments-reader-controller.php:201, :257-266).
	 *
	 * @return array<string,mixed>
	 */
	private function get_answered_reader(): array {
		return array(
			'id'          => 'tmr_counter',
			'livemode'    => false,
			'device_type' => 'bbpos_wisepos_e',
			'label'       => 'Counter',
			'location'    => 'tml_store',
			'metadata'    => array(),
			'status'      => 'online',
		);
	}

	/**
	 * Get a Stripe Terminal Location named after the store, at the store address (https://docs.stripe.com/api/terminal/locations/object).
	 *
	 * @return array<string,mixed>
	 */
	private function get_platform_location(): array {
		return array(
			'id'           => 'tml_store',
			'object'       => 'terminal.location',
			'address'      => array(
				'city'        => 'San Francisco',
				'country'     => 'US',
				'line1'       => '123 Main St',
				'postal_code' => '94107',
				'state'       => 'CA',
			),
			'display_name' => $this->get_site_host(),
			'livemode'     => false,
			'metadata'     => array(),
		);
	}

	/**
	 * Sort an object's keys: the apps read JSON objects, whose key order carries no meaning.
	 *
	 * @param array<string,mixed> $item Object.
	 * @return array<string,mixed>
	 */
	private function sorted( array $item ): array {
		ksort( $item );

		return $item;
	}

	/**
	 * Get the site's host, the store location's name.
	 *
	 * @return string
	 */
	private function get_site_host(): string {
		return str_replace( array( 'https://', 'http://' ), '', get_site_url() );
	}

	/**
	 * Create a pending order of 12.34 USD.
	 *
	 * @return \WC_Order
	 */
	private function create_order(): \WC_Order {
		$order = WC_Helper_Order::create_order();
		$order->set_total( 12.34 );
		$order->set_currency( 'USD' );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}
}
