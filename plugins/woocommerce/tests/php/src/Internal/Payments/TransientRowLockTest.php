<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use WC_Unit_Test_Case;

/**
 * Tests for the TransientRowLock class.
 *
 * OrderPaymentLockTest covers the same claim through the order payment lock, with a holder record and the race orderings.
 */
class TransientRowLockTest extends WC_Unit_Test_Case {

	private const KEY = 'transient_row_lock_test';

	/**
	 * The System Under Test.
	 *
	 * @var TransientRowLock
	 */
	private TransientRowLock $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( TransientRowLock::class );
	}

	/**
	 * @testdox A claim should hold the lock until its release, and refuse a second claim meanwhile.
	 */
	public function test_claim_excludes_a_second_claim_until_release(): void {
		$this->assertTrue( $this->sut->claim( self::KEY, 'first', 300 ) );
		$this->assertFalse( $this->sut->claim( self::KEY, 'second', 300 ), 'A held lock must refuse another claim.' );
		$this->assertSame( 'first', $this->sut->read( self::KEY ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_row( '_transient_timeout_' . self::KEY ), 'The lock must expire a full TTL from now.' );

		$this->sut->release( self::KEY, 'first' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
		$this->assertTrue( $this->sut->claim( self::KEY, 'third', 300 ), 'A released lock must be claimable again.' );
	}

	/**
	 * @testdox A claim should refuse a lock another request stored after this request cached the lock as missing.
	 */
	public function test_claim_refuses_a_lock_stored_after_this_request_cached_it_as_missing(): void {
		// This request reads the lock as missing, which WordPress records in its notoptions cache; add_option() would trust that and upsert.
		$this->assertFalse( get_option( '_transient_' . self::KEY ) );
		$this->insert_rows( 'other_request', time() + 300 );

		$this->assertFalse( $this->sut->claim( self::KEY, 'this_request', 300 ) );
		$this->assertSame( 'other_request', $this->read_row( '_transient_' . self::KEY ), 'A refused claim must leave the holder unchanged.' );
	}

	/**
	 * @testdox A claim should take over an expired lock.
	 */
	public function test_claim_takes_over_an_expired_lock(): void {
		$this->insert_rows( 'stale', time() - 10 );

		$this->assertTrue( $this->sut->claim( self::KEY, 'fresh', 300 ) );
		$this->assertSame( 'fresh', $this->read_row( '_transient_' . self::KEY ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_row( '_transient_timeout_' . self::KEY ) );
	}

	/**
	 * @testdox Only one of two overlapping takeovers of an expired lock should win.
	 */
	public function test_only_one_overlapping_takeover_wins(): void {
		$this->insert_rows( 'stale', time() - 10 );
		$rival_took_over = false;
		$rival           = function ( $query ) use ( &$rival, &$rival_took_over ) {
			if ( ! $rival_took_over && 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, '_transient_timeout_' . self::KEY ) ) {
				$rival_took_over = true;
				remove_filter( 'query', $rival );
				$this->update_row( '_transient_' . self::KEY, 'rival' );
				$this->update_row( '_transient_timeout_' . self::KEY, (string) ( time() + 300 ) );
			}
			return $query;
		};
		add_filter( 'query', $rival );

		$claimed = $this->sut->claim( self::KEY, 'this_request', 300 );
		remove_filter( 'query', $rival );

		$this->assertTrue( $rival_took_over, 'The rival takeover must have run.' );
		$this->assertFalse( $claimed );
		$this->assertSame( 'rival', $this->read_row( '_transient_' . self::KEY ) );
	}

	/**
	 * @testdox A former holder's release should keep the lock a later claim took over after it expired.
	 */
	public function test_release_by_a_former_holder_keeps_a_takeover(): void {
		$this->insert_rows( 'former', time() - 10 );
		$this->assertTrue( $this->sut->claim( self::KEY, 'successor', 300 ) );

		$this->sut->release( self::KEY, 'former' );

		$this->assertSame( 'successor', $this->sut->read( self::KEY ) );
		$this->assertNotNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
	}

	/**
	 * @testdox With a holder, a release should keep the lock once a takeover replaced the holder record.
	 */
	public function test_release_with_a_holder_keeps_a_takeover_that_reused_the_lock_value(): void {
		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, self::KEY . '_holder', 'former_holder' ) );
		$this->update_row( '_transient_timeout_' . self::KEY, (string) ( time() - 10 ) );
		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, self::KEY . '_holder', 'successor_holder' ) );

		$this->sut->release( self::KEY, 'same_value', self::KEY . '_holder', 'former_holder' );

		$this->assertSame( 'same_value', $this->sut->read( self::KEY ) );
		$this->assertSame( 'successor_holder', $this->read_row( '_transient_' . self::KEY . '_holder' ) );

		$this->sut->release( self::KEY, 'same_value', self::KEY . '_holder', 'successor_holder' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_' . self::KEY . '_holder' ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY . '_holder' ) );
	}

	/**
	 * @testdox Reading an expired lock should report it missing and leave its rows for the next claim.
	 */
	public function test_read_of_an_expired_lock_keeps_its_rows(): void {
		$this->insert_rows( 'stale', time() - 10 );

		$this->assertNull( $this->sut->read( self::KEY ) );
		$this->assertSame( 'stale', $this->read_row( '_transient_' . self::KEY ), 'get_transient() would have deleted the rows a takeover may be writing.' );
	}

	/**
	 * @testdox A release should retry once when its delete fails.
	 */
	public function test_release_retries_a_failed_delete_once(): void {
		$this->assertTrue( $this->sut->claim( self::KEY, 'holder', 300 ) );
		$failed = false;
		$fail   = function ( $query ) use ( &$fail, &$failed ) {
			if ( 0 === strpos( ltrim( $query ), 'DELETE lock_value' ) ) {
				$failed = true;
				remove_filter( 'query', $fail );
				return 'DELETE FROM a_table_that_does_not_exist';
			}
			return $query;
		};
		add_filter( 'query', $fail );
		$suppress = $GLOBALS['wpdb']->suppress_errors( true );

		$this->sut->release( self::KEY, 'holder' );
		$GLOBALS['wpdb']->suppress_errors( $suppress );
		remove_filter( 'query', $fail );

		$this->assertTrue( $failed, 'The first delete must have failed.' );
		$this->assertNull( $this->read_row( '_transient_' . self::KEY ) );
	}

	/**
	 * Store lock rows directly in the database, as a concurrent request would.
	 *
	 * @param string $value      Lock value.
	 * @param int    $expiration Lock expiry timestamp.
	 */
	private function insert_rows( string $value, int $expiration ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_transient_timeout_' . self::KEY,
				'option_value' => (string) $expiration,
				'autoload'     => 'off',
			)
		);
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => '_transient_' . self::KEY,
				'option_value' => $value,
				'autoload'     => 'off',
			)
		);
	}

	/**
	 * Overwrite a lock row directly in the database, as a concurrent request would.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 */
	private function update_row( string $name, string $value ): void {
		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
	}

	/**
	 * Read a lock row straight from the database.
	 *
	 * @param string $name Option name.
	 * @return string|null Stored value, or null when the row does not exist.
	 */
	private function read_row( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}
}
