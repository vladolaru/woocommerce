<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use WC_Log_Levels;
use WC_Logger_Interface;

/**
 * A WooCommerce logger that keeps every record it receives, for code that logs through wc_get_logger().
 *
 * Install it with the `woocommerce_logging_class` filter. It does not extend WC_Logger, so wc_get_logger() drops it once
 * the filter is gone instead of keeping it in its static cache.
 */
final class RecordingWcLogger implements WC_Logger_Interface {

	/**
	 * The records: level, message and context.
	 *
	 * @var array<int, array{level: string, message: string, context: array}>
	 */
	public array $records = array();

	/**
	 * The level whose call throws, to stand for a logging setup that fails; none when empty.
	 *
	 * @var string
	 */
	private string $throws_on;

	/**
	 * Constructor.
	 *
	 * @param string $throws_on The level whose call throws; none when empty.
	 */
	public function __construct( string $throws_on = '' ) {
		$this->throws_on = $throws_on;
	}

	/**
	 * Install this logger for wc_get_logger(); the test's hook snapshot removes the filter.
	 *
	 * @return self
	 */
	public function install(): self {
		add_filter(
			'woocommerce_logging_class',
			function () {
				return $this;
			}
		);

		return $this;
	}

	/**
	 * The records at a level.
	 *
	 * @param string $level The level.
	 * @return array<int, array{level: string, message: string, context: array}>
	 */
	public function at( string $level ): array {
		return array_values(
			array_filter(
				$this->records,
				static function ( array $record ) use ( $level ): bool {
					return $level === $record['level'];
				}
			)
		);
	}

	/**
	 * Keep a record under the legacy handle.
	 *
	 * @param string $handle  The handle.
	 * @param string $message The message.
	 * @param string $level   The level.
	 * @return bool
	 */
	public function add( $handle, $message, $level = WC_Log_Levels::NOTICE ) {
		$this->log( $level, $message, array( 'source' => $handle ) );

		return true;
	}

	/**
	 * Keep a record, or throw for the level set to throw.
	 *
	 * @param string $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 * @throws \RuntimeException For the level set to throw.
	 */
	public function log( $level, $message, $context = array() ) {
		if ( '' !== $this->throws_on && $level === $this->throws_on ) {
			throw new \RuntimeException( 'The logger failed.' );
		}
		$this->records[] = array(
			'level'   => (string) $level,
			'message' => (string) $message,
			'context' => (array) $context,
		);
	}

	/**
	 * Keep an emergency record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function emergency( $message, $context = array() ) {
		$this->log( WC_Log_Levels::EMERGENCY, $message, $context );
	}

	/**
	 * Keep an alert record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function alert( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ALERT, $message, $context );
	}

	/**
	 * Keep a critical record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function critical( $message, $context = array() ) {
		$this->log( WC_Log_Levels::CRITICAL, $message, $context );
	}

	/**
	 * Keep an error record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function error( $message, $context = array() ) {
		$this->log( WC_Log_Levels::ERROR, $message, $context );
	}

	/**
	 * Keep a warning record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function warning( $message, $context = array() ) {
		$this->log( WC_Log_Levels::WARNING, $message, $context );
	}

	/**
	 * Keep a notice record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function notice( $message, $context = array() ) {
		$this->log( WC_Log_Levels::NOTICE, $message, $context );
	}

	/**
	 * Keep an info record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function info( $message, $context = array() ) {
		$this->log( WC_Log_Levels::INFO, $message, $context );
	}

	/**
	 * Keep a debug record.
	 *
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function debug( $message, $context = array() ) {
		$this->log( WC_Log_Levels::DEBUG, $message, $context );
	}
}
