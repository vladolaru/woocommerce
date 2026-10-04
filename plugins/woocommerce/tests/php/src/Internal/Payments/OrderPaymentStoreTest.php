<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceProfile;
use WC_Order_Refund;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderPaymentStore class.
 */
class OrderPaymentStoreTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var OrderPaymentStore
	 */
	private $sut;

	/**
	 * WooPayments persistence profile.
	 *
	 * @var WooPaymentsPersistenceProfile
	 */
	private WooPaymentsPersistenceProfile $persistence_profile;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut                 = wc_get_container()->get( OrderPaymentStore::class );
		$this->persistence_profile = new WooPaymentsPersistenceProfile();
	}

	/**
	 * @testdox Payment meta keys preserve the WooPayments Bucket-E persisted surface.
	 */
	public function test_payment_meta_keys_preserve_woopayments_bucket_e_surface(): void {
		$keys = OrderPaymentStore::get_payment_meta_keys( $this->persistence_profile );

		$this->assertSame( $keys, array_values( array_unique( $keys ) ), 'Payment meta keys must not contain duplicates.' );

		foreach (
			array(
				'_intent_id',
				'_payment_method_id',
				'_charge_id',
				'_intention_status',
				'_charge_risk_level',
				'_stripe_customer_id',
				'_wcpay_fraud_meta_box_type',
				'_wcpay_fraud_outcome_status',
				'_wcpay_intent_currency',
				'_wcpay_refund_id',
				'_wcpay_refund_transaction_id',
				'_wcpay_refund_status',
				'_wcpay_transaction_fee',
				'_wcpay_mode',
				'_wcpay_payment_transaction_id',
				'_wcpay_multibanco_entity',
				'_wcpay_multibanco_reference',
				'_wcpay_multibanco_expiry',
				'_wcpay_multibanco_url',
				'_wcpay_payment_method_details',
				'_wcpay_ipp_channel',
				'_wcpay_net',
				'_stripe_mandate_id',
				'_wcpay_express_checkout_payment_method',
				'_wcpay_multi_currency_stripe_exchange_rate',
				'_wcpay_multi_currency_order_exchange_rate',
				'_wcpay_multi_currency_order_default_currency',
				'_wcpay_fraud_outcome_manual_entry',
				'is_woopay',
				'last4',
				'_card_brand',
			) as $key
		) {
			$this->assertContains( $key, $keys, "{$key} must remain part of the preserved WooPayments persisted surface." );
		}
	}

	/**
	 * @testdox read_payment_surface returns a stable HPOS-safe projection without unrelated meta.
	 */
	public function test_read_payment_surface_returns_stable_payment_projection(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_transaction_id( 'txn_123' );
		$order->set_total( '12.34' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.71' );
		$order->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$order->update_meta_data( '_wcpay_multi_currency_stripe_exchange_rate', '0.724' );
		$order->update_meta_data(
			'_wcpay_fraud_outcome_manual_entry',
			array(
				'status' => 'approved',
			)
		);
		$order->update_meta_data( '_not_a_payment_key', 'ignore-me' );
		$order->save();

		$surface = $this->sut->read_payment_surface( $order, $this->persistence_profile );

		$this->assertSame( $order->get_id(), $surface['order_id'] );
		$this->assertSame( $order->get_status(), $surface['status'] );
		$this->assertSame( OrderPaymentStore::GATEWAY_ID, $surface['payment_method'] );
		$this->assertSame( 'txn_123', $surface['transaction_id'] );
		$this->assertSame( 'USD', $surface['currency'] );
		$this->assertSame( '12.34', $surface['total'] );
		$this->assertSame( 'pi_123', $surface['meta']['_intent_id'] );
		$this->assertSame( 'ch_123', $surface['meta']['_charge_id'] );
		$this->assertSame( '0.71', $surface['meta']['_wcpay_multi_currency_order_exchange_rate'] );
		$this->assertSame( 'USD', $surface['meta']['_wcpay_multi_currency_order_default_currency'] );
		$this->assertSame( '0.724', $surface['meta']['_wcpay_multi_currency_stripe_exchange_rate'] );
		$this->assertSame( '{"status":"approved"}', $surface['meta']['_wcpay_fraud_outcome_manual_entry'] );
		$this->assertArrayNotHasKey( '_not_a_payment_key', $surface['meta'] );
		$this->assertSame( array(), $surface['refunds'] );
	}

	/**
	 * @testdox read_payment_surface includes refund payment meta in the stable projection.
	 */
	public function test_read_payment_surface_includes_refund_payment_meta(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->set_total( '12.34' );
		$order->save();

		$refund = wc_create_refund(
			array(
				'amount'   => '3.21',
				'reason'   => 'partial refund',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
		$refund->update_meta_data( '_wcpay_multi_currency_order_exchange_rate', '0.71' );
		$refund->update_meta_data( '_wcpay_multi_currency_order_default_currency', 'USD' );
		$refund->update_meta_data( '_wcpay_multi_currency_stripe_exchange_rate', '0.724' );
		$refund->update_meta_data( '_not_a_payment_key', 'ignore-me' );
		$refund->save();

		$surface = $this->sut->read_payment_surface( wc_get_order( $order->get_id() ), $this->persistence_profile );

		$this->assertCount( 1, $surface['refunds'] );
		$this->assertSame( $refund->get_id(), $surface['refunds'][0]['refund_id'] );
		$this->assertSame( '3.21', $surface['refunds'][0]['amount'] );
		$this->assertSame( 'partial refund', $surface['refunds'][0]['reason'] );
		$this->assertSame( 're_123', $surface['refunds'][0]['meta']['_wcpay_refund_id'] );
		$this->assertSame( '0.71', $surface['refunds'][0]['meta']['_wcpay_multi_currency_order_exchange_rate'] );
		$this->assertSame( 'USD', $surface['refunds'][0]['meta']['_wcpay_multi_currency_order_default_currency'] );
		$this->assertSame( '0.724', $surface['refunds'][0]['meta']['_wcpay_multi_currency_stripe_exchange_rate'] );
		$this->assertArrayNotHasKey( '_not_a_payment_key', $surface['refunds'][0]['meta'] );
	}

	/**
	 * @testdox Payment surfaces and locks use the supplied provider persistence profile.
	 */
	public function test_payment_surfaces_and_locks_use_supplied_provider_profile(): void {
		$order = wc_create_order();
		$order->update_meta_data( '_provider_payment_id', 'provider_payment_123' );
		$order->update_meta_data( '_intent_id', 'pi_must_not_leak' );
		$order->save();

		$profile = $this->create_provider_profile();
		$surface = $this->sut->read_payment_surface( $order, $profile );

		$this->assertSame( array( '_provider_payment_id' => 'provider_payment_123' ), $surface['meta'] );
		$this->sut->lock_order_payment( $order, $profile );
		$this->assertSame( 'provider-lock', get_transient( 'provider_payment_lock_' . $order->get_id() ) );
		$this->assertFalse( get_transient( OrderPaymentStore::LOCK_TRANSIENT_PREFIX . $order->get_id() ) );

		$this->sut->unlock_order_payment( $order, $profile );
		$this->assertFalse( get_transient( 'provider_payment_lock_' . $order->get_id() ) );
	}

	/**
	 * @testdox Order payment locks use the WooPayments-compatible transient shape.
	 */
	public function test_order_payment_locks_use_woopayments_compatible_transient_shape(): void {
		$order = wc_create_order();

		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'pi_123' ) );

		$this->sut->lock_order_payment( $order, $this->persistence_profile, 'pi_123' );

		$this->assertTrue( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'pi_123' ) );
		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'pi_other' ) );

		$this->sut->lock_order_payment( $order, $this->persistence_profile );

		$this->assertTrue( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'pi_other' ), 'The sentinel lock must block every payment reference.' );

		$this->sut->unlock_order_payment( $order, $this->persistence_profile );

		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'pi_123' ) );
	}

	/**
	 * @testdox Native money-operation claims should block any active order payment lock.
	 */
	public function test_claim_order_payment_lock_blocks_any_active_lock(): void {
		$order = wc_create_order();

		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'native_refund_key' ) );
		$this->assertTrue( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'native_refund_key' ) );

		$this->sut->unlock_order_payment( $order, $this->persistence_profile );

		$this->sut->lock_order_payment( $order, $this->persistence_profile, 'pi_legacy' );
		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'native_charge_key' ) );

		$this->sut->unlock_order_payment( $order, $this->persistence_profile );
		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'native_charge_key' ) );
		$this->sut->unlock_order_payment( $order, $this->persistence_profile );
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

		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ), 'Only one of two overlapping requests may hold the order payment lock.' );
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

		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'fresh_key' ), 'An expired lock must not block a new claim.' );
		$this->assertSame( 'fresh_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The new lock must expire a full TTL from now.' );
		$this->assertTrue( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'fresh_key' ) );
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'later_key' ), 'The taken-over lock must block the next claim.' );
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

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
		remove_filter( 'query', $rival );

		$this->assertTrue( $rival_took_over, 'The rival takeover must have run.' );
		$this->assertFalse( $claimed, 'A takeover must fail when another request took the expired lock first.' );
		$this->assertSame( 'rival_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox Releasing the lock should delete it only while the caller still holds it.
	 */
	public function test_release_deletes_the_lock_only_for_its_holder(): void {
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );

		$this->assertTrue( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'first_key', 'capture' ) );
		// The first operation ran past the TTL and another operation took the lock over.
		$this->sut->unlock_order_payment( $order, $this->persistence_profile );
		$this->assertTrue( $this->sut->claim_order_payment_lock_for_operation( $order, $this->persistence_profile, 'second_key', 'refund' ) );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'first_key' );

		$this->assertSame( 'second_key', get_transient( $lock_key ), 'A former holder must not release the current holder lock.' );
		$this->assertSame( 'refund', get_transient( $lock_key . '_holder' )['operation'] ?? null, 'A former holder must not delete the current holder record.' );

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'second_key' );

		$this->assertFalse( get_transient( $lock_key ), 'The holder must be able to release its lock.' );
		$this->assertFalse( get_transient( $lock_key . '_holder' ), 'Releasing the lock must delete its holder record.' );
		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile ), 'A released lock must be claimable again.' );
		$this->sut->release_order_payment_lock( $order, $this->persistence_profile );
		$this->assertFalse( get_transient( $lock_key ), 'A sentinel claim must be released by a null reference.' );
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

		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ), 'A leftover expiry row without a value row must not block a claim.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The claim must replace the leftover expiry with a full TTL.' );
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'later_key' ), 'The claimed lock must block the next claim.' );
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

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
		remove_filter( 'query', $rival );

		$this->assertTrue( $took_over, 'The rival takeover must have run.' );
		$this->assertFalse( $claimed, 'Only the rival may hold the lock after taking it over.' );
		$this->assertSame( 'rival_key', $this->read_lock_row( '_transient_' . $lock_key ) );
	}

	/**
	 * @testdox A claim should win, with an expiry, when the holder releases the lock between the claim's expiry read and its value insert.
	 */
	public function test_claim_wins_when_the_holder_releases_between_its_expiry_read_and_value_insert(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'holder_key', time() + 100 );

		$released = false;
		$filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			function () use ( &$released, $order ): void {
				$released = true;
				$this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'holder_key' );
			}
		);

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $released, 'The holder release must have run.' );
		$this->assertTrue( $claimed, 'A claim must win a lock the holder released before the claim inserted its value.' );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The winning claim must leave an expiry row.' );
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'later_key' ), 'The claimed lock must block the next claim.' );
	}

	/**
	 * @testdox A claim should keep a lock whose expiry a refused rival filled in after the holder released the lock.
	 */
	public function test_claim_keeps_the_lock_when_a_refused_rival_fills_in_its_expiry(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'holder_key', time() + 100 );

		// The holder releases right after the claim reads the expiry; a rival claims right after the claim inserts its value.
		$release_filter = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			fn() => $this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'holder_key' )
		);
		$rival_claimed  = null;
		$rival_filter   = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_value_insert( $query, $lock_key ),
			function () use ( &$rival_claimed, $order ): void {
				$rival_claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'rival_key' );
			}
		);

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
		remove_filter( 'query', $release_filter );
		remove_filter( 'query', $rival_filter );

		$this->assertFalse( $rival_claimed, 'The rival claim must have run and been refused.' );
		$this->assertTrue( $claimed, 'The claim that inserted the value row must keep the lock.' );
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

		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ) );
		$this->assertSame( 'this_request_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( (int) $expiration, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ) );
	}

	/**
	 * @testdox A claim should write an expiry row when the holder, whose expiry equals the claim's, releases between the claim's expiry read and value insert.
	 */
	public function test_claim_writes_an_expiry_when_a_holder_with_the_same_expiry_releases(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'holder_key', time() + $this->persistence_profile->get_lock_ttl_seconds() );

		$filter = $this->run_before_query_after(
			fn( string $query ): bool => $this->is_expiry_read( $query, $lock_key ),
			fn() => $this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'holder_key' )
		);

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $claimed );
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

		$claimed = $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'pi_shared' );
		remove_filter( 'query', $filter );

		$this->assertTrue( $took_over, 'The rival takeover must have run.' );
		$this->assertFalse( $claimed, 'Two requests for the same payment reference must not both hold the lock.' );
		$this->assertSame( $rival_expiry, $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must leave the taker expiry.' );
	}

	/**
	 * @testdox Releasing the lock should retry once when its delete fails.
	 */
	public function test_release_retries_a_failed_delete_once(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ) );

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

		$this->sut->release_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' );
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

		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ), 'A value row without an expiry must be treated as held.' );
		$this->assertSame( 'orphan_key', $this->read_lock_row( '_transient_' . $lock_key ) );
		$this->assertGreaterThanOrEqual( time() + 290, (int) $this->read_lock_row( '_transient_timeout_' . $lock_key ), 'The refused claim must give the leftover value row an expiry.' );

		// Once that expiry passes, the leftover lock can be taken over.
		$this->update_lock_row( '_transient_timeout_' . $lock_key, (string) ( time() - 1 ) );
		$this->assertTrue( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'this_request_key' ) );
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
		$this->assertFalse( $this->sut->claim_order_payment_lock( $order, $this->persistence_profile, 'third_key' ), 'A third claimant must not win while the taker holds the lock.' );
	}

	/**
	 * @testdox Checking whether an order is locked should not delete an expired lock.
	 */
	public function test_is_order_payment_locked_does_not_delete_an_expired_lock(): void {
		$this->skip_when_transients_live_in_object_cache();
		$order    = wc_create_order();
		$lock_key = $this->persistence_profile->get_order_lock_key( $order );
		$this->insert_lock_rows( $lock_key, 'stale_key', time() - 10 );

		$this->assertFalse( $this->sut->is_order_payment_locked( $order, $this->persistence_profile, 'stale_key' ), 'An expired lock must not count as held.' );
		$this->assertSame( 'stale_key', $this->read_lock_row( '_transient_' . $lock_key ), 'Only a takeover may replace an expired lock.' );
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

	/**
	 * Create a non-WooPayments persistence profile.
	 *
	 * @return ProviderPersistenceProfile
	 */
	private function create_provider_profile(): ProviderPersistenceProfile {
		$profile = $this->createMock( ProviderPersistenceProfile::class );
		$profile->method( 'get_order_lock_key' )->willReturnCallback(
			static fn( WC_Order $order ): string => 'provider_payment_lock_' . $order->get_id()
		);
		$profile->method( 'get_lock_sentinel' )->willReturn( 'provider-lock' );
		$profile->method( 'get_lock_ttl_seconds' )->willReturn( 60 );
		$profile->method( 'get_preserved_payment_meta_keys' )->willReturn( array( '_provider_payment_id' ) );

		return $profile;
	}
}
