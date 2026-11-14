<?php
/**
 * Request class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

/**
 * A platform request built from an API path, an HTTP method and params, for requests that need no class of their own.
 *
 * @since 11.0.0
 * @internal
 */
class Request extends AbstractRequest {

	/**
	 * WooPayments API path.
	 *
	 * @var string
	 */
	private string $api = '';

	/**
	 * HTTP method.
	 *
	 * @var string
	 */
	private string $method = 'GET';

	/**
	 * Create a generic WooPayments API request object.
	 *
	 * @param array<int|string,mixed> $params Request params.
	 * @param string                  $api    WooPayments API path.
	 * @param string                  $method HTTP method.
	 * @return self
	 */
	public static function create( array $params, string $api, string $method ): self {
		$request = new self();

		foreach ( $params as $key => $value ) {
			$request->set_param( (string) $key, $value );
		}

		$request->set_api( $api );
		$request->set_method( $method );

		return $request;
	}

	/**
	 * Get the WooPayments API path.
	 *
	 * @return string
	 */
	public function get_api(): string {
		return $this->api;
	}

	/**
	 * Set the WooPayments API path.
	 *
	 * @param string $api WooPayments API path.
	 */
	public function set_api( string $api ): void {
		$this->api = ltrim( $api, '/' );
	}

	/**
	 * Get the HTTP method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}

	/**
	 * Set the HTTP method.
	 *
	 * @param string $method HTTP method.
	 */
	public function set_method( string $method ): void {
		$this->method = strtoupper( $method );
	}

	/**
	 * Return the decoded answer as it is.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return $response;
	}
}
