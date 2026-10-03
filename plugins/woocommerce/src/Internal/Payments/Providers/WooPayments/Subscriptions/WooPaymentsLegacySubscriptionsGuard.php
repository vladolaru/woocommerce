<?php
/**
 * WooPaymentsLegacySubscriptionsGuard class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\WooPaymentsStripeBillingModule;

/**
 * Detects stores on the bundled WooPayments subscriptions flavor, which stay on the plugin at cutover.
 *
 * Stores with WooCommerce Subscriptions active switch with their Stripe Billing data, which native serves. A store without it
 * is bundled when the bundled subscriptions flag is on or a subscription is still billed by Stripe Billing; subscriptions
 * migrated off Stripe Billing keep only `_migrated_*` meta, so they do not count.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsLegacySubscriptionsGuard {

	/**
	 * Order type of the subscriptions Stripe Billing bills.
	 */
	private const SUBSCRIPTION_ORDER_TYPE = 'shop_subscription';

	/**
	 * Subscription meta holding the Stripe subscription ID while Stripe Billing bills the subscription.
	 */
	private const STRIPE_BILLED_META_KEY = '_wcpay_subscription_id';

	/**
	 * Option that turns the bundled WooPayments subscriptions on (client 11.1.0 `WC_Payments_Features::WCPAY_SUBSCRIPTIONS_FLAG_NAME`).
	 */
	private const BUNDLED_SUBSCRIPTIONS_FLAG_OPTION = '_wcpay_feature_subscriptions';

	/**
	 * Plugin file of WooCommerce Subscriptions, whatever folder it is installed in.
	 */
	private const SUBSCRIPTIONS_PLUGIN_FILE = 'woocommerce-subscriptions.php';

	/**
	 * Cached results for each blog visited during the current request.
	 *
	 * @var array<int,bool>
	 */
	private array $is_bundled_store = array();

	/**
	 * Tell whether the store uses the bundled WooPayments subscriptions, so cutover would strand its subscriptions.
	 *
	 * A database error counts as bundled, so cutover never proceeds on an unknown answer.
	 *
	 * @return bool True when WooCommerce Subscriptions is inactive and the bundled flag is on or a subscription is still Stripe-billed.
	 */
	public function is_bundled_stripe_billing_store(): bool {
		$blog_id = get_current_blog_id();
		if ( array_key_exists( $blog_id, $this->is_bundled_store ) ) {
			return $this->is_bundled_store[ $blog_id ];
		}

		$this->is_bundled_store[ $blog_id ] = ! $this->is_subscriptions_plugin_active()
			&& ( '1' === get_option( self::BUNDLED_SUBSCRIPTIONS_FLAG_OPTION, '0' ) || $this->query_stripe_billed_subscription_exists() );

		return $this->is_bundled_store[ $blog_id ];
	}

	/**
	 * Tell whether WooCommerce Subscriptions is active on the current site.
	 *
	 * On the site serving the request this is the Stripe Billing module's own check. A site visited with switch_to_blog() has not
	 * loaded its plugins, so its active plugin options are read instead.
	 *
	 * @return bool
	 */
	protected function is_subscriptions_plugin_active(): bool {
		if ( ! is_multisite() || ! ms_is_switched() ) {
			return WooPaymentsStripeBillingModule::is_woocommerce_subscriptions_active();
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$network_active = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		foreach ( array_merge( $active_plugins, $network_active ) as $plugin_file ) {
			if ( is_string( $plugin_file ) && self::SUBSCRIPTIONS_PLUGIN_FILE === basename( $plugin_file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Query whether a subscription is still billed by Stripe Billing.
	 *
	 * @return bool True when at least one exists, or the query failed.
	 */
	private function query_stripe_billed_subscription_exists(): bool {
		return $this->query_stripe_billed_subscription_exists_from_hpos_tables() || $this->query_stripe_billed_subscription_exists_from_posts();
	}

	/**
	 * Query the HPOS tables directly for a Stripe-billed subscription.
	 *
	 * @return bool True when one exists, or the query failed.
	 */
	private function query_stripe_billed_subscription_exists_from_hpos_tables(): bool {
		$wpdb = $this->get_database();

		if ( ! $wpdb->has_cap( 'identifier_placeholders' ) ) {
			return true;
		}

		$hpos_tables_available = $this->has_hpos_tables();
		if ( null === $hpos_tables_available ) {
			return true;
		}
		if ( ! $hpos_tables_available ) {
			return false;
		}

		$sql = $wpdb->prepare(
			'SELECT 1
			FROM %i AS orders
			INNER JOIN %i AS ordermeta ON orders.id = ordermeta.order_id
			WHERE orders.type = %s
				AND ordermeta.meta_key = %s
			LIMIT 1',
			OrdersTableDataStore::get_orders_table_name(),
			OrdersTableDataStore::get_meta_table_name(),
			self::SUBSCRIPTION_ORDER_TYPE,
			self::STRIPE_BILLED_META_KEY
		);

		$wpdb->last_error = '';
		$marker           = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $this->database_has_error( $wpdb ) || null !== $marker;
	}

	/**
	 * Check whether both HPOS tables are available without treating an unavailable table as a marker.
	 *
	 * @return bool|null True when present, false when absent, and null on a database error.
	 */
	private function has_hpos_tables(): ?bool {
		$wpdb = $this->get_database();

		foreach ( array( OrdersTableDataStore::get_orders_table_name(), OrdersTableDataStore::get_meta_table_name() ) as $table_name ) {
			$wpdb->last_error = '';
			$table            = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
			);
			if ( $this->database_has_error( $wpdb ) ) {
				return null;
			}
			if ( $table_name !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Query the CPT store directly for a Stripe-billed subscription the order APIs cannot surface.
	 *
	 * @return bool True when one exists, or the query failed.
	 */
	private function query_stripe_billed_subscription_exists_from_posts(): bool {
		$wpdb = $this->get_database();

		if ( ! $wpdb->has_cap( 'identifier_placeholders' ) ) {
			return true;
		}

		$sql = $wpdb->prepare(
			'SELECT 1
			FROM %i AS posts
			INNER JOIN %i AS postmeta ON posts.ID = postmeta.post_id
			WHERE posts.post_type = %s
				AND postmeta.meta_key = %s
			LIMIT 1',
			$wpdb->posts,
			$wpdb->postmeta,
			self::SUBSCRIPTION_ORDER_TYPE,
			self::STRIPE_BILLED_META_KEY
		);

		$wpdb->last_error = '';
		$marker           = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $this->database_has_error( $wpdb ) || null !== $marker;
	}

	/**
	 * Check whether the most recent database query failed.
	 *
	 * @param \wpdb $wpdb WordPress database access abstraction.
	 * @return bool True when the last query failed.
	 */
	private function database_has_error( \wpdb $wpdb ): bool {
		return '' !== $wpdb->last_error;
	}

	/**
	 * Get the WordPress database abstraction.
	 *
	 * @return \wpdb WordPress database access abstraction.
	 */
	protected function get_database(): \wpdb {
		global $wpdb;

		return $wpdb;
	}
}
