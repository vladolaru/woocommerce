<?php
/**
 * WooPaymentsOrderEffectApplier class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Throwable;
use WC_Order;
use WC_Payment_Token;

/**
 * Applies WooPayments-specific order, token, and note effects after provider transport completes.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsOrderEffectApplier {

	/**
	 * WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

	/**
	 * Initialize the effect applier.
	 *
	 * @internal
	 *
	 * @param WooPaymentsTokenService     $token_service      WooPayments token service.
	 * @param WooPaymentsOrderDataService $order_data_service WooPayments order data service.
	 * @param WooPaymentsAccountService   $account_service    WooPayments account service.
	 * @param WooPaymentsLegacyRuntime    $legacy_runtime     WooPayments legacy runtime.
	 */
	final public function init( WooPaymentsTokenService $token_service, WooPaymentsOrderDataService $order_data_service, WooPaymentsAccountService $account_service, WooPaymentsLegacyRuntime $legacy_runtime ): void {
		$this->token_service      = $token_service;
		$this->order_data_service = $order_data_service;
		$this->account_service    = $account_service;
		$this->legacy_runtime     = $legacy_runtime;
	}

	/**
	 * Apply a typed WooPayments effect plan.
	 *
	 * @param PaymentContext             $context Payment context.
	 * @param PaymentOutcome             $outcome Provider outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    WooPayments effect plan.
	 * @return PaymentOutcome
	 */
	public function apply( PaymentContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		switch ( $plan->get_type() ) {
			case WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT:
				if ( $plan->should_apply_token_effects() ) {
					$outcome = $this->apply_token_effects( $context, $outcome, $plan->is_recurring() );
					if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
						return $outcome;
					}
				}
					$this->apply_generic_payment_method_title( $context->get_order() );

					$charge_meta     = $this->compose_charge_payment_intent_meta( $context->get_order(), $plan->get_provider_result() );
					$display_effects = $this->compose_payment_method_display_details( $context->get_order(), $plan->get_provider_result() );
					$display_meta    = empty( $display_effects ) ? array() : $display_effects['meta'];

				return $this->merge_meta_into_outcome( $outcome, array_merge( $charge_meta, $display_meta ), $plan );

			case WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT:
				if ( $plan->should_apply_token_effects() ) {
					$outcome = $this->apply_token_effects( $context, $outcome, $plan->is_recurring() );
					if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
						return $outcome;
					}
				}
				$this->persist_setup_intent_details( $context->get_order(), $outcome, $plan->get_setup_meta() );
				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_CAPTURE:
				$outcome = $this->merge_meta_into_outcome(
					$outcome,
					$this->compose_completed_capture_meta( $context->get_order(), $plan->get_provider_result() ),
					$plan
				);
				$this->apply_capture_fee_details( $context->get_order(), $plan->get_provider_result() );
				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_REFUND:
				return $this->merge_effect_data_into_outcome(
					$outcome,
					WooPaymentsOrderEffects::compose_refund_effect_data( $context, $plan->get_provider_result() ),
					$plan
				);
		}

		return $outcome;
	}

	/**
	 * Compose charge-derived PaymentIntent metadata after the provider outcome is retained.
	 *
	 * @param WC_Order            $order  Order being charged.
	 * @param array<string,mixed> $result Provider PaymentIntent response.
	 * @return array<string,string>
	 */
	private function compose_charge_payment_intent_meta( WC_Order $order, array $result ): array {
		$status = (string) ( $result['status'] ?? '' );
		if ( ! in_array( $status, array( 'processing', 'requires_capture', 'succeeded' ), true ) ) {
			return array();
		}

		$charge                   = WooPaymentsOrderEffects::latest_charge( $result );
		$account_default_currency = $this->account_service->get_account_default_currency();

		if ( 'succeeded' === $status ) {
			return WooPaymentsOrderEffects::completed_charge_meta(
				$result,
				$charge,
				$order,
				$account_default_currency,
				$this->order_data_service
			);
		}

		return WooPaymentsOrderEffects::authorized_charge_meta(
			$result,
			$charge,
			$order,
			$account_default_currency,
			$this->order_data_service
		);
	}

	/**
	 * Compose completed capture metadata after the provider outcome is retained.
	 *
	 * @param WC_Order            $order  Order being captured.
	 * @param array<string,mixed> $result Provider capture response.
	 * @return array<string,string>
	 */
	private function compose_completed_capture_meta( WC_Order $order, array $result ): array {
		if ( 'succeeded' !== (string) ( $result['status'] ?? '' ) ) {
			return array();
		}

		return WooPaymentsOrderEffects::completed_capture_meta(
			$result,
			$order,
			$this->account_service->get_mode(),
			$this->account_service->get_account_default_currency(),
			$this->order_data_service
		);
	}

	/**
	 * Apply composed payment-method display details to an order.
	 *
	 * @param WC_Order            $order           Order being updated.
	 * @param array<string,mixed> $result          Provider PaymentIntent response.
	 * @param string              $account_country Connected account country override.
	 */
	public function apply_payment_method_display_details( WC_Order $order, array $result, string $account_country = '' ): void {
		$effects = $this->compose_payment_method_display_details( $order, $result, $account_country );
		$this->apply_composed_payment_method_display_details( $order, $effects );
	}

	/**
	 * Compose payment-method display effects for an order without writing them.
	 *
	 * @param WC_Order            $order           Order being projected.
	 * @param array<string,mixed> $result          Provider PaymentIntent response.
	 * @param string              $account_country Connected account country override.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_title:string}|array{}
	 */
	private function compose_payment_method_display_details( WC_Order $order, array $result, string $account_country = '' ): array {
		return WooPaymentsOrderEffects::compose_payment_method_display_details(
			$result,
			'' !== $account_country ? $account_country : $this->account_service->get_account_country(),
			(string) $order->get_billing_country(),
			(string) $order->get_meta( '_wcpay_express_checkout_payment_method', true )
		);
	}

	/**
	 * Apply already-composed payment-method display effects to an order.
	 *
	 * @param WC_Order                                                                                      $order   Order being updated.
	 * @param array{meta:array<string,string>,payment_method_id:string,payment_method_title:string}|array{} $effects Composed display effects.
	 */
	private function apply_composed_payment_method_display_details( WC_Order $order, array $effects ): void {
		if ( empty( $effects ) ) {
			return;
		}

		foreach ( $effects['meta'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( '' !== $effects['payment_method_id'] ) {
			$order->set_payment_method( $effects['payment_method_id'] );
		}
		$order->set_payment_method_title( $effects['payment_method_title'] );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
	}

	/**
	 * Carry composed metadata into the outcome for generic lifecycle persistence.
	 *
	 * @param PaymentOutcome             $outcome Provider outcome.
	 * @param array<string,string>       $meta    Composed provider metadata.
	 * @param WooPaymentsOrderEffectPlan $plan    Applied effect plan.
	 * @return PaymentOutcome
	 */
	private function merge_meta_into_outcome( PaymentOutcome $outcome, array $meta, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		if ( empty( $meta ) ) {
			return $outcome;
		}

		$data                              = $outcome->get_data();
		$outcome_meta                      = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] )
			? $data[ PaymentOutcome::DATA_META ]
			: array();
		$data[ PaymentOutcome::DATA_META ] = array_merge( $outcome_meta, $meta );

		$merged_outcome = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);

		return $merged_outcome->with_effect_plan( $plan );
	}

	/**
	 * Merge provider-specific effect data into a retained transport outcome.
	 *
	 * @param PaymentOutcome             $outcome     Provider transport outcome.
	 * @param array<string,mixed>        $effect_data Composed local effect data.
	 * @param WooPaymentsOrderEffectPlan $plan        Applied effect plan.
	 * @return PaymentOutcome
	 */
	private function merge_effect_data_into_outcome( PaymentOutcome $outcome, array $effect_data, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		$merged_outcome = new PaymentOutcome(
			$outcome->get_status(),
			$outcome->get_provider_payment_id(),
			$outcome->get_redirect_url(),
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			array_merge( $outcome->get_data(), $effect_data )
		);

		return $merged_outcome->with_effect_plan( $plan );
	}

	/**
	 * Apply only payment-method gateway and title effects to an order.
	 *
	 * @param WC_Order            $order           Order being updated.
	 * @param array<string,mixed> $result          Provider PaymentIntent response.
	 * @param string              $account_country Connected account country override.
	 */
	public function apply_payment_method_display_title( WC_Order $order, array $result, string $account_country = '' ): void {
		$effects = $this->compose_payment_method_display_details( $order, $result, $account_country );
		if ( empty( $effects ) ) {
			return;
		}

		if ( '' !== $effects['payment_method_id'] ) {
			$order->set_payment_method( $effects['payment_method_id'] );
		}
		$order->set_payment_method_title( $effects['payment_method_title'] );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
	}

	/**
	 * Establish the generic title used by the lifecycle completion note.
	 *
	 * @param WC_Order $order Order being processed.
	 */
	private function apply_generic_payment_method_title( WC_Order $order ): void {
		$title = __( 'WooPayments', 'woocommerce' );
		if ( $title === $order->get_payment_method_title() ) {
			return;
		}

		$order->set_payment_method_title( $title );
		$order->save();
	}

	/**
	 * Propagate final payment identity to subscriptions created from the parent order.
	 *
	 * @param WC_Order $order Parent order with finalized payment identity.
	 */
	private function sync_payment_method_to_subscriptions( WC_Order $order ): void {
		$payment_method       = $order->get_payment_method();
		$payment_method_title = $order->get_payment_method_title();

		foreach ( $this->token_service->get_related_subscriptions_for_order( $order ) as $subscription ) {
			if ( ! $subscription instanceof WC_Order ) {
				continue;
			}
			if ( $payment_method === $subscription->get_payment_method() && $payment_method_title === $subscription->get_payment_method_title() ) {
				continue;
			}

			$subscription->set_payment_method( $payment_method );
			$subscription->set_payment_method_title( $payment_method_title );
			$subscription->save();
		}
	}

	/**
	 * Apply saved or newly created token effects.
	 *
	 * @param PaymentContext $context      Payment context.
	 * @param PaymentOutcome $outcome      Provider outcome.
	 * @param bool           $is_recurring Whether recurring token persistence is required.
	 * @return PaymentOutcome
	 */
	private function apply_token_effects( PaymentContext $context, PaymentOutcome $outcome, bool $is_recurring ): PaymentOutcome {
		$payment_data      = $context->get_payment_data();
		$payment_method_id = $outcome->get_payment_method_id();
		$customer_id       = $outcome->get_customer_id();
		$order             = $context->get_order();

		try {
			if ( $this->is_using_saved_payment_token( $payment_data ) ) {
				$payment_token_id = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';
				$token            = $this->token_service->get_valid_token_from_token_id( $payment_token_id, $order->get_user_id() );
				if ( $token instanceof WC_Payment_Token && $this->attach_and_sync_token( $order, $token, $payment_method_id, $customer_id ) ) {
					return $outcome;
				}

				return $is_recurring ? $this->recurring_token_save_failed_outcome( $outcome ) : $outcome;
			}

			if ( empty( $payment_data['save_payment_method'] ) && ! $is_recurring ) {
				return $outcome;
			}

			if ( '' === $payment_method_id || 0 >= $order->get_user_id() ) {
				return $is_recurring ? $this->recurring_token_save_failed_outcome( $outcome ) : $outcome;
			}

			$token = $this->token_service->get_or_create_token_for_user( $payment_method_id, $order->get_user_id() );
			if ( $token instanceof WC_Payment_Token && $this->attach_and_sync_token( $order, $token, $payment_method_id, $customer_id ) ) {
				return $outcome;
			}
		} catch ( Throwable $exception ) {
			$this->log_token_save_error( $payment_method_id, $exception );
		}

		return $is_recurring ? $this->recurring_token_save_failed_outcome( $outcome ) : $outcome;
	}

	/**
	 * Attach a token to an order and synchronize related subscriptions.
	 *
	 * @param WC_Order         $order             Order being updated.
	 * @param WC_Payment_Token $token             WooCommerce token.
	 * @param string           $payment_method_id Provider payment method ID.
	 * @param string           $customer_id       Provider customer ID.
	 * @return bool Whether the token was attached and synchronized.
	 */
	private function attach_and_sync_token( WC_Order $order, WC_Payment_Token $token, string $payment_method_id, string $customer_id ): bool {
		if ( ! $this->token_service->attach_token_to_order( $order, $token ) ) {
			return false;
		}

		$this->token_service->sync_related_subscriptions_payment_token( $order, $token, $payment_method_id, $customer_id );

		return true;
	}

	/**
	 * Persist SetupIntent details needed by customer-authentication callbacks.
	 *
	 * @param WC_Order             $order   Order being updated.
	 * @param PaymentOutcome       $outcome SetupIntent outcome.
	 * @param array<string,string> $meta    SetupIntent metadata.
	 */
	private function persist_setup_intent_details( WC_Order $order, PaymentOutcome $outcome, array $meta ): void {
		if ( '' !== $outcome->get_provider_payment_id() ) {
			$order->set_transaction_id( $outcome->get_provider_payment_id() );
			$order->update_meta_data( '_intent_id', $outcome->get_provider_payment_id() );
		}
		if ( '' !== $outcome->get_payment_method_id() ) {
			$order->update_meta_data( '_payment_method_id', $outcome->get_payment_method_id() );
		}
		if ( '' !== $outcome->get_customer_id() ) {
			$order->update_meta_data( '_stripe_customer_id', $outcome->get_customer_id() );
		}
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();
	}

	/**
	 * Apply capture fee details when the provider capture succeeded.
	 *
	 * @param WC_Order            $order  Order being captured.
	 * @param array<string,mixed> $result Provider capture response.
	 */
	private function apply_capture_fee_details( WC_Order $order, array $result ): void {
		if ( 'succeeded' !== (string) ( $result['status'] ?? '' ) ) {
			return;
		}

		$this->order_data_service->add_fee_breakdown_note_from_intent( $order, $result, false );
	}

	/**
	 * Build a critical token failure outcome while retaining the provider identity.
	 *
	 * @param PaymentOutcome $outcome Successful provider outcome.
	 * @return PaymentOutcome
	 */
	private function recurring_token_save_failed_outcome( PaymentOutcome $outcome ): PaymentOutcome {
		$data = $outcome->get_data();
		unset( $data[ PaymentOutcome::DATA_NOTE ], $data[ PaymentOutcome::DATA_NOTE_TYPE ] );
		$data[ PaymentOutcome::DATA_ERROR_CODE ]    = 'wcpay_recurring_token_save_failed';
		$data[ PaymentOutcome::DATA_ERROR_MESSAGE ] = __(
			'Unable to save payment method for subscription. Please try again or use a different payment method.',
			'woocommerce'
		);

		return new PaymentOutcome(
			PaymentOutcome::STATUS_FAILED,
			$outcome->get_provider_payment_id(),
			'',
			$outcome->get_payment_method_id(),
			$outcome->get_customer_id(),
			$data
		);
	}

	/**
	 * Tell whether payment data identifies an existing saved token.
	 *
	 * @param array<string,mixed> $payment_data Payment data.
	 * @return bool
	 */
	private function is_using_saved_payment_token( array $payment_data ): bool {
		$payment_token = isset( $payment_data['payment_token'] ) ? (string) $payment_data['payment_token'] : '';

		return '' !== $payment_token && 'new' !== $payment_token;
	}

	/**
	 * Log a token-save failure without losing the provider outcome.
	 *
	 * @param string    $payment_method_id Provider payment method ID.
	 * @param Throwable $exception         Token-save exception.
	 */
	private function log_token_save_error( string $payment_method_id, Throwable $exception ): void {
		try {
			$logger = $this->legacy_runtime->get_logger();
			if ( ! is_object( $logger ) || ! is_callable( array( $logger, 'error' ) ) ) {
				return;
			}

			$logger->error(
				sprintf( 'Error saving WooPayments payment method %s: %s', $payment_method_id, $exception->getMessage() ),
				array( 'source' => 'payment-info' )
			);
		} catch ( Throwable $logging_exception ) {
			return;
		}
	}
}
