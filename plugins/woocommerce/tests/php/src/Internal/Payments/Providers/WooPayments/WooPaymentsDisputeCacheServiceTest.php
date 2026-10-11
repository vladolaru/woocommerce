<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDisputeCacheService class.
 */
class WooPaymentsDisputeCacheServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox A dispute cache entry left in the object cache after its option row is gone is cleared too (G1-4).
	 *
	 * Client 11.1.0 `class-database-cache.php:230-243` deletes the object-cache entry whatever delete_option() did
	 * (its #9639 regression fix): delete_option() returns before touching the cache when the row is already gone.
	 *
	 * @testWith ["wcpay_dispute_status_counts_cache"]
	 *           ["wcpay_test_dispute_status_counts_cache"]
	 *           ["wcpay_active_dispute_cache"]
	 *
	 * @param string $key Dispute cache option.
	 */
	public function test_clears_a_stale_object_cache_entry_without_its_option_row( string $key ): void {
		global $wpdb;
		update_option( $key, array( 'needs_response' => 3 ), false );
		// The row disappears while a persistent object cache still holds the old value.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
		wp_cache_set( $key, array( 'needs_response' => 3 ), 'options' );

		( new WooPaymentsDisputeCacheService() )->delete_dispute_caches();

		$this->assertFalse( wp_cache_get( $key, 'options' ) );
	}
}
