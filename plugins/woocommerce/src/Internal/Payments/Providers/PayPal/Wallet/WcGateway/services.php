<?php
/**
 * The services of the Gateway module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway;

use WooCommerce;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ReferenceTransactionStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\Cache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetterFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesApply;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\MessagesDisclaimers;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Common\Pattern\SingletonDecorator;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\FeesRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\OrderTablePaymentStatusColumn;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\PaymentStatusOrderDetail;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\RenderAuthorizeAction;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Admin\RenderReauthorizeAction;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Assets\FraudNetAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Assets\VoidButtonAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout\CheckoutPayPalAddressPreset;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Checkout\DisableGateways;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\CapturePayPalPayment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\RefreshFeatureStatusEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\ReturnUrlEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\ShippingCallbackEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Endpoint\VoidOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNet;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FraudNet\FraudNetSourceWebsiteId;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\FundingSource\FundingSourceRenderer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\GatewayIds;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\GatewayRepository;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\TransactionUrlProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\CartCheckoutDetector;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\DCCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\Environment;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\FeesUpdater;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\InstallmentsProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\MerchantDetails;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PaymentMethodTitleEnricher;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ApmCapabilityStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PWCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\RefundFeesUpdater;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ResumedOrderShippingRestorer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\SettingsStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Notice\AuthorizeOrderActionNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Notice\ConnectAdminNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Notice\SendOnlyCountryNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Notice\UnsupportedCurrencyAdminNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\AuthorizedPaymentsProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\OrderProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Processor\RefundProcessor;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Settings\Settings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteAction;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcInboxNotes\InboxNoteRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory\SimpleRedirectTaskFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Factory\SimpleRedirectTaskFactoryInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Registrar\TaskRegistrar;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Registrar\TaskRegistrarInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\WcTasks\Tasks\SimpleRedirectTask;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Shipping\ShippingCallbackUrlFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Endpoint\CartEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\CartFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\CartTotalsFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\MoneyFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\StoreApi\Factory\ShippingRatesFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\WebhookEventStorage;

return array(
	'woocommerce.core'                                     => static function (): WooCommerce {
		return WC();
	},

	'wcgateway.paypal-gateway'                             => static function ( ContainerInterface $container ): PayPalGateway {
		return new PayPalGateway(
			$container->get( 'wcgateway.funding-source.renderer' ),
			$container->get( 'wcgateway.order-processor' ),
			$container->get( 'settings.settings-provider' ),
			$container->get( 'session.handler' ),
			$container->get( 'wcgateway.processor.refunds' ),
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'wcgateway.transaction-url-provider' ),
			$container->get( 'wc-subscriptions.helper' ),
			$container->get( 'settings.environment' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'api.shop.country' ),
			$container->get( 'api.factory.paypal-checkout-url' ),
			$container->get( 'api.endpoint.payment-tokens' ),
			$container->get( 'wc-payment-tokens.wc-payment-tokens' ),
			$container->get( 'wcgateway.asset_getter' ),
			$container->get( 'wcgateway.settings.admin-settings-enabled' ),
			$container->get( 'wcgateway.endpoint.capture-paypal-payment' ),
			$container->get( 'api.endpoint.order' ),
			$container->get( 'button.helper.context' )
		);
	},
	'wcgateway.disabler'                                   => static function ( ContainerInterface $container ): DisableGateways {
		$settings_provider  = $container->get( 'settings.settings-provider' );
		$settings_status    = $container->get( 'wcgateway.settings.status' );
		$subscription_helper = $container->get( 'wc-subscriptions.helper' );
		$context            = $container->get( 'button.helper.context' );

		return new DisableGateways( $settings_provider, $settings_status, $subscription_helper, $context );
	},

	'wcgateway.is-wc-settings-page'                        => static function ( ContainerInterface $container ): bool {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		return 'wc-settings' === $page;
	},
	'wcgateway.is-wc-payments-page'                        => static function ( ContainerInterface $container ): bool {
		$is_wc_settings_page = $container->get( 'wcgateway.is-wc-settings-page' );
		$tab                 = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';

		return $is_wc_settings_page && 'checkout' === $tab;
	},
	'wcgateway.is-wc-gateways-list-page'                   => static function ( ContainerInterface $container ): bool {
		return $container->get( 'wcgateway.is-wc-payments-page' ) && ! isset( $_GET['section'] );
	},

	/**
	 * Whether the current request renders the PayPal Payments settings page.
	 */
	'wcgateway.is-plugin-settings-page'                    => static function (): bool {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
			return false;
		}
		if ( ! is_admin() ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$is_wc_settings     = isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'];
		$is_plugin_settings = isset( $_GET['section'] ) && PayPalGateway::ID === $_GET['section'];

		// phpcs:enable WordPress.Security.NonceVerification

		return $is_wc_settings && $is_plugin_settings;
	},

	'wcgateway.settings'                                   => SingletonDecorator::make(
		static function ( ContainerInterface $container ): Settings {
			return new Settings(
				$container->get( 'wcgateway.button.default-locations' ),
				$container->get( 'wcgateway.settings.pay-later.default-button-locations' ),
				$container->get( 'wcgateway.settings.pay-later.default-messaging-locations' )
			);
		}
	),
	'wcgateway.notice.connect'                             => static function ( ContainerInterface $container ): ConnectAdminNotice {
		return new ConnectAdminNotice(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'wcgateway.is-send-only-country' )
		);
	},
	'wcgateway.notice.currency-unsupported'                => static function ( ContainerInterface $container ): UnsupportedCurrencyAdminNotice {
		return new UnsupportedCurrencyAdminNotice(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'api.shop.currency.getter' ),
			$container->get( 'api.supported-currencies' ),
			$container->get( 'wcgateway.is-wc-gateways-list-page' ),
			$container->get( 'wcgateway.is-plugin-settings-page' )
		);
	},
	'wcgateway.store-country'                              => static function (): string {
		$location = wc_get_base_location();

		return $location['country'];
	},
	'wcgateway.send-only-message'                          => static function () {
		return __( "<strong>Important</strong>: Your current WooCommerce store location is in a \"send-only\" country, according to PayPal's policies. Sellers in these countries are unable to receive payments via PayPal. Since receiving payments is essential for using the PayPal Payments extension, you will not be able to connect your PayPal account while operating from a \"send-only\" country. To activate PayPal, please update your WooCommerce store location to a supported region and connect a PayPal account eligible for receiving payments.", 'woocommerce' );
	},
	'wcgateway.send-only-countries'                        => static function () {
		return array(
			'AO',
			'AI',
			'AM',
			'AW',
			'AZ',
			'BY',
			'BJ',
			'BT',
			'BO',
			'VG',
			'BN',
			'BF',
			'BI',
			'CI',
			'KH',
			'CM',
			'CV',
			'TD',
			'KM',
			'CG',
			'CK',
			'DJ',
			'ER',
			'ET',
			'FK',
			'GA',
			'GM',
			'GN',
			'GW',
			'GY',
			'KI',
			'KG',
			'LA',
			'MK',
			'MG',
			'MV',
			'ML',
			'MH',
			'MR',
			'FM',
			'MN',
			'ME',
			'MS',
			'NA',
			'NR',
			'NP',
			'NE',
			'NG',
			'NU',
			'NF',
			'PG',
			'PY',
			'PN',
			'RW',
			'ST',
			'WS',
			'SL',
			'SB',
			'SO',
			'SH',
			'PM',
			'VC',
			'SR',
			'SJ',
			'TJ',
			'TZ',
			'TG',
			'TO',
			'TN',
			'TM',
			'TV',
			'UG',
			'UA',
			'VA',
			'WF',
			'YE',
			'ZM',
			'ZW',
		);
	},
	'wcgateway.is-send-only-country'                       => static function ( ContainerInterface $container ) {
		$store_country       = $container->get( 'wcgateway.store-country' );
		$send_only_countries = $container->get( 'wcgateway.send-only-countries' );

		return in_array( $store_country, $send_only_countries, true );
	},
	'wcgateway.notice.send-only-country'                   => static function ( ContainerInterface $container ) {
		return new SendOnlyCountryNotice(
			$container->get( 'wcgateway.send-only-message' ),
			$container->get( 'wcgateway.is-send-only-country' ),
			$container->get( 'wcgateway.is-plugin-settings-page' ),
			$container->get( 'wcgateway.is-wc-gateways-list-page' ),
			$container->get( 'settings.flag.is-connected' )
		);
	},

	'wcgateway.notice.authorize-order-action'              =>
		static function ( ContainerInterface $container ): AuthorizeOrderActionNotice {
			return new AuthorizeOrderActionNotice();
		},

	'wcgateway.settings.status'                            => static function ( ContainerInterface $container ): SettingsStatus {
		$settings_provider = $container->get( 'settings.settings-provider' );

		return new SettingsStatus( $settings_provider );
	},
	'wcgateway.order-processor'                            => static function ( ContainerInterface $container ): OrderProcessor {

		$session_handler               = $container->get( 'session.handler' );
		$order_endpoint                = $container->get( 'api.endpoint.order' );
		$order_factory                 = $container->get( 'api.factory.order' );
		$authorized_payments_processor = $container->get( 'wcgateway.processor.authorized-payments' );
		$settings_provider             = $container->get( 'settings.settings-provider' );
		$environment                   = $container->get( 'settings.environment' );
		$logger                        = $container->get( 'woocommerce.logger.woocommerce' );
		$subscription_helper           = $container->get( 'wc-subscriptions.helper' );
		$order_helper                  = $container->get( 'api.order-helper' );

		return new OrderProcessor(
			$session_handler,
			$order_endpoint,
			$order_factory,
			$authorized_payments_processor,
			$settings_provider,
			$logger,
			$environment,
			$subscription_helper,
			$order_helper,
			$container->get( 'api.factory.purchase-unit' ),
			$container->get( 'api.factory.payer' ),
			$container->get( 'api.factory.shipping-preference' ),
			$container->get( 'wcgateway.builder.experience-context' )
		);
	},
	'wcgateway.processor.refunds'                          => static function ( ContainerInterface $container ): RefundProcessor {
		return new RefundProcessor(
			$container->get( 'api.endpoint.order' ),
			$container->get( 'api.endpoint.payments' ),
			$container->get( 'wcgateway.helper.refund-fees-updater' ),
			$container->get( 'api.prefix' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'wcgateway.processor.authorized-payments'              => static function ( ContainerInterface $container ): AuthorizedPaymentsProcessor {
		$order_endpoint      = $container->get( 'api.endpoint.order' );
		$payments_endpoint   = $container->get( 'api.endpoint.payments' );
		$logger              = $container->get( 'woocommerce.logger.woocommerce' );
		$notice              = $container->get( 'wcgateway.notice.authorize-order-action' );
		$settings            = $container->get( 'wcgateway.settings' );
		$subscription_helper = $container->get( 'wc-subscriptions.helper' );
		$amount_factory      = $container->get( 'api.factory.amount' );

		return new AuthorizedPaymentsProcessor(
			$order_endpoint,
			$payments_endpoint,
			$logger,
			$notice,
			$settings,
			$subscription_helper,
			$amount_factory
		);
	},
	'wcgateway.admin.render-authorize-action'              => static function ( ContainerInterface $container ): RenderAuthorizeAction {
		$column = $container->get( 'wcgateway.admin.orders-payment-status-column' );

		return new RenderAuthorizeAction( $column );
	},
	'wcgateway.admin.render-reauthorize-action'            => static function ( ContainerInterface $container ): RenderReauthorizeAction {
		$column = $container->get( 'wcgateway.admin.orders-payment-status-column' );

		return new RenderReauthorizeAction( $column );
	},
	'wcgateway.admin.order-payment-status'                 => static function ( ContainerInterface $container ): PaymentStatusOrderDetail {
		$column = $container->get( 'wcgateway.admin.orders-payment-status-column' );

		return new PaymentStatusOrderDetail( $column );
	},
	'wcgateway.admin.orders-payment-status-column'         => static function ( ContainerInterface $container ): OrderTablePaymentStatusColumn {
		return new OrderTablePaymentStatusColumn(
			$container->get( 'settings.settings-provider' )
		);
	},
	'wcgateway.admin.fees-renderer'                        => static function ( ContainerInterface $container ): FeesRenderer {
		return new FeesRenderer();
	},
	'wcgateway.all-funding-sources'                        => static function ( ContainerInterface $container ): array {
		return array(
			'card'       => _x( 'Credit or debit cards', 'Name of payment method', 'woocommerce' ),
			'sepa'       => _x( 'SEPA-Lastschrift', 'Name of payment method', 'woocommerce' ),
			'bancontact' => _x( 'Bancontact', 'Name of payment method', 'woocommerce' ),
			'blik'       => _x( 'BLIK', 'Name of payment method', 'woocommerce' ),
			'eps'        => _x( 'eps', 'Name of payment method', 'woocommerce' ),
			'ideal'      => _x( 'iDEAL', 'Name of payment method', 'woocommerce' ),
			'mybank'     => _x( 'MyBank', 'Name of payment method', 'woocommerce' ),
			'p24'        => _x( 'Przelewy24', 'Name of payment method', 'woocommerce' ),
			'venmo'      => _x( 'Venmo', 'Name of payment method', 'woocommerce' ),
			'trustly'    => _x( 'Trustly', 'Name of payment method', 'woocommerce' ),
			'paylater'   => _x( 'PayPal Pay Later', 'Name of payment method', 'woocommerce' ),
			'paypal'     => _x( 'PayPal', 'Name of payment method', 'woocommerce' ),
		);
	},

	/**
	 * The sources that do not cause issues about redirecting (on mobile, ...) and sometimes not returning back.
	 */
	'wcgateway.funding-sources-without-redirect'           => static function ( ContainerInterface $container ): array {
		return array( 'paypal', 'paylater', 'venmo', 'card' );
	},

	'wcgateway.checkout.address-preset'                    => static function ( ContainerInterface $container ): CheckoutPayPalAddressPreset {

		return new CheckoutPayPalAddressPreset(
			$container->get( 'session.handler' )
		);
	},
	'wcgateway.asset_getter'                               => static function ( ContainerInterface $container ): AssetGetter {
		$factory = $container->get( 'assets.asset_getter_factory' );
		assert( $factory instanceof AssetGetterFactory );

		return $factory->for_module( 'ppcp-wc-gateway' );
	},
	'wcgateway.endpoint.return-url'                        => static function ( ContainerInterface $container ): ReturnUrlEndpoint {
		$gateway  = $container->get( 'wcgateway.paypal-gateway' );
		$endpoint = $container->get( 'api.endpoint.order' );

		return new ReturnUrlEndpoint(
			$gateway,
			$endpoint,
			$container->get( 'session.handler' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},
	'wcgateway.endpoint.refresh-feature-status'            => static function ( ContainerInterface $container ): RefreshFeatureStatusEndpoint {
		return new RefreshFeatureStatusEndpoint(
			$container->get( 'wcgateway.settings' ),
			new Cache( 'ppcp-timeout' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'wcgateway.transaction-url-sandbox'                    => static function ( ContainerInterface $container ): string {
		return 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
	},

	'wcgateway.transaction-url-live'                       => static function ( ContainerInterface $container ): string {
		return 'https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
	},

	'wcgateway.soft-descriptor'                            => static function ( ContainerInterface $container ): string {
		$settings_provider = $container->get( 'settings.settings-provider' );
		return $settings_provider->soft_descriptor();
	},

	'wcgateway.transaction-url-provider'                   => static function ( ContainerInterface $container ): TransactionUrlProvider {
		$sandbox_url_base = $container->get( 'wcgateway.transaction-url-sandbox' );
		$live_url_base    = $container->get( 'wcgateway.transaction-url-live' );

		return new TransactionUrlProvider(
			$sandbox_url_base,
			$live_url_base,
			$container->get( 'settings.environment' )
		);
	},

	'wcgateway.helper.dcc-product-status'                  => static function ( ContainerInterface $container ): DCCProductStatus {
		return new DCCProductStatus(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'api.endpoint.partners' ),
			$container->get( 'api.helper.failure-registry' ),
			$container->get( 'api.helper.product-status-result-cache' ),
			$container->get( 'api.helpers.dccapplies' )
		);
	},

	'wcgateway.helper.refund-fees-updater'                 => static function ( ContainerInterface $container ): RefundFeesUpdater {
		$order_endpoint = $container->get( 'api.endpoint.order' );
		$logger         = $container->get( 'woocommerce.logger.woocommerce' );

		return new RefundFeesUpdater( $order_endpoint, $logger );
	},
	'wcgateway.helper.fees-updater'                        => static function ( ContainerInterface $container ): FeesUpdater {
		return new FeesUpdater(
			$container->get( 'api.endpoint.orders' ),
			$container->get( 'api.factory.capture' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'wcgateway.helper.resumed-order-shipping-restorer'     => static function ( ContainerInterface $container ): ResumedOrderShippingRestorer {
		return new ResumedOrderShippingRestorer(
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'button.helper.messages-disclaimers'                   => static function ( ContainerInterface $container ): MessagesDisclaimers {
		return new MessagesDisclaimers(
			$container->get( 'api.shop.country' )
		);
	},

	'wcgateway.funding-source.renderer'                    => function ( ContainerInterface $container ): FundingSourceRenderer {
		return new FundingSourceRenderer(
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.all-funding-sources' )
		);
	},

	'wcgateway.payment-method-title-enricher'              => static function ( ContainerInterface $container ): PaymentMethodTitleEnricher {
		return new PaymentMethodTitleEnricher(
			$container->get( 'wcgateway.asset_getter' )
		);
	},

	'wcgateway.fraudnet-source-website-id'                 => static function ( ContainerInterface $container ): FraudNetSourceWebsiteId {
		return new FraudNetSourceWebsiteId( $container->get( 'api.merchant_id' ) );
	},
	'wcgateway.fraudnet'                                   => static function ( ContainerInterface $container ): FraudNet {
		$source_website_id = $container->get( 'wcgateway.fraudnet-source-website-id' );

		return new FraudNet(
			(string) $source_website_id()
		);
	},
	'wcgateway.installments-product-status'                => static function ( ContainerInterface $container ): InstallmentsProductStatus {
		return new InstallmentsProductStatus(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'api.endpoint.partners' ),
			$container->get( 'api.helper.failure-registry' ),
			$container->get( 'api.helper.product-status-result-cache' )
		);
	},
	'wcgateway.pwc-product-status'                         => static function ( ContainerInterface $container ): PWCProductStatus {
		return new PWCProductStatus(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'api.endpoint.partners' ),
			$container->get( 'api.helper.failure-registry' ),
			$container->get( 'api.helper.product-status-result-cache' )
		);
	},
	'wcgateway.apm-capability-status'                      => static function ( ContainerInterface $container ): ApmCapabilityStatus {
		return new ApmCapabilityStatus(
			$container->get( 'settings.flag.is-connected' ),
			$container->get( 'api.endpoint.partners' ),
			$container->get( 'api.helper.failure-registry' ),
			$container->get( 'api.helper.product-status-result-cache' )
		);
	},
	'wcgateway.logging.is-enabled'                         => static function ( ContainerInterface $container ): bool {
		$settings = $container->get( 'settings.data.settings' );
		assert( $settings instanceof SettingsModel );

		$is_enabled = $settings->get_enable_logging();

		if ( ! $is_enabled ) {
			$state = $container->get( 'settings.connection-state' );
			assert( $state instanceof ConnectionState );

			$is_enabled = $state->is_onboarding();
		}

		/**
		 * Whether the logging of the plugin errors/events is enabled.
		 *
		 * @param bool $is_enabled Whether the logging is enabled.
		 */
		return apply_filters( 'woocommerce_paypal_payments_is_logging_enabled', $is_enabled );
	},

	'wcgateway.use-place-order-button'                     => function ( ContainerInterface $container ): bool {
		/**
		 * Whether to use the standard "Place order" button with redirect to PayPal instead of the PayPal smart buttons.
		 */
		return apply_filters(
			'woocommerce_paypal_payments_use_place_order_button',
			false
		);
	},
	'wcgateway.helper.vaulting-scope'                      => static function ( ContainerInterface $container ): bool {
		try {
			$token = $container->get( 'api.bearer' )->bearer();

			return $token->vaulting_available();
		} catch ( RuntimeException $exception ) {
			return false;
		}
	},

	'button.helper.vaulting-label'                         => static function ( ContainerInterface $container ): string {
		$vaulting_label = '';
		if ( ! $container->get( 'wcgateway.helper.vaulting-scope' ) ) {
			$vaulting_label .= sprintf(
			// translators: %1$s and %2$s are the opening and closing of HTML <a> tag.
				__( ' To use vaulting features, you must %1$senable vaulting on your account%2$s.', 'woocommerce' ),
				'<a
					href="https://docs.woocommerce.com/document/woocommerce-paypal-payments/#enable-vaulting-on-your-live-account"
					target="_blank"
				>',
				'</a>'
			);
		}

		$vaulting_label .= '<p class="description">';
		$vaulting_label .= sprintf(
		// translators: %1$s, %2$s, %3$s and %4$s are the opening and closing of HTML <a> tag.
			__( 'This will disable all %1$sPay Later%2$s features and %3$sAlternative Payment Methods%4$s on your site.', 'woocommerce' ),
			'<a
					href="https://woocommerce.com/document/woocommerce-paypal-payments/#pay-later"
					target="_blank"
				>',
			'</a>',
			'<a
					href="https://woocommerce.com/document/woocommerce-paypal-payments/#alternative-payment-methods"
					target="_blank"
				>',
			'</a>'
		);
		$vaulting_label .= '</p>';

		return $vaulting_label;
	},

	'wcgateway.enable-reference-transactions-url-sandbox'  => static function ( ContainerInterface $container ): string {
		return 'https://www.sandbox.paypal.com/bizsignup/entry?product=ADVANCED_VAULTING';
	},
	'wcgateway.enable-reference-transactions-url-live'     => static function ( ContainerInterface $container ): string {
		return 'https://www.paypal.com/bizsignup/entry?product=ADVANCED_VAULTING';
	},
	'wcgateway.settings.connection.reference-transactions-status-text' => static function ( ContainerInterface $container ): string {
		$environment = $container->get( 'settings.environment' );
		assert( $environment instanceof Environment );

		$reference_transaction_status = $container->get( 'api.reference-transaction-status' );
		assert( $reference_transaction_status instanceof ReferenceTransactionStatus );

		$enabled = $reference_transaction_status->reference_transaction_enabled();

		$enabled_status_text  = esc_html__( 'Status: Available', 'woocommerce' );
		$disabled_status_text = esc_html__( 'Status: Not yet enabled', 'woocommerce' );

		$button_text = $enabled
			? esc_html__( 'Settings', 'woocommerce' )
			: esc_html__( 'Enable saving PayPal & Venmo', 'woocommerce' );

		$enable_url = $environment->is_production()
			? $container->get( 'wcgateway.enable-reference-transactions-url-live' )
			: $container->get( 'wcgateway.enable-reference-transactions-url-sandbox' );

		$button_url = $enabled
			? admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway#field-paypal_saved_payments' )
			: $enable_url;

		return sprintf(
			'<p>%1$s %2$s</p><p><a target="%3$s" href="%4$s" class="button">%5$s</a></p>',
			$enabled ? $enabled_status_text : $disabled_status_text,
			$enabled ? '<span class="dashicons dashicons-yes"></span>' : '<span class="dashicons dashicons-no"></span>',
			$enabled ? '_self' : '_blank',
			esc_url( $button_url ),
			esc_html( $button_text )
		);
	},
	'installments.status-cache'                            => static function ( ContainerInterface $container ): Cache {
		return new Cache( 'ppcp-paypal-installments-status-cache' );
	},
	'wcgateway.button.locations'                           => static function ( ContainerInterface $container ): array {
		return array(
			'product'   => 'Single Product',
			'cart'      => 'Classic Cart',
			'checkout'  => 'Classic Checkout',
			'mini-cart' => 'Mini Cart',
		);
	},
	'wcgateway.button.default-locations'                   => static function ( ContainerInterface $container ): array {
		$button_locations = $container->get( 'wcgateway.button.locations' );
		unset( $button_locations['mini-cart'] );

		return array_keys( $button_locations );
	},
	'wcgateway.button.recommended-styling-notice'          => static function ( ContainerInterface $container ): string {
		if ( CartCheckoutDetector::has_block_checkout() ) {
			$block_checkout_page_string_html = '<a href="' . esc_url( wc_get_page_permalink( 'checkout' ) ) . '">' . __( 'Checkout block', 'woocommerce' ) . '</a>';
		} else {
			$block_checkout_page_string_html = __( 'Checkout block', 'woocommerce' );
		}

		$notice_content = sprintf(
		/* translators: %1$s: URL to the Checkout edit page. */
			__(
				'<span class="highlight">Important:</span> The <code>Cart</code> & <code>Express Checkout</code> <strong>Smart Button Stylings</strong> may be controlled by the %1$s configuration.',
				'woocommerce'
			),
			$block_checkout_page_string_html
		);

		return '<div class="ppcp-notice ppcp-notice-warning"><p>' . $notice_content . '</p></div>';
	},
	'wcgateway.settings.pay-later.messaging-locations'     => static function ( ContainerInterface $container ): array {
		$button_locations = $container->get( 'wcgateway.button.locations' );
		unset( $button_locations['mini-cart'] );

		return array_merge(
			$button_locations,
			array(
				'shop' => did_action( 'init' ) ? __( 'Shop', 'woocommerce' ) : 'Shop',
				'home' => did_action( 'init' ) ? __( 'Home', 'woocommerce' ) : 'Home',
			)
		);
	},
	'wcgateway.settings.pay-later.default-messaging-locations' => static function ( ContainerInterface $container ): array {
		$locations = $container->get( 'wcgateway.settings.pay-later.messaging-locations' );
		unset( $locations['home'] );

		return array_keys( $locations );
	},
	'wcgateway.settings.pay-later.button-locations'        => static function ( ContainerInterface $container ): array {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		$button_locations = $container->get( 'wcgateway.button.locations' );

		$smart_button_selected_locations = $settings_provider->smart_button_locations();

		return array_intersect_key( $button_locations, array_flip( $smart_button_selected_locations ) );
	},
	'wcgateway.settings.pay-later.default-button-locations' => static function ( ContainerInterface $container ): array {
		return $container->get( 'wcgateway.button.default-locations' );
	},
	'wcgateway.ppcp-gateways'                              => static function ( ContainerInterface $container ): array {
		return array(
			PayPalGateway::ID,
			GatewayIds::CREDIT_CARD,
			GatewayIds::PAY_UPON_INVOICE,
			GatewayIds::CARD_BUTTON,
			GatewayIds::OXXO,
			AxoGateway::ID,
			GatewayIds::GOOGLE_PAY,
			GatewayIds::APPLE_PAY,
		);
	},
	'wcgateway.gateway-repository'                         => static function ( ContainerInterface $container ): GatewayRepository {
		return new GatewayRepository(
			$container->get( 'wcgateway.ppcp-gateways' )
		);
	},
	'wcgateway.is-fraudnet-enabled'                        => static function ( ContainerInterface $container ): bool {
		return true;
	},
	'wcgateway.fraudnet-assets'                            => function ( ContainerInterface $container ): FraudNetAssets {
		return new FraudNetAssets(
			$container->get( 'wcgateway.asset_getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'wcgateway.fraudnet' ),
			$container->get( 'settings.environment' ),
			$container->get( 'settings.settings-provider' ),
			$container->get( 'wcgateway.gateway-repository' ),
			$container->get( 'session.handler' ),
			$container->get( 'wcgateway.is-fraudnet-enabled' ),
			$container->get( 'button.helper.context' )
		);
	},
	'wcgateway.wp-paypal-locales-map'                      => static function ( ContainerInterface $container ): array {
		return apply_filters(
			'woocommerce_paypal_payments_button_locales',
			array(
				''      => __( 'Browser language', 'woocommerce' ),
				'ar_DZ' => __( 'Arabic (Algeria)', 'woocommerce' ),
				'ar_BH' => __( 'Arabic (Bahrain)', 'woocommerce' ),
				'ar_EG' => __( 'Arabic (Egypt)', 'woocommerce' ),
				'ar_JO' => __( 'Arabic (Jordan)', 'woocommerce' ),
				'ar_KW' => __( 'Arabic (Kuwait)', 'woocommerce' ),
				'ar_MA' => __( 'Arabic (Morocco)', 'woocommerce' ),
				'ar_SA' => __( 'Arabic (Saudi Arabia)', 'woocommerce' ),
				'cs_CZ' => __( 'Czech', 'woocommerce' ),
				'zh_CN' => __( 'Chinese (Simplified)', 'woocommerce' ),
				'zh_HK' => __( 'Chinese (Hong Kong)', 'woocommerce' ),
				'zh_TW' => __( 'Chinese (Traditional)', 'woocommerce' ),
				'da_DK' => __( 'Danish', 'woocommerce' ),
				'nl_NL' => __( 'Dutch', 'woocommerce' ),
				'en_AU' => __( 'English (Australia)', 'woocommerce' ),
				'en_GB' => __( 'English (United Kingdom)', 'woocommerce' ),
				'en_US' => __( 'English (United States)', 'woocommerce' ),
				'fi_FI' => __( 'Finnish', 'woocommerce' ),
				'fr_CA' => __( 'French (Canada)', 'woocommerce' ),
				'fr_FR' => __( 'French (France)', 'woocommerce' ),
				'de_DE' => __( 'German (Germany)', 'woocommerce' ),
				'de_CH' => __( 'German (Switzerland)', 'woocommerce' ),
				'de_AT' => __( 'German (Austria)', 'woocommerce' ),
				'de_LU' => __( 'German (Luxembourg)', 'woocommerce' ),
				'el_GR' => __( 'Greek', 'woocommerce' ),
				'he_IL' => __( 'Hebrew', 'woocommerce' ),
				'hu_HU' => __( 'Hungarian', 'woocommerce' ),
				'id_ID' => __( 'Indonesian', 'woocommerce' ),
				'it_IT' => __( 'Italian', 'woocommerce' ),
				'ja_JP' => __( 'Japanese', 'woocommerce' ),
				'ko_KR' => __( 'Korean', 'woocommerce' ),
				'no_NO' => __( 'Norwegian', 'woocommerce' ),
				'es_ES' => __( 'Spanish (Spain)', 'woocommerce' ),
				'es_MX' => __( 'Spanish (Mexico)', 'woocommerce' ),
				'pl_PL' => __( 'Polish', 'woocommerce' ),
				'pt_BR' => __( 'Portuguese (Brazil)', 'woocommerce' ),
				'pt_PT' => __( 'Portuguese (Portugal)', 'woocommerce' ),
				'ru_RU' => __( 'Russian', 'woocommerce' ),
				'sk_SK' => __( 'Slovak', 'woocommerce' ),
				'sv_SE' => __( 'Swedish', 'woocommerce' ),
				'th_TH' => __( 'Thai', 'woocommerce' ),
			)
		);
	},
	'wcgateway.endpoint.capture-paypal-payment'            => static function ( ContainerInterface $container ): CapturePayPalPayment {
		return new CapturePayPalPayment(
			$container->get( 'api.host' ),
			$container->get( 'api.bearer' ),
			$container->get( 'api.factory.order' ),
			$container->get( 'api.factory.purchase-unit' ),
			$container->get( 'settings.settings-provider' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'wcgateway.settings.wc-tasks.simple-redirect-task-factory' => static function (): SimpleRedirectTaskFactoryInterface {
		return new SimpleRedirectTaskFactory();
	},
	'wcgateway.settings.wc-tasks.task-registrar'           => static function (): TaskRegistrarInterface {
		return new TaskRegistrar();
	},

	'wcgateway.settings.wc-tasks.pay-later-task-config'    => static function ( ContainerInterface $container ): array {
		$section_id       = PayPalGateway::ID;

		if ( $container->has( 'paylater-configurator.is-available' ) && $container->get( 'paylater-configurator.is-available' ) ) {
			return array(
				array(
					'id'           => 'pay-later-messaging-task',
					'title'        => __( 'Configure PayPal Pay Later messaging', 'woocommerce' ),
					'description'  => __( 'Decide where you want dynamic Pay Later messaging to show up and how you want it to look on your site.', 'woocommerce' ),
					'redirect_url' => admin_url( "admin.php?page=wc-settings&tab=checkout&section={$section_id}" ),
				),
			);
		}

		return array();
	},

	'wcgateway.settings.wc-tasks.connect-task-config'      => static function ( ContainerInterface $container ): array {
		$is_connected                 = $container->get( 'settings.flag.is-connected' );
		$is_current_country_send_only = $container->get( 'wcgateway.is-send-only-country' );

		if ( ! $is_connected && ! $is_current_country_send_only ) {
			return array(
				array(
					'id'           => 'connect-to-paypal-task',
					'title'        => __( 'Connect PayPal to complete setup', 'woocommerce' ),
					'description'  => __( 'PayPal Payments is almost ready. To get started, connect your account with the Activate PayPal Payments button.', 'woocommerce' ),
					'redirect_url' => admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ppcp-gateway' ),
				),
			);
		}

		return array();
	},

	'wcgateway.settings.wc-tasks.working-capital-config'   => static function ( ContainerInterface $container ): array {
		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		$is_working_capital_feature_flag_enabled = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- feature flags use this convention
			'woocommerce.feature-flags.woocommerce_paypal_payments.working_capital_enabled',
			true
		);

		$is_working_capital_eligible = $container->get( 'api.shop.country' ) === 'US' && $settings_provider->stay_updated();

		if ( ! $settings_provider->merchant_connected() || ! $is_working_capital_feature_flag_enabled || ! $is_working_capital_eligible ) {
			return array();
		}

		return array(
			array(
				'id'           => 'ppcp-working-capital-task',
				'title'        => __( 'Fuel your business growth with a PayPal Working Capital loan. Check eligibility', 'woocommerce' ),
				'description'  => '',
				'redirect_url' => 'https://www.paypal.com/us/business/financial-services/working-capital?partner_camp_id=woocommerce_ppwc',
			),
		);
	},

	'wcgateway.settings.wc-tasks.task-config-services'     => static function (): array {
		return array(
			'wcgateway.settings.wc-tasks.pay-later-task-config',
			'wcgateway.settings.wc-tasks.connect-task-config',
			'wcgateway.settings.wc-tasks.working-capital-config',
		);
	},

	/**
	 * A configuration for simple redirect wc tasks.
	 *
	 * @returns array<array{
	 *     id: string,
	 *     title: string,
	 *     description: string,
	 *     redirect_url: string
	 * }>
	 */
	'wcgateway.settings.wc-tasks.simple-redirect-tasks-config' => static function ( ContainerInterface $container ): array {
		$list_of_config       = array();
		$task_config_services = $container->get( 'wcgateway.settings.wc-tasks.task-config-services' );

		foreach ( $task_config_services as $service_id ) {
			if ( $container->has( $service_id ) ) {
				$task_config    = $container->get( $service_id );
				$list_of_config = array_merge( $list_of_config, $task_config );
			}
		}

		return $list_of_config;
	},

	/**
	 * Retrieves the list of simple redirect task instances.
	 *
	 * @returns SimpleRedirectTask[]
	 */
	'wcgateway.settings.wc-tasks.simple-redirect-tasks'    => static function ( ContainerInterface $container ): array {
		$simple_redirect_tasks_config = $container->get( 'wcgateway.settings.wc-tasks.simple-redirect-tasks-config' );
		$simple_redirect_task_factory = $container->get( 'wcgateway.settings.wc-tasks.simple-redirect-task-factory' );
		assert( $simple_redirect_task_factory instanceof SimpleRedirectTaskFactoryInterface );

		$simple_redirect_tasks = array();

		foreach ( $simple_redirect_tasks_config as $config ) {
			$id                      = $config['id'] ?? '';
			$title                   = $config['title'] ?? '';
			$description             = $config['description'] ?? '';
			$redirect_url            = $config['redirect_url'] ?? '';
			$simple_redirect_tasks[] = $simple_redirect_task_factory->create_task( $id, $title, $description, $redirect_url );
		}

		return $simple_redirect_tasks;
	},

	'wcgateway.settings.inbox-note-factory'                => static function (): InboxNoteFactory {
		return new InboxNoteFactory();
	},

	'wcgateway.settings.inbox-note-registrar'              => static function ( ContainerInterface $container ): InboxNoteRegistrar {
		return new InboxNoteRegistrar( $container->get( 'wcgateway.settings.inbox-notes' ), $container->get( 'ppcp.base-name' ) );
	},

	/**
	 * Retrieves the list of inbox note instances.
	 *
	 * @returns InboxNoteInterface[]
	 */
	'wcgateway.settings.inbox-notes'                       => static function ( ContainerInterface $container ): array {
		$inbox_note_factory = $container->get( 'wcgateway.settings.inbox-note-factory' );
		assert( $inbox_note_factory instanceof InboxNoteFactory );

		$settings = $container->get( 'wcgateway.settings' );
		assert( $settings instanceof Settings );

		$settings_model = $container->get( 'settings.data.settings' );
		assert( $settings_model instanceof SettingsModel );

		$messages_apply = $container->get( 'button.helper.messages-apply' );
		assert( $messages_apply instanceof MessagesApply );

		$settings_provider = $container->get( 'settings.settings-provider' );
		assert( $settings_provider instanceof SettingsProvider );

		$is_working_capital_feature_flag_enabled = apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- feature flags use this convention
			'woocommerce.feature-flags.woocommerce_paypal_payments.working_capital_enabled',
			true
		);

		return array(
			$inbox_note_factory->create_note(
				__( 'PayPal Working Capital', 'woocommerce' ),
				__( 'Business loans from $1k to $230k for first-time borrowers. Looking to fuel your business growth? With a PayPal Working Capital loan, approved loans are funded in minutes and repaid as a share of your sales. Minimum payment required every 90 days. The lender for PayPal Working Capital is WebBank.', 'woocommerce' ),
				Note::E_WC_ADMIN_NOTE_INFORMATIONAL,
				'ppcp-working-capital-inbox-note',
				Note::E_WC_ADMIN_NOTE_UNACTIONED,
				$is_working_capital_feature_flag_enabled && $container->get( 'api.shop.country' ) === 'US' && $settings_model->get_stay_updated(),
				new InboxNoteAction(
					'learn_more',
					__( 'Learn More', 'woocommerce' ),
					'https://www.paypal.com/us/business/financial-services/working-capital?partner_camp_id=woocommerce_ppwc',
					Note::E_WC_ADMIN_NOTE_UNACTIONED,
					true
				)
			),
		);
	},

	'wcgateway.void-button.assets'                         => function ( ContainerInterface $container ): VoidButtonAssets {
		return new VoidButtonAssets(
			$container->get( 'wcgateway.asset_getter' ),
			$container->get( 'ppcp.asset-version' ),
			$container->get( 'api.endpoint.order.cached' ),
			$container->get( 'wcgateway.processor.refunds' )
		);
	},
	'wcgateway.void-button.endpoint'                       => function ( ContainerInterface $container ): VoidOrderEndpoint {
		return new VoidOrderEndpoint(
			$container->get( 'button.request-data' ),
			$container->get( 'api.endpoint.order' ),
			$container->get( 'wcgateway.processor.refunds' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'wcgateway.settings.admin-settings-enabled'            => static function ( ContainerInterface $container ): bool {
		return $container->has( 'settings.asset_getter' );
	},

	'wcgateway.contact-module.eligibility.check'           => static function ( ContainerInterface $container ): callable {
		$feature_enabled = (bool) apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- feature flags use this convention
			'woocommerce.feature-flags.woocommerce_paypal_payments.contact_module_enabled',
			getenv( 'PCP_CONTACT_MODULE_ENABLED' ) !== '0'
		);

		/**
		 * Decides, whether the current merchant is eligible to use the
		 * "Contact Module" feature on this site.
		 */
		return static function () use ( $feature_enabled, $container ) {
			if ( ! $feature_enabled ) {
				return false;
			}

			$details = $container->get( 'settings.merchant-details' );
			assert( $details instanceof MerchantDetails );

			$enable_contact_module = 'US' === $details->get_merchant_country();

			/**
			 * The contact module is enabled for US-based merchants by default.
			 * This filter provides the official way to opt-out of using it on this store.
			 */
			return (bool) apply_filters(
				'woocommerce_paypal_payments_contact_module_enabled',
				$enable_contact_module
			);
		};
	},

	/**
	 * Returns a centralized list of feature eligibility checks.
	 *
	 * This is a helper service which is used by the `MerchantDetails` class and
	 * should not be directly accessed.
	 */
	'wcgateway.feature-eligibility.list'                   => static function ( ContainerInterface $container ): array {
		return array(
			FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => $container->get( 'save-payment-methods.eligibility.check' ),
			FeaturesDefinition::FEATURE_CONTACT_MODULE => $container->get( 'wcgateway.contact-module.eligibility.check' ),
		);
	},

	/**
	 * Returns a prefix for the site, ensuring the same site always gets the same prefix (unless the URL changes).
	 */
	'wcgateway.settings.invoice-prefix'                    => static function ( ContainerInterface $container ): string {
		$site_url = get_site_url( get_current_blog_id() );
		$hash     = md5( $site_url );
		$letters  = preg_replace( '~\d~', '', $hash ) ?? '';
		$prefix   = substr( $letters, 0, 6 );

		return $prefix ? $prefix . '-' : '';
	},

	/**
	 * Returns random 6 characters length alphabetic prefix, followed by a hyphen.
	 */
	'wcgateway.settings.invoice-prefix-random'             => static function ( ContainerInterface $container ): string {
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$prefix     = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$prefix .= $characters[ wp_rand( 0, strlen( $characters ) - 1 ) ];
		}

		return $prefix . '-';
	},

	'wcgateway.store-api.endpoint.cart'                    => static function ( ContainerInterface $container ): CartEndpoint {
		return new CartEndpoint(
			$container->get( 'wcgateway.store-api.factory.cart' ),
			$container->get( 'woocommerce.logger.woocommerce' )
		);
	},

	'wcgateway.store-api.factory.cart'                     => static function ( ContainerInterface $container ): CartFactory {
		return new CartFactory(
			$container->get( 'wcgateway.store-api.factory.cart-totals' ),
			$container->get( 'wcgateway.store-api.factory.shipping-rates' )
		);
	},
	'wcgateway.store-api.factory.cart-totals'              => static function ( ContainerInterface $container ): CartTotalsFactory {
		return new CartTotalsFactory(
			$container->get( 'wcgateway.store-api.factory.money' )
		);
	},
	'wcgateway.store-api.factory.shipping-rates'           => static function ( ContainerInterface $container ): ShippingRatesFactory {
		return new ShippingRatesFactory(
			$container->get( 'wcgateway.store-api.factory.money' )
		);
	},
	'wcgateway.store-api.factory.money'                    => static function ( ContainerInterface $container ): MoneyFactory {
		return new MoneyFactory();
	},

	'wcgateway.shipping.callback.endpoint'                 => static function ( ContainerInterface $container ): ShippingCallbackEndpoint {
		return new ShippingCallbackEndpoint(
			$container->get( 'wcgateway.store-api.endpoint.cart' ),
			$container->get( 'api.factory.amount' ),
			$container->get( 'woocommerce.logger.woocommerce' ),
			$container->get( 'button.session.storage.card-data.transient' )
		);
	},

	'wcgateway.shipping.callback.factory.url'              => static function ( ContainerInterface $container ): ShippingCallbackUrlFactory {
		return new ShippingCallbackUrlFactory(
			$container->get( 'wcgateway.store-api.endpoint.cart' ),
			$container->get( 'wcgateway.shipping.callback.endpoint' )
		);
	},

	'wcgateway.server-side-shipping-callback-enabled'      => static function ( ContainerInterface $container ): bool {
		// SSSC depends on Woo's Store API, which currently doesn't work with plain permalinks because of the rest_get_url_prefix bug.
		$has_plain_permalinks = empty( get_option( 'permalink_structure' ) );

		$last_webhook_storage = $container->get( 'webhook.last-webhook-storage' );
		assert( $last_webhook_storage instanceof WebhookEventStorage );

		// Not directly related, but if webhooks are not arriving then SSSC probably is not accessible too.
		$webhooks_working = ! $last_webhook_storage->is_empty();

		$enabled = getenv( 'PCP_SERVER_SIDE_SHIPPING_CALLBACK_ENABLED' ) !== '0'
					&& ! $has_plain_permalinks
					&& $webhooks_working;

		return apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			'woocommerce.feature-flags.woocommerce_paypal_payments.server_side_shipping_callback_enabled',
			$enabled
		);
	},

	'wcgateway.appswitch-enabled'                          => static function ( ContainerInterface $container ): bool {
		return apply_filters(
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			'woocommerce.feature-flags.woocommerce_paypal_payments.appswitch_enabled',
			getenv( 'PCP_APPSWITCH_ENABLED' ) !== '0'
		);
	},
);
