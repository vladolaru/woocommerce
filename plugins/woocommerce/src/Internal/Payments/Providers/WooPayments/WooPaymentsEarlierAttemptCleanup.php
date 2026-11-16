<?php
/**
 * WooPaymentsEarlierAttemptCleanup class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the payment details of an earlier WooPayments attempt when the customer checks out an order with another
 * payment method, as client 11.3.0's maybe_remove_payment_details_for_other_gateway() does
 * (class-wc-payments-order-service.php:1270-1340), and finishes at shutdown: the transaction ID that names that attempt
 * is cleared, and an attempt still waiting for authentication is cancelled (:1384-1460).
 *
 * WooCommerce reuses a Pending or Failed order at checkout, which keeps what an earlier WooPayments attempt stored on it.
 * Every attempt stores its mode first, so a mode on an order of another payment method means an attempt since the last
 * cleanup. The stored values are written on a fresh copy of the order, under the order payment lock, never through the
 * checkout's own object, and then marked deleted on that object in memory only, so its later readers see them gone.
 *
 * @since 11.4.0
 * @internal
 */
class WooPaymentsEarlierAttemptCleanup implements RegisterHooksInterface {

	/**
	 * Seconds the read of a stored payment may take. It runs inside another gateway's checkout, before that gateway's
	 * payment, and a read that times out keeps everything, as the WooPayments extension does without the read.
	 */
	private const READ_TIMEOUT_SECONDS = 5;

	/**
	 * Claims of the order payment lock before giving up, as the in-person capture route waits
	 * (WooPaymentsTerminalPaymentsRestController): a WooPayments operation on the order holds it for well under a second.
	 */
	private const LOCK_CLAIM_ATTEMPTS = 4;

	/**
	 * Wait between two claims of the order payment lock, in microseconds.
	 */
	private const LOCK_RETRY_WAIT_MICROSECONDS = 500000;

	/**
	 * The operation the cleanup claims the order payment lock as.
	 */
	private const LOCK_OPERATION = 'takeover cleanup';

	/**
	 * Stored statuses that claim the attempt took money: client 11.3.0's Intent_Status::AUTHORIZED_STATUSES
	 * (includes/constants/class-intent-status.php:35-39).
	 */
	private const AUTHORIZED_STATUSES = array( 'succeeded', 'requires_capture', 'processing' );

	/**
	 * The values client 11.3.0 deletes (class-wc-payments-order-service.php:1313-1317): the charge, fee, net, currency
	 * and mandate stay.
	 */
	private const REMOVED_META_KEYS = array( '_stripe_customer_id', '_payment_method_id', '_intent_id', '_intention_status', '_wcpay_mode' );

	/**
	 * Log reason: the order payment lock refused the cleanup.
	 */
	private const REASON_LOCK_REFUSED = 'takeover_cleanup_lock_refused';

	/**
	 * Log reason: the stored payment could not be read, so everything was kept.
	 */
	private const REASON_READ_FAILED = 'takeover_read_failed';

	/**
	 * Log reason: the stored intent or status changed between the hook's first read and the claim.
	 */
	private const REASON_VALUES_CHANGED = 'takeover_values_changed';

	/**
	 * Log reason: the transaction ID names a payment intent other than the stored one, so it is left as it is.
	 */
	private const REASON_TRANSACTION_ID_NAMES_ANOTHER_INTENT = 'takeover_transaction_id_names_another_intent';

	/**
	 * Order lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private OrderPaymentLock $order_payment_lock;

	/**
	 * WooPayments persistence vocabulary.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $persistence_vocabulary;

	/**
	 * WooPayments API client.
	 *
	 * @var Client
	 */
	private Client $api_client;

