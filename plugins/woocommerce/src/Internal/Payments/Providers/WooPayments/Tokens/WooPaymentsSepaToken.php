<?php
/**
 * WooPaymentsSepaToken class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens;

use WC_Payment_Token;

/**
 * WooPayments SEPA Direct Debit payment token.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSepaToken extends WC_Payment_Token {

	/**
	 * Token type preserved from the WooPayments extension.
	 *
	 * @var string
	 */
	public const TYPE = 'wcpay_sepa';

	/**
	 * Payment token type.
	 *
	 * @var string
	 */
	protected $type = self::TYPE;

	/**
	 * Stores SEPA payment token data.
	 *
	 * @var array<string,string>
	 */
	protected $extra_data = array(
		'last4' => '',
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

		return sprintf(
			/* translators: %s: last 4 digits of the IBAN account. */
			__( 'SEPA IBAN ending in %s', 'woocommerce' ),
			$this->get_last4()
		);
	}

	/**
	 * Get the hook prefix for token getters.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	protected function get_hook_prefix() {
		return 'woocommerce_payments_token_wcpay_sepa_get_';
	}

	/**
	 * Validate SEPA payment tokens.
	 *
	 * @return bool True when the token has required SEPA data.
	 *
	 * @since 11.0.0
	 */
	public function validate() {
		return parent::validate() && (bool) $this->get_last4( 'edit' );
	}

	/**
	 * Get the IBAN last four digits.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_last4( $context = 'view' ) {
		return (string) $this->get_prop( 'last4', $context );
	}

	/**
	 * Set the IBAN last four digits.
	 *
	 * @param string $last4 Last four digits.
	 *
	 * @since 11.0.0
	 */
	public function set_last4( $last4 ): void {
		$this->set_prop( 'last4', $last4 );
	}
}
