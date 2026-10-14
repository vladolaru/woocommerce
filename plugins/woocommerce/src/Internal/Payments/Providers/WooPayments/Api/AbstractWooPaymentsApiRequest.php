<?php
/**
 * AbstractWooPaymentsApiRequest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsResponse;

/**
 * A filterable request the WooPayments platform API client sends: its params, its request filter, its transport flags,
 * and how it is sent and its answer formatted.
 *
 * `Api/` holds the WooPayments platform API client and the requests it sends.
 *
 * @since 11.2.0
 * @internal
 */
abstract class AbstractWooPaymentsApiRequest {

	protected const DEFAULT_PARAMS = array();

	/**
	 * Request params.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = array();

	/**
	 * WordPress filter applied when the request is sent.
	 *
	 * Requests with a fixed filter set this property. Requests whose filter depends on the operation get it from
	 * assign_hook() before send().
	 *
	 * @var string
	 */
	protected $hook = '';

	/**
	 * Constructor.
	 */
	final public function __construct() {
	}

	/**
	 * Returns the request's API.
	 *
	 * @return string
	 */
	abstract public function get_api(): string;

	/**
	 * Returns the request's HTTP method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET';
	}

	/**
	 * Whether this request is site-specific.
	 *
	 * @return bool
	 */
	public function is_site_specific(): bool {
		return true;
	}

	/**
	 * Whether this request should use the user token.
	 *
	 * @return bool
	 */
	public function should_use_user_token(): bool {
		return false;
	}

	/**
	 * Whether this request should return the raw response.
	 *
	 * @return bool
	 */
	public function should_return_raw_response(): bool {
		return false;
	}

	/**
	 * Get a request param by key.
	 *
	 * @param string $key Param key.
	 * @return mixed
	 */
	public function get_param( string $key ) {
		$params = $this->get_params();

		if ( array_key_exists( $key, $params ) ) {
			return $params[ $key ];
		}

		return null;
	}

	/**
	 * Get request params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_params(): array {
		$params = array_merge( static::DEFAULT_PARAMS, $this->params );

		foreach ( $params as $key => $value ) {
			if ( true === $value ) {
				$params[ $key ] = 'true';
			}
		}

		return $params;
	}

	/**
	 * Set a request parameter and return the request, so callbacks can chain setters.
	 *
	 * @param mixed $key   Param key.
	 * @param mixed $value Param value.
	 * @return static
	 */
	public function set( $key, $value ) {
		$this->set_param( (string) $key, $value );

		return $this;
	}

	/**
	 * Set a request param.
	 *
	 * @param string $key   Param key.
	 * @param mixed  $value Param value.
	 */
	public function set_param( string $key, $value ): void {
		$this->params[ $key ] = $value;
	}

	/**
	 * Set the parameter of a filter setter the request does not declare, under the name after `set_` and unchanged.
	 * Extensions call such setters on the requests their filter callbacks receive. Declared setters win.
	 *
	 * @param string           $name      Method name.
	 * @param array<int,mixed> $arguments Method arguments.
	 */
	public function __call( string $name, array $arguments ): void {
		if ( 0 === strpos( $name, 'set_' ) && array_key_exists( 0, $arguments ) ) {
			$this->set_param( substr( $name, 4 ), $arguments[0] );
		}
	}

	/**
	 * Get the WordPress filter applied when the request is sent.
	 *
	 * @return string
	 */
	final public function get_hook(): string {
		return $this->hook;
	}

	/**
	 * Assign an operation-specific WordPress request filter.
	 *
	 * @param string $hook WordPress filter name.
	 * @return void
	 */
	public function assign_hook( string $hook ) {
		$this->hook = $hook;
	}

	/**
	 * Fire this request's filter and return the request the callbacks hand back.
	 *
	 * @since 11.2.0
	 *
	 * @return AbstractWooPaymentsApiRequest
	 * @throws WooPaymentsApiException When a callback returns something that is not a request.
	 */
	final public function get_filtered_request(): AbstractWooPaymentsApiRequest {
		/**
		 * Filters a WooPayments API request before it is sent to the platform.
		 *
		 * Fires under the WooPayments extension's request hook names, so existing callbacks keep running. Callbacks receive the
		 * provider's own request object, not the extension's request classes; its class and setters are not a supported API.
		 * Any return value that is not a request object stops the request with an error.
		 *
		 * @since 11.0.0
		 *
		 * @param AbstractWooPaymentsApiRequest $request Request about to be sent.
		 */
		$filtered_request = apply_filters( $this->hook, $this );

		if ( ! $filtered_request instanceof self ) {
			// translators: %s: WooPayments API request filter hook name.
			$message = sprintf( __( 'Invalid WooPayments request returned by %s.', 'woocommerce' ), $this->hook );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
			throw new WooPaymentsApiException( $message, 'wcpay_invalid_filtered_request', 500 );
		}

		return $filtered_request;
	}

	/**
	 * Format the low-level transport response.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return mixed
	 */
	public function format_response( $response ) {
		return $this->format_default_response( $response );
	}

	/**
	 * Wrap a transport response in a response object.
	 *
	 * @param array<mixed> $response Transport response.
	 * @return WooPaymentsResponse
	 */
	protected function format_default_response( $response ): WooPaymentsResponse {
		return new WooPaymentsResponse( $response );
	}

	/**
	 * Send the request to the platform through the API client and format the answer.
	 *
	 * Callbacks on the WooPayments extension's request filters may call send() on the request they receive, so remove it
	 * only through a deprecation cycle.
	 *
	 * @since 11.0.0
	 * @return mixed
	 */
	public function send() {
		$api_client = wc_get_container()->get( WooPaymentsApiClient::class );

		return $this->format_response( $api_client->send_list_request( $this ) );
	}
}
