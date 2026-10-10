<?php
/**
 * PaymentProcessingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Throwable;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

/**
 * Runs checkout, refund, capture and cancel through a payment provider under the order payment lock, and applies the outcome to the order.
 *
 * @since 11.0.0
 * @internal
 */
class PaymentProcessingService {

	/**
	 * Operation name a checkout passes to the provider's effect appliers, whether or not the provider was called; the order
	 * payment lock names its holder 'checkout'.
	 */
	public const OPERATION_CHARGE = 'charge';

	/** Operation name for a refund. */
	public const OPERATION_REFUND = 'refund';

	/** Operation name for a capture of an authorized payment. */
	public const OPERATION_CAPTURE = 'capture';

	/** Operation name for a cancel of an authorized payment. */
	public const OPERATION_CANCEL = 'cancel';

	/**
	 * Order payment lock.
	 *
	 * @var OrderPaymentLock
	 */
	private OrderPaymentLock $order_payment_lock;

	/**
	 * Order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Order payment notes.
	 *
	 * @var OrderPaymentNotes
	 */
	private OrderPaymentNotes $order_payment_notes;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentLock             $order_payment_lock  Order payment lock.
	 * @param OrderPaymentLifecycleService $lifecycle_service   Order payment lifecycle service.
	 * @param OrderPaymentNotes            $order_payment_notes Order payment notes.
	 */
	final public function init(
		OrderPaymentLock $order_payment_lock,
		OrderPaymentLifecycleService $lifecycle_service,
		OrderPaymentNotes $order_payment_notes
	): void {
		$this->order_payment_lock  = $order_payment_lock;
		$this->lifecycle_service   = $lifecycle_service;
		$this->order_payment_notes = $order_payment_notes;
	}

	/**
	 * Process checkout payment through a provider and return the outcome.
	 *
	 * Under the order payment lock the context's order is read again in place, so every holder of that object sees the
	 * order as the charge saw it (refresh_order_before_charge()).
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return PaymentOutcome
	 * @throws PaymentOutcomeApplyException When applying a referenced or successful charge outcome fails.
	 * @throws Throwable When applying an unreferenced unsuccessful charge outcome fails.
	 */
	public function process_checkout_outcome( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
		$order                  = $context->get_order();
		$idempotency_key        = $this->mint_idempotency_key();
		$persistence_vocabulary = $provider->get_persistence_vocabulary();

		// A refused checkout claim returns the in-progress outcome without logging; the gateway shows the shopper its generic
		// retry notice. A second submission of one checkout is the common case the lock stops; refused refunds, captures,
		// cancels and provider events are logged.
		$lock_token = $this->order_payment_lock->claim( $order, $persistence_vocabulary, $idempotency_key, 'checkout' );
		if ( null === $lock_token ) {
			return $this->get_operation_in_progress_outcome();
		}

		try {
			$changed_order_outcome = $this->refresh_order_before_charge( $order, $persistence_vocabulary );
			if ( null !== $changed_order_outcome ) {
				return $changed_order_outcome;
			}

			$provider_outcome = 0.0 >= (float) $order->get_total() && ! $this->should_call_provider_for_zero_total_checkout( $context, $provider )
				? new PaymentOutcome( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT )
				: $this->charge_provider( $context, $provider, $idempotency_key );
			$outcome          = $provider_outcome;

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, self::OPERATION_CHARGE );
				$this->apply_outcome_lifecycle_event( $order, $outcome, $provider );
				$this->apply_provider_post_lifecycle_effects( $context, $outcome, $provider, self::OPERATION_CHARGE );
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				// The provider already returned a durable result. Its effects may have replaced the outcome, but applying failed,
				// so the service attempts to save the provider's own outcome, best effort, and hands it back, and a retry finds its
				// reference instead of paying again.
				$reconciliation_persisted = $this->persist_reconciliation_context( $order, $provider_outcome, $provider );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, self::OPERATION_CHARGE, $apply_exception, $reconciliation_persisted );

