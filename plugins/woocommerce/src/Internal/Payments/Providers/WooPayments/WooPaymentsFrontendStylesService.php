<?php
/**
 * WooPaymentsFrontendStylesService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Shared frontend styling helpers for native WooPayments checkout surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsFrontendStylesService implements RegisterHooksInterface {

	private const STYLES_CACHE_VERSION_OPTION = 'wcpay_styles_cache_version';

	private const STYLES_CACHE_SCHEMA_VERSION = 'appearance-extractor-v3';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register shared frontend style lifecycle hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		foreach ( $this->get_style_change_hooks() as $hook ) {
			if ( false === has_action( $hook, array( $this, 'handle_style_change' ) ) ) {
				add_action( $hook, array( $this, 'handle_style_change' ) );
			}
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
	public function get_styles_cache_version(): string {
		$version = get_option( self::STYLES_CACHE_VERSION_OPTION );

		if ( is_scalar( $version ) && '' !== (string) $version ) {
			return $this->append_schema_version( sanitize_text_field( (string) $version ) );
		}

		$version = md5( wp_generate_uuid4() . '|' . (string) wp_get_theme()->get_stylesheet() . '|' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '' ) );
		update_option( self::STYLES_CACHE_VERSION_OPTION, $version, true );

		return $this->append_schema_version( $version );
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

	/**
	 * Append the frontend style extractor schema version.
	 *
	 * @param string $version Theme/WooCommerce style cache version.
	 * @return string
	 */
	private function append_schema_version( string $version ): string {
		return $version . '|' . self::STYLES_CACHE_SCHEMA_VERSION;
	}
}
