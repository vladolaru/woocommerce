<?php
/**
 * ProviderOperationEffectApplierInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port that runs right after the provider call returns, before the outcome is applied.
 *
 * PaymentProcessingService calls it under the order payment lock from process_checkout_outcome(), process_refund()
 * and run_provider_order_operation() (capture, cancel), passing the context, the provider outcome and the operation.
 * It may write provider data to the order and returns the outcome the service applies next, unchanged or replaced.
 * Without it, the provider outcome is applied as returned.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderOperationEffectApplierInterface {

	/**
	 * Apply provider-specific effects and optionally replace the neutral outcome.
	 *
	 * @param PaymentContext $context   Payment context.
	 * @param PaymentOutcome $outcome   Provider transport outcome.
	 * @param string         $operation Operation name.
	 * @return PaymentOutcome
	 */
	public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome;
}
