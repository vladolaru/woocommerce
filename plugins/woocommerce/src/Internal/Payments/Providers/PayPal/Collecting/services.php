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
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'collecting.options'          => static function (): Options {
		return new Options();
	},
	'collecting.state'            => static function ( ContainerInterface $container ): CollectingState {
		return new CollectingState( $container->get( 'collecting.options' ), new NoHeldOrders() );
	},
	'collecting.connection-state' => static function ( ContainerInterface $container ): ConnectionState {
		return new ConnectionState( $container->get( 'collecting.options' ) );
	},
);
