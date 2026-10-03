<?php
/**
 * The Payee Repository.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payee;

/**
 * Class PayeeRepository
 */
class PayeeRepository {

	/**
	 * The merchant email.
	 *
	 * @var string
	 */
	private $merchant_email;

	/**
	 * The merchant ID.
	 *
	 * @var string
	 */
	private $merchant_id;

	/**
	 * PayeeRepository constructor.
	 *
	 * @param string $merchant_email The email of the merchant.
	 * @param string $merchant_id The ID of the merchant.
	 */
	public function __construct( string $merchant_email, string $merchant_id ) {
		$this->merchant_email = $merchant_email;
		$this->merchant_id    = $merchant_id;
	}

	/**
	 * Returns the current Payee.
	 *
	 * @return Payee
	 */
	public function payee(): Payee {
		return new Payee(
			$this->merchant_email,
			$this->merchant_id
		);
	}
}
