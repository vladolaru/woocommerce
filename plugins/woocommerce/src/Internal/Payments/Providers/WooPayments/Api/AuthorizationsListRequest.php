<?php
/**
 * AuthorizationsListRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * The authorizations list request, filtered by `wcpay_list_authorizations_request`.
 *
 * @since 11.0.0
 * @internal
 */
class AuthorizationsListRequest extends PaginatedListRequest {
	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * @var string
	 */
	protected $hook = 'wcpay_list_authorizations_request';

	/**
	 * Create a request from normalized params.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return self
	 */
	public static function from_params( array $params ): self {
		$request = new self();

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return 'authorizations';
	}
}
