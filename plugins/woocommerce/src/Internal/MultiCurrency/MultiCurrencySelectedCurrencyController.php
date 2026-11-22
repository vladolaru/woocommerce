<?php
/**
 * MultiCurrencySelectedCurrencyController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\CartTotalsCurrencyService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\DisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\GeolocationNotice;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\GeolocationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SelectedCurrencyPersistenceService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\IntegrationsController;

/**
 * Sets the shopper's selected currency from the URL, geolocation, the My Account field or checkout, runs the settings
 * Preview simulation, and keeps the cart's saved totals from being used in another currency.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencySelectedCurrencyController implements RegisterHooksInterface {

	private const ACCOUNT_CURRENCY_FIELD = 'wcpay_selected_currency';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Selected currency persistence service.
	 *
	 * @var SelectedCurrencyPersistenceService|null
	 */
	private ?SelectedCurrencyPersistenceService $persistence_service = null;

	/**
	 * Request context.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * Geolocation service.
	 *
	 * @var GeolocationService|null
	 */
	private ?GeolocationService $geolocation_service = null;

	/**
	 * Whether selected-currency state is ready to read from the WooCommerce session.
	 *
	 * @var bool
	 */
	private bool $selected_currency_state_is_session_ready = false;

	/**
	 * Settings preview simulation parameters; empty outside a simulation.
	 *
	 * @var array<string,bool>
	 */
	private array $simulation_params = array();

	/**
	 * Multi-Currency service factory.
	 *
	 * @var ServiceFactory
	 */
	private ServiceFactory $service_factory;

	/**
	 * Integrations controller.
	 *
	 * @var IntegrationsController
	 */
	private IntegrationsController $integrations_controller;

	/**
	 * Cart totals currency service.
	 *
	 * @var CartTotalsCurrencyService
	 */
	private CartTotalsCurrencyService $cart_totals_currency;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter                  Runtime owner arbiter.
	 * @param ServiceFactory              $service_factory          Multi-Currency service factory.
	 * @param IntegrationsController      $integrations_controller Integrations controller.
	 * @param RequestContext              $request_context          Request context.
	 * @param CartTotalsCurrencyService   $cart_totals_currency     Cart totals currency service.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, ServiceFactory $service_factory, IntegrationsController $integrations_controller, RequestContext $request_context, CartTotalsCurrencyService $cart_totals_currency ): void {
		$this->arbiter                 = $arbiter;
		$this->service_factory         = $service_factory;
		$this->integrations_controller = $integrations_controller;
		$this->request_context         = $request_context;
		$this->cart_totals_currency    = $cart_totals_currency;
	}

	/**
	 * Register selected-currency hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_filter( 'woocommerce_rest_prepare_customer', array( $this, 'handle_woocommerce_rest_prepare_customer' ), 10, 3 );
		// The stamp runs wherever totals are calculated: last, after WC_Cart_Session::set_session() (priority 1000) saved them.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'handle_woocommerce_before_calculate_totals' ), PHP_INT_MIN );
		add_action( 'woocommerce_cart_updated', array( $this, 'handle_woocommerce_cart_updated' ), PHP_INT_MAX, 0 );
		add_action( 'woocommerce_after_calculate_totals', array( $this, 'handle_woocommerce_after_calculate_totals' ), PHP_INT_MAX );
		add_action( 'woocommerce_cart_emptied', array( $this, 'handle_woocommerce_cart_emptied' ), 10, 0 );

		if ( $this->request_context->should_register_selected_currency_entry_hooks() ) {
			if ( $this->request_context->is_store_api_request() ) {
				add_filter( 'rest_pre_dispatch', array( $this, 'handle_store_api_rest_pre_dispatch' ), 10, 3 );
			} else {
				add_action( 'woocommerce_load_cart_from_session', array( $this, 'handle_woocommerce_load_cart_from_session' ), 10, 0 );
			}

			// Only here does the currency in effect reflect the selection; last, after other callbacks changed the loaded cart.
			add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_woocommerce_cart_loaded_from_session' ), PHP_INT_MAX, 0 );

			add_action( 'init', array( $this, 'handle_init' ), 11 );
			add_action( 'init', array( $this, 'handle_geolocation_init' ), 12 );
			add_action( 'init', array( $this, 'handle_simulation_init' ), 13 );
			add_action( 'woocommerce_created_customer', array( $this, 'handle_woocommerce_created_customer' ) );
		}

		add_action( 'woocommerce_edit_account_form', array( $this, 'handle_woocommerce_edit_account_form' ) );
		add_action( 'woocommerce_save_account_details', array( $this, 'handle_woocommerce_save_account_details' ) );
	}

	/**
	 * Handle classic cart session readiness.
	 *
	 * @internal
	 */
	public function handle_woocommerce_load_cart_from_session(): void {
		$this->prepare_selected_currency_state_for_session( false );
	}

	/**
	 * Handle Store API session readiness before REST dispatch.
	 *
	 * @internal
	 *
	 * @param mixed $result  Response to replace the requested version with, or null.
	 * @param mixed $server  REST server instance.
	 * @param mixed $request REST request instance.
	 * @return mixed Incoming result unchanged.
	 */
	public function handle_store_api_rest_pre_dispatch( $result, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof \WP_REST_Request || 0 !== strpos( $request->get_route(), '/wc/store/' ) ) {
			return $result;
		}

		$this->prepare_selected_currency_state_for_session( true );

		return $result;
	}

	/**
	 * Start tracking the main cart's totals saved during its calculation.
	 *
	 * @internal
	 *
	 * @param mixed $cart The cart about to be calculated.
	 */
	public function handle_woocommerce_before_calculate_totals( $cart = null ): void {
		$this->cart_totals_currency->start_cart_totals_calculation( $cart );
	}

	/**
	 * Note the totals WooCommerce saved to the session.
	 *
	 * @internal
	 */
	public function handle_woocommerce_cart_updated(): void {
		$this->cart_totals_currency->note_cart_totals_saved();
	}

	/**
	 * Stamp the main cart's saved totals with the currency they were calculated in.
	 *
	 * @internal
	 *
	 * @param mixed $cart The cart whose totals were calculated.
	 */
	public function handle_woocommerce_after_calculate_totals( $cart = null ): void {
		$this->cart_totals_currency->record_cart_totals_currency( $cart );
	}

	/**
	 * Remove the stamp of an emptied cart, so it does not keep an otherwise empty session alive.
	 *
	 * @internal
	 */
	public function handle_woocommerce_cart_emptied(): void {
		$this->cart_totals_currency->clear_cart_totals_currency();
	}

	/**
	 * Recalculate a loaded cart whose saved totals were calculated in another currency.
	 *
	 * @internal
	 */
	public function handle_woocommerce_cart_loaded_from_session(): void {
		$this->cart_totals_currency->maybe_recalculate_cart_for_currency();
	}

	/**
	 * Select the shopper's currency for a Store API route matched on a request whose URL did not name it.
	 *
	 * As client 11.3.0 `includes/multi-currency/MultiCurrency.php:444`, `:454-460`: the created-customer copy and the check of
	 * the cart's saved totals are added, which registration skips on a request with an admin referer; the session is made
	 * ready and geolocation runs now, for the session already in use, and geolocation runs again when the cart loads, for a
	 * session the route loads with it. The state follows that session on its own.
	 *
	 * @internal
	 */
	public function start_matched_store_api_route(): void {
		add_action( 'woocommerce_created_customer', array( $this, 'handle_woocommerce_created_customer' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_woocommerce_cart_loaded_from_session' ), PHP_INT_MAX, 0 );
		$this->prepare_selected_currency_state_for_session( true );
		$this->handle_geolocation_init();
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_geolocation_init' ), 10, 0 );
	}

	/**
	 * Stop selecting the geolocated currency on cart load once the matched Store API route's callbacks have run.
	 *
	 * @internal
	 */
	public function end_matched_store_api_route(): void {
		remove_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_geolocation_init' ), 10 );
	}

	/**
	 * Handle explicit selected-currency URL changes.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		if ( ! isset( $_GET['currency'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$currency_code = wc_clean( wp_unslash( $_GET['currency'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $currency_code ) ) {
			return;
		}

		$this->get_persistence_service()->update_selected_currency_by_url( strtoupper( trim( (string) $currency_code ) ) );
		$this->clear_url_price_params();
	}

	/**
	 * Handle automatic selected-currency changes from geolocation.
	 *
	 * @internal
	 */
	public function handle_geolocation_init(): void {
		if (
			! $this->is_using_auto_currency_switching()
			|| $this->integrations_controller->should_disable_currency_switching()
		) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'handle_wp_footer' ) );

		if ( $this->should_use_async_rendering() || $this->get_persistence_service()->has_stored_currency_code() ) {
			return;
		}

		$currency_code = $this->get_geolocation_service()->get_currency_by_customer_location();
		if ( ! is_string( $currency_code ) || '' === trim( $currency_code ) ) {
			return;
		}

		$this->get_persistence_service()->update_selected_currency( strtoupper( trim( $currency_code ) ), false );
	}

	/**
	 * Render the geolocation currency update notice.
	 *
	 * @internal
	 */
	public function handle_wp_footer(): void {
		$store_currency_code = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		$async               = $this->should_use_async_rendering();

		if ( $async ) {
			// The shared page carries only the store currency. The renderer fills the visitor's slots.
			$message   = GeolocationNotice::get_message( '<span data-wcpay-notice-country></span>', '<span data-wcpay-notice-currency></span>', $store_currency_code );
			$notice_id = '';
			echo '<template id="wcpay-geolocation-notice">';
		} else {
			$notice = GeolocationNotice::get_notice(
				$this->get_geolocation_service(),
				strtoupper( $this->get_persistence_service()->get_selected_currency_code() ),
				$store_currency_code,
				! empty( $this->simulation_params )
			);
			if ( null === $notice ) {
				return;
			}
			$message   = $notice['message'];
			$notice_id = $notice['id'];
		}

		echo '<p class="woocommerce-store-notice demo_store" data-notice-id="' . esc_attr( $notice_id ) . '" style="display:none;">';
		echo wp_kses_post( $message );
		echo ' <a href="#" class="woocommerce-store-notice__dismiss-link">' . esc_html__( 'Dismiss', 'woocommerce' ) . '</a></p>';

		if ( $async ) {
			echo '</template>';
		}
	}

	/**
	 * Start the multi-currency settings Preview simulation when the request asks for it.
	 *
	 * Client 11.1.0 MultiCurrency::possible_simulation_activation() and simulate_client_currency():
	 * the Preview shows the automatic-switch notice as a USD store's UK visitor (any other store's US visitor) would see it.
	 *
	 * @internal
	 */
	public function handle_simulation_init(): void {
		$this->simulation_params = RequestContext::get_settings_preview_params();
		if ( empty( $this->simulation_params ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'handle_simulation_wp_footer' ) );

		if ( ! $this->simulation_params['enable_auto_currency'] ) {
			return;
		}

		$simulation_currency = 'USD' === strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) ) ? 'GBP' : 'USD';
		$countries           = WC()->countries->get_countries();
		$country_code        = 'GBP' === $simulation_currency ? 'GB' : 'US';
		$country_name        = (string) ( $countries[ $country_code ] ?? $country_code );
		$currency_name       = (string) ( get_woocommerce_currencies()[ $simulation_currency ] ?? $simulation_currency );

		add_filter(
			GeolocationNotice::FILTER_OVERRIDE_NOTICE_CURRENCY_NAME,
			static function () use ( $currency_name ) {
				return $currency_name;
			}
		);
		add_filter(
			GeolocationNotice::FILTER_OVERRIDE_NOTICE_COUNTRY,
			static function () use ( $country_name ) {
				return $country_name;
			}
		);

		add_action( 'wp_footer', array( $this, 'handle_wp_footer' ) );
	}

	/**
	 * Carry the Preview simulation through the page links and show the store notice again.
	 *
	 * Client 11.1.0 MultiCurrency::add_simulation_params_to_preview_urls().
	 *
	 * @internal
	 */
	public function handle_simulation_wp_footer(): void {
		if ( empty( $this->simulation_params ) ) {
			return;
		}

		$enable_auto_currency       = $this->simulation_params['enable_auto_currency'] ? 'true' : 'false';
		$enable_storefront_switcher = $this->simulation_params['enable_storefront_switcher'] ? 'true' : 'false';
		$script                     = "document.querySelectorAll( 'a' ).forEach( ( link ) => {
	if ( ! link.href ) {
		return;
	}
	const parsedURL = new URL( link.href );
	if ( false === parsedURL.searchParams.has( 'is_mc_onboarding_simulation' ) ) {
		parsedURL.searchParams.set( 'is_mc_onboarding_simulation', true );
		parsedURL.searchParams.set( 'enable_auto_currency', {$enable_auto_currency} );
		parsedURL.searchParams.set( 'enable_storefront_switcher', {$enable_storefront_switcher} );
		link.href = parsedURL.toString();
	}
} );
document.addEventListener( 'DOMContentLoaded', () => {
	const noticeElement = document.querySelector( '.woocommerce-store-notice.demo_store' );
	if ( noticeElement && window.cookieStore ) {
		window.cookieStore.delete( 'store_notice' + noticeElement.getAttribute( 'data-notice-id' ) );
	}
} );";

		wp_print_inline_script_tag( $script, array( 'id' => 'wcpay_multi_currency-simulation-script' ) );
	}

	/**
	 * Handle customer creation during checkout.
	 *
	 * @internal
	 *
	 * @param mixed $customer_id Customer ID.
	 */
	public function handle_woocommerce_created_customer( $customer_id ): void {
		$this->get_persistence_service()->set_new_customer_currency_meta( absint( $customer_id ) );
	}

	/**
	 * Remove the customer's stored selected-currency meta from customer REST responses.
	 *
	 * @internal
	 *
	 * @param mixed            $response Customer response.
	 * @param \WP_User         $user_data Customer user data.
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 * @return mixed Customer response without the stored selected-currency meta.
	 */
	public function handle_woocommerce_rest_prepare_customer( $response, \WP_User $user_data, \WP_REST_Request $request ) {
		unset( $user_data, $request );
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! isset( $data['meta_data'] ) || ! is_array( $data['meta_data'] ) ) {
			return $response;
		}

		$data['meta_data'] = array_values(
			array_filter(
				$data['meta_data'],
				static function ( $meta ): bool {
					if ( $meta instanceof \WC_Meta_Data ) {
						return StateBuilder::CURRENCY_STORAGE_KEY !== $meta->key;
					}

					return ! ( is_array( $meta ) && isset( $meta['key'] ) && StateBuilder::CURRENCY_STORAGE_KEY === $meta['key'] );
				}
			)
		);
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Clear stale URL price filters after a browser currency switch.
	 */
	private function clear_url_price_params(): void {
		if (
			WC()->is_rest_api_request()
			|| ! empty( $_GET['rest_route'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			return;
		}

		if ( isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$url = remove_query_arg( array( 'min_price', 'max_price' ) );

			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Render the My Account presentment currency field.
	 *
	 * @internal
	 */
	public function handle_woocommerce_edit_account_form(): void {
		$service = $this->get_persistence_service();
		if ( ! $service->has_additional_currencies_enabled() ) {
			return;
		}

		$selected_code = $service->get_selected_currency_code();
		$markup        = '<p class="woocommerce-form-row woocommerce-form-row--first form-row form-row-first">';
		$markup       .= '<label for="' . esc_attr( self::ACCOUNT_CURRENCY_FIELD ) . '">' . esc_html__( 'Default currency', 'woocommerce' ) . '</label>';
		$markup       .= '<select name="' . esc_attr( self::ACCOUNT_CURRENCY_FIELD ) . '" id="' . esc_attr( self::ACCOUNT_CURRENCY_FIELD ) . '">';

		// Both codes come from MultiCurrencyCurrency::get_code(), upper-cased when the currency is built.
		foreach ( $service->get_enabled_currency_options() as $currency ) {
			$code     = $currency['code'];
			$selected = $selected_code === $code ? ' selected' : '';
			$markup  .= '<option value="' . esc_attr( $code ) . '"' . $selected . '>' . wp_kses_post( trim( $currency['symbol'] . ' ' . $code ) ) . '</option>';
		}

		$markup .= '</select>';
		$markup .= '<span><em>' . esc_html__( 'Select your preferred currency for shopping and payments.', 'woocommerce' ) . '</em></span>';
		$markup .= '</p>';
		$markup .= '<div class="clear"></div>';

		// The markup escapes each attribute and text while keeping WooPayments-compatible form HTML.
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Save the My Account presentment currency field.
	 *
	 * @internal
	 */
	public function handle_woocommerce_save_account_details(): void {
		$raw_currency_code = $_POST[ self::ACCOUNT_CURRENCY_FIELD ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- WooCommerce verified the account details nonce; the value is unslashed and cleaned below.
		if ( ! is_scalar( $raw_currency_code ) ) {
			return;
		}

		$cleaned_currency_code = wc_clean( wp_unslash( (string) $raw_currency_code ) );
		$currency_code         = is_string( $cleaned_currency_code ) ? trim( $cleaned_currency_code ) : '';
		if ( '' !== $currency_code ) {
			$this->get_persistence_service()->update_selected_currency( $currency_code );
		}
	}

	/**
	 * Get the persistence service.
	 *
	 * @return SelectedCurrencyPersistenceService
	 */
	private function get_persistence_service(): SelectedCurrencyPersistenceService {
		if ( null === $this->persistence_service ) {
			$this->persistence_service = $this->service_factory->create_selected_currency_persistence_service();
		}

		return $this->persistence_service;
	}

	/**
	 * Get the geolocation service.
	 *
	 * @return GeolocationService
	 */
	private function get_geolocation_service(): GeolocationService {
		if ( null === $this->geolocation_service ) {
			$this->geolocation_service = $this->service_factory->create_geolocation_service();
		}

		return $this->geolocation_service;
	}

	/**
	 * Tell whether automatic currency switching is enabled.
	 *
	 * @return bool
	 */
	private function is_using_auto_currency_switching(): bool {
		return 'yes' === get_option( StateBuilder::OPTION_PREFIX . '_enable_auto_currency', 'no' );
	}

	/**
	 * Tell whether async rendering should handle geolocation switching client-side.
	 *
	 * @return bool
	 */
	private function should_use_async_rendering(): bool {
		return $this->is_cache_optimized_mode()
			&& ! $this->has_active_session()
			&& ! $this->request_context->is_store_api_request();
	}

	/**
	 * Tell whether cache-optimized mode is active.
	 *
	 * @return bool
	 */
	private function is_cache_optimized_mode(): bool {
		return DisplayService::RENDERING_MODE_CACHE === get_option( DisplayService::RENDERING_MODE_OPTION, DisplayService::RENDERING_MODE_SPEED );
	}

	/**
	 * Tell whether a cookie-backed WooCommerce session exists.
	 *
	 * @return bool
	 */
	private function has_active_session(): bool {
		// The session class is swappable (woocommerce_session_handler), and the WC_Session base class has no has_session().
		return is_object( WC()->session )
			&& method_exists( WC()->session, 'has_session' )
			&& WC()->session->has_session();
	}

	/**
	 * Prepare selected-currency state after the WooCommerce session is ready.
	 *
	 * @param bool $initialize_session Whether WooCommerce should initialize its session first.
	 */
	private function prepare_selected_currency_state_for_session( bool $initialize_session ): void {
		if ( $this->selected_currency_state_is_session_ready ) {
			return;
		}

		$woocommerce = WC();
		if ( $initialize_session && ! $woocommerce->session ) {
			$woocommerce->initialize_session();
		}

		if ( ! $woocommerce->session ) {
			return;
		}

		$this->get_persistence_service()->reset_selected_currency_state();
		$this->selected_currency_state_is_session_ready = true;
	}
}
