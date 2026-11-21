<?php
/**
 * CartTotalsCurrencyService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Keeps the cart totals saved in the session from being used in a currency they were not calculated in.
 *
 * The main cart's saved totals are stamped with the currency they were calculated in and a hash of them, under the
 * WooPayments extension's session keys, so either runtime reads the other's stamp. When the cart loads with totals the stamp
 * does not describe in the current currency, its cached shipping rates are dropped, once per stale state, and the cart is
 * recalculated on a REST request or when this request selected the currency before the cart loaded (client 11.3.0
 * `includes/multi-currency/MultiCurrency.php:1310-1486`).
 *
 * @internal
 */
class CartTotalsCurrencyService {

	/**
	 * Session key of the currency the saved cart totals were calculated in.
	 */
	public const CURRENCY_SESSION_KEY = 'wcpay_cart_totals_currency';

	/**
	 * Session key of the hash of the saved cart totals the currency stamp was recorded for.
	 */
	public const HASH_SESSION_KEY = 'wcpay_cart_totals_hash';

	/**
	 * Session key of the stale state whose cached shipping rates were already dropped.
	 */
	private const RATES_DROPPED_SESSION_KEY = 'wcpay_stale_totals_rates_dropped';

	/**
	 * Prefix of the session keys WooCommerce caches each shipping package's rates under.
	 */
	private const SHIPPING_RATES_SESSION_KEY_PREFIX = 'shipping_for_package_';

	/**
	 * Request context.
	 *
	 * @var RequestContext
	 */
	private RequestContext $request_context;

	/**
	 * Whether this request selected the currency before the cart loaded.
	 *
	 * @var bool
	 */
	private bool $currency_selected_before_cart_load = false;

	/**
	 * The main cart while its totals are being calculated.
	 *
	 * @var \WC_Cart|null
	 */
	private ?\WC_Cart $main_cart_in_calculation = null;

	/**
	 * The totals the main cart saved to the session during its current calculation, or null when it saved none.
	 *
	 * @var mixed
	 */
	private $main_cart_totals_saved_in_calculation = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param RequestContext $request_context Request context.
	 */
	final public function init( RequestContext $request_context ): void {
		$this->request_context = $request_context;
	}

	/**
	 * Recalculate the cart for a currency change: now when the cart has loaded, else when it loads.
	 */
	public function recalculate_for_currency_change(): void {
		if ( did_action( 'woocommerce_cart_loaded_from_session' ) ) {
			$this->recalculate_cart();
			return;
		}

		$this->currency_selected_before_cart_load = true;
	}

