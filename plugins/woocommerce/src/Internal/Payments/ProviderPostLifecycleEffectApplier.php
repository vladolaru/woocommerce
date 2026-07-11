<?php
/**
 * ProviderPostLifecycleEffectApplier interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port for effects that must run after the generic payment lifecycle.
 *
 * This is separate from ProviderOperationEffectApplier so existing provider implementations do not
 * gain a new required method.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderPostLifecycleEffectApplier {

	/**
	 * Apply provider-specific effects after the generic lifecycle has persisted its result.
	 *
	 * @param PaymentContext $context   Payment context.
	 * @param PaymentOutcome $outcome   Applied provider outcome.
	 * @param string         $operation Operation name.
	 */
	public function apply_post_lifecycle_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): void;
}
