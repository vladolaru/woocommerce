<?php
/**
 * WooPaymentsEventIngestor class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsOtherChargeRecorder;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use InvalidArgumentException;
use Throwable;
use WC_Order;
use WC_Payment_Token;

/**
 * Ingests WooPayments provider webhook events into native payment lifecycle effects.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
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
	 * Known WooPayments event types whose side effects still block native cutover.
	 *
	 * @var string[]
	 */
	const KNOWN_UNHANDLED_EVENT_TYPES = array();

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
	 * Other types get one attempt, as on the client: running the refund, dispute or fraud warning handlers again can
	 * duplicate a refund or apply an older event over a newer one.
	 *
	 * @var string[]
	 */
	private const RETRIED_EVENT_TYPES = array(
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
	);

	/**
	 * Event types also retried when the order payment lock refused them ({@see OrderPaymentLockRefusedException}).
	 *
	 * A refusal comes before the handler writes anything, so a retry cannot duplicate a write. What remains is whether
	 * the event is safe to apply late, after a newer event: charge.expired is terminal, and a fraud warning only
	 * records its warning and a note, so a late one changes no status and moves no money. Refund and dispute events
	 * are not, since a late created or pending event would undo a newer close or failure; their refusal keeps one
	 * attempt and is noted on the order (monitor ruling 2026-10-06).
	 *
	 * @var string[]
	 */
	private const LOCK_REFUSAL_RETRIED_EVENT_TYPES = array(
		'charge.expired',
		'radar.early_fraud_warning.created',
		'radar.early_fraud_warning.updated',
	);

	/**
	 * Order lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Legacy proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

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
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService|null
	 */
	private ?WooPaymentsOrderDataService $order_data_service = null;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * WooPayments order effect applier.
	 *
	 * @var WooPaymentsOrderEffectApplier|null
	 */
	private ?WooPaymentsOrderEffectApplier $order_effect_applier = null;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService|null
	 */
	private ?WooPaymentsOrderNoteService $order_note_service = null;

	/**
	 * WooPayments admin menu badge service.
	 *
	 * @var WooPaymentsAdminMenuBadgeService|null
	 */
	private ?WooPaymentsAdminMenuBadgeService $admin_menu_badge_service = null;

	/**
	 * WooPayments early fraud warning event handler.
	 *
	 * @var WooPaymentsEarlyFraudWarningEventHandler|null
	 */
	private ?WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null;

	/**
	 * Webhook event order resolver.
	 *
	 * @var WooPaymentsEventOrderResolver|null
	 */
	private ?WooPaymentsEventOrderResolver $event_order_resolver = null;

	/**
	 * Recorder of events on a charge that does not pay the order.
	 *
	 * @var WooPaymentsOtherChargeRecorder|null
	 */
	private ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLifecycleService                  $lifecycle_service                  Order lifecycle service.
	 * @param LegacyProxy                                   $legacy_proxy                       Legacy proxy.
	 * @param WooPaymentsApiClient                          $api_client                         Native WooPayments API client.
	 * @param WooPaymentsDisputeEventHandler                $dispute_event_handler              Dispute event handler.
	 * @param WooPaymentsRefundEventHandler                 $refund_event_handler               Refund event handler.
	 * @param WooPaymentsAccountEventHandler                $account_event_handler              Account event handler.
	 * @param WooPaymentsNotificationEventHandler           $notification_event_handler         Notification event handler.
	 * @param WooPaymentsOrderDataService|null              $order_data_service                 WooPayments order data service.
	 * @param WooPaymentsAccountService|null                $account_service                    WooPayments account service.
	 * @param WooPaymentsOrderEffectApplier|null            $order_effect_applier               Optional order effect applier.
	 * @param WooPaymentsOrderNoteService|null              $order_note_service                 Optional order note service.
	 * @param WooPaymentsAdminMenuBadgeService|null         $admin_menu_badge_service           Optional admin menu badge service.
	 * @param WooPaymentsEarlyFraudWarningEventHandler|null $early_fraud_warning_event_handler Optional early fraud warning event handler.
	 * @param WooPaymentsEventOrderResolver|null            $event_order_resolver               Optional webhook event order resolver.
	 * @param WooPaymentsOtherChargeRecorder|null           $other_charge_recorder              Optional recorder of events on another charge.
	 */
	final public function init( OrderPaymentLifecycleService $lifecycle_service, LegacyProxy $legacy_proxy, WooPaymentsApiClient $api_client, WooPaymentsDisputeEventHandler $dispute_event_handler, WooPaymentsRefundEventHandler $refund_event_handler, WooPaymentsAccountEventHandler $account_event_handler, WooPaymentsNotificationEventHandler $notification_event_handler, ?WooPaymentsOrderDataService $order_data_service = null, ?WooPaymentsAccountService $account_service = null, ?WooPaymentsOrderEffectApplier $order_effect_applier = null, ?WooPaymentsOrderNoteService $order_note_service = null, ?WooPaymentsAdminMenuBadgeService $admin_menu_badge_service = null, ?WooPaymentsEarlyFraudWarningEventHandler $early_fraud_warning_event_handler = null, ?WooPaymentsEventOrderResolver $event_order_resolver = null, ?WooPaymentsOtherChargeRecorder $other_charge_recorder = null ): void {
		$this->lifecycle_service                 = $lifecycle_service;
		$this->legacy_proxy                      = $legacy_proxy;
		$this->api_client                        = $api_client;
		$this->dispute_event_handler             = $dispute_event_handler;
		$this->refund_event_handler              = $refund_event_handler;
		$this->account_event_handler             = $account_event_handler;
		$this->notification_event_handler        = $notification_event_handler;
		$this->order_data_service                = $order_data_service;
		$this->account_service                   = $account_service;
		$this->order_effect_applier              = $order_effect_applier;
		$this->order_note_service                = $order_note_service;
		$this->admin_menu_badge_service          = $admin_menu_badge_service;
		$this->early_fraud_warning_event_handler = $early_fraud_warning_event_handler;
		$this->event_order_resolver              = $event_order_resolver;
		$this->other_charge_recorder             = $other_charge_recorder;
	}

	/**
	 * Process a WooPayments webhook event.
	 *
	 * An event carrying an ID is not processed again within the marker TTL once a delivery of it finished:
	 * the same event can be delivered repeatedly (Action Scheduler retries, provider re-delivery, the
	 * failed-event replay queue), and re-applying it would duplicate money-affecting side effects such as
	 * order-state transitions, refund metadata, and dispute updates.
	 *
	 * The "processed" marker is written only after the event is handled, so an event whose processing threw, or
	 * whose request died, is processed by the next delivery. Two deliveries of one event that overlap are not kept
	 * apart here: the handlers that change an order serialize themselves (the order payment lock, or the Stripe Billing
	 * invoice lock), and a redelivery after a failed attempt can add its notes again.
	 * Client 11.1.0 keeps no marker at all.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws Throwable When dispatching the event fails (including an InvalidArgumentException for an invalid event shape); the event is left unmarked so it can be retried.
	 */
	public function process( array $event ): void {
		$event_id = $this->get_event_id( $event );
		// Sweep row 300: the client's received line, first, so a skipped, deduplicated or refused event still leaves one.
		$this->api_client->log_redacted_payload(
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

		$this->run_delivery_hook( 'woocommerce_payments_before_webhook_delivery', $event_type, $event );

		if ( $this->is_stripe_billing_invoice_event( $event_type ) ) {
			if ( ! $this->get_stripe_billing_module()->is_loaded() ) {
				$this->refuse_stripe_billing_invoice_event_without_module( $event_type );
			}

			$this->get_stripe_billing_module()->handle_invoice_event( $event );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->notification_event_handler->is_supported_event( $event_type ) ) {
			$this->notification_event_handler->process( $event );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$event_object = $this->get_event_object( $event );
		if ( $this->get_early_fraud_warning_event_handler()->is_supported_event( $event_type ) ) {
			$this->get_early_fraud_warning_event_handler()->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->dispute_event_handler->is_supported_event( $event_type ) ) {
			$this->dispute_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->refund_event_handler->is_supported_event( $event_type ) ) {
			$this->refund_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( $this->account_event_handler->is_supported_event( $event_type ) ) {
			$this->account_event_handler->process( $event_type, $event_object );
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( ! $this->is_lifecycle_event_type( $event_type ) ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		// The plugin's canceled/amount_capturable_updated handlers are nothing but
		// this cache invalidation and never resolve an order.
		if ( in_array( $event_type, array( 'payment_intent.canceled', 'payment_intent.amount_capturable_updated' ), true ) ) {
			$this->get_admin_menu_badge_service()->invalidate_authorization_summary_caches();
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$record_only = false;
		if ( 'charge.expired' === $event_type ) {
			$order = $this->get_event_order_resolver()->find_order_by_charge_id( $this->get_object_id( $event_object ) );
			if ( ! $order instanceof WC_Order ) {
				$order       = $this->get_event_order_resolver()->find_order_from_charge_metadata( $event_object );
				$record_only = true;
			}
		} else {
			$order = $this->get_event_order_resolver()->find_order_for_intent_event( $event_object );
		}
		if ( ! $order instanceof WC_Order ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		if ( 'payment_intent.payment_failed' === $event_type && ! $this->is_actionable_payment_failure( $event_object ) ) {
			$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
			return;
		}

		$this->process_order_payment_event( $order, $event_type, $event_object, $record_only );

		// Captures and expiries change what the uncaptured-transactions badge counts; the plugin
		// invalidates after the order effects land.
		if ( 'payment_intent.payment_failed' !== $event_type ) {
			$this->get_admin_menu_badge_service()->invalidate_authorization_summary_caches();
		}

		$this->run_delivery_hook( 'woocommerce_payments_after_webhook_delivery', $event_type, $event );
	}

	/**
	 * Apply a payment intent or charge expiry event to its order, or record it when it is on another charge.
	 *
	 * The event is the order's own payment or another charge on it, decided on the order read again under the order
	 * payment lock; the same claim covers what either branch writes. The lock value is the event object's ID: the intent
	 * for intent events, the charge for `charge.expired`.
	 *
	 * @param WC_Order            $order        Order the event resolved to.
	 * @param string              $event_type   `payment_intent.succeeded`, `payment_intent.payment_failed` or `charge.expired`.
	 * @param array<string,mixed> $event_object Payment intent or charge object.
	 * @param bool                $record_only  Whether the order was found only by the charge's metadata, so the event is recorded while the order still does not hold the charge.
	 * @throws OrderPaymentLockRefusedException When another operation holds the order payment lock; nothing is written.
	 */
	private function process_order_payment_event( WC_Order $order, string $event_type, array $event_object, bool $record_only = false ): void {
		$vocabulary         = new WooPaymentsPersistenceVocabulary();
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$lock_value         = $this->get_object_id( $event_object );
		$lock_token         = $order_payment_lock->claim( $order, $vocabulary, $lock_value, 'payment status update' );
		if ( null === $lock_token ) {
			$this->log_payment_event_refusal( $order, $vocabulary, $event_type, $lock_value );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built in the exception from an order ID and a fixed operation name, not HTML output.
			throw new OrderPaymentLockRefusedException( $order->get_id(), 'payment status update' );
		}

		$is_own_payment = false;
		try {
			$this->lifecycle_service->reread_order_from_data_store( $order );
			$is_charge_event = 'charge.expired' === $event_type;
			$charge_id       = $is_charge_event ? $this->get_object_id( $event_object ) : $this->get_charge_id_from_intent( $event_object );
			$intent_id       = $is_charge_event ? $this->get_event_order_resolver()->get_event_intent_id( $event_object, $order ) : $this->get_object_id( $event_object );
			// An order found by the charge's metadata is recorded only while it still does not hold the charge: one that
			// saved it before this claim is decided like any order found by its charge.
			$record_only    = $record_only && $charge_id !== (string) $order->get_meta( '_charge_id', true );
			$is_own_payment = ! $record_only && $this->get_event_order_resolver()->is_own_payment( $order, $intent_id, $charge_id );

			if ( ! $is_own_payment ) {
				$this->get_other_charge_recorder()->record( $order, $event_type, $this->get_other_charge_facts( $event_type, $event_object, $intent_id, $charge_id ) );
			} elseif ( 'payment_intent.succeeded' === $event_type ) {
				$this->apply_succeeded_payment_intent_under_lock( $order, $event_object, $vocabulary );
			} else {
				$lifecycle_event = $this->build_lifecycle_event( $event_type, $event_object, $order );
				if ( null !== $lifecycle_event ) {
					$this->lifecycle_service->apply_under_lock( $order, $lifecycle_event, $vocabulary );
				}
			}
		} finally {
			$order_payment_lock->release( $order, $vocabulary, $lock_token );
		}

		if ( $is_own_payment && 'payment_intent.succeeded' === $event_type ) {
			$this->maybe_send_ipp_receipt_email( $order, $event_object );
		}
	}

	/**
	 * Log the refusal of a payment intent or charge expiry event by the order payment lock.
	 *
	 * A failed payment or an expiry logs the lifecycle refusal line, with its payment reference and status; a succeeded
	 * intent logs the plain refusal line.
	 *
	 * @param WC_Order                         $order      Order the event resolved to.
	 * @param WooPaymentsPersistenceVocabulary $vocabulary WooPayments persistence vocabulary.
	 * @param string                           $event_type Event type.
	 * @param string                           $lock_value Lock value the event claimed with.
	 */
	private function log_payment_event_refusal( WC_Order $order, WooPaymentsPersistenceVocabulary $vocabulary, string $event_type, string $lock_value ): void {
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		if ( 'payment_intent.succeeded' === $event_type ) {
			$order_payment_lock->log_refusal( $order, $vocabulary, 'payment status update' );
			return;
		}

		$order_payment_lock->log_refusal(
			$order,
			$vocabulary,
			'payment status update',
			null,
			array(
				'payment_reference' => $lock_value,
				'event_type'        => 'charge.expired' === $event_type ? PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED : PaymentLifecycleEvent::STATUS_FAILED,
				'reason'            => 'order_locked',
			)
		);
	}

	/**
	 * Get the facts the other-charge record of a payment intent or charge expiry event is written from.
	 *
	 * @param string              $event_type   Event type.
	 * @param array<string,mixed> $event_object Payment intent or charge object.
	 * @param string              $intent_id    The event's payment intent ID.
	 * @param string              $charge_id    The event's charge ID.
	 * @return array{object_id:string,status:string,intent_id:string,charge_id:string,amount?:float,currency?:string}
	 */
	private function get_other_charge_facts( string $event_type, array $event_object, string $intent_id, string $charge_id ): array {
		$facts = array(
			'object_id' => $this->get_object_id( $event_object ),
			'status'    => isset( $event_object['status'] ) && is_scalar( $event_object['status'] ) ? (string) $event_object['status'] : '',
			'intent_id' => $intent_id,
			'charge_id' => $charge_id,
		);
		if ( 'payment_intent.succeeded' === $event_type ) {
			$currency          = isset( $event_object['currency'] ) && is_string( $event_object['currency'] ) ? $event_object['currency'] : '';
			$facts['amount']   = WooPaymentsCurrencyUtils::amount_from_minor_units( (int) ( $event_object['amount'] ?? 0 ), $currency );
			$facts['currency'] = $currency;
		}

		return $facts;
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

		if ( in_array( $event_type, self::RETRIED_EVENT_TYPES, true ) ) {
			return true;
		}

		return 'invoice.paid' === $event_type && $this->get_stripe_billing_module()->is_loaded();
	}

	/**
	 * Tell whether an event the order payment lock refused is processed again later.
	 *
	 * Events retried after any passing failure are, and so are the types that are safe to apply late
	 * ({@see self::LOCK_REFUSAL_RETRIED_EVENT_TYPES}).
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @return bool
	 */
	public function is_retried_lock_refusal( array $event ): bool {
		if ( $this->is_retried_event( $event ) ) {
			return true;
		}

		return isset( $event['type'] ) && in_array( $event['type'], self::LOCK_REFUSAL_RETRIED_EVENT_TYPES, true );
	}

	/**
	 * Apply a succeeded payment intent that pays its order: payment method title, payment meta, status and notes.
	 *
	 * Provider code that learns of a succeeded intent outside its webhook, such as a paid Stripe Billing invoice, uses it.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order            $order          Order the intent pays, whatever its payment method.
	 * @param array<string,mixed> $payment_intent Provider payment intent object.
	 * @throws OrderPaymentLockRefusedException When another operation holds the order payment lock; nothing is applied.
	 */
	public function apply_succeeded_payment_intent( WC_Order $order, array $payment_intent ): void {
		// One claim covers the payment meta, the token repair and the status update, so a refusal leaves the order untouched.
		$vocabulary         = new WooPaymentsPersistenceVocabulary();
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$lock_token         = $order_payment_lock->claim( $order, $vocabulary, $this->get_object_id( $payment_intent ), 'payment status update' );
		if ( null === $lock_token ) {
			$order_payment_lock->log_refusal( $order, $vocabulary, 'payment status update' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built in the exception from an order ID and a fixed operation name, not HTML output.
			throw new OrderPaymentLockRefusedException( $order->get_id(), 'payment status update' );
		}

		try {
			// Decide on what the order is now: another gateway marks an order paid without taking this lock.
			$this->lifecycle_service->reread_order_from_data_store( $order );
			$this->apply_succeeded_payment_intent_under_lock( $order, $payment_intent, $vocabulary );
		} finally {
			$order_payment_lock->release( $order, $vocabulary, $lock_token );
		}

		$this->maybe_send_ipp_receipt_email( $order, $payment_intent );

		// Captures change what the uncaptured-transactions badge counts; the plugin
		// invalidates after the order effects land.
		$this->get_admin_menu_badge_service()->invalidate_authorization_summary_caches();
	}

	/**
	 * Apply a succeeded payment intent to an order read under the order payment lock the caller holds.
	 *
	 * @param WC_Order                         $order          Order the intent pays, read under the lock.
	 * @param array<string,mixed>              $payment_intent Provider payment intent object.
	 * @param WooPaymentsPersistenceVocabulary $vocabulary     WooPayments persistence vocabulary.
	 */
	private function apply_succeeded_payment_intent_under_lock( WC_Order $order, array $payment_intent, WooPaymentsPersistenceVocabulary $vocabulary ): void {
		$this->write_succeeded_payment_intent_meta( $order, $payment_intent );
		// The client's webhook never retitles an order, so an order of another gateway keeps its payment method.
		if ( WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			$this->apply_completed_payment_method_display_title( $order, $payment_intent );
		}
		$lifecycle_event = $this->build_succeeded_lifecycle_event( $payment_intent, $order );
		$this->repair_recurring_order_token( $order, $payment_intent );
		$this->lifecycle_service->apply_under_lock( $order, $lifecycle_event, $vocabulary );
	}

	/**
	 * Write the meta client 11.1.0 writes on every succeeded payment intent event, whatever the order's status.
	 *
	 * Intent, charge and payment method IDs, the event's currency and the mandate are written when set; the fee and net
	 * are written whenever the event yields them, zero included.
	 *
	 * @param WC_Order            $order          Order the intent belongs to.
	 * @param array<string,mixed> $payment_intent Provider payment intent object.
	 */
	private function write_succeeded_payment_intent_meta( WC_Order $order, array $payment_intent ): void {
		$values = array(
			'_intent_id'             => $this->get_object_id( $payment_intent ),
			'_charge_id'             => $this->get_charge_id_from_intent( $payment_intent ),
			'_payment_method_id'     => $this->get_payment_method_id_from_intent( $payment_intent ),
			// The event's raw, lowercase currency.
			'_wcpay_intent_currency' => isset( $payment_intent['currency'] ) ? (string) $payment_intent['currency'] : '',
			'_stripe_mandate_id'     => $this->get_mandate_id_from_intent( $payment_intent ),
		);
		foreach ( $values as $key => $value ) {
			if ( '' !== $value && '0' !== $value ) {
				$order->update_meta_data( $key, $value );
			}
		}

		foreach ( WooPaymentsOrderEffects::webhook_fee_meta( $payment_intent, $this->get_first_charge_from_intent( $payment_intent ) ) as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();
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
		return false !== $this->legacy_proxy->call_function( 'get_transient', $this->get_processed_event_transient_name( $event_id ) );
	}

	/**
	 * Record that an event ID was processed so later re-deliveries are skipped.
	 *
	 * @param string $event_id Event ID.
	 */
	private function mark_event_processed( string $event_id ): void {
		$this->legacy_proxy->call_function( 'set_transient', $this->get_processed_event_transient_name( $event_id ), 1, self::PROCESSED_EVENT_TRANSIENT_TTL );
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
	 * Tell whether a WooPayments event type is handled by the neutral lifecycle path.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	private function is_lifecycle_event_type( string $event_type ): bool {
		return in_array(
			$event_type,
			array(
				'payment_intent.succeeded',
				'payment_intent.payment_failed',
				'payment_intent.canceled',
				'payment_intent.amount_capturable_updated',
				'charge.expired',
			),
			true
		);
	}

	/**
	 * Apply charge-derived display title data before WooCommerce writes completion notes.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object Provider object.
	 */
	private function apply_completed_payment_method_display_title( WC_Order $order, array $event_object ): void {
		$this->get_order_effect_applier()->apply_payment_method_display_title(
			$order,
			$event_object,
			$this->get_account_service()->get_account_country()
		);
	}

	/**
	 * Get the WooPayments order effect applier.
	 *
	 * @return WooPaymentsOrderEffectApplier
	 */
	private function get_order_effect_applier(): WooPaymentsOrderEffectApplier {
		if ( null === $this->order_effect_applier ) {
			$this->order_effect_applier = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		}

		return $this->order_effect_applier;
	}

	/**
	 * Build the neutral lifecycle event for a succeeded payment intent.
	 *
	 * @param array<string,mixed> $event_object Provider intent object.
	 * @param WC_Order            $order        Order object.
	 * @return PaymentLifecycleEvent
	 */
	private function build_succeeded_lifecycle_event( array $event_object, WC_Order $order ): PaymentLifecycleEvent {
		$charge         = $this->get_first_charge_from_intent( $event_object );
		$completed_note = $this->get_completed_payment_note_data_from_intent( $event_object, $order );
		$meta           = $this->without_empty_values(
			array(
				'_intent_id'             => $this->get_object_id( $event_object ),
				'_charge_id'             => $this->get_charge_id_from_intent( $event_object ),
				'_payment_method_id'     => $this->get_payment_method_id_from_intent( $event_object ),
				'_intention_status'      => isset( $event_object['status'] ) ? (string) $event_object['status'] : '',
				// Plugin 11.1.0 stores the webhook's raw, lowercase currency (class-wc-payments-webhook-processing-service.php:497,514).
				'_wcpay_intent_currency' => isset( $event_object['currency'] ) ? (string) $event_object['currency'] : '',
				'_stripe_mandate_id'     => $this->get_mandate_id_from_intent( $event_object ),
				'_wcpay_mode'            => $this->get_account_service()->get_order_mode(),
				'_wcpay_ipp_channel'     => $this->get_ipp_channel_from_intent( $event_object ),
			)
		);
		if ( ! empty( $charge ) ) {
			$settlement_meta = $this->get_order_data_service()->get_settlement_exchange_rate_order_meta(
				$order,
				$charge,
				$this->get_account_service()->get_account_default_currency()
			);
			$meta            = array_merge(
				$meta,
				WooPaymentsOrderEffects::completed_charge_meta(
					$event_object,
					$charge,
					$settlement_meta,
					false,
					$order->has_status( OrderStatus::ON_HOLD )
				),
				WooPaymentsOrderEffects::completed_charge_payment_method_backfill_meta(
					$charge,
					$this->order_has_placeholder_payment_method_details( $order ),
					(string) $order->get_meta( '_wcpay_payment_transaction_id', true )
				)
			);
		}

		return new PaymentLifecycleEvent(
			PaymentLifecycleEvent::STATUS_COMPLETED,
			$this->get_object_id( $event_object ),
			$meta,
			array(),
			$completed_note['note'],
			$completed_note['type'],
			$completed_note['equivalents']
		);
	}

	/**
	 * Build a neutral lifecycle event for a supported WooPayments webhook type.
	 *
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event_object Provider object.
	 * @param WC_Order            $order Order object.
	 * @return PaymentLifecycleEvent|null
	 */
	private function build_lifecycle_event( string $event_type, array $event_object, WC_Order $order ): ?PaymentLifecycleEvent {
		switch ( $event_type ) {
			case 'payment_intent.payment_failed':
				if ( ! $this->should_process_payment_failed_event( $event_object, $order ) ) {
					return null;
				}

				$last_payment_error  = isset( $event_object['last_payment_error'] ) && is_array( $event_object['last_payment_error'] ) ? $event_object['last_payment_error'] : array();
				$payment_method      = isset( $last_payment_error['payment_method'] ) && is_array( $last_payment_error['payment_method'] ) ? $last_payment_error['payment_method'] : array();
				$payment_method_type = isset( $payment_method['type'] ) && is_string( $payment_method['type'] ) ? $payment_method['type'] : '';
				$intent_id           = $this->get_object_id( $event_object );
				$charge_id           = $this->get_charge_id_from_intent( $event_object );
				$note_candidates     = 'card_present' === $payment_method_type
					? $this->get_order_note_service()->format_terminal_payment_failed_note_candidates( $order, $intent_id, $charge_id, $last_payment_error )
					: $this->get_order_note_service()->format_payment_failed_note_candidates( $order, $intent_id, $charge_id, $last_payment_error );

				return new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_FAILED,
					$intent_id,
					$this->without_empty_values(
						array(
							'_intent_id'        => $intent_id,
							'_intention_status' => isset( $event_object['status'] ) ? (string) $event_object['status'] : '',
						)
					),
					array(),
					$note_candidates[0],
					PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_FAILED,
					$note_candidates
				);

			case 'charge.expired':
				$charge_id       = $this->get_object_id( $event_object );
				$intent_id       = isset( $event_object['payment_intent'] ) && is_string( $event_object['payment_intent'] )
					? $event_object['payment_intent']
					: (string) $order->get_meta( '_intent_id', true );
				$note_candidates = $this->get_order_note_service()->format_capture_expired_note_candidates( $intent_id, $charge_id );

				// The stored intention status still says requires_capture; only the live
				// intent knows its post-expiry status (canceled), and leaving the stale
				// value keeps capture/cancel actions offered against a dead intent. The
				// fetch is load-bearing: on failure the exception propagates and the
				// delivery fails, as in the plugin. charge.expired is not a retried type,
				// so the event gets that one attempt.
				$expired_intent = $this->api_client->get_payment_intention( $intent_id );

				$meta = array(
					'_charge_id'        => $charge_id,
					'_intention_status' => isset( $expired_intent['status'] ) ? (string) $expired_intent['status'] : '',
				);
				if ( 'review' === (string) $order->get_meta( '_wcpay_fraud_outcome_status', true ) ) {
					$meta['_wcpay_fraud_meta_box_type'] = 'review_expired';
				}

				return new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
					$charge_id,
					$this->without_empty_values( $meta ),
					array(),
					$note_candidates[0],
					PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED,
					$note_candidates
				);
		}

		return null;
	}

	/**
	 * Tell whether a payment_intent.payment_failed event is actionable.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @param WC_Order            $order        Order resolved for the event.
	 * @return bool
	 */
	private function should_process_payment_failed_event( array $event_object, WC_Order $order ): bool {
		if ( ! $this->is_actionable_payment_failure( $event_object ) ) {
			return false;
		}

		// A failure from a superseded attempt (the shopper re-paid with another method) must not flip the order to
		// failed or overwrite its payment meta with the stale intent. Terminal (card_present) intents are exempt: their
		// payment method is created at the reader and is never the one stored on the order.
		$payment_method      = $event_object['last_payment_error']['payment_method'];
		$payment_method_type = (string) $payment_method['type'];

		return 'card_present' === $payment_method_type || (string) $payment_method['id'] === (string) $order->get_meta( '_payment_method_id', true );
	}

	/**
	 * Tell whether a payment_intent.payment_failed event names a failed payment method of a type the order is failed for.
	 *
	 * A failure that names no payment method changes nothing, on the order's own payment or on another charge.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return bool
	 */
	private function is_actionable_payment_failure( array $event_object ): bool {
		$last_payment_error  = $event_object['last_payment_error'] ?? null;
		$payment_method      = is_array( $last_payment_error ) ? ( $last_payment_error['payment_method'] ?? null ) : null;
		$payment_method_type = is_array( $payment_method ) && isset( $payment_method['type'] ) && is_string( $payment_method['type'] ) ? $payment_method['type'] : '';

		if ( ! in_array(
			$payment_method_type,
			array(
				'card',
				'card_present',
				'us_bank_account',
				'au_becs_debit',
				'wechat_pay',
			),
			true
		) ) {
			return false;
		}

		$payment_method_id = is_array( $payment_method ) && isset( $payment_method['id'] ) && is_string( $payment_method['id'] ) ? $payment_method['id'] : '';

		return '' !== $payment_method_id;
	}

	/**
	 * Get a provider object's ID.
	 *
	 * @param array<string,mixed> $event_object Provider object.
	 * @return string
	 */
	private function get_object_id( array $event_object ): string {
		return isset( $event_object['id'] ) ? (string) $event_object['id'] : '';
	}

	/**
	 * Get the first charge ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_charge_id_from_intent( array $event_object ): string {
		$charge_id = $event_object['charges']['data'][0]['id'] ?? '';

		return is_string( $charge_id ) ? $charge_id : '';
	}

	/**
	 * Get the payment method ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_payment_method_id_from_intent( array $event_object ): string {
		$payment_method_id = $event_object['charges']['data'][0]['payment_method'] ?? $event_object['payment_method'] ?? '';

		// An event created with an expanded payment_method carries the whole object.
		if ( is_array( $payment_method_id ) ) {
			$payment_method_id = $payment_method_id['id'] ?? '';
		}

		return is_string( $payment_method_id ) ? $payment_method_id : '';
	}

	/**
	 * Get the mandate ID from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_mandate_id_from_intent( array $event_object ): string {
		$mandate_id = $event_object['charges']['data'][0]['payment_method_details']['card']['mandate'] ?? '';

		return is_string( $mandate_id ) ? $mandate_id : '';
	}

	/**
	 * Get the IPP channel from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return string
	 */
	private function get_ipp_channel_from_intent( array $event_object ): string {
		$ipp_channel      = $event_object['metadata']['ipp_channel'] ?? '';
		$allowed_channels = array( 'mobile_pos', 'mobile_store_management' );

		return is_string( $ipp_channel ) && in_array( $ipp_channel, $allowed_channels, true ) ? $ipp_channel : '';
	}

	/**
	 * Get the completed-payment lifecycle note and note type from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @param WC_Order            $order        Order object.
	 * @return array{note:string,type:string,equivalents:string[]}
	 */
	private function get_completed_payment_note_data_from_intent( array $event_object, WC_Order $order ): array {
		$charge          = $this->get_first_charge_from_intent( $event_object );
		$note_candidates = $this->get_order_note_service()->format_payment_success_note_candidates(
			$order,
			$this->get_object_id( $event_object ),
			$this->get_charge_id_from_intent( $event_object ),
			WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null )
		);

		return array(
			'note'        => $note_candidates[0],
			'type'        => PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS,
			'equivalents' => $note_candidates,
		);
	}

	/**
	 * Tell whether payment method details are still an empty placeholder.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function order_has_placeholder_payment_method_details( WC_Order $order ): bool {
		$details = $order->get_meta( '_wcpay_payment_method_details', true );
		if ( '' === $details || null === $details || array() === $details ) {
			return true;
		}

		return is_string( $details ) && array() === json_decode( $details, true );
	}

	/**
	 * Get the WooPayments order note service.
	 *
	 * @return WooPaymentsOrderNoteService
	 */
	private function get_order_note_service(): WooPaymentsOrderNoteService {
		if ( null === $this->order_note_service ) {
			$this->order_note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		}

		return $this->order_note_service;
	}

	/**
	 * Get the WooPayments early fraud warning event handler.
	 *
	 * @return WooPaymentsEarlyFraudWarningEventHandler
	 */
	private function get_early_fraud_warning_event_handler(): WooPaymentsEarlyFraudWarningEventHandler {
		if ( null === $this->early_fraud_warning_event_handler ) {
			$this->early_fraud_warning_event_handler = wc_get_container()->get( WooPaymentsEarlyFraudWarningEventHandler::class );
		}

		return $this->early_fraud_warning_event_handler;
	}

	/**
	 * Remove empty string meta values.
	 *
	 * @param array<string,string> $values Raw values.
	 * @return array<string,string>
	 */
	private function without_empty_values( array $values ): array {
		return array_filter(
			$values,
			static function ( string $value ): bool {
				return '' !== $value;
			}
		);
	}

	/**
	 * Get the WooPayments admin menu badge service.
	 *
	 * @return WooPaymentsAdminMenuBadgeService
	 */
	private function get_admin_menu_badge_service(): WooPaymentsAdminMenuBadgeService {
		if ( null === $this->admin_menu_badge_service ) {
			$this->admin_menu_badge_service = wc_get_container()->get( WooPaymentsAdminMenuBadgeService::class );
		}

		return $this->admin_menu_badge_service;
	}

	/**
	 * Get the webhook event order resolver.
	 *
	 * @return WooPaymentsEventOrderResolver
	 */
	private function get_event_order_resolver(): WooPaymentsEventOrderResolver {
		if ( null === $this->event_order_resolver ) {
			$this->event_order_resolver = wc_get_container()->get( WooPaymentsEventOrderResolver::class );
		}

		return $this->event_order_resolver;
	}

	/**
	 * Get the recorder of events on a charge that does not pay the order.
	 *
	 * @return WooPaymentsOtherChargeRecorder
	 */
	private function get_other_charge_recorder(): WooPaymentsOtherChargeRecorder {
		if ( null === $this->other_charge_recorder ) {
			$this->other_charge_recorder = wc_get_container()->get( WooPaymentsOtherChargeRecorder::class );
		}

		return $this->other_charge_recorder;
	}

	/**
	 * Get the WooPayments order data service.
	 *
	 * @return WooPaymentsOrderDataService
	 */
	private function get_order_data_service(): WooPaymentsOrderDataService {
		if ( null === $this->order_data_service ) {
			$this->order_data_service = wc_get_container()->get( WooPaymentsOrderDataService::class );
		}

		return $this->order_data_service;
	}

	/**
	 * Send the IPP customer receipt email for card-present successful payments.
	 *
	 * @param WC_Order            $order        Order object.
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return void
	 */
	private function maybe_send_ipp_receipt_email( WC_Order $order, array $event_object ): void {
		$charge = $this->get_first_charge_from_intent( $event_object );
		if ( ! $this->is_ipp_receipt_charge( $charge ) ) {
			return;
		}

		$email = $this->get_ipp_receipt_email();
		if ( ! $email instanceof WooPaymentsIppReceiptEmail ) {
			return;
		}

		$email->trigger( $order, $this->get_ipp_receipt_merchant_settings(), $charge );
	}

	/**
	 * Get the first charge from a PaymentIntent object.
	 *
	 * @param array<string,mixed> $event_object PaymentIntent object.
	 * @return array<string,mixed>
	 */
	private function get_first_charge_from_intent( array $event_object ): array {
		$charge = $event_object['charges']['data'][0] ?? array();

		return is_array( $charge ) ? $charge : array();
	}

	/**
	 * Tell whether a charge should send an IPP receipt email.
	 *
	 * @param array<string,mixed> $charge Charge payload.
	 * @return bool
	 */
	private function is_ipp_receipt_charge( array $charge ): bool {
		$type = $charge['payment_method_details']['type'] ?? '';

		return in_array( $type, array( 'card_present', 'interac_present' ), true );
	}

	/**
	 * Get the IPP receipt email from the WooCommerce mailer.
	 *
	 * @return WooPaymentsIppReceiptEmail|null
	 */
	private function get_ipp_receipt_email(): ?WooPaymentsIppReceiptEmail {
		if ( ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ WooPaymentsIppReceiptEmail::EMAIL_CLASS_KEY ] ?? null;

		return $email instanceof WooPaymentsIppReceiptEmail ? $email : null;
	}

	/**
	 * Get merchant settings used by the IPP receipt email.
	 *
	 * @return array<string,mixed>
	 */
	private function get_ipp_receipt_merchant_settings(): array {
		$account_service = $this->get_account_service();
		$support_address = $account_service->get_gateway_setting( 'account_business_support_address', array() );

		return array(
			'business_name' => (string) $account_service->get_gateway_setting( 'account_business_name', get_bloginfo( 'name' ) ),
			'support_info'  => array(
				'address' => is_array( $support_address ) ? $support_address : array(),
				'phone'   => (string) $account_service->get_gateway_setting( 'account_business_support_phone', '' ),
				'email'   => (string) $account_service->get_gateway_setting( 'account_business_support_email', get_option( 'admin_email', '' ) ),
			),
		);
	}

	/**
	 * Repair the payment token on an unpaid recurring order paid through a webhook.
	 *
	 * A recurring order can reach `payment_intent.succeeded` without a usable local
	 * token — WooPay checkouts and platform-side charges save the method at the
	 * provider, not locally. Without the repair, the next scheduled renewal finds no
	 * token and fails. Runs before the lifecycle apply on purpose: once this same
	 * event marks the order paid, the redelivery guard below would skip the repair.
	 *
	 * @param WC_Order            $order        Order the event resolved to.
	 * @param array<string,mixed> $event_object Provider intent object.
	 */
	private function repair_recurring_order_token( WC_Order $order, array $event_object ): void {
		$payment_method_id = $this->get_payment_method_id_from_intent( $event_object );
		$intent_status     = isset( $event_object['status'] ) ? (string) $event_object['status'] : '';
		if ( '' === $payment_method_id || ! WooPaymentsIntentCodec::is_authorized_native_intent_status( $intent_status ) ) {
			return;
		}

		if ( ! $this->is_recurring_order( $order ) ) {
			return;
		}

		// A paid order already had its token saved at checkout, so this is a
		// redelivered event. Saving again could re-point sibling subscriptions to a
		// card the customer has since replaced.
		if ( $order->is_paid() ) {
			return;
		}

		$token_service  = wc_get_container()->get( WooPaymentsTokenService::class );
		$previous_token = $token_service->get_active_token_for_order( $order );

		try {
			$token = $token_service->get_or_create_token_for_user( $payment_method_id, (int) $order->get_customer_id() );
			if ( ! $token instanceof WC_Payment_Token ) {
				return;
			}

			$token_service->attach_token_to_order( $order, $token );

			// Write the restored token through to every related subscription: WCS
			// copies the subscription's tokens into each new renewal order, so a
			// subscription left pointing at the replaced card would charge it on
			// the next renewal.
			$token_service->sync_related_subscriptions_payment_token(
				$order,
				$token,
				$payment_method_id,
				(string) $order->get_meta( '_stripe_customer_id', true )
			);

			if ( $previous_token instanceof WC_Payment_Token && $previous_token->get_id() !== $token->get_id() ) {
				$note = sprintf(
					/* translators: 1: Previous payment token ID, 2: New payment token ID. */
					__( 'WooPayments updated the subscription payment method token from token #%1$d to #%2$d after receiving a successful renewal payment webhook.', 'woocommerce' ),
					$previous_token->get_id(),
					$token->get_id()
				);
				wc_get_logger()->info( $note, array( 'source' => 'woopayments-subscriptions' ) );
				$order->add_order_note( $note );
			}
		} catch ( Throwable $exception ) {
			// The token save fetches the payment method from the platform; its error's message is the platform's text.
			wc_get_logger()->error(
				'Error when saving payment method from webhook.',
				array_merge(
					WooPaymentsLogger::get_failure_context( $exception ),
					array(
						'source'   => 'woopayments-subscriptions',
						'order_id' => $order->get_id(),
					)
				)
			);
			$order->add_order_note( __( 'Unable to save payment method for subscription. Please try again or use a different payment method.', 'woocommerce' ) );
		}
	}

	/**
	 * Tell whether an order belongs to a recurring payment.
	 *
	 * `wcs_order_contains_subscription()` deliberately excludes renewals, so both
	 * detectors participate, matching the extension's is_payment_recurring().
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function is_recurring_order( WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order->get_id() ) ) {
			return true;
		}

		return function_exists( 'wcs_order_contains_renewal' ) && (bool) wcs_order_contains_renewal( $order->get_id() );
	}

	/**
	 * Get the account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Tell whether the webhook livemode does not match native runtime mode, logging one error line when it does.
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

		if ( $this->is_native_live_mode() === (bool) $event['livemode'] ) {
			return false;
		}

		try {
			wc_get_logger()->error(
				sprintf( 'Webhook event mode did not match the gateway mode (event ID: %s)', $this->get_event_id( $event ) ),
				array( 'source' => 'native-payments-webhook' )
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		return true;
	}

	/**
	 * Tell whether native WooPayments is in live mode.
	 *
	 * The same mode every other native reader uses (WooPaymentsAccountService::is_test_mode_enabled(): test-mode
	 * onboarding, dev mode and the wcpay_test_mode filter included), as the client checks WC_Payments::mode()->is_live().
	 *
	 * @return bool
	 */
	private function is_native_live_mode(): bool {
		return ! $this->get_account_service()->is_test_mode_enabled();
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
	 * Refuse a Stripe Billing invoice event that reaches native WooPayments while the Stripe Billing module is not loaded.
	 *
	 * Client 11.1.0 loads its event handler without WooCommerce Subscriptions too; its subscription lookup then finds nothing,
	 * so it refuses the event with these reasons (`class-wc-payments-subscriptions-event-handler.php:79,138,233`), the
	 * webhook answers 400 and nothing is recorded. As on the client, the webhook route or the failed-event job that catches
	 * the refusal logs it, so it is not logged here.
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
	 * Run a WooPayments-compatible webhook delivery hook.
	 *
	 * @param string              $hook       Hook name.
	 * @param string              $event_type Event type.
	 * @param array<string,mixed> $event      Event payload.
	 */
	private function run_delivery_hook( string $hook, string $event_type, array $event ): void {
		try {
			$this->legacy_proxy->call_function( 'do_action', $hook, $event_type, $event );
		} catch ( Throwable $exception ) {
			// Logging is best-effort: a failing log handler must not turn an isolated hook failure into a failed event.
			try {
				// A callback can let a platform error out, directly or wrapped, so its message is not logged.
				wc_get_logger()->error(
					'A WooPayments webhook delivery hook callback failed.',
					array_merge(
						WooPaymentsLogger::get_failure_context( $exception ),
						array(
							'source' => 'native-payments-webhook',
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
