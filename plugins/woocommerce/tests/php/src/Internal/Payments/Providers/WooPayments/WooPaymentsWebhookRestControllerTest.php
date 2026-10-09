<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookRestController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedEventStore;
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
	 * @testdox The webhook route is not registered when the plugin owns runtime.
	 */
	public function test_registers_no_route_when_plugin_owns_runtime(): void {
		$this->fake_plugin( true );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );

		$this->sut->register();

		$this->assertFalse( has_action( 'rest_api_init', array( $this->sut, 'register_routes' ) ) );
	}

	/**
	 * @testdox The controller registers POST /wc/v3/payments/webhook when native owns runtime.
	 */
	public function test_registers_wc_v3_payments_webhook_when_native_owns_runtime(): void {
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
	 * @testdox The webhook route permission callback rejects requests without manage_woocommerce.
	 */
	public function test_permission_callback_rejects_unauthenticated_requests(): void {
		wp_set_current_user( 0 );

		$permission_callback = $this->get_registered_permission_callback();

		$this->assertFalse(
			(bool) call_user_func( $permission_callback, $this->create_post_request( array( 'type' => 'customer.created' ) ) ),
			'Unauthenticated requests must be rejected because real platform delivery uses the authenticated reliability pull path, not this direct route.'
		);
	}

	/**
	 * @testdox The webhook route permission callback allows manage_woocommerce administrators.
	 */
	public function test_permission_callback_allows_manage_woocommerce_admins(): void {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$permission_callback = $this->get_registered_permission_callback();

		$this->assertTrue(
			(bool) call_user_func( $permission_callback, $this->create_post_request( array( 'type' => 'customer.created' ) ) ),
			'Administrators with manage_woocommerce must pass the gate.'
		);

		wp_set_current_user( 0 );
		wp_delete_user( $admin_id );
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
		$this->assertSame( 'native-payments-webhook', $logger->get_errors()[0][2] );
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
		$this->assertSame( 'native-payments-webhook', $logger->get_errors()[0][2] );
		// Processing calls the platform, so a failure's message is left out; its class names it.
		$this->assertSame( RuntimeException::class, $this->get_logged_context( $logger, 'Failed processing a WooPayments webhook event.' )['exception'] );
	}

	/**
	 * @testdox An event that fails to process for a passing reason is kept and scheduled to run again; the reply is unchanged.
	 */
	public function test_failed_event_is_kept_and_scheduled_for_retry(): void {
		$event      = array(
			'id'   => 'evt_retry_controller',
			'type' => 'payment_intent.succeeded',
		);
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new RuntimeException( 'platform call failed' ) ) );

		$response = $controller->handle_webhook( $this->create_post_request( $event ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'result' => 'error' ), $response->get_data() );
		$this->assertSame( $event, wc_get_container()->get( WooPaymentsFailedEventStore::class )->get_event( 'evt_retry_controller' ) );
		$this->assertSame(
			array(
				array(
					'hook' => WooPaymentsWebhookReliabilityService::WEBHOOK_PROCESS_EVENT_ACTION,
					'args' => array( 'event_id' => 'evt_retry_controller' ),
				),
			),
			$this->scheduler->scheduled_jobs
		);
	}

	/**
	 * @testdox A refund event that fails for a passing reason is not kept or retried: it gets one attempt, as on the client.
	 */
	public function test_failed_event_off_the_retried_list_is_not_kept(): void {
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new RuntimeException( 'database error' ) ) );

		$response = $controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_refund_controller',
					'type' => 'charge.refunded',
				)
			)
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( wc_get_container()->get( WooPaymentsFailedEventStore::class )->get_event( 'evt_refund_controller' ) );
		$this->assertSame( array(), $this->scheduler->scheduled_jobs );
	}

	/**
	 * @testdox A fraud warning push refused by the order payment lock is kept and scheduled to run again, although the type gets one attempt after other failures.
	 */
	public function test_lock_refused_fraud_warning_push_is_kept_and_scheduled(): void {
		$order      = wc_create_order();
		$event      = array(
			'id'   => 'evt_efw_lock_controller',
			'type' => 'radar.early_fraud_warning.created',
		);
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new OrderPaymentLockRefusedException( $order->get_id(), 'early fraud warning webhook' ) ) );

		$response = $controller->handle_webhook( $this->create_post_request( $event ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( array( 'result' => 'error' ), $response->get_data() );
		$this->assertSame( $event, wc_get_container()->get( WooPaymentsFailedEventStore::class )->get_event( 'evt_efw_lock_controller' ) );
		$this->assertCount( 1, $this->scheduler->scheduled_jobs );
		$this->assertSame( array( 'event_id' => 'evt_efw_lock_controller' ), $this->scheduler->scheduled_jobs[0]['args'] );
	}

	/**
	 * @testdox A dispute push refused by the order payment lock keeps its one attempt and leaves a note on its order.
	 */
	public function test_lock_refused_dispute_push_is_noted_on_its_order(): void {
		$order      = wc_create_order();
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new OrderPaymentLockRefusedException( $order->get_id(), 'dispute webhook' ) ) );

		$response = $controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_dispute_lock_controller',
					'type' => 'charge.dispute.created',
				)
			)
		);

		$this->assertSame( 500, $response->get_status() );
		$this->assertNull( wc_get_container()->get( WooPaymentsFailedEventStore::class )->get_event( 'evt_dispute_lock_controller' ) );
		$this->assertSame( array(), $this->scheduler->scheduled_jobs );

		// The same refused push again adds no second note.
		$controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_dispute_lock_controller',
					'type' => 'charge.dispute.created',
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
						'type' => 'charge.dispute.created',
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
	 * @testdox A malformed event is not kept or retried.
	 */
	public function test_malformed_event_is_not_retried(): void {
		$controller = $this->create_controller_with_ingestor( new ThrowingEventIngestor( new InvalidArgumentException( 'malformed event' ) ) );

		$response = $controller->handle_webhook(
			$this->create_post_request(
				array(
					'id'   => 'evt_malformed_controller',
					'type' => 'payment_intent.succeeded',
				)
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( wc_get_container()->get( WooPaymentsFailedEventStore::class )->get_event( 'evt_malformed_controller' ) );
		$this->assertSame( array(), $this->scheduler->scheduled_jobs );
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
		$this->assertSame( array( array( 'error', 'Failed processing event evt_invoice_controller. Reason: Cannot find subscription for the incoming "invoice.paid" event.', 'native-payments-webhook' ) ), $logger->get_errors() );
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

		$this->scheduler     = new RecordingActionSchedulerService();
		$reliability_service = new WooPaymentsWebhookReliabilityService();
		$reliability_service->init(
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			$this->scheduler,
			wc_get_container()->get( WooPaymentsFailedEventStore::class ),
			new StaticFailedEventsProvider(),
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
