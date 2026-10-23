<?php
/**
 * The Payee Factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payee;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class PayeeFactory
 */
class PayeeFactory {

	/**
	 * Returns a Payee object based off a PayPal Response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return Payee|null
	 * @throws RuntimeException When JSON object is malformed.
	 */
	public function from_paypal_response( \stdClass $data ) {
		$email       = ( isset( $data->email_address ) ) ? $data->email_address : '';
		$merchant_id = ( isset( $data->merchant_id ) ) ? $data->merchant_id : '';
		return new Payee( $email, $merchant_id );
	}
}
