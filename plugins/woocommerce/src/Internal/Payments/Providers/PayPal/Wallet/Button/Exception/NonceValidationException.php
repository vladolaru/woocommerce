<?php
/**
 * NonceValidationException.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception;

/**
 * Thrown when nonce validation fails on an AJAX endpoint request.
 */
class NonceValidationException extends RuntimeException {

}
