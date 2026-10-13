<?php
/**
 * WooPaymentsEventIngestor class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsTransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use InvalidArgumentException;
use Throwable;

/**
 * Receives platform webhook events, skips repeats and other modes, and passes each to its handler.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsEventIngestor {

	/**
	 * Transient prefix for the per-event "already processed" idempotency marker.
	 *
	 * @var string
	 */
	private const PROCESSED_EVENT_TRANSIENT_PREFIX = 'wcpay_processed_event_';

	/**
	 * TTL for the per-event "already processed" idempotency marker, in seconds.
	 *
	 * @var int
	 */
	private const PROCESSED_EVENT_TRANSIENT_TTL = HOUR_IN_SECONDS;

	/**
	 * Stripe Billing invoice event types.
	 *
	 * The Stripe Billing module handles them when it is loaded. Otherwise they are refused, as client 11.1.0 refuses them
	 * when WooCommerce Subscriptions is not active.
	 *
	 * @var string[]
	 */
	private const STRIPE_BILLING_INVOICE_EVENT_TYPES = array(
		'invoice.paid',
		'invoice.payment_failed',
		'invoice.upcoming',
	);

	/**
	 * Event types retried after a processing failure, whose handlers are safe to run again and to run late.
	 *
	 * The refund and dispute types are retried too ({@see self::REFUND_AND_DISPUTE_RETRIED_EVENT_TYPES}), and invoice.paid
	 * while the Stripe Billing module is loaded ({@see self::is_retried_event()}). Other types get one attempt: running the
	 * fraud warning handlers again can apply an older event over a newer one.
	 *
	 * @var string[]
	 */
	private const RETRIED_EVENT_TYPES = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
	);

	/**
	 * Refund and dispute event types retried after a processing failure, but not after a lock refusal.
	 *
	 * The platform counts the store's error reply as delivered and never sends the event again, so without a retry a
	 * refund or dispute update that failed once is lost. A lock refusal keeps its one attempt and the note on the order
	 * ({@see self::LOCK_REFUSAL_RETRIED_EVENT_TYPES}).
	 *
	 * @var string[]
	 */
	private const REFUND_AND_DISPUTE_RETRIED_EVENT_TYPES = array(
		'charge.refunded',
		'charge.refund.updated',
		'charge.dispute.created',
		'charge.dispute.updated',
		'charge.dispute.closed',
	);

	/**
	 * Event types also retried when the order payment lock refused them ({@see OrderPaymentLockRefusedException}).
	 *
	 * A refusal comes before the handler writes anything, so a retry cannot duplicate a write. What remains is whether
	 * the event is safe to apply late, after a newer event: charge.expired is terminal, and a fraud warning only
	 * records its warning and a note, so a late one changes no status and moves no money. A late created warning can
	 * replace a newer stored warning; that is accepted, since the warning only informs the merchant. Refund and dispute
	 * events are not, since a late created or pending event would undo a newer close or failure; their refusal keeps
	 * one attempt and is noted on the order.
	 *
	 * @var string[]
	 */
	private const LOCK_REFUSAL_RETRIED_EVENT_TYPES = array(
		'charge.expired',
		'radar.early_fraud_warning.created',
		'radar.early_fraud_warning.updated',
	);

	/**
	 * Transport log, which writes the received-event line.
	 *
	 * @var WooPaymentsTransportLog
	 */
	private WooPaymentsTransportLog $transport_log;

	/**
	 * Dispute event handler.
	 *
	 * @var WooPaymentsDisputeEventHandler
	 */
	private WooPaymentsDisputeEventHandler $dispute_event_handler;

	/**
	 * Refund event handler.
	 *
	 * @var WooPaymentsRefundEventHandler
	 */
	private WooPaymentsRefundEventHandler $refund_event_handler;

	/**
	 * Account event handler.
	 *
	 * @var WooPaymentsAccountEventHandler
	 */
	private WooPaymentsAccountEventHandler $account_event_handler;

	/**
	 * Notification event handler.
	 *
	 * @var WooPaymentsNotificationEventHandler
	 */
	private WooPaymentsNotificationEventHandler $notification_event_handler;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments early fraud warning event handler.
	 *
	 * @var WooPaymentsEarlyFraudWarningEventHandler
	 */
	private WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler;

	/**
	 * Payment intent and charge expiry event handler.
	 *
	 * @var WooPaymentsPaymentIntentEventHandler
	 */
	private WooPaymentsPaymentIntentEventHandler $payment_intent_event_handler;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsTransportLog                  $transport_log                     Transport log.
	 * @param WooPaymentsDisputeEventHandler           $dispute_event_handler             Dispute event handler.
	 * @param WooPaymentsRefundEventHandler            $refund_event_handler              Refund event handler.
	 * @param WooPaymentsAccountEventHandler           $account_event_handler             Account event handler.
	 * @param WooPaymentsNotificationEventHandler      $notification_event_handler        Notification event handler.
	 * @param WooPaymentsAccountService                $account_service                   WooPayments account service.
	 * @param WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler WooPayments early fraud warning event handler.
	 * @param WooPaymentsPaymentIntentEventHandler     $payment_intent_event_handler      Payment intent and charge expiry event handler.
	 */
	final public function init( WooPaymentsTransportLog $transport_log, WooPaymentsDisputeEventHandler $dispute_event_handler, WooPaymentsRefundEventHandler $refund_event_handler, WooPaymentsAccountEventHandler $account_event_handler, WooPaymentsNotificationEventHandler $notification_event_handler, WooPaymentsAccountService $account_service, WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler, WooPaymentsPaymentIntentEventHandler $payment_intent_event_handler ): void {
		$this->transport_log                     = $transport_log;
		$this->dispute_event_handler             = $dispute_event_handler;
		$this->refund_event_handler              = $refund_event_handler;
		$this->account_event_handler             = $account_event_handler;
		$this->notification_event_handler        = $notification_event_handler;
		$this->account_service                   = $account_service;
		$this->early_fraud_warning_event_handler = $early_fraud_warning_event_handler;
		$this->payment_intent_event_handler      = $payment_intent_event_handler;
	}

	/**
	 * Process a WooPayments webhook event.
	 *
	 * An event with an ID is skipped for the marker TTL once a delivery of it has finished: the store's retry job, a
	 * platform redelivery and the missed-event fetch can deliver it again, and applying it twice would repeat status
	 * changes, refund metadata and dispute updates. The marker is written only after the event is handled, so a delivery
	 * that threw or died is processed again. Overlapping deliveries are not kept apart here: the handlers that change an
	 * order take the order payment lock or the Stripe Billing invoice lock, and a redelivery after a failed attempt can
	 * add its notes again. Client 11.1.0 keeps no marker at all.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws Throwable When dispatching the event fails (including an InvalidArgumentException for an invalid event shape); the event is left unmarked so it can be retried.
	 */
	public function process( array $event ): void {
		$event_id = $this->get_event_id( $event );
		// Client 11.1.0 logs the received line first (class-wc-payments-webhook-processing-service.php:155-160), so a skipped,
		// repeated or refused event still leaves one.
		$this->transport_log->debug_payload(
			sprintf( 'WEBHOOK RECEIVED: %1$s %2$s', is_string( $event['type'] ?? null ) ? $event['type'] : '', $event_id ),
			$event
		);

		if ( '' !== $event_id && $this->is_event_already_processed( $event_id ) ) {
			return;
		}

		$this->dispatch( $event );

		if ( '' !== $event_id ) {
			$this->mark_event_processed( $event_id );
		}
	}

	/**
	 * Dispatch a WooPayments webhook event to the matching handler.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws InvalidArgumentException When the event shape is invalid.
	 */
	private function dispatch( array $event ): void {
		$event_type = $event['type'] ?? null;
		if ( ! is_string( $event_type ) || '' === $event_type ) {
			throw new InvalidArgumentException( 'WooPayments webhook event is missing a type.' );
		}

		if ( $this->is_webhook_mode_mismatch( $event ) ) {
			return;
		}

		/**
		 * Fires before a WooPayments webhook event is handled. A callback that throws is logged and does not fail the event.
		 *
		 * Keeps this name because extensions listen to it.
		 *
		 * @since 11.2.0
		 *
		 * @param string              $event_type Event type.
		 * @param array<string,mixed> $event      Event payload.
		 */
		$this->run_delivery_hook( 'woocommerce_payments_before_webhook_delivery', $event_type, $event );
		$this->route( $event_type, $event );
		/**
		 * Fires after a WooPayments webhook event is handled; not when its handler throws.
		 *
		 * Keeps this name because extensions listen to it.
		 *
		 * @since 11.2.0
		 *
		 * @param string              $event_type Event type.
		 * @param array<string,mixed> $event      Event payload.
		 */
		$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
	}

	/**
	 * Hand the event to the handler for its type.
	 *
	 * A handler that throws ends the delivery here, so the after-delivery hook does not fire for it.
	 *
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event      Event payload.
	 * @throws InvalidArgumentException When the event shape is invalid.
	 */
	private function route( string $event_type, array $event ): void {
		if ( $this->is_stripe_billing_invoice_event( $event_type ) ) {
			if ( ! $this->get_stripe_billing_module()->is_loaded() ) {
				$this->refuse_stripe_billing_invoice_event_without_module( $event_type );
			}

			$this->get_stripe_billing_module()->handle_invoice_event( $event );
			return;
		}

		if ( $this->notification_event_handler->is_supported_event( $event_type ) ) {
			$this->notification_event_handler->process( $event );
			return;
		}

		$event_object = $this->get_event_object( $event );
		if ( $this->early_fraud_warning_event_handler->is_supported_event( $event_type ) ) {
			$this->early_fraud_warning_event_handler->process( $event_type, $event_object );
			return;
		}

		if ( $this->dispute_event_handler->is_supported_event( $event_type ) ) {
			$this->dispute_event_handler->process( $event_type, $event_object );
			return;
		}

		if ( $this->refund_event_handler->is_supported_event( $event_type ) ) {
			$this->refund_event_handler->process( $event_type, $event_object );
			return;
		}

		if ( $this->account_event_handler->is_supported_event( $event_type ) ) {
			$this->account_event_handler->process( $event_type, $event_object );
			return;
		}

		if ( $this->payment_intent_event_handler->is_supported_event( $event_type ) ) {
			$this->payment_intent_event_handler->process( $event_type, $event_object );
		}
	}

	/**
	 * Tell whether an event that failed to process for a passing reason is processed again later.
	 *
	 * Of the Stripe Billing invoice events, only `invoice.paid` is retried, and only while the module is loaded: the
	 * failed and upcoming invoice handlers write notes and count failed attempts before their last platform call.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return bool
	 */
	public function is_retried_event( array $event ): bool {
		$event_type = $event['type'] ?? null;
		if ( ! is_string( $event_type ) ) {
			return false;
		}

		if ( in_array( $event_type, self::RETRIED_EVENT_TYPES, true ) || in_array( $event_type, self::REFUND_AND_DISPUTE_RETRIED_EVENT_TYPES, true ) ) {
			return true;
		}

		return 'invoice.paid' === $event_type && $this->get_stripe_billing_module()->is_loaded();
	}

	/**
	 * Tell whether an event the order payment lock refused is processed again later.
	 *
	 * Events retried after any passing failure are, except refund and dispute events, and so are the types that are safe
	 * to apply late ({@see self::LOCK_REFUSAL_RETRIED_EVENT_TYPES}).
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return bool
	 */
	public function is_retried_lock_refusal( array $event ): bool {
		if ( isset( $event['type'] ) && in_array( $event['type'], self::REFUND_AND_DISPUTE_RETRIED_EVENT_TYPES, true ) ) {
			return false;
		}

		if ( $this->is_retried_event( $event ) ) {
			return true;
		}

		return isset( $event['type'] ) && in_array( $event['type'], self::LOCK_REFUSAL_RETRIED_EVENT_TYPES, true );
	}

	/**
	 * Get the provider event ID from an event payload.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return string Event ID, or an empty string when the event has no usable ID.
	 */
	private function get_event_id( array $event ): string {
		$event_id = $event['id'] ?? null;

		return is_scalar( $event_id ) ? (string) $event_id : '';
	}

	/**
	 * Tell whether an event ID was already processed within the marker TTL.
	 *
	 * @param string $event_id Event ID.
	 * @return bool
	 */
	private function is_event_already_processed( string $event_id ): bool {
		return false !== get_transient( $this->get_processed_event_transient_name( $event_id ) );
	}

	/**
	 * Record that an event ID was processed so later re-deliveries are skipped.
	 *
	 * @param string $event_id Event ID.
	 */
	private function mark_event_processed( string $event_id ): void {
		set_transient( $this->get_processed_event_transient_name( $event_id ), 1, self::PROCESSED_EVENT_TRANSIENT_TTL );
	}

	/**
	 * Get the idempotency-marker transient name for an event ID.
	 *
	 * @param string $event_id Event ID.
	 * @return string
	 */
	private function get_processed_event_transient_name( string $event_id ): string {
		return self::PROCESSED_EVENT_TRANSIENT_PREFIX . md5( $event_id );
	}

	/**
	 * Get the provider object from an event payload.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return array<string,mixed>
	 * @throws InvalidArgumentException When the object shape is invalid.
	 */
	private function get_event_object( array $event ): array {
		$object = $event['data']['object'] ?? null;
		if ( ! is_array( $object ) ) {
			throw new InvalidArgumentException( 'WooPayments webhook event is missing an object.' );
		}

		return $object;
	}

	/**
	 * Tell whether the event's livemode differs from the store's WooPayments mode, logging one error line when it does.
	 *
	 * Client 11.1.0 `class-wc-payments-webhook-processing-service.php:268-290` skips the event and logs the same line.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return bool
	 */
	private function is_webhook_mode_mismatch( array $event ): bool {
		if ( ! array_key_exists( 'livemode', $event ) ) {
			return false;
		}

		if ( $this->is_live_mode() === (bool) $event['livemode'] ) {
			return false;
		}

		try {
			wc_get_logger()->error(
				sprintf( 'Webhook event mode did not match the gateway mode (event ID: %s)', $this->get_event_id( $event ) ),
				array( 'source' => WooPaymentsLogger::SOURCE )
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		return true;
	}

	/**
	 * Tell whether WooPayments is in live mode.
	 *
	 * The mode every WooPayments reader uses (WooPaymentsAccountService::is_test_mode_enabled(): test-mode onboarding,
	 * dev mode and the wcpay_test_mode filter included); client 11.1.0 reads WC_Payments::mode()->is_live().
	 *
	 * @return bool
	 */
	private function is_live_mode(): bool {
		return ! $this->account_service->is_test_mode_enabled();
	}

	/**
	 * Tell whether the event is a Stripe Billing invoice event.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	private function is_stripe_billing_invoice_event( string $event_type ): bool {
		return in_array( $event_type, self::STRIPE_BILLING_INVOICE_EVENT_TYPES, true );
	}

	/**
	 * Get the Stripe Billing module.
	 *
	 * @return WooPaymentsStripeBillingModule
	 */
	private function get_stripe_billing_module(): WooPaymentsStripeBillingModule {
		return wc_get_container()->get( WooPaymentsStripeBillingModule::class );
	}

	/**
	 * Refuse a Stripe Billing invoice event while the Stripe Billing module is not loaded.
	 *
	 * Client 11.1.0 loads its event handler without WooCommerce Subscriptions too; its subscription lookup then finds nothing,
	 * so it refuses the event with these reasons (`class-wc-payments-subscriptions-event-handler.php:79,138,233`), the
	 * webhook answers 400 and nothing is recorded. The webhook route or the failed-event job that catches the refusal
	 * logs it, so it is not logged here.
	 *
	 * @param string $event_type Event type.
	 * @throws InvalidArgumentException Always.
	 */
	private function refuse_stripe_billing_invoice_event_without_module( string $event_type ): void {
		$reasons = array(
			'invoice.upcoming'       => __( 'Cannot find subscription to handle the "invoice.upcoming" event.', 'woocommerce' ),
			'invoice.paid'           => __( 'Cannot find subscription for the incoming "invoice.paid" event.', 'woocommerce' ),
			'invoice.payment_failed' => __( 'Cannot find subscription for the incoming "invoice.payment_failed" event.', 'woocommerce' ),
		);
		$reason  = $reasons[ $event_type ] ?? '';

		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
		throw new InvalidArgumentException( $reason );
	}

	/**
	 * Get a refused event's reason for its log line: " Reason: <message>", or '' when the refusal wraps a platform error.
	 *
	 * Every refusal (InvalidArgumentException) is thrown with a constant message, except the Stripe Billing handler's, which
	 * passes on its module's refusal; that one can carry the platform's text, so it is logged by the platform's codes only.
	 *
	 * @internal
	 *
	 * @param Throwable $refusal Refusal.
	 * @return string
	 */
	public static function get_refusal_reason_for_log( Throwable $refusal ): string {
		return array() === WooPaymentsLogger::get_api_error_context( $refusal ) ? ' Reason: ' . $refusal->getMessage() : '';
	}

	/**
	 * Fire a webhook delivery hook; a callback that throws is logged and does not fail the event.
	 *
	 * @param string              $hook       Hook name.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event      Event payload.
	 */
	private function run_delivery_hook( string $hook, string $event_type, array $event ): void {
		try {
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Each hook is documented where process() names it.
			do_action( $hook, $event_type, $event );
		} catch ( Throwable $exception ) {
			// Logging is best-effort: a failing log handler must not turn an isolated hook failure into a failed event.
			try {
				// A callback can let a platform error out, directly or wrapped, so its message is not logged.
				wc_get_logger()->error(
					'A WooPayments webhook delivery hook callback failed.',
					array_merge(
						WooPaymentsLogger::get_failure_context( $exception ),
						array(
							'source' => WooPaymentsLogger::SOURCE,
							'hook'   => $hook,
						)
					)
				);
			} catch ( Throwable $logger_exception ) {
				unset( $logger_exception );
			}
		}
	}
}
