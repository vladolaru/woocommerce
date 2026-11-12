<?php
/**
 * WooPaymentsOutcomeMetadataMapper class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderOutcomeMetadataMapperInterface;

/**
 * Maps a payment outcome to the WooPayments order meta: intent, payment method, customer and intention status.
 *
 * @since 11.0.0
 * @internal
 */
final class WooPaymentsOutcomeMetadataMapper implements ProviderOutcomeMetadataMapperInterface {

	/**
	 * Outcome data key: the order meta the payment lifecycle writes; refund meta travels in `PaymentOutcome::DATA_REFUND_META`.
	 *
	 * @var string
	 */
	public const OUTCOME_META_KEY = 'meta';

	/**
	 * Outcome data key, set by WooPaymentsOrderEffectApplier: a started payment whose own writes are skipped, because the
	 * order is not Pending or already has the payment's started note. Its lifecycle event then carries no meta and no note;
	 * the intent's record is written by the attach before it (client 11.3.0 class-wc-payments-order-service.php:2229-2241).
	 *
	 * @var string
	 */
	public const STARTED_WRITES_SKIPPED_KEY = 'woopayments_started_writes_skipped';

	/**
	 * Map a payment outcome to the WooPayments order meta its lifecycle event writes: none for a started payment whose own
	 * writes are skipped (STARTED_WRITES_SKIPPED_KEY), the intent's meta otherwise.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		return true === ( $outcome->get_data()[ self::STARTED_WRITES_SKIPPED_KEY ] ?? false ) ? array() : $this->get_intent_meta( $outcome );
	}

	/**
	 * Map a payment outcome to WooPayments order meta, the intent's record included, whatever its event writes: what the
	 * intent attach takes its fields from.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_intent_meta( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		$meta = array();

		if ( isset( $data[ self::OUTCOME_META_KEY ] ) && is_array( $data[ self::OUTCOME_META_KEY ] ) ) {
			foreach ( $data[ self::OUTCOME_META_KEY ] as $key => $value ) {
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

		// A $0 order confirmed without an intent has no intention status; client 11.1.0 writes none there (gw:1673-1771).
		$plan = $outcome->get_effect_plan();
		if ( ! isset( $meta['_intention_status'] ) && ! ( $plan instanceof WooPaymentsOrderEffectPlan && WooPaymentsOrderEffectPlan::TYPE_ZERO_AMOUNT_WITHOUT_INTENT === $plan->get_type() ) ) {
			$meta['_intention_status'] = $this->get_default_intention_status( $outcome );
		}

		ksort( $meta );

		return array_map( 'strval', $meta );
	}

	/**
	 * Map a failed capture or cancel to WooPayments order metadata.
	 *
	 * The intention status the effects composed is kept, such as the status re-fetched after a failed cancel, which
	 * client 11.1.0 saves (class-wc-payment-gateway-wcpay.php:4129); with none composed, the authorization stays
	 * requires_capture.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	public function get_failed_capture_or_cancel_outcome_meta( PaymentOutcome $outcome ): array {
		$meta = $this->get_outcome_meta( $outcome );
		if ( ! isset( $outcome->get_data()[ self::OUTCOME_META_KEY ]['_intention_status'] ) ) {
			$meta['_intention_status'] = 'requires_capture';
		}
		ksort( $meta );

		return array_map( 'strval', $meta );
	}

	/**
	 * Get the intention status an outcome implies when it sets none.
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
