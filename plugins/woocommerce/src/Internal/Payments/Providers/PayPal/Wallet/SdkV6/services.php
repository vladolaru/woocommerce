<?php
/**
 * The SDK v6 module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ModuleAvailability;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\AddPaymentMethodManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\SdkV6Manager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Blocks\V6PaymentMethod;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\ClientTokenEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Endpoint\SimulateCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\ButtonStyleMapper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\FastlaneConfig;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessagesEligibility;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Helper\MessageStyleMapper;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;

return array(

	'sdk-v6.asset-getter'               => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-sdk-v6' );
	},

	'sdk-v6.button-style-mapper'        => static function ( ContainerInterface $container ): ButtonStyleMapper {
		return new ButtonStyleMapper(
			$container->get( 'settings.settings-provider' )
		);
	},

	/**
	 * Fastlane keeps its UI in the ppcp-axo modules; this only decides whether
	 * the SDK requests the fastlane component on the current page.
	 */
	'sdk-v6.fastlane-config'            => static function ( ContainerInterface $container ): FastlaneConfig {
		$availability = $container->get( 'ppcp.module-availability' );
		assert( $availability instanceof ModuleAvailability );
		return new FastlaneConfig(
			$container->get( 'wc-subscriptions.helper' ),
			$availability->availability_check( 'axo' )
		);
	},

	/**
	 * Whether the PayPal v6 SDK loads on the current page.
	 *
	 * Callers use this to decide whether to stand down and not load a second (v5)
	 * PayPal SDK against window.paypal.
	 *
	 * A callable rather than a bool: the answer depends on the query, which is
	 * unresolved while the container is being built. Exposed as a service so the
	 * wallet modules can ask without naming SdkV6Manager, which their own
	 * feature flags may leave unloaded.
	 */
	'sdk-v6.owns-current-page'          => static function ( ContainerInterface $container ): callable {
		return static function () use ( $container ): bool {
			$manager = $container->get( 'sdk-v6.manager' );
			assert( $manager instanceof SdkV6Manager );

			return $manager->should_load_on_current_page();
		};
	},

	'sdk-v6.message-style-mapper'       => static function ( ContainerInterface $container ): MessageStyleMapper {
		return new MessageStyleMapper(
			$container->get( 'settings.settings-provider' )
		);
	},

	'sdk-v6.messages-eligibility'       => static function ( ContainerInterface $container ): MessagesEligibility {
		return new MessagesEligibility(
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.settings.status' ),
			$container->get( 'button.helper.messages-apply' ),
			$container->get( 'wc-subscriptions.free-trial-subscription-helper' )
		);
	},

	'sdk-v6.manager'                    => static function ( ContainerInterface $container ): SdkV6Manager {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		return new SdkV6Manager(
			$container->get( 'sdk-v6.asset-getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'settings.environment' ),
			$container->get( 'sdk-v6.button-style-mapper' ),
			$container->get( 'wcgateway.settings.status' ),
			$container->get( 'button.helper.context' ),
			$container->get( 'session.handler' ),
			$container->get( 'session.cancellation.view' ),
			// Computed here rather than reusing blocks.settings.final_review_enabled
			// so this module does not depend on the ppcp-blocks module it replaces.
			! $settings_provider->enable_pay_now(),
			$settings_provider->save_paypal_and_venmo(),
			$container->get( 'wc-subscriptions.helper' ),
			$container->get( 'wc-subscriptions.free-trial-subscription-helper' ),
			// Same mode callable the v5 SmartButton uses; drives deferring native
			// PayPal Subscriptions (subscriptions_api mode) back to the v5 stack.
			$container->get( 'button.subscriptions-mode' ),
			$container->get( 'sdk-v6.message-style-mapper' ),
			$container->get( 'sdk-v6.messages-eligibility' ),
			$container->get( 'sdk-v6.fastlane-config' )
		);
	},

	'sdk-v6.add-payment-method-manager' => static function ( ContainerInterface $container ): AddPaymentMethodManager {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		return new AddPaymentMethodManager(
			$container->get( 'sdk-v6.asset-getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'settings.environment' ),
			$container->get( 'button.helper.context' ),
			$settings_provider->save_paypal_and_venmo()
		);
	},

	'sdk-v6.endpoint.client-token'      => static function ( ContainerInterface $container ): ClientTokenEndpoint {
		return new ClientTokenEndpoint(
			$container->get( 'order-endpoints.request-data' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'api.sdk-client-token' )
		);
	},

	'sdk-v6.endpoint.simulate-cart'     => static function ( ContainerInterface $container ): SimulateCartEndpoint {
		return new SimulateCartEndpoint(
			$container->get( 'order-endpoints.request-data' ),
			$container->get( 'order-endpoints.helper.cart-products' ),
			$container->get( 'button.helper.isolated-cart-simulator' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'sdk-v6.blocks.place-order-enabled' => static function ( ContainerInterface $container ): callable {
		// Whether the non-express PayPal row is offered. A callable, since neither
		// the cart nor the filtered value below is settled while the container is built.
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		$subscription_helper = $container->get( 'wc-subscriptions.helper' );
		assert( $subscription_helper instanceof SubscriptionHelper );

		return static function () use ( $container, $settings_provider, $subscription_helper ): bool {
			/**
			 * Whether to offer the non-express PayPal method.
			 *
			 * @param bool $add_place_order_method Whether to offer the method.
			 */
			$offer_method = (bool) apply_filters(
				'woocommerce_paypal_payments_blocks_add_place_order_method',
				true
			);

			// A subscription needs a method that can be vaulted to pay the
			// renewals. SmartButton::can_save_vault_token()'s conditions, inlined
			// because extensions.php swaps in DisabledSmartButton on v6's pages.
			$can_vault = $settings_provider->save_paypal_and_venmo()
				&& (bool) $container->get( 'button.client_id' );

			$usable_for_cart = ! $subscription_helper->cart_contains_subscription() || $can_vault;

			return $offer_method && $usable_for_cart;
		};
	},

	'sdk-v6.blocks.payment-method'      => static function ( ContainerInterface $container ): V6PaymentMethod {
		// The saved-PayPal vault component lives in its own feature-flagged module,
		// so its services may be absent; fall back to no saved-token support.
		$has_vault = $container->has( 'vault-component.data' )
			&& $container->has( 'vault-component.eligibility.check' );

		return new V6PaymentMethod(
			$container->get( 'sdk-v6.manager' ),
			$container->get( 'sdk-v6.asset-getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'wcgateway.paypal-gateway' ),
			$has_vault ? $container->get( 'vault-component.data' ) : null,
			$has_vault ? $container->get( 'vault-component.eligibility.check' ) : null,
			$container->get( 'button.client_id' ),
			$container->get( 'sdk-v6.blocks.place-order-enabled' )
		);
	},

);
