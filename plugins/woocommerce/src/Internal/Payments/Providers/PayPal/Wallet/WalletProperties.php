<?php
/**
 * WalletProperties class file.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Properties\BaseProperties;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Properties\Properties;

/**
 * The Modularity properties of the forked wallet: what the extension derived from its plugin main file.
 *
 * The base name stays the extension's, because the inbox notes the wallet registers carry it as their source and
 * the extension deletes its notes by that source. The version stays the extension's version at the fork point,
 * because the kept migrations fire on a change of the shared `woocommerce-ppcp-version` option; bump it only when
 * porting a migration from a newer extension release.
 *
 * @since 11.3.0
 * @internal
 */
final class WalletProperties extends BaseProperties {

	/**
	 * The extension version the fork was taken from (its main-file header at 0083204e7).
	 */
	public const EXTENSION_VERSION = '4.1.3';

	/**
	 * Build the properties for this core install.
	 *
	 * @return self
	 */
	public static function new(): self {
		return new self(
			'woocommerce-paypal-payments',
			WC_ABSPATH . 'assets/client/paypal-wallet/',
			WC()->plugin_url() . '/assets/client/paypal-wallet/',
			array(
				Properties::PROP_NAME         => 'PayPal Wallet',
				Properties::PROP_VERSION      => self::EXTENSION_VERSION,
				Properties::PROP_TEXTDOMAIN   => 'woocommerce',
				Properties::PROP_REQUIRES_PHP => '7.4',
			)
		);
	}
}
