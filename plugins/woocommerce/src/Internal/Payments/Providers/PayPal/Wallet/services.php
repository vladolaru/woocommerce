<?php
/**
 * The plugin module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Http\RedirectorInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Http\WpRedirector;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Package;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Properties\PluginProperties;
use Automattic\WooCommerce\Vendor\Inpsyde\Modularity\Properties\Properties;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'ppcp.asset-version'            => function ( ContainerInterface $container ): string {
		return $container->get( 'ppcp.plugin-version' );
	},

	'assets.asset_getter_factory'   => function ( ContainerInterface $container ): AssetGetterFactory {
		$properties = $container->get( Package::PROPERTIES );
		assert( $properties instanceof Properties );

		return new AssetGetterFactory(
			(string) $properties->baseUrl(),
			$properties->basePath()
		);
	},

	'http.redirector'               => function ( ContainerInterface $container ): RedirectorInterface {
		return new WpRedirector();
	},
	'ppcp.plugin-version'           => function ( ContainerInterface $container ): string {
		/** @var Properties $properties */
		$properties = $container->get( Package::PROPERTIES );

		return $properties->version();
	},
	'ppcp.base-name'                => function ( ContainerInterface $container ): string {
		/** @var Properties $properties */
		$properties = $container->get( Package::PROPERTIES );

		return $properties->baseName();
	},
	'ppcp.path-to-plugin-folder'    => function ( ContainerInterface $container ): string {
		/** @var Properties $properties */
		$properties = $container->get( Package::PROPERTIES );

		return $properties->basePath();
	},
	'ppcp.path-to-plugin-main-file' => function ( ContainerInterface $container ): string {
		/** @var PluginProperties $properties */
		$properties = $container->get( Package::PROPERTIES );

		/** @psalm-suppress UndefinedInterfaceMethod */
		return $properties->pluginMainFile();
	},
	'ppcp.module-availability'      => static function ( ContainerInterface $container ): ModuleAvailability {
		return new ModuleAvailability( $container );
	},
);
