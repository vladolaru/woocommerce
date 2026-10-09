<?php
/**
 * The collecting module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\NoHeldOrders;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextBearer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\ContextHostResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\DirectPlatformTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\NotReadyTransport;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\OrderAppContext;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Transport\PlatformTransport;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'collecting.options'               => static function (): Options {
		return new Options();
	},
	'collecting.state'                 => static function ( ContainerInterface $container ): CollectingState {
		return new CollectingState( $container->get( 'collecting.options' ), new NoHeldOrders() );
	},
	'collecting.connection-state'      => static function ( ContainerInterface $container ): ConnectionState {
		return new ConnectionState( $container->get( 'collecting.options' ) );
	},
	// The one binding point for the transport: the POC's direct transport where the wp-config.php constants exist, else one that is not ready.
	'collecting.transport'             => static function ( ContainerInterface $container ): PlatformTransport {
		$transport = DirectPlatformTransport::from_constants( $container->get( 'woocommerce.logger.woocommerce' ), $container->get( 'collecting.order-app-context' ) );

		return $transport->is_ready() ? $transport : new NotReadyTransport( $container->get( 'collecting.state' ) );
	},
	// One per request: the bearer, the host resolver and the code that enters an order share it.
	'collecting.order-app-context'     => static function (): OrderAppContext {
		return new OrderAppContext();
	},
	'collecting.context-bearer'        => static function ( ContainerInterface $container ): ContextBearer {
		return new ContextBearer(
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.state' )
		);
	},
	'collecting.context-host-resolver' => static function ( ContainerInterface $container ): ContextHostResolver {
		return new ContextHostResolver(
			$container->get( 'settings.connection-state' ),
			$container->get( 'collecting.order-app-context' ),
			$container->get( 'collecting.transport' ),
			$container->get( 'collecting.state' )
		);
	},
);
