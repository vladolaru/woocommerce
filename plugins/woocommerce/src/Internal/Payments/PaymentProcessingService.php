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
	 * @throws Throwable When applying the lifecycle outcome fails for an unsuccessful charge.
	 */
	public function process_checkout_outcome( PaymentContext $context, ProviderContract $provider ): PaymentOutcome {
		$order           = $context->get_order();
		$amount          = (float) $order->get_total();
		$currency        = (string) $order->get_currency();
		$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'charge', $amount, $currency );

		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $idempotency_key ) ) {
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
			$outcome = 0.0 >= $amount && ! $this->should_call_provider_for_zero_total_checkout( $context, $provider )
				? new PaymentOutcome( PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT )
				: $this->charge_provider( $context, $provider, $idempotency_key );

			try {
				$this->apply_checkout_outcome( $order, $outcome, $provider );
			} catch ( Throwable $apply_exception ) {
				if ( ! $outcome->is_successful() ) {
					throw $apply_exception;
				}

				// The charge already moved money. Downgrading the outcome to FAILED would risk a
				// re-charge on retry, so keep the success and make the order reconcilable instead:
				// persist the provider payment reference and log the failure for follow-up.
				$this->persist_payment_reference( $order, $outcome );
				$this->log_post_charge_apply_failure( $order, $outcome, $apply_exception );
			}

			return $outcome;
		} finally {
			$this->order_payment_store->unlock_order_payment( $order );
		}
	}

	/**
	 * Persist the provider payment reference onto the order so a successful charge stays reconcilable.
	 *
	 * Called when lifecycle application fails after the provider has already moved money. The order is
	 * reloaded to avoid clobbering concurrent writes, and the transaction ID is only set when the order
	 * does not already carry one, so an existing reference is never overwritten.
	 *
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Successful provider outcome.
	 */
	private function persist_payment_reference( WC_Order $order, PaymentOutcome $outcome ): void {
		$payment_reference = $outcome->get_provider_payment_id();
		if ( '' === $payment_reference ) {
			return;
		}

		$reloaded_order = wc_get_order( $order->get_id() );
		if ( ! $reloaded_order instanceof WC_Order ) {
			return;
		}

		if ( '' !== (string) $reloaded_order->get_transaction_id() ) {
			return;
		}

		$reloaded_order->set_transaction_id( $payment_reference );
		$reloaded_order->save();
	}

	/**
	 * Log a post-charge lifecycle application failure so a money-moving charge is never silently dropped.
	 *
	 * @param WC_Order       $order     Order object.
	 * @param PaymentOutcome $outcome   Successful provider outcome.
	 * @param Throwable      $exception Lifecycle application exception.
	 */
	private function log_post_charge_apply_failure( WC_Order $order, PaymentOutcome $outcome, Throwable $exception ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->error(
			'Native payment charge succeeded but applying the order lifecycle outcome failed; the order has been left reconcilable via its payment reference.',
			array(
				'source'            => 'native-payments',
				'order_id'          => $order->get_id(),
				'payment_reference' => $outcome->get_provider_payment_id(),
				'error'             => $exception->getMessage(),
			)
		);
	}

	/**
	 * Process a refund through a provider.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext   $context  Payment context.
	 * @param ProviderContract $provider Provider.
	 * @return bool|WP_Error
	 */
	public function process_refund( PaymentContext $context, ProviderContract $provider ) {
		$order        = $context->get_order();
		$payment_data = $context->get_payment_data();
		$amount       = isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0;
		$reason       = isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '';

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
		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $refund_scope_key ) ) {
			return new WP_Error( 'native_payment_refund_locked', __( 'A refund is already in progress for this order.', 'woocommerce' ) );
		}

		try {
			$refund_instance = $this->resolve_provider_refund_instance_id( $order, $amount, $reason, $provider );
			$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), 'refund', $amount, (string) $order->get_currency(), $reason, $refund_instance );

			try {
				$outcome = $provider->refund( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$outcome = $this->exception_policy->to_failed_outcome( $exception );
			}

			if ( $outcome->is_successful() ) {
				$this->apply_refund_outcome( $order, $outcome, $amount, $reason );
			}
		} finally {
			$this->order_payment_store->unlock_order_payment( $order );
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
	private const PROCESSED_REFUND_LINK_META_KEY = '_wcpay_refund_id';

	/**
	 * Resolve the ID of the specific WooCommerce refund this operation is processing.
	 *
	 * The local `WC_Order_Refund` row is created and saved before the gateway refund call, so it is
	 * already present here. Its ID is used as a per-instance discriminator in the idempotency key so
	 * two distinct refunds of the same amount and reason never share a key, which would otherwise let
	 * the provider replay the first refund and silently drop the second.
	 *
	 * Resolution must identify the *fresh, unprocessed* refund rather than rely on row ordering. An
	 * equal-amount, equal-reason refund that has already been processed carries
	 * {@see self::PROCESSED_REFUND_LINK_META_KEY}; passing that key as the exclusion set makes
	 * find_matching_refund() skip such refunds. Without this exclusion, resolution would depend on
	 * the order get_refunds() happens to return rows (CPT vs HPOS, same-second ties, future query
	 * changes) and could latch onto an already-processed refund — reusing its key and reintroducing
	 * the exact collision this guard prevents.
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
	 * @param WC_Order       $order   Parent order.
	 * @param PaymentOutcome $outcome Provider refund outcome.
	 * @param float          $amount  Refund amount.
	 * @param string         $reason  Refund reason.
	 */
	private function apply_refund_outcome( WC_Order $order, PaymentOutcome $outcome, float $amount, string $reason ): void {
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

		$matched_refund = $this->find_matching_refund( $order, $amount, $reason, array_map( 'strval', array_keys( $refund_meta ) ) );
		$reloaded_order = wc_get_order( $order->get_id() );
		if ( ! $matched_refund instanceof WC_Order_Refund ) {
			return;
		}
		if ( ! $reloaded_order instanceof WC_Order ) {
			return;
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

		$expected_amount = wc_format_decimal( $amount );
		$refunds         = array_reverse( $reloaded_order->get_refunds() );

		foreach ( $refunds as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			if ( $this->refund_has_any_meta_value( $refund, $provider_refund_meta_keys ) ) {
				continue;
			}

			if ( wc_format_decimal( $refund->get_amount() ) !== $expected_amount ) {
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
			return $this->exception_policy->to_failed_outcome( $exception );
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
	 */
	private function run_provider_order_operation( PaymentContext $context, ProviderContract $provider, string $operation ): PaymentOutcome {
		$order           = $context->get_order();
		$amount          = $context->get_amount() ?? (float) $order->get_total();
		$idempotency_key = $this->idempotency->derive_key( $order, $provider->get_id(), $operation, $amount, (string) $order->get_currency() );

		if ( ! $this->order_payment_store->claim_order_payment_lock( $order, $idempotency_key ) ) {
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
				$outcome = 'capture' === $operation
					? $provider->capture( $context, $idempotency_key )
					: $provider->cancel( $context, $idempotency_key );
			} catch ( Throwable $exception ) {
				$outcome = $this->exception_policy->to_failed_outcome( $exception );
			}

			$this->apply_order_operation_outcome( $order, $outcome, $operation, $provider );

			return $outcome;
		} finally {
			$this->order_payment_store->unlock_order_payment( $order );
		}
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
		if ( 'capture' === $operation && PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			$meta = $provider->get_persistence_profile()->get_capture_failure_outcome_meta( $outcome );

			$this->lifecycle_service->apply_unlocked(
				$order,
				new PaymentLifecycleEvent(
					PaymentLifecycleEvent::STATUS_STARTED,
					$this->get_lifecycle_payment_reference( $outcome ),
					$meta,
					array(),
					$this->get_lifecycle_note( $outcome )
				)
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
				array(),
				$this->get_lifecycle_note( $outcome )
			)
		);
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
		return $provider->get_persistence_profile()->get_outcome_meta( $outcome );
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
				'result'         => 'fail',
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
