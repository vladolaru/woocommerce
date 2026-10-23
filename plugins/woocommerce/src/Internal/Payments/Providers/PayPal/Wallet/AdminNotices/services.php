<?php
/**
 * The services of the admin notice module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices;

use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer\Renderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer\RendererInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\Repository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\RepositoryInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint\MuteMessageEndpoint;

return array(
	'admin-notices.renderer'              => static function ( ContainerInterface $container ): RendererInterface {
		return new Renderer(
			$container->get( 'admin-notices.repository' ),
			$container->get( 'ppcp.asset-version' )
		);
	},
	'admin-notices.repository'            => static function ( ContainerInterface $container ): RepositoryInterface { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return new Repository();
	},
	'admin-notices.mute-message-endpoint' => static function ( ContainerInterface $container ): MuteMessageEndpoint {
		return new MuteMessageEndpoint(
			$container->get( 'button.request-data' )
		);
	},
);
