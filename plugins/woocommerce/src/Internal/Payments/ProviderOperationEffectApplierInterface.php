<?php
/**
 * ProviderOperationEffectApplierInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Applies a provider's own effects right after its call returns, before the runtime applies the outcome.
 *
 * PaymentProcessingService calls it under the order payment lock for checkout, refund, capture and cancel. It may
 * write provider data to the order and returns the outcome to apply, unchanged or replaced; without it, the provider
 * outcome is applied as returned.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderOperationEffectApplierInterface {

	/**
	 * Apply provider-specific effects and optionally replace the outcome.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Provider transport outcome.
	 * @param string                  $operation One of PaymentProcessingService::OPERATION_*.
	 * @return PaymentOutcome
	 */
	public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome;
}
