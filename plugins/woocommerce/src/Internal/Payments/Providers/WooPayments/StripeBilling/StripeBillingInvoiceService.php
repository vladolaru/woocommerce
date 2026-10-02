<?php
/**
 * StripeBillingInvoiceService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use WC_Order;
use WP_Http;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps WooCommerce orders and subscriptions in step with their Stripe Billing invoices.
 *
 * Meta keys and platform calls are client 11.1.0's (`includes/subscriptions/class-wc-payments-invoice-service.php`),
 * so a store can go back to the plugin.
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

	/**
	 * Stripe Billing platform calls.
	 *
	 * @var StripeBillingApi
	 */
	private StripeBillingApi $api;

	/**
	 * Platform API client, for the charge read.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Module root, the single reader of the Stripe Billing toggle.
	 *
	 * @var WooPaymentsStripeBillingModule
	 */
	private WooPaymentsStripeBillingModule $module;

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
	 * @param StripeBillingApi               $api        Stripe Billing platform calls.
	 * @param WooPaymentsApiClient           $api_client Platform API client.
	 * @param WooPaymentsStripeBillingModule $module     Module root.
	 * @param StripeBillingLogger            $logger     Module logger.
	 */
	final public function init( StripeBillingApi $api, WooPaymentsApiClient $api_client, WooPaymentsStripeBillingModule $module, StripeBillingLogger $logger ): void {
		$this->api        = $api;
		$this->api_client = $api_client;
		$this->module     = $module;
		$this->logger     = $logger;
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
	 * @throws WooPaymentsApiException When the platform refuses to mark the invoice paid for another reason than it being paid already.
	 */
	public function maybe_record_invoice_payment( $order_id ): void {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || $this->get_order_invoice_id( $order ) ) {
			return;
		}

		foreach ( wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) ) as $subscription ) {
			if ( ! $subscription instanceof WC_Order ) {
				continue;
			}

			$invoice_id = $this->get_subscription_invoice_id( $subscription );
			if ( ! $invoice_id || ! $this->get_subscription_service()->is_wcpay_subscription( $subscription ) ) {
				continue;
			}

			try {
				$this->api->charge_invoice( $invoice_id, array( 'paid_out_of_band' => 'true' ) );
			} catch ( WooPaymentsApiException $exception ) {
				if ( WP_Http::BAD_REQUEST !== $exception->get_http_code() || false === stripos( $exception->getMessage(), 'invoice is already paid' ) ) {
					throw $exception;
				}

				$this->logger->log( sprintf( 'Invoice for subscription #%s has already been paid.', $subscription->get_id() ) );
			}

			if ( is_callable( array( $subscription, 'is_manual' ) ) && $subscription->is_manual() && is_callable( array( $subscription, 'set_requires_manual_renewal' ) ) ) {
				$subscription->set_requires_manual_renewal( false );
				$subscription->set_payment_method( WooPaymentsPersistenceProfile::GATEWAY_ID );

				// The subscription renews with the payment method that paid this order.
				wc_get_container()->get( NativeWooPaymentsGateway::class )->update_failing_payment_method( $subscription, $order );
				$subscription->save();
			}
		}
	}

	/**
	 * Update the Stripe subscription so its items and discounts match the WooCommerce subscription.
	 *
	 * @param array<int,array<string,mixed>> $wcpay_item_data     Invoice lines.
	 * @param array<int,mixed>               $wcpay_discount_data Invoice discount IDs.
	 * @param WC_Order                       $subscription        Subscription.
	 * @throws StripeBillingException When the invoice lacks an item of the subscription.
	 * @throws WooPaymentsApiException When an update fails.
	 */
	public function validate_invoice( array $wcpay_item_data, array $wcpay_discount_data, WC_Order $subscription ): void {
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
	 * Tell the platform whether an invoice was paid under Stripe Billing or a legacy WooPayments subscription.
	 *
	 * @param string $invoice_id Invoice ID.
	 * @return array<string,mixed> The invoice.
	 * @throws WooPaymentsApiException When the request fails.
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
	 * @throws WooPaymentsApiException When a request fails.
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
	 * @throws WooPaymentsApiException When the request fails.
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
	 * Get the subscription service when it is used, as client 11.1.0 does: the subscription service uses this service.
	 *
	 * @return StripeBillingSubscriptionService
	 */
	private function get_subscription_service(): StripeBillingSubscriptionService {
		return wc_get_container()->get( StripeBillingSubscriptionService::class );
	}
}
