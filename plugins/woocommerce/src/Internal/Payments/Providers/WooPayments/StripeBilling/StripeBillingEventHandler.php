<?php
/**
 * StripeBillingEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Stripe Billing invoice events: `invoice.upcoming`, `invoice.paid` and `invoice.payment_failed`.
 *
 * Follows client 11.1.0 `includes/subscriptions/class-wc-payments-subscriptions-event-handler.php`. The event ingestor
 * runs it inside its usual processing (the mode check and the processed-event marker); overlapping deliveries of one
 * invoice are kept apart here by a lock on the invoice, and the payment is recorded under the order payment lock.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingEventHandler {

	/**
	 * Failed renewal attempts after which the subscription is cancelled (client 11.1.0 `MAX_RETRIES`).
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
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Event ingestor, which records a succeeded payment intent on its order.
	 *
	 * @var WooPaymentsEventIngestor
	 */
	private WooPaymentsEventIngestor $event_ingestor;

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
	 * Lock held in the database rows of a transient, keeping two deliveries of one invoice from each creating a renewal order.
	 *
	 * @var TransientRowLock
	 */
	private TransientRowLock $row_lock;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingInvoiceService      $invoice_service      Invoice service.
	 * @param StripeBillingSubscriptionService $subscription_service Subscription service.
	 * @param WooPaymentsApiClient             $api_client           Platform API client.
	 * @param WooPaymentsEventIngestor         $event_ingestor       Event ingestor.
	 * @param WooPaymentsAccountService        $account_service      Account service.
	 * @param WooPaymentsLogger                $logger               Module logger.
	 * @param TransientRowLock                 $row_lock             Lock held in the database rows of a transient.
	 */
	final public function init( StripeBillingInvoiceService $invoice_service, StripeBillingSubscriptionService $subscription_service, WooPaymentsApiClient $api_client, WooPaymentsEventIngestor $event_ingestor, WooPaymentsAccountService $account_service, WooPaymentsLogger $logger, TransientRowLock $row_lock ): void {
		$this->invoice_service      = $invoice_service;
		$this->subscription_service = $subscription_service;
		$this->api_client           = $api_client;
		$this->event_ingestor       = $event_ingestor;
		$this->account_service      = $account_service;
		$this->logger               = $logger;
		$this->row_lock             = $row_lock;
	}

	/**
	 * Handle one invoice event.
	 *
	 * An event with missing data, or about a subscription this store does not have, is refused with an
	 * `InvalidArgumentException`, which the webhook answers with 400 and does not retry. As on the client, the webhook route
	 * or the failed-event job that catches the refusal logs it. Other failures, such as a platform call that fails, are
	 * passed on so the event is retried.
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

		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
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

		$wcpay_subscription = $this->subscription_service->get_wcpay_subscription( $subscription );

		// No next payment is expected, so Stripe must not bill.
		if ( 0 === $this->get_time( $subscription, 'next_payment' ) ) {
			if ( ! $subscription->has_status( 'on-hold' ) && 0 !== $this->get_time( $subscription, 'end' ) ) {
				$this->subscription_service->cancel_subscription( $subscription );
			} else {
				$this->subscription_service->suspend_subscription( $subscription );
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
		$this->invoice_service->validate_invoice( $wcpay_lines, is_array( $wcpay_discounts ) ? $wcpay_discounts : array(), $subscription );
	}

	/**
	 * Record the renewal a paid invoice billed: find or create the renewal order, mark it paid, and tell the platform about it.
	 *
	 * The invoice of the subscription's first order is ignored: checkout records that payment. Everything that changes
	 * the order or the subscription runs under the invoice lock (see run_under_invoice_lock()); the platform updates
	 * that follow it do not need it.
	 *
	 * A RuntimeException from the invoice lock or the recording fails the event, so it is retried.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 */
	private function handle_invoice_paid( array $event ): void {
		$event_object          = $this->get_event_array( $event, array( 'data', 'object' ) );
		$wcpay_subscription_id = (string) $this->get_event_property( $event_object, array( 'subscription' ) );

		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
			$this->log_skipped_webhook_due_to_staging( 'invoice.paid', $wcpay_subscription_id );
			return;
		}

		$wcpay_invoice_id = (string) $this->get_event_property( $event_object, array( 'id' ) );
		$subscription     = $this->subscription_service->get_subscription_from_wcpay_subscription_id( $wcpay_subscription_id );

		if ( null === $subscription ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new StripeBillingException( __( 'Cannot find subscription for the incoming "invoice.paid" event.', 'woocommerce' ), StripeBillingException::INVALID_EVENT_DATA );
		}

		$order = $this->run_under_invoice_lock(
			$subscription,
			$wcpay_invoice_id,
			'',
			fn() => $this->record_paid_invoice( $this->get_fresh_subscription( $subscription ), $wcpay_invoice_id, $event_object )
		);
		if ( null === $order ) {
			return;
		}

		$invoice = $this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
		$this->invoice_service->update_charge_details( $invoice, $order->get_id() );
		$this->invoice_service->update_transaction_details( $invoice, $order );
	}

	/**
	 * Record a paid invoice on its renewal order and the subscription, under the invoice lock.
	 *
	 * @param WC_Order            $subscription     Subscription, read after the lock was claimed.
	 * @param string              $wcpay_invoice_id Invoice ID.
	 * @param array<string,mixed> $event_object     Invoice from the event.
	 * @return WC_Order|null The renewal order, or null for the invoice of the subscription's first order.
	 * @throws RuntimeException When the renewal order is still unpaid after recording its payment.
	 */
	private function record_paid_invoice( WC_Order $subscription, string $wcpay_invoice_id, array $event_object ): ?WC_Order {
		if ( $this->invoice_service->get_subscription_invoice_id( $subscription ) === $wcpay_invoice_id ) {
			return null;
		}

		$order     = $this->get_or_create_renewal_order( $subscription, $wcpay_invoice_id, __( 'Unable to generate renewal order for subscription on the "invoice.paid" event.', 'woocommerce' ) );
		$intent_id = isset( $event_object['payment_intent'] ) && is_string( $event_object['payment_intent'] ) ? $event_object['payment_intent'] : '';
		$intent    = '' !== $intent_id ? $this->get_payment_intent( $intent_id ) : null;

		$was_succeeded = 'succeeded' === $order->get_meta( '_intention_status', true );
		if ( null !== $intent ) {
			// Recording writes this mode too, after the success note reads it; a renewal has none stored, so it is set first.
			$order->update_meta_data( '_wcpay_mode', $this->account_service->get_order_mode() );
			if ( isset( $intent['customer'] ) && is_string( $intent['customer'] ) && '' !== $intent['customer'] ) {
				$order->update_meta_data( '_stripe_customer_id', $intent['customer'] );
			}
			$order->save();
		}

		if ( $order->needs_payment() ) {
			// Put the subscription on hold first, as a renewal does, so WooCommerce Subscriptions records the payment on it. Stripe already billed it, so neither change reaches Stripe.
			$this->subscription_service->run_without_stripe_sync(
				function () use ( $subscription, $order, $intent, $intent_id, $event_object ): void {
					$subscription->update_status( 'on-hold' );

					if ( null !== $intent ) {
						$this->event_ingestor->record_succeeded_payment_intent( $order, $intent );
					} else {
						$this->complete_order_without_intent( $order, $intent_id, $event_object );
					}
				}
			);

			// Completing the order activated another instance of the subscription; saving this one would undo that.
			$subscription = $this->get_fresh_subscription( $subscription );

			if ( $order->needs_payment() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
				throw new RuntimeException( sprintf( 'Renewal order #%1$d is still unpaid after recording invoice %2$s.', $order->get_id(), $wcpay_invoice_id ) );
			}
		} elseif ( null !== $intent ) {
			$this->event_ingestor->record_succeeded_payment_intent( $order, $intent );
		} elseif ( '' !== $intent_id ) {
			$order->add_order_note( __( 'The payment info couldn\'t be added to the order.', 'woocommerce' ) );
		}

		if ( null !== $intent && ! $was_succeeded ) {
			// The client attaches the fetched intent here: the transaction id even on an order a retry already paid, where
			// payment_complete() ignores it, and the currency upper case, where the webhook writes it lower case.
			$order->set_transaction_id( $intent_id );
			$order->update_meta_data( '_wcpay_intent_currency', strtoupper( isset( $intent['currency'] ) && is_string( $intent['currency'] ) ? $intent['currency'] : $order->get_currency() ) );
			$order->save();
		}

		$this->invoice_service->mark_pending_invoice_paid_for_subscription( $subscription );

		return $order;
	}

	/**
	 * Record a failed renewal attempt: note the decline, put the subscription on hold, or cancel it after the last attempt.
	 *
	 * The notes and the status changes run under the invoice lock (see run_under_invoice_lock()). A failure for an invoice
	 * whose renewal order no longer needs payment, such as one that arrives after the invoice was paid, changes nothing.
	 *
	 * A RuntimeException from the invoice lock fails the event.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 */
	private function handle_invoice_payment_failed( array $event ): void {
		$event_object          = $this->get_event_array( $event, array( 'data', 'object' ) );
		$wcpay_subscription_id = (string) $this->get_event_property( $event_object, array( 'subscription' ) );

		if ( WooPaymentsSubscriptionMethodPolicy::is_duplicate_site() ) {
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

		$charge = null;
		if ( isset( $event_object['charge'] ) && is_string( $event_object['charge'] ) ) {
			try {
				$charge = $this->api_client->get_charge( $event_object['charge'] );
			} catch ( WooPaymentsApiException $exception ) {
				// The client appends the platform's message; native logs its status and code.
				$this->logger->log_throwable( sprintf( 'Unable to retrieve charge data for invoice.payment_failed webhook. Charge ID: %s.', $event_object['charge'] ), $exception );
			}
		}

		$error_details = '';
		$error_code    = '';
		if ( isset( $charge['outcome']['seller_message'] ) ) {
			$error_details = (string) $charge['outcome']['seller_message'];
			$error_code    = (string) ( $charge['failure_code'] ?? '' );
		}

		$order = $this->run_under_invoice_lock(
			$subscription,
			$wcpay_invoice_id,
			sprintf(
				/* translators: %1$s: Stripe Billing invoice ID, %2$d: renewal attempt number. */
				__( 'A WooPayments failed-payment update for this subscription (invoice %1$s, attempt %2$d) could not be applied, because another update of the same invoice was running. Check the subscription in your WooPayments dashboard.', 'woocommerce' ),
				$wcpay_invoice_id,
				$attempts
			),
			fn() => $this->record_failed_invoice( $this->get_fresh_subscription( $subscription ), $wcpay_invoice_id, $attempts, $error_details, $error_code )
		);
		if ( null === $order ) {
			return;
		}

		$this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
	}

	/**
	 * Record a failed renewal attempt on its renewal order and the subscription, under the invoice lock.
	 *
	 * @param WC_Order $subscription     Subscription, read after the lock was claimed.
	 * @param string   $wcpay_invoice_id Invoice ID.
	 * @param int      $attempts         Renewal attempts Stripe made for the invoice.
	 * @param string   $error_details    Decline message, empty when unknown.
	 * @param string   $error_code       Decline code.
	 * @return WC_Order|null The renewal order, or null when it no longer needs payment.
	 * @throws StripeBillingException When the renewal order cannot be created.
	 */
	private function record_failed_invoice( WC_Order $subscription, string $wcpay_invoice_id, int $attempts, string $error_details, string $error_code ): ?WC_Order {
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
	 * The client calls `payment_failed()` (event handler :298-304), which WooCommerce Subscriptions deprecated in 7.9.0
	 * because it fails the subscription's last order, whichever invoice it belongs to. The invoice's own order is passed
	 * to `payment_failed_for_related_order()` instead. Before 7.9.0, `payment_failed()` runs only when the last order is
	 * the invoice's; otherwise the invoice's order is failed and noted here, and the subscription is left as it is.
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

		if ( ! $order->has_status( OrderStatus::FAILED ) ) {
			$order->update_status( OrderStatus::FAILED );
		}
		/* translators: %d: renewal order ID. */
		$subscription->add_order_note( sprintf( __( 'Related order #%d failed.', 'woocommerce' ), $order->get_id() ) );
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
	 * Run what an invoice event changes in this store under a lock on the invoice.
	 *
	 * Two deliveries of one invoice can overlap (a slow first delivery the platform counts as failed and lists again,
	 * or a duplicate push). The renewal order lookup and creation, the payment recording, the notes and the subscription
	 * status changes run under the lock, so a second delivery waits for none of it: it is refused. invoice.paid is
	 * retried and finds the order paid; an invoice.payment_failed gets one attempt, so its refusal leaves a note on the
	 * subscription. Like the order payment lock, this is a lease of WooPaymentsPersistenceProfile::LOCK_TTL_SECONDS: a
	 * delivery that died cannot block the next one, and one that stalls past it can be overtaken (accepted, as for the
	 * order payment lock). Client 11.1.0 takes no lock (class-wc-payments-subscriptions-event-handler.php:146-175, :254-307).
	 *
	 * @template T
	 * @param WC_Order      $subscription Subscription, for the refusal note.
	 * @param string        $invoice_id   Invoice ID.
	 * @param string        $refusal_note Note left on the subscription when another delivery holds the lock, if any.
	 * @param callable(): T $callback     What the event changes.
	 * @return T
	 * @throws RuntimeException When another delivery of the invoice holds the lock.
	 */
	private function run_under_invoice_lock( WC_Order $subscription, string $invoice_id, string $refusal_note, callable $callback ) {
		$lock_key   = 'wcpay_stripe_billing_renewal_' . md5( $invoice_id );
		$lock_token = wp_generate_uuid4();
		if ( ! $this->row_lock->claim( $lock_key, $lock_token, WooPaymentsPersistenceProfile::LOCK_TTL_SECONDS ) ) {
			if ( '' !== $refusal_note ) {
				try {
					$subscription->add_order_note( $refusal_note );
				} catch ( Throwable $exception ) {
					unset( $exception );
				}
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
			throw new RuntimeException( sprintf( 'Another delivery of invoice %s is being recorded.', $invoice_id ) );
		}

		try {
			return $callback();
		} finally {
			$this->row_lock->release( $lock_key, $lock_token );
		}
	}

	/**
	 * Get the renewal order an invoice paid, or create it. Runs under the invoice lock.
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

		$order->set_payment_method( WooPaymentsPersistenceProfile::GATEWAY_ID );
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
		} catch ( WooPaymentsApiException $exception ) {
			return null;
		}
	}

	/**
	 * Mark a renewal order paid when its payment intent cannot be read, as the client does, keeping the IDs a refund needs.
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
	 * Get a value from event data, as client 11.1.0's `get_event_property()` reads it: a missing or null value is refused.
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
