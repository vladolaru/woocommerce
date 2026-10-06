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
	 * Accept the subscription statuses an order does not have.
	 *
	 * @return string[]
	 */
	protected function get_valid_statuses() {
		return array_merge( parent::get_valid_statuses(), array( 'wc-active', 'wc-pending-cancel', 'wc-expired' ) );
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

	/**
	 * Get a date as a timestamp, 0 when unset: `start` is the creation date, others are GMT dates under `_schedule_{type}`.
	 *
	 * WooCommerce Subscriptions works out the last order dates from the related orders; here they are read like the others.
	 *
	 * @param string $date_type Date type, such as `trial_end` or `next_payment`.
	 * @return int
	 */
	public function get_time( string $date_type ): int {
		if ( 'start' === $date_type ) {
			$date_created = $this->get_date_created();

			return $date_created ? $date_created->getTimestamp() : 0;
		}

		$date = (string) $this->get_meta( '_schedule_' . $date_type, true );

		return '' === $date ? 0 : ( new \DateTime( $date, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
	}

	/**
	 * Update the status, firing the subscription status hooks WooCommerce Subscriptions fires on a change.
	 *
	 * @param string $new_status New status.
	 * @param string $note       Note added with the change.
	 * @param bool   $manual     Whether the change was made by a user.
	 * @return bool
	 */
	public function update_status( $new_status, $note = '', $manual = false ) {
		$old_status = $this->get_status();
		$result     = parent::update_status( $new_status, $note, $manual );
		$new_status = $this->get_status();

		if ( $old_status !== $new_status ) {
			do_action( 'woocommerce_subscription_status_' . $new_status, $this );
			do_action( 'woocommerce_subscription_status_' . $old_status . '_to_' . $new_status, $this );
		}

		return $result;
	}

	/**
	 * Record a failed renewal payment as WooCommerce Subscriptions before 7.9.0 does: the subscription's last order fails,
	 * whichever invoice it belongs to, with the renewal-order listener off, and the subscription moves to the given
	 * status, on hold unless told otherwise.
	 *
	 * WooCommerce Subscriptions 7.8.2 `WC_Subscription::payment_failed()` (includes/core/class-wc-subscription.php:2035-2074).
	 *
	 * @param string $new_status Status after the failure.
	 */
	public function payment_failed( string $new_status = 'on-hold' ): void {
		$last_order = $this->get_last_order( 'all', 'any' );
		if ( $last_order instanceof \WC_Order && ! $last_order->has_status( 'failed' ) ) {
			// WCS always has the listener; here it is on only when the test hooked it.
			$listener  = 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment';
			$listening = false !== has_filter( 'woocommerce_order_status_changed', $listener );
			remove_filter( 'woocommerce_order_status_changed', $listener );
			$last_order->update_status( 'failed' );
			if ( $listening ) {
				add_filter( 'woocommerce_order_status_changed', $listener, 10, 3 );
			}
		}
		$this->add_order_note( 'Payment failed.' );
		$this->update_status( $new_status );
	}

	/**
	 * Get the related order with the highest ID among the given relations, as WooCommerce Subscriptions does; `any` means parent, renewal and switch.
	 *
	 * Relations are read from the `WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS` registry.
	 *
	 * @param string          $return_fields `ids` for the order ID, anything else for the order.
	 * @param string|string[] $order_types   Relations to look at.
	 * @return \WC_Order|int|false
	 */
	public function get_last_order( $return_fields = 'ids', $order_types = array( 'parent', 'renewal' ) ) {
		$order_types = 'any' === $order_types ? array( 'parent', 'renewal', 'switch' ) : (array) $order_types;
		$order_ids   = array();
		foreach ( $GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ] ?? array() as $order_id => $relations ) {
			foreach ( $order_types as $order_type ) {
				if ( in_array( $this->get_id(), array_map( 'absint', $relations[ $order_type ] ?? array() ), true ) ) {
					$order_ids[] = absint( $order_id );
				}
			}
		}

		if ( empty( $order_ids ) ) {
			return false;
		}

		return 'ids' === $return_fields ? max( $order_ids ) : wc_get_order( max( $order_ids ) );
	}

	/**
	 * Save GMT dates and fire `woocommerce_subscription_date_updated` for each, as WooCommerce Subscriptions does.
	 *
	 * @param array<string,string> $dates GMT dates in `Y-m-d H:i:s`, by date type.
	 */
	public function update_dates( array $dates ): void {
		foreach ( $dates as $date_type => $date ) {
			$this->update_meta_data( '_schedule_' . $date_type, $date );
		}
		$this->save();

		foreach ( $dates as $date_type => $date ) {
			do_action( 'woocommerce_subscription_date_updated', $this, $date_type, $date );
		}
	}
}
