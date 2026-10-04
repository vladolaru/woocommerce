<?php
/**
 * ProviderPostLifecycleEffectApplier interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port that runs after OrderPaymentLifecycleService::apply_unlocked() has applied the outcome.
 *
 * PaymentProcessingService calls it under the order payment lock from process_checkout_outcome() and
 * run_provider_order_operation() (capture, cancel), never for refunds, passing the context, the applied outcome
 * and the operation. It may update the order but cannot change the outcome. Without it, nothing runs afterwards.
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
