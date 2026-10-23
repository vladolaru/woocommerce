<?php
/**
 * The vaulting module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens;

use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'wc-payment-tokens.wc-payment-tokens' => static function ( ContainerInterface $container ): WooCommercePaymentTokens {
		return new WooCommercePaymentTokens(
			$container->get( 'api.endpoint.payment-tokens' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
);
