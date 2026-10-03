<?php
/**
 * Common messages.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

/**
 * Class Messages
 */
class Messages {
	/**
	 * The generic payment failure message.
	 *
	 * @return string
	 */
	public static function generic_payment_error_message(): string {
		return apply_filters(
			'woocommerce_paypal_payments_generic_payment_error_message',
			__( 'Failed to process the payment. Please try again or contact the shop admin.', 'woocommerce' )
		);
	}
}
