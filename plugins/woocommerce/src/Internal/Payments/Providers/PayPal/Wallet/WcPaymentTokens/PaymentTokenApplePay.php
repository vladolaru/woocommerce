<?php
/**
 * WooCommerce Payment token for ApplePay.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcPaymentTokens;

use WC_Payment_Token;

/**
 * Class PaymentTokenApplePay
 */
class PaymentTokenApplePay extends WC_Payment_Token {
	/**
	 * Token Type String.
	 *
	 * @var string
	 */
	protected $type = 'ApplePay';

	/**
	 * Extra data.
	 *
	 * @var string[]
	 */
	protected $extra_data = array();
}
