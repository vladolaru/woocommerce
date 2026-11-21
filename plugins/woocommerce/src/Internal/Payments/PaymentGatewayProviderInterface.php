<?php
/**
 * PaymentGatewayProviderInterface interface file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

/**
 * Publishes a payment provider's WooCommerce payment gateways.
 *
 * @since 11.0.0
 * @internal
 */
interface PaymentGatewayProviderInterface {

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
