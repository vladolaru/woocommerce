<?php
/**
 * WooPaymentsCheckoutAssets class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\SessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers the WooPayments checkout scripts and styles, and builds the payment fields config they read: the
 * `wcpay_core_checkout_config` objects on the classic checkout and the Checkout block's payment method data.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCheckoutAssets implements RegisterHooksInterface {
	/**
	 * Payment method capability for saved/reusable payment credentials.
	 */
	private const PAYMENT_METHOD_CAPABILITY_TOKENIZATION = 'tokenization';

	/**
	 * Payment method capability for buy-now-pay-later methods.
	 */
	private const PAYMENT_METHOD_CAPABILITY_BUY_NOW_PAY_LATER = 'buy_now_pay_later';

	/**
	 * Payment method capability for express checkout methods.
	 */
	private const PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT = 'express_checkout';

	/**
	 * Classic checkout script handle.
	 */
	private const CLASSIC_SCRIPT_HANDLE = 'wc-woopayments-checkout';

	/**
	 * Classic checkout style handle.
	 */
	private const CLASSIC_STYLE_HANDLE = 'wc-woopayments-checkout';

	/**
	 * Core's tokenization-form.js handle, registered by WC_Payment_Gateway::tokenization_script().
	 */
	private const TOKENIZATION_FORM_SCRIPT_HANDLE = 'woocommerce-tokenization-form';

	/**
	 * Payment fields config keys left out of the Blocks payment method data.
	 *
	 * Client 11.1.0's Blocks data (class-wc-payments-blocks-payment-method.php:88-104) does not carry them and
	 * WooCommerce's Blocks bundle does not need them; the classic checkout config keeps them.
	 */
	private const BLOCKS_OMITTED_CONFIG_KEYS = array(
		'confirmationErrorMessage',
		'customerData',
		// The Blocks card method translates its own (index.js FINGERPRINT_GENERIC_ERROR).
		'fingerprintErrorMessage',
		'paymentListWalletsConfig',
		'woopayButtonLabels',
		'woopayAdditionalInfoText',
		'woopayAgreementText',
		'woopayTermsOfServiceLabel',
		'woopayPrivacyPolicyLabel',
		// The Blocks readers fall back to the same text (index.js WooPaySaveUserSection).
		'woopaySaveUserLabel',
		'woopayPhoneLabel',
		// The Blocks card method translates its own brand labels (index.js:191-194, :233-236).
		'cardBrandPopoverLabel',
		'cardBrandLogosLabel',
	);

	/**
	 * Config keys added only when the WooPay button can show on this page.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-button-handler.php:144-160.
	 */
	private const WOOPAY_BUTTON_CONFIG_KEYS = array(
		'woopayButton',
		'woopayButtonNonce',
		'addToCartNonce',
		'shouldShowWooPayButton',
		'woopaySessionEmail',
		'woopayIsCountryAvailable',
		'woopayAppearance',
		'woopayFontRules',
	);

	/**
	 * Country-specific Stripe test card numbers used by WooPayments checkout.
	 *
	 * @var array<string,string>
	 */
	private const COUNTRY_TEST_CARDS = array(
		'US' => '4242 4242 4242 4242',
		'AR' => '4000 0003 2000 0021',
		'BR' => '4000 0007 6000 0002',
		'CA' => '4000 0012 4000 0000',
		'CL' => '4000 0015 2000 0001',
		'CO' => '4000 0017 0000 0003',
		'CR' => '4000 0018 8000 0005',
		'EC' => '4000 0021 8000 0000',
		'MX' => '4000 0048 4000 8001',
		'PA' => '4000 0059 1000 0000',
		'PY' => '4000 0060 0000 0066',
		'PE' => '4000 0060 4000 0068',
		'UY' => '4000 0085 8000 0003',
		'AE' => '4000 0078 4000 0001',
		'AT' => '4000 0004 0000 0008',
		'BE' => '4000 0005 6000 0004',
		'BG' => '4000 0010 0000 0000',
		'BY' => '4000 0011 2000 0005',
		'HR' => '4000 0019 1000 0009',
		'CY' => '4000 0019 6000 0008',
		'CZ' => '4000 0020 3000 0002',
		'DK' => '4000 0020 8000 0001',
		'EE' => '4000 0023 3000 0009',
		'FI' => '4000 0024 6000 0001',
		'FR' => '4000 0025 0000 0003',
		'DE' => '4000 0027 6000 0016',
		'GI' => '4000 0029 2000 0005',
		'GR' => '4000 0030 0000 0030',
		'HU' => '4000 0034 8000 0005',
		'IE' => '4000 0037 2000 0005',
		'IT' => '4000 0038 0000 0008',
		'LV' => '4000 0042 8000 0005',
		'LI' => '4000 0043 8000 0004',
		'LT' => '4000 0044 0000 0000',
		'LU' => '4000 0044 2000 0006',
		'MT' => '4000 0047 0000 0007',
		'NL' => '4000 0052 8000 0002',
		'NO' => '4000 0057 8000 0007',
		'PL' => '4000 0061 6000 0005',
		'PT' => '4000 0062 0000 0007',
		'RO' => '4000 0064 2000 0001',
		'SA' => '4000 0068 2000 0007',
		'SI' => '4000 0070 5000 0006',
		'SK' => '4000 0070 3000 0001',
		'ES' => '4000 0072 4000 0007',
		'SE' => '4000 0075 2000 0008',
		'CH' => '4000 0075 6000 0009',
		'GB' => '4000 0082 6000 0000',
		'AU' => '4000 0003 6000 0006',
		'CN' => '4000 0015 6000 0002',
		'HK' => '4000 0034 4000 0004',
		'IN' => '4000 0035 6000 0008',
		'JP' => '4000 0039 2000 0003',
		'MY' => '4000 0045 8000 0002',
		'NZ' => '4000 0055 4000 0008',
		'SG' => '4000 0070 2000 0003',
		'TW' => '4000 0015 8000 0008',
		'TH' => '4000 0076 4000 0003',
	);

	/**
	 * Shopper-facing card brand icons.
	 *
	 * @var array<string,string>
	 */
	private const CARD_BRAND_ICONS = array(
		'visa'       => 'Visa',
		'mastercard' => 'Mastercard',
		'amex'       => 'American Express',
		'discover'   => 'Discover',
		'jcb'        => 'JCB',
		'unionpay'   => 'Union Pay',
	);

	/**
	 * France-only shopper-facing card brand icons.
	 *
	 * @var array<string,string>
	 */
	private const FR_CARD_BRAND_ICONS = array(
		'cartes_bancaires' => 'Cartes Bancaires',
	);

	/**
	 * Shopper-facing card brand icon asset paths.
	 *
	 * Client 11.1.0 draws the checkout card strip from getCardBrands() (client/utils/card-brands.ts): payment-method-icons/{visa,mastercard,amex,discover}, cards/{jcb,unionpay,cartes_bancaires}.
	 *
	 * @var array<string,string>
	 */
	public const CARD_BRAND_ICON_ASSETS = array(
		'visa'             => 'payment-methods/visa-color.svg',
		'mastercard'       => 'payment-methods/mastercard-color.svg',
		'amex'             => 'payment-methods/amex-color.svg',
		'discover'         => 'payment-methods/discover-color.svg',
		'jcb'              => 'payment-methods/jcb-color.svg',
		'unionpay'         => 'payment-methods/unionpay-color.svg',
		'cartes_bancaires' => 'payment-methods/cartes_bancaires-color.svg',
	);

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments fraud service.
	 *
	 * @var WooPaymentsFraudService
	 */
	private WooPaymentsFraudService $fraud_service;

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * WooPay session service.
	 *
	 * @var SessionService
	 */
	private SessionService $woopay_session_service;

	/**
	 * WooPay eligibility rules.
	 *
	 * @var Eligibility
	 */
	private Eligibility $woopay_eligibility;

	/**
	 * Shared frontend styles service.
	 *
	 * @var WooPaymentsFrontendStylesService
	 */
	private WooPaymentsFrontendStylesService $frontend_styles_service;

	/**
	 * Frontend tracking controller.
	 *
	 * @var WooPaymentsFrontendTrackingController
	 */
	private WooPaymentsFrontendTrackingController $frontend_tracking_controller;

	/**
	 * Fraud-prevention service.
	 *
	 * @var WooPaymentsFraudPreventionService
	 */
	private WooPaymentsFraudPreventionService $fraud_prevention_service;

	/**
	 * WooPayments payment method definition registry.
	 *
	 * @var PaymentMethodRegistry
	 */
	private PaymentMethodRegistry $payment_method_registry;

	/**
	 * Express checkout service.
	 *
	 * @var WooPaymentsExpressCheckoutService|null
	 */
	private ?WooPaymentsExpressCheckoutService $express_checkout_service = null;

	/**
	 * Registrar of the script handles the WooPayments shopper scripts share.
	 *
	 * @var WooPaymentsFrontendAssets|null
	 */
	private ?WooPaymentsFrontendAssets $frontend_assets = null;

	/**
	 * Whether the aggregate classic checkout config has been localized.
	 *
	 * @var bool
	 */
	private bool $base_classic_config_localized = false;

	/**
	 * The classic payment-list config base built last in this request, with the key of the inputs it was built from.
	 *
	 * Client 11.1.0 builds its classic config once per request: payment_fields() builds it only while the checkout
	 * script is not yet enqueued, then enqueues the script, so every later gateway reuses the first build
	 * (includes/class-wc-payments-checkout.php:409-424). This class renders one config per gateway, so it shares the base
	 * the same way and builds again only when an input of the base changes (get_payment_list_config_base_key()) or a new
	 * request starts (reset_config_memos()).
	 *
	 * @var array{key:string,base:array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}}|null
	 */
	private ?array $payment_list_config_base = null;

	/**
	 * The Blocks config base built last in the current Blocks registration pass, with the key of the inputs it was built from.
	 *
	 * The Blocks integration registers one payment method per split gateway where client 11.1.0 registers one, so the
	 * gateways share this base, and it is built again when an input of the base changes (get_blocks_config_base_key())
	 * or a new request or registration pass starts (reset_config_memos()).
	 *
	 * @var array{key:string,base:array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}}|null
	 */
	private ?array $blocks_config_base = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService              $account_service              WooPayments account service.
	 * @param SessionService                         $woopay_session_service       WooPay session service.
	 * @param Eligibility                            $woopay_eligibility           WooPay eligibility rules.
	 * @param WooPaymentsFrontendStylesService       $frontend_styles_service      Shared frontend styles service.
	 * @param WooPaymentsFrontendTrackingController  $frontend_tracking_controller Frontend tracking controller.
	 * @param PaymentMethodRegistry                  $payment_method_registry      Payment method registry.
	 * @param WooPaymentsFraudPreventionService|null $fraud_prevention_service  Optional fraud-prevention service.
	 * @param WooPaymentsCustomerService|null        $customer_service          Optional WooPayments customer service.
	 */
	final public function init(
		WooPaymentsAccountService $account_service,
		SessionService $woopay_session_service,
		Eligibility $woopay_eligibility,
		WooPaymentsFrontendStylesService $frontend_styles_service,
		WooPaymentsFrontendTrackingController $frontend_tracking_controller,
		PaymentMethodRegistry $payment_method_registry,
		?WooPaymentsFraudPreventionService $fraud_prevention_service = null,
		?WooPaymentsCustomerService $customer_service = null
	): void {
		$this->account_service              = $account_service;
		$this->woopay_session_service       = $woopay_session_service;
		$this->woopay_eligibility           = $woopay_eligibility;
		$this->frontend_styles_service      = $frontend_styles_service;
		$this->frontend_tracking_controller = $frontend_tracking_controller;
		$this->payment_method_registry      = $payment_method_registry;

		if ( null !== $fraud_prevention_service ) {
			$this->fraud_prevention_service = $fraud_prevention_service;
		}
		if ( null !== $customer_service ) {
			$this->customer_service = $customer_service;
		}
	}

	/**
	 * Ensure classic checkout and order-pay assets exist when no WooPayments fields rendered.
	 *
	 * @param string[] $supports Card gateway support features.
	 */
	public function enqueue_classic_checkout_assets_without_fields( array $supports ): void {
		if ( $this->base_classic_config_localized ) {
			return;
		}

		$this->enqueue_payment_fields_assets( $this->get_payment_fields_js_config( $supports ), $supports );
	}

	/**
	 * Tell whether the account can take payments, so the shopper checkout UI can show.
	 *
	 * @return bool
	 */
	public function should_expose_checkout_surface(): bool {
		return $this->get_account_service()->can_process_payments();
	}

	/**
	 * Get the classic checkout JS config.
	 *
	 * @param string[]                     $supports                  Card gateway support features, sent as `features`.
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return array<string,mixed>
	 */
	public function get_payment_fields_js_config( array $supports, ?PaymentMethodDefinition $payment_method_definition = null ): array {
		return $this->complete_payment_fields_js_config( $this->get_payment_fields_js_config_base( $supports ), $payment_method_definition );
	}

	/**
	 * Get the shared keys a WooPay script needs to reach the store and WooPay: the AJAX URL, the tracker nonce, the WooPay
	 * host and the account mode. The light direct-checkout config reads only these, so it computes no tracking keys.
	 *
	 * @since 11.2.0
	 *
	 * @return array{ajaxUrl:string,platformTrackerNonce:string,woopayHost:string,testMode:bool}
	 */
	public function get_connection_script_config(): array {
		return array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'platformTrackerNonce' => wp_create_nonce( 'platform_tracks_nonce' ),
			'woopayHost'           => SessionService::get_woopay_url(),
			'testMode'             => $this->get_account_service()->is_test_mode_enabled(),
		);
	}

	/**
	 * Get the config keys that both the card checkout scripts and the WooPay script read, built in one place.
	 *
	 * It reads only what its keys need and no customer, styles or session data, so WooPay pages can use it without the
	 * card config. The tracking flag reads the account data, which the account service refreshes and stores when its cache
	 * is due, as on every page that prints these keys.
	 *
	 * @since 11.2.0
	 *
	 * @return array{ajaxUrl:string,platformTrackerNonce:string,tracksUrl:string,tracksRestNonce:string,isShopperTrackingEnabled:bool,woopayHost:string,testMode:bool,wcpayVersionNumber:string}
	 */
	public function get_shared_script_config(): array {
		$connection = $this->get_connection_script_config();

		return array(
			'ajaxUrl'                  => $connection['ajaxUrl'],
			'platformTrackerNonce'     => $connection['platformTrackerNonce'],
			'tracksUrl'                => $this->get_frontend_tracking_controller()->get_tracks_rest_url(),
			'tracksRestNonce'          => wp_create_nonce( 'wp_rest' ),
			// Store-level only, as client 11.2.0 sets it (class-woopay-tracker.php:663-665); WooPay events are checked when recorded.
			'isShopperTrackingEnabled' => $this->get_frontend_tracking_controller()->is_shopper_event_tracking_enabled(),
			'woopayHost'               => $connection['woopayHost'],
			'testMode'                 => $connection['testMode'],
			'wcpayVersionNumber'       => WooPaymentsClientVersion::VERSION,
		);
	}

	/**
	 * Build the gateway-independent part of the payment fields config.
	 *
	 * @param string[]                                                                  $supports        Card gateway support features.
	 * @param array{currency:string,total:int,order_id:int,billing_country:string}|null $payment_context Payment context, when the caller already read it.
	 * @param string                                                                    $button_context  WooPay button context: `cart` on a Blocks cart, else `checkout`.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_payment_fields_js_config_base( array $supports, ?array $payment_context = null, string $button_context = 'checkout' ): array {
		$force_network_saved_cards = $this->should_force_network_saved_cards();
		$saved_cards_enabled       = $this->is_saved_cards_enabled();
		$payment_context           = $payment_context ?? $this->get_payment_context();
		$customer_data             = $this->get_customer_service()->get_prepared_customer_data();
		if ( '' !== $payment_context['billing_country'] ) {
			$customer_data['billing_country'] = $payment_context['billing_country'];
		}
		/**
		 * Filters the account ID used for payment intent confirmation.
		 *
		 * Keeps this name because the WooPay platform site sets it to the account that confirms intents on its checkout.
		 *
		 * @since 11.0.0
		 *
		 * @param string $account_id The account ID for intent confirmation.
		 */
		$account_id_for_intent_confirmation = (string) apply_filters( 'wc_payments_account_id_for_intent_confirmation', '' );
		$shared_script_config               = $this->get_shared_script_config();
		$config                             = array(
			'publishableKey'                           => $this->get_account_service()->get_publishable_key(),
			'accountId'                                => $this->get_account_service()->get_account_id(),
			'locale'                                   => WooPaymentsLocaleUtils::get_stripe_locale(),
			'gatewayId'                                => '',
			'ajaxUrl'                                  => $shared_script_config['ajaxUrl'],
			'wcAjaxUrl'                                => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'paymentMethodsConfig'                     => array(),
			'paymentListWalletsConfig'                 => array(),
			'paymentMethodTypes'                       => array(),
			'testMode'                                 => $shared_script_config['testMode'],
			'enabledBillingFields'                     => $this->get_enabled_billing_fields(),
			'currency'                                 => $payment_context['currency'],
			'cartTotal'                                => $payment_context['total'],
			'storeCountry'                             => WC()->countries->get_base_country(),
			'cartContainsSubscription'                 => SubscriptionMethodPolicy::cart_contains_subscription_or_renewal(),
			'stylesCacheVersion'                       => $this->get_frontend_styles_service()->get_or_create_styles_cache_version(),
			'forceNetworkSavedCards'                   => $force_network_saved_cards,
			'isSavedCardsEnabled'                      => $saved_cards_enabled,
			'customerData'                             => $customer_data,
			'genericErrorMessage'                      => __(
				'There was a problem processing the payment. Please check your email inbox and refresh the page to try again.',
				'woocommerce'
			),
			'fraudServices'                            => $this->get_fraud_services_config(),
			'features'                                 => array_values( $supports ),
			'isCheckout'                               => is_checkout(),
			'isPreview'                                => is_preview(),
			'isShortcodeCheckout'                      => $this->is_shortcode_checkout(),
			'isCoreNativeCheckoutAvailable'            => $this->should_expose_checkout_surface(),
			'isWooPayEnabled'                          => false,
			'isWoopayExpressCheckoutEnabled'           => false,
			'isWoopayFirstPartyAuthEnabled'            => false,
			'isWooPayEmailInputEnabled'                => false,
			'isWooPayDirectCheckoutEnabled'            => false,
			'isWooPayGlobalThemeSupportEnabled'        => false,
			'isShopperTrackingEnabled'                 => $shared_script_config['isShopperTrackingEnabled'],
			'platformTrackerNonce'                     => $shared_script_config['platformTrackerNonce'],
			'tracksUrl'                                => $shared_script_config['tracksUrl'],
			'tracksRestNonce'                          => $shared_script_config['tracksRestNonce'],
			'woopayHost'                               => $shared_script_config['woopayHost'],
			'accountIdForIntentConfirmation'           => $account_id_for_intent_confirmation,
			'wcpayVersionNumber'                       => $shared_script_config['wcpayVersionNumber'],
			'icon'                                     => '',
			'isExpressCheckoutInPaymentMethodsEnabled' => $this->is_express_checkout_in_payment_methods_enabled(),
			'confirmationErrorMessage'                 => __( 'There was a problem confirming your payment.', 'woocommerce' ),
			// Client 11.2.0 client/checkout/utils/fingerprint.js:12-15; the classic checkout shows it when the device fingerprint fails.
			'fingerprintErrorMessage'                  => __( 'An error was encountered when preparing the payment form. Please try again later.', 'woocommerce' ),
			'cardBrandPopoverLabel'                    => __( 'Supported credit card brands', 'woocommerce' ),
			'cardBrandLogosLabel'                      => __( 'Show all supported credit card brands', 'woocommerce' ),
		);
		if ( 0 < $payment_context['order_id'] ) {
			$config['isOrderPay'] = true;
			$config['orderId']    = $payment_context['order_id'];
		}

		if ( $this->should_expose_checkout_surface() ) {
			$config['createSetupIntentNonce'] = wp_create_nonce( 'wcpay_create_setup_intent_nonce' );
			$config                           = array_merge(
				$config,
				$this->get_woopay_session_service()->get_woopay_frontend_config( $button_context )
			);
			$config['forceNetworkSavedCards'] = $force_network_saved_cards;
		}

		if ( $this->is_changing_payment_method_for_subscription() ) {
			$config['isChangingPayment'] = true;
		}

		return array(
			'config'              => $config,
			'saved_cards_enabled' => $saved_cards_enabled,
			'currency'            => $payment_context['currency'],
		);
	}

	/**
	 * Add the gateway-specific keys to a config base and apply the config filter.
	 *
	 * @param array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string} $base                      Config base.
	 * @param PaymentMethodDefinition|null                                               $payment_method_definition Optional payment method definition.
	 * @param bool                                                                       $for_blocks                Whether to leave out the keys only the classic checkout uses.
	 * @return array<string,mixed>
	 */
	private function complete_payment_fields_js_config( array $base, ?PaymentMethodDefinition $payment_method_definition, bool $for_blocks = false ): array {
		$config = $base['config'];
		if ( ! $for_blocks ) {
			$config['paymentListWalletsConfig'] = $this->get_payment_list_wallets_config( $base['saved_cards_enabled'], $payment_method_definition, $base['currency'] );
		}
		$config['gatewayId']            = $this->get_gateway_id_for_payment_method_definition( $payment_method_definition );
		$config['paymentMethodsConfig'] = $this->get_payment_methods_config( $base['saved_cards_enabled'], $payment_method_definition, $base['currency'] );
		$config['paymentMethodTypes']   = $this->get_payment_method_types_for_definition( $payment_method_definition, $config['paymentMethodsConfig'] );
		if ( ! empty( $config['isChangingPayment'] ) ) {
			return $config;
		}

		/**
		 * Allows filtering of the JS config for the WooPayments payment fields.
		 *
		 * @since 11.0.0
		 *
		 * @param array $config The JS config for the payment fields.
		 */
		$filtered_config = apply_filters( 'wcpay_payment_fields_js_config', $config );

		// A callback that returns something other than an array must not break the payment fields.
		return is_array( $filtered_config ) ? $filtered_config : $config;
	}

	/**
	 * Get one gateway's classic payment fields config, which the gateway prints in its payment form and localizes with
	 * enqueue_payment_fields_assets(). The config base is built once per request for the same inputs.
	 *
	 * @since 11.2.0
	 *
	 * @param string[]                     $supports                  Card gateway support features.
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return array<string,mixed>
	 */
	public function get_payment_fields_config( array $supports, ?PaymentMethodDefinition $payment_method_definition = null ): array {
		return $this->complete_payment_fields_js_config( $this->get_payment_list_config_base( $supports ), $payment_method_definition );
	}

	/**
	 * Get the config base for a classic payment-list gateway, built once per request for the same inputs.
	 *
	 * @param string[] $supports Card gateway support features.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_payment_list_config_base( array $supports ): array {
		$payment_context = $this->get_payment_context();
		$key             = $this->get_payment_list_config_base_key( $supports, $payment_context );
		if ( null === $this->payment_list_config_base || $key !== $this->payment_list_config_base['key'] ) {
			$this->payment_list_config_base = array(
				'key'  => $key,
				'base' => $this->get_payment_fields_js_config_base( $supports, $payment_context ),
			);
		}

		return $this->payment_list_config_base['base'];
	}

	/**
	 * Get the key of the config base inputs that can change within one request.
	 *
	 * The card gateway supports, the order or cart total and currency, the order and its billing country, whether the
	 * cart holds a subscription, the shopper (the nonces and the saved-card data are per user), the account and its
	 * effective mode (run_in_test_mode_context() changes it), and whether the page changes a subscription's payment method.
	 * The rest of the base (gateway settings, page type, locale) is fixed for the request, as client 11.1.0's single build
	 * assumes; the session-bound values are cleared with the memos on each request (reset_config_memos()).
	 *
	 * @param string[]                                                             $supports        Card gateway support features.
	 * @param array{currency:string,total:int,order_id:int,billing_country:string} $payment_context Payment context.
	 * @return string
	 */
	private function get_payment_list_config_base_key( array $supports, array $payment_context ): string {
		return (string) wp_json_encode(
			array(
				array_values( $supports ),
				$payment_context,
				SubscriptionMethodPolicy::cart_contains_subscription_or_renewal(),
				get_current_user_id(),
				$this->get_account_service()->get_account_id(),
				$this->get_account_service()->is_test_mode_enabled(),
				$this->is_changing_payment_method_for_subscription(),
			)
		);
	}

	/**
	 * Register the request boundary of the config memos.
	 */
	public function register(): void {
		// Before Blocks registers its payment methods (init priority 5) and before any render.
		add_action( 'init', array( $this, 'reset_config_memos' ), 0 );
	}

	/**
	 * Forget the memoized classic and Blocks config bases, so the next render builds them again.
	 *
	 * The bases hold values bound to the shopper's session and the time: nonces, the Store API cart token in the WooPay
	 * session data, the Sift session ID and the WooPay session email. This service can outlive a request in a persistent
	 * worker or a long-running CLI process, so the memos are cleared on each request's init and at the start of each
	 * Blocks registration pass.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 */
	public function reset_config_memos(): void {
		$this->payment_list_config_base = null;
		$this->blocks_config_base       = null;
	}

	/**
	 * Get Blocks payment method data.
	 *
	 * The gateway-independent base is built once per Blocks registration pass for the same inputs, so the split gateways'
	 * Blocks methods share it and each gateway adds only its own keys.
	 *
	 * @param string[]                     $supports                  Card gateway support features, shared by every Blocks method.
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @param string                       $button_context            WooPay button context of the page, as client 11.2.0 reads the page (class-wc-payments-woopay-button-handler.php:273-281).
	 * @return array<string,mixed>
	 */
	public function get_blocks_payment_method_data( array $supports, ?PaymentMethodDefinition $payment_method_definition = null, string $button_context = 'checkout' ): array {
		$data = $this->complete_payment_fields_js_config( $this->get_blocks_config_base( $supports, $button_context ), $payment_method_definition, true );

		// Sanitize the shopper-facing testing instructions after the wcpay_payment_fields_js_config
		// filter has run. The Blocks checkout script renders this value via dangerouslySetInnerHTML,
		// so escaping it here (server-side, post-filter) prevents third-party filter mutations from
		// shipping raw HTML - such as <script> tags - to the browser.
		if ( isset( $data['paymentMethodsConfig']['card']['testingInstructions'] )
			&& is_string( $data['paymentMethodsConfig']['card']['testingInstructions'] ) ) {
			$data['paymentMethodsConfig']['card']['testingInstructions'] = wp_kses_post( $data['paymentMethodsConfig']['card']['testingInstructions'] );
		}

		if ( ! empty( $data['isWooPayEnabled'] ) ) {
			$data = array_merge(
				$data,
				$this->get_woopay_session_service()->get_save_user_checkout_data()
			);
		}

		return array_merge(
			$data,
			array(
				'title'       => $this->get_blocks_payment_method_title( $payment_method_definition ),
				'description' => $this->get_blocks_payment_method_description( $payment_method_definition ),
				// Client 11.1.0 class-wc-payments-blocks-payment-method.php:102, for the payment method preview in wp-admin.
				'is_admin'    => is_admin(),
				'supports'    => array_values( $supports ),
			)
		);
	}

	/**
	 * Get the config base for the Blocks payment method data, built once per request for the same inputs.
	 *
	 * @param string[] $supports       Card gateway support features.
	 * @param string   $button_context WooPay button context of the page.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_blocks_config_base( array $supports, string $button_context ): array {
		$payment_context = $this->get_payment_context();
		$key             = $this->get_blocks_config_base_key( $supports, $payment_context, $button_context );
		if ( null === $this->blocks_config_base || $key !== $this->blocks_config_base['key'] ) {
			$this->blocks_config_base = array(
				'key'  => $key,
				'base' => $this->get_blocks_payment_fields_js_config_base( $supports, $payment_context, $button_context ),
			);
		}

		return $this->blocks_config_base['base'];
	}

	/**
	 * Get the key of the Blocks config base inputs that can change within one request: the classic base's inputs
	 * (get_payment_list_config_base_key()) and the WooPay button context, which picks the button location's settings.
	 *
	 * @param string[]                                                             $supports        Card gateway support features.
	 * @param array{currency:string,total:int,order_id:int,billing_country:string} $payment_context Payment context.
	 * @param string                                                               $button_context  WooPay button context of the page.
	 * @return string
	 */
	private function get_blocks_config_base_key( array $supports, array $payment_context, string $button_context ): string {
		return (string) wp_json_encode( array( $this->get_payment_list_config_base_key( $supports, $payment_context ), $button_context ) );
	}

	/**
	 * Build the config base for the Blocks payment method data, with client 11.1.0's Blocks key set.
	 *
	 * Before the config filter, client 11.1.0's own filter callbacks add the WooPay button keys, the express checkout
	 * switches and the order-pay keys only while their handlers run; a subscription payment method change skips them all.
	 *
	 * @param string[]                                                             $supports        Card gateway support features.
	 * @param array{currency:string,total:int,order_id:int,billing_country:string} $payment_context Payment context.
	 * @param string                                                               $button_context  WooPay button context of the page.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_blocks_payment_fields_js_config_base( array $supports, array $payment_context, string $button_context ): array {
		$base   = $this->get_payment_fields_js_config_base( $supports, $payment_context, $button_context );
		$config = array_diff_key( $base['config'], array_flip( self::BLOCKS_OMITTED_CONFIG_KEYS ) );

		if ( ! $this->should_add_woopay_button_config() ) {
			$config = array_diff_key( $config, array_flip( self::WOOPAY_BUTTON_CONFIG_KEYS ) );
		}

		if ( empty( $config['isChangingPayment'] ) && $this->can_offer_express_checkout() ) {
			$config = array_merge( $config, $this->get_express_checkout_enabled_config( $base['currency'] ), WooPaymentsOrderPayAccess::get_pay_for_order_page_params() );
		}

		$base['config'] = $config;

		return $base;
	}

	/**
	 * Tell whether express checkout can be offered on this request: the account works, and it is not a cron or XML-RPC
	 * request.
	 *
	 * Client 11.1.0 loads its express checkout handlers on the same check (class-wc-payments.php:1858-1871).
	 *
	 * @return bool
	 */
	private function can_offer_express_checkout(): bool {
		return ! wp_doing_cron()
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& $this->get_account_service()->has_working_account();
	}

	/**
	 * Tell whether the express buttons' config is added on this request: express checkout can be offered, the WooPayments
	 * gateway is enabled and the page is not a subscription's change payment method page.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-button-handler.php:107-126 and class-wc-payments-express-checkout-button-handler.php:74-89.
	 *
	 * @return bool
	 */
	private function should_add_express_button_config(): bool {
		return $this->can_offer_express_checkout()
			&& $this->get_account_service()->is_gateway_enabled()
			&& ! isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request flag.
	}

	/**
	 * Tell whether the WooPay button config is added on this request: the express buttons' config is, and the WooPay
	 * button is enabled.
	 *
	 * Client 11.1.0's WooPay button handler adds its config keys on the same check.
	 *
	 * @return bool
	 */
	private function should_add_woopay_button_config(): bool {
		return $this->should_add_express_button_config() && $this->get_woopay_eligibility()->is_woopay_button_enabled();
	}

	/**
	 * Get client 11.1.0's Apple Pay/Google Pay and Amazon Pay switches for the current page.
	 *
	 * Client 11.1.0 `WC_Payments_Express_Checkout_Button_Handler::payment_fields_js_config()`
	 * (class-wc-payments-express-checkout-button-handler.php:112-124), added while that handler runs.
	 *
	 * @param string $currency Checkout or order-pay currency.
	 * @return array<string,bool>
	 */
	private function get_express_checkout_enabled_config( string $currency ): array {
		$express_service = $this->get_express_checkout_service();
		$payment_request = $express_service->is_payment_request_enabled();
		if ( ! $this->should_add_express_button_config() || ! ( $payment_request || $express_service->is_amazon_pay_usable() ) ) {
			return array();
		}

		$context = $this->get_express_button_context();
		if ( '' === $context ) {
			return array(
				'isPaymentRequestEnabled' => $payment_request,
				'isAmazonPayEnabled'      => $express_service->is_amazon_pay_usable( 'checkout', $currency ),
			);
		}

		$enabled_methods = $express_service->get_enabled_methods_for_context( $context, $currency );

		return array(
			'isPaymentRequestEnabled' => $payment_request && in_array( WooPaymentsExpressPaymentMethodTypes::EXPRESS_METHOD_PAYMENT_REQUEST, $enabled_methods, true ),
			'isAmazonPayEnabled'      => in_array( WooPaymentsExpressPaymentMethodTypes::EXPRESS_METHOD_AMAZON_PAY, $enabled_methods, true ),
		);
	}

	/**
	 * Get the express checkout page context like client 11.1.0's `get_button_context()`, or '' on other pages.
	 *
	 * Client 11.1.0 class-wc-payments-express-checkout-button-helper.php:255-286,450-468.
	 *
	 * @return string
	 */
	private function get_express_button_context(): string {
		if ( WooPaymentsExpressCheckoutService::is_product_page() ) {
			return 'product';
		}

		if ( is_cart() || has_block( 'woocommerce/cart' ) ) {
			return 'cart';
		}

		$is_checkout = is_checkout();
		if ( $is_checkout && isset( $_GET['pay_for_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request flag.
			return 'pay_for_order';
		}

		return $is_checkout || has_block( 'woocommerce/checkout' ) ? 'checkout' : '';
	}

	/**
	 * Localize a gateway's payment fields config on the classic checkout script and enqueue the classic checkout assets.
	 *
	 * @since 11.2.0
	 *
	 * @param array<string,mixed> $config   Payment fields config, as get_payment_fields_config() returns it.
	 * @param string[]            $supports Card gateway support features; the script needs tokenization-form.js when they include tokenization.
	 */
	public function enqueue_payment_fields_assets( array $config, array $supports ): void {
		$this->register_classic_assets( $supports );
		// The checkout script reads the card gateway's config from the base object, so it is localized once.
		if ( WooPaymentsPersistenceVocabulary::GATEWAY_ID === $config['gatewayId'] ) {
			wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, 'wcpay_core_checkout_config', $config );
			$this->base_classic_config_localized = true;
			$this->maybe_add_woopay_email_check_dependency( $config );
		} else {
			wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, $this->get_classic_script_config_object_name( (string) $config['gatewayId'] ), $config );
		}
		wp_enqueue_style( self::CLASSIC_STYLE_HANDLE );
		wp_enqueue_script( self::CLASSIC_SCRIPT_HANDLE );

		$this->get_fraud_prevention_service()->maybe_enqueue_token_script();

		if ( $this->should_expose_checkout_surface() ) {
			wp_enqueue_script( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE );
		}
	}

	/**
	 * List the WooPay protocol as a dependency of the classic checkout script when the card config turns the WooPay email
	 * check on, so a checkout without it loads no protocol entry.
	 *
	 * The script calls the protocol only to wire that check, when both flags in its localized config are truthy; the flags
	 * are read the same way here, after the config filter.
	 *
	 * @param array<string,mixed> $config The card gateway's payment fields config, as localized on the script.
	 */
	private function maybe_add_woopay_email_check_dependency( array $config ): void {
		if ( ! self::is_truthy_when_localized( $config['isWooPayEnabled'] ?? null ) || ! self::is_truthy_when_localized( $config['isWooPayEmailInputEnabled'] ?? null ) ) {
			return;
		}

		$this->get_frontend_assets()->register_woopay_common_script();
		$script = wp_scripts()->query( self::CLASSIC_SCRIPT_HANDLE, 'registered' );
		if ( $script instanceof \_WP_Dependency && ! in_array( WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE, $script->deps, true ) ) {
			$script->deps[] = WooPaymentsFrontendAssets::WOOPAY_COMMON_SCRIPT_HANDLE;
		}
	}

	/**
	 * Tell whether a top-level config value is truthy in the browser once wp_localize_script() prints it.
	 *
	 * The localizer turns top-level scalars into strings (false becomes '', 0 becomes '0') and prints other values as
	 * JSON, so only an empty string and null are falsy there.
	 *
	 * @param mixed $value Config value.
	 */
	private static function is_truthy_when_localized( $value ): bool {
		if ( is_scalar( $value ) ) {
			return '' !== (string) $value;
		}

		return null !== $value;
	}

	/**
	 * Register the classic checkout assets.
	 *
	 * @param string[] $supports Card gateway support features.
	 * @return void
	 */
	public function register_classic_assets( array $supports = array() ): void {
		$frontend_assets = $this->get_frontend_assets();
		$frontend_assets->register_stripe_script();

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';

		$frontend_assets->register_appearance_script();

		$frontend_assets->register_fingerprint_script();

		$frontend_assets->register_common_script();

		if ( ! wp_script_is( self::CLASSIC_SCRIPT_HANDLE, 'registered' ) ) {
			// The WooPay protocol is added by maybe_add_woopay_email_check_dependency(), only when the card config turns the
			// email check on.
			$dependencies = array( 'jquery', 'wc-checkout', WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, WooPaymentsFrontendAssets::FINGERPRINT_SCRIPT_HANDLE, WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE, WooPaymentsFrontendAssets::COMMON_SCRIPT_HANDLE );
			// tokenization-form.js must listen before this script mounts the card element and fires
			// `wc-credit-card-form-init`, on any theme (client 11.1.0 includes/class-wc-payments-checkout.php:130-131).
			if ( in_array( PaymentGatewayFeature::TOKENIZATION, $supports, true ) ) {
				$this->register_tokenization_form_script();
				$dependencies[] = self::TOKENIZATION_FORM_SCRIPT_HANDLE;
			}

			wp_register_script(
				self::CLASSIC_SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/js/frontend/woopayments-checkout' . $suffix . '.js',
				$dependencies,
				WC_VERSION,
				true
			);
		}

		if ( ! wp_style_is( self::CLASSIC_STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::CLASSIC_STYLE_HANDLE,
				WC()->plugin_url() . '/assets/css/woopayments-checkout.css',
				array(),
				WC_VERSION
			);
			wp_style_add_data( self::CLASSIC_STYLE_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Register core's tokenization-form.js as WC_Payment_Gateway::tokenization_script() does, when no gateway has yet.
	 *
	 * A gateway that renders saved payment methods enqueues it itself; this keeps the dependency resolvable on a page
	 * where only the checkout script loads, so WordPress does not drop that script.
	 *
	 * @return void
	 */
	private function register_tokenization_form_script(): void {
		if ( wp_script_is( self::TOKENIZATION_FORM_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::TOKENIZATION_FORM_SCRIPT_HANDLE,
			plugins_url( '/assets/js/frontend/tokenization-form' . ( Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min' ) . '.js', WC_PLUGIN_FILE ),
			array( 'jquery' ),
			WC()->version,
			false
		);
		wp_localize_script(
			self::TOKENIZATION_FORM_SCRIPT_HANDLE,
			'wc_tokenization_form_params',
			array(
				'is_registration_required' => WC()->checkout()->is_registration_required(),
				'is_logged_in'             => is_user_logged_in(),
			)
		);
	}

	/**
	 * Get the WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( ! isset( $this->account_service ) ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Get the WooPayments customer service.
	 *
	 * @return WooPaymentsCustomerService
	 */
	private function get_customer_service(): WooPaymentsCustomerService {
		if ( ! isset( $this->customer_service ) ) {
			$this->customer_service = wc_get_container()->get( WooPaymentsCustomerService::class );
		}

		return $this->customer_service;
	}

	/**
	 * Get the WooPay session service.
	 *
	 * @return SessionService
	 */
	private function get_woopay_session_service(): SessionService {
		if ( ! isset( $this->woopay_session_service ) ) {
			$this->woopay_session_service = wc_get_container()->get( SessionService::class );
		}

		return $this->woopay_session_service;
	}

	/**
	 * Get the WooPay eligibility rules.
	 *
	 * @return Eligibility
	 */
	private function get_woopay_eligibility(): Eligibility {
		if ( ! isset( $this->woopay_eligibility ) ) {
			$this->woopay_eligibility = wc_get_container()->get( Eligibility::class );
		}

		return $this->woopay_eligibility;
	}

	/**
	 * Get the WooPayments fraud-prevention service.
	 *
	 * @return WooPaymentsFraudPreventionService
	 */
	private function get_fraud_prevention_service(): WooPaymentsFraudPreventionService {
		if ( ! isset( $this->fraud_prevention_service ) ) {
			$this->fraud_prevention_service = wc_get_container()->get( WooPaymentsFraudPreventionService::class );
		}

		return $this->fraud_prevention_service;
	}

	/**
	 * Get the WooPayments gateway ID for a payment method definition.
	 *
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_gateway_id_for_payment_method_definition( ?PaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return WooPaymentsPersistenceVocabulary::GATEWAY_ID;
		}

		return WooPaymentsPersistenceVocabulary::GATEWAY_ID_PREFIX . $payment_method_definition->get_id();
	}

	/**
	 * Get the classic checkout config object name for a gateway.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return string
	 */
	private function get_classic_script_config_object_name( string $gateway_id ): string {
		$config_suffix = preg_replace( '/[^A-Za-z0-9_]/', '_', $gateway_id );

		return 'wcpay_core_checkout_config_' . $config_suffix;
	}

	/**
	 * Get payment method config for one split gateway or the shared card surface.
	 *
	 * @param bool                         $saved_cards_enabled       Whether saved cards are enabled.
	 * @param PaymentMethodDefinition|null $payment_method_definition Payment method definition.
	 * @param string                       $currency                  Checkout or order-pay currency.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_payment_methods_config( bool $saved_cards_enabled, ?PaymentMethodDefinition $payment_method_definition, string $currency ): array {
		if ( null !== $payment_method_definition && 'card' !== $payment_method_definition->get_id() ) {
			return array(
				$payment_method_definition->get_id() => $this->get_payment_method_config( $payment_method_definition, $saved_cards_enabled ),
			);
		}

		if ( ! in_array( 'card', $this->get_enabled_payment_method_ids(), true ) ) {
			return array();
		}

		$config = array(
			'card' => array(
				'id'                     => 'card',
				'gatewayId'              => WooPaymentsPersistenceVocabulary::GATEWAY_ID,
				'title'                  => __( 'Card', 'woocommerce' ),
				'label'                  => __( 'Card', 'woocommerce' ),
				'isReusable'             => true,
				'isBnpl'                 => false,
				'isExpressCheckout'      => false,
				'forceNetworkSavedCards' => $this->should_force_network_saved_cards(),
				'cardBrandIcons'         => $this->get_card_brand_icons(),
				'showSaveOption'         => $this->should_show_card_save_option( $saved_cards_enabled ),
				'testingInstructions'    => $this->get_card_testing_instructions(),
				'countries'              => array(),
			),
		);

		if ( $this->should_fold_link_into_card( $currency ) ) {
			$link_definition = $this->payment_method_registry->get( 'link' );
			if ( null !== $link_definition ) {
				$config['link'] = $this->get_payment_method_config( $link_definition, $saved_cards_enabled );
			}
		}

		return $config;
	}

	/**
	 * Get custom-button wallet config for express methods placed in the payment-method list.
	 *
	 * @param bool                         $saved_cards_enabled       Whether saved cards are enabled.
	 * @param PaymentMethodDefinition|null $payment_method_definition Payment method definition.
	 * @param string                       $currency                  Checkout or order-pay currency.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_payment_list_wallets_config( bool $saved_cards_enabled, ?PaymentMethodDefinition $payment_method_definition, string $currency ): array {
		if (
			( null !== $payment_method_definition && 'card' !== $payment_method_definition->get_id() )
			|| ! $this->is_express_checkout_in_payment_methods_enabled()
		) {
			return array();
		}

		$config = array();
		foreach ( array( 'apple_pay', 'google_pay' ) as $payment_method_id ) {
			$definition = $this->payment_method_registry->get( $payment_method_id );
			if (
				null === $definition
				|| ! $this->get_account_service()->is_payment_request_method_enabled( $payment_method_id )
				|| ! $this->get_account_service()->is_capability_active( $definition->get_account_capability_key() )
				|| ! $definition->is_available_for( $currency, $this->get_account_service()->get_account_country_or_us() )
			) {
				continue;
			}

			$config[ $payment_method_id ] = $this->get_payment_method_config( $definition, $saved_cards_enabled );
		}

		return $config;
	}

	/**
	 * Build shopper configuration for one payment method definition.
	 *
	 * @param PaymentMethodDefinition $definition          Payment method definition.
	 * @param bool                    $saved_cards_enabled Whether saved cards are enabled.
	 * @return array<string,mixed>
	 */
	private function get_payment_method_config( PaymentMethodDefinition $definition, bool $saved_cards_enabled ): array {
		$is_reusable     = $this->payment_method_definition_supports( $definition, self::PAYMENT_METHOD_CAPABILITY_TOKENIZATION );
		$account_country = $this->get_account_service()->get_account_country_or_us();

		return array(
			'id'                => $definition->get_id(),
			'gatewayId'         => $this->get_gateway_id_for_payment_method_definition( $definition ),
			'title'             => $definition->get_title( $account_country ),
			'label'             => $definition->get_title( $account_country ),
			'icon'              => $this->get_payment_method_icon_url( $definition->get_icon_asset_path( $account_country ) ),
			'darkIcon'          => $this->get_payment_method_icon_url( $definition->get_dark_icon_asset_path( $account_country ) ),
			'isReusable'        => $is_reusable,
			'isBnpl'            => $this->payment_method_definition_supports( $definition, self::PAYMENT_METHOD_CAPABILITY_BUY_NOW_PAY_LATER ),
			'isExpressCheckout' => $this->payment_method_definition_supports( $definition, self::PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT ),
			'showSaveOption'    => $is_reusable && $this->should_show_save_option( $saved_cards_enabled ),
			'countries'         => $definition->get_supported_countries( $account_country ),
		);
	}

	/**
	 * Convert a payment method's plugin-relative asset path to a shopper-facing URL.
	 *
	 * @param string $asset_path Payment method asset path.
	 * @return string
	 */
	private function get_payment_method_icon_url( string $asset_path ): string {
		if ( '' === $asset_path ) {
			return '';
		}

		return WC()->plugin_url() . '/' . ltrim( $asset_path, '/' );
	}

	/**
	 * Tell whether express checkout methods belong in the payment-method list.
	 *
	 * @return bool
	 */
	private function is_express_checkout_in_payment_methods_enabled(): bool {
		return WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled()
			&& $this->get_account_service()->is_gateway_setting_enabled( 'express_checkout_in_payment_methods' );
	}

	/**
	 * Get canonical WooPayments enabled payment method IDs.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_method_ids(): array {
		// Card when none were ever stored; a stored empty list stays empty (client 11.1.0 get_upe_enabled_payment_method_ids()).
		$method_ids = $this->get_account_service()->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );
		$method_ids = '' === $method_ids ? array( 'card' ) : $method_ids;

		$normalized = array();
		foreach ( is_array( $method_ids ) ? $method_ids : array() as $method_id ) {
			if ( ! is_scalar( $method_id ) ) {
				continue;
			}

			$method_id = sanitize_key( (string) $method_id );
			if ( '' !== $method_id && ! in_array( $method_id, $normalized, true ) ) {
				$normalized[] = $method_id;
			}
		}

		return $normalized;
	}

	/**
	 * Tell whether Link should be folded into the card Payment Element.
	 *
	 * @param string $currency Checkout or order-pay currency.
	 * @return bool
	 */
	private function should_fold_link_into_card( string $currency ): bool {
		return $this->payment_method_registry->is_link_folded_into_card( $this->get_account_service(), $currency );
	}

	/**
	 * Resolve the current checkout or authorized order-pay context.
	 *
	 * @return array{currency:string,total:int,order_id:int,billing_country:string}
	 */
	private function get_payment_context(): array {
		$order_id = absint( get_query_var( 'order-pay' ) );

		// A subscription payment-method change arrives on an order-pay URL but
		// collects no payment, so the order behind it must not decide the
		// context: its total would flow into `cartTotal` and make the checkout
		// script build a payment-mode Payment Element for an amount the shopper
		// is not paying. Client 11.1.0 resolves this request to the cart context
		// (class-wc-payments-checkout.php:237-238, :250-253), whose empty-cart
		// total of zero yields a setup-mode element, and this class must produce
		// the same element.
		if ( 0 < $order_id && ! $this->is_changing_payment_method_for_subscription() ) {
			$order = wc_get_order( $order_id );
			// The pay link's key as well as the capability: core grants pay_for_order on a guest order to anyone, and the
			// Checkout block publishes this data before the order-pay endpoint checks the key (client 11.1.0 checks only
			// the capability, class-wc-payments-checkout.php:257-266).
			if ( $order instanceof \WC_Order && WooPaymentsOrderPayAccess::can_pay_with_key( $order, WooPaymentsOrderPayAccess::get_request_order_key() ) ) {
				$currency = '' !== $order->get_currency() ? strtoupper( $order->get_currency() ) : strtoupper( get_woocommerce_currency() );

				return array(
					'currency'        => $currency,
					'total'           => WooPaymentsCurrencyUtils::amount_to_minor_units( (float) $order->get_total(), $currency ),
					'order_id'        => $order->get_id(),
					'billing_country' => strtoupper( $order->get_billing_country() ),
				);
			}
		}

		$currency = strtoupper( get_woocommerce_currency() );
		$total    = WC()->cart instanceof \WC_Cart
			? (float) WC()->cart->get_total( '' )
			: 0.0;

		return array(
			'currency'        => $currency,
			'total'           => WooPaymentsCurrencyUtils::amount_to_minor_units( $total, $currency ),
			'order_id'        => 0,
			'billing_country' => '',
		);
	}

	/**
	 * Get WooPayments fraud services config exposed to checkout scripts.
	 *
	 * @return array<string,mixed>
	 */
	private function get_fraud_services_config(): array {
		return $this->get_fraud_service()->get_fraud_services_config();
	}

	/**
	 * Get the WooPayments fraud service.
	 *
	 * @return WooPaymentsFraudService
	 */
	private function get_fraud_service(): WooPaymentsFraudService {
		if ( ! isset( $this->fraud_service ) ) {
			$this->fraud_service = wc_get_container()->get( WooPaymentsFraudService::class );
		}

		return $this->fraud_service;
	}

	/**
	 * Tell whether the current request is the shortcode checkout.
	 *
	 * @return bool
	 */
	private function is_shortcode_checkout(): bool {
		if ( ! is_checkout() ) {
			return false;
		}

		$post_id = get_queried_object_id();
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post instanceof \WP_Post ) {
			$post = get_queried_object();
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post();
		}

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return has_shortcode( $post->post_content, 'woocommerce_checkout' );
	}

	/**
	 * Get the Stripe payment method types for a payment method definition.
	 *
	 * @param PaymentMethodDefinition|null      $payment_method_definition Optional payment method definition.
	 * @param array<string,array<string,mixed>> $payment_methods_config    Payment method configuration.
	 * @return string[]
	 */
	private function get_payment_method_types_for_definition( ?PaymentMethodDefinition $payment_method_definition, array $payment_methods_config ): array {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return isset( $payment_methods_config['link'] ) ? array( 'card', 'link' ) : array( 'card' );
		}

		return array( $payment_method_definition->get_stripe_payment_method_type() );
	}

	/**
	 * Get the Blocks payment method title.
	 *
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_blocks_payment_method_title( ?PaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return __( 'Card', 'woocommerce' );
		}

		return $payment_method_definition->get_title( $this->get_account_service()->get_account_country_or_us() );
	}

	/**
	 * Get the Blocks payment method description.
	 *
	 * @param PaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_blocks_payment_method_description( ?PaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return __( 'Pay securely using WooPayments.', 'woocommerce' );
		}

		return $payment_method_definition->get_description( $this->get_account_service()->get_account_country_or_us() );
	}

	/**
	 * Tell whether a payment method definition supports a capability.
	 *
	 * @param PaymentMethodDefinition $payment_method_definition Payment method definition.
	 * @param string                  $capability                Capability name.
	 * @return bool
	 */
	private function payment_method_definition_supports( PaymentMethodDefinition $payment_method_definition, string $capability ): bool {
		return in_array( $capability, $payment_method_definition->get_capabilities(), true );
	}

	/**
	 * Tell whether the current order-pay request changes a subscription payment method.
	 *
	 * @return bool
	 */
	private function is_changing_payment_method_for_subscription(): bool {
		if (
			! is_wc_endpoint_url( 'order-pay' ) ||
			! SubscriptionMethodPolicy::is_subscriptions_available() ||
			! isset( $_GET['change_payment_method'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context matching WooCommerce Subscriptions.
			! function_exists( 'wcs_is_subscription' )
		) {
			return false;
		}

		$subscription_id = wc_clean( wp_unslash( $_GET['change_payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request context matching WooCommerce Subscriptions.

		return (bool) wcs_is_subscription( $subscription_id );
	}

	/**
	 * Get shopper-facing card test-mode instructions.
	 *
	 * @return string
	 */
	private function get_card_testing_instructions(): string {
		$test_card_number = $this->get_test_card_for_country( $this->get_account_service()->get_account_country_or_us() );
		// The visible number names the button (client 11.1.0 replaces it with an aria-label), and a polite status
		// announces the copy, as the Multibanco copy buttons do (WooPaymentsOrderSuccessPage).
		$test_card_button = sprintf(
			'<button type="button" class="js-woopayments-copy-test-number" title="%1$s"><i></i><span>%2$s</span></button><span class="js-woopayments-copy-test-number-status screen-reader-text" role="status" aria-live="polite" data-copied-message="%3$s"></span>',
			esc_attr__( 'Copy to clipboard', 'woocommerce' ),
			esc_html( $test_card_number ),
			esc_attr__( 'Copied to clipboard.', 'woocommerce' )
		);
		$testing_guide    = sprintf(
			'<a href="%1$s" target="_blank">%2$s</a>',
			esc_url( 'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/#test-cards' ),
			esc_html__( 'testing guide', 'woocommerce' )
		);

		return sprintf(
			/* translators: 1: Test card copy button, 2: Link to the WooPayments testing guide. */
			__( 'Use test card %1$s or refer to our %2$s.', 'woocommerce' ),
			$test_card_button,
			$testing_guide
		);
	}

	/**
	 * Get shopper-facing card brand icon data.
	 *
	 * @return array<int,array{id:string,alt:string,src:string}>
	 */
	private function get_card_brand_icons(): array {
		$icons = array();

		foreach ( self::get_card_brand_icon_labels() as $brand => $label ) {
			$asset_path = self::CARD_BRAND_ICON_ASSETS[ $brand ] ?? 'payment-methods/' . $brand . '.svg';
			$icons[]    = array(
				'id'  => $brand,
				'alt' => $label,
				'src' => WC()->plugin_url() . '/assets/images/' . $asset_path,
			);
		}

		return $icons;
	}

	/**
	 * Get shopper-facing card brand labels for the store base country, for the checkout config and the gateway icon.
	 *
	 * Client 11.1.0 adds Cartes Bancaires for stores based in France, reading the storeCountry it sends
	 * (`client/utils/card-brands.ts:43-50`, `includes/class-wc-payments-checkout.php:270`).
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,string> Labels keyed by card brand.
	 */
	public static function get_card_brand_icon_labels(): array {
		if ( 'FR' === WC()->countries->get_base_country() ) {
			return array_merge( self::CARD_BRAND_ICONS, self::FR_CARD_BRAND_ICONS );
		}

		return self::CARD_BRAND_ICONS;
	}

	/**
	 * Tell whether card checkout should use Stripe platform behavior for saved payment details: network saved cards are
	 * on, or Stripe initializes through the platform account because WooPay applies. WooPay eligibility owns that one
	 * predicate, which the card and the WooPay config both read (client 11.1.0 class-wc-payments-checkout.php:194, :599).
	 *
	 * @return bool
	 */
	private function should_force_network_saved_cards(): bool {
		return $this->get_account_service()->is_network_saved_cards_enabled() || $this->get_woopay_eligibility()->should_use_stripe_platform_on_checkout_page();
	}

	/**
	 * Tell whether saved cards are enabled in the gateway settings.
	 *
	 * @return bool
	 */
	private function is_saved_cards_enabled(): bool {
		$value = $this->get_account_service()->get_gateway_setting( 'saved_cards', 'yes' );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Tell whether card checkout should show the WooCommerce save-payment option.
	 *
	 * @param bool $saved_cards_enabled Whether saved cards are enabled.
	 * @return bool
	 */
	private function should_show_card_save_option( bool $saved_cards_enabled ): bool {
		if ( is_user_logged_in() && $this->get_woopay_eligibility()->is_woopay_enabled() ) {
			return false;
		}

		return $this->should_show_save_option( $saved_cards_enabled );
	}

	/**
	 * Tell whether a reusable payment method should show the WooCommerce save-payment option.
	 *
	 * The logged-in WooPay check applies to cards only (should_show_card_save_option()), as in client 11.1.0
	 * (class-wc-payments-checkout.php:610-619).
	 *
	 * @param bool $saved_cards_enabled Whether saved cards are enabled.
	 * @return bool
	 */
	private function should_show_save_option( bool $saved_cards_enabled ): bool {
		return $saved_cards_enabled && ! SubscriptionMethodPolicy::cart_contains_subscription_or_renewal();
	}

	/**
	 * Get the country-specific test card number.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	private function get_test_card_for_country( string $country ): string {
		$country = strtoupper( $country );

		return self::COUNTRY_TEST_CARDS[ $country ] ?? self::COUNTRY_TEST_CARDS['US'];
	}

	/**
	 * Get enabled billing field requirements.
	 *
	 * @return array<string,array{required:bool}>
	 */
	private function get_enabled_billing_fields(): array {
		$enabled_fields = array();
		$billing_fields = WC()->checkout()->get_checkout_fields( 'billing' );
		foreach ( $billing_fields as $field_key => $field_options ) {
			if ( isset( $field_options['enabled'] ) && ! $field_options['enabled'] ) {
				continue;
			}

			$enabled_fields[ (string) $field_key ] = array(
				'required' => ! empty( $field_options['required'] ),
			);
		}

		return $enabled_fields;
	}

	/**
	 * Get the shared frontend styles service.
	 *
	 * @return WooPaymentsFrontendStylesService
	 */
	private function get_frontend_styles_service(): WooPaymentsFrontendStylesService {
		if ( ! isset( $this->frontend_styles_service ) ) {
			$this->frontend_styles_service = wc_get_container()->get( WooPaymentsFrontendStylesService::class );
		}

		return $this->frontend_styles_service;
	}

	/**
	 * Get the express checkout service.
	 *
	 * @return WooPaymentsExpressCheckoutService
	 */
	private function get_express_checkout_service(): WooPaymentsExpressCheckoutService {
		if ( null === $this->express_checkout_service ) {
			$this->express_checkout_service = wc_get_container()->get( WooPaymentsExpressCheckoutService::class );
		}

		return $this->express_checkout_service;
	}

	/**
	 * Get the registrar of the shared WooPayments script handles.
	 *
	 * @return WooPaymentsFrontendAssets
	 */
	private function get_frontend_assets(): WooPaymentsFrontendAssets {
		if ( null === $this->frontend_assets ) {
			$this->frontend_assets = wc_get_container()->get( WooPaymentsFrontendAssets::class );
		}

		return $this->frontend_assets;
	}

	/**
	 * Get the frontend tracking controller.
	 *
	 * @return WooPaymentsFrontendTrackingController
	 */
	private function get_frontend_tracking_controller(): WooPaymentsFrontendTrackingController {
		if ( ! isset( $this->frontend_tracking_controller ) ) {
			$this->frontend_tracking_controller = wc_get_container()->get( WooPaymentsFrontendTrackingController::class );
		}

		return $this->frontend_tracking_controller;
	}
}
