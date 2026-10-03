<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTransactionsListRequest;
use ReflectionMethod;
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

		$method = $this->get_format_method();
		$date   = '2026-06-01 12:00:00';

		// A timezone matching the store yields no shift.
		$this->assertSame( $date, $method->invoke( null, $date, 'UTC' ) );

		// A different valid timezone shifts the date deterministically.
		$this->assertSame( '2026-06-01 08:00:00', $method->invoke( null, $date, 'America/New_York' ) );
	}

	/**
	 * @testdox Invalid user timezones fall back to UTC instead of throwing.
	 */
	public function test_format_transaction_date_falls_back_to_utc_for_invalid_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$method = $this->get_format_method();
		$date   = '2026-06-01 12:00:00';

		$utc_result     = $method->invoke( null, $date, 'UTC' );
		$invalid_result = $method->invoke( null, $date, 'Not/AZone' );

		// The invalid timezone is treated as UTC (same result as an explicit UTC request)...
		$this->assertSame( $utc_result, $invalid_result );
		// ...and the fallback path actually shifted the date, since the store timezone differs from UTC.
		$this->assertNotSame( $date, $invalid_result );
	}

	/**
	 * Get an accessible reflection of the private date-formatting method.
	 *
	 * @return ReflectionMethod
	 */
	private function get_format_method(): ReflectionMethod {
		$method = new ReflectionMethod( WooPaymentsTransactionsListRequest::class, 'format_transaction_date_by_timezone' );
		$method->setAccessible( true );

		return $method;
	}
}
