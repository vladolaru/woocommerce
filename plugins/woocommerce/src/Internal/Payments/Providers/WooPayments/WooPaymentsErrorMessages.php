<?php
/**
 * WooPaymentsErrorMessages class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Maps WooPayments provider errors to safe shopper-facing messages.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsErrorMessages {

	/**
	 * Get a safe shopper-facing message for a provider error.
	 *
	 * @since 11.0.0
	 *
	 * @param string $error_type   Provider error type.
	 * @param string $error_code   Provider error code.
	 * @param string $decline_code Provider decline code.
	 * @return string
	 */
	public static function get_shopper_message( string $error_type, string $error_code, string $decline_code = '' ): string {
		if ( 'card_error' !== $error_type ) {
			return self::get_generic_message();
		}

		if ( 'incorrect_zip' === $error_code ) {
			return __( 'We couldn’t verify the postal code in your billing address. Make sure the information is current with your card issuing bank and try again.', 'woocommerce' );
		}

		$localized_messages = self::get_localized_messages();
		$localized_message  = '';

		if ( '' !== $decline_code && isset( $localized_messages[ $decline_code ] ) ) {
			$localized_message = $localized_messages[ $decline_code ];
		} elseif ( isset( $localized_messages[ $error_code ] ) ) {
			$localized_message = $localized_messages[ $error_code ];
		}

		if ( '' === $localized_message ) {
			return self::get_generic_message();
		}

		return sprintf(
			// translators: %1$s is a localized payment-provider error message.
			_x( 'Error: %1$s', 'API error message to throw as Exception', 'woocommerce' ),
			$localized_message
		);
	}

	/**
	 * Get the generic safe request error message.
	 *
	 * @since 11.0.0
	 *
	 * @return string
	 */
	public static function get_generic_message(): string {
		return __( "We're not able to process this request. Please refresh the page and try again.", 'woocommerce' );
	}

	/**
	 * Get provider error codes mapped to translated shopper-facing messages.
	 *
	 * @since 11.0.0
	 *
	 * @return array<string,string> Map of provider error codes to translated messages.
	 */
	public static function get_localized_messages(): array {
		/**
		 * Filters the localized, customer-facing Stripe error messages.
		 *
		 * @since 10.7.0
		 *
		 * @param array<string,string> $messages Map of Stripe error codes to translated messages.
		 */
		$messages = apply_filters(
			'wcpay_localized_messages',
			array(
				'invalid_number'                        => __( 'The card number is not a valid credit card number.', 'woocommerce' ),
				'invalid_expiry_month'                  => __( "Your card's expiration month is invalid.", 'woocommerce' ),
				'invalid_expiry_year'                   => __( "Your card's expiration year is invalid.", 'woocommerce' ),
				'invalid_cvc'                           => __( "Your card's security code is invalid.", 'woocommerce' ),
				'incorrect_number'                      => __( 'Your card number is incorrect.', 'woocommerce' ),
				'incomplete_number'                     => __( 'Your card number is incomplete.', 'woocommerce' ),
				'incomplete_cvc'                        => __( "Your card's security code is incomplete.", 'woocommerce' ),
				'incomplete_expiry'                     => __( "Your card's expiration date is incomplete.", 'woocommerce' ),
				'expired_card'                          => __( 'Your card has expired.', 'woocommerce' ),
				'incorrect_cvc'                         => __( "Your card's security code is incorrect.", 'woocommerce' ),
				'postal_code_invalid'                   => __( 'Invalid zip code, please correct and try again.', 'woocommerce' ),
				'invalid_expiry_year_past'              => __( "Your card's expiration year is in the past.", 'woocommerce' ),
				'card_declined'                         => __( 'Your card was declined.', 'woocommerce' ),
				'missing'                               => __( 'There is no card on a customer that is being charged.', 'woocommerce' ),
				'processing_error'                      => __( 'An error occurred while processing your card. Try again in a little bit.', 'woocommerce' ),
				'invalid_sofort_country'                => __( 'The billing country is not accepted by Sofort. Please try another country.', 'woocommerce' ),
				'email_invalid'                         => __( 'Invalid email address, please correct and try again.', 'woocommerce' ),
				'country_code_invalid'                  => __( 'Invalid country code, please try again with a valid country code.', 'woocommerce' ),
				'tax_id_invalid'                        => __( 'Invalid Tax ID, please try again with a valid tax ID.', 'woocommerce' ),
				'invalid_wallet_type'                   => __( 'Invalid wallet payment type, please try again or use an alternative method.', 'woocommerce' ),
				'payment_intent_authentication_failure' => __( 'We are unable to authenticate your payment method. Please choose a different payment method and try again.', 'woocommerce' ),
				'authentication_required'               => __( 'Your card was declined because additional authentication is required. Please contact your card issuer or try a different payment method.', 'woocommerce' ),
				'insufficient_funds'                    => __( 'Your card has insufficient funds.', 'woocommerce' ),
			)
		);

		return is_array( $messages ) ? $messages : array();
	}
}
