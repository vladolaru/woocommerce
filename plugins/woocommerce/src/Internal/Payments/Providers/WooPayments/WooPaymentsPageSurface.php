<?php
/**
 * WooPaymentsPageSurface class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Tells which shopper page the current request is, for the WooPayments checkout, express checkout, fraud-prevention,
 * BNPL messaging and WooPay rules.
 *
 * It asks core's page conditionals, as those rules did at each site before. Tests replace it through the container: once
 * `WOOCOMMERCE_CART` or `WOOCOMMERCE_CHECKOUT` is defined, `is_cart()` or `is_checkout()` stays true for the whole process.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsPageSurface {

	/**
	 * Tell whether the request is the cart, as core's is_cart() answers.
	 *
	 * @return bool
	 */
	public function is_cart(): bool {
		return is_cart();
	}

	/**
	 * Tell whether the request is the checkout, as core's is_checkout() answers.
	 *
	 * @return bool
	 */
	public function is_checkout(): bool {
		return is_checkout();
	}

	/**
	 * Tell whether the request is the cart or any page holding the Cart block, as client 11.1.0 reads it
	 * (class-wc-payments-express-checkout-button-helper.php:275-277).
	 *
	 * @return bool
	 */
	public function is_cart_surface(): bool {
		return $this->is_cart() || has_block( 'woocommerce/cart' );
	}

	/**
	 * Tell whether the request is the checkout or any page holding the Checkout block, as client 11.1.0 reads it
	 * (class-wc-payment-gateway-wcpay.php:1181).
	 *
	 * @return bool
	 */
	public function is_checkout_surface(): bool {
		return $this->is_checkout() || has_block( 'woocommerce/checkout' );
	}
}
