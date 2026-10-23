<?php
/**
 * Stub file for the constants the PayPal wallet code reads.
 *
 * Analysis-only: it is listed in `scanFiles` in phpstan.neon and is never loaded at runtime. The PayPal Payments
 * extension's main file defines these constants, and core defines them for the wallet in
 * PayPalWalletBootstrap::define_constants(), in a loop PHPStan cannot follow. The values here are placeholders;
 * the real ones live in PayPalWalletBootstrap::EXTENSION_CONSTANTS.
 *
 * @see \Automattic\WooCommerce\Internal\Payments\Providers\PayPal\PayPalWalletBootstrap::define_constants()
 */

define( 'PAYPAL_API_URL', '' );
define( 'PAYPAL_URL', '' );
define( 'PAYPAL_SANDBOX_API_URL', '' );
define( 'PAYPAL_SANDBOX_URL', '' );
define( 'PAYPAL_INTEGRATION_DATE', '' );
define( 'PPCP_PAYPAL_BN_CODE', '' );
define( 'CONNECT_WOO_CLIENT_ID', '' );
define( 'CONNECT_WOO_SANDBOX_CLIENT_ID', '' );
define( 'CONNECT_WOO_MERCHANT_ID', '' );
define( 'CONNECT_WOO_SANDBOX_MERCHANT_ID', '' );
define( 'CONNECT_WOO_URL', '' );
define( 'CONNECT_WOO_SANDBOX_URL', '' );
