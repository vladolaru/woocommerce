<?php
/**
 * The Pay Later block module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock\PayLaterBlockRenderer;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'paylater-block.asset_getter' => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-paylater-block' );
	},
	'paylater-block.renderer'     => static function (): PayLaterBlockRenderer {
		return new PayLaterBlockRenderer();
	},
);
