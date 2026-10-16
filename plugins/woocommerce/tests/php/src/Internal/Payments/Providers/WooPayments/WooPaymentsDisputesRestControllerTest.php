<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsDisputesListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDetailsService;
use RuntimeException;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsDisputesRestController class.
 */
class WooPaymentsDisputesRestControllerTest extends WC_REST_Unit_Test_Case {

	use WooPaymentsMoneyMovementControllerTestTrait;

	/**
	 * Recording API client.
	 *
	 * @var RecordingMoneyMovementApiClient
	 */
	private RecordingMoneyMovementApiClient $api_client;

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
		remove_all_filters( 'wcpay_list_disputes_request' );
		remove_all_filters( 'woocommerce_logging_class' );
		delete_option( 'wcpay_dispute_status_counts_cache' );
		delete_option( 'wcpay_test_dispute_status_counts_cache' );
		delete_option( 'wcpay_active_dispute_cache' );
		parent::tearDown();
	}

	/**
	 * @testdox Every registered dispute route requires manage_woocommerce before reaching the API client.
	 * @dataProvider dispute_route_provider
	 *
	 * Mirrors client 11.1.0 `WC_Payments_REST_Controller::check_permission()`
	 * (`includes/admin/class-wc-payments-rest-controller.php:63-65`), which the disputes
	 * controller wires as `permission_callback` on every route
	 * (`includes/admin/class-wc-rest-payments-disputes-controller.php:34, :43`).
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path.
	 */
	public function test_dispute_routes_require_manage_woocommerce( string $method, string $path ): void {
		$this->create_disputes_controller()->register_routes();

		wp_set_current_user( 0 );
		$anonymous_response = $this->server->dispatch( new WP_REST_Request( $method, $path ) );

		$this->assertSame( rest_authorization_required_code(), $anonymous_response->get_status(), "{$method} {$path} must require manage_woocommerce for an anonymous request." );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$customer_response = $this->server->dispatch( new WP_REST_Request( $method, $path ) );

		$this->assertSame( rest_authorization_required_code(), $customer_response->get_status(), "{$method} {$path} must require manage_woocommerce for a logged-in customer without the capability." );
		$this->assertSame( array(), $this->api_client->last_call, 'The permission check must run before the API client is called.' );
	}

	/**
	 * Every registered dispute route, as (method, path).
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function dispute_route_provider(): array {
		return array(
			'list disputes'      => array( 'GET', '/wc/v3/payments/disputes' ),
			'dispute export url' => array( 'GET', '/wc/v3/payments/disputes/download/export_test' ),
			'disputes summary'   => array( 'GET', '/wc/v3/payments/disputes/summary' ),
			'disputes export'    => array( 'POST', '/wc/v3/payments/disputes/download' ),
			'dispute detail'     => array( 'GET', '/wc/v3/payments/disputes/dp_test' ),
			'dispute update'     => array( 'POST', '/wc/v3/payments/disputes/dp_test' ),
			'dispute close'      => array( 'POST', '/wc/v3/payments/disputes/dp_test/close' ),
		);
	}

	/**
	 * @testdox Disputes list maps reference REST filters to platform filter names.
	 */
	public function test_disputes_list_maps_reference_filters(): void {
		$this->create_disputes_controller()->register_routes();
		add_filter(
			'wcpay_list_disputes_request',
			static function ( WooPaymentsDisputesListRequest $request ): WooPaymentsDisputesListRequest {
				$request->set( 'extension_custom_param', 'disputes-custom' );

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes' );
		$request->set_query_params(
			array(
				'page'              => '1',
				'pagesize'          => '25',
				'store_currency_is' => 'usd',
				'date_before'       => '2026-06-18',
				'status_is'         => 'needs_response',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_disputes', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'page'                   => 1,
				'pagesize'               => 25,
				'sort'                   => 'created',
				'direction'              => 'desc',
				'limit'                  => 100,
				'currency_is'            => 'usd',
				'created_before'         => '2026-06-18',
				'status_is'              => 'needs_response',
				'extension_custom_param' => 'disputes-custom',
			),
			$this->api_client->last_call['filters']
		);
	}

	/**
	 * @testdox Disputes list adds the WooPayments extension's compact order context and keeps missing orders explicit.
	 */
	public function test_disputes_list_enriches_order_context(): void {
		$order = $this->create_order_with_charge( 'ch_dispute', 'pi_dispute' );

		$this->api_client->response = array(
			'data' => array(
				array(
					'dispute_id' => 'dp_order',
					'charge_id'  => 'ch_dispute',
				),
				array(
					'dispute_id' => 'dp_missing',
					'charge_id'  => 'ch_missing',
				),
			),
		);
		$this->create_disputes_controller()->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/disputes' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order->get_order_number(), $data['data'][0]['order']['number'] );
		$this->assertNull( $data['data'][1]['order'] );
	}

	/**
	 * @testdox A disputes list order lookup that throws is logged with the exception class, code and trace, never its message.
	 */
	public function test_disputes_list_logs_an_order_lookup_failure_without_its_message(): void {
		$this->create_order_with_charge( 'ch_dispute', 'pi_dispute' );
		// Shape: client 11.1.0 `includes/core/server/request/class-list-disputes.php:174-187` reads `data`, `dispute_id` and `charge_id`.
		$this->api_client->response = array(
			'data' => array(
				array(
					'dispute_id' => 'dp_order',
					'charge_id'  => 'ch_dispute',
				),
			),
		);

		$logger = RecordingWcLogger::install();
		add_filter(
			'woocommerce_order_number',
			static function (): string {
				throw new RuntimeException( 'Order lookup failed for ada@example.com', 4242 );
			}
		);
		$this->create_disputes_controller()->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/disputes' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['data'][0]['order'] );
		$this->assertCount( 1, $logger->lines );
		$this->assertSame( 'error', $logger->lines[0][0] );
		$this->assertSame( 'woopayments', $logger->lines[0][2] );
		$this->assertSame( RuntimeException::class, $logger->contexts[0]['exception'] );
		$this->assertSame( 'dp_order', $logger->contexts[0]['entity_id'] );
		$this->assertSame( 'dispute_list', $logger->contexts[0]['context'] );
		$this->assertSame( 4242, $logger->contexts[0]['code'] );
		$this->assertMatchesRegularExpression( '/\A#0 [^\n]*\(\)(\n#\d+ [^\n]*\(\))*\z/', $logger->contexts[0]['trace'], 'The trace lists frames without call arguments.' );
		$this->assertStringNotContainsString( 'ada@example.com', (string) wp_json_encode( array( $logger->lines, $logger->contexts ) ) );
	}

	/**
	 * @testdox Disputes summary and export map reference REST filters to platform filter names.
	 */
	public function test_disputes_summary_and_export_map_reference_filters(): void {
		$this->create_disputes_controller()->register_routes();

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/summary' );
		$request->set_query_params(
			array(
				'store_currency_is' => 'gbp',
				'date_between'      => array( '2026-06-01', '2026-06-18' ),
				'status_is_not'     => 'won',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'get_disputes_summary', $this->api_client->last_call['method'] );
		$this->assertSame( 'gbp', $this->api_client->last_call['filters']['currency_is'] );
		$this->assertSame( array( '2026-06-01', '2026-06-18' ), $this->api_client->last_call['filters']['created_between'] );
		$this->assertSame( 'won', $this->api_client->last_call['filters']['status_is_not'] );
		$this->assertArrayNotHasKey( 'ignored', $this->api_client->last_call['filters'] );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/download' );
		$request->set_body_params(
			array(
				'store_currency_is' => 'usd',
				'date_before'       => '2026-06-18',
				'user_email'        => 'merchant@example.com',
				'locale'            => 'en_US',
				'ignored'           => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'start_disputes_export', $this->api_client->last_call['method'] );
		$this->assertSame( 'usd', $this->api_client->last_call['filters']['currency_is'] );
		$this->assertSame( '2026-06-18', $this->api_client->last_call['filters']['created_before'] );
		$this->assertArrayNotHasKey( 'ignored', $this->api_client->last_call['filters'] );
		$this->assertSame( 'merchant@example.com', $this->api_client->last_call['user_email'] );
		$this->assertSame( 'en_US', $this->api_client->last_call['locale'] );
	}

	/**
	 * @testdox Disputes summary and export send only the filters, without paging, and run no list filter.
	 *
	 * Client 11.1.0 builds both from get_disputes_filters() alone (class-wc-rest-payments-disputes-controller.php:124-127, :174-205).
	 */
	public function test_disputes_summary_and_export_send_only_filters(): void {
		$this->create_disputes_controller()->register_routes();
		$list_filter_calls = 0;
		add_filter(
			'wcpay_list_disputes_request',
			static function ( $request ) use ( &$list_filter_calls ) {
				++$list_filter_calls;

				return $request;
			}
		);
		$query = array(
			'store_currency_is' => 'gbp',
			'page'              => 3,
			'pagesize'          => 50,
			'sort'              => 'amount',
		);

		$summary = new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/summary' );
		$summary->set_query_params( $query );
		$this->server->dispatch( $summary );
		$summary_filters = $this->api_client->last_call['filters'];

		$export = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/download' );
		$export->set_body_params( $query + array( 'user_email' => 'merchant@example.com' ) );
		$this->server->dispatch( $export );

		$this->assertSame( array( 'currency_is' => 'gbp' ), $summary_filters );
		$this->assertSame( array( 'currency_is' => 'gbp' ), $this->api_client->last_call['filters'] );
		$this->assertSame( 0, $list_filter_calls );
	}

	/**
	 * @testdox Dispute update and close forward payloads unchanged.
	 */
	public function test_dispute_update_and_close_forward_payloads(): void {
		$this->create_disputes_controller()->register_routes();
		$order = $this->create_order_with_charge( 'ch_dispute', 'pi_dispute' );

		$this->api_client->response = array(
			'id'     => 'dp_test',
			'charge' => array(
				'id'              => 'ch_dispute',
				'billing_details' => array(
					'address' => array(
						'line1'       => '1 Main Street',
						'city'        => 'San Francisco',
						'state'       => 'CA',
						'postal_code' => '94105',
						'country'     => 'US',
					),
				),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$request->set_body_params(
			array(
				'evidence' => array( 'customer_name' => 'Ada' ),
				'submit'   => 'true',
				'metadata' => array( 'order_id' => 123 ),
			)
		);

		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'update_dispute', $this->api_client->last_call['method'] );
		$this->assertSame( 'dp_test', $this->api_client->last_call['dispute_id'] );
		$this->assertSame( array( 'customer_name' => 'Ada' ), $this->api_client->last_call['evidence'] );
		$this->assertTrue( $this->api_client->last_call['submit'] );
		$this->assertSame( array( 'order_id' => 123 ), $this->api_client->last_call['metadata'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertStringContainsString( '1 Main Street', $data['charge']['billing_details']['formatted_address'] );

		// A JSON request body carries `submit` as a real JSON boolean rather than the
		// form-encoded string the request above used; `wc_string_to_bool()` must accept
		// it unchanged, not just the string form (REC-5b R-d: the wire `submit` is a
		// JSON boolean). `submit` is sent as `false` here, the opposite of the prior
		// request's `true`: `last_call['submit']` would still read `true` from the
		// previous dispatch if this request failed before reaching the API client, so
		// a stale value cannot masquerade as a pass.
		$calls_before_json_submit = count( $this->api_client->calls );
		$json_request             = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$json_request->set_header( 'Content-Type', 'application/json' );
		$json_request->set_body(
			wp_json_encode(
				array(
					'evidence' => array( 'customer_name' => 'Ada' ),
					'submit'   => false,
					'metadata' => array( 'order_id' => 123 ),
				)
			)
		);
		$json_response = $this->server->dispatch( $json_request );

		$this->assertSame( 200, $json_response->get_status() );
		$this->assertCount( $calls_before_json_submit + 1, $this->api_client->calls, 'The JSON-body update must call the API client exactly once.' );
		$this->assertSame( false, $this->api_client->last_call['submit'], 'A JSON boolean submit value must reach the API client as a real boolean.' );

		// Then JSON `true`, following the `false` above, so only a real boolean parse of `true` can pass.
		$calls_before_json_true = count( $this->api_client->calls );
		$json_true_request      = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$json_true_request->set_header( 'Content-Type', 'application/json' );
		$json_true_request->set_body(
			wp_json_encode(
				array(
					'evidence' => array( 'customer_name' => 'Ada' ),
					'submit'   => true,
					'metadata' => array( 'order_id' => 123 ),
				)
			)
		);
		$json_true_response = $this->server->dispatch( $json_true_request );

		$this->assertSame( 200, $json_true_response->get_status() );
		$this->assertCount( $calls_before_json_true + 1, $this->api_client->calls, 'The JSON true update must call the API client exactly once.' );
		$this->assertTrue( $this->api_client->last_call['submit'], 'A JSON boolean true must submit the evidence, not save a draft.' );

		// Closing a dispute must call the API client exactly once: only close_dispute,
		// never a preceding get_dispute or update_dispute.
		$calls_before_close = count( $this->api_client->calls );
		$response           = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test/close' ) );
		$data               = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'close_dispute', $this->api_client->last_call['method'] );
		$this->assertSame( 'dp_test', $this->api_client->last_call['dispute_id'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertCount( $calls_before_close + 1, $this->api_client->calls, 'Closing a dispute must call the API client exactly once.' );
		$this->assertSame( 'close_dispute', $this->api_client->calls[ $calls_before_close ]['method'] );
	}

	/**
	 * @testdox Closing a dispute or submitting its evidence deletes the stale dispute caches after the platform accepts it.
	 *
	 * Client 11.1.0 clears them after the update request returns, as it does for close (class-wc-payments-api-client.php:722-724).
	 *
	 * @dataProvider provide_dispute_cache_clearing_actions
	 *
	 * @param string              $path              Route path.
	 * @param array<string,mixed> $body              Body arguments.
	 * @param array<string,mixed> $platform_response Platform dispute.
	 * @param array<string,mixed> $caches            Cached options to seed, by option name.
	 */
	public function test_dispute_action_deletes_stale_dispute_caches_after_platform_success( string $path, array $body, array $platform_response, array $caches ): void {
		$this->create_disputes_controller()->register_routes();
		foreach ( $caches as $option => $value ) {
			update_option( $option, $value );
		}
		$this->api_client->response = $platform_response;

		$request = new WP_REST_Request( 'POST', $path );
		if ( array() !== $body ) {
			$request->set_body_params( $body );
		}
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		foreach ( array_keys( $caches ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
	}

	/**
	 * Dispute actions the platform accepts, with the caches each must clear.
	 *
	 * @return array<string,array{string,array<string,mixed>,array<string,mixed>,array<string,mixed>}>
	 */
	public static function provide_dispute_cache_clearing_actions(): array {
		return array(
			'close'           => array(
				'/wc/v3/payments/disputes/dp_test/close',
				array(),
				array(
					'id'     => 'dp_test',
					'status' => 'lost',
				),
				array(
					'wcpay_dispute_status_counts_cache' => array( 'data' => array( 'needs_response' => 1 ) ),
					'wcpay_test_dispute_status_counts_cache' => array( 'data' => array( 'warning_needs_response' => 1 ) ),
					'wcpay_active_dispute_cache'        => array( 'id' => 'dp_test' ),
				),
			),
			'evidence submit' => array(
				'/wc/v3/payments/disputes/dp_test',
				array( 'submit' => true ),
				array(
					'id'     => 'dp_test',
					'status' => 'under_review',
				),
				array(
					'wcpay_dispute_status_counts_cache' => array( 'data' => array( 'needs_response' => 1 ) ),
					'wcpay_active_dispute_cache'        => array( 'id' => 'dp_test' ),
				),
			),
		);
	}

	/**
	 * @testdox Dispute close keeps dispute caches when the platform close fails.
	 */
	public function test_dispute_close_keeps_stale_dispute_caches_when_platform_fails(): void {
		$this->create_disputes_controller()->register_routes();
		update_option( 'wcpay_dispute_status_counts_cache', array( 'data' => array( 'needs_response' => 1 ) ) );
		$this->api_client->exception = new WooPaymentsApiException( 'Close failed.', 'close_failed', 400 );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test/close' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			array( 'data' => array( 'needs_response' => 1 ) ),
			get_option( 'wcpay_dispute_status_counts_cache' )
		);
	}

	/**
	 * @testdox Dispute update forwards draft evidence clearing fields unchanged.
	 */
	public function test_dispute_update_forwards_draft_evidence_clearing_fields(): void {
		$this->create_disputes_controller()->register_routes();

		$this->api_client->response = array(
			'id' => 'dp_test',
		);

		$evidence = array(
			'receipt'                  => '',
			'customer_communication'   => '',
			'shipping_carrier'         => '',
			'shipping_tracking_number' => '',
			'product_description'      => 'Physical goods shipped to the customer.',
		);
		$metadata = array(
			'__product_type' => 'physical_product',
		);
		$request  = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$request->set_body_params(
			array(
				'evidence' => $evidence,
				'submit'   => 'false',
				'metadata' => $metadata,
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'update_dispute', $this->api_client->last_call['method'] );
		$this->assertSame( $evidence, $this->api_client->last_call['evidence'] );
		$this->assertFalse( $this->api_client->last_call['submit'] );
		$this->assertSame( $metadata, $this->api_client->last_call['metadata'] );
	}

	/**
	 * @testdox Dispute detail adds the WooPayments extension's detail order context and formatted charge addresses.
	 */
	public function test_dispute_detail_enriches_order_and_charge_address(): void {
		$order = $this->create_order_with_charge( 'ch_detail', 'pi_detail' );

		$this->api_client->response = array(
			'id'     => 'dp_detail',
			'charge' => array(
				'id'              => 'ch_detail',
				'billing_details' => array(
					'address' => array(
						'line1'       => '2 Market Street',
						'city'        => 'San Francisco',
						'state'       => 'CA',
						'postal_code' => '94105',
						'country'     => 'US',
					),
				),
			),
		);
		$this->create_disputes_controller()->register_routes();

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/dp_detail' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertSame( 'physical_product', $data['order']['suggested_product_type'] );
		$this->assertStringContainsString( '2 Market Street', $data['charge']['billing_details']['formatted_address'] );
	}

	/**
	 * @testdox Dispute update logs a safe audit trail on success.
	 */
	public function test_dispute_update_logs_success_audit_trail(): void {
		$this->create_disputes_controller()->register_routes();
		$logger = RecordingWcLogger::install();

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$request->set_body_params(
			array(
				'evidence' => array( 'customer_name' => 'Ada' ),
				'submit'   => 'true',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $logger->lines );
		$this->assertSame( 'info', $logger->lines[0][0] );
		$this->assertSame( 'woopayments', $logger->lines[0][2] );
		$this->assertSame( 'update', $logger->contexts[0]['action'] );
		$this->assertSame( 'dp_test', $logger->contexts[0]['dispute_id'] );
		$this->assertTrue( $logger->contexts[0]['submit'] );
		$this->assertSame( get_current_user_id(), $logger->contexts[0]['user_id'] );
		$this->assertSame( 'info', $logger->lines[1][0] );
	}

	/**
	 * @testdox Dispute update does not hide a completed platform mutation when local enrichment fails.
	 */
	public function test_dispute_update_returns_platform_response_when_completed_action_enrichment_fails(): void {
		$order_service = new class() extends WooPaymentsOrderDetailsService {
			/**
			 * Add order context and formatted charge address to a dispute response.
			 *
			 * @param array<string,mixed> $dispute Platform dispute.
			 * @return array<string,mixed>
			 */
			public function enrich_dispute_response( array $dispute ): array {
				if ( is_array( $dispute ) ) {
					throw new RuntimeException( 'enrichment failed' );
				}

				return $dispute;
			}
		};
		$this->create_disputes_controller( $order_service )->register_routes();
		$logger                     = RecordingWcLogger::install();
		$this->api_client->response = array( 'id' => 'dp_test' );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$request->set_body_params( array( 'submit' => 'false' ) );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'dp_test', $data['id'] );
		$this->assertNull( $data['order'] );
		$this->assertCount( 3, $logger->lines );
		$this->assertSame( 'info', $logger->lines[1][0] );
		$this->assertSame( 'warning', $logger->lines[2][0] );
		$this->assertSame( RuntimeException::class, $logger->contexts[2]['exception'] );
	}

	/**
	 * @testdox Dispute close logs safe upstream failure context.
	 */
	public function test_dispute_close_logs_failure_audit_trail(): void {
		$this->create_disputes_controller()->register_routes();
		$logger = RecordingWcLogger::install();
		// A platform error code is free text to this store; one the logger does not list is logged as unknown_error.
		$this->api_client->exception = new WooPaymentsApiException( 'Ambiguous dispute failure.', 'https://pay.example.test/r?key=sk_test_leak123', 504 );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test/close' ) );

		$this->assertSame( 504, $response->get_status() );
		$this->assertCount( 2, $logger->lines );
		$this->assertSame( 'error', $logger->lines[1][0] );
		$this->assertSame( 'woopayments', $logger->lines[1][2] );
		$this->assertSame( 'close', $logger->contexts[1]['action'] );
		$this->assertSame( 'dp_test', $logger->contexts[1]['dispute_id'] );
		$this->assertSame( 'unknown_error', $logger->contexts[1]['api_code'] );
		$this->assertSame( 504, $logger->contexts[1]['http_status'] );
		$this->assertStringNotContainsString( 'sk_test_leak123', (string) wp_json_encode( array( $logger->lines, $logger->contexts ) ) );
	}

	/**
	 * @testdox Dispute update logs a listed platform code, and any other code as unknown_error.
	 * @testWith ["resource_missing", "resource_missing"]
	 *           ["https://pay.example.test/r?key=sk_test_leak123", "unknown_error"]
	 *
	 * @param string $code     Platform error code.
	 * @param string $expected Logged api_code.
	 */
	public function test_dispute_update_failure_logs_only_a_listed_code( string $code, string $expected ): void {
		$this->create_disputes_controller()->register_routes();
		$logger                      = RecordingWcLogger::install();
		$this->api_client->exception = new WooPaymentsApiException( 'Ambiguous dispute failure.', $code, 504 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/disputes/dp_test' );
		$request->set_body_params( array( 'submit' => 'false' ) );
		$this->server->dispatch( $request );

		$this->assertSame( 'error', $logger->lines[1][0] );
		$this->assertSame( 'update', $logger->contexts[1]['action'] );
		$this->assertSame( $expected, $logger->contexts[1]['api_code'] );
		$this->assertStringNotContainsString( 'sk_test_leak123', (string) wp_json_encode( array( $logger->lines, $logger->contexts ) ) );
	}

	/**
	 * A 4xx or 5xx platform status reaches the answer. The faked failure is the shape the API client builds from a platform
	 * error body and its HTTP status.
	 *
	 * @testdox A "resource_missing" platform failure with status 404 answers 404.
	 */
	public function test_api_exceptions_answer_a_4xx_or_5xx_status(): void {
		$this->create_disputes_controller()->register_routes();
		$this->api_client->exception = new WooPaymentsApiException( 'Platform failure.', 'resource_missing', 404 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/disputes/dp_missing' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'resource_missing', $response->get_data()['code'] );
	}

	/**
	 * A list request filter callback that returns something other than the request stops the request with the API client
	 * path's error, as the extension's request classes end it with a 500 (includes/core/server/class-request.php:528-569, :770-783).
	 *
	 * @testdox A wcpay_list_disputes_request callback returning no request answers 500 wcpay_invalid_filtered_request.
	 */
	public function test_list_filter_returning_no_request_fails_the_request(): void {
		$this->create_disputes_controller()->register_routes();
		add_filter( 'wcpay_list_disputes_request', '__return_null' );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/disputes' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_filtered_request', $response->get_data()['code'] );
		$this->assertSame( 'Invalid WooPayments request returned by wcpay_list_disputes_request.', $response->get_data()['message'] );
	}

	/**
	 * Create a disputes controller.
	 *
	 * @param WooPaymentsOrderDetailsService|null $order_service Optional order service.
	 * @return WooPaymentsDisputesRestController
	 */
	private function create_disputes_controller( ?WooPaymentsOrderDetailsService $order_service = null ): WooPaymentsDisputesRestController {
		$controller = new WooPaymentsDisputesRestController();
		$controller->init( $this->api_client, $order_service ?? $this->create_order_service(), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );

		return $controller;
	}
}
