<?php
/**
 * Compatibility layer for the Account Funds for WooCommerce plugin.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat;

/**
 * Reports Account Funds store credit, which the plugin applies through
 * WC_Cart::set_total() and WC_Order::set_total() rather than a coupon or fee. Left
 * unreported it lands in the tax line, and PayPal rejects a negative tax_total.
 */
class WcAccountFundsCompat {

	private const CART_CLASS = '\Kestrel\Account_Funds\Cart';

	// Written on order creation, before payment processing, and by the pre-4.0 path too.
	private const ORDER_META = '_funds_used';

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_filter(
			'woocommerce_paypal_payments_cart_extra_discount',
			array( $this, 'cart_extra_discount' ),
			10,
			2
		);
		add_filter(
			'woocommerce_paypal_payments_order_extra_discount',
			array( $this, 'order_extra_discount' ),
			10,
			2
		);
		add_filter(
			'woocommerce_paypal_payments_store_api_cart_extra_discount',
			array( $this, 'store_api_cart_extra_discount' ),
			10,
			1
		);
	}

	/**
	 * Adds the applied Account Funds credit to the extra discount of a cart.
	 *
	 * @param float    $extra The extra discount accumulated so far.
	 * @param \WC_Cart $cart  The WooCommerce cart.
	 */
	public function cart_extra_discount( float $extra, \WC_Cart $cart ): float { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The woocommerce_paypal_payments_cart_extra_discount callback signature is fixed.
		return $extra + $this->applied_cart_credit();
	}

	/**
	 * Same for the Store API, which works in minor units on both sides of the sum.
	 *
	 * @param int $extra The extra discount accumulated so far, in minor units.
	 */
	public function store_api_cart_extra_discount( int $extra ): int {
		return $extra + (int) round( $this->applied_cart_credit() * 10 ** wc_get_price_decimals() );
	}

	/**
	 * Read from order meta rather than the cart: the cart session is gone on the
	 * pay-for-order page, and a stored order keeps the amount it was reduced by.
	 *
	 * @param float     $extra The extra discount accumulated so far.
	 * @param \WC_Order $order The order.
	 */
	public function order_extra_discount( float $extra, \WC_Order $order ): float {
		return $extra + max( 0.0, (float) $order->get_meta( self::ORDER_META ) );
	}

	/**
	 * Gated on partial use, which is what makes the plugin reduce the cart total. The
	 * getter reports a balance for fully covered carts too, which PayPal never sees.
	 */
	private function applied_cart_credit(): float {
		$cart_class = self::CART_CLASS;

		if ( ! class_exists( $cart_class ) || ! $cart_class::is_using_account_funds_partially() ) {
			return 0.0;
		}

		return max( 0.0, (float) $cart_class::get_applied_account_funds_amount() );
	}
}
