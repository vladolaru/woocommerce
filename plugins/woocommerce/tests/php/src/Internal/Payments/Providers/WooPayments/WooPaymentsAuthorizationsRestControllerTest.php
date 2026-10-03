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
