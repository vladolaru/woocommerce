<?php
/**
 * WooPaymentsCheckoutBridge class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Throwable;

/**
 * Owns the transitional Core checkout surface for the WooPayments card gateway.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsCheckoutBridge implements RegisterHooksInterface {
	/**
	 * Native payment method capability for saved/reusable payment credentials.
	 */
	private const PAYMENT_METHOD_CAPABILITY_TOKENIZATION = 'tokenization';

	/**
	 * Native payment method capability for buy-now-pay-later methods.
	 */
	private const PAYMENT_METHOD_CAPABILITY_BUY_NOW_PAY_LATER = 'buy_now_pay_later';

	/**
	 * Native payment method capability for express checkout methods.
	 */
	private const PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT = 'express_checkout';

	/**
	 * Core-owned classic checkout script handle.
	 */
	private const CLASSIC_SCRIPT_HANDLE = 'wc-woopayments-checkout';

	/**
	 * Handle for the vendored FingerprintJS device-fingerprinting script.
	 */
	private const FINGERPRINT_SCRIPT_HANDLE = 'wc-woopayments-fingerprintjs';

	/**
	 * Core-owned classic checkout style handle.
	 */
	private const CLASSIC_STYLE_HANDLE = 'wc-woopayments-checkout';

	/**
	 * Core's tokenization-form.js handle, registered by WC_Payment_Gateway::tokenization_script().
	 */
	private const TOKENIZATION_FORM_SCRIPT_HANDLE = 'woocommerce-tokenization-form';

	/**
	 * Payment fields config keys left out of the Blocks payment method data.
	 *
	 * The client's Blocks data (class-wc-payments-blocks-payment-method.php:88-104) does not carry them and the
	 * native Blocks bundle does not need them; the classic checkout config keeps them.
	 */
	private const BLOCKS_OMITTED_CONFIG_KEYS = array(
		'confirmationErrorMessage',
		'customerData',
		'isCoreNativeCheckoutBridge',
		'paymentListWalletsConfig',
		'updateOrderStatusNonce',
		'usesLegacyOrderStatusBridge',
		'usesLegacySetupIntentBridge',
		'usesNativeOrderStatusBridge',
		'usesNativeSetupIntentBridge',
		'woopayButtonLabels',
		'woopayAdditionalInfoText',
		'woopayAgreementText',
		'woopayTermsOfServiceLabel',
		'woopayPrivacyPolicyLabel',
		// The Blocks readers fall back to the same text (index.js WooPaySaveUserSection) or read isShopperTrackingEnabled (tracks.js).
		'woopaySaveUserLabel',
		'woopayPhoneLabel',
		'is_shopper_tracking_enabled',
	);

	/**
	 * WooPay button config keys, which the client adds only while its WooPay button handler runs.
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
	 * WooPayments legacy runtime.
	 *
	 * @var WooPaymentsLegacyRuntime
	 */
	private WooPaymentsLegacyRuntime $legacy_runtime;

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
	 * Native WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * WooPay session service.
	 *
	 * @var WooPaymentsWooPaySessionService
	 */
	private WooPaymentsWooPaySessionService $woopay_session_service;

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
	 * @var WooPaymentsPaymentMethodRegistry|null
	 */
	private ?WooPaymentsPaymentMethodRegistry $payment_method_registry = null;

	/**
	 * Express checkout service.
	 *
	 * @var WooPaymentsExpressCheckoutService|null
	 */
	private ?WooPaymentsExpressCheckoutService $express_checkout_service = null;

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter|null
	 */
	private ?NativePaymentsRuntimeArbiter $arbiter = null;

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
	 * (includes/class-wc-payments-checkout.php:409-424). Native renders one config per gateway, so it shares the base
	 * the same way and builds again only when an input of the base changes (get_payment_list_config_base_key()).
	 *
	 * @var array{key:string,base:array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}}|null
	 */
	private ?array $payment_list_config_base = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsLegacyRuntime               $legacy_runtime               WooPayments legacy runtime.
	 * @param WooPaymentsAccountService              $account_service              WooPayments account service.
	 * @param WooPaymentsWooPaySessionService        $woopay_session_service       WooPay session service.
	 * @param WooPaymentsFrontendStylesService       $frontend_styles_service      Shared frontend styles service.
	 * @param WooPaymentsFrontendTrackingController  $frontend_tracking_controller Frontend tracking controller.
	 * @param WooPaymentsFraudPreventionService|null $fraud_prevention_service  Optional fraud-prevention service.
	 * @param WooPaymentsPaymentMethodRegistry|null  $payment_method_registry   Optional payment method registry.
	 * @param WooPaymentsCustomerService|null        $customer_service          Optional native customer service.
	 * @param NativePaymentsRuntimeArbiter|null      $arbiter                   Optional runtime owner arbiter.
	 */
	final public function init(
		WooPaymentsLegacyRuntime $legacy_runtime,
		WooPaymentsAccountService $account_service,
		WooPaymentsWooPaySessionService $woopay_session_service,
		WooPaymentsFrontendStylesService $frontend_styles_service,
		WooPaymentsFrontendTrackingController $frontend_tracking_controller,
		?WooPaymentsFraudPreventionService $fraud_prevention_service = null,
		?WooPaymentsPaymentMethodRegistry $payment_method_registry = null,
		?WooPaymentsCustomerService $customer_service = null,
		?NativePaymentsRuntimeArbiter $arbiter = null
	): void {
		$this->legacy_runtime               = $legacy_runtime;
		$this->account_service              = $account_service;
		$this->woopay_session_service       = $woopay_session_service;
		$this->frontend_styles_service      = $frontend_styles_service;
		$this->frontend_tracking_controller = $frontend_tracking_controller;

		if ( null !== $fraud_prevention_service ) {
			$this->fraud_prevention_service = $fraud_prevention_service;
		}
		if ( null !== $customer_service ) {
			$this->customer_service = $customer_service;
		}

		$this->payment_method_registry = $payment_method_registry;
		$this->arbiter                 = $arbiter;
	}

	/**
	 * Register classic checkout bootstrap hooks.
	 *
	 * @internal
	 */
	public function register() {
		if ( ! $this->get_runtime_arbiter()->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_after_checkout_form', array( $this, 'record_classic_checkout_page_view' ) ) ) {
			add_action( 'woocommerce_after_checkout_form', array( $this, 'record_classic_checkout_page_view' ) );
		}
		if ( false === has_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'record_blocks_checkout_page_view' ) ) ) {
			add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'record_blocks_checkout_page_view' ) );
		}
		if ( false === has_action( 'woocommerce_checkout_order_processed', array( $this, 'record_checkout_order_placed' ) ) ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_checkout_order_placed' ), 10, 2 );
		}
		if ( false === has_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_checkout_order_placed' ) ) ) {
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_checkout_order_placed' ), 10, 2 );
		}
		foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form', 'woocommerce_payments_save_user_in_woopay' ) as $hook ) {
			if ( false === has_action( $hook, array( $this, 'record_shopper_funnel_event' ) ) ) {
				add_action( $hook, array( $this, 'record_shopper_funnel_event' ), 10, 0 );
			}
		}
	}

	/**
	 * Record the client 11.1.0 WooPay_Tracker shopper funnel event for the current hook (`class-woopay-tracker.php:73-81,496-532,608`).
	 *
	 * Page views are queued for the footer script, as the client does; the WooPay sign-up records at once, as in the client.
	 * Cart pages also arm the footer script's "Proceed to checkout" click tracking, the client's `client/cart/index.js`.
	 *
	 * @internal
	 */
	public function record_shopper_funnel_event(): void {
		try {
			$tracking = $this->get_frontend_tracking_controller();
			switch ( current_action() ) {
				case 'woocommerce_after_cart':
					$tracking->queue_user_event( 'cart_page_view', array( 'theme_type' => 'short_code' ) );
					$tracking->track_proceed_to_checkout_clicks( fn(): bool => $this->get_woopay_session_service()->is_woopay_direct_checkout_enabled() );
					break;
				case 'woocommerce_blocks_enqueue_cart_block_scripts_after':
					$tracking->queue_user_event( 'cart_page_view', array( 'theme_type' => 'blocks' ) );
					$tracking->track_proceed_to_checkout_clicks( fn(): bool => $this->get_woopay_session_service()->is_woopay_direct_checkout_enabled() );
					break;
				case 'woocommerce_after_single_product':
					$tracking->queue_user_event( 'product_page_view', array( 'theme_type' => 'short_code' ) );
					break;
				case 'before_woocommerce_pay_form':
					$tracking->queue_user_event( 'pay_for_order_page_view' );
					break;
				case 'woocommerce_payments_save_user_in_woopay':
					$tracking->record_user_event( 'woopay_registered', array( 'source' => 'checkout' ) );
					break;
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt storefront rendering or checkout.
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

		$this->enqueue_classic_checkout_assets( $this->get_payment_fields_js_config( $supports ), $supports );
	}

	/**
	 * Record the classic checkout page-view contract.
	 *
	 * @internal
	 */
	public function record_classic_checkout_page_view(): void {
		$this->record_checkout_page_view( 'short_code' );
	}

	/**
	 * Record the Blocks checkout page-view contract.
	 *
	 * @internal
	 */
	public function record_blocks_checkout_page_view(): void {
		$this->record_checkout_page_view( 'blocks' );
	}

	/**
	 * Record a checkout page view without allowing telemetry failures to interrupt checkout.
	 *
	 * @param string $theme_type Checkout implementation identifier.
	 */
	private function record_checkout_page_view( string $theme_type ): void {
		try {
			$this->get_frontend_tracking_controller()->queue_user_event(
				'checkout_page_view',
				array(
					'theme_type'     => $theme_type,
					'woopay_enabled' => $this->get_woopay_session_service()->is_woopay_enabled(),
				)
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt checkout rendering.
		}
	}

	/**
	 * Record that checkout created a WooPayments order before payment processing begins.
	 *
	 * @internal
	 * @param int|\WC_Order $order          Order ID or Store API order object.
	 * @param mixed         $_checkout_data Optional classic checkout data; unused.
	 */
	public function record_checkout_order_placed( $order, $_checkout_data = null ): void {
		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order || 0 !== strpos( $order->get_payment_method(), 'woocommerce_payments' ) ) {
			return;
		}

		$is_woopay_order = isset( $_SERVER['HTTP_USER_AGENT'] ) && 'WooPay' === $_SERVER['HTTP_USER_AGENT'];
		if ( $is_woopay_order ) {
			return;
		}

		try {
			$this->get_frontend_tracking_controller()->record_user_event(
				'checkout_order_placed',
				array(
					'payment_title'     => $order->get_payment_method_title(),
					'record_event_data' => array( 'track_on_all_stores' => true ),
				)
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt checkout order processing.
		}
	}

	/**
	 * Tell whether the bridge has enough data to expose the shopper checkout UI.
	 *
	 * @return bool
	 */
	public function should_expose_checkout_surface(): bool {
		return $this->get_account_service()->can_process_payments();
	}

	/**
	 * Get the classic checkout JS config.
	 *
	 * @param string[]                                $supports                  Card gateway support features, sent as `features`.
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return array<string,mixed>
	 */
	public function get_payment_fields_js_config( array $supports, ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): array {
		return $this->complete_payment_fields_js_config( $this->get_payment_fields_js_config_base( $supports ), $payment_method_definition );
	}

	/**
	 * Build the gateway-independent part of the payment fields config.
	 *
	 * @param string[]                                                                  $supports        Card gateway support features.
	 * @param array{currency:string,total:int,order_id:int,billing_country:string}|null $payment_context Payment context, when the caller already read it.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_payment_fields_js_config_base( array $supports, ?array $payment_context = null ): array {
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
		 * @since 11.0.0
		 *
		 * @param string $account_id The account ID for intent confirmation.
		 */
		$account_id_for_intent_confirmation = (string) apply_filters( 'wc_payments_account_id_for_intent_confirmation', '' );
		$config                             = array(
			'publishableKey'                           => $this->get_account_service()->get_publishable_key(),
			'accountId'                                => $this->get_account_service()->get_account_id(),
			'locale'                                   => $this->get_stripe_locale(),
			'gatewayId'                                => '',
			'ajaxUrl'                                  => admin_url( 'admin-ajax.php' ),
			'wcAjaxUrl'                                => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'paymentMethodsConfig'                     => array(),
			'paymentListWalletsConfig'                 => array(),
			'paymentMethodTypes'                       => array(),
			'testMode'                                 => $this->get_account_service()->is_test_mode_enabled(),
			'enabledBillingFields'                     => $this->get_enabled_billing_fields(),
			'currency'                                 => $payment_context['currency'],
			'cartTotal'                                => $payment_context['total'],
			'storeCountry'                             => $this->get_account_country(),
			'cartContainsSubscription'                 => $this->cart_contains_subscription(),
			'stylesCacheVersion'                       => $this->get_frontend_styles_service()->get_styles_cache_version(),
			'forceNetworkSavedCards'                   => $force_network_saved_cards,
			'isSavedCardsEnabled'                      => $saved_cards_enabled,
			'customerData'                             => $customer_data,
			'genericErrorMessage'                      => __(
				'There was a problem processing the payment. Please check your email inbox and refresh the page to try again.',
				'woocommerce'
			),
			'fraudServices'                            => $this->get_fraud_services_config(),
			'features'                                 => array_values( $supports ),
			'usesLegacySetupIntentBridge'              => false,
			'usesLegacyOrderStatusBridge'              => false,
			'usesNativeSetupIntentBridge'              => true,
			'usesNativeOrderStatusBridge'              => true,
			'isCheckout'                               => function_exists( 'is_checkout' ) && is_checkout(),
			'isPreview'                                => function_exists( 'is_preview' ) && is_preview(),
			'isShortcodeCheckout'                      => $this->is_shortcode_checkout(),
			'isCoreNativeCheckoutBridge'               => true,
			'isCoreNativeCheckoutAvailable'            => $this->should_expose_checkout_surface(),
			'isWooPayEnabled'                          => false,
			'isWoopayExpressCheckoutEnabled'           => false,
			'isWoopayFirstPartyAuthEnabled'            => false,
			'isWooPayEmailInputEnabled'                => false,
			'isWooPayDirectCheckoutEnabled'            => false,
			'isWooPayGlobalThemeSupportEnabled'        => false,
			'isShopperTrackingEnabled'                 => $this->get_frontend_tracking_controller()->is_shopper_tracking_enabled(),
			'platformTrackerNonce'                     => wp_create_nonce( 'platform_tracks_nonce' ),
			'woopayHost'                               => $this->get_woopay_session_service()->get_woopay_url(),
			'accountIdForIntentConfirmation'           => $account_id_for_intent_confirmation,
			'wcpayVersionNumber'                       => WooPaymentsClientVersion::VERSION,
			'icon'                                     => '',
			'isExpressCheckoutInPaymentMethodsEnabled' => $this->is_express_checkout_in_payment_methods_enabled(),
			'confirmationErrorMessage'                 => __( 'There was a problem confirming your payment.', 'woocommerce' ),
			'fraudPreventionToken'                     => $this->get_fraud_prevention_token(),
		);
		if ( 0 < $payment_context['order_id'] ) {
			$config['isOrderPay'] = true;
			$config['orderId']    = $payment_context['order_id'];
		}

		if ( $this->should_expose_checkout_surface() ) {
			$config['createSetupIntentNonce'] = wp_create_nonce( 'wcpay_create_setup_intent_nonce' );
			$config['updateOrderStatusNonce'] = wp_create_nonce( 'wcpay_update_order_status_nonce' );
			$config                           = array_merge(
				$config,
				$this->get_woopay_session_service()->get_woopay_frontend_config( 'checkout' )
			);
			$config['forceNetworkSavedCards'] = $force_network_saved_cards;
		}

		if ( $this->is_changing_payment_method_for_subscription() ) {
			$config['isChangingPayment'] = true;
		}

		return array(
			'config'              => $config,
			'saved_cards_enabled' => $saved_cards_enabled,
			'currency'            => (string) $payment_context['currency'],
		);
	}

	/**
	 * Add the gateway-specific keys to a config base and apply the config filter.
	 *
	 * @param array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string} $base                      Config base.
	 * @param WooPaymentsPaymentMethodDefinition|null                                    $payment_method_definition Optional payment method definition.
	 * @param bool                                                                       $for_blocks                Whether to leave out the keys only the classic checkout uses.
	 * @return array<string,mixed>
	 */
	private function complete_payment_fields_js_config( array $base, ?WooPaymentsPaymentMethodDefinition $payment_method_definition, bool $for_blocks = false ): array {
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
	 * Render the classic checkout payment fields.
	 *
	 * @param string[]                                $supports                     Card gateway support features.
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition    Optional payment method definition.
	 * @param callable|null                           $render_saved_payment_methods Optional callback that prints the saved payment methods, below the test-mode instructions.
	 * @return void
	 */
	public function render_payment_fields( array $supports, ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null, ?callable $render_saved_payment_methods = null ): void {
		$config      = $this->complete_payment_fields_js_config( $this->get_payment_list_config_base( $supports ), $payment_method_definition );
		$json_config = wp_json_encode( $config );

		if ( ! is_string( $json_config ) ) {
			$json_config = '{}';
		}

		$this->enqueue_classic_checkout_assets( $config, $supports );

		$payment_method_type = null === $payment_method_definition ? 'card' : $payment_method_definition->get_id();

		// The client's classes (`wcpay-upe-form`, `wc-payment-form`, `wcpay-upe-element`) stay beside native's: core's
		// tokenization-form.js hides `.wc-payment-form` while a saved method is selected, and the woocommerce.com theme
		// styles `.payment_box .wc-payment-form .wcpay-upe-element`. The wrapper is the client's own fieldset, inline
		// padding included (client includes/class-wc-payments-checkout.php), so themes and the appearance probe for
		// `.payment_box fieldset` see the same markup.
		echo '<div id="wcpay-core-checkout-form" class="wcpay-core-checkout-form wcpay-upe-form" data-payment-method-type="' . esc_attr( $payment_method_type ) . '" data-wcpay-config="' . esc_attr( $json_config ) . '">';

		if ( ! empty( $config['testMode'] ) ) {
			$testing_instructions = $config['paymentMethodsConfig']['card']['testingInstructions'] ?? '';
			if ( is_string( $testing_instructions ) && '' !== $testing_instructions ) {
				echo '<p class="wcpay-core-test-mode-instructions testmode-info">';
				echo wp_kses_post( $testing_instructions );
				echo '</p>';
			}
		}

		// The client prints the saved payment methods here, below the test-mode instructions (client 11.1.0
		// includes/class-wc-payments-checkout.php:474-499).
		if ( null !== $render_saved_payment_methods ) {
			$render_saved_payment_methods();
		}

		echo '<fieldset style="padding: 7px" class="wc-payment-form">';
		echo '<div id="wcpay-core-payment-element" class="wcpay-core-payment-element wcpay-upe-element" data-payment-method-type="' . esc_attr( $payment_method_type ) . '"></div>';
		echo '</fieldset>';
		echo '<div class="woocommerce-error wcpay-core-payment-errors" role="alert" hidden></div>';

		if ( ! $this->should_expose_checkout_surface() ) {
			echo '<p class="woocommerce-info wcpay-core-checkout-unavailable">';
			echo esc_html__( 'WooPayments checkout is not available right now. Please choose another payment method.', 'woocommerce' );
			echo '</p>';
		}

		echo '</div>';
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
	 * cart holds a subscription, and the shopper (the nonces and the saved-card data are per user). The rest of the
	 * base (account, gateway settings, page type, locale) is fixed for the request, as the client's single build assumes.
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
				$this->cart_contains_subscription(),
				get_current_user_id(),
			)
		);
	}

	/**
	 * Get Blocks payment method data.
	 *
	 * @param string[]                                $supports                  Card gateway support features, shared by every Blocks method.
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @param \ArrayObject|null                       $shared                    Holder that lets split gateways share one config base (client 11.1.0 registers one Blocks method).
	 * @phpstan-param \ArrayObject<string,mixed>|null $shared
	 * @return array<string,mixed>
	 */
	public function get_blocks_payment_method_data( array $supports, ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null, ?\ArrayObject $shared = null ): array {
		if ( null !== $shared && ! isset( $shared['base'] ) ) {
			$shared['base'] = $this->get_blocks_payment_fields_js_config_base( $supports );
		}
		$base = null === $shared ? $this->get_blocks_payment_fields_js_config_base( $supports ) : $shared['base'];
		$data = $this->complete_payment_fields_js_config( $base, $payment_method_definition, true );

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
	 * Build the config base for the Blocks payment method data, with the client's Blocks key set.
	 *
	 * Before the config filter, the client's own filter callbacks add the WooPay button keys, the express checkout
	 * switches and the order-pay keys only while their handlers run; a subscription payment method change skips them all.
	 *
	 * @param string[] $supports Card gateway support features.
	 * @return array{config:array<string,mixed>,saved_cards_enabled:bool,currency:string}
	 */
	private function get_blocks_payment_fields_js_config_base( array $supports ): array {
		$base   = $this->get_payment_fields_js_config_base( $supports );
		$config = array_diff_key( $base['config'], array_flip( self::BLOCKS_OMITTED_CONFIG_KEYS ) );

		if ( ! $this->is_woopay_button_handler_active() ) {
			$config = array_diff_key( $config, array_flip( self::WOOPAY_BUTTON_CONFIG_KEYS ) );
		}

		if ( empty( $config['isChangingPayment'] ) && $this->are_express_checkout_handlers_loaded() ) {
			$config = array_merge( $config, $this->get_express_checkout_enabled_config( $base['currency'] ), $this->get_pay_for_order_config() );
		}

		$base['config'] = $config;

		return $base;
	}

	/**
	 * Tell whether the client loads its express checkout handlers on this request: payments enabled on the account,
	 * and not a cron or XML-RPC request (client 11.1.0 class-wc-payments.php:1858-1871).
	 *
	 * @return bool
	 */
	private function are_express_checkout_handlers_loaded(): bool {
		return ! wp_doing_cron()
			&& ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			&& $this->get_account_service()->has_working_account();
	}

	/**
	 * Tell whether a client express button handler registers its config filter on this request: the WooPayments
	 * gateway is enabled and the page is not a subscription's change payment method page.
	 *
	 * Client 11.1.0 class-wc-payments-woopay-button-handler.php:107-126 and class-wc-payments-express-checkout-button-handler.php:74-89.
	 *
	 * @return bool
	 */
	private function is_express_button_handler_request(): bool {
		return $this->are_express_checkout_handlers_loaded()
			&& $this->get_account_service()->is_gateway_enabled()
			&& ! isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request flag, as in the client.
	}

	/**
	 * Tell whether the client's WooPay button handler adds its config keys on this request.
	 *
	 * @return bool
	 */
	private function is_woopay_button_handler_active(): bool {
		return $this->is_express_button_handler_request() && $this->get_woopay_session_service()->is_woopay_button_enabled();
	}

	/**
	 * Get the client's Apple Pay/Google Pay and Amazon Pay switches for the current page.
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
		if ( ! $this->is_express_button_handler_request() || ! ( $payment_request || $express_service->is_amazon_pay_usable() ) ) {
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
	 * Get the express checkout page context like the client's `get_button_context()`, or '' on other pages.
	 *
	 * Client 11.1.0 class-wc-payments-express-checkout-button-helper.php:255-286,450-468.
	 *
	 * @return string
	 */
	private function get_express_button_context(): string {
		$post = get_post();
		if ( ( function_exists( 'is_product' ) && is_product() ) || ( $post instanceof \WP_Post && has_shortcode( $post->post_content, 'product_page' ) ) ) {
			return 'product';
		}

		if ( ( function_exists( 'is_cart' ) && is_cart() ) || has_block( 'woocommerce/cart' ) ) {
			return 'cart';
		}

		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		if ( $is_checkout && isset( $_GET['pay_for_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request flag, as in the client.
			return 'pay_for_order';
		}

		return $is_checkout || has_block( 'woocommerce/checkout' ) ? 'checkout' : '';
	}

	/**
	 * Get the client's order-pay keys for a pay-for-order link opened by someone allowed to pay the order.
	 *
	 * Client 11.1.0 `add_pay_for_order_params_to_js_config()` (class-wc-payments-express-checkout-button-display-handler.php:183-224).
	 * The visitor's email goes into the page only when WooPaymentsOrderPayAccess::may_put_shopper_email_in_page() allows it.
	 *
	 * @return array<string,mixed>
	 */
	private function get_pay_for_order_config(): array {
		global $wp;

		$order_id = is_object( $wp ) ? ( $wp->query_vars['order-pay'] ?? null ) : null;
		// phpcs:disable WordPress.Security.NonceVerification -- Read-only order-pay link values, as in the client.
		if ( ! $order_id || ! isset( $_GET['pay_for_order'], $_GET['key'] ) || ! current_user_can( 'pay_for_order', $order_id ) ) {
			return array();
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$config = array(
			'order_id'      => $order->get_id(),
			'pay_for_order' => sanitize_text_field( wp_unslash( $_GET['pay_for_order'] ) ),
			'key'           => sanitize_text_field( wp_unslash( $_GET['key'] ) ),
			'billing_email' => WooPaymentsOrderPayAccess::may_put_shopper_email_in_page() ? WooPaymentsOrderPayAccess::get_billing_email_for_current_visitor( $order ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification

		return $config;
	}

	/**
	 * Localize and enqueue the shared classic checkout assets.
	 *
	 * @param array<string,mixed> $config   Checkout configuration.
	 * @param string[]            $supports Card gateway support features.
	 */
	private function enqueue_classic_checkout_assets( array $config, array $supports ): void {
		$this->register_classic_assets( $supports );
		// The checkout script reads the card gateway's config from the base object, so it is localized once.
		if ( OrderPaymentStore::GATEWAY_ID === $config['gatewayId'] ) {
			wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, 'wcpay_core_checkout_config', $config );
			$this->base_classic_config_localized = true;
		} else {
			wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, $this->get_classic_script_config_object_name( (string) $config['gatewayId'] ), $config );
		}
		wp_enqueue_style( self::CLASSIC_STYLE_HANDLE );
		wp_enqueue_script( self::CLASSIC_SCRIPT_HANDLE );

		if ( '' !== $config['fraudPreventionToken'] ) {
			wp_register_script( WooPaymentsFraudPreventionService::TOKEN_NAME, false, array(), WC_VERSION, true );
			wp_enqueue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
			wp_add_inline_script(
				WooPaymentsFraudPreventionService::TOKEN_NAME,
				"window.wcpayFraudPreventionToken = '" . esc_js( (string) $config['fraudPreventionToken'] ) . "';",
				'after'
			);
		}

		if ( $this->should_expose_checkout_surface() ) {
			wp_enqueue_script( WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE );
		}
	}

	/**
	 * Get the runtime owner arbiter.
	 *
	 * @return NativePaymentsRuntimeArbiter
	 */
	private function get_runtime_arbiter(): NativePaymentsRuntimeArbiter {
		if ( ! $this->arbiter instanceof NativePaymentsRuntimeArbiter ) {
			$this->arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		}

		return $this->arbiter;
	}

	/**
	 * Register the vendored FingerprintJS script, which sets `window.FingerprintJS`.
	 *
	 * Classic checkout and the Blocks card script both load it from this one handle
	 * to compute the buyer device fingerprint the platform's risk rules score on.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function register_fingerprint_script(): void {
		if ( wp_script_is( self::FINGERPRINT_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		// Pre-minified upstream UMD build, so there is no suffix variant.
		wp_register_script(
			self::FINGERPRINT_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/js/fingerprintjs/fp.umd.min.js',
			array(),
			WC_VERSION,
			true
		);
	}

	/**
	 * Register the classic checkout assets.
	 *
	 * @param string[] $supports Card gateway support features.
	 * @return void
	 */
	public function register_classic_assets( array $supports = array() ): void {
		WooPaymentsFrontendAssets::register_stripe_script();

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';

		WooPaymentsFrontendAssets::register_appearance_script();

		$this->register_fingerprint_script();

		if ( ! wp_script_is( self::CLASSIC_SCRIPT_HANDLE, 'registered' ) ) {
			$dependencies = array( 'jquery', 'wc-checkout', WooPaymentsFrontendAssets::STRIPE_SCRIPT_HANDLE, self::FINGERPRINT_SCRIPT_HANDLE, WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE );
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
	 * Get the WooPayments legacy runtime.
	 *
	 * @return WooPaymentsLegacyRuntime
	 */
	private function get_legacy_runtime(): WooPaymentsLegacyRuntime {
		if ( ! isset( $this->legacy_runtime ) ) {
			$this->legacy_runtime = wc_get_container()->get( WooPaymentsLegacyRuntime::class );
		}

		return $this->legacy_runtime;
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
	 * Get the native WooPayments customer service.
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
	 * @return WooPaymentsWooPaySessionService
	 */
	private function get_woopay_session_service(): WooPaymentsWooPaySessionService {
		if ( ! isset( $this->woopay_session_service ) ) {
			$this->woopay_session_service = wc_get_container()->get( WooPaymentsWooPaySessionService::class );
		}

		return $this->woopay_session_service;
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
	 * Get the fraud-prevention token exposed to checkout clients.
	 *
	 * @return string
	 */
	private function get_fraud_prevention_token(): string {
		$fraud_prevention_service = $this->get_fraud_prevention_service();
		if ( ! $fraud_prevention_service->has_session() || ! $fraud_prevention_service->is_enabled() ) {
			return '';
		}

		return $fraud_prevention_service->get_token();
	}

	/**
	 * Get the WooPayments gateway ID for a payment method definition.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_gateway_id_for_payment_method_definition( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return OrderPaymentStore::GATEWAY_ID;
		}

		return OrderPaymentStore::GATEWAY_ID . '_' . $payment_method_definition->get_id();
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
	 * @param bool                                    $saved_cards_enabled       Whether saved cards are enabled.
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Payment method definition.
	 * @param string                                  $currency                  Checkout or order-pay currency.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_payment_methods_config( bool $saved_cards_enabled, ?WooPaymentsPaymentMethodDefinition $payment_method_definition, string $currency ): array {
		if ( null !== $payment_method_definition && 'card' !== $payment_method_definition->get_id() ) {
			return array(
				$payment_method_definition->get_id() => $this->get_payment_method_config( $payment_method_definition, $saved_cards_enabled ),
			);
		}

		$enabled_method_ids = $this->get_enabled_payment_method_ids();
		if ( ! empty( $enabled_method_ids ) && ! in_array( 'card', $enabled_method_ids, true ) ) {
			return array();
		}

		$config = array(
			'card' => array(
				'id'                     => 'card',
				'gatewayId'              => OrderPaymentStore::GATEWAY_ID,
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

		if ( $this->should_fold_link_into_card( $enabled_method_ids, $currency ) ) {
			$link_definition = $this->get_payment_method_registry()->get( 'link' );
			if ( null !== $link_definition ) {
				$config['link'] = $this->get_payment_method_config( $link_definition, $saved_cards_enabled );
			}
		}

		return $config;
	}

	/**
	 * Get custom-button wallet config for express methods placed in the payment-method list.
	 *
	 * @param bool                                    $saved_cards_enabled       Whether saved cards are enabled.
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Payment method definition.
	 * @param string                                  $currency                  Checkout or order-pay currency.
	 * @return array<string,array<string,mixed>>
	 */
	private function get_payment_list_wallets_config( bool $saved_cards_enabled, ?WooPaymentsPaymentMethodDefinition $payment_method_definition, string $currency ): array {
		if (
			( null !== $payment_method_definition && 'card' !== $payment_method_definition->get_id() )
			|| ! $this->is_express_checkout_in_payment_methods_enabled()
		) {
			return array();
		}

		$config = array();
		foreach ( array( 'apple_pay', 'google_pay' ) as $payment_method_id ) {
			$definition = $this->get_payment_method_registry()->get( $payment_method_id );
			if (
				null === $definition
				|| ! $this->get_account_service()->is_payment_request_method_enabled( $payment_method_id )
				|| ! $this->get_account_service()->is_capability_active( $definition->get_account_capability_key() )
				|| ! $definition->is_available_for( $currency, $this->get_account_country() )
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
	 * @param WooPaymentsPaymentMethodDefinition $definition          Payment method definition.
	 * @param bool                               $saved_cards_enabled Whether saved cards are enabled.
	 * @return array<string,mixed>
	 */
	private function get_payment_method_config( WooPaymentsPaymentMethodDefinition $definition, bool $saved_cards_enabled ): array {
		$is_reusable     = $this->payment_method_definition_supports( $definition, self::PAYMENT_METHOD_CAPABILITY_TOKENIZATION );
		$account_country = $this->get_account_country();

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
			&& $this->is_truthy_gateway_setting( 'express_checkout_in_payment_methods' );
	}

	/**
	 * Get canonical WooPayments enabled payment method IDs.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_method_ids(): array {
		$method_ids = $this->get_account_service()->get_gateway_setting( 'upe_enabled_payment_method_ids', null );
		if ( ! is_array( $method_ids ) ) {
			$method_ids = $this->get_legacy_runtime()->get_gateway_upe_enabled_payment_method_ids();
		}

		$normalized = array();
		foreach ( $method_ids as $method_id ) {
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
	 * @param string[] $enabled_method_ids Canonical enabled payment method IDs.
	 * @param string   $currency           Checkout or order-pay currency.
	 * @return bool
	 */
	private function should_fold_link_into_card( array $enabled_method_ids, string $currency ): bool {
		unset( $enabled_method_ids );

		return WooPaymentsFeaturePolicy::is_link_folded_into_card(
			$this->get_account_service(),
			$this->get_payment_method_registry(),
			$currency,
			$this->get_legacy_runtime()
		);
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
		// is not paying. The WooPayments client plugin resolves this request to
		// the cart context, whose empty-cart total of zero yields a setup-mode
		// element, and this bridge must produce the same element.
		if ( 0 < $order_id && ! $this->is_changing_payment_method_for_subscription() ) {
			$order = wc_get_order( $order_id );
			// The pay link's key as well as the capability: core grants pay_for_order on a guest order to anyone, and the
			// Checkout block publishes this data before the order-pay endpoint checks the key (client 11.1.0 checks only
			// the capability, class-wc-payments-checkout.php:257-266).
			if ( $order instanceof \WC_Order && WooPaymentsOrderPayAccess::can_pay_with_key( $order, WooPaymentsOrderPayAccess::get_request_order_key() ) ) {
				$currency = '' !== $order->get_currency() ? strtoupper( $order->get_currency() ) : strtoupper( get_woocommerce_currency() );

				return array(
					'currency'        => $currency,
					'total'           => $this->prepare_amount( (float) $order->get_total(), $currency ),
					'order_id'        => $order->get_id(),
					'billing_country' => strtoupper( $order->get_billing_country() ),
				);
			}
		}

		$currency = strtoupper( get_woocommerce_currency() );
		$total    = function_exists( 'WC' ) && WC() && WC()->cart instanceof \WC_Cart
			? (float) WC()->cart->get_total( '' )
			: 0.0;

		return array(
			'currency'        => $currency,
			'total'           => $this->prepare_amount( $total, $currency ),
			'order_id'        => 0,
			'billing_country' => '',
		);
	}

	/**
	 * Convert a display amount to the provider minor-unit convention.
	 *
	 * @param float  $amount   Display amount.
	 * @param string $currency Currency code.
	 * @return int
	 */
	private function prepare_amount( float $amount, string $currency ): int {
		$minor_unit = WooPaymentsCurrencyUtils::get_stripe_minor_unit_for_currency( $currency );

		return (int) round( $amount * ( 10 ** $minor_unit ) );
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
	 * Get the native WooPayments fraud service.
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
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}

		$post_id = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
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
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @param array<string,array<string,mixed>>       $payment_methods_config    Payment method configuration.
	 * @return string[]
	 */
	private function get_payment_method_types_for_definition( ?WooPaymentsPaymentMethodDefinition $payment_method_definition, array $payment_methods_config ): array {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return isset( $payment_methods_config['link'] ) ? array( 'card', 'link' ) : array( 'card' );
		}

		return array( $payment_method_definition->get_stripe_payment_method_type() );
	}

	/**
	 * Get the payment method definition registry.
	 *
	 * @return WooPaymentsPaymentMethodRegistry
	 */
	private function get_payment_method_registry(): WooPaymentsPaymentMethodRegistry {
		if ( null === $this->payment_method_registry ) {
			$this->payment_method_registry = new WooPaymentsPaymentMethodRegistry();
		}

		return $this->payment_method_registry;
	}

	/**
	 * Get the Blocks payment method title.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_blocks_payment_method_title( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return __( 'Card', 'woocommerce' );
		}

		return $payment_method_definition->get_title( $this->get_account_country() );
	}

	/**
	 * Get the Blocks payment method description.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return string
	 */
	private function get_blocks_payment_method_description( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): string {
		if ( null === $payment_method_definition || 'card' === $payment_method_definition->get_id() ) {
			return __( 'Pay securely using WooPayments.', 'woocommerce' );
		}

		return $payment_method_definition->get_description( $this->get_account_country() );
	}

	/**
	 * Tell whether a payment method definition supports a capability.
	 *
	 * @param WooPaymentsPaymentMethodDefinition $payment_method_definition Payment method definition.
	 * @param string                             $capability                Capability name.
	 * @return bool
	 */
	private function payment_method_definition_supports( WooPaymentsPaymentMethodDefinition $payment_method_definition, string $capability ): bool {
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
			! WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available() ||
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
		$test_card_number = $this->get_test_card_for_country( $this->get_account_country() );
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

		foreach ( $this->get_card_brand_icon_labels() as $brand => $label ) {
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
	 * Get shopper-facing card brand labels for the connected account country.
	 *
	 * @return array<string,string>
	 */
	private function get_card_brand_icon_labels(): array {
		if ( 'FR' === $this->get_account_country() ) {
			return array_merge( self::CARD_BRAND_ICONS, self::FR_CARD_BRAND_ICONS );
		}

		return self::CARD_BRAND_ICONS;
	}

	/**
	 * Get the connected account country, falling back to the store base country.
	 *
	 * @return string
	 */
	private function get_account_country(): string {
		$account_data = $this->get_account_service()->get_cached_account_data();
		$country      = isset( $account_data['country'] ) && is_scalar( $account_data['country'] )
			? strtoupper( (string) $account_data['country'] )
			: '';

		if ( '' === $country && function_exists( 'WC' ) && WC() && WC()->countries ) {
			$country = strtoupper( (string) WC()->countries->get_base_country() );
		}

		if ( false !== strpos( $country, ':' ) ) {
			$base_country = strtok( $country, ':' );
			$country      = is_string( $base_country ) ? $base_country : '';
		}

		return '' !== $country ? $country : 'US';
	}

	/**
	 * Tell whether card checkout should use Stripe platform behavior for saved payment details.
	 *
	 * @return bool
	 */
	private function should_force_network_saved_cards(): bool {
		return $this->get_account_service()->is_network_saved_cards_enabled() || $this->should_use_stripe_platform_on_checkout_page();
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
		if ( is_user_logged_in() && $this->get_woopay_session_service()->is_woopay_enabled() ) {
			return false;
		}

		return $this->should_show_save_option( $saved_cards_enabled );
	}

	/**
	 * Tell whether a reusable payment method should show the WooCommerce save-payment option.
	 *
	 * The logged-in WooPay guard applies to cards only, as in the plugin.
	 *
	 * @param bool $saved_cards_enabled Whether saved cards are enabled.
	 * @return bool
	 */
	private function should_show_save_option( bool $saved_cards_enabled ): bool {
		return $saved_cards_enabled && ! $this->cart_contains_subscription();
	}

	/**
	 * Tell whether card checkout initializes Stripe through the platform account because WooPay applies; the WooPay
	 * session service owns the one predicate both the card and the WooPay config read (client 11.1.0
	 * class-wc-payments-checkout.php:194, :599).
	 *
	 * @return bool
	 */
	public function should_use_stripe_platform_on_checkout_page(): bool {
		return $this->get_woopay_session_service()->should_use_stripe_platform_on_checkout_page();
	}

	/**
	 * Tell whether a WooPayments gateway setting is truthy.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_truthy_gateway_setting( string $key ): bool {
		$value = $this->get_account_service()->get_gateway_setting( $key, 'no' );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
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
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->checkout() ) {
			return array();
		}

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
	 * Tell whether the current cart contains a subscription or a subscription renewal.
	 *
	 * Renewal-inclusive, unlike NativeWooPaymentsGateway::cart_contains_subscription(), which ignores renewals.
	 *
	 * @return bool
	 */
	private function cart_contains_subscription(): bool {
		return WooPaymentsSubscriptionMethodPolicy::cart_contains_subscription_or_renewal();
	}

	/**
	 * Get a Stripe-supported locale for the current request.
	 *
	 * @return string
	 */
	private function get_stripe_locale(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();

		return WooPaymentsLocaleUtils::convert_to_stripe_locale( (string) $locale );
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
