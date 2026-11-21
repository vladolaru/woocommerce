<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogEntryFormat;
use WC_Unit_Test_Case;

/**
 * Tests for the request id and entry number on WooPayments log lines.
 *
 * Client 11.1.0 `src/Internal/LoggerContext.php:63-79`, `:119-132` prefixes each of its log lines with a per-request id and a
 * sequential entry number, so support can follow one request through a log file.
 */
class WooPaymentsLogEntryFormatTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Adds one request id and a running entry number after the level of each WooPayments line, keeping the rest of the line.
	 */
	public function test_adds_the_request_id_and_entry_number_to_woopayments_lines(): void {
		$sut = new WooPaymentsLogEntryFormat();
		$sut->register();

		$first  = $this->format( '2026-10-06T10:00:00+00:00 ERROR First line. CONTEXT: {"note":"x"}', 'error', 'woopayments' );
		$second = $this->format( '2026-10-06T10:00:01+00:00 INFO Second line.', 'info', 'woopayments' );

		$this->assertMatchesRegularExpression( '/^2026-10-06T10:00:00\+00:00 ERROR ([0-9a-f]{13})-0001 First line\. CONTEXT: \{"note":"x"\}$/', $first );
		$this->assertMatchesRegularExpression( '/^2026-10-06T10:00:01\+00:00 INFO ([0-9a-f]{13})-0002 Second line\.$/', $second );
		preg_match( '/ERROR (\w+)-/', $first, $first_id );
		preg_match( '/INFO (\w+)-/', $second, $second_id );
		$this->assertSame( $first_id[1], $second_id[1], 'One request keeps one id.' );
	}

	/**
	 * @testdox Leaves a line unchanged and uncounted when $label.
	 * @testWith ["an earlier filter changed core's prefix", "[ERROR] 2026-10-06 The platform sent an upstream ERROR response.", "woopayments"]
	 *           ["it is already numbered", "2026-10-06T10:00:00+00:00 ERROR 0123456789abc-0007 Already numbered.", "woopayments"]
	 *           ["it is not a string", null, "woopayments"]
	 *           ["it comes from another source", "2026-10-06T10:00:00+00:00 ERROR Other.", "other-source"]
	 *
	 * @param string      $label  Case.
	 * @param string|null $entry  Formatted entry, or null for a non-string entry.
	 * @param string      $source Log source.
	 */
	public function test_leaves_lines_unchanged_and_uncounted( string $label, ?string $entry, string $source ): void {
		unset( $label );
		$sut = new WooPaymentsLogEntryFormat();
		$sut->register();

		if ( null === $entry ) {
			$this->assertSame(
				array( 'not a string' ),
				$sut->handle_woocommerce_format_log_entry(
					array( 'not a string' ),
					array(
						'level'   => 'error',
						'context' => array( 'source' => $source ),
					)
				)
			);
		} else {
			$this->assertSame( $entry, $this->format( $entry, 'error', $source ) );
		}
		$this->assertMatchesRegularExpression( '/ ERROR \w+-0001 First counted\.$/', $this->format( '2026-10-06T10:00:00+00:00 ERROR First counted.', 'error', 'woopayments' ), 'The unchanged line is not counted.' );
	}

	/**
	 * Run an entry through the core log entry filter.
	 *
	 * @param string $entry  Formatted entry.
	 * @param string $level  Level.
	 * @param string $source Log source.
	 * @return string
	 */
	private function format( string $entry, string $level, string $source ): string {
		// The arguments core's log handlers pass with the filter (includes/abstracts/abstract-wc-log-handler.php format_entry()).
		return (string) apply_filters(
			'woocommerce_format_log_entry',
			$entry,
			array(
				'timestamp' => 1791280800,
				'level'     => $level,
				'message'   => 'unused',
				'context'   => array( 'source' => $source ),
			)
		);
	}
}
