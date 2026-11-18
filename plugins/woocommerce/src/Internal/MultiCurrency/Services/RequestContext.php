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
	 * The settings preview's query parameter, under the extension's name so its preview links keep working.
	 */
	private const SETTINGS_PREVIEW_FLAG = 'is_mc_onboarding_simulation';

	/**
	 * Whether WordPress has matched this request, or a request batched or dispatched in it, to a Store API route its URL did
	 * not name, until that request's callbacks have run.
	 *
	 * @var bool
	 */
	private bool $matched_store_api_route = false;

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
	 * Tell whether the current request is Store API: by its URL, or by the Store API route WordPress matched for it.
	 *
	 * @return bool True when this is a Store API request.
	 *
	 * @since 11.0.0
	 */
	public function is_store_api_request(): bool {
		if ( $this->matched_store_api_route ) {
			return true;
		}

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
	 * Record whether WordPress has matched the request to a Store API route its URL did not name, as client 11.3.0
	 * `includes/multi-currency/Utils.php:130-157` does: once matched, the request is a Store API request whatever its URL.
	 *
	 * @internal
	 *
	 * @param bool $matched True once a Store API route has matched, false when that request's callbacks have run.
	 */
	public function set_matched_store_api_route( bool $matched ): void {
		$this->matched_store_api_route = $matched;
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
	public function is_call_in_backtrace( array $calls ): bool {
		return $this->is_call_in_backtrace_within( $calls, 0 );
	}

	/**
	 * Tell whether any of the given calls appears within the innermost frames of the current backtrace.
	 *
	 * For a call that always sits a fixed, shallow distance below the caller, so the cost stays bounded however deep the stack
	 * is (client 11.3.0 `includes/multi-currency/Utils.php:52-62`).
	 *
	 * @param string[] $calls Expected calls, as `Class->method`, `Class::method` or `function`.
	 * @param int      $limit Most frames to read, innermost first; 0 reads them all.
	 * @return bool
	 */
	public function is_call_in_backtrace_within( array $calls, int $limit ): bool {
		$expected_lookup = array_fill_keys( $calls, true );

		// Cheaper equivalent of wp_debug_backtrace_summary( null, 0, false ) + in_array():
		// DEBUG_BACKTRACE_IGNORE_ARGS skips capturing argument values, and the loop early-returns
		// on the first matching frame instead of formatting the whole stack into a summary array.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $limit ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Compatibility guard on the price/cart hot path.
			if ( isset( $expected_lookup[ self::get_frame_call( $frame ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count the frames of the current backtrace that are the given call.
	 *
	 * As client 11.3.0 `includes/multi-currency/Utils.php:71-81`, which reads it on each price-format call during a
	 * recalculation, so the arguments are not collected.
	 *
	 * @param string $call Call as `Class->method`, `Class::method` or `function`.
	 * @return int
	 */
	public function get_backtrace_call_count( string $call ): int {
		$count = 0;
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Recalculation frame count on the price-format path.
			if ( self::get_frame_call( $frame ) === $call ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get a backtrace frame's call, as `Class->method`, `Class::method` or `function`.
	 *
	 * @param array<string,mixed> $frame Backtrace frame.
	 * @return string
	 */
	private static function get_frame_call( array $frame ): string {
		$function = (string) ( $frame['function'] ?? '' );

		return isset( $frame['class'] ) ? $frame['class'] . ( $frame['type'] ?? '::' ) . $function : $function;
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
	 * Get the settings preview's choices from the query string, or from the referring page, for a user who manages the store.
	 *
	 * As client 11.3.0 MultiCurrency::get_multi_currency_onboarding_simulation_variables() (MultiCurrency.php:2049-2075):
	 * nothing is read for a user without `manage_woocommerce`, and a choice the preview leaves out reads as false.
	 *
	 * @since 11.0.0
	 *
	 * @return array<string,bool> The `enable_storefront_switcher` and `enable_auto_currency` choices; empty outside a preview.
	 */
	public static function get_settings_preview_params(): array {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return array();
		}

		$parameters = wc_clean( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$parameters = is_array( $parameters ) ? $parameters : array();

		if ( ! wp_validate_boolean( $parameters[ self::SETTINGS_PREVIEW_FLAG ] ?? false ) ) {
			$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
			if ( false === strpos( $referer, self::SETTINGS_PREVIEW_FLAG ) ) {
				return array();
			}

			$parameters = array();
			wp_parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $parameters );
			if ( ! wp_validate_boolean( $parameters[ self::SETTINGS_PREVIEW_FLAG ] ?? false ) ) {
				return array();
			}
		}

		return array(
			'enable_storefront_switcher' => wp_validate_boolean( $parameters['enable_storefront_switcher'] ?? false ),
			'enable_auto_currency'       => wp_validate_boolean( $parameters['enable_auto_currency'] ?? false ),
		);
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
