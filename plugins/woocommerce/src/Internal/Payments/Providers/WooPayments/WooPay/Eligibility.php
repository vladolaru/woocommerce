<?php
/**
 * Eligibility class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressPaymentMethodTypes;

/**
 * Decides whether WooPay applies to the store, the page and the shopper: whether WooPay is on, whether its express
 * button shows in a context, whether direct checkout runs, whether the save-user fields load, and whether card checkout
 * initializes Stripe through the platform account because WooPay applies.
 *
 * Client 11.1.0 keeps these rules in `WC_Payments_Features::is_woopay_eligible()` (includes/class-wc-payments-features.php:193-209),
 * `WooPay_Utilities` (includes/woopay/class-woopay-utilities.php:34-62) and `WC_Payments_WooPay_Button_Handler`
 * (includes/class-wc-payments-woopay-button-handler.php:94-102, :300-320).
 *
 * @since 11.2.0
 * @internal
 */
class Eligibility {

	/**
	 * Countries WooPay is available in, synced from the platform by ExtensionSync under the option name
	 * the WooPayments extension also writes.
	 */
	private const AVAILABLE_COUNTRIES_OPTION = 'woocommerce_woocommerce_payments_woopay_available_countries';
	/**
	 * Default WooPay country list when the platform has not synced one.
	 */
	private const AVAILABLE_COUNTRIES_DEFAULT = '["US"]';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Tell whether WooPay is on: the WooPay setting is on and the account is eligible, as client 11.1.0 decides
	 * (`WooPay_Session::is_woopay_enabled()`, includes/woopay/class-woopay-session.php:883-890, and the WooPay hooks,
	 * includes/class-wc-payments.php:1711-1715). An account still finishing onboarding counts.
	 *
	 * @return bool
	 */
	public function is_woopay_enabled(): bool {
		return $this->is_woopay_setting_on() && $this->is_woopay_eligible();
	}

