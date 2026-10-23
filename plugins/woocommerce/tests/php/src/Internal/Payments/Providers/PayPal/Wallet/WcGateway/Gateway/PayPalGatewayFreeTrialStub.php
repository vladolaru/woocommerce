<?php
/**
 * Test double for the PayPal wallet gateway that treats every $0 order as a free trial.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use WC_Order;

/**
 * The real free-trial check needs WooCommerce Subscriptions (its class and wcs_get_subscriptions_for_order()), which the
 * core test suite does not load. This double keeps the real $0 total check and stands in for the subscription lookup,
 * so the free-trial branches of process_payment() can run.
 */
class PayPalGatewayFreeTrialStub extends PayPalGateway {

	/**
	 * Whether the order is a free trial: a zero total, with the subscription lookup assumed to succeed.
	 *
	 * @param WC_Order $wc_order The WooCommerce order.
	 * @return bool
	 */
	protected function is_free_trial_order( WC_Order $wc_order ): bool {
		return (float) $wc_order->get_total( 'numeric' ) <= 0;
	}
}
