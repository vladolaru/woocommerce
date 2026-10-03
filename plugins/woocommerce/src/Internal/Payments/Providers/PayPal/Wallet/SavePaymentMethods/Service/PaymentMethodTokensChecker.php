<?php
/**
 * Service for checking payment method tokens.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Helper
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\SavePaymentMethods\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\PaymentTokensEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;

class PaymentMethodTokensChecker {

	/**
	 * Payment method tokens endpoint.
	 *
	 * @var PaymentTokensEndpoint
	 */
	private PaymentTokensEndpoint $payment_method_tokens_endpoint;

	public function __construct( PaymentTokensEndpoint $payment_method_tokens_endpoint ) {
		$this->payment_method_tokens_endpoint = $payment_method_tokens_endpoint;
	}

	/**
	 * Checks if customer has a saved PayPal payment token.
	 *
	 * @param string $customer_id PayPal customer ID.
	 * @return bool
	 */
	public function has_paypal_payment_token( string $customer_id ): bool {
		if ( ! $customer_id ) {
			return false;
		}

		try {
			$tokens = $this->payment_method_tokens_endpoint->payment_tokens_for_customer( $customer_id );
			foreach ( $tokens as $token ) {
				$payment_source = $token['payment_source']->name() ?? '';
				if ( $payment_source === 'paypal' ) {
					return true;
				}
			}
		} catch ( RuntimeException $e ) {
			return false;
		}

		return false;
	}
}
