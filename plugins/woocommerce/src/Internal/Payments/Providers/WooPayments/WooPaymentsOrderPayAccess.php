<?php
/**
 * WooPaymentsOrderPayAccess class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Who may use an order's pay link, and which billing email the page may hand to the visitor.
 *
 * Shared by the order-pay checkout config, the classic express checkout and WooPay sessions so they apply one rule.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsOrderPayAccess {

	/**
	 * Tell whether the current user may pay the order with the given order key.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Order key from the pay link.
	 * @return bool
	 */
	public static function can_pay_with_key( \WC_Order $order, string $key ): bool {
		if ( '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return false;
		}

		return current_user_can( 'pay_for_order', $order->get_id() );
	}

	/**
	 * Get the billing email the order-pay page may give the current visitor.
	 *
	 * Only shop managers and the logged-in order owner get the order's email; anyone else gets the email they posted
	 * in the order-pay email check, or their session email. Client 11.1.0
	 * class-wc-payments-express-checkout-button-display-handler.php:199-217.
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function get_billing_email_for_current_visitor( \WC_Order $order ): string {
		if ( current_user_can( 'read_private_shop_orders' ) || ( 0 !== get_current_user_id() && $order->get_customer_id() === get_current_user_id() ) ) {
			return $order->get_billing_email();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core's order-pay email check posts this without a nonce; it only fills the visitor's own email.
		if ( isset( $_POST['email'] ) && is_string( $_POST['email'] ) ) {
			return sanitize_email( wp_unslash( $_POST['email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$session  = function_exists( 'WC' ) && WC() ? WC()->session : null;
		$customer = $session instanceof \WC_Session ? $session->get( 'customer' ) : null;

		return is_array( $customer ) && isset( $customer['email'] ) && is_string( $customer['email'] ) ? $customer['email'] : '';
	}
}
