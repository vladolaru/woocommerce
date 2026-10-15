<?php
/**
 * WooPaymentsConnectionFeatures class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Config as JetpackConfig;
use Automattic\Jetpack\Identity_Crisis;
use Automattic\Jetpack\Sync\Data_Settings;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Turns on Jetpack's Safe Mode and Sync for the store's WordPress.com connection once WooPayments is set up.
 *
 * Safe Mode shows WooCommerce's banner when a copy of the store shares the connection. Sync keeps WordPress.com's copy of
 * the store name, description, timezone and active plugins current.
 * It loads on every request of a connected store, while WooCommerce loads, so jetpack-config enables both on
 * `plugins_loaded`, Safe Mode before Sync sends anything.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsConnectionFeatures implements RegisterHooksInterface {

	/**
	 * The Safe Mode support document.
	 */
	private const SAFE_MODE_DOCUMENT_URL = 'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/safe-mode/';

	/**
	 * Ask jetpack-config for the connection's features.
	 */
	public function register() {
		$config = new JetpackConfig();
		$config->ensure(
			'identity_crisis',
			array(
				'slug'          => 'woocommerce',
				// A callable, so the copy is translated when the banner renders.
				'customContent' => array( $this, 'get_safe_mode_content' ),
				// This runs inside the WooCommerce constructor, so WC() is not available yet.
				'logo'          => plugins_url( 'assets/images/woo-logo.svg', WC_PLUGIN_FILE ),
				'admin_page'    => $this->get_admin_page(),
				'priority'      => 5,
			)
		);
		// The extension's settings, as client 11.1.0 woocommerce-payments.php:114-133 passes them.
		$config->ensure(
			'sync',
			array_merge_recursive(
				Data_Settings::MUST_SYNC_DATA_SETTINGS,
				array(
					'jetpack_sync_modules'           => array(
						'Automattic\\Jetpack\\Sync\\Modules\\Full_Sync_Immediately',
						'Automattic\\Jetpack\\Sync\\Modules\\Options',
						'Automattic\\Jetpack\\Sync\\Modules\\Posts',
						'Automattic\\Jetpack\\Sync\\Modules\\Meta',
					),
					'jetpack_sync_options_whitelist' => array(
						'active_plugins',
						'blogdescription',
						'blogname',
						'timezone_string',
						'gmt_offset',
					),
				)
			)
		);
	}

	/**
	 * Read Jetpack Sync as off until a plugin asks jetpack-config for it.
	 *
	 * The connection reports active plugins and package versions to WordPress.com itself only while Sync is off, and Sync
	 * stores its default setting the first time it is read; this keeps both as they were on stores where nothing runs Sync.
	 * Every plugin that turns Sync on fills Sync's data settings when it calls Config::ensure( 'sync' ).
	 *
	 * @internal
	 *
	 * @param mixed $pre The value an earlier pre_option filter returned, or false.
	 * @return mixed 1 while no plugin asked for Sync in this request, otherwise $pre unchanged.
	 */
	public static function report_sync_off_until_ensured( $pre ) {
		return array() === ( new Data_Settings() )->get_data_settings() ? 1 : $pre;
	}

	/**
	 * Get the Safe Mode banner copy, which names the store's WordPress.com connection and never a payment provider.
	 *
	 * @internal
	 *
	 * @return array<string, string> The banner copy, keyed as the connection package reads it.
	 */
	public function get_safe_mode_content(): array {
		$content = array(
			'headerText'                => __( 'Safe Mode', 'woocommerce' ),
			'mainTitle'                 => __( 'Safe Mode activated', 'woocommerce' ),
			'mainBodyText'              => __( 'We’ve detected that you have duplicate sites connected to this store’s WordPress.com connection. While Safe Mode is active, services that use this connection may be limited until you resolve this issue below. Safe Mode is most often activated when you move your site from one domain to another, or create a staging site for testing. <safeModeLink>Learn more</safeModeLink>', 'woocommerce' ),
			'migratedTitle'             => __( 'WordPress.com connection transferred', 'woocommerce' ),
			'migratedBodyText'          => __( 'Safe Mode is off and services that use this store’s WordPress.com connection are fully available again.', 'woocommerce' ),
			'migrateCardTitle'          => __( 'Transfer connection', 'woocommerce' ),
			'migrateButtonLabel'        => __( 'Transfer your connection', 'woocommerce' ),
			'startFreshCardTitle'       => __( 'Create a new connection', 'woocommerce' ),
			'startFreshButtonLabel'     => __( 'Create a new connection', 'woocommerce' ),
			'nonAdminTitle'             => __( 'Safe Mode activated', 'woocommerce' ),
			'nonAdminBodyText'          => __( 'We’ve detected that you have duplicate sites connected to this store’s WordPress.com connection. While Safe Mode is active, services that use this connection may be limited until the issue is resolved. Safe Mode is most often activated when you move your site from one domain to another, or create a staging site for testing. A site administrator can resolve this issue. <safeModeLink>Learn more</safeModeLink>', 'woocommerce' ),
			'supportURL'                => self::SAFE_MODE_DOCUMENT_URL,
			'adminBarSafeModeLabel'     => __( 'WooCommerce Safe Mode', 'woocommerce' ),
			'stayInSafeModeButtonLabel' => __( 'Stay in Safe Mode', 'woocommerce' ),
			'dynamicSiteUrlText'        => __( "<strong>Notice:</strong> It appears that your 'wp-config.php' file might be using dynamic site URL values. Dynamic site URLs could cause WooCommerce to enter Safe Mode. <dynamicSiteUrlSupportLink>Learn how to set a static site URL.</dynamicSiteUrlSupportLink>", 'woocommerce' ),
			'dynamicSiteUrlSupportLink' => self::SAFE_MODE_DOCUMENT_URL . '#dynamic-site-urls',
		);

		$urls = Identity_Crisis::get_mismatched_urls();
		if ( ! is_array( $urls ) ) {
			return $content;
		}

		$current_url = untrailingslashit( $urls['current_url'] );
		$wpcom_url   = untrailingslashit( $urls['wpcom_url'] );

		$content['migrateCardBodyText'] = sprintf(
			/* translators: %1$s: This site's domain name. %2$s: The original site's domain name. Keep the hostname tags. */
			__( 'Transfer this store’s WordPress.com connection from <hostname>%2$s</hostname> to this site <hostname>%1$s</hostname>. <hostname>%2$s</hostname> will be disconnected from WordPress.com.', 'woocommerce' ),
			$current_url,
			$wpcom_url
		);

		$content['startFreshCardBodyText'] = sprintf(
			/* translators: %1$s: This site's domain name. %2$s: The original site's domain name. Keep the hostname tags. */
			__( 'Create a new WordPress.com connection for <hostname>%1$s</hostname>. Services that use the connection may need to be set up again. Your <hostname>%2$s</hostname> connection will remain as is.', 'woocommerce' ),
			$current_url,
			$wpcom_url
		);

		$content['startFreshCardBodyTextDev'] = sprintf(
			/* translators: %1$s: This site's domain name. %2$s: The original site's domain name. Keep the hostname and list tags. */
			__( '<p><strong>Recommended for</strong></p><list><item>development sites</item><item>sites that need every service that uses the connection</item></list><p><strong>Please note</strong> that creating a fresh connection for <hostname>%1$s</hostname> would require restoring the connection on <hostname>%2$s</hostname> if that site is cloned back to production. <safeModeLink>Learn more</safeModeLink>.</p>', 'woocommerce' ),
			$current_url,
			$wpcom_url
		);

		$content['safeModeCardBodyText'] = __( '<p><strong>Recommended for</strong></p><list><item>short-lived test sites</item><item>sites that will be cloned back to production after testing</item></list><p><strong>Please note</strong> that staying in Safe Mode limits services that use this store’s WordPress.com connection. <safeModeLink>Learn more</safeModeLink>.</p>', 'woocommerce' );

		$content['mainBodyTextDev'] = sprintf(
			/* translators: %1$s: This site's domain name. %2$s: The original site's domain name. Keep the hostname tags. */
			__( '<span>Your site is in Safe Mode because <hostname>%1$s</hostname> appears to be a staging or development copy of <hostname>%2$s</hostname>.</span> Two sites are telling WordPress.com they’re the same site. <safeModeLink>Learn more about Safe Mode issues</safeModeLink>.', 'woocommerce' ),
			$current_url,
			$wpcom_url
		);

		return $content;
	}

	/**
	 * Get the WooCommerce admin page path the connection matches against the request URI to pick this banner.
	 *
	 * Built from admin_url(), so it also matches when WordPress lives in a subdirectory.
	 *
	 * @return string
	 */
	private function get_admin_page(): string {
		$admin_path = wp_parse_url( admin_url( 'admin.php' ), PHP_URL_PATH );

		return ( is_string( $admin_path ) && '' !== $admin_path ? $admin_path : '/wp-admin/admin.php' ) . '?page=wc-admin';
	}
}