				throw new PaymentOutcomeApplyException( $provider_outcome, $apply_exception, $reconciliation_persisted );
			}

			return $outcome;
		} finally {
			$this->order_payment_lock->release( $order, $persistence_vocabulary, $lock_token );
		}
	}

	/**
	 * Get the failed outcome for a checkout, capture or cancel refused because another operation holds the order payment lock,
	 * or because the order changed before this request claimed it.
	 *
	 * @return PaymentOutcome
	 */
	private function get_operation_in_progress_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_MESSAGE => __( 'A payment operation is already in progress for this order.', 'woocommerce' ),
			)
		);
	}

	/**
	 * Read the order again under the checkout lock, and stop the charge when another request touched its payment since this one loaded it.
	 *
	 * A gateway runs its own duplicate-payment checks before it calls this service, outside the lock, so two submissions of
	 * one order can both pass them.
	 * The order is read again in place, so the charge and everything after it use what the read returns, including the
	 * unresolved charge key of another attempt. Compared with what this request loaded: a new paid status answers as already
	 * paid; any other status change, or any change to its payment references or unresolved charge key (a charge whose outcome
	 * is unknown), whether added, replaced or cleared,
	 * means another request is at work, so the charge is refused. Client 11.1.0 takes no lock in process_payment()
	 * (class-wc-payment-gateway-wcpay.php:1251-1268) and would charge again.
	 *
	 * @param WC_Order                               $order                  Order as this request loaded it, read again in place.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return PaymentOutcome|null The outcome to return instead of charging, or null to charge.
	 */
	private function refresh_order_before_charge( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): ?PaymentOutcome {
		$paid_statuses     = wc_get_is_paid_statuses();
		$loaded_status     = $order->get_status();
		$loaded_was_paid   = $order->has_status( $paid_statuses );
		$loaded_references = $this->get_recorded_payment_references( $order, $persistence_vocabulary );
		$loaded_charge_key = $this->get_unresolved_charge_key( $order, $persistence_vocabulary );

		$this->lifecycle_service->reread_order_from_data_store( $order );

		if ( $order->has_status( $paid_statuses ) && ! $loaded_was_paid ) {
			$this->log_checkout_refused_after_claim( $order, 'order_paid' );

			return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, '', '', '', '', array( PaymentOutcome::DATA_ORDER_PAID_BY_ANOTHER_REQUEST => true ) );
		}

		$reason = null;
		if ( $order->get_status() !== $loaded_status ) {
			$reason = 'order_status_changed';
		} elseif ( $this->get_recorded_payment_references( $order, $persistence_vocabulary ) !== $loaded_references ) {
			$reason = 'payment_reference_changed';
		} elseif ( $this->get_unresolved_charge_key( $order, $persistence_vocabulary ) !== $loaded_charge_key ) {
			$reason = 'unresolved_charge_key_changed';
		}

		if ( null === $reason ) {
			return null;
		}

		$this->log_checkout_refused_after_claim( $order, $reason );

		return $this->get_operation_in_progress_outcome();
	}

	/**
	 * Get the payment references recorded on an order: its transaction ID and the provider's payment meta.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return array{0:string,1:string}
	 */
	private function get_recorded_payment_references( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): array {
		return array(
			(string) $order->get_transaction_id(),
			(string) $order->get_meta( $persistence_vocabulary->get_payment_reference_meta_key(), true ),
		);
	}

	/**
	 * Get the charge idempotency key the provider stores on the order while a charge outcome is unknown.
	 *
	 * @param WC_Order                               $order                  Order object.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return string The unresolved key, or '' when there is none or the provider stores none.
	 */
	private function get_unresolved_charge_key( WC_Order $order, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): string {
		$meta_key = $persistence_vocabulary->get_charge_idempotency_key_meta_key();

		return '' === $meta_key ? '' : (string) $order->get_meta( $meta_key, true );
	}

	/**
	 * Log a warning when a checkout is refused because the order changed before the lock was claimed.
	 *
	 * Logging is best-effort and never throws.
	 *
	 * @param WC_Order $order  Order being paid.
	 * @param string   $reason Why the charge was refused.
	 */
	private function log_checkout_refused_after_claim( WC_Order $order, string $reason ): void {
		try {
			wc_get_logger()->warning(
				sprintf( 'Checkout charged nothing: order %1$d changed before this request claimed its payment lock.', $order->get_id() ),
				array(
					'source'   => 'order-payments',
					'order_id' => $order->get_id(),
					'reason'   => $reason,
				)
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Save the provider's payment reference and meta on the order after applying its outcome failed, the reconciliation
	 * context, so a retry or the provider's next event finds the payment the provider already made instead of paying again.
	 *
	 * The order is reloaded to avoid clobbering concurrent writes. The provider's order meta is needed
	 * by later confirmation and reconciliation paths, so persisting only the transaction ID is not enough.
	 * Recovery is deliberately best-effort: no local persistence failure may replace a durable provider
	 * outcome after the provider call returned.
	 * A failed outcome's reference names a payment that never succeeded, so it is saved only through the provider's meta and
	 * never becomes the transaction ID.
	 *
	 * @param WC_Order          $order   Order object.
	 * @param PaymentOutcome    $outcome Provider outcome with a durable reference.
	 * @param ProviderInterface $provider Provider.
	 * @return bool Whether the reconciliation context was persisted.
	 */
	private function persist_reconciliation_context( WC_Order $order, PaymentOutcome $outcome, ProviderInterface $provider ): bool {
		try {
			$reloaded_order = wc_get_order( $order->get_id() );
			if ( ! $reloaded_order instanceof WC_Order ) {
				return false;
			}

			$payment_reference       = $outcome->get_provider_payment_id();
			$existing_transaction_id = (string) $reloaded_order->get_transaction_id();
			if ( '' !== $payment_reference && '' !== $existing_transaction_id && $payment_reference !== $existing_transaction_id ) {
				// A different transaction ID on the order points to a second payment this order does not link to.
				wc_get_logger()->error(
					'Payment reconciliation context was not saved: the order already has a different transaction ID.',
					array(
						'source'                  => 'order-payments',
						'order_id'                => $reloaded_order->get_id(),
						'payment_reference'       => $payment_reference,
						'existing_transaction_id' => $existing_transaction_id,
					)
				);
				return false;
			}

			foreach ( $this->get_provider_outcome_meta( $outcome, $provider ) as $key => $value ) {
				$reloaded_order->update_meta_data( $key, $value );
			}

			if ( '' !== $payment_reference && '' === $existing_transaction_id && PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ) {
				$reloaded_order->set_transaction_id( $payment_reference );
			}

			$reloaded_order->save();
			return true;
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Tell whether an outcome must be retained after local application fails.
	 *
	 * Successful outcomes are retained even without a reference, including a zero-total completion the service built without
	 * calling the provider; any other status is retained when it carries a provider payment ID: declines, failed captures and
	 * cancels, and customer-action flows.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return bool
	 */
	private function is_reconcilable_provider_outcome( PaymentOutcome $outcome ): bool {
		return $outcome->is_successful() || '' !== $outcome->get_provider_payment_id();
	}

	/**
	 * Log a post-provider application failure so a money-moving operation is never silently dropped.
	 *
	 * The failure's message is left out: a provider's effects can throw its platform's text, which can hold an email or a URL.
	 *
	 * @param WC_Order       $order     Order object.
	 * @param PaymentOutcome $outcome   Reconcilable provider outcome.
	 * @param string         $operation Provider operation.
	 * @param Throwable      $exception Lifecycle application exception.
	 * @param bool           $reconciliation_persisted Whether reconciliation context was persisted.
	 * @param string         $reconciliation_skipped   Why no save was attempted ('failed_outcome'), or '' when one was.
	 */
	private function log_post_provider_apply_failure( WC_Order $order, PaymentOutcome $outcome, string $operation, Throwable $exception, bool $reconciliation_persisted, string $reconciliation_skipped = '' ): void {
		$context = array(
			'source'                   => 'order-payments',
			'order_id'                 => $order->get_id(),
			'payment_reference'        => $outcome->get_provider_payment_id(),
			'operation'                => $operation,
			'exception_class'          => get_class( $exception ),
			'exception_code'           => $exception->getCode(),
			'reconciliation_persisted' => $reconciliation_persisted,
		);
		if ( '' !== $reconciliation_skipped ) {
			$context['reconciliation_skipped'] = $reconciliation_skipped;
		}

		try {
			wc_get_logger()->error(
				'Payment provider operation returned a reconcilable outcome but applying local effects failed; best-effort reconciliation persistence was attempted.',
				$context
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Process a refund through a provider.
	 *
	 * When applying a refund the provider already made fails, the refund still reports success, because WooCommerce
	 * deletes its refund row when the gateway reports a failure. Under the lock the refund runs on a fresh copy of the order,
	 * so a request that waited on the lock sees what the earlier one saved, while the caller's object, unsaved changes
	 * included, is left as it was. The row this call links is the one WooCommerce is refunding through the gateway in this
	 * request (RefundRowCapture), handed to the provider in the payment data (PaymentOperationContext::PAYMENT_DATA_REFUND_ID);
	 * with none, as for a direct wc_refund_payment() caller, it is the order's newest row and the provider gets no ID.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return bool|WP_Error
	 * @throws Throwable When applying an unreferenced unsuccessful refund outcome fails.
	 */
	public function process_refund( PaymentOperationContext $context, ProviderInterface $provider ) {
		$order                  = $context->get_order();
		$amount                 = $context->get_amount() ?? 0.0;
		$persistence_vocabulary = $provider->get_persistence_vocabulary();
		$captured_refund_id     = $this->get_refund_row_capture()->consume( $order, $amount );

		if ( '0.00' === sprintf( '%0.2f', $amount ) ) {
			return true;
		}

		// Like client 11.1.0, each refund call sends its own key, so a retry after a failed refund
		// reaches the provider instead of replaying the stored failure. The key is also the lock value.
		$idempotency_key = $this->mint_idempotency_key();
		$lock_token      = $this->order_payment_lock->claim( $order, $persistence_vocabulary, $idempotency_key, self::OPERATION_REFUND );
		if ( null === $lock_token ) {
			$this->order_payment_lock->log_refusal( $order, $persistence_vocabulary, self::OPERATION_REFUND );
			return new WP_Error( 'order_payment_refund_locked', __( 'A payment operation is already in progress for this order.', 'woocommerce' ) );
		}

		try {
			// A request that waited on the lock may hold an order loaded before the earlier refund saved its state, so the
			// refund runs on a fresh copy; the caller's object keeps whatever it has not saved.
			$order   = $this->lifecycle_service->get_fresh_order_from_data_store( $order );
			$context = $context->with_order( $order );

			// The row WooCommerce is refunding in this request, when it still belongs to the order. Otherwise the newest row,
			// read under the lock and before the provider call, so a row created after this read (a manual refund, or one
			// the lock refuses) is never linked; a row another request saves between this call's own row and this read is.
			$own_row_known = null !== $captured_refund_id && in_array( $captured_refund_id, $this->get_refund_ids( $order ), true );
			$wc_refund_id  = $own_row_known ? $captured_refund_id : $this->get_newest_refund_id( $order );

			// Refuse a refund with no local row before any money moves, so a retry cannot refund twice.
			// Client 11.1.0 sends it first and then fails (class-wc-payment-gateway-wcpay.php:3003-3007).
			// Core's callers (wc_create_refund(), which the admin and REST refunds use) save the row before calling the
			// gateway, so only a direct wc_refund_payment() or process_refund() caller on an order with no refund row reaches this.
			if ( null === $wc_refund_id ) {
				return new WP_Error(
					'order_payment_refund_not_found',
					/* translators: %1$s: order ID. */
					sprintf( __( 'A refund cannot be found for order: %1$s', 'woocommerce' ), $order->get_id() )
				);
			}

			if ( $own_row_known ) {
				$context = $context->with_payment_data( array( PaymentOperationContext::PAYMENT_DATA_REFUND_ID => $wc_refund_id ) );
			}
			try {
				$provider_outcome = $provider->refund( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$provider_outcome = $this->failed_outcome_from_throwable( $exception );
				$this->log_provider_failure( $order, self::OPERATION_REFUND, $idempotency_key, $exception );
			}
			$outcome = $provider_outcome;

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, self::OPERATION_REFUND );
				if ( $outcome->is_successful() ) {
					$this->apply_refund_outcome( $order, $outcome, $wc_refund_id, $persistence_vocabulary );
				}
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				$outcome                  = $provider_outcome;
				$reconciliation_persisted = $this->persist_refund_reconciliation_context( $order, $wc_refund_id, $provider_outcome, $persistence_vocabulary );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, self::OPERATION_REFUND, $apply_exception, $reconciliation_persisted );
			}
		} finally {
			$this->order_payment_lock->release( $order, $persistence_vocabulary, $lock_token );
		}

		if ( $outcome->is_successful() ) {
			return true;
		}

		$data          = $outcome->get_data();
		$error_code    = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) && '' !== (string) $data[ PaymentOutcome::DATA_ERROR_CODE ]
			? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ]
			: 'order_payment_refund_failed';
		$error_message = isset( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] )
			? (string) $data[ PaymentOutcome::DATA_ERROR_MESSAGE ]
			: __( 'The refund failed.', 'woocommerce' );

		return new WP_Error( $error_code, $error_message );
	}

	/**
	 * Retain the provider refund identity on the local refund after local effects fail.
	 *
	 * @param WC_Order                               $order                  Parent order.
	 * @param int|null                               $wc_refund_id           Local refund this call links.
	 * @param PaymentOutcome                         $outcome                Provider refund outcome.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @return bool Whether the refund identity was persisted.
	 */
	private function persist_refund_reconciliation_context( WC_Order $order, ?int $wc_refund_id, PaymentOutcome $outcome, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): bool {
		$refund_reference = $outcome->get_provider_payment_id();
		if ( '' === $refund_reference || null === $wc_refund_id ) {
			return false;
		}

		try {
			$refund = wc_get_order( $wc_refund_id );
			if ( ! $refund instanceof WC_Order_Refund || $order->get_id() !== $refund->get_parent_id() ) {
				return false;
			}

			$meta_key = $persistence_vocabulary->get_processed_refund_link_meta_key();
			if ( '' === $meta_key ) {
				return false;
			}

			$existing_reference = (string) $refund->get_meta( $meta_key, true );
			if ( '' !== $existing_reference && $refund_reference !== $existing_reference ) {
				return false;
			}

			$refund->update_meta_data( $meta_key, $refund_reference );
			$refund->save_meta_data();
			return true;
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Get the IDs of the order's refunds, read from the data store.
	 *
	 * @param WC_Order $order Parent order.
	 * @return int[]
	 */
	private function get_refund_ids( WC_Order $order ): array {
		try {
			$ids = wc_get_orders(
				array(
					'type'   => 'shop_order_refund',
					'parent' => $order->get_id(),
					'limit'  => -1,
					'return' => 'ids',
				)
			);
		} catch ( Throwable $exception ) {
			return array();
		}

		$refund_ids = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			// 'return' => 'ids' yields IDs, whatever the declared return type says.
			$refund_ids[] = is_object( $id ) ? (int) $id->get_id() : (int) $id;
		}

		return $refund_ids;
	}

	/**
	 * Get the request's refund row capture.
	 *
	 * @return RefundRowCapture
	 */
	private function get_refund_row_capture(): RefundRowCapture {
		return wc_get_container()->get( RefundRowCapture::class );
	}

	/**
	 * Get the ID of the order's newest refund: the one WooCommerce created for this refund call.
	 *
	 * WooCommerce saves the refund row before it calls the gateway, so when refunds of one order do not overlap and every
	 * call creates a row, the newest row is the one
	 * this call refunds. Client 11.1.0 links the provider refund the same way
	 * (`WC_Payments_Utils::get_last_refund_from_order_id()`).
	 *
	 * @param WC_Order $order Parent order.
	 * @return int|null
	 */
	private function get_newest_refund_id( WC_Order $order ): ?int {
		try {
			$refunds = wc_get_orders(
				array(
					'type'    => 'shop_order_refund',
					'parent'  => $order->get_id(),
					'limit'   => 1,
					'orderby' => 'ID',
					'order'   => 'DESC',
				)
			);
		} catch ( Throwable $exception ) {
			return null;
		}

		return is_array( $refunds ) && isset( $refunds[0] ) ? (int) $refunds[0]->get_id() : null;
	}

	/**
	 * Apply provider refund metadata to the WooCommerce refund this call links.
	 *
	 * @param WC_Order                               $order                  Parent order.
	 * @param PaymentOutcome                         $outcome                Provider refund outcome.
	 * @param int|null                               $wc_refund_id           Local refund this call links.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 * @throws \RuntimeException When the refund or parent order cannot be reloaded.
	 */
	private function apply_refund_outcome( WC_Order $order, PaymentOutcome $outcome, ?int $wc_refund_id, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		$data                          = $outcome->get_data();
		$refund_meta                   = isset( $data[ PaymentOutcome::DATA_REFUND_META ] ) && is_array( $data[ PaymentOutcome::DATA_REFUND_META ] )
			? $data[ PaymentOutcome::DATA_REFUND_META ]
			: array();
		$order_meta                    = isset( $data[ PaymentOutcome::DATA_ORDER_META ] ) && is_array( $data[ PaymentOutcome::DATA_ORDER_META ] )
			? $data[ PaymentOutcome::DATA_ORDER_META ]
			: array();
		$refund_note                   = isset( $data[ PaymentOutcome::DATA_REFUND_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_REFUND_NOTE ] )
			? $data[ PaymentOutcome::DATA_REFUND_NOTE ]
			: '';
		$refund_note_equivalents_valid = isset( $data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ] ) && is_array( $data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ] );
		$refund_note_identity          = $refund_note_equivalents_valid && isset( $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY ] ) && is_string( $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY ] )
			? $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY ]
			: '';
		$refund_note_equivalents       = $refund_note_equivalents_valid
			? array_values( array_filter( $data[ PaymentOutcome::DATA_REFUND_NOTE_EQUIVALENTS ], 'is_string' ) )
			: array();

		if ( empty( $refund_meta ) && empty( $order_meta ) && '' === $refund_note ) {
			return;
		}

		$matched_refund = null !== $wc_refund_id ? wc_get_order( $wc_refund_id ) : false;
		$reloaded_order = wc_get_order( $order->get_id() );
		if ( ! $matched_refund instanceof WC_Order_Refund || $order->get_id() !== $matched_refund->get_parent_id() ) {
			throw new \RuntimeException( 'The local refund could not be loaded after the provider refund succeeded.' );
		}
		if ( ! $reloaded_order instanceof WC_Order ) {
			throw new \RuntimeException( 'The parent order could not be loaded after the provider refund succeeded.' );
		}

		foreach ( $refund_meta as $meta_key => $meta_value ) {
			$matched_refund->update_meta_data( (string) $meta_key, $meta_value );
		}
		$matched_refund->save_meta_data();

		foreach ( $order_meta as $meta_key => $meta_value ) {
			$reloaded_order->update_meta_data( (string) $meta_key, $meta_value );
		}

		if ( '' !== $refund_note ) {
			$this->maybe_add_refund_note( $reloaded_order, $refund_note, $refund_note_identity, $refund_note_equivalents, $persistence_vocabulary );
		}
		$reloaded_order->save_meta_data();
	}

	/**
	 * Add the provider's refund note unless the order already has it.
	 *
	 * A note without an identity, or from a provider that names no identity key, is found by its text alone.
	 *
	 * @param WC_Order                               $order                  Parent order.
	 * @param string                                 $note                   Provider refund note.
	 * @param string                                 $identity               Stable refund note identity.
	 * @param string[]                               $equivalent_notes       Other texts of the same refund note.
	 * @param ProviderPersistenceVocabularyInterface $persistence_vocabulary Provider persistence vocabulary.
	 */
	private function maybe_add_refund_note( WC_Order $order, string $note, string $identity, array $equivalent_notes, ProviderPersistenceVocabularyInterface $persistence_vocabulary ): void {
		if ( '' === $identity || '' === $persistence_vocabulary->get_note_identity_meta_key() ) {
			if ( 0 === $this->order_payment_notes->find_by_content( $order, $note ) ) {
				$this->order_payment_notes->add( $order, $note, '', $persistence_vocabulary );
			}
			return;
		}

		if ( 0 < $this->order_payment_notes->find_by_identity( $order, $identity, $persistence_vocabulary ) ) {
			return;
		}

		$content_match_note_id = $this->order_payment_notes->find_by_content( $order, $note, $equivalent_notes );
		if ( 0 < $content_match_note_id ) {
			// A note found by its text gets the identity, so the next write finds it whatever its text reads by then.
			$this->order_payment_notes->record_identity( $content_match_note_id, $identity, $persistence_vocabulary );
			return;
		}

		$this->order_payment_notes->add( $order, $note, $identity, $persistence_vocabulary );
	}

	/**
	 * Capture an authorized payment through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
		return $this->run_provider_order_operation( $context, $provider, self::OPERATION_CAPTURE );
	}

	/**
	 * Cancel an authorized payment through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentOperationContext $context, ProviderInterface $provider ): PaymentOutcome {
		return $this->run_provider_order_operation( $context, $provider, self::OPERATION_CANCEL );
	}

	/**
	 * Charge a provider and normalize exceptions.
	 *
	 * @param PaymentOperationContext $context         Payment context.
	 * @param ProviderInterface       $provider        Provider.
	 * @param string                  $idempotency_key Key minted fresh for this payment attempt.
	 * @return PaymentOutcome
	 */
	private function charge_provider( PaymentOperationContext $context, ProviderInterface $provider, string $idempotency_key ): PaymentOutcome {
		try {
			return $provider->charge( $context, $idempotency_key );
		} catch ( Throwable $exception ) {
			$outcome = $this->failed_outcome_from_throwable( $exception );
			$this->log_provider_failure( $context->get_order(), self::OPERATION_CHARGE, $idempotency_key, $exception );

			return $outcome;
		}
	}

	/**
	 * Mint a fresh key for one checkout attempt, refund, capture or cancel: the order payment lock's value and the provider's request key.
	 *
	 * A fresh key per call keeps a retry from replaying an earlier call's cached failure; the application-level guards,
	 * not the key, prevent a duplicate charge. A provider keeping an ambiguous charge attempt's key sends that key instead.
	 * Client 11.1.0 mints a UUID v4 Idempotency-Key for each request other than GET and DELETE whose caller supplies none (class-wc-payments-api-client.php:2686-2690, 3114).
	 *
	 * @return string
	 */
	private function mint_idempotency_key(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Tell whether a zero-total checkout still needs a provider-owned setup operation.
	 *
	 * @param PaymentOperationContext $context  Payment context.
	 * @param ProviderInterface       $provider Provider.
	 * @return bool
	 */
	private function should_call_provider_for_zero_total_checkout( PaymentOperationContext $context, ProviderInterface $provider ): bool {
		if ( ! $provider->supports_zero_amount_setup( $context ) ) {
			return false;
		}

		return '' !== $context->get_payment_method_id() || $this->has_saved_payment_token( $context );
	}

	/**
	 * Tell whether checkout context carries an existing saved payment token.
	 *
	 * @param PaymentOperationContext $context Payment context.
	 * @return bool
	 */
	private function has_saved_payment_token( PaymentOperationContext $context ): bool {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return '' !== $payment_token && 'new' !== $payment_token;
	}

	/**
	 * Run a capture/cancel provider operation under the shared order lock.
	 *
	 * When applying a capture or cancel outcome that carries a provider reference fails, the provider's outcome is returned as
	 * it is: a completed capture moved the money and a completed cancel released the authorization, and the service attempts,
	 * best effort, to save the reference on the order so the provider's next event settles it; checkout throws
	 * PaymentOutcomeApplyException instead, because what the shopper sees depends on how applying failed. When applying a
	 * failed capture or cancel fails, the failed outcome is returned. Recovery saves nothing further; changes already
	 * persisted during local application remain, as client 11.1.0 leaves them when its failure handling throws.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param ProviderInterface       $provider  Provider.
	 * @param string                  $operation Operation name.
	 * @return PaymentOutcome
	 * @throws Throwable When applying an unreferenced unsuccessful provider outcome fails.
	 */
	private function run_provider_order_operation( PaymentOperationContext $context, ProviderInterface $provider, string $operation ): PaymentOutcome {
		$order                  = $context->get_order();
		$idempotency_key        = $this->mint_idempotency_key();
		$persistence_vocabulary = $provider->get_persistence_vocabulary();

		$lock_token = $this->order_payment_lock->claim( $order, $persistence_vocabulary, $idempotency_key, $operation );
		if ( null === $lock_token ) {
			$this->order_payment_lock->log_refusal( $order, $persistence_vocabulary, $operation );
			return $this->get_operation_in_progress_outcome();
		}

		try {
			try {
				$provider_outcome = self::OPERATION_CAPTURE === $operation
					? $provider->capture( $context, $idempotency_key )
					: $provider->cancel( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$provider_outcome = $this->failed_outcome_from_throwable( $exception );
				$this->log_provider_failure( $order, $operation, $idempotency_key, $exception );
			}
			$outcome = $provider_outcome;

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, $operation );
				$this->apply_order_operation_outcome( $order, $outcome, $operation, $provider );
				$this->apply_provider_post_lifecycle_effects( $context, $outcome, $provider, $operation );
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				$outcome                  = $provider_outcome;
				$is_failed_outcome        = PaymentOutcome::STATUS_FAILED === $provider_outcome->get_status();
				$reconciliation_persisted = ! $is_failed_outcome && $this->persist_reconciliation_context( $order, $provider_outcome, $provider );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, $operation, $apply_exception, $reconciliation_persisted, $is_failed_outcome ? 'failed_outcome' : '' );
			}

			return $outcome;
		} finally {
			$this->order_payment_lock->release( $order, $persistence_vocabulary, $lock_token );
		}
	}

	/**
	 * Build the failed outcome for a provider operation that threw.
	 *
	 * The error code comes from the exception's get_error_code() when it has one.
	 *
	 * @param Throwable $exception Throwable from the provider.
	 * @return PaymentOutcome
	 */
	private function failed_outcome_from_throwable( Throwable $exception ): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => is_callable( array( $exception, 'get_error_code' ) ) ? (string) $exception->get_error_code() : '',
				PaymentOutcome::DATA_ERROR_MESSAGE => $exception->getMessage(),
			)
		);
	}

	/**
	 * Log a provider operation throwable with idempotency correlation.
	 *
	 * Logging is best-effort and must never replace the normalized failed outcome. The exception's message and the
	 * provider's error code are left out: both are the provider platform's text, which can hold an email, a URL or a key.
	 *
	 * @param WC_Order  $order           Order being processed.
	 * @param string    $operation       Provider operation.
	 * @param string    $idempotency_key Key minted for this operation.
	 * @param Throwable $exception       Provider throwable.
	 */
	private function log_provider_failure( WC_Order $order, string $operation, string $idempotency_key, Throwable $exception ): void {
		try {
			wc_get_logger()->error(
				'Payment provider operation threw an exception.',
				array(
					'source'          => 'order-payments',
					'operation'       => $operation,
					'order_id'        => $order->get_id(),
					'idempotency_key' => $idempotency_key,
					'exception_class' => get_class( $exception ),
					'exception_code'  => $exception->getCode(),
				)
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Apply optional provider-owned effects after the provider call and before the generic lifecycle.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Provider outcome.
	 * @param ProviderInterface       $provider  Provider.
	 * @param string                  $operation Operation name.
	 * @return PaymentOutcome
	 */
	private function apply_provider_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, ProviderInterface $provider, string $operation ): PaymentOutcome {
		if ( ! $provider instanceof ProviderOperationEffectApplierInterface ) {
			return $outcome;
		}

		return $provider->apply_operation_effects( $context, $outcome, $operation );
	}

	/**
	 * Apply optional provider-owned effects after the provider call and the generic lifecycle.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Applied provider outcome.
	 * @param ProviderInterface       $provider  Provider.
	 * @param string                  $operation Operation name.
	 */
	private function apply_provider_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, ProviderInterface $provider, string $operation ): void {
		if ( ! $provider instanceof ProviderPostLifecycleEffectApplierInterface ) {
			return;
		}

		$provider->apply_post_lifecycle_effects( $context, $outcome, $operation );
	}

	/**
	 * Apply a provider order-operation outcome to the order lifecycle.
	 *
	 * @param WC_Order          $order     Order object.
	 * @param PaymentOutcome    $outcome   Provider outcome.
	 * @param string            $operation Operation name.
	 * @param ProviderInterface $provider  Provider.
	 */
	private function apply_order_operation_outcome( WC_Order $order, PaymentOutcome $outcome, string $operation, ProviderInterface $provider ): void {
		if ( in_array( $operation, array( self::OPERATION_CAPTURE, self::OPERATION_CANCEL ), true ) && PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			// An expired authorization is the one failed capture that moves the order: when the provider marks the
			// outcome with the capture-expired note type, the order goes to failed.
			if ( PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED === $this->get_lifecycle_note_type( $outcome ) ) {
				$this->lifecycle_service->apply_under_lock(
					$order,
					new PaymentLifecycleEvent(
						PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
						$this->get_lifecycle_payment_reference( $outcome ),
						$this->get_provider_outcome_meta( $outcome, $provider ),
						array(),
						$this->get_lifecycle_note( $outcome ),
						$this->get_lifecycle_note_type( $outcome ),
						$this->get_lifecycle_note_equivalents( $outcome )
					),
					$provider->get_persistence_vocabulary()
				);
				return;
			}

			// A failed capture or cancel leaves the authorization in place, so the event is STATUS_STARTED: the
			// authorization stays and the order status does not change.
			$meta = $this->get_failed_capture_or_cancel_outcome_meta( $outcome, $provider );

			$this->lifecycle_service->apply_under_lock(
				$order,
				new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_STARTED,
					$this->get_lifecycle_payment_reference( $outcome ),
					$meta,
					array(),
					$this->get_lifecycle_note( $outcome ),
					$this->get_lifecycle_note_type( $outcome ),
					$this->get_lifecycle_note_equivalents( $outcome )
				),
				$provider->get_persistence_vocabulary()
			);
			return;
		}

		$this->apply_outcome_lifecycle_event( $order, $outcome, $provider );
	}

	/**
	 * Apply the lifecycle event a provider outcome maps to: every checkout outcome, and captures and cancels that did not fail.
	 *
	 * @param WC_Order          $order    Order object.
	 * @param PaymentOutcome    $outcome  Provider outcome.
	 * @param ProviderInterface $provider Provider.
	 */
	private function apply_outcome_lifecycle_event( WC_Order $order, PaymentOutcome $outcome, ProviderInterface $provider ): void {
		$this->lifecycle_service->apply_under_lock(
			$order,
			new PaymentLifecycleEvent(
				PaymentLifecycleEvent::status_for_outcome( $outcome ),
				$this->get_lifecycle_payment_reference( $outcome ),
				$this->get_provider_outcome_meta( $outcome, $provider ),
				$this->get_lifecycle_meta_to_delete( $outcome ),
				$this->get_lifecycle_note( $outcome ),
				$this->get_lifecycle_note_type( $outcome ),
				$this->get_lifecycle_note_equivalents( $outcome ),
				// The event stays a failure so the late-failure guard still
				// protects already-paid orders; only the status transition is
				// suppressed when the outcome asked to preserve the status.
				$this->should_preserve_order_status( $outcome )
			),
			$provider->get_persistence_vocabulary()
		);
	}

	/**
	 * Tell whether a failed outcome asked to preserve the current order status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return bool
	 */
	private function should_preserve_order_status( PaymentOutcome $outcome ): bool {
		return true === ( $outcome->get_data()[ PaymentOutcome::DATA_PRESERVE_ORDER_STATUS ] ?? false );
	}

	/**
	 * Map provider outcome metadata through the provider's ProviderOutcomeMetadataMapperInterface, when it implements it.
	 *
	 * @param PaymentOutcome    $outcome  Provider outcome.
	 * @param ProviderInterface $provider Provider.
	 * @return array<string,string>
	 */
	private function get_provider_outcome_meta( PaymentOutcome $outcome, ProviderInterface $provider ): array {
		return $provider instanceof ProviderOutcomeMetadataMapperInterface ? $provider->get_outcome_meta( $outcome ) : array();
	}

	/**
	 * Map a failed capture or cancel outcome to metadata through the provider's ProviderOutcomeMetadataMapperInterface,
	 * when it implements it.
	 *
	 * @param PaymentOutcome    $outcome  Provider outcome.
	 * @param ProviderInterface $provider Provider.
	 * @return array<string,string>
	 */
	private function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome, ProviderInterface $provider ): array {
		return $provider instanceof ProviderOutcomeMetadataMapperInterface ? $provider->get_failed_capture_or_cancel_outcome_meta( $outcome ) : array();
	}

	/**
	 * Get order meta keys to delete from an outcome, as the outcome carries them; the lifecycle event casts them to strings.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<mixed>
	 */
	private function get_lifecycle_meta_to_delete( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		if ( ! isset( $data[ PaymentOutcome::DATA_META_TO_DELETE ] ) || ! is_array( $data[ PaymentOutcome::DATA_META_TO_DELETE ] ) ) {
			return array();
		}

		return $data[ PaymentOutcome::DATA_META_TO_DELETE ];
	}

	/**
	 * Get the lifecycle payment reference from an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string|null
	 */
	private function get_lifecycle_payment_reference( PaymentOutcome $outcome ): ?string {
		return '' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id();
	}

	/**
	 * Get the order note from an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string|null
	 */
	private function get_lifecycle_note( PaymentOutcome $outcome ): ?string {
		$data = $outcome->get_data();

		if (
			isset( $data[ PaymentOutcome::DATA_NOTE ] )
			&& is_string( $data[ PaymentOutcome::DATA_NOTE ] )
			&& '' !== $data[ PaymentOutcome::DATA_NOTE ]
		) {
			return $data[ PaymentOutcome::DATA_NOTE ];
		}

		return null;
	}

	/**
	 * Get the stable order note type from an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string|null
	 */
	private function get_lifecycle_note_type( PaymentOutcome $outcome ): ?string {
		$data = $outcome->get_data();

		if (
			isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] )
			&& is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] )
			&& '' !== $data[ PaymentOutcome::DATA_NOTE_TYPE ]
		) {
			return $data[ PaymentOutcome::DATA_NOTE_TYPE ];
		}

		return null;
	}

	/**
	 * Get the other texts of the order note from an outcome, as the outcome carries them; the lifecycle event keeps each
	 * non-empty string once.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<mixed>
	 */
	private function get_lifecycle_note_equivalents( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		if ( ! isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) || ! is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) ) {
			return array();
		}

		return $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ];
	}
}
