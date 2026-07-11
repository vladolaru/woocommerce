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
