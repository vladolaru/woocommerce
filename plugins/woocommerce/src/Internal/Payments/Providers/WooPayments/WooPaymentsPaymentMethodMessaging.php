<?php
/**
 * WooPaymentsPaymentMethodMessaging class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\Jetpack\Constants;
use WC_AJAX;
use WC_Product;

/**
 * Native WooPayments BNPL payment method messaging callbacks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPaymentMethodMessaging implements RegisterHooksInterface {

	private const SCRIPT_HANDLE = 'wc-woopayments-payment-method-messaging';

	private const CART_BLOCK_SCRIPT_HANDLE = 'wc-woopayments-cart-block-payment-method-messaging';

	private const STRIPE_SCRIPT_HANDLE = 'stripe';

	private const STRIPE_SCRIPT_URL = 'https://js.stripe.com/v3/';

	private const APPEARANCE_SCRIPT_HANDLE = 'wc-woopayments-appearance';

	private const STYLE_HANDLE = 'wc-woopayments-payment-method-messaging';

	private const CART_BLOCK_STYLE_HANDLE = 'wc-woopayments-cart-block-payment-method-messaging';

	private const BNPL_CAPABILITY = 'buy_now_pay_later';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPayments payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * WooPayments order data service.
	 *
	 * @var WooPaymentsOrderDataService
	 */
	private WooPaymentsOrderDataService $order_data_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter     $arbiter                 Runtime owner arbiter.
	 * @param WooPaymentsAccountService        $account_service         WooPayments account service.
	 * @param WooPaymentsPaymentMethodRegistry $payment_method_registry WooPayments payment method registry.
	 * @param WooPaymentsOrderDataService      $order_data_service      WooPayments order data service.
	 */
	final public function init(
		NativePaymentsRuntimeArbiter $arbiter,
		WooPaymentsAccountService $account_service,
		WooPaymentsPaymentMethodRegistry $payment_method_registry,
		WooPaymentsOrderDataService $order_data_service
	): void {
		$this->arbiter                 = $arbiter;
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry;
		$this->order_data_service      = $order_data_service;
	}

	/**
	 * Register BNPL payment method messaging hooks.
	 */
	public function register() {
		if (
			! $this->arbiter->should_native_register()
			|| ! $this->account_service->is_gateway_enabled()
			|| ! $this->account_service->can_process_payments()
			|| empty( $this->get_active_bnpl_payment_method_ids() )
		) {
			return;
		}

		if ( false === has_action( 'woocommerce_single_product_summary', array( $this, 'render_site_messaging' ) ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_site_messaging' ) );
		}

		if ( false === has_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_site_messaging' ) ) ) {
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_site_messaging' ), 5 );
		}

		if ( false === has_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'render_site_messaging' ) ) ) {
			add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', array( $this, 'render_site_messaging' ) );
		}

		if ( false === has_action( 'wc_ajax_wcpay_get_cart_total', array( $this, 'handle_get_cart_total' ) ) ) {
			add_action( 'wc_ajax_wcpay_get_cart_total', array( $this, 'handle_get_cart_total' ) );
		}

		if ( false === has_action( 'wc_ajax_wcpay_check_bnpl_availability', array( $this, 'handle_check_bnpl_availability' ) ) ) {
			add_action( 'wc_ajax_wcpay_check_bnpl_availability', array( $this, 'handle_check_bnpl_availability' ) );
		}
	}

	/**
	 * Render the BNPL messaging placeholder and enqueue its config.
	 *
	 * @internal
	 */
	public function render_site_messaging(): void {
		if ( ! $this->is_supported_surface() ) {
			return;
		}

		$is_cart_block = $this->is_cart_block_surface();
		$this->enqueue_site_messaging_config( $is_cart_block );

		if ( ! $is_cart_block ) {
			echo '<div id="payment-method-message"></div>';
		}
	}

	/**
	 * Handle the preserved cart-total AJAX callback.
	 *
	 * @internal
	 */
	public function handle_get_cart_total(): void {
		check_ajax_referer( 'wcpay-get-cart-total', 'security' );

		wp_send_json( $this->get_cart_total_response() );
	}

	/**
	 * Handle the preserved BNPL availability AJAX callback.
	 *
	 * @internal
	 */
	public function handle_check_bnpl_availability(): void {
		check_ajax_referer( 'wcpay-is-bnpl-available', 'security' );

		$response = $this->get_bnpl_availability_response( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		wp_send_json_success( $response['data'] );
	}

	/**
	 * Get the BNPL availability response from native amount limits.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array{success:bool,data:array{is_available:bool}}
	 */
	public function get_bnpl_availability_response( array $request ): array {
		$price    = isset( $request['price'] ) && is_scalar( $request['price'] ) ? (float) $request['price'] : 0.0;
		$currency = isset( $request['currency'] ) && is_scalar( $request['currency'] ) ? strtoupper( sanitize_text_field( (string) $request['currency'] ) ) : '';
		$country  = isset( $request['country'] ) && is_scalar( $request['country'] ) ? strtoupper( sanitize_text_field( (string) $request['country'] ) ) : '';

		return array(
			'success' => true,
			'data'    => array(
				'is_available' => $this->is_any_bnpl_method_available( $this->get_active_bnpl_payment_method_ids(), $country, $currency, $price ),
			),
		);
	}

	/**
	 * Get the cart total AJAX response.
	 *
	 * @return array{total:int}
	 */
	public function get_cart_total_response(): array {
		return array(
			'total' => $this->get_cart_total(),
		);
	}

	/**
	 * Enqueue the BNPL messaging config.
	 *
	 * @param bool $is_cart_block Whether the current surface is the cart block.
	 */
	private function enqueue_site_messaging_config( bool $is_cart_block ): void {
		$this->register_site_messaging_assets( $is_cart_block );

		$script_handle = $is_cart_block ? self::CART_BLOCK_SCRIPT_HANDLE : self::SCRIPT_HANDLE;
		$style_handle  = $is_cart_block ? self::CART_BLOCK_STYLE_HANDLE : self::STYLE_HANDLE;

		wp_localize_script(
			$script_handle,
			'wcpayStripeSiteMessaging',
			$this->get_site_messaging_config( $is_cart_block )
		);

		wp_enqueue_script( $script_handle );
		wp_enqueue_style( $style_handle );
	}

	/**
	 * Get BNPL messaging frontend config.
	 *
	 * @param bool $is_cart_block Whether the current surface is the cart block.
	 * @return array<string,mixed>
	 */
	private function get_site_messaging_config( bool $is_cart_block ): array {
		$product            = $this->get_current_product();
		$currency_code      = get_woocommerce_currency();
		$country            = $this->get_customer_or_store_country();
		$product_variations = array();
		$product_price      = 0;
		$is_product_surface = $product instanceof WC_Product || ( function_exists( 'is_product' ) && is_product() );

		if ( $product instanceof WC_Product ) {
			$product_variations = $this->get_product_variations( $product, $currency_code );
			$product_price      = (int) ( $product_variations['base_product']['amount'] ?? 0 );
		}

		$payment_methods = $this->get_active_bnpl_payment_method_ids();

		$config = array(
			'productId'            => 'base_product',
			'productVariations'    => $product_variations,
			'country'              => $country,
			'locale'               => $this->convert_to_stripe_locale( get_locale() ),
			'accountId'            => $this->account_service->get_account_id(),
			'publishableKey'       => $this->account_service->get_publishable_key(),
			'paymentMethods'       => array_values( $payment_methods ),
			'currencyCode'         => $currency_code,
			'isCart'               => (bool) ( ! $is_product_surface && function_exists( 'is_cart' ) && is_cart() ),
			'isCartBlock'          => $is_cart_block,
			'cartTotal'            => $this->get_cart_total(),
			'nonce'                => array(
				'get_cart_total'    => wp_create_nonce( 'wcpay-get-cart-total' ),
				'is_bnpl_available' => wp_create_nonce( 'wcpay-is-bnpl-available' ),
			),
			'wcAjaxUrl'            => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'shouldInitializePMME' => $this->is_any_bnpl_supporting_country( $payment_methods, $country, $currency_code ),
			'stylesCacheVersion'   => (string) get_option( 'woocommerce_woopayments_styles_cache_version', '0' ),
		);

		if ( $product instanceof WC_Product ) {
			$config['shouldShowPMME'] = $this->is_any_bnpl_method_available( $payment_methods, $country, $currency_code, $product_price );
		}

		return $config;
	}

	/**
	 * Register the script handles required for BNPL messaging config.
	 *
	 * @param bool $is_cart_block Whether the current surface is the cart block.
	 */
	private function register_site_messaging_assets( bool $is_cart_block ): void {
		if ( ! wp_script_is( self::STRIPE_SCRIPT_HANDLE, 'registered' ) ) {
			// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_register_script( self::STRIPE_SCRIPT_HANDLE, self::STRIPE_SCRIPT_URL, array(), null, true );
		}

		if ( $is_cart_block ) {
			$this->register_cart_block_assets();
			return;
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';

		if ( ! wp_script_is( self::APPEARANCE_SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::APPEARANCE_SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/js/frontend/utils/woopayments-appearance' . $suffix . '.js',
				array(),
				WC_VERSION,
				true
			);
		}

		if ( ! wp_script_is( self::SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/js/frontend/woopayments-payment-method-messaging' . $suffix . '.js',
				array( 'jquery', self::STRIPE_SCRIPT_HANDLE, self::APPEARANCE_SCRIPT_HANDLE ),
				WC_VERSION,
				true
			);
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				WC()->plugin_url() . '/assets/css/woopayments-payment-method-messaging.css',
				array(),
				WC_VERSION
			);
			wp_style_add_data( self::STYLE_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Register the Blocks cart BNPL messaging slotfill assets.
	 */
	private function register_cart_block_assets(): void {
		if ( ! wp_script_is( self::CART_BLOCK_SCRIPT_HANDLE, 'registered' ) ) {
			wp_register_script(
				self::CART_BLOCK_SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/client/blocks/wc-woopayments-cart-block-payment-method-messaging.js',
				array(
					self::STRIPE_SCRIPT_HANDLE,
					'react-jsx-runtime',
					'wc-blocks-checkout',
					'wp-data',
					'wp-element',
					'wp-plugins',
					'wp-polyfill',
				),
				WC_VERSION,
				true
			);
		}

		if ( ! wp_style_is( self::CART_BLOCK_STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::CART_BLOCK_STYLE_HANDLE,
				WC()->plugin_url() . '/assets/client/blocks/wc-woopayments-cart-block-payment-method-messaging.css',
				array(),
				WC_VERSION
			);
			wp_style_add_data( self::CART_BLOCK_STYLE_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Tell whether the current request can show BNPL messaging.
	 *
	 * @return bool
	 */
	private function is_supported_surface(): bool {
		return ( function_exists( 'is_product' ) && is_product() )
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| $this->is_cart_block_surface();
	}

	/**
	 * Tell whether the current hook is the cart-block messaging surface.
	 *
	 * @return bool
	 */
	private function is_cart_block_surface(): bool {
		return 'woocommerce_blocks_enqueue_cart_block_scripts_after' === current_filter();
	}

	/**
	 * Get the current product.
	 *
	 * @return WC_Product|null
	 */
	private function get_current_product(): ?WC_Product {
		$product = $GLOBALS['product'] ?? null;

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Get product and variation amounts in provider minor units.
	 *
	 * @param WC_Product $product  Product object.
	 * @param string     $currency Currency code.
	 * @return array<array-key,array{amount:int,currency:string}>
	 */
	private function get_product_variations( WC_Product $product, string $currency ): array {
		$product_variations = array(
			'base_product' => array(
				'amount'   => $this->order_data_service->prepare_amount( $this->get_product_price( $product ), $currency ),
				'currency' => $currency,
			),
		);

		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product ) {
				$product_variations[ (string) $variation_id ] = array(
					'amount'   => $this->order_data_service->prepare_amount( $this->get_product_price( $variation ), $currency ),
					'currency' => $currency,
				);
			}
		}

		return $product_variations;
	}

	/**
	 * Get a product price matching the shop tax display context.
	 *
	 * @param WC_Product $product Product object.
	 * @return float
	 */
	private function get_product_price( WC_Product $product ): float {
		if ( wc_tax_enabled() && $product->is_taxable() ) {
			if (
				wc_prices_include_tax()
				&& ( 'incl' !== get_option( 'woocommerce_tax_display_shop' ) || ( WC()->customer && WC()->customer->get_is_vat_exempt() ) )
			) {
				return (float) wc_get_price_excluding_tax( $product );
			}

			if ( 'incl' === get_option( 'woocommerce_tax_display_shop' ) && ! ( WC()->customer && WC()->customer->get_is_vat_exempt() ) ) {
				return (float) wc_get_price_including_tax( $product );
			}
		}

		return (float) $product->get_price();
	}

	/**
	 * Get active enabled BNPL payment method IDs.
	 *
	 * @return string[]
	 */
	private function get_active_bnpl_payment_method_ids(): array {
		$enabled_payment_methods = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids', array( 'card' ) );
		if ( ! is_array( $enabled_payment_methods ) ) {
			return array();
		}

		$enabled_payment_methods = array_map( 'strval', $enabled_payment_methods );
		$active_bnpl_methods     = array();

		foreach ( $enabled_payment_methods as $payment_method_id ) {
			$definition = $this->payment_method_registry->get( $payment_method_id );
			if ( ! $definition instanceof WooPaymentsPaymentMethodDefinition ) {
				continue;
			}

			if ( ! in_array( self::BNPL_CAPABILITY, $definition->get_capabilities(), true ) ) {
				continue;
			}

			if ( ! $this->is_capability_active( $definition->get_stripe_id() ) ) {
				continue;
			}

			$active_bnpl_methods[] = $definition->get_id();
		}

		return array_values( array_unique( $active_bnpl_methods ) );
	}

	/**
	 * Tell whether the cached account capability is active.
	 *
	 * @param string $capability_key Stripe capability key.
	 * @return bool
	 */
	private function is_capability_active( string $capability_key ): bool {
		$account_data = $this->account_service->get_cached_account_data();
		$capabilities = isset( $account_data['capabilities'] ) && is_array( $account_data['capabilities'] ) ? $account_data['capabilities'] : array();
		$status       = $capabilities[ $capability_key ] ?? null;

		if ( is_array( $status ) ) {
			$status = $status['status'] ?? null;
		}

		return 'active' === $status;
	}

	/**
	 * Tell whether any enabled BNPL method supports the country/currency pair.
	 *
	 * @param string[] $enabled_methods Enabled BNPL method IDs.
	 * @param string   $country         Country code.
	 * @param string   $currency        Currency code.
	 * @return bool
	 */
	private function is_any_bnpl_supporting_country( array $enabled_methods, string $country, string $currency ): bool {
		foreach ( $enabled_methods as $method ) {
			$definition = $this->payment_method_registry->get( $method );
			if ( ! $definition instanceof WooPaymentsPaymentMethodDefinition ) {
				continue;
			}

			$limits = $definition->get_limits_per_currency();
			if ( isset( $limits[ strtoupper( $currency ) ][ strtoupper( $country ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether any enabled BNPL method supports the requested amount.
	 *
	 * @param string[] $enabled_methods Enabled BNPL method IDs.
	 * @param string   $country         Country code.
	 * @param string   $currency        Currency code.
	 * @param float    $price           Price in provider minor units.
	 * @return bool
	 */
	private function is_any_bnpl_method_available( array $enabled_methods, string $country, string $currency, float $price ): bool {
		foreach ( $enabled_methods as $method ) {
			$definition = $this->payment_method_registry->get( $method );
			if ( ! $definition instanceof WooPaymentsPaymentMethodDefinition ) {
				continue;
			}

			$minimum_amount = $definition->get_minimum_amount( $currency, $country );
			$maximum_amount = $definition->get_maximum_amount( $currency, $country );
			if ( null !== $minimum_amount && null !== $maximum_amount && $price >= $minimum_amount && $price <= $maximum_amount ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the customer billing country or store base country.
	 *
	 * @return string
	 */
	private function get_customer_or_store_country(): string {
		$billing_country = WC()->customer ? WC()->customer->get_billing_country() : '';

		if ( '' !== $billing_country ) {
			return strtoupper( $billing_country );
		}

		if ( WC()->countries ) {
			return strtoupper( WC()->countries->get_base_country() );
		}

		$default_country = (string) get_option( 'woocommerce_default_country', '' );

		$country = strtok( $default_country, ':' );

		return strtoupper( is_string( $country ) ? $country : '' );
	}

	/**
	 * Get the current cart total in provider minor units.
	 *
	 * @return int
	 */
	private function get_cart_total(): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		return $this->order_data_service->prepare_amount( (float) WC()->cart->get_total( 'edit' ), get_woocommerce_currency() );
	}

	/**
	 * Convert WordPress locale to the closest Stripe.js locale.
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	private function convert_to_stripe_locale( string $locale ): string {
		$supported_locales = array(
			'ar',
			'bg',
			'cs',
			'da',
			'de',
			'el',
			'en',
			'en-GB',
			'es',
			'es-419',
			'et',
			'fi',
			'fil',
			'fr',
			'fr-CA',
			'he',
			'hr',
			'hu',
			'id',
			'it',
			'ja',
			'ko',
			'lt',
			'lv',
			'ms',
			'mt',
			'nb',
			'nl',
			'pl',
			'pt',
			'pt-BR',
			'ro',
			'ru',
			'sk',
			'sl',
			'sv',
			'th',
			'tr',
			'vi',
			'zh',
			'zh-HK',
			'zh-TW',
		);

		$locale = str_replace( '_', '-', $locale );
		if ( in_array( $locale, $supported_locales, true ) ) {
			return $locale;
		}

		$language = strtok( $locale, '-' );

		return is_string( $language ) && in_array( $language, $supported_locales, true ) ? $language : 'auto';
	}
}
