<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsTransactionsListRequest timezone-aware date formatting.
 */
class WooPaymentsTransactionsListRequestTest extends WC_Unit_Test_Case {

	/**
	 * Original store timezone option.
	 *
	 * @var string
	 */
	private string $original_timezone_string;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_timezone_string = (string) get_option( 'timezone_string', '' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		update_option( 'timezone_string', $this->original_timezone_string );

		parent::tearDown();
	}

	/**
	 * @testdox Valid user timezones still shift transaction dates relative to the store timezone.
	 */
	public function test_format_transaction_date_preserves_valid_timezone(): void {
		update_option( 'timezone_string', 'UTC' );
		$date = '2026-06-01 12:00:00';

		// A timezone matching the store yields no shift.
		$this->assertSame( $date, WooPaymentsTransactionsListRequest::format_transaction_date_by_timezone( $date, 'UTC' ) );

		// A different valid timezone shifts the date deterministically.
		$this->assertSame( '2026-06-01 08:00:00', WooPaymentsTransactionsListRequest::format_transaction_date_by_timezone( $date, 'America/New_York' ) );
	}

	/**
	 * @testdox A time zone or date PHP cannot parse leaves the date unchanged instead of failing the request.
	 *
	 * Client 11.1.0 Request_Utils::format_transaction_date_by_timezone() has no guard, so the same input fatals its request.
	 */
	public function test_format_transaction_date_leaves_unparseable_input_unchanged(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$this->assertSame( '2026-06-01 12:00:00', WooPaymentsTransactionsListRequest::format_transaction_date_by_timezone( '2026-06-01 12:00:00', 'Not/AZone' ) );
		$this->assertSame( 'not a date', WooPaymentsTransactionsListRequest::format_transaction_date_by_timezone( 'not a date', '+03:00' ) );
	}
}
