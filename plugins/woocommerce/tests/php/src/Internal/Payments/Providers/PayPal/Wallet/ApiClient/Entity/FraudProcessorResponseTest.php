<?php
/**
 * Tests for the fraud processor response entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\FraudProcessorResponse;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The processor response PayPal reports on a payment: the response code, the message for the merchant and the message
 * for the buyer. The messages are translatable and the buyer's message has two filters, so these cases run against real
 * WordPress: the English strings come from __() and the filters are real. Filters added in a test are removed with the
 * rest of the hooks when the test ends.
 *
 * @group paypal-wallet
 */
class FraudProcessorResponseTest extends WalletTestCase {

	private const DECLINE_MESSAGE_HOOK = 'woocommerce_paypal_payments_customer_decline_message';
	private const DECLINE_REASON_HOOK  = 'woocommerce_paypal_payments_customer_decline_reason_message';

	/**
	 * @testdox Should return the response code it is given.
	 */
	public function test_response_code_getter(): void {
		$testee = new FraudProcessorResponse( 'A', 'M', '9500' );

		$this->assertSame( '9500', $testee->response_code() );
	}

	/**
	 * @testdox Should store a missing response code as an empty string.
	 */
	public function test_null_response_code_stored_as_empty_string(): void {
		$testee = new FraudProcessorResponse( 'A', 'M', null );

		$this->assertSame( '', $testee->response_code() );
	}

	/**
	 * @testdox Should write the response code into the array.
	 */
	public function test_to_array_includes_response_code(): void {
		$array = ( new FraudProcessorResponse( 'A', 'M', '9500' ) )->to_array();

		$this->assertArrayHasKey( 'response_code', $array );
		$this->assertSame( '9500', $array['response_code'] );
	}

	/**
	 * @testdox Should write an empty string into the array when there is no response code.
	 */
	public function test_to_array_without_response_code_has_empty_string(): void {
		$array = ( new FraudProcessorResponse( 'A', 'M', null ) )->to_array();

		$this->assertArrayHasKey( 'response_code', $array );
		$this->assertSame( '', $array['response_code'] );
	}

	/**
	 * @testdox Should name the suspected fraud code 9500 in the merchant message.
	 */
	public function test_get_response_code_message_for_known_code_9500(): void {
		$testee = new FraudProcessorResponse( null, null, '9500' );

		$this->assertSame( '9500: Suspected Fraud', $testee->get_response_code_message() );
	}

	/**
	 * @testdox Should name the approved code 0000 in the merchant message.
	 */
	public function test_get_response_code_message_for_known_code_0000(): void {
		$testee = new FraudProcessorResponse( null, null, '0000' );

		$this->assertSame( '0000: Approved', $testee->get_response_code_message() );
	}

	/**
	 * @testdox Should name the retry code 9100 in the merchant message.
	 */
	public function test_get_response_code_message_for_known_code_9100(): void {
		$testee = new FraudProcessorResponse( null, null, '9100' );

		$this->assertSame( '9100: Declined, Please Retry', $testee->get_response_code_message() );
	}

	/**
	 * @testdox Should call an unknown response code unknown in the merchant message.
	 */
	public function test_get_response_code_message_for_unknown_code(): void {
		$testee = new FraudProcessorResponse( null, null, 'ZZZZ' );

		$this->assertSame( 'ZZZZ: Unknown response code', $testee->get_response_code_message() );
	}

	/**
	 * @testdox Should return an empty merchant message when there is no response code.
	 */
	public function test_get_response_code_message_for_empty_code_returns_empty_string(): void {
		$testee = new FraudProcessorResponse( null, null, null );

		$this->assertSame( '', $testee->get_response_code_message() );
	}

	/**
	 * @testdox Should give the buyer a friendly message for a known code, without the code or the word fraud.
	 */
	public function test_get_customer_decline_message_with_known_code_is_user_friendly(): void {
		$message = ( new FraudProcessorResponse( null, null, '9500' ) )->get_customer_decline_message();

		$this->assertSame(
			'Your payment could not be processed because your bank was unable to approve this transaction. Please try a different payment method or contact your bank for more information.',
			$message
		);
		$this->assertStringNotContainsString( '9500', $message );
		$this->assertStringNotContainsString( 'Fraud', $message );
	}

	/**
	 * @testdox Should give the buyer the default reason for an unknown code.
	 */
	public function test_get_customer_decline_message_with_unknown_code_uses_default_reason(): void {
		$message = ( new FraudProcessorResponse( null, null, 'ZZZZ' ) )->get_customer_decline_message();

		$this->assertSame(
			'Your payment could not be processed because your card was declined by your bank. Please try a different payment method or contact your bank for more information.',
			$message
		);
	}

	/**
	 * @testdox Should give the buyer a generic message when there is no response code.
	 */
	public function test_get_customer_decline_message_with_empty_code_returns_generic_message(): void {
		$message = ( new FraudProcessorResponse( null, null, null ) )->get_customer_decline_message();

		$this->assertSame( 'Payment provider declined the payment, please use a different payment method.', $message );
	}

	/**
	 * @testdox Should let the decline message filter replace the final message, after the reason filter has run once.
	 */
	public function test_customer_decline_message_filter_overrides_final_message(): void {
		$reason_calls  = $this->spy_filter( self::DECLINE_REASON_HOOK );
		$message_calls = $this->spy_filter( self::DECLINE_MESSAGE_HOOK, 'Custom decline message.' );

		$testee = new FraudProcessorResponse( null, null, '5120' );

		$this->assertSame( 'Custom decline message.', $testee->get_customer_decline_message() );
		$this->assertCount( 1, $reason_calls );
		$this->assertSame( 'your card was declined due to insufficient funds.', $reason_calls[0][0] );
		$this->assertSame( '5120', $reason_calls[0][1] );
		$this->assertSame( $testee, $reason_calls[0][2] );
		$this->assertCount( 1, $message_calls );
		$this->assertSame( $testee, $message_calls[0][1] );
	}

	/**
	 * @testdox Should let the reason filter replace the reason inside the buyer's message.
	 */
	public function test_customer_decline_reason_message_filter_overrides_reason(): void {
		$reason_calls  = $this->spy_filter( self::DECLINE_REASON_HOOK, 'a custom reason.' );
		$message_calls = $this->spy_filter( self::DECLINE_MESSAGE_HOOK );

		$testee = new FraudProcessorResponse( null, null, '5120' );

		$this->assertSame(
			'Your payment could not be processed because a custom reason. Please try a different payment method or contact your bank for more information.',
			$testee->get_customer_decline_message()
		);
		$this->assertCount( 1, $reason_calls );
		$this->assertCount( 1, $message_calls );
	}
}
