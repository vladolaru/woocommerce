<?php
/**
 * Helper trait for the free trial subscriptions handling.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions;

use WC_Subscriptions;

/**
 * Class SubscriptionsHandlerTrait
 */
trait SubscriptionsHandlerTrait {
	/**
	 * Whether the subscription plugin is active or not.
	 *
	 * @return bool
	 */
	protected function is_wcs_plugin_active(): bool {
		return class_exists( WC_Subscriptions::class );
	}
}
