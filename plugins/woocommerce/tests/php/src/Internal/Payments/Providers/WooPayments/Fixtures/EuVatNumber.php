<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures;

/**
 * EU VAT Number's public gateway API, available since 3.2.2.
 */
class EuVatNumber {
	/**
	 * Load the plugin in an isolated test process, with its B2B option on.
	 *
	 * @param bool $modern Whether the gateway API exists.
	 * @param bool $ready  Whether the plugin passed its dependency checks.
	 */
	public static function load( bool $modern = true, bool $ready = true ): void {
		$legacy = new class() {};
		class_alias( $modern ? self::class : get_class( $legacy ), 'WC_EU_VAT_Number_Init' );
		if ( $ready ) {
			class_alias( self::class, 'WC_EU_VAT_Number' );
		}
		update_option( 'woocommerce_eu_vat_number_b2b', 'yes' );
	}

	/**
	 * Return the modern plugin's filtered B2B decision.
	 */
	public static function should_prevent_incompatible_payment_methods(): bool {
		return 'yes' === get_option( 'woocommerce_eu_vat_number_b2b', 'no' )
			&& apply_filters( 'wc_eu_vat_prevent_incompatible_payment_methods', 'yes' === get_option( 'woocommerce_eu_vat_number_prevent_incompatible_payment_methods', 'yes' ) );
	}

	/**
	 * Merchant choices that must survive whole-option writes.
	 *
	 * @return array<string,mixed>
	 */
	public static function wallet_settings(): array {
		return array(
			'payment_request'                     => 'yes',
			'platform_checkout'                   => 'yes',
			'express_checkout_in_payment_methods' => 'yes',
			'express_checkout_product_methods'    => array( 'payment_request', 'amazon_pay', 'woopay' ),
			'express_checkout_cart_methods'       => array( 'amazon_pay', 'woopay' ),
			'express_checkout_checkout_methods'   => array( 'payment_request', 'woopay', 'amazon_pay' ),
		);
	}
}
