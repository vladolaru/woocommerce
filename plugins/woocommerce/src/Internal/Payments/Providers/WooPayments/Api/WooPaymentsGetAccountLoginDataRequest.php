<?php
/**
 * WooPaymentsGetAccountLoginDataRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The request for a one-time link into the merchant's Stripe dashboard, filtered by `wpcay_get_account_login_data`.
 *
 * The filter keeps the WooPayments extension's misspelled name, so callbacks written for it keep running (client 11.1.0
 * `includes/core/server/request/class-get-account-login-data.php:29`).
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsGetAccountLoginDataRequest extends WooPaymentsApiRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wpcay_get_account_login_data';

	private const API = 'accounts/login_links';

	/**
	 * Create a request for a one-time Stripe dashboard login link.
	 *
	 * @param string $redirect_url URL to navigate back to from the dashboard.
	 * @param bool   $test_mode    Whether the account is onboarding in test mode.
	 * @return self
	 * @throws WooPaymentsApiException When the redirect URL is not allowed.
	 */
	public static function from_redirect_url( string $redirect_url, bool $test_mode ): self {
		$request = new self();
		$request->set_api( self::API );
		$request->set_method( 'POST' );
		$request->set_param( 'test_mode', $test_mode );
		$request->set_redirect_url( $redirect_url );

		return $request;
	}

	/**
	 * Set the redirect URL; only a URL in the allowed redirect hosts is accepted.
	 *
	 * @param string $redirect_url URL to navigate back to from the dashboard.
	 * @throws WooPaymentsApiException When the redirect URL is not allowed.
	 */
	public function set_redirect_url( string $redirect_url ): void {
		$fallback_url = wp_generate_password( 12, false );
		if ( hash_equals( $fallback_url, wp_validate_redirect( $redirect_url, $fallback_url ) ) ) {
			throw new WooPaymentsApiException(
				sprintf(
					// translators: %s: redirect URL.
					esc_html__( '%s is not a valid redirect URL. Use a URL in the allowed_redirect_hosts filter.', 'woocommerce' ),
					esc_html( $redirect_url )
				),
				'wcpay_core_invalid_request_parameter_invalid_redirect_url',
				400
			);
		}

		$this->set_param( 'redirect_url', $redirect_url );
	}

	/**
	 * Login link requests must use the connection-owner user token.
	 *
	 * @return bool
	 */
	public function should_use_user_token(): bool {
		return true;
	}

	/**
	 * Wrap the decoded answer in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return $this->format_default_response( $response );
	}
}
