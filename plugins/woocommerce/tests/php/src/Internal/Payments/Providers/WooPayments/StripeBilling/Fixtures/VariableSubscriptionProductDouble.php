<?php
/**
 * WooCommerce Subscriptions variable subscription stand-in for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * A variable product of the `variable-subscription` type WooCommerce Subscriptions registers.
 *
 * Stored with the variable product data store, as WooCommerce Subscriptions stores it.
 */
class VariableSubscriptionProductDouble extends \WC_Product_Variable {

	/**
	 * Get the product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return 'variable-subscription';
	}
}
