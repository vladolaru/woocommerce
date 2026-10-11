<?php
/**
 * The Pay with Crypto module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(
	'ppcp-local-apms.asset_getter'          => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-local-alternative-payment-methods' );
	},
	'ppcp-local-apms.payment-methods'       => static function (): array {
		return array(
			'pwc' => array(
				'id'         => PWCGateway::ID,
				'countries'  => array(),
				'currencies' => array( 'USD' ),
			),
		);
	},
	'ppcp-local-apms.pwc.wc-gateway'        => static function ( ContainerInterface $container ): PWCGateway {
		return new PWCGateway(
			$container->get( 'wcgateway.asset_getter' ),
			$container->get( 'api.endpoint.orders' ),
			$container->get( 'api.factory.purchase-unit' ),
			$container->get( 'wcgateway.processor.refunds' ),
			$container->get( 'wcgateway.transaction-url-provider' ),
			$container->get( 'wcgateway.builder.experience-context' )
		);
	},
	'ppcp-local-apms.pwc.payment-method'    => static function ( ContainerInterface $container ): PWCPaymentMethod {
		return new PWCPaymentMethod(
			$container->get( 'ppcp-local-apms.asset_getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'ppcp-local-apms.pwc.wc-gateway' )
		);
	},
	// The extension's APM country rule: the merchant country is not in its ineligible list.
	'ppcp-local-apms.eligibility.check'     => static function ( ContainerInterface $container ): bool {
		$ineligible_countries = array( 'RU', 'BR', 'JP' );

		return ! in_array( (string) $container->get( 'api.merchant.country' ), $ineligible_countries, true );
	},
	'ppcp-local-apms.pwc.currency.check'    => static function ( ContainerInterface $container ): bool {
		return 'USD' === $container->get( 'api.shop.currency.getter' )->get();
	},
	'ppcp-local-apms.pwc.eligibility.check' => static function ( ContainerInterface $container ): bool {
		return $container->get( 'ppcp-local-apms.eligibility.check' ) && $container->get( 'ppcp-local-apms.pwc.currency.check' );
	},
);
