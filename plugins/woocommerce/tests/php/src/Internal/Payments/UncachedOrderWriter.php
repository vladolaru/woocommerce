<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * Writes order state straight to the database, as another request would, leaving this request's post, meta and HPOS
 * order caches as they were.
 *
 * A test that changes an order this way between a handler's lookup and its lock claim fails when the handler decides
 * on a cached read instead of a read from the data store.
 */
final class UncachedOrderWriter {

	/**
	 * Turn on the HPOS order data cache when orders live in the HPOS tables, so a stale read can come from it.
	 */
	public static function enable_hpos_data_caching(): void {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			update_option( CustomOrdersTableController::HPOS_DATASTORE_CACHING_ENABLED_OPTION, 'yes' );
		}
	}

	/**
	 * Write order properties and meta to the database without clearing any cache.
	 *
	 * @param int                  $order_id Order ID.
	 * @param array<string,mixed>  $props    Any of `status` (without the wc- prefix), `transaction_id`, `payment_method` and `date_paid` (a Unix time).
	 * @param array<string,string> $meta     Meta values, each replacing any stored value.
	 */
	public static function write( int $order_id, array $props, array $meta = array() ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Raw writes emulate another request without cache invalidation.
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$columns = array();
			if ( isset( $props['status'] ) ) {
				$columns['status'] = 'wc-' . $props['status'];
			}
			foreach ( array( 'transaction_id', 'payment_method' ) as $column ) {
				if ( isset( $props[ $column ] ) ) {
					$columns[ $column ] = (string) $props[ $column ];
				}
			}
			if ( ! empty( $columns ) ) {
				$wpdb->update( OrdersTableDataStore::get_orders_table_name(), $columns, array( 'id' => $order_id ) );
			}
			if ( isset( $props['date_paid'] ) ) {
				$wpdb->update( OrdersTableDataStore::get_operational_data_table_name(), array( 'date_paid_gmt' => gmdate( 'Y-m-d H:i:s', (int) $props['date_paid'] ) ), array( 'order_id' => $order_id ) );
			}
			foreach ( $meta as $key => $value ) {
				$wpdb->delete(
					OrdersTableDataStore::get_meta_table_name(),
					array(
						'order_id' => $order_id,
						'meta_key' => $key,
					)
				);
				$wpdb->insert(
					OrdersTableDataStore::get_meta_table_name(),
					array(
						'order_id'   => $order_id,
						'meta_key'   => $key,
						'meta_value' => $value,
					)
				);
			}
		} else {
			if ( isset( $props['status'] ) ) {
				$wpdb->update( $wpdb->posts, array( 'post_status' => 'wc-' . $props['status'] ), array( 'ID' => $order_id ) );
			}
			$post_meta = $meta;
			if ( isset( $props['transaction_id'] ) ) {
				$post_meta['_transaction_id'] = (string) $props['transaction_id'];
			}
			if ( isset( $props['payment_method'] ) ) {
				$post_meta['_payment_method'] = (string) $props['payment_method'];
			}
			if ( isset( $props['date_paid'] ) ) {
				$post_meta['_date_paid'] = (string) (int) $props['date_paid'];
				$post_meta['_paid_date'] = gmdate( 'Y-m-d H:i:s', (int) $props['date_paid'] );
			}
			foreach ( $post_meta as $key => $value ) {
				$wpdb->delete(
					$wpdb->postmeta,
					array(
						'post_id'  => $order_id,
						'meta_key' => $key,
					)
				);
				$wpdb->insert(
					$wpdb->postmeta,
					array(
						'post_id'    => $order_id,
						'meta_key'   => $key,
						'meta_value' => $value,
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	}
}
