<?php
/**
 * SelectedCurrencyPersistenceService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Stores the shopper's selected currency under the `wcpay_currency` key, in user meta for a logged-in customer or in the
 * session for a guest, and recalculates the cart after a change.
 *
 * @since 11.0.0
 * @internal
 */
class SelectedCurrencyPersistenceService {

	/**
	 * State builder.
	 *
	 * @var StateBuilder
	 */
	private StateBuilder $state_builder;

	/**
	 * Constructor.
	 *
	 * @param StateBuilder $state_builder State builder.
	 *
	 * @since 11.0.0
	 */
	public function __construct( StateBuilder $state_builder ) {
		$this->state_builder = $state_builder;
	}

	/**
	 * Reset request-local selected-currency state.
	 *
	 * @internal
	 */
	public function reset_selected_currency_state(): void {
		$this->state_builder->reset();
	}

	/**
	 * Persist the selected currency when it is enabled.
	 *
	 * @param string $currency_code  Three-letter currency code.
	 * @param bool   $persist_change Whether the explicit change should persist across requests.
	 * @return bool True when the selected currency was persisted.
	 *
	 * @since 11.0.0
	 */
	public function update_selected_currency( string $currency_code, bool $persist_change = true ): bool {
		$currency_code = $this->normalize_currency_code( $currency_code );
		if ( '' === $currency_code || ! $this->is_enabled_currency( $currency_code ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, StateBuilder::CURRENCY_STORAGE_KEY, $currency_code );
		} else {
			$this->set_session_currency( $currency_code, $persist_change );
		}

		$this->state_builder->reset();
		$this->recalculate_cart_or_schedule();

		return true;
	}

	/**
	 * Persist a new customer's selected currency from the guest session.
	 *
	 * @param int $customer_id Customer ID.
	 * @return bool True when customer meta was updated.
	 *
	 * @since 11.0.0
	 */
	public function set_new_customer_currency_meta( int $customer_id ): bool {
		if ( 0 >= $customer_id || ! WC()->session ) {
			return false;
		}

		$currency_code = WC()->session->get( StateBuilder::CURRENCY_STORAGE_KEY );
		if ( ! is_string( $currency_code ) ) {
			return false;
		}

		$currency_code = $this->normalize_currency_code( $currency_code );
		if ( '' === $currency_code ) {
			return false;
		}

		update_user_meta( $customer_id, StateBuilder::CURRENCY_STORAGE_KEY, $currency_code );

		return true;
	}

	/**
	 * Tell whether more than one currency is enabled.
	 *
	 * @return bool True when a customer-facing selector is useful.
	 *
	 * @since 11.0.0
	 */
	public function has_additional_currencies_enabled(): bool {
		return $this->state_builder->build()->has_additional_currencies_enabled();
	}

	/**
	 * Get enabled currency option data for account forms.
	 *
	 * @return array<int,array{code:string,symbol:string}>
	 *
	 * @since 11.0.0
	 */
	public function get_enabled_currency_options(): array {
		$options = array();

		foreach ( $this->state_builder->build()->get_enabled_currencies() as $currency ) {
			$options[] = array(
				'code'   => $currency->get_code(),
				'symbol' => $currency->get_symbol(),
			);
		}

		return $options;
	}

	/**
	 * Get the selected currency code.
	 *
	 * @return string Selected currency code.
	 *
	 * @since 11.0.0
	 */
	public function get_selected_currency_code(): string {
		return $this->state_builder->build()->get_selected_currency()->get_code();
	}

	/**
	 * Tell whether the current customer has a stored selected currency.
	 *
	 * A guest's session is loaded first: a Store API request loads it only inside the route, after this check runs
	 * (client 11.1.0 includes/multi-currency/MultiCurrency.php:1813-1824).
	 *
	 * @return bool True when user meta or the guest's session stores a currency.
	 *
	 * @since 11.0.0
	 */
	public function has_stored_currency_code(): bool {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$currency_code = get_user_meta( $user_id, StateBuilder::CURRENCY_STORAGE_KEY, true );

			return is_string( $currency_code ) && '' !== $currency_code;
		}

		if ( ! WC()->session ) {
			WC()->initialize_session();
		}

		$currency_code = WC()->session->get( StateBuilder::CURRENCY_STORAGE_KEY );

		return is_string( $currency_code ) && '' !== $currency_code;
	}

	/**
	 * Recalculate cart totals.
	 *
	 * @internal
	 */
	public function recalculate_cart(): void {
		if ( WC()->cart instanceof \WC_Cart ) {
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Persist the selected guest currency to session.
	 *
	 * @param string $currency_code  Currency code.
	 * @param bool   $persist_change Whether to request a persistent customer session cookie.
	 */
	private function set_session_currency( string $currency_code, bool $persist_change ): void {
		if ( ! WC()->session ) {
			WC()->initialize_session();
		}

		WC()->session->set( StateBuilder::CURRENCY_STORAGE_KEY, $currency_code );

		// The session class is swappable (woocommerce_session_handler), and not every handler sets a customer cookie.
		if ( $persist_change && method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/**
	 * Recalculate cart totals now or after WordPress finishes loading.
	 */
	private function recalculate_cart_or_schedule(): void {
		if ( did_action( 'wp_loaded' ) ) {
			$this->recalculate_cart();
			return;
		}

		if ( ! has_action( 'wp_loaded', array( $this, 'recalculate_cart' ) ) ) {
			add_action( 'wp_loaded', array( $this, 'recalculate_cart' ) );
		}
	}

	/**
	 * Tell whether a currency code is currently enabled.
	 *
	 * @param string $currency_code Currency code.
	 * @return bool True when enabled.
	 */
	private function is_enabled_currency( string $currency_code ): bool {
		return isset( $this->state_builder->build()->get_enabled_currencies()[ $currency_code ] );
	}

	/**
	 * Normalize a currency code.
	 *
	 * @param string $currency_code Currency code.
	 * @return string Normalized code.
	 */
	private function normalize_currency_code( string $currency_code ): string {
		return strtoupper( trim( $currency_code ) );
	}
}
