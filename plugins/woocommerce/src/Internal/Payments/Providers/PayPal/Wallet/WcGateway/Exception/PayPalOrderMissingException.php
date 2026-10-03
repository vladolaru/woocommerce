<?php
/**
 * Thrown when there is no PayPal order during WC order processing.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Exception;

use Exception;

/**
 * Class PayPalOrderMissingException
 */
class PayPalOrderMissingException extends Exception {
}
