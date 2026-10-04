<?php
/**
 * PaymentProcessingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Throwable;
use WC_Logger_Interface;
use WC_Order;
use WC_Order_Refund;
use WP_Error;

/**
 * Generic native payment processing template.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class PaymentProcessingService {

	/**
	 * Order payment store.
	 *
	 * @var OrderPaymentStore
	 */
	private OrderPaymentStore $order_payment_store;

	/**
	 * Order payment lifecycle service.
	 *
	 * @var OrderPaymentLifecycleService
	 */
	private OrderPaymentLifecycleService $lifecycle_service;

	/**
	 * Payment operation idempotency service.
	 *
	 * @var PaymentOperationIdempotency
	 */
	private PaymentOperationIdempotency $idempotency;

	/**
	 * Payment exception policy.
	 *
	 * @var PaymentExceptionPolicy
	 */
	private PaymentExceptionPolicy $exception_policy;

	/**
	 * Payments logger.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private ?WC_Logger_Interface $logger = null;

	/**
	 * Constructor.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Logger_Interface|null $logger Payments logger.
	 */
	public function __construct( ?WC_Logger_Interface $logger = null ) {
		$this->logger = $logger;
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param OrderPaymentStore            $order_payment_store Order payment store.
	 * @param OrderPaymentLifecycleService $lifecycle_service  Order payment lifecycle service.
	 * @param PaymentOperationIdempotency  $idempotency          Payment operation idempotency service.
	 * @param PaymentExceptionPolicy       $exception_policy     Payment exception policy.
	 */
	final public function init(
		OrderPaymentStore $order_payment_store,
		OrderPaymentLifecycleService $lifecycle_service,
		PaymentOperationIdempotency $idempotency,
		PaymentExceptionPolicy $exception_policy
	): void {
		$this->order_payment_store = $order_payment_store;
		$this->lifecycle_service   = $lifecycle_service;
		$this->idempotency         = $idempotency;
		$this->exception_policy    = $exception_policy;
	}

	/**
	 * Process checkout payment through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 * @throws PaymentOutcomeApplyException When applying a referenced or successful charge outcome fails.
	 */
	public function process_checkout( PaymentContext $context, ProviderContract $provider ): array {
		$outcome = $this->process_checkout_outcome( $context, $provider );

		return $this->format_checkout_result( $context, $context->get_order(), $outcome );
	}

	/**
	 * Process checkout payment through a provider and return the neutral outcome.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return PaymentOutcome
	 * @throws PaymentOutcomeApplyException When applying a referenced or successful charge outcome fails.
	 * @throws Throwable When applying an unreferenced unsuccessful charge outcome fails.
	 */
	public function process_checkout_outcome( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
		$order           = $context->get_order();
		$amount          = (float) $order->get_total();
		$currency        = (string) $order->get_currency();
		$idempotency_key = $this->idempotency->mint_attempt_key();
		$profile         = $provider->get_persistence_profile();

		// WooPayments locks checkout too, so this refusal is not logged as a native-only one.
		if ( ! $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $profile, $idempotency_key, 'checkout' ) ) {
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

		try {
			$provider_outcome = 0.0 >= $amount && ! $this->should_call_provider_for_zero_total_checkout( $context, $provider )
				? new PaymentOutcome( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT )
				: $this->charge_provider( $context, $provider, $idempotency_key );
			$outcome          = $provider_outcome;

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, 'charge' );
				$this->apply_checkout_outcome( $order, $outcome, $provider );
				$this->apply_provider_post_lifecycle_effects( $context, $outcome, $provider, 'charge' );
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				// The provider already returned a durable result. Persist its reference first, so a retry
				// finds it instead of paying again, then hand the failure back for the caller to settle.
				$reconciliation_persisted = $this->persist_reconciliation_context( $order, $provider_outcome, $provider );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, 'charge', $apply_exception, $reconciliation_persisted );

				throw new PaymentOutcomeApplyException( $provider_outcome, $apply_exception, $reconciliation_persisted );
			}

			return $outcome;
		} finally {
			$this->order_payment_store->release_order_payment_lock( $order, $profile, $idempotency_key );
		}
	}

	/**
	 * Persist provider reconciliation context after local outcome application fails.
	 *
	 * The order is reloaded to avoid clobbering concurrent writes. Provider-profile metadata is needed
	 * by later confirmation and reconciliation paths, so persisting only the transaction ID is not enough.
	 * Recovery is deliberately best-effort: no local persistence failure may replace a durable provider
	 * outcome after transport has completed.
	 *
	 * @param WC_Order         $order   Order object.
	 * @param PaymentOutcome   $outcome Provider outcome with a durable reference.
	 * @param ProviderContract $provider Provider.
	 * @return bool Whether the reconciliation context was persisted.
	 */
	private function persist_reconciliation_context( WC_Order $order, PaymentOutcome $outcome, ProviderContract $provider ): bool {
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
					'Native payment reconciliation context was not saved: the order already has a different transaction ID.',
					array(
						'source'                  => 'native-payments',
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

			if ( '' !== $payment_reference && '' === $existing_transaction_id ) {
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
	 * Successful outcomes may represent money movement even without a reference. Other statuses are
	 * reconcilable when the provider returned a durable payment ID, including customer-action flows.
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
	 * @param WC_Order       $order     Order object.
	 * @param PaymentOutcome $outcome   Reconcilable provider outcome.
	 * @param string         $operation Provider operation.
	 * @param Throwable      $exception Lifecycle application exception.
	 * @param bool           $reconciliation_persisted Whether reconciliation context was persisted.
	 */
	private function log_post_provider_apply_failure( WC_Order $order, PaymentOutcome $outcome, string $operation, Throwable $exception, bool $reconciliation_persisted ): void {
		try {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}

			wc_get_logger()->error(
				'Native payment provider operation returned a reconcilable outcome but applying local effects failed; best-effort reconciliation persistence was attempted.',
				array(
					'source'                   => 'native-payments',
					'order_id'                 => $order->get_id(),
					'payment_reference'        => $outcome->get_provider_payment_id(),
					'operation'                => $operation,
					'error'                    => $exception->getMessage(),
					'reconciliation_persisted' => $reconciliation_persisted,
				)
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Process a refund through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return bool|WP_Error
	 * @throws Throwable When applying an unreferenced unsuccessful refund outcome fails.
	 */
	public function process_refund( PaymentContext $context, ProviderContract $provider ) {
		$order        = $context->get_order();
		$payment_data = $context->get_payment_data();
		$amount       = isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0;
		$profile      = $provider->get_persistence_profile();

		if ( '0.00' === sprintf( '%0.2f', $amount ) ) {
			return true;
		}

		// Like client 11.1.0, each refund call sends its own key, so a retry after a failed refund
		// reaches the provider instead of replaying the stored failure. The key also serves as this
		// call's order payment lock token.
		$idempotency_key = $this->idempotency->mint_attempt_key();
		if ( ! $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $profile, $idempotency_key, 'refund' ) ) {
			$this->order_payment_store->log_order_payment_lock_refusal( $order, $profile, 'refund' );
			return new WP_Error( 'native_payment_refund_locked', __( 'A payment operation is already in progress for this order.', 'woocommerce' ) );
		}

		try {
			try {
				$provider_outcome = $provider->refund( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$provider_outcome = $this->exception_policy->to_failed_outcome( $exception );
				$this->log_provider_failure( $order, 'refund', $idempotency_key, $exception, $provider_outcome );
			}
			$outcome = $provider_outcome;

			$wc_refund_id = $this->get_newest_refund_id( $order );

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, 'refund' );
				if ( $outcome->is_successful() ) {
					$this->apply_refund_outcome( $order, $outcome, $wc_refund_id );
				}
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				$outcome                  = $provider_outcome;
				$reconciliation_persisted = $this->persist_refund_reconciliation_context( $order, $wc_refund_id, $provider_outcome, $profile );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, 'refund', $apply_exception, $reconciliation_persisted );
			}
		} finally {
			$this->order_payment_store->release_order_payment_lock( $order, $profile, $idempotency_key );
		}

		if ( $outcome->is_successful() ) {
			return true;
		}

		$data          = $outcome->get_data();
		$error_code    = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) && '' !== (string) $data[ PaymentOutcome::DATA_ERROR_CODE ]
			? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ]
			: 'native_payment_refund_failed';
		$error_message = isset( $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] )
			? (string) $data[ PaymentOutcome::DATA_ERROR_MESSAGE ]
			: __( 'The refund failed.', 'woocommerce' );

		return new WP_Error( $error_code, $error_message );
	}

	/**
	 * Retain the provider refund identity on the local refund after local effects fail.
	 *
	 * @param WC_Order                      $order        Parent order.
	 * @param int|null                      $wc_refund_id Local refund this call links.
	 * @param PaymentOutcome                $outcome      Provider refund outcome.
	 * @param ProviderPersistenceVocabulary $profile      Provider persistence vocabulary.
	 * @return bool Whether the refund identity was persisted.
	 */
	private function persist_refund_reconciliation_context( WC_Order $order, ?int $wc_refund_id, PaymentOutcome $outcome, ProviderPersistenceVocabulary $profile ): bool {
		$refund_reference = $outcome->get_provider_payment_id();
		if ( '' === $refund_reference || null === $wc_refund_id ) {
			return false;
		}

		try {
			$refund = wc_get_order( $wc_refund_id );
			if ( ! $refund instanceof WC_Order_Refund || $order->get_id() !== $refund->get_parent_id() ) {
				return false;
			}

			$meta_key = $profile->get_processed_refund_link_meta_key();
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
	 * Get the ID of the order's newest refund: the one WooCommerce created for this refund call.
	 *
	 * WooCommerce saves the refund row before it calls the gateway, so the newest row is the one
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
	 * @param WC_Order       $order        Parent order.
	 * @param PaymentOutcome $outcome      Provider refund outcome.
	 * @param int|null       $wc_refund_id Local refund this call links.
	 * @throws \RuntimeException When the refund or parent order cannot be reloaded.
	 */
	private function apply_refund_outcome( WC_Order $order, PaymentOutcome $outcome, ?int $wc_refund_id ): void {
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
		$refund_note_identity_meta_key = isset( $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY ] ) && is_string( $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY ] )
			? $data[ PaymentOutcome::DATA_REFUND_NOTE_IDENTITY_META_KEY ]
			: '';

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
			$this->maybe_add_refund_note( $reloaded_order, $refund_note, $refund_note_identity, $refund_note_equivalents, $refund_note_identity_meta_key );
		}
		$reloaded_order->save_meta_data();
	}

	/**
	 * Add a WooPayments-compatible provider refund note if it does not already exist.
	 *
	 * @param WC_Order $order             Parent order.
	 * @param string   $note              Provider refund note.
	 * @param string   $identity          Stable refund note identity.
	 * @param string[] $equivalent_notes  Exact equivalent refund-note renderings.
	 * @param string   $identity_meta_key Comment-meta key for the stable identity.
	 */
	private function maybe_add_refund_note( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), string $identity_meta_key = '' ): void {
		if ( '' === $identity || '' === $identity_meta_key ) {
			if ( ! $this->order_note_exists( $order, $note ) ) {
				$order->add_order_note( $note );
			}
			return;
		}

		$identity_hash         = hash( 'sha256', $identity );
		$equivalent_notes      = array_values( array_unique( array_merge( array( $note ), $equivalent_notes ) ) );
		$content_match_note_id = 0;
		$notes                 = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $order_note ) {
			$note_identities = get_comment_meta( $order_note->id, $identity_meta_key, false );
			if ( in_array( $identity_hash, $note_identities, true ) ) {
				return;
			}

			if ( in_array( (string) $order_note->content, $equivalent_notes, true ) ) {
				$content_match_note_id = $order_note->id;
			}
		}

		if ( 0 < $content_match_note_id ) {
			add_comment_meta( $content_match_note_id, $identity_meta_key, $identity_hash );
			return;
		}

		$order->add_order_note( $note, 0, false, array( $identity_meta_key => $identity_hash ) );
	}

	/**
	 * Tell whether an order already has a note.
	 *
	 * @param WC_Order $order        Order object.
	 * @param string   $note_content Note content.
	 * @return bool
	 */
	private function order_note_exists( WC_Order $order, string $note_content ): bool {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( $note_content === $note->content ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Capture an authorized payment through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return PaymentOutcome
	 */
	public function capture( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
		return $this->run_provider_order_operation( $context, $provider, 'capture' );
	}

	/**
	 * Cancel an authorized payment through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return PaymentOutcome
	 */
	public function cancel( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
		return $this->run_provider_order_operation( $context, $provider, 'cancel' );
	}

	/**
	 * Charge a provider and normalize exceptions.
	 *
	 * @param PaymentContext   $context         Payment context.
	 * @param ProviderContract $provider        Provider.
	 * @param string           $idempotency_key Key minted fresh for this payment attempt.
	 * @return PaymentOutcome
	 */
	private function charge_provider( PaymentContext $context, ProviderContract $provider, string $idempotency_key ): PaymentOutcome {
		try {
			return $provider->charge( $context, $idempotency_key );
		} catch ( Throwable $exception ) {
			$outcome = $this->exception_policy->to_failed_outcome( $exception );
			$this->log_provider_failure( $context->get_order(), 'charge', $idempotency_key, $exception, $outcome );

			return $outcome;
		}
	}

	/**
	 * Tell whether a zero-total checkout still needs a provider-owned setup operation.
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return bool
	 */
	private function should_call_provider_for_zero_total_checkout( PaymentContext $context, ProviderContract $provider ): bool {
		if ( ! $provider->supports_zero_amount_setup( $context ) ) {
			return false;
		}

		return '' !== $context->get_payment_method_id() || $this->has_saved_payment_token( $context );
	}

	/**
	 * Tell whether checkout context carries an existing saved payment token.
	 *
	 * @param PaymentContext $context Payment context.
	 * @return bool
	 */
	private function has_saved_payment_token( PaymentContext $context ): bool {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return '' !== $payment_token && 'new' !== $payment_token;
	}

	/**
	 * Run a capture/cancel provider operation under the shared order lock.
	 *
	 * @param PaymentContext   $context   Payment context.
	 * @param ProviderContract $provider  Provider.
	 * @param string           $operation Operation name.
	 * @return PaymentOutcome
	 * @throws Throwable When applying an unreferenced unsuccessful provider outcome fails.
	 */
	private function run_provider_order_operation( PaymentContext $context, ProviderContract $provider, string $operation ): PaymentOutcome {
		$order           = $context->get_order();
		$amount          = $context->get_amount() ?? (float) $order->get_total();
		$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), $operation, $amount, (string) $order->get_currency() );
		$profile         = $provider->get_persistence_profile();

		if ( ! $this->order_payment_store->claim_order_payment_lock_for_operation( $order, $profile, $idempotency_key, $operation ) ) {
			$this->order_payment_store->log_order_payment_lock_refusal( $order, $profile, $operation );
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_ERROR_MESSAGE => __( 'A payment operation is already in progress for this order.', 'woocommerce' ) )
			);
		}

		try {
			try {
				$provider_outcome = 'capture' === $operation
					? $provider->capture( $context, $idempotency_key )
					: $provider->cancel( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$provider_outcome = $this->exception_policy->to_failed_outcome( $exception );
				$this->log_provider_failure( $order, $operation, $idempotency_key, $exception, $provider_outcome );
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
				$reconciliation_persisted = $this->persist_reconciliation_context( $order, $provider_outcome, $provider );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, $operation, $apply_exception, $reconciliation_persisted );
			}

			return $outcome;
		} finally {
			$this->order_payment_store->release_order_payment_lock( $order, $profile, $idempotency_key );
		}
	}

	/**
	 * Log a provider operation throwable with idempotency correlation.
	 *
	 * Logging is best-effort and must never replace the normalized failed outcome.
	 *
	 * @param WC_Order       $order           Order being processed.
	 * @param string         $operation       Provider operation.
	 * @param string         $idempotency_key Operation key: the per-attempt key for charges, the derived key otherwise.
	 * @param Throwable      $exception       Provider throwable.
	 * @param PaymentOutcome $outcome         Normalized failed outcome.
	 */
	private function log_provider_failure( WC_Order $order, string $operation, string $idempotency_key, Throwable $exception, PaymentOutcome $outcome ): void {
		try {
			$logger = $this->logger;
			if ( null === $logger ) {
				if ( ! function_exists( 'wc_get_logger' ) ) {
					return;
				}

				$logger = wc_get_logger();
			}

			$data                = $outcome->get_data();
			$provider_error_code = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) ? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ] : '';
			$logger->error(
				'Native payment provider operation threw an exception.',
				array(
					'source'              => 'woopayments-payments',
					'operation'           => $operation,
					'order_id'            => $order->get_id(),
					'idempotency_key'     => $idempotency_key,
					'exception_class'     => get_class( $exception ),
					'exception_message'   => $exception->getMessage(),
					'provider_error_code' => $provider_error_code,
				)
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Apply optional provider-owned effects after transport and before the generic lifecycle.
	 *
	 * @param PaymentContext   $context   Payment context.
	 * @param PaymentOutcome   $outcome   Provider outcome.
	 * @param ProviderContract $provider  Provider.
	 * @param string           $operation Operation name.
	 * @return PaymentOutcome
	 */
	private function apply_provider_operation_effects( PaymentContext $context, PaymentOutcome $outcome, ProviderContract $provider, string $operation ): PaymentOutcome {
		if ( ! $provider instanceof ProviderOperationEffectApplier ) {
			return $outcome;
		}

		return $provider->apply_operation_effects( $context, $outcome, $operation );
	}

	/**
	 * Apply optional provider-owned effects after the generic lifecycle.
	 *
	 * @param PaymentContext   $context   Payment context.
	 * @param PaymentOutcome   $outcome   Applied provider outcome.
	 * @param ProviderContract $provider  Provider.
	 * @param string           $operation Operation name.
	 */
	private function apply_provider_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, ProviderContract $provider, string $operation ): void {
		if ( ! $provider instanceof ProviderPostLifecycleEffectApplier ) {
			return;
		}

		$provider->apply_post_lifecycle_effects( $context, $outcome, $operation );
	}

	/**
	 * Apply a provider order-operation outcome to the order lifecycle.
	 *
	 * @param WC_Order         $order     Order object.
	 * @param PaymentOutcome   $outcome   Provider outcome.
	 * @param string           $operation Operation name.
	 * @param ProviderContract $provider  Provider.
	 */
	private function apply_order_operation_outcome( WC_Order $order, PaymentOutcome $outcome, string $operation, ProviderContract $provider ): void {
		if ( in_array( $operation, array( 'capture', 'cancel' ), true ) && PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			// An expired authorization is the one capture failure that must move the order:
			// the provider effects carry the capture-expired note when the re-fetched intent
			// came back canceled, and the order goes to failed like the charge.expired webhook.
			if ( PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_EXPIRED === $this->get_lifecycle_note_type( $outcome ) ) {
				$this->lifecycle_service->apply_unlocked(
					$order,
					new PaymentLifecycleEvent(
						PaymentLifecycleEvent::STATUS_CAPTURE_EXPIRED,
						$this->get_lifecycle_payment_reference( $outcome ),
						$this->get_lifecycle_meta( $outcome, $provider ),
						array(),
						$this->get_lifecycle_note( $outcome ),
						$this->get_lifecycle_note_type( $outcome ),
						$this->get_lifecycle_note_equivalents( $outcome )
					),
					$provider->get_persistence_profile()
				);
				return;
			}

			// A failed authorization operation leaves the original authorization active, regardless of whether
			// the attempted operation was capture or cancellation.
			$meta = $this->get_failed_capture_or_cancel_outcome_meta( $outcome, $provider );

			$this->lifecycle_service->apply_unlocked(
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
				$provider->get_persistence_profile()
			);
			return;
		}

		$this->apply_checkout_outcome( $order, $outcome, $provider );
	}

	/**
	 * Apply a provider checkout outcome to the order lifecycle.
	 *
	 * @param WC_Order         $order    Order object.
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 */
	private function apply_checkout_outcome( WC_Order $order, PaymentOutcome $outcome, ProviderContract $provider ): void {
		$this->lifecycle_service->apply_unlocked(
			$order,
			new PaymentLifecycleEvent(
				$this->get_lifecycle_status( $outcome ),
				$this->get_lifecycle_payment_reference( $outcome ),
				$this->get_lifecycle_meta( $outcome, $provider ),
				$this->get_lifecycle_meta_to_delete( $outcome ),
				$this->get_lifecycle_note( $outcome ),
				$this->get_lifecycle_note_type( $outcome ),
				$this->get_lifecycle_note_equivalents( $outcome ),
				// The event stays a failure so the late-failure guard still
				// protects already-paid orders; only the status transition is
				// suppressed when the outcome asked to preserve the status.
				$this->should_preserve_order_status( $outcome )
			),
			$provider->get_persistence_profile()
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
	 * Map a provider outcome status to an order lifecycle status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private function get_lifecycle_status( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return PaymentLifecycleEvent::STATUS_COMPLETED;

			case PaymentOutcome::STATUS_AUTHORIZED:
				return PaymentLifecycleEvent::STATUS_AUTHORIZED;

			case PaymentOutcome::STATUS_FAILED:
				return PaymentLifecycleEvent::STATUS_FAILED;

			case PaymentOutcome::STATUS_CANCELED:
				return PaymentLifecycleEvent::STATUS_CANCELED;

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return PaymentLifecycleEvent::STATUS_STARTED;
		}

		return PaymentLifecycleEvent::STATUS_FAILED;
	}

	/**
	 * Build lifecycle meta from a provider outcome.
	 *
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 */
	private function get_lifecycle_meta( PaymentOutcome $outcome, ProviderContract $provider ): array {
		return $this->get_provider_outcome_meta( $outcome, $provider );
	}

	/**
	 * Map provider outcome metadata through the optional provider port.
	 *
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 */
	private function get_provider_outcome_meta( PaymentOutcome $outcome, ProviderContract $provider ): array {
		return $provider instanceof ProviderOutcomeMetadataMapper ? $provider->get_outcome_meta( $outcome ) : array();
	}

	/**
	 * Map a failed capture or cancel outcome to metadata through the optional provider port.
	 *
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 */
	private function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome, ProviderContract $provider ): array {
		return $provider instanceof ProviderOutcomeMetadataMapper ? $provider->get_failed_capture_or_cancel_outcome_meta( $outcome ) : array();
	}

	/**
	 * Get order meta keys to delete from an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string[]
	 */
	private function get_lifecycle_meta_to_delete( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		if ( ! isset( $data[ PaymentOutcome::DATA_META_TO_DELETE ] ) || ! is_array( $data[ PaymentOutcome::DATA_META_TO_DELETE ] ) ) {
			return array();
		}

		return array_values( array_map( 'strval', $data[ PaymentOutcome::DATA_META_TO_DELETE ] ) );
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
	 * Get exact equivalent order-note renderings from an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string[]
	 */
	private function get_lifecycle_note_equivalents( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		if ( ! isset( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) || ! is_array( $data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ] ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$data[ PaymentOutcome::DATA_NOTE_EQUIVALENTS ],
				static fn( $note_equivalent ): bool => is_string( $note_equivalent ) && '' !== $note_equivalent
			)
		);
	}

	/**
	 * Format a WooCommerce checkout result from an outcome.
	 *
	 * @param PaymentContext $context Payment context.
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	private function format_checkout_result( PaymentContext $context, WC_Order $order, PaymentOutcome $outcome ): array {
		if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			return array(
				// 'failure', not 'fail'. WooCommerce recognizes exactly
				// success, failure, pending and error; the Store API turns a
				// failed payment's notice into a shopper-visible error only on
				// an exact 'failure' match, then clears the notice queue. An
				// unrecognized value skips that conversion, is coerced to
				// failure for the status, and leaves the shopper a failed
				// checkout with no reason given.
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		$payment_method_id = '' !== $outcome->get_payment_method_id() ? $outcome->get_payment_method_id() : $context->get_payment_method_id();
		$data              = $outcome->get_data();
		$redirect          = array_key_exists( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $data )
			? (string) $data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ]
			: ( '' !== $outcome->get_redirect_url() ? $outcome->get_redirect_url() : $order->get_checkout_order_received_url() );

		return array(
			'result'         => 'success',
			'redirect'       => $redirect,
			'payment_method' => $payment_method_id,
		);
	}
}
