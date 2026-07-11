<?php
/**
 * WooPaymentsIntentCodec class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use WC_Order;
use WP_Error;

/**
 * Maps WooPayments provider payloads to neutral payment outcomes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsIntentCodec {

	/**
	 * Provider-data key for saved-token Stripe payment method type.
	 *
	 * @var string
	 */
	public const PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE = 'saved_payment_method_type';

	/**
	 * WooPayments v1 client capability version represented by the native provider.
	 *
	 * @var string
	 */
	private const WCPAY_V1_CLIENT_CAPABILITY_VERSION = '10.8.0';

	/**
	 * Build the native WooPayments charge request payload.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext              $context            Payment context.
	 * @param string                      $payment_credential Payment method or confirmation token.
	 * @param string                      $customer_id        Customer ID.
	 * @param bool                        $is_recurring       Whether the order requires recurring payment handling.
	 * @param WooPaymentsAccountService   $account_service    WooPayments account service.
	 * @param WooPaymentsOrderDataService $order_data_service WooPayments order data service.
	 * @return array<string,mixed>
	 */
	public static function charge_request_data( PaymentContext $context, string $payment_credential, string $customer_id, bool $is_recurring, WooPaymentsAccountService $account_service, WooPaymentsOrderDataService $order_data_service ): array {
		$order                = $context->get_order();
		$payment_data         = $context->get_payment_data();
		$provider_data        = $context->get_provider_data();
		$is_renewal           = ! empty( $provider_data['scheduled_subscription_payment'] );
		$is_recurring         = $is_renewal || $is_recurring;
		$payment_type         = $is_recurring ? 'recurring' : 'single';
		$subscription_payment = $is_renewal ? 'renewal' : ( $is_recurring ? 'initial' : 'no' );
		$payment_method_types = self::payment_method_types_for_request( $context, (string) $order->get_currency(), $account_service );
		$request_data         = array(
			'amount'               => $order_data_service->prepare_amount( (float) $order->get_total(), (string) $order->get_currency() ),
			'capture_method'       => ! $is_renewal && 'yes' === $account_service->get_gateway_setting( 'manual_capture', 'no' ) ? 'manual' : 'automatic',
			'currency'             => strtolower( (string) $order->get_currency() ),
			'customer'             => $customer_id,
			'metadata'             => self::metadata_from_order( $order, $payment_type, $subscription_payment ),
			'payment_method_types' => $payment_method_types,
		);

		if ( self::is_confirmation_token( $payment_credential ) ) {
			$request_data['confirmation_token'] = $payment_credential;
		} else {
			$request_data['payment_method'] = $payment_credential;
		}

		if ( ! empty( $provider_data['cvc_confirmation'] ) ) {
			$request_data['cvc_confirmation'] = (string) $provider_data['cvc_confirmation'];
		}

		if ( $is_renewal ) {
			$request_data['off_session'] = true;
			$renewal_mandate             = isset( $provider_data['renewal_mandate'] ) ? (string) $provider_data['renewal_mandate'] : '';
			if ( '' !== $renewal_mandate ) {
				$request_data['mandate'] = $renewal_mandate;
			}
		}

		if ( ! $is_renewal && ( ! empty( $payment_data['save_payment_method'] ) || $is_recurring ) ) {
			$request_data['setup_future_usage'] = 'off_session';
		}

		if ( self::is_mandate_data_required( $payment_method_types ) ) {
			$request_data['mandate_data'] = self::mandate_data();
		}

		if ( self::is_redirect_return_url_required( $payment_method_types ) ) {
			$request_data['return_url'] = self::redirect_return_url( $order );
		}

		if ( self::is_using_saved_payment_token( $payment_data ) && ! preg_match( '/^(card_|src_)/', $payment_credential ) ) {
			$billing_details = $order_data_service->get_billing_data_from_order( $order );
			if ( ! empty( $billing_details ) ) {
				$request_data['payment_method_update_data'] = array(
					'billing_details' => $billing_details,
				);
			}
		}

		return WooPaymentsPlatformPaymentMethodContext::from_provider_data( $provider_data )->apply_to_request_data( $request_data );
	}

	/**
	 * Build the native WooPayments setup-intent request payload.
	 *
	 * @since 11.0.0
	 *
	 * @param PaymentContext            $context            Payment context.
	 * @param string                    $payment_credential Payment method or confirmation token.
	 * @param string                    $customer_id        Customer ID.
	 * @param bool                      $is_recurring       Whether the order requires recurring payment handling.
	 * @param WooPaymentsAccountService $account_service    WooPayments account service.
	 * @return array<string,mixed>
	 */
	public static function setup_intent_request_data( PaymentContext $context, string $payment_credential, string $customer_id, bool $is_recurring, WooPaymentsAccountService $account_service ): array {
		$payment_type         = $is_recurring ? 'recurring' : 'single';
		$subscription_payment = 'recurring' === $payment_type ? 'initial' : 'no';
		$request_data         = array(
			'customer'             => $customer_id,
			'metadata'             => self::metadata_from_order( $context->get_order(), $payment_type, $subscription_payment ),
			'payment_method_types' => self::payment_method_types_for_request( $context, (string) $context->get_order()->get_currency(), $account_service ),
		);

		if ( ! self::is_confirmation_token( $payment_credential ) ) {
			$request_data['payment_method'] = $payment_credential;
		}

		return WooPaymentsPlatformPaymentMethodContext::from_provider_data( $context->get_provider_data() )->apply_to_request_data( $request_data );
	}

	/**
	 * Build the WooPayments metadata payload for an order.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order                Order being charged.
	 * @param string   $payment_type         Payment type slug.
	 * @param string   $subscription_payment Subscription payment type.
	 * @return array<string,mixed>
	 */
	public static function metadata_from_order( WC_Order $order, string $payment_type = 'single', string $subscription_payment = 'no' ): array {
		WooPaymentsPaymentType::register_legacy_alias();

		$payment_type         = 'recurring' === $payment_type ? WooPaymentsPaymentType::recurring() : WooPaymentsPaymentType::single();
		$subscription_payment = in_array( $subscription_payment, array( 'initial', 'renewal' ), true ) ? $subscription_payment : 'no';
		$metadata             = array(
			'customer_name'        => trim( sanitize_text_field( $order->get_billing_first_name() ) . ' ' . sanitize_text_field( $order->get_billing_last_name() ) ),
			'customer_email'       => sanitize_email( $order->get_billing_email() ),
			'site_url'             => esc_url( get_site_url() ),
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'order_key'            => $order->get_order_key(),
			'payment_type'         => $payment_type,
			'checkout_type'        => $order->get_created_via(),
			'client_version'       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'subscription_payment' => $subscription_payment,
		);

		if ( 'no' !== $subscription_payment ) {
			$metadata['payment_context'] = 'regular_subscription';
		}

		/**
		 * Filters the WooPayments metadata created from an order.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $metadata Metadata being sent to WooPayments.
		 * @param WC_Order            $order    Order object.
		 * @param WooPaymentsPaymentType $payment_type Payment type.
		 */
		$metadata = apply_filters( 'wcpay_metadata_from_order', $metadata, $order, $payment_type );

		return is_array( $metadata ) ? $metadata : array();
	}

	/**
	 * Normalize a native intent response to a neutral payment outcome.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intention Native intent response.
	 * @param WC_Order            $order     Order being processed.
	 * @param array<string,mixed> $args      Mapping context.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_intention( array $intention, WC_Order $order, array $args = array() ): PaymentOutcome {
		$intent_type             = isset( $args['intent_type'] ) ? (string) $args['intent_type'] : 'pi';
		$status                  = isset( $intention['status'] ) ? (string) $intention['status'] : '';
		$intent_id               = isset( $intention['id'] ) ? (string) $intention['id'] : '';
		$client_secret           = isset( $intention['client_secret'] ) ? (string) $intention['client_secret'] : '';
		$payment_credential      = isset( $args['payment_credential'] ) ? (string) $args['payment_credential'] : '';
		$payment_method_id       = self::result_payment_method_id( $intention );
		$fallback_customer       = isset( $args['fallback_customer_id'] ) ? (string) $args['fallback_customer_id'] : '';
		$customer_id             = isset( $intention['customer'] ) ? (string) $intention['customer'] : $fallback_customer;
		$account_mode            = isset( $args['account_mode'] ) ? (string) $args['account_mode'] : 'live';
		$confirmation_token      = isset( $args['confirmation_token'] ) ? (string) $args['confirmation_token'] : '';
		$charge                  = WooPaymentsOrderEffects::latest_charge( $intention );
		$charge_id               = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		$multibanco_voucher_meta = WooPaymentsOrderEffects::multibanco_voucher_meta( $intention );
		$meta                    = array(
			'_wcpay_intent_currency' => strtoupper( 'si' === $intent_type ? (string) $order->get_currency() : ( isset( $intention['currency'] ) ? (string) $intention['currency'] : (string) $order->get_currency() ) ),
			'_wcpay_mode'            => $account_mode,
		);
		$meta                    = array_merge( $meta, $multibanco_voucher_meta );

		if ( '' === $payment_method_id && isset( $charge['payment_method'] ) ) {
			$payment_method_id = (string) $charge['payment_method'];
		}

		if ( '' === $payment_method_id && '' !== $payment_credential && ! self::is_confirmation_token( $payment_credential ) ) {
			$payment_method_id = $payment_credential;
		}

		if ( '' !== $charge_id ) {
			$meta['_charge_id'] = $charge_id;
		}

		$balance_transaction_id = WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null );
		if ( 'pi' === $intent_type ) {
			$meta['_wcpay_payment_transaction_id'] = $balance_transaction_id;
		}

		if ( isset( $charge['outcome']['risk_level'] ) ) {
			$meta['_charge_risk_level'] = (string) $charge['outcome']['risk_level'];
		}

		if ( 'succeeded' === $status && isset( $args['completed_meta'] ) && is_array( $args['completed_meta'] ) ) {
			$meta = array_merge( $meta, $args['completed_meta'] );
		}

		$outcome_data = array( PaymentOutcome::DATA_META => $meta );
		if ( '' !== $charge_id ) {
			$outcome_data['charge_id'] = $charge_id;
		}

		if ( 'succeeded' === $status && 'pi' === $intent_type ) {
			$outcome_data[ PaymentOutcome::DATA_NOTE ]      = WooPaymentsOrderEffects::payment_success_note(
				$order,
				$intent_id,
				$charge_id,
				$balance_transaction_id,
				$account_mode
			);
			$outcome_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS;
		}

		switch ( $status ) {
			case 'succeeded':
				return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $intent_id, '', $payment_method_id, $customer_id, $outcome_data );

			case 'requires_capture':
			case 'processing':
				$outcome_data[ PaymentOutcome::DATA_META ]['_intention_status'] = $status;
				if ( 'pi' === $intent_type && '' !== $intent_id ) {
					$outcome_data[ PaymentOutcome::DATA_NOTE ]      = WooPaymentsOrderEffects::payment_authorized_note( $order, $intent_id, $charge_id );
					$outcome_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED;
				}

				return new PaymentOutcome( PaymentOutcome::STATUS_AUTHORIZED, $intent_id, '', $payment_method_id, $customer_id, $outcome_data );

			case 'requires_action':
			case 'requires_confirmation':
				if ( 'pi' === $intent_type ) {
					$meta                                      = array_merge( $meta, WooPaymentsOrderEffects::started_payment_meta( $intention, $order ) );
					$outcome_data[ PaymentOutcome::DATA_META ] = $meta;
					if ( '' !== $intent_id ) {
						$outcome_data[ PaymentOutcome::DATA_NOTE ]      = WooPaymentsOrderEffects::payment_started_note( $order, $intent_id );
						$outcome_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED;
					}
				}

				$next_action_redirect = self::next_action_redirect_url( $intention );
				if ( '' !== $next_action_redirect ) {
					$outcome_data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $next_action_redirect;

					return new PaymentOutcome(
						PaymentOutcome::STATUS_REQUIRES_REDIRECT,
						$intent_id,
						$next_action_redirect,
						$payment_method_id,
						$customer_id,
						$outcome_data
					);
				}

				if ( ! empty( $multibanco_voucher_meta ) ) {
					$checkout_redirect                                      = $order->get_checkout_order_received_url();
					$outcome_data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $checkout_redirect;
					$outcome_data[ PaymentOutcome::DATA_META ]              = $meta;

					return new PaymentOutcome(
						PaymentOutcome::STATUS_AUTHORIZED,
						$intent_id,
						$checkout_redirect,
						$payment_method_id,
						$customer_id,
						$outcome_data
					);
				}

				return new PaymentOutcome(
					PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION,
					$intent_id,
					self::confirmation_redirect_for( $order, $client_secret, $intent_type, $confirmation_token ),
					$payment_method_id,
					$customer_id,
					$outcome_data
				);

			case 'canceled':
				return new PaymentOutcome( PaymentOutcome::STATUS_CANCELED, $intent_id, '', $payment_method_id, $customer_id, $outcome_data );
		}

		$error_key = 'si' === $intent_type ? 'last_setup_error' : 'last_payment_error';
		$error     = is_array( $intention[ $error_key ] ?? null ) ? $intention[ $error_key ] : array();

		$outcome_data[ PaymentOutcome::DATA_ERROR_CODE ]    = isset( $error['code'] ) ? (string) $error['code'] : ( 'si' === $intent_type ? 'wcpay_native_setup_intent_failed' : 'wcpay_native_charge_failed' );
		$outcome_data[ PaymentOutcome::DATA_ERROR_MESSAGE ] = isset( $error['message'] ) ? (string) $error['message'] : '';

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$intent_id,
			'',
			$payment_method_id,
			$customer_id,
			$outcome_data
		);
	}

	/**
	 * Build a WooPayments lifecycle event from a native intent response.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $intention Native intent response.
	 * @param WC_Order            $order     Order being updated.
	 * @param array<string,mixed> $args      Mapping context.
	 * @return PaymentLifecycleEvent
	 */
	public static function lifecycle_event_from_intention( array $intention, WC_Order $order, array $args = array() ): PaymentLifecycleEvent {
		$outcome = self::outcome_from_intention( $intention, $order, $args );
		$data    = $outcome->get_data();
		$note    = isset( $data[ PaymentOutcome::DATA_NOTE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE ] )
			? $data[ PaymentOutcome::DATA_NOTE ]
			: null;

		return new PaymentLifecycleEvent(
			self::lifecycle_status_from_outcome( $outcome ),
			'' === $outcome->get_provider_payment_id() ? null : $outcome->get_provider_payment_id(),
			( new WooPaymentsPersistenceProfile() )->get_outcome_meta( $outcome ),
			array(),
			'' === $note ? null : $note,
			isset( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) && is_string( $data[ PaymentOutcome::DATA_NOTE_TYPE ] ) ? $data[ PaymentOutcome::DATA_NOTE_TYPE ] : null
		);
	}

	/**
	 * Map a neutral outcome status to a lifecycle status.
	 *
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return string
	 */
	private static function lifecycle_status_from_outcome( PaymentOutcome $outcome ): string {
		switch ( $outcome->get_status() ) {
			case PaymentOutcome::STATUS_COMPLETED:
			case PaymentOutcome::STATUS_NO_EXTERNAL_PAYMENT:
				return PaymentLifecycleEvent::STATUS_COMPLETED;

			case PaymentOutcome::STATUS_AUTHORIZED:
				return PaymentLifecycleEvent::STATUS_AUTHORIZED;

			case PaymentOutcome::STATUS_FAILED:
				return PaymentLifecycleEvent::STATUS_FAILED;

			case PaymentOutcome::STATUS_CANCELED:
				return PaymentLifecycleEvent::STATUS_CANCELED;

			case PaymentOutcome::STATUS_PENDING_ASYNC:
			case PaymentOutcome::STATUS_REQUIRES_REDIRECT:
			case PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION:
				return PaymentLifecycleEvent::STATUS_STARTED;
		}

		return PaymentLifecycleEvent::STATUS_FAILED;
	}

	/**
	 * Normalize a legacy process_payment result.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed>|null $result                     Legacy process_payment result.
	 * @param WC_Order                 $order                      Order being processed.
	 * @param string                   $fallback_payment_method_id Fallback payment method ID.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_legacy_result( ?array $result, WC_Order $order, string $fallback_payment_method_id = '' ): PaymentOutcome {
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

		$redirect              = isset( $result['redirect'] ) ? (string) $result['redirect'] : '';
		$payment_method_id     = isset( $result['payment_method'] ) ? (string) $result['payment_method'] : $fallback_payment_method_id;
		$fresh_order           = wc_get_order( $order->get_id() );
		$order                 = $fresh_order instanceof WC_Order ? $fresh_order : $order;
		$provider_payment_id   = (string) $order->get_meta( '_intent_id', true );
		$stored_payment_method = (string) $order->get_meta( '_payment_method_id', true );
		$intention_status      = (string) $order->get_meta( '_intention_status', true );
		$data                  = array();

		if ( '' === $payment_method_id ) {
			$payment_method_id = $stored_payment_method;
		}

		if ( array_key_exists( 'redirect', $result ) ) {
			$data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] = $redirect;
		}

		if ( str_starts_with( $redirect, '#wcpay-confirm-' ) ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $provider_payment_id, $redirect, $payment_method_id, '', $data );
		}

		if ( '' !== $redirect && ! self::is_order_received_redirect( $order, $redirect ) ) {
			return new PaymentOutcome( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $provider_payment_id, $redirect, $payment_method_id, '', $data );
		}

		$status = self::map_intention_status_to_outcome_status( $intention_status );
		if ( '' === $status && '' === $provider_payment_id && 0.0 < (float) $order->get_total() && '' === $redirect ) {
			$status = PaymentOutcome::STATUS_PENDING_ASYNC;
		}

		return new PaymentOutcome(
			'' === $status ? PaymentOutcome::STATUS_COMPLETED : $status,
			$provider_payment_id,
			$redirect,
			$payment_method_id,
			'',
			$data
		);
	}

	/**
	 * Normalize a capture result.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed>         $result                   Legacy or native capture result.
	 * @param PaymentContext              $context                  Payment context.
	 * @param string                      $account_mode             WooPayments account mode.
	 * @param string                      $account_default_currency WooPayments account default currency.
	 * @param WooPaymentsOrderDataService $order_data_service       WooPayments order data service.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_capture_result( array $result, PaymentContext $context, string $account_mode, string $account_default_currency, WooPaymentsOrderDataService $order_data_service ): PaymentOutcome {
		$status = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$meta   = 'succeeded' === $status
			? WooPaymentsOrderEffects::completed_capture_meta( $result, $context->get_order(), $account_mode, $account_default_currency, $order_data_service )
			: array();

		return self::outcome_from_capture_result_with_meta( $result, $context, $meta );
	}

	/**
	 * Normalize a native capture response before fallible local enrichment.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $result  Native capture response.
	 * @param PaymentContext      $context Payment context.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_native_capture_result( array $result, PaymentContext $context ): PaymentOutcome {
		return self::outcome_from_capture_result_with_meta( $result, $context, array() );
	}

	/**
	 * Normalize capture status and notes with already-composed metadata.
	 *
	 * @param array<string,mixed>  $result  Provider capture response.
	 * @param PaymentContext       $context Payment context.
	 * @param array<string,string> $meta    Completed capture metadata.
	 * @return PaymentOutcome
	 */
	private static function outcome_from_capture_result_with_meta( array $result, PaymentContext $context, array $meta ): PaymentOutcome {
		$status     = isset( $result['status'] ) ? (string) $result['status'] : 'failed';
		$intent_id  = isset( $result['id'] ) ? (string) $result['id'] : '';
		$error_code = isset( $result['error_code'] ) ? (string) $result['error_code'] : '';
		$message    = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'succeeded' === $status ) {
			$data                   = empty( $meta ) ? array() : array( PaymentOutcome::DATA_META => $meta );
			$charge                 = WooPaymentsOrderEffects::latest_charge( $result );
			$charge_id              = isset( $charge['id'] ) ? (string) $charge['id'] : '';
			$balance_transaction_id = WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null );

			$data[ PaymentOutcome::DATA_NOTE ]      = WooPaymentsOrderEffects::capture_success_note(
				$context->get_order(),
				$intent_id,
				$charge_id,
				$balance_transaction_id
			);
			$data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS;

			return new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $intent_id, '', '', '', $data );
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
				PaymentOutcome::DATA_META          => WooPaymentsOrderEffects::failed_capture_meta(),
				PaymentOutcome::DATA_NOTE          => WooPaymentsOrderEffects::capture_failed_note(
					$context->get_order(),
					$intent_id,
					self::failed_capture_charge_id( $result, $context->get_order() ),
					$message
				),
				PaymentOutcome::DATA_NOTE_TYPE     => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_FAILED,
			)
		);
	}

	/**
	 * Normalize a native refund response.
	 *
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $result  Native refund result.
	 * @param PaymentContext      $context Payment context.
	 * @return PaymentOutcome
	 */
	public static function outcome_from_refund_result( array $result, PaymentContext $context ): PaymentOutcome {
		$refund_id              = isset( $result['id'] ) ? (string) $result['id'] : '';
		$provider_status        = isset( $result['status'] ) ? (string) $result['status'] : '';
		$refund_status          = 'pending' === $provider_status ? 'pending' : 'successful';
		$balance_transaction_id = WooPaymentsOrderEffects::balance_transaction_id( $result['balance_transaction'] ?? null );

		if ( ! in_array( $provider_status, array( '', 'pending', 'succeeded' ), true ) ) {
			$failure_reason = isset( $result['failure_reason'] ) ? (string) $result['failure_reason'] : '';
			$error_message  = sprintf(
				/* translators: %1$s: refund status, %2$s: failure reason. */
				__( 'The refund returned status "%1$s". Reason: %2$s', 'woocommerce' ),
				$provider_status,
				'' !== $failure_reason ? $failure_reason : __( 'No reason provided.', 'woocommerce' )
			);

			return new PaymentOutcome(
				PaymentOutcome::STATUS_FAILED,
				$refund_id,
				'',
				'',
				'',
				array(
					PaymentOutcome::DATA_ERROR_CODE    => '' !== $failure_reason ? $failure_reason : $provider_status,
					PaymentOutcome::DATA_ERROR_MESSAGE => $error_message,
					'refund_status'                    => $provider_status,
				)
			);
		}

		$data = array(
			PaymentOutcome::DATA_ORDER_META  => array( '_wcpay_refund_status' => $refund_status ),
			PaymentOutcome::DATA_REFUND_META => array( '_wcpay_refund_id' => $refund_id ),
			PaymentOutcome::DATA_REFUND_NOTE => WooPaymentsOrderEffects::refund_note( $context, $refund_id, 'pending' === $refund_status ),
			'refund_status'                  => $refund_status,
		);

		if ( '' !== $balance_transaction_id ) {
			$data[ PaymentOutcome::DATA_REFUND_META ]['_wcpay_refund_transaction_id'] = $balance_transaction_id;
			$data['refund_balance_transaction_id']                                    = $balance_transaction_id;
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_COMPLETED,
			$refund_id,
			'',
			'',
			'',
			$data
		);
	}

	/**
	 * Normalize a legacy refund result.
	 *
	 * @since 11.0.0
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
	 * @since 11.0.0
	 *
	 * @param array<string,mixed> $result Legacy or native cancel result.
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
	 * Build a failed outcome from a transport exception.
	 *
	 * @since 11.0.0
	 *
	 * @param string                  $operation           Operation name.
	 * @param WooPaymentsApiException $exception           Native transport exception.
	 * @param WC_Order|null           $order               Order object.
	 * @param string                  $provider_payment_id Provider payment ID.
	 * @return PaymentOutcome
	 */
	public static function failed_transport_outcome( string $operation, WooPaymentsApiException $exception, ?WC_Order $order = null, string $provider_payment_id = '' ): PaymentOutcome {
		$data = array(
			PaymentOutcome::DATA_ERROR_CODE    => '' !== $exception->get_error_code()
				? $exception->get_error_code()
				: 'wcpay_native_transport_failed',
			PaymentOutcome::DATA_ERROR_MESSAGE => $exception->getMessage(),
			'operation'                        => $operation,
		);

		if ( 'capture' === $operation && $order instanceof WC_Order ) {
			$data[ PaymentOutcome::DATA_META ]      = WooPaymentsOrderEffects::failed_capture_meta();
			$data[ PaymentOutcome::DATA_NOTE ]      = WooPaymentsOrderEffects::capture_failed_note(
				$order,
				$provider_payment_id,
				(string) $order->get_meta( '_charge_id', true ),
				$exception->getMessage()
			);
			$data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_FAILED;
		}

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$provider_payment_id,
			'',
			'',
			'',
			$data
		);
	}

	/**
	 * Get a payment method ID from a provider intent response.
	 *
	 * @since 11.0.0
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
	 * @since 11.0.0
	 *
	 * @param string $status Intent status.
	 * @return bool
	 */
	public static function is_authorized_native_intent_status( string $status ): bool {
		return in_array( $status, array( 'succeeded', 'requires_capture', 'processing' ), true );
	}

	/**
	 * Build the legacy-compatible frontend confirmation hash.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order              Order being charged.
	 * @param string   $client_secret      Intent client secret.
	 * @param string   $intent_type        Intent type.
	 * @param string   $confirmation_token Confirmation token.
	 * @return string
	 */
	public static function confirmation_redirect_for( WC_Order $order, string $client_secret, string $intent_type = 'pi', string $confirmation_token = '' ): string {
		$redirect = '#wcpay-confirm-' . $intent_type . ':' . $order->get_id() . ':' . $client_secret . ':' . wp_create_nonce( 'wcpay_update_order_status_nonce' );

		if ( '' !== $confirmation_token ) {
			$redirect .= ':' . $confirmation_token;
		}

		return $redirect;
	}

	/**
	 * Map a persisted WooPayments intention status to a neutral payment outcome.
	 *
	 * @param string $intention_status WooPayments intention status.
	 * @return string Empty string when the status does not imply a different outcome.
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
	 * Get the charge ID for a failed capture response.
	 *
	 * @param array<string,mixed> $result Native or legacy capture result.
	 * @param WC_Order            $order  Order being captured.
	 * @return string
	 */
	private static function failed_capture_charge_id( array $result, WC_Order $order ): string {
		$charge = WooPaymentsOrderEffects::latest_charge( $result );
		if ( isset( $charge['id'] ) && '' !== (string) $charge['id'] ) {
			return (string) $charge['id'];
		}

		return (string) $order->get_meta( '_charge_id', true );
	}

	/**
	 * Tell whether a redirect URL is the order received URL.
	 *
	 * @param WC_Order $order    Order object.
	 * @param string   $redirect Redirect URL.
	 * @return bool
	 */
	private static function is_order_received_redirect( WC_Order $order, string $redirect ): bool {
		return '' !== $redirect && $redirect === $order->get_checkout_order_received_url();
	}

	/**
	 * Get the provider redirect URL from an intent next action.
	 *
	 * @param array<string,mixed> $intention Native intent response.
	 * @return string
	 */
	private static function next_action_redirect_url( array $intention ): string {
		$next_action = isset( $intention['next_action'] ) && is_array( $intention['next_action'] ) ? $intention['next_action'] : array();
		if ( 'redirect_to_url' !== (string) ( $next_action['type'] ?? '' ) ) {
			return '';
		}

		$redirect_to_url = isset( $next_action['redirect_to_url'] ) && is_array( $next_action['redirect_to_url'] ) ? $next_action['redirect_to_url'] : array();
		$url             = $redirect_to_url['url'] ?? '';

		return is_scalar( $url ) ? esc_url_raw( (string) $url ) : '';
	}

	/**
	 * Get Stripe payment method types for a native WooPayments request.
	 *
	 * @param PaymentContext            $context         Payment context.
	 * @param string                    $currency        Order currency.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @return array<int,string>
	 */
	private static function payment_method_types_for_request( PaymentContext $context, string $currency, WooPaymentsAccountService $account_service ): array {
		$provider_data             = $context->get_provider_data();
		$saved_payment_method_type = isset( $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] ) && is_scalar( $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ] )
			? (string) $provider_data[ self::PROVIDER_DATA_SAVED_PAYMENT_METHOD_TYPE ]
			: '';

		if ( '' !== $saved_payment_method_type ) {
			return array( $saved_payment_method_type );
		}

		$split_gateway_payment_method_type = self::payment_method_type_from_gateway_id( $context->get_gateway_id() );
		if ( '' !== $split_gateway_payment_method_type ) {
			return array( $split_gateway_payment_method_type );
		}

		$submitted_types = $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_DATA_KEY ] ?? array();
		$express_context = isset( $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] ) && is_scalar( $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ] )
			? (string) $provider_data[ WooPaymentsExpressPaymentMethodTypes::PROVIDER_CONTEXT_KEY ]
			: 'checkout';
		$allowed_types   = WooPaymentsExpressPaymentMethodTypes::get_allowed_payment_method_types_for_account( $account_service, $express_context, $currency );
		$validated_types = WooPaymentsExpressPaymentMethodTypes::validate_submitted_payment_method_types( $submitted_types, $allowed_types );

		return empty( $validated_types ) ? array( WooPaymentsExpressPaymentMethodTypes::STRIPE_TYPE_CARD ) : $validated_types;
	}

	/**
	 * Get the Stripe payment method type represented by a split WooPayments gateway ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	private static function payment_method_type_from_gateway_id( string $gateway_id ): string {
		if ( 0 !== strpos( $gateway_id, OrderPaymentStore::GATEWAY_ID_PREFIX ) ) {
			return '';
		}

		$payment_method_id = substr( $gateway_id, strlen( OrderPaymentStore::GATEWAY_ID_PREFIX ) );
		$definition        = ( new WooPaymentsPaymentMethodRegistry() )->get( $payment_method_id );

		return null === $definition ? '' : $definition->get_stripe_payment_method_type();
	}

	/**
	 * Tell whether the selected Stripe method requires customer mandate acceptance data.
	 *
	 * @param array<int,string> $payment_method_types Stripe payment method types.
	 * @return bool
	 */
	private static function is_mandate_data_required( array $payment_method_types ): bool {
		return in_array( 'sepa_debit', $payment_method_types, true ) || in_array( 'link', $payment_method_types, true );
	}

	/**
	 * Tell whether the selected Stripe method needs a post-authentication return URL.
	 *
	 * @param array<int,string> $payment_method_types Stripe payment method types.
	 * @return bool
	 */
	private static function is_redirect_return_url_required( array $payment_method_types ): bool {
		return in_array( 'amazon_pay', $payment_method_types, true )
			|| ( 1 === count( $payment_method_types ) && WooPaymentsExpressPaymentMethodTypes::STRIPE_TYPE_CARD !== ( $payment_method_types[0] ?? '' ) );
	}

	/**
	 * Build the order return URL used by redirect-based WooPayments methods.
	 *
	 * @param WC_Order $order Order being processed.
	 * @return string
	 */
	private static function redirect_return_url( WC_Order $order ): string {
		return wp_sanitize_redirect(
			esc_url_raw(
				add_query_arg(
					array(
						'wc_payment_method' => OrderPaymentStore::GATEWAY_ID,
						'_wpnonce'          => wp_create_nonce( 'wcpay_process_redirect_order_nonce' ),
					),
					$order->get_checkout_order_received_url()
				)
			)
		);
	}

	/**
	 * Get Stripe mandate acceptance data for deferred server-side confirmation.
	 *
	 * @return array<string,mixed>
	 */
	private static function mandate_data(): array {
		return array(
			'customer_acceptance' => array(
				'type'   => 'online',
				'online' => array(
					'ip_address' => \WC_Geolocation::get_ip_address(),
					'user_agent' => self::mandate_user_agent(),
				),
			),
		);
	}

	/**
	 * Build the WooPayments mandate user-agent string.
	 *
	 * @return string
	 */
	private static function mandate_user_agent(): string {
		return 'WooCommerce Payments/' . self::WCPAY_V1_CLIENT_CAPABILITY_VERSION . '; ' . get_bloginfo( 'url' );
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
	 * Tell whether a credential is a Stripe confirmation token.
	 *
	 * @since 11.0.0
	 *
	 * @param string $payment_credential Credential value.
	 * @return bool
	 */
	public static function is_confirmation_token( string $payment_credential ): bool {
		return 0 === strpos( $payment_credential, 'ctoken_' );
	}
}