	/**
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * WooPayments logger.
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Earlier attempts to finish at shutdown, keyed by order ID.
	 *
	 * @var array<int,array{intent_id:string,cancel:bool}>
	 */
	private array $attempts_to_finish = array();

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLifecycleService     $lifecycle_service      Order lifecycle service.
	 * @param OrderPaymentLock                 $order_payment_lock     Order payment lock.
	 * @param WooPaymentsPersistenceVocabulary $persistence_vocabulary WooPayments persistence vocabulary.
	 * @param Client                           $api_client             WooPayments API client.
	 * @param WooPaymentsOrderNoteService      $note_service           WooPayments order note service.
	 * @param WooPaymentsLogger                $logger                 WooPayments logger.
	 */
	final public function init( OrderPaymentLifecycleService $lifecycle_service, OrderPaymentLock $order_payment_lock, WooPaymentsPersistenceVocabulary $persistence_vocabulary, Client $api_client, WooPaymentsOrderNoteService $note_service, WooPaymentsLogger $logger ): void {
		$this->lifecycle_service      = $lifecycle_service;
		$this->order_payment_lock     = $order_payment_lock;
		$this->persistence_vocabulary = $persistence_vocabulary;
		$this->api_client             = $api_client;
		$this->note_service           = $note_service;
		$this->logger                 = $logger;
	}

	/**
	 * Clean up at the two checkout hooks, at client 11.3.0's priority (class-wc-payments-order-service.php:345-346): below
	 * WooCommerce Subscriptions' subscription creation at 100, which copies the order's meta into the new subscription.
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clean_up_after_classic_checkout' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clean_up_after_store_api_checkout' ) );
	}

	/**
	 * Clean up an order the classic checkout processed, and mark the removed values deleted on core's own order object.
	 *
	 * @internal
	 *
	 * @param mixed $order_id    Order ID.
	 * @param mixed $posted_data Posted checkout data, unused.
	 * @param mixed $order       The checkout's order object.
	 */
	public function clean_up_after_classic_checkout( $order_id, $posted_data = null, $order = null ): void {
		unset( $posted_data );
		$passed_order = $order instanceof WC_Order && $order->get_id() === absint( $order_id ) ? $order : null;
		$this->clean_up_safely( $passed_order ?? wc_get_order( absint( $order_id ) ), $passed_order );
	}

	/**
	 * Clean up an order the Store API checkout or order-pay route processed, and mark the removed values deleted on the
	 * route's own order object.
	 *
	 * @internal
	 *
	 * @param mixed $order The route's order object.
	 */
	public function clean_up_after_store_api_checkout( $order ): void {
		if ( $order instanceof WC_Order ) {
			$this->clean_up_safely( $order, $order );
		}
	}

	/**
	 * Finish the cleanups of this request: clear each order's transaction ID that still names the earlier attempt, under
	 * the order payment lock, then cancel an attempt still waiting for authentication, with a note either way.
	 *
	 * Runs on `shutdown`, once the checkout has saved the order, because a Store API cart update during the checkout can
	 * save the earlier gateway on the order (client 11.3.0 class-wc-payments-order-service.php:1290-1293). Each step
	 * catches every throwable: an uncaught error is fatal there.
	 *
	 * @internal
	 */
	public function finish_earlier_attempts(): void {
		$attempts                 = $this->attempts_to_finish;
		$this->attempts_to_finish = array();

		foreach ( $attempts as $order_id => $attempt ) {
			try {
				$this->finish_earlier_attempt( $order_id, $attempt['intent_id'], $attempt['cancel'] );
			} catch ( Throwable $exception ) {
				$this->logger->log_throwable_always( sprintf( 'The earlier WooPayments attempt of order #%d could not be finished.', $order_id ), $exception, array( 'order_id' => $order_id ) );
			}
		}
	}

