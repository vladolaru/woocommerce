<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\OrderStatusSync;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAuthorizationsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudOrderTrackingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderAdminActionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderCardInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentDetailsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTerminalPaymentsRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\PlatformRequestClaimGuard;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use WC_Order;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exercise the order claim permissions through provider code and the real API client.
 */
class PlatformRequestClaimTest extends WC_Unit_Test_Case {
	use WithOrderPaymentClaim;

	/** Hook of the account refresh a webhook delivery queues, named as a string so the rows also run at the parent. */
	private const REFRESH_HOOK = 'woocommerce_woopayments_queued_account_refresh';

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
	 * Charge expiry reads the expired intent under the event's claim.
	 */
	public function test_expired_charge_reads_the_intent_under_its_claim(): void {
		$this->store_fresh_account();
		$order = $this->order();
		$order->update_meta_data( '_charge_id', 'ch_expired' );
		$order->update_meta_data( '_intent_id', 'pi_expired' );
		$order->save();
		$this->http->responses = array(
			$this->response(
				array(
					'id'     => 'pi_expired',
					'status' => 'canceled',
				)
			),
		);
		wc_get_container()->get( PaymentIntentEventHandler::class )->process( 'charge.expired', array( 'id' => 'ch_expired' ) );
		$this->assertSame( array( 'intentions/pi_expired' ), $this->routes() );
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
	 * An event with no local token looks up the saved methods and the card under its claim.
	 */
	public function test_recurring_payment_repairs_the_token_under_claim(): void {
		$this->store_fresh_account();
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
	 * @testdox A $_dataName delivery with the stored account due sends no account request and queues one refresh.
	 *
	 * @dataProvider provider_claiming_intent_events
	 *
	 * @param string $pair            Recorded payment intent event, or charge_expired.
	 * @param string $expected_status Order status once the event applies.
	 */
	public function test_due_intent_delivery_reads_no_account_and_queues_one_refresh( string $pair, string $expected_status ): void {
		$this->store_due_account();
		$order = $this->order();
		if ( 'charge_expired' === $pair ) {
			$order->update_meta_data( '_charge_id', 'ch_expired' );
			$order->update_meta_data( '_intent_id', 'pi_expired' );
			$order->save();
			$this->http->responses = array(
				$this->response(
					array(
						'id'     => 'pi_expired',
						'status' => 'canceled',
					)
				),
			);
			$event                 = array(
				'id'   => 'evt_expired',
				'type' => 'charge.expired',
				'data' => array( 'object' => array( 'id' => 'ch_expired' ) ),
			);
		} else {
			$event = $this->recorded_intent_event( $pair, $order );
		}

		$response = $this->deliver( $event );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'charge_expired' === $pair ? array( 'intentions/pi_expired' ) : array(), $this->routes(), 'The delivery reads the stored account and sends no account request.' );
		$this->assertSame( $expected_status, $this->status_of( $order ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * Claiming intent events and the status each leaves.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provider_claiming_intent_events(): array {
		return array(
			'payment_intent.succeeded'                 => array( 'card_checkout_succeeded', 'processing' ),
			'payment_intent.payment_failed'            => array( 'card_declined_failed', 'failed' ),
			'payment_intent.amount_capturable_updated' => array( 'manual_capture_amount_capturable_updated', 'on-hold' ),
			'charge.expired'                           => array( 'charge_expired', 'failed' ),
		);
	}

	/**
	 * @testdox A failed refund for insufficient funds, delivered with the stored account due, writes its note from the stored country and sends no account request.
	 */
	public function test_due_failed_refund_reads_no_account(): void {
		$this->store_due_account();
		$order = $this->paid_order();

		$response = $this->deliver(
			array(
				'id'   => 'evt_refund_failed',
				'type' => 'charge.refund.updated',
				'data' => array(
					'object' => array(
						'id'             => 're_failed',
						'charge'         => 'ch_paid',
						'amount'         => 400,
						'currency'       => 'usd',
						'status'         => 'failed',
						'failure_reason' => 'insufficient_funds',
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->routes() );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, array_filter( $notes, static fn( $note ): bool => false !== strpos( $note->content, 'insufficient funds in your WooPayments balance' ) ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox A dispute on a $_dataName, delivered with the stored account due, sends no account request.
	 *
	 * On a card order, the order's save under the dispute claim reads the account for Sift tracking; on a WooPay order, the
	 * status change reads it for WooPay eligibility. Each row loads only the listener it is about.
	 *
	 * @dataProvider provider_disputed_orders
	 *
	 * @param bool $woopay Whether the order was paid with WooPay, with WooPay on.
	 */
	public function test_due_dispute_reads_no_account( bool $woopay ): void {
		$this->store_due_account();
		$order = $this->paid_order();
		if ( $woopay ) {
			$settings                      = get_option( 'woocommerce_woocommerce_payments_settings' );
			$settings['platform_checkout'] = 'yes';
			update_option( 'woocommerce_woocommerce_payments_settings', $settings );
			$order->update_meta_data( 'is_woopay', '1' );
			$order->save();
			wc_get_container()->get( OrderStatusSync::class )->register();
		} else {
			wc_get_container()->get( WooPaymentsFraudOrderTrackingService::class )->register();
		}

		$response = $this->deliver(
			array(
				'id'   => 'evt_dispute',
				'type' => 'charge.dispute.created',
				'data' => array(
					'object' => array(
						'id'               => 'dp_due',
						'charge'           => 'ch_paid',
						'status'           => 'needs_response',
						'amount'           => 1000,
						'reason'           => 'fraudulent',
						'evidence_details' => array( 'due_by' => 1893456000 ),
					),
				),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 'on-hold', $this->status_of( $order ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * Disputed orders.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provider_disputed_orders(): array {
		return array(
			'card order'   => array( false ),
			'WooPay order' => array( true ),
		);
	}

	/**
	 * @testdox The queued refresh fetches the account once, outside any claim, and its listeners start the pull and rewrite the tier.
	 */
	public function test_queued_refresh_fetches_the_account_once_and_runs_its_listeners(): void {
		$this->store_due_account();
		$order = $this->order();
		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $order ) );
		$account                           = $this->recorded_account();
		$account['has_more_failed_events'] = true;
		$this->answer_account_requests( $this->response( $account ) );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::CONNECTED );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		wc_get_container()->get( WebhookReliabilityService::class )->register();

		$this->run_pending_refresh_job();

		$this->assertSame( array( 'accounts' ), $this->routes() );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
		$this->assertTrue( as_has_scheduled_action( WebhookReliabilityService::WEBHOOK_FETCH_EVENTS_ACTION ), 'The refresh starts the pull of lost pushes.' );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		$stored = get_option( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		$this->assertFalse( $stored['errored'] );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, $stored['fetched'] );
	}

	/**
	 * @testdox A queued refresh that runs after another request refreshed the account sends no request.
	 */
	public function test_queued_refresh_after_another_refresh_sends_no_request(): void {
		$this->store_due_account();
		$order = $this->order();
		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $order ) );
		// Another request refreshed the account; this request still holds the expired copy it read.
		$this->store_fresh_account();

		$this->run_pending_refresh_job();

		$this->assertSame( array(), $this->routes() );
	}

	/**
	 * @testdox A failed queued refresh sends one request and stores an errored entry, after which a due delivery the same day queues nothing.
	 */
	public function test_failed_queued_refresh_stores_an_errored_entry_for_the_day(): void {
		$this->store_due_account();
		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $this->order() ) );
		$this->answer_account_requests( $this->response( array( 'error' => array( 'code' => 'server_error' ) ), 500 ) );

		$this->run_pending_refresh_job();

		$this->assertSame( array( 'accounts' ), $this->routes() );
		$stored = get_option( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		$this->assertTrue( $stored['errored'] );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, $stored['fetched'] );
		$this->reset_container_resolutions();

		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $this->order() ) );

		$this->assertSame( array( 'accounts' ), $this->routes() );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox An event refused by a held claim sends no account request, queues one refresh, and its retry applies it with no request and no second job.
	 */
	public function test_refused_due_event_queues_one_refresh_and_its_retry_reads_no_account(): void {
		$this->store_due_account();
		$order      = $this->order();
		$event      = $this->recorded_intent_event( 'card_checkout_succeeded', $order );
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$token      = PlatformRequestClaimGuard::rival_token( $lock->claim( $order, $vocabulary, 'pi_capture', 'capture' ) );
		$this->assertNotNull( $token );
		try {
			$response = $this->deliver( $event );
		} finally {
			$lock->release( $order, $vocabulary, $token );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 'pending', $this->status_of( $order ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
		$retries = as_get_scheduled_actions(
			array(
				'hook'     => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $retries );
		$this->assertEqualsWithDelta( time(), as_next_scheduled_action( WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION ), 5, 'The store retries a refused delivery at once, as before.' );
		wc_get_container()->get( WooPaymentsAccountService::class )->register();
		wc_get_container()->get( WebhookReliabilityService::class )->register();

		\ActionScheduler::runner()->process_action( (int) reset( $retries ), 'WP Cron' );

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 'processing', $this->status_of( $order ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox An event that claims just after another request released the order sends no account request under its claim.
	 */
	public function test_due_event_after_a_released_claim_reads_no_account(): void {
		$this->store_due_account();
		$order = $this->order();
		$this->with_order_payment_claim( $order, 'capture', static fn() => null );

		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $order ) );

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 'processing', $this->status_of( $order ) );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox A delivery with a fresh stored account sends no account request and queues nothing.
	 */
	public function test_fresh_account_delivery_queues_nothing(): void {
		$this->store_fresh_account();

		$this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $this->order() ) );

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox A retried delivery under Action Scheduler with no stored account sends no request, queues nothing, and applies the event.
	 */
	public function test_retried_delivery_without_an_account_queues_nothing(): void {
		$order = $this->order();
		wc_get_container()->get( WooPaymentsAccountService::class )->handle_action_scheduler_before_execute();

		wc_get_container()->get( EventIngestor::class )->process( $this->recorded_intent_event( 'card_checkout_succeeded', $order ) );

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 'processing', $this->status_of( $order ) );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox account.updated with the stored account due forces one refresh in its delivery and queues none.
	 */
	public function test_account_event_forces_its_refresh_and_queues_none(): void {
		$this->store_due_account();

		$response = $this->deliver(
			array(
				'id'   => 'evt_account',
				'type' => 'account.updated',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'accounts' ), $this->routes() );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox A $_dataName delivery with the stored account due sends no request, replies as before, and queues one refresh.
	 *
	 * @dataProvider provider_deliveries_without_a_claim
	 *
	 * @param array<string,mixed> $event  Event payload.
	 * @param int                 $status Reply status.
	 */
	public function test_due_delivery_without_a_claim_queues_one_refresh( array $event, int $status ): void {
		$this->store_due_account();

		$response = $this->deliver( $event );

		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox Sequential due deliveries leave one pending refresh job.
	 */
	public function test_sequential_due_deliveries_leave_one_pending_refresh(): void {
		$this->store_due_account();

		foreach ( $this->provider_deliveries_without_a_claim() as list( $event ) ) {
			$this->deliver( $event );
		}

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 1, $this->pending_refresh_jobs() );
	}

	/**
	 * Deliveries that take no order claim, with their reply status.
	 *
	 * @return array<string,array{array<string,mixed>,int}>
	 */
	public function provider_deliveries_without_a_claim(): array {
		return array(
			'notification'            => array(
				array(
					'id'   => 'evt_note',
					'type' => 'wcpay.notification',
					'data' => array(
						'title'   => 'Due notification',
						'content' => 'A notification.',
					),
				),
				200,
			),
			'platform check'          => array( array( 'type' => 'test' ), 200 ),
			'mode mismatch'           => array(
				array(
					'id'       => 'evt_live',
					'type'     => 'payment_intent.succeeded',
					'livemode' => true,
					'data'     => array( 'object' => array( 'id' => 'pi_live' ) ),
				),
				200,
			),
			'payment_intent.canceled' => array(
				array(
					'id'   => 'evt_canceled',
					'type' => 'payment_intent.canceled',
					'data' => array( 'object' => array( 'id' => 'pi_canceled' ) ),
				),
				200,
			),
			'invoice.payment_failed'  => array(
				array(
					'id'   => 'evt_invoice',
					'type' => 'invoice.payment_failed',
					'data' => array( 'object' => array( 'id' => 'in_failed' ) ),
				),
				400,
			),
		);
	}

	/**
	 * @testdox A throwing account refresh listener fails the queued refresh after the entry is stored, and not the event.
	 */
	public function test_throwing_refresh_listener_fails_the_job_not_the_event(): void {
		$this->store_due_account();
		add_action(
			'woocommerce_payments_account_refreshed',
			static function (): void {
				throw new \RuntimeException( 'Refresh listener failure.' );
			}
		);
		$order = $this->order();

		$response = $this->deliver( $this->recorded_intent_event( 'card_checkout_succeeded', $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'processing', $this->status_of( $order ) );
		$job = $this->run_pending_refresh_job();
		$this->assertSame( array( 'accounts' ), $this->routes() );
		$this->assertSame( \ActionScheduler_Store::STATUS_FAILED, \ActionScheduler::store()->get_status( $job ) );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, get_option( WooPaymentsDatabaseCache::ACCOUNT_KEY )['fetched'] );
	}

	/**
	 * @testdox With a throwing account option filter, a $_dataName delivery replies as before and the due check logs one line.
	 *
	 * @dataProvider provider_option_filter_throws
	 *
	 * @param bool $reads_account Whether the event reads the account.
	 */
	public function test_throwing_account_option_filter_replies_as_before( bool $reads_account ): void {
		$this->enable_logging();
		$logger = RecordingWcLogger::install();
		add_filter(
			'pre_option_' . WooPaymentsDatabaseCache::ACCOUNT_KEY,
			static function ( $pre ) {
				if ( false === $pre ) {
					throw new \RuntimeException( 'Option filter failure.' );
				}
				return $pre;
			}
		);
		$event = $reads_account
			? $this->recorded_intent_event( 'card_checkout_succeeded', $this->order() )
			: $this->provider_deliveries_without_a_claim()['notification'][0];

		$response = $this->deliver( $event );

		$this->assertSame( $reads_account ? 500 : 200, $response->get_status() );
		$lines = array_filter( $logger->lines, static fn( array $line ): bool => 'error' === $line[0] && 'WooPayments could not queue the account refresh after a webhook delivery.' === $line[1] );
		$this->assertCount( 1, $lines );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * Option filter cases.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provider_option_filter_throws(): array {
		return array(
			'payment_intent.succeeded' => array( true ),
			'notification'             => array( false ),
		);
	}

	/**
	 * @testdox Without a platform connection, a delivery with the stored account due sends no request and queues nothing.
	 */
	public function test_unconnected_due_delivery_queues_nothing(): void {
		$this->store_due_account();
		$this->http->connected = false;

		$this->deliver( $this->provider_deliveries_without_a_claim()['notification'][0] );

		$this->assertSame( array(), $this->routes() );
		$this->assertSame( 0, $this->pending_refresh_jobs() );
	}

	/**
	 * @testdox An in-person payment delivered with the stored account due sends no request, and its receipt carries the stored business details.
	 */
	public function test_due_in_person_receipt_uses_the_stored_business_details(): void {
		$this->store_due_account();
		$order = $this->order();
		$order->set_billing_email( 'ada@example.test' );
		$order->save();
		$recording = wp_json_file_decode( __DIR__ . '/Fixtures/platform-card-present-succeeded-event.json', array( 'associative' => true ) );
		$event     = $recording['events'][0];
		$event['data']['object']['metadata']['order_id']  = (string) $order->get_id();
		$event['data']['object']['metadata']['order_key'] = $order->get_order_key();
		$event['data']['object']['amount']                = (int) round( 100 * (float) $order->get_total() );
		$event['data']['object']['amount_received']       = $event['data']['object']['amount'];
		unset( $event['livemode'] );
		$email  = new class() extends WooPaymentsIppReceiptEmail {
			/** @var array<int,array<string,mixed>> Merchant settings of each receipt. */
			public array $received = array();

			/**
			 * Record the receipt's merchant settings.
			 *
			 * @param WC_Order            $order             Order.
			 * @param array<string,mixed> $merchant_settings Merchant settings.
			 * @param array<string,mixed> $charge            Charge.
			 */
			public function trigger( WC_Order $order, array $merchant_settings, array $charge ): void {
				unset( $order, $charge );
				$this->received[] = $merchant_settings;
			}
		};
		$emails = WC()->mailer()->emails;
		WC()->mailer()->emails[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] = $email;
		try {
			$this->deliver( $event );
		} finally {
			WC()->mailer()->emails = $emails;
		}

		$this->assertSame( array(), $this->routes() );
		$profile = $this->recorded_account()['business_profile'];
		$this->assertCount( 1, $email->received );
		$this->assertSame( $profile['name'], $email->received[0]['business_name'] );
		$this->assertSame( $profile['support_phone'], $email->received[0]['support_info']['phone'] );
	}

	/**
	 * @testdox The queued refresh hook loads in cron requests at the $_dataName tier and is on the switch's list of handled queue hooks.
	 *
	 * @dataProvider provider_working_tiers
	 *
	 * @param string $tier Setup tier.
	 */
	public function test_queued_refresh_hook_loads_in_cron( string $tier ): void {
		$this->assertContains( WooPaymentsAccountService::class, WooPaymentsProvider::get_classes_by_setup_tier()[ $tier ][ RequestTypeUtil::CRON ] );
		$this->assertContains( WooPaymentsAccountService::class, WooPaymentsProvider::get_classes_by_setup_tier()[ $tier ][ RequestTypeUtil::CLI ] );
		remove_all_actions( self::REFRESH_HOOK );
		wc_get_container()->get( WooPaymentsAccountService::class )->register();
		$this->assertNotFalse( has_action( self::REFRESH_HOOK, array( wc_get_container()->get( WooPaymentsAccountService::class ), 'handle_queued_refresh' ) ) );
		$this->assertContains( self::REFRESH_HOOK, ( new \ReflectionClassConstant( PreflightService::class, 'BUILTIN_HANDLED_QUEUE_HOOKS' ) )->getValue() );
	}

	/**
	 * The tiers the built-in WooPayments takes payments at.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_working_tiers(): array {
		return array(
			'connected' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'    => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * Deliver an event through the webhook REST route.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return WP_REST_Response
	 */
	private function deliver( array $event ): WP_REST_Response {
		/** @var WP_REST_Request<array<string,mixed>> $request */
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_body_params( $event );

		return wc_get_container()->get( WebhookRestController::class )->handle_webhook( $request );
	}

	/**
	 * Count the pending queued account refreshes.
	 *
	 * @return int
	 */
	private function pending_refresh_jobs(): int {
		return count(
			as_get_scheduled_actions(
				array(
					'hook'     => self::REFRESH_HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	/**
	 * Run the one pending queued account refresh as the queue runner does, in a request that loaded the account service.
	 *
	 * @return int The action's ID.
	 */
	private function run_pending_refresh_job(): int {
		$ids = as_get_scheduled_actions(
			array(
				'hook'     => self::REFRESH_HOOK,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			),
			'ids'
		);
		$this->assertCount( 1, $ids );
		$this->http->requests = array();
		wc_get_container()->get( WooPaymentsAccountService::class )->register();

		\ActionScheduler::runner()->process_action( (int) reset( $ids ), 'WP Cron' );

		return (int) reset( $ids );
	}

	/**
	 * A recorded payment intent event for the order, at its total.
	 *
	 * @param string   $pair  Recorded entry in Fixtures/platform-payment-intent-events.json.
	 * @param WC_Order $order Order the event names.
	 * @return array<string,mixed>
	 */
	private function recorded_intent_event( string $pair, WC_Order $order ): array {
		$event  = RecordedPlatformEvents::body( 'platform-payment-intent-events.json', $pair );
		$intent = $event['data']['object'];
		$order->update_meta_data( '_payment_method_id', (string) ( $intent['last_payment_error']['payment_method']['id'] ?? $intent['payment_method'] ) );
		$order->set_total( sprintf( '%.2f', ( (int) $intent['amount'] ) / 100 ) );
		$order->save();
		$event['data']['object']['metadata']['order_id']  = (string) $order->get_id();
		$event['data']['object']['metadata']['order_key'] = $order->get_order_key();

		return $event;
	}

	/**
	 * Create a processing card order paid with intent pi_paid and charge ch_paid.
	 *
	 * @return WC_Order
	 */
	private function paid_order(): WC_Order {
		$order = $this->order();
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_paid' );
		$order->update_meta_data( '_intent_id', 'pi_paid' );
		$order->update_meta_data( '_charge_id', 'ch_paid' );
		$order->update_meta_data( '_intention_status', 'succeeded' );
		$order->save();

		return $order;
	}

	/**
	 * The recorded active US account.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded_account(): array {
		return wp_json_file_decode( __DIR__ . '/Fixtures/platform-test-drive-account.json', array( 'associative' => true ) )['account'];
	}

	/**
	 * Answer every account request with this response, whenever it comes.
	 *
	 * @param array $response Transport response.
	 */
	private function answer_account_requests( array $response ): void {
		$http = $this->http;
		if ( property_exists( $http, 'account' ) ) {
			$http->account = $response;
		}
	}

	/**
	 * Read the order's stored status.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function status_of( WC_Order $order ): string {
		$stored = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $stored );

		return $stored->get_status();
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
	 * Store the recorded US account, fetched two days ago, and answer any account request with it, whenever it comes.
	 */
	private function store_due_account(): void {
		$this->store_expired_account();
		$this->answer_account_requests( $this->account_response() );
	}

	/**
	 * Store the recorded US account, fetched now, so a read serves it.
	 */
	private function store_fresh_account(): void {
		$this->store_expired_account();
		$stored            = get_option( WooPaymentsDatabaseCache::ACCOUNT_KEY );
		$stored['fetched'] = time();
		update_option( WooPaymentsDatabaseCache::ACCOUNT_KEY, $stored, false );
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
