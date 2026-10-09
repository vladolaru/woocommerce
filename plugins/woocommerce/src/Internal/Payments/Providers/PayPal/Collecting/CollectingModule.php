<?php
/**
 * CollectingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExecutableModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ExtendingModule;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ModuleClassNameIdTrait;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Module\ServiceModule;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The collecting module: the services and extensions of the collecting state, added to the wallet's module list.
 *
 * The shell appends it after the wallet's own modules, so its extensions wrap theirs. It is built only for a store the
 * platform serves, so a dormant store never boots it and the shell owns anything that has to exist before a boot.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class CollectingModule implements ServiceModule, ExtendingModule, ExecutableModule {
	use ModuleClassNameIdTrait;

	/**
	 * {@inheritDoc}
	 */
	public function services(): array {
		return require __DIR__ . '/services.php';
	}

	/**
	 * {@inheritDoc}
	 */
	public function extensions(): array {
		return require __DIR__ . '/extensions.php';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ContainerInterface $container The service container.
	 */
	public function run( ContainerInterface $container ): bool {
		return true;
	}
}
