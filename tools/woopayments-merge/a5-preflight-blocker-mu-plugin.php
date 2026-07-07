<?php
/**
 * Local-only A5 negative preflight proof helper.
 *
 * Copy this file into a target wp-env container's `wp-content/mu-plugins/`
 * directory when validating blocked cutover behavior. Remove it after the
 * probe. This enables the native runtime and adds a synthetic preflight marker
 * only; it does not force real transport, platform, admin, queue, or financial
 * readiness checks to pass.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );
add_filter(
	'woocommerce_woopayments_native_cutover_preflight_failures',
	static function ( $failures ): array {
		$failures = is_array( $failures ) ? $failures : array( 'preflight_filter_invalid' );
		$failures[] = 'a5_synthetic_preflight_blocker';

		return array_values( array_unique( array_map( 'strval', $failures ) ) );
	}
);