	/**
	 * Recalculate totals saved in another currency when the cart loads, on any request, for a currency selected before then.
	 *
	 * A `currency` link asks for this for any enabled code, unchanged included (client 11.3.0
	 * `includes/multi-currency/MultiCurrency.php:1149-1159`).
	 */
	public function recalculate_stale_totals_on_cart_load(): void {
		if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
			$this->currency_selected_before_cart_load = true;
		}
	}

	/**
	 * Start tracking whether the main cart's totals are saved during this calculation.
	 *
	 * @param mixed $cart The cart about to be calculated.
	 */
	public function start_cart_totals_calculation( $cart ): void {
		if ( $cart instanceof \WC_Cart && WC()->cart === $cart ) {
			$this->main_cart_in_calculation              = $cart;
			$this->main_cart_totals_saved_in_calculation = null;
		}
	}

	/**
	 * Note the totals WC_Cart_Session::set_session() saved for the main cart during its calculation.
	 *
	 * `woocommerce_cart_updated` does not say which cart was saved, so a save counts as the main cart's only when the session
	 * now holds the main cart's totals.
	 */
	public function note_cart_totals_saved(): void {
		if ( ! doing_action( 'woocommerce_after_calculate_totals' ) || null === $this->main_cart_in_calculation || ! WC()->session ) {
			return;
		}

		$saved_totals = WC()->session->get( 'cart_totals' );
		if ( $this->main_cart_in_calculation->get_totals() === $saved_totals ) {
			$this->main_cart_totals_saved_in_calculation = $saved_totals;
		}
	}

	/**
	 * Stamp the main cart's saved totals with the currency they were calculated in.
	 *
	 * Totals the session holds that this calculation did not save (a skipped session save, or another cart's save) are not
	 * described by this currency, so the stamp is removed and the next load recalculates. Totals the main cart saved count
	 * even when a later callback changed its totals in memory, so long as the session still holds exactly them.
	 *
	 * @param mixed $cart The cart whose totals were calculated.
	 */
	public function record_cart_totals_currency( $cart ): void {
		if ( ! $cart instanceof \WC_Cart || WC()->cart !== $cart || ! WC()->session ) {
			return;
		}

		$saved_totals                   = WC()->session->get( 'cart_totals' );
		$this->main_cart_in_calculation = null;

		$saved_by_main_cart = null !== $this->main_cart_totals_saved_in_calculation && $saved_totals === $this->main_cart_totals_saved_in_calculation;
		if ( ! $saved_by_main_cart && $saved_totals !== $cart->get_totals() ) {
			$this->clear_cart_totals_currency();
			return;
		}

		WC()->session->set( self::CURRENCY_SESSION_KEY, get_woocommerce_currency() );
		WC()->session->set( self::HASH_SESSION_KEY, $this->get_cart_totals_hash( $saved_totals ) );
		WC()->session->__unset( self::RATES_DROPPED_SESSION_KEY );
	}

	/**
	 * Remove the currency stamp and the totals hash.
	 */
	public function clear_cart_totals_currency(): void {
		if ( WC()->session ) {
			WC()->session->__unset( self::CURRENCY_SESSION_KEY );
			WC()->session->__unset( self::HASH_SESSION_KEY );
		}
	}

	/**
	 * Recalculate the loaded cart when its saved totals were calculated in another currency, or are not the ones stamped.
	 *
	 * Only REST requests can use saved totals without calculating them. Elsewhere only a request that selected the currency
	 * before the cart loaded recalculates, so a third-party calculation that throws cannot fail every page view.
	 */
	public function maybe_recalculate_cart_for_currency(): void {
		$cart    = WC()->cart;
		$session = WC()->session;
		if ( ! $cart instanceof \WC_Cart || ! $session ) {
			return;
		}

		// An empty cart is never stamped, and removing the last item does not empty the cart, so drop the stamp here.
		if ( $cart->is_empty() ) {
			$this->clear_cart_totals_currency();
			$session->__unset( self::RATES_DROPPED_SESSION_KEY );
			return;
		}

		// Without saved totals (a new session or a restored persistent cart), WooCommerce calculates them right after this.
		$saved_totals = $session->get( 'cart_totals' );
		if ( null === $saved_totals ) {
			return;
		}

		// Totals saved where Multi-Currency did not run keep an older stamp; the hash tells them apart.
		$currency_code  = get_woocommerce_currency();
		$stamped_code   = $session->get( self::CURRENCY_SESSION_KEY );
		$stamped_totals = $session->get( self::HASH_SESSION_KEY );
		if ( $currency_code === $stamped_code && $this->get_cart_totals_hash( $saved_totals ) === $stamped_totals ) {
			return;
		}

		// Rates cached beside stale totals can be stale without the package hash showing it. They are dropped once per stale
		// state, so rates rebuilt for the same totals stay cached when something keeps the stamp from being recorded.
		$stale_state = $this->get_cart_totals_hash( array( $currency_code, $stamped_code, $stamped_totals, $saved_totals ) );
		if ( $session->get( self::RATES_DROPPED_SESSION_KEY ) !== $stale_state ) {
			$this->clear_cached_shipping_rates();
			$session->set( self::RATES_DROPPED_SESSION_KEY, $stale_state );
		}

		if ( ! $this->currency_selected_before_cart_load && ! $this->is_rest_request() ) {
			return;
		}

		$this->recalculate_cart();
	}

	/**
	 * Recalculate the main cart's totals.
	 */
	private function recalculate_cart(): void {
		if ( WC()->cart instanceof \WC_Cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Drop the shipping rates WooCommerce cached in the session for the cart's packages.
	 */
	private function clear_cached_shipping_rates(): void {
		$session = WC()->session;
		// The session class is swappable (woocommerce_session_handler), and the WC_Session base class has no get_session_data().
		if ( ! is_callable( array( $session, 'get_session_data' ) ) ) {
			return;
		}

		foreach ( array_keys( (array) $session->get_session_data() ) as $key ) {
			if ( 0 === strpos( (string) $key, self::SHIPPING_RATES_SESSION_KEY_PREFIX ) ) {
				$session->set( (string) $key, null );
			}
		}
	}

	/**
	 * Hash saved cart totals as the WooPayments extension does, so either runtime reads the other's stamp.
	 *
	 * @param mixed $totals The cart totals, or the parts of a stale state.
	 * @return string
	 */
	private function get_cart_totals_hash( $totals ): string {
		return md5( (string) wp_json_encode( $totals ) );
	}

	/**
	 * Tell whether the cart is loading for a REST request, including one whose cart loads before `REST_REQUEST` is defined.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request classification.
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! empty( $_REQUEST['rest_route'] ) ) {
			return true;
		}

		return $this->request_context->is_wc_rest_api_request() || $this->request_context->is_store_api_request();
	}
}
