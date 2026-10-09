<?php
/**
 * CollectingModule class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformServedGates;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\BearerRetryFilter;
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
	 * The priority of the gate filters: late, so they have the last word over the wallet's own callbacks at 10.
	 */
	private const GATE_PRIORITY = 100;

	/**
	 * The priority of the retry filter: after the wallet's own at 10, which drops only its first-party token.
	 */
	private const RETRY_PRIORITY = 20;

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
	 * Add the filters that keep authorize-only and saved PayPal and Venmo off, after the wallet's own callbacks, and,
	 * while the platform serves the store, the one that re-signs a retried request with the call's app.
	 *
	 * @param ContainerInterface $container The service container.
	 */
	public function run( ContainerInterface $container ): bool {
		$connection_state = $container->get( 'collecting.connection-state' );

		$gates = new PlatformServedGates( $connection_state );
		add_filter( 'woocommerce_paypal_payments_order_intent', array( $gates, 'handle_woocommerce_paypal_payments_order_intent' ), self::GATE_PRIORITY );
		add_filter( 'woocommerce_paypal_payments_rest_common_merchant_features', array( $gates, 'handle_woocommerce_paypal_payments_rest_common_merchant_features' ), self::GATE_PRIORITY );

		if ( $connection_state->is_served_by_platform() ) {
			$retry = new BearerRetryFilter(
				$connection_state,
				$container->get( 'collecting.transport' ),
				$container->get( 'collecting.order-app-context' ),
				$container->get( 'collecting.state' ),
				$container->get( 'woocommerce.logger.woocommerce' )
			);
			add_filter( 'ppcp_retry_request_args', array( $retry, 'handle_ppcp_retry_request_args' ), self::RETRY_PRIORITY, 2 );
		}

		return true;
	}
}
