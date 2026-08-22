<?php
/**
 * NativeWooPaymentsGateway class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\PaymentGatewayFeature;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedAuthenticationRetryEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsFailedRenewalAuthenticationEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionAdminPaymentMethodHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Throwable;
use WC_Order;
use WC_Payment_Token;
use WC_Payment_Gateway_CC;
use WP_Error;

/**
 * Native WooPayments payment gateway shell.
 *
 * Hook-name parity: a handful of the filters/actions this gateway fires intentionally keep the
 * standalone WooPayments **plugin's** hook names (e.g. the `wcpay_` prefix or the plugin's
 * double-prefixed `woocommerce_woocommerce_payments_*` action names) instead of the
 * `woocommerce_native_*` convention used elsewhere in the native runtime. This is deliberate, not
 * an oversight: extensions in the ecosystem hook those plugin-named hooks, and reusing the exact
 * names preserves their behavior once a site switches from the plugin to the native runtime.
 * Do NOT "normalize" these names to the native prefix — renaming them silently breaks extension
 * compatibility. Each such hook is annotated at its call site; native-only hooks use the
 * `woocommerce_native_*` prefix.
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
	private const RECOMMENDED_PAYMENT_METHODS_CACHE_KEY = 'woocommerce_woocommerce_payments_recommended_payment_methods';

	/**
	 * Recommended payment methods cache TTL.
	 */
	private const RECOMMENDED_PAYMENT_METHODS_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Whether the base gateway attached the shared subscription integration hooks for this request.
	 *
	 * @var bool
	 */
	private static bool $has_attached_subscription_handlers = false;

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
		$this->init_supported_features();

		if ( $this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT ) ) {
			$this->has_custom_place_order_button = true;
			$this->has_fields                    = false;
		}

		if ( did_action( 'init' ) ) {
			$this->handle_init();
		} else {
			add_action( 'init', array( $this, 'handle_init' ) );
		}
	}

	/**
	 * Handle the init hook.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$this->title              = $this->get_translated_payment_method_title();
		$this->method_title       = $this->get_translated_method_title();
		$this->method_description = __( 'Accept payments with WooPayments.', 'woocommerce' );
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
		if ( ! parent::is_available() || ! $this->payment_method_definition->should_publish_gateway() ) {
			return false;
		}
		if ( 'card' !== $this->get_payment_method_id() && ! $this->get_account_service()->is_gateway_enabled() ) {
			return false;
		}
		if ( ! $this->get_provider()->can_process_payments() || ! $this->is_account_capability_active() ) {
			return false;
		}

		if (
			$this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_EXPRESS_CHECKOUT )
			&& ! is_admin()
			&& ! $this->is_express_checkout_in_payment_methods_enabled()
		) {
			return false;
		}
		if ( $this->needs_https_setup() || ! $this->is_available_for_current_subscription_context() || ! $this->is_bnpl_order_pay_available() ) {
			return false;
		}

		$currency             = strtoupper( $this->get_checkout_currency() );
		$account_country      = $this->get_account_country();
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
			'/payments/transactions/details',
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
	 * Render the native WooPayments payment form.
	 *
	 * @return void
	 */
	public function form() {
		$this->get_checkout_bridge()->render_payment_fields( $this->payment_method_definition );
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
		try {
			$setup_intent_id = $this->sanitize_post_string( 'wcpay-setup-intent' );

			if ( '' === $setup_intent_id ) {
				return $this->add_payment_method_error( __( 'A WooPayments payment method was not provided.', 'woocommerce' ) );
			}

			$user_id = get_current_user_id();
			if ( 0 >= $user_id ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please log in and try again.", 'woocommerce' ) );
			}

			$fraud_prevention_error = $this->get_fraud_prevention_error_message( false );
			if ( '' !== $fraud_prevention_error ) {
				return $this->add_payment_method_error( $fraud_prevention_error );
			}

			$setup_intent = $this->get_api_client()->get_setup_intention( $setup_intent_id );
			$status       = isset( $setup_intent['status'] ) ? (string) $setup_intent['status'] : '';
			if ( 'succeeded' !== $status ) {
				return $this->add_payment_method_error( __( 'Failed to add the provided payment method. Please try again later.', 'woocommerce' ) );
			}

			// Reject SetupIntents owned by a different WooPayments customer to prevent attaching another
			// user's payment method. This is a no-op when either customer ID is unknown (e.g. the server
			// already scopes the intent, or the user has no WooPayments customer yet), so we only reject
			// on a real mismatch between two known IDs.
			$intent_customer = $this->get_setup_intent_customer_id( $setup_intent );
			$user_customer   = (string) $this->get_customer_service()->get_customer_id_by_user_id( $user_id );
			if ( '' !== $intent_customer && '' !== $user_customer && $intent_customer !== $user_customer ) {
				return $this->add_payment_method_error( __( 'Failed to add the provided payment method. Please try again later.', 'woocommerce' ) );
			}

			$payment_method_id = $this->get_setup_intent_payment_method_id( $setup_intent );
			if ( '' === $payment_method_id ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ) );
			}

			$token = $this->get_token_service()->get_or_create_token_for_user( $payment_method_id, $user_id );
			if ( ! $token instanceof WC_Payment_Token ) {
				return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ) );
			}

			return array(
				'result'   => 'success',
				/**
				 * Filters the redirect URL after adding a WooPayments payment method.
				 *
				 * This intentionally uses the standalone WooPayments plugin's `wcpay_` hook name
				 * (not the native `woocommerce_native_*` prefix) for parity: extensions that hooked
				 * the plugin's filter keep working once a site switches to the native runtime.
				 * Do not rename it — see the class doc block for the hook-name parity rationale.
				 *
				 * @since 11.0.0
				 * @param string $url Redirect URL.
				 */
				'redirect' => apply_filters( 'wcpay_get_add_payment_method_redirect_url', wc_get_endpoint_url( 'payment-methods' ) ),
			);
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Error when adding native WooPayments payment method: ' . $exception->getMessage(),
				array( 'source' => 'wcpay-add-payment-method' )
			);

			return $this->add_payment_method_error( __( "We're not able to add this payment method. Please try again later.", 'woocommerce' ) );
		}
	}

	/**
	 * Process a scheduled subscription renewal payment.
	 *
	 * @param float    $amount        Renewal amount.
	 * @param WC_Order $renewal_order Renewal order.
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount, $renewal_order ): void {
		unset( $amount );

		if ( ! $renewal_order instanceof WC_Order ) {
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
		if ( ! $token instanceof WC_Payment_Token ) {
			$renewal_order->add_order_note( __( 'Subscription renewal failed: No saved payment method found.', 'woocommerce' ) );
			$renewal_order->update_status( 'failed' );
			return;
		}

		$provider_data = array( 'scheduled_subscription_payment' => true );
		$mandate       = $this->get_renewal_order_mandate( $renewal_order );
		if ( '' !== $mandate ) {
			$provider_data['renewal_mandate'] = $mandate;
		}

		$customer_id = $this->get_renewal_order_customer_id( $renewal_order );
		if ( '' !== $customer_id ) {
			$renewal_order->update_meta_data( '_stripe_customer_id', $customer_id );
			$renewal_order->save_meta_data();
		}

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

		$this->maybe_handle_subscription_customer_action_required( $renewal_order, $outcome );
	}

	/**
	 * Handle a scheduled renewal that requires customer authentication.
	 *
	 * @param WC_Order       $renewal_order Renewal order.
	 * @param PaymentOutcome $outcome       Provider payment outcome.
	 * @return void
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
			 * (`woocommerce_woocommerce_payments_*`) rather than the native `woocommerce_native_*`
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
			wc_get_logger()->error(
				'Failed to run WooPayments subscription renewal authentication hooks: ' . $exception->getMessage(),
				array( 'source' => 'woopayments-subscriptions' )
			);
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
				wc_price( $renewal_order->get_total(), array( 'currency' => $renewal_order->get_currency() ) ),
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
			wc_get_logger()->error(
				'Error repairing subscription renewal payment token for order #' . $renewal_order->get_id() . ': ' . $exception->getMessage(),
				array( 'source' => 'woopayments-subscriptions' )
			);

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
		/**
		 * Allows forcing WooPayments to use network-wide saved payment methods across a multisite network.
		 *
		 * Kept under the WooPayments extension's filter name for parity. The extension
		 * marks it internal to Automattic; it participates here only so the repair
		 * honors the same opt-out.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether the site should only use network-wide saved payment methods.
		 */
		return (bool) apply_filters( 'wcpay_force_network_saved_cards', false );
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
		if ( $this->is_subscriptions_plugin_active() ) {
			return version_compare( (string) $this->get_subscriptions_plugin_version(), '2.2.0', '>=' );
		}

		return class_exists( 'WC_Subscriptions_Core_Plugin' );
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
	 * Get the active WooCommerce Subscriptions version.
	 *
	 * @return string|null
	 */
	public function get_subscriptions_plugin_version(): ?string {
		if ( ! class_exists( 'WC_Subscriptions' ) || ! isset( \WC_Subscriptions::$version ) ) {
			return null;
		}

		return (string) \WC_Subscriptions::$version;
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
					esc_url( \WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/payment-methods/' . $brand . '.svg' ) ),
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
	 * Tell whether the connected account has this payment method's capability active.
	 *
	 * An empty capability map is the pre-onboarding fallback used by WooPayments: card remains
	 * available, while split methods require an explicit active capability.
	 *
	 * @return bool
	 */
	private function is_account_capability_active(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();
		$capabilities = is_array( $account_data['capabilities'] ?? null ) ? $account_data['capabilities'] : array();

		if ( array() === $capabilities ) {
			return 'card' === $this->get_payment_method_id();
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

		if ( ! empty( $_POST['is-woopay-preflight-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$order->update_status( 'pending' );

			return array(
				'result'   => 'success',
				'redirect' => '',
			);
		}

		$fraud_prevention_error = $this->get_fraud_prevention_error_message( true );
		if ( '' !== $fraud_prevention_error ) {
			wc_add_notice( $fraud_prevention_error, 'error', array( 'icon' => 'error' ) );

			return array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		$failed_transaction_rate_limiter_error = $this->get_failed_transaction_rate_limiter_error_message();
		if ( '' !== $failed_transaction_rate_limiter_error ) {
			wc_add_notice( $failed_transaction_rate_limiter_error, 'error', array( 'icon' => 'error' ) );

			return array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		$duplicate_order_result = $this->get_duplicate_payment_prevention_service()->check_against_session_processing_order( $order, $this );
		if ( is_array( $duplicate_order_result ) ) {
			return $duplicate_order_result;
		}

		$this->get_duplicate_payment_prevention_service()->maybe_update_session_processing_order( (int) $order_id );

		$existing_intent_result = $this->get_duplicate_payment_prevention_service()->check_payment_intent_attached_to_order_succeeded( $order, $this );
		if ( is_wp_error( $existing_intent_result ) ) {
			wc_add_notice( $existing_intent_result->get_error_message(), 'error', array( 'icon' => 'error' ) );

			return array(
				'result'         => 'failure',
				'redirect'       => '',
				'payment_method' => '',
			);
		}

		if ( is_array( $existing_intent_result ) ) {
			return $existing_intent_result;
		}

		$is_subscription_change = $this->is_subscription_change_payment_request( $order );

		// Runs after the intent check so that a reachable intent still produces the richer
		// response, including the amount-mismatch message. This catches the same-order
		// resubmission when that check returns empty-handed.
		$already_paid_result = $this->get_duplicate_payment_prevention_service()->check_order_already_paid( $order, $this, $is_subscription_change );
		if ( is_array( $already_paid_result ) ) {
			return $already_paid_result;
		}

		$client_error_result = $this->maybe_fail_for_client_payment_method_error( $order, $is_subscription_change );
		if ( is_array( $client_error_result ) ) {
			return $client_error_result;
		}

		$context = PaymentContext::for_checkout(
			$order,
			$this->id,
			$this->get_request_payment_method_id(),
			$this->get_checkout_payment_data( $is_subscription_change ),
			$this->get_checkout_provider_data( $is_subscription_change )
		);
		$outcome = $this->get_processing_service()->process_checkout_outcome( $context, $this->get_provider() );
		$this->maybe_bump_failed_transaction_rate_limiter( $outcome );
		self::maybe_add_failed_checkout_notice( $outcome );

		$result = self::format_checkout_result( $context, $order, $outcome );
		if (
			$is_subscription_change
			&& $this->is_terminal_subscription_change_outcome( $outcome )
			&& 'success' === ( $result['result'] ?? '' )
			&& ! $this->is_confirmation_redirect_result( $result )
			&& $order->get_checkout_order_received_url() === ( $result['redirect'] ?? '' )
		) {
			$result['redirect'] = $this->get_return_url( $order );
		}

		$this->maybe_handle_subscription_change_payment_success( $order, $result, $outcome, $is_subscription_change );
		$result = $this->maybe_add_order_pay_save_intent_to_confirmation_redirect( $context, $order, $result );

		return $result;
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

		$refund_amount = null === $amount ? 0.0 : (float) $amount;
		if ( '0.00' !== sprintf( '%0.2f', $refund_amount ) && ! $this->can_refund_order( $order ) ) {
			return new WP_Error( 'native_payment_refund_missing_charge', __( 'This order does not have a WooPayments charge to refund.', 'woocommerce' ) );
		}

		$result = $this->get_processing_service()->process_refund(
			PaymentContext::for_refund( $order, $this->id, $refund_amount, (string) $reason ),
			$this->get_provider()
		);

		// The lock refusal made no platform attempt, so there is no failure to record.
		// This gates by exclusion: every other WP_Error from the processing service today
		// comes from an actual platform refund attempt. A new pre-flight refusal added
		// inside the service must be excluded here too, or it will start recording
		// failures for refunds that never reached the provider.
		if ( is_wp_error( $result ) && 'native_payment_refund_locked' !== $result->get_error_code() ) {
			$this->record_refund_failure( $order, $refund_amount, $result );
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

		if ( 'insufficient_balance_for_refund' === $error->get_error_code() ) {
			// The dedicated note carries the funding guidance; the generic failure
			// line (and its log) is deliberately skipped, matching the extension.
			$note = $note_service->format_insufficient_balance_refund_note( $order, $amount, $currency, $this->get_account_service()->get_account_country() );
		} else {
			$note = $note_service->format_refund_failure_note( $order, $amount, $currency, $error->get_error_message() );

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

		return sprintf( 'WooPayments (%s)', $this->payment_method_definition->get_title() );
	}

	/**
	 * Get the translated shopper-facing payment method title.
	 *
	 * @return string
	 */
	private function get_translated_payment_method_title(): string {
		if ( 'card' === $this->get_payment_method_id() ) {
			return __( 'Card', 'woocommerce' );
		}

		return $this->payment_method_definition->get_title();
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
			 * (not the native `woocommerce_native_*` prefix) for parity: WooPay Store API flows
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

		$fraud_prevention_service->regenerate_token();

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

		\WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $order, $this->id );

		remove_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', array( WooPaymentsSubscriptionAdminPaymentMethodHandler::instance(), 'update_payment_method_for_subscriptions' ), 10 );
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
		if ( ! isset( $_POST['_wcsnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ), 'wcs_change_payment_method' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}

		$token_key = 'wc-' . $this->id . '-payment-token';
		if ( isset( $_POST[ $token_key ] ) && 'new' !== sanitize_text_field( wp_unslash( $_POST[ $token_key ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
	 * Tell whether the current cart contains a subscription.
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
	 * @return void
	 */
	private function init_supported_features(): void {
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

			$this->supports = array_merge(
				$this->supports,
				array( 'subscription_amount_changes', 'subscription_date_changes' )
			);
		}

		if ( $this->is_saved_cards_enabled() && $this->payment_method_supports( self::PAYMENT_METHOD_CAPABILITY_TOKENIZATION ) ) {
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
		if ( ! $this->owns_subscription_renewal_hooks() || self::$has_attached_subscription_handlers ) {
			return;
		}
		self::$has_attached_subscription_handlers = true;

		if ( false === has_filter( 'woocommerce_email_classes', array( self::class, 'add_subscription_emails' ) ) ) {
			add_filter( 'woocommerce_email_classes', array( self::class, 'add_subscription_emails' ), 20 );
		}

		if ( false === has_action( 'woocommerce_checkout_subscription_created', array( $this, 'maybe_force_subscription_to_manual' ) ) ) {
			add_action( 'woocommerce_checkout_subscription_created', array( $this, 'maybe_force_subscription_to_manual' ), 10, 1 );
		}

		foreach ( WooPaymentsSubscriptionMethodPolicy::get_reusable_gateway_ids() as $gateway_id ) {
			$scheduled_hook = 'woocommerce_scheduled_subscription_payment_' . $gateway_id;
			$failing_hook   = 'woocommerce_subscription_failing_payment_method_updated_' . $gateway_id;

			if ( false === has_action( $scheduled_hook, array( $this, 'scheduled_subscription_payment' ) ) ) {
				add_action( $scheduled_hook, array( $this, 'scheduled_subscription_payment' ), 10, 2 );
			}

			if ( false === has_action( $failing_hook, array( $this, 'update_failing_payment_method' ) ) ) {
				add_action( $failing_hook, array( $this, 'update_failing_payment_method' ), 10, 2 );
			}
		}

		WooPaymentsSubscriptionAdminPaymentMethodHandler::instance()->register_hooks();
	}

	/**
	 * Tell whether this gateway owns automatic subscription renewal handling.
	 *
	 * Link credentials remain attached to the base card gateway, and Amazon Pay renewals use a
	 * preserved compatibility gateway ID handled by the same base gateway instance.
	 *
	 * @return bool
	 */
	private function owns_subscription_renewal_hooks(): bool {
		return OrderPaymentStore::GATEWAY_ID === $this->id && $this->is_subscriptions_enabled();
	}

	/**
	 * Add WooPayments subscription emails to WooCommerce.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $email_classes WooCommerce email classes.
	 * @return array<string,mixed>
	 */
	public static function add_subscription_emails( array $email_classes ): array {
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

		// On a subscription payment-method change $order is the subscription
		// itself: 'failed' is not a valid subscription transition, and the
		// existing method stays in place, so only the notice is surfaced.
		if ( ! $is_subscription_change ) {
			$order->update_status( 'failed', $message );
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
	 * @param bool $is_subscription_change Whether this is a validated new-method subscription change.
	 * @return array<string,mixed>
	 */
	private function get_checkout_provider_data( bool $is_subscription_change = false ): array {
		$cvc_key = 'wc-' . $this->id . '-payment-cvc-confirmation';

		$save_user_in_woopay = $this->get_woopay_session_service()->should_save_user_in_woopay();
		if ( $save_user_in_woopay ) {
			/**
			 * Fires when the customer opts to save their account with WooPay.
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
			)
		);

		if ( $is_subscription_change ) {
			$provider_data[ WooPaymentsIntentRequestBuilder::PROVIDER_DATA_RECURRING_PAYMENT ] = true;
		}

		return $provider_data;
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
