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
	 * @testdox The localized message catalog preserves the WooPayments oracle message IDs.
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
	 * @testdox Known card codes use their localized shopper message.
	 */
	public function test_get_shopper_message_localizes_known_card_code(): void {
		$this->assertSame(
			'Error: Your card has expired.',
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'expired_card' )
		);
	}

	/**
	 * @testdox A known decline code takes precedence over the general card-declined code.
	 */
	public function test_get_shopper_message_prefers_known_decline_code(): void {
		$this->assertSame(
			'Error: Your card has insufficient funds.',
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'card_declined', 'insufficient_funds' )
		);
	}

	/**
	 * @testdox An unknown decline code falls back to the known general error code.
	 */
	public function test_get_shopper_message_falls_back_from_unknown_decline_code_to_error_code(): void {
		$this->assertSame(
			'Error: Your card was declined.',
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'card_declined', 'provider_specific_decline' )
		);
	}

	/**
	 * @testdox Each REC-1 card decline code pair maps to its exact client shopper message, present or mirrored.
	 *
	 * REC-1 (`Fixtures/rec-1-intention-declines.json`) recorded that the platform's `decline_code`
	 * is present even when it equals `code` (`expired_card`, `incorrect_cvc`, `processing_error`), so
	 * the mapping must not assume it is only present for `card_declined`. Client citations
	 * `class-wc-payments-utils.php:806-817` (decline code first, then error code) and `:852-866`
	 * (11.1.0 catalog).
	 *
	 * @dataProvider recorded_decline_code_pair_data
	 *
	 * @param string $error_code   Provider error code.
	 * @param string $decline_code Provider decline code.
	 * @param string $expected     Expected shopper-facing message.
	 */
	public function test_get_shopper_message_maps_provider_decline_vocabulary( string $error_code, string $decline_code, string $expected ): void {
		$this->assertSame(
			$expected,
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', $error_code, $decline_code )
		);
	}

	/**
	 * REC-1 card decline pairs, each asserted with its mirrored decline code and with none.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function recorded_decline_code_pair_data(): array {
		return array(
			'generic_decline, decline code present'    => array( 'card_declined', 'generic_decline', 'Error: Your card was declined.' ),
			'generic_decline, decline code absent'     => array( 'card_declined', '', 'Error: Your card was declined.' ),
			'expired_card, decline code mirrored'      => array( 'expired_card', 'expired_card', 'Error: Your card has expired.' ),
			'expired_card, decline code absent'        => array( 'expired_card', '', 'Error: Your card has expired.' ),
			'insufficient_funds, decline code present' => array( 'card_declined', 'insufficient_funds', 'Error: Your card has insufficient funds.' ),
			'insufficient_funds, decline code absent'  => array( 'card_declined', '', 'Error: Your card was declined.' ),
			'incorrect_cvc, decline code mirrored'     => array( 'incorrect_cvc', 'incorrect_cvc', "Error: Your card's security code is incorrect." ),
			'incorrect_cvc, decline code absent'       => array( 'incorrect_cvc', '', "Error: Your card's security code is incorrect." ),
			'processing_error, decline code mirrored'  => array( 'processing_error', 'processing_error', 'Error: An error occurred while processing your card. Try again in a little bit.' ),
			'processing_error, decline code absent'    => array( 'processing_error', '', 'Error: An error occurred while processing your card. Try again in a little bit.' ),
		);
	}

	/**
	 * @testdox Incorrect postal codes use the oracle's dedicated message.
	 */
	public function test_get_shopper_message_uses_incorrect_zip_message(): void {
		$this->assertSame(
			'We couldn’t verify the postal code in your billing address. Make sure the information is current with your card issuing bank and try again.',
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'incorrect_zip' )
		);
	}

	/**
	 * @testdox A failed transport request uses the client's connection copy instead of the transport diagnostic.
	 *
	 * Source: client 11.1.0 `class-wc-payments-utils.php:773-774` (`Connection_Exception` branch).
	 */
	public function test_get_shopper_message_uses_connection_copy_for_transport_failure(): void {
		$this->assertSame(
			'There was an error while processing this request. If you continue to see this notice, please contact the admin.',
			WooPaymentsErrorMessages::get_shopper_message( '', 'wcpay_http_request_failed', '', 'Http request failed. Reason: cURL error 28: Operation timed out' )
		);
	}

	/**
	 * @testdox Typeless and unmapped card errors pass the platform message through, as the client does.
	 *
	 * Source: client 11.1.0 `class-wc-payments-utils.php:770` (the message defaults to the exception message) and `:797-819`
	 * (only typed non-card errors and `wcpay_bad_request` are redacted; unmapped card codes keep the message).
	 *
	 * @dataProvider passed_through_platform_error_data
	 *
	 * @param string $error_type       Error type.
	 * @param string $error_code       Error code.
	 * @param string $platform_message Normalized platform message.
	 */
	public function test_get_shopper_message_passes_platform_message_through( string $error_type, string $error_code, string $platform_message ): void {
		$this->assertSame(
			$platform_message,
			WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, '', $platform_message )
		);
	}

	/**
	 * Platform errors whose message the client shows unchanged.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function passed_through_platform_error_data(): array {
		return array(
			'card-testing prevention'  => array( '', 'wcpay_card_testing_prevention', "Error: We're not able to add this payment method. Please try again later." ),
			'arbitrary typeless error' => array( '', 'wcpay_native_transport_error', 'Error: Provider diagnostic.' ),
			'unmapped card error'      => array( 'card_error', 'provider_internal_detail', 'Error: Your card does not support this type of purchase.' ),
			'unmapped card code'       => array( 'card_error', 'card_velocity_exceeded', 'Error: Your card has exceeded its velocity limit.' ),
		);
	}

	/**
	 * @testdox An empty platform message stays empty when the client would pass it through.
	 *
	 * Source: client 11.1.0 `class-wc-payments-utils.php:770`, `:821`. Native callers substitute their own fallback copy.
	 */
	public function test_get_shopper_message_keeps_empty_platform_message(): void {
		$this->assertSame( '', WooPaymentsErrorMessages::get_shopper_message( '', 'wcpay_card_testing_prevention', '', '' ) );
		$this->assertSame( '', WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'provider_internal_detail', '', '' ) );
	}

	/**
	 * @testdox Typed non-card errors and bad requests use the generic shopper copy.
	 *
	 * Source: client 11.1.0 `class-wc-payments-utils.php:795-798`.
	 *
	 * @dataProvider unsafe_error_data
	 *
	 * @param string $error_type       Error type.
	 * @param string $error_code       Error code.
	 * @param string $platform_message Normalized platform message.
	 */
	public function test_get_shopper_message_redacts_unsafe_errors( string $error_type, string $error_code, string $platform_message ): void {
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, '', $platform_message )
		);
	}

	/**
	 * Provider errors the client redacts.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function unsafe_error_data(): array {
		return array(
			'wcpay bad request'      => array( '', 'wcpay_bad_request', 'Error: Provider diagnostic.' ),
			'non-card API error'     => array( 'api_error', 'provider_internal_detail', 'Error: Provider diagnostic.' ),
			'phone length rejection' => array( 'invalid_request_error', 'invalid_request_error', 'Error: Invalid string length: 55555501004242424242424242 must be at most 20 characters' ),
		);
	}
}
