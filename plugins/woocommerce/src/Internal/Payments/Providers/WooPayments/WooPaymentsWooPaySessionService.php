<?php
/**
 * WooPaymentsWooPaySessionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Connection\Client as Jetpack_Connection_Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayAdaptedExtensions;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayBlocksDataExtractor;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Native WooPay session helpers.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPaySessionService {

	private const WOOPAY_SESSION_KEY = 'woopay-user-data';

	private const WOOPAY_DEFAULT_URL = 'https://pay.woo.com';

	private const WOOPAY_REST_NAMESPACE = 'wp-json/platform-checkout/v1';

	private const APPEARANCE_OPTION = 'wcpay_woopay_checkout_appearance';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

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
	 * WooPay blocks data extractor.
	 *
	 * @var WooPaymentsWooPayBlocksDataExtractor|null
	 */
	private ?WooPaymentsWooPayBlocksDataExtractor $blocks_data_extractor = null;

	/**
	 * WooPay adapted extensions registry.
	 *
	 * @var WooPaymentsWooPayAdaptedExtensions|null
	 */
	private ?WooPaymentsWooPayAdaptedExtensions $adapted_extensions = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService                 $account_service              WooPayments account service.
	 * @param WooPaymentsFrontendStylesService          $frontend_styles_service      Shared frontend styles service.
	 * @param WooPaymentsFrontendTrackingController     $frontend_tracking_controller Frontend tracking controller.
	 * @param WooPaymentsWooPayBlocksDataExtractor|null $blocks_data_extractor        WooPay blocks data extractor.
	 * @param WooPaymentsWooPayAdaptedExtensions|null   $adapted_extensions           WooPay adapted extensions registry.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsFrontendStylesService $frontend_styles_service, WooPaymentsFrontendTrackingController $frontend_tracking_controller, ?WooPaymentsWooPayBlocksDataExtractor $blocks_data_extractor = null, ?WooPaymentsWooPayAdaptedExtensions $adapted_extensions = null ): void {
		$this->account_service              = $account_service;
		$this->frontend_styles_service      = $frontend_styles_service;
		$this->frontend_tracking_controller = $frontend_tracking_controller;
		$this->blocks_data_extractor        = $blocks_data_extractor;
		$this->adapted_extensions           = $adapted_extensions;
	}

	/**
	 * Tell whether WooPay is enabled in WooPayments gateway settings.
	 *
	 * @return bool
	 */
	public function is_woopay_enabled(): bool {
		return 'yes' === $this->get_account_service()->get_gateway_setting( 'platform_checkout', 'no' ) &&
			$this->is_woopay_account_eligible();
	}

	/**
	 * Get the WooPay host URL.
	 *
	 * @return string
	 */
	public function get_woopay_url(): string {
		$url = defined( 'PLATFORM_CHECKOUT_HOST' ) && is_string( PLATFORM_CHECKOUT_HOST )
			? PLATFORM_CHECKOUT_HOST
			: self::WOOPAY_DEFAULT_URL;

		return untrailingslashit( $url );
	}

	/**
	 * Get a WooPay platform checkout REST URL.
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	public function get_woopay_rest_url( string $endpoint ): string {
		return $this->get_woopay_url() . '/' . self::WOOPAY_REST_NAMESPACE . '/' . ltrim( $endpoint, '/' );
	}

	/**
	 * Generate the WooPay request signature from the blog token.
	 *
	 * @return string
	 */
	public function get_woopay_request_signature(): string {
		$blog_id    = $this->get_store_blog_id();
		$blog_token = $this->get_store_blog_token();

		if ( '' === $blog_id || '' === $blog_token ) {
			return '';
		}

		return hash_hmac( 'sha512', $blog_id . floor( time() / 30 ), $blog_token );
	}

	/**
	 * Get the connected WooPay merchant ID.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_woopay_merchant_id(): string {
		return $this->get_store_blog_id();
	}

	/**
	 * Get unencrypted minimum WooPay session data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_minimum_session_data(): array {
		return array(
			'wcpay_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'blog_id'           => $this->get_store_blog_id(),
			'blog_rest_url'     => get_rest_url(),
			'blog_checkout_url' => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
			'session_nonce'     => $this->create_woopay_nonce( get_current_user_id() ),
			'store_api_token'   => $this->get_store_api_token(),
		);
	}

	/**
	 * Get encrypted minimum WooPay session data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_encrypted_minimum_session_data(): array {
		return $this->encrypt_and_sign_data( $this->get_minimum_session_data() );
	}

	/**
	 * Get encrypted full WooPay session data.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_encrypted_session_data( array $request ): array {
		return $this->encrypt_and_sign_data(
			$this->get_init_session_request(
				$this->get_request_string( $request, 'email' ),
				$this->get_request_string( $request, 'user_session' ),
				null,
				$this->get_request_int( $request, 'order_id' ),
				$this->get_request_string( $request, 'key' ),
				$this->get_request_string( $request, 'billing_email' ),
				$this->get_request_array( $request, 'appearance' ),
				$this->get_font_rules_from_request( $request )
			)
		);
	}

	/**
	 * Get session data for the WooPay REST callback.
	 *
	 * @param string|null          $email           Shopper email.
	 * @param WP_REST_Request|null $woopay_request  WooPay REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	public function get_session_data( ?string $email = null, ?WP_REST_Request $woopay_request = null ): array {
		return $this->get_init_session_request( $email, null, $woopay_request );
	}

	/**
	 * Build the WooPay init-session request body.
	 *
	 * @param string|null                     $email           Shopper email.
	 * @param string|null                     $user_session    WooPay user session.
	 * @param WP_REST_Request|null            $woopay_request  WooPay REST request.
	 * @param int|null                        $order_id        Pay-for-order order ID.
	 * @param string|null                     $key             Pay-for-order key.
	 * @param string|null                     $billing_email   Pay-for-order billing email.
	 * @param array<string,mixed>|null        $appearance      WooPay appearance payload.
	 * @param array<int,array<string,string>> $font_rules      WooPay font rules.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	public function get_init_session_request(
		?string $email = null,
		?string $user_session = null,
		?WP_REST_Request $woopay_request = null,
		?int $order_id = null,
		?string $key = null,
		?string $billing_email = null,
		?array $appearance = null,
		array $font_rules = array()
	): array {
		$is_pay_for_order = null !== $order_id;
		$email            = $email ?? '';

		$request = array(
			'wcpay_version'        => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'user_id'              => get_current_user_id(),
			'customer_id'          => 0,
			'session_nonce'        => $this->create_woopay_nonce( get_current_user_id() ),
			'store_api_token'      => $this->get_store_api_token(),
			'email'                => $email,
			'store_data'           => $this->get_store_data( $order_id ),
			'user_session'         => $user_session,
			'preloaded_requests'   => $is_pay_for_order
				? array(
					'cart'     => $this->get_cart_data( true, $order_id, $key, $billing_email, $woopay_request ),
					'checkout' => array(
						'order_id' => $order_id,
					),
				)
				: array(
					'cart'     => $this->get_cart_data( false, null, null, null, $woopay_request ),
					'checkout' => $this->get_checkout_data( $woopay_request ),
				),
			'tracks_user_identity' => null,
			'appearance'           => null === $appearance ? $this->get_woopay_appearance() : $this->sanitize_array_recursive( $appearance ),
			'font_rules'           => array() === $font_rules ? $this->get_woopay_font_rules() : $this->sanitize_woopay_font_rules( $font_rules ),
		);

		$adapted_extensions        = $this->get_adapted_extensions();
		$request['extension_data'] = $adapted_extensions->get_extension_data();
		if ( '' === $email ) {
			return $request;
		}

		$customer = WC()->customer;
		if ( is_object( $customer ) && is_callable( array( $customer, 'set_billing_email' ) ) && is_callable( array( $customer, 'save' ) ) ) {
			$customer->set_billing_email( $email );
			$customer->save();
		}

		$adapted_extensions->register_integrations();
		$request['adapted_extensions'] = $adapted_extensions->get_adapted_extensions_data( $email );
		if ( ! is_user_logged_in() && array() !== $request['adapted_extensions'] ) {
			$registered_user = get_user_by( 'email', $email );
			if ( $registered_user instanceof \WP_User ) {
				$request['email_verified_session_nonce'] = $this->create_woopay_nonce( $registered_user->ID );
			}
		}

		return $request;
	}

	/**
	 * Encrypt and sign WooPay session data.
	 *
	 * @param array<string,mixed> $data Session data.
	 * @return array<string,mixed>
	 */
	public function encrypt_and_sign_data( array $data ): array {
		$blog_id    = $this->get_store_blog_id();
		$blog_token = $this->get_store_blog_token();

		if ( '' === $blog_id || '' === $blog_token || ! function_exists( 'openssl_encrypt' ) ) {
			return array();
		}

		$message = wp_json_encode( $data );
		if ( ! is_string( $message ) ) {
			return array();
		}

		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv_length ) {
			return array();
		}

		$iv = openssl_random_pseudo_bytes( $iv_length );
		if ( false === $iv ) {
			return array();
		}

		$session_encrypted = openssl_encrypt( $message, 'aes-256-cbc', $blog_token, OPENSSL_RAW_DATA, $iv );
		if ( false === $session_encrypted ) {
			return array();
		}

		return array(
			'blog_id' => $blog_id,
			'data'    => array_map(
				'base64_encode',
				array(
					'session' => $session_encrypted,
					'iv'      => $iv,
					'hash'    => hash_hmac( 'sha256', $session_encrypted, $blog_token ),
				)
			),
		);
	}

	/**
	 * Initialize a WooPay session through the WooPay REST endpoint.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function init_woopay_session( array $request ): array {
		$body = wp_json_encode(
			$this->get_init_session_request(
				$this->get_request_string( $request, 'email' ),
				$this->get_request_string( $request, 'user_session' ),
				null,
				$this->get_request_int( $request, 'order_id' ),
				$this->get_request_string( $request, 'key' ),
				$this->get_request_string( $request, 'billing_email' ),
				$this->get_request_array( $request, 'appearance' ),
				$this->get_font_rules_from_request( $request )
			)
		);

		if ( ! is_string( $body ) ) {
			return array( 'result' => 'failure' );
		}

		$response = Jetpack_Connection_Client::remote_request(
			array(
				'url'     => $this->get_woopay_rest_url( 'init' ),
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
			),
			$body
		);

		if ( $response instanceof WP_Error || ! is_array( $response ) ) {
			return array( 'result' => 'failure' );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : array( 'result' => 'failure' );
	}

	/**
	 * Persist WooPay phone/session data in the WooCommerce session.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	public function set_woopay_phone_session_data( array $request ): void {
		$session = $this->get_wc_session();
		if ( null === $session ) {
			return;
		}

		if ( ! empty( $request['empty'] ) ) {
			$this->clear_woopay_session_data();
			return;
		}

		if ( method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}

		$phone_field = is_array( $request['woopay_user_phone_field'] ?? null ) ? $request['woopay_user_phone_field'] : array();
		$phone_full  = is_scalar( $phone_field['full'] ?? null ) ? $phone_field['full'] : ( $request['phone_number'] ?? '' );

		$session->set(
			self::WOOPAY_SESSION_KEY,
			array(
				'save_user_in_woopay'     => filter_var( $request['save_user_in_woopay'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'woopay_source_url'       => esc_url_raw( (string) ( $request['woopay_source_url'] ?? '' ) ),
				'woopay_is_blocks'        => filter_var( $request['woopay_is_blocks'] ?? false, FILTER_VALIDATE_BOOLEAN ),
				'woopay_viewport'         => sanitize_text_field( (string) ( $request['woopay_viewport'] ?? '' ) ),
				'woopay_user_phone_field' => array(
					'full' => sanitize_text_field( (string) $phone_full ),
				),
			)
		);
	}

	/**
	 * Clear WooPay session data.
	 */
	public function clear_woopay_session_data(): void {
		$session = $this->get_wc_session();
		if ( null !== $session ) {
			$session->set( self::WOOPAY_SESSION_KEY, null );
		}
	}

	/**
	 * Get WooPay session data from the WooCommerce session.
	 *
	 * @return mixed
	 */
	public function get_woopay_session_data() {
		$session = $this->get_wc_session();

		return null === $session ? null : $session->get( self::WOOPAY_SESSION_KEY );
	}

	/**
	 * Get WooPay frontend config used by classic and block checkout surfaces.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,mixed>
	 */
	public function get_woopay_frontend_config( string $context = 'checkout' ): array {
		$is_woopay_enabled                 = $this->is_woopay_enabled();
		$is_country_available              = $this->is_woopay_country_available();
		$is_global_theme_enabled           = $this->is_woopay_global_theme_support_enabled();
		$should_show_woopay                = $this->is_woopay_gateway_available() && $this->should_show_woopay_button_for_enabled_state( $context, $is_woopay_enabled );
		$woopay_appearance                 = $is_global_theme_enabled ? $this->get_woopay_appearance() : null;
		$woopay_font_rules                 = $is_global_theme_enabled ? $this->get_woopay_font_rules() : array();
		$woopay_session_email              = $this->get_current_shopper_email();
		$woopay_minimum_session            = $is_woopay_enabled ? $this->get_encrypted_minimum_session_data() : array();
		$woopay_express_available          = $is_woopay_enabled && $this->is_woopay_express_checkout_configured_at( $context );
		$woopay_first_party_auth_available = $woopay_express_available && $is_country_available;

		return array(
			'isWooPayEnabled'                   => $is_woopay_enabled,
			'isWoopayExpressCheckoutEnabled'    => $woopay_express_available,
			'isWoopayFirstPartyAuthEnabled'     => $woopay_first_party_auth_available,
			'isWooPayEmailInputEnabled'         => $is_woopay_enabled,
			'isWooPayDirectCheckoutEnabled'     => $this->is_truthy_gateway_setting( 'is_woopay_direct_checkout_enabled' ),
			'isWooPayGlobalThemeSupportEnabled' => $is_global_theme_enabled,
			'forceNetworkSavedCards'            => $this->is_truthy_gateway_setting( 'force_network_saved_cards' ) || $this->should_use_stripe_platform_on_checkout_page( $context ),
			'ajaxUrl'                           => admin_url( 'admin-ajax.php' ),
			'platformTrackerNonce'              => wp_create_nonce( 'platform_tracks_nonce' ),
			'isShopperTrackingEnabled'          => $this->get_frontend_tracking_controller()->is_shopper_tracking_enabled(),
			'is_shopper_tracking_enabled'       => $this->get_frontend_tracking_controller()->is_shopper_tracking_enabled(),
			'woopayHost'                        => $this->get_woopay_url(),
			'wcpayVersionNumber'                => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'woopayMerchantId'                  => $this->get_woopay_merchant_id(),
			'initWooPayNonce'                   => wp_create_nonce( 'wcpay_init_woopay_nonce' ),
			'woopaySessionNonce'                => wp_create_nonce( 'woopay_session_nonce' ),
			'woopaySignatureNonce'              => wp_create_nonce( 'woopay_signature_nonce' ),
			'woopayMinimumSessionData'          => $woopay_minimum_session,
			'woopayButton'                      => $this->get_woopay_button_settings( $context ),
			'woopayButtonNonce'                 => wp_create_nonce( 'woopay_button_nonce' ),
			'addToCartNonce'                    => wp_create_nonce( 'wcpay-add-to-cart' ),
			'shouldShowWooPayButton'            => $should_show_woopay,
			'woopaySessionEmail'                => $woopay_session_email,
			'woopayIsCountryAvailable'          => $is_country_available,
			'woopayAppearance'                  => $woopay_appearance,
			'woopayFontRules'                   => $woopay_font_rules,
			'woopayButtonLabels'                => array(
				'default' => __( 'WooPay', 'woocommerce' ),
				'buy'     => sprintf(
					/* translators: %s: WooPay. */
					__( 'Buy with %s', 'woocommerce' ),
					'WooPay'
				),
				'donate'  => sprintf(
					/* translators: %s: WooPay. */
					__( 'Donate with %s', 'woocommerce' ),
					'WooPay'
				),
				'book'    => sprintf(
					/* translators: %s: WooPay. */
					__( 'Book with %s', 'woocommerce' ),
					'WooPay'
				),
			),
			'woopaySaveUserLabel'               => __( 'Securely save my information for 1-click checkout', 'woocommerce' ),
			'woopayPhoneLabel'                  => __( 'Mobile phone number', 'woocommerce' ),
		);
	}

	/**
	 * Get express checkout params for WooPay.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,mixed>
	 */
	public function get_express_checkout_params( string $context = 'checkout' ): array {
		$currency = strtolower( get_woocommerce_currency() );
		$country  = $this->get_store_base_country();

		return array(
			'nonce'              => array(
				'payment_request'  => wp_create_nonce( 'wcpay-payment-request' ),
				'shipping'         => wp_create_nonce( 'wcpay-shipping' ),
				'update_shipping'  => wp_create_nonce( 'wcpay-update-shipping' ),
				'checkout'         => wp_create_nonce( 'wcpay-checkout' ),
				'platform_tracker' => wp_create_nonce( 'platform_tracks_nonce' ),
			),
			'checkout'           => array(
				'currency_code'              => $currency,
				'currency_decimals'          => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2,
				'stripe_minor_unit'          => WooPaymentsCurrencyUtils::get_stripe_minor_unit_for_currency( $currency ),
				'country_code'               => $country,
				'needs_shipping'             => function_exists( 'WC' ) && WC() && WC()->cart ? WC()->cart->needs_shipping() : false,
				'needs_payer_phone'          => 'required' === get_option( 'woocommerce_checkout_phone_field', 'required' ),
				'allowed_shipping_countries' => function_exists( 'WC' ) && WC() && WC()->countries ? array_keys( WC()->countries->get_shipping_countries() ?? array() ) : array(),
				'display_prices_with_tax'    => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
			),
			'has_subscription'   => class_exists( '\WC_Subscriptions_Cart' ) && is_callable( array( '\WC_Subscriptions_Cart', 'cart_contains_subscription' ) ) && \WC_Subscriptions_Cart::cart_contains_subscription(),
			'is_manual_capture'  => $this->is_truthy_gateway_setting( 'manual_capture' ),
			'button'             => $this->get_woopay_button_settings( $context ),
			'login_confirmation' => false,
			'button_context'     => $this->normalize_button_context( $context ),
			'has_block'          => has_block( 'woocommerce/cart' ) || has_block( 'woocommerce/checkout' ),
			'product'            => array(),
			'store_name'         => get_bloginfo( 'name' ),
			'enabled_methods'    => $this->is_woopay_express_checkout_enabled_at( $context ) ? array( 'woopay' ) : array(),
			'stripe'             => array(
				'publishableKey' => $this->get_account_service()->get_publishable_key(),
				'accountId'      => $this->get_account_service()->get_account_id(),
				'locale'         => $this->get_stripe_locale(),
			),
			'flags'              => array(
				'isEceUsingConfirmationTokens' => false,
			),
		);
	}

	/**
	 * Get WooPay save-user checkout data.
	 *
	 * @return array<string,bool>
	 */
	public function get_save_user_checkout_data(): array {
		$account_data = $this->get_account_service()->get_cached_account_data();

		return array(
			'PRE_CHECK_SAVE_MY_INFO' => ! empty( $account_data['pre_check_save_my_info'] ),
		);
	}

	/**
	 * Tell whether the WooPay button should be shown in the current context.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_show_woopay_button( string $context = 'checkout' ): bool {
		if ( ! $this->is_woopay_gateway_available() ) {
			return false;
		}

		return $this->should_show_woopay_button_for_enabled_state( $context, $this->is_woopay_enabled() );
	}

	/**
	 * Tell whether the WooPay button should show for a known global enabled state.
	 *
	 * @param string $context            Express checkout context.
	 * @param bool   $is_woopay_enabled Whether WooPay is globally enabled.
	 * @return bool
	 */
	private function should_show_woopay_button_for_enabled_state( string $context, bool $is_woopay_enabled ): bool {
		/**
		 * Allows third parties to programmatically show or hide the WooPay button.
		 *
		 * @since 9.5.0
		 *
		 * @param bool $is_woopay_enabled Whether WooPay is globally enabled.
		 */
		if ( ! apply_filters( 'wcpay_woopay_enabled', $is_woopay_enabled ) ) {
			return false;
		}

		$context = sanitize_key( $context );
		if ( ! in_array( $context, array( 'product', 'cart', 'checkout' ), true ) ) {
			return false;
		}

		if (
			! $this->is_woopay_country_available() ||
			! $this->is_woopay_express_checkout_configured_at( $context )
		) {
			return false;
		}

		$product = null;
		if ( 'product' === $context ) {
			$product = $this->get_current_woopay_product();
			if (
				! $this->is_woopay_product_supported( $product ) ||
				null === $product ||
				! $product->is_purchasable() ||
				! $product->is_in_stock()
			) {
				return false;
			}
		} elseif ( ! $this->has_allowed_woopay_cart_items() ) {
			return false;
		}

		if ( ! is_user_logged_in() ) {
			if ( $product instanceof \WC_Product && $this->is_woopay_subscription_product( $product ) ) {
				return false;
			}

			if ( 'product' !== $context && $this->woopay_cart_contains_subscription() ) {
				return false;
			}

			if ( ! $this->is_woopay_guest_checkout_enabled() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tell whether WooPay save-user assets should load for the current context.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_load_woopay_save_user_assets( string $context = 'checkout' ): bool {
		return 'checkout' === $this->normalize_button_context( $context ) &&
			$this->is_woopay_enabled() &&
			$this->is_woopay_country_available() &&
			( $this->is_truthy_gateway_setting( 'force_network_saved_cards' ) || $this->should_use_stripe_platform_on_checkout_page( $context ) );
	}

	/**
	 * Add WooPay save-user session data to order metadata.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @param \WC_Order           $order    Order object.
	 * @return array<string,mixed>
	 */
	public function maybe_add_woopay_user_metadata( array $metadata, \WC_Order $order ): array {
		$should_save_woopay_user = $this->get_woopay_save_user_flag();
		$woopay_phone            = $this->get_woopay_phone();

		if ( ! $should_save_woopay_user || '' === $woopay_phone ) {
			return $metadata;
		}

		$metadata['platform_checkout_primary_first_name']   = wc_clean( $order->get_billing_first_name() );
		$metadata['platform_checkout_primary_last_name']    = wc_clean( $order->get_billing_last_name() );
		$metadata['platform_checkout_primary_phone']        = wc_clean( $order->get_billing_phone() );
		$metadata['platform_checkout_primary_company']      = wc_clean( $order->get_billing_company() );
		$metadata['platform_checkout_secondary_first_name'] = wc_clean( $order->get_shipping_first_name() );
		$metadata['platform_checkout_secondary_last_name']  = wc_clean( $order->get_shipping_last_name() );
		$metadata['platform_checkout_secondary_phone']      = wc_clean( $order->get_shipping_phone() );
		$metadata['platform_checkout_secondary_company']    = wc_clean( $order->get_shipping_company() );
		$metadata['platform_checkout_phone']                = $woopay_phone;
		$metadata['platform_checkout_source_url']           = $this->get_woopay_source_url();
		$metadata['platform_checkout_is_blocks']            = $this->get_woopay_is_blocks();
		$metadata['platform_checkout_viewport']             = $this->get_woopay_viewport();

		return $metadata;
	}

	/**
	 * Save WooPay appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 */
	public function save_woopay_appearance( array $appearance, array $font_rules = array() ): void {
		$appearance = $this->sanitize_array_recursive( $appearance );
		if ( ! $this->validate_appearance_schema( $appearance ) ) {
			return;
		}

		update_option(
			self::APPEARANCE_OPTION,
			array(
				'appearance' => $appearance,
				'font_rules' => $this->sanitize_woopay_font_rules( $font_rules ),
				'version'    => $this->get_appearance_version(),
			),
			false
		);
	}

	/**
	 * Get WooPay appearance data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_appearance(): array {
		$stored = get_option( self::APPEARANCE_OPTION, array() );

		if ( isset( $stored['appearance'] ) && is_array( $stored['appearance'] ) ) {
			return $this->has_current_appearance_version( $stored ) ? $stored['appearance'] : array();
		}

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Get WooPay font rules.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function get_woopay_font_rules(): array {
		$stored = get_option( self::APPEARANCE_OPTION, array() );

		return isset( $stored['font_rules'] ) && is_array( $stored['font_rules'] ) && $this->has_current_appearance_version( $stored )
			? $this->sanitize_woopay_font_rules( $stored['font_rules'] )
			: array();
	}

	/**
	 * Conditionally save WooPay shopper appearance data.
	 *
	 * @param array<string,mixed>             $appearance Appearance data.
	 * @param array<int,array<string,string>> $font_rules Font rules.
	 * @return bool
	 */
	public function maybe_save_woopay_appearance( array $appearance, array $font_rules = array() ): bool {
		$appearance = $this->sanitize_array_recursive( $appearance );
		if ( ! $this->validate_appearance_schema( $appearance ) || array() !== $this->get_woopay_appearance() ) {
			return false;
		}

		$this->save_woopay_appearance( $appearance, $font_rules );

		return true;
	}

	/**
	 * Validate WooPay appearance data against the preserved schema.
	 *
	 * @param array<string,mixed> $appearance Appearance data.
	 * @return bool
	 */
	public function validate_appearance_schema( array $appearance ): bool {
		$allowed_top_keys = array( 'variables', 'theme', 'labels', 'rules' );
		foreach ( array_keys( $appearance ) as $key ) {
			if ( ! in_array( $key, $allowed_top_keys, true ) ) {
				return false;
			}
		}

		if ( isset( $appearance['theme'] ) && ! in_array( $appearance['theme'], array( 'stripe', 'night' ), true ) ) {
			return false;
		}

		if ( isset( $appearance['labels'] ) && ! in_array( $appearance['labels'], array( 'floating', 'above' ), true ) ) {
			return false;
		}

		if ( isset( $appearance['variables'] ) ) {
			if ( ! is_array( $appearance['variables'] ) ) {
				return false;
			}

			$allowed_variables = array( 'colorBackground', 'colorText', 'fontFamily', 'fontSizeBase' );
			foreach ( array_keys( $appearance['variables'] ) as $key ) {
				if ( ! in_array( $key, $allowed_variables, true ) ) {
					return false;
				}
			}

			if ( ! $this->validate_string_values( $appearance['variables'] ) ) {
				return false;
			}
		}

		if ( isset( $appearance['rules'] ) ) {
			if ( ! is_array( $appearance['rules'] ) ) {
				return false;
			}

			$allowed_rules      = $this->get_allowed_appearance_rules();
			$allowed_properties = $this->get_allowed_appearance_properties();
			foreach ( $appearance['rules'] as $rule_key => $rule_value ) {
				if ( ! in_array( $rule_key, $allowed_rules, true ) || ! is_array( $rule_value ) ) {
					return false;
				}

				foreach ( array_keys( $rule_value ) as $property ) {
					if ( ! in_array( $property, $allowed_properties, true ) ) {
						return false;
					}
				}

				if ( ! $this->validate_string_values( $rule_value ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize WooPay appearance font rules.
	 *
	 * @param array<int,mixed> $font_rules Font rules.
	 * @return array<int,array<string,string>>
	 */
	public function sanitize_woopay_font_rules( array $font_rules ): array {
		$sanitized = array();
		foreach ( array_slice( $font_rules, 0, 10 ) as $rule ) {
			if ( ! is_array( $rule ) || ! isset( $rule['cssSrc'] ) || ! is_string( $rule['cssSrc'] ) ) {
				continue;
			}

			$url  = esc_url_raw( $rule['cssSrc'], array( 'https' ) );
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && in_array( $host, $this->get_allowed_font_domains(), true ) ) {
				$sanitized[] = array( 'cssSrc' => $url );
			}
		}

		return $sanitized;
	}

	/**
	 * Tell whether the base WooPayments gateway is available for checkout.
	 *
	 * @return bool
	 */
	private function is_woopay_gateway_available(): bool {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return false;
		}

		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		return isset( $available_gateways['woocommerce_payments'] );
	}

	/**
	 * Tell whether WooPay is available for the connected account country.
	 *
	 * @return bool
	 */
	private function is_woopay_country_available(): bool {
		return 'US' === $this->get_account_country();
	}

	/**
	 * Tell whether the connected account is eligible for WooPay.
	 *
	 * @return bool
	 */
	private function is_woopay_account_eligible(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();

		return ! empty( $account_data['platform_checkout_eligible'] )
			&& $this->get_account_service()->has_valid_account_for_admin_navigation()
			&& ! $this->get_account_service()->is_account_rejected()
			&& ! $this->get_account_service()->is_account_under_review();
	}

	/**
	 * Tell whether WooPay express checkout is enabled for a context.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	private function is_woopay_express_checkout_enabled_at( string $context ): bool {
		return $this->is_woopay_enabled() && $this->is_woopay_express_checkout_configured_at( $context );
	}

	/**
	 * Tell whether WooPay express checkout is configured for a context.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	private function is_woopay_express_checkout_configured_at( string $context ): bool {

		$setting_key = 'express_checkout_' . $this->normalize_button_context( $context ) . '_methods';
		$methods     = $this->get_account_service()->get_gateway_setting( $setting_key, array() );

		if ( is_array( $methods ) ) {
			return in_array( 'woopay', $methods, true );
		}

		return 'yes' === $this->get_account_service()->get_gateway_setting( 'platform_checkout', 'no' );
	}

	/**
	 * Get the current product for WooPay product-button eligibility checks.
	 *
	 * @return \WC_Product|null
	 */
	private function get_current_woopay_product(): ?\WC_Product {
		$product = $GLOBALS['product'] ?? null;
		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$product = $this->get_woopay_product_from_shortcode();
		if ( $product instanceof \WC_Product ) {
			return $product;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product() : null;

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Get the product from the current product_page shortcode.
	 *
	 * @return \WC_Product|null
	 */
	private function get_woopay_product_from_shortcode(): ?\WC_Product {
		$post = get_post();
		if ( ! $post instanceof \WP_Post || ! has_shortcode( $post->post_content, 'product_page' ) ) {
			return null;
		}

		if ( ! preg_match_all( '/' . get_shortcode_regex( array( 'product_page' ) ) . '/', $post->post_content, $matches, PREG_SET_ORDER ) ) {
			return null;
		}

		foreach ( $matches as $shortcode ) {
			if ( 'product_page' !== $shortcode[2] ) {
				continue;
			}

			$atts = shortcode_parse_atts( $shortcode[3] );
			if ( ! is_array( $atts ) ) {
				continue;
			}

			$product_id = isset( $atts['id'] ) ? absint( $atts['id'] ) : 0;
			if ( ! $product_id && isset( $atts['sku'] ) && is_scalar( $atts['sku'] ) ) {
				$sku        = wc_clean( wp_unslash( (string) $atts['sku'] ) );
				$sku        = is_scalar( $sku ) ? (string) $sku : '';
				$product_id = '' !== $sku ? wc_get_product_id_by_sku( $sku ) : 0;
			}

			$product = $product_id ? wc_get_product( $product_id ) : null;
			if ( $product instanceof \WC_Product ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Tell whether WooPay supports the current product type.
	 *
	 * @param \WC_Product|null $product Product being checked.
	 * @return bool
	 */
	private function is_woopay_product_supported( ?\WC_Product $product ): bool {
		$is_supported = $product instanceof \WC_Product;

		if ( $product instanceof \WC_Product && $product->is_type( 'external' ) ) {
			$is_supported = false;
		}

		if ( $this->is_woopay_preorder_product_charged_on_release( $product ) ) {
			$is_supported = false;
		}

		if ( $product instanceof \WC_Product && $this->is_woopay_booking_product_requiring_confirmation( $product ) ) {
			$is_supported = false;
		}

		/**
		 * Filters whether the WooPay Express button supports the given product.
		 *
		 * @since 5.9.0
		 *
		 * @param bool             $is_supported Whether the product is supported.
		 * @param \WC_Product|null $product      Product being checked.
		 */
		return (bool) apply_filters( 'wcpay_woopay_button_is_product_supported', $is_supported, $product );
	}

	/**
	 * Tell whether an optional Pre-Orders product is charged upon release.
	 *
	 * @param \WC_Product|null $product Product being checked.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	protected function is_woopay_preorder_product_charged_on_release( ?\WC_Product $product ): bool {
		$is_pre_order_charged_release = array( '\\WC_Pre_Orders_Product', 'product_is_charged_upon_release' );

		return class_exists( '\\WC_Pre_Orders_Product' ) &&
			is_callable( $is_pre_order_charged_release ) &&
			(bool) call_user_func( $is_pre_order_charged_release, $product );
	}

	/**
	 * Tell whether an optional Bookings product requires confirmation.
	 *
	 * @param \WC_Product $product Product being checked.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	protected function is_woopay_booking_product_requiring_confirmation( \WC_Product $product ): bool {
		$requires_confirmation = array( $product, 'get_requires_confirmation' );

		return is_a( $product, 'WC_Product_Booking' ) &&
			is_callable( $requires_confirmation ) &&
			(bool) call_user_func( $requires_confirmation );
	}

	/**
	 * Tell whether WooPay supports all items currently in the cart.
	 *
	 * @return bool
	 */
	private function has_allowed_woopay_cart_items(): bool {
		$is_supported = true;

		if ( $this->is_woopay_cart_preorder_charged_on_release() ) {
			$is_supported = false;
		}

		/**
		 * Filters whether the WooPay Express button supports all current cart items.
		 *
		 * @since 5.7.0
		 *
		 * @param bool $is_supported Whether all cart items are supported.
		 */
		return (bool) apply_filters( 'wcpay_platform_checkout_button_are_cart_items_supported', $is_supported );
	}

	/**
	 * Tell whether the optional Pre-Orders cart contains a product charged upon release.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	protected function is_woopay_cart_preorder_charged_on_release(): bool {
		$cart_contains_pre_order      = array( '\\WC_Pre_Orders_Cart', 'cart_contains_pre_order' );
		$get_pre_order_product        = array( '\\WC_Pre_Orders_Cart', 'get_pre_order_product' );
		$is_pre_order_charged_release = array( '\\WC_Pre_Orders_Product', 'product_is_charged_upon_release' );

		return class_exists( '\\WC_Pre_Orders_Cart' ) &&
			class_exists( '\\WC_Pre_Orders_Product' ) &&
			is_callable( $cart_contains_pre_order ) &&
			is_callable( $get_pre_order_product ) &&
			is_callable( $is_pre_order_charged_release ) &&
			(bool) call_user_func( $cart_contains_pre_order ) &&
			(bool) call_user_func(
				$is_pre_order_charged_release,
				call_user_func( $get_pre_order_product )
			);
	}

	/**
	 * Tell whether a product is a subscription product.
	 *
	 * @param \WC_Product $product Product being checked.
	 * @return bool
	 */
	private function is_woopay_subscription_product( \WC_Product $product ): bool {
		return in_array( $product->get_type(), array( 'subscription', 'subscription_variation', 'variable-subscription' ), true );
	}

	/**
	 * Tell whether the current cart contains a subscription.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	protected function woopay_cart_contains_subscription(): bool {
		$cart_contains_subscription = array( '\\WC_Subscriptions_Cart', 'cart_contains_subscription' );

		return class_exists( '\\WC_Subscriptions_Cart' ) &&
			is_callable( $cart_contains_subscription ) &&
			(bool) call_user_func( $cart_contains_subscription );
	}

	/**
	 * Tell whether guest checkout is enabled for WooPay.
	 *
	 * @return bool
	 */
	private function is_woopay_guest_checkout_enabled(): bool {
		return 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'no' );
	}

	/**
	 * Tell whether WooPay global theme support is enabled.
	 *
	 * @return bool
	 */
	private function is_woopay_global_theme_support_enabled(): bool {
		$account_data = $this->get_account_service()->get_cached_account_data();

		return ! empty( $account_data['platform_global_theme_support_enabled'] ) &&
			$this->is_truthy_gateway_setting( 'is_woopay_global_theme_support_enabled' );
	}

	/**
	 * Tell whether checkout should use the Stripe platform account for WooPay.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	private function should_use_stripe_platform_on_checkout_page( string $context ): bool {
		if (
			'checkout' !== $this->normalize_button_context( $context ) ||
			! $this->is_woopay_enabled() ||
			! $this->is_woopay_country_available() ||
			( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) )
		) {
			return false;
		}

		return function_exists( 'WC' ) &&
			WC() &&
			WC()->cart instanceof \WC_Cart &&
			! WC()->cart->is_empty() &&
			WC()->cart->needs_payment();
	}

	/**
	 * Get WooPay button settings.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,string>
	 */
	private function get_woopay_button_settings( string $context ): array {
		return array(
			'type'    => $this->get_string_gateway_setting( 'payment_request_button_type', 'default' ),
			'theme'   => $this->get_string_gateway_setting( 'payment_request_button_theme', 'dark' ),
			'height'  => $this->get_woopay_button_height(),
			'radius'  => $this->get_string_gateway_setting_allow_empty( 'payment_request_button_border_radius', '' ),
			'size'    => $this->get_string_gateway_setting( 'payment_request_button_size', 'default' ),
			'context' => $this->normalize_button_context( $context ),
		);
	}

	/**
	 * Get the WooPay button height from the express checkout size setting.
	 *
	 * @return string
	 */
	private function get_woopay_button_height(): string {
		$size = $this->get_string_gateway_setting( 'payment_request_button_size', 'medium' );

		if ( 'medium' === $size ) {
			return '48';
		}

		if ( 'large' === $size ) {
			return '55';
		}

		return '40';
	}

	/**
	 * Normalize an express checkout context.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	private function normalize_button_context( string $context ): string {
		$context = sanitize_key( $context );

		return in_array( $context, array( 'product', 'cart', 'checkout' ), true ) ? $context : 'checkout';
	}

	/**
	 * Get a string gateway setting.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_string_gateway_setting( string $key, string $fallback ): string {
		$value = $this->get_account_service()->get_gateway_setting( $key, $fallback );

		return is_scalar( $value ) && '' !== (string) $value ? sanitize_text_field( (string) $value ) : $fallback;
	}

	/**
	 * Get a string gateway setting while preserving empty string values.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_string_gateway_setting_allow_empty( string $key, string $fallback ): string {
		$value = $this->get_account_service()->get_gateway_setting( $key, $fallback );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
	}

	/**
	 * Tell whether a gateway setting is truthy.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_truthy_gateway_setting( string $key ): bool {
		$value = $this->get_account_service()->get_gateway_setting( $key, 'no' );

		return true === $value || 'yes' === $value || '1' === $value || 1 === $value;
	}

	/**
	 * Get the connected account country.
	 *
	 * @return string
	 */
	private function get_account_country(): string {
		$account_data = $this->get_account_service()->get_cached_account_data();
		$country      = isset( $account_data['country'] ) && is_scalar( $account_data['country'] )
			? strtoupper( (string) $account_data['country'] )
			: '';

		if ( false !== strpos( $country, ':' ) ) {
			$base_country = strtok( $country, ':' );
			$country      = is_string( $base_country ) ? $base_country : '';
		}

		return '' !== $country ? $country : $this->get_store_base_country();
	}

	/**
	 * Get the store base country.
	 *
	 * @return string
	 */
	private function get_store_base_country(): string {
		$country = (string) get_option( 'woocommerce_default_country', 'US' );
		if ( function_exists( 'WC' ) && WC() && WC()->countries ) {
			$country = (string) WC()->countries->get_base_country();
		}

		if ( false !== strpos( $country, ':' ) ) {
			$base_country = strtok( $country, ':' );
			$country      = is_string( $base_country ) ? $base_country : '';
		}

		$country = strtoupper( $country );

		return '' !== $country ? $country : 'US';
	}

	/**
	 * Get the current shopper email when available.
	 *
	 * @return string
	 */
	private function get_current_shopper_email(): string {
		$user = wp_get_current_user();

		return $user instanceof \WP_User && is_email( $user->user_email ) ? sanitize_email( $user->user_email ) : '';
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
	 * Tell whether the shopper opted to save their details in WooPay.
	 *
	 * @return bool
	 */
	private function get_woopay_save_user_flag(): bool {
		$value = $this->get_posted_or_session_value( 'save_user_in_woopay', false );

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Get WooPay shopper phone.
	 *
	 * @return string
	 */
	private function get_woopay_phone(): string {
		$phone_field = $this->get_posted_or_session_value( 'woopay_user_phone_field', array() );
		if ( is_array( $phone_field ) && is_scalar( $phone_field['full'] ?? null ) ) {
			return sanitize_text_field( (string) $phone_field['full'] );
		}

		$phone = $this->get_posted_or_session_value( 'phone_number', '' );

		return is_scalar( $phone ) ? sanitize_text_field( (string) $phone ) : '';
	}

	/**
	 * Get WooPay source URL.
	 *
	 * @return string
	 */
	private function get_woopay_source_url(): string {
		$value = $this->get_posted_or_session_value( 'woopay_source_url', '' );

		return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
	}

	/**
	 * Tell whether the WooPay save-user request came from Blocks checkout.
	 *
	 * @return bool
	 */
	private function get_woopay_is_blocks(): bool {
		$value = $this->get_posted_or_session_value( 'woopay_is_blocks', false );

		return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
	}

	/**
	 * Get WooPay checkout viewport.
	 *
	 * @return string
	 */
	private function get_woopay_viewport(): string {
		$value = $this->get_posted_or_session_value( 'woopay_viewport', '' );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * Get a posted value or fall back to WooPay session data.
	 *
	 * @param string $key     Value key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	private function get_posted_or_session_value( string $key, $fallback ) {
		$post_data = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_array( $post_data ) && array_key_exists( $key, $post_data ) ) {
			return $post_data[ $key ];
		}

		$session_data = $this->get_woopay_session_data();
		if ( is_array( $session_data ) && array_key_exists( $key, $session_data ) ) {
			return $session_data[ $key ];
		}

		return $fallback;
	}

	/**
	 * Get the connected store blog ID.
	 *
	 * @return string
	 */
	private function get_store_blog_id(): string {
		$blog_id = '';

		if ( class_exists( '\Jetpack_Options' ) ) {
			$blog_id = (string) \Jetpack_Options::get_option( 'id' );
		}

		/**
		 * Filters the native WooPay blog ID used for session signatures.
		 *
		 * @param string $blog_id Connected store blog ID.
		 *
		 * @since 11.0.0
		 */
		return (string) apply_filters( 'woocommerce_woopayments_woopay_blog_id', $blog_id );
	}

	/**
	 * Get the connected store blog token.
	 *
	 * @return string
	 */
	private function get_store_blog_token(): string {
		$blog_token = '';

		if ( class_exists( '\Jetpack_Options' ) ) {
			$blog_token = (string) \Jetpack_Options::get_option( 'blog_token' );
		} elseif ( defined( 'DEV_BLOG_TOKEN_SECRET' ) && is_string( DEV_BLOG_TOKEN_SECRET ) ) {
			$blog_token = DEV_BLOG_TOKEN_SECRET;
		}

		/**
		 * Filters the native WooPay blog token used for session signatures.
		 *
		 * @param string $blog_token Connected store blog token.
		 *
		 * @since 11.0.0
		 */
		return (string) apply_filters( 'woocommerce_woopayments_woopay_blog_token', $blog_token );
	}

	/**
	 * Create a WooPay Store API nonce without requiring a cookie.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private function create_woopay_nonce( int $user_id ): string {
		$action = 'wc_store_api';
		$token  = '';
		$tick   = wp_nonce_tick( $action );

		return substr( wp_hash( $tick . '|' . $action . '|' . $user_id . '|' . $token, 'nonce' ), -12, 10 );
	}

	/**
	 * Get the Store API cart token when a session is available.
	 *
	 * @return string
	 */
	private function get_store_api_token(): string {
		$session = $this->get_wc_session();
		if ( null === $session || ! class_exists( CartTokenUtils::class ) ) {
			return '';
		}

		return CartTokenUtils::get_cart_token( (string) $session->get_customer_id() );
	}

	/**
	 * Get native store data for WooPay session initialization.
	 *
	 * @param int|null $order_id Pay-for-order order ID.
	 * @return array<string,mixed>
	 */
	private function get_store_data( ?int $order_id = null ): array {
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		if ( ! is_string( $shop_url ) || '' === $shop_url ) {
			$shop_url = home_url( '/' );
		}

		$manual_capture = 'yes' === $this->get_account_service()->get_gateway_setting( 'manual_capture', 'no' );
		$order          = $order_id ? wc_get_order( $order_id ) : false;
		$checkout_url   = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		$custom_message = (string) $this->get_account_service()->get_gateway_setting( 'platform_checkout_custom_message', '' );
		$blocks_data    = $this->get_blocks_data_extractor();
		if ( $order instanceof \WC_Order ) {
			$checkout_url = $order->get_checkout_payment_url();
		}
		$store_logo_file_id = (string) $this->get_account_service()->get_gateway_setting( 'platform_checkout_store_logo', '' );
		$store_logo         = $this->get_theme_store_logo_url();
		if ( '' !== $store_logo_file_id ) {
			$store_logo = get_rest_url( null, 'wc/v3/payments/file/' . $store_logo_file_id );
		}

		return array(
			'store_name'                     => get_bloginfo( 'name' ),
			'store_logo'                     => $store_logo,
			'custom_message'                 => $custom_message,
			'blog_id'                        => $this->get_store_blog_id(),
			'blog_url'                       => get_site_url(),
			'blog_checkout_url'              => $checkout_url,
			'blog_shop_url'                  => $shop_url,
			'blog_timezone'                  => wp_timezone_string(),
			'store_api_url'                  => get_rest_url( null, 'wc/store' ),
			'account_id'                     => $this->get_account_service()->get_account_id(),
			'test_mode'                      => $this->get_account_service()->is_test_mode_enabled(),
			'capture_method'                 => $manual_capture ? 'manual' : 'automatic',
			'is_subscriptions_plugin_active' => class_exists( 'WC_Subscriptions' ),
			'woocommerce_tax_display_cart'   => get_option( 'woocommerce_tax_display_cart' ),
			'ship_to_billing_address_only'   => function_exists( 'wc_ship_to_billing_address_only' ) && wc_ship_to_billing_address_only(),
			'return_url'                     => $order instanceof \WC_Order ? $checkout_url : ( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ),
			'blocks_data'                    => $blocks_data->get_data(),
			'checkout_schema_namespaces'     => $blocks_data->get_checkout_schema_namespaces(),
			'optional_fields_status'         => $blocks_data->get_optional_fields_status( $custom_message ),
		);
	}

	/**
	 * Get the WooPay blocks data extractor.
	 *
	 * @return WooPaymentsWooPayBlocksDataExtractor
	 */
	private function get_blocks_data_extractor(): WooPaymentsWooPayBlocksDataExtractor {
		if ( ! $this->blocks_data_extractor instanceof WooPaymentsWooPayBlocksDataExtractor ) {
			$blocks_data_extractor = wc_get_container()->get( WooPaymentsWooPayBlocksDataExtractor::class );

			$this->blocks_data_extractor = $blocks_data_extractor instanceof WooPaymentsWooPayBlocksDataExtractor
				? $blocks_data_extractor
				: new WooPaymentsWooPayBlocksDataExtractor();
		}

		return $this->blocks_data_extractor;
	}

	/**
	 * Get the WooPay adapted extensions registry.
	 *
	 * @return WooPaymentsWooPayAdaptedExtensions
	 */
	private function get_adapted_extensions(): WooPaymentsWooPayAdaptedExtensions {
		if ( ! $this->adapted_extensions instanceof WooPaymentsWooPayAdaptedExtensions ) {
			$this->adapted_extensions = new WooPaymentsWooPayAdaptedExtensions();
		}

		return $this->adapted_extensions;
	}

	/**
	 * Get the store logo URL from the active theme custom logo.
	 *
	 * @return string
	 */
	private function get_theme_store_logo_url(): string {
		$site_logo_id = get_theme_mod( 'custom_logo' );
		if ( empty( $site_logo_id ) ) {
			return '';
		}

		$site_logo = wp_get_attachment_image_src( (int) $site_logo_id, 'full' );

		return is_array( $site_logo ) && isset( $site_logo[0] ) && is_string( $site_logo[0] ) ? $site_logo[0] : '';
	}

	/**
	 * Get cart data for WooPay init-session preloading.
	 *
	 * @param bool                 $is_pay_for_order Whether this is a pay-for-order session.
	 * @param int|null             $order_id         Pay-for-order order ID.
	 * @param string|null          $key              Pay-for-order key.
	 * @param string|null          $billing_email    Pay-for-order billing email.
	 * @param WP_REST_Request|null $woopay_request   WooPay REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	private function get_cart_data( bool $is_pay_for_order, ?int $order_id, ?string $key, ?string $billing_email, ?WP_REST_Request $woopay_request ): array {
		if ( $woopay_request instanceof WP_REST_Request ) {
			/**
			 * Store API cart subrequest.
			 *
			 * @var WP_REST_Request<array<string,mixed>> $request
			 */
			$request = new WP_REST_Request( 'GET', '/wc/store/v1/cart' );
			$this->copy_cart_token_header( $woopay_request, $request );

			return $this->get_store_api_response_data( $request );
		}

		if ( $is_pay_for_order && null !== $order_id ) {
			return $this->preload_store_api_path(
				'/wc/store/v1/order/' . rawurlencode( (string) $order_id ) .
				'?key=' . rawurlencode( (string) $key ) .
				'&billing_email=' . rawurlencode( (string) $billing_email )
			);
		}

		return $this->preload_store_api_path( '/wc/store/v1/cart' );
	}

	/**
	 * Get checkout data for WooPay init-session preloading.
	 *
	 * @param WP_REST_Request|null $woopay_request WooPay REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	private function get_checkout_data( ?WP_REST_Request $woopay_request ): array {
		add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		try {
			if ( $woopay_request instanceof WP_REST_Request ) {
				/**
				 * Store API checkout subrequest.
				 *
				 * @var WP_REST_Request<array<string,mixed>> $request
				 */
				$request = new WP_REST_Request( 'GET', '/wc/store/v1/checkout' );
				$this->copy_cart_token_header( $woopay_request, $request );

				return $this->get_store_api_response_data( $request );
			}

			return $this->preload_store_api_path( '/wc/store/v1/checkout' );
		} finally {
			remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
		}
	}

	/**
	 * Preload a Store API path and return the response body.
	 *
	 * @param string $path Store API path.
	 * @return array<string,mixed>
	 */
	private function preload_store_api_path( string $path ): array {
		$preloaded = rest_preload_api_request( array(), $path );
		$body      = $preloaded[ $path ]['body'] ?? array();

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Dispatch a Store API subrequest and return its data.
	 *
	 * @param WP_REST_Request $request Store API subrequest.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return array<string,mixed>
	 */
	private function get_store_api_response_data( WP_REST_Request $request ): array {
		$response = rest_do_request( $request );
		if ( $response instanceof WP_Error ) {
			return array();
		}

		if ( ! $response instanceof WP_REST_Response ) {
			$response = rest_ensure_response( $response );
		}

		if ( ! $response instanceof WP_REST_Response ) {
			return array();
		}

		if ( $response->is_error() ) {
			return array();
		}

		$data = $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Copy a WooPay Cart-Token header into a Store API subrequest.
	 *
	 * @param WP_REST_Request $source Source request.
	 * @param WP_REST_Request $target Target request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $source
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $target
	 */
	private function copy_cart_token_header( WP_REST_Request $source, WP_REST_Request $target ): void {
		$cart_token = $source->get_header( 'cart_token' );
		if ( is_string( $cart_token ) && '' !== $cart_token ) {
			$target->set_header( 'Cart-Token', $cart_token );
		}
	}

	/**
	 * Get a request string.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $key     Request key.
	 * @return string|null
	 */
	private function get_request_string( array $request, string $key ): ?string {
		if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
			return null;
		}

		$value = sanitize_text_field( (string) $request[ $key ] );

		return '' === $value ? null : $value;
	}

	/**
	 * Get a sanitized integer request value.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $key     Request key.
	 * @return int|null
	 */
	private function get_request_int( array $request, string $key ): ?int {
		if ( ! isset( $request[ $key ] ) || ! is_scalar( $request[ $key ] ) ) {
			return null;
		}

		$value = absint( $request[ $key ] );

		return 0 === $value ? null : $value;
	}

	/**
	 * Get a sanitized array request value.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @param string              $key     Request key.
	 * @return array<string,mixed>|null
	 */
	private function get_request_array( array $request, string $key ): ?array {
		if ( ! isset( $request[ $key ] ) ) {
			return null;
		}

		$value = $request[ $key ];
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : null;
		}

		return is_array( $value ) ? $this->sanitize_array_recursive( $value ) : null;
	}

	/**
	 * Get sanitized WooPay font rules from request data.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<int,array<string,string>>
	 */
	private function get_font_rules_from_request( array $request ): array {
		$font_rules = $request['font_rules'] ?? array();
		if ( is_string( $font_rules ) ) {
			$decoded    = json_decode( $font_rules, true );
			$font_rules = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $font_rules ) ? $this->sanitize_woopay_font_rules( $font_rules ) : array();
	}

	/**
	 * Get WooCommerce session when available.
	 *
	 * @return \WC_Session|null
	 */
	private function get_wc_session(): ?\WC_Session {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session instanceof \WC_Session ) {
			return null;
		}

		return WC()->session;
	}

	/**
	 * Recursively sanitize appearance data.
	 *
	 * @param array<string,mixed> $data Appearance data.
	 * @return array<string,mixed>
	 */
	private function sanitize_array_recursive( array $data ): array {
		$sanitized = array();

		foreach ( $data as $key => $value ) {
			$sanitized[ sanitize_text_field( (string) $key ) ] = is_array( $value )
				? $this->sanitize_array_recursive( $value )
				: sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	/**
	 * Validate that all appearance values are short strings.
	 *
	 * @param array<mixed> $values Values to validate.
	 * @return bool
	 */
	private function validate_string_values( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 200 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get the current WooPay appearance version.
	 *
	 * @return string
	 */
	private function get_appearance_version(): string {
		return $this->get_frontend_styles_service()->get_styles_cache_version();
	}

	/**
	 * Check whether stored WooPay appearance data matches the active styles cache.
	 *
	 * @param array<mixed> $stored Stored appearance option.
	 * @return bool
	 */
	private function has_current_appearance_version( array $stored ): bool {
		return isset( $stored['version'] ) && is_scalar( $stored['version'] ) && (string) $stored['version'] === $this->get_appearance_version();
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
	 * Get allowed WooPay appearance rule selectors.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_appearance_rules(): array {
		return array(
			'.Input',
			'.Input--invalid',
			'.Label',
			'.Label--resting',
			'.Label--floating',
			'.Text',
			'.Text--redirect',
			'.Block',
			'.Tab',
			'.Tab:hover',
			'.Tab--selected',
			'.TabIcon',
			'.TabIcon:hover',
			'.TabIcon--selected',
			'.TabLabel',
			'.Heading',
			'.Header',
			'.Footer',
			'.Footer-link',
			'.Footer--link',
			'.Button',
			'.Link',
			'.Container',
		);
	}

	/**
	 * Get allowed WooPay appearance CSS properties.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_appearance_properties(): array {
		return array(
			'color',
			'backgroundColor',
			'fontFamily',
			'fontSize',
			'fontWeight',
			'fontVariation',
			'lineHeight',
			'letterSpacing',
			'padding',
			'paddingTop',
			'paddingRight',
			'paddingBottom',
			'paddingLeft',
			'border',
			'borderTop',
			'borderRight',
			'borderBottom',
			'borderLeft',
			'borderColor',
			'borderStyle',
			'borderWidth',
			'borderTopColor',
			'borderTopStyle',
			'borderTopWidth',
			'borderRightColor',
			'borderRightStyle',
			'borderRightWidth',
			'borderBottomColor',
			'borderBottomStyle',
			'borderBottomWidth',
			'borderLeftColor',
			'borderLeftStyle',
			'borderLeftWidth',
			'borderRadius',
			'borderTopLeftRadius',
			'borderTopRightRadius',
			'borderBottomRightRadius',
			'borderBottomLeftRadius',
			'outline',
			'outlineColor',
			'outlineWidth',
			'outlineStyle',
			'outlineOffset',
			'boxShadow',
			'textDecoration',
			'textShadow',
			'textTransform',
			'transition',
			'transform',
			'-webkit-font-smoothing',
			'-moz-osx-font-smoothing',
		);
	}

	/**
	 * Get allowed WooPay appearance font domains.
	 *
	 * @return array<int,string>
	 */
	private function get_allowed_font_domains(): array {
		return array(
			'fonts.googleapis.com',
			'fonts.gstatic.com',
			'use.typekit.net',
			'fonts.bunny.net',
			'fonts.wp.com',
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
