<?php
/**
 * WooCommerce Subscriptions subscription stand-in for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * An order with the `WC_Subscription` methods the Stripe Billing module calls, stored in the meta keys WooCommerce Subscriptions uses.
 *
 * `WooCommerceSubscriptionsDoubles::load()` makes `wc_get_order()` return this class for the IDs in its subscription registry.
 */
class SubscriptionDouble extends \WC_Order {

	/**
	 * Tell whether the subscription renews manually.
	 *
	 * @return bool
	 */
	public function is_manual(): bool {
		return 'true' === $this->get_meta( '_requires_manual_renewal', true );
	}

	/**
	 * Set whether the subscription renews manually.
	 *
	 * @param bool $is_manual Whether it renews manually.
	 */
	public function set_requires_manual_renewal( bool $is_manual ): void {
		$this->update_meta_data( '_requires_manual_renewal', wc_bool_to_string( $is_manual ) );
	}

	/**
	 * Get the billing period: `day`, `week`, `month` or `year`.
	 *
	 * @return string
	 */
	public function get_billing_period(): string {
		return (string) $this->get_meta( '_billing_period', true );
	}

	/**
	 * Get the billing interval.
	 *
	 * @return string
	 */
	public function get_billing_interval(): string {
		return (string) $this->get_meta( '_billing_interval', true );
	}
}
