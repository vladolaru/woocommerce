<?php
/**
 * The Pay Later configurator module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Endpoint\SaveConfig;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Endpoint\GetConfig;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\Factory\ConfigFactory;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PayLaterMessagingSettings;

return array(
	'paylater-configurator.asset_getter'         => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-paylater-configurator' );
	},
	'paylater-configurator.factory.config'       => static function ( ContainerInterface $container ): ConfigFactory {
		return new ConfigFactory();
	},
	'paylater-configurator.endpoint.save-config' => static function ( ContainerInterface $container ): SaveConfig {
		return new SaveConfig(
			$container->get( 'settings.data.paylater-messaging-settings' ),
			$container->get( 'button.request-data' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'paylater-configurator.endpoint.get-config'  => static function ( ContainerInterface $container ): GetConfig {
		return new GetConfig(
			$container->get( 'settings.data.paylater-messaging-settings' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'paylater-configurator.is-available'         => static function ( ContainerInterface $container ): bool {
		$messages_apply = $container->get( 'button.helper.messages-apply' );
		assert( $messages_apply instanceof MessagesApply );

		return $messages_apply->for_country();
	},
	'paylater-configurator.messaging-locations'  => static function ( ContainerInterface $container ): array {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		if ( ! $settings_provider->paylater_enabled() ) {
			return array();
		}

		return $settings_provider->pay_later_messaging_locations();
	},
);
