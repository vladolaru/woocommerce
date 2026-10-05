<?php
/**
 * The forked wallet's module list.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\AdminNotices\AdminNotices;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\ApiModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Blocks\BlocksModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\ButtonModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Logging\WooCommerceLoggingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\OrderEndpointsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterBlock\PayLaterBlockModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterConfigurator\PayLaterConfiguratorModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayLaterWCBlocks\PayLaterWCBlocksModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\SavePaymentMethodsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\SdkV6Module;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\SettingsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\VaultComponent\VaultComponentModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WCGatewayModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens\WcPaymentTokensModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\WcSubscriptionsModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookModule;

/**
 * Returns the module instances in the extension's load order, with its conditional loads kept under their filter names.
 *
 * @return callable(): array
 */
return static function (): array {
	$modules = array(
		new PluginModule(),
		new WooCommerceLoggingModule(),
		new AdminNotices(),
		new ApiModule(),
		new CompatModule(),
		new OrderEndpointsModule(),
		new ButtonModule(),
		new SessionModule(),
		new WcSubscriptionsModule(),
		new WCGatewayModule(),
		new WebhookModule(),
		new WcPaymentTokensModule(),
		new BlocksModule(),
		new SettingsModule(),
	);

	/**
	 * Filters whether the Save Payment Methods module is loaded.
	 *
	 * @since 11.3.0
	 *
	 * @param bool $enabled Whether the module is enabled; false only when the PCP_SAVE_PAYMENT_METHODS environment variable is "0".
	 */
	if ( apply_filters( 'woocommerce.feature-flags.woocommerce_paypal_payments.save_payment_methods_enabled', getenv( 'PCP_SAVE_PAYMENT_METHODS' ) !== '0' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The extension's flag name is part of the shared contract.
		$modules[] = new SavePaymentMethodsModule();
	}

	if ( PayLaterBlockModule::is_module_loading_required() ) {
		$modules[] = new PayLaterBlockModule();
	}

	if ( PayLaterConfiguratorModule::is_enabled() ) {
		$modules[] = new PayLaterConfiguratorModule();

		if ( PayLaterWCBlocksModule::is_module_loading_required() ) {
			$modules[] = new PayLaterWCBlocksModule();
		}
	}

	/**
	 * Filters whether the Vault Component module is loaded.
	 *
	 * @since 11.3.0
	 *
	 * @param bool $enabled Whether the module is enabled; false only when the PCP_VAULT_COMPONENT_ENABLED environment variable is "0".
	 */
	if ( apply_filters( 'woocommerce.feature-flags.woocommerce_paypal_payments.vault_component_enabled', getenv( 'PCP_VAULT_COMPONENT_ENABLED' ) !== '0' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The extension's flag name is part of the shared contract.
		$modules[] = new VaultComponentModule();
	}

	/**
	 * Filters whether the SDK v6 module is loaded.
	 *
	 * @since 11.3.0
	 *
	 * @param bool $enabled Whether the module is enabled; true when the PCP_SDK_V6_ENABLED environment variable is "1" or the store is not flagged as ineligible.
	 */
	if ( apply_filters( 'woocommerce.feature-flags.woocommerce_paypal_payments.sdk_v6_enabled', getenv( 'PCP_SDK_V6_ENABLED' ) === '1' || 'no' !== get_option( 'woocommerce-ppcp-sdk-v6-eligible' ) ) ) { // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The extension's flag name is part of the shared contract.
		$modules[] = new SdkV6Module();
	}

	return $modules;
};
