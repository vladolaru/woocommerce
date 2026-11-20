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
		$this->assertNotNull( $this->read_row( '_transient_timeout_' . self::KEY ), 'A refused claim must keep the holder expiry.' );
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
		$holder_key = self::KEY . '_holder';
		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'former_holder' ) );
		$former = $this->sut->read_holder( $holder_key )['stored'] ?? '';
		$this->update_row( '_transient_timeout_' . self::KEY, (string) ( time() - 10 ) );
		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'successor_holder' ) );

		$this->sut->release( self::KEY, 'same_value', $holder_key, $former );

		$this->assertSame( 'same_value', $this->sut->read( self::KEY ) );
		$this->assertSame( 'successor_holder', $this->sut->read_holder( $holder_key )['record'] ?? null );

		$this->sut->release( self::KEY, 'same_value', $holder_key, $this->sut->read_holder( $holder_key )['stored'] ?? '' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_' . $holder_key ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . $holder_key ) );
	}

	/**
	 * @testdox A former holder's release, after the lock's own rows were deleted by name, should keep a later claim of the lock.
	 */
	public function test_release_by_a_former_holder_after_its_lock_rows_were_deleted_keeps_a_later_claim_of_the_lock(): void {
		$holder_key = self::KEY . '_holder';
		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'former_holder' ) );
		$former = (string) $this->read_row( '_transient_' . $holder_key );
		// An unconditional unlock or the expired-transient cleanup deletes the lock's rows by name and leaves the holder rows.
		$this->delete_row( '_transient_' . self::KEY );
		$this->delete_row( '_transient_timeout_' . self::KEY );

		// The former holder releases right after the later claim inserts its value row, before it writes its holder record.
		$released    = false;
		$seen_insert = false;
		$release     = function ( $query ) use ( &$release, &$released, &$seen_insert, $holder_key, $former ) {
			if ( ! $seen_insert ) {
				$seen_insert = 0 === strpos( ltrim( $query ), 'INSERT IGNORE' ) && false !== strpos( $query, "'_transient_" . self::KEY . "'" );
				return $query;
			}
			remove_filter( 'query', $release );
			$released = true;
			$this->sut->release( self::KEY, 'same_value', $holder_key, $former );
			return $query;
		};
		add_filter( 'query', $release );
		$claimed = $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'successor_holder' );
		remove_filter( 'query', $release );

		$this->assertTrue( $released, 'The former holder must have released.' );
		$this->assertTrue( $claimed );
		$this->assertSame( 'same_value', $this->read_row( '_transient_' . self::KEY ), 'The former holder must not delete the later claim\'s lock.' );
		$this->assertNotNull( $this->read_row( '_transient_timeout_' . self::KEY ), 'The later claim keeps its expiry.' );
		$this->assertSame( 'successor_holder', $this->sut->read_holder( $holder_key )['record'] ?? null, 'The holder record is the later claim\'s.' );
		$this->assertFalse( $this->sut->claim( self::KEY, 'third', 300, $holder_key, 'third_holder' ), 'A third claim must be refused.' );

		$this->sut->release( self::KEY, 'same_value', $holder_key, $this->sut->read_holder( $holder_key )['stored'] ?? '' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_' . $holder_key ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . $holder_key ) );
	}

	/**
	 * @testdox A takeover should be refused when the value row it read is deleted and made again before its write.
	 */
	public function test_takeover_of_a_value_row_remade_after_its_read_is_refused(): void {
		$holder_key = self::KEY . '_holder';
		$this->insert_rows( 'same_value', time() - 10 );

		// Right after the takeover reads the expired rows, the value row is deleted and inserted again with the same value.
		$remade    = false;
		$seen_read = false;
		$remake    = function ( $query ) use ( &$remake, &$remade, &$seen_read ) {
			if ( ! $seen_read ) {
				$seen_read = 0 === strpos( ltrim( $query ), 'SELECT option_name' ) && false !== strpos( $query, "'_transient_" . self::KEY . "'" );
				return $query;
			}
			remove_filter( 'query', $remake );
			$remade = true;
			$this->delete_row( '_transient_' . self::KEY );
			$GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->options,
				array(
					'option_name'  => '_transient_' . self::KEY,
					'option_value' => 'same_value',
					'autoload'     => 'off',
				)
			);
			return $query;
		};
		add_filter( 'query', $remake );
		$claimed = $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'taker_holder' );
		remove_filter( 'query', $remake );

		$this->assertTrue( $remade, 'The value row must have been made again.' );
		$this->assertFalse( $claimed, 'A takeover must not win a row it did not read.' );
		$this->assertNull( $this->read_row( '_transient_' . $holder_key ), 'A refused takeover writes no holder record.' );

		$this->assertTrue( $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'next_holder' ), 'The next claim takes the remade row over.' );
		$this->sut->release( self::KEY, 'same_value', $holder_key, $this->sut->read_holder( $holder_key )['stored'] ?? '' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ), 'The next claim can release the row it took over.' );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_' . $holder_key ) );
	}

	/**
	 * @testdox A fresh claim should be refused when its value row is deleted by name and another claim of the same value fills the gap before its expiry write.
	 */
	public function test_fresh_claim_whose_value_row_was_replaced_before_its_expiry_write_is_refused(): void {
		$holder_key = self::KEY . '_holder';

		// Right after the first claim inserts its value row, the row is deleted by name and a second claim of the same value runs whole.
		$replaced    = false;
		$second      = false;
		$seen_insert = false;
		$replace     = function ( $query ) use ( &$replace, &$replaced, &$second, &$seen_insert, $holder_key ) {
			if ( ! $seen_insert ) {
				$seen_insert = 0 === strpos( ltrim( $query ), 'INSERT IGNORE' ) && false !== strpos( $query, "'_transient_" . self::KEY . "'" );
				return $query;
			}
			remove_filter( 'query', $replace );
			$replaced = true;
			$this->delete_row( '_transient_' . self::KEY );
			$second = $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'second_holder' );
			return $query;
		};
		add_filter( 'query', $replace );
		$first = $this->sut->claim( self::KEY, 'same_value', 300, $holder_key, 'first_holder' );
		remove_filter( 'query', $replace );

		$this->assertTrue( $replaced, 'The value row must have been replaced.' );
		$this->assertTrue( $second, 'The second claim holds the lock.' );
		$this->assertFalse( $first, 'The first claim must not hold a row another claim inserted.' );
		$this->assertSame( 'second_holder', $this->sut->read_holder( $holder_key )['record'] ?? null, 'The holder record is the second claim\'s.' );

		$this->sut->release( self::KEY, 'same_value', $holder_key, $this->sut->read_holder( $holder_key )['stored'] ?? '' );

		$this->assertNull( $this->read_row( '_transient_' . self::KEY ), 'The second claim releases its lock.' );
		$this->assertNull( $this->read_row( '_transient_timeout_' . self::KEY ) );
		$this->assertNull( $this->read_row( '_transient_' . $holder_key ) );
		$this->assertNull( $this->read_row( '_transient_timeout_' . $holder_key ) );
	}

	/**
	 * @testdox The holder read should return the caller's record, the stored row and its expiry, expired or not, and nothing for a row it did not write.
	 */
	public function test_holder_read_returns_the_record_its_expiry_and_the_stored_row_expired_or_not(): void {
		$holder_key = self::KEY . '_holder';
		$this->assertTrue( $this->sut->claim( self::KEY, 'value', 300, $holder_key, 'caller_record' ) );

		$live = $this->sut->read_holder( $holder_key );
		$this->assertSame( 'caller_record', $live['record'] ?? null );
		$this->assertSame( $this->read_row( '_transient_' . $holder_key ), $live['stored'] ?? null );
		$this->assertIsInt( $live['expires'] ?? null );
		$this->assertGreaterThanOrEqual( time() + 290, $live['expires'] );

		$past = time() - 10;
		$this->update_row( '_transient_timeout_' . $holder_key, (string) $past );
		$expired = $this->sut->read_holder( $holder_key );
		$this->assertSame( 'caller_record', $expired['record'] ?? null, 'An expired holder row is read too.' );
		$this->assertSame( $this->read_row( '_transient_' . $holder_key ), $expired['stored'] ?? null );
		$this->assertSame( $past, $expired['expires'] ?? null );

		$this->update_row( '_transient_' . $holder_key, 'caller_record' );
		$this->assertNull( $this->sut->read_holder( $holder_key ), 'A holder row this class did not write reads as none.' );
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
	 * Delete a lock row by name, as an unconditional unlock or the expired-transient cleanup does.
	 *
	 * @param string $name Option name.
	 */
	private function delete_row( string $name ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
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
