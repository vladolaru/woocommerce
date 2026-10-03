<?php
/**
 * Test double for the PayPal wallet gateway that counts the saved-method calls.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;

/**
 * Replaces the parent's side-effectful saved-payment-method methods with call-count spies.
 */
class SpyablePayPalGateway extends PayPalGateway {

	/**
	 * How many times saved_payment_methods() was called.
	 *
	 * @var int
	 */
	public int $saved_payment_methods_call_count = 0;

	/**
	 * How many times tokenization_script() was called.
	 *
	 * @var int
	 */
	public int $tokenization_script_call_count = 0;

	/**
	 * Set the features the gateway reports as supported.
	 *
	 * @param string[] $supports The features.
	 */
	public function set_test_supports( array $supports ): void {
		$this->supports = $supports;
	}

	/**
	 * Whether the feature is in the list set through set_test_supports().
	 *
	 * @param string $feature The feature.
	 * @return bool
	 */
	public function supports( $feature ): bool {
		return in_array( $feature, $this->supports, true );
	}

	/**
	 * Count the call instead of rendering the saved methods.
	 */
	public function saved_payment_methods(): void {
		++$this->saved_payment_methods_call_count;
	}

	/**
	 * Count the call instead of enqueueing the tokenization script.
	 */
	public function tokenization_script(): void {
		++$this->tokenization_script_call_count;
	}

	/**
	 * No description, so payment_fields() prints nothing besides the saved-method UI.
	 *
	 * @return string
	 */
	public function get_description(): string {
		return '';
	}
}
