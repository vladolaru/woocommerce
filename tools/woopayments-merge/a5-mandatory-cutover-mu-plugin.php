<?php
/**
 * Local-only A5 mandatory WooPayments cutover helper.
 *
 * Copy this file into a target wp-env container's `wp-content/mu-plugins/`
 * directory when validating mandatory cutover behavior. Remove it after the
 * probe. This enables the native runtime and mandatory cutover gate only; it
 * does not force transport, platform, admin, queue, or financial readiness.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );
add_filter( 'woocommerce_woopayments_mandatory_cutover_enabled', '__return_true' );
