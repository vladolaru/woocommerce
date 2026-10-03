<?php
/**
 * WooPaymentsAmazonPayToken class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens;

use WC_Payment_Token;

/**
 * WooPayments Amazon Pay payment token.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAmazonPayToken extends WC_Payment_Token {

	/**
	 * Token type preserved from the WooPayments extension.
	 *
	 * @var string
	 */
	public const TYPE = 'wcpay_amazon_pay';

	/**
	 * Payment token type.
	 *
	 * @var string
	 */
	protected $type = self::TYPE;

	/**
	 * Stores Amazon Pay payment token data.
	 *
	 * @var array<string,string>
	 */
	protected $extra_data = array(
		'email' => '',
	);

	/**
	 * Get the payment method display name.
	 *
	 * @param string $deprecated Deprecated since WooCommerce 3.0.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_display_name( $deprecated = '' ) {
		unset( $deprecated );

		$email = $this->get_email();
		if ( '' !== $email ) {
			return sprintf(
				/* translators: %s: redacted customer email. */
				__( 'Amazon Pay (%s)', 'woocommerce' ),
				$email
			);
		}

		return __( 'Amazon Pay', 'woocommerce' );
	}

	/**
	 * Get the hook prefix for token getters.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	protected function get_hook_prefix() {
		return 'woocommerce_payments_token_wcpay_amazon_pay_get_';
	}

	/**
	 * Get the redacted customer email.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_email( $context = 'view' ) {
		return (string) $this->get_prop( 'email', $context );
	}

	/**
	 * Set the customer email after redacting it for storage.
	 *
	 * @param string $email Customer email.
	 *
	 * @since 11.0.0
	 */
	public function set_email( $email ): void {
		$this->set_prop( 'email', $this->redact_email_address( (string) $email ) );
	}

	/**
	 * Get the payment token type.
	 *
	 * @param string $deprecated Deprecated since WooCommerce 3.0.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_type( $deprecated = '' ) {
		unset( $deprecated );

		return self::TYPE;
	}

	/**
	 * Redact an email address to the extension-compatible display format.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function redact_email_address( string $email ): string {
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return $email;
		}

		$shortened_length        = 4;
		list( $handle, $domain ) = explode( '@', $email, 2 );
		$redacted_handle         = strlen( $handle ) > $shortened_length ? substr( $handle, - $shortened_length ) : $handle;

		return '***' . $redacted_handle . '@' . $domain;
	}
}
