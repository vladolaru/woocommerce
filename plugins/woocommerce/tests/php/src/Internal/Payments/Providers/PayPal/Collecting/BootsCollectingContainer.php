<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\CollectingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WalletProperties;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\Module;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Package;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Boots a real container with the wallet's modules and the collecting module appended last, as the shell builds it for
 * a store the platform serves.
 */
trait BootsCollectingContainer {

	/**
	 * Boot the wallet's modules plus the collecting module and return the container.
	 *
	 * The collecting module is added whatever the store's state, so a store the platform does not serve shows that the
	 * extensions hand the wallet's own values through.
	 *
	 * @param Module[] $extra_modules   Modules added after the collecting module, such as a transport binding.
	 * @param Module[] $wallet_bindings Modules added before the collecting module, so its extensions wrap theirs, as they
	 *                                  wrap the wallet's own.
	 * @return ContainerInterface
	 */
	private function boot_container( array $extra_modules = array(), array $wallet_bindings = array() ): ContainerInterface {
		foreach ( PayPalWalletBootstrap::get_extension_constants() as $name => $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constants the shell defines before a boot.
			}
		}
		require_once WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/SerializedClasses/load.php';
		// The SDK v6 module loads unless the store is flagged ineligible; load it for certain.
		add_filter( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled', '__return_true' );

		$modules   = array_merge( ( require WC_ABSPATH . 'src/Internal/Payments/Providers/PayPal/Wallet/modules.php' )(), $wallet_bindings );
		$modules[] = new CollectingModule();

		$package = Package::new( WalletProperties::new() );
		foreach ( array_merge( $modules, $extra_modules ) as $module ) {
			$package->addModule( $module );
		}
		$package->boot();

		return $package->container();
	}
}
