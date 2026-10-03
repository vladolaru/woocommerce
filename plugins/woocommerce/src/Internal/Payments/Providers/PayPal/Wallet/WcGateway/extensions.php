<?php
/**
 * The extensions of the gateway module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\Logger\NullLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\Logger\WooCommerceLogger;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;

return array(

	'api.merchant_email'             => static function ( string $previous, ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );
		return $settings_provider->merchant_email();
	},
	'api.merchant_id'                => static function ( string $previous, ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );
		return $settings_provider->merchant_id();
	},
	'api.partner_merchant_id'        => static function ( string $previous, ContainerInterface $container ): string {
		$environment = $container->get( 'settings.environment' );

		/**
		 * The environment.
		 *
		 * @var Environment $environment
		 */
		return $environment->is_sandbox() ?
			(string) $container->get( 'api.partner_merchant_id-sandbox' ) : (string) $container->get( 'api.partner_merchant_id-production' );
	},
	'api.key'                        => static function ( string $previous, ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );
		return $settings_provider->client_id();
	},
	'api.secret'                     => static function ( string $previous, ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );
		return $settings_provider->client_secret();
	},
	'api.prefix'                     => static function ( string $previous, ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );
		$prefix = $settings_provider->invoice_prefix();
		return $prefix ? $prefix : 'WC-';
	},
	'woocommerce.logger.woocommerce' => function ( LoggerInterface $previous, ContainerInterface $container ): LoggerInterface {
		if ( ! function_exists( 'wc_get_logger' ) || ! $container->get( 'wcgateway.logging.is-enabled' ) ) {
			return new NullLogger();
		}

		$source = $container->get( 'woocommerce.logger.source' );
		return new WooCommerceLogger(
			wc_get_logger(),
			$source
		);
	},
);
