<?php
/**
 * WooPaymentsWooPaySessionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\Jetpack\Connection\Client as Jetpack_Connection_Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsSubscriptionMethodPolicy;
use Automattic\WooCommerce\StoreApi\RoutesController;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendAssets;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderPayAccess;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsDefaults;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;

/**
 * Starts and serves the shopper's WooPay session: builds, encrypts and signs the session data WooPay's checkout loads,
 * keeps the shopper's WooPay details in the WooCommerce session, and builds the WooPay config the checkout scripts read.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsWooPaySessionService {

	private const WOOPAY_SESSION_KEY = 'woopay-user-data';

	private const WOOPAY_DEFAULT_URL = 'https://pay.woo.com';

	private const WOOPAY_REST_NAMESPACE = 'wp-json/platform-checkout/v1';

	/**
	 * WP_Error codes a failed WooPay init request can carry that are safe to log: WP_Http::request() and
	 * WP_Http::_dispatch_request() (http_request_failed, http_request_not_executed, http_failure) and Jetpack Connection's
	 * Client::build_signed_request() (missing_token, malformed_token, invalid_body).
	 */
	private const KNOWN_REQUEST_ERROR_CODES = array( 'http_request_failed', 'http_request_not_executed', 'http_failure', 'missing_token', 'malformed_token', 'invalid_body' );

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPay eligibility rules.
	 *
	 * @var WooPaymentsWooPayEligibility
	 */
	private WooPaymentsWooPayEligibility $woopay_eligibility;

	/**
	 * WooPay checkout appearance store.
	 *
	 * @var WooPaymentsWooPayAppearanceStore
	 */
	private WooPaymentsWooPayAppearanceStore $appearance_store;

	/**
	 * Frontend tracking controller.
	 *
	 * @var WooPaymentsFrontendTrackingController
	 */
	private WooPaymentsFrontendTrackingController $frontend_tracking_controller;

	/**
	 * WooPay blocks data extractor.
	 *
	 * @var WooPaymentsWooPayBlocksDataExtractor
	 */
	private WooPaymentsWooPayBlocksDataExtractor $blocks_data_extractor;

	/**
	 * WooPay adapted extensions registry.
	 *
	 * @var WooPaymentsWooPayAdaptedExtensions
	 */
	private WooPaymentsWooPayAdaptedExtensions $adapted_extensions;

	/**
	 * WooPayments customer service.
	 *
	 * @var WooPaymentsCustomerService
	 */
	private WooPaymentsCustomerService $customer_service;

	/**
	 * WooPayments logger, which writes only when debug logging is on or the store is in development mode (client 11.1.0
	 * `src/Internal/Logger.php:64-91`).
	 *
	 * @var WooPaymentsLogger
	 */
	private WooPaymentsLogger $logger;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService             $account_service              WooPayments account service.
	 * @param WooPaymentsWooPayEligibility          $woopay_eligibility           WooPay eligibility rules.
	 * @param WooPaymentsWooPayAppearanceStore      $appearance_store             WooPay checkout appearance store.
	 * @param WooPaymentsFrontendTrackingController $frontend_tracking_controller Frontend tracking controller.
	 * @param WooPaymentsWooPayBlocksDataExtractor  $blocks_data_extractor        WooPay blocks data extractor.
	 * @param WooPaymentsWooPayAdaptedExtensions    $adapted_extensions           WooPay adapted extensions registry.
	 * @param WooPaymentsCustomerService            $customer_service             WooPayments customer service.
	 * @param WooPaymentsLogger                     $logger                       WooPayments logger.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsWooPayEligibility $woopay_eligibility, WooPaymentsWooPayAppearanceStore $appearance_store, WooPaymentsFrontendTrackingController $frontend_tracking_controller, WooPaymentsWooPayBlocksDataExtractor $blocks_data_extractor, WooPaymentsWooPayAdaptedExtensions $adapted_extensions, WooPaymentsCustomerService $customer_service, WooPaymentsLogger $logger ): void {
		$this->account_service              = $account_service;
		$this->woopay_eligibility           = $woopay_eligibility;
		$this->appearance_store             = $appearance_store;
		$this->frontend_tracking_controller = $frontend_tracking_controller;
		$this->blocks_data_extractor        = $blocks_data_extractor;
		$this->adapted_extensions           = $adapted_extensions;
		$this->customer_service             = $customer_service;
		$this->logger                       = $logger;
	}

	/**
	 * Get the WooPay host URL.
	 *
	 * @return string
	 */
	public static function get_woopay_url(): string {
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
	public static function get_woopay_rest_url( string $endpoint ): string {
		return self::get_woopay_url() . '/' . self::WOOPAY_REST_NAMESPACE . '/' . ltrim( $endpoint, '/' );
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
	 * Resolve the WooPay session email: the email passed in, the verified WooPay identity, the WooCommerce customer's
	 * billing or account email, then the logged-in user's email.
	 *
	 * Callers pass the request's email and identity; nothing is read from the request here.
	 *
	 * @param string|null              $email          Email supplied with the request, if any.
	 * @param array<string,mixed>|null $encrypted_data Encrypted WooPay identity envelope, if any.
	 * @return string
	 */
	private function resolve_session_email( ?string $email, ?array $encrypted_data = null ): string {
		if ( null !== $email && '' !== $email ) {
			return $email;
		}

		$identity_email = $this->get_verified_identity_email( $encrypted_data );
		if ( '' !== $identity_email ) {
			return $identity_email;
		}

		$customer = WC()->customer;
		if ( is_object( $customer ) ) {
			$billing_email = is_callable( array( $customer, 'get_billing_email' ) ) ? (string) $customer->get_billing_email() : '';
			if ( '' !== $billing_email ) {
				return $billing_email;
			}

			$customer_email = is_callable( array( $customer, 'get_email' ) ) ? (string) $customer->get_email() : '';
			if ( '' !== $customer_email ) {
				return $customer_email;
			}
		}

		$user = wp_get_current_user();
		if ( $user->exists() ) {
			return (string) $user->user_email;
		}

		return '';
	}

	/**
	 * Get the email that pre-fills the WooPay OTP iframe from the page HTML.
	 *
	 * Follows the client's chain (WooPay_Session::get_user_email(), class-woopay-session.php:433-472), but only on pages that
	 * no page cache serves to another visitor (WooPaymentsOrderPayAccess::may_put_shopper_email_in_page()).
	 *
	 * @return string
	 */
	private function get_page_prefill_email(): string {
		return WooPaymentsOrderPayAccess::may_put_shopper_email_in_page() ? $this->resolve_session_email( null ) : '';
	}

	/**
	 * Get the sanitized email from a verified WooPay identity envelope.
	 *
	 * @param array<string,mixed>|null $encrypted_data Encrypted WooPay identity envelope.
	 * @return string
	 */
	private function get_verified_identity_email( ?array $encrypted_data ): string {
		$identity = $this->decrypt_identity_data( $encrypted_data );
		if ( ! is_string( $identity['user_email'] ?? null ) ) {
			return '';
		}

		$email = sanitize_email( $identity['user_email'] );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Verify and decrypt a WooPay identity envelope.
	 *
	 * @param array<string,mixed>|null $encrypted_data Encrypted WooPay identity envelope.
	 * @return array<string,mixed>
	 */
	private function decrypt_identity_data( ?array $encrypted_data ): array {
		if ( null === $encrypted_data ) {
			return array();
		}

		$field_names = array_keys( $encrypted_data );
		sort( $field_names );
		if ( array( 'data', 'hash', 'iv' ) !== $field_names ) {
			return array();
		}

		if ( ! is_string( $encrypted_data['data'] ) || ! is_string( $encrypted_data['iv'] ) || ! is_string( $encrypted_data['hash'] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the WooPay identity envelope.
		$data = base64_decode( $encrypted_data['data'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the WooPay identity envelope.
		$iv = base64_decode( $encrypted_data['iv'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the WooPay identity envelope.
		$hash = base64_decode( $encrypted_data['hash'], true );
		if ( false === $data || false === $iv || false === $hash || '' === $data ) {
			return array();
		}

		$iv_length  = openssl_cipher_iv_length( 'aes-256-cbc' );
		$blog_token = $this->get_store_blog_token();
		if ( false === $iv_length || strlen( $iv ) !== $iv_length || '' === $blog_token ) {
			return array();
		}

		$computed_hash = hash_hmac( 'sha256', $iv . $data, $blog_token );
		if ( ! hash_equals( $computed_hash, $hash ) ) {
			return array();
		}

		$decrypted_data = openssl_decrypt( $data, 'aes-256-cbc', $blog_token, OPENSSL_RAW_DATA, $iv );
		if ( false === $decrypted_data ) {
			return array();
		}

		$identity = json_decode( $decrypted_data, true );

		return is_array( $identity ) ? $identity : array();
	}

	/**
	 * Get unencrypted minimum WooPay session data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_minimum_session_data(): array {
		return array(
			'wcpay_version'     => WooPaymentsClientVersion::VERSION,
			'blog_id'           => $this->get_store_blog_id(),
			'blog_rest_url'     => get_rest_url(),
			'blog_checkout_url' => wc_get_checkout_url(),
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
		$email          = $this->get_request_string( $request, 'email' );
		$encrypted_data = isset( $request['encrypted_data'] ) && is_array( $request['encrypted_data'] ) ? $request['encrypted_data'] : null;
		$email          = $this->resolve_session_email( $email, $encrypted_data );

		return $this->encrypt_and_sign_data(
			$this->start_init_session_request(
				$email,
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
	 * Start a WooPay session request: build its body and, for a known email, save it as the shopper's billing email and register the adapted extensions.
	 *
	 * @param string|null                     $email           Shopper email.
	 * @param string|null                     $user_session    WooPay user session.
	 * @param WP_REST_Request|null            $woopay_request  WooPay REST request.
	 * @param int|null                        $order_id        Pay-for-order order ID; ignored unless `$key` lets the current user pay that order.
	 * @param string|null                     $key             Pay-for-order key.
	 * @param string|null                     $billing_email   Pay-for-order billing email.
	 * @param array<string,mixed>|null        $appearance      WooPay appearance payload.
	 * @param array<int,array<string,string>> $font_rules      WooPay font rules.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	public function start_init_session_request(
		?string $email = null,
		?string $user_session = null,
		?WP_REST_Request $woopay_request = null,
		?int $order_id = null,
		?string $key = null,
		?string $billing_email = null,
		?array $appearance = null,
		array $font_rules = array()
	): array {
		// The session carries the order's key-bearing pay URL, so a posted order ID needs its key; client 11.1.0 trusts it (class-woopay-session.php:499-500).
		$order = null !== $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || ! WooPaymentsOrderPayAccess::can_pay_with_key( $order, (string) $key ) ) {
			$order_id = null;
		}

		$is_pay_for_order = null !== $order_id;
		$email            = $this->resolve_session_email( $email );

		$request = array(
			'wcpay_version'        => WooPaymentsClientVersion::VERSION,
			'user_id'              => get_current_user_id(),
			'customer_id'          => $this->customer_service->get_or_create_customer_id_for_user( get_current_user_id() ),
			'session_nonce'        => $this->create_woopay_nonce( get_current_user_id() ),
			'store_api_token'      => $this->get_store_api_token(),
			'email'                => $email,
			'store_data'           => $this->get_store_data( $order_id ),
			'user_session'         => $user_session,
			'preloaded_requests'   => $is_pay_for_order
				? array(
					'cart'     => $this->get_cart_data( $order_id, $key, $billing_email, $woopay_request ),
					'checkout' => array(
						'order_id' => $order_id,
					),
				)
				: array(
					'cart'     => $this->get_cart_data( null, null, null, $woopay_request ),
					'checkout' => $this->get_checkout_data( $woopay_request ),
				),
			'tracks_user_identity' => $this->frontend_tracking_controller->get_or_create_tracks_identity_for_current_user(),
			// The stored appearance and font rules fill in only while global theme support is on, so a merchant who turns the
			// setting off stops sending old theme data to WooPay.
			'appearance'           => null === $appearance
				? ( $this->woopay_eligibility->is_woopay_global_theme_support_enabled() ? $this->appearance_store->get_woopay_appearance_or_null() : null )
				: $this->appearance_store->sanitize_array_recursive( $appearance ),
			'font_rules'           => array() === $font_rules
				? ( $this->woopay_eligibility->is_woopay_global_theme_support_enabled() ? $this->appearance_store->get_woopay_font_rules() : array() )
				: $this->appearance_store->sanitize_woopay_font_rules( $font_rules ),
		);

		$adapted_extensions        = $this->adapted_extensions;
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

		if ( '' === $blog_id ) {
			$this->logger->log( 'WooPay blog_id is missing, so the WooPay session data cannot be signed.' );
		}

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
			$this->start_init_session_request(
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
				'url'     => self::get_woopay_rest_url( 'init' ),
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => $body,
			),
			$body
		);

		// Client 11.1.0 logs the whole failed request and every response body (class-woopay-session.php:707-715). The init
		// answer's URL carries the WooPay session key, so only the result, the HTTP status and the error code are logged.
		if ( $response instanceof WP_Error || ! is_array( $response ) ) {
			$this->logger->error( 'HTTP_REQUEST_ERROR: WooPay session init request failed.', $this->get_request_error_log_context( $response ) );
			$this->logger->log( 'WooPay session init response.', 'info', array( 'result' => 'failure' ) );

			return array( 'result' => 'failure' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array( 'result' => 'failure' );
		$this->logger->log(
			'WooPay session init response.',
			'info',
			array(
				'result'      => 'success' === ( $data['result'] ?? null ) ? 'success' : 'failure',
				'http_status' => (int) wp_remote_retrieve_response_code( $response ),
			)
		);

		return $data;
	}

	/**
	 * Get the log context for a failed WooPay request: the error code and, for a cURL failure, its number.
	 *
	 * The error message is left out: a transport message can name the host or the URL. The code is logged only when it is
	 * one WordPress or Jetpack sets; a pre_http_request callback can return a WP_Error with any code, a URL or a token
	 * included, so any other code is logged as unknown_error.
	 *
	 * @param mixed $response What the request returned instead of a response array.
	 * @return array<string,int|string>
	 */
	private function get_request_error_log_context( $response ): array {
		if ( ! $response instanceof WP_Error ) {
			return array( 'response_type' => gettype( $response ) );
		}

		$error_code = $response->get_error_code();
		$context    = array( 'error_code' => in_array( $error_code, self::KNOWN_REQUEST_ERROR_CODES, true ) ? $error_code : 'unknown_error' );
		// Requests words a transport failure as "cURL error <number>: <text>" (wp-includes/Requests/src/Transport/Curl.php).
		if ( 1 === preg_match( '/^cURL error (\d+):/', $response->get_error_message(), $matches ) ) {
			$context['curl_error'] = (int) $matches[1];
		}

		return $context;
	}

	/**
	 * Persist WooPay phone/session data in the WooCommerce session.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	public function set_woopay_phone_session_data( array $request ): void {
		$session = WooPaymentsSessionService::get_wc_session();
		if ( null === $session ) {
			return;
		}

		// The session class is swappable (woocommerce_session_handler), and not every handler sets a customer cookie.
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
		$session = WooPaymentsSessionService::get_wc_session();
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
		$session = WooPaymentsSessionService::get_wc_session();

		return null === $session ? null : $session->get( self::WOOPAY_SESSION_KEY );
	}

	/**
	 * Get WooPay frontend config used by classic and block checkout surfaces.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,mixed>
	 */
	public function get_woopay_frontend_config( string $context = 'checkout' ): array {
		// isWoopayFirstPartyAuthEnabled is the express feature option, WooPay eligibility and the visitor's country, whatever the
		// express button locations (client 11.1.0 WooPay_Utilities::is_woopay_first_party_auth_enabled(), class-woopay-utilities.php:80-82).
		$is_woopay_enabled                 = $this->woopay_eligibility->is_woopay_enabled();
		$is_country_available              = $is_woopay_enabled && $this->woopay_eligibility->is_woopay_country_available();
		$is_global_theme_enabled           = $this->woopay_eligibility->is_woopay_global_theme_support_enabled();
		$should_show_woopay                = $this->woopay_eligibility->should_show_woopay_button_for_enabled_state( $context, $is_woopay_enabled ) && $this->woopay_eligibility->is_woopay_gateway_available();
		$woopay_appearance                 = $is_global_theme_enabled ? $this->appearance_store->get_woopay_appearance_or_null() : null;
		$woopay_font_rules                 = $is_global_theme_enabled ? $this->appearance_store->get_woopay_font_rules() : array();
		$woopay_session_email              = $this->get_page_prefill_email();
		$woopay_minimum_session            = $is_woopay_enabled ? $this->get_encrypted_minimum_session_data() : array();
		$woopay_express_available          = $is_woopay_enabled && $this->woopay_eligibility->is_woopay_express_checkout_configured_at( $context );
		$woopay_first_party_auth_available = $is_country_available && '1' === get_option( '_wcpay_feature_woopay_express_checkout', '1' );

		return array(
			'isWooPayEnabled'                   => $is_woopay_enabled,
			'isWoopayExpressCheckoutEnabled'    => $woopay_express_available,
			'isWoopayFirstPartyAuthEnabled'     => $woopay_first_party_auth_available,
			// Client 11.1.0 keeps the WooPay email lookup from shoppers the guest rule excludes by ANDing the rule into its checkout
			// config's isWooPayEnabled (class-wc-payments-checkout.php:198), read by its email inputs and the Blocks button
			// registration (client/checkout/blocks/index.js:123-134), which carries the rule through shouldShowWooPayButton on
			// both sides (class-wc-payments-woopay-button-handler.php:300-320; should_show_woopay_button_for_enabled_state()).
			// isWooPayEnabled also turns on the save-user section here, which client 11.1.0 does not hide from those shoppers,
			// so the rule goes on the email input flag.
			'isWooPayEmailInputEnabled'         => $this->woopay_eligibility->is_woopay_email_input_enabled() && $this->woopay_eligibility->should_enable_woopay_on_guest_checkout(),
			'isWooPayDirectCheckoutEnabled'     => $this->woopay_eligibility->is_woopay_direct_checkout_enabled(),
			'isWooPayGlobalThemeSupportEnabled' => $is_global_theme_enabled,
			'forceNetworkSavedCards'            => $this->account_service->is_network_saved_cards_enabled() || $this->woopay_eligibility->should_use_stripe_platform_on_checkout_page(),
			'ajaxUrl'                           => admin_url( 'admin-ajax.php' ),
			'platformTrackerNonce'              => wp_create_nonce( 'platform_tracks_nonce' ),
			'tracksUrl'                         => $this->frontend_tracking_controller->get_tracks_rest_url(),
			'tracksRestNonce'                   => wp_create_nonce( 'wp_rest' ),
			'isShopperTrackingEnabled'          => $this->frontend_tracking_controller->is_woopay_event_tracking_enabled(),
			'woopayHost'                        => self::get_woopay_url(),
			'testMode'                          => $this->account_service->is_test_mode_enabled(),
			'wcpayVersionNumber'                => WooPaymentsClientVersion::VERSION,
			'woopayMerchantId'                  => $this->get_store_blog_id(),
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
			// Client 11.1.0 client/components/woopay/save-user/additional-information.js, rendered just before the agreement.
			'woopayAdditionalInfoText'          => __( "Next time you buy here and on other Woo-powered stores, we'll send you a code to securely purchase with WooPay.", 'woocommerce' ),
			// Client 11.1.0 client/components/woopay/save-user/agreement.js; the classic script replaces the tags with links.
			'woopayAgreementText'               => __( "By continuing, you agree to WooPay's <termsOfService/> and <privacyPolicy/>.", 'woocommerce' ),
			'woopayTermsOfServiceLabel'         => __( 'Terms of Service', 'woocommerce' ),
			'woopayPrivacyPolicyLabel'          => __( 'Privacy Policy', 'woocommerce' ),
			'woopayOtpIframeTitle'              => __( 'WooPay SMS code verification', 'woocommerce' ),
			'woopayOtpCloseLabel'               => __( 'Close', 'woocommerce' ),
			'woopayUnavailableMessage'          => __( 'WooPay is unavailable at this time. Please complete your checkout below. Sorry for the inconvenience.', 'woocommerce' ),
			'woopayExpressUnavailableMessage'   => __( 'WooPay is unavailable at this time. Sorry for the inconvenience.', 'woocommerce' ),
		);
	}

	/**
	 * Get the light config the classic script needs for direct checkout on pages that only carry a mini-cart.
	 *
	 * Mirrors client 11.1.0 `WC_Payments::enqueue_woopay_common_config_script()` (class-wc-payments.php:1797-1835): no button
	 * settings and no shopper geolocation.
	 *
	 * @since 11.2.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_woopay_direct_checkout_config(): array {
		return array(
			'woopayHost'                    => self::get_woopay_url(),
			'testMode'                      => $this->account_service->is_test_mode_enabled(),
			'woopaySessionNonce'            => wp_create_nonce( 'woopay_session_nonce' ),
			'woopayMerchantId'              => $this->get_store_blog_id(),
			'isWooPayDirectCheckoutEnabled' => $this->woopay_eligibility->is_woopay_direct_checkout_enabled(),
			'platformTrackerNonce'          => wp_create_nonce( 'platform_tracks_nonce' ),
			'ajaxUrl'                       => admin_url( 'admin-ajax.php' ),
			'woopayMinimumSessionData'      => $this->get_encrypted_minimum_session_data(),
		);
	}

	/**
	 * Get WooPay save-user checkout data.
	 *
	 * @return array<string,bool|string>
	 */
	public function get_save_user_checkout_data(): array {
		$account_data = $this->account_service->get_cached_account_data();

		return array(
			'PRE_CHECK_SAVE_MY_INFO'         => ! empty( $account_data['pre_check_save_my_info'] ),
			'woopayPhoneValidationScriptUrl' => WooPaymentsFrontendAssets::get_phone_validation_script_url(),
		);
	}

	/**
	 * Add WooPay save-user session data to order metadata.
	 *
	 * @param array<string,mixed> $metadata Metadata.
	 * @param \WC_Order           $order    Order object.
	 * @return array<string,mixed>
	 */
	public function maybe_add_woopay_user_metadata( array $metadata, \WC_Order $order ): array {
		$should_save_woopay_user = $this->should_save_user_in_woopay();
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
	 * Get WooPay button settings.
	 *
	 * @param string $context Express checkout context.
	 * @return array<string,string>
	 */
	private function get_woopay_button_settings( string $context ): array {
		return array(
			'type'    => $this->get_string_gateway_setting( 'payment_request_button_type' ),
			'theme'   => $this->get_string_gateway_setting( 'payment_request_button_theme' ),
			'height'  => $this->get_woopay_button_height(),
			'radius'  => $this->get_string_gateway_setting_allow_empty( 'payment_request_button_border_radius', '' ),
			'size'    => $this->get_string_gateway_setting( 'payment_request_button_size' ),
			'context' => WooPaymentsExpressPaymentMethodTypes::normalize_context( $context ),
		);
	}

	/**
	 * Get the WooPay button height from the express checkout size setting.
	 *
	 * @return string
	 */
	private function get_woopay_button_height(): string {
		$size = $this->get_string_gateway_setting( 'payment_request_button_size' );

		if ( 'medium' === $size ) {
			return '48';
		}

		if ( 'large' === $size ) {
			return '55';
		}

		return '40';
	}

	/**
	 * Get a string gateway setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private function get_string_gateway_setting( string $key ): string {
		$value = $this->account_service->get_gateway_setting( $key );

		if ( is_scalar( $value ) && '' !== (string) $value ) {
			return sanitize_text_field( (string) $value );
		}

		$default = WooPaymentsSettingsDefaults::get( $key );

		return is_scalar( $default ) ? sanitize_text_field( (string) $default ) : '';
	}

	/**
	 * Get a string gateway setting, keeping an empty string as it is.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private function get_string_gateway_setting_allow_empty( string $key, string $fallback ): string {
		$value = $this->account_service->get_gateway_setting( $key, $fallback );

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $fallback;
	}

	/**
	 * Tell whether the shopper opted to save their details in WooPay.
	 *
	 * Reads the posted save_user_in_woopay field, else the flag stored in the WooPay session data.
	 *
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public function should_save_user_in_woopay(): bool {
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
		return (string) \Jetpack_Options::get_option( 'id' );
	}

	/**
	 * Get the connected store blog token.
	 *
	 * @return string
	 */
	private function get_store_blog_token(): string {
		$blog_token = (string) \Jetpack_Options::get_option( 'blog_token' );

		if ( '' === $blog_token ) {
			// Client 11.1.0 class-woopay-utilities.php:297.
			$this->logger->log( 'WooPay blog_token is currently misconfigured.' );
		}

		return $blog_token;
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
		$session = WooPaymentsSessionService::get_wc_session();
		if ( null === $session ) {
			return '';
		}

		return CartTokenUtils::get_cart_token( (string) $session->get_customer_id() );
	}

	/**
	 * Get the store data a WooPay session starts with.
	 *
	 * @param int|null $order_id Pay-for-order order ID.
	 * @return array<string,mixed>
	 */
	private function get_store_data( ?int $order_id = null ): array {
		$shop_url = wc_get_page_permalink( 'shop' );
		if ( ! is_string( $shop_url ) || '' === $shop_url ) {
			$shop_url = home_url( '/' );
		}

		$manual_capture = 'yes' === $this->account_service->get_gateway_setting( 'manual_capture' );
		$order          = $order_id ? wc_get_order( $order_id ) : false;
		$checkout_url   = wc_get_checkout_url();
		$custom_message = (string) $this->account_service->get_gateway_setting( 'platform_checkout_custom_message' );
		$blocks_data    = $this->blocks_data_extractor;
		if ( $order instanceof \WC_Order ) {
			$checkout_url = $order->get_checkout_payment_url();
		}
		$store_logo_file_id = (string) $this->account_service->get_gateway_setting( 'platform_checkout_store_logo', '' );
		$store_logo         = $this->get_theme_store_logo_url();
		if ( '' !== $store_logo_file_id ) {
			$store_logo = get_rest_url( null, 'wc/v3/payments/file/' . $store_logo_file_id );
		}

		return array(
			'store_name'                     => get_bloginfo( 'name' ),
			'store_logo'                     => $store_logo,
			'custom_message'                 => $blocks_data->format_custom_terms( $custom_message ),
			'blog_id'                        => $this->get_store_blog_id(),
			'blog_url'                       => get_site_url(),
			'blog_checkout_url'              => $checkout_url,
			'blog_shop_url'                  => $shop_url,
			'blog_timezone'                  => wp_timezone_string(),
			'store_api_url'                  => $this->get_store_api_url(),
			'account_id'                     => $this->account_service->get_account_id(),
			'test_mode'                      => $this->account_service->is_test_mode_enabled(),
			'capture_method'                 => $manual_capture ? 'manual' : 'automatic',
			'is_subscriptions_plugin_active' => WooPaymentsSubscriptionMethodPolicy::is_woocommerce_subscriptions_active(),
			'woocommerce_tax_display_cart'   => get_option( 'woocommerce_tax_display_cart' ),
			'ship_to_billing_address_only'   => wc_ship_to_billing_address_only(),
			'return_url'                     => $order instanceof \WC_Order ? $checkout_url : wc_get_cart_url(),
			'blocks_data'                    => $blocks_data->get_data(),
			'checkout_schema_namespaces'     => $blocks_data->get_checkout_schema_namespaces(),
			'optional_fields_status'         => $blocks_data->get_optional_fields_status( $custom_message ),
		);
	}

	/**
	 * Get the Store API base URL WooPay calls: the cart route's namespace (wc/store/v1), as client 11.1.0
	 * `WooPay_Session::get_store_api_url()` resolves it (class-woopay-session.php:908-919).
	 *
	 * @return string
	 */
	private function get_store_api_url(): string {
		try {
			$namespace = StoreApi::container()->get( RoutesController::class )->get( 'cart' )->get_namespace();
		} catch ( \Exception $e ) {
			$namespace = 'wc/store';
		}

		return get_rest_url( null, $namespace );
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
	 * @param int|null             $order_id         Pay-for-order order ID.
	 * @param string|null          $key              Pay-for-order key.
	 * @param string|null          $billing_email    Pay-for-order billing email.
	 * @param WP_REST_Request|null $woopay_request   WooPay REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>>|null $woopay_request
	 * @return array<string,mixed>
	 */
	private function get_cart_data( ?int $order_id, ?string $key, ?string $billing_email, ?WP_REST_Request $woopay_request ): array {
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

		if ( null !== $order_id ) {
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

		return is_array( $value ) ? $this->appearance_store->sanitize_array_recursive( $value ) : null;
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

		return is_array( $font_rules ) ? $this->appearance_store->sanitize_woopay_font_rules( $font_rules ) : array();
	}
}
