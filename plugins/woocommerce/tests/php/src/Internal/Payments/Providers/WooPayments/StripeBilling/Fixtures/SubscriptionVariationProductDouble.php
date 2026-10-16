<?php
/**
 * WooCommerce Subscriptions subscription variation stand-in for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * A variation of the `subscription_variation` type WooCommerce Subscriptions registers.
 *
 * Stored with the variation data store, as WooCommerce Subscriptions stores it.
 */
class SubscriptionVariationProductDouble extends \WC_Product_Variation {

	/**
	 * Get the product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'subscription_variation';
	}
}
