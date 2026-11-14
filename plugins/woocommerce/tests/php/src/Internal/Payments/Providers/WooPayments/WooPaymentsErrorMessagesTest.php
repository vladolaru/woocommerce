<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsErrorMessages;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsErrorMessages class.
 */
class WooPaymentsErrorMessagesTest extends WC_Unit_Test_Case {

	/**
	 * Clean up message filters.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wcpay_localized_messages' );
		parent::tearDown();
	}

	/**
	 * @testdox The localized message catalog preserves the WooPayments extension's message IDs.
	 */
	public function test_get_localized_messages_preserves_oracle_messages(): void {
		$this->assertSame(
			array(
				'invalid_number'                        => 'The card number is not a valid credit card number.',
				'invalid_expiry_month'                  => "Your card's expiration month is invalid.",
				'invalid_expiry_year'                   => "Your card's expiration year is invalid.",
				'invalid_cvc'                           => "Your card's security code is invalid.",
				'incorrect_number'                      => 'Your card number is incorrect.',
				'incomplete_number'                     => 'Your card number is incomplete.',
				'incomplete_cvc'                        => "Your card's security code is incomplete.",
				'incomplete_expiry'                     => "Your card's expiration date is incomplete.",
				'expired_card'                          => 'Your card has expired.',
				'incorrect_cvc'                         => "Your card's security code is incorrect.",
				'postal_code_invalid'                   => 'Invalid zip code, please correct and try again.',
				'invalid_expiry_year_past'              => "Your card's expiration year is in the past.",
				'card_declined'                         => 'Your card was declined.',
				'missing'                               => 'There is no card on a customer that is being charged.',
				'processing_error'                      => 'An error occurred while processing your card. Try again in a little bit.',
				'invalid_sofort_country'                => 'The billing country is not accepted by Sofort. Please try another country.',
				'email_invalid'                         => 'Invalid email address, please correct and try again.',
				'country_code_invalid'                  => 'Invalid country code, please try again with a valid country code.',
				'tax_id_invalid'                        => 'Invalid Tax ID, please try again with a valid tax ID.',
				'invalid_wallet_type'                   => 'Invalid wallet payment type, please try again or use an alternative method.',
				'payment_intent_authentication_failure' => 'We are unable to authenticate your payment method. Please choose a different payment method and try again.',
				'authentication_required'               => 'Your card was declined because additional authentication is required. Please contact your card issuer or try a different payment method.',
				'insufficient_funds'                    => 'Your card has insufficient funds.',
			),
			WooPaymentsErrorMessages::get_localized_messages()
		);
	}

	/**
	 * @testdox The existing message filter can extend the catalog through its single array argument.
	 */
	public function test_get_localized_messages_preserves_filter_shape(): void {
		$received_argument_count = 0;
		$filter                  = static function ( array $messages ) use ( &$received_argument_count ): array {
			$received_argument_count    = func_num_args();
			$messages['custom_decline'] = 'A custom decline message.';

			return $messages;
		};
		add_filter( 'wcpay_localized_messages', $filter );

		$messages = WooPaymentsErrorMessages::get_localized_messages();

		$this->assertSame( 1, $received_argument_count );
		$this->assertSame( 'A custom decline message.', $messages['custom_decline'] );
	}

