<?php
/**
 * WooPaymentsTokenizedCartSessionController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\StoreApi\Utilities\JsonWebToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Runs the tokenized cart sessions the product-page express checkout uses: a Store API cart of its own, kept apart from
 * the shopper's cart.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsTokenizedCartSessionController implements RegisterHooksInterface {

	private const SESSION_NONCE_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION_NONCE';

	public const SESSION_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_SESSION';

	private const RESPONSE_SESSION_HEADER = 'X-WooPayments-Tokenized-Cart-Session';

	private const EPHEMERAL_CART_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_IS_EPHEMERAL_CART';

	/**
	 * Nonce action of the product-page tokenized cart session: the express checkout service creates it, this class checks it.
	 */
	public const SESSION_NONCE_ACTION = 'woopayments_tokenized_cart_session_nonce';

	public const TOKEN_ISSUER = 'woopayments/product-page';

	private const RETURN_URL_MARKER = 'woopayments-custom-session';

	/**
	 * Nonce action every surface checks before treating a Store API request as a tokenized cart request.
	 */
	public const TOKENIZED_CART_NONCE_ACTION = 'woopayments_tokenized_cart_nonce';

	private const CART_SESSION_KEYS = array(
		'cart',
		'cart_totals',
		'applied_coupons',
		'coupon_discount_totals',
		'coupon_discount_tax_totals',
		'removed_cart_contents',
		'chosen_shipping_methods',
		'shipping_method_counts',
		'shipping_for_package_0',
		'shipping_for_package_1',
		'shipping_for_package_2',
		'shipping_for_package_3',
		'shipping_for_package_4',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * Express checkout address normalizer.
	 *
	 * @var WooPaymentsExpressCheckoutAddressNormalizer
	 */
	private WooPaymentsExpressCheckoutAddressNormalizer $address_normalizer;

	/**
	 * Saved cart data for product-page order-received redirects.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $saved_cart_data = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter                   $arbiter            Runtime owner arbiter.
	 * @param WooPaymentsExpressCheckoutAddressNormalizer $address_normalizer Express checkout address normalizer.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsExpressCheckoutAddressNormalizer $address_normalizer ): void {
		$this->arbiter            = $arbiter;
		$this->address_normalizer = $address_normalizer;
	}

	/**
	 * Register tokenized cart session hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		if ( false === has_filter( 'woocommerce_session_handler', array( $this, 'handle_woocommerce_session_handler' ) ) ) {
			add_filter( 'woocommerce_session_handler', array( $this, 'handle_woocommerce_session_handler' ), 20 );
		}

		if ( false === has_filter( 'rest_pre_dispatch', array( $this, 'maybe_reject_invalid_tokenized_cart_session' ) ) ) {
			add_filter( 'rest_pre_dispatch', array( $this, 'maybe_reject_invalid_tokenized_cart_session' ), 10, 3 );
		}

		if ( $this->is_custom_session_order_received_request() ) {
			add_action( 'wp', array( $this, 'save_old_cart_data_for_restore' ), 1 );
			add_action( 'woocommerce_cart_emptied', array( $this, 'restore_old_cart_data' ) );
			add_filter( 'woocommerce_should_clear_cart_after_payment', array( $this, 'preserve_cart_after_tokenized_payment' ) );
		}
	}

	/**
	 * Swap the WooCommerce session handler for tokenized product-page Store API requests.
	 *
	 * @param mixed $session_handler Session handler class name.
	 * @return mixed
	 */
	public function handle_woocommerce_session_handler( $session_handler ) {
		if ( ! is_string( $session_handler ) || ! WC()->is_store_api_request() || ! $this->has_valid_session_nonce() ) {
			return $session_handler;
		}

		$this->register_tokenized_session_filters();

		return WooPaymentsTokenizedCartSessionHandler::class;
	}

	/**
	 * Register request filters that keep tokenized sessions isolated.
	 */
	private function register_tokenized_session_filters(): void {
		if ( false === has_filter( 'woocommerce_persistent_cart_enabled', array( $this, 'disable_persistent_cart' ) ) ) {
			add_filter( 'woocommerce_persistent_cart_enabled', array( $this, 'disable_persistent_cart' ) );
		}

		if ( false === has_filter( 'woocommerce_get_return_url', array( $this, 'add_tokenized_cart_return_url_marker' ) ) ) {
			add_filter( 'woocommerce_get_return_url', array( $this, 'add_tokenized_cart_return_url_marker' ) );
		}

		if ( false === has_filter( 'rest_post_dispatch', array( $this, 'handle_store_api_response' ) ) ) {
			add_filter( 'rest_post_dispatch', array( $this, 'handle_store_api_response' ), 10, 3 );
		}
	}

	/**
	 * Disable persistent cart merges for isolated tokenized sessions.
	 *
	 * @return bool
	 */
	public function disable_persistent_cart(): bool {
		return false;
	}

	/**
	 * Add the custom-session marker to Store API order return URLs.
	 *
	 * @param mixed $return_url Return URL.
	 * @return mixed
	 */
	public function add_tokenized_cart_return_url_marker( $return_url ) {
		if ( ! is_string( $return_url ) ) {
			return $return_url;
		}

		return add_query_arg( self::RETURN_URL_MARKER, '1', $return_url );
	}

	/**
	 * Maybe reject Store API requests that carry an invalid tokenized product cart session.
	 *
	 * @param mixed $result  Response to replace the requested endpoint response with.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function maybe_reject_invalid_tokenized_cart_session( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( null !== $result || ! WC()->is_store_api_request() ) {
			return $result;
		}

		if ( ! $this->has_valid_session_nonce() ) {
			if ( $this->has_product_session_marker() ) {
				return $this->reject_invalid_tokenized_cart_session( $result, $server, $request );
			}

			$this->address_normalizer->normalize_tokenized_cart_store_api_addresses( $request );

			return $result;
		}

		if ( ! $this->has_valid_incoming_session_token() ) {
			return $this->reject_invalid_tokenized_cart_session( $result, $server, $request );
		}

		$this->address_normalizer->normalize_tokenized_cart_store_api_addresses( $request );

		return $result;
	}

	/**
	 * Reject a Store API request that carries an invalid tokenized product cart session.
	 *
	 * @param mixed           $result  Response to replace the requested endpoint response with.
	 * @param mixed           $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error
	 */
	public function reject_invalid_tokenized_cart_session( $result, $server, WP_REST_Request $request ): WP_Error {
		return new WP_Error(
			'woocommerce_rest_invalid_tokenized_cart_session',
			__( 'Invalid tokenized cart session.', 'woocommerce' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Add the next tokenized session header and clean ephemeral sessions after Store API dispatch.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function handle_store_api_response( $response, $server = null, $request = null ) {
		if ( ! $response instanceof WP_REST_Response || ! WC() || ! WC()->session || ! is_callable( array( WC()->session, 'get_customer_id' ) ) ) {
			return $response;
		}

		$session_id = (string) WC()->session->get_customer_id();
		if ( '' !== $session_id ) {
			$response->header( self::RESPONSE_SESSION_HEADER, $this->create_session_token( $session_id ) );
		}

		if ( self::is_ephemeral_request() && is_callable( array( WC()->session, 'delete_session' ) ) ) {
			WC()->session->delete_session( $session_id );
		}

		return $response;
	}

	/**
	 * Save the real cart data before WooCommerce empties the checkout cart on order-received.
	 */
	public function save_old_cart_data_for_restore(): void {
		if ( ! WC() || ! WC()->cart ) {
			return;
		}

		$this->saved_cart_data = array(
			'cart_contents'              => WC()->cart->get_cart_contents(),
			'removed_cart_contents'      => WC()->cart->get_removed_cart_contents(),
			'applied_coupons'            => WC()->cart->get_applied_coupons(),
			'coupon_discount_totals'     => WC()->cart->get_coupon_discount_totals(),
			'coupon_discount_tax_totals' => WC()->cart->get_coupon_discount_tax_totals(),
			'totals'                     => WC()->cart->get_totals(),
			'session_data'               => $this->get_cart_session_snapshot(),
		);
	}

	/**
	 * Restore the real cart data after WooCommerce empties the checkout cart on order-received.
	 */
	public function restore_old_cart_data(): void {
		if ( null === $this->saved_cart_data || ! WC() || ! WC()->cart ) {
			return;
		}

		WC()->cart->set_cart_contents( $this->saved_cart_data['cart_contents'] ?? array() );
		WC()->cart->set_removed_cart_contents( $this->saved_cart_data['removed_cart_contents'] ?? array() );
		WC()->cart->set_applied_coupons( $this->saved_cart_data['applied_coupons'] ?? array() );
		WC()->cart->set_coupon_discount_totals( $this->saved_cart_data['coupon_discount_totals'] ?? array() );
		WC()->cart->set_coupon_discount_tax_totals( $this->saved_cart_data['coupon_discount_tax_totals'] ?? array() );
		WC()->cart->set_totals( $this->saved_cart_data['totals'] ?? array() );
		$this->restore_cart_session_snapshot( $this->saved_cart_data['session_data'] ?? array() );
		$this->saved_cart_data = null;
	}

	/**
	 * Prevent the tokenized product-page return URL from clearing the shopper's real cart.
	 *
	 * @return bool
	 */
	public function preserve_cart_after_tokenized_payment(): bool {
		return false;
	}

	/**
	 * Snapshot cart-related session state that direct order-received empty-cart calls clear.
	 *
	 * @return array<string,mixed>
	 */
	private function get_cart_session_snapshot(): array {
		if ( ! WC() || ! WC()->session ) {
			return array();
		}

		$session  = WC()->session;
		$snapshot = array();
		if ( is_callable( array( $session, 'get_session_data' ) ) ) {
			foreach ( (array) $session->get_session_data() as $key => $value ) {
				$snapshot[ (string) $key ] = maybe_unserialize( $value );
			}
		}

		foreach ( self::CART_SESSION_KEYS as $key ) {
			if ( is_callable( array( $session, 'get' ) ) ) {
				$value = $session->get( $key, null );
				if ( null !== $value ) {
					$snapshot[ $key ] = $value;
				}
			}
		}

		return $snapshot;
	}

	/**
	 * Restore a cart-related session snapshot.
	 *
	 * @param array<string,mixed> $snapshot Session values to restore.
	 */
	private function restore_cart_session_snapshot( array $snapshot ): void {
		if ( ! WC() || ! WC()->session || ! is_callable( array( WC()->session, 'set' ) ) ) {
			return;
		}

		foreach ( $snapshot as $key => $value ) {
			WC()->session->set( (string) $key, $value );
		}
	}

	/**
	 * Tell whether the product-page session nonce is valid.
	 *
	 * @return bool
	 */
	private function has_valid_session_nonce(): bool {
		$nonce = self::get_server_header( self::SESSION_NONCE_HEADER );

		return '' !== $nonce && function_exists( 'wp_verify_nonce' ) && (bool) \wp_verify_nonce( $nonce, self::SESSION_NONCE_ACTION );
	}

	/**
	 * Tell whether the request carries WooPayments product-page session markers.
	 *
	 * @return bool
	 */
	private function has_product_session_marker(): bool {
		return isset( $_SERVER[ self::SESSION_NONCE_HEADER ] ) ||
			isset( $_SERVER[ self::SESSION_HEADER ] ) ||
			isset( $_SERVER[ self::EPHEMERAL_CART_HEADER ] );
	}

	/**
	 * Tell whether the incoming tokenized session header is empty or valid.
	 *
	 * @return bool
	 */
	private function has_valid_incoming_session_token(): bool {
		$token = self::get_server_header( self::SESSION_HEADER );

		return '' === $token || '' !== WooPaymentsTokenizedCartSessionHandler::get_session_id_from_token( $token );
	}

	/**
	 * Get a sanitized HTTP request header from the server environment.
	 *
	 * @param string $key Server header key.
	 * @return string
	 */
	public static function get_server_header( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return '';
		}

		$value = wc_clean( wp_unslash( (string) $_SERVER[ $key ] ) );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Create a signed token for the current tokenized cart session.
	 *
	 * @param string $session_id Tokenized session ID.
	 * @return string
	 */
	private function create_session_token( string $session_id ): string {
		return JsonWebToken::create(
			array(
				'session_id' => $session_id,
				'exp'        => time() + DAY_IN_SECONDS,
				'iss'        => self::TOKEN_ISSUER,
			),
			WooPaymentsTokenizedCartSessionHandler::get_token_secret()
		);
	}

	/**
	 * Tell whether this Store API request should delete the isolated cart after dispatch.
	 *
	 * @return bool
	 */
	public static function is_ephemeral_request(): bool {
		return '1' === self::get_server_header( self::EPHEMERAL_CART_HEADER );
	}

	/**
	 * Tell whether the current order-received request originated from a tokenized product cart session.
	 *
	 * @return bool
	 */
	private function is_custom_session_order_received_request(): bool {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		return false !== strpos( $request_uri, self::RETURN_URL_MARKER . '=1' );
	}
}
