<?php
/**
 * The webhook module factories.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'webhook.status.registered-webhooks' => function ( ContainerInterface $container ): array {
		$endpoint = $container->get( 'api.endpoint.webhook' );
		assert( $endpoint instanceof WebhookEndpoint );

		$is_connected = $container->get( 'settings.flag.is-connected' );

		if ( $is_connected ) {
			return $endpoint->list();
		}

		return array();
	},
);
