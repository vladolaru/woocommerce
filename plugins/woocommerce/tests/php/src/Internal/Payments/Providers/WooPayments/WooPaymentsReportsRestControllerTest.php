<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLevel3Service;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsReportsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransactionsListRequest;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tests for the native WooPayments Reports REST controller.
 */
class WooPaymentsReportsRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * Recording API client.
	 *
	 * @var RecordingReportsApiClient
	 */
	private RecordingReportsApiClient $api_client;

	/**
	 * Original site timezone.
	 *
	 * @var string
	 */
	private string $original_timezone_string;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client               = new RecordingReportsApiClient();
		$this->original_timezone_string = (string) get_option( 'timezone_string' );
		update_option( 'timezone_string', 'UTC' );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_get_reporting_balance_summary_request' );
		remove_all_filters( 'wcpay_list_transactions_request' );
		update_option( 'timezone_string', $this->original_timezone_string );
		wp_set_current_user( 0 );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Reports routes are registered whether or not the account Reports area is available ($scenario).
	 * @dataProvider provide_reports_area_availability
	 *
	 * @param string $scenario        Case.
	 * @param bool   $reports_enabled Whether the account Reports area is available.
	 */
	public function test_registers_routes( string $scenario, bool $reports_enabled ): void {
		unset( $scenario );
		$controller = $this->create_controller( $reports_enabled );
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsReportsRestController::class, $controller );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$this->assertExpectedRoutesRegistered();
	}

	/**
	 * Provide the account Reports area states: case, whether the area is available.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provide_reports_area_availability(): array {
		return array(
			'Reports area available'   => array( 'Reports area available', true ),
			'Reports area unavailable' => array( 'Reports area unavailable', false ),
		);
	}

	/**
	 * @testdox Reports routes require manage_woocommerce before calling the platform API.
	 */
	public function test_routes_require_manage_woocommerce(): void {
		$this->create_controller( true )->register_routes();
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/balance' );
		$request->set_query_params(
			array(
				'date_start' => '2026-06-01T00:00:00Z',
				'date_end'   => '2026-06-19T23:59:59Z',
				'currency'   => 'usd',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( rest_authorization_required_code(), $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Reports routes deny disabled Reports and missing accounts before calling the platform API.
	 */
	public function test_routes_require_enabled_reports_and_an_account_before_calling_the_platform_api(): void {
		$this->create_controller( false, true )->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );

		$this->create_controller( true, false )->register_routes();
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox Balance report forwards date range and lowercase currency to the reporting endpoint.
	 */
	public function test_balance_report_forwards_sanitized_query(): void {
		$this->create_controller( true )->register_routes();
		$this->api_client->response = array(
			'currency'                         => 'usd',
			'starting_balance'                 => array(
				'amount' => 1000,
				'count'  => 1,
			),
			'reader_fees'                      => array(
				'amount' => -150,
				'count'  => 1,
			),
			'net_balance_change_in_the_period' => array(
				'amount' => 850,
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/balance' );
		$request->set_query_params(
			array(
				'date_start' => '2026-06-01T00:00:00Z',
				'date_end'   => '2026-06-19T23:59:59Z',
				'currency'   => 'USD',
				'ignored'    => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_reporting_balance_summary', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'date_start' => '2026-06-01T00:00:00Z',
				'date_end'   => '2026-06-19T23:59:59Z',
				'currency'   => 'usd',
			),
			$this->api_client->last_call['query']
		);
		$this->assertSame( $this->api_client->response, $response->get_data() );
		$this->assertSame( -150, $response->get_data()['reader_fees']['amount'] );
		$this->assertSame( 850, $response->get_data()['net_balance_change_in_the_period']['amount'] );
	}

	/**
	 * @testdox Report routes reject malformed input before calling the platform.
	 *
	 * Client 11.1.0 class-wc-rest-payments-reports-transactions-controller.php:161-254 and the Fees variant :233-269.
	 *
	 * @dataProvider provide_rejected_report_queries
	 *
	 * @param string              $route Route.
	 * @param array<string,mixed> $query Query.
	 */
	public function test_report_collections_reject_malformed_input( string $route, array $query ): void {
		$this->create_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $query );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( array(), $this->api_client->last_call );
	}

	/**
	 * @testdox The Fees routes accept the dates the admin UI sends: day boundaries as UTC "Y-m-d H:i:s", like the WooPayments extension's formatDateValue().
	 */
	public function test_fees_routes_accept_the_admin_ui_dates(): void {
		$this->create_controller( true )->register_routes();

		foreach ( array( '/wc/v3/payments/reports/fees', '/wc/v3/payments/reports/fees/summary' ) as $route ) {
			$request = new WP_REST_Request( 'GET', $route );
			$request->set_query_params(
				array(
					'date_after'    => '2026-03-31 21:00:00',
					'date_before'   => '2026-04-30 20:59:59',
					'date_between'  => array( '2026-03-31 21:00:00', '2026-04-30 20:59:59' ),
					'user_timezone' => '+03:00',
				)
			);
			$response = $this->server->dispatch( $request );

			$this->assertSame( 200, $response->get_status(), $route );
		}
	}

	/**
	 * Malformed report queries, one per input class.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>}>
	 */
	public function provide_rejected_report_queries(): array {
		return array(
			'page size over 100'       => array( '/wc/v3/payments/reports/transactions', array( 'per_page' => 101 ) ),
			'order ID not an integer'  => array( '/wc/v3/payments/reports/authorizations', array( 'order_id' => 'abc' ) ),
			'malformed date'           => array( '/wc/v3/payments/reports/transactions', array( 'date_after' => 'yesterday' ) ),
			'fees page below one'      => array( '/wc/v3/payments/reports/fees', array( 'page' => 0 ) ),
			'fees summary type object' => array( '/wc/v3/payments/reports/fees/summary', array( 'type' => array( array( 'nested' ) ) ) ),
			'invalid balance currency' => array(
				'/wc/v3/payments/reports/balance',
				array(
					'date_start' => '2026-06-01T00:00:00Z',
					'date_end'   => '2026-06-19T23:59:59Z',
					'currency'   => 'usd1',
				),
			),
		);
	}

	/**
	 * @testdox Fees list maps Reports filters to transaction filters and strips customer data from rows.
	 */
	public function test_fees_list_maps_filters_and_strips_customer_data(): void {
		$this->create_controller( true )->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ): TransactionsListRequest {
				$request->set( 'extension_custom_param', 'fees-custom' );

				return $request;
			}
		);
		$this->api_client->response = array(
			'data' => array(
				array(
					'transaction_id'    => 'txn_123',
					'date'              => '2026-06-19T10:00:00Z',
					'payment_intent_id' => 'pi_123',
					'channel'           => 'woocommerce',
					'source'            => 'card',
					'type'              => 'charge',
					'customer_currency' => 'usd',
					'amount'            => 2000,
					'exchange_rate'     => 1,
					'currency'          => 'usd',
					'fees'              => 59,
					'customer'          => array(
						'email' => 'ada@example.com',
					),
					'customer_name'     => 'Ada Lovelace',
					'customer_email'    => 'ada@example.com',
					'customer_country'  => 'US',
					'net'               => 1941,
					'order_id'          => 123,
					'risk_level'        => 'normal',
					'available_on'      => '2026-06-21T00:00:00Z',
					'deposit_id'        => 'po_123',
					'deposit_status'    => 'paid',
				),
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' );
		$request->set_query_params(
			array(
				'page'                => '2',
				'per_page'            => '50',
				'sort'                => 'date',
				'direction'           => 'desc',
				'date_between'        => array( '2026-06-01T00:00:00Z', '2026-06-19T23:59:59Z' ),
				'payment_method_type' => 'card',
				'type'                => array( 'charge', 'refund' ),
				'search'              => array( 'txn_123' ),
				'user_timezone'       => '+03:00',
				'ignored'             => 'drop-me',
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
				'source_is'              => 'card',
				'type_is_in'             => array( 'charge', 'refund' ),
				'date_between'           => array( '2026-06-01 03:00:00', '2026-06-20 02:59:59' ),
				'user_timezone'          => '+03:00',
				'transaction_id_is'      => 'txn_123',
				'extension_custom_param' => 'fees-custom',
			),
			$this->api_client->last_call['query']
		);
		$this->assertSame( 'txn_123', $response->get_data()[0]['transaction_id'] );
		$this->assertSame( array( 'type' => 'card' ), $response->get_data()[0]['payment_method'] );
		$this->assertArrayNotHasKey( 'customer', $response->get_data()[0] );
	}

	/**
	 * @testdox Fees list defaults to fee-bearing transaction types and maps payout search to deposit ID.
	 */
	public function test_fees_list_defaults_types_and_maps_payout_search(): void {
		$this->create_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' );
		$request->set_query_params(
			array(
				'search' => array( 'po_123' ),
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'po_123', $this->api_client->last_call['query']['deposit_id'] );
		$this->assertSame(
			array(
				'charge',
				'payment',
				'payment_failure_refund',
				'payment_refund',
				'refund',
				'refund_failure',
				'dispute',
				'dispute_reversal',
				'fee_refund',
				'network_costs',
			),
			$this->api_client->last_call['query']['type_is_in']
		);
	}

	/**
	 * @testdox Fees list, summary, and export map the WooPayments extension's order search tokens to charge IDs.
	 */
	public function test_fees_routes_map_order_search_tokens_to_charge_ids(): void {
		$order = $this->create_order_with_charge( 'ch_reports_order' );
		$this->create_controller( true )->register_routes();

		$list_request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' );
		$list_request->set_query_params(
			array(
				'search' => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
			)
		);
		$this->server->dispatch( $list_request );

		$this->assertSame( array( 'ch_reports_order' ), $this->api_client->last_call['query']['search'] );

		$summary_request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees/summary' );
		$summary_request->set_query_params(
			array(
				'search' => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
			)
		);
		$this->server->dispatch( $summary_request );

		$this->assertSame( array( 'ch_reports_order' ), $this->api_client->last_call['filters']['search'] );

		$export_request = new WP_REST_Request( 'POST', '/wc/v3/payments/reports/fees/download' );
		$export_request->set_query_params(
			array(
				'search' => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
			)
		);
		$this->server->dispatch( $export_request );

		$this->assertSame( array( 'ch_reports_order' ), $this->api_client->last_call['filters']['search'] );
	}

	/**
	 * @testdox Fees summary and export routes proxy the existing transactions summary and export APIs.
	 */
	public function test_fees_summary_and_export_routes_proxy_transactions_apis(): void {
		$this->create_controller( true )->register_routes();

		$summary_request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees/summary' );
		$summary_request->set_query_params(
			array(
				'deposit_id'    => 'po_123',
				'date_after'    => '2026-06-01T00:00:00Z',
				'type'          => array( 'charge' ),
				'user_timezone' => '+03:00',
			)
		);
		$this->server->dispatch( $summary_request );

		$this->assertSame( 'get_transactions_summary', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'type_is_in'    => array( 'charge' ),
				'date_after'    => '2026-06-01 03:00:00',
				'user_timezone' => '+03:00',
			),
			$this->api_client->last_call['filters']
		);
		$this->assertSame( 'po_123', $this->api_client->last_call['deposit_id'] );

		$export_request = new WP_REST_Request( 'POST', '/wc/v3/payments/reports/fees/download' );
		$export_request->set_query_params(
			array(
				'deposit_id'    => 'po_123',
				'date_before'   => '2026-06-19T23:59:59Z',
				'type'          => array( 'refund' ),
				'user_timezone' => '+03:00',
				'user_email'    => 'merchant@example.com',
				'locale'        => 'en_US',
			)
		);
		$this->server->dispatch( $export_request );

		$this->assertSame( 'start_transactions_export', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'type_is_in'    => array( 'refund' ),
				'date_before'   => '2026-06-20 02:59:59',
				'user_timezone' => '+03:00',
			),
			$this->api_client->last_call['filters']
		);
		$this->assertSame( 'po_123', $this->api_client->last_call['deposit_id'] );
		$this->assertSame( 'merchant@example.com', $this->api_client->last_call['user_email'] );
		$this->assertSame( 'en_US', $this->api_client->last_call['locale'] );

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees/download/export-123' ) );

		$this->assertSame( 'get_transactions_export_url', $this->api_client->last_call['method'] );
		$this->assertSame( 'export-123', $this->api_client->last_call['export_id'] );
	}

	/**
	 * @testdox Transaction report routes proxy transaction rows with customer context.
	 */
	public function test_transaction_report_routes_proxy_transaction_rows(): void {
		$this->create_controller( true )->register_routes();
		$filter_calls = 0;
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( &$filter_calls ): TransactionsListRequest {
				++$filter_calls;
				$request->set( 'extension_custom_param', 'report-transactions-custom' );

				return $request;
			}
		);
		$this->api_client->response = array(
			'data' => array(
				array(
					'transaction_id'    => 'txn_123',
					'date'              => '2026-06-19T10:00:00Z',
					'payment_intent_id' => 'pi_123',
					'channel'           => 'woocommerce',
					'source'            => 'card',
					'type'              => 'refund',
					'customer_currency' => 'usd',
					'amount'            => -1000,
					'exchange_rate'     => 1,
					'currency'          => 'usd',
					'fees'              => -30,
					'customer_name'     => 'Ada Lovelace',
					'customer_email'    => 'ada@example.com',
					'customer_country'  => 'US',
					'net'               => -970,
					'order_id'          => 123,
					'risk_level'        => 'normal',
					'available_on'      => '2026-06-21T00:00:00Z',
					'deposit_id'        => 'po_123',
					'deposit_status'    => 'paid',
				),
			),
		);

		$list_request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions' );
		$list_request->set_query_params(
			array(
				'per_page'            => '50',
				'type'                => 'refund',
				'order_id'            => '123',
				'customer_email'      => 'ada@example.com',
				'payment_method_type' => 'card',
			)
		);
		$list_response = $this->server->dispatch( $list_request );

		$this->assertSame( 200, $list_response->get_status() );
		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );
		$this->assertSame( 50, $this->api_client->last_call['query']['pagesize'] );
		$this->assertSame( 'refund', $this->api_client->last_call['query']['type_is'] );
		$this->assertSame( 123, $this->api_client->last_call['query']['order_id_is'], 'The route arg absints the order ID, and client 11.3.0 sends it as read.' );
		$this->assertSame( 'ada@example.com', $this->api_client->last_call['query']['customer_email_is'] );
		$this->assertSame( 'card', $this->api_client->last_call['query']['source_is'] );
		$this->assertSame( 'report-transactions-custom', $this->api_client->last_call['query']['extension_custom_param'] );
		$this->assertSame( 1, $filter_calls, 'The report transactions list should apply its request filter exactly once.' );
		$this->assertSame( 'txn_123', $list_response->get_data()[0]['transaction_id'] );
		$this->assertSame(
			array(
				'name'    => 'Ada Lovelace',
				'email'   => 'ada@example.com',
				'country' => 'US',
			),
			$list_response->get_data()[0]['customer']
		);

		$detail_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions/txn_123' ) );

		$this->assertSame( 200, $detail_response->get_status() );
		$this->assertSame( 'txn_123', $this->api_client->last_call['query']['transaction_id_is'] );
		$this->assertSame( 1, $this->api_client->last_call['query']['pagesize'] );
		$this->assertSame( 'date', $this->api_client->last_call['query']['sort'] );
		$this->assertSame( 'txn_123', $detail_response->get_data()['transaction_id'] );

		$this->api_client->response = array( 'data' => array() );
		$empty_response             = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions/txn_missing' ) );

		$this->assertSame( 200, $empty_response->get_status() );
		$this->assertSame( array(), $empty_response->get_data() );
	}

	/**
	 * @testdox Authorization report routes proxy authorization rows and detail lookup.
	 */
	public function test_authorization_report_routes_proxy_authorization_rows(): void {
		$this->create_controller( true )->register_routes();
		$this->api_client->response = array(
			'data' => array(
				array(
					'charge_id'         => 'ch_auth',
					'created'           => '2026-06-19T10:00:00Z',
					'payment_intent_id' => 'pi_auth',
					'channel'           => 'woocommerce',
					'source'            => 'card',
					'currency'          => 'usd',
					'amount'            => 2000,
					'amount_captured'   => 0,
					'fees'              => 59,
					'customer_name'     => 'Ada Lovelace',
					'customer_email'    => 'ada@example.com',
					'customer_country'  => 'US',
					'net'               => 1941,
					'order_id'          => 456,
					'risk_level'        => 'normal',
				),
			),
		);

		$list_request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/authorizations' );
		$list_request->set_query_params(
			array(
				'per_page'            => '10',
				'match'               => 'all',
				'order_id'            => '456',
				'customer_email'      => 'ada@example.com',
				'payment_method_type' => 'card',
				'date_between'        => array( '2026-06-01T00:00:00Z', '2026-06-19T23:59:59Z' ),
				'user_timezone'       => '+03:00',
			)
		);
		$list_response = $this->server->dispatch( $list_request );

		$this->assertSame( 200, $list_response->get_status() );
		$this->assertSame( 'get_authorizations', $this->api_client->last_call['method'] );
		$this->assertSame( 10, $this->api_client->last_call['query']['pagesize'] );
		$this->assertSame( 'all', $this->api_client->last_call['query']['match'] );
		$this->assertSame( '456', $this->api_client->last_call['query']['order_id_is'] );
		$this->assertSame( 'ada@example.com', $this->api_client->last_call['query']['customer_email_is'] );
		$this->assertSame( 'card', $this->api_client->last_call['query']['source_is'] );
		$this->assertSame( strtotime( '2026-06-01 03:00:00' ), $this->api_client->last_call['query']['from_date'] );
		$this->assertSame( strtotime( '2026-06-20 02:59:59' ), $this->api_client->last_call['query']['to_date'] );
		$this->assertSame( 'ch_auth', $list_response->get_data()[0]['authorization_id'] );
		$this->assertSame( array( 'type' => 'card' ), $list_response->get_data()[0]['payment_method'] );

		$detail_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/authorizations/ch_auth' ) );

		$this->assertSame( 200, $detail_response->get_status() );
		$this->assertSame( 'ch_auth', $this->api_client->last_call['query']['charge_id_is'] );
		$this->assertSame( 1, $this->api_client->last_call['query']['pagesize'] );
		$this->assertSame( 'ch_auth', $detail_response->get_data()['authorization_id'] );

		$this->api_client->response = array( 'data' => array() );
		$empty_response             = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/authorizations/ch_missing' ) );

		$this->assertSame( 200, $empty_response->get_status() );
		$this->assertSame( array(), $empty_response->get_data() );
	}

	/**
	 * @testdox Reports routes preserve platform exception status codes.
	 */
	public function test_reports_routes_preserve_platform_exception_status(): void {
		$this->create_controller( true )->register_routes();
		$this->api_client->exception = new ApiException( 'Provider unavailable.', 'wcpay_provider_unavailable', 503 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'wcpay_provider_unavailable', $response->get_data()['code'] );
	}

	/**
	 * A list request filter callback that returns something other than the request stops the request with the API client
	 * path's error, as the extension's request classes end it with a 500 (includes/core/server/class-request.php:528-569, :770-783).
	 *
	 * @testdox A $hook callback returning no request on $path answers 500 wcpay_invalid_filtered_request.
	 * @testWith ["/wc/v3/payments/reports/fees", "wcpay_list_transactions_request", false]
	 *           ["/wc/v3/payments/reports/transactions", "wcpay_list_transactions_request", false]
	 *           ["/wc/v3/payments/reports/transactions", "wcpay_list_transactions_request", true]
	 *           ["/wc/v3/payments/reports/transactions/txn_123", "wcpay_list_transactions_request", false]
	 *
	 * @param string $path      Route path.
	 * @param string $hook      Request filter.
	 * @param bool   $as_object Whether the callback returns an object of another class.
	 */
	public function test_list_filter_returning_no_request_fails_the_request( string $path, string $hook, bool $as_object ): void {
		$this->create_controller( true )->register_routes();
		add_filter(
			$hook,
			static function () use ( $as_object ) {
				return $as_object ? new \stdClass() : null;
			}
		);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $path ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_filtered_request', $response->get_data()['code'] );
		$this->assertSame( "Invalid WooPayments request returned by {$hook}.", $response->get_data()['message'] );
	}

	/**
	 * @testdox Fees list sends a search term a callback sets through set_param() as written, as client 11.3.0 does.
	 *
	 * Client 11.3.0 maps the Fees search before the filter (class-wc-rest-payments-reports-fees-controller.php:123) and runs
	 * nothing after it (:125).
	 */
	public function test_fees_list_sends_a_callback_set_param_search_as_written(): void {
		$order = $this->create_order_with_charge( 'ch_m' );
		$term  = __( 'Order #', 'woocommerce' ) . $order->get_id();
		$this->create_controller( true )->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( $term ): TransactionsListRequest {
				$request->set_param( 'search', array( $term ) );

				return $request;
			}
		);

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' ) );

		$this->assertSame( array( $term ), $this->api_client->last_call['query']['search'] );
	}

	/**
	 * @testdox Fees list sends an empty value a callback sets, as client 11.3.0 does.
	 */
	public function test_fees_list_sends_a_callback_empty_value(): void {
		$this->create_controller( true )->register_routes();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ): TransactionsListRequest {
				$request->set_param( 'match', '' );

				return $request;
			}
		);

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' ) );

		$this->assertArrayHasKey( 'match', $this->api_client->last_call['query'] );
		$this->assertSame( '', $this->api_client->last_call['query']['match'] );
	}

	/**
	 * @testdox Transactions report list maps a merchant's order search before the filter and sends a callback's set_param() term as written.
	 *
	 * Client 11.3.0 builds the list with List_Transactions::from_rest_request()
	 * (class-wc-rest-payments-reports-transactions-controller.php:61), whose set_search() maps the terms.
	 */
	public function test_report_transactions_list_maps_search_before_the_filter(): void {
		$order_n = $this->create_order_with_charge( 'ch_n' );
		$order_m = $this->create_order_with_charge( 'ch_m' );
		$term_m  = __( 'Order #', 'woocommerce' ) . $order_m->get_id();
		$this->create_controller( true )->register_routes();
		$seen = array();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( &$seen ): TransactionsListRequest {
				$seen[] = $request->get_param( 'search' );

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions' );
		$request->set_query_params( array( 'search' => array( __( 'Order #', 'woocommerce' ) . $order_n->get_id() ) ) );
		$this->server->dispatch( $request );

		$this->assertSame( array( array( 'ch_n' ) ), $seen );
		$this->assertSame( array( 'ch_n' ), $this->api_client->last_call['query']['search'] );

		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( $term_m ): TransactionsListRequest {
				$request->set_param( 'search', array( $term_m ) );

				return $request;
			},
			20
		);
		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions' ) );

		$this->assertSame( array( $term_m ), $this->api_client->last_call['query']['search'] );
	}

	/**
	 * @testdox Transactions report list sends $filter as client 11.3.0 does.
	 * @dataProvider provide_report_transactions_list_filters
	 *
	 * Client 11.3.0 reads every filter in List_Transactions::from_rest_request() (class-list-transactions.php:79-104), then
	 * applies per_page, type, order_id, customer_email and payment_method_type on top (reports controller :62-71). The date
	 * filters are shifted by `user_timezone`, which is not sent.
	 *
	 * @param string              $filter   Case.
	 * @param array<string,mixed> $query    Query params.
	 * @param array<string,mixed> $expected Expected platform params; null means the key is not sent.
	 */
	public function test_report_transactions_list_sends_the_client_filters( string $filter, array $query, array $expected ): void {
		unset( $filter );
		$this->create_controller( true )->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions' );
		$request->set_query_params( $query );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		foreach ( $expected as $key => $value ) {
			if ( null === $value ) {
				$this->assertArrayNotHasKey( $key, $this->api_client->last_call['query'] );
				continue;
			}
			$this->assertArrayHasKey( $key, $this->api_client->last_call['query'] );
			$this->assertSame( $value, $this->api_client->last_call['query'][ $key ] );
		}
	}

	/**
	 * Every filter client 11.3.0's report list reads that the report list did not.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public function provide_report_transactions_list_filters(): array {
		$cases = array();
		foreach ( array( 'type_is_not', 'source_device_is', 'source_device_is_not', 'channel_is', 'channel_is_not', 'customer_country_is', 'customer_country_is_not', 'risk_level_is', 'risk_level_is_not', 'store_currency_is', 'customer_currency_is', 'customer_currency_is_not', 'source_is_not', 'loan_id_is' ) as $name ) {
			$cases[ $name ] = array( $name, array( $name => 'value-' . $name ), array( $name => 'value-' . $name ) );
		}

		$cases['type_is']                                    = array( 'type_is', array( 'type_is' => 'charge' ), array( 'type_is' => 'charge' ) );
		$cases['type_is, replaced by type']                  = array(
			'type_is',
			array(
				'type_is' => 'charge',
				'type'    => 'refund',
			),
			array( 'type_is' => 'refund' ),
		);
		$cases['type_is_in, cast to an array']               = array( 'type_is_in', array( 'type_is_in' => 'charge' ), array( 'type_is_in' => array( 'charge' ) ) );
		$cases['source_is']                                  = array( 'source_is', array( 'source_is' => 'card' ), array( 'source_is' => 'card' ) );
		$cases['source_is, replaced by payment_method_type'] = array(
			'source_is',
			array(
				'source_is'           => 'card',
				'payment_method_type' => 'sepa_debit',
			),
			array( 'source_is' => 'sepa_debit' ),
		);
		$cases['search, cast to an array']                   = array( 'search', array( 'search' => 'Ada' ), array( 'search' => array( 'Ada' ) ) );
		$cases['channel_is, an empty string kept']           = array( 'channel_is', array( 'channel_is' => '' ), array( 'channel_is' => '' ) );
		$cases['user_timezone, not sent']                    = array(
			'user_timezone',
			array(
				'user_timezone' => '+03:00',
				'date_after'    => '2026-06-01T00:00:00Z',
			),
			array(
				'user_timezone' => null,
				'date_after'    => '2026-06-01 03:00:00',
			),
		);

		return $cases;
	}

	/**
	 * @testdox Transactions report row runs the list request filter once and sends client 11.3.0's request.
	 *
	 * Client 11.3.0 get_transaction() (class-wc-rest-payments-reports-transactions-controller.php:91-98): List_Transactions
	 * with `transaction_id_is`, sort `date` and page size 1, sent through the filter.
	 */
	public function test_report_transaction_runs_the_list_filter(): void {
		$this->create_controller( true )->register_routes();
		$seen = array();
		add_filter(
			'wcpay_list_transactions_request',
			static function ( TransactionsListRequest $request ) use ( &$seen ): TransactionsListRequest {
				$seen[] = array(
					'transaction_id_is' => $request->get_param( 'transaction_id_is' ),
					'pagesize'          => $request->get_param( 'pagesize' ),
					'sort'              => $request->get_param( 'sort' ),
				);

				return $request;
			}
		);

		$this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/reports/transactions/txn_123' ) );

		$this->assertSame(
			array(
				array(
					'transaction_id_is' => 'txn_123',
					'pagesize'          => 1,
					'sort'              => 'date',
				),
			),
			$seen
		);
		$this->assertSame(
			array(
				'page'              => 0,
				'pagesize'          => 1,
				'sort'              => 'date',
				'direction'         => 'desc',
				'limit'             => 100,
				'transaction_id_is' => 'txn_123',
			),
			$this->api_client->last_call['query']
		);
	}

	/**
	 * @testdox $path sends GET transactions with the returned request's params, whatever API or method that request names.
	 * @testWith ["/wc/v3/payments/reports/fees"]
	 *           ["/wc/v3/payments/reports/transactions"]
	 *           ["/wc/v3/payments/reports/transactions/txn_123"]
	 *
	 * The list request filter fires in the controller and only the returned request's params are kept (register row 523).
	 *
	 * @param string $path Route path.
	 */
	public function test_list_filter_returned_request_api_and_method_are_ignored( string $path ): void {
		$this->create_controller( true )->register_routes();
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

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $path ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_transactions', $this->api_client->last_call['method'] );
		$this->assertSame( 'other-request', $this->api_client->last_call['query']['extension_custom_param'] );
	}

	/**
	 * @testdox The Fees page's list, summary and export requests reach the platform unchanged ($scenario).
	 * @testWith ["without a search", false]
	 *           ["with an order search, a type and a payment method", true]
	 *
	 * The queries are the ones the page builds (client/admin/client/woopayments/admin/reports/query.ts:24-52, :157-184).
	 *
	 * @param string $scenario   Case.
	 * @param bool   $with_order Whether the merchant searches for an order and filters by type and payment method.
	 */
	public function test_fees_page_requests_reach_the_platform_unchanged( string $scenario, bool $with_order ): void {
		unset( $scenario );
		$order = $this->create_order_with_charge( 'ch_page' );
		$this->create_controller( true )->register_routes();
		$filters = array(
			'date_after'    => '2026-06-01T00:00:00Z',
			'user_timezone' => '+03:00',
		);
		if ( $with_order ) {
			$filters = array(
				'date_after'          => '2026-06-01T00:00:00Z',
				'payment_method_type' => 'card',
				'type'                => array( 'charge' ),
				'search'              => array( __( 'Order #', 'woocommerce' ) . $order->get_id() ),
				'user_timezone'       => '+03:00',
			);
		}
		$types    = $with_order ? array( 'charge' ) : array( 'charge', 'payment', 'payment_failure_refund', 'payment_refund', 'refund', 'refund_failure', 'dispute', 'dispute_reversal', 'fee_refund', 'network_costs' );
		$expected = $with_order
			? array(
				'source_is'     => 'card',
				'type_is_in'    => $types,
				'date_after'    => '2026-06-01 03:00:00',
				'search'        => array( 'ch_page' ),
				'user_timezone' => '+03:00',
			)
			: array(
				'type_is_in'    => $types,
				'date_after'    => '2026-06-01 03:00:00',
				'user_timezone' => '+03:00',
			);

		$list = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees' );
		$list->set_query_params(
			array(
				'page'      => '1',
				'per_page'  => '25',
				'sort'      => 'date',
				'direction' => 'desc',
			) + $filters
		);
		$this->server->dispatch( $list );
		$this->assertSame(
			array(
				'page'      => 1,
				'pagesize'  => 25,
				'sort'      => 'date',
				'direction' => 'desc',
				'limit'     => 100,
			) + $expected,
			$this->api_client->last_call['query']
		);

		$summary = new WP_REST_Request( 'GET', '/wc/v3/payments/reports/fees/summary' );
		$summary->set_query_params( $filters );
		$this->server->dispatch( $summary );
		$this->assertSame( 'get_transactions_summary', $this->api_client->last_call['method'] );
		$this->assertSame( $expected, $this->api_client->last_call['filters'] );

		$export = new WP_REST_Request( 'POST', '/wc/v3/payments/reports/fees/download' );
		$export->set_query_params(
			$filters + array(
				'user_email' => 'merchant@example.com',
				'locale'     => 'en_US',
			)
		);
		$this->server->dispatch( $export );
		$this->assertSame( 'start_transactions_export', $this->api_client->last_call['method'] );
		$this->assertSame( $expected, $this->api_client->last_call['filters'] );
	}

	/**
	 * Create a native Reports REST controller.
	 *
	 * @param bool $reports_enabled Whether Reports are enabled.
	 * @param bool $has_account Whether an account exists.
	 * @return WooPaymentsReportsRestController
	 */
	private function create_controller( bool $reports_enabled, bool $has_account = true ): WooPaymentsReportsRestController {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_account', 'is_reports_enabled' ) )
			->getMock();
		$account_service->method( 'is_reports_enabled' )->willReturn( $reports_enabled );
		if ( $reports_enabled ) {
			$account_service->method( 'has_account' )->willReturn( $has_account );
		} else {
			$account_service->expects( $this->never() )->method( 'has_account' );
		}

		$controller = new WooPaymentsReportsRestController();
		$controller->init( $this->api_client, $account_service, $this->create_order_service() );

		return $controller;
	}

	/**
	 * Create an order search mapping service.
	 *
	 * @return WooPaymentsOrderDetailsService
	 */
	private function create_order_service(): WooPaymentsOrderDetailsService {
		$service = new WooPaymentsOrderDetailsService();
		$service->init( new WooPaymentsOrderDataService(), wc_get_container()->get( WooPaymentsLevel3Service::class ) );

		return $service;
	}

	/**
	 * Create an order with WooPayments charge metadata.
	 *
	 * @param string $charge_id Charge ID.
	 * @return WC_Order
	 */
	private function create_order_with_charge( string $charge_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_currency( 'USD' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		return $order;
	}

	/**
	 * Assert a route handler supports a method.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 * @param string                         $method         Method constant.
	 */
	private function assertRouteHasMethod( array $route_handlers, string $method ): void {
		$methods = array_map( 'trim', explode( ',', $method ) );

		foreach ( $route_handlers as $handler ) {
			$route_methods = isset( $handler['methods'] ) && is_array( $handler['methods'] ) ? $handler['methods'] : array();
			$missing       = array_filter(
				$methods,
				static function ( string $method_name ) use ( $route_methods ): bool {
					return ! isset( $route_methods[ $method_name ] ) || true !== $route_methods[ $method_name ];
				}
			);

			if ( empty( $missing ) ) {
				return;
			}
		}

		$this->fail( 'Route does not accept method ' . $method . '.' );
	}

	/**
	 * Assert all expected report routes are registered.
	 */
	private function assertExpectedRoutesRegistered(): void {
		$routes = $this->server->get_routes();

		foreach ( $this->get_expected_routes() as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $methods as $method ) {
				$this->assertRouteHasMethod( $routes[ $route ], $method );
			}
		}
	}

	/**
	 * Get expected route methods.
	 *
	 * @return array<string,string[]>
	 */
	private function get_expected_routes(): array {
		return array(
			'/wc/v3/payments/reports/balance'        => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/fees'           => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/fees/summary'   => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/fees/download'  => array( WP_REST_Server::CREATABLE ),
			'/wc/v3/payments/reports/fees/download/(?P<export_id>[^/\\\\%]+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/transactions'   => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/transactions/(?P<id>\\w+)' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/authorizations' => array( WP_REST_Server::READABLE ),
			'/wc/v3/payments/reports/authorizations/(?P<id>\\w+)' => array( WP_REST_Server::READABLE ),
		);
	}
}
