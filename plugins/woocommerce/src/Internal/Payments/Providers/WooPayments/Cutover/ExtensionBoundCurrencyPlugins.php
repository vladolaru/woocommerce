<?php
/**
 * Multi-Currency plugins that hold a WooPayments switch.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;

/**
 * Matches activation and carried-over currency settings before the extension hands over payments.
 *
 * @internal
 * @since 11.4.0
 */
final class ExtensionBoundCurrencyPlugins {

	/** Removing the additional currencies clears these holds. */
	public const ADDITIONAL = 'additional';

	/** Only deactivating the plugin clears these holds. */
	public const ACTIVE = 'active';

	/** Folder slug and the condition under which its integration requires the extension. */
	private const PLUGINS = array(
		'advanced-coupons-for-woocommerce-free' => self::ADDITIONAL, // Active-file check skips coupon threshold conversion.
		'easy-woocommerce-discounts'            => self::ADDITIONAL, // Extension file constant guards discount thresholds.
		'gift-cards-pro-for-woocommerce'        => self::ADDITIONAL, // Loader function guards gift card redemption.
		'pickset-product-options'               => self::ADDITIONAL, // Extension currency manager converts fixed options.
		'product-addons'                        => self::ADDITIONAL, // WowAddons uses the extension manager for fixed prices.
		'woocommerce-smart-coupons'             => self::ADDITIONAL, // Active-file check skips store credit conversion.
		'auctions-made-easy-for-woocommerce'    => self::ACTIVE,     // Checks only the payments class before pricing a bid.
		'tiered-pricing-for-woocommerce'        => self::ACTIVE,     // Checks only the payments class before loading currency classes.
	);

	/**
	 * Find active blockers by group. Only a matching conditional plugin needs currency settings.
	 *
	 * @param array<int|string,mixed> $files Filtered and stored active plugin files.
	 * @return array<string,string[]> Nonempty groups of plugin files.
	 */
	public static function get_groups( array $files ): array {
		$groups = array();
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) ) {
				continue;
			}
			$slug = dirname( $file );
			if ( isset( self::PLUGINS[ $slug ] ) ) {
				$groups[ self::PLUGINS[ $slug ] ][ $file ] = $file;
			}
		}
		if ( isset( $groups[ self::ADDITIONAL ] ) && ! self::has_additional_currency() ) {
			unset( $groups[ self::ADDITIONAL ] );
		}
		return array_map( 'array_values', $groups );
	}

	/**
	 * Check the extension's saved settings before the built-in owner is selected.
	 */
	private static function has_additional_currency(): bool {
		if ( '1' !== (string) get_option( MultiCurrencyFeatureController::WOOPAYMENTS_MULTI_CURRENCY_FLAG_OPTION, '1' )
			|| 'no' === get_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION ) ) {
			return false;
		}
		$store = strtoupper( (string) get_option( 'woocommerce_currency', 'USD' ) );
		$codes = get_option( StateBuilder::ENABLED_CURRENCIES_OPTION, array() );
		foreach ( is_array( $codes ) ? $codes : array() as $code ) {
			if ( is_string( $code ) && '' !== $code && strtoupper( $code ) !== $store ) {
				return true;
			}
		}
		return false;
	}
}
