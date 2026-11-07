<?php
/**
 * WooCommerce Subscriptions 7.9.0+ subscription stand-in for the Stripe Billing module tests.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures;

/**
 * A `SubscriptionDouble` with the related-order failure WooCommerce Subscriptions added in 7.9.0.
 *
 * `WooCommerceSubscriptionsDoubles::load()` loads subscriptions as this class unless a test asks for an older WooCommerce Subscriptions.
 */
class RelatedOrderFailureSubscriptionDouble extends SubscriptionDouble {

	/**
	 * Fail the given related order and move the subscription to the given status, as WooCommerce Subscriptions 7.9.0+ does.
	 *
	 * WooCommerce Subscriptions `WC_Subscription::payment_failed_for_related_order()` (includes/core/class-wc-subscription.php:2094-2147).
	 *
	 * @param string          $new_status    Status after the failure.
	 * @param \WC_Order|false $related_order The related order that failed.
	 */
	public function payment_failed_for_related_order( string $new_status = 'on-hold', $related_order = false ): void {
		if ( $related_order instanceof \WC_Order ) {
			if ( ! $related_order->has_status( 'failed' ) ) {
				$related_order->update_status( 'failed' );
			}
			$this->add_order_note( sprintf( 'Related order #%d failed.', $related_order->get_id() ) );
		}
		$this->update_status( $new_status );
	}

	/**
	 * Record a failed renewal payment on the last order, as WooCommerce Subscriptions 7.9.0+ still does for its deprecated method.
	 *
	 * @param string $new_status Status after the failure.
	 */
	public function payment_failed( string $new_status = 'on-hold' ): void {
		$this->payment_failed_for_related_order( $new_status, $this->get_last_order( 'all', 'any' ) );
	}
}
