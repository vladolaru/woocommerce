<?php
/**
 * Tests for the PayPal API exception.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\PayPalApiException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The exception thrown when PayPal answers with an error. The messages are translatable, so the English text comes
 * from real WordPress.
 *
 * @group paypal-wallet
 */
class PayPalApiExceptionTest extends WalletTestCase {

	/**
	 * @testdox Should turn the PAYMENT_DENIED issue into a message the buyer can act on.
	 */
	public function test_friendly_message(): void {
		$testee = new PayPalApiException();

		$response = json_decode( '{"details":[{"issue":"PAYMENT_DENIED"}]}' );

		$this->assertSame(
			'PayPal rejected the payment. Please reach out to the PayPal support for more information.',
			$testee->get_customer_friendly_message( $response )
		);
	}
}
