<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use WC_Log_Levels;
use WC_Logger_Interface;

/**
 * WooCommerce logger test double that records each call once, whatever log handlers the store has.
 */
class RecordingWcLogger implements WC_Logger_Interface {

	/**
	 * Recorded calls as level, message and source.
	 *
	 * @var array<int,array{0:string,1:string,2:string}>
	 */
	public array $lines = array();

	/**
	 * Make this logger the one `wc_get_logger()` returns until the test's hooks are restored.
	 *
	 * @return self
	 */
	public static function install(): self {
		$logger = new self();
		add_filter(
			'woocommerce_logging_class',
			static function () use ( $logger ) {
				return $logger;
			}
		);

		return $logger;
	}

	/**
	 * Get the error-level calls.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public function get_errors(): array {
		return array_values( array_filter( $this->lines, static fn( array $line ): bool => WC_Log_Levels::ERROR === $line[0] ) );
	}

	/**
	 * Record a message under a handle.
	 *
	 * @param string $handle  Log handle.
	 * @param string $message Message.
	 * @param string $level   Level.
	 * @return bool
	 */
	public function add( $handle, $message, $level = WC_Log_Levels::NOTICE ) {
		$this->log( $level, $message, array( 'source' => $handle ) );
		return true;
	}

	/**
	 * Record a message.
	 *
	 * @param string              $level   Level.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function log( $level, $message, $context = array() ) {
		$this->lines[] = array( (string) $level, (string) $message, (string) ( $context['source'] ?? '' ) );
	}

	/**
	 * Record an emergency message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function emergency( $message, $context = array() ) {
		$this->log( WC_Log_Levels::EMERGENCY, $message, $context );
	}

	/**
	 * Record an alert message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function alert( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ALERT, $message, $context );
	}

	/**
	 * Record a critical message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function critical( $message, $context = array() ) {
		$this->log( WC_Log_Levels::CRITICAL, $message, $context );
	}

	/**
	 * Record an error message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function error( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ERROR, $message, $context );
	}

	/**
	 * Record a warning message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function warning( $message, $context = array() ) {
		$this->log( WC_Log_Levels::WARNING, $message, $context );
	}

	/**
	 * Record a notice message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function notice( $message, $context = array() ) {
		$this->log( WC_Log_Levels::NOTICE, $message, $context );
	}

	/**
	 * Record an info message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function info( $message, $context = array() ) {
		$this->log( WC_Log_Levels::INFO, $message, $context );
	}

	/**
	 * Record a debug message.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 */
	public function debug( $message, $context = array() ) {
		$this->log( WC_Log_Levels::DEBUG, $message, $context );
	}
}
