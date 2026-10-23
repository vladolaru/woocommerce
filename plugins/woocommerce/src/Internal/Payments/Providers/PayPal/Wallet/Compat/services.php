<?php
/**
 * The compatibility module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\ConnectionDataSanitizer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalBlueprintBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsExporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\PayPalSettingsImporter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

return array(

	'compat.ppec.mock-gateway'                      => static function ( $container ) {
		$settings = $container->get( 'settings.settings-provider' );
		assert( $settings instanceof SettingsProvider );

		$title    = sprintf(
			/* Translators: placeholder is the gateway name. */
			__( '%s (Legacy)', 'woocommerce' ),
			$settings->paypal_gateway_title()
		);

		return new PPEC\MockGateway( $title );
	},

	'compat.ppec.billing-agreement-converter'       => static function ( ContainerInterface $container ) {
		return new PPEC\BillingAgreementTokenConverter(
			$container->get( 'api.endpoint.payment-method-tokens' ),
			$container->get( 'api.repository.customer' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'compat.ppec.subscriptions-handler'             => static function ( ContainerInterface $container ) {
		return new PPEC\SubscriptionsHandler(
			$container->get( 'wc-subscriptions.renewal-handler' ),
			$container->get( 'compat.ppec.mock-gateway' ),
			$container->get( 'compat.ppec.billing-agreement-converter' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'compat.plugin-script-names'                    => static function ( ContainerInterface $container ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return array(
			'ppcp-smart-button',
			'ppcp-wc-payment-tokens-myaccount-payments',
			'ppcp-gateway-settings',
			'ppcp-webhooks-status-page',
			'ppcp-fraudnet',
		);
	},

	'compat.plugin-script-file-names'               => static function ( ContainerInterface $container ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return array(
			'button.js',
			'gateway-settings.js',
			'fraudnet.js',
		);
	},

	'compat.nyp.is_supported_plugin_version_active' => function (): bool {
		return function_exists( 'wc_nyp_init' );
	},
	'compat.wc_bookings.is_supported_plugin_version_active' => function (): bool {
		return class_exists( 'WC_Bookings' );
	},

	'compat.blueprint.is_available'                 => function (): bool {
		return interface_exists( 'Automattic\WooCommerce\Blueprint\Exporters\StepExporter' );
	},
	'compat.blueprint.connection_data_sanitizer'    => static function (): ConnectionDataSanitizer {
		return new ConnectionDataSanitizer();
	},
	'compat.blueprint.paypal_settings_exporter'     => static function ( ContainerInterface $container ): PayPalSettingsExporter {
		return new PayPalSettingsExporter(
			$container->get( 'compat.blueprint.connection_data_sanitizer' ),
			false
		);
	},
	'compat.blueprint.paypal_settings_exporter_with_connection' => static function ( ContainerInterface $container ): PayPalSettingsExporter {
		return new PayPalSettingsExporter(
			$container->get( 'compat.blueprint.connection_data_sanitizer' ),
			true
		);
	},
	'compat.blueprint.paypal_settings_importer'     => static function ( ContainerInterface $container ): PayPalSettingsImporter {
		return new PayPalSettingsImporter(
			$container->get( 'settings.service.sanitizer' )
		);
	},
	'compat.blueprint.bootstrap'                    => static function ( ContainerInterface $container ): PayPalBlueprintBootstrap {
		return new PayPalBlueprintBootstrap(
			$container->get( 'compat.blueprint.paypal_settings_exporter' ),
			$container->get( 'compat.blueprint.paypal_settings_exporter_with_connection' ),
			$container->get( 'compat.blueprint.paypal_settings_importer' )
		);
	},
);
