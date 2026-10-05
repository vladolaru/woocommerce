<?php
/**
 * The button module services.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\DisabledSmartButton;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\SmartButton;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Assets\SmartButtonInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ApproveOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\CartScriptParamsEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\ChangeCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\CreateOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\DataClientIdEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\GetOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\SaveCheckoutFormEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\SimulateCartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint\ValidateCheckoutEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\CartProductsHelper;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\CheckoutFormSaver;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\DisabledFundingSources;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\EarlyOrderHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\IsolatedCartSimulator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\WooCommerceOrderCreator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Validation\CheckoutFormValidator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\SubscriptionHelper;

return array(
	'button.client_id'                            => static function ( ContainerInterface $container ): string {

		$settings_provider = $container->get( 'settings.settings-provider' );
		$merchant_data     = $settings_provider->merchant_data();
		$client_id         = $merchant_data->client_id;
		if ( $client_id ) {
			return $client_id;
		}

		$env = $container->get( 'settings.environment' );
		/**
		 * The environment.
		 *
		 * @var Environment $env
		 */

		return $env->is_sandbox() ?
			CONNECT_WOO_SANDBOX_CLIENT_ID : CONNECT_WOO_CLIENT_ID;
	},
	'button.client_id_for_admin'                  => static function ( ContainerInterface $container ): string {
		$dummy_ids = array(
			'AU' => 'AQ5yx7aGjD0fWKDMQrngSznDlfSfWvio9j1fCeuLC5foFoimaM_d1AbeRmEvc9jVuJU7BbopMSd4aNPG',
			'DE' => 'AYZigu5BLwbJ_QKNasp_1k0kJUon7NRyazh8Lo-bthJuKetzXRBEzUlbeUIvUfsBxrcN-K0UEk-V6Lq9',
			'ES' => 'Aa3A3B4MvF2_Xwoj7kG_4qI_hh2pRmuvjRefIgp8B0HSIIGnqsx2Wd8IGOvhyX1G2WLOMl0xGJsiHpXl',
			'FR' => 'AYIb1W_LbKGlgpOwk64dGk8PPQnIx0H4wdmQfdNt8M6cCaAsSgQ6O-TwTDF6y9Jpp_5BxtHoYYMQDCb5',
			'GB' => 'AZvAtq7qHoM0yefv-ptnmAvN3gDm9oNj2A7oDqhw_d-pEdWW5q68b7_xd-U2-dQs_kipnmLhV3-7vWkU',
			'IT' => 'AZm7Rq3sLabDbtq2vRCRVtMRJ09SLi6HeoRy4JuUdFQ6j0D_x-wEZtRzjBhY4NzAcFC_T7GTBdvSYEwK',
			'US' => 'Ad5dKzVsWZvPD3YgjhZ24LKNKmJqg2Xo3uKx7yuazPiARFc9xJWg1mM-vy-eJhb1V7xn5mPnp_QjAMaM',
		);

		$shop_country = $container->get( 'api.shop.country' );

		return $dummy_ids[ $shop_country ] ?? $container->get( 'button.client_id' );
	},
	// This service may not work correctly when called too early.
	'button.context'                              => static function ( ContainerInterface $container ): string {
		$context = $container->get( 'button.helper.context' );
		return $context->context();
	},
	'button.smart-button'                         => static function ( ContainerInterface $container ): SmartButtonInterface {
		$context = $container->get( 'button.context' );

		$settings_status = $container->get( 'wcgateway.settings.status' );
		assert( $settings_status instanceof SettingsStatus );

		if ( in_array( $context, array( 'checkout', 'pay-now' ), true ) ) {
			$redirect_to_pay = $container->get( 'wcgateway.use-place-order-button' );
			if ( $redirect_to_pay ) {
				// No smart buttons, redirect the current page to PayPal for payment.
				return new DisabledSmartButton();
			}

			$no_smart_buttons = ! $settings_status->is_smart_button_enabled_for_location( $context );

			if ( $no_smart_buttons ) {
				return new DisabledSmartButton();
			}
		}

		$is_connected = $container->get( 'settings.flag.is-connected' );
		if ( ! $is_connected ) {
			return new DisabledSmartButton();
		}

		$settings_provider   = $container->get( 'settings.settings-provider' );
		$paypal_disabled     = ! $settings_provider->gateway_enabled( PayPalGateway::ID );
		if ( $paypal_disabled ) {
			return new DisabledSmartButton();
		}

		$payer_factory    = $container->get( 'api.factory.payer' );
		$request_data     = $container->get( 'button.request-data' );
		$client_id           = $container->get( 'button.client_id' );
		$subscription_helper = $container->get( 'wc-subscriptions.helper' );
		$messages_apply      = $container->get( 'button.helper.messages-apply' );
		$environment         = $container->get( 'settings.environment' );
		return new SmartButton(
			$container->get( 'button.asset_getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'session.handler' ),
			$settings_provider,
			$payer_factory,
			$client_id,
			$request_data,
			$subscription_helper,
			$container->get( 'button.subscriptions-mode' ),
			$messages_apply,
			$environment,
			$settings_status,
			$container->get( 'api.shop.currency.getter' ),
			$container->get( 'button.basic-checkout-validation-enabled' ),
			$container->get( 'button.early-wc-checkout-validation-enabled' ),
			$container->get( 'button.pay-now-contexts' ),
			$container->get( 'wcgateway.funding-sources-without-redirect' ),
			$container->get( 'button.handle-shipping-in-paypal' ),
			$container->get( 'wcgateway.server-side-shipping-callback-enabled' ),
			$container->get( 'wcgateway.appswitch-enabled' ),
			$container->get( 'button.helper.disabled-funding-sources' ),
			$container->get( 'api.helper.partner-attribution' ),
			$container->get( 'blocks.settings.final_review_enabled' ),
			$container->get( 'button.helper.context' ),
		);
	},
	'button.asset_getter'                         => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-button' );
	},
	'button.pay-now-contexts'                     => static function ( ContainerInterface $container ): array {
		return $container->get( 'order-endpoints.pay-now-contexts' );
	},
	'button.request-data'                         => static function ( ContainerInterface $container ): RequestData {
		return $container->get( 'order-endpoints.request-data' );
	},
	'button.endpoint.simulate-cart'               => static function ( ContainerInterface $container ): SimulateCartEndpoint {
		return new SimulateCartEndpoint(
			$container->get( 'button.smart-button' ),
			$container->get( 'button.request-data' ),
			$container->get( 'button.helper.cart-products' ),
			$container->get( 'button.helper.isolated-cart-simulator' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'button.endpoint.change-cart'                 => static function ( ContainerInterface $container ): ChangeCartEndpoint {
		return $container->get( 'order-endpoints.endpoint.change-cart' );
	},
	'button.endpoint.create-order'                => static function ( ContainerInterface $container ): CreateOrderEndpoint {
		return $container->get( 'order-endpoints.endpoint.create-order' );
	},
	'button.helper.early-order-handler'           => static function ( ContainerInterface $container ): EarlyOrderHandler {
		return $container->get( 'order-endpoints.helper.early-order-handler' );
	},
	'button.endpoint.approve-order'               => static function ( ContainerInterface $container ): ApproveOrderEndpoint {
		return $container->get( 'order-endpoints.endpoint.approve-order' );
	},
	'button.helper.context'                       => static function ( ContainerInterface $container ): Context {
		$session_handler = $container->get( 'session.handler' );

		return new Context( $session_handler );
	},
	'button.checkout-form-saver'                  => static function ( ContainerInterface $container ): CheckoutFormSaver {
		return new CheckoutFormSaver(
			$container->get( 'session.handler' )
		);
	},
	'button.endpoint.save-checkout-form'          => static function ( ContainerInterface $container ): SaveCheckoutFormEndpoint {
		return new SaveCheckoutFormEndpoint(
			$container->get( 'button.request-data' ),
			$container->get( 'button.checkout-form-saver' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'button.endpoint.data-client-id'              => static function ( ContainerInterface $container ): DataClientIdEndpoint {
		$request_data   = $container->get( 'button.request-data' );
		$identity_token = $container->get( 'api.endpoint.identity-token' );
		$logger = $container->get( 'woocommerce.logger.woocommerce' );
		return new DataClientIdEndpoint(
			$request_data,
			$identity_token,
			$logger
		);
	},
	'button.endpoint.validate-checkout'           => static function ( ContainerInterface $container ): ValidateCheckoutEndpoint {
		return new ValidateCheckoutEndpoint(
			$container->get( 'button.request-data' ),
			$container->get( 'button.validation.wc-checkout-validator' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'button.endpoint.cart-script-params'          => static function ( ContainerInterface $container ): CartScriptParamsEndpoint {
		return new CartScriptParamsEndpoint(
			$container->get( 'button.smart-button' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'button.endpoint.get-order'                   => static function ( ContainerInterface $container ): GetOrderEndpoint {
		$request_data          = $container->get( 'button.request-data' );
		$order_endpoint        = $container->get( 'api.endpoint.order' );
		$logger                = $container->get( 'woocommerce.logger.woocommerce' );
		$cart_data_storage     = $container->get( 'button.session.storage.card-data.transient' );
		return new GetOrderEndpoint(
			$request_data,
			$order_endpoint,
			$logger,
			$cart_data_storage
		);
	},
	'button.helper.cart-products'                 => static function ( ContainerInterface $container ): CartProductsHelper {
		return $container->get( 'order-endpoints.helper.cart-products' );
	},
	'button.helper.isolated-cart-simulator'       => static function ( ContainerInterface $container ): IsolatedCartSimulator {
		return new IsolatedCartSimulator(
			$container->get( 'button.helper.cart-products' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'button.helper.messages-apply'                => static function ( ContainerInterface $container ): MessagesApply {
		return new MessagesApply(
			$container->get( 'api.paylater-countries' ),
			$container->get( 'api.merchant.country' )
		);
	},
	'button.helper.disabled-funding-sources'      => static function ( ContainerInterface $container ): DisabledFundingSources {
		return new DisabledFundingSources(
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.all-funding-sources' )
		);
	},
	'button.is-logged-in'                         => static function ( ContainerInterface $container ): bool {
		return $container->get( 'order-endpoints.is-logged-in' );
	},
	'button.registration-required'                => static function ( ContainerInterface $container ): bool {
		return $container->get( 'order-endpoints.registration-required' );
	},
	'button.current-user-must-register'           => static function ( ContainerInterface $container ): bool {
		return $container->get( 'order-endpoints.current-user-must-register' );
	},

	'button.basic-checkout-validation-enabled'    => static function ( ContainerInterface $container ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		/**
		 * The filter allowing to disable the basic client-side validation of the checkout form
		 * when the PayPal button is clicked.
		 *
		 * @since 11.3.0
		 *
		 * @param bool $enabled Whether the basic validation is enabled; false by default.
		 */
		return (bool) apply_filters( 'woocommerce_paypal_payments_basic_checkout_validation_enabled', false );
	},
	'button.early-wc-checkout-validation-enabled' => static function ( ContainerInterface $container ): bool {
		return $container->get( 'order-endpoints.early-wc-checkout-validation-enabled' );
	},
	'button.validation.wc-checkout-validator'     => static function ( ContainerInterface $container ): CheckoutFormValidator { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return new CheckoutFormValidator();
	},
	'button.subscriptions-mode'                   => static function ( ContainerInterface $container ): callable {
		return static function () use ( $container ): string {
			$settings_provider   = $container->get( 'settings.settings-provider' );
			$subscription_helper = $container->get( 'wc-subscriptions.helper' );
			assert( $settings_provider instanceof SettingsProvider );
			assert( $subscription_helper instanceof SubscriptionHelper );

			return $subscription_helper->resolve_subscription_mode( $settings_provider );
		};
	},

	'button.handle-shipping-in-paypal'            => static function ( ContainerInterface $container ): bool {
		return $container->get( 'order-endpoints.handle-shipping-in-paypal' );
	},

	'button.helper.wc-order-creator'              => static function ( ContainerInterface $container ): WooCommerceOrderCreator {
		return $container->get( 'order-endpoints.helper.wc-order-creator' );
	},

	'button.session.factory.card-data'            => static function ( ContainerInterface $container ): CartDataFactory { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return new CartDataFactory();
	},
	'button.session.storage.card-data.transient'  => static function ( ContainerInterface $container ): CartDataTransientStorage { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The container factory signature is fixed.
		return new CartDataTransientStorage();
	},
);
