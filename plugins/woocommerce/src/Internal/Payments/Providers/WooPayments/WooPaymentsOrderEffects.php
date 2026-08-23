<?php
/**
 * WooPaymentsOrderEffects class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;

/**
 * Deterministically projects WooPayments provider and order facts to local effect data.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderEffects {

	/**
	 * Compose payment-method identity and metadata without rendering a title.
	 *
	 * @param array<string,mixed> $result                Native PaymentIntent response.
	 * @param string              $express_checkout_type Existing express-checkout identity.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string}|array{}
	 */
	public static function compose_payment_method_display_details( array $result, string $express_checkout_type = '' ): array {
		$charge                 = self::latest_charge( $result );
		$payment_method_details = is_array( $charge['payment_method_details'] ?? null ) ? $charge['payment_method_details'] : array();
		$payment_method_type    = isset( $payment_method_details['type'] ) && is_scalar( $payment_method_details['type'] )
			? sanitize_key( (string) $payment_method_details['type'] )
			: self::intent_payment_method_type( $result );
		$wallet_type            = isset( $payment_method_details['card']['wallet']['type'] ) && is_scalar( $payment_method_details['card']['wallet']['type'] )
			? sanitize_key( (string) $payment_method_details['card']['wallet']['type'] )
			: '';
		if ( '' === $wallet_type && 'amazon_pay' === $payment_method_type ) {
			$wallet_type = 'amazon_pay';
		}

		$express_checkout_type = sanitize_key( $express_checkout_type );
		if ( '' === $express_checkout_type ) {
			$express_checkout_type = $wallet_type;
		}

		if ( '' === $payment_method_type && '' === $express_checkout_type ) {
			return array();
		}

		$meta = array();
		if ( ! empty( $payment_method_details ) ) {
			$encoded_details = wp_json_encode( self::payment_method_details_for_order_meta( $payment_method_details ) );
			if ( false !== $encoded_details ) {
				$meta['_wcpay_payment_method_details'] = $encoded_details;
			}
		}

		if ( 'link' !== $wallet_type && isset( $payment_method_details['card']['last4'] ) ) {
			$meta['last4'] = (string) $payment_method_details['card']['last4'];
			if ( isset( $payment_method_details['card']['brand'] ) ) {
				$meta['_card_brand'] = (string) $payment_method_details['card']['brand'];
			}
		}

		if ( 'amazon_pay' === $payment_method_type && isset( $payment_method_details['amazon_pay']['funding']['card'] ) && is_array( $payment_method_details['amazon_pay']['funding']['card'] ) ) {
			$funding_card = $payment_method_details['amazon_pay']['funding']['card'];
			if ( isset( $funding_card['last4'] ) && is_scalar( $funding_card['last4'] ) ) {
				$meta['last4'] = (string) $funding_card['last4'];
			}
			if ( isset( $funding_card['brand'] ) && is_scalar( $funding_card['brand'] ) ) {
				$meta['_card_brand'] = strtolower( (string) $funding_card['brand'] );
			}
		}

		if ( '' !== $express_checkout_type ) {
			$meta['_wcpay_express_checkout_payment_method'] = $express_checkout_type;
		}

		$effective_type = '' !== $express_checkout_type ? $express_checkout_type : $payment_method_type;

		return array(
			'meta'                   => $meta,
			'payment_method_id'      => self::payment_method_gateway_id( $effective_type ),
			'payment_method_type'    => $payment_method_type,
			'payment_method_details' => $payment_method_details,
			'express_checkout_type'  => $express_checkout_type,
		);
	}

	/**
	 * Compose lifecycle metadata for a native PaymentIntent.
	 *
	 * @param array<string,mixed>  $intent         Native PaymentIntent response.
	 * @param string               $order_currency Order currency.
	 * @param string               $account_mode   WooPayments account mode.
	 * @param array<string,string> $settlement_meta Precomputed settlement metadata.
	 * @return array<string,string>
	 */
	public static function payment_intent_meta( array $intent, string $order_currency, string $account_mode, array $settlement_meta = array() ): array {
		$status                 = isset( $intent['status'] ) ? (string) $intent['status'] : '';
		$charge                 = self::latest_charge( $intent );
		$charge_id              = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		$balance_transaction_id = self::balance_transaction_id( $charge['balance_transaction'] ?? null );
		$intent_currency        = isset( $intent['currency'] ) ? (string) $intent['currency'] : $order_currency;
		$meta                   = array(
			'_wcpay_intent_currency'        => strtoupper( $intent_currency ),
			'_wcpay_mode'                   => $account_mode,
			'_wcpay_payment_transaction_id' => $balance_transaction_id,
		);

		if ( '' !== $charge_id ) {
			$meta['_charge_id'] = $charge_id;
		}

		if ( isset( $charge['outcome']['risk_level'] ) ) {
			$meta['_charge_risk_level'] = (string) $charge['outcome']['risk_level'];
		}

		if ( 'succeeded' === $status ) {
			$meta = array_merge( $meta, self::completed_charge_meta( $intent, $charge, $settlement_meta ) );
		} elseif ( in_array( $status, array( 'requires_capture', 'processing' ), true ) ) {
			$meta['_intention_status'] = $status;
			$meta                      = array_merge( $meta, self::authorized_charge_meta( $intent, $charge, $settlement_meta ) );
		} elseif ( in_array( $status, array( 'requires_action', 'requires_confirmation' ), true ) ) {
			$meta = array_merge( $meta, self::started_payment_meta( $intent, $order_currency ) );
		}

		return array_merge( $meta, self::multibanco_voucher_meta( $intent ) );
	}

	/**
	 * Get the latest charge array from a PaymentIntent response.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @return array<string,mixed>
	 */
	public static function latest_charge( array $intent ): array {
		$charges = isset( $intent['charges']['data'] ) && is_array( $intent['charges']['data'] ) ? $intent['charges']['data'] : array();
		$charge  = empty( $charges ) ? array() : end( $charges );

		return is_array( $charge ) ? $charge : array();
	}

	/**
	 * Get a balance transaction ID from a provider response field.
	 *
	 * @param mixed $balance_transaction Balance transaction response field.
	 * @return string
	 */
	public static function balance_transaction_id( $balance_transaction ): string {
		if ( is_string( $balance_transaction ) ) {
			return $balance_transaction;
		}

		return is_array( $balance_transaction ) && isset( $balance_transaction['id'] ) ? (string) $balance_transaction['id'] : '';
	}

	/**
	 * Get legacy-compatible order metadata for a completed charge.
	 *
	 * @param array<string,mixed>  $intent                         Native PaymentIntent response.
	 * @param array<string,mixed>  $charge                         Native Charge response.
	 * @param array<string,string> $settlement_meta                Precomputed settlement metadata.
	 * @param bool                 $include_payment_transaction_id Whether to include the balance transaction ID.
	 * @param bool                 $was_held_for_review            Whether the order was held for fraud review before completing.
	 * @return array<string,string>
	 */
	public static function completed_charge_meta( array $intent, array $charge, array $settlement_meta = array(), bool $include_payment_transaction_id = true, bool $was_held_for_review = false ): array {
		$meta            = array();
		$transaction_fee = self::transaction_fee_from_charge( $intent, $charge );
		if ( '' !== $transaction_fee ) {
			$meta['_wcpay_transaction_fee'] = $transaction_fee;
		}

		$net = self::net_from_charge( $intent, $charge, $transaction_fee );
		if ( '' !== $net ) {
			$meta['_wcpay_net'] = $net;
		}

		$balance_transaction_id = self::balance_transaction_id( $charge['balance_transaction'] ?? null );
		if ( $include_payment_transaction_id && '' !== $balance_transaction_id ) {
			$meta['_wcpay_payment_transaction_id'] = $balance_transaction_id;
		}

		return array_merge( $meta, self::authorized_charge_meta( $intent, $charge, $settlement_meta, $was_held_for_review ) );
	}

	/**
	 * Get charge metadata valid before capture.
	 *
	 * @param array<string,mixed>  $intent              Native PaymentIntent response.
	 * @param array<string,mixed>  $charge              Native Charge response.
	 * @param array<string,string> $settlement_meta     Precomputed settlement metadata.
	 * @param bool                 $was_held_for_review Whether the order was held for fraud review before completing.
	 * @return array<string,string>
	 */
	public static function authorized_charge_meta( array $intent, array $charge, array $settlement_meta = array(), bool $was_held_for_review = false ): array {
		$meta = $settlement_meta;
		if ( isset( $charge['outcome']['risk_level'] ) ) {
			$meta['_charge_risk_level'] = (string) $charge['outcome']['risk_level'];
		}

		return array_merge( $meta, self::fraud_outcome_meta( $intent, $charge, $was_held_for_review ) );
	}

	/**
	 * Get payment-method backfill metadata from a completed charge.
	 *
	 * @param array<string,mixed> $charge                          Native Charge response.
	 * @param bool                $has_placeholder_payment_details Whether existing details are empty.
	 * @param string              $existing_transaction_id        Existing balance transaction ID.
	 * @return array<string,string>
	 */
	public static function completed_charge_payment_method_backfill_meta( array $charge, bool $has_placeholder_payment_details, string $existing_transaction_id = '' ): array {
		if ( ! $has_placeholder_payment_details ) {
			return array();
		}

		$meta                   = array();
		$balance_transaction_id = self::balance_transaction_id( $charge['balance_transaction'] ?? null );
		if ( '' === $existing_transaction_id && '' !== $balance_transaction_id ) {
			$meta['_wcpay_payment_transaction_id'] = $balance_transaction_id;
		}

		$payment_method_details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] ) ? $charge['payment_method_details'] : array();
		if ( ! empty( $payment_method_details ) ) {
			$encoded_details = wp_json_encode( self::payment_method_details_for_order_meta( $payment_method_details ) );
			if ( false !== $encoded_details ) {
				$meta['_wcpay_payment_method_details'] = $encoded_details;
			}
		}

		return $meta;
	}

	/**
	 * Get legacy-compatible order metadata for a started PaymentIntent.
	 *
	 * @param array<string,mixed> $intent         Native PaymentIntent response.
	 * @param string              $order_currency Order currency.
	 * @return array<string,string>
	 */
	public static function started_payment_meta( array $intent, string $order_currency ): array {
		return array(
			'_intention_status'             => isset( $intent['status'] ) ? (string) $intent['status'] : 'requires_action',
			'_wcpay_intent_currency'        => $order_currency,
			'_wcpay_payment_transaction_id' => '',
			'_wcpay_fraud_meta_box_type'    => self::is_card_intent( $intent ) ? 'payment_started' : 'not_card',
		);
	}

	/**
	 * Get lifecycle metadata from a completed capture response.
	 *
	 * @param array<string,mixed>  $intent              Native PaymentIntent response.
	 * @param string               $order_currency      Order currency.
	 * @param string               $account_mode        WooPayments account mode.
	 * @param array<string,string> $settlement_meta     Precomputed settlement metadata.
	 * @param bool                 $was_held_for_review Whether the order's stored fraud outcome is review.
	 * @return array<string,string>
	 */
	public static function completed_capture_meta( array $intent, string $order_currency, string $account_mode, array $settlement_meta = array(), bool $was_held_for_review = false ): array {
		$charge = self::latest_charge( $intent );
		if ( empty( $charge ) ) {
			return array();
		}

		$meta      = array(
			'_wcpay_intent_currency' => strtolower( isset( $intent['currency'] ) ? (string) $intent['currency'] : $order_currency ),
			'_wcpay_mode'            => $account_mode,
		);
		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		if ( '' !== $charge_id ) {
			$meta['_charge_id'] = $charge_id;
		}

		return array_merge( $meta, self::completed_charge_meta( $intent, $charge, $settlement_meta, false, $was_held_for_review ) );
	}

	/**
	 * Get legacy-compatible order metadata for a failed capture response.
	 *
	 * @return array<string,string>
	 */
	public static function failed_capture_meta(): array {
		return array( '_intention_status' => 'requires_capture' );
	}

	/**
	 * Get WooPayments fraud-outcome order metadata.
	 *
	 * An order that succeeds after sitting in fraud review is stamped
	 * review_allowed, not allow — the meta box then records that a human
	 * approved the payment rather than the risk filters alone.
	 *
	 * @param array<string,mixed> $intent              Native PaymentIntent response.
	 * @param array<string,mixed> $charge              Native Charge response.
	 * @param bool                $was_held_for_review Whether the order was held for fraud review before completing.
	 * @return array<string,string>
	 */
	public static function fraud_outcome_meta( array $intent, array $charge, bool $was_held_for_review = false ): array {
		$metadata      = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$fraud_outcome = isset( $metadata['fraud_outcome'] ) ? (string) $metadata['fraud_outcome'] : '';
		$is_card       = self::is_card_charge( $charge );

		if ( in_array( $fraud_outcome, array( 'allow', 'block', 'review' ), true ) ) {
			$allow_type = $was_held_for_review ? 'review_allowed' : 'allow';

			return array(
				'_wcpay_fraud_outcome_status' => $fraud_outcome,
				'_wcpay_fraud_meta_box_type'  => $is_card ? $allow_type : 'not_card',
			);
		}

		return $is_card ? array() : array( '_wcpay_fraud_meta_box_type' => 'not_card' );
	}

	/**
	 * Get the merchant transaction fee from a native charge.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @param array<string,mixed> $charge Native Charge response.
	 * @return string
	 */
	public static function transaction_fee_from_charge( array $intent, array $charge ): string {
		$fee_breakdown_v1 = $charge['fee_breakdown_v1'] ?? null;
		if ( is_array( $fee_breakdown_v1 ) && isset( $fee_breakdown_v1['totals']['fee']['amount'], $fee_breakdown_v1['totals']['fee']['currency'] ) ) {
			return (string) self::interpret_stripe_amount( (int) $fee_breakdown_v1['totals']['fee']['amount'], (string) $fee_breakdown_v1['totals']['fee']['currency'] );
		}

		$application_fee_amount = $charge['application_fee_amount'] ?? null;
		$currency               = isset( $charge['currency'] ) ? (string) $charge['currency'] : (string) ( $intent['currency'] ?? '' );

		return null !== $application_fee_amount && '' !== $currency
			? (string) self::interpret_stripe_amount( (int) $application_fee_amount, $currency )
			: '';
	}

	/**
	 * Get the merchant net amount from a native charge.
	 *
	 * @param array<string,mixed> $intent          Native PaymentIntent response.
	 * @param array<string,mixed> $charge          Native Charge response.
	 * @param string              $transaction_fee Transaction fee.
	 * @return string
	 */
	public static function net_from_charge( array $intent, array $charge, string $transaction_fee ): string {
		$fee_breakdown_v1 = $charge['fee_breakdown_v1'] ?? null;
		if ( is_array( $fee_breakdown_v1 ) && isset( $fee_breakdown_v1['totals']['net']['amount'], $fee_breakdown_v1['totals']['net']['currency'] ) ) {
			return (string) self::interpret_stripe_amount( (int) $fee_breakdown_v1['totals']['net']['amount'], (string) $fee_breakdown_v1['totals']['net']['currency'] );
		}

		$application_fee_amount = $charge['application_fee_amount'] ?? null;
		$charge_amount          = $charge['amount'] ?? $intent['amount'] ?? null;
		$currency               = isset( $charge['currency'] ) ? (string) $charge['currency'] : (string) ( $intent['currency'] ?? '' );
		if ( null !== $application_fee_amount && '' !== $transaction_fee && null !== $charge_amount && '' !== $currency ) {
			return (string) ( self::interpret_stripe_amount( (int) $charge_amount, $currency ) - (float) $transaction_fee );
		}

		return '';
	}

	/**
	 * Interpret a Stripe integer amount for a currency.
	 *
	 * @param int    $amount   Stripe integer amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function interpret_stripe_amount( int $amount, string $currency ): float {
		return WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency );
	}

	/**
	 * Normalize payment method details before storing order metadata.
	 *
	 * @param array<string,mixed> $payment_method_details Payment method details.
	 * @return array<string,mixed>
	 */
	public static function payment_method_details_for_order_meta( array $payment_method_details ): array {
		if ( isset( $payment_method_details['sepa_debit'] ) && is_array( $payment_method_details['sepa_debit'] ) ) {
			unset( $payment_method_details['sepa_debit']['expected_debit_date'] );
		}

		return $payment_method_details;
	}

	/**
	 * Get legacy-compatible Multibanco voucher order metadata.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @return array<string,string>
	 */
	public static function multibanco_voucher_meta( array $intent ): array {
		$next_action = isset( $intent['next_action'] ) && is_array( $intent['next_action'] ) ? $intent['next_action'] : array();
		if ( 'multibanco_display_details' !== (string) ( $next_action['type'] ?? '' ) ) {
			return array();
		}

		$details = isset( $next_action['multibanco_display_details'] ) && is_array( $next_action['multibanco_display_details'] )
			? $next_action['multibanco_display_details']
			: array();

		return self::scalar_meta_from_keys(
			$details,
			array(
				'reference'          => '_wcpay_multibanco_reference',
				'entity'             => '_wcpay_multibanco_entity',
				'hosted_voucher_url' => '_wcpay_multibanco_url',
				'expires_at'         => '_wcpay_multibanco_expiry',
			)
		);
	}

	/**
	 * Compose local effects for a successful provider refund.
	 *
	 * @param array<string,mixed> $result        Provider refund response.
	 * @param string              $rendered_note Rendered compatibility note.
	 * @return array<string,mixed>
	 */
	public static function compose_refund_effect_data( array $result, string $rendered_note ): array {
		$refund_id              = isset( $result['id'] ) ? (string) $result['id'] : '';
		$provider_status        = isset( $result['status'] ) ? (string) $result['status'] : '';
		$refund_status          = 'pending' === $provider_status ? 'pending' : 'successful';
		$balance_transaction_id = self::balance_transaction_id( $result['balance_transaction'] ?? null );
		$refund_meta            = array( '_wcpay_refund_id' => $refund_id );

		if ( '' !== $balance_transaction_id ) {
			$refund_meta['_wcpay_refund_transaction_id'] = $balance_transaction_id;
		}

		return array(
			PaymentOutcome::DATA_ORDER_META  => array( '_wcpay_refund_status' => $refund_status ),
			PaymentOutcome::DATA_REFUND_META => $refund_meta,
			PaymentOutcome::DATA_REFUND_NOTE => $rendered_note,
		);
	}

	/**
	 * Tell whether the currency uses zero decimal places at the provider boundary.
	 *
	 * @param string $currency Currency code.
	 * @return bool
	 */
	public static function is_zero_decimal_currency( string $currency ): bool {
		return WooPaymentsCurrencyUtils::is_zero_decimal_currency( $currency );
	}

	/**
	 * Tell whether a native charge used a card payment method.
	 *
	 * @param array<string,mixed> $charge Native Charge response.
	 * @return bool
	 */
	private static function is_card_charge( array $charge ): bool {
		$details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] ) ? $charge['payment_method_details'] : array();

		return 'card' === (string) ( $details['type'] ?? '' );
	}

	/**
	 * Tell whether a native intent used a card payment method.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @return bool
	 */
	private static function is_card_intent( array $intent ): bool {
		$charge = self::latest_charge( $intent );
		if ( ! empty( $charge ) && self::is_card_charge( $charge ) ) {
			return true;
		}

		$payment_method = isset( $intent['payment_method'] ) && is_array( $intent['payment_method'] ) ? $intent['payment_method'] : array();
		if ( 'card' === (string) ( $payment_method['type'] ?? '' ) ) {
			return true;
		}

		$payment_method_types = isset( $intent['payment_method_types'] ) && is_array( $intent['payment_method_types'] )
			? array_map( 'strval', $intent['payment_method_types'] )
			: array();
		if ( in_array( 'card', $payment_method_types, true ) ) {
			return true;
		}

		$options = isset( $intent['payment_method_options'] ) && is_array( $intent['payment_method_options'] ) ? $intent['payment_method_options'] : array();

		return array_key_exists( 'card', $options );
	}

	/**
	 * Map a provider payment method type to its WooCommerce gateway ID.
	 *
	 * @param string $payment_method_type Provider payment method type.
	 * @return string
	 */
	private static function payment_method_gateway_id( string $payment_method_type ): string {
		if ( in_array( $payment_method_type, array( 'card', 'link', 'apple_pay', 'google_pay' ), true ) ) {
			return OrderPaymentStore::GATEWAY_ID;
		}

		return '' === $payment_method_type ? '' : OrderPaymentStore::GATEWAY_ID_PREFIX . $payment_method_type;
	}

	/**
	 * Get the payment method type represented by an intent.
	 *
	 * @param array<string,mixed> $intent Provider intent response.
	 * @return string
	 */
	private static function intent_payment_method_type( array $intent ): string {
		$options = isset( $intent['payment_method_options'] ) && is_array( $intent['payment_method_options'] ) ? array_keys( $intent['payment_method_options'] ) : array();
		if ( ! empty( $options ) ) {
			return sanitize_key( (string) $options[0] );
		}

		$types = isset( $intent['payment_method_types'] ) && is_array( $intent['payment_method_types'] ) ? array_values( $intent['payment_method_types'] ) : array();

		return ! empty( $types ) && is_scalar( $types[0] ) ? sanitize_key( (string) $types[0] ) : '';
	}

	/**
	 * Project scalar payload fields to metadata keys.
	 *
	 * @param array<string,mixed>  $payload Payload values.
	 * @param array<string,string> $key_map Source-to-meta key map.
	 * @return array<string,string>
	 */
	private static function scalar_meta_from_keys( array $payload, array $key_map ): array {
		$meta = array();

		foreach ( $key_map as $source_key => $meta_key ) {
			if ( isset( $payload[ $source_key ] ) && is_scalar( $payload[ $source_key ] ) ) {
				$meta[ $meta_key ] = (string) $payload[ $source_key ];
			}
		}

		return $meta;
	}
}
