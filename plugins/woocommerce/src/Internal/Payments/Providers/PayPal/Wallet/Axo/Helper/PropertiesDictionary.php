<?php
/**
 * Properties of the AXO module.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Helper;

/**
 * Class PropertiesDictionary
 */
class PropertiesDictionary {

	/**
	 * Returns the list of possible cardholder name options.
	 *
	 * @return array
	 */
	public static function cardholder_name_options(): array {
		return array(
			'yes' => __( 'Yes', 'woocommerce' ),
			'no'  => __( 'No', 'woocommerce' ),
		);
	}
}
