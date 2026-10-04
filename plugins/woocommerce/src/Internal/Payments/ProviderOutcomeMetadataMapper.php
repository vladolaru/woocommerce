<?php
/**
 * ProviderOutcomeMetadataMapper interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port that turns an outcome into provider order meta.
 *
 * PaymentProcessingService calls it from apply_checkout_outcome() and apply_order_operation_outcome() to build
 * the PaymentLifecycleEvent meta that OrderPaymentLifecycleService::apply_unlocked() saves, and from
 * persist_reconciliation_context() when applying an outcome failed. It receives the outcome and only returns meta.
 * Without it, no provider meta is written for checkout, capture or cancel outcomes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderOutcomeMetadataMapper {

	/**
	 * Map a neutral outcome to provider-owned order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array;

	/**
	 * Map a failed capture or cancel, which leaves the authorization active, to provider-owned order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array;
}
