<?php
/**
 * ProviderOperationEffectPlan interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Marker contract for provider-owned post-transport effect plans.
 *
 * Plans are request-scoped values carried outside PaymentOutcome data so they are never serialized
 * or persisted by the generic payment lifecycle.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface ProviderOperationEffectPlan {
}
