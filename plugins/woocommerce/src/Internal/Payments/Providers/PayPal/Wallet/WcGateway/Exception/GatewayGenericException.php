<?php
/**
 * Wrapper for more detailed gateway error.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception;

use Exception;
use Throwable;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\Messages;

/**
 * Class GatewayGenericException
 */
class GatewayGenericException extends Exception {
	/**
	 * GatewayGenericException constructor.
	 *
	 * @param Throwable|null $inner The exception.
	 */
	public function __construct( ?Throwable $inner = null ) {
		parent::__construct(
			Messages::generic_payment_error_message(),
			$inner ? (int) $inner->getCode() : 0,
			$inner
		);
	}
}
