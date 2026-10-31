<?php
/**
 * WooPaymentsFrontendTrackingController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;
use WC_Tracks;
use WC_Tracks_Client;
use WC_Tracks_Event;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;

/**
 * Records the WooPayments shopper Tracks events: the checkout funnel page views on every store, the WooPay events while
 * WooPay is on, and the events the checkout scripts send to the `wc/v3/payments/tracks` route or the `platform_tracks`
 * AJAX action.
 *
 * It also counts a checkout order paid with another gateway on the WordPress.com stats pixel.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsFrontendTrackingController implements RegisterHooksInterface {

	private const USER_EVENT_PREFIX = 'wcpay';

	/**
	 * Button locations the WooPay and express checkout buttons report as `source`.
	 */
	private const BUTTON_SOURCES = array( 'product', 'cart', 'checkout', 'pay_for_order' );

	/**
	 * Shopper events the browser may record, without the `wcpay_` prefix.
	 *
	 * Each declares whether it is recorded on every store or only while WooPay is on (client PR 6870 and PR 8821 for
	 * the page views; the Apple Pay and Google Pay events get theirs from the `wcpay_tracks_event_properties` callback,
	 * client PR 9793) and its properties: `bool`, `string`, or the list of allowed values. Anything else is dropped.
	 */
	private const SHOPPER_EVENTS = array(
		'checkout_page_view'                         => array(
			'all_stores' => true,
			'properties' => array(
				'theme_type'     => array( 'short_code', 'blocks' ),
				'woopay_enabled' => 'bool',
			),
		),
		'cart_page_view'                             => array(
			'all_stores' => true,
			'properties' => array( 'theme_type' => array( 'short_code', 'blocks' ) ),
		),
		'product_page_view'                          => array(
			'all_stores' => true,
			'properties' => array( 'theme_type' => array( 'short_code', 'blocks' ) ),
		),
		'pay_for_order_page_view'                    => array(
			'all_stores' => true,
			'properties' => array(),
		),
		'order_success_page_view'                    => array(
			'all_stores' => true,
			'properties' => array(),
		),
		'checkout_place_order_button_click'          => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'proceed_to_checkout_button_click'           => array(
			'all_stores' => false,
			'properties' => array( 'woopay_direct_checkout' => 'bool' ),
		),
		'checkout_email_address_woopay_check'        => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'checkout_woopay_save_my_info_offered'       => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'checkout_save_my_info_click'                => array(
			'all_stores' => false,
			'properties' => array( 'status' => array( 'checked', 'unchecked' ) ),
		),
		'checkout_woopay_save_my_info_mobile_enter'  => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'checkout_save_my_info_tos_click'            => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'checkout_save_my_info_privacy_policy_click' => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'woopay_skipped'                             => array(
			'all_stores' => false,
			'properties' => array(),
		),
		'woopay_button_load'                         => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
		'woopay_button_click'                        => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
		'applepay_button_load'                       => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
		'applepay_button_click'                      => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
		'gpay_button_load'                           => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
		'gpay_button_click'                          => array(
			'all_stores' => false,
			'properties' => array( 'source' => self::BUTTON_SOURCES ),
		),
	);

	/**
	 * Names the browser may still send for a declared event, mapped to the event.
	 *
	 * Client 11.1.0 sends `wcpay_proceed_to_checkout_button_click` and records it with a second `wcpay_` prefix; the
	 * single-prefixed name is recorded here, and a page cached while the WooPayments extension ran still sends the old one.
	 */
	private const SHOPPER_EVENT_ALIASES = array(
		'wcpay_proceed_to_checkout_button_click' => 'proceed_to_checkout_button_click',
	);

	/**
	 * Longest string property value the recorder keeps.
	 */
	private const MAX_PROPERTY_LENGTH = 100;

	private const FRONTEND_EVENTS_SCRIPT_HANDLE = 'wc-woopayments-frontend-tracks';

	/**
	 * REST namespace of the shopper Tracks route, the provider's.
	 */
	private const REST_NAMESPACE = 'wc/v3';

	/**
	 * Shopper Tracks REST route.
	 */
	private const REST_ROUTE = '/payments/tracks';

	/**
	 * WordPress.com stats pixel that counts a checkout order paid with another gateway, with no visitor data
	 * (client 11.1.0 `includes/class-woopay-tracker.php:50,557-561`).
	 */
	private const OTHER_GATEWAY_ORDER_COUNTER_URL = 'https://pixel.wp.com/g.gif?v=wpcom-no-pv&x_wcpay_order_completed_gateway=other';

	/**
	 * Page-view events queued during render for the footer script to send.
	 *
	 * @var array<int,array{event:string,properties:array<string,mixed>}>
	 */
	private array $frontend_events = array();

	/**
	 * Cart "Proceed to checkout" click tracking for the footer script, or null when not armed.
	 *
	 * @var array{woopayDirectCheckout:bool}|null
	 */
	private ?array $proceed_to_checkout = null;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * WooPay eligibility rules, looked up when a funnel event first needs them.
	 *
	 * @var Eligibility|null
	 */
	private ?Eligibility $woopay_eligibility = null;

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
	 * Register the shopper Tracks AJAX actions, REST route and checkout funnel hooks.
	 */
	public function register() {
		foreach ( $this->get_ajax_hooks() as $hook => $callback ) {
			add_action( $hook, $callback );
		}

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'woocommerce_after_checkout_form', array( $this, 'record_classic_checkout_page_view' ) );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', array( $this, 'record_blocks_checkout_page_view' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_checkout_order_placed' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'record_checkout_order_placed' ), 10, 2 );
		foreach ( array( 'woocommerce_after_cart', 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'woocommerce_after_single_product', 'before_woocommerce_pay_form', 'woocommerce_payments_save_user_in_woopay' ) as $hook ) {
			add_action( $hook, array( $this, 'record_shopper_funnel_event' ), 10, 0 );
		}
	}

	/**
	 * Record the client 11.1.0 WooPay_Tracker shopper funnel event for the current hook (`class-woopay-tracker.php:73-81,496-532,608`).
	 *
	 * Page views are queued for the footer script; the WooPay sign-up records at once.
	 * Cart pages also arm the footer script's "Proceed to checkout" click tracking, the client's `client/cart/index.js`.
	 *
	 * @internal
	 */
	public function record_shopper_funnel_event(): void {
		try {
			switch ( current_action() ) {
				case 'woocommerce_after_cart':
					$this->queue_user_event( 'cart_page_view', array( 'theme_type' => 'short_code' ) );
					$this->track_proceed_to_checkout_clicks( fn(): bool => $this->get_woopay_eligibility()->is_woopay_direct_checkout_enabled() );
					break;
				case 'woocommerce_blocks_enqueue_cart_block_scripts_after':
					$this->queue_user_event( 'cart_page_view', array( 'theme_type' => 'blocks' ) );
					$this->track_proceed_to_checkout_clicks( fn(): bool => $this->get_woopay_eligibility()->is_woopay_direct_checkout_enabled() );
					break;
				case 'woocommerce_after_single_product':
					$this->queue_user_event( 'product_page_view', array( 'theme_type' => 'short_code' ) );
					break;
				case 'before_woocommerce_pay_form':
					$this->queue_user_event( 'pay_for_order_page_view' );
					break;
				case 'woocommerce_payments_save_user_in_woopay':
					$this->record_user_event( 'woopay_registered', array( 'source' => 'checkout' ) );
					break;
			}
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt storefront rendering or checkout.
		}
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
			$this->queue_user_event(
				'checkout_page_view',
				array(
					'theme_type'     => $theme_type,
					'woopay_enabled' => $this->get_woopay_eligibility()->is_woopay_enabled(),
				)
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt checkout rendering.
		}
	}

	/**
	 * Record that checkout created an order before payment processing begins: a Tracks event for a WooPayments order,
	 * the stats counter for an order paid with another gateway (client 11.1.0 `includes/class-woopay-tracker.php:581-602`).
	 *
	 * @internal
	 * @param int|\WC_Order $order          Order ID or Store API order object.
	 * @param mixed         $_checkout_data Optional classic checkout data; unused.
	 */
	public function record_checkout_order_placed( $order, $_checkout_data = null ): void {
		$order = wc_get_order( $order );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( 0 !== strpos( $order->get_payment_method(), 'woocommerce_payments' ) ) {
			$this->send_other_gateway_order_counter();
			return;
		}

		$is_woopay_order = isset( $_SERVER['HTTP_USER_AGENT'] ) && 'WooPay' === $_SERVER['HTTP_USER_AGENT'];
		if ( $is_woopay_order ) {
			return;
		}

		try {
			$this->record_user_event(
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
	 * Register the shopper Tracks REST route the checkout scripts post to; the `platform_tracks` AJAX action takes the same
	 * requests.
	 *
	 * @internal
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest_tracks' ),
				'permission_callback' => array( $this, 'check_rest_tracks_nonce' ),
				'args'                => array(
					'tracksNonce'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'tracksEventName' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array_merge( array_keys( self::SHOPPER_EVENTS ), array_keys( self::SHOPPER_EVENT_ALIASES ) ),
					),
					'tracksEventProp' => array(
						'type'    => 'string',
						'default' => '{}',
					),
				),
			)
		);
	}

	/**
	 * Get the shopper Tracks REST route URL the checkout scripts post to.
	 *
	 * @return string
	 */
	public function get_tracks_rest_url(): string {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Check the platform Tracks nonce of a REST request, as the AJAX action does.
	 *
	 * @internal
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function check_rest_tracks_nonce( WP_REST_Request $request ) {
		$nonce = $request->get_param( 'tracksNonce' );
		if ( is_string( $nonce ) && false !== wp_verify_nonce( $nonce, 'platform_tracks_nonce' ) ) {
			return true;
		}

		return new WP_Error( 'rest_forbidden', __( 'You aren’t authorized to do that.', 'woocommerce' ), array( 'status' => 403 ) );
	}

	/**
	 * Record a shopper event posted to the REST route.
	 *
	 * @internal
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function handle_rest_tracks( WP_REST_Request $request ): WP_REST_Response {
		$response = $this->record_tracks_request( $request->get_params() );

		return new WP_REST_Response( $response['data'], $response['status_code'] );
	}

	/**
	 * Answer the `platform_tracks` AJAX action, which records a shopper event.
	 */
	public function handle_tracks(): void {
		$response = $this->record_tracks_request( wp_unslash( $_REQUEST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $response['success'] ) {
			wp_send_json_success( $response['data'], $response['status_code'] );
		}

		wp_send_json_error( $response['data'], $response['status_code'] );
	}

	/**
	 * Answer the `get_identity` AJAX action with the shopper's Tracks identity.
	 */
	public function handle_tracks_identity(): void {
		wp_send_json_success( $this->get_or_create_tracks_identity_for_current_user() );
	}

	/**
	 * Record a shopper event the browser posted, after checking its nonce and declared schema, and return the response.
	 *
	 * @param array<string,mixed> $request Request data.
	 * @return array{success:bool,status_code:int,data:mixed}
	 */
	public function record_tracks_request( array $request ): array {
		if (
			empty( $request['tracksNonce'] )
			|| ! is_scalar( $request['tracksNonce'] )
			|| ! wp_verify_nonce( (string) $request['tracksNonce'], 'platform_tracks_nonce' )
		) {
			return $this->error_response( __( 'You aren’t authorized to do that.', 'woocommerce' ), 403 );
		}

		if ( empty( $request['tracksEventName'] ) || ! is_scalar( $request['tracksEventName'] ) ) {
			return $this->error_response( __( 'No valid event name or type.', 'woocommerce' ), 403 );
		}

		$event_name = sanitize_text_field( (string) $request['tracksEventName'] );
		$event_name = self::SHOPPER_EVENT_ALIASES[ $event_name ] ?? $event_name;
		if ( ! isset( self::SHOPPER_EVENTS[ $event_name ] ) ) {
			return $this->error_response( __( 'No valid event name or type.', 'woocommerce' ), 400 );
		}

		$properties = array();
		if ( isset( $request['tracksEventProp'] ) && is_scalar( $request['tracksEventProp'] ) ) {
			$encoded_properties = wc_clean( (string) $request['tracksEventProp'] );
			if ( is_string( $encoded_properties ) ) {
				$decoded = json_decode( $encoded_properties, true );
				if ( is_array( $decoded ) ) {
					$properties = $decoded;
				}
			}
		}

		$event      = self::SHOPPER_EVENTS[ $event_name ];
		$properties = $this->get_declared_properties( $event['properties'], $properties );
		if ( $event['all_stores'] ) {
			$properties['record_event_data'] = array( 'track_on_all_stores' => true );
		}

		$this->record_user_event( $event_name, $properties );

		return array(
			'success'     => true,
			'status_code' => 200,
			'data'        => array(),
		);
	}

	/**
	 * Keep the properties an event declares, coerced to their declared type and length.
	 *
	 * @param array<string,string|string[]> $declared   Declared properties: `bool`, `string`, or the allowed values.
	 * @param array<string,mixed>           $properties Properties the browser sent.
	 * @return array<string,mixed>
	 */
	private function get_declared_properties( array $declared, array $properties ): array {
		$kept = array();
		foreach ( $declared as $key => $type ) {
			if ( ! isset( $properties[ $key ] ) || ! is_scalar( $properties[ $key ] ) ) {
				continue;
			}

			if ( 'bool' === $type ) {
				$value = filter_var( $properties[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null !== $value ) {
					$kept[ $key ] = $value;
				}
				continue;
			}

			$value = substr( sanitize_text_field( (string) $properties[ $key ] ), 0, self::MAX_PROPERTY_LENGTH );
			if ( 'string' === $type || ( is_array( $type ) && in_array( $value, $type, true ) ) ) {
				$kept[ $key ] = $value;
			}
		}

		return $kept;
	}

	/**
	 * Tell whether a shopper event recorded on every store may be recorded for this request: the store allows tracking,
	 * and the visitor has not opted out, is not loading a wp-admin page and is not an administrator.
	 *
	 * @return bool
	 */
	public function is_shopper_event_tracking_enabled(): bool {
		return $this->is_store_tracking_enabled() && ! $this->has_tracking_opt_out_cookie() && $this->is_tracked_shopper_request();
	}

	/**
	 * Tell whether a WooPay event may be recorded for this request: a shopper event while WooPay is on and the account
	 * is eligible for it.
	 *
	 * @return bool
	 */
	public function is_woopay_event_tracking_enabled(): bool {
		return $this->is_shopper_event_tracking_enabled() && $this->is_woopay_tracking_enabled();
	}

	/**
	 * Tell whether a WooPay admin event may be recorded: the store allows tracking and the visitor has not opted out.
	 *
	 * @return bool
	 */
	private function is_admin_event_tracking_enabled(): bool {
		return $this->is_store_tracking_enabled() && ! $this->has_tracking_opt_out_cookie();
	}

	/**
	 * Tell whether the visitor opted out of Tracks with the `tk_opt-out` cookie.
	 *
	 * @return bool
	 */
	private function has_tracking_opt_out_cookie(): bool {
		return ! empty( $_COOKIE['tk_opt-out'] );
	}

	/**
	 * Tell whether the request is a shopper's: not a wp-admin page load (AJAX counts) and not a logged-in administrator.
	 *
	 * @return bool
	 */
	private function is_tracked_shopper_request(): bool {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}

		$user = wp_get_current_user();

		return ! ( is_user_logged_in() && in_array( 'administrator', $user->roles, true ) );
	}

	/**
	 * Queue a shopper page view for the footer script to record.
	 *
	 * Nothing is recorded during render, so no Tracks identity cookie or outbound request touches a cacheable page.
	 * Only store-wide checks run here; the Tracks route's recorder applies the per-visitor ones. Page views are recorded on every
	 * store (client PR 6870, PR 8821), so WooPay is not checked here, unlike client 11.1.0 since PR 11199.
	 *
	 * @param string              $event_name Event name without the wcpay_ prefix.
	 * @param array<string,mixed> $properties Event properties.
	 */
	public function queue_user_event( string $event_name, array $properties = array() ): void {
		if ( ! $this->is_store_tracking_enabled() ) {
			return;
		}

		$this->frontend_events[] = array(
			'event'      => $event_name,
			'properties' => $properties,
		);

		$this->add_frontend_events_script_hook();
	}

	/**
	 * Have the footer script record cart "Proceed to checkout" clicks, like client 11.1.0's cart script (`client/cart/index.js`).
	 *
	 * Armed only when the Tracks route's recorder would keep the event: the store-wide checks and WooPay, since the event is not
	 * recorded on every store and the recorder drops it while WooPay is off.
	 *
	 * @param callable $is_woopay_direct_checkout_enabled Returns whether WooPay direct checkout is enabled; called only once armed.
	 */
	public function track_proceed_to_checkout_clicks( callable $is_woopay_direct_checkout_enabled ): void {
		if ( ! $this->is_store_tracking_enabled() || ! $this->is_woopay_tracking_enabled() ) {
			return;
		}

		$this->proceed_to_checkout = array( 'woopayDirectCheckout' => (bool) $is_woopay_direct_checkout_enabled() );
		$this->add_frontend_events_script_hook();
	}

	/**
	 * Print the footer script once, at wp_footer.
	 */
	private function add_frontend_events_script_hook(): void {
		if ( false === has_action( 'wp_footer', array( $this, 'enqueue_frontend_events_script' ) ) ) {
			add_action( 'wp_footer', array( $this, 'enqueue_frontend_events_script' ) );
		}
	}

	/**
	 * Enqueue the footer script that posts the queued page views to the shopper Tracks REST route.
	 *
	 * @internal
	 */
	public function enqueue_frontend_events_script(): void {
		if ( empty( $this->frontend_events ) && null === $this->proceed_to_checkout ) {
			return;
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		wp_enqueue_script(
			self::FRONTEND_EVENTS_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/js/frontend/woopayments-frontend-tracks' . $suffix . '.js',
			array(),
			WC_VERSION,
			true
		);
		wp_localize_script(
			self::FRONTEND_EVENTS_SCRIPT_HANDLE,
			'wc_woopayments_frontend_tracks_params',
			array(
				'nonce'             => wp_create_nonce( 'platform_tracks_nonce' ),
				'tracksUrl'         => $this->get_tracks_rest_url(),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'events'            => $this->frontend_events,
				'proceedToCheckout' => $this->proceed_to_checkout,
			)
		);
		$this->frontend_events     = array();
		$this->proceed_to_checkout = null;
	}

	/**
	 * Record a WooPayments shopper event.
	 *
	 * @param string              $event_name Event name without the wcpay_ prefix.
	 * @param array<string,mixed> $properties Event properties.
	 * @return bool|\WP_Error
	 */
	public function record_user_event( string $event_name, array $properties = array() ) {
		return $this->record_prefixed_event( self::USER_EVENT_PREFIX . '_' . $event_name, $properties );
	}

	/**
	 * Record a WooPay admin event under the WooPayments store tracking checks.
	 *
	 * The WooPay settings events (enabled, disabled, global theme support, express button locations) go through this
	 * recorder, not `WC_Tracks::record_event()`, so they keep the WooPayments store checks, including the US-only store
	 * check and the opt-out cookie. An admin event does not need WooPay to be on (client 11.1.0
	 * `includes/class-woopay-tracker.php:254-266`).
	 *
	 * @param string              $event_name Event name without the wcadmin_ prefix.
	 * @param array<string,mixed> $properties Event properties.
	 * @return bool|\WP_Error
	 */
	public function record_woopay_admin_event( string $event_name, array $properties = array() ) {
		$properties['record_event_data'] = array( 'is_admin_event' => true );

		return $this->record_prefixed_event( 'wcadmin_' . $event_name, $properties );
	}

	/**
	 * Record an already-prefixed event through core's `WC_Tracks_Event::record()` transport.
	 *
	 * @param string              $event_name Full event name.
	 * @param array<string,mixed> $properties Event properties.
	 * @return bool|\WP_Error
	 */
	private function record_prefixed_event( string $event_name, array $properties ) {

		/**
		 * Filters WooPayments Tracks event properties.
		 *
		 * @since 11.0.0
		 *
		 * @param array<string,mixed> $properties Event properties.
		 * @param string              $event_name Event name.
		 */
		$properties = apply_filters( 'wcpay_tracks_event_properties', $properties, $event_name );
		if ( ! is_array( $properties ) ) {
			// Client 11.1.0 `class-woopay-tracker.php:400` merges `(array) $properties`.
			$properties = (array) $properties;
		}

		$is_admin_event  = false;
		$is_woopay_event = true;
		if ( isset( $properties['record_event_data'] ) && is_array( $properties['record_event_data'] ) ) {
			$is_admin_event  = ! empty( $properties['record_event_data']['is_admin_event'] );
			$is_woopay_event = empty( $properties['record_event_data']['track_on_all_stores'] );
			unset( $properties['record_event_data'] );
		}

		if ( $is_admin_event ) {
			$is_tracking_enabled = $this->is_admin_event_tracking_enabled();
		} elseif ( $is_woopay_event ) {
			$is_tracking_enabled = $this->is_woopay_event_tracking_enabled();
		} else {
			$is_tracking_enabled = $this->is_shopper_event_tracking_enabled();
		}
		if ( ! $is_tracking_enabled ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( $user instanceof WP_User && 'wptests_capabilities' === $user->cap_key ) {
			return false;
		}

		$event = new WC_Tracks_Event( $this->build_event_properties( $event_name, $properties, $user ) );

		if ( is_wp_error( $event->error ) ) {
			return $event->error;
		}

		// Core's transport: sent now in AJAX, REST, CLI and cron requests, otherwise from the footer pixel queue.
		return $event->record();
	}

	/**
	 * Count an order paid with another gateway on the WordPress.com stats pixel.
	 *
	 * Under the same checks as client 11.1.0 (`includes/class-woopay-tracker.php:545-555`). Checkout does
	 * not wait for the answer beyond a one-second timeout; the request is never retried and a failure is ignored.
	 */
	private function send_other_gateway_order_counter(): void {
		try {
			if ( ! $this->is_shopper_event_tracking_enabled() || $this->account_service->is_test_mode_enabled() ) {
				return;
			}

			wp_safe_remote_get(
				self::OTHER_GATEWAY_ORDER_COUNTER_URL,
				array(
					'blocking' => false,
					'timeout'  => 1,
				)
			);
		} catch ( Throwable $throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Tracking must never interrupt checkout order processing.
		}
	}

	/**
	 * Get AJAX hooks and callbacks.
	 *
	 * @return array<string,callable>
	 */
	private function get_ajax_hooks(): array {
		return array(
			'wp_ajax_platform_tracks'        => array( $this, 'handle_tracks' ),
			'wp_ajax_nopriv_platform_tracks' => array( $this, 'handle_tracks' ),
			'wp_ajax_get_identity'           => array( $this, 'handle_tracks_identity' ),
			'wp_ajax_nopriv_get_identity'    => array( $this, 'handle_tracks_identity' ),
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status_code HTTP status code.
	 * @return array{success:bool,status_code:int,data:string}
	 */
	private function error_response( string $message, int $status_code ): array {
		return array(
			'success'     => false,
			'status_code' => $status_code,
			'data'        => $message,
		);
	}

	/**
	 * Build Tracks event properties.
	 *
	 * @param string              $event_name Event name.
	 * @param array<string,mixed> $properties Event properties.
	 * @param WP_User             $user       Current user.
	 * @return array<string,mixed>
	 */
	private function build_event_properties( string $event_name, array $properties, WP_User $user ): array {
		$blog_details = WC_Tracks::get_blog_details( $user->ID );

		// Client 11.1.0 `class-woopay-tracker.php:368-405` sends no shopper IP, referrer or request URL.
		$server_details = array_diff_key( WC_Tracks::get_server_details(), array_flip( array( '_via_ip', '_dr', '_dl' ) ) );

		$event = array_merge(
			$properties,
			$server_details,
			$this->get_or_create_tracks_identity( $user->ID ),
			$blog_details,
			array(
				'_en'       => $event_name,
				'_ts'       => WC_Tracks_Client::build_timestamp(),
				'blog_url'  => get_option( 'siteurl' ),
				'user_lang' => $user->get( 'WPLANG' ),
				'test_mode' => $this->account_service->is_test_mode_enabled() ? 1 : 0,
			)
		);
		// The source property is set here, so a value the browser sent cannot change it.
		$event = WooPaymentsTracks::with_runtime( $event );

		foreach ( $event as $key => $value ) {
			if ( null === $value ) {
				unset( $event[ $key ] );
			}
		}

		return $event;
	}

	/**
	 * Get the Tracks identity for the current user.
	 *
	 * The WooPay session payload carries this identity so shopper analytics stay
	 * continuous across the store and WooPay's hosted checkout.
	 *
	 * @return array<string,string>
	 *
	 * @since 11.0.0
	 */
	public function get_or_create_tracks_identity_for_current_user(): array {
		return $this->get_or_create_tracks_identity( get_current_user_id() );
	}

	/**
	 * Get the shopper's Tracks identity: the linked WordPress.com user, stored once read, else an anonymous ID created and
	 * stored on first use.
	 *
	 * @param int $user_id User ID.
	 * @return array{_ut:string,_ui:string}
	 */
	private function get_or_create_tracks_identity( int $user_id ): array {
		$wpcom_id = get_user_meta( $user_id, 'jetpack_tracks_wpcom_id', true );
		if ( is_string( $wpcom_id ) && '' !== $wpcom_id && $this->is_user_connected( $user_id ) ) {
			return array(
				'_ut' => 'wpcom:user_id',
				'_ui' => $wpcom_id,
			);
		}

		if ( $this->is_user_connected( $user_id ) ) {
			$wpcom_user_data = $this->get_connected_user_data( $user_id );
			$wpcom_id        = is_array( $wpcom_user_data ) ? ( $wpcom_user_data['ID'] ?? null ) : null;
			if ( is_string( $wpcom_id ) && '' !== $wpcom_id ) {
				update_user_meta( $user_id, 'jetpack_tracks_wpcom_id', $wpcom_id );

				return array(
					'_ut' => 'wpcom:user_id',
					'_ui' => $wpcom_id,
				);
			}
		}

		$anon_id = get_user_meta( $user_id, 'jetpack_tracks_anon_id', true );
		if ( ! is_string( $anon_id ) || '' === $anon_id ) {
			$anon_id = \Jetpack_Tracks_Client::get_anon_id();
			add_user_meta( $user_id, 'jetpack_tracks_anon_id', $anon_id, false );
		}

		return array(
			'_ut' => 'anon',
			'_ui' => $anon_id,
		);
	}

	/**
	 * Tell whether a user has a connected WPCOM identity.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private function is_user_connected( int $user_id ): bool {
		$manager = new JetpackConnectionManager( 'woocommerce' );

		return $manager->is_user_connected( $user_id );
	}

	/**
	 * Get connected WPCOM user data.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null
	 */
	private function get_connected_user_data( int $user_id ): ?array {
		$manager = new JetpackConnectionManager( 'woocommerce' );
		$data    = $manager->get_connected_user_data( $user_id );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Tell whether the store allows WooPayments shopper Tracks at all, before any per-visitor check.
	 *
	 * @return bool
	 */
	private function is_store_tracking_enabled(): bool {
		/**
		 * Filters whether WooPayments shopper tracking is enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $is_enabled Whether shopper tracking is enabled.
		 */
		if ( ! apply_filters( 'wcpay_shopper_tracking_enabled', 'no' !== get_option( 'woocommerce_allow_tracking', '' ) ) ) {
			return false;
		}

		// No tracking while the gateway is disabled, as on the client (`class-woopay-tracker.php:242-246`).
		if ( ! $this->account_service->is_gateway_enabled() ) {
			return false;
		}

		// Any connected account, onboarded or not, like the client's `is_stripe_connected()` (`class-woopay-tracker.php:248-252`).
		return array() !== $this->account_service->get_cached_account_data() && $this->is_country_tracks_eligible();
	}

	/**
	 * Tell whether WooPay is enabled and the account is eligible for it.
	 *
	 * @return bool
	 */
	private function is_woopay_tracking_enabled(): bool {
		$account_data = $this->account_service->get_cached_account_data();

		return 'yes' === $this->account_service->get_gateway_setting( 'platform_checkout', 'no' )
			&& ! empty( $account_data['platform_checkout_eligible'] );
	}

	/**
	 * Check whether the store country is eligible for WooPayments shopper Tracks.
	 *
	 * @return bool
	 */
	private function is_country_tracks_eligible(): bool {
		$base_location = wc_get_base_location();

		return 'US' === ( $base_location['country'] ?? '' );
	}

	/**
	 * Get the WooPay eligibility rules, looked up on first use: only the funnel events read them, so a request that
	 * records none builds nothing more.
	 *
	 * @return Eligibility
	 */
	private function get_woopay_eligibility(): Eligibility {
		if ( null === $this->woopay_eligibility ) {
			$this->woopay_eligibility = wc_get_container()->get( Eligibility::class );
		}

		return $this->woopay_eligibility;
	}
}
