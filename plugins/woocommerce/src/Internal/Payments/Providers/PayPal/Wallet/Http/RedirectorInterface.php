<?php
/**
 * HTTP redirection.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Api
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Http;

/**
 * Interface for HTTP redirection.
 */
interface RedirectorInterface {
	/**
	 * Starts HTTP redirection and shutdowns.
	 *
	 * @param string $location The URL to redirect to.
	 */
	public function redirect( string $location ): void;
}
