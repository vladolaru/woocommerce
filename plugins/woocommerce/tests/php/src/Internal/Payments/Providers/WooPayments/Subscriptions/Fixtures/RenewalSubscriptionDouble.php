<?php
/**
 * WooCommerce Subscriptions subscription stand-in that decides `is_manual()` as WooCommerce Subscriptions 9.4.1 does.
 *
 * @package WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures;

use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\RelatedOrderFailureSubscriptionDouble;

/**
 * A subscription whose `is_manual()` asks for the payment gateway and runs `woocommerce_subscription_is_manual`, and whose
 * `payment_method_supports()` runs `woocommerce_subscription_payment_gateway_supports`, as WooCommerce Subscriptions 9.4.1
 * does (includes/core/class-wc-subscription.php:323-331, :700-709).
 */
class RenewalSubscriptionDouble extends RelatedOrderFailureSubscriptionDouble {

	/**
	 * Tell whether the subscription renews manually: a staging copy, the manual renewal flag, or no gateway for its payment
	 * method, then the filter.
	 *
	 * @return bool
	 */
	public function is_manual(): bool {
		$is_manual = \WCS_Staging::is_duplicate_site() || $this->get_requires_manual_renewal() || false === wc_get_payment_gateway_by_order( $this );

		return (bool) apply_filters( 'woocommerce_subscription_is_manual', $is_manual, $this );
	}

	/**
	 * Tell whether the subscription is set to renew manually.
	 *
	 * @return bool
	 */
	public function get_requires_manual_renewal(): bool {
		return 'true' === $this->get_meta( '_requires_manual_renewal', true );
	}

	/**
	 * Get the parent order, or false without one.
	 *
	 * @return \WC_Order|false
	 */
	public function get_parent() {
		$parent = $this->get_parent_id() ? wc_get_order( $this->get_parent_id() ) : false;

		return $parent instanceof \WC_Order ? $parent : false;
	}

	/**
	 * Tell whether the subscription's payment method supports a feature: always for a manual subscription.
	 *
	 * @param string $payment_gateway_feature Feature name.
	 * @return bool
	 */
	public function payment_method_supports( $payment_gateway_feature ): bool {
		$gateway  = wc_get_payment_gateway_by_order( $this );
		$supports = $this->is_manual() || ( false !== $gateway && $gateway->supports( $payment_gateway_feature ) );

		return (bool) apply_filters( 'woocommerce_subscription_payment_gateway_supports', $supports, $payment_gateway_feature, $this );
	}
}
