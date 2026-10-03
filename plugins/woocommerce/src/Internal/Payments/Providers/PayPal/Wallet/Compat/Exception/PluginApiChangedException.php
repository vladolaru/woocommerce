<?php
/**
 * The modules Runtime Exception.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\Exception
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\Exception;

/**
 * Thrown when an API method of a plugin doesn't exist although that plugin is active.
 */
class PluginApiChangedException extends \RuntimeException {


}
