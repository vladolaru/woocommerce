<?php
/**
 * WooPaymentsFrontendAssets class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;

/**
 * Registers the script handles the WooPayments shopper scripts share: Stripe.js, the Stripe Elements appearance
 * utility and FingerprintJS. Each surface registers its own scripts and lists these as dependencies.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsFrontendAssets {

	/**
	 * Stripe Elements appearance utility script handle.
	 */
	public const APPEARANCE_SCRIPT_HANDLE = 'wc-woopayments-appearance';

	/**
	 * Stripe.js script handle.
	 */
	public const STRIPE_SCRIPT_HANDLE = 'stripe';

	/**
	 * Handle for the vendored FingerprintJS device-fingerprinting script.
	 */
	public const FINGERPRINT_SCRIPT_HANDLE = 'wc-woopayments-fingerprintjs';

	/**
	 * Handle for the WooPay protocol the card and WooPay scripts share on both checkouts (`window.wc.woopayments.woopay`).
	 */
	public const WOOPAY_COMMON_SCRIPT_HANDLE = 'wc-woopayments-woopay-common';

	/**
	 * Stripe.js URL. Stripe serves v3 from its own domain only and asks sites not to pin or bundle it.
	 */
	private const STRIPE_SCRIPT_URL = 'https://js.stripe.com/v3/';

	/**
	 * Register Stripe.js for the WooPayments surfaces that need it, unless the `stripe` handle is already registered.
	 *
	 * @since 11.2.0
	 */
	public function register_stripe_script(): void {
		if ( wp_script_is( self::STRIPE_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_script( self::STRIPE_SCRIPT_HANDLE, self::STRIPE_SCRIPT_URL, array(), null, true );
	}

	/**
	 * Register the shared Stripe Elements appearance utility.
	 */
	public function register_appearance_script(): void {
		if ( wp_script_is( self::APPEARANCE_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		$suffix = Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
		wp_register_script(
			self::APPEARANCE_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/js/frontend/utils/woopayments-appearance' . $suffix . '.js',
			array(),
			WC_VERSION,
			true
		);
	}

	/**
	 * Register the vendored FingerprintJS script, which sets `window.FingerprintJS`.
	 *
	 * Classic checkout and the Blocks card script both load it from this one handle
	 * to compute the buyer device fingerprint the platform's risk rules score on.
	 *
	 * @since 11.2.0
	 *
	 * @return void
	 */
	public function register_fingerprint_script(): void {
		if ( wp_script_is( self::FINGERPRINT_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		// Pre-minified upstream UMD build, so there is no suffix variant.
		wp_register_script(
			self::FINGERPRINT_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/js/fingerprintjs/fp.umd.min.js',
			array(),
			WC_VERSION,
			true
		);
	}

	/**
	 * Register the WooPay protocol the card and WooPay scripts share, which sets `window.wc.woopayments.woopay`.
	 *
	 * The Blocks build emits the file; classic scripts list this handle and Blocks scripts reach it as a build external.
	 * It lists no dependency: the build's asset file names only wp-polyfill, which the classic scripts that read the
	 * protocol already run without.
	 *
	 * @since 11.4.0
	 */
	public function register_woopay_common_script(): void {
		if ( wp_script_is( self::WOOPAY_COMMON_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_script(
			self::WOOPAY_COMMON_SCRIPT_HANDLE,
			WC()->plugin_url() . '/assets/client/blocks/wc-woopayments-woopay-common.js',
			array(),
			WC_VERSION,
			true
		);
	}
}
