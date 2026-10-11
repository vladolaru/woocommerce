<?php
/**
 * WooPaymentsRefundAmbiguityService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Throwable;
use WC_Cache_Helper;
use WC_Order;
use WC_Order_Refund;

/**
 * Holds the key of a refund whose answer was lost, and settles that earlier attempt before the order's next refund.
 *
 * A refund answered ambiguously (a transport failure, a server error, or a key still in use) may have gone through
 * at Stripe while WooCommerce deleted its refund row, so a retry under a fresh key could refund twice. The order keeps
 * the attempt's key and request in one record. Every refund POST carries its key as `metadata.refund_attempt`, so the
 * next refund call reads the charge's refunds first and decides from what the earlier attempt left: link it to this
 * call, resend it under the same key, refuse this call, or forget the record and send fresh. Everything runs under the
 * order payment lock the refund call already holds. Client 11.1.0 has no equivalent: it sends a fresh key per request
 * (`class-wc-payments-api-client.php:2690`) and never looks for the earlier refund.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsRefundAmbiguityService {

	/**
	 * Decision: send this call's refund under its own key.
	 */
	public const ACTION_SEND = 'send';

	/**
	 * Decision: resend the earlier attempt's request under its kept key.
	 */
	public const ACTION_RETRY_HELD_KEY = 'retry_held_key';

	/**
	 * Decision: answer this call with the earlier attempt's refund, with no request.
	 */
	public const ACTION_LINK = 'link';

	/**
	 * Decision: refuse this call with no request.
	 */
	public const ACTION_REFUSE = 'refuse';

	/**
	 * Refusal: the earlier attempt could not be checked.
	 */
	public const REFUSAL_CHECK_FAILED = 'wcpay_refund_check_failed';

	/**
	 * Refusal: the earlier attempt went through for another amount and is not recorded on the order yet.
	 */
	public const REFUSAL_EARLIER_FOUND = 'wcpay_refund_earlier_found';

	/**
	 * Refusal: the earlier attempt went through for this amount and is already recorded on the order.
	 */
	public const REFUSAL_ALREADY_RECORDED = 'wcpay_refund_already_recorded';

	/**
	 * Refusal: the earlier attempt may still be running.
	 */
	public const REFUSAL_SETTLING = 'wcpay_refund_settling';

	/**
	 * Every refusal, each made with no platform refund attempt.
	 */
	public const REFUSAL_CODES = array(
		self::REFUSAL_CHECK_FAILED,
		self::REFUSAL_EARLIER_FOUND,
		self::REFUSAL_ALREADY_RECORDED,
		self::REFUSAL_SETTLING,
	);

	/**
	 * Most refunds one lookup reads; Stripe's page limit.
	 */
	private const LIST_LIMIT = 100;

	/**
	 * Seconds after the latest ambiguous failure during which the earlier attempt may still be running, so its refund
	 * may not be listed yet.
	 *
	 * The store gives up 70 s after it sent, and the platform's Stripe call ends within 70 s plus a 10 s connect
	 * timeout of its start; the order payment lock lives as long.
	 */
	private const HELD_KEY_WINDOW_SECONDS = 300;

	/**
	 * Seconds after the latest ambiguous failure during which a same-amount call is taken as a retry from a stale
	 * order screen when the earlier refund is already recorded.
	 */
	private const ALREADY_RECORDED_WINDOW_SECONDS = 900;

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
	 * Decide what this refund call does about the order's earlier ambiguous attempt.
	 *
	 * With no record, or one that no longer applies, the call sends under its own key. Otherwise the charge's refunds
	 * are read and the one carrying the kept key (or the refund the record already located) decides; the record is
	 * kept while the earlier attempt is unsettled and cleared once it is.
	 *
	 * @param WC_Order                                                    $order      Order being refunded.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request    This call's refund request.
	 * @param int                                                         $own_row_id The refund row this call links, as the runtime handed it over; 0 when
	 *                                                                                unknown, and then no manual record or backstop is decided.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string} `action` (one of the ACTION_* values), with `key` and `request` for a held-key retry, `refund` for a
	 *         link, and `code` and `message` for a refusal.
	 */
	public function decide( WC_Order $order, array $request, int $own_row_id = 0 ): array {
		$record = $this->read_record( $order, $request['charge'] );
		if ( null === $record ) {
			return array( 'action' => self::ACTION_SEND );
		}

		// Without the row this call links (a direct wc_refund_payment() caller), the order's other rows cannot be told
		// apart from it, so the manual-record rule and the backstop do not run (monitor ruling 2026-10-10 14:10).
		if ( 0 < $own_row_id && '' !== $record['found_refund_id'] && $this->clear_found_refund_the_order_cannot_refund( $order, $record, $own_row_id ) ) {
			return array( 'action' => self::ACTION_SEND );
		}

		try {
			$list = $this->api_client->list_charge_refunds( $request['charge'], self::LIST_LIMIT );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->refuse_check_failed( $order, $record, 'the charge\'s refunds could not be listed (' . $exception->get_error_code() . ')' );
		}

		if ( ! isset( $list['data'] ) || ! is_array( $list['data'] ) ) {
			return $this->refuse_check_failed( $order, $record, 'the platform answered without a refund list' );
		}

		$refund = $this->find_attempt_refund( $list['data'], $record );
		if ( null === $refund ) {
			return $this->decide_without_refund( $order, $record, $request, ! empty( $list['has_more'] ) );
		}

		return $this->decide_with_refund( $order, $record, $request, $refund, $own_row_id );
	}

	/**
	 * Record an ambiguous answer to a refund request, or move the latest failure time of the record for the same key.
	 *
	 * @param WC_Order                                                    $order      Order being refunded.
	 * @param string                                                      $key        Key the request was sent with.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request    Refund request sent.
	 * @param int                                                         $own_row_id The refund row the call links, for the log line; 0 when unknown.
	 */
	public function record_ambiguous_answer( WC_Order $order, string $key, array $request, int $own_row_id = 0 ): void {
		$now    = time();
		$record = $this->read_record( $order, $request['charge'] );
		if ( null !== $record && $key === $record['key'] ) {
			// A clock stepped back never moves the end of the held-key window earlier.
			$record['last_failed_at'] = max( $record['last_failed_at'], $now );
		} else {
			$record = array(
				'order_id'        => $order->get_id(),
				'charge_id'       => $request['charge'],
				'key'             => $key,
				'amount'          => $request['amount'],
				'currency'        => strtolower( (string) $order->get_currency() ),
				'request'         => $request,
				'wc_refund_id'    => 0 < $own_row_id ? $own_row_id : $this->get_newest_refund_id( $order ),
				'failed_at'       => $now,
				'last_failed_at'  => $now,
				'found_refund_id' => '',
				'found_amount'    => 0,
			);
		}

		$order->update_meta_data( WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META, $record );
		$order->save_meta_data();

		$this->log(
			sprintf(
				'The refund request sent under key %1$s for order #%2$d (local refund #%3$d, %4$d %5$s) got an ambiguous answer, so it may have gone through. The key is kept, and the next refund of the order reads the charge\'s refunds before sending anything.',
				$key,
				$order->get_id(),
				$record['wc_refund_id'],
				$record['amount'],
				$record['currency']
			),
			$order,
			$key
		);
	}

	/**
	 * Settle the record after a held-key retry got a refund back.
	 *
	 * The record is cleared. A refund already recorded on the order (its webhook arrived meanwhile) refuses this call,
	 * since linking it again would record it twice.
	 *
	 * @param WC_Order            $order  Order being refunded.
	 * @param array<string,mixed> $refund Refund the retry got back.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}|null A refusal decision, or null to apply the refund to this call.
	 */
	public function settle_held_key_answer( WC_Order $order, array $refund ): ?array {
		$record = $order->get_meta( WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META, true );
		$key    = is_array( $record ) ? (string) ( $record['key'] ?? '' ) : '';
		$this->clear_record( $order );

		$refund_id = (string) ( $refund['id'] ?? '' );
		$this->log_cleared( $order, array( 'key' => $key ), sprintf( 'resending its request under the held key returned refund %s', $refund_id ) );
		if ( '' === $refund_id || ! $this->is_recorded_on_order( $order, $refund_id ) ) {
			return null;
		}

		return $this->refuse(
			$order,
			$key,
			self::REFUSAL_ALREADY_RECORDED,
			sprintf(
				/* translators: %s: refund amount. */
				__( 'The earlier refund of %s went through and is recorded on the order. No new refund was sent. Reload the order to see it.', 'woocommerce' ),
				self::format_refund_amount( $refund, $order )
			),
			sprintf( 'resending it under the kept key returned refund %s, which is already recorded on the order', $refund_id )
		);
	}

	/**
	 * Decide when the charge's refunds hold nothing from the earlier attempt.
	 *
	 * @param WC_Order                                                    $order    Order being refunded.
	 * @param array<string,mixed>                                         $record   The order's record.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request  This call's refund request.
	 * @param bool                                                        $has_more Whether the list page is incomplete.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}
	 */
	private function decide_without_refund( WC_Order $order, array $record, array $request, bool $has_more ): array {
		if ( $has_more ) {
			return $this->refuse_check_failed( $order, $record, 'the earlier attempt is not on the first page of the charge\'s refunds and more pages exist' );
		}

		// A located refund never leaves the list; a complete list without it cannot settle anything.
		if ( '' !== $record['found_refund_id'] ) {
			return $this->refuse_check_failed( $order, $record, sprintf( 'the refund %s located earlier is missing from the complete list of the charge\'s refunds', $record['found_refund_id'] ) );
		}

		if ( time() - $record['last_failed_at'] >= self::HELD_KEY_WINDOW_SECONDS ) {
			$this->clear_record( $order );
			$this->log_cleared( $order, $record, 'the charge\'s refunds hold nothing from it and it can no longer be running, so this call sends under its own key' );

			return array( 'action' => self::ACTION_SEND );
		}

		if ( $request['amount'] === $record['amount'] ) {
			$this->log(
				sprintf(
					'The earlier refund attempt under key %1$s for order #%2$d is not listed yet and may still be running, so this same-amount call resends its request under the same key instead of sending a new refund.',
					$record['key'],
					$order->get_id()
				),
				$order,
				$record['key']
			);

			return array(
				'action'  => self::ACTION_RETRY_HELD_KEY,
				'key'     => $record['key'],
				'request' => $record['request'],
			);
		}

		return $this->refuse(
			$order,
			$record['key'],
			self::REFUSAL_SETTLING,
			__( 'An earlier refund attempt for this order is still being confirmed. No new refund was sent. Please try again in a few minutes.', 'woocommerce' ),
			'it is not listed yet and may still be running, and this call is for another amount'
		);
	}

	/**
	 * Decide from the refund the earlier attempt made.
	 *
	 * @param WC_Order                                                    $order      Order being refunded.
	 * @param array<string,mixed>                                         $record     The order's record.
	 * @param array{charge:string,amount:int,reason:string,source:string} $request    This call's refund request.
	 * @param array<string,mixed>                                         $refund     The earlier attempt's refund.
	 * @param int                                                         $own_row_id The refund row this call links.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}
	 */
	private function decide_with_refund( WC_Order $order, array $record, array $request, array $refund, int $own_row_id ): array {
		$refund_id       = (string) ( $refund['id'] ?? '' );
		$refund_amount   = is_numeric( $refund['amount'] ?? null ) ? (int) $refund['amount'] : -1;
		$refund_currency = strtolower( (string) ( $refund['currency'] ?? '' ) );

		// The marker is unique to the attempt, so its refund decides, even when it differs from what the record holds.
		if ( $refund_amount !== $record['amount'] || $refund_currency !== $record['currency'] ) {
			$this->log(
				sprintf(
					'The refund %1$s carrying the kept key %2$s for order #%3$d is for %4$d %5$s, but the record holds %6$d %7$s. The refund decides.',
					$refund_id,
					$record['key'],
					$order->get_id(),
					$refund_amount,
					$refund_currency,
					$record['amount'],
					$record['currency']
				),
				$order,
				$record['key']
			);
		}

		if ( in_array( (string) ( $refund['status'] ?? '' ), array( 'failed', 'canceled' ), true ) ) {
			$this->clear_record( $order );
			$this->log_cleared( $order, $record, sprintf( 'its refund %s did not go through, so this call sends under its own key', $refund_id ) );

			return array( 'action' => self::ACTION_SEND );
		}

		$is_same_amount = $request['amount'] === $refund_amount && strtolower( (string) $order->get_currency() ) === $refund_currency;
		if ( ! $this->is_recorded_on_order( $order, $refund_id ) ) {
			// The merchant may have recorded it with Refund manually: that row records it, so this call is a refund of its
			// own (monitor rulings 2026-10-10 13:20 and 13:17).
			// The rows are in the order's currency, so only a refund in that currency can be one of them (Codex review 201 M4).
			$manual_row = 0 < $own_row_id && strtolower( (string) $order->get_currency() ) === $refund_currency
				? self::find_manual_record_row( $this->get_earlier_refund_rows( $order, $own_row_id ), (string) $order->get_currency(), $refund_amount, (int) $record['failed_at'] )
				: null;
			if ( null !== $manual_row ) {
				$this->link_manual_record_row( $order, $record, $manual_row, $refund_id );

				return array( 'action' => self::ACTION_SEND );
			}

			if ( $is_same_amount ) {
				$this->clear_record( $order );
				$this->log_cleared( $order, $record, sprintf( 'its refund %s went through for this amount and is not recorded on the order, so it answers this call with no new refund', $refund_id ) );

				return array(
					'action' => self::ACTION_LINK,
					'refund' => $refund,
				);
			}

			return $this->refuse_earlier_found( $order, $record, $refund, $refund_id, $refund_amount );
		}

		// After row 4 told the merchant to refund the found amount, its webhook row may have recorded it meanwhile: that
		// refund is refused at any time, once, since the hold is cleared with it.
		if ( $is_same_amount && ( '' !== $record['found_refund_id'] || time() - $record['last_failed_at'] <= self::ALREADY_RECORDED_WINDOW_SECONDS ) ) {
			$this->clear_record( $order );

			return $this->refuse(
				$order,
				$record['key'],
				self::REFUSAL_ALREADY_RECORDED,
				sprintf(
					/* translators: %s: refund amount. */
					__( 'The earlier refund of %s went through and is recorded on the order. No new refund was sent. Reload the order to see it.', 'woocommerce' ),
					self::format_refund_amount( $refund, $order )
				),
				sprintf( 'its refund %s is already recorded on the order, and this same-amount call came soon after the failure or after the order was told to refund that amount', $refund_id )
			);
		}

		$this->clear_record( $order );
		$this->log_cleared( $order, $record, sprintf( 'its refund %s is already recorded on the order, so this call sends under its own key', $refund_id ) );

		return array( 'action' => self::ACTION_SEND );
	}

	/**
	 * Refuse a call for another amount while the earlier attempt's refund is not recorded on the order.
	 *
	 * The record keeps the refund it located, so a later same-amount call links it, and the merchant gets one note.
	 *
	 * @param WC_Order            $order         Order being refunded.
	 * @param array<string,mixed> $record        The order's record.
	 * @param array<string,mixed> $refund        The earlier attempt's refund.
	 * @param string              $refund_id     Its ID.
	 * @param int                 $refund_amount Its amount in minor units.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}
	 */
	private function refuse_earlier_found( WC_Order $order, array $record, array $refund, string $refund_id, int $refund_amount ): array {
		$formatted_amount = self::format_refund_amount( $refund, $order );
		if ( $refund_id !== $record['found_refund_id'] ) {
			$record['found_refund_id'] = $refund_id;
			$record['found_amount']    = $refund_amount;
			$record['found_currency']  = strtolower( (string) ( $refund['currency'] ?? '' ) );
			$order->update_meta_data( WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META, $record );
			$order->save_meta_data();
			$order->add_order_note(
				sprintf(
					/* translators: %1$s: refund amount, %2$s: provider refund ID. */
					__( 'An earlier WooPayments refund of %1$s (%2$s) went through after its answer was lost, and is not yet recorded on this order. To record it, refund %1$s through WooPayments (no money is sent for that step), or record it with Refund manually.', 'woocommerce' ),
					WooPaymentsCurrencyUtils::format_explicit_order_price( WooPaymentsCurrencyUtils::amount_from_minor_units( $refund_amount, (string) ( $refund['currency'] ?? '' ) ), (string) ( $refund['currency'] ?? '' ), $order ),
					$refund_id
				)
			);
		}

		return $this->refuse(
			$order,
			$record['key'],
			self::REFUSAL_EARLIER_FOUND,
			sprintf(
				/* translators: %1$s: earlier refund amount, %2$s: the same amount. */
				__( 'The earlier refund of %1$s went through but is not yet recorded on this order. Refund %2$s first to record it; no money is sent for that step.', 'woocommerce' ),
				$formatted_amount,
				$formatted_amount
			),
			sprintf( 'its refund %s went through for another amount and is not recorded on the order', $refund_id )
		);
	}

	/**
	 * Find the earlier attempt's refund in a list page: the one carrying the kept key, or the refund already located.
	 *
	 * @param array<mixed>        $refunds List items.
	 * @param array<string,mixed> $record  The order's record.
	 * @return array<string,mixed>|null
	 */
	private function find_attempt_refund( array $refunds, array $record ): ?array {
		foreach ( $refunds as $refund ) {
			if ( ! is_array( $refund ) ) {
				continue;
			}

			$metadata = isset( $refund['metadata'] ) && is_array( $refund['metadata'] ) ? $refund['metadata'] : array();
			if ( (string) ( $metadata['refund_attempt'] ?? '' ) === $record['key'] ) {
				return $refund;
			}

			if ( '' !== $record['found_refund_id'] && (string) ( $refund['id'] ?? '' ) === $record['found_refund_id'] ) {
				return $refund;
			}
		}

		return null;
	}

	/**
	 * Read the order's record when it applies to this order and charge.
	 *
	 * A record naming another order or charge was copied or left stale, and is deleted with a warning. A record never
	 * expires: losing the key at Stripe after 24 hours does not settle what the earlier request did, so only the lookup
	 * does (monitor ruling 2026-10-10 13:45, Codex review 201 H3).
	 *
	 * @param WC_Order $order     Order being refunded.
	 * @param string   $charge_id The order's charge.
	 * @return array{order_id:int,charge_id:string,key:string,amount:int,currency:string,request:array{charge:string,amount:int,reason:string,source:string},wc_refund_id:int,failed_at:int,last_failed_at:int,found_refund_id:string,found_amount:int,found_currency:string}|null
	 */
	private function read_record( WC_Order $order, string $charge_id ): ?array {
		$stored = $order->get_meta( WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META, true );
		if ( '' === $stored || null === $stored ) {
			return null;
		}

		$request = is_array( $stored ) && isset( $stored['request'] ) && is_array( $stored['request'] ) ? $stored['request'] : array();
		$key     = is_array( $stored ) ? (string) ( $stored['key'] ?? '' ) : '';
		if ( ! is_array( $stored ) || (int) ( $stored['order_id'] ?? 0 ) !== $order->get_id() || (string) ( $stored['charge_id'] ?? '' ) !== $charge_id || '' === $key || (string) ( $request['charge'] ?? '' ) !== $charge_id ) {
			$this->clear_record( $order );
			$this->log( sprintf( 'The refund ambiguity record on order #%d names another order or charge, or is malformed, so it is ignored and deleted.', $order->get_id() ), $order, $key );

			return null;
		}

		$failed_at = (int) ( $stored['failed_at'] ?? 0 );

		return array(
			'order_id'        => $order->get_id(),
			'charge_id'       => $charge_id,
			'key'             => $key,
			'amount'          => (int) ( $stored['amount'] ?? 0 ),
			'currency'        => strtolower( (string) ( $stored['currency'] ?? '' ) ),
			'request'         => array(
				'charge' => $charge_id,
				'amount' => (int) ( $request['amount'] ?? 0 ),
				'reason' => (string) ( $request['reason'] ?? '' ),
				'source' => (string) ( $request['source'] ?? '' ),
			),
			'wc_refund_id'    => (int) ( $stored['wc_refund_id'] ?? 0 ),
			'failed_at'       => $failed_at,
			'last_failed_at'  => max( $failed_at, (int) ( $stored['last_failed_at'] ?? 0 ) ),
			'found_refund_id' => (string) ( $stored['found_refund_id'] ?? '' ),
			'found_amount'    => (int) ( $stored['found_amount'] ?? 0 ),
			'found_currency'  => strtolower( (string) ( $stored['found_currency'] ?? ( $stored['currency'] ?? '' ) ) ),
		);
	}

	/**
	 * Delete the order's record.
	 *
	 * @param WC_Order $order Order being refunded.
	 */
	private function clear_record( WC_Order $order ): void {
		$order->delete_meta_data( WooPaymentsProviderGatewayAdapter::REFUND_AMBIGUITY_META );
		$order->save_meta_data();
	}

	/**
	 * Tell whether a provider refund is already linked to one of the order's local refunds.
	 *
	 * The order's refund IDs are read again past the request cache, since the refund webhook may have linked one in
	 * another request.
	 *
	 * @param WC_Order $order     Order being refunded.
	 * @param string   $refund_id Provider refund ID.
	 * @return bool
	 */
	private function is_recorded_on_order( WC_Order $order, string $refund_id ): bool {
		foreach ( $this->get_fresh_refunds( $order ) as $refund ) {
			if ( $refund_id === (string) $refund->get_meta( '_wcpay_refund_id', true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clear a hold that found its refund when the order can no longer refund the found amount, before reading the
	 * charge's refunds.
	 *
	 * The hold then protects nothing: whatever recorded the earlier refund, refunding its amount is no longer possible
	 * (Opus review F2, monitor ruling 2026-10-10 13:20). The row this call links, by the ID the runtime handed over, does
	 * not count. When a manual row records the found refund (the usual reason the order cannot refund it), it is linked
	 * first, unless the found refund is already recorded on the order (monitor ruling 2026-10-10 14:10, L1).
	 *
	 * @param WC_Order            $order      Order being refunded.
	 * @param array<string,mixed> $record     The order's record, holding a found refund.
	 * @param int                 $own_row_id The refund row this call links.
	 * @return bool Whether the hold was cleared, so this call sends under its own key.
	 */
	private function clear_found_refund_the_order_cannot_refund( WC_Order $order, array $record, int $own_row_id ): bool {
		$rows        = $this->get_earlier_refund_rows( $order, $own_row_id );
		$currency    = (string) $order->get_currency();
		$found_id    = (string) $record['found_refund_id'];
		$found_minor = (int) $record['found_amount'];

		// Another request's gateway refund may still be running and fail, whenever its row was created; only the rows already
		// settled count, which can only keep the hold: a manual record, a refund through the gateway, or a row linked to a
		// provider refund, money that has moved (monitor rulings 2026-10-10 15:05, 17:40; Codex review 206 F1).
		$settled_rows = array_filter(
			$rows,
			static fn( WC_Order_Refund $refund ): bool => self::is_manual_record_row( $refund )
				|| $refund->get_refunded_payment()
				|| '' !== (string) $refund->get_meta( '_wcpay_refund_id', true )
		);
		$other_minor  = array_sum( array_map( static fn( WC_Order_Refund $refund ): int => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $refund->get_amount(), $currency ), $settled_rows ) );
		if ( $found_minor > WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), $currency ) - $other_minor ) {
			$manual_row = strtolower( $currency ) === (string) ( $record['found_currency'] ?? '' ) && ! $this->is_recorded_on_order( $order, $found_id )
				? self::find_manual_record_row( $rows, $currency, $found_minor, (int) $record['failed_at'] )
				: null;
			if ( null !== $manual_row ) {
				$this->link_manual_record_row( $order, $record, $manual_row, $found_id );

				return true;
			}

			$this->clear_record( $order );
			$this->log_cleared( $order, $record, sprintf( 'the order can no longer refund the %1$d %2$s of the found refund %3$s, so the hold protects nothing and this call sends under its own key', $found_minor, strtolower( $currency ), $found_id ) );

			return true;
		}

		return false;
	}

	/**
	 * Get the order's local refunds other than this call's own row, read again past the request cache.
	 *
	 * @param WC_Order $order      Order being refunded.
	 * @param int      $own_row_id The refund row this call links.
	 * @return WC_Order_Refund[]
	 */
	private function get_earlier_refund_rows( WC_Order $order, int $own_row_id ): array {
		$refunds = $this->get_fresh_refunds( $order );

		return array_values( array_filter( $refunds, static fn( WC_Order_Refund $refund ): bool => $own_row_id !== $refund->get_id() ) );
	}

	/**
	 * Find the manual refund row that records the earlier attempt's refund: marked as a manual record, not refunded through
	 * the gateway, with no provider refund ID, created after the failure, for exactly the refund's amount. The oldest one
	 * takes the link.
	 *
	 * @param WC_Order_Refund[] $rows         The order's refunds other than this call's own row.
	 * @param string            $currency     Order currency.
	 * @param int               $amount_minor The refund's amount in minor units.
	 * @param int               $failed_at    Unix time of the first ambiguous failure.
	 * @return WC_Order_Refund|null
	 */
	private static function find_manual_record_row( array $rows, string $currency, int $amount_minor, int $failed_at ): ?WC_Order_Refund {
		$oldest     = null;
		$oldest_key = null;
		foreach ( $rows as $refund ) {
			$created = $refund->get_date_created();
			if (
				self::is_manual_record_row( $refund )
				&& ! $refund->get_refunded_payment()
				&& '' === (string) $refund->get_meta( '_wcpay_refund_id', true )
				&& null !== $created && $created->getTimestamp() >= $failed_at
				&& WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $refund->get_amount(), $currency ) === $amount_minor
			) {
				$key = array( $created->getTimestamp(), $refund->get_id() );
				if ( null === $oldest_key || $key < $oldest_key ) {
					$oldest     = $refund;
					$oldest_key = $key;
				}
			}
		}

		return $oldest;
	}

	/**
	 * Tell whether a refund row is marked as one the merchant recorded without refunding through a gateway (RefundRowCapture).
	 *
	 * A row without the mark is never taken for the merchant's record: a gateway refund, a row still inside its datastore
	 * write, or one saved by code that never fires `woocommerce_create_refund` (Codex review 205 R1, monitor ruling
	 * 2026-10-10 17:40).
	 *
	 * @param WC_Order_Refund $refund Refund row.
	 * @return bool
	 */
	private static function is_manual_record_row( WC_Order_Refund $refund ): bool {
		return '' !== (string) $refund->get_meta( RefundRowCapture::MANUAL_REFUND_META, true );
	}

	/**
	 * Link the earlier attempt's refund to the manual row that records it, and clear the hold.
	 *
	 * @param WC_Order            $order      Order being refunded.
	 * @param array<string,mixed> $record     The order's record.
	 * @param WC_Order_Refund     $manual_row Manual refund row.
	 * @param string              $refund_id  The earlier attempt's refund.
	 */
	private function link_manual_record_row( WC_Order $order, array $record, WC_Order_Refund $manual_row, string $refund_id ): void {
		$manual_row->update_meta_data( '_wcpay_refund_id', $refund_id );
		$manual_row->save_meta_data();
		$this->clear_record( $order );
		$this->log_cleared( $order, $record, sprintf( 'the manual refund #%1$d the merchant recorded after the failure is for its refund %2$s, so it is linked to it and this call sends under its own key', $manual_row->get_id(), $refund_id ) );
	}

	/**
	 * Get the ID of the order's newest local refund, which this call created, for a caller that did not say which it links.
	 *
	 * @param WC_Order $order Order being refunded.
	 * @return int 0 when the order has none.
	 */
	private function get_newest_refund_id( WC_Order $order ): int {
		$ids = array_map( static fn( WC_Order_Refund $refund ): int => $refund->get_id(), $this->get_fresh_refunds( $order ) );

		return array() === $ids ? 0 : max( $ids );
	}

	/**
	 * Get the order's local refunds, read again past the request cache.
	 *
	 * @param WC_Order $order Order being refunded.
	 * @return WC_Order_Refund[]
	 */
	private function get_fresh_refunds( WC_Order $order ): array {
		wp_cache_delete( WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refund_ids' . $order->get_id(), 'orders' );

		return array_values( $order->get_refunds() );
	}

	/**
	 * Refuse because the earlier attempt could not be settled; the record stays.
	 *
	 * @param WC_Order            $order  Order being refunded.
	 * @param array<string,mixed> $record The order's record.
	 * @param string              $why    Why, for the log line.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}
	 */
	private function refuse_check_failed( WC_Order $order, array $record, string $why ): array {
		return $this->refuse(
			$order,
			(string) $record['key'],
			self::REFUSAL_CHECK_FAILED,
			__( 'An earlier refund attempt for this order may have gone through, and it could not be checked just now. No new refund was sent. Please try again in a few minutes.', 'woocommerce' ),
			$why
		);
	}

	/**
	 * Build a refusal and log it.
	 *
	 * @param WC_Order $order   Order being refunded.
	 * @param string   $key     Kept key.
	 * @param string   $code    One of the REFUSAL_* codes.
	 * @param string   $message Merchant message.
	 * @param string   $why     What was found, for the log line.
	 * @return array{action:string,key?:string,request?:array{charge:string,amount:int,reason:string,source:string},refund?:array<string,mixed>,code?:string,message?:string}
	 */
	private function refuse( WC_Order $order, string $key, string $code, string $message, string $why ): array {
		$this->log(
			sprintf( 'A refund of order #%1$d was refused with %2$s and no request: the earlier attempt under key %3$s is unsettled because %4$s.', $order->get_id(), $code, $key, $why ),
			$order,
			$key
		);

		return array(
			'action'  => self::ACTION_REFUSE,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Log that the record was cleared.
	 *
	 * @param WC_Order            $order  Order being refunded.
	 * @param array<string,mixed> $record The cleared record.
	 * @param string              $why    Why, for the log line.
	 */
	private function log_cleared( WC_Order $order, array $record, string $why ): void {
		$this->log(
			sprintf( 'The earlier refund attempt under key %1$s for order #%2$d is settled and its record is deleted: %3$s.', (string) $record['key'], $order->get_id(), $why ),
			$order,
			(string) $record['key']
		);
	}

	/**
	 * Format a provider refund's amount in its own currency, as plain text for a merchant message.
	 *
	 * @param array<string,mixed> $refund Provider refund.
	 * @param WC_Order            $order  Order being refunded.
	 * @return string
	 */
	private static function format_refund_amount( array $refund, WC_Order $order ): string {
		$currency = (string) ( $refund['currency'] ?? '' );
		$currency = '' !== $currency ? $currency : (string) $order->get_currency();
		$amount   = is_numeric( $refund['amount'] ?? null ) ? (int) $refund['amount'] : 0;

		return WooPaymentsCurrencyUtils::format_currency( WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency ), strtoupper( $currency ) );
	}

	/**
	 * Write an always-on warning about a held refund key, since support needs it to reconcile the order.
	 *
	 * Best-effort: a failing logger or log filter never changes what was decided for the refund.
	 *
	 * @param string   $message         Log line.
	 * @param WC_Order $order           Order being refunded.
	 * @param string   $idempotency_key Held refund key.
	 */
	private function log( string $message, WC_Order $order, string $idempotency_key ): void {
		try {
			wc_get_container()->get( WooPaymentsLogger::class )->log_always(
				$message,
				'warning',
				array(
					'order_id'        => $order->get_id(),
					'idempotency_key' => $idempotency_key,
				)
			);
		} catch ( Throwable $log_failure ) {
			unset( $log_failure );
		}
	}
}
