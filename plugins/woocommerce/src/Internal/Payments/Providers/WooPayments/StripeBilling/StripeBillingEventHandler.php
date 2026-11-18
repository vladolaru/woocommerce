<?php
/**
 * StripeBillingEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLockRefusedException;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\OtherChargeRecorder;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\PaymentIntentEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffects;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Stripe Billing invoice events: `invoice.upcoming`, `invoice.paid` and `invoice.payment_failed`.
 *
 * Follows client 11.1.0 `includes/subscriptions/class-wc-payments-subscriptions-event-handler.php`. The event ingestor
 * runs it inside its usual processing (the mode check), and a paid invoice's payment intent is recorded under the order
 * payment lock.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingEventHandler {

	/**
	 * Failed renewal attempts after which the subscription is cancelled (client 11.1.0 `MAX_RETRIES`,
	 * class-wc-payments-subscriptions-event-handler.php:24).
	 */
	private const MAX_RETRIES = 4;

	/**
	 * Invoice service.
	 *
	 * @var StripeBillingInvoiceService
	 */
	private StripeBillingInvoiceService $invoice_service;

	/**
	 * Subscription service.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private StripeBillingSubscriptionService $subscription_service;

	/**
	 * Platform API client, for the intent and charge reads.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Payment intent event handler, which records a succeeded payment intent on its order.
	 *
	 * @var PaymentIntentEventHandler
	 */
	private PaymentIntentEventHandler $payment_intent_event_handler;

	/**
	 * Account service, for the mode a renewal is recorded in.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Module logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Payment lifecycle service, for reading a renewal order past this request's caches.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Recorder of an invoice collected on a renewal that records another payment.
	 *
	 * @var OtherChargeRecorder
	 */
	private OtherChargeRecorder $other_charge_recorder;

	/**
	 * Webhook event order resolver, for the paid test the event ownership uses.
	 *
	 * @var EventOrderResolver
	 */
	private EventOrderResolver $event_order_resolver;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingInvoiceService      $invoice_service              Invoice service.
	 * @param StripeBillingSubscriptionService $subscription_service         Subscription service.
	 * @param Client                           $api_client                   Platform API client.
	 * @param PaymentIntentEventHandler        $payment_intent_event_handler Payment intent event handler.
	 * @param WooPaymentsAccountService        $account_service              Account service.
	 * @param WooPaymentsLogger                $logger                       Module logger.
	 * @param OrderPaymentLifecycleService     $lifecycle_service            Payment lifecycle service.
	 * @param OtherChargeRecorder              $other_charge_recorder        Recorder of events on another charge.
	 * @param EventOrderResolver               $event_order_resolver         Webhook event order resolver.
	 */
	final public function init( StripeBillingInvoiceService $invoice_service, StripeBillingSubscriptionService $subscription_service, Client $api_client, PaymentIntentEventHandler $payment_intent_event_handler, WooPaymentsAccountService $account_service, WooPaymentsLogger $logger, OrderPaymentLifecycleService $lifecycle_service, OtherChargeRecorder $other_charge_recorder, EventOrderResolver $event_order_resolver ): void {
		$this->invoice_service              = $invoice_service;
		$this->subscription_service         = $subscription_service;
		$this->api_client                   = $api_client;
		$this->payment_intent_event_handler = $payment_intent_event_handler;
		$this->account_service              = $account_service;
		$this->logger                       = $logger;
		$this->lifecycle_service            = $lifecycle_service;
		$this->other_charge_recorder        = $other_charge_recorder;
		$this->event_order_resolver         = $event_order_resolver;
	}

	/**
	 * Handle one invoice event.
	 *
	 * An event with missing data, or about a subscription this store does not have, is refused with an
	 * `InvalidArgumentException`, which the webhook answers with 400 and does not retry. The webhook route or the
	 * failed-event job that catches the refusal logs it. Other failures, such as a platform call that fails, are passed on:
	 * an invoice.paid is retried, invoice.upcoming and invoice.payment_failed get this one attempt.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws InvalidArgumentException When the event has missing data or names no subscription of this store.
	 */
	public function handle_event( array $event ): void {
		$event_type = isset( $event['type'] ) && is_string( $event['type'] ) ? $event['type'] : '';

		try {
			switch ( $event_type ) {
				case 'invoice.upcoming':
					$this->handle_invoice_upcoming( $event );
					break;
				case 'invoice.paid':
					$this->handle_invoice_paid( $event );
					break;
				case 'invoice.payment_failed':
					$this->handle_invoice_payment_failed( $event );
					break;
			}
		} catch ( StripeBillingException $exception ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new InvalidArgumentException( $exception->getMessage(), 0, $exception );
		}
	}

	/**
	 * Bring a subscription in line with its Stripe subscription before Stripe bills it: next payment date and items.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 * @throws RuntimeException When the Stripe subscription cannot be read.
	 */
	private function handle_invoice_upcoming( array $event ): void {
		$event_object          = $this->get_event_array( $event, array( 'data', 'object' ) );
		$wcpay_subscription_id = (string) $this->get_event_property( $event_object, array( 'subscription' ) );

		if ( SubscriptionMethodPolicy::is_duplicate_site() ) {
			$this->log_skipped_webhook_due_to_staging( 'invoice.upcoming', $wcpay_subscription_id );
			return;
		}

		$wcpay_discounts = $this->get_event_property( $event_object, array( 'discounts' ) );
		$wcpay_lines     = $this->get_event_array( $event_object, array( 'lines', 'data' ) );
		$subscription    = $this->subscription_service->get_subscription_from_wcpay_subscription_id( $wcpay_subscription_id );

		if ( null === $subscription ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( __( 'Cannot find subscription to handle the "invoice.upcoming" event.', 'woocommerce' ), StripeBillingException::INVALID_EVENT_DATA );
		}

		// A subscription moved off WooPayments keeps its Stripe ID until Stripe confirms the cancel: try the cancel again, and
		// leave the other payment method's schedule and Stripe's items as they are.
		if ( ! $this->subscription_service->is_wcpay_subscription( $subscription ) ) {
			$this->subscription_service->maybe_cancel_subscription( $subscription, $subscription->get_payment_method() );
			return;
		}

		$wcpay_subscription = $this->subscription_service->get_wcpay_subscription( $subscription );

		// No next payment is expected, so Stripe must not bill.
		if ( 0 === $this->get_time( $subscription, 'next_payment' ) ) {
			if ( ! $subscription->has_status( 'on-hold' ) && 0 !== $this->get_time( $subscription, 'end' ) ) {
				$this->subscription_service->cancel_subscription( $subscription );
			} elseif ( $this->subscription_service->suspend_subscription( $subscription ) ) {
				// A failed pause leaves its own note and log line (StripeBillingSubscriptionService::suspend_subscription()).
				$subscription->add_order_note( __( 'Suspended WooPayments Subscription in invoice.upcoming webhook handler because subscription next_payment date is 0.', 'woocommerce' ) );
				$this->logger->log(
					sprintf(
						'Suspended WooPayments Subscription in invoice.upcoming webhook handler because subscription next_payment date is 0. WC ID: %d; WooPayments ID: %s.',
						$subscription->get_id(),
						$wcpay_subscription_id
					)
				);
			}

			return;
		}

		if ( null === $wcpay_subscription ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new RuntimeException( sprintf( 'Stripe subscription %s could not be read to handle the "invoice.upcoming" event.', $wcpay_subscription_id ) );
		}

		$subscription->add_order_note(
			sprintf(
				/* translators: %s: scheduled payment date and time. */
				__( 'Next automatic payment scheduled for %s.', 'woocommerce' ),
				get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) ( $wcpay_subscription['current_period_end'] ?? 0 ) ), wc_date_format() . ' ' . wc_time_format() )
			)
		);

		$this->subscription_service->update_dates_to_match_wcpay_subscription( $wcpay_subscription, $subscription );
		$this->invoice_service->update_wcpay_subscription_to_match( $wcpay_lines, is_array( $wcpay_discounts ) ? $wcpay_discounts : array(), $subscription );
	}

	/**
	 * Record the renewal a paid invoice billed: find or create the renewal order, mark it paid, and tell the platform about it.
	 *
	 * The invoice of the subscription's first order is ignored: checkout records that payment. A second delivery of the
	 * invoice finds its renewal order by the invoice ID and, once the order is paid, does not record the payment again.
	 *
	 * A RuntimeException from the recording fails the event, so it is retried.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 */
	private function handle_invoice_paid( array $event ): void {
		$event_object          = $this->get_event_array( $event, array( 'data', 'object' ) );
		$wcpay_subscription_id = (string) $this->get_event_property( $event_object, array( 'subscription' ) );

		if ( SubscriptionMethodPolicy::is_duplicate_site() ) {
			$this->log_skipped_webhook_due_to_staging( 'invoice.paid', $wcpay_subscription_id );
			return;
		}

		$wcpay_invoice_id = (string) $this->get_event_property( $event_object, array( 'id' ) );
		$subscription     = $this->subscription_service->get_subscription_from_wcpay_subscription_id( $wcpay_subscription_id );

		if ( null === $subscription ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( __( 'Cannot find subscription for the incoming "invoice.paid" event.', 'woocommerce' ), StripeBillingException::INVALID_EVENT_DATA );
		}

		$order = $this->apply_paid_invoice( $subscription, $wcpay_invoice_id, $event_object );
		if ( null === $order ) {
			return;
		}

		$invoice = $this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
		$this->invoice_service->update_charge_details( $invoice, $order->get_id() );
		$this->invoice_service->update_transaction_details( $invoice, $order );
	}

	/**
	 * Apply a paid invoice to its renewal order and the subscription, deciding on the renewal read again under the order
	 * payment lock.
	 *
	 * A renewal that needs payment completes when the invoice's amount and currency match its total
	 * (StripeBillingInvoiceService::does_invoice_payment_match_order_total()) and is otherwise held with its payment recorded;
	 * a renewal that no longer needs payment gets its payment recorded and a note, or only a note when it records another
	 * payment or can no longer refund the invoice's amount (see apply_paid_invoice_under_lock()). An invoice without its
	 * amount paid or currency is refused as invalid event data before any renewal is found or created.
	 *
	 * @param WC_Order            $subscription     Subscription.
	 * @param string              $wcpay_invoice_id Invoice ID.
	 * @param array<string,mixed> $event_object     Invoice from the event.
	 * @return WC_Order|null The renewal order, or null for the invoice of the subscription's first order.
	 * @throws OrderPaymentLockRefusedException When another operation holds the order payment lock; nothing is applied, and the event is retried.
	 */
	private function apply_paid_invoice( WC_Order $subscription, string $wcpay_invoice_id, array $event_object ): ?WC_Order {
		if ( $this->invoice_service->get_subscription_invoice_id( $subscription ) === $wcpay_invoice_id ) {
			return null;
		}

		// Client 11.3.0 reads both before it finds or creates the renewal (class-wc-payments-subscriptions-event-handler.php:146-147).
		$amount_paid      = $this->get_event_property( $event_object, array( 'amount_paid' ) );
		$invoice_currency = $this->get_event_property( $event_object, array( 'currency' ) );

		$order     = $this->get_or_create_renewal_order( $subscription, $wcpay_invoice_id, __( 'Unable to generate renewal order for subscription on the "invoice.paid" event.', 'woocommerce' ) );
		$intent_id = isset( $event_object['payment_intent'] ) && is_string( $event_object['payment_intent'] ) ? $event_object['payment_intent'] : '';
		$intent    = '' !== $intent_id ? $this->get_payment_intent( $intent_id ) : null;

		$vocabulary         = new WooPaymentsPersistenceVocabulary();
		$order_payment_lock = wc_get_container()->get( OrderPaymentLock::class );
		$lock_token         = $order_payment_lock->claim( $order, $vocabulary, '' !== $intent_id ? $intent_id : $wcpay_invoice_id, 'payment status update' );
		if ( null === $lock_token ) {
			$order_payment_lock->log_refusal( $order, $vocabulary, 'payment status update' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is built in the exception from an order ID and a fixed operation name, not HTML output.
			throw new OrderPaymentLockRefusedException( $order->get_id(), 'payment status update' );
		}

		try {
			// Decide on what the renewal is now: another delivery, or the payment of a failed renewal, may have changed it.
			$this->lifecycle_service->reread_order_from_data_store( $order );
			$subscription = $this->apply_paid_invoice_under_lock( $subscription, $order, $wcpay_invoice_id, $event_object, $intent_id, $intent, $amount_paid, $invoice_currency );
		} finally {
			$order_payment_lock->release( $order, $vocabulary, $lock_token );
		}

		$this->invoice_service->mark_pending_invoice_paid_for_subscription( $subscription );

		return $order;
	}

	/**
	 * Apply a paid invoice to its renewal read under the order payment lock the caller holds.
	 *
	 * A renewal that no longer needs payment is decided first, before anything is written:
	 * - one that stores an uncaptured authorization as its payment gets the invoice's payment recorded in its place, as any
	 *   such renewal does, and a note naming the authorization. When the invoice's intent cannot be read, a note says not to
	 *   capture the authorization and the event fails, so it is retried and the payment recorded once it can be read;
	 * - one that records another payment (another payment method, or a paid renewal whose transaction ID names another
	 *   payment) gets only a note: the invoice's payment is not written over it;
	 * - one that is neither paid nor payable and can no longer refund the invoice's amount, such as a refunded renewal,
	 *   gets only the note that sends the refund to the charge's transaction page, unless it already recorded this invoice;
	 * - one that is neither paid nor payable, such as a cancelled renewal, gets its payment recorded and a note saying where
	 *   to refund it, unless it already recorded this invoice.
	 *
	 * @param WC_Order                 $subscription     Subscription.
	 * @param WC_Order                 $order            Renewal order, read under the lock.
	 * @param string                   $wcpay_invoice_id Invoice ID.
	 * @param array<string,mixed>      $event_object     Invoice from the event.
	 * @param string                   $intent_id        The invoice's payment intent ID, or an empty string.
	 * @param array<string,mixed>|null $intent           The payment intent, or null when the invoice has none or it cannot be read.
	 * @param mixed                    $amount_paid      The invoice's `amount_paid`.
	 * @param mixed                    $invoice_currency The invoice's currency.
	 * @return WC_Order The subscription, read again after a completed renewal.
	 * @throws RuntimeException When the renewal order is still unpaid after applying its payment, or when the payment of a renewal holding an uncaptured authorization cannot be read.
	 */
	private function apply_paid_invoice_under_lock( WC_Order $subscription, WC_Order $order, string $wcpay_invoice_id, array $event_object, string $intent_id, ?array $intent, $amount_paid, $invoice_currency ): WC_Order {
		$charge_id     = $this->get_invoice_charge_id( $event_object, $intent );
		$invoice_facts = array(
			'object_id' => $wcpay_invoice_id,
			'status'    => 'paid',
			'intent_id' => $intent_id,
			'charge_id' => $charge_id,
			'amount'    => WooPaymentsCurrencyUtils::amount_from_minor_units( is_numeric( $amount_paid ) ? (int) $amount_paid : 0, is_string( $invoice_currency ) ? $invoice_currency : '' ),
			'currency'  => is_string( $invoice_currency ) ? $invoice_currency : '',
		);

		$authorization = '' !== $intent_id && ! $order->needs_payment() && $this->stores_one_uncaptured_authorization( $order )
			? array(
				'authorization_intent_id' => (string) $order->get_meta( '_intent_id', true ),
				'authorization_charge_id' => (string) $order->get_meta( '_charge_id', true ),
			)
			: null;

		if ( null === $authorization && $this->records_another_payment( $order, $intent_id, $charge_id ) ) {
			$this->other_charge_recorder->record( $order, 'invoice.paid', $invoice_facts + $this->get_another_payment_note_facts( $subscription, $order ) );
			return $subscription;
		}

		if ( null !== $authorization && null === $intent ) {
			// Today's writes need the intent; without them the authorization stays capturable beside the collected invoice.
			// The note is saved before the throw, so it stays if no retry ever reads the intent.
			$this->add_invoice_note_once( $order, WooPaymentsOrderNoteService::INVOICE_NOTE_PAYMENT_NOT_READ, $invoice_facts + $authorization, 'stripe_billing_invoice_unread|' . $wcpay_invoice_id );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new RuntimeException( sprintf( 'The payment of invoice %1$s could not be read for renewal order #%2$d, which holds an uncaptured authorization.', $wcpay_invoice_id, $order->get_id() ) );
		}

		$already_recorded = $intent_id === (string) $order->get_meta( '_intent_id', true )
			|| WooPaymentsOrderEffects::transaction_id_names_payment( $order, $intent_id, $charge_id );
		$recorded_unpaid  = false;
		$was_succeeded    = 'succeeded' === $order->get_meta( '_intention_status', true );

		if ( null === $authorization && ! $already_recorded && $this->cannot_refund_the_invoice_amount( $order, $intent_id, $amount_paid ) ) {
			// A refund from this order would be refused, so its charge is left to its transaction page, which then refunds at the platform.
			$note_facts = array( 'wording' => WooPaymentsOrderNoteService::INVOICE_NOTE_REFUND_FROM_TRANSACTION_PAGE ) + $this->get_collected_invoice_note_facts( $subscription, $order );
			$this->other_charge_recorder->record( $order, 'invoice.paid', $invoice_facts + $note_facts );
			return $subscription;
		}

		if ( null !== $intent ) {
			// Recording writes this mode too, after the success note reads it; a renewal has none stored, so it is set first.
			$order->update_meta_data( '_wcpay_mode', $this->account_service->get_order_mode() );
			if ( isset( $intent['customer'] ) && is_string( $intent['customer'] ) && '' !== $intent['customer'] ) {
				$order->update_meta_data( '_stripe_customer_id', $intent['customer'] );
			}
			$order->save();
		}

		if ( ! $order->needs_payment() && null !== $intent && $order->is_paid() ) {
			// A paid renewal that records no other payment, such as one paid by its new payment method, gets the payment's
			// success note and its Fee details job once; its status does not move, and a payment that differs from its total
			// adds no note to a paid order. One that stored an uncaptured authorization gets the payment as its capture.
			$this->payment_intent_event_handler->apply_succeeded_payment_intent_under_lock(
				$order,
				$intent,
				$this->invoice_service->does_invoice_payment_match_order_total( $order, $amount_paid, $invoice_currency )
					? null
					: $this->get_invoice_hold_note_candidates( $order, $wcpay_invoice_id, $amount_paid, $invoice_currency )
			);
		} elseif ( ! $order->needs_payment() ) {
			// Client 11.3.0 only attaches the payment to a renewal that no longer needs payment
			// (class-wc-payments-subscriptions-event-handler.php:200-203): its status and notes stay as they are.
			if ( null !== $intent ) {
				$this->payment_intent_event_handler->record_succeeded_payment_intent_under_lock( $order, $intent );
				$recorded_unpaid = true;
			} elseif ( '' !== $intent_id ) {
				$order->add_order_note( __( 'The payment info couldn\'t be added to the order.', 'woocommerce' ) );
			}
		} elseif ( ! $this->invoice_service->does_invoice_payment_match_order_total( $order, $amount_paid, $invoice_currency ) ) {
			// The renewal is held and its payment recorded; the subscription is not put on hold or reactivated (client :163-172).
			$hold_note_candidates = $this->get_invoice_hold_note_candidates( $order, $wcpay_invoice_id, $amount_paid, $invoice_currency );
			if ( null !== $intent ) {
				$this->payment_intent_event_handler->apply_succeeded_payment_intent_under_lock( $order, $intent, $hold_note_candidates );
			} else {
				$this->hold_renewal_without_intent( $order, $intent_id, $event_object, $hold_note_candidates );
			}
		} else {
			// Put the subscription on hold first, as a renewal does, so WooCommerce Subscriptions records the payment on it. Stripe already billed it, so neither change reaches Stripe.
			// The hold is for a subscription WooPayments bills that Subscriptions lets be held. Any other, such as one moved to
			// another payment method or a cancelled one whose Stripe cancel failed, only gets the payment and a note.
			$can_hold            = $this->subscription_service->is_wcpay_subscription( $subscription ) && $this->can_be_put_on_hold( $subscription );
			$subscription_status = $subscription->get_status();
			try {
				$this->subscription_service->run_without_stripe_sync(
					function () use ( $subscription, $order, $intent, $intent_id, $event_object, $can_hold ): void {
						if ( $can_hold ) {
							$subscription->update_status( 'on-hold' );
						}

						if ( null !== $intent ) {
							$this->payment_intent_event_handler->apply_succeeded_payment_intent_under_lock( $order, $intent );
						} else {
							$this->complete_order_without_intent( $order, $intent_id, $event_object );
						}
					}
				);
			} finally {
				// Completing the order activated another instance of the subscription; saving this one would undo that.
				$subscription = $this->get_fresh_subscription( $subscription );
				$this->reactivate_subscription_of_paid_renewal( $subscription, $order );
			}

			if ( $order->needs_payment() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
				throw new RuntimeException( sprintf( 'Renewal order #%1$d is still unpaid after applying invoice %2$s.', $order->get_id(), $wcpay_invoice_id ) );
			}

			if ( ! $can_hold ) {
				// The status the invoice found: Subscriptions may change it as the payment is recorded.
				$facts = $invoice_facts + array( 'subscription_status' => $subscription_status ) + $this->get_collected_invoice_note_facts( $subscription, $order );
				$this->add_invoice_note_once( $order, WooPaymentsOrderNoteService::INVOICE_NOTE_SUBSCRIPTION_NOT_HELD, $facts, 'stripe_billing_invoice_collected|' . $wcpay_invoice_id );

				// Stripe still charges a subscription it may no longer bill: the cancel job decides, so no request goes out here.
				$this->subscription_service->request_stripe_subscription_cancel( $subscription );
			}
		}

		if ( null !== $intent && ! $was_succeeded ) {
			// Client 11.1.0 attaches the fetched intent here (class-wc-payments-invoice-service.php:289-301): the transaction id,
			// which payment_complete() leaves alone on a paid order, and the currency upper case, where the webhook writes it
			// lower case.
			$order->set_transaction_id( $intent_id );
			$order->update_meta_data( '_wcpay_intent_currency', strtoupper( isset( $intent['currency'] ) && is_string( $intent['currency'] ) ? $intent['currency'] : $order->get_currency() ) );
			$order->save();
		}

		if ( null !== $authorization ) {
			$facts = $invoice_facts + $authorization + $this->get_collected_invoice_note_facts( $subscription, $order );
			// Said only of a renewal that is not paid: on a paid one the invoice completed it.
			$facts['billing_continues'] = $facts['billing_continues'] && ! $this->event_order_resolver->is_paid_for_ownership( $order );
			$this->add_invoice_note_once( $order, WooPaymentsOrderNoteService::INVOICE_NOTE_AUTHORIZATION_REPLACED, $facts, 'stripe_billing_invoice_collected|' . $wcpay_invoice_id );
		} elseif ( $recorded_unpaid && ! $already_recorded ) {
			// The order screen's refund goes to the stored charge, which is the invoice's only when nothing was stored before.
			$wording = '' !== $charge_id && $charge_id === (string) $order->get_meta( '_charge_id', true )
				? WooPaymentsOrderNoteService::INVOICE_NOTE_REFUND_FROM_ORDER
				: WooPaymentsOrderNoteService::INVOICE_NOTE_REFUND_FROM_TRANSACTION_PAGE;
			$this->add_invoice_note_once( $order, $wording, $invoice_facts + $this->get_collected_invoice_note_facts( $subscription, $order ), 'stripe_billing_invoice_collected|' . $wcpay_invoice_id );
		}

		return $subscription;
	}

	/**
	 * Tell whether WooCommerce Subscriptions lets a subscription be put on hold: it is on hold already, which its status
	 * update skips, or its own status check allows the move. A cancelled or expired subscription, or one on a payment
	 * method without suspension support, is refused.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return bool
	 */
	private function can_be_put_on_hold( WC_Order $subscription ): bool {
		return $subscription->has_status( 'on-hold' )
			|| ! is_callable( array( $subscription, 'can_be_updated_to' ) )
			|| $subscription->can_be_updated_to( 'on-hold' );
	}

	/**
	 * Tell whether a renewal stores one uncaptured authorization as its payment: the stored status is requires_capture, and
	 * the stored intent is set and is the transaction ID, so the three name one attempt.
	 *
	 * A stored status alone may belong to another attempt. When this answer is wrong because the authorization was captured
	 * outside the store, the invoice's payment replaces it, and the note names the authorization so it can be refunded.
	 *
	 * @param WC_Order $order Renewal order, read under the lock.
	 * @return bool
	 */
	private function stores_one_uncaptured_authorization( WC_Order $order ): bool {
		$stored_intent_id = (string) $order->get_meta( '_intent_id', true );

		return 'requires_capture' === (string) $order->get_meta( '_intention_status', true )
			&& '' !== $stored_intent_id
			&& $stored_intent_id === (string) $order->get_transaction_id();
	}

	/**
	 * Tell whether a renewal that no longer needs payment records another payment than the invoice's.
	 *
	 * A renewal on another payment method does, whatever its status. A WooPayments renewal does once it has a paid status
	 * or a paid date and its transaction ID names neither the invoice's intent nor its charge, as EventOrderResolver reads a
	 * paid order whose transaction ID is set; an empty transaction ID names no payment here, where the resolver now asks the
	 * stored payment. An unpaid renewal's transaction ID may name an attempt that took nothing, so it is not read. The
	 * stored intent status is never read.
	 *
	 * @param WC_Order $order     Renewal order, read under the lock.
	 * @param string   $intent_id The invoice's payment intent ID.
	 * @param string   $charge_id The invoice's charge ID.
	 * @return bool
	 */
	private function records_another_payment( WC_Order $order, string $intent_id, string $charge_id ): bool {
		if ( '' === $intent_id || $order->needs_payment() ) {
			return false;
		}

		if ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			return true;
		}

		if ( ! $this->event_order_resolver->is_paid_for_ownership( $order ) ) {
			return false;
		}

		return '' !== (string) $order->get_transaction_id() && ! WooPaymentsOrderEffects::transaction_id_names_payment( $order, $intent_id, $charge_id );
	}

	/**
	 * Tell whether a renewal neither paid nor payable can no longer refund the invoice's amount: what it can still refund,
	 * in its own currency, is below that amount, as after a change to Refunded. A refund of the invoice's payment from this
	 * order would then be refused, on the order screen and on the charge's transaction page alike.
	 *
	 * @param WC_Order $order       Renewal order, read under the lock.
	 * @param string   $intent_id   The invoice's payment intent ID.
	 * @param mixed    $amount_paid The invoice's `amount_paid`, in minor units.
	 * @return bool
	 */
	private function cannot_refund_the_invoice_amount( WC_Order $order, string $intent_id, $amount_paid ): bool {
		if ( '' === $intent_id || ! is_numeric( $amount_paid ) || $order->needs_payment() || $order->is_paid() ) {
			return false;
		}

		// Compared as the transaction page's refund compares it (WooPaymentsPaymentDetailsRestController::get_refund_amount()).
		return WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $amount_paid, (string) $order->get_currency() ) > (float) $order->get_remaining_refund_amount();
	}

	/**
	 * Tell whether a transaction ID names a WooPayments payment: the renewal's stored intent or charge, or a payment intent
	 * or charge ID by its form. A setup intent is not a payment. Read only to choose a note's wording.
	 *
	 * @param WC_Order $order          Renewal order.
	 * @param string   $transaction_id The renewal's transaction ID.
	 * @return bool
	 */
	private function names_a_woopayments_payment( WC_Order $order, string $transaction_id ): bool {
		return $transaction_id === (string) $order->get_meta( '_intent_id', true )
			|| $transaction_id === (string) $order->get_meta( '_charge_id', true )
			|| 1 === preg_match( '/^(pi|ch|py)_\w+$/', $transaction_id );
	}

	/**
	 * Get the invoice's charge ID: the read intent's charge, else the invoice's `charge`.
	 *
	 * @param array<string,mixed>      $event_object Invoice from the event.
	 * @param array<string,mixed>|null $intent       The invoice's payment intent, or null when it was not read.
	 * @return string
	 */
	private function get_invoice_charge_id( array $event_object, ?array $intent ): string {
		$intent_charge_id = $intent['charges']['data'][0]['id'] ?? '';
		if ( is_string( $intent_charge_id ) && '' !== $intent_charge_id ) {
			return $intent_charge_id;
		}

		return isset( $event_object['charge'] ) && is_string( $event_object['charge'] ) ? $event_object['charge'] : '';
	}

	/**
	 * Get the note facts for an invoice collected on a renewal that records another payment: the wording by what the
	 * renewal recorded, and what it recorded.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param WC_Order $order        Renewal order, read under the lock.
	 * @return array<string,mixed>
	 */
	private function get_another_payment_note_facts( WC_Order $subscription, WC_Order $order ): array {
		$transaction_id = (string) $order->get_transaction_id();
		if ( ! $this->event_order_resolver->is_paid_for_ownership( $order ) ) {
			$wording = WooPaymentsOrderNoteService::INVOICE_NOTE_REFUND_FROM_TRANSACTION_PAGE;
		} elseif ( ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() ) ) {
			$wording        = WooPaymentsOrderNoteService::INVOICE_NOTE_PAID_ANOTHER_WAY;
			$transaction_id = (string) $order->get_payment_method_title();
		} elseif ( $this->names_a_woopayments_payment( $order, $transaction_id ) ) {
			$wording = WooPaymentsOrderNoteService::INVOICE_NOTE_CHARGED_TWICE;
		} else {
			$wording = WooPaymentsOrderNoteService::INVOICE_NOTE_MARKED_PAID_WITH_REFERENCE;
		}

		return array(
			'wording'   => $wording,
			'paid_with' => $transaction_id,
		) + $this->get_collected_invoice_note_facts( $subscription, $order );
	}

	/**
	 * Get the renewal's and the subscription's note facts: the renewal's status, the subscription's number, and whether
	 * Stripe Billing keeps charging it. Nothing in invoice.paid changes an on-hold or active subscription's billing at
	 * Stripe, so the next period is charged; a subscription pending cancellation, cancelled or expired is not.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param WC_Order $order        Renewal order.
	 * @return array{order_status:string,subscription_number:string,billing_continues:bool}
	 */
	private function get_collected_invoice_note_facts( WC_Order $subscription, WC_Order $order ): array {
		return array(
			'order_status'        => $order->get_status(),
			'subscription_number' => (string) $subscription->get_order_number(),
			'billing_continues'   => $subscription->has_status( array( 'on-hold', 'active' ) ),
		);
	}

	/**
	 * Add a Stripe Billing invoice note once, by its identity.
	 *
	 * @param WC_Order            $order    Renewal order.
	 * @param string              $wording  One of WooPaymentsOrderNoteService's `INVOICE_NOTE_*` wordings.
	 * @param array<string,mixed> $facts    The note's facts.
	 * @param string              $identity The note's identity.
	 */
	private function add_invoice_note_once( WC_Order $order, string $wording, array $facts, string $identity ): void {
		$note_service = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$note_service->add_note_once( $order, $note_service->format_collected_invoice_note( $wording, $facts ), $identity );
	}

	/**
	 * Record a failed renewal attempt: note the decline, put the subscription on hold, or cancel it after the last attempt.
	 *
	 * A failure for an invoice whose renewal order no longer needs payment, such as one that arrives after the invoice
	 * was paid, changes nothing. One for a subscription moved off WooPayments only tries its Stripe cancel again.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 */
	private function handle_invoice_payment_failed( array $event ): void {
		$event_object          = $this->get_event_array( $event, array( 'data', 'object' ) );
		$wcpay_subscription_id = (string) $this->get_event_property( $event_object, array( 'subscription' ) );

		if ( SubscriptionMethodPolicy::is_duplicate_site() ) {
			$this->log_skipped_webhook_due_to_staging( 'invoice.payment_failed', $wcpay_subscription_id );
			return;
		}

		$wcpay_invoice_id = (string) $this->get_event_property( $event_object, array( 'id' ) );
		$attempts         = (int) $this->get_event_property( $event_object, array( 'attempt_count' ) );
		$subscription     = $this->subscription_service->get_subscription_from_wcpay_subscription_id( $wcpay_subscription_id );

		if ( null === $subscription ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( __( 'Cannot find subscription for the incoming "invoice.payment_failed" event.', 'woocommerce' ), StripeBillingException::INVALID_EVENT_DATA );
		}

		// A subscription moved off WooPayments keeps its Stripe ID until Stripe confirms the cancel. The declined charge took
		// nothing, so the cancel is tried again and the other payment method's subscription is left alone.
		if ( ! $this->subscription_service->is_wcpay_subscription( $subscription ) ) {
			$this->subscription_service->maybe_cancel_subscription( $subscription, $subscription->get_payment_method() );
			return;
		}

		$charge = null;
		if ( isset( $event_object['charge'] ) && is_string( $event_object['charge'] ) ) {
			try {
				$charge = $this->api_client->get_charge( $event_object['charge'] );
			} catch ( ApiException $exception ) {
				// Client 11.1.0 appends the platform's message (class-wc-payments-subscriptions-event-handler.php:242); this line
				// logs its status and code instead.
				$this->logger->log_throwable( sprintf( 'Unable to retrieve charge data for invoice.payment_failed webhook. Charge ID: %s.', $event_object['charge'] ), $exception );
			}
		}

		$error_details = '';
		$error_code    = '';
		if ( isset( $charge['outcome']['seller_message'] ) ) {
			$error_details = (string) $charge['outcome']['seller_message'];
			$error_code    = (string) ( $charge['failure_code'] ?? '' );
		}

		$order = $this->apply_failed_invoice( $subscription, $wcpay_invoice_id, $attempts, $error_details, $error_code );
		if ( null === $order ) {
			return;
		}

		$this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
	}

	/**
	 * Apply a failed renewal attempt to its renewal order and the subscription.
	 *
	 * @param WC_Order $subscription     Subscription.
	 * @param string   $wcpay_invoice_id Invoice ID.
	 * @param int      $attempts         Renewal attempts Stripe made for the invoice.
	 * @param string   $error_details    Decline message, empty when unknown.
	 * @param string   $error_code       Decline code.
	 * @return WC_Order|null The renewal order, or null when it no longer needs payment.
	 * @throws StripeBillingException When the renewal order cannot be created.
	 */
	private function apply_failed_invoice( WC_Order $subscription, string $wcpay_invoice_id, int $attempts, string $error_details, string $error_code ): ?WC_Order {
		$order = $this->get_or_create_renewal_order( $subscription, $wcpay_invoice_id, __( 'Unable to generate renewal order for subscription to record the incoming "invoice.payment_failed" event.', 'woocommerce' ) );
		if ( ! $order->needs_payment() ) {
			return null;
		}

		if ( $error_details ) {
			$subscription->add_order_note(
				sprintf(
					/* translators: %1$d: number of failed renewal attempts, %2$s: failure message, %3$s: failure code. */
					_n(
						'WooPayments subscription renewal attempt %1$d failed with the following message "%2$s" and failure code <code>%3$s</code>',
						'WooPayments subscription renewal attempt %1$d failed with the following message "%2$s" and failure code <code>%3$s</code>',
						$attempts,
						'woocommerce'
					),
					$attempts,
					$error_details,
					$error_code
				)
			);
			$order->add_order_note(
				sprintf(
					/* translators: %1$s: failure message, %2$s: failure code. */
					__( 'Payment for the order failed with the following message: "%1$s" and failure code <code>%2$s</code>', 'woocommerce' ),
					$error_details,
					$error_code
				)
			);
		} else {
			/* translators: %d: number of failed renewal attempts. */
			$subscription->add_order_note( sprintf( _n( 'WooPayments subscription renewal attempt %d failed.', 'WooPayments subscription renewal attempt %d failed.', $attempts, 'woocommerce' ), $attempts ) );
		}

		if ( self::MAX_RETRIES > $attempts ) {
			// Stripe retries the invoice itself, so putting the subscription on hold must not pause it there.
			$this->subscription_service->run_without_stripe_sync(
				function () use ( $subscription, $order ): void {
					$this->fail_renewal_order( $subscription, $order, 'on-hold' );
				}
			);
		} else {
			$this->fail_renewal_order( $subscription, $order, 'cancelled' );
		}

		// A later payment method change charges this invoice again.
		$this->invoice_service->mark_pending_invoice_for_subscription( $subscription, $wcpay_invoice_id );

		return $order;
	}

	/**
	 * Fail an invoice's renewal order and move the subscription to the given status, as WooCommerce Subscriptions does.
	 *
	 * Client 11.1.0 calls `payment_failed()` (class-wc-payments-subscriptions-event-handler.php:298-304), which WooCommerce
	 * Subscriptions deprecated in 7.9.0 because it fails the subscription's last order, whichever invoice it belongs to.
	 * The invoice's own order is passed to `payment_failed_for_related_order()` instead. Before 7.9.0, `payment_failed()`
	 * runs only when the last order is the invoice's; otherwise the invoice's order is failed and noted here, and the
	 * subscription is left as it is.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param WC_Order $order        The invoice's renewal order.
	 * @param string   $new_status   Subscription status after the failure: `on-hold` or `cancelled`.
	 */
	private function fail_renewal_order( WC_Order $subscription, WC_Order $order, string $new_status ): void {
		if ( is_callable( array( $subscription, 'payment_failed_for_related_order' ) ) ) {
			$subscription->payment_failed_for_related_order( $new_status, $order );
			return;
		}

		if ( ! is_callable( array( $subscription, 'payment_failed' ) ) ) {
			return;
		}

		$last_order_id = is_callable( array( $subscription, 'get_last_order' ) ) ? (int) $subscription->get_last_order( 'ids', 'any' ) : 0;
		if ( $last_order_id === $order->get_id() ) {
			$subscription->payment_failed( $new_status );
			return;
		}

		// WooCommerce Subscriptions' renewal-order listener calls payment_failed() when a last renewal fails, which would
		// fail the last order after all; WCS takes it off around its own failure the same way (class-wc-subscription.php
		// payment_failed(), 7.8.2 :2044-2046).
		$listener = 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment';
		$priority = has_filter( 'woocommerce_order_status_changed', $listener );
		if ( false !== $priority ) {
			remove_filter( 'woocommerce_order_status_changed', $listener, $priority );
		}
		try {
			if ( ! $order->has_status( OrderStatus::FAILED ) ) {
				$order->update_status( OrderStatus::FAILED );
			}
		} finally {
			if ( false !== $priority && is_callable( $listener ) ) {
				add_filter( 'woocommerce_order_status_changed', $listener, $priority, 3 );
			}
		}
		/* translators: %d: renewal order ID. */
		$subscription->add_order_note( sprintf( __( 'Related order #%d failed.', 'woocommerce' ), $order->get_id() ) );
	}

	/**
	 * Record the renewal payment on a subscription left on hold although its renewal order, its last one, is paid.
	 *
	 * An overlapping delivery of the same invoice can pay the order first; this delivery's hold then comes after that
	 * payment, and its own payment is skipped as already recorded. Client 11.1.0 pays the order again from its stale copy
	 * (class-wc-payments-subscriptions-event-handler.php:159-184), and WooCommerce Subscriptions then runs this same call,
	 * only for the subscription's last renewal (WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment()).
	 *
	 * @param WC_Order $subscription Subscription, as stored.
	 * @param WC_Order $order        Renewal order.
	 */
	private function reactivate_subscription_of_paid_renewal( WC_Order $subscription, WC_Order $order ): void {
		// Another delivery may have paid the order after this request cached it, so the read goes past those caches.
		$fresh_order = $this->lifecycle_service->get_fresh_order_from_data_store( $order );
		if ( $fresh_order->needs_payment() || ! $subscription->has_status( 'on-hold' ) || ! is_callable( array( $subscription, 'payment_complete_for_order' ) ) ) {
			return;
		}

		if ( ! function_exists( 'wcs_is_order_last_renewal_of_subscription' ) || ! wcs_is_order_last_renewal_of_subscription( $fresh_order, $subscription ) ) {
			return;
		}

		// Stripe billed the invoice and keeps the subscription active, so the reactivation does not reach Stripe.
		$this->subscription_service->run_without_stripe_sync(
			static function () use ( $subscription, $fresh_order ): void {
				$subscription->payment_complete_for_order( $fresh_order );
			}
		);
	}

	/**
	 * Read a subscription again, so a change another instance saved is not overwritten.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order The subscription as stored, or the one given when WooCommerce Subscriptions cannot read it.
	 */
	private function get_fresh_subscription( WC_Order $subscription ): WC_Order {
		$fresh_subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription->get_id() ) : null;

		return $fresh_subscription instanceof WC_Order ? $fresh_subscription : $subscription;
	}

	/**
	 * Get the renewal order an invoice paid, or create it.
	 *
	 * @param WC_Order $subscription  Subscription.
	 * @param string   $invoice_id    Invoice ID.
	 * @param string   $error_message Message when the renewal order cannot be created.
	 * @return WC_Order
	 * @throws StripeBillingException When the renewal order cannot be created.
	 */
	private function get_or_create_renewal_order( WC_Order $subscription, string $invoice_id, string $error_message ): WC_Order {
		$order = wc_get_order( $this->invoice_service->get_order_id_by_invoice_id( $invoice_id ) );
		if ( $order instanceof WC_Order ) {
			return $order;
		}

		$order = function_exists( 'wcs_create_renewal_order' ) ? wcs_create_renewal_order( $subscription ) : null;
		if ( ! $order instanceof WC_Order ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( $error_message, StripeBillingException::INVALID_EVENT_DATA );
		}

		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$this->invoice_service->set_order_invoice_id( $order, $invoice_id );

		return $order;
	}

	/**
	 * Get a payment intent from the platform.
	 *
	 * @param string $intent_id Payment intent ID.
	 * @return array<string,mixed>|null The intent, or null when the platform cannot be read.
	 */
	private function get_payment_intent( string $intent_id ): ?array {
		try {
			return $this->api_client->get_payment_intention( $intent_id );
		} catch ( ApiException $exception ) {
			return null;
		}
	}

	/**
	 * Mark a renewal order paid when its payment intent cannot be read, and note that the payment info is missing.
	 *
	 * Client 11.1.0 does the same (class-wc-payments-subscriptions-event-handler.php:174,
	 * class-wc-payments-invoice-service.php:295-297) but stores no ID; this keeps the intent and charge IDs a refund needs.
	 *
	 * @param WC_Order            $order        Renewal order.
	 * @param string              $intent_id    Payment intent ID, or an empty string when the invoice has none.
	 * @param array<string,mixed> $event_object Invoice.
	 */
	private function complete_order_without_intent( WC_Order $order, string $intent_id, array $event_object ): void {
		if ( '' === $intent_id ) {
			$order->payment_complete();
			return;
		}

		$order->update_meta_data( '_intent_id', $intent_id );
		if ( isset( $event_object['charge'] ) && is_string( $event_object['charge'] ) ) {
			$order->update_meta_data( '_charge_id', $event_object['charge'] );
		}
		$order->payment_complete( $intent_id );
		$order->add_order_note( __( 'The payment info couldn\'t be added to the order.', 'woocommerce' ) );
	}

	/**
	 * Get the hold note's wordings for a renewal whose paid invoice does not match its total, WooCommerce's first.
	 *
	 * @param WC_Order $order            Renewal order.
	 * @param string   $wcpay_invoice_id Invoice ID.
	 * @param mixed    $amount_paid      The invoice's `amount_paid`.
	 * @param mixed    $invoice_currency The invoice's currency.
	 * @return string[]
	 */
	private function get_invoice_hold_note_candidates( WC_Order $order, string $wcpay_invoice_id, $amount_paid, $invoice_currency ): array {
		return wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_invoice_mismatch_hold_note_candidates(
			$order,
			$wcpay_invoice_id,
			is_numeric( $amount_paid ) ? (int) $amount_paid : 0,
			is_string( $invoice_currency ) ? $invoice_currency : ''
		);
	}

	/**
	 * Hold a renewal whose invoice does not match its total when its intent cannot be read, through the payment lifecycle's
	 * held completed event under the order payment lock the caller holds: the intent and charge the event names, on-hold
	 * with the hold note once, nothing on a paid renewal; then the client's note that the payment info could not be added
	 * (client 11.3.0 class-wc-payments-subscriptions-event-handler.php:163-172, class-wc-payments-invoice-service.php:334-343).
	 *
	 * @param WC_Order            $order                Renewal order, read under the lock.
	 * @param string              $intent_id            Intent ID from the event, or an empty string.
	 * @param array<string,mixed> $event_object         Invoice from the event.
	 * @param string[]            $hold_note_candidates The hold note's wordings, WooCommerce's first.
	 */
	private function hold_renewal_without_intent( WC_Order $order, string $intent_id, array $event_object, array $hold_note_candidates ): void {
		$meta = array();
		if ( '' !== $intent_id ) {
			$meta['_intent_id'] = $intent_id;
			if ( isset( $event_object['charge'] ) && is_string( $event_object['charge'] ) ) {
				$meta['_charge_id'] = $event_object['charge'];
			}
		}

		$this->lifecycle_service->apply_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::STATUS_COMPLETED,
				'' !== $intent_id ? $intent_id : null,
				$meta,
				array(),
				$hold_note_candidates[0],
				PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_HELD,
				$hold_note_candidates,
				false,
				true
			),
			new WooPaymentsPersistenceVocabulary()
		);

		if ( '' !== $intent_id ) {
			$order->add_order_note( __( 'The payment info couldn\'t be added to the order.', 'woocommerce' ) );
		}
	}

	/**
	 * Get a value from event data, as client 11.1.0's `get_event_property()` reads it
	 * (class-wc-payments-subscriptions-event-handler.php:323-337): a missing or null value is refused.
	 *
	 * @param array<string,mixed> $data Event data.
	 * @param string[]            $keys Keys leading to the value.
	 * @return mixed
	 * @throws StripeBillingException When the value is missing.
	 */
	private function get_event_property( array $data, array $keys ) {
		$value = $data;
		foreach ( $keys as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
				throw new StripeBillingException( sprintf( /* translators: %s: event property name. */ __( '%s not found in array', 'woocommerce' ), $key ), StripeBillingException::INVALID_EVENT_DATA );
			}

			$value = $value[ $key ];
		}

		return $value;
	}

	/**
	 * Get a list or object from event data.
	 *
	 * @param array<string,mixed> $data Event data.
	 * @param string[]            $keys Keys leading to the value.
	 * @return array<mixed>
	 * @throws StripeBillingException When the value is missing or is not a list or object.
	 */
	private function get_event_array( array $data, array $keys ): array {
		$value = $this->get_event_property( $data, $keys );
		if ( ! is_array( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( sprintf( '%s is not an array', implode( '.', $keys ) ), StripeBillingException::INVALID_EVENT_DATA );
		}

		return $value;
	}

	/**
	 * Get one of a subscription's dates as a timestamp, 0 when it has none.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $date_type    Date type, such as `next_payment` or `end`.
	 * @return int
	 */
	private function get_time( WC_Order $subscription, string $date_type ): int {
		return is_callable( array( $subscription, 'get_time' ) ) ? (int) $subscription->get_time( $date_type ) : 0;
	}

	/**
	 * Log that an invoice event was skipped because this site is a staging copy.
	 *
	 * Logs both site URLs, so support can see why renewals paused on a site WooCommerce Subscriptions treats as a staging copy.
	 *
	 * @param string $event_type            Event type.
	 * @param string $wcpay_subscription_id Stripe subscription ID.
	 */
	private function log_skipped_webhook_due_to_staging( string $event_type, string $wcpay_subscription_id ): void {
		$this->logger->log(
			sprintf(
				'%s webhook processing for %s was skipped. The current site (%s) is in staging mode. Live site is %s.',
				$event_type,
				$wcpay_subscription_id,
				class_exists( 'WCS_Staging' ) ? \WCS_Staging::get_site_url_from_source( 'current_wp_site' ) : '',
				class_exists( 'WCS_Staging' ) ? \WCS_Staging::get_site_url_from_source( 'subscriptions_install' ) : ''
			)
		);
	}
}
