<?php
/**
 * The services of the session module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session;

use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelController;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\Cancellation\CancelView;

return array(
	'session.handler'                 => function ( ContainerInterface $container ): SessionHandler {
		return new SessionHandler( $container->get( 'woocommerce.logger.woocommerce' ) );
	},
	'session.order-reloader'          => static function ( ContainerInterface $container ): SessionOrderReloader {
		return new SessionOrderReloader(
			$container->get( 'api.endpoint.order' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'session.cancellation.view'       => function ( ContainerInterface $container ): CancelView {
		return new CancelView(
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.funding-source.renderer' )
		);
	},
	'session.cancellation.controller' => function ( ContainerInterface $container ): CancelController {
		return new CancelController(
			$container->get( 'session.handler' ),
			$container->get( 'session.cancellation.view' ),
			$container->get( 'button.helper.context' )
		);
	},
);
