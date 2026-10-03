<?php
/**
 * ProviderOperationEffectApplier interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port for applying provider-specific effects after transport returns an outcome.
 *
 * This is deliberately separate from ProviderContract so existing provider implementations do not
 * gain a new required method.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderOperationEffectApplier {

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
