<?php
/**
 * NativeWooPaymentsGateway class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\OrderStatus;
use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcomeApplyException;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedRenewalAuthenticationEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionRenewalHooks;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\WooPaymentsLinkToken;
use Exception;
use Throwable;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Token_CC;
use WC_Payment_Gateway_CC;
use WP_Error;

/**
 * Native WooPayments payment gateway shell.
 *
 * Hook-name parity: a handful of the filters/actions this gateway fires intentionally keep the
 * standalone WooPayments **plugin's** hook names (e.g. the `wcpay_` prefix or the plugin's
 * double-prefixed `woocommerce_woocommerce_payments_*` action names) instead of the
 * `woocommerce_woopayments_*` prefix of native-only WooPayments hooks. This is deliberate, not
 * an oversight: extensions in the ecosystem hook those plugin-named hooks, and reusing the exact
 * names preserves their behavior once a site switches from the plugin to the native runtime.
 * Do NOT "normalize" these names to the native prefix — renaming them silently breaks extension
 * compatibility. Each such hook says so in its docblock at the call site.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class NativeWooPaymentsGateway extends WC_Payment_Gateway_CC {

	/**
	 * Untranslated gateway title.
	 */
	private const METHOD_TITLE = 'WooPayments';

	/**
	 * Sentinel the checkout scripts submit in place of a payment method when
	 * client-side payment method creation failed. Matches the WooPayments
	 * client plugin's Payment_Information::PAYMENT_METHOD_ERROR.
	 */
	private const CLIENT_PAYMENT_METHOD_ERROR_SENTINEL = 'woocommerce_payments_payment_method_error';

	/**
	 * Untranslated gateway description.
	 */
	private const METHOD_DESCRIPTION = 'Accept payments with WooPayments.';

	/**
	 * Native payment method capability for saved/reusable payment credentials.
	 */
	private const PAYMENT_METHOD_CAPABILITY_TOKENIZATION = 'tokenization';

	/**
	 * Native payment method capability for express checkout methods.
	 */
	private const PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT = 'express_checkout';

	/**
	 * Provider intention statuses that mean a payment exists to link to.
	 *
	 * Mirrors the WooPayments client plugin's `Intent_Status::AUTHORIZED_STATUSES`,
	 * so an order links to its transaction under exactly the same conditions in both
	 * runtimes.
	 */
	private const AUTHORIZED_INTENTION_STATUSES = array(
		'succeeded',
		'requires_capture',
		'processing',
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
	 * Recommended payment methods cache key.
	 */
	public const RECOMMENDED_PAYMENT_METHODS_CACHE_KEY = 'woocommerce_woocommerce_payments_recommended_payment_methods';

	/**
	 * Recommended payment methods cache TTL.
	 */
	private const RECOMMENDED_PAYMENT_METHODS_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Payment processing service.
	 *
	 * @var PaymentProcessingService
	 */
	private PaymentProcessingService $processing_service;

	/**
	 * WooPayments provider.
	 *
	 * @var WooPaymentsProvider
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Whether the current checkout got as far as the payment attempt.
	 *
	 * @var bool
	 */
	private bool $checkout_payment_started = false;

	/**
	 * WooPayments checkout bridge.
	 *
	 * @var WooPaymentsCheckoutBridge
	 */
	private WooPaymentsCheckoutBridge $checkout_bridge;

	/**
	 * WooPay session service.
	 *
	 * @var WooPaymentsWooPaySessionService
	 */
	private WooPaymentsWooPaySessionService $woopay_session_service;

	/**
	 * Native WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * WooPayments fraud-prevention service.
	 *
	 * @var WooPaymentsFraudPreventionService
	 */
	private WooPaymentsFraudPreventionService $fraud_prevention_service;

	/**
	 * WooPayments failed-transaction rate limiter.
	 *
	 * @var WooPaymentsFailedTransactionRateLimiter
	 */
	private WooPaymentsFailedTransactionRateLimiter $failed_transaction_rate_limiter;

	/**
	 * WooPayments duplicate-payment prevention service.
	 *
	 * @var WooPaymentsDuplicatePaymentPreventionService
	 */
	private WooPaymentsDuplicatePaymentPreventionService $duplicate_payment_prevention_service;

	/**
	 * WooPayments payment method definition backing this gateway instance.
	 *
	 * @var WooPaymentsPaymentMethodDefinition
	 */
	private WooPaymentsPaymentMethodDefinition $payment_method_definition;

	/**
	 * Country-branded checkout title, resolved lazily.
	 *
	 * @var string|null
	 */
	private ?string $branded_title = null;

	/**
	 * Country-branded admin title, resolved lazily.
	 *
	 * @var string|null
	 */
	private ?string $branded_method_title = null;

	/**
	 * Blog whose site-derived gateway state is currently cached.
	 *
	 * @var int
	 */
	private int $settings_blog_id = 0;

	/**
	 * Capabilities this gateway added for the cached blog.
	 *
	 * @var string[]
	 */
	private array $site_supports = array();

	/**
	 * Capabilities removed from the public support list outside this gateway.
	 *
	 * @var string[]
	 */
	private array $externally_removed_supports = array();

	/**
	 * Whether the classic checkout fallback hooks were added in this request.
	 *
	 * @var bool
	 */
	private static bool $classic_checkout_fallback_hooks_added = false;

	/**
	 * Constructor.
	 *
	 * @param WooPaymentsPaymentMethodDefinition|null $payment_method_definition Optional payment method definition.
	 */
	public function __construct( ?WooPaymentsPaymentMethodDefinition $payment_method_definition = null ) {
		$this->payment_method_definition = $payment_method_definition ?? $this->get_default_payment_method_definition();
		$payment_method_id               = $this->payment_method_definition->get_id();

		$this->id                 = 'card' === $payment_method_id ? OrderPaymentStore::GATEWAY_ID : OrderPaymentStore::GATEWAY_ID . '_' . $payment_method_id;
		$this->title              = $this->payment_method_definition->get_title();
		$this->method_title       = $this->get_untranslated_method_title();
		$this->method_description = self::METHOD_DESCRIPTION;
		$this->has_fields         = true;
		$this->supports           = array( PaymentGatewayFeature::PRODUCTS );

		if ( $this->payment_method_supports( PaymentGatewayFeature::REFUNDS ) ) {
			$this->supports[] = PaymentGatewayFeature::REFUNDS;
		}

		$this->init_settings();
		$this->settings_blog_id = get_current_blog_id();
		$base_supports          = $this->supports;
		$this->init_supported_features();
		$this->site_supports = array_values( array_diff( $this->supports, $base_supports ) );

		if ( $this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT ) ) {
			$this->has_custom_place_order_button = true;
			$this->has_fields                    = false;
		}

		if ( did_action( 'init' ) ) {
			$this->handle_init();
		} else {
			add_action( 'init', array( $this, 'handle_init' ) );
		}

		$this->maybe_add_classic_checkout_fallback_hooks();

		// Only the card gateway adds it, as the client's main gateway does (client 11.1.0 class-wc-payment-gateway-wcpay.php:559,572-573).
		if ( OrderPaymentStore::GATEWAY_ID === $this->id && false === has_action( 'set_logged_in_cookie', array( self::class, 'handle_set_logged_in_cookie' ) ) ) {
			add_action( 'set_logged_in_cookie', array( self::class, 'handle_set_logged_in_cookie' ) );
		}
	}

	/**
	 * Use the new login cookie for the rest of a checkout request that created the customer's account.
	 *
	 * Nonces created later in the request, such as the 3DS confirmation nonce, then match the session the shopper's
	 * next request sends. Port of client 11.1.0 `set_cookie_on_current_request()` (class-wc-payment-gateway-wcpay.php:762-766).
	 *
	 * @internal
	 *
	 * @param string $cookie New logged-in cookie value.
	 */
	public static function handle_set_logged_in_cookie( $cookie ): void {
		if ( defined( 'LOGGED_IN_COOKIE' ) && defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT && did_action( 'woocommerce_created_customer' ) > 0 ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		}
	}

	/**
	 * Add the classic checkout and order-pay fallback hooks once per request, from the card gateway.
	 */
	private function maybe_add_classic_checkout_fallback_hooks(): void {
		if ( self::$classic_checkout_fallback_hooks_added || OrderPaymentStore::GATEWAY_ID !== $this->id ) {
			return;
		}
		self::$classic_checkout_fallback_hooks_added = true;

		add_action( 'woocommerce_after_checkout_form', array( $this, 'handle_classic_checkout_without_fields' ) );
		add_action( 'woocommerce_pay_order_before_payment', array( $this, 'handle_classic_checkout_without_fields' ) );
	}

	/**
	 * Localize the classic checkout config when no WooPayments fields rendered and native owns payments.
	 *
	 * The ownership check runs here, not when the gateway is built, so building a gateway never settles it early.
	 *
	 * @internal
	 */
	public function handle_classic_checkout_without_fields(): void {
		if ( ! wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->should_native_register() ) {
			return;
		}
		// No WooPayments gateway can become available on this page without it (see is_available()), so skip the stack.
		// Client 11.1.0 loads it on every classic checkout (includes/class-wc-payments-checkout.php:103,162-166).
		if ( ! $this->get_provider()->can_process_payments() ) {
			return;
		}

		$this->get_checkout_bridge()->enqueue_classic_checkout_assets_without_fields( $this->supports );
	}

	/**
	 * Handle the init hook.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$this->method_description = __( 'Accept payments with WooPayments.', 'woocommerce' );
		$this->refresh_site_supports();
	}

	/**
	 * Get a gateway setting for the current blog.
	 *
	 * @since 11.2.0
	 *
	 * @param string $key         Setting key.
	 * @param mixed  $empty_value Value returned for an empty setting.
	 * @return mixed
	 */
	public function get_option( $key, $empty_value = null ) {
		$this->ensure_current_blog_context();

		return parent::get_option( $key, $empty_value );
	}

	/**
	 * Return the current user's saved tokens for the current blog.
	 *
	 * @since 11.2.0
	 *
	 * @return WC_Payment_Token[]
	 */
	public function get_tokens() {
		$this->ensure_current_blog_context();

		return parent::get_tokens();
	}

	/**
	 * Check support using the current blog's gateway capabilities.
	 *
	 * @since 11.2.0
	 *
	 * @param string $feature Gateway feature.
	 * @return bool
	 */
	public function supports( $feature ) {
		$this->ensure_current_blog_context();

		return parent::supports( $feature );
	}

	/**
	 * Return the gateway's checkout title.
	 *
	 * @return string
	 */
	public function get_title() {
		$this->ensure_current_blog_context();

		if ( null === $this->branded_title ) {
			$this->branded_title = $this->get_translated_payment_method_title();
			$this->title         = $this->branded_title;
		}

		return parent::get_title();
	}

	/**
	 * Return the gateway's admin title.
	 *
	 * @return string
	 */
	public function get_method_title() {
		$this->ensure_current_blog_context();

		if ( null === $this->branded_method_title ) {
			$this->branded_method_title = $this->get_translated_method_title();
			$this->method_title         = $this->branded_method_title;
		}

		return parent::get_method_title();
	}

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param PaymentProcessingService                          $processing_service        Payment processing service.
	 * @param WooPaymentsProvider                               $provider                  WooPayments provider.
	 * @param WooPaymentsCheckoutBridge|null                    $checkout_bridge           Optional checkout bridge.
	 * @param WooPaymentsApiClient|null                         $api_client                Optional API client.
	 * @param WooPaymentsAccountService|null                    $account_service           Optional account service.
	 * @param WooPaymentsTokenService|null                      $token_service             Optional token service.
	 * @param WooPaymentsCustomerService|null                   $customer_service          Optional customer service.
	 * @param WooPaymentsFraudPreventionService|null            $fraud_prevention_service  Optional fraud-prevention service.
	 * @param WooPaymentsFailedTransactionRateLimiter|null      $failed_transaction_rate_limiter Optional failed-transaction rate limiter.
	 * @param WooPaymentsDuplicatePaymentPreventionService|null $duplicate_payment_prevention_service Optional duplicate-payment prevention service.
	 */
	final public function init(
		PaymentProcessingService $processing_service,
		WooPaymentsProvider $provider,
		?WooPaymentsCheckoutBridge $checkout_bridge = null,
		?WooPaymentsApiClient $api_client = null,
		?WooPaymentsAccountService $account_service = null,
		?WooPaymentsTokenService $token_service = null,
		?WooPaymentsCustomerService $customer_service = null,
		?WooPaymentsFraudPreventionService $fraud_prevention_service = null,
		?WooPaymentsFailedTransactionRateLimiter $failed_transaction_rate_limiter = null,
		?WooPaymentsDuplicatePaymentPreventionService $duplicate_payment_prevention_service = null
	): void {
		$this->processing_service = $processing_service;
		$this->provider           = $provider;

		if ( null !== $checkout_bridge ) {
			$this->checkout_bridge = $checkout_bridge;
		}

		if ( null !== $api_client ) {
			$this->api_client = $api_client;
		}

		if ( null !== $account_service ) {
			$this->account_service = $account_service;
		}

		if ( null !== $token_service ) {
			$this->token_service = $token_service;
		}

		if ( null !== $customer_service ) {
			$this->customer_service = $customer_service;
		}

		if ( null !== $fraud_prevention_service ) {
			$this->fraud_prevention_service = $fraud_prevention_service;
		}

		if ( null !== $failed_transaction_rate_limiter ) {
			$this->failed_transaction_rate_limiter = $failed_transaction_rate_limiter;
		}

		if ( null !== $duplicate_payment_prevention_service ) {
			$this->duplicate_payment_prevention_service = $duplicate_payment_prevention_service;
		}
	}

	/**
	 * Get the payment method definition backing this gateway.
	 *
	 * @return WooPaymentsPaymentMethodDefinition
	 */
	public function get_payment_method_definition(): WooPaymentsPaymentMethodDefinition {
		return $this->payment_method_definition;
	}

	/**
	 * Get the native WooPayments payment method ID.
	 *
	 * @return string
	 */
	public function get_payment_method_id(): string {
		return $this->payment_method_definition->get_id();
	}

	/**
	 * Tell whether this payment method is available for the current checkout context.
	 *
	 * @since 11.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		$this->ensure_current_blog_context();

		if ( ! parent::is_available() || ! $this->payment_method_definition->should_publish_gateway() ) {
			return false;
		}
		// Only the active tier wires the checkout, redirect-return and express handlers; a stale tier must not offer a half-wired checkout.
		if ( NativePaymentsState::ACTIVE !== wc_get_container()->get( NativePaymentsState::class )->get_state() ) {
			return false;
		}
		if ( 'card' !== $this->get_payment_method_id() && ! $this->get_account_service()->is_gateway_enabled() ) {
			return false;
		}
		if ( ! $this->get_provider()->can_process_payments() || ! $this->is_account_capability_active() ) {
			return false;
		}

		// Express methods are never offered in admin (for example, the Subscriptions payment-method select):
		// the client's express gateways fail its enabled-at-checkout list check there.
		if (
			$this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT )
			&& ( is_admin() || ! $this->is_express_checkout_in_payment_methods_enabled() )
		) {
			return false;
		}
		if ( $this->needs_https_setup() || ! $this->is_available_for_current_currency() || ! $this->is_available_for_current_subscription_context() || ! $this->is_bnpl_order_pay_available() ) {
			return false;
		}

		$currency        = strtoupper( $this->get_checkout_currency() );
		$account_country = $this->get_account_country();

		// The reference client rejects a non-domestic presentment currency outright for
		// domestic-only methods (is_currency_valid with the account's domestic currency).
		// The limits table blocks the same pairs today, but only as long as every currency
		// row stays keyed to its domestic country - this gate does not drift with that data.
		if (
			$this->payment_method_supports( WooPaymentsPaymentMethodRegistry::DOMESTIC_TRANSACTIONS_ONLY )
			&& strtolower( $currency ) !== $this->get_account_domestic_currency()
		) {
			return false;
		}

		$supported_currencies = $this->payment_method_definition->get_supported_currencies( $account_country );
		if ( ! empty( $supported_currencies ) && ! in_array( $currency, $supported_currencies, true ) ) {
			return false;
		}

		return $this->is_checkout_amount_within_definition_limits( $currency, $account_country );
	}

	/**
	 * Get the admin transaction-details URL for an order, called by WooCommerce core.
	 *
	 * WooCommerce renders the order's transaction ID on the admin order page, and links
	 * it when the gateway answers this with a URL. Native writes `_transaction_id` on
	 * every paid order, so without this the ID renders as plain text and the merchant
	 * loses the click-through to payment details they had before switching — a
	 * user-visible parity regression rather than a missing nicety.
	 *
	 * Deliberately matched to the WooPayments client plugin's `get_transaction_url()`:
	 * the same authorized-status gate, the same preference for the intent ID with the
	 * charge ID as fallback, the same refusal to link a SetupIntent (which has no
	 * transaction to show). The URL is composed through the shared admin helper the
	 * order notes already use, so a note's link and the order page's link resolve to
	 * the same place.
	 *
	 * @since 11.0.0
	 *
	 * @param WC_Order $order Order shown on the admin order page.
	 * @return string Transaction details URL, or an empty string when there is nothing to link.
	 */
	public function get_transaction_url( $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$intention_status = (string) $order->get_meta( '_intention_status', true );
		if ( ! in_array( $intention_status, self::AUTHORIZED_INTENTION_STATUSES, true ) ) {
			return '';
		}

		$intent_id = (string) $order->get_meta( '_intent_id', true );
		$charge_id = (string) $order->get_meta( '_charge_id', true );

		if ( '' === $intent_id && '' === $charge_id ) {
			return '';
		}

		// A SetupIntent stores no payment, so there is no transaction page to send
		// the merchant to.
		if ( false !== strpos( $intent_id, 'seti_' ) ) {
			return '';
		}

		return Utils::wc_payments_legacy_admin_url(
			rawurlencode( '/payments/transactions/details' ),
			array( 'id' => '' !== $intent_id ? $intent_id : $charge_id )
		);
	}

	/**
	 * Tell whether express checkout methods should appear in the payment-method list.
	 *
	 * @since 11.0.0
	 *
	 * @return bool
	 */
	public function is_express_checkout_in_payment_methods_enabled(): bool {
		return WooPaymentsSettingsService::is_dynamic_checkout_place_order_button_enabled()
			&& 'yes' === (string) $this->get_account_service()->get_gateway_setting( 'express_checkout_in_payment_methods', 'no' );
	}

	/**
	 * Tell whether live checkout requires HTTPS configuration.
	 *
	 * @return bool
	 */
	public function needs_https_setup(): bool {
		return ! $this->get_account_service()->is_test_mode_enabled() && ! wc_checkout_is_https();
	}

	/**
	 * Output the payment fields, as client 11.1.0 does (includes/class-wc-payments-checkout.php:401,462-502).
	 *
	 * The saved payment methods print inside the WooPayments form, below the test-mode instructions, and core's
	 * tokenization-form.js loads wherever tokenization shows, the My Account add-payment-method form included.
	 *
	 * @return void
	 */
	public function payment_fields() {
		$is_add_payment_method_page = is_add_payment_method_page();
		if ( ! ( $this->supports( PaymentGatewayFeature::TOKENIZATION ) && ( is_checkout() || $is_add_payment_method_page ) ) ) {
			$this->form();
			return;
		}

		$this->tokenization_script();
		$this->get_checkout_bridge()->render_payment_fields(
			$this->get_card_gateway_supports(),
			$this->payment_method_definition,
			$is_add_payment_method_page ? null : array( $this, 'saved_payment_methods' )
		);
		if ( ! $is_add_payment_method_page ) {
			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Render the native WooPayments payment form.
	 *
	 * @return void
	 */
	public function form() {
		$this->get_checkout_bridge()->render_payment_fields( $this->get_card_gateway_supports(), $this->payment_method_definition );
	}

	/**
	 * Get the card gateway's support features, which every WooPayments checkout method shares, as in client 11.1.0.
	 *
	 * @return string[]
	 */
	private function get_card_gateway_supports(): array {
		if ( OrderPaymentStore::GATEWAY_ID === $this->id ) {
			return $this->supports;
		}

		$card_gateway = $this->get_provider()->get_gateway_for_method( 'card' );

		return null === $card_gateway ? array() : $card_gateway->supports;
	}

	/**
	 * Output the save-payment-method checkbox.
	 *
	 * Subscription checkouts and payment-method changes must save a reusable credential, so the
	 * checkbox remains checked in the form for the checkout bridge while being hidden from the shopper.
	 *
	 * @return void
	 */
	public function save_payment_method_checkbox() {
		if ( ! $this->cart_contains_subscription() && ! $this->is_subscription_change_payment_form() ) {
			parent::save_payment_method_checkbox();
			return;
		}

		$html = sprintf(
			'<p class="form-row woocommerce-SavedPaymentMethods-saveNew">
				<input id="wc-%1$s-new-payment-method" name="wc-%1$s-new-payment-method" type="checkbox" value="true" style="width:auto;" checked="checked" />
				<label for="wc-%1$s-new-payment-method" style="display:inline;">%2$s</label>
			</p>',
			esc_attr( $this->id ),
			esc_html__( 'Save to account', 'woocommerce' )
		);

		echo '<div style="display:none;">';
		/**
		 * Filters the saved payment method checkbox HTML.
		 *
		 * @since 2.6.0
		 *
		 * @param string              $html    Saved payment method checkbox HTML.
		 * @param \WC_Payment_Gateway $gateway Payment gateway instance.
		 */
		echo apply_filters( 'woocommerce_payment_gateway_save_new_payment_method_option_html', $html, $this ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Add a WooPayments payment method from the My Account payment-method form.
	 *
	 * @return array<string,string>
	 */
	public function add_payment_method() {
		$this->ensure_current_blog_context();

		try {
			$setup_intent_id = $this->sanitize_post_string( 'wcpay-setup-intent' );

			if ( '' === $setup_intent_id ) {
				return $this->add_payment_method_error( __( 'A WooPayments payment method was not provided', 'woocommerce' ) );
			}

			$user_id = get_current_user_id();
			if ( 0 >= $user_id ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please log in and try again.", 'woocommerce' ) );
			}

			$fraud_prevention_error = $this->get_fraud_prevention_error_message( false );
			if ( '' !== $fraud_prevention_error ) {
				return $this->add_payment_method_error( $fraud_prevention_error );
			}

			// The My Account form creates the customer before the SetupIntent, so a user without one cannot own the
			// intent; the client refuses before reading it (client 11.1.0 gw:4434-4439).
			$user_customer = (string) $this->get_customer_service()->get_customer_id_by_user_id( $user_id );
			if ( '' === $user_customer ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later", 'woocommerce' ) );
			}

			$setup_intent = $this->get_api_client()->get_setup_intention( $setup_intent_id );
			$status       = isset( $setup_intent['status'] ) ? (string) $setup_intent['status'] : '';
			if ( 'succeeded' !== $status ) {
				return $this->add_payment_method_error( __( 'Failed to add the provided payment method. Please try again later', 'woocommerce' ) );
			}

			// Save only an intent made for this user's customer, so a posted SetupIntent id of another shopper cannot
			// attach their payment method here. The client checks only that the user has a customer (gw:4434-4454).
			if ( ! hash_equals( $user_customer, $this->get_setup_intent_customer_id( $setup_intent ) ) ) {
				return $this->add_payment_method_error( __( 'Failed to add the provided payment method. Please try again later', 'woocommerce' ) );
			}

			$payment_method_id = $this->get_setup_intent_payment_method_id( $setup_intent );
			if ( '' === $payment_method_id ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later", 'woocommerce' ) );
			}

			$token = $this->get_token_service()->get_or_create_token_for_user( $payment_method_id, $user_id );
			if ( ! $token instanceof WC_Payment_Token ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later", 'woocommerce' ) );
			}

			return array(
				'result'   => 'success',
				/**
				 * Filters the redirect URL after adding a WooPayments payment method.
				 *
				 * This intentionally uses the standalone WooPayments plugin's `wcpay_` hook name
				 * (not the native `woocommerce_woopayments_*` prefix) for parity: extensions that hooked
				 * the plugin's filter keep working once a site switches to the native runtime.
				 * Do not rename it — see the class doc block for the hook-name parity rationale.
				 *
				 * @since 11.0.0
				 * @param string $url Redirect URL.
				 */
				'redirect' => apply_filters( 'wcpay_get_add_payment_method_redirect_url', wc_get_endpoint_url( 'payment-methods' ) ),
			);
		} catch ( WooPaymentsApiException $exception ) {
			// Client 11.1.0 gw:4471 logs this at info level.
			$this->get_logger()->log_throwable( 'Error when adding payment method: ' . $exception->getMessage(), $exception, array(), 'info' );

			// Client 11.1.0 gw:4467-4468 filters API errors through get_filtered_error_message().
			return $this->add_payment_method_error(
				WooPaymentsErrorMessages::get_shopper_message( $exception->get_error_type(), $exception->get_error_code(), $exception->get_decline_code(), $exception->getMessage() )
			);
		} catch ( Throwable $exception ) {
			$this->get_logger()->log_throwable( 'Error when adding payment method: ' . $exception->getMessage(), $exception, array(), 'info' );

			return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later", 'woocommerce' ) );
		}
	}

	/**
	 * Process a scheduled subscription renewal payment.
	 *
	 * @param float    $amount        Renewal amount.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return void
	 * @throws Throwable When a requires-action hook callback throws, or the token repair or applying the payment raises
	 *                   a PHP Error, so the scheduled action fails as on the client.
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order ): void {
		unset( $amount );

		if ( ! $renewal_order instanceof WC_Order ) {
			return;
		}

		// Stripe charges the renewals of Stripe Billing subscriptions itself; the invoice webhooks record them.
		if ( $this->get_stripe_billing_module()->is_stripe_billed_order( $renewal_order ) ) {
			return;
		}

		$token = $this->get_payment_token_from_order( $renewal_order );
		if ( ! $token instanceof WC_Payment_Token && ! $this->is_network_saved_cards_enabled() ) {
			$token = $this->maybe_repair_renewal_order_payment_token( $renewal_order );
		}

		// Deliberate divergence: on a network forcing network-wide saved cards, the
		// extension proceeds with a null token and lets the platform resolve the
		// network card. Native has no network-card machinery, so a tokenless renewal
		// fails honestly here instead of sending a charge with no payment method.
		// Authorized divergence: plan.md revision log 2026-09-25 10:15; data/t7-network-saved-cards-usage.md.
		if ( ! $token instanceof WC_Payment_Token ) {
			$renewal_order->add_order_note( __( 'Subscription renewal failed: No saved payment method found.', 'woocommerce' ) );
			// Client 11.1.0 trait:415.
			$this->get_logger()->error( 'There is no saved payment token for order #' . $renewal_order->get_id() );
			$renewal_order->update_status( 'failed' );
			return;
		}

		$provider_data = array(
			'scheduled_subscription_payment'    => true,
			'saved_payment_method_display_name' => $token->get_display_name(),
		);
		$mandate       = $this->get_renewal_order_mandate( $renewal_order );
		if ( '' !== $mandate ) {
			$provider_data['renewal_mandate'] = $mandate;
		}

		$customer_id = $this->get_renewal_order_customer_id( $renewal_order );
		if ( '' !== $customer_id ) {
			$renewal_order->update_meta_data( '_stripe_customer_id', $customer_id );
			$renewal_order->save_meta_data();
		}

		try {
			$outcome = $this->get_processing_service()->process_checkout_outcome(
				PaymentContext::for_checkout(
					$renewal_order,
					$this->id,
					'',
					array(
						'payment_token'       => (string) $token->get_id(),
						'save_payment_method' => false,
					),
					$provider_data
				),
				$this->get_provider()
			);
		} catch ( PaymentOutcomeApplyException $exception ) {
			// The processing service logged the failure and tried to save the payment reference on the renewal; see was_reconciliation_context_persisted().
			$failure = $exception->get_failure();
			$outcome = $exception->get_outcome();
			$this->get_logger()->log_throwable(
				'Error applying the WooPayments subscription renewal payment: ' . $exception->getMessage(),
				$failure,
				array( 'order_id' => $renewal_order->get_id() )
			);

			// Client trait:426 catches only API_Exception, so a PHP Error fails the scheduled action and leaves the renewal
			// pending (monitor ruling 2026-10-04 on renewal apply errors). A requires-action outcome still runs its hooks below.
			if ( ! $failure instanceof Exception && PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION !== $outcome->get_status() ) {
				throw $failure;
			}
		}

		$this->maybe_handle_subscription_customer_action_required( $renewal_order, $outcome );
	}

	/**
	 * Handle a scheduled renewal that requires customer authentication.
	 *
	 * @param WC_Order       $renewal_order Renewal order.
	 * @param PaymentOutcome $outcome       Provider payment outcome.
	 * @return void
	 * @throws Throwable When a requires-action hook callback throws; the renewal is left as it was.
	 */
	private function maybe_handle_subscription_customer_action_required( WC_Order $renewal_order, PaymentOutcome $outcome ): void {
		if ( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION !== $outcome->get_status() ) {
			return;
		}

		$data      = $outcome->get_data();
		$meta      = isset( $data[ PaymentOutcome::DATA_META ] ) && is_array( $data[ PaymentOutcome::DATA_META ] )
			? $data[ PaymentOutcome::DATA_META ]
			: array();
		$charge_id = isset( $data['charge_id'] )
			? (string) $data['charge_id']
			: ( isset( $meta['_charge_id'] ) ? (string) $meta['_charge_id'] : '' );

		try {
			/**
			 * Fires when a native WooPayments payment requires customer authentication.
			 *
			 * This intentionally keeps the standalone WooPayments plugin's action name
			 * (`woocommerce_woocommerce_payments_*`) rather than the native `woocommerce_woopayments_*`
			 * prefix, for parity: extensions hooked to the plugin's action keep working on the
			 * native runtime. Do not rename it — see the class doc block for the rationale.
			 *
			 * @param WC_Order $renewal_order     The renewal order that requires authentication.
			 * @param string   $intent_id         The provider payment intent ID.
			 * @param string   $payment_method_id The provider payment method ID.
			 * @param string   $customer_id       The provider customer ID.
			 * @param string   $charge_id         The provider charge ID.
			 * @param string   $currency          The order currency.
			 *
			 * @since 11.0.0
			 */
			do_action(
				'woocommerce_woocommerce_payments_payment_requires_action',
				$renewal_order,
				$outcome->get_provider_payment_id(),
				$outcome->get_payment_method_id(),
				$outcome->get_customer_id(),
				$charge_id,
				$renewal_order->get_currency()
			);
		} catch ( Throwable $exception ) {
			// Client gw:1921 does not catch, so the scheduled action fails and the renewal stays pending
			// (monitor ruling 2026-10-04 (2)); the line is written whatever the logging setting.
			$this->get_logger()->log_throwable_always(
				'Failed to run WooPayments subscription renewal authentication hooks: ' . $exception->getMessage(),
				$exception,
				array(
					'order_id'  => $renewal_order->get_id(),
					'intent_id' => $outcome->get_provider_payment_id(),
				)
			);

			throw $exception;
		}

		if ( ! $renewal_order->has_status( 'failed' ) ) {
			$renewal_order->update_status( 'failed' );
		}

		$failure_note = $this->get_subscription_customer_action_failure_note( $renewal_order, $outcome, $charge_id );
		if ( '' !== $failure_note && ! $this->order_has_note_containing( $renewal_order, $failure_note ) ) {
			$renewal_order->add_order_note( $failure_note );
		}
	}

	/**
	 * Get the failed-renewal note for customer-action-required outcomes.
	 *
	 * @param WC_Order       $renewal_order Renewal order.
	 * @param PaymentOutcome $outcome       Provider payment outcome.
	 * @param string         $charge_id     Provider charge ID.
	 * @return string Order note.
	 */
	private function get_subscription_customer_action_failure_note( WC_Order $renewal_order, PaymentOutcome $outcome, string $charge_id ): string {
		$transaction_id = '' !== $charge_id ? $charge_id : $outcome->get_provider_payment_id();
		if ( '' === $transaction_id ) {
			return '';
		}

		return wp_kses_post(
			sprintf(
				/* translators: %1$s: the failed payment amount, %2$s: WooPayments, %3$s: transaction ID. */
				__( 'A payment of %1$s <strong>failed</strong> using %2$s (<code>%3$s</code>).', 'woocommerce' ),
				WooPaymentsCurrencyUtils::format_price_in_currency( (float) $renewal_order->get_total(), $renewal_order->get_currency() ),
				'WooPayments',
				esc_html( $transaction_id )
			)
		);
	}

	/**
	 * Tell whether an order already has a note containing the expected text.
	 *
	 * @param WC_Order $order         Order object.
	 * @param string   $expected_note Expected note text.
	 * @return bool
	 */
	private function order_has_note_containing( WC_Order $order, string $expected_note ): bool {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order->get_id(),
				'type'     => 'any',
			)
		);

		foreach ( $notes as $note ) {
			if ( str_contains( (string) $note->content, $expected_note ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the mandate ID from the subscription parent order for a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return string Mandate ID, or an empty string when not available.
	 */
	private function get_renewal_order_mandate( WC_Order $renewal_order ): string {
		$parent_order = $this->get_subscription_parent_order_for_renewal( $renewal_order );
		if ( ! $parent_order instanceof WC_Order ) {
			return '';
		}

		return (string) $parent_order->get_meta( '_stripe_mandate_id', true );
	}

	/**
	 * Get the WooPayments customer ID from the renewal order, current subscription, or subscription parent order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return string Customer ID, or an empty string when not available.
	 */
	private function get_renewal_order_customer_id( WC_Order $renewal_order ): string {
		$customer_id = (string) $renewal_order->get_meta( '_stripe_customer_id', true );
		if ( '' !== $customer_id ) {
			return $customer_id;
		}

		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( $subscription instanceof WC_Order ) {
			$customer_id = (string) $subscription->get_meta( '_stripe_customer_id', true );
			if ( '' !== $customer_id ) {
				return $customer_id;
			}
		}

		$parent_order = $this->get_subscription_parent_order_for_renewal( $renewal_order );
		if ( ! $parent_order instanceof WC_Order ) {
			return '';
		}

		return (string) $parent_order->get_meta( '_stripe_customer_id', true );
	}

	/**
	 * Get the subscription parent order associated with a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return WC_Order|null Parent order, or null when not available.
	 */
	private function get_subscription_parent_order_for_renewal( WC_Order $renewal_order ): ?WC_Order {
		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( ! $subscription instanceof WC_Order ) {
			return null;
		}

		$parent_order = wc_get_order( (int) $subscription->get_parent_id() );
		if ( ! $parent_order instanceof WC_Order ) {
			return null;
		}

		return $parent_order;
	}

	/**
	 * Recover a renewal order's missing payment token from the parent order.
	 *
	 * A renewal can arrive without a token — the subscription's token row was deleted,
	 * or a migration dropped the link. Failing the renewal outright loses revenue the
	 * merchant can still collect: the original order's payment method ID identifies a
	 * charge-able saved method. Ports the WooPayments extension's repair.
	 *
	 * @param WC_Order $renewal_order Renewal order missing its token.
	 * @return WC_Payment_Token|null The restored token, or null when repair is impossible.
	 * @throws Throwable When the repair raises a PHP Error.
	 */
	private function maybe_repair_renewal_order_payment_token( WC_Order $renewal_order ): ?WC_Payment_Token {
		$subscription = $this->get_subscription_for_renewal_order( $renewal_order );
		if ( ! $subscription instanceof WC_Order ) {
			return null;
		}

		$parent_order = wc_get_order( (int) $subscription->get_parent_id() );
		if ( ! $parent_order instanceof WC_Order ) {
			return null;
		}

		$payment_method_id = (string) $parent_order->get_meta( '_payment_method_id', true );
		if ( '' === $payment_method_id ) {
			return null;
		}

		try {
			// The parent order is only a source for the payment method ID, never a
			// write target: attaching the token to it would fan the token out to every
			// subscription that order created, silently re-pointing sibling
			// subscriptions the customer has since moved to a different card.
			$token = $this->get_token_service()->get_or_create_token_for_user( $payment_method_id, (int) $subscription->get_customer_id() );
			if ( ! $token instanceof WC_Payment_Token ) {
				return null;
			}

			$this->get_token_service()->attach_token_to_order( $renewal_order, $token );

			$subscription_token = $this->get_payment_token_from_order( $subscription );
			if ( ! $subscription_token instanceof WC_Payment_Token || $token->get_id() !== $subscription_token->get_id() ) {
				$subscription->add_payment_token( $token );
				$subscription->add_order_note(
					sprintf(
						/* translators: %s: payment method display name. */
						__( 'The saved payment method for this subscription was missing, so WooPayments restored %s from the original order to complete the renewal.', 'woocommerce' ),
						$token->get_display_name()
					)
				);
			}

			$renewal_order->add_order_note( __( 'Recovered missing subscription payment method token from the parent order.', 'woocommerce' ) );

			return $token;
		} catch ( Throwable $exception ) {
			$this->get_logger()->log_throwable(
				'Error repairing subscription renewal payment token for order #' . $renewal_order->get_id() . ': ' . $exception->getMessage(),
				$exception,
				array( 'order_id' => $renewal_order->get_id() )
			);

			// Client trait:538 catches only Exception, so a PHP Error fails the scheduled action and leaves the
			// renewal pending (monitor ruling 2026-10-04 (3)).
			if ( ! $exception instanceof Exception ) {
				throw $exception;
			}

			return null;
		}
	}

	/**
	 * Tell whether the site only uses network-wide saved payment methods.
	 *
	 * On such networks the token intentionally lives outside the site, so the local
	 * repair must not run and re-localize it.
	 *
	 * @return bool
	 */
	private function is_network_saved_cards_enabled(): bool {
		return $this->get_account_service()->is_network_saved_cards_enabled();
	}

	/**
	 * Get the subscription associated with a renewal order.
	 *
	 * @param WC_Order $renewal_order Renewal order.
	 * @return WC_Order|null Subscription order, or null when not available.
	 */
	private function get_subscription_for_renewal_order( WC_Order $renewal_order ): ?WC_Order {
		$subscriptions = array();
		if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order->get_id() );
		}

		$subscriptions = is_array( $subscriptions ) ? $subscriptions : array();

		/**
		 * Filters native WooPayments subscriptions related to a renewal order.
		 *
		 * @since 11.0.0
		 *
		 * @param array<int,mixed> $subscriptions Related subscriptions.
		 * @param WC_Order         $renewal_order Renewal order.
		 */
		$subscriptions = apply_filters( 'woocommerce_woopayments_subscriptions_for_renewal_order', $subscriptions, $renewal_order );
		$subscriptions = is_array( $subscriptions ) ? $subscriptions : array();
		$subscription  = reset( $subscriptions );

		return $subscription instanceof WC_Order ? $subscription : null;
	}

	/**
	 * Copy the successful renewal token to the failing subscription.
	 *
	 * @param WC_Order $subscription  Subscription order.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order ): void {
		if ( ! $subscription instanceof WC_Order || ! $renewal_order instanceof WC_Order ) {
			return;
		}

		$token = $this->get_payment_token_from_order( $renewal_order );
		if ( ! $token instanceof WC_Payment_Token ) {
			$renewal_order->add_order_note( __( 'Unable to update subscription payment method: No valid payment token or method found.', 'woocommerce' ) );
			return;
		}

		$this->get_token_service()->attach_token_to_order( $subscription, $token );
	}

	/**
	 * Force subscriptions using non-reusable WooPayments methods to manual renewal.
	 *
	 * @internal
	 *
	 * @param mixed $subscription Subscription object.
	 * @return void
	 */
	public function maybe_force_subscription_to_manual( $subscription ): void {
		if ( ! $subscription instanceof WC_Order || ! is_callable( array( $subscription, 'set_requires_manual_renewal' ) ) ) {
			return;
		}

		$gateway_id = $subscription->get_payment_method();
		if ( ! WooPaymentsSubscriptionMethodPolicy::is_native_gateway_id( $gateway_id ) || WooPaymentsSubscriptionMethodPolicy::is_reusable_gateway_id( $gateway_id ) ) {
			return;
		}

		$payment_method_type = substr( $gateway_id, strlen( OrderPaymentStore::GATEWAY_ID_PREFIX ) );
		$subscription->update_meta_data( '_wcpay_original_payment_method_id', $gateway_id );
		$subscription->set_requires_manual_renewal( true );
		$subscription->save();
		$subscription->add_order_note(
			sprintf(
				/* translators: %s: payment method type. */
				__( 'Subscription set to manual renewal because %s is a non-reusable payment method.', 'woocommerce' ),
				$payment_method_type
			)
		);
	}

	/**
	 * Tell whether saved payment methods are enabled.
	 *
	 * @return bool
	 */
	public function is_saved_cards_enabled(): bool {
		$settings = get_option( 'woocommerce_' . OrderPaymentStore::GATEWAY_ID . '_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'saved_cards', $settings ) ) {
			return 'yes' === $settings['saved_cards'];
		}

		return 'yes' === $this->get_option( 'saved_cards' );
	}

	/**
	 * Tell whether subscriptions support is available.
	 *
	 * @return bool
	 */
	public function is_subscriptions_enabled(): bool {
		return WooPaymentsSubscriptionMethodPolicy::is_subscriptions_available();
	}

	/**
	 * Tell whether WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	public function is_subscriptions_plugin_active(): bool {
		return class_exists( 'WC_Subscriptions' );
	}

	/**
	 * Tell whether a WooPayments account is connected, like client 11.1.0 WC_Payment_Gateway_WCPay::is_connected().
	 *
	 * The Payments settings providers list reads this for the account connected state.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		return array() !== $this->get_account_service()->get_cached_account_data();
	}

	/**
	 * Tell whether the connected account has not submitted its details yet, like client 11.1.0 is_account_partially_onboarded().
	 *
	 * The Payments settings providers list reads this for the onboarding completed state.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_account_partially_onboarded(): bool {
		return $this->is_connected() && ! $this->get_account_service()->is_details_submitted();
	}

	/**
	 * Tell whether the gateway needs setup, like client 11.1.0 WC_Payment_Gateway_WCPay::needs_setup().
	 *
	 * Setup is needed without an account, when the account data lacks a status, or while payments are disabled.
	 *
	 * @return bool
	 */
	public function needs_setup() {
		if ( ! $this->is_connected() ) {
			return true;
		}

		$account_data = $this->get_account_service()->get_cached_account_data();

		return parent::needs_setup() || ! isset( $account_data['status'], $account_data['payments_enabled'] ) || ! $account_data['payments_enabled'];
	}

	/**
	 * Tell whether WooPayments is in test mode.
	 *
	 * @return bool
	 */
	public function is_test_mode(): bool {
		return $this->get_account_service()->is_test_mode_enabled();
	}

	/**
	 * Tell whether WooPayments is in development mode.
	 *
	 * @return bool
	 */
	public function is_dev_mode(): bool {
		return $this->get_account_service()->is_dev_mode_enabled();
	}

	/**
	 * Return the shopper-facing payment method icons.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icons = array();

		if ( $this->is_test_mode() ) {
			$badge_style = implode(
				'',
				array(
					'background-color:#fff2d7;',
					'border-radius:4px;',
					'color:#4d3716;',
					'display:inline-block;',
					'font-size:12px;',
					'font-weight:400;',
					'line-height:16px;',
					'margin-left:8px;',
					'padding:4px 6px;',
				)
			);
			$icons[]     = sprintf(
				'<span class="test-mode badge" style="%1$s">%2$s</span>',
				esc_attr( $badge_style ),
				esc_html__( 'Test Mode', 'woocommerce' )
			);
		}

		if ( 'card' !== $this->get_payment_method_id() ) {
			$account_country = $this->get_account_country();
			$icon_asset_path = $this->payment_method_definition->get_icon_asset_path( $account_country );

			if ( '' !== $icon_asset_path ) {
				$icons[] = sprintf(
					'<img class="wcpay-payment-method-icon" src="%1$s" alt="%2$s" />',
					esc_url( \WC_HTTPS::force_https_url( WC()->plugin_url() . '/' . ltrim( $icon_asset_path, '/' ) ) ),
					esc_attr( $this->payment_method_definition->get_title( $account_country ) )
				);
			}

			$icon = implode( '', $icons );
		} else {
			$brand_labels          = $this->get_card_brand_icon_labels();
			$brands                = array_slice( $brand_labels, 0, 3, true );
			$additional_icon_count = count( $brand_labels ) - count( $brands );

			foreach ( $brands as $brand => $label ) {
				$icons[] = sprintf(
					'<img src="%1$s" alt="%2$s" width="38" height="24" />',
					esc_url( \WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/' . ( WooPaymentsCheckoutBridge::CARD_BRAND_ICON_ASSETS[ $brand ] ?? 'payment-methods/' . $brand . '.svg' ) ) ),
					esc_attr( $label )
				);
			}

			if ( $additional_icon_count > 0 ) {
				$icons[] = sprintf(
					'<span class="payment-methods--logos-count">+ %d</span>',
					$additional_icon_count
				);
			}

			$icon = '<span class="wcpay-core-card-brand-icons payment-methods--logos">' . implode( '', $icons ) . '</span>';
		}

		/**
		 * Filter the gateway icon.
		 *
		 * @since 1.5.8
		 * @param string $icon Gateway icon.
		 * @param string $id Gateway ID.
		 * @return string
		 */
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
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
	 * Tell whether the account's customer-supported currencies allow the store currency.
	 *
	 * Mirrors the reference client's is_available_for_current_currency():
	 * an empty supported list never disables the gateway, and the comparison
	 * deliberately reads the store presentment currency, not the order-pay
	 * checkout currency override.
	 *
	 * @return bool
	 */
	private function is_available_for_current_currency(): bool {
		$supported_currencies = $this->get_account_service()->get_customer_supported_currencies();

		if ( array() === $supported_currencies ) {
			return true;
		}

		return in_array( strtolower( get_woocommerce_currency() ), $supported_currencies, true );
	}

	/**
	 * Get the connected account's domestic currency, lowercase.
	 *
	 * Mirrors the reference client's get_account_domestic_currency(): the merchant
	 * country's locale data resolves the currency first, and the account default
	 * currency is only the fallback when locale data is missing for the country.
	 *
	 * @return string
	 */
	private function get_account_domestic_currency(): string {
		$country_locale_data = wc_get_container()->get( MultiCurrencyLocalizationService::class )->get_country_locale_data( $this->get_account_country() );
		$currency_code       = $country_locale_data['currency_code'] ?? null;

		if ( ! is_string( $currency_code ) || '' === $currency_code ) {
			return $this->get_account_service()->get_account_default_currency();
		}

		return strtolower( $currency_code );
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

		if ( '' === $country ) {
			$base    = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
			$country = strtoupper( (string) ( $base['country'] ?? '' ) );
		}

		if ( false !== strpos( $country, ':' ) ) {
			$base_country = strtok( $country, ':' );
			$country      = is_string( $base_country ) ? $base_country : '';
		}

		return '' !== $country ? $country : 'US';
	}

	/**
	 * Tell whether the connected account has this payment method's capability active.
	 *
	 * An empty capability map is the pre-onboarding fallback used by WooPayments: the reference
	 * client synthesizes an active card_payments capability, so every method backed by it
	 * (card, Apple Pay, Google Pay) stays available while other methods require an explicit
	 * active capability.
	 *
	 * @return bool
	 */
	private function is_account_capability_active(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();

		if ( array() === $capabilities ) {
			return 'card_payments' === $this->payment_method_definition->get_account_capability_key();
		}

		$capability_key = $this->payment_method_definition->get_account_capability_key();

		return 'active' === ( $capabilities[ $capability_key ] ?? null );
	}

	/**
	 * Get the currency for the current checkout or order-pay context.
	 *
	 * @return string
	 */
	private function get_checkout_currency(): string {
		$order = $this->get_order_pay_order();
		if ( $order instanceof WC_Order && '' !== $order->get_currency() ) {
			return strtoupper( $order->get_currency() );
		}

		return strtoupper( get_woocommerce_currency() );
	}

	/**
	 * Get the current order-pay order.
	 *
	 * @return WC_Order|null
	 */
	private function get_order_pay_order(): ?WC_Order {
		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( 0 === $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );

		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Tell whether this method is available in the current subscription context.
	 *
	 * @return bool
	 */
	private function is_available_for_current_subscription_context(): bool {
		$is_subscription_context = false;
		if ( class_exists( 'WC_Subscriptions_Cart' ) && $this->is_subscriptions_enabled() ) {
			$is_subscription_context = \WC_Subscriptions_Cart::cart_contains_subscription()
				|| ( function_exists( 'wcs_cart_contains_renewal' ) && (bool) wcs_cart_contains_renewal() );
		}

		if ( ! $is_subscription_context && isset( $_GET['change_payment_method'] ) && function_exists( 'wcs_is_subscription' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$is_subscription_context = (bool) wcs_is_subscription( absint( $_GET['change_payment_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$order = $this->get_order_pay_order();
		if ( ! $is_subscription_context && $order instanceof WC_Order && function_exists( 'wcs_order_contains_renewal' ) ) {
			$is_subscription_context = (bool) wcs_order_contains_renewal( $order );
		}

		$manual_renewals_enabled = function_exists( 'wcs_is_manual_renewal_enabled' ) && (bool) wcs_is_manual_renewal_enabled();

		return $this->is_available_for_subscription_context( $is_subscription_context, $manual_renewals_enabled );
	}

	/**
	 * Apply reusable/manual-renewal policy to a subscription context.
	 *
	 * @param bool $is_subscription_context Whether checkout is for a subscription.
	 * @param bool $manual_renewals_enabled Whether manual renewals are enabled.
	 * @return bool
	 */
	private function is_available_for_subscription_context( bool $is_subscription_context, bool $manual_renewals_enabled ): bool {
		return ! $is_subscription_context
			|| $manual_renewals_enabled
			|| WooPaymentsSubscriptionMethodPolicy::is_reusable_gateway_id( $this->id );
	}

	/**
	 * Tell whether an order-pay BNPL method has usable shopper address data.
	 *
	 * @return bool
	 */
	private function is_bnpl_order_pay_available(): bool {
		$payment_method_id = $this->get_payment_method_id();
		if ( ! in_array( $payment_method_id, array( 'affirm', 'afterpay_clearpay' ), true ) ) {
			return true;
		}

		$order = $this->get_order_pay_order();
		if ( ! $order instanceof WC_Order ) {
			return true;
		}

		$address = $this->get_usable_order_address( $order );
		if ( null === $address ) {
			return false;
		}

		return 'affirm' !== $payment_method_id || '' !== $address['name'];
	}

	/**
	 * Get usable shipping data from an order, falling back to billing data.
	 *
	 * @param WC_Order $order Order-pay order.
	 * @return array{name:string,address:array<string,string>}|null
	 */
	private function get_usable_order_address( WC_Order $order ): ?array {
		foreach ( array( 'shipping', 'billing' ) as $address_type ) {
			$address = $order->get_address( $address_type );
			if ( ! is_array( $address ) || ! $this->is_order_address_usable( $address ) ) {
				continue;
			}

			return array(
				'name'    => trim( (string) ( $address['first_name'] ?? '' ) . ' ' . (string) ( $address['last_name'] ?? '' ) ),
				'address' => array_map( 'strval', $address ),
			);
		}

		return null;
	}

	/**
	 * Validate the address fields WooCommerce requires for a country.
	 *
	 * @param array<string,mixed> $address Order address.
	 * @return bool
	 */
	private function is_order_address_usable( array $address ): bool {
		$country = strtoupper( (string) ( $address['country'] ?? '' ) );
		if ( '' === $country ) {
			return false;
		}

		$country_locale = function_exists( 'WC' ) && WC() && WC()->countries
			? WC()->countries->get_country_locale()
			: array();
		$fields         = array( 'state', 'city', 'postcode', 'address_1' );

		foreach ( $fields as $field ) {
			$field_config = $country_locale[ $country ][ $field ] ?? array();
			$is_required  = ! array_key_exists( 'required', $field_config ) || (bool) $field_config['required'];
			if ( $is_required && '' === trim( (string) ( $address[ $field ] ?? '' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tell whether the current checkout total is inside the definition's amount limits.
	 *
	 * @param string $currency        Checkout currency.
	 * @param string $account_country Merchant account country.
	 * @return bool
	 */
	private function is_checkout_amount_within_definition_limits( string $currency, string $account_country ): bool {
		$limits = $this->payment_method_definition->get_limits_per_currency();
		if ( ! isset( $limits[ $currency ] ) ) {
			return true;
		}

		$total = (float) $this->get_order_total();
		if ( 0.0 >= $total ) {
			return true;
		}

		$range = $limits[ $currency ][ $account_country ] ?? $limits[ $currency ]['default'] ?? null;
		if ( ! is_array( $range ) ) {
			return false;
		}

		$minor_unit = WooPaymentsCurrencyUtils::get_stripe_minor_unit_for_currency( $currency );
		$amount     = (int) round( $total * ( 10 ** $minor_unit ) );
		$minimum    = $range['min'] ?? null;
		$maximum    = $range['max'] ?? null;

		return ( null === $minimum || $amount >= $minimum )
			&& ( null === $maximum || $amount <= $maximum );
	}

	/**
	 * Process payment for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return array<string,string>
	 */
	public function process_payment( $order_id ) {
		$this->ensure_current_blog_context();

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array(
				// 'failure', not 'fail'. WooCommerce recognizes exactly success,
				// failure, pending and error. On the Store API path,
				// StoreApi\Legacy::process_legacy_payment() turns the notices this
				// method adds into a shopper-visible error only on an exact
				// 'failure' match, and then calls wc_clear_notices() regardless -
				// so any other spelling silently discards the explanation and
				// leaves the shopper with a bare HTTP 400.
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		$this->checkout_payment_started = false;

		try {
			return $this->process_order_payment( $order );
		} catch ( Exception $exception ) {
			$failure = $exception instanceof PaymentOutcomeApplyException ? $exception->get_failure() : $exception;
			if ( $this->has_succeeded_intent( $order ) || $this->is_succeeded_intent_outcome( $exception ) ) {
				// Client 11.1.0 logs the failure with its class, code and trace before this check (gw:1274).
				$this->log_checkout_payment_failure( $order, $failure );

				return $this->keep_succeeded_intent_order( $order, $failure->getMessage(), get_class( $failure ) );
			}

			if ( $exception instanceof PaymentOutcomeApplyException && ! $failure instanceof Exception && PaymentOutcome::STATUS_AUTHORIZED === $exception->get_outcome()->get_status() ) {
				return $this->keep_authorized_order_after_php_error( $order, $exception );
			}

			return $this->fail_checkout_after_exception( $order, $failure );
		}
	}

	/**
	 * Run the checkout payment for a loaded order.
	 *
	 * The plugin throws its refusals inside process_payment()'s try (client 11.1.0 `gw:1206-1233`);
	 * native returns them, so each refusal goes through refuse_checkout().
	 *
	 * @param WC_Order $order Order being paid.
	 * @return array<string,string>
	 */
	private function process_order_payment( WC_Order $order ): array {
		$order_id = $order->get_id();

		// The plugin refuses these first, before any other check (Invalid_Phone_Number_Exception).
		if ( 20 < strlen( $order->get_billing_phone() ) ) {
			return $this->refuse_checkout( $order, __( 'Invalid phone number.', 'woocommerce' ), 'invalid_phone_number' );
		}

		$fraud_prevention_error = $this->get_fraud_prevention_error_message( true );
		if ( '' !== $fraud_prevention_error ) {
			return $this->refuse_checkout( $order, $fraud_prevention_error, 'fraud_prevention_enabled' );
		}

		$failed_transaction_rate_limiter_error = $this->get_failed_transaction_rate_limiter_error_message();
		if ( '' !== $failed_transaction_rate_limiter_error ) {
			return $this->refuse_checkout(
				$order,
				$failed_transaction_rate_limiter_error,
				'rate_limiter_enabled',
				true,
				wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_rate_limited_payment_note( $order )
			);
		}

		if ( ! empty( $_POST['is-woopay-preflight-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order->update_status( 'pending' );

			return array(
				'result'   => 'success',
				'redirect' => '',
			);
		}

		$duplicate_order_result = $this->get_duplicate_payment_prevention_service()->check_against_session_processing_order( $order, $this );
		if ( is_array( $duplicate_order_result ) ) {
			return $duplicate_order_result;
		}

		$this->get_duplicate_payment_prevention_service()->maybe_update_session_processing_order( (int) $order_id );

		$existing_intent_result = $this->get_duplicate_payment_prevention_service()->check_payment_intent_attached_to_order_succeeded( $order, $this );
		if ( is_wp_error( $existing_intent_result ) ) {
			// The plugin fails the order with the mismatch as the note (client 11.1.0 `gw:1324-1325`). A guard lookup that
			// failed on a PHP Error leaves the order pending, as the client's fatal does (monitor ruling 2026-10-04 (1)).
			$is_amount_mismatch = 'duplicate_payment_amount_mismatch' === $existing_intent_result->get_error_code();

			return $this->refuse_checkout(
				$order,
				$existing_intent_result->get_error_message(),
				(string) $existing_intent_result->get_error_code(),
				$is_amount_mismatch,
				'',
				$is_amount_mismatch ? $existing_intent_result->get_error_message() : ''
			);
		}

		if ( is_array( $existing_intent_result ) ) {
			return $existing_intent_result;
		}

		$is_subscription_change                = $this->is_subscription_change_payment_request( $order );
		$is_subscription_payment_method_change = $this->is_subscription_payment_method_change_request( $order );

		// Runs after the intent check so that a reachable intent still produces the richer
		// response, including the amount-mismatch message. This catches the same-order
		// resubmission when that check returns empty-handed.
		$already_paid_result = $this->get_duplicate_payment_prevention_service()->check_order_already_paid( $order, $this, $is_subscription_payment_method_change );
		if ( is_array( $already_paid_result ) ) {
			return $already_paid_result;
		}

		// Recorded before the payment runs, whatever its outcome, as the plugin's
		// Payment_Information::from_payment_request() does. The plugin passes true,
		// which the meta tables store as '1'.
		if ( ! empty( $_POST['is_woopay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order->add_meta_data( 'is_woopay', '1', true );
			$order->save_meta_data();
		}

		$client_error_result = $this->maybe_fail_for_client_payment_method_error( $order, $is_subscription_change );
		if ( is_array( $client_error_result ) ) {
			return $client_error_result;
		}

		$context                        = PaymentContext::for_checkout(
			$order,
			$this->id,
			$this->get_request_payment_method_id(),
			$this->get_checkout_payment_data( $is_subscription_change ),
			$this->get_checkout_provider_data( $is_subscription_change, $is_subscription_payment_method_change )
		);
		$this->checkout_payment_started = true;
		$outcome                        = $this->get_processing_service()->process_checkout_outcome( $context, $this->get_provider() );
		if ( true === ( $outcome->get_data()[ PaymentOutcome::DATA_ORDER_PAID_BY_ANOTHER_REQUEST ] ?? false ) ) {
			// Another submission paid the order after this one passed the already-paid check above.
			return $this->get_duplicate_payment_prevention_service()->prevent_payment_for_paid_order( $order, $this );
		}

		$this->maybe_bump_failed_transaction_rate_limiter( $outcome );
		self::maybe_add_failed_checkout_notice( $outcome );
		$this->maybe_store_paid_intent_in_session( $outcome );
		$this->get_duplicate_payment_prevention_service()->maybe_remove_session_processing_order_for_offline_voucher( (int) $order_id, $this->get_payment_method_id(), $outcome );

		$result = self::format_checkout_result( $context, $order, $outcome );
		// Client 11.1.0 returns get_return_url() for every change, saved method or new, which WooCommerce Subscriptions maps to the subscription.
		if (
			$is_subscription_payment_method_change
			&& $this->is_terminal_subscription_change_outcome( $outcome )
			&& 'success' === ( $result['result'] ?? '' )
			&& ! $this->is_confirmation_redirect_result( $result )
			&& $order->get_checkout_order_received_url() === ( $result['redirect'] ?? '' )
		) {
			$result['redirect'] = $this->get_return_url( $order );
		}

		$this->maybe_handle_subscription_change_payment_success( $order, $result, $outcome, $is_subscription_change );
		$this->maybe_handle_saved_method_subscription_change_success( $order, $result, $outcome, $is_subscription_payment_method_change && ! $is_subscription_change );
		$result = $this->maybe_add_order_pay_save_intent_to_confirmation_redirect( $context, $order, $result );

		return $result;
	}

	/**
	 * Store evidence that the current session completed a native PaymentIntent.
	 *
	 * @param PaymentOutcome $outcome Provider checkout outcome.
	 * @return void
	 */
	private function maybe_store_paid_intent_in_session( PaymentOutcome $outcome ): void {
		$intent_id = $outcome->get_provider_payment_id();
		if (
			PaymentOutcome::STATUS_COMPLETED !== $outcome->get_status()
			|| '' === $intent_id
			|| ! function_exists( 'WC' )
			|| ! WC()
			|| ! WC()->session
		) {
			return;
		}

		WC()->session->set( WooPaymentsOrderDataService::PAID_INTENT_ID_SESSION_KEY, $intent_id );
	}

	/**
	 * Process refund for an order.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		// An authorized-but-uncaptured payment has nothing to refund yet; refunding the
		// charge would race the capture. Point the merchant at the order actions instead.
		if ( 'requires_capture' === (string) $order->get_meta( '_intention_status', true ) ) {
			return new WP_Error(
				'uncaptured-payment',
				/* translators: an error message which will appear if a user tries to refund an order which has been authorized but not yet charged. */
				__( "This payment is not captured yet. To cancel this order, please go to 'Order Actions' > 'Cancel authorization'. To proceed with a refund, please go to 'Order Actions' > 'Capture charge' to charge the payment card, and then trigger a refund via the 'Refund' button.", 'woocommerce' )
			);
		}

		$refund_amount  = null === $amount ? 0.0 : (float) $amount;
		$is_zero_refund = '0.00' === sprintf( '%0.2f', $refund_amount );

		if ( ! $is_zero_refund && ( $refund_amount < 0 || $refund_amount > (float) $order->get_total() ) ) {
			return new WP_Error( 'invalid-amount', __( 'The refund amount is not valid.', 'woocommerce' ) );
		}

		if ( ! $is_zero_refund && ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'native_payment_refund_missing_charge', __( 'This order does not have a WooPayments charge to refund.', 'woocommerce' ) );
		}

		$result = $this->get_processing_service()->process_refund(
			PaymentContext::for_refund( $order, $this->id, $refund_amount, (string) $reason ),
			$this->get_provider()
		);

		// The order has no refund row to link, so the service refused before the platform call.
		// Return client 11.1.0's code and message (class-wc-payment-gateway-wcpay.php:3003-3007);
		// no money moved, so record neither the success event nor a failure.
		if ( is_wp_error( $result ) && 'native_payment_refund_not_found' === $result->get_error_code() ) {
			return new WP_Error( 'wcpay_edit_order_refund_not_found', $result->get_error_message() );
		}

		// The lock refusal made no platform attempt, so there is no failure to record.
		// This gates by exclusion: every other WP_Error from the processing service today
		// comes from an actual platform refund attempt. A new pre-flight refusal added
		// inside the service must be excluded here too, or it will start recording
		// failures for refunds that never reached the provider.
		if ( is_wp_error( $result ) && 'native_payment_refund_locked' !== $result->get_error_code() ) {
			$this->record_refund_failure( $order, $refund_amount, $result );
		} elseif ( true === $result && ! $is_zero_refund ) {
			wc_admin_record_tracks_event( 'wcpay_edit_order_refund_success' );
		}

		return $result;
	}

	/**
	 * Record a failed synchronous refund attempt on the order.
	 *
	 * The merchant is looking at the order screen when a refund fails, and the error they
	 * dismissed is otherwise gone: the note and the failed refund status keep the failure
	 * visible on the order itself.
	 *
	 * @param WC_Order $order  Order that was being refunded.
	 * @param float    $amount Refund amount.
	 * @param WP_Error $error  Refund failure.
	 */
	private function record_refund_failure( WC_Order $order, float $amount, WP_Error $error ): void {
		$note_service    = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$intent_currency = (string) $order->get_meta( '_wcpay_intent_currency', true );
		$currency        = '' !== $intent_currency ? $intent_currency : (string) $order->get_currency();

		// Plugin 11.1.0 sends the failure note as the reason, or the error message when it adds no generic note.
		$tracks_reason = $error->get_error_message();
		if ( 'insufficient_balance_for_refund' === $error->get_error_code() ) {
			// The dedicated note carries the funding guidance; the generic failure
			// line (and its log) is deliberately skipped, matching the extension.
			$note = $note_service->format_insufficient_balance_refund_note( $order, $amount, $currency, $this->get_account_service()->get_account_country() );
		} else {
			$note          = $note_service->format_refund_failure_note( $order, $amount, $currency, $error->get_error_message() );
			$tracks_reason = $note;

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					$note,
					array(
						'source'   => 'woopayments-payments',
						'order_id' => $order->get_id(),
					)
				);
			}
		}

		$order->add_order_note( $note );
		$order->update_meta_data( '_wcpay_refund_status', 'failed' );
		$order->save();
		wc_admin_record_tracks_event( 'wcpay_edit_order_refund_failure', array( 'reason' => $tracks_reason ) );
	}

	/**
	 * Tell whether an order can be refunded through WooPayments.
	 *
	 * @param WC_Order|mixed $order Order object.
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return $order instanceof WC_Order
			&& $this->supports( PaymentGatewayFeature::REFUNDS )
			&& '' !== (string) $order->get_meta( '_charge_id', true );
	}

	/**
	 * Get the recommended payment methods list for onboarding.
	 *
	 * @param string $country_code Optional. Business location country code.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recommended_payment_methods( string $country_code = '' ): array {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code ) {
			return array();
		}

		$locale = get_user_locale();
		$cached = $this->get_cached_recommended_payment_methods( $country_code, $locale );
		if ( null !== $cached ) {
			return $cached;
		}

		try {
			$recommended_pms = $this->get_api_client()->get_recommended_payment_methods( $country_code, $locale );
		} catch ( Throwable $exception ) {
			$this->get_logger()->log_throwable( 'Failed to fetch the WooPayments recommended payment methods: ' . $exception->getMessage(), $exception );

			return array();
		}

		$recommended_pms = $this->normalize_recommended_payment_methods( $recommended_pms );
		if ( ! empty( $recommended_pms ) ) {
			$this->set_cached_recommended_payment_methods( $country_code, $locale, $recommended_pms );
		}

		return $recommended_pms;
	}

	/**
	 * Normalize recommended payment methods for the settings provider pipeline.
	 *
	 * @param array $recommended_pms Raw recommended payment methods.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_recommended_payment_methods( array $recommended_pms ): array {
		$recommended_pms = array_values(
			array_filter(
				$recommended_pms,
				static function ( $payment_method ): bool {
					return is_array( $payment_method ) && isset( $payment_method['id'], $payment_method['title'] );
				}
			)
		);

		return array_map(
			static function ( array $payment_method, int $index ): array {
				if ( ! isset( $payment_method['enabled'] ) ) {
					$payment_method['enabled'] = ! isset( $payment_method['type'] ) || 'available' !== $payment_method['type'];
				}

				$payment_method['priority'] = isset( $payment_method['priority'] ) ? (int) $payment_method['priority'] : $index;

				return $payment_method;
			},
			$recommended_pms,
			array_keys( $recommended_pms )
		);
	}

	/**
	 * Get cached recommended payment methods for a country and locale.
	 *
	 * @param string $country_code Business location country code.
	 * @param string $locale       User locale.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function get_cached_recommended_payment_methods( string $country_code, string $locale ): ?array {
		$cached = get_transient( self::RECOMMENDED_PAYMENT_METHODS_CACHE_KEY );
		if ( ! is_array( $cached ) ||
			( $cached['country_code'] ?? '' ) !== $country_code ||
			( $cached['__locale'] ?? '' ) !== $locale ||
			! isset( $cached['payment_methods'] ) ||
			! is_array( $cached['payment_methods'] ) ) {

			return null;
		}

		return $cached['payment_methods'];
	}

	/**
	 * Cache recommended payment methods for a country and locale.
	 *
	 * @param string $country_code    Business location country code.
	 * @param string $locale          User locale.
	 * @param array  $payment_methods Recommended payment methods.
	 * @return void
	 */
	private function set_cached_recommended_payment_methods( string $country_code, string $locale, array $payment_methods ): void {
		set_transient(
			self::RECOMMENDED_PAYMENT_METHODS_CACHE_KEY,
			array(
				'payment_methods' => $payment_methods,
				'__locale'        => $locale,
				'country_code'    => $country_code,
			),
			self::RECOMMENDED_PAYMENT_METHODS_CACHE_TTL
		);
	}

	/**
	 * Get the default card payment method definition.
	 *
	 * @return WooPaymentsPaymentMethodDefinition
	 * @throws \RuntimeException When the registry does not contain the card definition.
	 */
	private function get_default_payment_method_definition(): WooPaymentsPaymentMethodDefinition {
		$definition = ( new WooPaymentsPaymentMethodRegistry() )->get( 'card' );

		if ( null === $definition ) {
			throw new \RuntimeException( 'The native WooPayments card payment method definition is missing.' );
		}

		return $definition;
	}

	/**
	 * Get the untranslated gateway method title.
	 *
	 * @return string
	 */
	private function get_untranslated_method_title(): string {
		if ( 'card' === $this->get_payment_method_id() ) {
			return self::METHOD_TITLE;
		}

		// Deliberately country-less: this is the construction-time placeholder, and
		// resolving the account country can fire an account API request when the
		// cache is stale - the reference client keeps that out of construction too
		// (its country branding happens on init / at title-render time).
		// get_method_title() swaps in the country-branded form on first read.
		return sprintf( 'WooPayments (%s)', $this->payment_method_definition->get_title() );
	}

	/**
	 * Refresh site-derived gateway state after a multisite blog switch.
	 */
	private function ensure_current_blog_context(): void {
		$current_blog_id = get_current_blog_id();
		if ( $this->settings_blog_id === $current_blog_id ) {
			return;
		}

		$this->settings_blog_id     = $current_blog_id;
		$this->tokens               = array();
		$this->branded_title        = null;
		$this->branded_method_title = null;
		$this->title                = $this->payment_method_definition->get_title();
		$this->method_title         = $this->get_untranslated_method_title();
		$this->init_settings();
		$this->refresh_site_supports(
			'card' === $this->get_payment_method_id()
				? 'yes' === $this->get_option( 'saved_cards' )
				: $this->is_saved_cards_enabled()
		);
	}

	/**
	 * Rebuild the capabilities this gateway adds, keeping out any that other code removed.
	 *
	 * @param bool|null $saved_cards_enabled Current-blog saved-card setting when already loaded.
	 */
	private function refresh_site_supports( ?bool $saved_cards_enabled = null ): void {
		$this->externally_removed_supports = array_values(
			array_unique(
				array_merge(
					array_diff( $this->externally_removed_supports, $this->supports ),
					array_diff( $this->site_supports, $this->supports )
				)
			)
		);
		$this->supports                    = array_values( array_diff( $this->supports, $this->site_supports ) );
		$base_supports                     = $this->supports;
		$this->init_supported_features( $saved_cards_enabled );
		$this->site_supports = array_values( array_diff( $this->supports, $base_supports ) );
		$this->supports      = array_values( array_diff( $this->supports, $this->externally_removed_supports ) );
	}

	/**
	 * Get the translated shopper-facing payment method title.
	 *
	 * @return string
	 */
	private function get_translated_payment_method_title(): string {
		return $this->payment_method_definition->get_title( $this->get_account_country() );
	}

	/**
	 * Get the translated gateway method title.
	 *
	 * @return string
	 */
	private function get_translated_method_title(): string {
		if ( 'card' === $this->get_payment_method_id() ) {
			return __( 'WooPayments', 'woocommerce' );
		}

		return sprintf(
			/* translators: %s: WooPayments payment method title. */
			__( 'WooPayments (%s)', 'woocommerce' ),
			$this->get_translated_payment_method_title()
		);
	}

	/**
	 * Tell whether this gateway's payment method definition supports a capability.
	 *
	 * @param string $capability Payment method capability.
	 * @return bool
	 */
	private function payment_method_supports( string $capability ): bool {
		return in_array( $capability, $this->payment_method_definition->get_capabilities(), true );
	}

	/**
	 * Get the payment processing service.
	 *
	 * @return PaymentProcessingService
	 */
	private function get_processing_service(): PaymentProcessingService {
		if ( ! isset( $this->processing_service ) ) {
			$this->processing_service = wc_get_container()->get( PaymentProcessingService::class );
		}

		return $this->processing_service;
	}

	/**
	 * Get the WooPayments provider.
	 *
	 * @return WooPaymentsProvider
	 */
	private function get_provider(): WooPaymentsProvider {
		if ( ! isset( $this->provider ) ) {
			$this->provider = wc_get_container()->get( WooPaymentsProvider::class );
		}

		return $this->provider;
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
	 * Get the WooPayments checkout bridge.
	 *
	 * @return WooPaymentsCheckoutBridge
	 */
	private function get_checkout_bridge(): WooPaymentsCheckoutBridge {
		if ( ! isset( $this->checkout_bridge ) ) {
			$this->checkout_bridge = wc_get_container()->get( WooPaymentsCheckoutBridge::class );
		}

		return $this->checkout_bridge;
	}

	/**
	 * Get the native WooPayments API client.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		if ( ! isset( $this->api_client ) ) {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		}

		return $this->api_client;
	}

	/**
	 * Get the native WooPayments account service.
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
	 * Get the WooPayments logger, which writes only when debug logging is on (client 11.1.0 `src/Internal/Logger.php:64-91`).
	 *
	 * @return WooPaymentsLogger
	 */
	private function get_logger(): WooPaymentsLogger {
		return wc_get_container()->get( WooPaymentsLogger::class );
	}

	/**
	 * Get the native WooPayments token service.
	 *
	 * @return WooPaymentsTokenService
	 */
	private function get_token_service(): WooPaymentsTokenService {
		if ( ! isset( $this->token_service ) ) {
			$this->token_service = wc_get_container()->get( WooPaymentsTokenService::class );
		}

		return $this->token_service;
	}

	/**
	 * Get the Stripe Billing module, which answers whether Stripe bills a subscription.
	 *
	 * @return WooPaymentsStripeBillingModule
	 */
	private function get_stripe_billing_module(): WooPaymentsStripeBillingModule {
		return wc_get_container()->get( WooPaymentsStripeBillingModule::class );
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
	 * Get the WooPayments failed-transaction rate limiter.
	 *
	 * @return WooPaymentsFailedTransactionRateLimiter
	 */
	private function get_failed_transaction_rate_limiter(): WooPaymentsFailedTransactionRateLimiter {
		if ( ! isset( $this->failed_transaction_rate_limiter ) ) {
			$this->failed_transaction_rate_limiter = wc_get_container()->get( WooPaymentsFailedTransactionRateLimiter::class );
		}

		return $this->failed_transaction_rate_limiter;
	}

	/**
	 * Get the WooPayments duplicate-payment prevention service.
	 *
	 * @return WooPaymentsDuplicatePaymentPreventionService
	 */
	private function get_duplicate_payment_prevention_service(): WooPaymentsDuplicatePaymentPreventionService {
		if ( ! isset( $this->duplicate_payment_prevention_service ) ) {
			$this->duplicate_payment_prevention_service = wc_get_container()->get( WooPaymentsDuplicatePaymentPreventionService::class );
		}

		return $this->duplicate_payment_prevention_service;
	}

	/**
	 * Get a fraud-prevention error message for the current request.
	 *
	 * @param bool $is_checkout Whether the request is a checkout payment request.
	 * @return string
	 */
	private function get_fraud_prevention_error_message( bool $is_checkout ): string {
		if ( $is_checkout ) {
			/**
			 * Identifies WooPay Store API requests handled by the standalone WooPayments integration.
			 *
			 * This intentionally uses the standalone WooPayments plugin's `wcpay_` hook name
			 * (not the native `woocommerce_woopayments_*` prefix) for parity: WooPay Store API flows
			 * already provide their own fraud-prevention checks in the extension runtime.
			 *
			 * @since 11.0.0
			 * @param bool $is_woopay_store_api_request Whether the request is a WooPay Store API request.
			 */
			$is_woopay_store_api_request = (bool) apply_filters( 'wcpay_is_woopay_store_api_request', false );

			if ( $is_woopay_store_api_request ) {
				return '';
			}
		}

		$fraud_prevention_service = $this->get_fraud_prevention_service();
		if ( ! $fraud_prevention_service->has_session() || ! $fraud_prevention_service->is_enabled() ) {
			return '';
		}

		if ( $fraud_prevention_service->verify_token( $this->sanitize_post_string( WooPaymentsFraudPreventionService::TOKEN_NAME ) ) ) {
			return '';
		}

		return $is_checkout
			? __( "We're not able to process this payment. Please refresh the page and try again.", 'woocommerce' )
			: __( "We're not able to add this payment method. Please refresh the page and try again.", 'woocommerce' );
	}

	/**
	 * Get a failed-transaction rate-limiter error message for the current request.
	 *
	 * @return string
	 */
	private function get_failed_transaction_rate_limiter_error_message(): string {
		$rate_limiter = $this->get_failed_transaction_rate_limiter();
		if ( ! $rate_limiter->has_session() || ! $rate_limiter->is_limited() ) {
			return '';
		}

		return __( 'Your payment was not processed.', 'woocommerce' );
	}

	/**
	 * Bump the failed-transaction rate limiter when the provider returned a card-decline failure.
	 *
	 * @param PaymentOutcome $outcome Provider payment outcome.
	 * @return void
	 */
	private function maybe_bump_failed_transaction_rate_limiter( PaymentOutcome $outcome ): void {
		if ( PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ) {
			return;
		}

		$data       = $outcome->get_data();
		$error_code = isset( $data[ PaymentOutcome::DATA_ERROR_CODE ] ) && is_scalar( $data[ PaymentOutcome::DATA_ERROR_CODE ] )
			? (string) $data[ PaymentOutcome::DATA_ERROR_CODE ]
			: '';

		if ( ! self::should_bump_failed_transaction_rate_limiter( $error_code ) ) {
			return;
		}

		$this->get_failed_transaction_rate_limiter()->bump();
	}

	/**
	 * Tell whether the provider error code should count toward the failed-transaction limiter.
	 *
	 * @param string $error_code Provider error code.
	 * @return bool
	 */
	private static function should_bump_failed_transaction_rate_limiter( string $error_code ): bool {
		return in_array( $error_code, array( 'card_declined', 'incorrect_number', 'incorrect_cvc' ), true );
	}

	/**
	 * Add the safe shopper notice for a failed provider checkout outcome.
	 *
	 * @param PaymentOutcome $outcome Provider payment outcome.
	 * @return void
	 */
	private static function maybe_add_failed_checkout_notice( PaymentOutcome $outcome ): void {
		if ( PaymentOutcome::STATUS_FAILED !== $outcome->get_status() ) {
			return;
		}

		$data    = $outcome->get_data();
		$message = $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? '';
		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			$message = WooPaymentsErrorMessages::get_generic_message();
		}

		wc_add_notice( $message, 'error', array( 'icon' => 'error' ) );
	}

	/**
	 * Format a WooCommerce checkout result from an outcome.
	 *
	 * @param PaymentContext $context Payment context.
	 * @param WC_Order       $order   Order object.
	 * @param PaymentOutcome $outcome Provider outcome.
	 * @return array<string,string>
	 */
	private static function format_checkout_result( PaymentContext $context, WC_Order $order, PaymentOutcome $outcome ): array {
		if ( PaymentOutcome::STATUS_FAILED === $outcome->get_status() ) {
			return array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		$payment_method_id = '' !== $outcome->get_payment_method_id() ? $outcome->get_payment_method_id() : $context->get_payment_method_id();
		$data              = $outcome->get_data();
		$redirect          = array_key_exists( PaymentOutcome::DATA_CHECKOUT_REDIRECT, $data )
			? (string) $data[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ]
			: ( '' !== $outcome->get_redirect_url() ? $outcome->get_redirect_url() : $order->get_checkout_order_received_url() );

		return array(
			'result'         => 'success',
			'redirect'       => $redirect,
			'payment_method' => $payment_method_id,
		);
	}

	/**
	 * Complete WC Subscriptions bookkeeping after a successful customer payment-method change.
	 *
	 * @param WC_Order             $order                  Subscription order.
	 * @param array<string,string> $result                 Native checkout result.
	 * @param PaymentOutcome       $outcome                Provider payment outcome.
	 * @param bool                 $is_subscription_change Whether this is a validated new-method change.
	 * @return void
	 */
	private function maybe_handle_subscription_change_payment_success( WC_Order $order, array $result, PaymentOutcome $outcome, bool $is_subscription_change ): void {
		if ( ! $is_subscription_change || 'success' !== ( $result['result'] ?? '' ) ) {
			return;
		}

		if ( $this->is_confirmation_redirect_result( $result ) ) {
			$this->maybe_set_delayed_subscription_update_all_marker( $order );
			return;
		}
		if ( ! $this->is_terminal_subscription_change_outcome( $outcome ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
			return;
		}

		// WCS resets the title to the gateway's; client 11.1.0 brands the subscription after this call, so it keeps the card title.
		$branded_title = $order->get_payment_method_title();
		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $order, $this->id );
		if ( '' !== $branded_title && $branded_title !== $order->get_payment_method_title() ) {
			$order->set_payment_method_title( $branded_title );
			$order->save();
		}

		remove_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_payment_method_for_subscriptions' ), 10 );
	}

	/**
	 * Note the saved payment method a customer switched their subscription to, and announce the change, as the plugin does.
	 *
	 * @param WC_Order             $order                  Subscription order.
	 * @param array<string,string> $result                 Native checkout result.
	 * @param PaymentOutcome       $outcome                Provider payment outcome.
	 * @param bool                 $is_saved_method_change Whether this is a validated change to a saved payment method.
	 * @return void
	 */
	private function maybe_handle_saved_method_subscription_change_success( WC_Order $order, array $result, PaymentOutcome $outcome, bool $is_saved_method_change ): void {
		if ( ! $is_saved_method_change || 'success' !== ( $result['result'] ?? '' ) || $this->is_confirmation_redirect_result( $result ) || ! $this->is_terminal_subscription_change_outcome( $outcome ) ) {
			return;
		}

		$token = $this->get_token_service()->get_valid_token_from_token_id( $this->sanitize_post_string( 'wc-' . $this->id . '-payment-token' ), $order->get_user_id() );
		if ( ! $token instanceof WC_Payment_Token ) {
			return;
		}

		$order->add_order_note( self::get_payment_method_changed_note( $token ) );

		/**
		 * Fires after a customer changed the payment method of a subscription to a saved one.
		 *
		 * This intentionally keeps the standalone WooPayments plugin's action name for parity.
		 * Do not rename it; see the class doc block for the rationale.
		 *
		 * @since 11.2.0
		 *
		 * @param WC_Order         $order The subscription.
		 * @param WC_Payment_Token $token The new payment token.
		 */
		do_action( 'woocommerce_payments_changed_subscription_payment_method', $order, $token );
	}

	/**
	 * Get the note that names the payment method a subscription was changed to.
	 *
	 * @param WC_Payment_Token $token New payment token.
	 * @return string
	 */
	private static function get_payment_method_changed_note( WC_Payment_Token $token ): string {
		if ( $token instanceof WooPaymentsLinkToken ) {
			return sprintf(
				/* translators: %1$s: redacted email address for Link payment method */
				__( 'Payment method is changed to: <strong>Link ending in %1$s</strong>.', 'woocommerce' ),
				esc_html( $token->get_redacted_email() )
			);
		}

		return sprintf(
			/* translators: %1$s: the last 4 digit of the credit card */
			__( 'Payment method is changed to: <strong>Credit card ending in %1$s</strong>.', 'woocommerce' ),
			$token instanceof WC_Payment_Token_CC ? esc_html( $token->get_last4() ) : '----'
		);
	}

	/**
	 * Tell whether a new subscription credential reached an authorized terminal state.
	 *
	 * Generic no-external-payment success is valid for some zero-total checkouts, but it cannot
	 * complete a new-method subscription change because no reusable credential was established.
	 *
	 * @param PaymentOutcome $outcome Provider payment outcome.
	 * @return bool
	 */
	private function is_terminal_subscription_change_outcome( PaymentOutcome $outcome ): bool {
		return in_array( $outcome->get_status(), array( PaymentOutcome::STATUS_COMPLETED, PaymentOutcome::STATUS_AUTHORIZED ), true );
	}

	/**
	 * Tell whether the current request is a WC Subscriptions new-payment-method change.
	 *
	 * @param WC_Order $order Subscription order.
	 * @return bool
	 */
	private function is_subscription_change_payment_request( WC_Order $order ): bool {
		if ( ! $this->is_subscription_payment_method_change_request( $order ) ) {
			return false;
		}

		$token_key = 'wc-' . $this->id . '-payment-token';

		return ! isset( $_POST[ $token_key ] ) || 'new' === sanitize_text_field( wp_unslash( $_POST[ $token_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Tell whether the current request is a validated WC Subscriptions payment-method change.
	 *
	 * @param WC_Order $order Subscription order.
	 * @return bool
	 */
	private function is_subscription_payment_method_change_request( WC_Order $order ): bool {
		if ( ! isset( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_change_payment_method' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$request_id = 0;
		if ( isset( $_POST['woocommerce_change_payment'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$request_id = absint( $_POST['woocommerce_change_payment'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_POST['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$request_id = absint( $_POST['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_GET['change_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$request_id = absint( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return 0 < $request_id
			&& $order->get_id() === $request_id
			&& function_exists( 'wcs_is_subscription' )
			&& (bool) wcs_is_subscription( $request_id );
	}

	/**
	 * Tell whether the current order-pay form is a validated subscription payment-method change.
	 *
	 * @return bool
	 */
	private function is_subscription_change_payment_form(): bool {
		if ( ! $this->is_subscriptions_enabled() || ! function_exists( 'wcs_is_subscription' ) ) {
			return false;
		}

		global $wp;

		$order_id   = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
		$request_id = isset( $_GET['change_payment_method'] ) ? absint( $_GET['change_payment_method'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 0 < $request_id
			&& $order_id === $request_id
			&& (bool) wcs_is_subscription( $request_id );
	}

	/**
	 * Tell whether the current cart contains a subscription, ignoring subscription renewals.
	 *
	 * Renewal-blind, unlike WooPaymentsCheckoutBridge::cart_contains_subscription(), which counts renewals.
	 *
	 * @return bool
	 */
	private function cart_contains_subscription(): bool {
		// Deliberately renewal-blind: this gates only the classic save checkbox, and the
		// extension's display_save_payment_method_checkbox keeps that surface showing the
		// checkbox on a renewal-only cart (the save is forced server-side regardless).
		// The renewal-inclusive check lives in WooPaymentsSubscriptionMethodPolicy for the
		// surfaces the extension does include renewals on.
		return class_exists( 'WC_Subscriptions_Cart' )
			&& is_callable( array( 'WC_Subscriptions_Cart', 'cart_contains_subscription' ) )
			&& (bool) \WC_Subscriptions_Cart::cart_contains_subscription();
	}

	/**
	 * Tell whether the native result redirects into the WooPayments confirmation bridge.
	 *
	 * @param array<string,string> $result Checkout result.
	 * @return bool
	 */
	private function is_confirmation_redirect_result( array $result ): bool {
		$redirect = isset( $result['redirect'] ) ? (string) $result['redirect'] : '';

		return 0 === strpos( $redirect, '#wcpay-confirm-' );
	}

	/**
	 * Carry an order-pay save choice across the local confirmation redirect.
	 *
	 * @param PaymentContext       $context Payment context.
	 * @param WC_Order             $order   Order being paid.
	 * @param array<string,string> $result  Native checkout result.
	 * @return array<string,string>
	 */
	private function maybe_add_order_pay_save_intent_to_confirmation_redirect( PaymentContext $context, WC_Order $order, array $result ): array {
		if (
			! isset( $_POST['woocommerce_pay'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			|| ! ( $context->get_payment_data()['save_payment_method'] ?? false )
			|| ! $this->is_confirmation_redirect_result( $result )
		) {
			return $result;
		}

		$payment_url     = $order->get_checkout_payment_url();
		$fragment_offset = strpos( $payment_url, '#' );
		if ( false !== $fragment_offset ) {
			$payment_url = substr( $payment_url, 0, $fragment_offset );
		}

		$result['redirect'] = add_query_arg( 'save_payment_method', 'yes', $payment_url ) . $result['redirect'];

		return $result;
	}

	/**
	 * Set the delayed update-all marker for SCA subscription payment-method changes when requested.
	 *
	 * @param WC_Order $order Subscription order.
	 * @return void
	 */
	private function maybe_set_delayed_subscription_update_all_marker( WC_Order $order ): void {
		if ( empty( $_POST['update_all_subscriptions_payment_method'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$gateway_id = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : $this->id; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order->update_meta_data( '_delayed_update_payment_method_all', $gateway_id );
		$order->save();
	}

	/**
	 * Initialize the WooPayments support list.
	 *
	 * @param bool|null $saved_cards_enabled Current-blog saved-card setting when already loaded.
	 * @return void
	 */
	private function init_supported_features( ?bool $saved_cards_enabled = null ): void {
		if ( $this->is_subscriptions_enabled() ) {
			$this->supports = array_merge(
				$this->supports,
				array(
					'multiple_subscriptions',
					'subscription_cancellation',
					'subscription_payment_method_change_admin',
					'subscription_payment_method_change_customer',
					'subscription_payment_method_change',
					'subscription_reactivation',
					'subscription_suspension',
					'subscriptions',
				)
			);

			// Stripe schedules the payments of Stripe Billing subscriptions, so their amounts and dates cannot change here.
			$this->supports = array_merge(
				$this->supports,
				$this->get_stripe_billing_module()->is_stripe_billing_enabled()
					? array( 'gateway_scheduled_payments' )
					: array( 'subscription_amount_changes', 'subscription_date_changes' )
			);
		}

		if ( ( $saved_cards_enabled ?? $this->is_saved_cards_enabled() ) && $this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_TOKENIZATION ) ) {
			$this->supports[] = PaymentGatewayFeature::TOKENIZATION;
			$this->supports[] = PaymentGatewayFeature::ADD_PAYMENT_METHOD;
		}

		$this->supports = array_values( array_unique( $this->supports ) );
		$this->register_subscription_handlers();
	}

	/**
	 * Register gateway-specific subscription handlers.
	 *
	 * @return void
	 */
	private function register_subscription_handlers(): void {
		if ( ! $this->owns_subscription_renewal_hooks() ) {
			return;
		}

		WooPaymentsSubscriptionRenewalHooks::attach( $this );
	}

	/**
	 * Tell whether this gateway owns automatic subscription renewal handling.
	 *
	 * Link credentials remain attached to the base card gateway, and Amazon Pay renewals use a
	 * preserved compatibility gateway ID handled by the same base gateway instance. While the plugin owns
	 * payments, its gateway renews: attaching the native handler too would charge each renewal twice.
	 *
	 * @return bool
	 */
	private function owns_subscription_renewal_hooks(): bool {
		return OrderPaymentStore::GATEWAY_ID === $this->id
			&& $this->is_subscriptions_enabled()
			&& wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->should_native_register();
	}

	/**
	 * Add WooPayments subscription emails to WooCommerce.
	 *
	 * @internal
	 *
	 * @param mixed $email_classes WooCommerce email classes; another callback may have left a non-array.
	 * @return mixed The email classes with the WooPayments ones added, or the input unchanged when it is not an array.
	 */
	public static function add_subscription_emails( $email_classes ) {
		if ( ! is_array( $email_classes ) ) {
			return $email_classes;
		}

		if ( ! class_exists( 'WC_Email_Failed_Order' ) ) {
			require_once WC_ABSPATH . 'includes/emails/class-wc-email-failed-order.php';
		}

		$failed_renewal_authentication = new WooPaymentsFailedRenewalAuthenticationEmail( $email_classes );
		$failed_renewal_authentication->init_hooks();
		$email_classes['WC_Payments_Email_Failed_Renewal_Authentication'] = $failed_renewal_authentication;

		$failed_authentication_retry = new WooPaymentsFailedAuthenticationRetryEmail();
		$failed_authentication_retry->init_hooks();
		$email_classes['WC_Payments_Email_Failed_Authentication_Retry'] = $failed_authentication_retry;

		return $email_classes;
	}

	/**
	 * Get the saved payment token from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Payment_Token|null
	 */
	private function get_payment_token_from_order( WC_Order $order ): ?WC_Payment_Token {
		return $this->get_token_service()->get_active_token_for_order( $order );
	}

	/**
	 * Whether the order's payment intent already succeeded.
	 *
	 * A refused checkout must not fail an order whose intent already succeeded, including a
	 * subscription whose payment method was changed with a setup intent (client 11.1.0 `gw:1283`).
	 *
	 * @param WC_Order $order Order being paid.
	 * @return bool
	 */
	private function has_succeeded_intent( WC_Order $order ): bool {
		return 'succeeded' === (string) $order->get_meta( '_intention_status', true );
	}

	/**
	 * Keep an order whose intent already succeeded when checkout fails after the payment.
	 *
	 * Client 11.1.0 `gw:1283-1304`: the order keeps its status, gets a diagnostic note and a warning
	 * log, and checkout returns success so a shopper who was already charged does not pay again.
	 * The warning is not behind the debug-log setting, like the gateway's other error logs.
	 *
	 * @param WC_Order $order   Order being paid.
	 * @param string   $message Error message of the failure.
	 * @param string   $failure The exception class, or the refusal code where native returns instead of throwing.
	 * @return array<string,string>
	 */
	private function keep_succeeded_intent_order( WC_Order $order, string $message, string $failure ): array {
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message from the downstream exception */
				__( 'Payment succeeded, but a downstream error occurred during post-payment processing: %s. Order status preserved.', 'woocommerce' ),
				esc_html( $message )
			)
		);

		wc_get_logger()->warning(
			sprintf( 'Payment intent already succeeded; downstream %s on order #%d suppressed to preserve order status.', $failure, $order->get_id() ),
			array(
				'source'   => 'woopayments',
				'order_id' => $order->get_id(),
			)
		);

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Settle checkout without failing an order whose payment is authorized, after a PHP Error applying the outcome.
	 *
	 * The client fatals there (`gw:1272` catches only Exception) and leaves the order as it was. Native leaves it too (monitor
	 * ruling 2026-10-04 (4)). When the order already shows the authorization, checkout answers as it does for an authorized
	 * payment, so a retry cannot authorize the card again on a new order; otherwise it shows the generic notice (ruling F2).
	 *
	 * @param WC_Order                     $order     Order being paid.
	 * @param PaymentOutcomeApplyException $exception Handed-back authorized outcome and the PHP Error that stopped it.
	 * @return array<string,string>
	 */
	private function keep_authorized_order_after_php_error( WC_Order $order, PaymentOutcomeApplyException $exception ): array {
		$failure = $exception->get_failure();
		$outcome = $exception->get_outcome();
		$context = array(
			'order_id'                 => $order->get_id(),
			'intent_id'                => $outcome->get_provider_payment_id(),
			'reconciliation_persisted' => $exception->was_reconciliation_context_persisted(),
		);
		// Logged before the order is read again, so a failing read cannot lose the original error (review 35 F3).
		$this->get_logger()->log_throwable_always(
			sprintf(
				'Applying the authorized payment to order #%1$d raised %2$s: %3$s.',
				$order->get_id(),
				get_class( $failure ),
				$failure->getMessage()
			),
			$failure,
			$context
		);

		$shows_authorization = $this->order_shows_authorization( $order, $outcome );
		$this->get_logger()->log_always(
			sprintf(
				'Order #%d %s',
				$order->get_id(),
				$shows_authorization ? 'already shows the authorization, so checkout continues.' : 'was left for reconciliation.'
			),
			'warning',
			$context
		);

		if ( $shows_authorization ) {
			// The same answer process_order_payment() gives an authorized outcome (format_checkout_result()).
			return array(
				'result'         => 'success',
				'redirect'       => $order->get_checkout_order_received_url(),
				'payment_method' => $outcome->get_payment_method_id(),
			);
		}

		wc_add_notice( WooPaymentsErrorMessages::get_generic_message(), 'error', array( 'icon' => 'error' ) );

		return array(
			'result'         => 'failure',
			'redirect'       => '',
			'payment_method' => '',
		);
	}

	/**
	 * Tell whether the stored order already shows the authorization of the outcome's intent.
	 *
	 * Only a held card (`requires_capture`) or a `processing` intent counts, not a Multibanco voucher. Both put the order
	 * on hold (client 11.1.0 `class-wc-payments-order-service.php:410-417`); a webhook may since have moved it to a paid status.
	 *
	 * @param WC_Order       $order   Order being paid.
	 * @param PaymentOutcome $outcome Handed-back authorized outcome.
	 * @return bool
	 */
	private function order_shows_authorization( WC_Order $order, PaymentOutcome $outcome ): bool {
		$intent_id     = $outcome->get_provider_payment_id();
		$intent_status = $this->get_provider()->get_outcome_meta( $outcome )['_intention_status'] ?? '';
		if ( '' === $intent_id || ! in_array( $intent_status, array( 'requires_capture', 'processing' ), true ) ) {
			return false;
		}

		try {
			$fresh_order = $this->reread_order_authoritatively( $order );
		} catch ( Throwable $read_failure ) {
			// The order cannot be read, so it is not shown as authorized and checkout keeps the refusal.
			$this->get_logger()->log_throwable_always(
				sprintf( 'Reading order #%1$d again after the PHP error raised %2$s: %3$s.', $order->get_id(), get_class( $read_failure ), $read_failure->getMessage() ),
				$read_failure,
				array( 'order_id' => $order->get_id() )
			);
			return false;
		}

		return $intent_id === (string) $fresh_order->get_meta( '_intent_id', true )
			&& $fresh_order->has_status( array_merge( array( OrderStatus::ON_HOLD ), wc_get_is_paid_statuses() ) );
	}

	/**
	 * Read an order again from its data store, past the post, meta and order caches.
	 *
	 * @param WC_Order $order Order object.
	 * @return WC_Order
	 */
	private function reread_order_authoritatively( WC_Order $order ): WC_Order {
		$order_id = $order->get_id();
		clean_post_cache( $order_id );
		wp_cache_delete( WC_Order::generate_meta_cache_key( $order_id, 'orders' ), 'orders' );

		/**
		 * Active order data store.
		 *
		 * @var \WC_Object_Data_Store_Interface $data_store
		 */
		$data_store = $order->get_data_store();
		if ( is_callable( array( $data_store, 'clear_cached_data' ) ) ) {
			call_user_func( array( $data_store, 'clear_cached_data' ), array( $order_id ) );
		}

		$fresh_order = clone $order;
		$data_store->read( $fresh_order );
		/**
		 * Freshly read order.
		 *
		 * @var WC_Order $fresh_order
		 */
		$fresh_order->read_meta_data( true );

		return $fresh_order;
	}

	/**
	 * Tell whether a handed-back outcome reached a succeeded intent.
	 *
	 * The order may not carry the intent status yet when applying the outcome failed before it was saved.
	 *
	 * @param Exception $exception Exception caught by process_payment().
	 * @return bool
	 */
	private function is_succeeded_intent_outcome( Exception $exception ): bool {
		if ( ! $exception instanceof PaymentOutcomeApplyException ) {
			return false;
		}

		return 'succeeded' === ( $this->get_provider()->get_outcome_meta( $exception->get_outcome() )['_intention_status'] ?? '' );
	}

	/**
	 * Fail checkout after an exception when the order's intent has not succeeded.
	 *
	 * Client 11.1.0 `gw:1305-1439`: the order fails, except on a subscription payment-method change
	 * (`:1326`); a payment that was attempted gets the failure note (`:1354-1401`); the shopper gets
	 * the error as a notice and checkout returns a failure (`:1425`, `:1436-1439`).
	 *
	 * @param WC_Order  $order   Order being paid.
	 * @param Throwable $failure Failure raised while processing the payment.
	 * @return array<string,string>
	 */
	private function fail_checkout_after_exception( WC_Order $order, Throwable $failure ): array {
		$this->log_checkout_payment_failure( $order, $failure );

		if ( ! $this->is_subscription_payment_method_change_request( $order ) ) {
			$order->update_status( OrderStatus::FAILED );
		}

		if ( $this->checkout_payment_started ) {
			$note_candidates = wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_checkout_payment_failed_note_candidates( $order, $failure->getMessage(), '', '', '' );
			$order->add_order_note( $note_candidates[0] );
		}

		// The plugin's catch only ever sees an Exception; a PHP error's message is not shopper copy.
		$message = $failure instanceof Exception ? wp_strip_all_tags( $failure->getMessage() ) : WooPaymentsErrorMessages::get_generic_message();
		wc_add_notice( $message, 'error', array( 'icon' => 'error' ) );

		return array(
			'result'         => 'failure',
			'redirect'       => '',
			'payment_method' => '',
		);
	}

	/**
	 * Log a failure raised while paying at checkout, as client 11.1.0 `Logger::exception()` does (gw:1274).
	 *
	 * @param WC_Order  $order   Order being paid.
	 * @param Throwable $failure Failure raised while processing the payment.
	 */
	private function log_checkout_payment_failure( WC_Order $order, Throwable $failure ): void {
		$this->get_logger()->log_throwable(
			'Error occurred during the payment process. Exception: ' . $failure->getMessage(),
			$failure,
			array( 'order_id' => $order->get_id() )
		);
	}

	/**
	 * Build an add-payment-method error response and customer notice.
	 *
	 * @param string $message Error message.
	 * @return array<string,string>
	 */
	private function add_payment_method_error( string $message ): array {
		wc_add_notice( $message, 'error', array( 'icon' => 'error' ) );

		return array( 'result' => 'error' );
	}

	/**
	 * Get the payment method ID from a SetupIntent response.
	 *
	 * @param array<string,mixed> $setup_intent SetupIntent response.
	 * @return string
	 */
	private function get_setup_intent_payment_method_id( array $setup_intent ): string {
		if ( isset( $setup_intent['payment_method'] ) && is_string( $setup_intent['payment_method'] ) ) {
			return $setup_intent['payment_method'];
		}

		if ( isset( $setup_intent['payment_method'] ) && is_array( $setup_intent['payment_method'] ) && isset( $setup_intent['payment_method']['id'] ) ) {
			return (string) $setup_intent['payment_method']['id'];
		}

		return '';
	}

	/**
	 * Get the WooPayments customer ID from a SetupIntent response.
	 *
	 * @param array<string,mixed> $setup_intent SetupIntent response.
	 * @return string
	 */
	private function get_setup_intent_customer_id( array $setup_intent ): string {
		if ( isset( $setup_intent['customer'] ) && is_string( $setup_intent['customer'] ) ) {
			return $setup_intent['customer'];
		}

		if ( isset( $setup_intent['customer'] ) && is_array( $setup_intent['customer'] ) && isset( $setup_intent['customer']['id'] ) ) {
			return (string) $setup_intent['customer']['id'];
		}

		return '';
	}

	/**
	 * Fail the order when the client reported a payment method creation error.
	 *
	 * When Stripe rejects createPaymentMethod in the browser (element
	 * validation, some client-side declines), the checkout scripts submit the
	 * WooPayments error sentinel with the error details instead of a payment
	 * method, so the attempt is recorded as a failed order instead of leaving
	 * no trace. Mirrors the client plugin's PAYMENT_METHOD_ERROR handling
	 * (Payment_Information:290-297).
	 *
	 * @param WC_Order $order                  Order being paid.
	 * @param bool     $is_subscription_change Whether this is a validated subscription payment-method change.
	 * @return array<string,string>|null Failure result, or null when no client error was reported.
	 */
	private function maybe_fail_for_client_payment_method_error( WC_Order $order, bool $is_subscription_change = false ): ?array {
		if ( self::CLIENT_PAYMENT_METHOD_ERROR_SENTINEL !== $this->get_request_payment_method_id() ) {
			return null;
		}

		$message = $this->sanitize_post_string( 'wcpay-payment-method-error-message' );
		if ( '' === $message ) {
			$message = __( "We're not able to process this payment. Please try again later.", 'woocommerce' );
		}

		// The plugin reaches its catch with payment information built, so the merchant gets the payment-failed note
		// (client 11.1.0 `gw:1354-1401`). On a subscription payment-method change $order is the subscription itself:
		// 'failed' is not a valid subscription transition, and the existing method stays in place (`gw:1326`).
		return $this->refuse_checkout(
			$order,
			$message,
			'payment_method_error',
			! $is_subscription_change,
			wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_checkout_payment_failed_note_candidates( $order, $message, '', '', '' )[0]
		);
	}

	/**
	 * Refuse checkout before any charge, following the plugin's process_payment() catch.
	 *
	 * Client 11.1.0 `gw:1272-1439`: an order whose intent already succeeded is kept (`gw:1283-1304`); otherwise the
	 * refusal is logged (`gw:1274`), the order fails (`gw:1323-1328`), gets the refusal's note and the shopper the notice (`gw:1425`).
	 * Unlike the catch, it does not fire `woocommerce_payments_order_failed` (`gw:1317`) or `wcpay_update_payment_result_on_error`
	 * (`gw:1434`); both are recorded in the silent-hooks ledger (BC surface diff §1(a)).
	 *
	 * @param WC_Order $order       Order being paid.
	 * @param string   $message     Shopper-facing refusal message.
	 * @param string   $code        Refusal code, named in the logs.
	 * @param bool     $fail_order  Whether the order moves to failed.
	 * @param string   $note        The refusal's order note, or '' for none.
	 * @param string   $status_note Note attached to the failed status transition.
	 * @return array<string,string>
	 */
	private function refuse_checkout( WC_Order $order, string $message, string $code, bool $fail_order = true, string $note = '', string $status_note = '' ): array {
		if ( $this->has_succeeded_intent( $order ) ) {
			return $this->keep_succeeded_intent_order( $order, $message, $code );
		}

		$this->get_logger()->error(
			'Error occurred during the payment process. Exception: ' . $message,
			array(
				'order_id'  => $order->get_id(),
				'exception' => $code,
			)
		);

		if ( $fail_order ) {
			$order->update_status( OrderStatus::FAILED, $status_note );
		}

		if ( '' !== $note ) {
			$order->add_order_note( $note );
		}

		wc_add_notice( $message, 'error', array( 'icon' => 'error' ) );

		return array(
			'result'         => 'failure',
			'redirect'       => '',
			'payment_method' => '',
		);
	}

	/**
	 * Read the submitted provider payment method ID.
	 *
	 * @return string
	 */
	private function get_request_payment_method_id(): string {
		foreach ( array( 'wcpay-confirmation-token', 'wcpay-payment-method', 'wcpay-payment-method-sepa' ) as $key ) {
			if ( empty( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				continue;
			}

			return $this->sanitize_post_string( $key );
		}

		return '';
	}

	/**
	 * Get generic checkout payment data.
	 *
	 * @param bool $is_subscription_change Whether this is a validated new-method subscription change.
	 * @return array<string,mixed>
	 */
	private function get_checkout_payment_data( bool $is_subscription_change = false ): array {
		$token_key = 'wc-' . $this->id . '-payment-token';

		return array(
			'payment_token'       => $this->sanitize_post_string( $token_key ),
			'save_payment_method' => $is_subscription_change || ! empty( $_POST[ 'wc-' . $this->id . '-new-payment-method' ] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	/**
	 * Get WooPayments-scoped checkout provider data.
	 *
	 * @param bool $is_subscription_change                Whether this is a validated new-method subscription change.
	 * @param bool $is_subscription_payment_method_change Whether this is a validated subscription payment-method change.
	 * @return array<string,mixed>
	 */
	private function get_checkout_provider_data( bool $is_subscription_change = false, bool $is_subscription_payment_method_change = false ): array {
		$cvc_key = 'wc-' . $this->id . '-payment-cvc-confirmation';

		$save_user_in_woopay = $this->get_woopay_session_service()->should_save_user_in_woopay();
		if ( $save_user_in_woopay ) {
			/**
			 * Fires when the customer opts to save their account with WooPay.
			 *
			 * This intentionally keeps the standalone WooPayments plugin's action name for parity.
			 * Do not rename it; see the class doc block for the rationale.
			 *
			 * @since 11.0.0
			 */
			do_action( 'woocommerce_payments_save_user_in_woopay' );
		}

		$provider_data = array_merge(
			WooPaymentsPlatformPaymentMethodContext::provider_data_from_checkout_value( $this->sanitize_post_string( WooPaymentsPlatformPaymentMethodContext::CHECKOUT_FIELD ) ),
			WooPaymentsPlatformPaymentMethodContext::provider_data_from_save_user_value( $save_user_in_woopay ),
			WooPaymentsExpressPaymentMethodTypes::provider_data_from_checkout_value( $this->sanitize_post_string( WooPaymentsExpressPaymentMethodTypes::CHECKOUT_FIELD ) ),
			WooPaymentsExpressPaymentMethodTypes::provider_context_from_checkout_value( $this->sanitize_post_string( WooPaymentsExpressPaymentMethodTypes::CONTEXT_FIELD ) ),
			array(
				'cvc_confirmation'          => $this->sanitize_post_string( $cvc_key ),
				'fingerprint'               => $this->sanitize_post_string( 'wcpay-fingerprint' ),
				'payment_method_error'      => $this->sanitize_post_string( 'wcpay-payment-method-error-message' ),
				'payment_method_error_code' => $this->sanitize_post_string( 'wcpay-payment-method-error-code' ),
				'is_woopay'                 => ! empty( $_POST['is_woopay'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				WooPaymentsIntentRequestBuilder::PROVIDER_DATA_WOOPAY_INTENT_ID => $this->get_woopay_intent_id(),
			)
		);

		if ( $is_subscription_change ) {
			$provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT ] = true;
		}

		if ( $is_subscription_payment_method_change ) {
			$provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_SUBSCRIPTION_PAYMENT_METHOD_CHANGE ] = true;
		}

		return $provider_data;
	}

	/**
	 * Read the intent WooPay already confirmed for this order from its merchant checkout request.
	 *
	 * Uses the plugin's WooPay_Utilities::sanitize_intent_id() rule: keep word characters only.
	 *
	 * @return string
	 */
	private function get_woopay_intent_id(): string {
		$intent_id = wp_unslash( $_POST['platform-checkout-intent'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_string( $intent_id ) ? (string) preg_replace( '/[^\w_]+/', '', $intent_id ) : '';
	}

	/**
	 * Safely read a string from the POST payload.
	 *
	 * @param string $key POST key.
	 * @return string
	 */
	private function sanitize_post_string( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return '';
		}

		$value = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		return is_string( $value ) ? $value : '';
	}
}
