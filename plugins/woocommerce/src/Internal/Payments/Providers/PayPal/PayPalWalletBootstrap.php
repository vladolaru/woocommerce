<?php
/**
 * PayPalWalletBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Boots the vendored PayPal Payments extension from core when the native wallet owns the site.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PayPalWalletBootstrap implements RegisterHooksInterface {

	/**
	 * Absolute path of the vendored extension (no trailing slash).
	 */
	public const VENDORED_DIR = __DIR__ . '/woocommerce-paypal-payments';

	/**
	 * Register hooks.
	 */
	public function register() {}
}
