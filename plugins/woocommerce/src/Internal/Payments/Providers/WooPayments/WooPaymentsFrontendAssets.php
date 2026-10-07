<?php
/**
 * WooPaymentsFrontendAssets class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;

/**
 * Registers frontend assets shared by native WooPayments surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsFrontendAssets {

	public const APPEARANCE_SCRIPT_HANDLE = 'wc-woopayments-appearance';

	/**
	 * Stripe.js script handle.
	 */
	public const STRIPE_SCRIPT_HANDLE = 'stripe';

	/**
	 * Stripe.js URL. Stripe serves v3 from its own domain only and asks sites not to pin or bundle it.
	 */
	private const STRIPE_SCRIPT_URL = 'https://js.stripe.com/v3/';

	/**
	 * Core's mobile phone validation (packages/js/components phone-number-input), bundled once by the Blocks build.
	 */
	private const PHONE_VALIDATION_SCRIPT_PATH = 'assets/client/blocks/wc-woopayments-phone-validation.js';

	/**
	 * Register Stripe.js for every native WooPayments surface that needs it, unless the handle is already registered.
	 *
	 * @since 11.2.0
	 */
	public static function register_stripe_script(): void {
		if ( wp_script_is( self::STRIPE_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_script( self::STRIPE_SCRIPT_HANDLE, self::STRIPE_SCRIPT_URL, array(), null, true );
	}

	/**
	 * Register the shared Stripe Elements appearance utility.
	 */
	public static function register_appearance_script(): void {
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
	 * Get the post the current request renders: the global post, which has_block() reads when given no post, as client
	 * 11.1.0's surface checks call it. On a singular page it is the queried post; on an archive, the loop's first post.
	 *
	 * Blocks a pattern or reusable block references are not resolved, as has_block() does not resolve them.
	 *
	 * @since 11.2.0
	 *
	 * @return \WP_Post|null
	 */
	public static function get_current_post(): ?\WP_Post {
		$post = get_post();

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Tell whether the post the current request renders contains a block.
	 *
	 * @since 11.2.0
	 *
	 * @param string $block_name Block name.
	 * @return bool
	 */
	public static function current_post_has_block( string $block_name ): bool {
		$post = self::get_current_post();

		return null !== $post && has_block( $block_name, $post );
	}

	/**
	 * Tell whether the post the current request renders contains a shortcode.
	 *
	 * @since 11.2.0
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	public static function current_post_has_shortcode( string $tag ): bool {
		$post = self::get_current_post();

		return null !== $post && has_shortcode( $post->post_content, $tag );
	}

	/**
	 * URL of the phone validation script the Blocks and classic WooPay save-user sections load when the shopper opts in.
	 *
	 * It is not registered as a handle: nothing loads it with the page.
	 *
	 * @return string
	 */
	public static function get_phone_validation_script_url(): string {
		return add_query_arg( 'ver', WC_VERSION, WC()->plugin_url() . '/' . self::PHONE_VALIDATION_SCRIPT_PATH );
	}
}
