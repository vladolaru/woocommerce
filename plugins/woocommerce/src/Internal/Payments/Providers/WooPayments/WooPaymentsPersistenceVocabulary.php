<?php
/**
 * WooPaymentsPersistenceVocabulary class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use WC_Order;

/**
 * Names the gateway ids, order payment lock and order meta keys that WooPayments payments are stored under.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsPersistenceVocabulary implements ProviderPersistenceVocabularyInterface {

	/**
	 * WooPayments gateway ID.
	 *
	 * Every WooPayments order stores it as its payment method, so it lives with the stored names, and comparing an
	 * order's payment method does not load the gateway class.
	 *
	 * @var string
	 */
	public const GATEWAY_ID = 'woocommerce_payments';

	/**
	 * WooPayments split-UPE gateway ID prefix.
	 *
	 * @var string
	 */
	public const GATEWAY_ID_PREFIX = 'woocommerce_payments_';

	/**
	 * WooPayments-compatible order processing lock transient prefix.
	 *
	 * The built-in WooPayments and the WooPayments extension share this order lock on stores that switch, so the key,
	 * the sentinel and the lifetime are the extension's.
	 *
	 * @var string
	 */
	public const LOCK_TRANSIENT_PREFIX = 'wcpay_processing_intent_';

	/**
	 * WooPayments-compatible sentinel used when the order is locked without a payment reference.
	 *
	 * @var string
	 */
	public const LOCK_SENTINEL = '-1';

	/**
	 * WooPayments lock time-to-live, in seconds.
	 *
	 * @var int
	 */
	public const LOCK_TTL_SECONDS = 300;

	/**
	 * Provider refund-link meta key written onto a `WC_Order_Refund` once it has been processed.
	 *
	 * @var string
	 */
	public const PROCESSED_REFUND_LINK_META_KEY = '_wcpay_refund_id';

	/**
	 * Provider early fraud warning evidence stored on the order.
	 *
	 * @since 11.2.0
	 * @var string
	 */
	public const EARLY_FRAUD_WARNING_META_KEY = '_wcpay_early_fraud_warning';

	/**
	 * Order and refund meta keys that hold WooPayments payment state, returned by get_preserved_payment_meta_keys().
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
		'_wcpay_fraud_ruleset_results',
		self::EARLY_FRAUD_WARNING_META_KEY,
		'_wcpay_intent_currency',
		'_wcpay_open_dispute_ids',
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
	 * Get the order meta key holding the payment intent the order is bound to.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_payment_reference_meta_key(): string {
		return '_intent_id';
	}

	/**
	 * Get the order meta key holding the charge idempotency key kept after an ambiguous charge failure.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_charge_idempotency_key_meta_key(): string {
		return WooPaymentsProviderGatewayAdapter::CHARGE_IDEMPOTENCY_KEY_META;
	}

	/**
	 * Get the order meta key holding the open dispute IDs.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_open_dispute_ids_meta_key(): string {
		return WooPaymentsDisputeEventHandler::OPEN_DISPUTE_IDS_META_KEY;
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
		return ( new WooPaymentsOutcomeMetadataMapper() )->get_outcome_meta( $outcome );
	}
}
