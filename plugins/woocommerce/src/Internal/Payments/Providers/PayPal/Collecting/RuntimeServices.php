<?php
/**
 * RuntimeServices class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Logging\RedactingLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Reconcile\Reconciler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\HeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\Logger\WooCommerceLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PPCP;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Throwable;

/**
 * The collecting services the owner-independent surfaces need, from the booted wallet when there is one, and the
 * ownership check they share.
 *
 * The REST routes and the profiler card run whoever owns the wallet, often on a request that boots nothing. They take
 * the booted container's services when the collecting module is in it, and otherwise build the transport from the
 * wp-config.php constants, as the container does. Nothing is built until a caller asks.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class RuntimeServices {

	/**
	 * The transport: the booted container's, or one built from the constants, or one that is not ready.
	 *
	 * @since 11.3.0
	 *
	 * @return PlatformTransport
	 */
	public static function transport(): PlatformTransport {
		$container = self::collecting_container();
		if ( null !== $container ) {
			return $container->get( 'collecting.transport' );
		}

		$logger    = new RedactingLogger( new WooCommerceLogger( wc_get_logger(), 'woocommerce-paypal-wallet' ) );
		$transport = DirectPlatformTransport::from_constants( $logger, new OrderAppContext() );

		return $transport->is_ready() ? $transport : new NotReadyTransport( new CollectingState( new Options(), new HeldOrders() ) );
	}

	/**
	 * The reconciler, which needs the wallet's own services: null unless the wallet booted with the collecting module.
	 *
	 * @since 11.3.0
	 *
	 * @return Reconciler|null
	 */
	public static function reconciler(): ?Reconciler {
		$container = self::collecting_container();

		return null === $container ? null : $container->get( 'collecting.reconciler' );
	}

	/**
	 * Whether core owns the wallet on this site, so a dormant store may start collecting. The profiler card and the panel's
	 * payee route both ask this before they enter the collecting state.
	 *
	 * @since 11.3.0
	 *
	 * @param PayPalWalletRuntimeArbiter|null $arbiter The arbiter; core's by default.
	 * @return bool
	 */
	public static function core_owns_wallet( ?PayPalWalletRuntimeArbiter $arbiter = null ): bool {
		$arbiter = $arbiter ?? wc_get_container()->get( PayPalWalletRuntimeArbiter::class );

		return PayPalWalletRuntimeArbiter::OWNER_NATIVE === $arbiter->get_runtime_owner();
	}

	/**
	 * Whether the PayPal Payments extension owns the wallet on this site, so the wallet's settings route does not exist and
	 * setup cannot complete. The held-order surfaces word and link themselves for that case.
	 *
	 * @since 11.3.0
	 *
	 * @return bool
	 */
	public static function extension_owns_wallet(): bool {
		return PayPalWalletRuntimeArbiter::OWNER_EXTENSION === wc_get_container()->get( PayPalWalletRuntimeArbiter::class )->get_runtime_owner();
	}

	/**
	 * The booted wallet container when the collecting module is in it, or null.
	 *
	 * @return ContainerInterface|null
	 */
	private static function collecting_container(): ?ContainerInterface {
		try {
			$container = PPCP::container();
		} catch ( Throwable $not_booted ) {
			return null;
		}

		return $container->has( 'collecting.reconciler' ) ? $container : null;
	}
}
