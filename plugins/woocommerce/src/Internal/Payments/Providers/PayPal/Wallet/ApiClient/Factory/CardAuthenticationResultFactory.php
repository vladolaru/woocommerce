<?php
/**
 * The card authentication result factory.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory;

use stdClass;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\CardAuthenticationResult;

/**
 * Class CardAuthenticationResultFactory
 */
class CardAuthenticationResultFactory {

	/**
	 * Returns a card authentication result from the given response object.
	 *
	 * @param stdClass $authentication_result The authentication result object.
	 * @return CardAuthenticationResult
	 */
	public function from_paypal_response( stdClass $authentication_result ): CardAuthenticationResult {
		return new CardAuthenticationResult(
			$authentication_result->liability_shift ?? '',
			$authentication_result->three_d_secure->enrollment_status ?? '',
			$authentication_result->three_d_secure->authentication_status ?? ''
		);
	}
}
