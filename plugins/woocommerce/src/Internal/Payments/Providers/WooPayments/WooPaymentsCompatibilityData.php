<?php
/**
 * WooPaymentsCompatibilityData class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Store data the platform uses to check WooPayments compatibility with the site.
 *
 * Ports the client's compatibility data (client 11.1.0 `includes/class-compatibility-service.php:85-139`), sent on
 * account refreshes and theme switches and with every onboarding request. The WooPayments version is the client release
 * native was verified against, as in every other version native reports to the platform.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsCompatibilityData {

	/**
	 * Build the compatibility data.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		return array(
			'woopayments_version'    => WooPaymentsClientVersion::VERSION,
			'woocommerce_version'    => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'woocommerce_permalinks' => get_option( 'woocommerce_permalinks', array() ),
			'woocommerce_shop'       => self::get_permalink_for_page_id( 'shop' ),
			'woocommerce_cart'       => self::get_permalink_for_page_id( 'cart' ),
			'woocommerce_checkout'   => self::get_permalink_for_page_id( 'checkout' ),
			'blog_theme'             => get_stylesheet(),
			'active_plugins'         => get_option( 'active_plugins', array() ),
			'post_types_count'       => self::get_post_types_count(),
		);
	}

	/**
	 * Get public post type publish counts.
	 *
	 * @return array<string,int>
	 */
	private static function get_post_types_count(): array {
		$post_types_count = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			$post_types_count[ $post_type ] = (int) wp_count_posts( $post_type )->publish;
		}

		return $post_types_count;
	}

	/**
	 * Gets the permalink for a WooCommerce page ID.
	 *
	 * @param string $page_id Page ID key.
	 * @return string
	 */
	private static function get_permalink_for_page_id( string $page_id ): string {
		$permalink = get_permalink( wc_get_page_id( $page_id ) );

		return $permalink ? $permalink : 'Not set';
	}
}
