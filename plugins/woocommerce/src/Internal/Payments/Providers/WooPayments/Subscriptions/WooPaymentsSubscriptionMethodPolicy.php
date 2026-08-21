<?php
/**
 * WooPaymentsSubscriptionMethodPolicy class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;

/**
 * Defines which native WooPayments methods support automatic subscription renewals.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsSubscriptionMethodPolicy {

	/**
	 * Tell whether the current cart contains a subscription or a subscription renewal.
	 *
	 * Mirrors the WooPayments extension's is_subscription_item_in_cart(): a renewal cart
	 * pays for an existing subscription, so every surface that forces card saving for
	 * subscription purchases must treat a renewal cart the same way.
	 *
	 * @return bool
	 */
	public static function cart_contains_subscription_or_renewal(): bool {
		$contains_subscription = class_exists( 'WC_Subscriptions_Cart' )
			&& is_callable( array( 'WC_Subscriptions_Cart', 'cart_contains_subscription' ) )
			&& (bool) \WC_Subscriptions_Cart::cart_contains_subscription();

		return $contains_subscription
			|| ( function_exists( 'wcs_cart_contains_renewal' ) && (bool) wcs_cart_contains_renewal() );
	}

	/**
	 * Get gateway IDs that support reusable subscription payment methods.
	 *
	 * @return array<int,string>
	 *
	 * @since 11.0.0
	 */
	public static function get_reusable_gateway_ids(): array {
		return array(
			OrderPaymentStore::GATEWAY_ID,
			OrderPaymentStore::GATEWAY_ID_PREFIX . 'amazon_pay',
		);
	}

	/**
	 * Tell whether a gateway supports reusable subscription payment methods.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public static function is_reusable_gateway_id( string $gateway_id ): bool {
		return in_array( $gateway_id, self::get_reusable_gateway_ids(), true );
	}

	/**
	 * Tell whether a gateway ID belongs to native WooPayments.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return bool
	 *
	 * @since 11.0.0
	 */
	public static function is_native_gateway_id( string $gateway_id ): bool {
		return OrderPaymentStore::GATEWAY_ID === $gateway_id || 0 === strpos( $gateway_id, OrderPaymentStore::GATEWAY_ID_PREFIX );
	}
}
