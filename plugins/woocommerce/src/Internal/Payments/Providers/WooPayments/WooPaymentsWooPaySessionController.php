<?php
/**
 * WooPaymentsWooPaySessionController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Native WooPay session REST and AJAX callbacks.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsWooPaySessionController implements RegisterHooksInterface {

	private const NAMESPACE = 'payments/woopay';

	private const CLASSIC_WOOPAY_SCRIPT_HANDLE = 'wc-woopayments-woopay';

	private const CLASSIC_WOOPAY_STYLE_HANDLE = 'wc-woopayments-woopay';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPay session service.
	 *
	 * @var WooPaymentsWooPaySessionService
	 */
	private WooPaymentsWooPaySessionService $session_service;

	/**
	 * Whether WooPay frontend assets have already been localized and enqueued.
	 *
	 * @var bool
	 */
	private bool $has_enqueued_frontend_assets = false;

	/**
	 * The order-pay params decided for this request, or null before the first decision.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $pay_for_order_params = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter    $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsWooPaySessionService $session_service WooPay session service.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsWooPaySessionService $session_service ): void {
		$this->arbiter         = $arbiter;
		$this->session_service = $session_service;
	}

	/**
	 * Register WooPay REST and AJAX hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		// Inbound WooPay identity hooks stay registered regardless of the enabled/eligibility
		// snapshot — the plugin registers them unconditionally and every WooPay-specific check
		// runs per request inside the callbacks themselves.
		if ( false === has_filter( 'determine_current_user', array( $this->session_service, 'determine_current_user_for_woopay' ) ) ) {
			add_filter( 'determine_current_user', array( $this->session_service, 'determine_current_user_for_woopay' ), 20 );
		}

		if ( false === has_action( 'woocommerce_order_payment_status_changed', array( $this->session_service, 'woopay_order_payment_status_changed' ) ) ) {
			add_action( 'woocommerce_order_payment_status_changed', array( $this->session_service, 'woopay_order_payment_status_changed' ) );
		}

		if ( false === has_action( 'woocommerce_store_api_checkout_order_processed', array( $this->session_service, 'catch_woopay_checkout_errors' ) ) ) {
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this->session_service, 'catch_woopay_checkout_errors' ), 1, 1 );
		}

		if ( false === has_filter( 'automatewoo/referrals/referred_order_advocate', array( $this->session_service, 'automatewoo_refer_a_friend_referral_from_parameter' ) ) ) {
			add_filter( 'automatewoo/referrals/referred_order_advocate', array( $this->session_service, 'automatewoo_refer_a_friend_referral_from_parameter' ) );
		}

		if ( false === has_filter( 'woocommerce_order_needs_payment', array( $this->session_service, 'woopay_trial_subscriptions_handler' ) ) ) {
			add_filter( 'woocommerce_order_needs_payment', array( $this->session_service, 'woopay_trial_subscriptions_handler' ), 20, 3 );
		}

		// After the Store API session handler saves the session on shutdown (priority 20, StoreApi\SessionHandler::init()).
		if ( false === has_action( 'shutdown', array( $this->session_service, 'refresh_woopay_browser_session_cache' ) ) ) {
			add_action( 'shutdown', array( $this->session_service, 'refresh_woopay_browser_session_cache' ), 21 );
		}

		// The session route stays registered whenever native owns the runtime: the plugin
		// registers it unconditionally and answers ineligible or unsigned callers through the
		// permission callback (401), so a disabled/ineligible state must not turn into a 404
		// that makes WooPay's bootstrap fail opaquely.
		if ( false === has_action( 'rest_api_init', array( $this, 'register_routes' ) ) ) {
			add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		}

		// WooCommerce registers this controller while plugins load, before Jetpack can confirm the connection
		// owner, so WooPay would read as disabled. Decide on init instead, like client 11.1.0
		// (class-wc-payments.php:685 on plugins_loaded and :694 on init).
		if ( did_action( 'init' ) ) {
			$this->register_woopay_hooks();
		} elseif ( false === has_action( 'init', array( $this, 'register_woopay_hooks' ) ) ) {
			add_action( 'init', array( $this, 'register_woopay_hooks' ), 15 );
		}
	}

	/**
	 * Register the WooPay AJAX, frontend and checkout hooks when WooPay is enabled.
	 *
	 * @internal
	 */
	public function register_woopay_hooks(): void {
		if ( ! $this->session_service->is_woopay_enabled() ) {
			return;
		}

		foreach ( $this->get_ajax_hooks() as $hook => $callback ) {
			if ( false === has_action( $hook, $callback ) ) {
				add_action( $hook, $callback );
			}
		}

		foreach ( $this->get_frontend_hooks() as $hook => $callback ) {
			if ( false === has_action( $hook, $callback ) ) {
				add_action( $hook, $callback );
			}
		}

		if ( false === has_filter( 'wcpay_metadata_from_order', array( $this, 'maybe_add_woopay_user_metadata' ) ) ) {
			add_filter( 'wcpay_metadata_from_order', array( $this, 'maybe_add_woopay_user_metadata' ), 10, 2 );
		}

		// With WooPay on, the plugin moves the classic billing email into a "Contact
		// information" section above the billing fields so the OTP popover anchors to it.
		if ( false === has_action( 'woocommerce_checkout_billing', array( $this, 'woopay_fields_before_billing_details' ) ) ) {
			add_action( 'woocommerce_checkout_billing', array( $this, 'woopay_fields_before_billing_details' ), -50 );
		}

		if ( false === has_filter( 'woocommerce_form_field_email', array( $this, 'filter_woocommerce_form_field_woopay_email' ) ) ) {
			add_filter( 'woocommerce_form_field_email', array( $this, 'filter_woocommerce_form_field_woopay_email' ), 20, 4 );
		}

		if ( false === has_action( 'woocommerce_checkout_process', array( $this, 'maybe_show_woopay_phone_number_error' ) ) ) {
			add_action( 'woocommerce_checkout_process', array( $this, 'maybe_show_woopay_phone_number_error' ) );
		}

		// Client 11.1.0 hooks the draft-order reuse only while direct checkout is enabled (class-wc-payments.php:1770-1777,
		// class-wc-payments-woopay-direct-checkout.php:40-43).
		if (
			$this->session_service->is_woopay_direct_checkout_enabled() &&
			false === has_filter( 'woocommerce_create_order', array( $this->session_service, 'maybe_use_store_api_draft_order_id' ) )
		) {
			add_filter( 'woocommerce_create_order', array( $this->session_service, 'maybe_use_store_api_draft_order_id' ) );
		}
	}

	/**
	 * Reject a classic checkout that asks to save the shopper in WooPay without a phone number.
	 *
	 * Mirrors the plugin's WC_Payments::maybe_show_woopay_phone_number_error(). The plugin
	 * validates the `no-country-code` half its intl-tel-input field posts; the native save-user
	 * form posts a single `full` number (see set_woopay_phone_session_data()), so that is the
	 * value validated here.
	 */
	public function maybe_show_woopay_phone_number_error(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before firing woocommerce_checkout_process.
		if ( ! isset( $_POST['save_user_in_woopay'] ) || 'true' !== $_POST['save_user_in_woopay'] ) {
			return;
		}

		$phone = isset( $_POST['woopay_user_phone_field']['full'] ) && is_scalar( $_POST['woopay_user_phone_field']['full'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_POST['woopay_user_phone_field']['full'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $phone ) {
			wc_add_notice( '<strong>' . esc_html__( 'Mobile Number', 'woocommerce' ) . '</strong> ' . esc_html__( 'is required to create an WooPay account.', 'woocommerce' ), 'error' );
		}
	}

	/**
	 * Render the WooPay contact section carrying the billing email above the billing fields.
	 *
	 * Mirrors the plugin's WC_Payments::woopay_fields_before_billing_details().
	 */
	public function woopay_fields_before_billing_details(): void {
		$checkout = WC()->checkout();

		echo '<div class="woocommerce-billing-fields" id="contact_details">';
		echo '<h3>' . esc_html__( 'Contact information', 'woocommerce' ) . '</h3>';
		echo '<div class="woocommerce-billing-fields__field-wrapper">';
		woocommerce_form_field(
			'billing_email',
			array(
				'type'        => 'email',
				'label'       => __( 'Email address', 'woocommerce' ),
				'class'       => array( 'form-row-wide woopay-billing-email' ),
				'input_class' => array( 'woopay-billing-email-input' ),
				'validate'    => array( 'email' ),
				'required'    => true,
			),
			$checkout->get_value( 'billing_email' )
		);
		echo '</div>';
		echo '</div>';

		// Block themes with the classic checkout do not load the Blocks stylesheet the
		// email spinner and notice borrow.
		wp_enqueue_style( 'wc-blocks-style' );
	}

	/**
	 * Hide the core billing email field on checkout: the WooPay contact section renders it.
	 *
	 * Mirrors the plugin's WC_Payments::filter_woocommerce_form_field_woopay_email().
	 *
	 * @param string $field         The rendered field markup.
	 * @param string $key           The field key.
	 * @param mixed  $args          Field arguments.
	 * @param mixed  $_unused_value Field value.
	 * @return string
	 */
	public function filter_woocommerce_form_field_woopay_email( $field, $key, $args, $_unused_value ) {
		$class = is_array( $args ) && isset( $args['class'][0] ) ? (string) $args['class'][0] : '';
		if ( false === strpos( $class, 'woopay-billing-email' ) && is_checkout() && ! is_checkout_pay_page() ) {
			return '';
		}

		return $field;
	}

	/**
	 * Register WooPay session routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_session' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'email' => array(
						'type'     => 'string',
						'format'   => 'email',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Check WooPay route permissions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		if ( 'WooPay' !== $request->get_header( 'user_agent' ) ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! $this->session_service->has_valid_request_signature() ) {
			return new WP_Error( 'woocommerce_rest_cannot_view', __( 'Sorry, you cannot list resources.', 'woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Get WooPay session data.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_session( WP_REST_Request $request ) {
		try {
			$email = $request->get_param( 'email' );

			return new WP_REST_Response(
				$this->session_service->get_session_data( is_scalar( $email ) ? sanitize_email( (string) $email ) : null, $request ),
				200
			);
		} catch ( Throwable $exception ) {
			// The exception's message is left out: it can carry the shopper's email or session values.
			wc_get_logger()->error(
				'Unable to assemble WooPay session data.',
				array_merge( WooPaymentsLogger::get_throwable_context( $exception ), array( 'source' => 'woopayments-woopay-session' ) )
			);

			return new WP_Error( 'wcpay_server_error', __( 'Unable to get WooPay session data.', 'woocommerce' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Handle WooPay init AJAX.
	 */
	public function handle_init_woopay(): void {
		if ( ! $this->is_ajax_nonce_valid( 'wcpay_init_woopay_nonce' ) ) {
			wp_send_json( array( 'result' => 'failure' ), 403 );
		}

		wp_send_json( $this->get_init_woopay_response( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle encrypted WooPay session AJAX.
	 */
	public function handle_get_woopay_session(): void {
		if ( ! $this->is_ajax_nonce_valid( 'woopay_session_nonce' ) ) {
			wp_send_json( array( 'result' => 'failure' ), 403 );
		}

		wp_send_json( $this->get_encrypted_session_response( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle WooPay phone-number session AJAX.
	 */
	public function handle_set_woopay_phone_number(): void {
		if ( ! $this->is_ajax_nonce_valid( 'woopay_session_nonce' ) ) {
			wp_send_json( array( 'result' => 'failure' ), 403 );
		}

		wp_send_json( $this->get_phone_session_response( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle WooPay request-signature AJAX.
	 */
	public function handle_get_woopay_signature(): void {
		if ( ! $this->is_ajax_nonce_valid( 'woopay_signature_nonce' ) ) {
			wp_send_json_error( array( 'result' => 'failure' ), 403 );
		}

		wp_send_json_success( $this->get_signature_response( wp_unslash( $_POST ) ), 200 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle encrypted minimum WooPay session AJAX.
	 */
	public function handle_get_woopay_minimum_session_data(): void {
		if ( ! $this->is_ajax_nonce_valid( 'woopay_session_nonce' ) ) {
			wp_send_json( array( 'result' => 'failure' ), 403 );
		}

		wp_send_json( $this->get_minimum_session_response( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Handle admin WooPay appearance persistence AJAX.
	 */
	public function handle_set_admin_woopay_appearance(): void {
		if ( ! $this->is_ajax_nonce_valid( 'wcpay_admin_woopay_appearance_nonce' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'result' => 'failure' ), 403 );
		}

		$this->reject_appearance_write_without_global_theme_support();

		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $this->is_valid_appearance_request( $request ) ) {
			wp_send_json_error( array( 'result' => 'failure' ), 400 );
		}

		$this->save_admin_appearance( $request );

		wp_send_json_success();
	}

	/**
	 * Handle shopper WooPay appearance persistence AJAX.
	 */
	public function handle_set_shopper_woopay_appearance(): void {
		if ( ! $this->is_ajax_nonce_valid( 'woopay_session_nonce' ) ) {
			wp_send_json_error( array( 'result' => 'failure' ), 403 );
		}

		$this->reject_appearance_write_without_global_theme_support();

		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $this->is_valid_appearance_request( $request ) ) {
			wp_send_json_error( array( 'result' => 'failure' ), 400 );
		}

		wp_send_json_success( $this->maybe_save_shopper_appearance( $request ) );
	}

	/**
	 * Enqueue Core-owned WooPay frontend assets on supported shopper surfaces.
	 */
	public function enqueue_frontend_assets(): void {
		if ( $this->has_enqueued_frontend_assets ) {
			return;
		}

		$supported_frontend_surface = $this->is_supported_frontend_surface();
		$direct_checkout_surface    = $this->is_supported_direct_checkout_surface();
		if ( ! $supported_frontend_surface && ! $direct_checkout_surface ) {
			return;
		}

		$direct_checkout_runs = $this->session_service->should_run_woopay_direct_checkout();
		if ( ! $supported_frontend_surface ) {
			if ( ! $direct_checkout_runs ) {
				return;
			}

			// A page that only carries a mini-cart gets the light direct-checkout config, like client 11.1.0
			// (class-wc-payments-woopay-direct-checkout.php:96-116): no button config, no shopper geolocation, no stylesheet.
			if ( ! $this->is_cart_surface() ) {
				$this->enqueue_direct_checkout_assets();
				return;
			}
		}

		$context = $this->get_current_button_context();
		$config  = $this->get_woopay_frontend_config( $context );
		if (
			empty( $config['shouldShowWooPayButton'] ) &&
			! $this->session_service->should_load_woopay_save_user_assets( $context ) &&
			! ( $direct_checkout_surface && $direct_checkout_runs )
		) {
			return;
		}

		// The classic script runs direct checkout on this flag; client 11.1.0 does not load its direct-checkout script for a
		// shopper the guest rule keeps out (class-wc-payments-woopay-direct-checkout.php:92-94).
		$config['isWooPayDirectCheckoutEnabled'] = $direct_checkout_runs;

		$this->register_classic_woopay_assets();
		wp_localize_script( self::CLASSIC_WOOPAY_SCRIPT_HANDLE, 'wcpay_core_woopay_config', $this->get_classic_woopay_config( $config ) );
		wp_enqueue_style( self::CLASSIC_WOOPAY_STYLE_HANDLE );
		wp_enqueue_script( self::CLASSIC_WOOPAY_SCRIPT_HANDLE );
		$this->has_enqueued_frontend_assets = true;
	}

	/**
	 * Enqueue the classic WooPay script with the light direct-checkout config only.
	 */
	private function enqueue_direct_checkout_assets(): void {
		$this->register_classic_woopay_assets();
		wp_localize_script(
			self::CLASSIC_WOOPAY_SCRIPT_HANDLE,
			'wcpay_core_woopay_config',
			array_merge(
				array( 'wcAjaxUrl' => \WC_AJAX::get_endpoint( '%%endpoint%%' ) ),
				$this->session_service->get_woopay_direct_checkout_config()
			)
		);
		wp_enqueue_script( self::CLASSIC_WOOPAY_SCRIPT_HANDLE );
		$this->has_enqueued_frontend_assets = true;
	}

	/**
	 * Get the WooPay button placeholder, which WooPaymentsExpressCheckoutController renders first in the one express
	 * checkout wrapper (client 11.1.0 class-wc-payments-express-checkout-button-display-handler.php:125-152).
	 *
	 * @return string Escaped markup, or an empty string when the WooPay button does not show on this page.
	 */
	public function get_express_checkout_button_html(): string {
		if ( ! $this->is_supported_frontend_surface() || ! $this->session_service->is_woopay_enabled() ) {
			return '';
		}

		$context = $this->get_current_button_context();
		$config  = $this->get_woopay_frontend_config( $context );
		if ( empty( $config['shouldShowWooPayButton'] ) ) {
			return '';
		}

		$settings = is_array( $config['woopayButton'] ?? null ) ? $config['woopayButton'] : array();
		$type     = isset( $settings['type'] ) && is_scalar( $settings['type'] ) ? (string) $settings['type'] : 'default';
		$theme    = isset( $settings['theme'] ) && is_scalar( $settings['theme'] ) ? (string) $settings['theme'] : 'dark';
		$height   = isset( $settings['height'] ) && is_scalar( $settings['height'] ) ? (string) $settings['height'] : '48';
		$radius   = isset( $settings['radius'] ) && is_scalar( $settings['radius'] ) ? (string) $settings['radius'] : '4';

		return '<div id="wcpay-woopay-button" data-product_page="' . esc_attr( 'product' === $context ? '1' : '0' ) . '">' .
			'<div class="woopay-express-button is-placeholder" aria-label="' . esc_attr__( 'WooPay', 'woocommerce' ) . '" data-type="' . esc_attr( $type ) . '" data-theme="' . esc_attr( $theme ) . '" data-size="' . esc_attr( (string) ( $settings['size'] ?? 'default' ) ) . '" style="height: ' . esc_attr( $height ) . 'px; border-radius: ' . esc_attr( $radius ) . 'px"></div>' .
			'</div>';
	}

	/**
	 * Handle WooPay product add-to-cart AJAX.
	 */
	public function handle_add_to_cart(): void {
		check_ajax_referer( 'wcpay-add-to-cart', 'security' );

		if ( ! defined( 'WOOCOMMERCE_CART' ) ) {
			define( 'WOOCOMMERCE_CART', true );
		}

		if ( function_exists( 'WC' ) && WC() ) {
			WC()->shipping()->reset_shipping();
		}

		$request    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$product_id = isset( $request['product_id'] ) ? absint( $request['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json(
				array(
					'error' => array(
						'code'    => 'invalid_product_id',
						'message' => __( 'Invalid product ID.', 'woocommerce' ),
					),
				),
				404
			);
		}

		$quantity     = 1;
		$raw_quantity = $request['quantity'] ?? null;
		if ( is_scalar( $raw_quantity ) ) {
			$clean_quantity = wc_clean( (string) $raw_quantity );
			if ( ! is_string( $clean_quantity ) ) {
				$clean_quantity = '';
			}
			$locale                    = localeconv();
			$decimal_separators        = array_filter(
				array(
					'.',
					wc_get_price_decimal_separator(),
					$locale['decimal_point'],
					$locale['mon_decimal_point'],
				)
			);
			$normalized_quantity       = str_replace( array_unique( $decimal_separators ), '.', $clean_quantity );
			$is_valid_decimal_quantity = 1 === preg_match( '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $normalized_quantity );
			if ( $is_valid_decimal_quantity ) {
				$quantity = wc_stock_amount(
					(float) wc_format_decimal( $normalized_quantity )
				);
				$quantity = $quantity > 0 ? $quantity : 1;
			}
		}

		/**
		 * Filters whether WooCommerce should add the WooPay product to the cart.
		 *
		 * @param bool $passed     Whether validation passed.
		 * @param int  $product_id Product ID.
		 * @param int|float $quantity   Quantity.
		 *
		 * @since 11.0.0
		 */
		if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity ) ) {
			wp_send_json(
				array(
					'error'  => true,
					'submit' => true,
				),
				400
			);
		}

		if ( function_exists( 'WC' ) && WC() && WC()->cart ) {
			WC()->cart->empty_cart();
			$variation_id = isset( $request['variation_id'] ) ? absint( $request['variation_id'] ) : 0;
			$attributes   = $this->get_add_to_cart_attributes( $request );
			$added        = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attributes );
			if ( ! $added ) {
				wp_send_json(
					array(
						'error' => array(
							'code'    => 'add_to_cart_failed',
							'message' => __( 'Unable to add this product to the cart.', 'woocommerce' ),
						),
					),
					400
				);
			}

			WC()->cart->calculate_totals();
		}

		wp_send_json(
			array(
				'result' => 'success',
				'cart'   => array(
					'items_count' => function_exists( 'WC' ) && WC() && WC()->cart ? WC()->cart->get_cart_contents_count() : 0,
					'total'       => function_exists( 'WC' ) && WC() && WC()->cart ? WC()->cart->get_total( '' ) : '',
				),
			)
		);
	}

	/**
	 * Get product variation attributes from a WooPay add-to-cart request.
	 *
	 * @param array<string,mixed> $request Unsigned add-to-cart request data.
	 * @return array<string,mixed>
	 */
	private function get_add_to_cart_attributes( array $request ): array {
		$attributes = array();
		if ( isset( $request['attributes'] ) && is_array( $request['attributes'] ) ) {
			$clean_attributes = wc_clean( $request['attributes'] );
			$attributes       = is_array( $clean_attributes ) ? $clean_attributes : array();
		}

		foreach ( $request as $key => $value ) {
			if ( 0 !== strpos( (string) $key, 'attribute_' ) || is_array( $value ) ) {
				continue;
			}

			$attributes[ sanitize_key( $key ) ] = wc_clean( $value );
		}

		return $attributes;
	}

	/**
	 * Handle WooPay frontend error notices.
	 */
	public function handle_show_error_notice(): void {
		$is_nonce_valid = check_ajax_referer( 'woopay_button_nonce', false, false );

		if ( ! $is_nonce_valid ) {
			wp_send_json_error(
				__( 'You aren’t authorized to do that.', 'woocommerce' ),
				403
			);
		}

		$request = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$message = isset( $request['message'] ) && is_scalar( $request['message'] )
			? sanitize_text_field( (string) $request['message'] )
			: __( 'There was a problem processing the payment.', 'woocommerce' );

		wc_add_notice( $message, 'error' );
		wp_send_json_success(
			array(
				'notice' => wc_print_notices( true ),
			)
		);
	}

	/**
	 * Clear WooPay session data when WooCommerce completes payment.
	 */
	public function handle_woocommerce_payment_complete(): void {
		$this->session_service->clear_woopay_session_data();
	}

	/**
	 * Add WooPay save-user session data to order metadata.
	 *
	 * @param mixed $metadata Metadata.
	 * @param mixed $order    Order object.
	 * @return mixed
	 */
	public function maybe_add_woopay_user_metadata( $metadata, $order ) {
		if ( ! is_array( $metadata ) || ! $order instanceof \WC_Order ) {
			return $metadata;
		}

		return $this->session_service->maybe_add_woopay_user_metadata( $metadata, $order );
	}

	/**
	 * Build the WooPay init response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_init_woopay_response( array $request ): array {
		return $this->session_service->init_woopay_session( $request );
	}

	/**
	 * Build the encrypted WooPay session response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_encrypted_session_response( array $request ): array {
		return $this->session_service->get_encrypted_session_data( $request );
	}

	/**
	 * Build the phone-session response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,string>
	 */
	public function get_phone_session_response( array $request ): array {
		if ( ! empty( $request['empty'] ) ) {
			$this->session_service->clear_woopay_session_data();
		} else {
			$this->session_service->set_woopay_phone_session_data( $request );
		}

		return array( 'result' => 'success' );
	}

	/**
	 * Build the WooPay signature response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,string>
	 */
	public function get_signature_response( array $request ): array {
		unset( $request );

		return array( 'signature' => $this->session_service->get_woopay_request_signature() );
	}

	/**
	 * Build the encrypted minimum session response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array<string,mixed>
	 */
	public function get_minimum_session_response( array $request ): array {
		unset( $request );

		return $this->session_service->get_encrypted_minimum_session_data();
	}

	/**
	 * Store the appearance an admin posted, replacing any stored one.
	 *
	 * @param array<string,mixed> $request Request data.
	 */
	private function save_admin_appearance( array $request ): void {
		$payload = $this->get_appearance_payload( $request );

		$this->session_service->save_woopay_appearance( $payload['appearance'], $payload['font_rules'] );
	}

	/**
	 * Store the appearance a shopper page posted when none is stored for the current styles version.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array{stored:bool} Whether it was stored.
	 */
	private function maybe_save_shopper_appearance( array $request ): array {
		$payload = $this->get_appearance_payload( $request );

		return array(
			'stored' => $this->session_service->maybe_save_woopay_appearance( $payload['appearance'], $payload['font_rules'] ),
		);
	}

	/**
	 * Answer an appearance write with 403 while WooPay global theme support is off, as client 11.1.0 does
	 * (class-woopay-session.php:1219-1224, :1273-1278), so nothing fills the slot WooPay would serve once it is turned on.
	 */
	private function reject_appearance_write_without_global_theme_support(): void {
		if ( ! $this->session_service->is_woopay_global_theme_support_enabled() ) {
			wp_send_json_error( __( 'This action is not available.', 'woocommerce' ), 403 );
		}
	}

	/**
	 * Check an AJAX nonce without dying.
	 *
	 * @param string $action Nonce action.
	 * @return bool
	 */
	private function is_ajax_nonce_valid( string $action ): bool {
		return (bool) check_ajax_referer( $action, false, false );
	}

	/**
	 * Check whether an appearance request carries a valid WooPay appearance payload.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return bool
	 */
	private function is_valid_appearance_request( array $request ): bool {
		return isset( $request['appearance'] ) &&
			is_array( $request['appearance'] ) &&
			$this->session_service->validate_appearance_schema( $request['appearance'] );
	}

	/**
	 * Get appearance payload data from a request.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array{appearance:array<string,mixed>,font_rules:array<int,array<string,string>>}
	 */
	private function get_appearance_payload( array $request ): array {
		$appearance = isset( $request['appearance'] ) && is_array( $request['appearance'] )
			? $request['appearance']
			: array();

		$font_rules = array();
		if ( isset( $request['font_rules'] ) ) {
			$raw_font_rules = $request['font_rules'];
			if ( is_string( $raw_font_rules ) ) {
				$decoded        = json_decode( $raw_font_rules, true );
				$raw_font_rules = is_array( $decoded ) ? $decoded : array();
			}

			if ( is_array( $raw_font_rules ) ) {
				$font_rules = $this->session_service->sanitize_woopay_font_rules( $raw_font_rules );
			}
		}

		return array(
			'appearance' => $appearance,
			'font_rules' => $font_rules,
		);
	}

	/**
	 * Get WooPay AJAX hooks and callbacks.
	 *
	 * @return array<string,callable>
	 */
	private function get_ajax_hooks(): array {
		return array(
			'wc_ajax_wcpay_init_woopay'                   => array( $this, 'handle_init_woopay' ),
			'wc_ajax_wcpay_get_woopay_session'            => array( $this, 'handle_get_woopay_session' ),
			'wc_ajax_wcpay_set_woopay_phone_number'       => array( $this, 'handle_set_woopay_phone_number' ),
			'wc_ajax_wcpay_get_woopay_signature'          => array( $this, 'handle_get_woopay_signature' ),
			'wc_ajax_wcpay_get_woopay_minimum_session_data' => array( $this, 'handle_get_woopay_minimum_session_data' ),
			'wp_ajax_wcpay_admin_set_woopay_appearance'   => array( $this, 'handle_set_admin_woopay_appearance' ),
			'wc_ajax_wcpay_shopper_set_woopay_appearance' => array( $this, 'handle_set_shopper_woopay_appearance' ),
			'wc_ajax_wcpay_add_to_cart'                   => array( $this, 'handle_add_to_cart' ),
			'wp_ajax_woopay_express_checkout_button_show_error_notice' => array( $this, 'handle_show_error_notice' ),
			'wp_ajax_nopriv_woopay_express_checkout_button_show_error_notice' => array( $this, 'handle_show_error_notice' ),
		);
	}

	/**
	 * Get WooPay frontend hooks and callbacks.
	 *
	 * @return array<string,callable>
	 */
	private function get_frontend_hooks(): array {
		return array(
			'wp_enqueue_scripts'           => array( $this, 'enqueue_frontend_assets' ),
			'wp_footer'                    => array( $this, 'enqueue_frontend_assets' ),
			'woocommerce_payment_complete' => array( $this, 'handle_woocommerce_payment_complete' ),
		);
	}

	/**
	 * Register the classic WooPay assets; this controller is their only registrar.
	 */
	private function register_classic_woopay_assets(): void {
		if ( ! wp_script_is( self::CLASSIC_WOOPAY_SCRIPT_HANDLE, 'registered' ) ) {
			$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
			wp_register_script(
				self::CLASSIC_WOOPAY_SCRIPT_HANDLE,
				WC()->plugin_url() . '/assets/js/frontend/woopayments-woopay' . $suffix . '.js',
				array( 'jquery', 'wp-i18n' ),
				WC_VERSION,
				true
			);
			wp_set_script_translations( self::CLASSIC_WOOPAY_SCRIPT_HANDLE, 'woocommerce' );
		}

		if ( ! wp_style_is( self::CLASSIC_WOOPAY_STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::CLASSIC_WOOPAY_STYLE_HANDLE,
				WC()->plugin_url() . '/assets/css/woopayments-woopay.css',
				array(),
				WC_VERSION
			);
			wp_style_add_data( self::CLASSIC_WOOPAY_STYLE_HANDLE, 'rtl', 'replace' );
		}
	}

	/**
	 * Get the WooPay frontend config for a context; on an order's pay page it carries the order, its key and billing email.
	 *
	 * WooPay then pays that order (client 11.1.0 class-wc-payments-express-checkout-button-display-handler.php:184-222). Without
	 * them the button stays off, so an order's pay page never starts a cart session. The billing email is left empty on a page
	 * that a page cache could serve to another visitor (WooPaymentsOrderPayAccess::may_put_shopper_email_in_page()). See
	 * get_pay_for_order_params().
	 *
	 * @param string $context WooPay button context.
	 * @return array<string,mixed>
	 */
	private function get_woopay_frontend_config( string $context ): array {
		$config = $this->session_service->get_woopay_frontend_config( $context );
		if ( 'pay_for_order' !== $context ) {
			return $config;
		}

		$pay_for_order_params = $this->get_pay_for_order_params();
		if ( array() === $pay_for_order_params ) {
			$config['shouldShowWooPayButton'] = false;

			return $config;
		}

		return array_merge( $config, $pay_for_order_params );
	}

	/**
	 * Get the order-pay params for this request, decided once so the button rendered in the pay form follows the config.
	 *
	 * Empty unless the pay link lets the visitor pay the order and the Store API order the WooPay session preloads states
	 * the order's total (WooPaymentsOrderPayAccess::store_api_states_order_total()). Multi-currency switches the active
	 * currency to the order's only inside the pay form (before_woocommerce_pay), after the config is built at
	 * wp_enqueue_scripts; the session request sees the shopper's.
	 *
	 * @return array<string,mixed>
	 */
	private function get_pay_for_order_params(): array {
		if ( null !== $this->pay_for_order_params ) {
			return $this->pay_for_order_params;
		}

		$params = WooPaymentsOrderPayAccess::get_pay_for_order_page_params();
		$order  = array() === $params ? false : wc_get_order( $params['order_id'] );
		if ( ! $order instanceof \WC_Order || ! WooPaymentsOrderPayAccess::store_api_states_order_total( $order ) ) {
			$params = array();
		} elseif ( ! WooPaymentsOrderPayAccess::may_put_shopper_email_in_page() ) {
			$params['billing_email'] = '';
		}

		$this->pay_for_order_params = $params;

		return $params;
	}

	/**
	 * Get localized classic WooPay config.
	 *
	 * @param array<string,mixed> $config WooPay frontend config.
	 * @return array<string,mixed>
	 */
	private function get_classic_woopay_config( array $config ): array {
		return array_merge(
			array(
				'wcAjaxUrl'                => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
				'confirmationErrorMessage' => __( 'There was a problem processing the payment. Please try again.', 'woocommerce' ),
				// The save-user source URL is the checkout page, as client 11.1.0 checkout-page-save-user.js:118-124 sends
				// wcSettings.storePages.checkout.permalink: a full browser URL can carry query strings and exceed the
				// 500-character limit of Stripe metadata.
				'woopaySourceUrl'          => wc_get_checkout_url(),
			),
			$config,
			$this->session_service->get_save_user_checkout_data()
		);
	}

	/**
	 * Tell whether the current request is a supported shopper frontend surface.
	 *
	 * @return bool
	 */
	private function is_supported_frontend_surface(): bool {
		// Core renders the classic checkout form on order-pay even under a Checkout block page (Blocks Checkout::render()).
		// Client 11.1.0 loads no WooPay on the change-payment page (class-wc-payments-woopay-button-handler.php:124-126).
		if ( $this->is_order_pay_surface() ) {
			return ! isset( $_GET['change_payment_method'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page flag.
		}

		if ( $this->is_block_cart_or_checkout_surface() ) {
			return false;
		}

		return ( function_exists( 'is_checkout' ) && is_checkout() ) ||
			( function_exists( 'is_cart' ) && is_cart() ) ||
			( function_exists( 'is_product' ) && is_product() ) ||
			$this->is_product_page_shortcode_surface();
	}

	/**
	 * Tell whether the current request supports WooPay direct checkout.
	 *
	 * Client 11.1.0 `should_enqueue_scripts()` (class-wc-payments-woopay-direct-checkout.php:130-134): a cart page, any page
	 * where a Mini-Cart block rendered (the checkout page too), or a page other than checkout with the classic cart widget.
	 *
	 * @return bool
	 */
	private function is_supported_direct_checkout_surface(): bool {
		if ( $this->is_cart_surface() || 0 < did_action( 'woocommerce_blocks_cart_enqueue_data' ) ) {
			return true;
		}

		return wp_script_is( 'wc-cart-fragments', 'enqueued' ) &&
			! ( ( function_exists( 'is_checkout' ) && is_checkout() ) || $this->current_surface_has_block( 'woocommerce/checkout' ) );
	}

	/**
	 * Tell whether the current request is a classic or Blocks cart page.
	 *
	 * @return bool
	 */
	private function is_cart_surface(): bool {
		return ( function_exists( 'is_cart' ) && is_cart() ) || $this->current_surface_has_block( 'woocommerce/cart' );
	}

	/**
	 * Tell whether the current request renders a Blocks cart or checkout page.
	 *
	 * @return bool
	 */
	private function is_block_cart_or_checkout_surface(): bool {
		return $this->current_surface_has_block( 'woocommerce/cart' ) || $this->current_surface_has_block( 'woocommerce/checkout' );
	}

	/**
	 * Tell whether the current request contains a given block.
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	private function current_surface_has_block( string $block_name ): bool {
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

		return has_block( $block_name, $post );
	}

	/**
	 * Get the current WooPay button context.
	 *
	 * @return string
	 */
	private function get_current_button_context(): string {
		// Order-pay first, so a product shortcode on the checkout page cannot make the order's button a product button. Client
		// 11.1.0 get_button_context() checks the product first (class-wc-payments-express-checkout-button-helper.php:450-468)
		// but adds the order to its config whatever the context (express checkout display handler :184-222).
		if ( $this->is_order_pay_surface() ) {
			return 'pay_for_order';
		}

		if ( ( function_exists( 'is_product' ) && is_product() ) || $this->is_product_page_shortcode_surface() ) {
			return 'product';
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}

		return function_exists( 'is_cart' ) && is_cart() ? 'cart' : 'checkout';
	}

	/**
	 * Tell whether the current request is the checkout page's order-pay endpoint.
	 *
	 * @return bool
	 */
	private function is_order_pay_surface(): bool {
		return function_exists( 'is_checkout' ) && is_checkout() && is_wc_endpoint_url( 'order-pay' );
	}

	/**
	 * Tell whether the current post contains a product_page shortcode.
	 *
	 * @return bool
	 */
	private function is_product_page_shortcode_surface(): bool {
		$post = get_post();

		return $post instanceof \WP_Post && has_shortcode( $post->post_content, 'product_page' );
	}
}
