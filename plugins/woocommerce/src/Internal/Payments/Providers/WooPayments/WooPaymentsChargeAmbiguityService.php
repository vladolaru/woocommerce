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
 * create-and-confirm carrying the order's id and key in its metadata, so the customer's PaymentIntents (or the account's,
 * when the customer's cannot be listed) show whether it created an intent and whether that intent moved money. Client
 * 11.1.0 has no equivalent: it sends a fresh key per
 * request (`class-wc-payments-api-client.php:2690`) and charges the resubmit.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsChargeAmbiguityService {

	/**
	 * Lookup answer: the order's intents were listed, possibly none.
	 */
	public const LOOKUP_DONE = 'done';

	/**
	 * Lookup answer: the lists could not be read this time, and a later attempt may read them.
	 */
	public const LOOKUP_FAILED = 'failed';

	/**
	 * Lookup answer: neither the customer's nor the account's list can be read, so a later attempt will not settle it either.
	 */
	public const LOOKUP_CANNOT_CHECK = 'cannot_check';

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
	 * Lists the customer the earlier request was sent with. When Stripe or the platform refuses that list for good (a
	 * deleted customer, for example), lists the account's intents created since shortly before the failure instead. A
	 * transport failure or a server error leaves the lookup failed, since a later attempt may read the list.
	 *
	 * @param WC_Order $order       Order whose earlier charge is looked up.
	 * @param string   $customer_id Customer the earlier charge request was sent with.
	 * @param int      $failed_at   Unix time the earlier charge failure was recorded.
	 * @return array{status:string,intents:array<int,array<string,mixed>>} One of the LOOKUP_* answers, with the order's
	 *                                                                    intents, newest first, when it is LOOKUP_DONE.
	 */
	public function find_order_intents( WC_Order $order, string $customer_id, int $failed_at ): array {
		try {
			$list = $this->api_client->list_payment_intentions( $customer_id, self::LIST_LIMIT );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->api_client->is_ambiguous_request_failure( $exception )
				? self::lookup( self::LOOKUP_FAILED )
				: $this->find_order_intents_created_since_failure( $order, $failed_at );
		}

		$order_intents = $this->get_order_intents_from_list( $list, $order, $failed_at );

		return null === $order_intents ? self::lookup( self::LOOKUP_FAILED ) : self::lookup( self::LOOKUP_DONE, $order_intents );
	}

	/**
	 * List the order's PaymentIntents among the account's intents created since shortly before the recorded failure.
	 *
	 * A definitive refusal, or a page that cannot be proven complete, cannot change on a later attempt: the window only
	 * grows, so a full page can never reach back past it.
	 *
	 * @param WC_Order $order     Order whose earlier charge is looked up.
	 * @param int      $failed_at Unix time the earlier charge failure was recorded.
	 * @return array{status:string,intents:array<int,array<string,mixed>>}
	 */
	private function find_order_intents_created_since_failure( WC_Order $order, int $failed_at ): array {
		try {
			$list = $this->api_client->list_payment_intentions_created_since( $failed_at - self::LOOKBACK_SECONDS, self::LIST_LIMIT );
		} catch ( WooPaymentsApiException $exception ) {
			return self::lookup( $this->api_client->is_ambiguous_request_failure( $exception ) ? self::LOOKUP_FAILED : self::LOOKUP_CANNOT_CHECK );
		}

		$order_intents = $this->get_order_intents_from_list( $list, $order, $failed_at );

		return null === $order_intents ? self::lookup( self::LOOKUP_CANNOT_CHECK ) : self::lookup( self::LOOKUP_DONE, $order_intents );
	}

	/**
	 * Build a lookup answer.
	 *
	 * @param string                         $status        One of the LOOKUP_* answers.
	 * @param array<int,array<string,mixed>> $order_intents The order's intents.
	 * @return array{status:string,intents:array<int,array<string,mixed>>}
	 */
	private static function lookup( string $status, array $order_intents = array() ): array {
		return array(
			'status'  => $status,
			'intents' => $order_intents,
		);
	}

	/**
	 * Get the order's intents from a PaymentIntents list page.
	 *
	 * An intent belongs to the order when its metadata carries both the order's id and its order key. A list also holds
	 * intents created elsewhere (other orders, Stripe Billing invoices), so both must match.
	 *
	 * @param array<string,mixed> $page      The list page.
	 * @param WC_Order            $order     Order whose earlier charge is looked up.
	 * @param int                 $failed_at Unix time the earlier charge failure was recorded.
	 * @return array<int,array<string,mixed>>|null The order's intents, newest first, possibly none; null when the answer
	 *                                             has no list or the page cannot be proven complete.
	 */
	private function get_order_intents_from_list( array $page, WC_Order $order, int $failed_at ): ?array {
		if ( ! isset( $page['data'] ) || ! is_array( $page['data'] ) ) {
			return null;
		}

		$order_intents = array();
		$oldest        = null;
		foreach ( $page['data'] as $intent ) {
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
		if ( array() === $order_intents && ! empty( $page['has_more'] ) ) {
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
	 * Log, whatever the logging setting, that neither list can be read, so this payment attempt was refused and the key kept.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 */
	public function log_lookup_cannot_check( WC_Order $order, string $idempotency_key ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one, and neither the customer\'s nor the account\'s PaymentIntents could be listed to learn whether the earlier request took the payment. This payment attempt is refused without a charge; the key is kept, every attempt on this order is refused the same way until a list can be read, and an order note asks the merchant to check the payment.',
				$idempotency_key,
				$order->get_id()
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
