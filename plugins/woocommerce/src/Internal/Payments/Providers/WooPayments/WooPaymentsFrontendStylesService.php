<?php
/**
 * WooPaymentsFrontendStylesService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Keeps the styles cache version that stamps the stored checkout appearance, the card form's and WooPay's, and starts
 * a new version when the theme, its global styles, templates or Customizer settings change, or WooCommerce updates.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsFrontendStylesService implements RegisterHooksInterface {

	private const STYLES_CACHE_VERSION_OPTION = 'wcpay_styles_cache_version';

	/**
	 * Register shared frontend style lifecycle hooks.
	 */
	public function register() {
		foreach ( $this->get_style_change_hooks() as $hook ) {
			add_action( $hook, array( $this, 'handle_style_change' ) );
		}
	}

	/**
	 * Get the current frontend styles cache version.
	 *
	 * This version is shared by card Payment Element styling and WooPay appearance
	 * persistence so both sibling checkout surfaces invalidate cached appearance
	 * data together when the active theme or WooCommerce version changes.
	 *
	 * @return string
	 */
	public function get_or_create_styles_cache_version(): string {
		$version = get_option( self::STYLES_CACHE_VERSION_OPTION );

		if ( is_scalar( $version ) && '' !== (string) $version ) {
			return sanitize_text_field( (string) $version );
		}

		$version = md5( wp_generate_uuid4() . '|' . (string) wp_get_theme()->get_stylesheet() . '|' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) );
		update_option( self::STYLES_CACHE_VERSION_OPTION, $version, true );

		return $version;
	}

	/**
	 * Invalidate the frontend styles cache version.
	 */
	public function invalidate_styles_cache_version(): void {
		delete_option( self::STYLES_CACHE_VERSION_OPTION );
	}

	/**
	 * Invalidate shopper appearance caches after a visual lifecycle change.
	 */
	public function handle_style_change(): void {
		$this->invalidate_styles_cache_version();
	}

	/**
	 * Get lifecycle hooks that can change computed checkout appearance.
	 *
	 * @return string[]
	 */
	private function get_style_change_hooks(): array {
		return array(
			'after_switch_theme',
			'save_post_wp_global_styles',
			'customize_save_after',
			'save_post_wp_template_part',
			'save_post_wp_template',
			'woocommerce_updated',
		);
	}
}