	/**
	 * Run the cleanup, so that nothing it throws reaches the other gateway's checkout.
	 *
	 * @param mixed         $order        The order to clean up, as loaded: anything but an order is left alone.
	 * @param WC_Order|null $passed_order The object the hook passed, which gets the removals in memory.
	 */
	private function clean_up_safely( $order, ?WC_Order $passed_order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		try {
			$this->clean_up( $order, $passed_order );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( sprintf( 'The earlier WooPayments attempt of order #%d could not be cleaned up.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Clean up an order another payment method checked out, when an earlier WooPayments attempt stored details on it.
	 *
	 * A stored status that claims money on a payment intent is read once from the platform, in the order's own mode,
	 * before the order payment lock: a payment that holds money is kept with a note, and a failed read keeps everything
	 * with a note. Under the lock, on a fresh copy, the values the decision used are read again; when they changed, the
	 * stored values decide with no second read. A setup intent never holds money, so it is cleaned up without a read.
	 *
	 * @param WC_Order      $order        The order, as the checkout holds it.
	 * @param WC_Order|null $passed_order The object the hook passed, which gets the removals in memory.
	 */
	private function clean_up( WC_Order $order, ?WC_Order $passed_order ): void {
		if ( ! self::is_taken_over_attempt( $order ) ) {
			return;
		}

		$intent_id     = (string) $order->get_meta( '_intent_id', true );
		$stored_status = (string) $order->get_meta( '_intention_status', true );
		$read          = null;
		if ( in_array( $stored_status, self::AUTHORIZED_STATUSES, true ) && 0 === strpos( $intent_id, 'pi_' ) ) {
			$read = $this->read_stored_payment( $intent_id, (string) $order->get_meta( '_wcpay_mode', true ) );
		}

		$lock_token = $this->claim_order_payment_lock( $order, $intent_id );
		if ( null === $lock_token ) {
			$this->order_payment_lock->log_refusal( $order, $this->persistence_vocabulary, self::LOCK_OPERATION, null, array( 'reason' => self::REASON_LOCK_REFUSED ) );
			$this->add_lock_refused_note( $order, $intent_id );
			return;
		}

		try {
			$fresh_order = $this->lifecycle_service->get_fresh_order_from_data_store( $order );
			$removed     = $this->clean_up_under_lock( $fresh_order, $intent_id, $stored_status, $read );
		} finally {
			$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $lock_token );
		}

		if ( $removed && null !== $passed_order && $passed_order !== $fresh_order && $passed_order->get_id() === $fresh_order->get_id() ) {
			// The list and the takeover time are not mirrored: added on this object, its next save would add second rows.
			foreach ( self::REMOVED_META_KEYS as $meta_key ) {
				$passed_order->delete_meta_data( $meta_key );
			}
		}
	}

	/**
	 * Decide and write the cleanup on the fresh copy of the order, under the order payment lock.
	 *
	 * @param WC_Order                       $order         Fresh copy of the order, read under the lock.
	 * @param string                         $intent_id     The stored intent the hook read.
	 * @param string                         $stored_status The stored status the hook read.
	 * @param array<string,mixed>|false|null $read          The platform's answer, false for a failed read, or null for no read.
	 * @return bool Whether the stored values were removed.
	 */
	private function clean_up_under_lock( WC_Order $order, string $intent_id, string $stored_status, $read ): bool {
		if ( ! self::is_taken_over_attempt( $order ) ) {
			return false;
		}

		$status = (string) $order->get_meta( '_intention_status', true );
		if ( (string) $order->get_meta( '_intent_id', true ) !== $intent_id || $status !== $stored_status ) {
			$this->log_reason( $order, self::REASON_VALUES_CHANGED, 'takeover cleanup found the stored payment changed after its first read', $intent_id );
			$intent_id = (string) $order->get_meta( '_intent_id', true );
			$read      = null;
		}

		if ( false === $read ) {
			$this->log_reason( $order, self::REASON_READ_FAILED, 'takeover cleanup could not read the stored payment and kept it', $intent_id );
			$this->add_read_failed_note( $order, $intent_id );
			return false;
		}

		if ( is_array( $read ) ) {
			$status = (string) $read['status'];
			if ( in_array( $status, self::AUTHORIZED_STATUSES, true ) ) {
				$this->add_kept_payment_note( $order, $intent_id, $read );
				return false;
			}
		} elseif ( in_array( $status, self::AUTHORIZED_STATUSES, true ) && 0 !== strpos( $intent_id, 'seti_' ) ) {
			// Values that changed after the read decide by the stored status alone, as client 11.3.0 does (:1284-1288).
			return false;
		}

		$waits_for_authentication = 0 === strpos( $intent_id, 'pi_' ) && 'requires_action' === $status;
		$this->remove_payment_details( $order, $intent_id, $waits_for_authentication );

		return true;
	}

	/**
	 * Remove the earlier attempt's details from the fresh copy of the order, list its intent and store the takeover time,
	 * in client 11.3.0's stored forms (class-wc-payments-order-service.php:1304-1322), then note it and queue the
	 * shutdown step when the transaction ID names the attempt or the attempt is cancelled.
	 *
	 * HPOS's full save after a meta change is turned off for this save, as core's own sync does
	 * (OrdersTableDataStore::backfill_post_record()), so a status transition the copy carries is not saved here; the order
	 * caches are still cleared, so a later load reads the cleaned order.
	 *
	 * @param WC_Order $order     Fresh copy of the order, read under the lock.
	 * @param string   $intent_id The stored intent.
	 * @param bool     $cancel    Whether the attempt waits for authentication and is cancelled at shutdown.
	 */
	private function remove_payment_details( WC_Order $order, string $intent_id, bool $cancel ): void {
		$transaction_id = (string) $order->get_transaction_id();
		if ( 0 === strpos( $transaction_id, 'pi_' ) && $transaction_id !== $intent_id ) {
			$this->log_reason( $order, self::REASON_TRANSACTION_ID_NAMES_ANOTHER_INTENT, 'takeover cleanup left a transaction ID that names another intent', $intent_id );
		}

		$superseded_intent_ids = $order->get_meta( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, true );
		$superseded_intent_ids = is_array( $superseded_intent_ids ) ? $superseded_intent_ids : array();
		if ( '' !== $intent_id && ! in_array( $intent_id, $superseded_intent_ids, true ) ) {
			$superseded_intent_ids[] = $intent_id;
		}

		foreach ( self::REMOVED_META_KEYS as $meta_key ) {
			$order->delete_meta_data( $meta_key );
		}
		if ( array() !== $superseded_intent_ids ) {
			$order->update_meta_data( WooPaymentsPersistenceVocabulary::SUPERSEDED_INTENT_IDS_META_KEY, $superseded_intent_ids );
		}
		$order->update_meta_data( WooPaymentsPersistenceVocabulary::PAYMENT_DETAILS_REMOVED_META_KEY, (string) time() );

		add_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
		try {
			$order->save_meta_data();
		} finally {
			remove_filter( 'woocommerce_orders_table_datastore_should_save_after_meta_change', '__return_false' );
		}

		if ( '' === $intent_id ) {
			$order->add_order_note( __( 'The payment details of an earlier WooPayments attempt were removed because the customer checked out with another payment method.', 'woocommerce' ) );
		} else {
			$order->add_order_note(
				self::note_naming_intent(
					/* translators: %1$s: the ID of a WooPayments payment. */
					__( 'The payment details of an earlier WooPayments attempt (<code>%1$s</code>) were removed because the customer checked out with another payment method.', 'woocommerce' ),
					$intent_id
				)
			);
		}

		if ( $cancel || ( '' !== $intent_id && $transaction_id === $intent_id ) ) {
			$this->attempts_to_finish[ $order->get_id() ] = array(
				'intent_id' => $intent_id,
				'cancel'    => $cancel,
			);
			add_action( 'shutdown', array( $this, 'finish_earlier_attempts' ) );
		}
	}

	/**
	 * Finish one order's cleanup at shutdown.
	 *
	 * When the order payment lock stays held, the transaction ID is not cleared, the cancel is still made, and its note
	 * says so.
	 *
	 * @param int    $order_id  Order ID.
	 * @param string $intent_id The earlier attempt's intent.
	 * @param bool   $cancel    Whether to cancel the intent.
	 */
	private function finish_earlier_attempt( int $order_id, string $intent_id, bool $cancel ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$not_cleared = false;
		$lock_token  = $this->claim_order_payment_lock( $order, $intent_id );
		if ( null === $lock_token ) {
			$this->order_payment_lock->log_refusal( $order, $this->persistence_vocabulary, self::LOCK_OPERATION, null, array( 'reason' => self::REASON_LOCK_REFUSED ) );
			$not_cleared = (string) $order->get_transaction_id() === $intent_id;
		} else {
			try {
				$fresh_order = $this->lifecycle_service->get_fresh_order_from_data_store( $order );
				// A transaction ID another gateway set stays (client 11.3.0 class-wc-payments-order-service.php:1420-1433).
				if ( (string) $fresh_order->get_transaction_id() === $intent_id ) {
					$fresh_order->set_transaction_id( '' );
					$fresh_order->save();
				}
			} catch ( Throwable $exception ) {
				$this->logger->log_throwable_always( sprintf( 'The transaction ID of order #%d could not be cleared.', $order_id ), $exception, array( 'order_id' => $order_id ) );
			} finally {
				$this->order_payment_lock->release( $order, $this->persistence_vocabulary, $lock_token );
			}
		}

		if ( $cancel ) {
			$this->cancel_earlier_attempt( $order, $intent_id, $not_cleared );
		}
	}

	/**
	 * Cancel the earlier attempt's intent through the platform, never through the payment operation service, so the
	 * order is not cancelled and no fraud meta is written, and note whether it worked (client 11.3.0
	 * class-wc-payments-order-service.php:1442-1460).
	 *
	 * The cancel is made in the store's current mode, as the client's is; after a mode switch it fails with the note.
	 *
	 * @param WC_Order $order       The order.
	 * @param string   $intent_id   The earlier attempt's intent.
	 * @param bool     $not_cleared Whether the order payment lock kept the transaction ID from being cleared.
	 */
	private function cancel_earlier_attempt( WC_Order $order, string $intent_id, bool $not_cleared ): void {
		try {
			$this->api_client->cancel_intention( $intent_id );
			$message = $not_cleared
				/* translators: %1$s: the ID of a WooPayments payment. */
				? __( 'The earlier WooPayments payment <code>%1$s</code> was cancelled because the customer checked out with another payment method. The order\'s transaction ID was not cleared, because a WooPayments payment for this order was being recorded at that moment.', 'woocommerce' )
				/* translators: %1$s: the ID of a WooPayments payment. */
				: __( 'The earlier WooPayments payment <code>%1$s</code> was cancelled because the customer checked out with another payment method.', 'woocommerce' );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( sprintf( 'The earlier WooPayments attempt of order #%d could not be cancelled.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
			$message = $not_cleared
				/* translators: %1$s: the ID of a WooPayments payment. */
				? __( 'The earlier WooPayments payment <code>%1$s</code> could not be cancelled when the customer checked out with another payment method. Check that it was not charged. The order\'s transaction ID was not cleared, because a WooPayments payment for this order was being recorded at that moment.', 'woocommerce' )
				/* translators: %1$s: the ID of a WooPayments payment. */
				: __( 'The earlier WooPayments payment <code>%1$s</code> could not be cancelled when the customer checked out with another payment method. Check that it was not charged.', 'woocommerce' );
		}

		try {
			$order->add_order_note( self::note_naming_intent( $message, $intent_id ) );
		} catch ( Throwable $exception ) {
			$this->logger->log_throwable_always( sprintf( 'The cancel of an earlier WooPayments attempt could not be noted on order #%d.', $order->get_id() ), $exception, array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Read the stored payment intent once, in the order's own mode, within the read timeout.
	 *
	 * @param string $intent_id  Stored payment intent.
	 * @param string $order_mode The order's stored mode.
	 * @return array<string,mixed>|false The intent, or false when it could not be read.
	 */
	private function read_stored_payment( string $intent_id, string $order_mode ) {
		try {
			$intent = $this->api_client->get_payment_intention_for_mode( $intent_id, WooPaymentsOrderMode::TEST === $order_mode, self::READ_TIMEOUT_SECONDS );
		} catch ( Throwable $exception ) {
			return false;
		}

		return isset( $intent['status'] ) && is_string( $intent['status'] ) && '' !== $intent['status'] ? $intent : false;
	}

	/**
	 * Claim the order payment lock, waiting for a WooPayments operation on the order to finish.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $intent_id The stored intent, the lock value, or empty for the provider's sentinel.
	 * @return string|null Lock token, or null when the lock stayed held.
	 */
	private function claim_order_payment_lock( WC_Order $order, string $intent_id ): ?string {
		for ( $attempt = 1; ; ++$attempt ) {
			$lock_token = $this->order_payment_lock->claim( $order, $this->persistence_vocabulary, '' === $intent_id ? null : $intent_id, self::LOCK_OPERATION );
			if ( null !== $lock_token || self::LOCK_CLAIM_ATTEMPTS === $attempt ) {
				return $lock_token;
			}

			$this->wait_before_lock_retry();
		}
	}

	/**
	 * Wait before claiming the order payment lock again.
	 *
	 * Overridable so tests need not pay the wait.
	 */
	protected function wait_before_lock_retry(): void {
		usleep( self::LOCK_RETRY_WAIT_MICROSECONDS );
	}

	/**
	 * Note, once per intent, that a takeover kept a WooPayments payment the read found holding money, in the state the
	 * read gave it, and whether the order's transaction ID names it at this moment.
	 *
	 * A payment refunded in full or disputed still reads `succeeded`, so those are told apart from its charges first.
	 *
	 * @param WC_Order            $order     Fresh copy of the order, read under the lock.
	 * @param string              $intent_id The kept payment's intent.
	 * @param array<string,mixed> $intent    The intent the read returned.
	 */
	private function add_kept_payment_note( WC_Order $order, string $intent_id, array $intent ): void {
		if ( WooPaymentsIntentCodec::is_disputed( $intent ) ) {
			/* translators: %1$s: the ID of a WooPayments payment. */
			$message = __( 'When the customer checked out with another payment method, the WooPayments payment <code>%1$s</code> recorded on this order was disputed. If both payments end up made, one of them needs a refund.', 'woocommerce' );
		} elseif ( WooPaymentsIntentCodec::is_fully_refunded( $intent ) ) {
			/* translators: %1$s: the ID of a WooPayments payment. */
			$message = __( 'When the customer checked out with another payment method, the WooPayments payment <code>%1$s</code> recorded on this order had been refunded in full. If both payments end up made, one of them needs a refund.', 'woocommerce' );
		} elseif ( 'requires_capture' === $intent['status'] ) {
			/* translators: %1$s: the ID of a WooPayments payment. */
			$message = __( 'When the customer checked out with another payment method, the WooPayments payment <code>%1$s</code> recorded on this order was authorized and not captured. If both payments end up made, one of them needs a refund.', 'woocommerce' );
		} else {
			/* translators: %1$s: the ID of a WooPayments payment. */
			$message = __( 'When the customer checked out with another payment method, the WooPayments payment <code>%1$s</code> recorded on this order held money. If both payments end up made, one of them needs a refund.', 'woocommerce' );
		}

		$note = self::note_naming_intent( $message, $intent_id );
		if ( (string) $order->get_transaction_id() === $intent_id ) {
			$note .= ' ' . esc_html__( 'This order\'s transaction ID names that payment at this moment, so a refund or a dispute of it changes this order.', 'woocommerce' );
		}

		$this->note_service->add_note_once( $order, $note, 'takeover_kept_payment|' . $intent_id );
	}

	/**
	 * Note, once per intent, that the stored payment could not be checked, without claiming it took money.
	 *
	 * @param WC_Order $order     Fresh copy of the order, read under the lock.
	 * @param string   $intent_id The kept payment's intent.
	 */
	private function add_read_failed_note( WC_Order $order, string $intent_id ): void {
		$this->note_service->add_note_once(
			$order,
			self::note_naming_intent(
				/* translators: %1$s: the ID of a WooPayments payment. */
				__( 'The WooPayments payment <code>%1$s</code> could not be checked when the customer checked out with another payment method. Check whether it took money from the customer.', 'woocommerce' ),
				$intent_id
			),
			'takeover_read_failed|' . $intent_id
		);
	}

	/**
	 * Note that the order payment lock kept the cleanup from running, and where to check.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $intent_id The stored intent, or empty.
	 */
	private function add_lock_refused_note( WC_Order $order, string $intent_id ): void {
		if ( '' === $intent_id ) {
			$order->add_order_note( __( 'The payment details of an earlier WooPayments attempt were not removed when the customer checked out with another payment method, because a WooPayments payment for this order was being recorded at that moment. Before fulfilling the order, check its payment details or its WooPayments transactions.', 'woocommerce' ) );
			return;
		}

		$order->add_order_note(
			self::note_naming_intent(
				/* translators: %1$s: the ID of a WooPayments payment. */
				__( 'The payment details of an earlier WooPayments attempt (<code>%1$s</code>) were not removed when the customer checked out with another payment method, because a WooPayments payment for this order was being recorded at that moment. Before fulfilling the order, check its payment details or the WooPayments transaction for <code>%1$s</code>.', 'woocommerce' ),
				$intent_id
			)
		);
	}

	/**
	 * Write a log line with its reason, whatever the logging setting.
	 *
	 * @param WC_Order $order     The order.
	 * @param string   $reason    Reason code.
	 * @param string   $phrase    What happened.
	 * @param string   $intent_id The stored intent.
	 */
	private function log_reason( WC_Order $order, string $reason, string $phrase, string $intent_id ): void {
		$printed_intent_id = WooPaymentsLogger::get_loggable_provider_id( $intent_id );
		$this->logger->log_always(
			sprintf( '%1$s: order %2$d, intent %3$s', $phrase, $order->get_id(), $printed_intent_id ),
			'warning',
			array(
				'order_id'  => $order->get_id(),
				'intent_id' => $printed_intent_id,
				'reason'    => $reason,
			)
		);
	}

	/**
	 * Tell whether an order of another payment method carries the mode an earlier WooPayments attempt stored (client
	 * 11.3.0 class-wc-payments-order-service.php:1276-1282).
	 *
	 * @param WC_Order $order The order.
	 * @return bool
	 */
	private static function is_taken_over_attempt( WC_Order $order ): bool {
		return ! WooPaymentsPersistenceVocabulary::is_woopayments_gateway_id( (string) $order->get_payment_method() )
			&& '' !== (string) $order->get_meta( '_wcpay_mode', true );
	}

	/**
	 * Build a note whose message names a WooPayments payment in its `<code>%1$s</code>` placeholder.
	 *
	 * @param string $message   The translated message.
	 * @param string $intent_id The payment to name.
	 * @return string
	 */
	private static function note_naming_intent( string $message, string $intent_id ): string {
		return sprintf( WooPaymentsHtmlUtils::escape_interpolated_html( $message, array( 'code' => '<code>' ) ), esc_html( $intent_id ) );
	}
}
