<?php
/**
 * WooPaymentsChargeAmbiguityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use WC_Order;

/**
 * Finds what an earlier charge of an order did when its answer was lost.
 *
 * After an ambiguous charge failure the order keeps its idempotency key, and a resubmit with another body under that key
 * is refused by Stripe with an idempotency error, which means the earlier request finished. Its money call is a single
 * create-and-confirm carrying the order's id and key in its metadata, so the customer's PaymentIntents show whether it
 * created an intent and whether that intent moved money. Client 11.1.0 has no equivalent: it sends a fresh key per
 * request (`class-wc-payments-api-client.php:2690`) and charges the resubmit.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsChargeAmbiguityService {

	/**
	 * PaymentIntent statuses in which the intent holds the shopper's money.
	 */
	private const MONEY_STATUSES = array( 'succeeded', 'requires_capture', 'processing' );

	/**
	 * Most intents one lookup reads; Stripe's page limit.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Seconds before the recorded failure that a full page must reach back to before it can prove the order has no intent.
	 *
	 * The earlier request was sent at most one request timeout plus its transport retries before the failure was recorded.
	 */
	private const LOOKBACK_SECONDS = 300;

	/**
	 * Native API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsApiClient $api_client Native API client.
	 */
	final public function init( WooPaymentsApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * List the PaymentIntents created for an order.
	 *
	 * An intent belongs to the order when its metadata carries both the order's id and its order key. A customer's list
	 * also holds intents created elsewhere (other orders, Stripe Billing invoices), so both must match.
	 *
	 * @param WC_Order $order       Order whose earlier charge is looked up.
	 * @param string   $customer_id Customer the earlier charge request was sent with.
	 * @param int      $failed_at   Unix time the earlier charge failure was recorded.
	 * @return array<int,array<string,mixed>>|null The order's intents, newest first, possibly none; null when the lookup
	 *                                             failed or could not prove the list complete.
	 */
	public function find_order_intents( WC_Order $order, string $customer_id, int $failed_at ): ?array {
		try {
			$list = $this->api_client->list_payment_intentions( $customer_id, self::LIST_LIMIT );
		} catch ( WooPaymentsApiException $exception ) {
			return null;
		}

		if ( ! isset( $list['data'] ) || ! is_array( $list['data'] ) ) {
			return null;
		}

		$order_intents = array();
		$oldest        = null;
		foreach ( $list['data'] as $intent ) {
			if ( ! is_array( $intent ) ) {
				continue;
			}

			$oldest   = $intent;
			$metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
			if ( (string) $order->get_id() === (string) ( $metadata['order_id'] ?? '' ) && $order->get_order_key() === (string) ( $metadata['order_key'] ?? '' ) ) {
				$order_intents[] = $intent;
			}
		}

		// A full page with no match that does not reach back before the earlier request may have left its intent for the next page.
		if ( array() === $order_intents && ! empty( $list['has_more'] ) ) {
			$oldest_created = is_array( $oldest ) && is_numeric( $oldest['created'] ?? null ) ? (int) $oldest['created'] : PHP_INT_MAX;
			if ( $oldest_created >= $failed_at - self::LOOKBACK_SECONDS ) {
				return null;
			}
		}

		return $order_intents;
	}

	/**
	 * Get the first of the order's intents that holds the shopper's money.
	 *
	 * @param array<int,array<string,mixed>> $order_intents The order's intents, newest first.
	 * @return array<string,mixed>|null
	 */
	public static function find_intent_with_money( array $order_intents ): ?array {
		foreach ( $order_intents as $intent ) {
			if ( in_array( (string) ( $intent['status'] ?? '' ), self::MONEY_STATUSES, true ) ) {
				return $intent;
			}
		}

		return null;
	}

	/**
	 * Log, whatever the logging setting, that the earlier request paid the order, so the new payment method was not charged.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 * @param string   $intent_id       The earlier request's PaymentIntent.
	 */
	public function log_earlier_payment_found( WC_Order $order, string $idempotency_key, string $intent_id ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The earlier request created PaymentIntent %3$s, which took the payment, so the order is paid from it and the new payment method is not charged.',
				$idempotency_key,
				$order->get_id(),
				$intent_id
			),
			$order,
			$idempotency_key
		);
	}

	/**
	 * Log, whatever the logging setting, that the earlier request took no money, so the new payment method is charged now.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 * @param int      $intent_count    How many PaymentIntents the earlier request left for the order, none holding money.
	 */
	public function log_charging_after_no_earlier_payment( WC_Order $order, string $idempotency_key, int $intent_count ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The order has %3$d PaymentIntent(s) from it and none took the payment, so the key is retired and the new payment method is charged now under a fresh key.',
				$idempotency_key,
				$order->get_id(),
				$intent_count
			),
			$order,
			$idempotency_key
		);
	}

	/**
	 * Log, whatever the logging setting, that the lookup failed, so this payment attempt was refused and the key kept.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 */
	public function log_lookup_failed( WC_Order $order, string $idempotency_key ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one, and the PaymentIntents of the order could not be listed to learn whether the earlier request took the payment. This payment attempt is refused without a charge; the key is kept and the next attempt looks again.',
				$idempotency_key,
				$order->get_id()
			),
			$order,
			$idempotency_key
		);
	}

	/**
	 * Write an always-on warning about a kept charge key, since support needs it to reconcile the order.
	 *
	 * @param string   $message         Log line.
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key.
	 */
	private function log( string $message, WC_Order $order, string $idempotency_key ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_always(
			$message,
			'warning',
			array(
				'order_id'        => $order->get_id(),
				'idempotency_key' => $idempotency_key,
			)
		);
	}
}
