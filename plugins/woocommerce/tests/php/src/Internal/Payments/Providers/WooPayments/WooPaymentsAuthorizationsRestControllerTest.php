<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderContract;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsAuthorizationsRestController class.
 */
class WooPaymentsAuthorizationsRestControllerTest extends WC_REST_Unit_Test_Case {

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
		remove_all_filters( 'wcpay_list_authorizations_request' );
		parent::tearDown();
	}

	/**
	 * @testdox Authorization routes register only when native owns runtime.
	 */
	public function test_authorization_routes_register_only_when_native_owns_runtime(): void {
		$controller = $this->create_authorizations_controller( true );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations/summary', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations/(?P<payment_intent_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/orders/(?P<order_id>\\w+)/capture_authorization', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/orders/(?P<order_id>\\w+)/cancel_authorization', $routes );

		$controller = $this->create_authorizations_controller( false );
		$controller->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $controller, 'register_routes' ) ) );
	}

	/**
	 * @testdox Every registered authorization route requires manage_woocommerce before reaching the API client or payment processing.
	 * @dataProvider authorization_route_provider
	 *
	 * Mirrors client 11.1.0 `WC_Payments_REST_Controller::check_permission()`
	 * (`includes/admin/class-wc-payments-rest-controller.php:63-65`), the shared
	 * `manage_woocommerce` check every WooPayments REST controller wires as its
	 * `permission_callback`; the orders controller registers it on every route, including
	 * capture and cancel authorization
	 * (`includes/admin/class-wc-rest-payments-orders-controller.php:79-149`).
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path.
	 */
	public function test_authorization_routes_require_manage_woocommerce( string $method, string $path ): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture call count.
			 *
			 * @var int
			 */
			public int $capture_calls = 0;

			/**
			 * Cancel call count.
			 *
			 * @var int
			 */
			public int $cancel_calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();

		wp_set_current_user( 0 );
		$anonymous_request = new WP_REST_Request( $method, $path );
		if ( 'POST' === $method ) {
			$anonymous_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		}
		$anonymous_response = $this->server->dispatch( $anonymous_request );

		$this->assertSame( rest_authorization_required_code(), $anonymous_response->get_status(), "{$method} {$path} must require manage_woocommerce for an anonymous request." );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'customer' ) ) );
		$customer_request = new WP_REST_Request( $method, $path );
		if ( 'POST' === $method ) {
			$customer_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		}
		$customer_response = $this->server->dispatch( $customer_request );

		$this->assertSame( rest_authorization_required_code(), $customer_response->get_status(), "{$method} {$path} must require manage_woocommerce for a logged-in customer without the capability." );
		$this->assertSame( array(), $this->api_client->last_call );
		$this->assertSame( 0, $processing_service->capture_calls, 'The permission check must run before payment processing.' );
		$this->assertSame( 0, $processing_service->cancel_calls, 'The permission check must run before payment processing.' );
	}

	/**
	 * Every registered authorization route, as (method, path).
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function authorization_route_provider(): array {
		return array(
			'list authorizations'    => array( 'GET', '/wc/v3/payments/authorizations' ),
			'authorizations summary' => array( 'GET', '/wc/v3/payments/authorizations/summary' ),
			'authorization detail'   => array( 'GET', '/wc/v3/payments/authorizations/pi_test' ),
			'capture authorization'  => array( 'POST', '/wc/v3/payments/orders/1/capture_authorization' ),
			'cancel authorization'   => array( 'POST', '/wc/v3/payments/orders/1/cancel_authorization' ),
		);
	}

	/**
	 * @testdox Authorizations list preserves reference query names and the legacy request filter.
	 */
	public function test_authorizations_list_preserves_filter_contract(): void {
		$this->create_authorizations_controller( true )->register_routes();
		$observed_request = null;

		add_filter(
			'wcpay_list_authorizations_request',
			static function ( \WCPay\Core\Server\Request\List_Authorizations $request ) use ( &$observed_request ): \WCPay\Core\Server\Request\List_Authorizations {
				$observed_request = $request;
				$request->set_page_size( 50 );
				$request->set_param( 'customer_email_is', 'ada@example.com' );
				$request->set( 'extension_custom_param', 'authorizations-custom' );

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations' );
		$request->set_query_params(
			array(
				'page'                => '2',
				'pagesize'            => '25',
				'sort'                => 'capture_by',
				'direction'           => 'asc',
				'order_id'            => '123',
				'customer_email'      => 'grace@example.com',
				'payment_method_type' => 'card',
				'loan_id_is'          => 'drop-me',
				'store_currency_is'   => 'drop-me',
				'ignored'             => 'drop-me',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( WooPaymentsAuthorizationsListRequest::class, $observed_request );
		$this->assertSame( 'authorizations', $observed_request->get_api() );
		$this->assertSame( 'get_authorizations', $this->api_client->last_call['method'] );
		$this->assertSame(
			array(
				'page'                   => 2,
				'pagesize'               => 50,
				'sort'                   => 'created',
				'direction'              => 'asc',
				'limit'                  => 100,
				'order_id_is'            => '123',
				'customer_email_is'      => 'ada@example.com',
				'source_is'              => 'card',
				'extension_custom_param' => 'authorizations-custom',
			),
			$this->api_client->last_call['query']
		);
	}

	/**
	 * @testdox Authorization detail and summary routes proxy the compatible API methods.
	 */
	public function test_authorization_detail_and_summary_routes_proxy_api_methods(): void {
		$this->create_authorizations_controller( true )->register_routes();

		$this->api_client->response = array(
			'payment_intent_id' => 'pi_auth',
			'captured'          => false,
		);

		$detail_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations/pi_auth' ) );

		$this->assertSame( 200, $detail_response->get_status() );
		$this->assertSame( 'get_authorization', $this->api_client->last_call['method'] );
		$this->assertSame( 'pi_auth', $this->api_client->last_call['payment_intent_id'] );

		$this->api_client->response = array(
			'count' => 2,
			'total' => 1000,
		);

		$summary_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations/summary' ) );

		$this->assertSame( 200, $summary_response->get_status() );
		$this->assertSame( 'get_authorizations_summary', $this->api_client->last_call['method'] );
		$this->assertSame( 2, $summary_response->get_data()['count'] );
	}

	/**
	 * @testdox The authorizations summary asks the platform for the store-wide totals, whatever the tab's filters.
	 *
	 * Client 11.1.0 sends the summary request with no query and no list filter (class-wc-rest-payments-authorizations-controller.php:88-92).
	 */
	public function test_authorizations_summary_ignores_the_list_filters(): void {
		$this->create_authorizations_controller( true )->register_routes();
		$list_filter_calls = 0;
		add_filter(
			'wcpay_list_authorizations_request',
			static function ( $request ) use ( &$list_filter_calls ) {
				++$list_filter_calls;

				return $request;
			}
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations/summary' );
		$request->set_query_params(
			array(
				'customer_email_is' => 'buyer@example.com',
				'date_after'        => '2026-01-01',
			)
		);
		$this->server->dispatch( $request );

		$this->assertSame( 'get_authorizations_summary', $this->api_client->last_call['method'] );
		$this->assertSame( array(), $this->api_client->last_call['query'] );
		$this->assertSame( 0, $list_filter_calls );
	}

	/**
	 * @testdox An unexpected error during an authorization capture or cancel is logged and answers the client's generic error.
	 *
	 * Client 11.1.0 logs and answers wcpay_server_error (class-wc-rest-payments-orders-controller.php:434-436, :667-669).
	 *
	 * @dataProvider provide_authorization_actions
	 *
	 * @param string $action  Route action.
	 * @param string $message Expected log line.
	 */
	public function test_authorization_actions_log_an_unexpected_error( string $action, string $message ): void {
		$processing_service = new class() extends PaymentProcessingService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \RuntimeException Always.
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				throw new \RuntimeException( 'capture failed' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \RuntimeException Always.
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				throw new \RuntimeException( 'cancel failed' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$logger             = RecordingWcLogger::install();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_auth' );
		// The intent fields the client checks before acting: metadata.order_id and an authorized status (class-wc-rest-payments-orders-controller.php:389-401).
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/' . $action . '_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( array( array( 'error', $message, 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * @testdox An unexpected PHP error during an authorization capture is logged even with logging off.
	 */
	public function test_authorization_capture_logs_a_php_error_with_logging_off(): void {
		$processing_service = new class() extends PaymentProcessingService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \Error Always.
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				throw new \Error( 'capture failed' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$logger             = RecordingWcLogger::install();
		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_auth' );
		// The intent fields the client checks before acting: metadata.order_id and an authorized status (class-wc-rest-payments-orders-controller.php:389-401).
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$this->assertSame( array( array( 'error', 'Failed to capture an authorization via the REST API.', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * @testdox A capture refused by the order payment lock keeps one audit entry and reaches no provider.
	 *
	 * The real processing service refuses before the provider call and saves nothing, so only the route's save keeps the entry.
	 */
	public function test_lock_refused_capture_keeps_one_audit_entry(): void {
		$order   = $this->create_authorized_order( 'pi_auth' );
		$store   = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::class );
		$profile = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile();
		$holder  = $store->claim_order_payment_lock_for_operation( $order, $profile, 'pi_other', 'payment status update' );
		$this->assertNotNull( $holder );
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $this->create_arbiter( true ), $this->api_client, wc_get_container()->get( PaymentProcessingService::class ), wc_get_container()->get( WooPaymentsProvider::class ) );
		$controller->register_routes();
		// The intent fields the client checks before acting: metadata.order_id and an authorized status (class-wc-rest-payments-orders-controller.php:389-401).
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );
		$store->release_order_payment_lock( $order, $profile, (string) $holder );

		$this->assertSame( 'wcpay_capture_error', $response->get_data()['code'] );
		$this->assertNotSame( 'capture_intention', $this->api_client->last_call['method'] ?? '' );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_meta( '_wcpay_fraud_outcome_manual_entry', false ) );
	}

	/**
	 * Authorization actions and the line their unexpected errors log.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_authorization_actions(): array {
		return array(
			'capture' => array( 'capture', 'Failed to capture an authorization via the REST API.' ),
			'cancel'  => array( 'cancel', 'Failed to cancel an authorization via the REST API.' ),
		);
	}

	/**
	 * @testdox A failed authorization capture or cancel answers the client's code, message and status, and keeps the merchant's audit entry.
	 *
	 * Client 11.1.0 class-wc-rest-payments-orders-controller.php:403-425 (capture) and :645-656 (cancel).
	 *
	 * @dataProvider provide_failed_authorization_outcomes
	 *
	 * @param string              $action   Route action.
	 * @param array<string,mixed> $data     Failed outcome data, as WooPaymentsIntentCodec::failed_transport_outcome() builds it.
	 * @param array<string,mixed> $expected Expected code, message and error data.
	 */
	public function test_failed_authorization_action_answers_the_client_error( string $action, array $data, array $expected ): void {
		$processing_service = new class( $data ) extends PaymentProcessingService {
			/**
			 * Failed outcome data.
			 *
			 * @var array<string,mixed>
			 */
			private array $data;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $data Failed outcome data.
			 */
			public function __construct( array $data ) {
				$this->data = $data;
			}

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_auth', '', '', '', $this->data );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_auth', '', '', '', $this->data );
			}
		};
		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_auth' );
		// The intent fields the client checks before acting: metadata.order_id and an authorized status (class-wc-rest-payments-orders-controller.php:389-401).
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array( 'order_id' => (string) $order->get_id() ),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/' . $action . '_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( $expected['status'], $response->get_status() );
		$this->assertSame( $expected['code'], $response->get_data()['code'] );
		$this->assertSame( $expected['message'], $response->get_data()['message'] );
		$this->assertSame( $expected['data'], $response->get_data()['data'] );
		$entries = array_values( wc_get_order( $order->get_id() )->get_meta( '_wcpay_fraud_outcome_manual_entry', false ) );
		$this->assertCount( 1, $entries, 'The merchant\'s action is recorded once even when the platform refuses it, as on the client.' );
		$this->assertSame( 'capture' === $action ? 'approved' : 'blocked', $entries[0]->value['action'] );
		$this->assertSame( get_current_user_id(), $entries[0]->value['user']['id'] );
	}

	/**
	 * Failed outcomes and the client's answers.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>}>
	 */
	public function provide_failed_authorization_outcomes(): array {
		$capture_prefix = 'Payment capture failed to complete with the following message: ';

		return array(
			'capture declined'         => array(
				'capture',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'card_declined',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Error: <b>Declined</b>.',
					'http_code'                        => 402,
					'extra_details'                    => array(),
				),
				array(
					'status'  => 402,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'Error: &lt;b&gt;Declined&lt;/b&gt;.',
					'data'    => array(
						'status'        => 402,
						'extra_details' => array(),
						'error_type'    => 'card_declined',
					),
				),
			),
			'capture amount too small' => array(
				'capture',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'amount_too_small',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Amount must be at least $0.50 usd',
					'http_code'                        => 400,
					'extra_details'                    => array(
						'minimum_amount'          => 50,
						'minimum_amount_currency' => 'USD',
					),
				),
				array(
					'status'  => 400,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'Amount must be at least $0.50 usd The minimum amount to capture is $0.50 USD.',
					'data'    => array(
						'status'        => 400,
						'extra_details' => array(
							'minimum_amount'          => 50,
							'minimum_amount_currency' => 'USD',
						),
						'error_type'    => 'amount_too_small',
					),
				),
			),
			'capture without message'  => array(
				'capture',
				array(),
				array(
					'status'  => 502,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'Unknown error',
					'data'    => array(
						'status'        => 502,
						'extra_details' => array(),
						'error_type'    => null,
					),
				),
			),
			'cancel refused'           => array(
				'cancel',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'payment_intent_unexpected_state',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'The PaymentIntent is in an unexpected state.',
					'http_code'                        => 400,
				),
				array(
					'status'  => 502,
					'code'    => 'wcpay_cancel_error',
					'message' => 'Payment cancel failed to complete with the following message: The PaymentIntent is in an unexpected state.',
					'data'    => array( 'status' => 502 ),
				),
			),
		);
	}

	/**
	 * @testdox Authorization actions validate order state before delegating to native payment processing.
	 */
	public function test_authorization_actions_validate_order_state_before_processing(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture call count.
			 *
			 * @var int
			 */
			public int $capture_calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();

		$no_intent_response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/orders/999999/capture_authorization' ) );

		$this->assertSame( 400, $no_intent_response->get_status(), 'payment_intent_id is a required route arg, like the reference client.' );
		$this->assertSame( 'rest_missing_callback_param', $no_intent_response->get_data()['code'] );

		$missing_request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/999999/capture_authorization' );
		$missing_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$missing_response = $this->server->dispatch( $missing_request );

		$this->assertSame( 404, $missing_response->get_status() );
		$this->assertSame( 'wcpay_missing_order', $missing_response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->capture_calls );

		$order   = $this->create_authorized_order( 'pi_order' );
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_other' ) );

		$mismatch_response = $this->server->dispatch( $request );

		$this->assertSame( 409, $mismatch_response->get_status() );
		$this->assertSame( 'wcpay_intent_order_mismatch', $mismatch_response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->capture_calls );
	}

	/**
	 * @testdox Authorization actions refuse capture and cancel on partially or fully refunded orders.
	 *
	 * Mirrors client 11.1.0's refund guard, present with the same error code and 400 status
	 * in both `capture_authorization()`
	 * (`includes/admin/class-wc-rest-payments-orders-controller.php:379-386`) and
	 * `cancel_authorization()` (`:618-625`): `0 < $order->get_total_refunded()` short-circuits
	 * before the live intent is ever fetched.
	 */
	public function test_authorization_actions_reject_refunded_orders(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture call count.
			 *
			 * @var int
			 */
			public int $capture_calls = 0;

			/**
			 * Cancel call count.
			 *
			 * @var int
			 */
			public int $cancel_calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_auth' );
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => 1,
			)
		);

		$capture_request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$capture_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$capture_response = $this->server->dispatch( $capture_request );

		$this->assertSame( 400, $capture_response->get_status() );
		$this->assertSame( 'wcpay_refunded_order_uncapturable', $capture_response->get_data()['code'] );

		$cancel_request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/cancel_authorization' );
		$cancel_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$cancel_response = $this->server->dispatch( $cancel_request );

		$this->assertSame( 400, $cancel_response->get_status() );
		$this->assertSame( 'wcpay_refunded_order_uncapturable', $cancel_response->get_data()['code'] );
		$this->assertNotSame(
			$capture_response->get_data()['message'],
			$cancel_response->get_data()['message'],
			'The capture and cancel refusals use different wording, like the reference client.'
		);

		$this->assertSame( array(), $this->api_client->last_call, 'The refund guard must run before the live intent is ever fetched.' );
		$this->assertSame( 0, $processing_service->capture_calls );
		$this->assertSame( 0, $processing_service->cancel_calls );
	}

	/**
	 * @testdox Cancel authorization requires payment_intent_id like the reference client.
	 *
	 * Mirrors client 11.1.0 `cancel_authorization`'s route registration
	 * (`includes/admin/class-wc-rest-payments-orders-controller.php:122-133`), which declares
	 * `payment_intent_id` as `required` on the cancel route the same way the capture route does.
	 */
	public function test_cancel_authorization_requires_payment_intent_id_param(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Cancel call count.
			 *
			 * @var int
			 */
			public int $cancel_calls = 0;

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_auth' );

		$response = $this->server->dispatch( new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/cancel_authorization' ) );

		$this->assertSame( 400, $response->get_status(), 'payment_intent_id is a required route arg on the cancel route too.' );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->cancel_calls );
	}

	/**
	 * @testdox Authorization actions reject live intents that belong to another order.
	 */
	public function test_authorization_actions_reject_live_intent_order_mismatch(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture call count.
			 *
			 * @var int
			 */
			public int $capture_calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order                      = $this->create_authorized_order( 'pi_auth' );
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array(
				'order_id' => (string) ( $order->get_id() + 1 ),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_intent_order_mismatch', $response->get_data()['code'] );
		$this->assertSame( 'get_payment_intention', $this->api_client->last_call['method'] );
		$this->assertSame( 0, $processing_service->capture_calls );
	}

	/**
	 * @testdox The $action route refuses an intent other than the order's own, even when that intent names the order and is authorized.
	 * @dataProvider provide_authorization_action_names
	 *
	 * Client 11.1.0 checks only the live intent's metadata.order_id and status
	 * (includes/admin/class-wc-rest-payments-orders-controller.php:389-406 for capture, :628-645 for cancel), then
	 * captures or cancels the order's stored intent (class-wc-payment-gateway-wcpay.php:3975, :4076), so a request naming
	 * pi_X moves pi_Y. Native refuses the request before any platform call.
	 *
	 * @param string $action Capture or cancel.
	 */
	public function test_authorization_action_refuses_an_intent_other_than_the_orders_own( string $action ): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture and cancel call count.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_Y' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_Y' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_Y' );
		// The live pi_X passes both client checks: metadata.order_id is the order (orders controller :393-396 for capture,
		// :632-635 for cancel) and the status is requires_capture (is_authorized() at :400, the status list at :639).
		$this->api_client->response = array(
			'id'       => 'pi_X',
			'status'   => 'requires_capture',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/' . $action . '_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_X' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_intent_order_mismatch', $response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->calls, "No {$action} may run for another intent." );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( 'pi_Y', $order->get_meta( '_intent_id', true ) );
		$this->assertSame( 'pi_Y', $order->get_transaction_id() );
		$this->assertSame( '', $order->get_meta( '_wcpay_fraud_outcome_manual_entry', true ), 'The refused request must not write the merchant audit entry.' );
	}

	/**
	 * Authorization route actions.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_authorization_action_names(): array {
		return array(
			'capture' => array( 'capture' ),
			'cancel'  => array( 'cancel' ),
		);
	}

	/**
	 * @testdox Authorization actions reject stale live intent statuses.
	 */
	public function test_authorization_actions_reject_stale_live_intent_status(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Cancel call count.
			 *
			 * @var int
			 */
			public int $cancel_calls = 0;

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order                      = $this->create_authorized_order( 'pi_auth' );
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'succeeded',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/cancel_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpay_payment_uncapturable', $response->get_data()['code'] );
		$this->assertSame( 'get_payment_intention', $this->api_client->last_call['method'] );
		$this->assertSame( 0, $processing_service->cancel_calls );
	}

	/**
	 * @testdox Capture and cancel authorization actions delegate through native payment processing.
	 *
	 * Mirrors client 11.1.0's `add_fraud_outcome_manual_entry()`
	 * (`includes/admin/class-wc-rest-payments-orders-controller.php:679-691`), called with
	 * `'approve'` on capture (`:404`) and `'block'` on cancel (`:643`); both write the same
	 * `type`/`user`/`action` shape to the `_wcpay_fraud_outcome_manual_entry` order meta.
	 */
	public function test_authorization_actions_delegate_through_native_processing(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Last capture context.
			 *
			 * @var PaymentContext|null
			 */
			public ?PaymentContext $last_capture_context = null;

			/**
			 * Last cancel context.
			 *
			 * @var PaymentContext|null
			 */
			public ?PaymentContext $last_cancel_context = null;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				$this->last_capture_context = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				$this->last_cancel_context = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order                      = $this->create_authorized_order( 'pi_auth' );
		$this->api_client->response = array(
			'id'       => 'pi_auth',
			'status'   => 'requires_capture',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$capture_request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$capture_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$capture_response = $this->server->dispatch( $capture_request );

		$this->assertSame( 200, $capture_response->get_status() );
		$this->assertSame( 'succeeded', $capture_response->get_data()['status'] );
		$this->assertSame( 'pi_auth', $capture_response->get_data()['id'] );
		$this->assertInstanceOf( PaymentContext::class, $processing_service->last_capture_context );
		$this->assertNull( $processing_service->last_capture_context->get_amount() );

		$order_after_capture      = wc_get_order( $order->get_id() );
		$capture_approved_entries = array_values(
			array_filter(
				$order_after_capture->get_meta( '_wcpay_fraud_outcome_manual_entry', false ),
				static function ( $meta ): bool {
					return isset( $meta->value['action'] ) && 'approved' === $meta->value['action'];
				}
			)
		);
		$this->assertNotEmpty( $capture_approved_entries, 'Capture must record an approved fraud outcome audit entry.' );
		$capture_entry = $capture_approved_entries[0]->value;
		$this->assertSame( 'fraud_outcome_manual_approve', $capture_entry['type'] );
		$this->assertSame( get_current_user_id(), $capture_entry['user']['id'] );

		$cancel_request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/cancel_authorization' );
		$cancel_request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$cancel_response = $this->server->dispatch( $cancel_request );

		$this->assertSame( 200, $cancel_response->get_status() );
		$this->assertSame( 'canceled', $cancel_response->get_data()['status'] );
		$this->assertSame( 'pi_auth', $cancel_response->get_data()['id'] );
		$this->assertInstanceOf( PaymentContext::class, $processing_service->last_cancel_context );

		$order_after_cancel     = wc_get_order( $order->get_id() );
		$cancel_blocked_entries = array_values(
			array_filter(
				$order_after_cancel->get_meta( '_wcpay_fraud_outcome_manual_entry', false ),
				static function ( $meta ): bool {
					return isset( $meta->value['action'] ) && 'blocked' === $meta->value['action'];
				}
			)
		);
		$this->assertNotEmpty( $cancel_blocked_entries, 'Cancel must record its own blocked fraud outcome audit entry, alongside the approved capture entry.' );
		$cancel_entry = $cancel_blocked_entries[0]->value;
		$this->assertSame( 'fraud_outcome_manual_block', $cancel_entry['type'] );
		$this->assertSame( get_current_user_id(), $cancel_entry['user']['id'] );
	}

	/**
	 * @testdox Explicit capture amounts use provider currency semantics and reach native payment processing.
	 * @dataProvider valid_capture_amount_provider
	 *
	 * @param string $currency                Order currency.
	 * @param float  $requested_amount         Requested decimal capture amount.
	 * @param int    $authorized_amount_minor Authorized amount in provider minor units.
	 */
	public function test_capture_authorization_threads_explicit_amount( string $currency, float $requested_amount, int $authorized_amount_minor ): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Last capture context.
			 *
			 * @var PaymentContext|null
			 */
			public ?PaymentContext $last_capture_context = null;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				$this->last_capture_context = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_partial' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_partial' );
		$order->set_currency( $currency );
		$order->save();

		$this->api_client->response = array(
			'id'       => 'pi_partial',
			'status'   => 'requires_capture',
			'amount'   => $authorized_amount_minor,
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params(
			array(
				'payment_intent_id' => 'pi_partial',
				'amount'            => $requested_amount,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( PaymentContext::class, $processing_service->last_capture_context );
		$this->assertSame( $requested_amount, $processing_service->last_capture_context->get_amount() );
	}

	/**
	 * Valid explicit capture amounts.
	 *
	 * @return array<string,array{string,float,int}>
	 */
	public static function valid_capture_amount_provider(): array {
		return array(
			'USD decimal amount'         => array( 'USD', 4.25, 1000 ),
			'JPY zero-decimal amount'    => array( 'JPY', 425.0, 1000 ),
			'full authorized amount'     => array( 'USD', 10.00, 1000 ),
			'JPY full authorized amount' => array( 'JPY', 1000.0, 1000 ),
		);
	}

	/**
	 * @testdox Invalid explicit capture amounts are rejected before payment processing.
	 * @dataProvider invalid_capture_amount_provider
	 *
	 * @param string    $currency                Order currency.
	 * @param float     $requested_amount         Requested decimal capture amount.
	 * @param int|float $authorized_amount_minor Authorized amount returned by the provider.
	 */
	public function test_capture_authorization_rejects_invalid_explicit_amount( string $currency, float $requested_amount, $authorized_amount_minor ): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture call count.
			 *
			 * @var int
			 */
			public int $capture_calls = 0;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentContext   $context  Payment context.
			 * @param ProviderContract $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_invalid_amount' );
			}
		};

		$this->create_authorizations_controller( true, $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_invalid_amount' );
		$order->set_currency( $currency );
		$order->save();

		$this->api_client->response = array(
			'id'       => 'pi_invalid_amount',
			'status'   => 'requires_capture',
			'amount'   => $authorized_amount_minor,
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params(
			array(
				'payment_intent_id' => 'pi_invalid_amount',
				'amount'            => $requested_amount,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_capture_amount', $response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->capture_calls );
	}

	/**
	 * Invalid explicit capture amounts.
	 *
	 * @return array<string,array{string,float,int|float}>
	 */
	public static function invalid_capture_amount_provider(): array {
		return array(
			'zero amount'                  => array( 'USD', 0.0, 1000 ),
			'negative amount'              => array( 'USD', -1.0, 1000 ),
			'above authorized amount'      => array( 'USD', 10.01, 1000 ),
			'non-integral provider amount' => array( 'USD', 4.25, 1000.5 ),
			'JPY above authorized amount'  => array( 'JPY', 1001.0, 1000 ),
		);
	}

	/**
	 * Create an authorizations controller.
	 *
	 * @param bool                          $native_register    Whether native should own routes.
	 * @param PaymentProcessingService|null $processing_service Optional payment processing service.
	 * @return WooPaymentsAuthorizationsRestController
	 */
	private function create_authorizations_controller( bool $native_register, ?PaymentProcessingService $processing_service = null ): WooPaymentsAuthorizationsRestController {
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $this->create_arbiter( $native_register ), $this->api_client, $processing_service ?? new PaymentProcessingService(), new WooPaymentsProvider() );

		return $controller;
	}

	/**
	 * Create an authorized WooPayments order.
	 *
	 * @param string $intent_id Intent ID.
	 * @return WC_Order
	 */
	private function create_authorized_order( string $intent_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_total( 10 );
		$order->set_currency( 'USD' );
		$order->set_transaction_id( $intent_id );
		$order->update_meta_data( '_intent_id', $intent_id );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->save();
		$order->update_status( 'on-hold' );

		return $order;
	}
}
