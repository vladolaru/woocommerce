<?php
/**
 * Interface for all integration controllers.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat;

interface Integration {

	/**
	 * Integrates some (possibly external) service with PayPal Payments.
	 */
	public function integrate(): void;
}
