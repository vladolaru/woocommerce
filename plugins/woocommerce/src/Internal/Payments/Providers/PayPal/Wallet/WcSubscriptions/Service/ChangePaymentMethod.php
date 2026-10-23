<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcSubscriptions\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Helper\Context;

/**
 * Lets the PayPal payment be processed everywhere except on the subscription change payment method page.
 */
class ChangePaymentMethod {
	/**
	 * The context.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * ChangePaymentMethod constructor.
	 *
	 * @param Context $context The context.
	 */
	public function __construct( Context $context ) {
		$this->context = $context;
	}

	/**
	 * Converts to the paypal payment.
	 */
	public function to_paypal_payment(): bool {
		if ( ! $this->context->is_subscription_change_payment_method_page() ) {
			return true;
		}

		return false;
	}
}
