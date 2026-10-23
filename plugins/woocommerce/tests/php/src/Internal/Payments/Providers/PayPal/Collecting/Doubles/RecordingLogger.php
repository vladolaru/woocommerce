<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles;

use Automattic\WooCommerce\Vendor\Psr\Log\AbstractLogger;

/**
 * A logger that keeps every record it receives, as given.
 */
final class RecordingLogger extends AbstractLogger {

	/**
	 * The records: level, message and context.
	 *
	 * @var array<int, array{level: mixed, message: mixed, context: array}>
	 */
	public array $records = array();

	/**
	 * Keep a record.
	 *
	 * @param mixed  $level   The level.
	 * @param string $message The message.
	 * @param array  $context The context.
	 */
	public function log( $level, $message, array $context = array() ): void {
		$this->records[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	/**
	 * Every record as text: the JSON a log file would hold and print_r(), which also shows non-public properties.
	 *
	 * @return string
	 */
	public function as_text(): string {
		return wp_json_encode( $this->records ) . "\n" . print_r( $this->records, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Test inspection of what a log handler could write.
	}
}
