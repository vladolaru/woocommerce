<?php
/**
 * WooPaymentsPersistenceVocabulary class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\DisputeEventHandler;
use WC_Order;

/**
 * Names the gateway ids, the gateway settings option, the order payment lock and the order meta keys that WooPayments
 * payments are stored under.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsPersistenceVocabulary implements ProviderPersistenceVocabularyInterface {

	/**
	 * Brand of the card used for the payment.
	 *
	 * @var string
	 */
	public const CARD_BRAND_META_KEY = '_card_brand';

	/**
	 * ID of the charge associated with the order.
	 *
	 * @var string
	 */
	public const CHARGE_ID_META_KEY = '_charge_id';

	/**
	 * Risk level reported for the charge.
	 *
	 * @var string
	 */
	public const CHARGE_RISK_LEVEL_META_KEY = '_charge_risk_level';

	/**
	 * ID of the intent associated with the order.
	 *
	 * @var string
	 */
	public const INTENT_ID_META_KEY = '_intent_id';

	/**
	 * Last recorded status of the payment intent.
	 *
	 * @var string
	 */
	public const INTENTION_STATUS_META_KEY = '_intention_status';

	/**
	 * ID of the payment method used for the order.
	 *
	 * @var string
	 */
	public const PAYMENT_METHOD_ID_META_KEY = '_payment_method_id';

	/**
	 * Stripe customer associated with the payment.
	 *
	 * @var string
	 */
	public const STRIPE_CUSTOMER_ID_META_KEY = '_stripe_customer_id';

	/**
	 * Stripe mandate authorizing future payments.
	 *
	 * @var string
	 */
	public const STRIPE_MANDATE_ID_META_KEY = '_stripe_mandate_id';

	/**
	 * Express checkout method used to place the order.
	 *
	 * @var string
	 */
	public const EXPRESS_CHECKOUT_PAYMENT_METHOD_META_KEY = '_wcpay_express_checkout_payment_method';

	/**
	 * Fraud panel to display on the order screen.
	 *
	 * @var string
	 */
	public const FRAUD_META_BOX_TYPE_META_KEY = '_wcpay_fraud_meta_box_type';

	/**
	 * Manual entry associated with the fraud outcome.
	 *
	 * @var string
	 */
	public const FRAUD_OUTCOME_MANUAL_ENTRY_META_KEY = '_wcpay_fraud_outcome_manual_entry';

	/**
	 * Fraud decision recorded on the order.
	 *
	 * @var string
	 */
	public const FRAUD_OUTCOME_STATUS_META_KEY = '_wcpay_fraud_outcome_status';

	/**
	 * Results of the fraud rules evaluated for the payment.
	 *
	 * @var string
	 */
	public const FRAUD_RULESET_RESULTS_META_KEY = '_wcpay_fraud_ruleset_results';

	/**
	 * Currency of the payment intent.
	 *
	 * @var string
	 */
	public const INTENT_CURRENCY_META_KEY = '_wcpay_intent_currency';

	/**
	 * Channel used to take the in-person payment.
	 *
	 * @var string
	 */
	public const IPP_CHANNEL_META_KEY = '_wcpay_ipp_channel';

	/**
	 * Live or test mode in which the payment was made.
	 *
	 * @var string
	 */
	public const MODE_META_KEY = '_wcpay_mode';

	/**
	 * Default currency recorded for the order.
	 *
	 * @var string
	 */
	private const MULTI_CURRENCY_ORDER_DEFAULT_CURRENCY_META_KEY = '_wcpay_multi_currency_order_default_currency';

	/**
	 * Order exchange rate recorded by multi-currency checkout.
	 *
	 * @var string
	 */
	private const MULTI_CURRENCY_ORDER_EXCHANGE_RATE_META_KEY = '_wcpay_multi_currency_order_exchange_rate';

	/**
	 * Exchange rate reported by Stripe for the payment.
	 *
	 * @var string
	 */
	public const MULTI_CURRENCY_STRIPE_EXCHANGE_RATE_META_KEY = '_wcpay_multi_currency_stripe_exchange_rate';

	/**
	 * Entity number on the Multibanco voucher.
	 *
	 * @var string
	 */
	public const MULTIBANCO_ENTITY_META_KEY = '_wcpay_multibanco_entity';

	/**
	 * Expiry of the Multibanco voucher.
	 *
	 * @var string
	 */
	public const MULTIBANCO_EXPIRY_META_KEY = '_wcpay_multibanco_expiry';

	/**
	 * Payment reference on the Multibanco voucher.
	 *
	 * @var string
	 */
	public const MULTIBANCO_REFERENCE_META_KEY = '_wcpay_multibanco_reference';

	/**
	 * URL for the Multibanco payment instructions.
	 *
	 * @var string
	 */
	public const MULTIBANCO_URL_META_KEY = '_wcpay_multibanco_url';

	/**
	 * Net amount after transaction fees.
	 *
	 * @var string
	 */
	public const NET_META_KEY = '_wcpay_net';

	/**
	 * IDs of open disputes associated with the order.
	 *
	 * @var string
	 */
	public const OPEN_DISPUTE_IDS_META_KEY = '_wcpay_open_dispute_ids';

	/**
	 * Original payment method ID recorded on the order.
	 *
	 * @var string
	 */
	public const ORIGINAL_PAYMENT_METHOD_ID_META_KEY = '_wcpay_original_payment_method_id';

	/**
	 * Payment method details retained with the order.
	 *
	 * @var string
	 */
	public const PAYMENT_METHOD_DETAILS_META_KEY = '_wcpay_payment_method_details';

	/**
	 * Balance transaction ID for the payment.
	 *
	 * @var string
	 */
	public const PAYMENT_TRANSACTION_ID_META_KEY = '_wcpay_payment_transaction_id';

	/**
	 * Raw payment method details retained with the order.
	 *
	 * @var string
	 */
	public const RAW_PAYMENT_METHOD_DETAILS_META_KEY = '_wcpay_raw_payment_method_details';

	/**
	 * Refund status recorded locally for reconciliation.
	 *
	 * @var string
	 */
	public const REFUND_STATUS_META_KEY = '_wcpay_refund_status';

	/**
	 * Balance transaction ID for the refund.
	 *
	 * @var string
	 */
	public const REFUND_TRANSACTION_ID_META_KEY = '_wcpay_refund_transaction_id';

	/**
	 * Provider subscription ID associated with the subscription.
	 *
	 * @var string
	 */
	public const SUBSCRIPTION_ID_META_KEY = '_wcpay_subscription_id';

	/**
	 * Transaction fee recorded for the payment.
	 *
	 * @var string
	 */
	public const TRANSACTION_FEE_META_KEY = '_wcpay_transaction_fee';

	/**
	 * Whether the shopper used WooPay.
	 *
	 * @var string
	 */
	public const IS_WOOPAY_META_KEY = 'is_woopay';

	/**
	 * Last four digits of the card used for the payment.
	 *
	 * @var string
	 */
	public const LAST4_META_KEY = 'last4';

	/**
	 * WooPayments gateway ID.
	 *
	 * WooPayments orders store the base or split gateway ID as their payment method, so these names live with the stored
	 * names, and comparing an order's payment method does not load the gateway class.
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
	 * Gateway settings option: WC_Settings_API's option for the `woocommerce_payments` gateway, which the WooPayments
	 * extension and the built-in WooPayments read and write under the same name.
	 *
	 * @since 11.2.0
	 *
	 * @var string
	 */
	public const GATEWAY_SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	/**
	 * Comment-meta key holding the identities of WooPayments payment notes.
	 *
	 * @since 11.2.0
	 *
	 * @var string
	 */
	public const NOTE_IDENTITY_META_KEY = '_wc_woopayments_note_identity';

	/**
	 * WooPayments-compatible order processing lock transient prefix.
	 *
	 * The built-in WooPayments and the WooPayments extension use this key, sentinel and lifetime, so on stores without a
	 * persistent object cache they share the lock's rows; on object-cache stores the extension keeps its lock in the
	 * cache and the built-in in rows.
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
	 * Order meta key present only while the stored early fraud warning is actionable, holding the warning's `created`
	 * time, so the order query can find and sort those orders; the WooPayments extension writes it (client 11.3.0
	 * includes/class-wc-payments-order-service.php:308).
	 *
	 * @since 11.4.0
	 * @var string
	 */
	public const EARLY_FRAUD_WARNING_ACTIONABLE_META_KEY = '_wcpay_early_fraud_warning_actionable';

	/**
	 * Order meta key holding the time another gateway last took the order over from a WooPayments attempt, whose payment
	 * details were removed then; the WooPayments extension writes it (client 11.3.0 includes/class-wc-payments-order-service.php:121).
	 *
	 * @since 11.2.0
	 * @var string
	 */
	public const PAYMENT_DETAILS_REMOVED_META_KEY = '_wcpay_payment_details_removed';

	/**
	 * Order meta key listing the intents of WooPayments attempts whose payment details were removed when another gateway
	 * took the order over; the WooPayments extension writes it (client 11.3.0 includes/class-wc-payments-order-service.php:129).
	 *
	 * @since 11.2.0
	 * @var string
	 */
	public const SUPERSEDED_INTENT_IDS_META_KEY = '_wcpay_superseded_intent_ids';

	/**
	 * Order and refund meta keys that hold WooPayments payment state, returned by get_preserved_payment_meta_keys().
	 *
	 * @var string[]
	 */
	private const PAYMENT_META_KEYS = array(
		self::INTENT_ID_META_KEY,
		self::PAYMENT_METHOD_ID_META_KEY,
		self::CHARGE_ID_META_KEY,
		self::INTENTION_STATUS_META_KEY,
		self::CHARGE_RISK_LEVEL_META_KEY,
		self::STRIPE_CUSTOMER_ID_META_KEY,
		self::FRAUD_META_BOX_TYPE_META_KEY,
		self::FRAUD_OUTCOME_STATUS_META_KEY,
		self::FRAUD_RULESET_RESULTS_META_KEY,
		self::EARLY_FRAUD_WARNING_META_KEY,
		self::INTENT_CURRENCY_META_KEY,
		self::OPEN_DISPUTE_IDS_META_KEY,
		self::PROCESSED_REFUND_LINK_META_KEY,
		self::REFUND_TRANSACTION_ID_META_KEY,
		self::REFUND_STATUS_META_KEY,
		self::TRANSACTION_FEE_META_KEY,
		self::MODE_META_KEY,
		self::PAYMENT_TRANSACTION_ID_META_KEY,
		self::MULTIBANCO_ENTITY_META_KEY,
		self::MULTIBANCO_REFERENCE_META_KEY,
		self::MULTIBANCO_EXPIRY_META_KEY,
		self::MULTIBANCO_URL_META_KEY,
		self::PAYMENT_METHOD_DETAILS_META_KEY,
		self::IPP_CHANNEL_META_KEY,
		self::NET_META_KEY,
		self::STRIPE_MANDATE_ID_META_KEY,
		self::EXPRESS_CHECKOUT_PAYMENT_METHOD_META_KEY,
		self::MULTI_CURRENCY_STRIPE_EXCHANGE_RATE_META_KEY,
		self::MULTI_CURRENCY_ORDER_EXCHANGE_RATE_META_KEY,
		self::MULTI_CURRENCY_ORDER_DEFAULT_CURRENCY_META_KEY,
		self::FRAUD_OUTCOME_MANUAL_ENTRY_META_KEY,
		self::IS_WOOPAY_META_KEY,
		self::LAST4_META_KEY,
		self::CARD_BRAND_META_KEY,
	);

	/**
	 * Tell whether a gateway ID is the WooPayments gateway's or one of its split payment method gateways'.
	 *
	 * @since 11.2.0
	 *
	 * @param string $gateway_id Gateway ID, such as an order's payment method.
	 * @return bool
	 */
	public static function is_woopayments_gateway_id( string $gateway_id ): bool {
		return self::GATEWAY_ID === $gateway_id || 0 === strpos( $gateway_id, self::GATEWAY_ID_PREFIX );
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
	 * Get the order meta key holding the payment intent the order is bound to.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_payment_reference_meta_key(): string {
		return self::INTENT_ID_META_KEY;
	}

	/**
	 * Get the order meta key holding the charge idempotency key kept after an ambiguous charge failure.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_charge_idempotency_key_meta_key(): string {
		return WooPaymentsPaymentOperationService::CHARGE_IDEMPOTENCY_KEY_META;
	}

	/**
	 * Get the order meta key holding the open dispute IDs.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_open_dispute_ids_meta_key(): string {
		return DisputeEventHandler::OPEN_DISPUTE_IDS_META_KEY;
	}

	/**
	 * Get the comment-meta key holding the identities of WooPayments payment notes.
	 *
	 * @return string
	 *
	 * @since 11.2.0
	 */
	public function get_note_identity_meta_key(): string {
		return self::NOTE_IDENTITY_META_KEY;
	}
}
