<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentStore class.
 */
class OrderPaymentStoreTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var OrderPaymentStore
	 */
	private $sut;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $persistence_profile;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                 = wc_get_container()->get( OrderPaymentStore::class );
		$this->persistence_profile = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * @testdox Native money-operation claims should block any active order payment lock.
	 */
	public function test_claim_order_payment_lock_blocks_any_active_lock(): void {
		$order = wc_create_order();

		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_charge_key', 'payment operation' ) );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_refund_key', 'payment operation' ) );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'native_refund_key' ) );

		$this->clear_order_payment_lock( $order, $this->persistence_profile );

		$this->hold_order_payment_lock( $order, $this->persistence_profile, 'pi_legacy' );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_charge_key', 'payment operation' ) );

		$this->clear_order_payment_lock( $order, $this->persistence_profile );
		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'native_charge_key', 'payment operation' ) );
		$this->clear_order_payment_lock( $order, $this->persistence_profile );
	}

	/**
	 * @testdox A claim should refuse a lock another request stored after this request cached the lock as missing.
	 */
	public function test_claim_refuses_lock_written_by_an_overlapping_request(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		// This request reads the lock as missing, which WordPress records in its notoptions cache.
		$this->assertFalse( get_transient( $lock_key ) );
		// A second request then stores its lock directly in the database.
		$this->insert_lock_rows( $lock_key, 'other_request_key', time() + 300 );

		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' ), 'Only one of two overlapping requests may hold the order payment lock.' );
		$this->assertSame( 'other_request_key', $this->read_lock_row( '_transient_' . $lock_key ), 'A refused claim must leave the holder unchanged.' );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'A refused claim must keep the holder expiry.' );
	}

	/**
	 * @testdox A claim should take over an expired lock and keep the new lock for the full TTL.
	 */
	public function test_claim_takes_over_an_expired_lock(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'stale_key', time() - 10 );

		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'fresh_key', 'payment operation' ), 'An expired lock must not block a new claim.' );
		$this->assertSame( 'fresh_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The new lock must expire a full TTL from now.' );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_profile, 'fresh_key' ) );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'later_key', 'payment operation' ), 'The taken-over lock must block the next claim.' );
	}

	/**
	 * @testdox Only one of two overlapping takeovers of an expired lock should win.
	 */
	public function test_only_one_overlapping_takeover_of_an_expired_lock_wins(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'stale_key', time() - 10 );

		// Let a rival request take the expired lock over right before this request writes its takeover.
		$rival_took_over = false;
		$rival           = function ( $query ) use ( &$rival, &$rival_took_over, $lock_key ) {
			if ( ! $rival_took_over && 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, '_transient_timeout_' . $lock_key ) ) {
				$rival_took_over = true;
				remove_filter( 'query', $rival );
				$this->update_lock_row( '_transient_' . $lock_key, 'rival_key' );
				$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() + 300 ) );
			}
			return $query;
		};
		add_filter( 'query', $rival );

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $rival );

		$this->assertTrue( $rival_took_over, 'The rival takeover must have run.' );
		$this->assertNull( $claimed, 'A takeover must fail when another request took the expired lock first.' );
		$this->assertSame( 'rival_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox Releasing the lock should delete it only while the caller still holds it.
	 */
	public function test_release_deletes_the_lock_only_for_its_holder(): void {
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		$first_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'first_key', 'capture' );
		$this->assertNotNull( $first_token );
		// Something removed the first operation's lock, and another operation claimed the order.
		$this->clear_order_payment_lock( $order, $this->persistence_profile );
		$second_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'second_key', 'refund' );
		$this->assertNotNull( $second_token );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $first_token );

		$this->assertSame( 'second_key', get_transient( $lock_key ), 'A former holder must not release the current holder lock.' );
		$this->assertSame( 'refund', get_transient( $lock_key . '_holder' )['operation'] ?? null, 'A former holder must not delete the current holder record.' );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $second_token );

		$this->assertFalse( get_transient( $lock_key ), 'The holder must be able to release its lock.' );
		$this->assertFalse( get_transient( $lock_key . '_holder' ), 'Releasing the lock must delete its holder record.' );
		$sentinel_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, null, 'payment operation' );
		$this->assertNotNull( $sentinel_token, 'A released lock must be claimable again.' );
		$this->assertSame( '-1', get_transient( $lock_key ), 'A claim without a reference must store the WooPayments sentinel.' );
		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $sentinel_token );
		$this->assertFalse( get_transient( $lock_key ), 'A sentinel claim must be released with its token.' );
	}

	/**
	 * @testdox A former holder should not release a lock that a later claim with the same reference took over after it expired.
	 */
	public function test_release_by_a_former_holder_keeps_a_same_reference_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		// A capture claims the lock with its derived key and then runs past the lock TTL.
		$first_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
		// A second capture of the same amount derives the same key and takes the expired lock over.
		$second_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
		$this->assertNotNull( $second_token, 'An expired lock must be taken over.' );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $first_token );

		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'The lock must keep the WooPayments-compatible payment reference.' );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The taken-over lock must keep its expiry.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'refund_key', 'refund' ), 'A third operation must not start while the second holds the lock.' );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $second_token );

		$this->assertNull( $this->read_lock_row( '_transient_' . $lock_key ), 'The current holder must still release its lock.' );
		$this->assertNull( $this->read_lock_row( '_transient_' . $lock_key . '_holder' ), 'Releasing the lock must delete its holder record.' );
	}

	/**
	 * @testdox A former holder releasing between a takeover and the taker's holder record write should keep the takeover.
	 */
	public function test_release_by_a_former_holder_during_a_takeover_keeps_the_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		$first_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );

		// The former holder finishes right after the takeover UPDATE, before the taker writes its holder record.
		$released     = false;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, "'_transient_timeout_{$lock_key}'" ),
			function () use ( &$released, $order, $first_token ): void {
				$released = true;
				$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $first_token );
			}
		);
		$second_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $released, 'The former holder release must have run.' );
		$this->assertNotNull( $second_token, 'The takeover must hold the lock.' );
		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'A former holder must not release a lock taken over from it.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'refund_key', 'refund' ), 'A third operation must not start while the taker holds the lock.' );
	}

	/**
	 * @testdox A former holder whose lock is taken over between its holder check and its delete should keep the takeover.
	 */
	public function test_release_keeps_a_takeover_made_after_its_holder_check(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		$first_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );

		// Right after the former holder reads its holder record, a second capture takes the expired lock over.
		$second_token = null;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => 0 === strpos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, "'_transient_{$lock_key}_holder'" ),
			function () use ( &$second_token, $order ): void {
				$second_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
			}
		);
		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $first_token );
		remove_filter( 'query', $filter );

		$this->assertNotNull( $second_token, 'The takeover must have run and won.' );
		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'A former holder must not release a lock taken over from it.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'refund_key', 'refund' ), 'A third operation must not start while the taker holds the lock.' );
	}

	/**
	 * @testdox With transients in the object cache, a former holder should not release a lock a same-reference claim made after it expired.
	 */
	public function test_object_cache_release_by_a_former_holder_keeps_a_same_reference_claim(): void {
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$was_ext  = wp_using_ext_object_cache( true );

		try {
			$first_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
			$this->assertNotNull( $first_token );
			// The cache drops the expired lock and a second capture with the same derived key claims it.
			wp_cache_delete( $lock_key, 'transient' );
			$second_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'capture_key', 'capture' );
			$this->assertNotNull( $second_token );

			$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $first_token );

			$this->assertSame( 'capture_key', wp_cache_get( $lock_key, 'transient' ), 'A former holder must not release the current holder lock.' );
			$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'refund_key', 'refund' ), 'A third operation must not start while the second holds the lock.' );

			$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $second_token );

			$this->assertFalse( wp_cache_get( $lock_key, 'transient' ), 'The current holder must still release its lock.' );
		} finally {
			wp_cache_delete( $lock_key, 'transient' );
			wp_cache_delete( $lock_key . '_holder', 'transient' );
			wp_using_ext_object_cache( (bool) $was_ext );
		}
	}

	/**
	 * @testdox A claim should win when only an unexpired expiry row is left behind, and replace that expiry.
	 */
	public function test_claim_wins_over_a_leftover_expiry_row_without_a_value_row(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		// A release that stopped between deleting the value row and the expiry row leaves the expiry alone.
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() + 100 ) );

		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' ), 'A leftover expiry row without a value row must not block a claim.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The claim must replace the leftover expiry with a full TTL.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'later_key', 'payment operation' ), 'The claimed lock must block the next claim.' );
	}

	/**
	 * @testdox A claim should lose when a takeover replaces its value row before it replaces an expired leftover expiry.
	 */
	public function test_claim_loses_to_a_takeover_before_it_replaces_an_expired_leftover_expiry(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 10 ) );

		// Right after this request inserts its value row, a rival sees it next to the expired expiry and takes over.
		$value_inserted = false;
		$took_over      = false;
		$rival          = function ( $query ) use ( &$rival, &$value_inserted, &$took_over, $lock_key ) {
			if ( ! $value_inserted ) {
				$value_inserted = 0 === strpos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, "'_transient_{$lock_key}'" );
				return $query;
			}
			$took_over = true;
			remove_filter( 'query', $rival );
			$this->update_lock_row( '_transient_' . $lock_key, 'rival_key' );
			$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() + 299 ) );
			return $query;
		};
		add_filter( 'query', $rival );

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $rival );

		$this->assertTrue( $took_over, 'The rival takeover must have run.' );
		$this->assertNull( $claimed, 'Only the rival may hold the lock after taking it over.' );
		$this->assertSame( 'rival_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox A claim should win, with an expiry, when the holder releases the lock between the claim's expiry read and its value insert.
	 */
	public function test_claim_wins_when_the_holder_releases_between_its_expiry_read_and_value_insert(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order        = wc_create_order();
		$lock_key     = $this->persistence_profile->get_order_lock_key( $order );
		$holder_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'holder_key', 'payment operation' );
		$this->assertNotNull( $holder_token );

		$released = false;
		$filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			function () use ( &$released, $order, $holder_token ): void {
				$released = true;
				$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $holder_token );
			}
		);

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $released, 'The holder release must have run.' );
		$this->assertNotNull( $claimed, 'A claim must win a lock the holder released before the claim inserted its value.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The winning claim must leave an expiry row.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'later_key', 'payment operation' ), 'The claimed lock must block the next claim.' );
	}

	/**
	 * @testdox A claim should keep a lock whose expiry a refused rival filled in after the holder released the lock.
	 */
	public function test_claim_keeps_the_lock_when_a_refused_rival_fills_in_its_expiry(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order        = wc_create_order();
		$lock_key     = $this->persistence_profile->get_order_lock_key( $order );
		$holder_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'holder_key', 'payment operation' );
		$this->assertNotNull( $holder_token );

		// The holder releases right after the claim reads the expiry; a rival claims right after the claim inserts its value.
		$release_filter = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			fn() => $this->sut->release_order_payment_lock( $order, $this->persistence_profile, $holder_token )
		);
		$rival_claimed  = 'not run';
		$rival_filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_value_insert( $query, $lock_key ),
			function () use ( &$rival_claimed, $order ): void {
				$rival_claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'rival_key', 'payment operation' );
			}
		);

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $release_filter );
		remove_filter( 'query', $rival_filter );

		$this->assertNull( $rival_claimed, 'The rival claim must have run and been refused.' );
		$this->assertNotNull( $claimed, 'The claim that inserted the value row must keep the lock.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The lock must keep an expiry row.' );
	}

	/**
	 * @testdox A claim should win over a leftover expiry row equal to the expiry it writes.
	 */
	public function test_claim_wins_over_a_leftover_expiry_equal_to_its_own(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order      = wc_create_order();
		$lock_key   = $this->persistence_profile->get_order_lock_key( $order );
		$expiration = (string) ( time() + $this->persistence_profile->get_lock_ttl_seconds() );
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, $expiration );

		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' ) );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( (int) $expiration, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ) );
	}

	/**
	 * @testdox A claim should write an expiry row when the holder, whose expiry equals the claim's, releases between the claim's expiry read and value insert.
	 */
	public function test_claim_writes_an_expiry_when_a_holder_with_the_same_expiry_releases(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order        = wc_create_order();
		$lock_key     = $this->persistence_profile->get_order_lock_key( $order );
		$holder_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'holder_key', 'payment operation' );
		$this->assertNotNull( $holder_token );

		$filter = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			fn() => $this->sut->release_order_payment_lock( $order, $this->persistence_profile, $holder_token )
		);

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $filter );

		$this->assertNotNull( $claimed );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'A held lock must never be left without an expiry row.' );
	}

	/**
	 * @testdox A claim should lose when a takeover with the same lock value replaces an expired leftover expiry after the claim inserts its value.
	 */
	public function test_claim_loses_to_a_same_value_takeover_of_an_expired_leftover_expiry(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 10 ) );

		// A second request for the same payment reference sees the value row next to the expired expiry and takes over.
		$rival_expiry = (string) ( time() + 299 );
		$took_over    = false;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_value_insert( $query, $lock_key ),
			function () use ( &$took_over, $lock_key, $rival_expiry ): void {
				$took_over = true;
				$this->update_lock_row( '_transient_timeout_' . $lock_key, $rival_expiry );
			}
		);

		$claimed = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'pi_shared', 'payment operation' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $took_over, 'The rival takeover must have run.' );
		$this->assertNull( $claimed, 'Two requests for the same payment reference must not both hold the lock.' );
		$this->assertSame( $rival_expiry, $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must leave the taker expiry.' );
	}

	/**
	 * @testdox Releasing the lock should retry once when its delete fails.
	 */
	public function test_release_retries_a_failed_delete_once(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order      = wc_create_order();
		$lock_key   = $this->persistence_profile->get_order_lock_key( $order );
		$lock_token = $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' );
		$this->assertNotNull( $lock_token );

		// Fail the first delete, as a deadlock victim would.
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
		$suppressed = $GLOBALS['wpdb']->suppress_errors( true );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, $lock_token );
		$GLOBALS['wpdb']->suppress_errors( $suppressed );
		remove_filter( 'query', $fail );

		$this->assertTrue( $failed, 'The first delete must have failed.' );
		$this->assertNull( $this->read_lock_row( '_transient_' . $lock_key ), 'The retried delete must release the lock.' );
		$this->assertNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ) );
	}

	/**
	 * @testdox A claim should refuse a leftover value row without an expiry row and give that row an expiry.
	 */
	public function test_claim_refuses_a_leftover_value_row_without_an_expiry_row(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		// A claim that stopped between writing its value row and its expiry row leaves the value alone.
		$this->insert_lock_row( '_transient_' . $lock_key, 'orphan_key' );

		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' ), 'A value row without an expiry must be treated as held.' );
		$this->assertSame( 'orphan_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must give the leftover value row an expiry.' );

		// Once that expiry passes, the leftover lock can be taken over.
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
		$this->assertNotNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'this_request_key', 'payment operation' ) );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox Reading an expired lock for a refusal log should not delete the rows of a takeover that ran meanwhile.
	 */
	public function test_refusal_log_read_of_an_expired_lock_keeps_a_concurrent_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'stale_key', time() - 10 );

		// Right after this request reads the expired expiry row, another request takes the lock over.
		$timeout_read = false;
		$took_over    = false;
		$takeover     = function ( $query ) use ( &$takeover, &$timeout_read, &$took_over, $lock_key ) {
			if ( ! $timeout_read ) {
				$timeout_read = 0 === strpos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, "'_transient_timeout_{$lock_key}'" );
				return $query;
			}
			$took_over = true;
			remove_filter( 'query', $takeover );
			$this->update_lock_row( '_transient_' . $lock_key, 'taker_key' );
			$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() + 300 ) );
			return $query;
		};
		add_filter( 'query', $takeover );

		$this->sut->log_order_payment_lock_refusal( $order, $this->persistence_profile, 'refund' );
		remove_filter( 'query', $takeover );

		$this->assertTrue( $took_over, 'The concurrent takeover must have run.' );
		$this->assertSame( 'taker_key', $this->read_lock_row( '_transient_' . $lock_key ), 'Reading an expired lock must not delete the new holder value.' );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'Reading an expired lock must not delete the new holder expiry.' );
		$this->assertNull( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'third_key', 'payment operation' ), 'A third claimant must not win while the taker holds the lock.' );
	}

	/**
	 * @testdox A lock refusal's log line never throws, even when the log filter throws $thrown.
	 *
	 * The logger is WooCommerce's own, so the line runs the woocommerce_logger_log_message filter (WC_Logger::log()). Every
	 * caller logs the refusal and then refuses its operation; a throw here would replace that refusal.
	 *
	 * @testWith ["RuntimeException"]
	 *           ["Error"]
	 *
	 * @param string $thrown Class the filter throws.
	 */
	public function test_lock_refusal_log_survives_a_throwing_log_filter( string $thrown ): void {
		$order  = wc_create_order();
		$throws = 0;
		add_filter(
			'woocommerce_logger_log_message',
			static function () use ( $thrown, &$throws ) {
				++$throws;
				throw new $thrown( 'Log write failed.' );
			}
		);

		$this->sut->log_order_payment_lock_refusal( $order, $this->persistence_profile, 'refund' );

		$this->assertSame( 1, $throws, 'The refusal line was written once.' );
	}

	/**
	 * Skip a test that drives the database lock path when transients live in a persistent object cache.
	 */
	private function skip_when_transients_live_in_object_cache(): void {
		if ( wp_using_ext_object_cache() ) {
			$this->markTestSkipped( 'The database lock path only runs without a persistent object cache.' );
		}
	}

	/**
	 * Run an action once, as another request would, right before the query that follows the first matching query.
	 *
	 * @param callable $matches Receives each query and returns true for the query to run the action after.
	 * @param callable $action  Action standing in for the concurrent request.
	 * @return callable The 'query' filter, to remove once the code under test has run.
	 */
	private function run_before_query_after( callable $matches, callable $action ): callable {
		$matched = false;
		$filter  = function ( $query ) use ( &$filter, &$matched, $matches, $action ) {
			if ( ! $matched ) {
				$matched = $matches( $query );
				return $query;
			}
			remove_filter( 'query', $filter );
			$action();
			return $query;
		};
		add_filter( 'query', $filter );

		return $filter;
	}

	/**
	 * Tell whether a query is a claim's read of the lock expiry row.
	 *
	 * @param string $query    SQL query.
	 * @param string $lock_key Lock transient key.
	 * @return bool
	 */
	private function is_expiry_read( string $query, string $lock_key ): bool {
		return 0 === strpos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, "'_transient_timeout_{$lock_key}'" );
	}

	/**
	 * Tell whether a query is a claim's insert of the lock value row.
	 *
	 * @param string $query    SQL query.
	 * @param string $lock_key Lock transient key.
	 * @return bool
	 */
	private function is_value_insert( string $query, string $lock_key ): bool {
		return 0 === strpos( ltrim( $query ), 'INSERT' ) && false !== strpos( $query, "'_transient_{$lock_key}'" );
	}

	/**
	 * Store lock rows directly in the database, as a concurrent request would.
	 *
	 * @param string $lock_key   Lock transient key.
	 * @param string $value      Lock value.
	 * @param int    $expiration Lock expiry timestamp.
	 */
	private function insert_lock_rows( string $lock_key, string $value, int $expiration ): void {
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) $expiration );
		$this->insert_lock_row( '_transient_' . $lock_key, $value );
	}

	/**
	 * Store one lock row directly in the database, as a concurrent or interrupted request would.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 */
	private function insert_lock_row( string $name, string $value ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
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
	private function update_lock_row( string $name, string $value ): void {
		global $wpdb;

		$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => $name ) );
	}

	/**
	 * Read a lock row straight from the database.
	 *
	 * @param string $name Option name.
	 * @return string|null Stored value, or null when the row does not exist.
	 */
	private function read_lock_row( string $name ): ?string {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	}
}
