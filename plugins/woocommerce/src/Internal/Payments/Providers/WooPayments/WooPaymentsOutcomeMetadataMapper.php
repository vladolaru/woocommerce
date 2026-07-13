<?php
/**
 * WooPaymentsOutcomeMetadataMapper class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapper;

/**
 * Maps neutral payment outcomes to WooPayments-owned order metadata.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsOutcomeMetadataMapper implements ProviderOutcomeMetadataMapper {

	/**
	 * Map a neutral outcome to WooPayments order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		$meta = array();

		if ( isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] ) ) {
			foreach ( $data[ PaymentOutcome::DATA_META ] as $key => $value ) {
				$meta[ (string) $key ] = $value;
			}
		}

		if ( '' !== $outcome->get_provider_payment_id() ) {
			$meta['_intent_id'] = $outcome->get_provider_payment_id();
		}

		if ( '' !== $outcome->get_payment_method_id() ) {
			$meta['_payment_method_id'] = $outcome->get_payment_method_id();
		}

		if ( '' !== $outcome->get_customer_id() ) {
			$meta['_stripe_customer_id'] = $outcome->get_customer_id();
		}

		if ( ! isset( $meta['_intention_status'] ) ) {
			$meta['_intention_status'] = $this->get_default_intention_status( $outcome );
		}

		ksort( $meta );

		return array_map( 'strval', $meta );
	}

	/**
	 * Map a failed authorization operation to WooPayments order metadata.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array {
		$meta                      = $this->get_outcome_meta( $outcome );
		$meta['_intention_status'] = 'requires_capture';
		ksort( $meta );

		return array_map( 'strval', $meta );
	}

	/**
	 * Get a default WooPayments-compatible intention status for an outcome.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private function get_default_intention_status( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return 'succeeded';

			case PaymentOutcome::STATUS_AUTHORIZED:
				return 'requires_capture';

			case PaymentOutcome::STATUS_PENDING_ASYNC:
				return 'processing';

			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return 'requires_action';

			case PaymentOutcome::STATUS_FAILED:
				return 'requires_payment_method';

			case PaymentOutcome::STATUS_CANCELED:
				return 'canceled';
		}

		return '';
	}
}
