<?php
/**
 * WooPaymentsIntentCodec class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;

/**
 * Maps explicit WooPayments provider data to neutral payment outcomes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsIntentCodec {

	/**
	 * Outcome data key: safe shopper-facing error message.
	 *
	 * @var string
	 */
	public const SHOPPER_ERROR_MESSAGE_KEY = 'shopper_error_message';

	/**
	 * Normalize a native intent response to a neutral payment outcome.
	 *
	 * @param array<string,mixed>             $intention Native intent response.
	 * @param WooPaymentsIntentMappingContext $context   Explicit mapping context.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_intention( array $intention, WooPaymentsIntentMappingContext $context ): PaymentOutcome {
		$intent_type        = $context->get_intent_type();
		$status             = isset( $intention['status'] ) ? (string) $intention['status'] : '';
		$intent_id          = isset( $intention['id'] ) ? (string) $intention['id'] : '';
		$payment_method_id  = self::result_payment_method_id( $intention );
		$customer_id        = self::result_customer_id( $intention, $context->get_fallback_customer_id() );
		$charge             = self::latest_charge( $intention );
		$charge_id          = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		$payment_credential = $context->get_payment_credential();
		$data               = array();

		if ( '' === $payment_method_id && isset( $charge['payment_method'] ) ) {
			$payment_method_id = (string) $charge['payment_method'];
		}

		if ( '' === $payment_method_id && '' !== $payment_credential && ! self::is_confirmation_token( $payment_credential ) ) {
			$payment_method_id = $payment_credential;
		}

		if ( '' !== $charge_id ) {
			$data['charge_id'] = $charge_id;
		}

		switch ( $status ) {
			case 'succeeded':
				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $intent_id, '', $payment_method_id, $customer_id, $data );

			case 'requires_capture':
			case 'processing':
				return new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, $intent_id, '', $payment_method_id, $customer_id, $data );

			case 'requires_action':
			case 'requires_confirmation':
				$provider_redirect = $context->get_provider_redirect_url();
				if ( '' !== $provider_redirect ) {
					$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $provider_redirect;

					return new PaymentOutcome(
						PaymentOutcome::STATUS_REQUIRES_REDIRECT,
						$intent_id,
						$provider_redirect,
						$payment_method_id,
						$customer_id,
						$data
					);
				}

				if ( self::has_multibanco_voucher( $intention ) ) {
					$checkout_redirect                              = $context->get_order_received_url();
					$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $checkout_redirect;

					return new PaymentOutcome(
						PaymentOutcome::STATUS_AUTHORIZED,
						$intent_id,
						$checkout_redirect,
						$payment_method_id,
						$customer_id,
						$data
					);
				}

				return new PaymentOutcome(
					PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
					$intent_id,
					$context->get_customer_action_redirect(),
					$payment_method_id,
					$customer_id,
					$data
				);

			case 'canceled':
				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, $intent_id, '', $payment_method_id, $customer_id, $data );
		}

		$error_key = 'si' === $intent_type ? 'last_setup_error' : 'last_payment_error';
		$error     = is_array( $intention[ $error_key ] ?? null ) ? $intention[ $error_key ] : array();

		$error_code                                 = isset( $error['code'] ) ? (string) $error['code'] : ( 'si' === $intent_type ? 'wcpay_native_setup_intent_failed' : 'wcpay_native_charge_failed' );
		$error_type                                 = isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : '';
		$decline_code                               = isset( $error['decline_code'] ) && is_string( $error['decline_code'] ) ? $error['decline_code'] : '';
		$error_message                              = isset( $error['message'] ) ? (string) $error['message'] : '';
		$data[ PaymentOutcome::DATA_ERROR_CODE ]    = $error_code;
		$data[ PaymentOutcome::DATA_ERROR_MESSAGE ] = $error_message;
		$data[ self::SHOPPER_ERROR_MESSAGE_KEY ]    = WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, $decline_code, $error_message );

		return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, $intent_id, '', $payment_method_id, $customer_id, $data );
	}

	/**
	 * Normalize a capture result.
	 *
	 * @param array<string,mixed> $result                       Provider capture result.
	 * @param string              $fallback_provider_payment_id Persisted provider payment ID.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_capture_result( array $result, string $fallback_provider_payment_id = '' ): PaymentOutcome {
		$status     = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$intent_id  = isset( $result['id'] ) ? (string) $result['id'] : $fallback_provider_payment_id;
		$error_code = isset( $result['error_code'] ) ? (string) $result['error_code'] : '';
		$message    = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'succeeded' === $status ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $intent_id );
		}

		if ( 'requires_capture' === $status && '' === $message ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, $intent_id );
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$intent_id,
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => $error_code,
				PaymentOutcome::DATA_ERROR_MESSAGE => $message,
			)
		);
	}

	/**
	 * Normalize a native capture response before local enrichment.
	 *
	 * @param array<string,mixed> $result                       Native capture result.
	 * @param string              $fallback_provider_payment_id Persisted provider payment ID.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_native_capture_result( array $result, string $fallback_provider_payment_id = '' ): PaymentOutcome {
		return self::outcome_from_capture_result( $result, $fallback_provider_payment_id );
	}

	/**
	 * Tell whether a provider refund status is a refund still on its way: recorded on the order as pending, and settled
	 * by `charge.refund.updated`.
	 *
	 * Stripe's `requires_action` refund (https://docs.stripe.com/api/refunds/object, https://docs.stripe.com/refunds#requires-action)
	 * waits for the customer's bank details and has moved no money yet, like `pending`. Client 11.1.0 records any refund its
	 * request returns and marks it pending only for `pending` (class-wc-payment-gateway-wcpay.php:3003-3009); native marks
	 * both pending (monitor ruling 2026-10-10 13:45).
	 *
	 * @since 11.2.0
	 *
	 * @param string $status Provider refund status.
	 * @return bool
	 */
	public static function is_pending_refund_status( string $status ): bool {
		return in_array( $status, array( 'pending', 'requires_action' ), true );
	}

	/**
	 * Normalize a native refund response before local enrichment.
	 *
	 * A refund that failed or was canceled maps to a failed outcome, so the order never shows a refund that moved no money;
	 * the client records it (decided improvement).
	 *
	 * @param array<string,mixed> $result Native refund result.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_refund_result( array $result ): PaymentOutcome {
		$refund_id              = isset( $result['id'] ) ? (string) $result['id'] : '';
		$provider_status        = isset( $result['status'] ) ? (string) $result['status'] : '';
		$balance_transaction_id = self::balance_transaction_id( $result['balance_transaction'] ?? null );

		if ( ! in_array( $provider_status, array( '', 'succeeded' ), true ) && ! self::is_pending_refund_status( $provider_status ) ) {
			$failure_reason = isset( $result['failure_reason'] ) ? (string) $result['failure_reason'] : '';

			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				$refund_id,
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => '' !== $failure_reason ? $failure_reason : $provider_status,
					PaymentOutcome::DATA_ERROR_MESSAGE => $failure_reason,
					'refund_status'                    => $provider_status,
					'refund_failure_reason'            => $failure_reason,
				)
			);
		}

		$data = array( 'refund_status' => self::is_pending_refund_status( $provider_status ) ? 'pending' : 'successful' );
		if ( '' !== $balance_transaction_id ) {
			$data['refund_balance_transaction_id'] = $balance_transaction_id;
		}

		return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $refund_id, '', '', '', $data );
	}

	/**
	 * Normalize a cancel authorization result.
	 *
	 * @param array<string,mixed> $result Provider cancel result.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_cancel_result( array $result ): PaymentOutcome {
		$status    = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$intent_id = isset( $result['id'] ) ? (string) $result['id'] : '';
		$message   = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'canceled' === $status ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, $intent_id );
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$intent_id,
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => 'legacy_cancel_authorization_failed',
				PaymentOutcome::DATA_ERROR_MESSAGE => $message,
			)
		);
	}

	/**
	 * Build a failed outcome from an explicit transport exception.
	 *
	 * @param string                  $operation           Operation name.
	 * @param WooPaymentsApiException $exception           Native transport exception.
	 * @param string                  $provider_payment_id Provider payment ID.
	 * @return PaymentOutcome
	 */
	public static function failed_transport_outcome( string $operation, WooPaymentsApiException $exception, string $provider_payment_id = '' ): PaymentOutcome {
		$error_code = '' !== $exception->get_error_code() ? $exception->get_error_code() : 'wcpay_native_transport_failed';

		if ( '' === $provider_payment_id ) {
			// Keep the declined intent id from the error envelope so the failed
			// order stays traceable and matchable from the transaction side.
			$provider_payment_id = $exception->get_payment_intent_id();
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$provider_payment_id,
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE    => $error_code,
				PaymentOutcome::DATA_ERROR_MESSAGE => $exception->getMessage(),
				self::SHOPPER_ERROR_MESSAGE_KEY    => self::shopper_message_for_exception( $error_code, $exception ),
				'operation'                        => $operation,
				// The admin capture and cancel routes answer with the platform's status and minimum amount, as the client does.
				'http_code'                        => $exception->get_http_code(),
				'extra_details'                    => self::amount_too_small_details( $error_code, $exception ),
			)
		);
	}

	/**
	 * Get the minimum amount an amount_too_small platform error carries, as the client's Amount_Too_Small_Exception exposes it.
	 *
	 * @param string                  $error_code Platform error code.
	 * @param WooPaymentsApiException $exception  Platform error.
	 * @return array<string,int|string> minimum_amount and minimum_amount_currency, or empty for any other error.
	 */
	private static function amount_too_small_details( string $error_code, WooPaymentsApiException $exception ): array {
		$error_data = $exception->get_error_data();
		if ( 'amount_too_small' !== $error_code || ! isset( $error_data['minimum_amount'], $error_data['currency'] ) || ! is_numeric( $error_data['minimum_amount'] ) || ! is_string( $error_data['currency'] ) ) {
			return array();
		}

		return array(
			'minimum_amount'          => (int) $error_data['minimum_amount'],
			'minimum_amount_currency' => strtoupper( $error_data['currency'] ),
		);
	}

	/**
	 * Resolve the shopper-facing message for a failed transport exception.
	 *
	 * Mirrors the plugin's get_filtered_error_message ordering: amount_too_small
	 * renders (and caches) the platform's per-currency floor, amount_too_large
	 * passes the transport's redacted capture message through, everything else
	 * goes through the safe card-error mapping.
	 *
	 * @param string                  $error_code Provider error code.
	 * @param WooPaymentsApiException $exception  Transport exception.
	 * @return string
	 */
	private static function shopper_message_for_exception( string $error_code, WooPaymentsApiException $exception ): string {
		$error_data = $exception->get_error_data();

		if ( 'amount_too_small' === $error_code && isset( $error_data['minimum_amount'], $error_data['currency'] ) && is_numeric( $error_data['minimum_amount'] ) && is_string( $error_data['currency'] ) ) {
			$minimum_amount = (int) $error_data['minimum_amount'];
			$currency       = $error_data['currency'];

			WooPaymentsCurrencyUtils::cache_minimum_amount( $currency, $minimum_amount );

			return WooPaymentsErrorMessages::get_amount_too_small_message( $minimum_amount, $currency );
		}

		if ( 'amount_too_large' === $error_code ) {
			return $exception->getMessage();
		}

		return WooPaymentsErrorMessages::get_shopper_message( $exception->get_error_type(), $error_code, $exception->get_decline_code(), $exception->getMessage() );
	}

	/**
	 * Get a payment method ID from a provider intent response.
	 *
	 * @param array<string,mixed> $result Intent response.
	 * @return string
	 */
	public static function result_payment_method_id( array $result ): string {
		if ( isset( $result['payment_method'] ) && is_string( $result['payment_method'] ) ) {
			return $result['payment_method'];
		}

		if ( isset( $result['payment_method'] ) && is_array( $result['payment_method'] ) && isset( $result['payment_method']['id'] ) ) {
			return (string) $result['payment_method']['id'];
		}

		return '';
	}

	/**
	 * Whether the intent has taken or reserved the shopper's money: succeeded, authorized for capture, or processing.
	 *
	 * @param string $status Intent status.
	 * @return bool
	 */
	public static function holds_money( string $status ): bool {
		return in_array( $status, array( 'succeeded', 'requires_capture', 'processing' ), true );
	}

	/**
	 * Tell whether a charge of the intent was fully refunded: `refunded`, or `amount_refunded` reaching its amount.
	 *
	 * A refunded or disputed PaymentIntent keeps its `succeeded` status. The platform pins Stripe-Version 2020-08-27
	 * (wpcom `wcpay/utils/class-config.php:414-425`), so a listed or retrieved intent carries its charges, each with
	 * `refunded` (true once fully refunded), `amount_refunded` and `disputed`. Every charge is read, so their order does not
	 * matter.
	 *
	 * @param array<string,mixed> $intent A PaymentIntent as the platform returns it.
	 * @return bool
	 */
	public static function is_fully_refunded( array $intent ): bool {
		foreach ( self::get_intent_charges( $intent ) as $charge ) {
			$amount          = is_numeric( $charge['amount'] ?? null ) ? (int) $charge['amount'] : 0;
			$amount_refunded = is_numeric( $charge['amount_refunded'] ?? null ) ? (int) $charge['amount_refunded'] : 0;
			if ( true === ( $charge['refunded'] ?? false ) || ( 0 < $amount && $amount_refunded >= $amount ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether a charge of the intent is disputed.
	 *
	 * @param array<string,mixed> $intent A PaymentIntent as the platform returns it.
	 * @return bool
	 */
	public static function is_disputed( array $intent ): bool {
		foreach ( self::get_intent_charges( $intent ) as $charge ) {
			if ( true === ( $charge['disputed'] ?? false ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the charges of an intent that are arrays.
	 *
	 * @param array<string,mixed> $intent A PaymentIntent as the platform returns it.
	 * @return array<int,array<string,mixed>>
	 */
	private static function get_intent_charges( array $intent ): array {
		$charges = isset( $intent['charges']['data'] ) && is_array( $intent['charges']['data'] ) ? $intent['charges']['data'] : array();

		return array_values( array_filter( $charges, 'is_array' ) );
	}

	/**
	 * Build the legacy-compatible frontend confirmation hash from explicit values.
	 *
	 * @param int    $order_id           Order ID.
	 * @param string $client_secret      Intent client secret.
	 * @param string $nonce              Customer-action nonce.
	 * @param string $intent_type        Intent type.
	 * @param string $confirmation_token Confirmation token.
	 * @return string
	 */
	public static function confirmation_redirect_for( int $order_id, string $client_secret, string $nonce, string $intent_type = 'pi', string $confirmation_token = '' ): string {
		$redirect = '#wcpay-confirm-' . $intent_type . ':' . $order_id . ':' . $client_secret . ':' . $nonce;

		return '' === $confirmation_token ? $redirect : $redirect . ':' . $confirmation_token;
	}

	/**
	 * Tell whether an intent needs the local customer-action confirmation hash.
	 *
	 * @param array<string,mixed> $intention            Provider intent response.
	 * @param string              $provider_redirect_url Sanitized provider redirect URL.
	 * @return bool
	 */
	public static function requires_confirmation_redirect( array $intention, string $provider_redirect_url ): bool {
		$status = isset( $intention['status'] ) ? (string) $intention['status'] : '';

		return in_array( $status, array( 'requires_action', 'requires_confirmation' ), true )
			&& '' === $provider_redirect_url
			&& ! self::has_multibanco_voucher( $intention );
	}

	/**
	 * Extract the raw provider redirect URL from an intent.
	 *
	 * The runtime boundary is responsible for sanitizing this value exactly once.
	 *
	 * @param array<string,mixed> $intention Provider intent response.
	 * @return string
	 */
	public static function raw_next_action_redirect_url( array $intention ): string {
		$next_action = isset( $intention['next_action'] ) && is_array( $intention['next_action'] ) ? $intention['next_action'] : array();
		if ( 'redirect_to_url' !== (string) ( $next_action['type'] ?? '' ) ) {
			return '';
		}

		$redirect_to_url = isset( $next_action['redirect_to_url'] ) && is_array( $next_action['redirect_to_url'] ) ? $next_action['redirect_to_url'] : array();
		$url             = $redirect_to_url['url'] ?? '';

		return is_scalar( $url ) ? (string) $url : '';
	}

	/**
	 * Tell whether a credential is a Stripe confirmation token.
	 *
	 * @param string $payment_credential Credential value.
	 * @return bool
	 */
	public static function is_confirmation_token( string $payment_credential ): bool {
		return 0 === strpos( $payment_credential, 'ctoken_' );
	}

	/**
	 * Get a balance transaction ID from a provider response field.
	 *
	 * @since 11.2.0
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
	 * Get the latest charge from an intent.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $intention Provider intent response.
	 * @return array<string,mixed>
	 */
	public static function latest_charge( array $intention ): array {
		$charges = isset( $intention['charges']['data'] ) && is_array( $intention['charges']['data'] ) ? $intention['charges']['data'] : array();
		$charge  = empty( $charges ) ? array() : end( $charges );

		return is_array( $charge ) ? $charge : array();
	}

	/**
	 * Get a customer ID from an intent.
	 *
	 * @param array<string,mixed> $intention Provider intent response.
	 * @param string              $fallback  Fallback customer ID.
	 * @return string
	 */
	public static function result_customer_id( array $intention, string $fallback ): string {
		if ( isset( $intention['customer'] ) && is_scalar( $intention['customer'] ) ) {
			return (string) $intention['customer'];
		}

		if ( isset( $intention['customer']['id'] ) && is_scalar( $intention['customer']['id'] ) ) {
			return (string) $intention['customer']['id'];
		}

		return $fallback;
	}

	/**
	 * Tell whether an intent contains Multibanco voucher details.
	 *
	 * @param array<string,mixed> $intention Provider intent response.
	 * @return bool
	 */
	private static function has_multibanco_voucher( array $intention ): bool {
		$next_action = isset( $intention['next_action'] ) && is_array( $intention['next_action'] ) ? $intention['next_action'] : array();

		return 'multibanco_display_details' === (string) ( $next_action['type'] ?? '' )
			&& is_array( $next_action['multibanco_display_details'] ?? null );
	}
}
