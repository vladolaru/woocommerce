<?php
/**
 * RequestContext class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency\Services;

/**
 * Tells the request contexts Multi-Currency registers its hooks by.
 *
 * @since 11.0.0
 * @internal
 */
class RequestContext {

	/**
	 * Tell whether the current request is a frontend request.
	 *
	 * @return bool True when frontend multi-currency hooks may register.
	 *
	 * @since 11.0.0
	 */
	public function is_frontend_request(): bool {
		return ! $this->is_wp_admin() && ! $this->is_doing_cron() && ! $this->is_admin_api_request();
	}

	/**
	 * Tell whether the current request is Store API.
	 *
	 * @return bool True when this is a Store API request.
	 *
	 * @since 11.0.0
	 */
	public function is_store_api_request(): bool {
		$wc_store_api_request = $this->get_wc_store_api_request();
		if ( null !== $wc_store_api_request ) {
			return $wc_store_api_request;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( '' !== $request_uri && false !== strpos( $request_uri, trailingslashit( rest_get_url_prefix() ) . 'wc/store/' ) ) {
			return true;
		}

		if ( ! isset( $_GET['rest_route'] ) || ! is_string( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$rest_route = rawurldecode( esc_url_raw( wp_unslash( $_GET['rest_route'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 0 === strpos( $rest_route, '/wc/store/' );
	}

	/**
	 * Tell whether the current request is a Store API batch request.
	 *
	 * @return bool True when this is a Store API batch request.
	 *
	 * @since 11.0.0
	 */
	public function is_store_batch_request(): bool {
		if ( isset( $_REQUEST['rest_route'] ) && is_string( $_REQUEST['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$rest_route = sanitize_text_field( wp_unslash( $_REQUEST['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			$url_parts    = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
			$request_path = ! empty( $url_parts['path'] ) ? rtrim( $url_parts['path'], '/' ) : '';
			$rest_route   = str_replace( trailingslashit( rest_get_url_prefix() ), '', $request_path );
		}

		if ( '' === $rest_route ) {
			return false;
		}

		return 1 === preg_match( '@^/?wc/store(/v[\d]+)?/batch@', $rest_route );
	}

	/**
	 * Tell whether the current request is an admin-originated non-Store REST request.
	 *
	 * @return bool True for admin REST requests.
	 *
	 * @since 11.0.0
	 */
	public function is_admin_api_request(): bool {
		$referer = wp_get_referer();
		if ( ! is_string( $referer ) || '' === $referer ) {
			return false;
		}

		return 0 === stripos( $referer, admin_url() ) && $this->is_wc_rest_api_request() && ! $this->is_store_api_request();
	}

	/**
	 * Tell whether frontend price/currency hooks should register.
	 *
	 * @return bool True when hooks should register.
	 *
	 * @since 11.0.0
	 */
	public function should_register_frontend_hooks(): bool {
		return $this->is_frontend_request();
	}

	/**
	 * Tell whether selected-currency writer hooks should register.
	 *
	 * @return bool True when selected-currency writers should register.
	 *
	 * @since 11.0.0
	 */
	public function should_register_selected_currency_entry_hooks(): bool {
		return $this->is_frontend_request() || $this->is_store_api_request();
	}

	/**
	 * Tell whether non-Store REST request-local currency overrides should register.
	 *
	 * @return bool True when REST request override filters should register.
	 *
	 * @since 11.0.0
	 */
	public function should_register_rest_request_overrides(): bool {
		return $this->is_wc_rest_api_request() && ! $this->is_store_api_request() && ! $this->is_store_batch_request();
	}

	/**
	 * Tell whether WordPress is serving an admin request.
	 *
	 * @return bool True for admin requests.
	 */
	protected function is_wp_admin(): bool {
		return is_admin();
	}

	/**
	 * Tell whether cron is running.
	 *
	 * @return bool True for cron requests.
	 */
	protected function is_doing_cron(): bool {
		return wp_doing_cron();
	}

	/**
	 * Tell whether any of the given calls appears in the current backtrace, as `Class->method`, `Class::method` or `function`.
	 *
	 * @param string[] $calls Expected calls.
	 * @return bool
	 */
	public static function is_call_in_backtrace( array $calls ): bool {
		$expected_lookup = array_fill_keys( $calls, true );

		// Cheaper equivalent of wp_debug_backtrace_summary( null, 0, false ) + in_array():
		// DEBUG_BACKTRACE_IGNORE_ARGS skips capturing argument values, and the loop early-returns
		// on the first matching frame instead of formatting the whole stack into a summary array.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Compatibility guard on the price/cart hot path.
			if ( empty( $frame['function'] ) ) {
				continue;
			}

			$call = isset( $frame['class'] ) ? $frame['class'] . ( $frame['type'] ?? '::' ) . $frame['function'] : $frame['function'];

			if ( isset( $expected_lookup[ $call ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the current request's query arguments, cleaned, for the currency switcher forms to carry over.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_current_query_args(): array {
		return (array) wc_clean( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query preservation for switcher form inputs.
	}

	/**
	 * Tell whether WooCommerce considers this a REST API request.
	 *
	 * @return bool True for WooCommerce REST requests.
	 */
	public function is_wc_rest_api_request(): bool {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		// Core's WooCommerce::is_rest_api_request(), which cannot be called while WooCommerce is still constructing: the REST prefix
		// in the path, or a rest_route parameter on plain-permalink stores and Jetpack-signed calls.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		$rest_prefix         = trailingslashit( rest_get_url_prefix() );
		$is_rest_api_request = false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix ) || ! empty( $_GET['rest_route'] );
		// phpcs:enable

		/**
		 * Filters whether this is a WooCommerce REST API request.
		 *
		 * @since 3.6.0
		 *
		 * @param bool $is_rest_api_request Whether this is a WooCommerce REST API request.
		 */
		return (bool) apply_filters( 'woocommerce_is_rest_api_request', $is_rest_api_request );
	}

	/**
	 * Return a fixed Store API answer; production returns null, so the request path decides.
	 *
	 * @return bool|null Store API result, or null when unavailable.
	 */
	protected function get_wc_store_api_request(): ?bool {
		return null;
	}
}
