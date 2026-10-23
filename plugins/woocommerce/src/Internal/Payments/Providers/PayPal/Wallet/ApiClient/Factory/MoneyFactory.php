<?php
/**
 * The Money factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use stdClass;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Money;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class MoneyFactory
 */
class MoneyFactory {

	/**
	 * Returns a Money object based off a PayPal Response.
	 *
	 * @param stdClass $data The JSON object.
	 *
	 * @return Money
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( stdClass $data ): Money {
		if ( ! isset( $data->value ) || ! is_numeric( $data->value ) ) {
			throw new RuntimeException( 'No money value given' );
		}
		if ( ! isset( $data->currency_code ) ) {
			throw new RuntimeException( 'No currency given' );
		}

		return new Money( (float) $data->value, $data->currency_code );
	}
}
