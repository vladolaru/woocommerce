<?php
/**
 * The connect dummy bearer.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Authentication;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;

/**
 * Class ConnectBearer
 */
class ConnectBearer implements Bearer {

	/**
	 * Returns the bearer.
	 *
	 * @return Token
	 */
	public function bearer(): Token {
		$data = (object) array(
			'created'    => time(),
			'expires_in' => 3600,
			'token'      => 'token',
		);
		return new Token( $data );
	}
}
