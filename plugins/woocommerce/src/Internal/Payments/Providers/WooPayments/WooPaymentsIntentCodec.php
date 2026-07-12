<?php
/**
 * WooPaymentsIntentCodec class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use WP_Error;

/**
 * Maps explicit WooPayments provider data to neutral payment outcomes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsIntentCodec {

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
		$data[ PaymentOutcome::DATA_ERROR_CODE ]    = $error_code;
		$data[ PaymentOutcome::DATA_ERROR_MESSAGE ] = isset( $error['message'] ) ? (string) $error['message'] : '';
		$data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] = WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, $decline_code );

		return new PaymentOutcome( PaymentOutcome::STATUS_FAILED, $intent_id, '', $payment_method_id, $customer_id, $data );
	}

	/**
	 * Normalize a legacy process_payment result using a post-bridge snapshot.
	 *
	 * @param array<string,mixed>|null        $result                     Legacy process_payment result.
	 * @param WooPaymentsIntentMappingContext $context                    Post-bridge order snapshot.
	 * @param string                          $fallback_payment_method_id Fallback payment method ID.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_legacy_result( ?array $result, WooPaymentsIntentMappingContext $context, string $fallback_payment_method_id = '' ): PaymentOutcome {
		if ( null === $result ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_ERROR_CODE => 'legacy_process_payment_empty_response' )
			);
		}

		if ( 'success' !== ( $result['result'] ?? '' ) ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array( PaymentOutcome::DATA_ERROR_CODE => 'legacy_process_payment_failed' )
			);
		}

		$redirect          = isset( $result['redirect'] ) ? (string) $result['redirect'] : '';
		$payment_method_id = isset( $result['payment_method'] ) ? (string) $result['payment_method'] : $fallback_payment_method_id;
		$data              = array();

		if ( '' === $payment_method_id ) {
			$payment_method_id = $context->get_persisted_payment_method_id();
		}

		if ( array_key_exists( 'redirect', $result ) ) {
			$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $redirect;
		}

		if ( str_starts_with( $redirect, '#wcpay-confirm-' ) ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $context->get_persisted_intent_id(), $redirect, $payment_method_id, '', $data );
		}

		if ( '' !== $redirect && $redirect !== $context->get_order_received_url() ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $context->get_persisted_intent_id(), $redirect, $payment_method_id, '', $data );
		}

		$status = self::map_intention_status_to_outcome_status( $context->get_persisted_intention_status() );
		if ( '' === $status && '' === $context->get_persisted_intent_id() && 0.0 < $context->get_order_total() && '' === $redirect ) {
			$status = PaymentOutcome::STATUS_PENDING_ASYNC;
		}

		return new PaymentOutcome(
			'' === $status ? PaymentOutcome::STATUS_COMPLETED : $status,
			$context->get_persisted_intent_id(),
			$redirect,
			$payment_method_id,
			'',
			$data
		);
	}

	/**
	 * Normalize a capture result with already-composed compatibility effects.
	 *
	 * @param array<string,mixed> $result                       Provider capture result.
	 * @param string              $fallback_provider_payment_id Persisted provider payment ID.
	 * @param array<string,mixed> $effect_data                  Explicitly composed local effect data.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_capture_result( array $result, string $fallback_provider_payment_id = '', array $effect_data = array() ): PaymentOutcome {
		$status     = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$intent_id  = isset( $result['id'] ) ? (string) $result['id'] : $fallback_provider_payment_id;
		$error_code = isset( $result['error_code'] ) ? (string) $result['error_code'] : '';
		$message    = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'succeeded' === $status ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $intent_id, '', '', '', $effect_data );
		}

		if ( 'requires_capture' === $status && '' === $message ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, $intent_id, '', '', '', $effect_data );
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$intent_id,
			'',
			'',
			'',
			array_merge(
				$effect_data,
				array(
					PaymentOutcome::DATA_ERROR_CODE    => $error_code,
					PaymentOutcome::DATA_ERROR_MESSAGE => $message,
				)
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
	 * Normalize a native refund response before local enrichment.
	 *
	 * @param array<string,mixed> $result Native refund result.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_refund_result( array $result ): PaymentOutcome {
		$refund_id              = isset( $result['id'] ) ? (string) $result['id'] : '';
		$provider_status        = isset( $result['status'] ) ? (string) $result['status'] : '';
		$balance_transaction_id = self::balance_transaction_id( $result['balance_transaction'] ?? null );

		if ( ! in_array( $provider_status, array( '', 'pending', 'succeeded' ), true ) ) {
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

		$data = array( 'refund_status' => 'pending' === $provider_status ? 'pending' : 'successful' );
		if ( '' !== $balance_transaction_id ) {
			$data['refund_balance_transaction_id'] = $balance_transaction_id;
		}

		return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $refund_id, '', '', '', $data );
	}

	/**
	 * Normalize a legacy refund result.
	 *
	 * @param mixed $result Legacy refund result.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_legacy_refund_result( $result ): PaymentOutcome {
		if ( true === $result ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED );
		}

		if ( $result instanceof WP_Error ) {
			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				'',
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => $result->get_error_code(),
					PaymentOutcome::DATA_ERROR_MESSAGE => $result->get_error_message(),
				)
			);
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			'',
			'',
			'',
			'',
			array( PaymentOutcome::DATA_ERROR_CODE => 'legacy_refund_failed' )
		);
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

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$provider_payment_id,
			'',
			'',
			'',
			array(
				PaymentOutcome::DATA_ERROR_CODE            => $error_code,
				PaymentOutcome::DATA_ERROR_MESSAGE         => $exception->getMessage(),
				PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE => WooPaymentsErrorMessages::get_shopper_message( $exception->get_error_type(), $error_code, $exception->get_decline_code() ),
				'operation'                                => $operation,
			)
		);
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
	 * Tell whether a native intent status represents an authorized payment.
	 *
	 * @param string $status Intent status.
	 * @return bool
	 */
	public static function is_authorized_native_intent_status( string $status ): bool {
		return in_array( $status, array( 'succeeded', 'requires_capture', 'processing' ), true );
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
	 * @param mixed $balance_transaction Balance transaction response field.
	 * @return string
	 */
	private static function balance_transaction_id( $balance_transaction ): string {
		if ( is_string( $balance_transaction ) ) {
			return $balance_transaction;
		}

		return is_array( $balance_transaction ) && isset( $balance_transaction['id'] ) ? (string) $balance_transaction['id'] : '';
	}

	/**
	 * Get the latest charge from an intent.
	 *
	 * @param array<string,mixed> $intention Provider intent response.
	 * @return array<string,mixed>
	 */
	private static function latest_charge( array $intention ): array {
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
	private static function result_customer_id( array $intention, string $fallback ): string {
		if ( isset( $intention['customer'] ) && is_scalar( $intention['customer'] ) ) {
			return (string) $intention['customer'];
		}

		if ( isset( $intention['customer']['id'] ) && is_scalar( $intention['customer']['id'] ) ) {
			return (string) $intention['customer']['id'];
		}

		return $fallback;
	}

	/**
	 * Map a persisted intention status to a neutral outcome status.
	 *
	 * @param string $intention_status Persisted intention status.
	 * @return string
	 */
	private static function map_intention_status_to_outcome_status( string $intention_status ): string {
		switch ( $intention_status ) {
			case 'succeeded':
				return PaymentOutcome::STATUS_COMPLETED;

			case 'requires_capture':
			case 'processing':
				return PaymentOutcome::STATUS_AUTHORIZED;

			case 'requires_action':
			case 'requires_confirmation':
				return PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION;

			case 'requires_payment_method':
				return PaymentOutcome::STATUS_FAILED;

			case 'canceled':
				return PaymentOutcome::STATUS_CANCELED;
		}

		return '';
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