	/**
	 * Tell whether WooPay and its express button are enabled, as the `wcpay_woopay_enabled` filter answers.
	 *
	 * Client 11.1.0 `WC_Payments_WooPay_Button_Handler::is_woopay_enabled()` (class-wc-payments-woopay-button-handler.php:94-102).
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_woopay_button_enabled(): bool {
		/**
		 * Allows third parties to programmatically show or hide the WooPay button.
		 *
		 * @since 9.5.0
		 *
		 * @param bool $is_woopay_enabled Whether WooPay and its express button are enabled.
		 */
		return (bool) apply_filters(
			'wcpay_woopay_enabled',
			$this->is_woopay_enabled() && '1' === get_option( '_wcpay_feature_woopay_express_checkout', '1' )
		);
	}

	/**
	 * Tell whether the WooPay email-input hooks (user lookup + OTP prompt) should run on checkout.
	 *
	 * This does not affect the appearance of the email input, only whether the
	 * email-exists check and the OTP auto-redirection are wired up.
	 *
	 * @return bool
	 */
	public function is_woopay_email_input_enabled(): bool {
		/**
		 * Filters whether the WooPay email input hooks should be enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether the WooPay email input behaviour is enabled.
		 */
		return (bool) apply_filters( 'wcpay_is_woopay_email_input_enabled', true );
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
	 * Applies `wcpay_woopay_enabled` too, so on a page that also builds the button config through is_woopay_button_enabled()
	 * the filter runs twice.
	 * Client 11.1.0 also applies it in two places on a page: `is_woopay_enabled()` (`includes/class-wc-payments-woopay-button-handler.php:101`)
	 * runs from its `init()` (`:120`) and from `should_show_woopay_button()` (`:253`).
	 *
	 * @param string $context            Express checkout context.
	 * @param bool   $is_woopay_enabled Whether WooPay is globally enabled.
	 * @return bool
	 */
	public function should_show_woopay_button_for_enabled_state( string $context, bool $is_woopay_enabled ): bool {
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
		if ( ! in_array( $context, array( 'product', 'cart', 'checkout', 'pay_for_order' ), true ) ) {
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
	 * Client 11.1.0 loads them only while WooPayments is an available gateway, before the visitor's country is checked
	 * (includes/woopay-user/class-woopay-save-user.php:49-57).
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_load_woopay_save_user_assets( string $context = 'checkout' ): bool {
		return 'checkout' === $this->get_button_location( $context ) &&
			$this->is_woopay_enabled() &&
			$this->is_woopay_gateway_available() &&
			$this->is_woopay_country_available() &&
			( $this->account_service->is_network_saved_cards_enabled() || $this->should_use_stripe_platform_on_checkout_page() );
	}

	/**
	 * Tell whether the base WooPayments gateway is available for checkout.
	 *
	 * @return bool
	 */
	public function is_woopay_gateway_available(): bool {
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();

		return isset( $available_gateways['woocommerce_payments'] );
	}

	/**
	 * Tell whether WooPay direct checkout is enabled for this store.
	 *
	 * Reads the gateway's enabled setting rather than resolving gateway availability, like client 11.1.0
	 * `WC_Payments_Features::is_woopay_direct_checkout_enabled()`: its caller on `init` at priority 15, the draft-order
	 * hook, runs too early to resolve the available gateways.
	 * should_run_woopay_direct_checkout() adds gateway availability, at page render.
	 *
	 * @return bool
	 */
	public function is_woopay_direct_checkout_enabled(): bool {
		$account_data = $this->account_service->get_cached_account_data();

		return ! empty( $account_data['platform_direct_checkout_eligible'] )
			&& '1' === get_option( '_wcpay_feature_woopay_direct_checkout', '1' )
			&& $this->account_service->is_gateway_enabled()
			&& $this->is_woopay_enabled();
	}

	/**
	 * Tell whether WooPay direct checkout runs for the current shopper.
	 *
	 * The store-level check and the guest rule come first, as client 11.1.0 loads its script
	 * (class-wc-payments-woopay-direct-checkout.php:87-94). Unlike client 11.1.0 (class-wc-payments-features.php:237-243),
	 * WooPayments must also be an available gateway, so a cart never sends the shopper to WooPay without a WooPayments
	 * method; availability is read last, at page render.
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function should_run_woopay_direct_checkout(): bool {
		return $this->is_woopay_direct_checkout_enabled() &&
			$this->should_enable_woopay_on_guest_checkout() &&
			$this->is_woopay_gateway_available();
	}

	/**
	 * Tell whether WooPay applies to the current shopper's guest status, like client 11.1.0
	 * `WooPay_Utilities::should_enable_woopay_on_guest_checkout()` (class-woopay-utilities.php:46-62).
	 *
	 * @return bool False for a logged-out shopper with a subscription in the cart, or when guest checkout is off.
	 */
	public function should_enable_woopay_on_guest_checkout(): bool {
		if ( is_user_logged_in() ) {
			return true;
		}

		return ! $this->woopay_cart_contains_subscription() && $this->is_woopay_guest_checkout_enabled();
	}

	/**
	 * Tell whether WooPay is available in the visitor's country, geolocated from the IP address; always true in test mode.
	 *
	 * @return bool
	 */
	public function is_woopay_country_available(): bool {
		if ( $this->account_service->is_test_mode_enabled() ) {
			return true;
		}

		$location_data = \WC_Geolocation::geolocate_ip();

		return in_array( $location_data['country'] ?? '', $this->get_persisted_available_countries(), true );
	}

	/**
	 * Get the platform-synced list of WooPay-available countries.
	 *
	 * @return array<int,string>
	 */
	private function get_persisted_available_countries(): array {
		$available_countries = json_decode( (string) get_option( self::AVAILABLE_COUNTRIES_OPTION, self::AVAILABLE_COUNTRIES_DEFAULT ), true );

		if ( ! is_array( $available_countries ) ) {
			return json_decode( self::AVAILABLE_COUNTRIES_DEFAULT, true );
		}

		return $available_countries;
	}

	/**
	 * Tell whether WooPay express checkout is configured for a context.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function is_woopay_express_checkout_configured_at( string $context ): bool {

		$setting_key = 'express_checkout_' . $this->get_button_location( $context ) . '_methods';
		$methods     = $this->account_service->get_gateway_setting( $setting_key );

		if ( is_array( $methods ) ) {
			return in_array( 'woopay', $methods, true );
		}

		return $this->is_woopay_setting_on();
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

		$product = wc_get_product();

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
	public function woopay_cart_contains_subscription(): bool {
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
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function is_woopay_global_theme_support_enabled(): bool {
		$account_data = $this->account_service->get_cached_account_data();

		return ! empty( $account_data['platform_global_theme_support_enabled'] ) &&
			$this->account_service->is_gateway_setting_enabled( 'is_woopay_global_theme_support_enabled' );
	}

	/**
	 * Tell whether card checkout initializes Stripe through the platform account because WooPay applies, as client
	 * 11.1.0 `should_use_stripe_platform_on_checkout_page()` does (class-wc-payment-gateway-wcpay.php:1173-1191). It forces
	 * network saved cards in both the card and the WooPay config and hides the classic save checkbox; the visitor's
	 * country only decides whether the save-user fields load (class-woopay-save-user.php:55).
	 *
	 * @since 11.2.0
	 *
	 * @return bool
	 */
	public function should_use_stripe_platform_on_checkout_page(): bool {
		if ( ! $this->is_woopay_eligible() || ! $this->is_woopay_setting_on() ) {
			return false;
		}

		if ( ! is_checkout() && ! has_block( 'woocommerce/checkout' ) ) {
			return false;
		}

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			return false;
		}

		return WC()->cart instanceof \WC_Cart &&
			! WC()->cart->is_empty() &&
			WC()->cart->needs_payment();
	}

	/**
	 * Tell whether the WooPay gateway setting is exactly "yes", as client 11.1.0 reads it at every site
	 * (includes/woopay/class-woopay-session.php:889, includes/class-wc-payment-gateway-wcpay.php:1180).
	 *
	 * @return bool
	 */
	private function is_woopay_setting_on(): bool {
		return 'yes' === $this->account_service->get_gateway_setting( 'platform_checkout', 'no' );
	}

	/**
	 * Tell whether the account is eligible for WooPay, like client 11.1.0 `WC_Payments_Features::is_woopay_eligible()`
	 * (class-wc-payments-features.php:193-209).
	 *
	 * @return bool
	 */
	public function is_woopay_eligible(): bool {
		$account_service = $this->account_service;
		$account_data    = $account_service->get_cached_account_data();

		return ! empty( $account_data['platform_checkout_eligible'] )
			&& ! $account_service->is_account_rejected()
			&& ! $account_service->is_account_under_review();
	}

	/**
	 * Tell whether the platform keeps this store on WooPay's signed path, where a blog-token signature still authenticates
	 * WooPay's requests. Read from the stored account, however old and with no refresh, so a caller cannot choose the
	 * credential, as client 11.2.0 reads it (`WC_Payments_Features::is_woopay_force_signed_requests_enabled()`,
	 * includes/class-wc-payments-features.php:226-233); a missing flag counts as off.
	 *
	 * @return bool
	 */
	public function is_woopay_force_signed_requests_enabled(): bool {
		$account_data = $this->account_service->get_stored_account_data();

		return ! empty( $account_data['platform_woopay_force_signed_requests'] );
	}

	/**
	 * Get the express checkout location whose settings apply to a context.
	 *
	 * The order-pay page is a checkout page for the location settings, as in client 11.1.0, where is_checkout() holds there
	 * (class-wc-payments-woopay-button-handler.php:273, class-wc-payments-express-checkout-button-helper.php:284-286).
	 *
	 * @param string $context Context.
	 * @return string
	 */
	private function get_button_location( string $context ): string {
		$context = WooPaymentsExpressPaymentMethodTypes::normalize_context( $context );

		return 'pay_for_order' === $context ? 'checkout' : $context;
	}
}
