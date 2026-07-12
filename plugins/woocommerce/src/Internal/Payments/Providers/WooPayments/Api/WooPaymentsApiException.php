<?php
/**
 * WooPaymentsApiException class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api;

use RuntimeException;

/**
 * Exception thrown by the native WooPayments provider transport.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsApiException extends RuntimeException {

	/**
	 * Provider error code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * HTTP status code.
	 *
	 * @var int
	 */
	private int $http_code;

	/**
	 * Provider error type.
	 *
	 * @var string
	 */
	private string $error_type;

	/**
	 * Provider decline code.
	 *
	 * @var string
	 */
	private string $decline_code;

	/**
	 * Constructor.
	 *
	 * @param string $message      Exception message.
	 * @param string $error_code   Provider error code.
	 * @param int    $http_code    HTTP status code.
	 * @param string $error_type   Provider error type.
	 * @param string $decline_code Provider decline code.
	 *
	 * @since 11.0.0
	 */
	public function __construct( string $message, string $error_code = '', int $http_code = 0, string $error_type = '', string $decline_code = '' ) {
		parent::__construct( $message );

		$this->error_code   = $error_code;
		$this->http_code    = $http_code;
		$this->error_type   = $error_type;
		$this->decline_code = $decline_code;
	}

	/**
	 * Get the provider error code.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Get the HTTP status code.
	 *
	 * @return int
	 *
	 * @since 11.0.0
	 */
	public function get_http_code(): int {
		return $this->http_code;
	}

	/**
	 * Get the provider error type.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_error_type(): string {
		return $this->error_type;
	}

	/**
	 * Get the provider decline code.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_decline_code(): string {
		return $this->decline_code;
	}
}
