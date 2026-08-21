<?php
/**
 * WooPaymentsCheckoutBridge class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
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
	 * WooPayments checkout base support features exposed to Checkout Blocks.
	 *
	 * @var string[]
	 */
	private const BASE_BLOCKS_SUPPORTS = array(
		'products',
	);

	/**
	 * WooPayments subscription support features exposed to Checkout Blocks when WCS is active.
	 *
	 * @var string[]
	 */
	private const SUBSCRIPTION_BLOCKS_SUPPORTS = array(
		'subscriptions',
		'multiple_subscriptions',
		'subscription_cancellation',
		'subscription_suspension',
		'subscription_reactivation',
		'subscription_amount_changes',
		'subscription_date_changes',
		'subscription_payment_method_change',
		'subscription_payment_method_change_customer',
		'subscription_payment_method_change_admin',
	);

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
	 * Core-owned classic checkout style handle.
	 */
	private const CLASSIC_STYLE_HANDLE = 'wc-woopayments-checkout';

	/**
	 * Stripe.js script handle.
	 */
	private const STRIPE_SCRIPT_HANDLE = 'stripe';

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
	 * @var array<string,string>
	 */
	private const CARD_BRAND_ICON_ASSETS = array(
		'visa'             => 'payment-methods-cards/visa.svg',
		'mastercard'       => 'payment-methods-cards/mastercard.svg',
		'amex'             => 'payment-methods-cards/amex.svg',
		'discover'         => 'payment-methods-cards/discover.svg',
		'jcb'              => 'woopayments-card-brands/jcb.svg',
		'unionpay'         => 'woopayments-card-brands/unionpay.svg',
		'cartes_bancaires' => 'payment-methods-cards/cartes_bancaires.svg',
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

		if ( false === has_action( 'woocommerce_after_checkout_form', array( $this, 'handle_woocommerce_after_checkout_form' ) ) ) {
			add_action( 'woocommerce_after_checkout_form', array( $this, 'handle_woocommerce_after_checkout_form' ) );
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
		if ( false === has_action( 'woocommerce_pay_order_before_payment', array( $this, 'handle_woocommerce_after_checkout_form' ) ) ) {
			add_action( 'woocommerce_pay_order_before_payment', array( $this, 'handle_woocommerce_after_checkout_form' ) );
		}
	}

	/**
	 * Ensure classic checkout and order-pay assets exist when no WooPayments fields rendered.
	 *
	 * @internal
	 */
	public function handle_woocommerce_after_checkout_form(): void {
		if ( $this->base_classic_config_localized ) {
			return;
		}

		$this->enqueue_classic_checkout_assets( $this->get_payment_fields_js_config() );
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
			$this->get_frontend_tracking_controller()->record_user_event(
				'checkout_page_view',
				array(
					'theme_type'        => $theme_type,
					'woopay_enabled'    => $this->get_woopay_session_service()->is_woopay_enabled(),
					'record_event_data' => array( 'track_on_all_stores' => true ),
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
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return array<string,mixed>
	 */
	public function get_payment_fields_js_config( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): array {
		$force_network_saved_cards = $this->should_force_network_saved_cards();
		$saved_cards_enabled       = $this->is_saved_cards_enabled();
		$payment_context           = $this->get_payment_context();
		$payment_methods_config    = $this->get_payment_methods_config( $saved_cards_enabled, $payment_method_definition, $payment_context['currency'] );
		$payment_list_wallets      = $this->get_payment_list_wallets_config( $saved_cards_enabled, $payment_method_definition, $payment_context['currency'] );
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
			'gatewayId'                                => $this->get_gateway_id_for_payment_method_definition( $payment_method_definition ),
			'ajaxUrl'                                  => admin_url( 'admin-ajax.php' ),
			'wcAjaxUrl'                                => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'paymentMethodsConfig'                     => $payment_methods_config,
			'paymentListWalletsConfig'                 => $payment_list_wallets,
			'paymentMethodTypes'                       => $this->get_payment_method_types_for_definition( $payment_method_definition, $payment_methods_config ),
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
			'features'                                 => $this->get_blocks_supports(),
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
			'wcpayVersionNumber'                       => defined( 'WC_VERSION' ) ? WC_VERSION : '',
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

			return $config;
		}

		/**
		 * Allows filtering of the JS config for the WooPayments payment fields.
		 *
		 * @since 11.0.0
		 *
		 * @param array $config The JS config for the payment fields.
		 */
		return apply_filters( 'wcpay_payment_fields_js_config', $config );
	}

	/**
	 * Render the classic checkout payment fields.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return void
	 */
	public function render_payment_fields( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): void {
		$config      = $this->get_payment_fields_js_config( $payment_method_definition );
		$json_config = wp_json_encode( $config );

		if ( ! is_string( $json_config ) ) {
			$json_config = '{}';
		}

		$this->enqueue_classic_checkout_assets( $config );

		echo '<div id="wcpay-core-checkout-form" class="wcpay-core-checkout-form" data-wcpay-config="' . esc_attr( $json_config ) . '">';

		if ( ! empty( $config['testMode'] ) ) {
			$testing_instructions = $config['paymentMethodsConfig']['card']['testingInstructions'] ?? '';
			if ( is_string( $testing_instructions ) && '' !== $testing_instructions ) {
				echo '<p class="wcpay-core-test-mode-instructions testmode-info">';
				echo wp_kses_post( $testing_instructions );
				echo '</p>';
			}
		}

		echo '<div id="wcpay-core-payment-element" class="wcpay-core-payment-element"></div>';
		echo '<div id="wcpay-core-payment-errors" class="woocommerce-error wcpay-core-payment-errors" role="alert" hidden></div>';

		if ( ! $this->should_expose_checkout_surface() ) {
			echo '<p class="woocommerce-info wcpay-core-checkout-unavailable">';
			echo esc_html__( 'WooPayments checkout is not available right now. Please choose another payment method.', 'woocommerce' );
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Get Blocks payment method data.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 * @return array<string,mixed>
	 */
	public function get_blocks_payment_method_data( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ): array {
		$data = $this->get_payment_fields_js_config( $payment_method_definition );

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
				'supports'    => $this->get_blocks_supports(),
			)
		);
	}

	/**
	 * Localize and enqueue the shared classic checkout assets.
	 *
	 * @param array<string,mixed> $config Checkout configuration.
	 */
	private function enqueue_classic_checkout_assets( array $config ): void {
		$this->register_classic_assets();
		wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, $this->get_classic_script_config_object_name( (string) $config['gatewayId'] ), $config );
		if ( OrderPaymentStore::GATEWAY_ID === $config['gatewayId'] ) {
			wp_localize_script( self::CLASSIC_SCRIPT_HANDLE, 'wcpay_core_checkout_config', $config );
			$this->base_classic_config_localized = true;
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
			wp_enqueue_script( self::STRIPE_SCRIPT_HANDLE );
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
	 * Register the classic checkout assets.
	 *
	 * @return void
	 */
	public function register_classic_assets(): void {
		if ( ! wp_script_is( self::STRIPE_SCRIPT_HANDLE, 'registered' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_register_script( self::STRIPE_SCRIPT_HANDLE, 'https://js.stripe.com/v3/', array(), null, true );
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';

		WooPaymentsFrontendAssets::register_appearance_script();

		if ( ! wp_script_is( self::CLASSIC_SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::CLASSIC_SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/js/frontend/woopayments-checkout' . $suffix . '.js',
				array( 'jquery', 'wc-checkout', self::STRIPE_SCRIPT_HANDLE, WooPaymentsFrontendAssets::APPEARANCE_SCRIPT_HANDLE ),
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
				'supports'               => $this->get_blocks_supports(),
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
				|| ! $this->is_payment_method_capability_active( $definition )
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
			'showSaveOption'    => $is_reusable && $this->should_show_card_save_option( $saved_cards_enabled ),
			'supports'          => $this->get_blocks_supports(),
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
	 * Tell whether one definition's account capability is active.
	 *
	 * @param WooPaymentsPaymentMethodDefinition $definition Payment method definition.
	 * @return bool
	 */
	private function is_payment_method_capability_active( WooPaymentsPaymentMethodDefinition $definition ): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();

		return 'active' === ( $capabilities[ $definition->get_account_capability_key() ] ?? null );
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
		if ( ! in_array( 'card', $enabled_method_ids, true ) || ! in_array( 'link', $enabled_method_ids, true ) ) {
			return false;
		}

		$link_definition = $this->get_payment_method_registry()->get( 'link' );
		if ( null === $link_definition || ! $link_definition->is_available_for( $currency, $this->get_account_country() ) ) {
			return false;
		}

		$account_data = $this->get_account_service()->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();
		$fees         = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();

		return 'active' === ( $capabilities['link_payments'] ?? null ) && array_key_exists( 'link', $fees );
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
			if ( $order instanceof \WC_Order && current_user_can( 'pay_for_order', $order->get_id() ) ) {
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
	 * Get WooPayments support features exposed to Checkout Blocks.
	 *
	 * @return string[]
	 */
	private function get_blocks_supports(): array {
		$supports = self::BASE_BLOCKS_SUPPORTS;

		if ( $this->is_subscriptions_enabled() ) {
			$supports = array_merge( $supports, self::SUBSCRIPTION_BLOCKS_SUPPORTS );
		}

		return array_values( array_unique( $supports ) );
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
	 * Tell whether WooCommerce Subscriptions support is available for Blocks.
	 *
	 * @return bool
	 */
	private function is_subscriptions_enabled(): bool {
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$version = isset( \WC_Subscriptions::$version ) ? (string) \WC_Subscriptions::$version : '';

			return '' !== $version && version_compare( $version, '2.2.0', '>=' );
		}

		return class_exists( 'WC_Subscriptions_Core_Plugin' );
	}

	/**
	 * Tell whether the current order-pay request changes a subscription payment method.
	 *
	 * @return bool
	 */
	private function is_changing_payment_method_for_subscription(): bool {
		if (
			! is_wc_endpoint_url( 'order-pay' ) ||
			! $this->is_subscriptions_enabled() ||
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
		$test_card_button = sprintf(
			'<button type="button" class="js-woopayments-copy-test-number" aria-label="%1$s" title="%2$s"><i></i><span>%3$s</span></button>',
			esc_attr__( 'Click to copy the test number to clipboard', 'woocommerce' ),
			esc_attr__( 'Copy to clipboard', 'woocommerce' ),
			esc_html( $test_card_number )
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
		return $this->is_truthy_gateway_setting( 'force_network_saved_cards' ) || $this->should_use_stripe_platform_for_card_checkout();
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

		return $saved_cards_enabled && ! $this->cart_contains_subscription();
	}

	/**
	 * Tell whether card checkout should initialize Stripe through the platform account.
	 *
	 * @return bool
	 */
	private function should_use_stripe_platform_for_card_checkout(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();
		if ( empty( $account_data['platform_checkout_eligible'] ) || 'yes' !== $this->get_string_gateway_setting( 'platform_checkout', 'no' ) ) {
			return false;
		}

		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		return function_exists( 'WC' ) &&
			WC() &&
			WC()->cart instanceof \WC_Cart &&
			! WC()->cart->is_empty() &&
			WC()->cart->needs_payment();
	}

	/**
	 * Get a string WooPayments gateway setting.
	 *
	 * @param string $key Setting key.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_string_gateway_setting( string $key, string $fallback ): string {
		$value = $this->get_account_service()->get_gateway_setting( $key, $fallback );

		return is_scalar( $value ) && '' !== (string) $value ? sanitize_text_field( (string) $value ) : $fallback;
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
	 * Tell whether the current cart contains a subscription.
	 *
	 * @return bool
	 */
	private function cart_contains_subscription(): bool {
		return class_exists( '\WC_Subscriptions_Cart' ) &&
			is_callable( array( '\WC_Subscriptions_Cart', 'cart_contains_subscription' ) ) &&
			\WC_Subscriptions_Cart::cart_contains_subscription();
	}

	/**
	 * Get a Stripe-compatible locale.
	 *
	 * @return string
	 */
	private function get_stripe_locale(): string {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$locale = strtolower( str_replace( '_', '-', (string) $locale ) );

		return '' !== $locale ? $locale : 'auto';
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
