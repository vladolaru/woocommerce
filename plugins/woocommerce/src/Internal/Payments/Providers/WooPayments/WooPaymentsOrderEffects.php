<?php
/**
 * WooPaymentsOrderEffects class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Order;

/**
 * Composes WooPayments-compatible order metadata, titles, and notes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderEffects {

	/**
	 * Compose payment-method metadata, gateway ID, and title without mutating an order.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $result          Native PaymentIntent response.
	 * @param string              $account_country Connected account country.
	 * @param string              $billing_country Order billing country fallback.
	 * @param string              $express_checkout_type Existing order express-checkout identity.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_title:string}|array{}
	 */
	public static function compose_payment_method_display_details( array $result, string $account_country = '', string $billing_country = '', string $express_checkout_type = '' ): array {
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

		$meta            = array();
		$display_country = strtoupper( trim( '' !== $account_country ? $account_country : $billing_country ) );
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
		$title          = '' !== $express_checkout_type
			? self::express_checkout_payment_method_title( $express_checkout_type, $display_country )
			: self::payment_method_title(
				empty( $payment_method_details )
					? array(
						'type'               => $payment_method_type,
						$payment_method_type => array(),
					)
					: $payment_method_details,
				$display_country
			);

		return array(
			'meta'                 => $meta,
			'payment_method_id'    => self::payment_method_gateway_id( $effective_type ),
			'payment_method_title' => $title,
		);
	}

	/**
	 * Get the latest charge array from a PaymentIntent response.
	 *
	 * @since 11.0.0
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
	 * @since 11.0.0
	 *
	 * @param mixed $balance_transaction Balance transaction response field.
	 * @return string
	 */
	public static function balance_transaction_id( $balance_transaction ): string {
		if ( is_string( $balance_transaction ) ) {
			return $balance_transaction;
		}

		if ( is_array( $balance_transaction ) && isset( $balance_transaction['id'] ) ) {
			return (string) $balance_transaction['id'];
		}

		return '';
	}

	/**
	 * Get legacy-compatible order meta for a completed native charge.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed>         $intent                   Native PaymentIntent response.
	 * @param array<string,mixed>         $charge                   Native Charge response.
	 * @param WC_Order                    $order                    Order being charged.
	 * @param string                      $account_default_currency WooPayments account default currency.
	 * @param WooPaymentsOrderDataService $order_data_service       WooPayments order data service.
	 * @param bool                        $include_payment_transaction_id Whether to include the charge balance transaction ID.
	 * @return array<string,string>
	 */
	public static function completed_charge_meta( array $intent, array $charge, WC_Order $order, string $account_default_currency, WooPaymentsOrderDataService $order_data_service, bool $include_payment_transaction_id = true ): array {
		$meta = array();

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

		return array_merge(
			$meta,
			self::authorized_charge_meta( $intent, $charge, $order, $account_default_currency, $order_data_service )
		);
	}

	/**
	 * Get charge metadata that is valid before a payment has been captured.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed>         $intent                   Native PaymentIntent response.
	 * @param array<string,mixed>         $charge                   Native Charge response.
	 * @param WC_Order                    $order                    Order being authorized.
	 * @param string                      $account_default_currency WooPayments account default currency.
	 * @param WooPaymentsOrderDataService $order_data_service       WooPayments order data service.
	 * @return array<string,string>
	 */
	public static function authorized_charge_meta( array $intent, array $charge, WC_Order $order, string $account_default_currency, WooPaymentsOrderDataService $order_data_service ): array {
		$meta = array();

		if ( isset( $charge['outcome']['risk_level'] ) ) {
			$meta['_charge_risk_level'] = (string) $charge['outcome']['risk_level'];
		}

		$meta = array_merge(
			$meta,
			$order_data_service->get_settlement_exchange_rate_order_meta(
				$order,
				$charge,
				$account_default_currency
			)
		);

		return array_merge( $meta, self::fraud_outcome_meta( $intent, $charge ) );
	}

	/**
	 * Get payment-method backfill meta from a completed charge.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $charge Native Charge response.
	 * @param WC_Order            $order  Order being charged.
	 * @return array<string,string>
	 */
	public static function completed_charge_payment_method_backfill_meta( array $charge, WC_Order $order ): array {
		if ( ! self::order_has_placeholder_payment_method_details( $order ) ) {
			return array();
		}

		$meta                   = array();
		$balance_transaction_id = self::balance_transaction_id( $charge['balance_transaction'] ?? null );
		if ( '' === (string) $order->get_meta( '_wcpay_payment_transaction_id', true ) && '' !== $balance_transaction_id ) {
			$meta['_wcpay_payment_transaction_id'] = $balance_transaction_id;
		}

		$payment_method_details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] )
			? $charge['payment_method_details']
			: array();
		if ( ! empty( $payment_method_details ) ) {
			$encoded_payment_method_details = wp_json_encode( self::payment_method_details_for_order_meta( $payment_method_details ) );
			if ( false !== $encoded_payment_method_details ) {
				$meta['_wcpay_payment_method_details'] = $encoded_payment_method_details;
			}
		}

		return $meta;
	}

	/**
	 * Get legacy-compatible order meta for a started PaymentIntent.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @param WC_Order            $order  Order being charged.
	 * @return array<string,string>
	 */
	public static function started_payment_meta( array $intent, WC_Order $order ): array {
		$status = isset( $intent['status'] ) ? (string) $intent['status'] : 'requires_action';

		return array(
			'_intention_status'             => $status,
			'_wcpay_intent_currency'        => (string) $order->get_currency(),
			'_wcpay_payment_transaction_id' => '',
			'_wcpay_fraud_meta_box_type'    => self::is_card_intent( $intent ) ? 'payment_started' : 'not_card',
		);
	}

	/**
	 * Get lifecycle meta from a completed capture response.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed>         $intent                   Native PaymentIntent response.
	 * @param WC_Order                    $order                    Order being captured.
	 * @param string                      $account_mode             WooPayments account mode.
	 * @param string                      $account_default_currency WooPayments account default currency.
	 * @param WooPaymentsOrderDataService $order_data_service       WooPayments order data service.
	 * @return array<string,string>
	 */
	public static function completed_capture_meta( array $intent, WC_Order $order, string $account_mode, string $account_default_currency, WooPaymentsOrderDataService $order_data_service ): array {
		$charge = self::latest_charge( $intent );
		if ( empty( $charge ) ) {
			return array();
		}

		$meta = array(
			'_wcpay_intent_currency' => strtolower( isset( $intent['currency'] ) ? (string) $intent['currency'] : (string) $order->get_currency() ),
			'_wcpay_mode'            => $account_mode,
		);

		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		if ( '' !== $charge_id ) {
			$meta['_charge_id'] = $charge_id;
		}

		return array_merge(
			$meta,
			self::completed_charge_meta( $intent, $charge, $order, $account_default_currency, $order_data_service, false )
		);
	}

	/**
	 * Get legacy-compatible order meta for a failed capture response.
	 *
	 * @since 11.0.0
	 *
	 * @return array<string,string>
	 */
	public static function failed_capture_meta(): array {
		return array(
			'_intention_status' => 'requires_capture',
		);
	}

	/**
	 * Get WooPayments fraud-outcome order meta for a completed charge.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @param array<string,mixed> $charge Native Charge response.
	 * @return array<string,string>
	 */
	public static function fraud_outcome_meta( array $intent, array $charge ): array {
		$metadata      = isset( $intent['metadata'] ) && is_array( $intent['metadata'] ) ? $intent['metadata'] : array();
		$fraud_outcome = isset( $metadata['fraud_outcome'] ) ? (string) $metadata['fraud_outcome'] : '';
		$is_card       = self::is_card_charge( $charge );

		if ( in_array( $fraud_outcome, array( 'allow', 'block', 'review' ), true ) ) {
			return array(
				'_wcpay_fraud_outcome_status' => $fraud_outcome,
				'_wcpay_fraud_meta_box_type'  => $is_card ? 'allow' : 'not_card',
			);
		}

		return $is_card ? array() : array( '_wcpay_fraud_meta_box_type' => 'not_card' );
	}

	/**
	 * Get the merchant transaction fee from a native charge.
	 *
	 * @since 11.0.0
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
		if ( null !== $application_fee_amount && '' !== $currency ) {
			return (string) self::interpret_stripe_amount( (int) $application_fee_amount, $currency );
		}

		return '';
	}

	/**
	 * Get the merchant net amount from a native charge.
	 *
	 * @since 11.0.0
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
	 * @since 11.0.0
	 *
	 * @param int    $amount   Stripe integer amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function interpret_stripe_amount( int $amount, string $currency ): float {
		return self::is_zero_decimal_currency( $currency ) ? (float) $amount : (float) $amount / 100;
	}

	/**
	 * Get the human-readable payment method title.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $payment_method_details Payment method details from the charge.
	 * @param string              $account_country         Connected account country.
	 * @return string
	 */
	public static function payment_method_title( array $payment_method_details, string $account_country = '' ): string {
		$wallet_type = $payment_method_details['card']['wallet']['type'] ?? null;
		$type        = isset( $payment_method_details['type'] ) && is_scalar( $payment_method_details['type'] ) ? (string) $payment_method_details['type'] : '';

		switch ( $wallet_type ) {
			case 'link':
				return __( 'Link', 'woocommerce' );

			case 'apple_pay':
				return __( 'Apple Pay', 'woocommerce' );

			case 'google_pay':
				return __( 'Google Pay', 'woocommerce' );
		}

		if ( 'card' === $type && isset( $payment_method_details['card'] ) && is_array( $payment_method_details['card'] ) ) {
			return self::card_payment_method_title( $payment_method_details['card'] );
		}

		$registered_title = self::registered_payment_method_title( $type, $payment_method_details, $account_country );
		if ( '' !== $registered_title ) {
			return $registered_title;
		}

		$non_card_title = self::non_card_payment_method_title( $type );
		if ( '' !== $non_card_title ) {
			return $non_card_title;
		}

		return __( 'Credit / Debit Cards', 'woocommerce' );
	}

	/**
	 * Normalize payment method details before storing them in WooPayments order meta.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $payment_method_details Payment method details from the charge.
	 * @return array<string,mixed>
	 */
	public static function payment_method_details_for_order_meta( array $payment_method_details ): array {
		if ( isset( $payment_method_details['sepa_debit'] ) && is_array( $payment_method_details['sepa_debit'] ) ) {
			unset( $payment_method_details['sepa_debit']['expected_debit_date'] );
		}

		return $payment_method_details;
	}

	/**
	 * Tell whether the order still has an empty payment-method details placeholder.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order Order being charged.
	 * @return bool
	 */
	private static function order_has_placeholder_payment_method_details( WC_Order $order ): bool {
		$payment_method_details = $order->get_meta( '_wcpay_payment_method_details', true );
		if ( '' === $payment_method_details || null === $payment_method_details || array() === $payment_method_details ) {
			return true;
		}

		if ( ! is_string( $payment_method_details ) ) {
			return false;
		}

		$decoded_payment_method_details = json_decode( $payment_method_details, true );

		return array() === $decoded_payment_method_details;
	}

	/**
	 * Get legacy-compatible Multibanco voucher order meta from an intent.
	 *
	 * @since 11.0.0
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
	 * Get a WooPayments-compatible payment success order note.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @param string   $account_mode           WooPayments account mode.
	 * @return string
	 */
	public static function payment_success_note( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '', string $account_mode = 'live' ): string {
		unset( $account_mode );

		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
		$transaction_id   = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url  = self::transaction_url( $intent_id, $charge_id, $balance_transaction_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: charged amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount,
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Get a WooPayments-compatible payment authorization order note.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @return string
	 */
	public static function payment_authorized_note( WC_Order $order, string $intent_id, string $charge_id ): string {
		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
		$transaction_id   = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url  = self::transaction_url( $intent_id, $charge_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount,
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Get a WooPayments-compatible payment-started order note.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @return string
	 */
	public static function payment_started_note( WC_Order $order, string $intent_id ): string {
		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: started amount, %2$s: WooPayments, %3$s: payment intent ID. */
				__( 'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'code'   => '<code>',
				)
			),
			$formatted_amount,
			'WooPayments',
			$intent_id
		);
	}

	/**
	 * Get a WooPayments-compatible capture success order note.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order                  Order object.
	 * @param string   $intent_id              Payment intent ID.
	 * @param string   $charge_id              Charge ID.
	 * @param string   $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	public static function capture_success_note( WC_Order $order, string $intent_id, string $charge_id, string $balance_transaction_id = '' ): string {
		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
		$transaction_id   = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url  = self::transaction_url( $intent_id, $charge_id, $balance_transaction_id );

		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: captured amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount,
			'WooPayments',
			$transaction_id,
			$transaction_url
		);
	}

	/**
	 * Get a WooPayments-compatible capture failure order note.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order     Order object.
	 * @param string   $intent_id Payment intent ID.
	 * @param string   $charge_id Charge ID.
	 * @param string   $message   Failure message.
	 * @return string
	 */
	public static function capture_failed_note( WC_Order $order, string $intent_id, string $charge_id, string $message ): string {
		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
		$transaction_id   = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url  = self::transaction_url( $intent_id, $charge_id, (string) $order->get_meta( '_wcpay_payment_transaction_id', true ) );
		$note             = sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: authorized amount, %2$s: WooPayments, %3$s: transaction ID, %4$s: transaction URL. */
				__( 'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).', 'woocommerce' ),
				array(
					'strong' => '<strong>',
					'a'      => '' !== $transaction_url ? '<a href="%4$s" target="_blank" rel="noopener noreferrer">' : '<code>',
				)
			),
			$formatted_amount,
			'WooPayments',
			$transaction_id,
			$transaction_url
		);

		if ( '' !== $message ) {
			$note .= ' ' . $message;
		}

		return $note;
	}

	/**
	 * Build a WooPayments-compatible provider refund note.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext $context    Payment context.
	 * @param string         $refund_id  Provider refund ID.
	 * @param bool           $is_pending Whether the provider refund is pending.
	 * @return string
	 */
	public static function refund_note( PaymentContext $context, string $refund_id, bool $is_pending ): string {
		$order        = $context->get_order();
		$payment_data = $context->get_payment_data();

		return wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_created_refund_note(
			$order,
			isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0,
			$order->get_currency(),
			$refund_id,
			isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '',
			$is_pending
		);
	}

	/**
	 * Compose WooPayments-compatible local effects for a successful provider refund.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext      $context Payment context.
	 * @param array<string,mixed> $result  Provider refund response.
	 * @return array<string,mixed>
	 */
	public static function compose_refund_effect_data( PaymentContext $context, array $result ): array {
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
			PaymentOutcome::DATA_REFUND_NOTE => self::refund_note( $context, $refund_id, 'pending' === $refund_status ),
		);
	}

	/**
	 * Get the WooPayments transaction details URL.
	 *
	 * @since 11.0.0
	 *
	 * @param string $intent_id              Payment intent ID.
	 * @param string $charge_id              Charge ID.
	 * @param string $balance_transaction_id Balance transaction ID.
	 * @return string
	 */
	public static function transaction_url( string $intent_id, string $charge_id, string $balance_transaction_id = '' ): string {
		if ( '' === $intent_id && '' === $charge_id && '' === $balance_transaction_id ) {
			return '';
		}

		if ( false !== strpos( $intent_id, 'seti_' ) ) {
			return '';
		}

		$params = array(
			'id' => '' !== $intent_id ? $intent_id : $charge_id,
		);

		return Utils::wc_payments_legacy_admin_url(
			'/payments/transactions/details',
			$params
		);
	}

	/**
	 * Tell whether the currency uses zero decimal places at the provider boundary.
	 *
	 * @since 11.0.0
	 *
	 * @param string $currency Currency code.
	 * @return bool
	 */
	public static function is_zero_decimal_currency( string $currency ): bool {
		return in_array(
			strtolower( $currency ),
			array(
				'bif',
				'clp',
				'djf',
				'gnf',
				'jpy',
				'kmf',
				'krw',
				'mga',
				'pyg',
				'rwf',
				'vnd',
				'vuv',
				'xaf',
				'xof',
				'xpf',
			),
			true
		);
	}

	/**
	 * Tell whether a native charge was made with a card payment method.
	 *
	 * @param array<string,mixed> $charge Native Charge response.
	 * @return bool
	 */
	private static function is_card_charge( array $charge ): bool {
		$payment_method_details = isset( $charge['payment_method_details'] ) && is_array( $charge['payment_method_details'] )
			? $charge['payment_method_details']
			: array();

		return 'card' === (string) ( $payment_method_details['type'] ?? '' );
	}

	/**
	 * Tell whether a native intent was started with a card payment method.
	 *
	 * @param array<string,mixed> $intent Native PaymentIntent response.
	 * @return bool
	 */
	private static function is_card_intent( array $intent ): bool {
		$charge = self::latest_charge( $intent );
		if ( ! empty( $charge ) && self::is_card_charge( $charge ) ) {
			return true;
		}

		$payment_method = isset( $intent['payment_method'] ) && is_array( $intent['payment_method'] )
			? $intent['payment_method']
			: array();
		if ( 'card' === (string) ( $payment_method['type'] ?? '' ) ) {
			return true;
		}

		$payment_method_types = isset( $intent['payment_method_types'] ) && is_array( $intent['payment_method_types'] )
			? array_map( 'strval', $intent['payment_method_types'] )
			: array();
		if ( in_array( 'card', $payment_method_types, true ) ) {
			return true;
		}

		$payment_method_options = isset( $intent['payment_method_options'] ) && is_array( $intent['payment_method_options'] )
			? $intent['payment_method_options']
			: array();

		return array_key_exists( 'card', $payment_method_options );
	}

	/**
	 * Get the human-readable card payment method title from charge details.
	 *
	 * @param array<string,mixed> $card_details Card details from the charge.
	 * @return string
	 */
	private static function card_payment_method_title( array $card_details ): string {
		$funding_types = array(
			'credit'  => __( 'credit', 'woocommerce' ),
			'debit'   => __( 'debit', 'woocommerce' ),
			'prepaid' => __( 'prepaid', 'woocommerce' ),
			'unknown' => __( 'unknown', 'woocommerce' ),
		);

		$networks     = isset( $card_details['networks'] ) && is_array( $card_details['networks'] ) ? $card_details['networks'] : array();
		$available    = isset( $networks['available'] ) && is_array( $networks['available'] ) ? $networks['available'] : array();
		$card_network = $card_details['display_brand'] ?? $card_details['network'] ?? $networks['preferred'] ?? $available[0] ?? 'card';
		$card_network = str_replace( '_', ' ', (string) $card_network );
		$funding      = isset( $card_details['funding'] ) && isset( $funding_types[ (string) $card_details['funding'] ] )
			? $funding_types[ (string) $card_details['funding'] ]
			: $funding_types['unknown'];

		return sprintf(
			/* translators: %1$s: card brand, %2$s: card funding type. */
			__( '%1$s %2$s card', 'woocommerce' ),
			ucwords( $card_network ),
			$funding
		);
	}

	/**
	 * Get a legacy-compatible non-card payment method title.
	 *
	 * @param string $type Stripe payment method details type.
	 * @return string
	 */
	private static function non_card_payment_method_title( string $type ): string {
		$titles = array(
			'affirm'            => __( 'Affirm', 'woocommerce' ),
			'afterpay_clearpay' => __( 'Afterpay', 'woocommerce' ),
			'alipay'            => __( 'Alipay', 'woocommerce' ),
			'amazon_pay'        => __( 'Amazon Pay', 'woocommerce' ),
			'au_becs_debit'     => __( 'BECS Direct Debit', 'woocommerce' ),
			'bancontact'        => __( 'Bancontact', 'woocommerce' ),
			'eps'               => __( 'EPS', 'woocommerce' ),
			'grabpay'           => __( 'GrabPay', 'woocommerce' ),
			'ideal'             => __( 'iDEAL', 'woocommerce' ),
			'klarna'            => __( 'Klarna', 'woocommerce' ),
			'link'              => __( 'Link', 'woocommerce' ),
			'multibanco'        => __( 'Multibanco', 'woocommerce' ),
			'p24'               => __( 'Przelewy24', 'woocommerce' ),
			'sepa_debit'        => __( 'SEPA Direct Debit', 'woocommerce' ),
			'wechat_pay'        => __( 'WeChat Pay', 'woocommerce' ),
		);

		return $titles[ $type ] ?? '';
	}

	/**
	 * Get the WooPayments gateway ID that should own an order with these charge details.
	 *
	 * @param string $payment_method_type Effective provider payment-method type.
	 * @return string
	 */
	private static function payment_method_gateway_id( string $payment_method_type ): string {
		if ( in_array( $payment_method_type, array( 'card', 'link', 'apple_pay', 'google_pay' ), true ) ) {
			return OrderPaymentStore::GATEWAY_ID;
		}

		if ( '' === $payment_method_type ) {
			return '';
		}

		return OrderPaymentStore::GATEWAY_ID_PREFIX . $payment_method_type;
	}

	/**
	 * Get the provider payment-method type represented by an intent without charge details.
	 *
	 * @param array<string,mixed> $intent Provider intent response.
	 * @return string
	 */
	private static function intent_payment_method_type( array $intent ): string {
		$payment_method_options = isset( $intent['payment_method_options'] ) && is_array( $intent['payment_method_options'] )
			? array_keys( $intent['payment_method_options'] )
			: array();
		if ( ! empty( $payment_method_options ) ) {
			return sanitize_key( (string) $payment_method_options[0] );
		}

		$payment_method_types = isset( $intent['payment_method_types'] ) && is_array( $intent['payment_method_types'] )
			? array_values( $intent['payment_method_types'] )
			: array();

		return ! empty( $payment_method_types ) && is_scalar( $payment_method_types[0] )
			? sanitize_key( (string) $payment_method_types[0] )
			: '';
	}

	/**
	 * Get the stored express-checkout title, including the legacy WooPayments suffix.
	 *
	 * @param string $express_checkout_type Express-checkout payment-method type.
	 * @param string $account_country       Connected account country.
	 * @return string
	 */
	private static function express_checkout_payment_method_title( string $express_checkout_type, string $account_country ): string {
		$title = self::registered_payment_method_title(
			$express_checkout_type,
			array(
				'type'                 => $express_checkout_type,
				$express_checkout_type => array(),
			),
			$account_country
		);
		if ( '' === $title ) {
			$title = self::non_card_payment_method_title( $express_checkout_type );
		}
		if ( '' === $title ) {
			$title = __( 'Payment Request', 'woocommerce' );
		}

		/**
		 * Filters the WooPayments suffix included in stored express-checkout titles.
		 *
		 * @since 11.0.0
		 *
		 * @param string $suffix Express-checkout payment-method title suffix.
		 */
		$suffix = (string) apply_filters( 'wcpay_payment_request_payment_method_title_suffix', 'WooPayments' );

		return '' === $suffix ? $title : $title . ' (' . $suffix . ')';
	}

	/**
	 * Get a payment-method title from the native registry definitions.
	 *
	 * @param string              $type                   Stripe payment method details type.
	 * @param array<string,mixed> $payment_method_details Payment method details from the charge.
	 * @param string              $account_country        Connected account country.
	 * @return string
	 */
	private static function registered_payment_method_title( string $type, array $payment_method_details, string $account_country ): string {
		if ( '' === $type ) {
			return '';
		}

		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( $type );
		if ( null === $definition ) {
			return '';
		}

		$dynamic_title = $definition->get_title_from_charge_details( $account_country, $payment_method_details );

		return null !== $dynamic_title ? $dynamic_title : $definition->get_title( $account_country );
	}

	/**
	 * Map scalar payload values to order meta keys.
	 *
	 * @param array<string,mixed>  $payload Payload values.
	 * @param array<string,string> $key_map Source-to-meta key map.
	 * @return array<string,string>
	 */
	private static function scalar_meta_from_keys( array $payload, array $key_map ): array {
		$meta = array();

		foreach ( $key_map as $source_key => $meta_key ) {
			if ( ! isset( $payload[ $source_key ] ) || ! is_scalar( $payload[ $source_key ] ) ) {
				continue;
			}

			$meta[ $meta_key ] = (string) $payload[ $source_key ];
		}

		return $meta;
	}
}
