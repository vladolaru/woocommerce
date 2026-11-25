<?php
/**
 * WooPaymentsChargeAmbiguityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use WC_Order;

/**
 * Finds what an earlier charge of an order did when its answer was lost.
 *
 * After an ambiguous charge failure the order keeps its idempotency key, and a resubmit with another body under that key
 * is refused by Stripe with an idempotency error, which means the earlier request finished. Its money call is a single
 * create-and-confirm carrying the order's id and key in its metadata, so the order's PaymentIntents show whether it
 * created an intent and whether that intent moved money.
 * Client 11.1.0 has no equivalent: it sends a fresh key per request (`class-wc-payments-api-client.php:2690`) and
 * charges the resubmit.
 *
 * @since 11.2.0
 * @internal
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
	 * Most intents one lookup reads; Stripe's page limit.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Seconds before the window's start from which the account's intents are listed.
	 *
	 * Stripe filters `created` by its own clock and the window's start comes from the store's, so the window is wider than
	 * the request timing alone needs. A wider window can only turn a charge into "cannot check", never the reverse.
	 */
	private const ACCOUNT_LOOKBACK_SECONDS = 3600;

	/**
	 * Seconds after the lookup up to which the account's intents are listed, for the same clock difference.
	 */
	private const ACCOUNT_LOOKAHEAD_SECONDS = 3600;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param Client            $api_client WooPayments API client.
	 * @param WooPaymentsLogger $logger     WooPayments logger.
	 */
	final public function init( Client $api_client, WooPaymentsLogger $logger ): void {
		$this->api_client = $api_client;
		$this->logger     = $logger;
	}

	/**
	 * List the PaymentIntents created for an order.
	 *
	 * No money is concluded only from the account's intents created since the key was kept, every page read, with none
	 * naming the order and holding money or a payable voucher; the customer's list, read first when the earlier requests
	 * were sent with one customer, only finds a payment faster. A transport failure or a server error leaves the lookup
	 * failed.
	 *
	 * @param WC_Order          $order          Order whose earlier charge is looked up.
	 * @param array<int,string> $customer_ids   Customers the earlier charge requests under the key were sent with.
	 * @param int               $since          Unix time before every request under the key.
	 * @param int               $now            Unix time of this lookup, after every request under the key.
	 * @return array{status:string,intents:array<int,array<string,mixed>>} One of the LOOKUP_* answers, with the order's
	 *                                                                    intents, newest first, when it is LOOKUP_DONE.
	 */
	public function find_order_intents( WC_Order $order, array $customer_ids, int $since, int $now ): array {
		if ( 1 !== count( $customer_ids ) ) {
			return $this->find_account_order_intents( $order, $since, $now );
		}

		try {
			$list = $this->api_client->list_payment_intentions( reset( $customer_ids ), self::LIST_LIMIT );
		} catch ( ApiException $exception ) {
			return $exception->has_ambiguous_outcome()
				? self::lookup( self::LOOKUP_FAILED )
				: $this->find_account_order_intents( $order, $since, $now );
		}

		$order_intents = $this->get_order_intents_from_list( $list, $order );

		return null === $order_intents || null === self::find_intent_with_money( $order_intents ) ? $this->find_account_order_intents( $order, $since, $now ) : self::lookup( self::LOOKUP_DONE, $order_intents );
	}

	/**
	 * List the order's PaymentIntents among the account's intents created from shortly before a time to shortly after now.
	 *
	 * @param WC_Order $order          Order whose earlier charge is looked up.
	 * @param int      $since          Unix time the window starts.
	 * @param int      $now            Unix time of this lookup.
	 * @return array{status:string,intents:array<int,array<string,mixed>>}
	 */
	private function find_account_order_intents( WC_Order $order, int $since, int $now ): array {
		try {
			$list = $this->api_client->list_payment_intentions_created_between( $since - self::ACCOUNT_LOOKBACK_SECONDS, $now + self::ACCOUNT_LOOKAHEAD_SECONDS, self::LIST_LIMIT );
		} catch ( ApiException $exception ) {
			return self::lookup( $exception->has_ambiguous_outcome() ? self::LOOKUP_FAILED : self::LOOKUP_CANNOT_CHECK );
		}

		$order_intents = $this->get_order_intents_from_list( $list, $order );

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
	 * @param array<string,mixed> $page         The list page.
	 * @param WC_Order            $order        Order whose earlier charge is looked up.
	 * @return array<int,array<string,mixed>>|null The order's intents, newest first, possibly none; null when the answer
	 *                                             has no list, or cannot rule out money (more pages, a loose match, or a
	 *                                             payable voucher).
	 */
	private function get_order_intents_from_list( array $page, WC_Order $order ): ?array {
		if ( ! isset( $page['data'] ) || ! is_array( $page['data'] ) ) {
			return null;
		}

		$order_intents = array();
		$loose_money   = false;
		$payable       = false;
		foreach ( $page['data'] as $intent ) {
			if ( ! is_array( $intent ) ) {
				continue;
			}

			$metadata = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
			if ( (string) $order->get_id() === (string) ( $metadata['order_id'] ?? '' ) && $order->get_order_key() === (string) ( $metadata['order_key'] ?? '' ) ) {
				$order_intents[] = $intent;
				$payable         = $payable || self::may_hold_or_take_money( $intent );
			} elseif ( WooPaymentsIntentCodec::intent_belongs_to_order( $intent, $order ) && self::may_hold_or_take_money( $intent ) ) {
				// Named by order ID alone, or by number on WooPay: it may be another order's, so it stops a clear but never pays.
				$loose_money = true;
			}
		}

		// More pages, a loose match that may hold or take money, or a strict match that may still be paid (a voucher awaiting
		// payment) can each hide the payment, so the page can find money but never clear it.
		return ( ! empty( $page['has_more'] ) || $loose_money || $payable ) && null === self::find_intent_with_money( $order_intents ) ? null : $order_intents;
	}

	/**
	 * Tell whether an intent holds the shopper's money or, as an offline voucher awaiting payment, may still take it.
	 *
	 * @param array<string,mixed> $intent Intent response.
	 * @return bool
	 */
	private static function may_hold_or_take_money( array $intent ): bool {
		return null !== self::find_intent_with_money( array( $intent ) ) || ( WooPaymentsIntentStatus::REQUIRES_ACTION === (string) ( $intent['status'] ?? '' ) && WooPaymentsIntentCodec::is_offline_payment_method_intent( $intent ) );
	}

	/**
	 * Get the first of the order's intents that holds the shopper's money.
	 *
	 * A fully refunded PaymentIntent keeps its `succeeded` status, so an older payment of the order that was given back
	 * must not count as the earlier request's payment. A disputed one is returned: its money may still come back.
	 *
	 * @param array<int,array<string,mixed>> $order_intents The order's intents, newest first.
	 * @return array<string,mixed>|null
	 */
	public static function find_intent_with_money( array $order_intents ): ?array {
		foreach ( $order_intents as $intent ) {
			if ( WooPaymentsIntentCodec::holds_money( (string) ( $intent['status'] ?? '' ) ) && ! WooPaymentsIntentCodec::is_fully_refunded( $intent ) ) {
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
	 * Log, whatever the logging setting, that the earlier request's payment is disputed, so this payment attempt was refused.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 * @param string   $intent_id       The earlier request's PaymentIntent.
	 */
	public function log_earlier_payment_disputed( WC_Order $order, string $idempotency_key, string $intent_id ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The earlier request created PaymentIntent %3$s, whose payment is disputed, so this payment attempt is refused without a charge and the key is kept.',
				$idempotency_key,
				$order->get_id(),
				$intent_id
			),
			$order,
			$idempotency_key
		);
	}

	/**
	 * Log, whatever the logging setting, that the earlier request took no money, so this payment attempt was refused and the key retired.
	 *
	 * @param WC_Order $order           Order being paid.
	 * @param string   $idempotency_key Kept charge key the earlier request was sent with.
	 * @param int      $intent_count    How many PaymentIntents the earlier request left for the order, none holding money.
	 */
	public function log_no_earlier_payment( WC_Order $order, string $idempotency_key, int $intent_count ): void {
		$this->log(
			sprintf(
				'The charge idempotency key %1$s kept on order #%2$d was refused because the new payment request differs from the earlier one. The order has %3$d PaymentIntent(s) from it and none took the payment, so the key is retired and this payment attempt is refused without a charge; the next attempt charges under a fresh key.',
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
		$this->logger->log_always(
			$message,
			'warning',
			array(
				'order_id'        => $order->get_id(),
				'idempotency_key' => $idempotency_key,
			)
		);
	}
}
