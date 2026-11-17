<?php
/**
 * SelectedCurrencyPersistenceService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Stores the shopper's selected currency under the `wcpay_currency` key, in user meta for a logged-in customer or in the
 * session for a guest, and has the cart recalculated when the stored currency changes.
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
	 * Cart totals currency service.
	 *
	 * @var CartTotalsCurrencyService
	 */
	private CartTotalsCurrencyService $cart_totals_currency;

	/**
	 * Constructor.
	 *
	 * @param StateBuilder              $state_builder        State builder.
	 * @param CartTotalsCurrencyService $cart_totals_currency Cart totals currency service.
	 *
	 * @since 11.0.0
	 */
	public function __construct( StateBuilder $state_builder, CartTotalsCurrencyService $cart_totals_currency ) {
		$this->state_builder        = $state_builder;
		$this->cart_totals_currency = $cart_totals_currency;
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

		// Read before the write: the cart was calculated in the stored currency, so only a different one makes it stale.
		$currency_changed = $this->get_stored_currency_code() !== $currency_code;

		$user_id = get_current_user_id();
		if ( $user_id ) {
			update_user_meta( $user_id, StateBuilder::CURRENCY_STORAGE_KEY, $currency_code );
		} else {
			$this->set_session_currency( $currency_code, $persist_change );
		}

		$this->state_builder->reset();
		if ( $currency_changed ) {
			$this->cart_totals_currency->recalculate_for_currency_change();
		}

		return true;
	}

	/**
	 * Persist a currency named by the `currency` URL parameter.
	 *
	 * For any enabled code the link names, the stored one included, totals saved in another currency are recalculated when
	 * the cart loads later in the request, on a page view too, as client 11.3.0 `update_selected_currency_by_url()` does
	 * (`includes/multi-currency/MultiCurrency.php:1149-1159`).
	 *
	 * @param string $currency_code Three-letter currency code.
	 * @return bool True when the selected currency was persisted.
	 */
	public function update_selected_currency_by_url( string $currency_code ): bool {
		if ( ! $this->update_selected_currency( $currency_code ) ) {
			return false;
		}

		$this->cart_totals_currency->recalculate_stale_totals_on_cart_load();

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
		return '' !== $this->get_stored_currency_code();
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
	 * Get the currency code stored for the current customer: user meta for a logged-in customer, else the guest's session.
	 *
	 * @return string The stored code, or an empty string when none is stored.
	 */
	private function get_stored_currency_code(): string {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$currency_code = get_user_meta( $user_id, StateBuilder::CURRENCY_STORAGE_KEY, true );

			return is_string( $currency_code ) ? $currency_code : '';
		}

		if ( ! WC()->session ) {
			WC()->initialize_session();
		}

		$currency_code = WC()->session->get( StateBuilder::CURRENCY_STORAGE_KEY );

		return is_string( $currency_code ) ? $currency_code : '';
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