	/**
	 * @testdox The shopper message for a provider error follows the WooPayments extension's mapping: $_dataName.
	 *
	 * Client 11.1.0 `class-wc-payments-utils.php`: the decline code first, then the error code (`:806-817`), against the
	 * 11.1.0 catalog (`:852-866`); `incorrect_zip`'s postal-code hint unless fraud rules blocked the payment (`:799`); the
	 * connection copy for a failed transport request (`:773-774`); typed non-card errors and `wcpay_bad_request` redacted
	 * (`:795-798`); every other message passed through, an empty one included (`:770`, `:797-819`, `:821`). Native callers
	 * substitute their own fallback copy for an empty message.
	 *
	 * REC-1 (`Fixtures/platform-intention-declines.json`) recorded that the platform's `decline_code` is present even when
	 * it equals `code` (`expired_card`, `incorrect_cvc`, `processing_error`), so the mapping must not assume it is only
	 * present for `card_declined`.
	 *
	 * @dataProvider shopper_message_data
	 *
	 * @param string $error_type       Error type.
	 * @param string $error_code       Provider error code.
	 * @param string $decline_code     Provider decline code.
	 * @param string $platform_message Normalized platform message.
	 * @param bool   $blocked_by_fraud Whether fraud rules blocked the payment.
	 * @param string $expected         Expected shopper-facing message.
	 */
	public function test_get_shopper_message( string $error_type, string $error_code, string $decline_code, string $platform_message, bool $blocked_by_fraud, string $expected ): void {
		$this->assertSame(
			$expected,
			WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, $decline_code, $platform_message, $blocked_by_fraud )
		);
	}

	/**
	 * Provider errors and the shopper message the WooPayments extension shows for each.
	 *
	 * @return array<string,array{string,string,string,string,bool,string}>
	 */
	public function shopper_message_data(): array {
		$redacted = "We're not able to process this request. Please refresh the page and try again.";

		return array(
			'REC-1 generic_decline, decline code present' => array( 'card_error', 'card_declined', 'generic_decline', '', false, 'Error: Your card was declined.' ),
			'REC-1 generic_decline, decline code absent'  => array( 'card_error', 'card_declined', '', '', false, 'Error: Your card was declined.' ),
			'REC-1 expired_card, decline code mirrored'   => array( 'card_error', 'expired_card', 'expired_card', '', false, 'Error: Your card has expired.' ),
			'REC-1 expired_card, decline code absent'     => array( 'card_error', 'expired_card', '', '', false, 'Error: Your card has expired.' ),
			'REC-1 insufficient_funds, decline code present' => array( 'card_error', 'card_declined', 'insufficient_funds', '', false, 'Error: Your card has insufficient funds.' ),
			'REC-1 insufficient_funds, decline code absent' => array( 'card_error', 'card_declined', '', '', false, 'Error: Your card was declined.' ),
			'REC-1 incorrect_cvc, decline code mirrored'  => array( 'card_error', 'incorrect_cvc', 'incorrect_cvc', '', false, "Error: Your card's security code is incorrect." ),
			'REC-1 incorrect_cvc, decline code absent'    => array( 'card_error', 'incorrect_cvc', '', '', false, "Error: Your card's security code is incorrect." ),
			'REC-1 processing_error, decline code mirrored' => array( 'card_error', 'processing_error', 'processing_error', '', false, 'Error: An error occurred while processing your card. Try again in a little bit.' ),
			'REC-1 processing_error, decline code absent' => array( 'card_error', 'processing_error', '', '', false, 'Error: An error occurred while processing your card. Try again in a little bit.' ),
			'unknown decline code falls back to the error code' => array( 'card_error', 'card_declined', 'provider_specific_decline', '', false, 'Error: Your card was declined.' ),
			'incorrect_zip gets the postal-code hint'     => array( 'card_error', 'incorrect_zip', '', '', false, 'We couldn’t verify the postal code in your billing address. Make sure the information is current with your card issuing bank and try again.' ),
			'fraud-blocked incorrect_zip keeps the platform message' => array( 'card_error', 'incorrect_zip', '', 'Error: Your postal code failed validation.', true, 'Error: Your postal code failed validation.' ),
			'failed transport request gets the connection copy' => array( '', 'wcpay_http_request_failed', '', 'Http request failed. Reason: cURL error 28: Operation timed out', false, 'There was an error while processing this request. If you continue to see this notice, please contact the admin.' ),
			'card-testing prevention passes through'      => array( '', 'wcpay_card_testing_prevention', '', "Error: We're not able to add this payment method. Please try again later.", false, "Error: We're not able to add this payment method. Please try again later." ),
			'arbitrary typeless error passes through'     => array( '', 'wcpay_native_transport_error', '', 'Error: Provider diagnostic.', false, 'Error: Provider diagnostic.' ),
			'unmapped card error passes through'          => array( 'card_error', 'provider_internal_detail', '', 'Error: Your card does not support this type of purchase.', false, 'Error: Your card does not support this type of purchase.' ),
			'unmapped card code passes through'           => array( 'card_error', 'card_velocity_exceeded', '', 'Error: Your card has exceeded its velocity limit.', false, 'Error: Your card has exceeded its velocity limit.' ),
			'card-testing prevention, empty message passes through' => array( '', 'wcpay_card_testing_prevention', '', '', false, '' ),
			'unmapped card error, empty message passes through' => array( 'card_error', 'provider_internal_detail', '', '', false, '' ),
			'wcpay bad request is redacted'               => array( '', 'wcpay_bad_request', '', 'Error: Provider diagnostic.', false, $redacted ),
			'non-card API error is redacted'              => array( 'api_error', 'provider_internal_detail', '', 'Error: Provider diagnostic.', false, $redacted ),
			'phone length rejection is redacted'          => array( 'invalid_request_error', 'invalid_request_error', '', 'Error: Invalid string length: 55555501004242424242424242 must be at most 20 characters', false, $redacted ),
		);
	}
}
