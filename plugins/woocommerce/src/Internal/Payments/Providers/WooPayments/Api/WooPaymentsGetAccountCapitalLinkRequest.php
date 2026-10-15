<?php
/**
 * WooPaymentsGetAccountCapitalLinkRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The request for a link to the merchant's Capital loan offer, filtered by `wcpay_get_account_capital_link`.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsGetAccountCapitalLinkRequest extends WooPaymentsApiRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_get_account_capital_link';

	private const API = 'accounts/capital_links';

	private const TYPE = 'capital_financing_offer';

	/**
	 * Create a request for the Capital view-offer link.
	 *
	 * @param string $return_url  URL to return to after viewing the offer.
	 * @param string $refresh_url URL to use when the link expires or is invalid.
	 * @return self
	 */
	public static function from_urls( string $return_url, string $refresh_url ): self {
		$request = new self();
		$request->set_api( self::API );
		$request->set_method( 'POST' );
		$request->set_type( self::TYPE );
		$request->set_return_url( $return_url );
		$request->set_refresh_url( $refresh_url );

		return $request;
	}

	/**
	 * Set the Capital link type.
	 *
	 * @param string $type Capital link type.
	 */
	public function set_type( string $type ): void {
		$this->set_param( 'type', $type );
	}

	/**
	 * Set the URL to return to after viewing the offer.
	 *
	 * @param string $return_url URL to return to after viewing the offer.
	 */
	public function set_return_url( string $return_url ): void {
		$this->set_param( 'return_url', $return_url );
	}

	/**
	 * Set the URL used when the link expired or is invalid.
	 *
	 * @param string $refresh_url URL to use when the link expires or is invalid.
	 */
	public function set_refresh_url( string $refresh_url ): void {
		$this->set_param( 'refresh_url', $refresh_url );
	}

	/**
	 * Capital link requests must use the connection-owner user token.
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
