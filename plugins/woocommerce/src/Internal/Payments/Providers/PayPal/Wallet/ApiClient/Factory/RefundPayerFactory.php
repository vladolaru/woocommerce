<?php
/**
 * The RefundPayerFactory factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerName;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerTaxInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Phone;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PhoneWithType;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\RefundPayer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

/**
 * Class RefundPayerFactory
 */
class RefundPayerFactory {

	/**
	 * Returns a Refund Payer object based off a PayPal Response.
	 *
	 * @param \stdClass $data The JSON object.
	 *
	 * @return RefundPayer
	 */
	public function from_paypal_response( \stdClass $data ): RefundPayer {
		return new RefundPayer(
			isset( $data->email_address ) ? $data->email_address : '',
			isset( $data->merchant_id ) ? $data->merchant_id : ''
		);
	}
}
