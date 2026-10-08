<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogEntryFormat;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use WC_Unit_Test_Case;

/**
 * Tests for the request id and entry number on WooPayments log lines.
 *
 * Client 11.1.0 `src/Internal/LoggerContext.php:63-79`, `:119-132` prefixes each of its log lines with a per-request id and a
 * sequential entry number, so support can follow one request through a log file.
 */
class WooPaymentsLogEntryFormatTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Loads on every request type of a connected or active store, where WooPayments writes log lines.
	 */
	public function test_loads_on_every_request_of_a_connected_store(): void {
		$matrix = WooPaymentsProvider::get_bootstrap_root_matrix();

		foreach ( array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ) as $state ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request ) {
				$this->assertContains( WooPaymentsLogEntryFormat::class, $matrix[ $state ][ $request ], "{$state} {$request}" );
			}
		}
	}

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
	 * @testdox Leaves lines of other sources unchanged and does not count them.
	 */
	public function test_leaves_other_sources_unchanged(): void {
		$sut = new WooPaymentsLogEntryFormat();
		$sut->register();

		$this->assertSame( '2026-10-06T10:00:00+00:00 ERROR Other.', $this->format( '2026-10-06T10:00:00+00:00 ERROR Other.', 'error', 'other-source' ) );
		$this->assertMatchesRegularExpression( '/ INFO \w+-0001 Mine\.$/', $this->format( '2026-10-06T10:00:00+00:00 INFO Mine.', 'info', 'woopayments' ) );
	}

	/**
	 * @testdox Leaves a line alone when an earlier filter changed core's prefix, when it is already numbered, or when it is not a string.
	 */
	public function test_leaves_lines_without_cores_prefix_unchanged(): void {
		$sut = new WooPaymentsLogEntryFormat();
		$sut->register();

		$changed_prefix = '[ERROR] 2026-10-06 The platform sent an upstream ERROR response.';
		$numbered       = '2026-10-06T10:00:00+00:00 ERROR 0123456789abc-0007 Already numbered.';

		$this->assertSame( $changed_prefix, $this->format( $changed_prefix, 'error', 'woopayments' ) );
		$this->assertSame( $numbered, $this->format( $numbered, 'error', 'woopayments' ) );
		$this->assertSame(
			array( 'not a string' ),
			$sut->handle_woocommerce_format_log_entry(
				array( 'not a string' ),
				array(
					'level'   => 'error',
					'context' => array( 'source' => 'woopayments' ),
				)
			)
		);
		$this->assertMatchesRegularExpression( '/ ERROR \w+-0001 First counted\.$/', $this->format( '2026-10-06T10:00:00+00:00 ERROR First counted.', 'error', 'woopayments' ) );
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
