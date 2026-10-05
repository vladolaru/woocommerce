<?php
/**
 * WooPaymentsDisputeCacheService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

/**
 * Invalidates preserved WooPayments dispute cache entries.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDisputeCacheService {

	private const DISPUTE_CACHE_KEYS = array(
		'wcpay_dispute_status_counts_cache',
		'wcpay_test_dispute_status_counts_cache',
		'wcpay_active_dispute_cache',
	);

	/**
	 * Delete all dispute-related cache entries.
	 *
	 * The object-cache entry is deleted whatever delete_option() did: it returns before touching the cache when the row
	 * is already gone, which left a persistent object cache serving stale dispute counts (client 11.1.0
	 * `class-database-cache.php:230-243`, its #9639 regression fix).
	 *
	 * @since 11.0.0
	 */
	public function delete_dispute_caches(): void {
		foreach ( self::DISPUTE_CACHE_KEYS as $key ) {
			delete_option( $key );
			wp_cache_delete( $key, 'options' );
		}
	}
}
