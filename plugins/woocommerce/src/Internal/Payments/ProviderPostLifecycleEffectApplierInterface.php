<?php
/**
 * ProviderPostLifecycleEffectApplierInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Applies a provider's own effects after the runtime has applied the outcome to the order.
 *
 * PaymentProcessingService calls it under the order payment lock for checkout, capture and cancel, never for refunds.
 * It may update the order but cannot change the outcome; without it, nothing runs afterwards.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderPostLifecycleEffectApplierInterface {

	/**
	 * Apply provider-specific effects after the generic lifecycle has persisted its result.
	 *
	 * @param PaymentOperationContext $context   Payment context.
	 * @param PaymentOutcome          $outcome   Applied provider outcome.
	 * @param string                  $operation Operation name.
	 */
	public function apply_post_lifecycle_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): void;
}
