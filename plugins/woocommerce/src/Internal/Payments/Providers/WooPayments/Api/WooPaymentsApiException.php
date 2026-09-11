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
	 * Structured platform error data (e.g. minimum_amount, ruleset_results).
	 *
	 * @var array<string,mixed>
	 */
	private array $error_data;

	/**
	 * Failed payment intent ID from the error envelope.
	 *
	 * @var string
	 */
	private string $payment_intent_id;

	/**
	 * Merchant-facing seller message from the declined charge outcome.
	 *
	 * @var string
	 */
	private string $merchant_message;

	/**
	 * Constructor.
	 *
	 * @param string              $message           Exception message.
	 * @param string              $error_code        Provider error code.
	 * @param int                 $http_code         HTTP status code.
	 * @param string              $error_type        Provider error type.
	 * @param string              $decline_code      Provider decline code.
	 * @param array<string,mixed> $error_data        Structured platform error data.
	 * @param string              $payment_intent_id Failed payment intent ID.
	 * @param string              $merchant_message  Merchant-facing seller message.
	 *
	 * @since 11.0.0
	 */
	public function __construct( string $message, string $error_code = '', int $http_code = 0, string $error_type = '', string $decline_code = '', array $error_data = array(), string $payment_intent_id = '', string $merchant_message = '' ) {
		parent::__construct( $message );

		$this->error_code        = $error_code;
		$this->http_code         = $http_code;
		$this->error_type        = $error_type;
		$this->decline_code      = $decline_code;
		$this->error_data        = $error_data;
		$this->payment_intent_id = $payment_intent_id;
		$this->merchant_message  = $merchant_message;
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

	/**
	 * Get the structured platform error data.
	 *
	 * @return array<string,mixed>
	 *
	 * @since 11.0.0
	 */
	public function get_error_data(): array {
		return $this->error_data;
	}

	/**
	 * Get the failed payment intent ID from the error envelope.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_payment_intent_id(): string {
		return $this->payment_intent_id;
	}

	/**
	 * Get the merchant-facing seller message from the declined charge outcome.
	 *
	 * @return string
	 *
	 * @since 11.0.0
	 */
	public function get_merchant_message(): string {
		return $this->merchant_message;
	}
}
