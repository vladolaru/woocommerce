<?php
/**
 * Test double for the v6 SDK manager that decides the free-trial product check itself.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SdkV6\Assets\SdkV6Manager;

/**
 * The real free-trial product check reads WooCommerce Subscriptions' static classes, which core's test suite does not
 * load. This double answers it from a property so the render-places rules that depend on it can run.
 */
class SdkV6ManagerFreeTrialStub extends SdkV6Manager {

	/**
	 * Whether the viewed product counts as a free-trial subscription.
	 *
	 * @var bool
	 */
	public bool $free_trial_product = false;

	/**
	 * Whether the viewed product is a free-trial subscription, as the test says.
	 *
	 * @return bool
	 */
	protected function is_free_trial_product(): bool {
		return $this->free_trial_product;
	}
}
