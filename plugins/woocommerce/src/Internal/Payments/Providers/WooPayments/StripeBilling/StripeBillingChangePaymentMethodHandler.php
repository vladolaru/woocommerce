<?php
/**
 * StripeBillingChangePaymentMethodHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling;

use Automattic\WooCommerce\Enums\OrderStatus;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Sends customers of a Stripe-billed subscription whose renewal failed to update their payment method, instead of paying the failed order.
 *
 * Stripe retries the pending invoice once the subscription has a new payment method, so paying the failed order
 * directly is not the flow. Port of client 11.1.0
 * `includes/subscriptions/class-wc-payments-subscription-change-payment-method-handler.php`.
 *
 * @since 11.2.0
 * @internal
 */
class StripeBillingChangePaymentMethodHandler {

	/**
	 * Subscription service, which holds the one rule for what is Stripe-billed.
	 *
	 * @var StripeBillingSubscriptionService
	 */
	private StripeBillingSubscriptionService $subscription_service;

	/**
	 * Invoice service.
	 *
	 * @var StripeBillingInvoiceService
	 */
	private StripeBillingInvoiceService $invoice_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param StripeBillingSubscriptionService $subscription_service Subscription service.
	 * @param StripeBillingInvoiceService      $invoice_service      Invoice service.
	 */
	final public function init( StripeBillingSubscriptionService $subscription_service, StripeBillingInvoiceService $invoice_service ): void {
		$this->subscription_service = $subscription_service;
		$this->invoice_service      = $invoice_service;
	}

	/**
	 * Replace the "Change payment" action of a subscription that needs a new payment method with "Update payment method".
	 *
	 * @internal
	 *
	 * @param mixed $actions      My Account > View Subscription actions.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public function update_subscription_change_payment_button( $actions, $subscription ) {
		if ( is_array( $actions ) && $subscription instanceof WC_Order && $this->does_subscription_need_payment_updated( $subscription ) ) {
			$actions['change_payment_method'] = array(
				'url'  => $this->get_subscription_update_payment_url( $subscription ),
				'name' => __( 'Update payment method', 'woocommerce' ),
			);
		}

		return $actions;
	}

	/**
	 * Point the "Pay" action of an order linked to an invoice to the update payment method page.
	 *
	 * @internal
	 *
	 * @param mixed $actions Order actions.
	 * @param mixed $order   Order.
	 * @return mixed
	 */
	public function update_order_pay_button( $actions, $order ) {
		if ( ! is_array( $actions ) || ! isset( $actions['pay'] ) || ! $order instanceof WC_Order || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return $actions;
		}

		if ( '' === $this->invoice_service->get_order_invoice_id( $order ) ) {
			return $actions;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		$subscription  = ! empty( $subscriptions ) ? array_pop( $subscriptions ) : null;

		// An order with an invoice belongs to a Stripe-billed subscription; paying it directly is never the flow.
		if ( ! $subscription ) {
			unset( $actions['pay'] );
			return $actions;
		}

		if ( $subscription instanceof WC_Order && $this->does_subscription_need_payment_updated( $subscription ) ) {
			$actions['pay']['url'] = $this->get_subscription_update_payment_url( $subscription );
		}

		return $actions;
	}

	/**
	 * Let a subscription that needs a new payment method have it updated.
	 *
	 * @internal
	 *
	 * @param mixed $can_update   Whether the payment method can be updated.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public function can_update_payment_method( $can_update, $subscription ) {
		return $this->does_subscription_need_payment_updated( $subscription ) ? true : $can_update;
	}

	/**
	 * Redirect the pay-for-order page of a failed invoice order to the update payment method page.
	 *
	 * @internal
	 */
	public function redirect_pay_for_order_to_update_payment_method(): void {
		global $wp;

		$order_pay = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;

		// There is no nonce for the "pay for order" action: the URL is long living.
		if ( ! isset( $_GET['pay_for_order'], $_GET['key'] ) || ! empty( $_GET['change_payment_method'] ) || ( ! isset( $_GET['order_id'] ) && ! $order_pay ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		$order_id  = $order_pay ? $order_pay : absint( $_GET['order_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = wc_clean( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order     = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! is_string( $order_key ) || ! hash_equals( $order->get_order_key(), $order_key ) || ! current_user_can( 'pay_for_order', $order->get_id() ) ) {
			return;
		}

		if ( '' === $this->invoice_service->get_order_invoice_id( $order ) ) {
			return;
		}

		$subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
		$subscription  = ! empty( $subscriptions ) ? array_pop( $subscriptions ) : null;

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce Subscriptions maps this meta capability.
		if ( $subscription instanceof WC_Order && current_user_can( 'edit_shop_subscription_payment_method', $subscription->get_id() ) && $this->does_subscription_need_payment_updated( $subscription ) ) {
			wp_safe_redirect( $this->get_subscription_update_payment_url( $subscription ) );
			exit;
		}
	}

	/**
	 * Retitle the change payment method page when the subscription needs a new payment method.
	 *
	 * @internal
	 *
	 * @param mixed $title        Page title.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public function change_payment_method_page_title( $title, $subscription ) {
		return $this->does_subscription_need_payment_updated( $subscription ) ? __( 'Update payment details', 'woocommerce' ) : $title;
	}

	/**
	 * Explain on the change payment method page that the last renewal failed.
	 *
	 * @internal
	 *
	 * @param mixed $message      Notice shown on the page.
	 * @param mixed $subscription Subscription.
	 * @return mixed
	 */
	public function change_payment_method_page_notice( $message, $subscription ) {
		return $this->does_subscription_need_payment_updated( $subscription )
			? __( "Your subscription's last renewal failed payment. Please update your payment details so we can reattempt payment.", 'woocommerce' )
			: $message;
	}

	/**
	 * Say on the change payment method button that the payment is retried.
	 *
	 * @internal
	 *
	 * @param mixed $button_text Button text.
	 * @return mixed
	 */
	public function change_payment_method_form_submit_text( $button_text ) {
		if ( ! isset( $_GET['change_payment_method'] ) || ! function_exists( 'wcs_get_subscription' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $button_text;
		}

		$subscription = wcs_get_subscription( wc_clean( wp_unslash( $_GET['change_payment_method'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $subscription && $this->does_subscription_need_payment_updated( $subscription ) ? __( 'Update and retry payment', 'woocommerce' ) : $button_text;
	}

	/**
	 * Tell whether a Stripe-billed subscription is on hold after its last order failed, with an invoice still pending.
	 *
	 * @param mixed $subscription Subscription.
	 * @return bool
	 */
	private function does_subscription_need_payment_updated( $subscription ): bool {
		if ( ! $subscription instanceof WC_Order || ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return false;
		}

		if ( ! $subscription->has_status( 'on-hold' ) || ! $this->subscription_service->is_wcpay_subscription( $subscription ) ) {
			return false;
		}

		$last_order = is_callable( array( $subscription, 'get_last_order' ) ) ? $subscription->get_last_order( 'all', 'any' ) : false;

		return $last_order instanceof WC_Order && $last_order->has_status( OrderStatus::FAILED ) && '' !== $this->invoice_service->get_pending_invoice_id( $subscription );
	}

	/**
	 * Get the URL of the update payment method page of a subscription.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return string
	 */
	private function get_subscription_update_payment_url( WC_Order $subscription ): string {
		return add_query_arg(
			array(
				'change_payment_method' => $subscription->get_id(),
				'_wpnonce'              => wp_create_nonce(),
			),
			$subscription->get_checkout_payment_url()
		);
	}
}
