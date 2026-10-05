<?php
/**
 * The blocks module extensions.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks;

use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'wcgateway.button.locations'                       => function ( array $locations, ContainerInterface $container ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The service filter callback signature is fixed.
		return array_merge(
			$locations,
			array(
				'checkout-block-express' => did_action( 'init' ) ? _x( 'Express Checkout', 'Name of Buttons Location', 'woocommerce' ) : 'Express Checkout',
				'cart-block'             => did_action( 'init' ) ? _x( 'Cart', 'Name of Buttons Location', 'woocommerce' ) : 'Cart',
			)
		);
	},
	'wcgateway.settings.pay-later.messaging-locations' => function ( array $locations, ContainerInterface $container ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The service filter callback signature is fixed.
		unset( $locations['checkout-block-express'] );
		unset( $locations['cart-block'] );

		return $locations;
	},

	'order-endpoints.pay-now-contexts'                 => function ( array $contexts, ContainerInterface $container ): array {
		if ( ! $container->get( 'blocks.settings.final_review_enabled' ) ) {
			$contexts[] = 'checkout-block';
			$contexts[] = 'cart-block';
		}

		return $contexts;
	},

	'order-endpoints.handle-shipping-in-paypal'        => function ( bool $previous, ContainerInterface $container ): bool {
		return ! $container->get( 'blocks.settings.final_review_enabled' );
	},
);
