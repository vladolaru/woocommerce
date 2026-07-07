<?php
/**
 * WooPaymentsOrderEffects class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Throwable;
use WC_Order;

/**
 * Composes WooPayments-compatible order metadata, titles, and notes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderEffects {

	/**
	 * Apply payment-method details to the order before completion or customer action.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order            $order  Order being charged.
	 * @param array<string,mixed> $result Native PaymentIntent response.
	 */
	public static function apply_payment_method_display_details( WC_Order $order, array $result ): void {
		$charge                 = self::latest_charge( $result );
		$payment_method_details = is_array( $charge['payment_method_details'] ?? null ) ? $charge['payment_method_details'] : array();
		$wallet_type            = $payment_method_details['card']['wallet']['type'] ?? null;

		if ( ! empty( $payment_method_details ) ) {
			$encoded_payment_method_details = wp_json_encode( $payment_method_details );
			if ( false !== $encoded_payment_method_details ) {
				$order->update_meta_data( '_wcpay_payment_method_details', $encoded_payment_method_details );
			}
		}

		if ( 'link' !== $wallet_type && isset( $payment_method_details['card']['last4'] ) ) {
			$order->update_meta_data( 'last4', (string) $payment_method_details['card']['last4'] );
			if ( isset( $payment_method_details['card']['brand'] ) ) {
				$order->update_meta_data( '_card_brand', (string) $payment_method_details['card']['brand'] );
			}
		}

		if ( is_string( $wallet_type ) && '' !== $wallet_type ) {
			$order->update_meta_data( '_wcpay_express_checkout_payment_method', $wallet_type );
		}

		$order->set_payment_method_title( self::payment_method_title( $payment_method_details ) );
		$order->save();
	}

	/**
	 * Persist setup-intent details needed by the post-authentication AJAX callback.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order             $order             Order being charged.
	 * @param string               $setup_intent_id   SetupIntent ID.
	 * @param string               $payment_method_id Payment method ID.
	 * @param string               $customer_id       Customer ID.
	 * @param array<string,string> $meta              SetupIntent meta.
	 */
	public static function persist_setup_intent_details( WC_Order $order, string $setup_intent_id, string $payment_method_id, string $customer_id, array $meta ): void {
		if ( '' !== $setup_intent_id ) {
			$order->set_transaction_id( $setup_intent_id );
			$order->update_meta_data( '_intent_id', $setup_intent_id );
		}

		if ( '' !== $payment_method_id ) {
			$order->update_meta_data( '_payment_method_id', $payment_method_id );
		}

		if ( '' !== $customer_id ) {
			$order->update_meta_data( '_stripe_customer_id', $customer_id );
		}

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();
	}

	/**
	 * Add fee details for a successful native capture response.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order                    $order              Order being captured.
	 * @param array<string,mixed>         $result             Native capture result.
	 * @param WooPaymentsOrderDataService $order_data_service WooPayments order data service.
	 */
	public static function maybe_add_capture_fee_breakdown_note( WC_Order $order, array $result, WooPaymentsOrderDataService $order_data_service ): void {
		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( 'succeeded' !== $status ) {
			return;
		}

		$order_data_service->add_fee_breakdown_note_from_intent( $order, $result, false );
	}

	/**
	 * Get the submitted payment method or saved payment token.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext          $context       Payment context.
	 * @param WooPaymentsTokenService $token_service WooPayments token service.
	 * @return string
	 */
	public static function payment_credential_from_context( PaymentContext $context, WooPaymentsTokenService $token_service ): string {
		$payment_data  = $context->get_payment_data();
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		if ( '' !== $payment_token && 'new' !== $payment_token ) {
			if ( ! empty( $context->get_provider_data()['scheduled_subscription_payment'] ) ) {
				return $token_service->resolve_payment_method_id_from_order_token_id( $payment_token, $context->get_order() );
			}

			return $token_service->resolve_payment_method_id_from_token_id( $payment_token, $context->get_order()->get_user_id() );
		}

		return $context->get_payment_method_id();
	}

	/**
	 * Attach an existing saved token to the order.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext          $context           Payment context.
	 * @param string                  $payment_method_id Provider payment method ID.
	 * @param string                  $customer_id       WooPayments customer ID.
	 * @param WooPaymentsTokenService $token_service     WooPayments token service.
	 */
	public static function maybe_attach_saved_payment_token_to_order( PaymentContext $context, string $payment_method_id, string $customer_id, WooPaymentsTokenService $token_service ): void {
		$payment_data = $context->get_payment_data();
		if ( ! self::is_using_saved_payment_token( $payment_data ) ) {
			return;
		}

		$payment_token_id = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';
		$token            = $token_service->get_valid_token_from_token_id( $payment_token_id, $context->get_order()->get_user_id() );
		if ( null === $token ) {
			return;
		}

		$order = $context->get_order();
		$token_service->attach_token_to_order( $order, $token );
		$token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $customer_id );
	}

	/**
	 * Save a newly used card to the customer and attach it to the order.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext          $context           Payment context.
	 * @param string                  $payment_method_id Provider payment method ID.
	 * @param string                  $customer_id       WooPayments customer ID.
	 * @param WooPaymentsTokenService $token_service     WooPayments token service.
	 * @param bool                    $is_recurring      Whether the order requires saved-token persistence.
	 * @param object|null             $logger            WooPayments logger.
	 * @return PaymentOutcome|null
	 */
	public static function maybe_save_new_card_token_to_order( PaymentContext $context, string $payment_method_id, string $customer_id, WooPaymentsTokenService $token_service, bool $is_recurring, ?object $logger = null ): ?PaymentOutcome {
		$payment_data = $context->get_payment_data();
		$order        = $context->get_order();
		if ( self::is_using_saved_payment_token( $payment_data ) || ( empty( $payment_data['save_payment_method'] ) && ! $is_recurring ) ) {
			return null;
		}

		if ( '' === $payment_method_id || 0 >= $order->get_user_id() ) {
			return $is_recurring ? self::recurring_token_save_failed_outcome() : null;
		}

		try {
			$token = $token_service->get_or_create_token_for_user( $payment_method_id, $order->get_user_id() );
			if ( null !== $token ) {
				$token_service->attach_token_to_order( $order, $token );
				$token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $customer_id );

				return null;
			}
		} catch ( Throwable $exception ) {
			self::log_token_save_error( $logger, $payment_method_id, $exception );

			return $is_recurring ? self::recurring_token_save_failed_outcome() : null;
		}

		return $is_recurring ? self::recurring_token_save_failed_outcome() : null;
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
	 * @return array<string,string>
	 */
	public static function completed_charge_meta( array $intent, array $charge, WC_Order $order, string $account_default_currency, WooPaymentsOrderDataService $order_data_service ): array {
		$meta = array();

		$transaction_fee = self::transaction_fee_from_charge( $intent, $charge );
		if ( '' !== $transaction_fee ) {
			$meta['_wcpay_transaction_fee'] = $transaction_fee;
		}

		$net = self::net_from_charge( $intent, $charge, $transaction_fee );
		if ( '' !== $net ) {
			$meta['_wcpay_net'] = $net;
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

		$balance_transaction_id = self::balance_transaction_id( $charge['balance_transaction'] ?? null );
		if ( '' !== $balance_transaction_id ) {
			$meta['_wcpay_payment_transaction_id'] = $balance_transaction_id;
		}

		return array_merge(
			$meta,
			self::completed_charge_meta( $intent, $charge, $order, $account_default_currency, $order_data_service )
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
	 * Get WooPayments fraud-outcome order meta from provider metadata.
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

		if ( '' === $fraud_outcome && self::is_card_charge( $charge ) ) {
			$fraud_outcome = 'allow';
		}

		if ( ! in_array( $fraud_outcome, array( 'allow', 'block', 'review' ), true ) ) {
			return array();
		}

		return array(
			'_wcpay_fraud_outcome_status' => $fraud_outcome,
			'_wcpay_fraud_meta_box_type'  => 'allow',
		);
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
	 * @return string
	 */
	public static function payment_method_title( array $payment_method_details ): string {
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

		$non_card_title = self::non_card_payment_method_title( $type );
		if ( '' !== $non_card_title ) {
			return $non_card_title;
		}

		return __( 'Credit / Debit Cards', 'woocommerce' );
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
		$formatted_amount = wc_price( (float) $order->get_total(), array( 'currency' => $order->get_currency() ) ) . ' ' . $order->get_currency();
		$transaction_id   = '' !== $intent_id ? $intent_id : $charge_id;
		$transaction_url  = self::transaction_url( $intent_id, $charge_id, $balance_transaction_id );

		if ( 'test' === $account_mode ) {
			return sprintf(
				self::interpolated_note_text(
					/* translators: %1$s: charged amount, %2$s: WooPayments, %3$s: transaction ID. */
					__( 'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.', 'woocommerce' ),
					array(
						'strong' => '<strong>',
						'a'      => '' !== $transaction_url ? '<a href="' . $transaction_url . '" target="_blank" rel="noopener noreferrer">' : '<code>',
					)
				),
				$formatted_amount,
				'WooPayments',
				$transaction_id
			);
		}

		return sprintf(
			self::interpolated_note_text(
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
			self::interpolated_note_text(
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
			self::interpolated_note_text(
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
		$order            = $context->get_order();
		$payment_data     = $context->get_payment_data();
		$refund_amount    = isset( $payment_data['amount'] ) ? (float) $payment_data['amount'] : 0.0;
		$refund_reason    = isset( $payment_data['reason'] ) ? (string) $payment_data['reason'] : '';
		$formatted_amount = wc_price( $refund_amount, array( 'currency' => $order->get_currency() ) );
		$status_text      = $is_pending
			? sprintf(
				'<a href="https://woocommerce.com/document/woopayments/managing-money/#pending-refunds" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_html__( 'is pending', 'woocommerce' )
			)
			: esc_html__( 'was successfully processed', 'woocommerce' );
		$refund_id_markup = '<code>' . esc_html( $refund_id ) . '</code>';

		if ( '' === $refund_reason ) {
			$note = sprintf(
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: provider refund ID, %4$s: refund status. */
				__( 'A refund of %1$s %4$s using %2$s (%3$s).', 'woocommerce' ),
				$formatted_amount,
				'WooPayments',
				$refund_id_markup,
				$status_text
			);
		} else {
			$note = sprintf(
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: refund reason, %4$s: provider refund ID, %5$s: refund status. */
				__( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (%4$s)', 'woocommerce' ),
				$formatted_amount,
				'WooPayments',
				esc_html( $refund_reason ),
				$refund_id_markup,
				$status_text
			);
		}

		return wp_kses_post( $note );
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
	 * Tell whether payment data represents an existing saved WooCommerce token.
	 *
	 * @param array<string,mixed> $payment_data Payment data.
	 * @return bool
	 */
	private static function is_using_saved_payment_token( array $payment_data ): bool {
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return '' !== $payment_token && 'new' !== $payment_token;
	}

	/**
	 * Build the recurring token-save failure outcome.
	 *
	 * @return PaymentOutcome
	 */
	private static function recurring_token_save_failed_outcome(): PaymentOutcome {
		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => 'wcpay_recurring_token_save_failed',
				PaymentOutcome::DATA_ERROR_MESSAGE => __(
					'Unable to save payment method for subscription. Please try again or use a different payment method.',
					'woocommerce'
				),
			)
		);
	}

	/**
	 * Log a non-fatal token save error.
	 *
	 * @param object|null $logger            WooPayments logger.
	 * @param string      $payment_method_id Provider payment method ID.
	 * @param Throwable   $exception         Token save exception.
	 */
	private static function log_token_save_error( ?object $logger, string $payment_method_id, Throwable $exception ): void {
		if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'error' ) ) ) {
			return;
		}

		$logger->error(
			sprintf(
				'Error saving WooPayments payment method %s: %s',
				$payment_method_id,
				$exception->getMessage()
			),
			array(
				'source' => 'payment-info',
			)
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

	/**
	 * Replace simple interpolation tags with stored note HTML.
	 *
	 * @param string               $text        Note text.
	 * @param array<string,string> $element_map Element replacements.
	 * @return string
	 */
	private static function interpolated_note_text( string $text, array $element_map ): string {
		foreach ( $element_map as $tag => $opening_tag ) {
			$closing_tag = '</' . $tag . '>';
			if ( preg_match( '/^<(\w+)/', $opening_tag, $matches ) ) {
				$closing_tag = '</' . $matches[1] . '>';
			}

			$text = str_replace( '<' . $tag . '>', $opening_tag, $text );
			$text = str_replace( '</' . $tag . '>', $closing_tag, $text );
		}

		return $text;
	}
}
