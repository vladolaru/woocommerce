<?php
/**
 * PaymentGatewayProviderContract interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Contract for native providers that publish WooCommerce payment gateways.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
interface PaymentGatewayProviderContract {

	/**
	 * Get the provider ID.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Get payment gateway instances published by the provider.
	 *
	 * @return array<int,\WC_Payment_Gateway>
	 */
	public function get_payment_gateways(): array;
}
