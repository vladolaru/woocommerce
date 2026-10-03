<?php
/**
 * The factories of the API client.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(

	'wcgateway.builder.experience-context' => static function ( ContainerInterface $container ): ExperienceContextBuilder {
		return new ExperienceContextBuilder(
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.shipping.callback.factory.url' )
		);
	},
);
