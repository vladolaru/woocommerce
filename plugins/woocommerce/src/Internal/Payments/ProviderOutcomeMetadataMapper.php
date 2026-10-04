<?php
/**
 * ProviderOutcomeMetadataMapper interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Optional provider port for mapping outcomes to provider-owned order metadata.
 *
 * Each optional port is a capability a provider may not need, so it is its own interface rather than a
 * ProviderContract method. A provider implements only the ports it uses; without this one, the processing
 * service writes no provider metadata from outcomes.
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
	 * Despite the name, the processing service calls this for both failed captures and failed cancels.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array;
}
