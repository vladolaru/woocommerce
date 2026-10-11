<?php
/**
 * WooPaymentsTerminalCardFormatter class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Formats card-present brands and networks for WooPayments receipt surfaces.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsTerminalCardFormatter {

	/**
	 * Get the display name for a provider card brand.
	 *
	 * @param string $brand Provider card brand.
	 * @return string
	 */
	public static function get_card_brand_display_name( string $brand ): string {
		$brand = strtolower( str_replace( '-', '_', $brand ) );
		$names = array(
			'cartes_bancaires' => __( 'Cartes Bancaires', 'woocommerce' ),
			'cb'               => __( 'Cartes Bancaires', 'woocommerce' ),
			'eftpos'           => __( 'eftpos', 'woocommerce' ),
			'eftpos_au'        => __( 'eftpos', 'woocommerce' ),
		);

		return $names[ $brand ] ?? ucfirst( $brand );
	}

	/**
	 * Get the terminal-card asset name for a provider brand or network.
	 *
	 * A non-empty result identifies terminal networks that take display
	 * precedence over the card brand in the standalone WooPayments runtime.
	 *
	 * @param string $brand Provider card brand or network.
	 * @return string
	 */
	public static function get_terminal_card_brand_asset_name( string $brand ): string {
		$brand = strtolower( str_replace( '-', '_', $brand ) );
		$names = array(
			'cartes_bancaires' => 'cartes_bancaires',
			'cb'               => 'cartes_bancaires',
			'eftpos'           => 'eftpos_au',
			'eftpos_au'        => 'eftpos_au',
		);

		return $names[ $brand ] ?? '';
	}

	/**
	 * Select the card-present brand that should be displayed.
	 *
	 * @param array<string,mixed> $card_present Card-present payment method details.
	 * @return string
	 */
	public static function get_terminal_card_display_brand( array $card_present ): string {
		$network = is_string( $card_present['network'] ?? null ) ? $card_present['network'] : '';
		if ( '' !== self::get_terminal_card_brand_asset_name( $network ) ) {
			return $network;
		}

		return is_string( $card_present['brand'] ?? null ) ? $card_present['brand'] : '';
	}

	/**
	 * Get the display name for a card-present payment method.
	 *
	 * @param array<string,mixed> $card_present Card-present payment method details.
	 * @return string
	 */
	public static function get_terminal_card_display_name( array $card_present ): string {
		return self::get_card_brand_display_name( self::get_terminal_card_display_brand( $card_present ) );
	}
}
