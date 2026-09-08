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
	 * @testdox Incorrect postal codes use the oracle's dedicated message.
	 */
	public function test_get_shopper_message_uses_incorrect_zip_message(): void {
		$this->assertSame(
			'We couldn’t verify the postal code in your billing address. Make sure the information is current with your card issuing bank and try again.',
			WooPaymentsErrorMessages::get_shopper_message( 'card_error', 'incorrect_zip' )
		);
	}

	/**
	 * @testdox Grounded platform errors preserve their actionable shopper message.
	 *
	 * @dataProvider grounded_platform_error_data
	 *
	 * @param string $error_type       Error type.
	 * @param string $error_code       Error code.
	 * @param string $platform_message Normalized platform message.
	 */
	public function test_get_shopper_message_preserves_grounded_platform_errors( string $error_type, string $error_code, string $platform_message ): void {
		$this->assertSame(
			$platform_message,
			WooPaymentsErrorMessages::get_shopper_message( $error_type, $error_code, '', $platform_message )
		);
	}

	/**
	 * Grounded platform errors whose normalized message is safe to show.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function grounded_platform_error_data(): array {
		return array(
			'card-testing prevention' => array( '', 'wcpay_card_testing_prevention', "Error: We're not able to add this payment method. Please try again later." ),
			'phone length rejection'  => array( 'invalid_request_error', 'invalid_request_error', 'Error: Invalid string length: 55555501004242424242424242 must be at most 20 characters' ),
		);
	}

	/**
	 * @testdox Unsafe provider details are redacted for unknown, non-card, and transport failures.
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
	 * Unsafe provider errors.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function unsafe_error_data(): array {
		return array(
			'unknown card error'                   => array( 'card_error', 'provider_internal_detail', 'Error: Provider diagnostic.' ),
			'wcpay bad request'                    => array( '', 'wcpay_bad_request', 'Error: Provider diagnostic.' ),
			'arbitrary typeless transport failure' => array( '', 'wcpay_native_transport_error', 'Error: Provider diagnostic.' ),
			'non-card API error'                   => array( 'api_error', 'provider_internal_detail', 'Error: Provider diagnostic.' ),
			'allowed pair without a message'       => array( '', 'wcpay_card_testing_prevention', '' ),
		);
	}
}
