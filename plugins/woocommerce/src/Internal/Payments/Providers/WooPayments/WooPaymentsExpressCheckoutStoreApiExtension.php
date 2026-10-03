<?php
/**
 * WooPaymentsExpressCheckoutStoreApiExtension class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;

/**
 * Native WooPayments express checkout Store API cart extension.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsExpressCheckoutStoreApiExtension implements RegisterHooksInterface {

	/**
	 * Countries whose state is optional for express checkout Store API requests. Wallets send no state for
	 * these countries; the list matches `Express_Checkout_Element_States::COUNTRIES_WITHOUT_STATES` in
	 * WooPayments 11.1.0.
	 */
	private const COUNTRIES_WITHOUT_STATES = array( 'DZ', 'AO', 'BD', 'BJ', 'BO', 'BG', 'HR', 'DO', 'GH', 'GT', 'HU', 'KE', 'LA', 'LR', 'LT', 'MD', 'NA', 'NP', 'PK', 'PY', 'RO', 'SA', 'ZA', 'TZ', 'UG', 'ZM' );

	/**
	 * Store API cart update namespace the express checkout frontend calls to refresh the Cart and Checkout blocks.
	 */
	public const REFRESH_UI_NAMESPACE = 'woopayments/express-checkout/refresh-ui';

	private const TOKENIZED_CART_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART';

	private const TOKENIZED_CART_NONCE_HEADER = 'HTTP_X_WOOPAYMENTS_TOKENIZED_CART_NONCE';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Express checkout service.
	 *
	 * @var WooPaymentsExpressCheckoutService
	 */
	private WooPaymentsExpressCheckoutService $express_checkout_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter      $arbiter                  Runtime owner arbiter.
	 * @param WooPaymentsExpressCheckoutService $express_checkout_service Express checkout service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsExpressCheckoutService $express_checkout_service ): void {
		$this->arbiter                  = $arbiter;
		$this->express_checkout_service = $express_checkout_service;
	}

	/**
	 * Register Store API extension hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		if ( false === has_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extension' ) ) ) {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extension' ) );
		}

		if ( false === has_filter( 'woocommerce_get_country_locale', array( $this, 'modify_country_locale_for_express_checkout' ) ) ) {
			add_filter( 'woocommerce_get_country_locale', array( $this, 'modify_country_locale_for_express_checkout' ), 20 );
		}

		if ( false === has_action( 'init', array( $this, 'register_refresh_ui_update_callback' ) ) ) {
			add_action( 'init', array( $this, 'register_refresh_ui_update_callback' ), 15 );
		}
	}

	/**
	 * Register the no-op Store API cart update callback the express checkout frontend calls to refresh the blocks UI.
	 */
	public function register_refresh_ui_update_callback(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) || ! $this->is_express_checkout_hooks_request() ) {
			return;
		}

		if ( ! $this->express_checkout_service->is_express_checkout_available() ) {
			return;
		}

		woocommerce_store_api_register_update_callback(
			array(
				'namespace' => self::REFRESH_UI_NAMESPACE,
				'callback'  => '__return_null',
			)
		);
	}

	/**
	 * Make the state optional for countries without states on express checkout Store API requests.
	 *
	 * @param mixed $locales Country locale settings.
	 * @return mixed
	 */
	public function modify_country_locale_for_express_checkout( $locales ) {
		if ( ! is_array( $locales ) || ! $this->is_express_checkout_request() ) {
			return $locales;
		}

		foreach ( self::COUNTRIES_WITHOUT_STATES as $country_code ) {
			if ( isset( $locales[ $country_code ] ) && ! is_array( $locales[ $country_code ] ) ) {
				continue;
			}

			$locales[ $country_code ]['state']['required'] = false;
		}

		return $locales;
	}

	/**
	 * Tell whether the current request is an express checkout (tokenized cart) Store API request.
	 *
	 * @return bool
	 */
	private function is_express_checkout_request(): bool {
		if ( ! $this->is_express_checkout_hooks_request() || ! WooPaymentsStoreApiRequestUtils::is_store_api_request() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- The null coalescing guards the read.
		if ( 'true' !== sanitize_text_field( wp_unslash( $_SERVER[ self::TOKENIZED_CART_HEADER ] ?? '' ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- The null coalescing guards the read.
		$nonce = sanitize_text_field( wp_unslash( $_SERVER[ self::TOKENIZED_CART_NONCE_HEADER ] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, WooPaymentsTokenizedCartSessionController::TOKENIZED_CART_NONCE_ACTION ) ) {
			return false;
		}

		return $this->express_checkout_service->is_express_checkout_available();
	}

	/**
	 * Tell whether the request is one WooPayments loads its express checkout hooks on: not cron, XML-RPC,
	 * or the change payment method page.
	 *
	 * @return bool
	 */
	private function is_express_checkout_hooks_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag check, mirroring the plugin.
		return ! wp_doing_cron() && ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) && ! isset( $_GET['change_payment_method'] );
	}

	/**
	 * Register WooPayments cart extension data with the Store API.
	 */
	public function register_store_api_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( CartSchema::class ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CartSchema::IDENTIFIER,
				'namespace'       => 'wcpay',
				'data_callback'   => array( $this, 'get_cart_extension_data' ),
				'schema_callback' => array( $this, 'get_cart_extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Get WooPayments cart extension data.
	 *
	 * @return array<string,array<int,string>>
	 */
	public function get_cart_extension_data(): array {
		return array(
			'express_checkout_methods' => $this->get_location_blind_methods_for_current_currency(),
		);
	}

	/**
	 * Get the Apple Pay/Google Pay and Amazon Pay methods available for the current cart currency.
	 *
	 * Matches WooPayments 11.1.0: only `payment_request` and `amazon_pay`, without cart-location gating. The
	 * frontend intersects this currency-fresh list with the localized current-location methods before mounting wallets.
	 *
	 * @return array<int,string>
	 */
	private function get_location_blind_methods_for_current_currency(): array {
		$methods = array();

		if ( $this->express_checkout_service->is_payment_request_enabled() ) {
			$methods[] = WooPaymentsExpressPaymentMethodTypes::EXPRESS_METHOD_PAYMENT_REQUEST;
		}

		if ( $this->express_checkout_service->can_use_amazon_pay( (string) get_woocommerce_currency() ) ) {
			$methods[] = WooPaymentsExpressPaymentMethodTypes::EXPRESS_METHOD_AMAZON_PAY;
		}

		return $methods;
	}

	/**
	 * Get WooPayments cart extension schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_cart_extension_schema(): array {
		return array(
			'express_checkout_methods' => array(
				'description' => __( 'Express Checkout methods available for the cart\'s current currency.', 'woocommerce' ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'items'       => array(
					'type' => 'string',
				),
			),
		);
	}
}
