<?php
/**
 * The Endpoint interface.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Endpoint;

/**
 * Interface EndpointInterface
 */
interface EndpointInterface {

	/**
	 * Returns the nonce for an endpoint.
	 */
	public static function nonce(): string;

	/**
	 * Handles the request for an endpoint.
	 */
	public function handle_request(): void;
}
