<?php
/**
 * WooPaymentsLogEntryFormat class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Adds a per-request id and a running entry number to each WooPayments log line, after core's time and level.
 *
 * Support follows one request through a log file by the id, as with the client's lines (client 11.1.0
 * `src/Internal/LoggerContext.php:63-79`, `:119-132`). Core's line is kept, so its context JSON stays.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsLogEntryFormat implements RegisterHooksInterface {

	/**
	 * The id shared by every line of this request.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * The number of the last WooPayments line of this request.
	 *
	 * @var int
	 */
	private int $entry_number = 0;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->request_id = uniqid();
	}

	/**
	 * Register the log entry filter.
	 */
	public function register() {
		add_filter( 'woocommerce_format_log_entry', array( $this, 'handle_woocommerce_format_log_entry' ), 10, 2 );
	}

	/**
	 * Handle the woocommerce_format_log_entry filter.
	 *
	 * @internal
	 *
	 * @param mixed $entry The formatted entry.
	 * @param mixed $args  The raw entry data: timestamp, level, message and context.
	 * @return mixed
	 */
	public function handle_woocommerce_format_log_entry( $entry, $args ) {
		if ( ! is_string( $entry ) || ! is_array( $args ) || ! isset( $args['level'] ) || ! is_string( $args['level'] )
			|| WooPaymentsLogger::SOURCE !== ( $args['context']['source'] ?? null ) ) {
			return $entry;
		}

		// Only core's own "<time> <LEVEL> " prefix, at the start of a line not numbered yet (core `format_entry()`).
		if ( 1 !== preg_match( '/^\S+ ' . preg_quote( strtoupper( $args['level'] ), '/' ) . ' (?![0-9a-f]{13}-\d{4} )/', $entry, $prefix ) ) {
			return $entry;
		}

		return $prefix[0] . sprintf( '%s-%04d ', $this->request_id, ++$this->entry_number ) . substr( $entry, strlen( $prefix[0] ) );
	}
}
