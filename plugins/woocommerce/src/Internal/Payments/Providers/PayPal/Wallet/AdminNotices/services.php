<?php
/**
 * The services of the admin notice module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer\Renderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Renderer\RendererInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\Repository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Repository\RepositoryInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\Endpoint\MuteMessageEndpoint;

return array(
	'admin-notices.asset_getter'          => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-admin-notices' );
	},
	'admin-notices.renderer'              => static function ( ContainerInterface $container ): RendererInterface {
		return new Renderer(
			$container->get( 'admin-notices.repository' ),
			$container->get( 'admin-notices.asset_getter' ),
			$container->get( 'ppcp.asset-version' )
		);
	},
	'admin-notices.repository'            => static function ( ContainerInterface $container ): RepositoryInterface {
		return new Repository();
	},
	'admin-notices.mute-message-endpoint' => static function ( ContainerInterface $container ): MuteMessageEndpoint {
		return new MuteMessageEndpoint(
			$container->get( 'button.request-data' )
		);
	},
);
