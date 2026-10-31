<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingActionSchedulerService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPaymentsEventHandlerTestTrait;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\RefundEventHandler;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the WebhookRestController class.
 */
class WebhookRestControllerTest extends WC_REST_Unit_Test_Case {

	use ProviderTextLogAssertions;
	use WooPaymentsEventHandlerTestTrait;

	/**
	 * Scheduler the controller's reliability service schedules retries on.
	 *
	 * @var RecordingActionSchedulerService
	 */
	private RecordingActionSchedulerService $scheduler;

	/**
	 * Reliability service the controller hands failed pushes to.
	 *
	 * @var WebhookReliabilityService
	 */
	private WebhookReliabilityService $reliability_service;

	/**
	 * The System Under Test.
	 *
	 * @var WebhookRestController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WebhookRestController::class );
		$this->remove_rest_hook();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_rest_hook();
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox The controller registers POST /wc/v3/payments/webhook when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_wc_v3_payments_webhook_when_builtin_owns_runtime(): void {
		$this->sut->register();
		// The controller registers routes on the REST API initialization hook in production.
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
		do_action( 'rest_api_init' );

		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/wc/v3/payments/webhook', $routes );
		$this->assertRouteHasPostMethod( $routes['/wc/v3/payments/webhook'] );
	}

	/**
	 * @testdox The webhook route permission callback lets only manage_woocommerce users in ($role).
	 *
	 * Real platform delivery uses the authenticated reliability pull path, not this direct route.
	 *
	 * @dataProvider provider_permission_callback
	 *
	 * @param string $role     Role of the current user, or '' for no user.
	 * @param bool   $expected Whether the request passes.
	 */
	public function test_permission_callback_allows_only_manage_woocommerce_users( string $role, bool $expected ): void {
		wp_set_current_user( '' === $role ? 0 : $this->factory->user->create( array( 'role' => $role ) ) );

		$permission_callback = $this->get_registered_permission_callback();

		$this->assertSame( $expected, (bool) call_user_func( $permission_callback, $this->create_post_request( array( 'type' => 'customer.created' ) ) ) );
	}

