<?php
/**
 * Test double for the free-trial subscription helper with a controllable WooCommerce Subscriptions check.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Helper\FreeTrialSubscriptionHelper;

/**
 * Overrides the protected WooCommerce Subscriptions check so a test decides it directly, without depending on whether
 * the real plugin class is loaded.
 */
class TestableFreeTrialSubscriptionHelper extends FreeTrialSubscriptionHelper {

	/**
	 * Whether the plugin counts as active.
	 *
	 * @var bool
	 */
	private bool $wcs_active;

	/**
	 * Constructor.
	 *
	 * @param bool $wcs_active Whether the plugin counts as active.
	 */
	public function __construct( bool $wcs_active ) {
		$this->wcs_active = $wcs_active;
	}

	/**
	 * Whether the plugin counts as active, as the test says.
	 *
	 * @return bool
	 */
	protected function is_wcs_plugin_active(): bool {
		return $this->wcs_active;
	}
}
