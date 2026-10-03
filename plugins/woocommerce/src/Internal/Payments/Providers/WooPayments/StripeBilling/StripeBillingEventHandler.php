<?php
/**
 * StripeBillingEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Stripe Billing invoice events: `invoice.upcoming`, `invoice.paid` and `invoice.payment_failed`.
 *
 * Follows client 11.1.0 `includes/subscriptions/class-wc-payments-subscriptions-event-handler.php`. The event ingestor
 * runs it inside its usual processing, so the mode check, duplicate events and the order lock are handled there.
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
	 * @var StripeBillingLogger
	 */
	private StripeBillingLogger $logger;

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
	 * @param StripeBillingLogger              $logger               Module logger.
	 */
	final public function init( StripeBillingInvoiceService $invoice_service, StripeBillingSubscriptionService $subscription_service, WooPaymentsApiClient $api_client, WooPaymentsEventIngestor $event_ingestor, WooPaymentsAccountService $account_service, StripeBillingLogger $logger ): void {
		$this->invoice_service      = $invoice_service;
		$this->subscription_service = $subscription_service;
		$this->api_client           = $api_client;
		$this->event_ingestor       = $event_ingestor;
		$this->account_service      = $account_service;
		$this->logger               = $logger;
	}

	/**
	 * Handle one invoice event.
	 *
	 * An event with missing data, or about a subscription this store does not have, is logged with its ID and the reason,
	 * and refused with an `InvalidArgumentException`, which the webhook answers with 400 and does not retry. Other failures,
	 * such as a platform call that fails, are passed on so the event is retried.
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
			wc_get_logger()->error(
				sprintf(
					'WooPayments webhook event %1$s (%2$s) was refused: %3$s',
					is_scalar( $event['id'] ?? null ) ? (string) $event['id'] : '',
					$event_type,
					$exception->getMessage()
				),
				array( 'source' => 'native-payments-webhook' )
			);

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
	 * The invoice of the subscription's first order is ignored: checkout records that payment.
	 *
	 * @param array<string,mixed> $event Event payload.
	 * @throws StripeBillingException When the event has missing data or names no subscription of this store.
	 * @throws RuntimeException When the renewal order is still unpaid after recording its payment.
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

		if ( $this->invoice_service->get_subscription_invoice_id( $subscription ) === $wcpay_invoice_id ) {
			return;
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
			$fresh_subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription->get_id() ) : null;
			$subscription       = $fresh_subscription instanceof WC_Order ? $fresh_subscription : $subscription;

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

		$invoice = $this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
		$this->invoice_service->update_charge_details( $invoice, $order->get_id() );
		$this->invoice_service->update_transaction_details( $invoice, $order );
	}

	/**
	 * Record a failed renewal attempt: note the decline, put the subscription on hold, or cancel it after the last attempt.
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
				$this->logger->log( sprintf( 'Unable to retrieve charge data for invoice.payment_failed webhook. Charge ID: %s; Error: %s', $event_object['charge'], $exception->getMessage() ), 'error' );
			}
		}

		$error_details = '';
		$error_code    = '';
		if ( isset( $charge['outcome']['seller_message'] ) ) {
			$error_details = (string) $charge['outcome']['seller_message'];
			$error_code    = (string) ( $charge['failure_code'] ?? '' );
		}

		$order = $this->get_or_create_renewal_order( $subscription, $wcpay_invoice_id, __( 'Unable to generate renewal order for subscription to record the incoming "invoice.payment_failed" event.', 'woocommerce' ) );

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

		if ( is_callable( array( $subscription, 'payment_failed' ) ) ) {
			if ( self::MAX_RETRIES > $attempts ) {
				// Stripe retries the invoice itself, so putting the subscription on hold must not pause it there.
				$this->subscription_service->run_without_stripe_sync(
					static function () use ( $subscription ): void {
						$subscription->payment_failed();
					}
				);
			} else {
				$subscription->payment_failed( 'cancelled' );
			}
		}

		// A later payment method change charges this invoice again.
		$this->invoice_service->mark_pending_invoice_for_subscription( $subscription, $wcpay_invoice_id );

		$this->invoice_service->record_subscription_payment_context( $wcpay_invoice_id );
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
