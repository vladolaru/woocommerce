<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalPaymentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Tests\Internal\Payments\PlatformRequestClaimGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exercise the order claim permissions through provider code and the real API client.
 */
class PlatformRequestClaimTest extends WC_Unit_Test_Case {
	use WithOrderPaymentClaim;

	/** @var FakeHttpClient Platform transport. */
	private FakeHttpClient $http;

	/**
	 * Start with an active card gateway and a cold account cache.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);
		update_option( 'wcpay_onboarding_test_mode', 'yes' );
		delete_option( 'wcpay_account_data' );
		$this->http = new class() extends FakeHttpClient {
			/** @var array|null The account response every account request gets, or null to answer it from the queue. */
			public ?array $account = null;

			/**
			 * Answer an account request with the account when one is set, whenever it comes, and other requests from the queue.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           WPCOM path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Request body.
			 * @param int         $timeout        Request timeout.
			 * @param bool        $use_user_token Whether to sign with the connection-owner user token.
			 * @param bool        $blocking       Whether the request should block for the response.
			 * @return mixed
			 */
			public function respond_to_request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				if ( null === $this->account || ! preg_match( '~/wcpay/accounts(?:\?|$)~', $path ) ) {
					return parent::respond_to_request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
				}
				$queued          = $this->responses;
				$this->responses = array( $this->account );
				parent::respond_to_request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );
				$this->responses = $queued;

				return $this->account;
			}
		};
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
	}

	/**
	 * Release the request's transport and services.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		$this->reset_container_resolutions();
		parent::tearDown();
	}

	/**
	 * Checkout reads the account during availability. Preparing Level 3 data uses the same account.
	 * The only sends during the first charge are creating its customer and the charge itself.
	 */
	public function test_checkout_uses_the_account_already_read_for_availability(): void {
		$order                 = $this->order();
		$this->http->responses = array(
			$this->account_response(),
			$this->response( array( 'id' => 'cus_first' ) ),
			$this->response(
				array(
					'id'            => 'pi_first',
					'status'        => 'requires_action',
					'client_secret' => 'secret',
				)
			),
		);
		$gateway               = new WooPaymentsGateway();
		$this->assertTrue( $gateway->is_available() );
		$this->assertSame( array( 'accounts' ), $this->routes() );
		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			$gateway->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
		}
		$this->assertSame( array( 'accounts', 'customers', 'intentions' ), $this->routes() );
		$body = json_decode( (string) $this->http->last_body, true );
		$this->assertSame( (string) $order->get_id(), $body['level3']['merchant_reference'] );
	}

	/**
	 * The setup retry creates a replacement customer before sending the second SetupIntent.
	 */
	public function test_setup_replaces_a_missing_customer_under_the_checkout_claim(): void {
		$order = $this->order();
		$order->set_total( '0.00' );
		$order->save();
		update_user_option( $order->get_customer_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_missing' );
		$this->http->responses = array(
			$this->account_response(),
			$this->response(
				array(
					'error' => array(
						'code'    => 'resource_missing',
						'message' => "No such customer: 'cus_missing'",
					),
				),
				404
			),
			$this->response( array( 'id' => 'cus_replaced' ) ),
			$this->response(
				array(
					'id'     => 'seti_retry',
					'status' => 'requires_action',
				)
			),
		);
		wc_get_container()->get( WooPaymentsAccountService::class )->get_cached_account_data();
		wc_get_container()->get( PaymentProcessingService::class )->process_checkout_outcome(
			PaymentOperationContext::for_checkout( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'ctoken_setup' ),
			wc_get_container()->get( WooPaymentsProvider::class )
		);
		$this->assertSame( array( 'accounts', 'setup_intents', 'customers', 'setup_intents' ), $this->routes() );
	}

	/**
	 * Charge expiry settles the account, then reads the expired intent under the event's claim.
	 */
	public function test_expired_charge_reads_the_account_and_intent_under_its_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_charge_id', 'ch_expired' );
		$order->update_meta_data( '_intent_id', 'pi_expired' );
		$order->save();
		$this->http->responses = array(
			$this->account_response(),
			$this->response(
				array(
					'id'     => 'pi_expired',
					'status' => 'canceled',
				)
			),
		);
		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'charge.expired', array( 'id' => 'ch_expired' ) );
		$this->assertSame( array( 'accounts', 'intentions/pi_expired' ), $this->routes() );
	}

	/**
	 * A lost dispute fetches its own summary before writing the refund.
	 */
	public function test_lost_dispute_reads_its_summary_under_its_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_charge_id', 'ch_lost' );
		$order->update_meta_data( '_wcpay_open_dispute_ids', array( 'dp_lost' ) );
		$order->save();
		$this->http->response = $this->response(
			array(
				'disputed_amount' => 300,
				'currency'        => 'usd',
			)
		);
		wc_get_container()->get( DisputeEventHandler::class )->process(
			'charge.dispute.closed',
			array(
				'id'     => 'dp_lost',
				'charge' => 'ch_lost',
				'status' => 'lost',
			)
		);
		$this->assertSame( array( 'disputes/dp_lost/summary' ), $this->routes() );
	}

	/**
	 * An event with no local token looks up the saved methods and the card, then settles its account.
	 */
	public function test_recurring_payment_repairs_the_token_and_settles_under_claim(): void {
		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$order = $this->order();
		wp_set_current_user( $order->get_customer_id() );
		update_user_option( $order->get_customer_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_renewal' );
		wc_get_container()->get( WooPaymentsTokenService::class )->register();
		$GLOBALS['wcpay_test_renewal_order_ids'] = array( $order->get_id() );
		$list                                    = $this->response( array( 'data' => array() ) );
		$card                                    = array(
			'brand'     => 'visa',
			'last4'     => '4242',
			'exp_month' => 12,
			'exp_year'  => 2035,
		);
		$this->http->responses                   = array(
			$list,
			$list,
			$list,
			$this->response(
				array(
					'id'   => 'pm_renewal',
					'type' => 'card',
					'card' => $card,
				)
			),
			$this->account_response(),
		);
		wc_get_container()->get( WooPaymentsOrderCardInfo::class )->register();
		try {
			$this->with_order_payment_claim(
				$order,
				'payment status update',
				static function () use ( $order, $card ) {
					wc_get_container()->get( PaymentIntentEventHandler::class )->apply_succeeded_payment_intent_under_lock(
						$order,
						array(
							'id'             => 'pi_renewal',
							'status'         => 'succeeded',
							'amount'         => 1000,
							'currency'       => 'usd',
							'payment_method' => 'pm_renewal',
							'charges'        => array(
								'data' => array(
									array(
										'id' => 'ch_renewal',
										'payment_method_details' => array(
											'type' => 'card',
											'card' => $card,
										),
									),
								),
							),
						)
					);
				}
			);
		} finally {
			unset( $GLOBALS['wcpay_test_renewal_order_ids'] );
			wp_set_current_user( 0 );
		}
		$this->assertContains( 'payment_methods', $this->routes() );
		$this->assertContains( 'payment_methods/pm_renewal', $this->routes() );
		$this->assertContains( 'accounts', $this->routes() );
		$this->assertNotEmpty( ( new WC_Order( $order->get_id() ) )->get_payment_tokens() );
	}

	/**
	 * A Failed email may fetch the card details omitted by the decline.
	 */
	public function test_failed_email_reads_missing_card_details_under_claim(): void {
		$order = $this->order();
		$order->update_meta_data( '_payment_method_id', 'pm_declined' );
		$order->save();
		wc_get_container()->get( WooPaymentsOrderCardInfo::class )->register();
		$this->http->response = $this->response(
			array(
				'type' => 'card',
				'card' => array(
					'brand' => 'visa',
					'last4' => '4242',
				),
			)
		);
		$order->set_payment_method_title( 'Card' );
		$order->save();
		add_action( 'woocommerce_email_order_details', array( WC()->mailer(), 'order_details' ), 10, 4 );
		$email         = new \WC_Email_Customer_Failed_Order();
		$email->object = $order;
		$this->with_order_payment_claim( $order, 'checkout', array( $email, 'get_content_html' ) );
		$this->assertSame( array( 'payment_methods/pm_declined' ), $this->routes() );
	}

	/**
	 * The same route from an unlisted caller fails only while this request owns the order.
	 */
	public function test_unlisted_customer_create_is_reported_only_inside_the_claim(): void {
		$order                = $this->order();
		$api                  = wc_get_container()->get( Client::class );
		$this->http->response = $this->response( array( 'id' => 'cus_guard' ) );
		$create               = static fn() => $api->create_customer( array() );
		$create();
		$this->with_order_payment_claim( $order, 'checkout', $create );
		$create();
		$this->expectException( \PHPUnit\Framework\AssertionFailedError::class );
		$this->expectExceptionMessageMatches( '/^checkout order ' . $order->get_id() . ': POST customers via [^\n]+$/D' );
		PlatformRequestClaimGuard::finish();
	}

	/**
	 * @testdox $_dataName reads a cold account once, before its claim, and reads nothing for the account under it.
	 *
	 * The Level 3 lookup and the composition data settler run under the claim and read the account the entry point read.
	 * The guard's listener tells a read before the claim from one under it; the routes are compared in any order.
	 *
	 * @dataProvider provider_entry_points
	 *
	 * @param string   $entry  Runs the entry point.
	 * @param string[] $routes Expected platform routes, in order.
	 */
	public function test_entry_point_reads_a_cold_account_before_its_claim( string $entry, array $routes ): void {
		$this->http->account = $this->account_response();

		$this->$entry( $this->authorized_order() );

		$this->assertEqualsCanonicalizing( $routes, $this->routes() );
	}

	/**
	 * Entry points that take an order payment claim and read the account under it.
	 *
	 * @return array<string,array{string,string[]}>
	 */
	public function provider_entry_points(): array {
		return array(
			'A direct checkout call'             => array( 'run_direct_checkout', array( 'accounts', 'customers', 'intentions' ) ),
			'The order screen capture'           => array( 'run_order_screen_capture', array( 'accounts', 'intentions/pi_auth/capture' ) ),
			'The status change capture'          => array( 'run_status_change_capture', array( 'accounts', 'intentions/pi_auth/capture' ) ),
			'The REST capture'                   => array( 'run_rest_capture', array( 'intentions/pi_auth', 'accounts', 'intentions/pi_auth/capture' ) ),
			'The REST payment intent creation'   => array( 'run_rest_payment_intent_creation', array( 'accounts', 'intentions' ) ),
			'The terminal capture'               => array( 'run_terminal_capture', array( 'accounts', 'intentions/pi_auth', 'intentions/pi_auth/capture' ) ),
			'A renewal run from an admin screen' => array( 'run_admin_renewal', array( 'accounts', 'intentions' ) ),
		);
	}

	/**
	 * @testdox When the read before the claim fails, the payment goes on with $_dataName and no second account read.
	 *
	 * @dataProvider provider_stored_accounts
	 *
	 * @param bool $stored Whether an expired US account is stored.
	 */
	public function test_failed_read_before_the_claim_leaves_the_payment_on_the_stored_account( bool $stored ): void {
		if ( $stored ) {
			$this->store_expired_account();
		}
		$order                 = $this->order();
		$this->http->account   = $this->response( array( 'error' => array( 'code' => 'server_error' ) ), 500 );
		$this->http->responses = array( $this->response( array( 'id' => 'cus_first' ) ), $this->intent_response() );

		$this->pay_without_availability_check( $order );

		$this->assertEqualsCanonicalizing( array( 'accounts', 'customers', 'intentions' ), $this->routes() );
		$body = json_decode( (string) $this->http->last_body, true );
		$this->assertSame( $stored, isset( $body['level3'] ), 'Level 3 data is sent with the stored US account, and left out with none.' );
	}

	/**
	 * Stored account states for the failed read.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provider_stored_accounts(): array {
		return array(
			'the expired account it stores' => array( true ),
			'no stored account'             => array( false ),
		);
	}

	/**
	 * @testdox A throwing account refresh callback is logged, and the payment goes on with no account read under its claim.
	 */
	public function test_throwing_refresh_callback_before_the_claim_is_logged(): void {
		$this->enable_logging();
		$logger = RecordingWcLogger::install();
		add_action(
			'woocommerce_payments_account_refreshed',
			static function (): void {
				throw new \RuntimeException( 'Refresh callback failure.' );
			}
		);
		$order                 = $this->order();
		$this->http->account   = $this->account_response();
		$this->http->responses = array( $this->response( array( 'id' => 'cus_first' ) ), $this->intent_response() );

		$this->pay_without_availability_check( $order );

		$this->assertEqualsCanonicalizing( array( 'accounts', 'customers', 'intentions' ), $this->routes() );
		$this->assertArrayHasKey( 'level3', json_decode( (string) $this->http->last_body, true ) );
		$lines = array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] && 'Failed to read the WooPayments account before the payment.' === $line[1] );
		$this->assertCount( 1, $lines );
	}

	/**
	 * @testdox A status change capture retried by Action Scheduler reads no account from the platform.
	 */
	public function test_status_change_retry_under_action_scheduler_reads_no_account(): void {
		$order = $this->authorized_order();
		$order->set_status( 'completed' );
		$order->save();
		$this->http->responses = array( $this->recorded( 'capture_full_amount', $order ) );
		wc_get_container()->get( WooPaymentsAccountService::class )->handle_action_scheduler_before_execute();

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_retry_status_change_operation( $order->get_id(), 'capture', 1 );

		$this->assertSame( array( 'intentions/pi_auth/capture' ), $this->routes() );
	}

	/**
	 * @testdox Cancelling an authorization from the order screen reads no account.
	 */
	public function test_order_screen_cancel_reads_no_account(): void {
		$order                 = $this->authorized_order();
		$this->http->responses = array( $this->recorded( 'authorize_manual_capture', $order, array( 'status' => 'canceled' ) ) );

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_woocommerce_order_action_cancel_authorization( $order );

		$this->assertSame( array( 'intentions/pi_auth/cancel' ), $this->routes() );
	}

	/**
	 * Pay through the card gateway as a direct caller does, without the availability check.
	 *
	 * @param WC_Order $order Order.
	 */
	private function run_direct_checkout( WC_Order $order ): void {
		$order->delete_meta_data( '_intent_id' );
		$order->delete_meta_data( '_intention_status' );
		$order->set_transaction_id( '' );
		$order->set_status( 'pending' );
		$order->save();
		$this->http->responses = array(
			$this->response( array( 'id' => 'cus_first' ) ),
			$this->intent_response(),
		);

		$this->pay_without_availability_check( $order );
	}

	/**
	 * Capture from the order screen's Capture charge action.
	 *
	 * @param WC_Order $order Authorized order.
	 */
	private function run_order_screen_capture( WC_Order $order ): void {
		$this->http->responses = array( $this->recorded( 'capture_full_amount', $order ) );

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_woocommerce_order_action_capture_charge( $order );
	}

	/**
	 * Capture on the order's change to Completed.
	 *
	 * @param WC_Order $order Authorized order.
	 */
	private function run_status_change_capture( WC_Order $order ): void {
		$this->http->responses = array( $this->recorded( 'capture_full_amount', $order ) );

		wc_get_container()->get( WooPaymentsOrderAdminActionsController::class )->handle_woocommerce_order_status_completed( $order->get_id(), $order );
	}

	/**
	 * Capture through the REST authorization route.
	 *
	 * @param WC_Order $order Authorized order.
	 */
	private function run_rest_capture( WC_Order $order ): void {
		$this->http->responses = array(
			$this->recorded( 'authorize_manual_capture', $order ),
			$this->recorded( 'capture_full_amount', $order ),
		);
		$request               = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_authorization' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_auth' );

		$response = wc_get_container()->get( WooPaymentsAuthorizationsRestController::class )->capture_authorization( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
	}

	/**
	 * Create and confirm a payment intent through the REST route.
	 *
	 * @param WC_Order $order Order.
	 */
	private function run_rest_payment_intent_creation( WC_Order $order ): void {
		$order->delete_meta_data( '_intent_id' );
		$order->delete_meta_data( '_intention_status' );
		$order->set_transaction_id( '' );
		$order->set_status( 'pending' );
		$order->save();
		$this->http->responses = array( $this->intent_response() );
		$request               = new WP_REST_Request( 'POST', '/wc/v3/payments/payment_intents' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'customer', 'cus_rest' );
		$request->set_param( 'payment_method', 'pm_card' );
		$gateways                                  = WC()->payment_gateways()->payment_gateways;
		WC()->payment_gateways()->payment_gateways = array( new WooPaymentsGateway() );

		try {
			$response = wc_get_container()->get( WooPaymentsPaymentDetailsRestController::class )->create_payment_intent( $request );
		} finally {
			WC()->payment_gateways()->payment_gateways = $gateways;
		}

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
	}

	/**
	 * Capture an in-person payment through the terminal route.
	 *
	 * @param WC_Order $order Order whose in-person payment is authorized.
	 */
	private function run_terminal_capture( WC_Order $order ): void {
		$order->delete_meta_data( '_intent_id' );
		$order->delete_meta_data( '_intention_status' );
		$order->set_transaction_id( '' );
		$order->set_status( 'pending' );
		$order->save();
		$this->http->responses = array(
			$this->recorded( 'authorize_manual_capture', $order ),
			$this->recorded( 'capture_full_amount', $order ),
		);
		$request               = new WP_REST_Request( 'POST', '/wc/v3/payments/orders/' . $order->get_id() . '/capture_terminal_payment' );
		$request->set_param( 'order_id', $order->get_id() );
		$request->set_param( 'payment_intent_id', 'pi_auth' );

		$response = wc_get_container()->get( WooPaymentsTerminalPaymentsRestController::class )->capture_terminal_payment( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_code() : '' );
	}

	/**
	 * Charge a renewal with its saved card in an admin request, as the subscription screen's renewal action does.
	 *
	 * @param WC_Order $order Renewal order.
	 */
	private function run_admin_renewal( WC_Order $order ): void {
		$order->delete_meta_data( '_intent_id' );
		$order->delete_meta_data( '_intention_status' );
		$order->set_transaction_id( '' );
		$order->set_status( 'pending' );
		$token = new \WC_Payment_Token_CC();
		$token->set_gateway_id( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$token->set_user_id( $order->get_customer_id() );
		$token->set_token( 'pm_renewal' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2035' );
		$token->save();
		$order->add_payment_token( $token );
		$order->save();
		update_user_option( $order->get_customer_id(), WooPaymentsCustomerService::TEST_CUSTOMER_ID_OPTION, 'cus_renewal' );
		$this->http->responses = array( $this->intent_response() );
		set_current_screen( 'edit-shop_subscription' );

		try {
			wc_get_container()->get( SubscriptionsController::class )->scheduled_subscription_payment( $order->get_total(), $order );
		} finally {
			set_current_screen( 'front' );
		}
	}

	/**
	 * Pay an order through the card gateway with a new card, as a direct caller with no shopper session does.
	 *
	 * Without a session the card-testing check reads no account, so nothing else reads it before the claim.
	 *
	 * @param WC_Order $order Order.
	 */
	private function pay_without_availability_check( WC_Order $order ): void {
		$session                       = WC()->session;
		WC()->session                  = null;
		$_POST['wcpay-payment-method'] = 'pm_card';
		try {
			( new WooPaymentsGateway() )->process_payment( $order->get_id() );
		} finally {
			unset( $_POST['wcpay-payment-method'] );
			WC()->session = $session;
		}
	}

	/**
	 * Create an on-hold order that stores an uncaptured WooPayments authorization.
	 *
	 * @return WC_Order
	 */
	private function authorized_order(): WC_Order {
		$order = $this->order();
		$order->set_transaction_id( 'pi_auth' );
		$order->update_meta_data( '_intent_id', 'pi_auth' );
		$order->update_meta_data( '_intention_status', 'requires_capture' );
		$order->set_status( 'on-hold' );
		$order->save();

		return $order;
	}

	/**
	 * Store the recorded US account, fetched two days ago, so a read refreshes it.
	 */
	private function store_expired_account(): void {
		$fixture = wp_json_file_decode( __DIR__ . '/Fixtures/platform-test-drive-account.json', array( 'associative' => true ) );
		update_option(
			WooPaymentsDatabaseCache::ACCOUNT_KEY,
			array(
				'data'               => $fixture['account'],
				'fetched'            => time() - 2 * DAY_IN_SECONDS,
				'errored'            => false,
				'consecutive_errors' => 0,
			),
			false
		);
	}

	/**
	 * Turn on the gateway's debug logging.
	 */
	private function enable_logging(): void {
		$settings                   = get_option( 'woocommerce_woocommerce_payments_settings' );
		$settings['enable_logging'] = 'yes';
		update_option( 'woocommerce_woocommerce_payments_settings', $settings );
	}

	/**
	 * A recorded manual capture response for the order, as intent pi_auth.
	 *
	 * @param string              $pair      Entry pair in Fixtures/platform-manual-capture.json.
	 * @param WC_Order            $order     Order.
	 * @param array<string,mixed> $overrides Fields to replace.
	 * @return array
	 */
	private function recorded( string $pair, WC_Order $order, array $overrides = array() ): array {
		$recording = wp_json_file_decode( __DIR__ . '/Fixtures/platform-manual-capture.json', array( 'associative' => true ) );
		foreach ( $recording['entries'] as $entry ) {
			if ( $pair === $entry['pair'] ) {
				$intent             = array_merge( $entry['response']['body'], $overrides );
				$intent['id']       = 'pi_auth';
				$intent['amount']   = (int) round( 100 * (float) $order->get_total() );
				$intent['metadata'] = array(
					'order_id'  => (string) $order->get_id(),
					'order_key' => $order->get_order_key(),
				);
				return $this->response( $intent );
			}
		}
		$this->fail( "No recorded entry {$pair}." );
	}

	/**
	 * An intent that waits on the shopper's authentication.
	 *
	 * @return array
	 */
	private function intent_response(): array {
		return $this->response(
			array(
				'id'            => 'pi_first',
				'status'        => 'requires_action',
				'client_secret' => 'secret',
			)
		);
	}

	/**
	 * Create an unpaid card order for a new customer.
	 *
	 * @return WC_Order
	 */
	private function order(): WC_Order {
		$customer_id = self::factory()->user->create();
		$this->assertIsInt( $customer_id );
		$order = \WC_Helper_Order::create_order( $customer_id );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->save();
		return $order;
	}

	/**
	 * Recorded active US account.
	 *
	 * @return array
	 */
	private function account_response(): array {
		$fixture = wp_json_file_decode( __DIR__ . '/Fixtures/platform-test-drive-account.json', array( 'associative' => true ) );
		return $this->response( $fixture['account'] );
	}

	/**
	 * Wrap the platform JSON in a transport response.
	 *
	 * @param array $body Payload.
	 * @param int   $code Status.
	 * @return array
	 */
	private function response( array $body, int $code = 200 ): array {
		return array(
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
		);
	}

	/**
	 * Return the ordered platform routes without their query strings.
	 *
	 * @return string[]
	 */
	private function routes(): array {
		return array_map( static fn( array $request ): string => explode( '/wcpay/', (string) wp_parse_url( $request['path'], PHP_URL_PATH ) )[1], $this->http->requests );
	}
}
