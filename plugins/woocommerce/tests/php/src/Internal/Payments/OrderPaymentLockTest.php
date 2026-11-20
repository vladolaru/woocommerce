<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentLock class.
 */
class OrderPaymentLockTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * The System Under Test.
	 *
	 * @var OrderPaymentLock
	 */
	private $sut;

	/**
	 * WooPayments persistence vocabulary.
	 *
	 * @var WooPaymentsPersistenceVocabulary
	 */
	private WooPaymentsPersistenceVocabulary $persistence_vocabulary;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                    = wc_get_container()->get( OrderPaymentLock::class );
		$this->persistence_vocabulary = new WooPaymentsPersistenceVocabulary();
	}

	/**
	 * @testdox Money-operation claims should be refused while any order payment lock is active.
	 */
	public function test_claim_order_payment_lock_blocks_any_active_lock(): void {
		$order = wc_create_order();

		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'native_charge_key', 'payment operation' ) );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'native_refund_key', 'payment operation' ) );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, 'native_charge_key' ) );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, 'native_refund_key' ) );

		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );

		$this->hold_order_payment_lock( $order, $this->persistence_vocabulary, 'pi_legacy' );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, 'native_charge_key' ) );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'native_charge_key', 'payment operation' ) );

		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'native_charge_key', 'payment operation' ) );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox A claim should take over an expired lock and keep the new lock for the full TTL.
	 */
	public function test_claim_takes_over_an_expired_lock(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'stale_key', time() - 10 );

		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'fresh_key', 'payment operation' ), 'An expired lock must not block a new claim.' );
		$this->assertSame( 'fresh_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The new lock must expire a full TTL from now.' );
		$this->assertTrue( $this->is_order_payment_lock_held_for( $order, $this->persistence_vocabulary, 'fresh_key' ) );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'later_key', 'payment operation' ), 'The taken-over lock must block the next claim.' );
	}

	/**
	 * @testdox Only one of two overlapping takeovers of an expired lock should win.
	 */
	public function test_only_one_overlapping_takeover_of_an_expired_lock_wins(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
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

		$claimed = $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' );
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
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );

		$first_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'first_key', 'capture' );
		$this->assertNotNull( $first_token );
		// Something removed the first operation's lock, and another operation claimed the order.
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
		$second_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'second_key', 'refund' );
		$this->assertNotNull( $second_token );

		$this->sut->release( $order, $this->persistence_vocabulary, $first_token );

		$this->assertSame( 'second_key', get_transient( $lock_key ), 'A former holder must not release the current holder lock.' );
		$this->assertSame( 'refund', get_transient( $lock_key . '_holder' )['operation'] ?? null, 'A former holder must not delete the current holder record.' );

		$this->sut->release( $order, $this->persistence_vocabulary, $second_token );

		$this->assertFalse( get_transient( $lock_key ), 'The holder must be able to release its lock.' );
		$this->assertFalse( get_transient( $lock_key . '_holder' ), 'Releasing the lock must delete its holder record.' );
		$sentinel_token = $this->sut->claim( $order, $this->persistence_vocabulary, null, 'payment operation' );
		$this->assertNotNull( $sentinel_token, 'A released lock must be claimable again.' );
		$this->assertSame( '-1', get_transient( $lock_key ), 'A claim without a reference must store the WooPayments sentinel.' );
		$this->sut->release( $order, $this->persistence_vocabulary, $sentinel_token );
		$this->assertFalse( get_transient( $lock_key ), 'A sentinel claim must be released with its token.' );
	}

	/**
	 * @testdox A former holder should not release a lock that a later claim with the same reference took over after it expired.
	 */
	public function test_release_by_a_former_holder_keeps_a_same_reference_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );

		// A capture claims the lock and then runs past the lock TTL.
		$first_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
		// A second claim with the same lock value takes the expired lock over.
		$second_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
		$this->assertNotNull( $second_token, 'An expired lock must be taken over.' );

		$this->sut->release( $order, $this->persistence_vocabulary, $first_token );

		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'The lock must keep the current holder\'s lock value.' );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The taken-over lock must keep its expiry.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'refund_key', 'refund' ), 'A third operation must not start while the second holds the lock.' );

		$this->sut->release( $order, $this->persistence_vocabulary, $second_token );

		$this->assertNull( $this->read_lock_row( '_transient_' . $lock_key ), 'The current holder must still release its lock.' );
		$this->assertNull( $this->read_lock_row( '_transient_' . $lock_key . '_holder' ), 'Releasing the lock must delete its holder record.' );
	}

	/**
	 * @testdox A former holder releasing between a takeover and the taker's holder record write should keep the takeover.
	 */
	public function test_release_by_a_former_holder_during_a_takeover_keeps_the_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );

		$first_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );

		// The former holder finishes right after the takeover UPDATE, before the taker writes its holder record.
		$released     = false;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, "'_transient_timeout_{$lock_key}'" ),
			function () use ( &$released, $order, $first_token ): void {
				$released = true;
				$this->sut->release( $order, $this->persistence_vocabulary, $first_token );
			}
		);
		$second_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $released, 'The former holder release must have run.' );
		$this->assertNotNull( $second_token, 'The takeover must hold the lock.' );
		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'A former holder must not release a lock taken over from it.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'refund_key', 'refund' ), 'A third operation must not start while the taker holds the lock.' );
	}

	/**
	 * @testdox A former holder whose lock is taken over between its holder check and its delete should keep the takeover.
	 */
	public function test_release_keeps_a_takeover_made_after_its_holder_check(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );

		$first_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
		$this->assertNotNull( $first_token );
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );

		// Right after the former holder reads its holder record, a second capture takes the expired lock over.
		$second_token = null;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => 0 === strpos( ltrim( $query ), 'SELECT' ) && false !== strpos( $query, "'_transient_{$lock_key}_holder'" ),
			function () use ( &$second_token, $order ): void {
				$second_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
			}
		);
		$this->sut->release( $order, $this->persistence_vocabulary, $first_token );
		remove_filter( 'query', $filter );

		$this->assertNotNull( $second_token, 'The takeover must have run and won.' );
		$this->assertSame( 'capture_key', $this->read_lock_row( '_transient_' . $lock_key ), 'A former holder must not release a lock taken over from it.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'refund_key', 'refund' ), 'A third operation must not start while the taker holds the lock.' );
	}

	/**
	 * @testdox With transients in the object cache, a former holder should not release a lock a same-reference claim made after it expired.
	 */
	public function test_object_cache_release_by_a_former_holder_keeps_a_same_reference_claim(): void {
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$was_ext  = wp_using_ext_object_cache( true );

		try {
			$first_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
			$this->assertNotNull( $first_token );
			// The cache drops the expired lock and a second claim with the same lock value (for example, a second lifecycle
			// event for the same payment reference) claims it.
			wp_cache_delete( $lock_key, 'transient' );
			$second_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'capture_key', 'capture' );
			$this->assertNotNull( $second_token );

			$this->sut->release( $order, $this->persistence_vocabulary, $first_token );

			$this->assertSame( 'capture_key', wp_cache_get( $lock_key, 'transient' ), 'A former holder must not release the current holder lock.' );
			$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'refund_key', 'refund' ), 'A third operation must not start while the second holds the lock.' );

			$this->sut->release( $order, $this->persistence_vocabulary, $second_token );

			$this->assertFalse( wp_cache_get( $lock_key, 'transient' ), 'The current holder must still release its lock.' );
		} finally {
			wp_cache_delete( $lock_key, 'transient' );
			wp_cache_delete( $lock_key . '_holder', 'transient' );
			wp_using_ext_object_cache( (bool) $was_ext );
		}
	}

	/**
	 * @testdox A claim should win when only an unexpired expiry row is left behind, and replace that expiry ($_dataName).
	 *
	 * @dataProvider leftover_expiry_cases
	 *
	 * @param bool $equal_to_own Whether the leftover expiry equals the one the claim writes, so the claim's update writes no row.
	 */
	public function test_claim_wins_over_a_leftover_expiry_row_without_a_value_row( bool $equal_to_own ): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		// A release that stopped between deleting the value row and the expiry row leaves the expiry alone.
		$leftover = $equal_to_own ? time() + $this->persistence_vocabulary->get_lock_ttl_seconds() : time() + 100;
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) $leftover );

		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' ), 'A leftover expiry row without a value row must not block a claim.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The claim must replace the leftover expiry with a full TTL.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'later_key', 'payment operation' ), 'The claimed lock must block the next claim.' );
	}

	/**
	 * Leftover expiry rows a claim must win over.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function leftover_expiry_cases(): array {
		return array(
			'shorter than the claim TTL'   => array( false ),
			'equal to the claim\'s expiry' => array( true ),
		);
	}

	/**
	 * @testdox A claim should lose when a takeover of an expired leftover expiry lands right after the claim inserts its value ($_dataName).
	 *
	 * @testWith ["rival_key", "this_request_key"]
	 *           ["pi_shared", "pi_shared"]
	 *
	 * @param string $rival_value Lock value the rival holds after its takeover.
	 * @param string $claim_value Lock value this claim inserts.
	 */
	public function test_claim_loses_to_a_takeover_before_it_replaces_an_expired_leftover_expiry( string $rival_value, string $claim_value ): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 10 ) );

		// Right after this request inserts its value row, a rival sees it next to the expired expiry and takes over.
		// A rival for the same payment reference holds the same value, so only its expiry tells the claims apart.
		$rival_expiry = (string) ( time() + 299 );
		$took_over    = false;
		$filter       = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_value_insert( $query, $lock_key ),
			function () use ( &$took_over, $lock_key, $rival_value, $claim_value, $rival_expiry ): void {
				$took_over = true;
				if ( $rival_value !== $claim_value ) {
					$this->update_lock_row( '_transient_' . $lock_key, $rival_value );
				}
				$this->update_lock_row( '_transient_timeout_' . $lock_key, $rival_expiry );
			}
		);

		$claimed = $this->sut->claim( $order, $this->persistence_vocabulary, $claim_value, 'payment operation' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $took_over, 'The rival takeover must have run.' );
		$this->assertNull( $claimed, 'Only the rival may hold the lock after taking it over.' );
		$this->assertSame( $rival_value, $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertSame( $rival_expiry, $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must leave the rival expiry.' );
	}

	/**
	 * @testdox A claim should win, with an expiry, when the holder releases the lock between the claim's expiry read and its value insert.
	 */
	public function test_claim_wins_when_the_holder_releases_between_its_expiry_read_and_value_insert(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order        = wc_create_order();
		$lock_key     = $this->persistence_vocabulary->get_order_lock_key( $order );
		$holder_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'holder_key', 'payment operation' );
		$this->assertNotNull( $holder_token );

		$released = false;
		$filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			function () use ( &$released, $order, $holder_token ): void {
				$released = true;
				$this->sut->release( $order, $this->persistence_vocabulary, $holder_token );
			}
		);

		$claimed = $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $released, 'The holder release must have run.' );
		$this->assertNotNull( $claimed, 'A claim must win a lock the holder released before the claim inserted its value.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The winning claim must leave an expiry row.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'later_key', 'payment operation' ), 'The claimed lock must block the next claim.' );
	}

	/**
	 * @testdox A claim should keep a lock whose expiry a refused rival filled in after the holder released the lock.
	 */
	public function test_claim_keeps_the_lock_when_a_refused_rival_fills_in_its_expiry(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order        = wc_create_order();
		$lock_key     = $this->persistence_vocabulary->get_order_lock_key( $order );
		$holder_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'holder_key', 'payment operation' );
		$this->assertNotNull( $holder_token );

		// The holder releases right after the claim reads the expiry; a rival claims right after the claim inserts its value.
		$release_filter = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			fn() => $this->sut->release( $order, $this->persistence_vocabulary, $holder_token )
		);
		$rival_claimed  = 'not run';
		$rival_filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_value_insert( $query, $lock_key ),
			function () use ( &$rival_claimed, $order ): void {
				$rival_claimed = $this->sut->claim( $order, $this->persistence_vocabulary, 'rival_key', 'payment operation' );
			}
		);

		$claimed = $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' );
		remove_filter( 'query', $release_filter );
		remove_filter( 'query', $rival_filter );

		$this->assertNull( $rival_claimed, 'The rival claim must have run and been refused.' );
		$this->assertNotNull( $claimed, 'The claim that inserted the value row must keep the lock.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The lock must keep an expiry row.' );
	}

	/**
	 * @testdox A claim should refuse a leftover value row without an expiry row and give that row an expiry.
	 */
	public function test_claim_refuses_a_leftover_value_row_without_an_expiry_row(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		// A claim that stopped between writing its value row and its expiry row leaves the value alone.
		$this->insert_lock_row( '_transient_' . $lock_key, 'orphan_key' );

		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' ), 'A value row without an expiry must be treated as held.' );
		$this->assertSame( 'orphan_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must give the leftover value row an expiry.' );

		// Once that expiry passes, the leftover lock can be taken over.
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'this_request_key', 'payment operation' ) );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox Reading an expired lock for a refusal log should not delete the rows of a takeover that ran meanwhile.
	 */
	public function test_refusal_log_read_of_an_expired_lock_keeps_a_concurrent_takeover(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
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

		$this->sut->log_refusal( $order, $this->persistence_vocabulary, 'refund' );
		remove_filter( 'query', $takeover );

		$this->assertTrue( $took_over, 'The concurrent takeover must have run.' );
		$this->assertSame( 'taker_key', $this->read_lock_row( '_transient_' . $lock_key ), 'Reading an expired lock must not delete the new holder value.' );
		$this->assertNotNull( $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'Reading an expired lock must not delete the new holder expiry.' );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'third_key', 'payment operation' ), 'A third claimant must not win while the taker holds the lock.' );
	}

	/**
	 * @testdox A claim that takes over an expired lock logs one warning naming the former holder's operation and age.
	 */
	public function test_takeover_of_an_expired_lock_logs_the_former_holder(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'pi_dead', time() - 12 );
		$this->insert_holder_row( $lock_key, 'checkout', 'pi_dead', time() - 312 );
		$logger = RecordingWcLogger::install();

		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'payment status update' ) );

		$lines = $this->get_takeover_lines( $logger );
		$this->assertCount( 1, $lines, 'One takeover line is logged.' );
		$age = $lines[0]['context']['former_age_seconds'];
		$this->assertContains( $age, array( 312, 313 ), 'The age counts from the former claim, not from its expiry.' );
		$this->assertSame( 'warning', $lines[0]['level'] );
		$this->assertSame( sprintf( 'order payment lock taken over: order %d, by payment status update, from checkout claimed %ds ago', $order->get_id(), $age ), $lines[0]['message'] );
		$this->assertSame(
			array(
				'source'             => 'order-payments',
				'order_id'           => $order->get_id(),
				'operation'          => 'payment status update',
				'former_operation'   => 'checkout',
				'former_age_seconds' => $age,
				'former_lock_value'  => 'pi_dead',
			),
			$lines[0]['context']
		);
	}

	/**
	 * @testdox A claim that takes over an expired lock whose holder record names another lock value logs the former holder as unknown.
	 */
	public function test_takeover_with_a_holder_record_of_another_lock_logs_an_unknown_holder(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'pi_dead', time() - 12 );
		$this->insert_holder_row( $lock_key, 'checkout', 'pi_other', time() - 312 );
		$logger = RecordingWcLogger::install();

		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'payment status update' ) );

		$lines = $this->get_takeover_lines( $logger );
		$this->assertCount( 1, $lines );
		$this->assertSame( sprintf( 'order payment lock taken over: order %d, by payment status update, from an unknown operation claimed an unknown time ago', $order->get_id() ), $lines[0]['message'] );
		$this->assertNull( $lines[0]['context']['former_operation'] );
		$this->assertNull( $lines[0]['context']['former_age_seconds'] );
		$this->assertSame( 'pi_dead', $lines[0]['context']['former_lock_value'] );
	}

	/**
	 * @testdox A claim that takes no lock over logs no takeover line: a fresh claim, a claim after a release, a refused claim.
	 */
	public function test_claim_that_takes_no_lock_over_logs_no_takeover(): void {
		$order  = wc_create_order();
		$logger = RecordingWcLogger::install();

		$token = $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_first', 'checkout' );
		$this->assertNotNull( $token );
		$this->assertNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_second', 'refund' ) );
		$this->sut->log_refusal( $order, $this->persistence_vocabulary, 'refund' );
		$this->sut->release( $order, $this->persistence_vocabulary, $token );
		$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_third', 'capture' ) );

		$this->assertSame( array(), $this->get_takeover_lines( $logger ) );
		$this->assertCount( 1, $logger->lines, 'The refusal line is the only line.' );
		$this->assertStringStartsWith( 'order payment lock refused: ', $logger->lines[0][1] );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox Of two overlapping takeovers of an expired lock, only the winner logs a takeover line.
	 */
	public function test_only_the_winning_takeover_logs_a_line(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'pi_dead', time() - 12 );
		$this->insert_holder_row( $lock_key, 'checkout', 'pi_dead', time() - 312 );
		$logger = RecordingWcLogger::install();

		// A rival request takes the expired lock over right before this request writes its own takeover.
		$rival_token = 'not run';
		$rival       = function ( $query ) use ( &$rival, &$rival_token, $order, $lock_key ) {
			if ( 0 === strpos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, '_transient_timeout_' . $lock_key ) ) {
				remove_filter( 'query', $rival );
				$rival_token = $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'refund' );
			}
			return $query;
		};
		add_filter( 'query', $rival );
		$claimed = $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'payment status update' );
		remove_filter( 'query', $rival );

		$this->assertNotNull( $rival_token, 'The rival takeover must have run and won.' );
		$this->assertNull( $claimed, 'This takeover must lose.' );
		$lines = $this->get_takeover_lines( $logger );
		$this->assertCount( 1, $lines, 'Only the winning takeover logs.' );
		$this->assertSame( 'refund', $lines[0]['context']['operation'] );
		$this->assertSame( 'checkout', $lines[0]['context']['former_operation'] );
	}

	/**
	 * @testdox With transients in the object cache, a claim made after the cache dropped an expired lock logs the former holder, whose record is kept for a day.
	 */
	public function test_object_cache_claim_after_the_lock_expired_logs_the_former_holder(): void {
		global $wp_object_cache;

		$order     = wc_create_order();
		$lock_key  = $this->persistence_vocabulary->get_order_lock_key( $order );
		$was_ext   = wp_using_ext_object_cache( true );
		$was_cache = $wp_object_cache;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Records the expiry of each write; restored below.
		$wp_object_cache = new class() extends \WP_Object_Cache {
			/**
			 * Expiry of each write, by key.
			 *
			 * @var array<string,int>
			 */
			public array $expiries = array();

			/**
			 * Record the expiry, then store.
			 *
			 * @param int|string $key    Key.
			 * @param mixed      $data   Data.
			 * @param string     $group  Group.
			 * @param int        $expire Expiry.
			 * @return bool
			 */
			public function set( $key, $data, $group = 'default', $expire = 0 ) {
				$this->expiries[ (string) $key ] = (int) $expire;
				return parent::set( $key, $data, $group, $expire );
			}
		};

		$logger = RecordingWcLogger::install();
		try {
			$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'checkout' ) );
			$this->assertSame( DAY_IN_SECONDS, $wp_object_cache->expiries[ $lock_key . '_holder' ] ?? null, 'The holder record outlives its lock.' );
			$this->assertSame( array(), $this->get_takeover_lines( $logger ) );
			// The cache drops the expired lock and keeps the holder record.
			wp_cache_delete( $lock_key, 'transient' );

			$this->assertNotNull( $this->sut->claim( $order, $this->persistence_vocabulary, 'pi_dead', 'payment status update' ) );

			$lines = $this->get_takeover_lines( $logger );
			$this->assertCount( 1, $lines );
			$this->assertSame( sprintf( 'order payment lock taken over: order %d, by payment status update, from checkout claimed %ds ago', $order->get_id(), $lines[0]['context']['former_age_seconds'] ), $lines[0]['message'] );
			$this->assertSame( 'checkout', $lines[0]['context']['former_operation'] );
			$this->assertLessThanOrEqual( 1, $lines[0]['context']['former_age_seconds'] );
			$this->assertSame( 'pi_dead', $lines[0]['context']['former_lock_value'] );
		} finally {
			wp_cache_delete( $lock_key, 'transient' );
			wp_cache_delete( $lock_key . '_holder', 'transient' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restores the cache replaced above.
			$wp_object_cache = $was_cache;
			wp_using_ext_object_cache( (bool) $was_ext );
		}
	}

	/**
	 * @testdox A refusal with a retry queued ($retry_queued) by a holder $holder_age seconds old is logged at $level, with the same message.
	 *
	 * @testWith [true, 5, "notice"]
	 *           [true, 90, "warning"]
	 *           [true, null, "warning"]
	 *           [false, 5, "warning"]
	 *
	 * @param bool     $retry_queued Whether the refused work is retried later.
	 * @param int|null $holder_age   Age of the holder; null for a holder record that names another lock value.
	 * @param string   $level        Expected level.
	 */
	public function test_refusal_is_a_notice_only_with_a_retry_queued_and_a_holder_under_a_minute( bool $retry_queued, ?int $holder_age, string $level ): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'pi_live', time() + 200 );
		$this->insert_holder_row( $lock_key, 'checkout', null === $holder_age ? 'pi_other' : 'pi_live', time() - ( $holder_age ?? 5 ) );
		$logger = RecordingWcLogger::install();

		$this->sut->log_refusal( $order, $this->persistence_vocabulary, 'refund', null, array(), $retry_queued );

		$this->assertCount( 1, $logger->lines );
		$this->assertSame( $level, $logger->lines[0][0] );
		$this->assertMatchesRegularExpression( '/^order payment lock refused: order ' . $order->get_id() . ', refused refund, held by (checkout for \d+s|an unknown operation for an unknown time)$/', $logger->lines[0][1] );
		$this->assertSame( $retry_queued, $logger->contexts[0]['retry_queued'] );
	}

	/**
	 * @testdox A claim the request never releases is released at shutdown, at the last priority, with its holder record (object cache: $object_cache).
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $object_cache Whether transients live in the object cache.
	 */
	public function test_claim_never_released_is_released_at_shutdown( bool $object_cache ): void {
		$this->in_storage_mode(
			$object_cache,
			function ( \WC_Order $order ): void {
				$sut = $this->create_lock();

				$this->assertNotNull( $sut->claim( $order, $this->persistence_vocabulary, 'pi_dying', 'checkout' ) );
				$this->assertSame( PHP_INT_MAX, has_action( 'shutdown', array( $sut, 'release_held_claims' ) ) );

				$sut->release_held_claims();

				$this->assertFalse( $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ), 'The lock is released.' );
				$this->assertFalse( $this->read_holder_record( $order ), 'Its holder record goes with it.' );
			}
		);
	}

	/**
	 * @testdox A request that takes no claim, or whose claim is refused, adds nothing to shutdown.
	 */
	public function test_no_claim_and_a_refused_claim_add_nothing_to_shutdown(): void {
		$order  = wc_create_order();
		$sut    = $this->create_lock();
		$holder = $this->create_lock();

		$this->assertFalse( has_action( 'shutdown', array( $sut, 'release_held_claims' ) ) );
		$token = $holder->claim( $order, $this->persistence_vocabulary, 'pi_holder', 'checkout' );
		$this->assertNull( $sut->claim( $order, $this->persistence_vocabulary, 'pi_refused', 'refund' ) );
		$this->assertFalse( has_action( 'shutdown', array( $sut, 'release_held_claims' ) ), 'A refused claim adds nothing.' );
		$holder->release( $order, $this->persistence_vocabulary, (string) $token );
	}

	/**
	 * @testdox Releasing at shutdown leaves a lock the request released and another request then claimed, on that order or another.
	 */
	public function test_shutdown_release_leaves_a_released_claim_alone(): void {
		$order       = wc_create_order();
		$other_order = wc_create_order();
		$sut         = $this->create_lock();
		$later       = $this->create_lock();
		$released    = $sut->claim( $order, $this->persistence_vocabulary, 'pi_released', 'refund' );
		$this->assertNotNull( $sut->claim( $other_order, $this->persistence_vocabulary, 'pi_kept', 'checkout' ) );
		$sut->release( $order, $this->persistence_vocabulary, (string) $released );
		$this->assertNotNull( $later->claim( $order, $this->persistence_vocabulary, 'pi_later', 'capture' ) );

		$sut->release_held_claims();

		$this->assertSame( 'pi_later', $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ), 'The later claim keeps its lock.' );
		$this->assertFalse( $this->get_order_payment_lock_value( $other_order, $this->persistence_vocabulary ), 'Only the claim never released is released.' );
		$this->clear_order_payment_lock( $order, $this->persistence_vocabulary );
	}

	/**
	 * @testdox Releasing at shutdown leaves a lock another request took over after this one's expired (object cache: $object_cache).
	 *
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $object_cache Whether transients live in the object cache.
	 */
	public function test_shutdown_release_leaves_a_takeover_alone( bool $object_cache ): void {
		$this->in_storage_mode(
			$object_cache,
			function ( \WC_Order $order ) use ( $object_cache ): void {
				$sut      = $this->create_lock();
				$taker    = $this->create_lock();
				$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
				$this->assertNotNull( $sut->claim( $order, $this->persistence_vocabulary, 'pi_same', 'checkout' ) );
				if ( $object_cache ) {
					wp_cache_delete( $lock_key, 'transient' );
				} else {
					$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
				}
				$this->assertNotNull( $taker->claim( $order, $this->persistence_vocabulary, 'pi_same', 'payment status update' ) );

				$sut->release_held_claims();

				$this->assertSame( 'pi_same', $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ), 'The taker keeps its lock.' );
				$this->assertSame( 'payment status update', $this->read_holder_record( $order )['operation'] ?? null, 'The taker keeps its holder record.' );
			}
		);
	}

	/**
	 * @testdox A claim first taken by a shutdown callback at priority 10 is released in the same shutdown.
	 */
	public function test_claim_taken_during_shutdown_is_released_in_the_same_shutdown(): void {
		$order = wc_create_order();
		$sut   = $this->create_lock();
		$token = null;
		remove_all_actions( 'shutdown' );
		add_action(
			'shutdown',
			function () use ( $sut, $order, &$token ): void {
				$token = $sut->claim( $order, $this->persistence_vocabulary, 'pi_cleanup', 'earlier attempt cleanup' );
			}
		);

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Runs WordPress's end of the request.
		do_action( 'shutdown' );

		$this->assertNotNull( $token, 'The shutdown callback must have claimed.' );
		$this->assertFalse( $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ) );
	}

	/**
	 * @testdox A claim whose money request was sent and whose result is not saved keeps its lock at shutdown, and is released once the result is saved.
	 */
	public function test_claim_with_an_unsaved_money_request_keeps_its_lock_at_shutdown(): void {
		$order = wc_create_order();
		$sut   = $this->create_lock();
		$token = (string) $sut->claim( $order, $this->persistence_vocabulary, 'pi_charging', 'checkout' );

		$sut->mark_money_request_unsaved( $token, true );
		$sut->release_held_claims();

		$this->assertSame( 'pi_charging', $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ), 'The lock is kept.' );

		$sut->mark_money_request_unsaved( $token, false );
		$sut->release_held_claims();

		$this->assertFalse( $this->get_order_payment_lock_value( $order, $this->persistence_vocabulary ), 'Once saved, the lock is released.' );
	}

	/**
	 * Build an order payment lock of its own, so its held claims and shutdown callback belong to the test.
	 *
	 * @return OrderPaymentLock
	 */
	private function create_lock(): OrderPaymentLock {
		$lock = new OrderPaymentLock();
		$lock->init( new TransientRowLock() );

		return $lock;
	}

	/**
	 * Run a test body on a new order with transients in the database or in the object cache.
	 *
	 * @param bool     $object_cache Whether transients live in the object cache.
	 * @param callable $body         Test body, given the order.
	 */
	private function in_storage_mode( bool $object_cache, callable $body ): void {
		if ( ! $object_cache ) {
			$this->skip_when_transients_live_in_object_cache();
		}
		$order    = wc_create_order();
		$lock_key = $this->persistence_vocabulary->get_order_lock_key( $order );
		$was_ext  = wp_using_ext_object_cache( $object_cache ? true : wp_using_ext_object_cache() );
		try {
			$body( $order );
		} finally {
			if ( $object_cache ) {
				wp_cache_delete( $lock_key, 'transient' );
				wp_cache_delete( $lock_key . '_holder', 'transient' );
			}
			wp_using_ext_object_cache( (bool) $was_ext );
		}
	}

	/**
	 * Read the order payment lock's holder record as stored.
	 *
	 * @param \WC_Order $order Order.
	 * @return mixed The record, or false when there is none.
	 */
	private function read_holder_record( \WC_Order $order ) {
		$holder_key = $this->persistence_vocabulary->get_order_lock_key( $order ) . '_holder';
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $holder_key, 'transient' );
		}
		$stored = $this->read_lock_row( '_transient_' . $holder_key );

		return null === $stored ? false : maybe_unserialize( $stored );
	}

	/**
	 * Get the takeover lines a recording logger holds.
	 *
	 * @param RecordingWcLogger $logger Recording logger.
	 * @return array<int,array{level:string,message:string,context:array<string,mixed>}>
	 */
	private function get_takeover_lines( RecordingWcLogger $logger ): array {
		$lines = array();
		foreach ( $logger->lines as $index => $line ) {
			if ( 0 === strpos( $line[1], 'order payment lock taken over: ' ) ) {
				$lines[] = array(
					'level'   => $line[0],
					'message' => $line[1],
					'context' => $logger->contexts[ $index ],
				);
			}
		}

		return $lines;
	}

	/**
	 * Store a holder record directly in the database, as a request that died holding the lock leaves it.
	 *
	 * @param string $lock_key   Lock transient key.
	 * @param string $operation  Operation the record names.
	 * @param string $lock_value Lock value the record names.
	 * @param int    $claimed_at When the record's claim was made.
	 */
	private function insert_holder_row( string $lock_key, string $operation, string $lock_value, int $claimed_at ): void {
		$this->insert_lock_row(
			'_transient_' . $lock_key . '_holder',
			maybe_serialize(
				array(
					'operation'  => $operation,
					'lock_value' => $lock_value,
					'token'      => 'token_of_a_dead_request',
					'claimed_at' => $claimed_at,
				)
			)
		);
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
