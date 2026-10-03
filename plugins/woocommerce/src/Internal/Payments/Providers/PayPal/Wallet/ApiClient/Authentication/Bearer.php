<?php
/**
 * The bearer interface.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;

/**
 * Interface Bearer
 */
interface Bearer {

	/**
	 * Returns the bearer.
	 *
	 * @return Token
	 */
	public function bearer(): Token;
}