	/**
	 * Current users and whether the webhook route lets them in.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function provider_permission_callback(): array {
		return array(
			'no user'       => array( '', false ),
			'administrator' => array( 'administrator', true ),
		);
	}

	/**
	 * @testdox Successful webhook processing returns the WooPayments success envelope.
	 */
	public function test_success_response_matches_woopayments_envelope(): void {
		$payload    = array( 'type' => 'customer.created' );
		$ingestor   = new class() extends EventIngestor {
				/**
				 * Processed payloads.
				 *
				 * @var array<int,array<string,mixed>>
				 */
			public array $processed_payloads = array();

				/**
				 * Process a payload.
				 *
				 * @param array<string,mixed> $event Event payload.
				 */
			public function process( array $event ): void {
				$this->processed_payloads[] = $event;
			}
		};
		$controller = $this->create_controller_with_ingestor( $ingestor );
		$request    = $this->create_post_request( $payload );
		$response   = $controller->handle_webhook( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame( array( $payload ), $ingestor->processed_payloads, 'The delivered payload reaches the ingestor once.' );
	}

	/**
	 * @testdox Bad webhook payloads return the WooPayments bad_request envelope.
	 */
	public function test_bad_payload_returns_bad_request_envelope(): void {
		$logger     = new RecordingWcLogger();
		$controller = $this->create_controller_with_ingestor(
			new class() extends EventIngestor {
				/**
				 * Process a payload.
				 *
				 * @param array<string,mixed> $event Event payload.
				 */
				public function process( array $event ): void {
					throw new InvalidArgumentException( 'bad payload' );
				}
			},
			$logger
		);

		$response = $controller->handle_webhook( $this->create_post_request( array( 'type' => 'bad' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'result' => 'bad_request' ), $response->get_data() );
		$this->assertCount( 1, $logger->get_errors() );
		$this->assertSame( 'woopayments', $logger->get_errors()[0][2] );
		$this->assertStringContainsString( 'bad payload', $logger->get_errors()[0][1] );
	}

	/**
	 * @testdox Processing exceptions return the WooPayments error envelope.
	 */
	public function test_processing_exception_returns_error_envelope(): void {
		$logger     = new RecordingWcLogger();
		$controller = $this->create_controller_with_ingestor(
			new class() extends EventIngestor {
				/**
				 * Process a payload.
				 *
				 * @param array<string,mixed> $event Event payload.
				 */
				public function process( array $event ): void {
					throw new RuntimeException( 'server failed' );
				}
			},
			$logger
		);

		$response = $controller->handle_webhook( $this->create_post_request( array( 'type' => 'bad' ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'result' => 'error' ), $response->get_data() );
		$this->assertCount( 1, $logger->get_errors() );
		$this->assertSame( 'woopayments', $logger->get_errors()[0][2] );
		// Processing calls the platform, so a failure's message is left out; its class names it.
		$this->assertSame( RuntimeException::class, $this->get_logged_context_at_level( $logger, 'error' )['exception'] );
	}

	/**
	 * @testdox A failed $event_type push gets the $expected_status reply and is kept and scheduled to run again only when its failure is retried ($kept).
	 *
	 * A passing failure of a retried type, a refund event included, is kept and retried, since the platform counts the error
	 * reply as delivered; a fraud warning refused by the order payment lock is retried although the type gets one attempt
	 * after other failures, and a malformed event is not kept.
	 *
	 * @dataProvider provider_failed_push_outcomes
	 *
	 * @param string   $event_type        Event type.
	 * @param callable $failure           Builds the ingestor's failure from an order ID.
	 * @param int      $expected_status   Reply status.
	 * @param string   $expected_result   Reply envelope result.
	 * @param bool     $kept              Whether the event is kept and one retry scheduled.
	 */
	public function test_failed_push_outcomes( string $event_type, callable $failure, int $expected_status, string $expected_result, bool $kept ): void {
		$order      = wc_create_order();
		$event      = array(
			'id'   => 'evt_failed_push',
			'type' => $event_type,
		);
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( $failure( $order->get_id() ) ) );

		$response = $controller->handle_webhook( $this->create_post_request( $event ) );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( array( 'result' => $expected_result ), $response->get_data() );
		$this->assertSame( $kept ? $event : null, ( new FailedEventTransients() )->get_event( 'evt_failed_push' ) );
		$this->assertSame(
			$kept ? array(
				array(
					'hook' => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => 'evt_failed_push' ),
				),
			) : array(),
			$this->scheduler->scheduled_jobs
		);
	}

	/**
	 * Failed pushes and what happens to them.
	 *
	 * @return array<string,array{0:string,1:callable,2:int,3:string,4:bool}>
	 */
	public function provider_failed_push_outcomes(): array {
		return array(
			'retried type, passing failure' => array(
				'payment_intent.succeeded',
				static function ( int $order_id ): \Throwable {
					unset( $order_id );
					return new RuntimeException( 'platform call failed' );
				},
				500,
				'error',
				true,
			),
			'refund event, passing failure' => array(
				'charge.refunded',
				static function ( int $order_id ): \Throwable {
					unset( $order_id );
					return new RuntimeException( 'database error' );
				},
				500,
				'error',
				true,
			),
			'fraud warning, lock refusal'   => array(
				'radar.early_fraud_warning.created',
				static fn( int $order_id ): \Throwable => new OrderPaymentLockRefusedException( $order_id, 'early fraud warning webhook' ),
				500,
				'error',
				true,
			),
			'malformed event'               => array(
				'payment_intent.succeeded',
				static function ( int $order_id ): \Throwable {
					unset( $order_id );
					return new InvalidArgumentException( 'malformed event' );
				},
				400,
				'bad_request',
				false,
			),
		);
	}

	/**
	 * @testdox A $event_type push gets the $expected_status reply, is kept for a retry only when it fails for a passing reason ($kept) and logs "$expected_log".
	 *
	 * Through the webhook route with the real ingestor and handlers. Client 11.1.0 refuses a refund or dispute event with a
	 * field that is not set, a dispute whose charge no order holds, a negative charge amount or a refund above the order
	 * total, and an unknown refund update status with Invalid_Webhook_Data_Exception
	 * (class-wc-payments-webhook-processing-service.php:361, :717, :933, :1131), which its webhook answers with 400 and the
	 * reason logged (class-wc-rest-payments-webhook-controller.php:81-83); a refund whose charge no order holds is answered
	 * 500 (webhook processing service :317, :1111), which the store retries. Its field reader checks only that a field is
	 * set (:933), and it takes a negative refund amount: the dispute amount that is not a number and the negative refund
	 * amount are refused here only, stricter than client 11.1.0.
	 *
	 * @dataProvider provider_refund_and_dispute_event_failures
	 *
	 * @param string              $event_type      Event type.
	 * @param array<string,mixed> $event_object    Event object; charge ch_route is the order's payment.
	 * @param int                 $expected_status Reply status.
	 * @param bool                $kept            Whether the event is kept and one retry scheduled.
	 * @param string              $expected_log    The one error line the push logs.
	 */
	public function test_refund_and_dispute_event_failures_through_the_webhook_route( string $event_type, array $event_object, int $expected_status, bool $kept, string $expected_log ): void {
		$order = wc_create_order();
		$order->update_meta_data( '_charge_id', 'ch_route' );
		$order->save();
		$logger     = new RecordingWcLogger();
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ), $logger );
		$event      = array(
			'id'   => 'evt_route',
			'type' => $event_type,
			'data' => array( 'object' => $event_object ),
		);

