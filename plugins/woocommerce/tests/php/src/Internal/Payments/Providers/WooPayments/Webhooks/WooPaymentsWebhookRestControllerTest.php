<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\ProviderTextLogAssertions;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingActionSchedulerService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use InvalidArgumentException;
use RuntimeException;
use WC_REST_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Tests for the WooPaymentsWebhookRestController class.
 */
class WooPaymentsWebhookRestControllerTest extends WC_REST_Unit_Test_Case {

	use ProviderTextLogAssertions;

	/**
	 * Scheduler the controller's reliability service schedules retries on.
	 *
	 * @var RecordingActionSchedulerService
	 */
	private RecordingActionSchedulerService $scheduler;

	/**
	 * Reliability service the controller hands failed pushes to.
	 *
	 * @var WooPaymentsWebhookReliabilityService
	 */
	private WooPaymentsWebhookReliabilityService $reliability_service;

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsWebhookRestController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsWebhookRestController::class );
		$this->remove_rest_hook();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->remove_rest_hook();
		remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox The webhook route is not registered when the WooPayments extension owns the runtime.
	 */
	public function test_registers_no_route_when_extension_owns_runtime(): void {
		$this->fake_plugin( true );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

		$this->sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox The controller registers POST /wc/v3/payments/webhook when the built-in WooPayments owns the runtime.
	 */
	public function test_registers_wc_v3_payments_webhook_when_builtin_owns_runtime(): void {
		$this->fake_plugin( false );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

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
		$ingestor   = new class() extends WooPaymentsEventIngestor {
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
			new class() extends WooPaymentsEventIngestor {
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
			new class() extends WooPaymentsEventIngestor {
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
					'hook' => WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
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
	 * Through the webhook route with the real ingestor and handlers. Client 11.1.0 refuses a malformed refund or dispute
	 * event, a dispute whose charge no order holds, a refund amount that is not valid and an unknown refund update status
	 * with Invalid_Webhook_Data_Exception (class-wc-payments-webhook-processing-service.php:361, :717, :933-944, :1132),
	 * which its webhook answers with 400 and the reason logged (class-wc-rest-payments-webhook-controller.php:81-83); a
	 * refund whose charge no order holds is answered 500 (webhook processing service :317, :1111), which the store retries.
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
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( WooPaymentsEventIngestor::class ), $logger );
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
					'hook' => WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
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
		$controller = $this->create_controller_with_ingestor( wc_get_container()->get( WooPaymentsEventIngestor::class ) );
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
					'hook' => WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
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
	 * @testdox A charge.dispute.funds_withdrawn push refused by the order payment lock keeps its one attempt and leaves a note on its order.
	 */
	public function test_lock_refused_dispute_push_is_noted_on_its_order(): void {
		$order      = wc_create_order();
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' ) ) );

		$response = $controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_dispute_lock_controller',
					'type' => 'charge.dispute.funds_withdrawn',
				)
			)
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( ( new FailedEventTransients() )->get_event( 'evt_dispute_lock_controller' ) );
		$this->assertSame( array(), $this->scheduler->scheduled_jobs );

		// The same refused push again adds no second note.
		$controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_dispute_lock_controller',
					'type' => 'charge.dispute.funds_withdrawn',
				)
			)
		);
		$notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => false !== strpos( $note->content, 'evt_dispute_lock_controller' ) && false !== strpos( $note->content, 'kept the order locked' )
		);
		$this->assertCount( 1, $notes );
	}

	/**
	 * @testdox A failure writing the lock-refusal note through $hook leaves the refused push's error reply unchanged.
	 * @testWith ["woocommerce_new_order_note_data"]
	 *           ["woocommerce_order_note_added"]
	 *
	 * @param string $hook Order note hook a third-party callback throws from.
	 */
	public function test_failing_lock_refusal_note_keeps_the_error_reply( string $hook ): void {
		$order      = wc_create_order();
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' ) ) );
		$throwing   = static function () {
			throw new RuntimeException( 'Order note extension failure.' );
		};
		add_filter( $hook, $throwing );

		try {
			$response = $controller->handle_webhook(
				$this->create_post_request(
					array(
						'id'   => 'evt_dispute_note_failure',
						'type' => 'charge.dispute.funds_withdrawn',
					)
				)
			);
		} finally {
			remove_filter( $hook, $throwing );
		}

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'result' => 'error' ), $response->get_data() );
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
		$module = $this->getMockBuilder( WooPaymentsStripeBillingModule::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_loaded', 'handle_invoice_event' ) )
			->getMock();
		$module->method( 'is_loaded' )->willReturn( false );
		$module->expects( $this->never() )->method( 'handle_invoice_event' );
		wc_get_container()->replace( WooPaymentsStripeBillingModule::class, $module );
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$logger     = RecordingWcLogger::install();
		$controller = new WooPaymentsWebhookRestController();
		$controller->init(
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			wc_get_container()->get( WooPaymentsEventIngestor::class ),
			wc_get_container()->get( WooPaymentsWebhookReliabilityService::class )
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
	 * @testdox Logger failures do not replace the webhook error envelope.
	 */
	public function test_logger_failures_do_not_replace_webhook_error_envelope(): void {
		$controller = $this->create_controller_with_ingestor(
			new class() extends WooPaymentsEventIngestor {
				/**
				 * Process a payload.
				 *
				 * @param array<string,mixed> $event Event payload.
				 */
				public function process( array $event ): void {
					throw new RuntimeException( 'server failed' );
				}
			},
			new class() extends RecordingWcLogger {
				/**
				 * Fail to record an error log entry.
				 *
				 * @param string              $message Log message.
				 * @param array<string,mixed> $context Log context.
				 * @throws RuntimeException Always.
				 */
				public function error( $message, $context = array() ) {
					unset( $message, $context );

					throw new RuntimeException( 'logger failed' );
				}
			}
		);

		$response = $controller->handle_webhook( $this->create_post_request( array( 'type' => 'bad' ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'result' => 'error' ), $response->get_data() );
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
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $active Whether the WooPayments plugin should appear active.
	 */
	private function fake_plugin( bool $active ): void {
		$entry = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $active, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $active ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $active;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);
	}

	/**
	 * Create a controller with a supplied ingestor.
	 *
	 * @param WooPaymentsEventIngestor $ingestor Ingestor test double.
	 * @param RecordingWcLogger|null   $logger   Optional logger that wc_get_logger() returns for the rest of the test.
	 * @return WooPaymentsWebhookRestController
	 */
	private function create_controller_with_ingestor( WooPaymentsEventIngestor $ingestor, ?RecordingWcLogger $logger = null ): WooPaymentsWebhookRestController {
		if ( null !== $logger ) {
			add_filter( 'woocommerce_logging_class', static fn() => $logger );
		}

		$this->scheduler           = new RecordingActionSchedulerService();
		$reliability_service       = new WooPaymentsWebhookReliabilityService();
		$this->reliability_service = $reliability_service;
		$reliability_service->init(
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			$this->scheduler,
			new StaticFailedEventsApiClient(),
			$ingestor
		);

		$controller = new WooPaymentsWebhookRestController();
		$controller->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), $ingestor, $reliability_service );

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
