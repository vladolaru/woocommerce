<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsAuthorizationsListRequest;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;

/**
 * Tests for the WooPaymentsAuthorizationsRestController class.
 */
class WooPaymentsAuthorizationsRestControllerTest extends WC_REST_Unit_Test_Case {

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
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Authorization routes register.
	 */
	public function test_authorization_routes_register(): void {
		$controller = $this->create_authorizations_controller();
		// WooCommerce's REST server registers the routes of the instance the container holds.
		wc_get_container()->replace( WooPaymentsAuthorizationsRestController::class, $controller );
		$controller->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations/summary', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/authorizations/(?P<payment_intent_id>\\w+)', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/orders/(?P<order_id>\\w+)/capture_authorization', $routes );
		$this->assertArrayHasKey( '/wc/v3/payments/orders/(?P<order_id>\\w+)/cancel_authorization', $routes );
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( $processing_service )->register_routes();

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
	 * @testdox Authorizations list preserves reference query names and the WooPayments extension request filter.
	 */
	public function test_authorizations_list_preserves_filter_contract(): void {
		$this->create_authorizations_controller()->register_routes();
		$observed_request = null;

		add_filter(
			'wcpay_list_authorizations_request',
			static function ( WooPaymentsAuthorizationsListRequest $request ) use ( &$observed_request ): WooPaymentsAuthorizationsListRequest {
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
		$this->create_authorizations_controller()->register_routes();

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
		$this->create_authorizations_controller()->register_routes();
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
	 * @testdox An unexpected error during an authorization capture or cancel is logged and answers the WooPayments extension's generic error.
	 *
	 * Client 11.1.0 logs and answers wcpay_server_error (class-wc-rest-payments-orders-controller.php:434-436, :667-669).
	 *
	 * @dataProvider provide_authorization_action_names
	 *
	 * @param string $action Route action.
	 */
	public function test_authorization_actions_log_an_unexpected_error( string $action ): void {
		$processing_service = new class() extends PaymentProcessingService {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Capture a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \RuntimeException Always.
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				throw new \RuntimeException( 'capture failed' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \RuntimeException Always.
			 */
			public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				throw new \RuntimeException( 'cancel failed' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$logger             = RecordingWcLogger::install();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->create_authorizations_controller( $processing_service )->register_routes();
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
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 * @throws \Error Always.
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				throw new \Error( 'capture failed' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};
		$logger             = RecordingWcLogger::install();
		$this->create_authorizations_controller( $processing_service )->register_routes();
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
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
	}

	/**
	 * @testdox A capture refused by the order payment lock keeps one audit entry and reaches no provider.
	 *
	 * The real processing service refuses before the provider call and saves nothing, so only the route's save keeps the entry.
	 */
	public function test_lock_refused_capture_keeps_one_audit_entry(): void {
		$order      = $this->create_authorized_order( 'pi_auth' );
		$store      = wc_get_container()->get( \Automattic\WooCommerce\Internal\Payments\OrderPaymentLock::class );
		$vocabulary = new \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary();
		$holder     = $store->claim( $order, $vocabulary, 'pi_other', 'payment status update' );
		$this->assertNotNull( $holder );
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $this->api_client, wc_get_container()->get( PaymentProcessingService::class ), wc_get_container()->get( WooPaymentsProvider::class ) );
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
		$store->release( $order, $vocabulary, (string) $holder );

		$this->assertSame( 'wcpay_capture_error', $response->get_data()['code'] );
		$this->assertNotSame( 'capture_intention', $this->api_client->last_call['method'] ?? '' );
		$this->assertCount( 1, wc_get_order( $order->get_id() )->get_meta( '_wcpay_fraud_outcome_manual_entry', false ) );
	}

	/**
	 * The extension reads the intent inside its generic catch, so a platform failure there answers its generic error
	 * after logging it (class-wc-rest-payments-orders-controller.php:389-391, :434-437, :628-630, :667-670). The faked
	 * failure is the shape the API client builds from a platform error body and its HTTP status.
	 *
	 * @testdox A failed intent read before $action answers the logged generic 500 error.
	 * @dataProvider provide_authorization_action_names
	 *
	 * @param string $action Route action.
	 */
	public function test_failed_intent_read_answers_the_generic_error( string $action ): void {
		$logger = RecordingWcLogger::install();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enable_logging' => 'yes' ) );
		$this->create_authorizations_controller()->register_routes();
		$order                       = $this->create_authorized_order( 'pi_auth' );
		$this->api_client->exception = new WooPaymentsApiException( 'No such payment_intent.', 'resource_missing', 404 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/' . $action . '_authorization' );
		$request->set_body_params( array( 'payment_intent_id' => 'pi_auth' ) );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_server_error', $response->get_data()['code'] );
		$errors = $logger->get_errors();
		$this->assertCount( 1, $errors );
		$this->assertSame( 'woopayments', $errors[0][2] );
	}

	/**
	 * Faked failures are the shape the API client builds from a platform error body and its HTTP status; an encode
	 * failure carries none (wcpay_client_unable_to_encode_json, status 0).
	 *
	 * @testdox A "$code" failure with status $platform_status on the authorization detail route answers $expected_status.
	 * @testWith ["wcpay_client_unable_to_encode_json", 0, 500]
	 *           ["authorization_missing", 404, 404]
	 *
	 * @param string $code            Platform error code.
	 * @param int    $platform_status Platform HTTP status.
	 * @param int    $expected_status Expected answer status.
	 */
	public function test_authorization_detail_answers_a_4xx_or_5xx_status( string $code, int $platform_status, int $expected_status ): void {
		$this->create_authorizations_controller()->register_routes();
		$this->api_client->exception = new WooPaymentsApiException( 'Platform failure.', $code, $platform_status );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations/pi_1' ) );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/**
	 * A list request filter callback that returns something other than the request stops the request with the API client
	 * path's error, as the extension's request classes end it with a 500 (includes/core/server/class-request.php:528-569, :770-783).
	 *
	 * @testdox A $hook callback returning no request answers 500 wcpay_invalid_filtered_request.
	 * @testWith ["wcpay_list_authorizations_request", false]
	 *           ["wcpay_list_authorizations_request", true]
	 *
	 * @param string $hook      Request filter.
	 * @param bool   $as_object Whether the callback returns an object of another class.
	 */
	public function test_list_filter_returning_no_request_fails_the_request( string $hook, bool $as_object ): void {
		$this->create_authorizations_controller()->register_routes();
		add_filter(
			$hook,
			static function () use ( $as_object ) {
				return $as_object ? new \stdClass() : null;
			}
		);

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/wc/v3/payments/authorizations' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'wcpay_invalid_filtered_request', $response->get_data()['code'] );
		$this->assertSame( "Invalid WooPayments request returned by {$hook}.", $response->get_data()['message'] );
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
	 * @testdox A failed authorization capture or cancel answers the WooPayments extension's code, message and status, and keeps the merchant's audit entry.
	 *
	 * Client 11.1.0 class-wc-rest-payments-orders-controller.php:403-425 (capture) and :645-656 (cancel).
	 *
	 * @dataProvider provide_failed_authorization_outcomes
	 *
	 * @param string              $action   Route action.
	 * @param array<string,mixed> $data     Failed outcome data, as WooPaymentsIntentCodec::failed_transport_outcome() builds it.
	 * @param array<string,mixed> $expected Expected code, message and error data.
	 */
	public function test_failed_authorization_action_answers_the_woopayments_extension_error( string $action, array $data, array $expected ): void {
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_auth', '', '', '', $this->data );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, 'pi_auth', '', '', '', $this->data );
			}
		};
		$this->create_authorizations_controller( $processing_service )->register_routes();
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
			'capture declined'                  => array(
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
			'capture amount too small'          => array(
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
			// Client 11.1.0 formats the minimum with WC_Payments_Utils::format_explicit_currency() (gw:4003-4009): wc_price() in the
			// currency's locale-info format, which for EUR is a comma decimal and the symbol on the right after a space
			// (WC_Payments_Utils::get_woocommerce_price_format() 'right_space', class-wc-payments-utils.php:1147-1148).
			'capture amount too small in euros' => array(
				'capture',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'amount_too_small',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Amount must be at least €0.50 eur',
					'http_code'                        => 400,
					'extra_details'                    => array(
						'minimum_amount'          => 50,
						'minimum_amount_currency' => 'EUR',
					),
				),
				array(
					'status'  => 400,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'Amount must be at least €0.50 eur The minimum amount to capture is 0,50 € EUR.',
					'data'    => array(
						'status'        => 400,
						'extra_details' => array(
							'minimum_amount'          => 50,
							'minimum_amount_currency' => 'EUR',
						),
						'error_type'    => 'amount_too_small',
					),
				),
			),
			// The API client's encode failure carries no HTTP status, which the client passes on (class-wc-rest-payments-orders-controller.php:419).
			'capture fails with status 0'       => array(
				'capture',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => 'wcpay_client_unable_to_encode_json',
					PaymentOutcome::DATA_ERROR_MESSAGE => 'Unable to encode the WooPayments request body.',
					'http_code'                        => 0,
					'extra_details'                    => array(),
				),
				array(
					'status'  => 0,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'Unable to encode the WooPayments request body.',
					'data'    => array(
						'status'        => 0,
						'extra_details' => array(),
						'error_type'    => 'wcpay_client_unable_to_encode_json',
					),
				),
			),
			// A refusal with a message and no platform status (the order payment lock) answers 502 ($result['http_code'] ?? 502, class-wc-rest-payments-orders-controller.php:419).
			'capture refused without a status'  => array(
				'capture',
				array(
					PaymentOutcome::DATA_ERROR_MESSAGE => 'A payment operation is already in progress for this order.',
				),
				array(
					'status'  => 502,
					'code'    => 'wcpay_capture_error',
					'message' => $capture_prefix . 'A payment operation is already in progress for this order.',
					'data'    => array(
						'status'        => 502,
						'extra_details' => array(),
						'error_type'    => null,
					),
				),
			),
			'capture without message'           => array(
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
			'cancel refused'                    => array(
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
	 * @testdox An authorization request is refused before payment processing: $_dataName.
	 * @dataProvider provide_authorization_refusals_before_processing
	 *
	 * @param string                   $action                 Capture or cancel.
	 * @param string|null              $stored_intent          The order's stored intent, or null for no order.
	 * @param string|null              $posted_intent          Posted payment_intent_id, or null to post none.
	 * @param bool                     $refunded               Whether the order has a refund.
	 * @param array<string,mixed>|null $live_intent            Live intent id, status and order_id offset, or null for no answer.
	 * @param int                      $expected_status        Expected answer status.
	 * @param string                   $expected_code          Expected error code.
	 * @param string|null              $expected_message       Expected error message, or null when the row does not check it.
	 * @param string|null              $expected_last_method   Platform method last called ('' for none), or null when the row does not check it.
	 * @param bool                     $keeps_payment_identity Whether the row checks the order keeps its status, intent and audit trail.
	 * @phpstan-param array{id:string,status:string,order_offset:int}|null $live_intent
	 */
	public function test_authorization_refusal_before_processing( string $action, ?string $stored_intent, ?string $posted_intent, bool $refunded, ?array $live_intent, int $expected_status, string $expected_code, ?string $expected_message, ?string $expected_last_method, bool $keeps_payment_identity ): void {
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
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				++$this->capture_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				++$this->cancel_calls;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( $processing_service )->register_routes();
		$order_id = 999999;
		if ( null !== $stored_intent ) {
			$order_id = $this->create_authorized_order( $stored_intent )->get_id();
		}
		if ( $refunded ) {
			wc_create_refund(
				array(
					'order_id' => $order_id,
					'amount'   => 1,
				)
			);
		}
		if ( null !== $live_intent ) {
			$this->api_client->response = array(
				'id'       => $live_intent['id'],
				'status'   => $live_intent['status'],
				'metadata' => array(
					'order_id' => (string) ( $order_id + $live_intent['order_offset'] ),
				),
			);
		}

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order_id . '/' . $action . '_authorization' );
		if ( null !== $posted_intent ) {
			$request->set_body_params( array( 'payment_intent_id' => $posted_intent ) );
		}
		$response = $this->server->dispatch( $request );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( $expected_code, $response->get_data()['code'] );
		$this->assertSame( 0, $processing_service->capture_calls, 'No capture may run for a refused request.' );
		$this->assertSame( 0, $processing_service->cancel_calls, 'No cancel may run for a refused request.' );
		if ( null !== $expected_message ) {
			$this->assertSame( $expected_message, $response->get_data()['message'] );
		}
		if ( '' === $expected_last_method ) {
			$this->assertSame( array(), $this->api_client->last_call, 'The refusal comes before the live intent is ever fetched.' );
		} elseif ( null !== $expected_last_method ) {
			$this->assertSame( $expected_last_method, $this->api_client->last_call['method'] );
		}
		if ( $keeps_payment_identity ) {
			$order = wc_get_order( $order_id );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertSame( 'on-hold', $order->get_status() );
			$this->assertSame( 'pi_Y', $order->get_meta( '_intent_id', true ) );
			$this->assertSame( 'pi_Y', $order->get_transaction_id() );
			$this->assertSame( '', $order->get_meta( '_wcpay_fraud_outcome_manual_entry', true ), 'The refused request must not write the merchant audit entry.' );
		}
	}

	/**
	 * Authorization requests refused before payment processing, with the checks each row adds.
	 *
	 * @return array<string,array{0:string,1:?string,2:?string,3:bool,4:?array{id:string,status:string,order_offset:int},5:int,6:string,7:?string,8:?string,9:bool}>
	 */
	public function provide_authorization_refusals_before_processing(): array {
		$another_intent = array(
			'id'           => 'pi_X',
			'status'       => 'requires_capture',
			'order_offset' => 0,
		);

		return array(
			// payment_intent_id is a required route arg, like the reference client.
			'capture without an intent id'               => array( 'capture', null, null, false, null, 400, 'rest_missing_callback_param', null, null, false ),
			'capture for a missing order'                => array( 'capture', null, 'pi_auth', false, null, 404, 'wcpay_missing_order', null, null, false ),
			'capture naming another intent than the stored one' => array( 'capture', 'pi_order', 'pi_other', false, null, 409, 'wcpay_intent_order_mismatch', null, null, false ),
			// The refund guard of capture_authorization() (includes/admin/class-wc-rest-payments-orders-controller.php:379-386, message :383):
			// 0 < $order->get_total_refunded() short-circuits before the live intent is ever fetched.
			'capture on a refunded order'                => array( 'capture', 'pi_auth', 'pi_auth', true, null, 400, 'wcpay_refunded_order_uncapturable', 'Payment cannot be captured for partially or fully refunded orders.', '', false ),
			// The same guard in cancel_authorization() (:618-625, message :622).
			'cancel on a refunded order'                 => array( 'cancel', 'pi_auth', 'pi_auth', true, null, 400, 'wcpay_refunded_order_uncapturable', 'Payment cannot be canceled for partially or fully refunded orders.', '', false ),
			// The cancel route declares payment_intent_id as required the same way the capture route does
			// (includes/admin/class-wc-rest-payments-orders-controller.php:122-133).
			'cancel without an intent id'                => array( 'cancel', 'pi_auth', null, false, null, 400, 'rest_missing_callback_param', null, null, false ),
			'capture of a live intent for another order' => array(
				'capture',
				'pi_auth',
				'pi_auth',
				false,
				array(
					'id'           => 'pi_auth',
					'status'       => 'requires_capture',
					'order_offset' => 1,
				),
				409,
				'wcpay_intent_order_mismatch',
				null,
				'get_payment_intention',
				false,
			),
			// Client 11.1.0 checks only the live intent's metadata.order_id and status
			// (includes/admin/class-wc-rest-payments-orders-controller.php:389-406 for capture, :628-645 for cancel), then
			// captures or cancels the order's stored intent (class-wc-payment-gateway-wcpay.php:3975, :4076), so a request naming
			// pi_X moves pi_Y. Native refuses the request before any platform call.
			// The live pi_X passes both client checks: metadata.order_id is the order (orders controller :393-396 for capture,
			// :632-635 for cancel) and the status is requires_capture (is_authorized() at :400, the status list at :639).
			'capture of another authorized intent for the order' => array( 'capture', 'pi_Y', 'pi_X', false, $another_intent, 409, 'wcpay_intent_order_mismatch', null, null, true ),
			'cancel of another authorized intent for the order' => array( 'cancel', 'pi_Y', 'pi_X', false, $another_intent, 409, 'wcpay_intent_order_mismatch', null, null, true ),
			'cancel of an intent that already succeeded' => array(
				'cancel',
				'pi_auth',
				'pi_auth',
				false,
				array(
					'id'           => 'pi_auth',
					'status'       => 'succeeded',
					'order_offset' => 0,
				),
				409,
				'wcpay_payment_uncapturable',
				null,
				'get_payment_intention',
				false,
			),
		);
	}

	/**
	 * @testdox Capture and cancel authorization actions delegate through payment processing.
	 *
	 * Mirrors client 11.1.0's `add_fraud_outcome_manual_entry()`
	 * (`includes/admin/class-wc-rest-payments-orders-controller.php:679-691`), called with
	 * `'approve'` on capture (`:404`) and `'block'` on cancel (`:643`); both write the same
	 * `type`/`user`/`action` shape to the `_wcpay_fraud_outcome_manual_entry` order meta.
	 */
	public function test_authorization_actions_delegate_through_payment_processing(): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Last capture context.
			 *
			 * @var PaymentOperationContext|null
			 */
			public ?PaymentOperationContext $last_capture_context = null;

			/**
			 * Last cancel context.
			 *
			 * @var PaymentOperationContext|null
			 */
			public ?PaymentOperationContext $last_cancel_context = null;

			/**
			 * Capture a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				$this->last_capture_context = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_auth' );
			}

			/**
			 * Cancel a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				$this->last_cancel_context = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, 'pi_auth' );
			}
		};

		$this->create_authorizations_controller( $processing_service )->register_routes();
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
		$this->assertInstanceOf( PaymentOperationContext::class, $processing_service->last_capture_context );
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
		$this->assertInstanceOf( PaymentOperationContext::class, $processing_service->last_cancel_context );

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
	 * The capture route takes no amount and captures the order total, as the extension's route, which registers only
	 * payment_intent_id and never reads a posted amount (class-wc-rest-payments-orders-controller.php:106-119, :368-438).
	 *
	 * @testdox A posted capture amount "$posted_amount" is ignored and the order total is captured.
	 * @testWith ["4.25"]
	 *           ["abc"]
	 *           ["-1"]
	 *
	 * @param string $posted_amount Amount posted with the capture request.
	 */
	public function test_capture_authorization_ignores_a_posted_amount( string $posted_amount ): void {
		$processing_service = new class() extends PaymentProcessingService {
			/**
			 * Capture contexts received.
			 *
			 * @var PaymentOperationContext[]
			 */
			public array $capture_contexts = array();

			/**
			 * Capture a payment.
			 *
			 * @param PaymentOperationContext $context  Payment context.
			 * @param ProviderInterface       $provider Payment provider.
			 * @return PaymentOutcome
			 */
			public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
				$this->capture_contexts[] = $context;

				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 'pi_partial' );
			}
		};

		$this->create_authorizations_controller( $processing_service )->register_routes();
		$order = $this->create_authorized_order( 'pi_partial' );
		// The intent fields the client checks before acting: metadata.order_id and an authorized status (class-wc-rest-payments-orders-controller.php:389-401).
		$this->api_client->response = array(
			'id'       => 'pi_partial',
			'status'   => 'requires_capture',
			'amount'   => 1000,
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_body_params(
			array(
				'payment_intent_id' => 'pi_partial',
				'amount'            => $posted_amount,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $processing_service->capture_contexts );
		$this->assertNull( $processing_service->capture_contexts[0]->get_amount(), 'No amount on the context: the capture takes the order total.' );
	}

	/**
	 * Create an authorizations controller.
	 *
	 * @param PaymentProcessingService|null $processing_service Optional payment processing service.
	 * @return WooPaymentsAuthorizationsRestController
	 */
	private function create_authorizations_controller( ?PaymentProcessingService $processing_service = null ): WooPaymentsAuthorizationsRestController {
		$controller = new WooPaymentsAuthorizationsRestController();
		$controller->init( $this->api_client, $processing_service ?? new PaymentProcessingService(), new WooPaymentsProvider() );

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
