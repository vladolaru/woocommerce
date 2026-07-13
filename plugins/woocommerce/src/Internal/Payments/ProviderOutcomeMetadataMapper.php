<?php
/**
 * ProviderOutcomeMetadataMapper interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port for mapping outcomes to provider-owned order metadata.
 *
 * Keeping outcome interpretation on the provider prevents persistence key
 * vocabulary from accumulating operation-specific behavior. The processing
 * service retains a deprecated ProviderPersistenceProfile fallback for existing
 * implementations during the compatibility window.
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
	 * Map a failed authorization operation to provider-owned order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array;
}
