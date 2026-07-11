<?php
/**
 * WooPaymentsOrderEffectApplier class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentLifecycleEvent;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
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
	 * WooPayments order note service.
	 *
	 * @var WooPaymentsOrderNoteService
	 */
	private WooPaymentsOrderNoteService $note_service;

	/**
	 * WooPayments payment method registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Initialize the effect applier.
	 *
	 * @internal
	 *
	 * @param WooPaymentsTokenService          $token_service      WooPayments token service.
	 * @param WooPaymentsOrderDataService      $order_data_service WooPayments order data service.
	 * @param WooPaymentsAccountService        $account_service    WooPayments account service.
	 * @param WooPaymentsLegacyRuntime         $legacy_runtime     WooPayments legacy runtime.
	 * @param WooPaymentsOrderNoteService      $note_service       WooPayments order note service.
	 * @param WooPaymentsPaymentMethodRegistry $payment_method_registry Payment method registry.
	 */
	final public function init(
		WooPaymentsTokenService $token_service,
		WooPaymentsOrderDataService $order_data_service,
		WooPaymentsAccountService $account_service,
		WooPaymentsLegacyRuntime $legacy_runtime,
		WooPaymentsOrderNoteService $note_service,
		WooPaymentsPaymentMethodRegistry $payment_method_registry
	): void {
		$this->token_service           = $token_service;
		$this->order_data_service      = $order_data_service;
		$this->account_service         = $account_service;
		$this->legacy_runtime          = $legacy_runtime;
		$this->note_service            = $note_service;
		$this->payment_method_registry = $payment_method_registry;
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

				return $this->enrich_outcome_for_lifecycle( $context, $outcome, $plan );

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
				$outcome = $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_capture_effect_data( $context->get_order(), $outcome, $plan->get_provider_result() ),
					$plan
				);
				$this->apply_capture_fee_details( $context->get_order(), $plan->get_provider_result() );
				return $outcome;

			case WooPaymentsOrderEffectPlan::TYPE_REFUND:
				if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
					$result          = $plan->get_provider_result();
					$provider_status = isset( $result['status'] ) ? (string) $result['status'] : (string) ( $outcome->get_data()['refund_status'] ?? '' );
					$failure_reason  = isset( $result['failure_reason'] ) ? (string) $result['failure_reason'] : (string) ( $outcome->get_data()['refund_failure_reason'] ?? '' );

					return $this->merge_effect_data_into_outcome(
						$outcome,
						array( PaymentOutcome::DATA_ERROR_MESSAGE => $this->note_service->format_refund_failure_message( $provider_status, $failure_reason ) ),
						$plan
					);
				}

				$payment_data  = $context->get_payment_data();
				$result        = $plan->get_provider_result();
				$refund_id     = isset( $result['id'] ) ? (string) $result['id'] : $outcome->get_provider_payment_id();
				$rendered_note = $this->note_service->format_created_refund_note(
					$context->get_order(),
					(float) ( $payment_data['amount'] ?? 0.0 ),
					(string) $context->get_order()->get_currency(),
					$refund_id,
					(string) ( $payment_data['reason'] ?? '' ),
					'pending' === (string) ( $result['status'] ?? '' )
				);

				return $this->merge_effect_data_into_outcome(
					$outcome,
					WooPaymentsOrderEffects::compose_refund_effect_data( $result, $rendered_note ),
					$plan
				);
		}

		return $outcome;
	}

	/**
	 * Enrich an outcome for lifecycle application without persisting order effects.
	 *
	 * @param PaymentContext             $context Payment context.
	 * @param PaymentOutcome             $outcome Provider outcome.
	 * @param WooPaymentsOrderEffectPlan $plan    WooPayments effect plan.
	 * @return PaymentOutcome
	 */
	public function enrich_outcome_for_lifecycle( PaymentContext $context, PaymentOutcome $outcome, WooPaymentsOrderEffectPlan $plan ): PaymentOutcome {
		switch ( $plan->get_type() ) {
			case WooPaymentsOrderEffectPlan::TYPE_PAYMENT_INTENT:
				return $this->merge_effect_data_into_outcome(
					$outcome,
					$this->compose_payment_intent_effect_data( $context->get_order(), $plan->get_provider_result() ),
					$plan
				);

			case WooPaymentsOrderEffectPlan::TYPE_SETUP_INTENT:
				$data          = $outcome->get_data();
				$existing_meta = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] )
					? $data[ PaymentOutcome::DATA_META ]
					: array();

				return $this->merge_effect_data_into_outcome(
					$outcome,
					array( PaymentOutcome::DATA_META => array_merge( $existing_meta, $plan->get_setup_meta() ) ),
					$plan
				);
		}

		return $outcome;
	}

	/**
	 * Compose PaymentIntent metadata and notes after the provider outcome is retained.
	 *
	 * @param WC_Order            $order  Order being charged.
	 * @param array<string,mixed> $result Provider PaymentIntent response.
	 * @return array<string,mixed>
	 */
	private function compose_payment_intent_effect_data( WC_Order $order, array $result ): array {
		$status          = (string) ( $result['status'] ?? '' );
		$charge          = WooPaymentsOrderEffects::latest_charge( $result );
		$settlement_meta = ! empty( $charge ) && in_array( $status, array( 'processing', 'requires_capture', 'succeeded' ), true )
			? $this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() )
			: array();
		$display_effects = $this->compose_payment_method_display_details( $order, $result );
		$meta            = WooPaymentsOrderEffects::payment_intent_meta(
			$result,
			(string) $order->get_currency(),
			$this->account_service->get_mode(),
			$settlement_meta
		);
		if ( ! empty( $display_effects ) ) {
			$meta = array_merge( $meta, $display_effects['meta'] );
		}

		$effect_data = array( PaymentOutcome::DATA_META => $meta );
		$intent_id   = isset( $result['id'] ) ? (string) $result['id'] : '';
		$charge_id   = isset( $charge['id'] ) ? (string) $charge['id'] : '';
		if ( 'succeeded' === $status ) {
			$effect_data[ PaymentOutcome::DATA_NOTE ]      = $this->note_service->format_payment_success_note(
				$order,
				$intent_id,
				$charge_id,
				WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null )
			);
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_SUCCESS;
		} elseif ( in_array( $status, array( 'requires_capture', 'processing' ), true ) && '' !== $intent_id ) {
			$effect_data[ PaymentOutcome::DATA_NOTE ]      = $this->note_service->format_payment_authorized_note( $order, $intent_id, $charge_id );
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_AUTHORIZED;
		} elseif ( in_array( $status, array( 'requires_action', 'requires_confirmation' ), true ) && '' !== $intent_id ) {
			$effect_data[ PaymentOutcome::DATA_NOTE ]      = $this->note_service->format_payment_started_note( $order, $intent_id );
			$effect_data[ PaymentOutcome::DATA_NOTE_TYPE ] = PaymentLifecycleEvent::NOTE_TYPE_PAYMENT_STARTED;
		}

		return $effect_data;
	}

	/**
	 * Compose capture metadata and notes after the provider outcome is retained.
	 *
	 * @param WC_Order            $order  Order being captured.
	 * @param PaymentOutcome      $outcome Provider capture outcome.
	 * @param array<string,mixed> $result  Provider capture response.
	 * @return array<string,mixed>
	 */
	private function compose_capture_effect_data( WC_Order $order, PaymentOutcome $outcome, array $result ): array {
		$charge    = WooPaymentsOrderEffects::latest_charge( $result );
		$intent_id = '' !== $outcome->get_provider_payment_id() ? $outcome->get_provider_payment_id() : (string) ( $result['id'] ?? '' );
		$charge_id = isset( $charge['id'] ) ? (string) $charge['id'] : (string) $order->get_meta( '_charge_id', true );

		if ( PaymentOutcome::STATUS_COMPLETED === $outcome->get_status() && 'succeeded' === (string) ( $result['status'] ?? '' ) ) {
			$settlement_meta = empty( $charge )
				? array()
				: $this->order_data_service->get_settlement_exchange_rate_order_meta( $order, $charge, $this->account_service->get_account_default_currency() );

			return array(
				PaymentOutcome::DATA_META      => WooPaymentsOrderEffects::completed_capture_meta(
					$result,
					(string) $order->get_currency(),
					$this->account_service->get_mode(),
					$settlement_meta
				),
				PaymentOutcome::DATA_NOTE      => $this->note_service->format_capture_success_note(
					$order,
					$intent_id,
					$charge_id,
					WooPaymentsOrderEffects::balance_transaction_id( $charge['balance_transaction'] ?? null )
				),
				PaymentOutcome::DATA_NOTE_TYPE => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_SUCCESS,
			);
		}

		if ( PaymentOutcome::STATUS_AUTHORIZED === $outcome->get_status() ) {
			return array();
		}

		$message = isset( $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] )
			? (string) $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ]
			: (string) ( $result['message'] ?? '' );

		return array(
			PaymentOutcome::DATA_META      => WooPaymentsOrderEffects::failed_capture_meta(),
			PaymentOutcome::DATA_NOTE      => $this->note_service->format_capture_failed_note( $order, $intent_id, $charge_id, $message ),
			PaymentOutcome::DATA_NOTE_TYPE => PaymentLifecycleEvent::NOTE_TYPE_CAPTURE_FAILED,
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
		$this->apply_composed_payment_method_display_details( $order, $effects, $account_country );
	}

	/**
	 * Compose payment-method display effects for an order without writing them.
	 *
	 * @param WC_Order            $order           Order being projected.
	 * @param array<string,mixed> $result          Provider PaymentIntent response.
	 * @param string              $account_country Connected account country override.
	 * @return array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string}|array{}
	 */
	private function compose_payment_method_display_details( WC_Order $order, array $result, string $account_country = '' ): array {
		unset( $account_country );

		return WooPaymentsOrderEffects::compose_payment_method_display_details(
			$result,
			(string) $order->get_meta( '_wcpay_express_checkout_payment_method', true )
		);
	}

	/**
	 * Apply already-composed payment-method display effects to an order.
	 *
	 * @param WC_Order                                                                                                                                                             $order           Order being updated.
	 * @param array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string}|array{} $effects Composed display effects.
	 * @param string                                                                                                                                                               $account_country Connected account country override.
	 */
	private function apply_composed_payment_method_display_details( WC_Order $order, array $effects, string $account_country = '' ): void {
		if ( empty( $effects ) ) {
			return;
		}

		foreach ( $effects['meta'] as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( '' !== $effects['payment_method_id'] ) {
			$order->set_payment_method( $effects['payment_method_id'] );
		}
		$order->set_payment_method_title( $this->resolve_payment_method_title( $order, $effects, $account_country ) );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
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
		$order->set_payment_method_title( $this->resolve_payment_method_title( $order, $effects, $account_country ) );
		$order->save();
		$this->sync_payment_method_to_subscriptions( $order );
	}

	/**
	 * Resolve a localized title only when applying the real display effect.
	 *
	 * @param WC_Order                                                                                                                                                     $order           Order being updated.
	 * @param array{meta:array<string,string>,payment_method_id:string,payment_method_type:string,payment_method_details:array<string,mixed>,express_checkout_type:string} $effects         Pure display projection.
	 * @param string                                                                                                                                                       $account_country Connected account country override.
	 * @return string
	 */
	private function resolve_payment_method_title( WC_Order $order, array $effects, string $account_country ): string {
		$display_country = strtoupper( trim( '' !== $account_country ? $account_country : $this->account_service->get_account_country() ) );
		if ( '' === $display_country ) {
			$display_country = strtoupper( trim( (string) $order->get_billing_country() ) );
		}

		$express_type = $effects['express_checkout_type'];
		if ( '' !== $express_type ) {
			$title = $this->registered_payment_method_title(
				$express_type,
				array(
					'type'        => $express_type,
					$express_type => array(),
				),
				$display_country
			);
			if ( '' === $title ) {
				$title = $this->non_card_payment_method_title( $express_type );
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

		$details = $effects['payment_method_details'];
		if ( empty( $details ) ) {
			$type    = $effects['payment_method_type'];
			$details = array(
				'type' => $type,
				$type  => array(),
			);
		}

		return $this->payment_method_title( $details, $display_country );
	}

	/**
	 * Get a localized payment method title.
	 *
	 * @param array<string,mixed> $details         Payment method details.
	 * @param string              $account_country Connected account country.
	 * @return string
	 */
	private function payment_method_title( array $details, string $account_country ): string {
		$wallet_type = $details['card']['wallet']['type'] ?? null;
		$type        = isset( $details['type'] ) && is_scalar( $details['type'] ) ? (string) $details['type'] : '';

		switch ( $wallet_type ) {
			case 'link':
				return __( 'Link', 'woocommerce' );
			case 'apple_pay':
				return __( 'Apple Pay', 'woocommerce' );
			case 'google_pay':
				return __( 'Google Pay', 'woocommerce' );
		}

		if ( 'card' === $type && isset( $details['card'] ) && is_array( $details['card'] ) ) {
			return $this->card_payment_method_title( $details['card'] );
		}

		$title = $this->registered_payment_method_title( $type, $details, $account_country );
		if ( '' === $title ) {
			$title = $this->non_card_payment_method_title( $type );
		}

		return '' === $title ? __( 'Credit / Debit Cards', 'woocommerce' ) : $title;
	}

	/**
	 * Get a localized card title.
	 *
	 * @param array<string,mixed> $card_details Card details.
	 * @return string
	 */
	private function card_payment_method_title( array $card_details ): string {
		$funding_types = array(
			'credit'  => __( 'credit', 'woocommerce' ),
			'debit'   => __( 'debit', 'woocommerce' ),
			'prepaid' => __( 'prepaid', 'woocommerce' ),
			'unknown' => __( 'unknown', 'woocommerce' ),
		);
		$networks      = isset( $card_details['networks'] ) && is_array( $card_details['networks'] ) ? $card_details['networks'] : array();
		$available     = isset( $networks['available'] ) && is_array( $networks['available'] ) ? $networks['available'] : array();
		$card_network  = $card_details['display_brand'] ?? $card_details['network'] ?? $networks['preferred'] ?? $available[0] ?? 'card';
		$funding       = isset( $card_details['funding'], $funding_types[ (string) $card_details['funding'] ] )
			? $funding_types[ (string) $card_details['funding'] ]
			: $funding_types['unknown'];

		return sprintf(
			/* translators: %1$s: card brand, %2$s: card funding type. */
			__( '%1$s %2$s card', 'woocommerce' ),
			ucwords( str_replace( '_', ' ', (string) $card_network ) ),
			$funding
		);
	}

	/**
	 * Get a localized non-card fallback title.
	 *
	 * @param string $type Stripe payment method type.
	 * @return string
	 */
	private function non_card_payment_method_title( string $type ): string {
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
	 * Get a payment method title from the registry.
	 *
	 * @param string              $type                   Stripe payment method type.
	 * @param array<string,mixed> $payment_method_details Payment method details.
	 * @param string              $account_country        Connected account country.
	 * @return string
	 */
	private function registered_payment_method_title( string $type, array $payment_method_details, string $account_country ): string {
		if ( '' === $type ) {
			return '';
		}

		$definition = $this->payment_method_registry->get( $type );
		if ( null === $definition ) {
			return '';
		}

		$dynamic_title = $definition->get_title_from_charge_details( $account_country, $payment_method_details );

		return null !== $dynamic_title ? $dynamic_title : $definition->get_title( $account_country );
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
