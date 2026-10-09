<?php
/**
 * ProviderOutcomeMetadataMapperInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Turns a payment outcome into the provider's order meta.
 *
 * PaymentProcessingService uses it to build the lifecycle event meta for checkout, capture and cancel outcomes, and
 * to save the reconciliation context when applying an outcome failed. It only returns meta; without it, no provider
 * meta is written for those outcomes.
 *
 * @since 11.0.0
 * @internal
 */
interface ProviderOutcomeMetadataMapperInterface {

	/**
	 * Map an outcome to provider-owned order metadata.
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
	 * A failed capture or cancel leaves the authorization in place, so this meta records the payment as still authorized.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array;
}