		$response = $controller->handle_webhook( $this->create_post_request( $event ) );

		$this->assertSame( $expected_status, $response->get_status() );
		$this->assertSame( array( 'result' => 400 === $expected_status ? 'bad_request' : 'error' ), $response->get_data() );
		$this->assertSame( $kept ? $event : null, ( new FailedEventTransients() )->get_event( 'evt_route' ) );
		$this->assertSame(
			$kept ? array(
				array(
					'hook' => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => 'evt_route' ),
				),
			) : array(),
			$this->scheduler->scheduled_jobs
		);
		$this->assertSame( array( $expected_log ), array_column( $logger->get_errors(), 1 ) );
	}

	/**
	 * Refund and dispute events that fail, one per refusal site of the handlers, and a refund whose charge no order holds.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:int,3:bool,4:string}>
	 */
	public function provider_refund_and_dispute_event_failures(): array {
		$dispute       = array(
			'id'               => 'du_route',
			'charge'           => 'ch_route',
			'amount'           => 5000,
			'reason'           => 'fraudulent',
			'status'           => 'needs_response',
			'evidence_details' => array( 'due_by' => 1782864000 ),
		);
		$refund_update = array(
			'id'       => 're_route',
			'charge'   => 'ch_route',
			'amount'   => 400,
			'currency' => 'usd',
			'status'   => 'failed',
		);
		$refunded      = array(
			'id'       => 'ch_route',
			'status'   => 'succeeded',
			'captured' => true,
			'amount'   => 1000,
			'currency' => 'usd',
			'refunds'  => array(
				'data' => array(
					array(
						'id'     => 're_route',
						'amount' => -400,
					),
				),
			),
		);
		$no_amount     = $refund_update;
		unset( $no_amount['amount'] );

		return array(
			'dispute amount not a number'         => array(
				'charge.dispute.created',
				array_merge( $dispute, array( 'amount' => 'lots' ) ),
				400,
				false,
				'Failed processing event evt_route. Reason: Expected numeric dispute webhook property: amount',
			),
			'dispute evidence details not a list' => array(
				'charge.dispute.created',
				array_merge( $dispute, array( 'evidence_details' => 'soon' ) ),
				400,
				false,
				'Failed processing event evt_route. Reason: Expected array dispute webhook property: evidence_details',
			),
			'dispute on a charge no order holds'  => array(
				'charge.dispute.closed',
				array_merge(
					$dispute,
					array(
						'charge' => 'ch_no_order',
						'status' => 'won',
					)
				),
				400,
				false,
				'Failed processing event evt_route. Reason: Could not find WooPayments order via disputed charge ID: ch_no_order',
			),
			'refund update without an amount'     => array(
				'charge.refund.updated',
				$no_amount,
				400,
				false,
				'Failed processing event evt_route. Reason: WooPayments refund event is missing required field: amount',
			),
			'refund with a negative amount'       => array(
				'charge.refunded',
				$refunded,
				400,
				false,
				'Failed processing event evt_route. Reason: The refund amount is not valid for charge ID: ch_route',
			),
			'refund update with another status'   => array(
				'charge.refund.updated',
				array_merge( $refund_update, array( 'status' => 'requires_action' ) ),
				400,
				false,
				'Failed processing event evt_route. Reason: Invalid refund update status: requires_action',
			),
			'refund on a charge no order holds'   => array(
				'charge.refund.updated',
				array_merge( $refund_update, array( 'charge' => 'ch_no_order' ) ),
				500,
				true,
				'Failed processing a WooPayments webhook event.',
			),
		);
	}

	/**
	 * @testdox A charge.refunded push for a charge no order holds is answered with the outcome its metadata order decides: acknowledged without a retry ($acknowledged).
	 *
	 * The event is the platform's recorded delivery (Fixtures/platform-charge-dispute-events.json, card_charge_refunded_in_full)
	 * with the charge's metadata changed. A charge whose metadata order was deleted is answered 200 with one info line and no
	 * retry, since no retry can find that order; a charge naming no order, or an order with another key, keeps the 500 and one
	 * scheduled retry (client 11.1.0 answers a charge it finds no order for with 500: class-wc-payments-webhook-processing-service.php:317, :1111).
	 *
	 * @dataProvider provider_refund_event_metadata_orders
	 *
	 * @param callable $change_metadata Builds the charge's order metadata from an existing order.
	 * @param bool     $acknowledged    Whether the push is answered as handled.
	 */
	public function test_refund_event_for_a_charge_no_order_holds_through_the_webhook_route( callable $change_metadata, bool $acknowledged ): void {
		// The recorded event is a test-mode delivery (livemode false), so the store runs in test mode, as the recording account did.
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event                               = RecordedPlatformEvents::body( 'platform-charge-dispute-events.json', 'card_charge_refunded_in_full' );
		$event['data']['object']['metadata'] = array_merge( $event['data']['object']['metadata'], $change_metadata( wc_create_order() ) );
		$logger                              = new RecordingWcLogger();
		$controller                          = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ), $logger );

		$response = $controller->handle_webhook( $this->create_post_request( $event ) );

		$this->assertSame( $acknowledged ? 200 : 500, $response->get_status() );
		$this->assertSame( array( 'result' => $acknowledged ? 'success' : 'error' ), $response->get_data() );
		$this->assertSame( $acknowledged ? null : $event, ( new FailedEventTransients() )->get_event( $event['id'] ) );
		$this->assertSame(
			$acknowledged ? array() : array(
				array(
					'hook' => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => $event['id'] ),
				),
			),
			$this->scheduler->scheduled_jobs
		);
		$this->assertCount( $acknowledged ? 1 : 0, array_filter( $logger->lines, static fn( array $line ): bool => 'info' === $line[0] && 'woopayments' === $line[2] && str_contains( $line[1], 'no longer exists' ) ) );
		$this->assertSame( $acknowledged ? array() : array( 'Failed processing a WooPayments webhook event.' ), array_column( $logger->get_errors(), 1 ) );
	}

	/**
	 * Order metadata a refunded charge can carry when no order holds the charge.
	 *
	 * @return array<string,array{0:callable,1:bool}>
	 */
	public function provider_refund_event_metadata_orders(): array {
		return array(
			'order deleted'          => array(
				static function ( WC_Order $order ): array {
					$order_id = $order->get_id();
					$order->delete( true );
					return array(
						'order_id'  => (string) $order_id,
						'order_key' => 'wc_order_deleted',
					);
				},
				true,
			),
			'no order ID'            => array(
				static fn(): array => array(
					'order_id'  => '',
					'order_key' => '',
				),
				false,
			),
			'order with another key' => array(
				static fn( WC_Order $order ): array => array(
					'order_id'  => (string) $order->get_id(),
					'order_key' => 'wc_order_another_site',
				),
				false,
			),
		);
	}

	/**
	 * The account event bodies the platform sends, each the bytes its sender's wp_json_encode() produces.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provider_platform_account_event_bodies(): array {
		return array(
			// wpcom service/class-admin-service.php:1190 (also class-payment-rate-limit-service.php:208, :396,
			// service/class-account-details-service.php:332, and MissionControl's class-accounts-controller.php:1179 when card
			// testing prevention is turned on), sent through remote-site/class-remote-site-client.php:85.
			'account.updated, type only'   => array( '{"type":"account.updated"}' ),
			// A Stripe account event forwarded by event-handler/class-account-event-handler.php:724-728 with
			// stripe/class-event.php:203-216 get_body_for_forwarding( false ): no data.
			'account.updated, from Stripe' => array( '{"id":"evt_1UR1c0qForwarded","type":"account.updated","livemode":false,"account":"acct_1UR1MzJIIUYbFCYM"}' ),
			// service/class-account-deletion-service.php:873-875, sent during the account deletion.
			'account.deleted, type only'   => array( '{"type":"account.deleted"}' ),
		);
	}

	/**
	 * @testdox The platform's $body through the webhook route answers 200, refreshes the account and clears the saved payment method caches.
	 *
	 * Client 11.1.0 reads only the type of either event (includes/class-wc-payments-webhook-processing-service.php:205-221):
	 * account.updated refreshes and clears the cached payment methods; account.deleted also resets the store's account
	 * state and clears the NOX profile and lock, on every delivery, with no account check. The deletion runs while the
	 * store's own delete call waits, after it read the account as gone, so no account ID is stored.
	 *
	 * @dataProvider provider_platform_account_event_bodies
	 *
	 * @param string $body Request body.
	 */
	public function test_platform_account_events_through_the_webhook_route( string $body ): void {
		global $wpdb, $wp_rest_server;

		$settings = array(
			'enabled'                        => 'yes',
			'test_mode'                      => 'yes',
			'upe_enabled_payment_method_ids' => array( 'card', 'ideal' ),
		);
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
		update_option( WooPaymentsService::NOX_PROFILE_OPTION_KEY, array( 'onboarding' => array() ) );
		update_option( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY, 1 );
		update_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments', 'yes' );
		$user_id = $this->factory->user->create( array( 'role' => 'customer' ) );
		update_user_meta( $user_id, '_wcpay_payment_methods', array( 'cus_route' => array() ) );
		update_option( 'wcpay_pm_cus_route', array( 'data' => array() ), false );
		$account_service = $this->create_account_service_counting_refreshes();
		$handler         = new WooPaymentsAccountEventHandler();
		$handler->init( $account_service, new WooPaymentsTokenService() );
		$controller = $this->create_controller_with_ingestor(
			$this->build_event_ingestor(
				wc_get_container()->get( OrderPaymentLifecycleService::class ),
				new class() extends Client {},
				$this->create_dispute_event_handler( new class() extends Client {} ),
				wc_get_container()->get( RefundEventHandler::class ),
				$handler,
				$this->create_notification_event_handler()
			)
		);
		// The platform's Jetpack-signed request runs as the connection owner, an administrator.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = new WP_REST_Server();
		$controller->register_routes();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );

		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame( 1, $account_service->refreshes, 'The account is read again from the platform.' );
		$this->assertSame( '', get_user_meta( $user_id, '_wcpay_payment_methods', true ), 'The cached saved payment methods are cleared.' );
		$this->assertFalse( get_option( 'wcpay_pm_cus_route' ) );
		$stored = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION );
		if ( '{"type":"account.deleted"}' !== $body ) {
			$this->assertSame( $settings, $stored );
			$this->assertSame( array( 'onboarding' => array() ), get_option( WooPaymentsService::NOX_PROFILE_OPTION_KEY ) );
			return;
		}

		$this->assertSame( 'no', $stored['enabled'] );
		$this->assertSame( 'no', $stored['test_mode'] );
		$this->assertSame( array( 'card' ), $stored['upe_enabled_payment_method_ids'] );
		$this->assertFalse( get_option( 'woocommerce_admin_pes_incentive_woopayments_store_had_woopayments' ) );
		$this->assertFalse( get_option( WooPaymentsService::NOX_PROFILE_OPTION_KEY ) );
		$this->assertFalse( get_option( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY ) );
	}

	/**
	 * @testdox The platform's {"type":"test"} delivery check answers 200 and fires both delivery hooks.
	 *
	 * The platform sends it with no ID and no object from Admin_Service::send_webhook() (wpcom
	 * service/class-admin-service.php:1211-1216), for the support "send webhook" tool and its blog switch check
	 * (:1522-1531). Client 11.1.0 has no case for the type, fires both hooks and answers 200
	 * (includes/class-wc-payments-webhook-processing-service.php:180-255).
	 */
	public function test_platform_test_delivery_is_acknowledged(): void {
		global $wp_rest_server;

		$fired = array();
		foreach ( array( 'woocommerce_payments_before_webhook_delivery', 'woocommerce_payments_after_webhook_delivery' ) as $hook ) {
			add_action(
				$hook,
				static function ( $event_type ) use ( &$fired, $hook ) {
					$fired[] = array( $hook, $event_type );
				}
			);
		}
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = new WP_REST_Server();
		$controller->register_routes();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"type":"test"}' );

		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'result' => 'success' ), $response->get_data() );
		$this->assertSame(
			array(
				array( 'woocommerce_payments_before_webhook_delivery', 'test' ),
				array( 'woocommerce_payments_after_webhook_delivery', 'test' ),
			),
			$fired
		);
	}

	/**
	 * @testdox A $event_type push without data.object, whose handler needs one, is still refused with 400.
	 *
	 * Client 11.1.0 refuses a refund or dispute event whose object fields are missing (class-wc-payments-webhook-processing-service.php:933).
	 *
	 * @testWith ["charge.refunded"]
	 *           ["charge.dispute.created"]
	 *
	 * @param string $event_type Event type.
	 */
	public function test_object_less_event_of_a_type_that_needs_an_object_is_refused( string $event_type ): void {
		global $wp_rest_server;

		// A test-mode store, so the test-mode event is not skipped as another mode's.
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'test_mode' => 'yes' ) );
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		$wp_rest_server = new WP_REST_Server();
		$controller->register_routes();
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'id'       => 'evt_no_object',
					'type'     => $event_type,
					'livemode' => false,
				)
			)
		);

		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'result' => 'bad_request' ), $response->get_data() );
	}

	/**
	 * @testdox A charge.dispute.created push refused while another dispute event holds the order payment lock is kept, and its retry puts the order on hold with the dispute note and the open dispute.
	 *
	 * The platform forwards each event of a dispute from its own job, so created and funds_withdrawn can arrive together.
	 * Client 11.1.0 takes no lock in its dispute handlers and applies both (class-wc-payments-order-service.php:585-700).
	 */
	public function test_lock_refused_dispute_created_push_is_retried_and_applied(): void {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_lock_retry' );
		$order->update_meta_data( '_intent_id', 'pi_lock_retry' );
		$order->update_meta_data( '_charge_id', 'ch_lock_retry' );
		$order->save();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ) );
		$event      = array(
			'id'   => 'evt_dispute_lock_retry',
			'type' => 'charge.dispute.created',
			'data' => array(
				'object' => array(
					'id'               => 'du_lock_retry',
					'charge'           => 'ch_lock_retry',
					'amount'           => 5000,
					'reason'           => 'fraudulent',
					'status'           => 'needs_response',
					'evidence_details' => array( 'due_by' => 1782864000 ),
				),
			),
		);
		$holder     = $lock->claim( $order, $vocabulary, 'dispute_webhook_du_lock_retry', 'dispute webhook' );
		$this->assertNotNull( $holder );

		try {
			$response = $controller->handle_webhook( $this->create_post_request( $event ) );
		} finally {
			$lock->release( $order, $vocabulary, $holder );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( $event, ( new FailedEventTransients() )->get_event( 'evt_dispute_lock_retry' ) );
		$this->assertSame(
			array(
				array(
					'hook' => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => 'evt_dispute_lock_retry' ),
				),
			),
			$this->scheduler->scheduled_jobs
		);
		$lock_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertSame( array(), $lock_notes, 'Nothing is noted while a retry is due.' );

		$this->reliability_service->process_event( 'evt_dispute_lock_retry' );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( array( 'du_lock_retry' ), $order->get_meta( '_wcpay_open_dispute_ids', true ) );
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'Payment has been disputed' )
		);
		$this->assertCount( 1, $notes );
		$this->assertNull( ( new FailedEventTransients() )->get_event( 'evt_dispute_lock_retry' ) );
	}

	/**
	 * @testdox A charge.dispute.funds_withdrawn push refused while the dispute's created event holds the order payment lock is kept, and its retry writes the funds note once.
	 *
	 * Stripe sends charge.dispute.created with charge.dispute.funds_withdrawn, and the platform forwards each from its own
	 * job. Client 11.1.0 takes no lock in its dispute handlers and writes both notes: the dispute note
	 * (class-wc-payments-order-service.php:585-700) and the funds note (class-wc-payments-webhook-processing-service.php:815-848).
	 */
	public function test_lock_refused_funds_withdrawn_push_is_retried_and_writes_its_note(): void {
		$order = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_funds_retry' );
		$order->update_meta_data( '_intent_id', 'pi_funds_retry' );
		$order->update_meta_data( '_charge_id', 'ch_funds_retry' );
		$order->save();
		$lock       = wc_get_container()->get( OrderPaymentLock::class );
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( EventIngestor::class ) );
		$event      = array(
			'id'   => 'evt_funds_lock_retry',
			'type' => 'charge.dispute.funds_withdrawn',
			'data' => array(
				'object' => array(
					'id'     => 'du_funds_retry',
					'charge' => 'ch_funds_retry',
					'status' => 'needs_response',
				),
			),
		);
		$holder     = $lock->claim( $order, $vocabulary, 'dispute_webhook_du_funds_retry', 'dispute webhook' );
		$this->assertNotNull( $holder );

		try {
			$response = $controller->handle_webhook( $this->create_post_request( $event ) );
		} finally {
			$lock->release( $order, $vocabulary, $holder );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( $event, ( new FailedEventTransients() )->get_event( 'evt_funds_lock_retry' ) );
		$this->assertSame(
			array(
				array(
					'hook' => WebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => 'evt_funds_lock_retry' ),
				),
			),
			$this->scheduler->scheduled_jobs
		);
		$this->assertSame( array(), self::order_notes_containing( $order, 'kept the order locked' ), 'Nothing is noted while a retry is due.' );

		$this->reliability_service->process_event( 'evt_funds_lock_retry' );
		$this->reliability_service->process_event( 'evt_funds_lock_retry' );

		$this->assertCount( 1, self::order_notes_containing( $order, 'Payment dispute and fees have been deducted from your next payout' ) );
		$this->assertNull( ( new FailedEventTransients() )->get_event( 'evt_funds_lock_retry' ) );
	}

	/**
	 * Get an order's notes that contain a phrase.
	 *
	 * @param \WC_Order $order  Order.
	 * @param string    $phrase Phrase.
	 * @return array<int,object>
	 */
	private static function order_notes_containing( \WC_Order $order, string $phrase ): array {
		return array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( $note->content, $phrase )
			)
		);
	}

	/**
	 * @testdox A failed event is logged with the platform error's status and code, never its message; so is a refusal that wraps one.
	 * @testWith [false, "Failed processing a WooPayments webhook event."]
	 *           [true, "Failed processing event evt_platform_error."]
	 *
	 * @param bool   $refused  Whether the ingestor refuses the event (an InvalidArgumentException wrapping the platform error).
	 * @param string $expected Expected line.
	 */
	public function test_processing_failure_log_leaves_out_platform_text( bool $refused, string $expected ): void {
		$failure    = $refused ? new InvalidArgumentException( self::make_provider_error()->getMessage(), 0, self::make_provider_error() ) : self::make_provider_error();
		$logger     = new RecordingWcLogger();
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( $failure ), $logger );

		$controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_platform_error',
					'type' => 'payment_intent.succeeded',
				)
			)
		);

		$context = $this->get_logged_context( $logger, $expected );
		$this->assertSame( array( 404, 'resource_missing' ), array( $context['http_status'], $context['error_code'] ) );
		$this->assert_log_holds_no_provider_text( $logger );
	}

	/**
	 * @testdox Without the Stripe Billing module, an invoice event is answered with 400 and exactly one error line, from the route.
	 *
	 * Client 11.1.0: the refusing handler logs nothing; the webhook controller logs the exception once
	 * (`class-wc-rest-payments-webhook-controller.php:81-83`). The line names the event, as Stripe Billing money invariant 3 asks.
	 */
	public function test_invoice_event_refused_without_the_stripe_billing_module_logs_one_line(): void {
		$module = $this->getMockBuilder( StripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( StripeBillingModule::class, $module );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$logger     = RecordingWcLogger::install();
		$controller = new WebhookRestController();
		$controller->init(
			wc_get_container()->get( EventIngestor::class ),
			wc_get_container()->get( WebhookReliabilityService::class )
		);

		$response = $controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'       => 'evt_invoice_controller',
					'type'     => 'invoice.paid',
					'livemode' => false,
					'data'     => array( 'object' => array( 'id' => 'in_123' ) ),
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array( 'result' => 'bad_request' ), $response->get_data() );
		$this->assertSame( array( array( 'error', 'Failed processing event evt_invoice_controller. Reason: Cannot find subscription for the incoming "invoice.paid" event.', 'woopayments' ) ), $logger->get_errors() );
	}

	/**
	 * Register the webhook route and return its actual permission callback.
	 *
	 * Reads the callback off the live route registration so the assertion exercises the
	 * gate the controller really wires up rather than a re-declared copy.
	 *
	 * @return callable
	 */
	private function get_registered_permission_callback(): callable {
		$this->sut->register_routes();

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/wc/v3/payments/webhook', $routes );

		foreach ( $routes['/wc/v3/payments/webhook'] as $handler ) {
			if ( isset( $handler['permission_callback'] ) && is_callable( $handler['permission_callback'] ) ) {
				return $handler['permission_callback'];
			}
		}

		$this->fail( 'Route does not expose a callable permission callback.' );
	}

	/**
	 * Remove the controller REST hook.
	 */
	private function remove_rest_hook(): void {
		remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
	}

	/**
	 * Create a real account service whose platform account read answers the recorded test-drive account and is counted.
	 *
	 * @return WooPaymentsAccountService&object{refreshes:int}
	 */
	private function create_account_service_counting_refreshes(): WooPaymentsAccountService {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture         = json_decode( (string) file_get_contents( __DIR__ . '/../Fixtures/platform-test-drive-account.json' ), true );
		$account_service = new class( $fixture['account'] ) extends WooPaymentsAccountService {
			/**
			 * Number of platform account reads.
			 *
			 * @var int
			 */
			public int $refreshes = 0;

			/**
			 * Recorded platform account.
			 *
			 * @var array<string,mixed>
			 */
			private array $account;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account Recorded platform account.
			 */
			public function __construct( array $account ) {
				$this->account = $account;
			}

			/**
			 * Read the account again, counting the reads.
			 *
			 * @return array<string,mixed>
			 */
			public function refresh_account_data(): array {
				++$this->refreshes;

				return $this->account;
			}
		};
		$database_cache  = new WooPaymentsDatabaseCache();
		$database_cache->init( new LegacyProxy() );
		$account_service->init( new LegacyProxy(), $database_cache );

		return $account_service;
	}

	/**
	 * Create a controller with a supplied ingestor.
	 *
	 * @param EventIngestor          $ingestor Ingestor test double.
	 * @param RecordingWcLogger|null $logger   Optional logger that wc_get_logger() returns for the rest of the test.
	 * @return WebhookRestController
	 */
	private function create_controller_with_ingestor( EventIngestor $ingestor, ?RecordingWcLogger $logger = null ): WebhookRestController {
		if ( null !== $logger ) {
			add_filter( 'woocommerce_logging_class', static fn() => $logger );
		}

		$this->scheduler           = new RecordingActionSchedulerService();
		$reliability_service       = new WebhookReliabilityService();
		$this->reliability_service = $reliability_service;
		$reliability_service->init(
			$this->scheduler,
			new StaticFailedEventsApiClient(),
			$ingestor
		);

		$controller = new WebhookRestController();
		$controller->init( $ingestor, $reliability_service );

		return $controller;
	}

	/**
	 * Create a POST request with body params.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @return WP_REST_Request
	 */
	private function create_post_request( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/webhook' );
		$request->set_body_params( $payload );

		return $request;
	}

	/**
	 * Assert a route handler accepts POST.
	 *
	 * @param array<int,array<string,mixed>> $route_handlers Route handlers.
	 */
	private function assertRouteHasPostMethod( array $route_handlers ): void {
		foreach ( $route_handlers as $handler ) {
			if ( isset( $handler['methods'][ WP_REST_Server::CREATABLE ] ) ) {
				$this->assertTrue( $handler['methods'][ WP_REST_Server::CREATABLE ] );
				return;
			}
		}

		$this->fail( 'Route does not accept POST.' );
	}
}
