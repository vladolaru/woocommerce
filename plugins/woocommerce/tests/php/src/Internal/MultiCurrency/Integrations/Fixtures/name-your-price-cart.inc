<?php
/**
 * Name Your Price 4.0.1 exposes its cart service through __get() alone.
 *
 * @package WooCommerce\Tests
 */

if ( ! function_exists( 'WC_Name_Your_Price' ) ) {
	/** The extension's global singleton accessor. */
	function WC_Name_Your_Price() {
		return $GLOBALS['wc_name_your_price'];
	}
}

return new class() {
	/** @var string Installed extension version. */
	public $version = '4.0.1';

	/**
	 * Resolve the cart service when the integration reads the property.
	 *
	 * @param string $key Requested property.
	 */
	public function __get( $key ) {
		if ( 'cart' === $key ) {
			return new class() {
				/**
				 * Apply the shopper's amount to the cart product.
				 *
				 * @param array $line Name Your Price cart line.
				 * @return array
				 */
				public function set_cart_item( $line ) {
					$line['data']->set_price( $line['nyp'] );
					return $line;
				}
			};
		}
	}
};
