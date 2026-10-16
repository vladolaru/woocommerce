<?php
/**
 * WordPress error stub for the standalone secretless provider fixture test.
 *
 * @package woopayments-native-ci-fixture
 */

declare( strict_types = 1 );

/**
 * Minimal WordPress error object used by the fixture contract test.
 */
final class WP_Error {
	/** @var string */
	public string $code;

	/** @var string */
	public string $message;

	/**
	 * Creates an error result.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 */
	public function __construct( string $code, string $message ) {
		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Returns the recorded error message.
	 */
	public function get_error_message(): string {
		return $this->message;
	}
}
