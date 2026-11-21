<?php
/**
 * StripeBillingInvoiceService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\LocalizationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsActionSchedulerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Throwable;
use WC_Order;
use WP_Http;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps WooCommerce orders and subscriptions in step with their Stripe Billing invoices.
 *
 * Meta keys and platform calls are client 11.1.0's (`includes/subscriptions/class-wc-payments-invoice-service.php`),
 * so the WooPayments extension reads the same data if the store switches back to it.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingInvoiceService {

	/**
	 * Subscription meta holding the ID of the invoice still waiting for payment.
	 */
	public const PENDING_INVOICE_ID_KEY = '_wcpay_pending_invoice_id';

	/**
	 * Order and subscription meta holding the invoice ID.
	 */
	public const ORDER_INVOICE_ID_KEY = '_wcpay_billing_invoice_id';

	/** Action Scheduler hook for recording a subscription's first payment. */
	public const FIRST_INVOICE_ACTION = 'woocommerce_woopayments_retry_stripe_billing_first_invoice';

	/**
	 * Prevent a second subscription lookup after the inline handler visits an order.
	 *
	 * @var array<string,bool>
	 */
	private array $visited_orders = array();

	/**
	 * Keeps one pending attempt per invoice and retry number.
	 *
	 * @var WooPaymentsActionSchedulerService
	 */
	private WooPaymentsActionSchedulerService $scheduler;

	/**
	 * Stripe Billing platform calls.
	 *
	 * @var StripeBillingApi
	 */
	private StripeBillingApi $api;

	/**
	 * Platform API client, for the charge read.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * Module root, the single reader of the Stripe Billing toggle.
	 *
	 * @var StripeBillingModule
	 */
	private StripeBillingModule $module;

	/**
	 * Module logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingApi                       $api        Stripe Billing platform calls.
	 * @param Client                                 $api_client Platform API client.
	 * @param StripeBillingModule                    $module     Module root.
	 * @param WooPaymentsLogger                      $logger     Module logger.
	 * @param WooPaymentsActionSchedulerService|null $scheduler  First-invoice jobs.
	 */
	final public function init( StripeBillingApi $api, Client $api_client, StripeBillingModule $module, WooPaymentsLogger $logger, ?WooPaymentsActionSchedulerService $scheduler = null ): void {
		$this->api        = $api;
		$this->api_client = $api_client;
		$this->module     = $module;
		$this->logger     = $logger;
		$this->scheduler  = $scheduler ?? wc_get_container()->get( WooPaymentsActionSchedulerService::class );
	}

	/**
	 * Get the ID of the invoice a subscription is still waiting to have paid.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	public function get_pending_invoice_id( WC_Order $subscription ): string {
		return (string) $subscription->get_meta( self::PENDING_INVOICE_ID_KEY, true );
	}

	/**
	 * Get the invoice ID of an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function get_order_invoice_id( WC_Order $order ): string {
		return (string) $order->get_meta( self::ORDER_INVOICE_ID_KEY, true );
	}

	/**
	 * Get the invoice ID of a subscription: the invoice of its parent order.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	public function get_subscription_invoice_id( WC_Order $subscription ): string {
		return (string) $subscription->get_meta( self::ORDER_INVOICE_ID_KEY, true );
	}

	/**
	 * Tell whether the amount an invoice collected matches its order's total: the currency is the order's, whatever the
	 * case, and the amount is within one store price unit per order line of the order total in minor units (client 11.3.0
	 * class-wc-payments-invoice-service.php:116-159).
	 *
	 * A price unit is 10 to the power of the currency's minor unit less the decimals the store prices it in, and the
	 * lines are the order's line items, fees, shipping, taxes and coupons.
	 *
	 * @since 11.2.0
	 *
	 * @param WC_Order $order            Order.
	 * @param mixed    $amount_paid      The invoice's `amount_paid`, in minor units.
	 * @param mixed    $invoice_currency The invoice's currency.
	 * @return bool
	 */
	public function does_invoice_payment_match_order_total( WC_Order $order, $amount_paid, $invoice_currency ): bool {
		if ( ! is_numeric( $amount_paid ) ) {
			return false;
		}

		$order_currency = (string) $order->get_currency();
		$order_total    = WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), $order_currency );
		$price_unit     = 10 ** max( 0, WooPaymentsCurrencyUtils::get_stripe_minor_unit_for_currency( $order_currency ) - $this->get_price_decimals( $order_currency ) );
		$allowance      = $price_unit * count( $order->get_items( array( 'line_item', 'fee', 'shipping', 'tax', 'coupon' ) ) );

		return strtolower( $order_currency ) === strtolower( is_string( $invoice_currency ) ? $invoice_currency : '' )
			&& abs( (int) $amount_paid - $order_total ) <= $allowance;
	}

	/**
	 * Get the number of decimals the store prices a currency in: WooCommerce's for the store currency, the localized
	 * format's for any other, as Multi-Currency prices it (client 11.3.0 class-wc-payments-invoice-service.php:153-159).
	 *
	 * @param string $currency Currency code.
	 * @return int
	 */
	private function get_price_decimals( string $currency ): int {
		if ( strtoupper( $currency ) === strtoupper( get_woocommerce_currency() ) ) {
			return wc_get_price_decimals();
		}

		return (int) ( wc_get_container()->get( LocalizationService::class )->get_currency_format( strtoupper( $currency ) )['num_decimals'] ?? 2 );
	}

	/**
	 * Get the ID of the order paid by an invoice.
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return int Order ID, or 0 when no order has the invoice.
	 */
	public function get_order_id_by_invoice_id( string $invoice_id ): int {
		/**
		 * Order IDs, as `return` asks.
		 *
		 * @var int[] $order_ids
		 */
		$order_ids = wc_get_orders(
			array(
				'status'     => 'any',
				'type'       => 'shop_order',
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => self::ORDER_INVOICE_ID_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $invoice_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return absint( reset( $order_ids ) );
	}

	/**
	 * Record the invoice a subscription is waiting to have paid.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $invoice_id   Invoice ID.
	 */
	public function mark_pending_invoice_for_subscription( WC_Order $subscription, string $invoice_id ): void {
		$this->set_pending_invoice_id( $subscription, $invoice_id );
	}

	/**
	 * Clear the invoice a subscription was waiting to have paid.
	 *
	 * @param WC_Order $subscription Subscription.
	 */
	public function mark_pending_invoice_paid_for_subscription( WC_Order $subscription ): void {
		$this->set_pending_invoice_id( $subscription, '' );
	}

	/**
	 * Mark the invoice of a Stripe-billed subscription paid, without charging, once the store took the payment.
	 *
	 * Runs when a parent order is paid, and when a subscription without a Stripe subscription is renewed by hand.
	 * A manual subscription then renews automatically with the payment method of this order.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID.
	 */
	public function maybe_record_invoice_payment( $order_id ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || $this->get_order_invoice_id( $order ) ) {
			return;
		}

		$this->visited_orders[ get_current_blog_id() . ':' . $order->get_id() ] = true;
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) ) as $subscription ) {
			if ( $subscription instanceof WC_Order ) {
				$this->record_first_invoice_payment( $order, $subscription );
			}
		}
	}

	/**
	 * Renewals use their own payment-complete hook.
	 *
	 * @param WC_Order $order Paying order.
	 */
	public function schedule_first_invoice_job_now( WC_Order $order ): void {
		if ( isset( $this->visited_orders[ get_current_blog_id() . ':' . $order->get_id() ] ) || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}
		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) ) as $subscription ) {
			if ( $subscription instanceof WC_Order && $this->get_subscription_invoice_id( $subscription ) && ! $this->is_ended( $subscription ) && $this->get_subscription_service()->is_wcpay_subscription( $subscription ) ) {
				$this->schedule_attempt( $order->get_id(), $subscription->get_id(), $this->get_subscription_invoice_id( $subscription ), 0, time() );
			}
		}
	}

	/**
	 * A cancelled or pending-cancel subscription is skipped. While its first invoice is open, its Stripe subscription is either still incomplete,
	 * where marking the invoice paid would make Stripe bill the next period for a customer who cancelled, or already canceled, which Stripe cannot revive.
	 *
	 * @param WC_Order $subscription Subscription.
	 */
	private function is_ended( WC_Order $subscription ): bool {
		return ! function_exists( 'wcs_get_subscription_ended_statuses' ) || $subscription->has_status( wcs_get_subscription_ended_statuses() );
	}

	/**
	 * Record an outstanding invoice only while this order holds the payment and the subscription still holds this invoice.
	 *
	 * @internal
	 * @param mixed $order_id        Paying order ID.
	 * @param mixed $subscription_id Subscription ID.
	 * @param mixed $invoice_id      Original Stripe invoice ID.
	 * @param mixed $retry           Attempt number.
	 */
	public function handle_first_invoice_job( $order_id, $subscription_id, $invoice_id, $retry ): void {
		if ( SubscriptionMethodPolicy::is_duplicate_site() || ! function_exists( 'wcs_get_subscription' ) ) {
			return;
		}
		$order        = wc_get_order( absint( $order_id ) );
		$subscription = wcs_get_subscription( absint( $subscription_id ) );
		if ( ! $order instanceof WC_Order || ! $subscription instanceof WC_Order || $invoice_id !== $this->get_subscription_invoice_id( $subscription ) || ! $this->holds_captured_money( $order ) ) {
			return;
		}
		$this->record_first_invoice_payment( $order, $subscription, absint( $retry ) );
	}

	/**
	 * A setup intent can succeed without collecting money.
	 *
	 * @param WC_Order $order Paying order.
	 */
	private function holds_captured_money( WC_Order $order ): bool {
		$total = (float) $order->get_total();
		if ( 0.0 === $total ) {
			return $order->is_paid();
		}
		return 'succeeded' === $order->get_meta( '_intention_status', true )
			&& 0 === strpos( (string) $order->get_meta( '_intent_id', true ), 'pi_' )
			&& (float) $order->get_total_refunded() < $total;
	}

	/**
	 * Keep an invoice refusal from interrupting the other subscriptions on the same order.
	 *
	 * @param WC_Order $order        Paying order.
	 * @param WC_Order $subscription Stripe-billed subscription.
	 * @param int|null $retry        Job attempt number, or null for the inline payment.
	 */
	private function record_first_invoice_payment( WC_Order $order, WC_Order $subscription, ?int $retry = null ): void {
		$invoice_id = $this->get_subscription_invoice_id( $subscription );
		if ( ! $invoice_id || $this->is_ended( $subscription ) || ! $this->get_subscription_service()->is_wcpay_subscription( $subscription ) ) {
			return;
		}

		$attempt = ( $retry ?? 0 ) + 1;
		$late    = null !== $retry && time() >= $this->get_window_start( $order, $subscription ) + 23 * HOUR_IN_SECONDS;
		if ( ! $late ) {
			if ( $this->try_first_invoice_payment( $order, $subscription, $invoice_id, $attempt ) ) {
				return;
			}
			$last = $this->get_window_start( $order, $subscription ) + 22 * HOUR_IN_SECONDS;
			if ( $attempt <= 4 && time() < $last ) {
				$delays = WebhookReliabilityService::RETRY_DELAYS_SECONDS;
				$due    = isset( $delays[ $attempt - 1 ] ) ? time() + $delays[ $attempt - 1 ] : $last;
				$this->schedule_attempt( $order->get_id(), $subscription->get_id(), $invoice_id, $due >= $last ? 4 : $attempt, min( $last, $due ) );
				return;
			}
			if ( null === $retry ) {
				// Keep the state read off checkout even when the order and subscription are already old.
				$this->schedule_attempt( $order->get_id(), $subscription->get_id(), $invoice_id, 4, time() );
				return;
			}
		}

		$subscription_id = $subscription->get_id();
		try {
			$stripe = $this->api->get_subscription( $this->get_subscription_service()->get_wcpay_subscription_id( $subscription ) );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( 'Could not check the first invoice payment.', $exception, compact( 'subscription_id' ) );
			return;
		}
		$status = $stripe['status'] ?? '';
		$latest = $stripe['latest_invoice'] ?? null;
		if ( 'active' === $status || ( is_array( $latest ) && ( $latest['id'] ?? '' ) === $invoice_id && 'paid' === ( $latest['status'] ?? '' ) ) ) {
			$this->adopt_order_payment_method( $subscription, $order );
		} elseif ( in_array( $status, array( 'incomplete_expired', 'canceled' ), true ) ) {
			$this->add_bound_note( $subscription, $invoice_id );
		} elseif ( 'incomplete' === $status ) {
			$this->try_first_invoice_payment( $order, $subscription, $invoice_id, $late ? $attempt : $attempt + 1 );
		} else {
			$this->logger->log_always( 'Could not verify the first invoice payment from the subscription status.', 'warning', compact( 'subscription_id' ) );
		}
	}

	/**
	 * Only a lasting platform answer about this invoice can end an attempt with a merchant note.
	 *
	 * @param WC_Order $order        Paying order.
	 * @param WC_Order $subscription Stripe-billed subscription.
	 * @param string   $invoice_id   Bound first invoice.
	 * @param int      $attempt      One-based pay attempt for the log.
	 * @return bool Whether the payment outcome is known.
	 */
	private function try_first_invoice_payment( WC_Order $order, WC_Order $subscription, string $invoice_id, int $attempt ): bool {
		try {
			$this->api->charge_invoice( $invoice_id, array( 'paid_out_of_band' => 'true' ) );
		} catch ( Throwable $exception ) {
			if ( $exception instanceof ApiException && WP_Http::BAD_REQUEST === $exception->get_http_code() && false !== stripos( $exception->getMessage(), 'invoice is already paid' ) ) {
				$this->logger->log( sprintf( 'Invoice for subscription #%s has already been paid.', $subscription->get_id() ) );
			} else {
				$context = array(
					'subscription_id' => $subscription->get_id(),
					'attempt'         => $attempt,
				);
				$this->logger->log_throwable_always( 'Could not record the first invoice payment.', $exception, $context );
				if ( ! $exception instanceof ApiException || ApiException::leaves_the_answer_unknown( $exception ) || $exception->has_ambiguous_outcome() ) {
					return false;
				}
				// Local failures can also carry an HTTP status, without a platform error envelope.
				if ( $exception->get_http_code() < 400 || ( '' === $exception->get_error_type() && ! isset( $exception->get_error_data()['status'] ) ) ) {
					return false;
				}
				$this->add_bound_note( $subscription, $invoice_id );
				return true;
			}
		}
		$this->adopt_order_payment_method( $subscription, $order );
		return true;
	}

	/**
	 * A resumed checkout creates a new subscription. A manual renewal creates its Stripe subscription when paid.
	 *
	 * @param WC_Order $order        Paying order.
	 * @param WC_Order $subscription Subscription created for this checkout.
	 */
	private function get_window_start( WC_Order $order, WC_Order $subscription ): int {
		$created = $order->get_date_created();
		$opened  = $subscription->get_date_created();
		$start   = max( $created ? $created->getTimestamp() : time(), $opened ? $opened->getTimestamp() : time() );
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			$paid = $order->get_date_paid();
			return max( $start, $paid ? $paid->getTimestamp() : time() );
		}
		return $start;
	}

	/**
	 * A remade subscription must never receive a job queued for its previous invoice.
	 *
	 * @param int    $order_id        Paying order ID.
	 * @param int    $subscription_id Subscription ID.
	 * @param string $invoice_id      Stripe invoice ID.
	 * @param int    $retry           Attempt number.
	 * @param int    $due             Timestamp.
	 */
	private function schedule_attempt( int $order_id, int $subscription_id, string $invoice_id, int $retry, int $due ): void {
		try {
			$this->scheduler->schedule_job( self::FIRST_INVOICE_ACTION, compact( 'order_id', 'subscription_id', 'invoice_id', 'retry' ), $due );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( 'Could not schedule the first invoice payment.', $exception );
		}
	}

	/**
	 * Inline and delayed failures share the invoice’s note identity.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $invoice_id   Stripe invoice ID.
	 */
	private function add_bound_note( WC_Order $subscription, string $invoice_id ): void {
		/* translators: %s: Stripe subscription ID. */
		$note = sprintf( __( "WooPayments could not tell Stripe that this subscription's first payment was received, so Stripe stopped billing it and it will not renew. The customer's first payment is not affected. To keep billing this customer, ask them to subscribe again, then cancel this subscription. Stripe subscription ID: %s.", 'woocommerce' ), esc_html( $this->get_subscription_service()->get_wcpay_subscription_id( $subscription ) ) );
		wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $subscription, $note, 'stripe_billing_first_invoice_unrecorded:' . $invoice_id );
	}

	/**
	 * Make a manual subscription renew automatically with the payment method that paid an order.
	 *
	 * @param WC_Order $subscription Manual subscription.
	 * @param WC_Order $order        Order whose payment method the subscription takes.
	 */
	private function adopt_order_payment_method( WC_Order $subscription, WC_Order $order ): void {
		if ( ! is_callable( array( $subscription, 'is_manual' ) ) || ! $subscription->is_manual() || ! is_callable( array( $subscription, 'set_requires_manual_renewal' ) ) ) {
			return;
		}

		$subscription->set_requires_manual_renewal( false );
		$subscription->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		wc_get_container()->get( SubscriptionsController::class )->update_failing_payment_method( $subscription, $order );
		$subscription->save();
	}

	/**
	 * Update the Stripe subscription's items, then its discounts, to match the WooCommerce subscription, saving any new discount IDs on the WooCommerce subscription.
	 *
	 * @param array<int,array<string,mixed>> $wcpay_item_data     Invoice lines.
	 * @param array<int,mixed>               $wcpay_discount_data Invoice discount IDs.
	 * @param WC_Order                       $subscription        Subscription.
	 * @throws StripeBillingException When the invoice lacks an item of the subscription.
	 * @throws ApiException When an update fails.
	 */
	public function update_wcpay_subscription_to_match( array $wcpay_item_data, array $wcpay_discount_data, WC_Order $subscription ): void {
		foreach ( $this->get_repair_data_for_wcpay_items( $wcpay_item_data, $subscription ) as $id => $data ) {
			$this->api->update_subscription_item( (string) $id, $data );
		}

		$discount_data = $this->get_repair_data_for_wcpay_discounts( $wcpay_discount_data, $subscription );
		if ( isset( $discount_data ) ) {
			$subscription_service = $this->get_subscription_service();
			$response             = $this->api->update_subscription(
				$subscription_service->get_wcpay_subscription_id( $subscription ),
				array( 'discounts' => $discount_data )
			);

			$subscription_service->set_wcpay_discount_ids( $subscription, $response['discounts'] );
		}
	}

	/**
	 * Save the invoice ID of an order.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $invoice_id Invoice ID.
	 */
	public function set_order_invoice_id( WC_Order $order, string $invoice_id ): void {
		$order->update_meta_data( self::ORDER_INVOICE_ID_KEY, $invoice_id );
		$order->save();
	}

	/**
	 * Save the invoice ID of a subscription: the invoice of its parent order.
	 *
	 * @param WC_Order $subscription      Subscription.
	 * @param string   $parent_invoice_id Parent order invoice ID.
	 */
	public function set_subscription_invoice_id( WC_Order $subscription, string $parent_invoice_id ): void {
		$subscription->update_meta_data( self::ORDER_INVOICE_ID_KEY, $parent_invoice_id );
		$subscription->save();
	}

	/**
	 * Tell the platform, on an invoice's payment intent, whether Stripe Billing is on for the store.
	 *
	 * `legacy_wcpay_subscription` is the subscription context sent with the invoice's payment intent while Stripe Billing is
	 * off for the store; the platform copies it into the intent's metadata, and client 11.1.0 sends the same value
	 * (class-wc-payments-invoice-service.php:315).
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return array<string,mixed> The invoice.
	 * @throws ApiException When the request fails.
	 */
	public function record_subscription_payment_context( string $invoice_id ): array {
		return $this->api->update_invoice(
			$invoice_id,
			array(
				'subscription_context' => $this->module->is_stripe_billing_enabled() ? 'stripe_billing' : 'legacy_wcpay_subscription',
			)
		);
	}

	/**
	 * Send the order's billing name, email and country to the transaction of an invoice's charge.
	 *
	 * @param array<string,mixed> $invoice Invoice.
	 * @param WC_Order            $order   Order the invoice paid.
	 * @throws ApiException When a request fails.
	 */
	public function update_transaction_details( array $invoice, WC_Order $order ): void {
		if ( ! isset( $invoice['charge'] ) ) {
			return;
		}

		$charge = $this->api_client->get_charge( (string) $invoice['charge'] );
		if ( ! isset( $charge['balance_transaction']['id'] ) ) {
			return;
		}

		$this->api->update_transaction(
			(string) $charge['balance_transaction']['id'],
			array(
				'customer_first_name' => $order->get_billing_first_name(),
				'customer_last_name'  => $order->get_billing_last_name(),
				'customer_email'      => $order->get_billing_email(),
				'customer_country'    => $order->get_billing_country(),
			)
		);
	}

	/**
	 * Send the order ID to the charge of an invoice.
	 *
	 * @param array<string,mixed> $invoice  Invoice.
	 * @param int                 $order_id Order the invoice paid.
	 * @throws ApiException When the request fails.
	 */
	public function update_charge_details( array $invoice, int $order_id ): void {
		if ( ! isset( $invoice['charge'] ) ) {
			return;
		}

		$this->api->update_charge(
			(string) $invoice['charge'],
			array(
				'metadata' => array( 'order_id' => $order_id ),
			)
		);
	}

	/**
	 * Save the ID of the invoice a subscription is waiting to have paid.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @param string   $invoice_id   Invoice ID, or an empty string when none is waiting.
	 */
	private function set_pending_invoice_id( WC_Order $subscription, string $invoice_id ): void {
		$subscription->update_meta_data( self::PENDING_INVOICE_ID_KEY, $invoice_id );
		$subscription->save();
	}

	/**
	 * Get the quantity and price updates that make the Stripe subscription items match the WooCommerce subscription.
	 *
	 * Billing period, interval and currency stay as Stripe has them: they cannot change mid-term.
	 *
	 * @param array<int,array<string,mixed>> $wcpay_item_data Invoice lines.
	 * @param WC_Order                       $subscription    Subscription.
	 * @return array<string,array<string,mixed>> Updates keyed by Stripe subscription item ID.
	 * @throws StripeBillingException When the invoice lacks an item of the subscription.
	 */
	private function get_repair_data_for_wcpay_items( array $wcpay_item_data, WC_Order $subscription ): array {
		$repair_data          = array();
		$wcpay_items          = array();
		$subscription_items   = $subscription->get_items( array( 'line_item', 'fee', 'shipping', 'tax' ) );
		$subscription_service = $this->get_subscription_service();

		foreach ( $wcpay_item_data as $item ) {
			$wcpay_items[ $item['subscription_item'] ] = array(
				'unit_amount'      => $item['price']['unit_amount_decimal'],
				'billing_period'   => $item['price']['recurring']['interval'],
				'billing_interval' => $item['price']['recurring']['interval_count'],
				'currency'         => $item['price']['currency'],
				'quantity'         => $item['quantity'],
			);
		}

		foreach ( $subscription_service->get_recurring_item_data_for_subscription( $subscription ) as $recurring_item_data ) {
			$item          = $subscription_items[ $recurring_item_data['metadata']['wc_item_id'] ];
			$wcpay_item_id = $subscription_service->get_wcpay_subscription_item_id( $item );

			if ( ! isset( $wcpay_items[ $wcpay_item_id ] ) ) {
				$message = __( 'The WooPayments invoice items do not match WC subscription items.', 'woocommerce' );
				$this->logger->log( $message, 'error' );
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is internal application state, not HTML output.
				throw new StripeBillingException( $message, StripeBillingException::INVOICE_ITEMS_MISMATCH );
			}

			if ( $item->is_type( 'line_item' ) && $wcpay_items[ $wcpay_item_id ]['quantity'] !== $recurring_item_data['quantity'] ) {
				$repair_data[ $wcpay_item_id ]['quantity'] = $recurring_item_data['quantity'];
			}

			if ( (string) $wcpay_items[ $wcpay_item_id ]['unit_amount'] !== (string) $recurring_item_data['price_data']['unit_amount_decimal'] ) {
				$price_data              = $recurring_item_data['price_data'];
				$price_data['currency']  = $wcpay_items[ $wcpay_item_id ]['currency'];
				$price_data['recurring'] = array(
					'interval'       => $wcpay_items[ $wcpay_item_id ]['billing_period'],
					'interval_count' => $wcpay_items[ $wcpay_item_id ]['billing_interval'],
				);

				$repair_data[ $wcpay_item_id ]['price_data'] = $price_data;
			}
		}

		return $repair_data;
	}

	/**
	 * Get the discounts that make the Stripe subscription match the WooCommerce subscription's coupons.
	 *
	 * @param array<int,mixed> $wcpay_discount_data Invoice discount IDs.
	 * @param WC_Order         $subscription        Subscription.
	 * @return array<int,array<string,mixed>>|null The discounts to send, or null when they already match.
	 */
	private function get_repair_data_for_wcpay_discounts( array $wcpay_discount_data, WC_Order $subscription ): ?array {
		$subscription_service      = $this->get_subscription_service();
		$subscription_discount_ids = $subscription_service->get_wcpay_discount_ids( $subscription );

		if ( empty( $subscription_discount_ids ) && empty( $wcpay_discount_data ) ) {
			return null;
		}

		if ( count( $subscription_discount_ids ) !== count( $wcpay_discount_data ) ) {
			return $subscription_service->get_discount_item_data_for_subscription( $subscription );
		}

		foreach ( $subscription_discount_ids as $discount_id ) {
			if ( ! in_array( $discount_id, $wcpay_discount_data, true ) ) {
				return $subscription_service->get_discount_item_data_for_subscription( $subscription );
			}
		}

		return null;
	}

	/**
	 * Get the subscription service.
	 *
	 * Resolved on use: the subscription service injects this service, so injecting it back would make a construction cycle.
	 *
	 * @return StripeBillingSubscriptionService
	 */
	private function get_subscription_service(): StripeBillingSubscriptionService {
		return wc_get_container()->get( StripeBillingSubscriptionService::class );
	}
}
