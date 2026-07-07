<?php
/**
 * WooPaymentsPersistenceProfile class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use WC_Order;

/**
 * WooPayments persistence vocabulary used by the native payments runtime.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPersistenceProfile implements ProviderPersistenceProfile {

	/**
	 * Preserved WooPayments gateway ID.
	 *
	 * @var string
	 */
	private const GATEWAY_ID = 'woocommerce_payments';

	/**
	 * Preserved WooPayments split-UPE gateway ID prefix.
	 *
	 * @var string
	 */
	private const GATEWAY_ID_PREFIX = 'woocommerce_payments_';

	/**
	 * WooPayments-compatible order processing lock transient prefix.
	 *
	 * @var string
	 */
	private const LOCK_TRANSIENT_PREFIX = 'wcpay_processing_intent_';

	/**
	 * WooPayments-compatible sentinel used when the order is locked without a payment reference.
	 *
	 * @var string
	 */
	private const LOCK_SENTINEL = '-1';

	/**
	 * WooPayments lock time-to-live, in seconds.
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 300;

	/**
	 * Provider refund-link meta key written onto a `WC_Order_Refund` once it has been processed.
	 *
	 * @var string
	 */
	private const PROCESSED_REFUND_LINK_META_KEY = '_wcpay_refund_id';

	/**
	 * WooPayments Bucket-E order/refund meta keys that native code must preserve.
	 *
	 * @var string[]
	 */
	private const PAYMENT_META_KEYS = array(
		'_intent_id',
		'_payment_method_id',
		'_charge_id',
		'_intention_status',
		'_charge_risk_level',
		'_stripe_customer_id',
		'_wcpay_fraud_meta_box_type',
		'_wcpay_fraud_outcome_status',
		'_wcpay_intent_currency',
		'_wcpay_refund_id',
		'_wcpay_refund_transaction_id',
		'_wcpay_refund_status',
		'_wcpay_transaction_fee',
		'_wcpay_mode',
		'_wcpay_payment_transaction_id',
		'_wcpay_multibanco_entity',
		'_wcpay_multibanco_reference',
		'_wcpay_multibanco_expiry',
		'_wcpay_multibanco_url',
		'_wcpay_payment_method_details',
		'_wcpay_ipp_channel',
		'_wcpay_net',
		'_stripe_mandate_id',
		'_wcpay_express_checkout_payment_method',
		'_wcpay_multi_currency_stripe_exchange_rate',
		'_wcpay_multi_currency_order_exchange_rate',
		'_wcpay_multi_currency_order_default_currency',
		'_wcpay_fraud_outcome_manual_entry',
		'is_woopay',
		'last4',
		'_card_brand',
	);

	/**
	 * Get the provider gateway ID.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_id(): string {
		return self::GATEWAY_ID;
	}

	/**
	 * Get the provider gateway ID prefix.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_gateway_id_prefix(): string {
		return self::GATEWAY_ID_PREFIX;
	}

	/**
	 * Get the order payment lock key.
	 *
	 * @param WC_Order $order Order object.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_order_lock_key( WC_Order $order ): string {
		return self::LOCK_TRANSIENT_PREFIX . $order->get_id();
	}

	/**
	 * Get the lock sentinel value.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_lock_sentinel(): string {
		return self::LOCK_SENTINEL;
	}

	/**
	 * Get the lock time-to-live in seconds.
	 *
	 * @return int
	 *
	 * @since 11.0.0
	 */
	public function get_lock_ttl_seconds(): int {
		return self::LOCK_TTL_SECONDS;
	}

	/**
	 * Get the processed refund link meta key.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_processed_refund_link_meta_key(): string {
		return self::PROCESSED_REFUND_LINK_META_KEY;
	}

	/**
	 * Get preserved order/refund meta keys.
	 *
	 * @return string[]
	 *
	 * @since 11.0.0
	 */
	public function get_preserved_payment_meta_keys(): array {
		return self::PAYMENT_META_KEYS;
	}

	/**
	 * Map a neutral outcome to WooPayments order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_outcome_meta( PaymentOutcome $outcome ): array {
		$data = $outcome->get_data();
		$meta = array();

		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( $data['meta'] as $key => $value ) {
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
	 * Map a failed capture outcome to WooPayments order meta.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_capture_failure_outcome_meta( PaymentOutcome $outcome ): array {
		$meta                      = $this->get_outcome_meta( $outcome );
		$meta['_intention_status'] = 'requires_capture';
		ksort( $meta );

		return array_map( 'strval', $meta );
	}

	/**
	 * Tell whether a provider-written duplicate order note should be skipped.
	 *
	 * @param WC_Order              $order Order object.
	 * @param PaymentLifecycleEvent $event Lifecycle event.
	 * @param string                $note  Note content.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function should_skip_note( WC_Order $order, PaymentLifecycleEvent $event, string $note ): bool {
		$payment_reference = (string) $event->get_payment_reference();

		if ( 0 === strpos( $note, '<strong>Fee details:</strong>' ) && $this->has_fee_details_note( $order ) ) {
			return true;
		}

		return PaymentLifecycleEvent::STATUS_COMPLETED === $event->get_status()
			&& 'Payment complete.' === $note
			&& '' !== $payment_reference
			&& $payment_reference === (string) $order->get_transaction_id()
			&& $order->has_status( array( 'processing', 'completed' ) );
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

	/**
	 * Tell whether the order already has a fee-details order note.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 */
	private function has_fee_details_note( WC_Order $order ): bool {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( 0 === strpos( (string) $note->content, '<strong>Fee details:</strong>' ) ) {
				return true;
			}
		}

		return false;
	}
}
