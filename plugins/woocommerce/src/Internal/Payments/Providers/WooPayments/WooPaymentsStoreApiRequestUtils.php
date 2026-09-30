<?php
/**
 * WooPaymentsStoreApiRequestUtils class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Store API request detection shared by the native WooPayments Store API hooks.
 *
 * Ports `WC_Payments_Utils::is_store_api_request()` from WooPayments 11.1.0: an allowlist of routes
 * rather than core's `/wc/store/` substring check.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsStoreApiRequestUtils {

	/**
	 * Store API route patterns; matches the WooPayments plugin allowlist byte for byte. The last route is not
	 * a Store API route: WooPay uses it to indirectly reach the Store API.
	 */
	private const STORE_API_ROUTE_PATTERNS = array(
		'@^\/wc\/store(\/v[\d]+)?\/cart$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/add-item$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/remove-item$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/apply-coupon$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/remove-coupon$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/select-shipping-rate$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/update-customer$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/update-item$@',
		'@^\/wc\/store(\/v[\d]+)?\/cart\/extensions$@',
		'@^\/wc\/store(\/v[\d]+)?\/checkout\/(?P<id>[\d]+)@',
		'@^\/wc\/store(\/v[\d]+)?\/checkout$@',
		'@^\/wc\/store(\/v[\d]+)?\/order\/(?P<id>[\d]+)@',
		'@^\/payments\/woopay\/session$@',
	);

	/**
	 * Tell whether the current request targets one of the allowlisted Store API routes.
	 *
	 * @return bool
	 */
	public static function is_store_api_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only route detection, mirroring the plugin.
		if ( isset( $_REQUEST['rest_route'] ) ) {
			$rest_route = sanitize_text_field( wp_unslash( $_REQUEST['rest_route'] ) );
		} else {
			$rest_route = self::extract_rest_route_from_url();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_string( $rest_route ) || '' === $rest_route ) {
			return false;
		}

		foreach ( self::STORE_API_ROUTE_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $rest_route ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the REST route from the request URL.
	 *
	 * @return string
	 */
	private static function extract_rest_route_from_url(): string {
		$url_parts = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );
		if ( ! is_array( $url_parts ) || empty( $url_parts['path'] ) ) {
			return '';
		}

		$request_path = rtrim( $url_parts['path'], '/' );
		if ( '' === $request_path ) {
			return '';
		}

		$rest_prefix = trailingslashit( rest_get_url_prefix() );

		// For multisite subdirectory setups, look for the REST prefix anywhere in the path
		// and keep everything after it.
		$rest_prefix_pos = strpos( $request_path, '/' . rtrim( $rest_prefix, '/' ) );
		if ( false !== $rest_prefix_pos ) {
			return substr( $request_path, $rest_prefix_pos + strlen( $rest_prefix ) );
		}

		return str_replace( $rest_prefix, '', $request_path );
	}
}
