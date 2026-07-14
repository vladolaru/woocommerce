<?php
/**
 * Local-only native payments runtime toggle for WooPayments merge verification.
 *
 * Copy this file into a target wp-env container's `wp-content/mu-plugins/`
 * directory when running native-owned local probes. Remove it after the probe.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );
