<?php
/**
 * ProviderOperationEffectPlanInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Marks a provider's effect plan for one request, carried beside the outcome after the provider call.
 *
 * Plans are request-scoped values carried outside PaymentOutcome data so they are never serialized
 * or persisted by the generic payment lifecycle.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderOperationEffectPlanInterface {
}
