<?php
/**
 * PaymentProcessingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
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
	 * @throws Throwable When applying an unreferenced unsuccessful charge outcome fails.
	 */
	public function process_checkout_outcome( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
		$order           = $context->get_order();
		$amount          = (float) $order->get_total();
		$currency        = (string) $order->get_currency();
		$idempotency_key = $this->idempotency->mint_attempt_key();
		$profile         = $provider->get_persistence_profile();

		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $profile, $idempotency_key ) ) {
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

				// The provider already returned a durable result. Downgrading it to an ID-less failure
				// would risk a duplicate operation on retry, so keep the result reconcilable.
				$outcome                  = $provider_outcome;
				$reconciliation_persisted = $this->persist_reconciliation_context( $order, $provider_outcome, $provider );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, 'charge', $apply_exception, $reconciliation_persisted );
			}

			return $outcome;
		} finally {
			$this->order_payment_store->unlock_order_payment( $order, $profile );
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
		$reason       = isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '';
		$profile      = $provider->get_persistence_profile();

		if ( '0.00' === sprintf( '%0.2f', $amount ) ) {
			return true;
		}

		// Claim the order lock before resolving the refund instance, keyed on a stable refund-scope
		// token rather than the per-instance idempotency key. Two concurrent equal-amount, equal-reason
		// refunds would otherwise resolve the same fresh refund row, derive the same idempotency key,
		// and the provider would replay the first refund and drop the second. Serializing resolution
		// under the lock lets each refund link its row before the next one resolves, so distinct
		// refunds resolve to distinct instances.
		$refund_scope_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'refund-scope', $amount, (string) $order->get_currency(), $reason );
		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $profile, $refund_scope_key ) ) {
			return new WP_Error( 'native_payment_refund_locked', __( 'A refund is already in progress for this order.', 'woocommerce' ) );
		}

		try {
			$refund_instance = $this->resolve_provider_refund_instance_id( $order, $amount, $reason, $provider );
			$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'refund', $amount, (string) $order->get_currency(), $reason, $refund_instance );

			try {
				$provider_outcome = $provider->refund( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$provider_outcome = $this->exception_policy->to_failed_outcome( $exception );
				$this->log_provider_failure( $order, 'refund', $idempotency_key, $exception, $provider_outcome );
			}
			$outcome = $provider_outcome;

			try {
				$outcome = $this->apply_provider_operation_effects( $context, $outcome, $provider, 'refund' );
				if ( $outcome->is_successful() ) {
					$this->apply_refund_outcome( $order, $outcome, $refund_instance );
				}
			} catch ( Throwable $apply_exception ) {
				if ( ! $this->is_reconcilable_provider_outcome( $provider_outcome ) ) {
					throw $apply_exception;
				}

				$outcome                  = $provider_outcome;
				$reconciliation_persisted = $this->persist_refund_reconciliation_context( $order, $refund_instance, $provider_outcome, $profile );
				$this->log_post_provider_apply_failure( $order, $provider_outcome, 'refund', $apply_exception, $reconciliation_persisted );
			}
		} finally {
			$this->order_payment_store->unlock_order_payment( $order, $profile );
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
	 * Retain the provider refund identity on the exact local refund after local effects fail.
	 *
	 * @param WC_Order                      $order           Parent order.
	 * @param string|null                   $refund_instance Local refund instance ID.
	 * @param PaymentOutcome                $outcome         Provider refund outcome.
	 * @param ProviderPersistenceVocabulary $profile         Provider persistence vocabulary.
	 * @return bool Whether the refund identity was persisted.
	 */
	private function persist_refund_reconciliation_context( WC_Order $order, ?string $refund_instance, PaymentOutcome $outcome, ProviderPersistenceVocabulary $profile ): bool {
		$refund_reference = $outcome->get_provider_payment_id();
		if ( '' === $refund_reference || null === $refund_instance || '' === $refund_instance ) {
			return false;
		}

		try {
			$refund = wc_get_order( (int) $refund_instance );
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
	 * Provider refund-link meta key written onto a `WC_Order_Refund` once it has been processed.
	 *
	 * Both the synchronous refund path ({@see WooPaymentsProviderGatewayAdapter}, via the
	 * `refund_meta` returned to apply_refund_outcome()) and the asynchronous webhook path
	 * ({@see WooPaymentsRefundEventHandler}) stamp this key on the refund. Its presence is the
	 * stable signal that a refund has already reached the provider, so refund-instance resolution
	 * uses it to exclude already-processed refunds and lock onto the fresh, unprocessed one.
	 *
	 * @var string
	 */
	private const PROCESSED_REFUND_LINK_META_KEY = WooPaymentsPersistenceProfile::PROCESSED_REFUND_LINK_META_KEY;

	/**
	 * Resolve the ID of the specific WooCommerce refund this operation is processing.
	 *
	 * The local `WC_Order_Refund` row is created and saved before the gateway refund call, so it is
	 * already present here. Its ID is used as a per-instance discriminator in the idempotency key so
	 * two distinct refunds of the same amount and reason never share a key, which would otherwise let
	 * the provider replay the first refund and silently drop the second.
	 *
	 * Resolution must identify the *fresh, unprocessed* refund rather than rely only on row ordering. An
	 * equal-amount, equal-reason refund that has already been processed carries
	 * {@see self::PROCESSED_REFUND_LINK_META_KEY}; passing that key as the exclusion set makes
	 * find_matching_refund() skip such refunds. Without this exclusion, resolution could latch onto
	 * an already-processed refund — reusing its key and reintroducing the exact collision this guard
	 * prevents.
	 *
	 * Returns null only when no matching refund can be located. In the normal synchronous WooCommerce
	 * refund flow this cannot happen: core creates and saves the `WC_Order_Refund` row before invoking
	 * the gateway, so the row is always present at this point. The null fallback therefore omits the
	 * instance from the key, which is the deterministic pre-instance behavior — safe because the only
	 * way to reach it is the absence of a concurrent equal refund to collide with. The fallback stays
	 * deterministic on purpose: a random or microtime discriminator would break idempotency on
	 * legitimate retries of the same refund.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Refund amount.
	 * @param string   $reason Refund reason.
	 * @return string|null
	 */
	protected function resolve_refund_instance_id( WC_Order $order, float $amount, string $reason ): ?string {
		return $this->resolve_refund_instance_id_with_meta_key( $order, $amount, $reason, self::PROCESSED_REFUND_LINK_META_KEY );
	}

	/**
	 * Resolve the refund instance for a provider operation.
	 *
	 * @param WC_Order         $order    Parent order.
	 * @param float            $amount   Refund amount.
	 * @param string           $reason   Refund reason.
	 * @param ProviderContract $provider Provider.
	 * @return string|null
	 */
	private function resolve_provider_refund_instance_id( WC_Order $order, float $amount, string $reason, ProviderContract $provider ): ?string {
		$processed_refund_link_meta_key = $provider->get_persistence_profile()->get_processed_refund_link_meta_key();
		if ( self::PROCESSED_REFUND_LINK_META_KEY === $processed_refund_link_meta_key ) {
			return $this->resolve_refund_instance_id( $order, $amount, $reason );
		}

		return $this->resolve_refund_instance_id_with_meta_key( $order, $amount, $reason, $processed_refund_link_meta_key );
	}

	/**
	 * Resolve the refund instance using the supplied processed-refund link meta key.
	 *
	 * @param WC_Order $order                          Parent order.
	 * @param float    $amount                         Refund amount.
	 * @param string   $reason                         Refund reason.
	 * @param string   $processed_refund_link_meta_key Provider refund link meta key.
	 * @return string|null
	 */
	private function resolve_refund_instance_id_with_meta_key( WC_Order $order, float $amount, string $reason, string $processed_refund_link_meta_key ): ?string {
		$matched_refund = $this->find_matching_refund( $order, $amount, $reason, array( $processed_refund_link_meta_key ) );

		return $matched_refund instanceof WC_Order_Refund ? (string) $matched_refund->get_id() : null;
	}

	/**
	 * Apply provider refund metadata to the matching WooCommerce refund.
	 *
	 * @param WC_Order       $order           Parent order.
	 * @param PaymentOutcome $outcome         Provider refund outcome.
	 * @param string|null    $refund_instance Exact local refund instance resolved before provider transport.
	 * @throws \RuntimeException When the exact refund or parent order cannot be reloaded.
	 */
	private function apply_refund_outcome( WC_Order $order, PaymentOutcome $outcome, ?string $refund_instance ): void {
		$data        = $outcome->get_data();
		$refund_meta = isset( $data[ PaymentOutcome::DATA_REFUND_META ] ) && is_array( $data[ PaymentOutcome::DATA_REFUND_META ] )
			? $data[ PaymentOutcome::DATA_REFUND_META ]
			: array();
		$order_meta  = isset( $data[ PaymentOutcome::DATA_ORDER_META ] ) && is_array( $data[ PaymentOutcome::DATA_ORDER_META ] )
			? $data[ PaymentOutcome::DATA_ORDER_META ]
			: array();
		$refund_note = isset( $data[ PaymentOutcome::DATA_REFUND_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_REFUND_NOTE ] )
			? $data[ PaymentOutcome::DATA_REFUND_NOTE ]
			: '';

		if ( empty( $refund_meta ) && empty( $order_meta ) && '' === $refund_note ) {
			return;
		}

		$matched_refund = null !== $refund_instance && '' !== $refund_instance
			? wc_get_order( (int) $refund_instance )
			: false;
		$reloaded_order = wc_get_order( $order->get_id() );
		if ( ! $matched_refund instanceof WC_Order_Refund || $order->get_id() !== $matched_refund->get_parent_id() ) {
			throw new \RuntimeException( 'The exact local refund target could not be loaded after the provider refund succeeded.' );
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
			$this->maybe_add_refund_note( $reloaded_order, $refund_note );
		}
		$reloaded_order->save_meta_data();
	}

	/**
	 * Find the local refund row created before the gateway refund call.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param float    $amount Refund amount.
	 * @param string   $reason Refund reason.
	 * @param string[] $provider_refund_meta_keys Provider refund meta keys that mark a refund as linked.
	 * @return WC_Order_Refund|null
	 */
	private function find_matching_refund( WC_Order $order, float $amount, string $reason, array $provider_refund_meta_keys ): ?WC_Order_Refund {
		$reloaded_order = wc_get_order( $order->get_id() );
		if ( ! $reloaded_order instanceof WC_Order ) {
			return null;
		}

		$expected_amount = wc_format_decimal( $amount, false, true );
		$refunds         = array_reverse( $reloaded_order->get_refunds() );

		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			if ( $this->refund_has_any_meta_value( $refund, $provider_refund_meta_keys ) ) {
				continue;
			}

			if ( wc_format_decimal( $refund->get_amount(), false, true ) !== $expected_amount ) {
				continue;
			}

			if ( '' !== $reason && $reason !== (string) $refund->get_reason() ) {
				continue;
			}

			return $refund;
		}

		return null;
	}

	/**
	 * Tell whether a refund already has any provider supplied link meta.
	 *
	 * @param WC_Order_Refund $refund    Refund object.
	 * @param string[]        $meta_keys Provider refund meta keys.
	 * @return bool
	 */
	private function refund_has_any_meta_value( WC_Order_Refund $refund, array $meta_keys ): bool {
		foreach ( $meta_keys as $meta_key ) {
			if ( '' === $meta_key ) {
				continue;
			}

			$meta_value = $refund->get_meta( $meta_key, true );
			if ( null !== $meta_value && '' !== $meta_value && array() !== $meta_value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a WooPayments-compatible provider refund note if it does not already exist.
	 *
	 * @param WC_Order $order Parent order.
	 * @param string   $note  Provider refund note.
	 */
	private function maybe_add_refund_note( WC_Order $order, string $note ): void {
		if ( ! $this->order_note_exists( $order, $note ) ) {
			$order->add_order_note( $note );
		}
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
	 * @param string           $idempotency_key Deterministic idempotency key.
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
		if ( ! $provider->get_capability_manifest()->supports( CapabilityManifest::CAPABILITY_ZERO_AMOUNT_SETUP ) ) {
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

		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $profile, $idempotency_key ) ) {
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
			$this->order_payment_store->unlock_order_payment( $order, $profile );
		}
	}

	/**
	 * Log a provider operation throwable with idempotency correlation.
	 *
	 * Logging is best-effort and must never replace the normalized failed outcome.
	 *
	 * @param WC_Order       $order           Order being processed.
	 * @param string         $operation       Provider operation.
	 * @param string         $idempotency_key Deterministic operation key.
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
			$meta = $this->get_capture_failure_outcome_meta( $outcome, $provider );

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
	 * Map provider outcome metadata through the provider port or legacy fallback.
	 *
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 */
	private function get_provider_outcome_meta( PaymentOutcome $outcome, ProviderContract $provider ): array {
		if ( $provider instanceof ProviderOutcomeMetadataMapper ) {
			return $provider->get_outcome_meta( $outcome );
		}

		$profile = $provider->get_persistence_profile();

		return $profile instanceof ProviderPersistenceProfile ? $profile->get_outcome_meta( $outcome ) : array();
	}

	/**
	 * Map failed authorization metadata through the provider port or legacy fallback.
	 *
	 * @param PaymentOutcome   $outcome  Provider outcome.
	 * @param ProviderContract $provider Provider.
	 * @return array<string,string>
	 */
	private function get_capture_failure_outcome_meta( PaymentOutcome $outcome, ProviderContract $provider ): array {
		if ( $provider instanceof ProviderOutcomeMetadataMapper ) {
			return $provider->get_capture_failure_outcome_meta( $outcome );
		}

		$profile = $provider->get_persistence_profile();

		return $profile instanceof ProviderPersistenceProfile ? $profile->get_capture_failure_outcome_meta( $outcome ) : array();
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
