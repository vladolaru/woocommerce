<?php
/**
 * LinkToken class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use WC_Payment_Token;

/**
 * WooPayments Link payment token.
 *
 * @since 11.0.0
 * @internal
 */
class LinkToken extends WC_Payment_Token {

	/**
	 * Token type; client 11.1.0 stores the same value (class-wc-payment-token-wcpay-link.php:26), so tokens saved before
	 * the switch keep loading.
	 *
	 * @var string
	 */
	public const TYPE = 'wcpay_link';

	/**
	 * Payment token type.
	 *
	 * @var string
	 */
	protected $type = self::TYPE;

	/**
	 * Stores Link payment token data.
	 *
	 * @var array<string,string>
	 */
	protected $extra_data = array(
		'email' => '',
	);

	/**
	 * Get the brand for this token's connected account.
	 *
	 * @since 11.4.0
	 *
	 * @return string
	 */
	public function get_brand_name(): string {
		$container = wc_get_container();
		$country   = $container->get( WooPaymentsAccountService::class )->get_account_country();

		return $container->get( PaymentMethodRegistry::class )->get_link_title( $country );
	}

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
			/* translators: 1: Link brand name, 2: redacted customer email. */
			__( '%1$s email ending in %2$s', 'woocommerce' ),
			$this->get_brand_name(),
			$this->get_redacted_email()
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
		return 'woocommerce_payments_token_wcpay_link_get_';
	}

	/**
	 * Get the customer email.
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
	 * Set the customer email.
	 *
	 * @param string $email Customer email.
	 *
	 * @since 11.0.0
	 */
	public function set_email( $email ): void {
		$this->set_prop( 'email', $email );
	}

	/**
	 * Get the redacted customer email.
	 *
	 * @param string $context What the value is for. Valid values are view and edit.
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_redacted_email( $context = 'view' ) {
		return $this->redact_email_address( $this->get_email( $context ) );
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
	 * Redact an email address the way client 11.1.0 displays it (class-wc-payment-token-wcpay-link.php:114).
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
