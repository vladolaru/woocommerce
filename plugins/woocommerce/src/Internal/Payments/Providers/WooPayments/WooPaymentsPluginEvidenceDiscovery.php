<?php
/**
 * WooPaymentsPluginEvidenceDiscovery class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Maintains durable WooPayments rollback evidence without synchronous table scans.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsPluginEvidenceDiscovery implements RegisterHooksInterface {

	/** Runtime owner arbiter. */
	private ?NativePaymentsRuntimeArbiter $arbiter = null;

	/** Per-site evidence marker option. */
	public const OPTION_NAME = 'woocommerce_woopayments_plugin_evidence';

	/** Per-site discovery progress option. */
	public const DISCOVERY_OPTION_NAME = 'woocommerce_woopayments_plugin_evidence_discovery';

	/** Network-wide evidence summary option. */
	public const NETWORK_OPTION_NAME = 'woocommerce_woopayments_plugin_evidence_network';

	/** Network-wide discovery progress option. */
	public const NETWORK_DISCOVERY_OPTION_NAME = 'woocommerce_woopayments_plugin_evidence_network_discovery';

	/** Evidence-discovery Action Scheduler hook. */
	public const DISCOVERY_ACTION_HOOK = 'woocommerce_woopayments_plugin_evidence_discovery';

	/** Network-summary Action Scheduler hook. */
	public const NETWORK_DISCOVERY_ACTION_HOOK = 'woocommerce_woopayments_plugin_evidence_network_discovery';

	/** Action Scheduler group. */
	public const ACTION_GROUP = 'woocommerce_woopayments_plugin_evidence';

	/** Evidence state for incomplete discovery. */
	public const STATE_UNKNOWN = 'unknown';

	/** Evidence state for a site that previously used WooPayments. */
	public const STATE_PRESENT = 'present';

	/** Evidence state for a conclusively first-time site. */
	public const STATE_NONE = 'none';

	/** Canonical WooPayments gateway identity. */
	private const GATEWAY_ID = 'woocommerce_payments';

	/** Prefix used by WooPayments payment-method variants. */
	private const GATEWAY_PREFIX = 'woocommerce_payments_';

	/** Maximum rows inspected by one discovery callback. */
	private const BATCH_SIZE = 100;

	/** Discovery stages. */
	private const STAGE_ORDERS = 'orders';
	private const STAGE_TOKENS = 'tokens';

	/**
	 * Initialize runtime-owner dependency.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter Runtime owner arbiter.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter ): void {
		$this->arbiter = $arbiter;
	}

	/**
	 * Register bounded discovery and write-path evidence observers.
	 */
	public function register(): void {
		add_action( self::DISCOVERY_ACTION_HOOK, array( $this, 'discover_current_site' ), 10, 1 );
		add_action( self::NETWORK_DISCOVERY_ACTION_HOOK, array( $this, 'refresh_network_summary' ) );
		add_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) );
		add_action( 'woocommerce_new_order', array( $this, 'observe_order' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'observe_order' ), 10, 2 );
		add_action( 'woocommerce_new_payment_token', array( $this, 'observe_payment_token' ), 10, 2 );
		add_action( 'woocommerce_payment_token_object_updated_props', array( $this, 'observe_payment_token_update' ), 10, 2 );

		if ( did_action( 'action_scheduler_init' ) ) {
			$this->handle_action_scheduler_init();
		}
	}

	/**
	 * Schedule deferred evidence discovery once Action Scheduler is available.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_init(): void {
		if ( self::STATE_UNKNOWN === self::get_current_site_state() ) {
			$this->schedule_site_discovery( get_current_blog_id() );
		}

		if ( is_multisite() ) {
			$this->schedule_network_summary();
		}
	}

	/**
	 * Record WooPayments order evidence when the order write path provides it.
	 *
	 * @internal
	 *
	 * @param mixed $order_id Order ID from the public WooCommerce hook.
	 * @param mixed $order    Order object from the public WooCommerce hook.
	 */
	public function observe_order( $order_id, $order ): void {
		unset( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
			return;
		}

		$gateway_id = $order->get_payment_method();
		if ( is_string( $gateway_id ) && self::is_woopayments_gateway_id( $gateway_id ) ) {
			$this->mark_current_site_present();
		}
	}

	/**
	 * Record WooPayments token evidence when the token write path provides it.
	 *
	 * @internal
	 *
	 * @param mixed $token_id Token ID from the public WooCommerce hook.
	 * @param mixed $token    Payment-token object from the public WooCommerce hook.
	 */
	public function observe_payment_token( $token_id, $token ): void {
		unset( $token_id );
		$this->observe_payment_token_object( $token );
	}

	/**
	 * Record WooPayments token evidence after a token property update.
	 *
	 * @internal
	 *
	 * @param mixed $token         Payment-token object from the public WooCommerce hook.
	 * @param mixed $updated_props Changed token properties from the public WooCommerce hook.
	 */
	public function observe_payment_token_update( $token, $updated_props ): void {
		unset( $updated_props );
		$this->observe_payment_token_object( $token );
	}

	/**
	 * Record evidence from one payment-token object.
	 *
	 * @param mixed $token Payment-token object from a public WooCommerce hook.
	 */
	private function observe_payment_token_object( $token ): void {
		if ( ! is_object( $token ) || ! method_exists( $token, 'get_gateway_id' ) ) {
			return;
		}

		$gateway_id = $token->get_gateway_id();
		if ( is_string( $gateway_id ) && self::is_woopayments_gateway_id( $gateway_id ) ) {
			$this->mark_current_site_present();
		}
	}

	/**
	 * Discover one bounded page of historical evidence for a site.
	 *
	 * @internal
	 *
	 * @param mixed $site_id Optional site ID supplied by Action Scheduler.
	 */
	public function discover_current_site( $site_id = null ): void {
		$target_site_id = null;
		if ( null !== $site_id ) {
			if ( ! is_int( $site_id ) || $site_id < 1 ) {
				return;
			}
			$target_site_id = $site_id;
		}

		$current_site_id = get_current_blog_id();
		if ( null !== $target_site_id && $target_site_id !== $current_site_id ) {
			switch_to_blog( $target_site_id );
		}

		try {
			$this->discover_current_site_page();
		} finally {
			if ( get_current_blog_id() !== $current_site_id ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * Refresh the durable summary used by the network activation guard.
	 *
	 * @internal
	 */
	public function refresh_network_summary(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$progress = get_site_option( self::NETWORK_DISCOVERY_OPTION_NAME, array() );
		$cursor   = is_array( $progress ) && isset( $progress['cursor'] ) && is_int( $progress['cursor'] ) && $progress['cursor'] >= 0 ? $progress['cursor'] : 0;
		$state    = is_array( $progress ) && isset( $progress['state'] ) && self::is_valid_state( $progress['state'] ) ? $progress['state'] : self::STATE_NONE;
		$has_site = is_array( $progress ) && true === ( $progress['has_native_site'] ?? false );
		$site_ids = $this->get_network_site_id_batch( $cursor );

		if ( null === $site_ids ) {
			update_site_option( self::NETWORK_OPTION_NAME, self::STATE_UNKNOWN );
			return;
		}

		$current_site_id = get_current_blog_id();
		try {
			foreach ( $site_ids as $site_id ) {
				if ( $site_id !== get_current_blog_id() ) {
					switch_to_blog( $site_id );
				}
				try {
					if ( ! $this->is_current_site_native_owned() ) {
						continue;
					}
					$has_site  = true;
					$site_state = self::get_current_site_state();
					if ( self::STATE_PRESENT === $site_state ) {
						$state = self::STATE_PRESENT;
						break;
					}
					if ( self::STATE_UNKNOWN === $site_state ) {
						$state = self::STATE_UNKNOWN;
						$this->schedule_site_discovery( $site_id );
						break;
					}
				} finally {
					if ( get_current_blog_id() !== $current_site_id ) {
						restore_current_blog();
					}
				}
			}
		} finally {
			while ( get_current_blog_id() !== $current_site_id && function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
				restore_current_blog();
			}
		}

		if ( self::STATE_PRESENT === $state || self::STATE_UNKNOWN === $state ) {
			update_site_option( self::NETWORK_OPTION_NAME, $state );
			delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
			return;
		}

		if ( count( $site_ids ) === self::BATCH_SIZE ) {
			$last_site_id = (int) end( $site_ids );
			if ( $last_site_id <= $cursor ) {
				update_site_option( self::NETWORK_OPTION_NAME, self::STATE_UNKNOWN );
				return;
			}
			update_site_option(
				self::NETWORK_DISCOVERY_OPTION_NAME,
				array(
					'cursor'          => $last_site_id,
					'state'           => $state,
					'has_native_site' => $has_site,
				)
			);
			update_site_option( self::NETWORK_OPTION_NAME, self::STATE_UNKNOWN );
			$this->schedule_network_summary();
			return;
		}

		delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
		update_site_option( self::NETWORK_OPTION_NAME, $has_site ? self::STATE_NONE : self::STATE_UNKNOWN );
	}

	/**
	 * Get the current site's durable evidence state.
	 *
	 * A version option is a direct, indexed rollback-evidence record and wins over any stale marker.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public static function get_current_site_state(): string {
		if ( false !== get_option( 'woocommerce_woocommerce_payments_version', false ) ) {
			return self::STATE_PRESENT;
		}

		$state = get_option( self::OPTION_NAME, self::STATE_UNKNOWN );
		return self::is_valid_state( $state ) ? $state : self::STATE_UNKNOWN;
	}

	/**
	 * Get the network-wide evidence state without visiting individual sites.
	 *
	 * @return string One of the STATE_* constants.
	 */
	public static function get_network_state(): string {
		$state = get_site_option( self::NETWORK_OPTION_NAME, self::STATE_UNKNOWN );
		return self::is_valid_state( $state ) ? $state : self::STATE_UNKNOWN;
	}

	/**
	 * Process one current-site discovery page.
	 */
	private function discover_current_site_page(): void {
		$state = self::get_current_site_state();
		if ( self::STATE_PRESENT === $state ) {
			$this->mark_current_site_present();
			return;
		}
		if ( self::STATE_NONE === $state ) {
			return;
		}

		$progress = get_option( self::DISCOVERY_OPTION_NAME, array() );
		$stage    = is_array( $progress ) && isset( $progress['stage'] ) && in_array( $progress['stage'], array( self::STAGE_ORDERS, self::STAGE_TOKENS ), true ) ? $progress['stage'] : self::STAGE_ORDERS;
		$cursor   = is_array( $progress ) && isset( $progress['cursor'] ) && is_int( $progress['cursor'] ) && $progress['cursor'] >= 0 ? $progress['cursor'] : 0;
		$rows     = $this->get_evidence_batch( $stage, $cursor );

		if ( null === $rows ) {
			$this->schedule_site_discovery( get_current_blog_id() );
			return;
		}

		foreach ( $rows as $row ) {
			if ( $this->row_has_woopayments_evidence( $stage, $row ) ) {
				$this->mark_current_site_present();
				return;
			}
		}

		if ( count( $rows ) === self::BATCH_SIZE ) {
			$last_row = end( $rows );
			$last_id  = is_array( $last_row ) && isset( $last_row['id'] ) ? (int) $last_row['id'] : 0;
			if ( $last_id <= $cursor ) {
				return;
			}
			update_option(
				self::DISCOVERY_OPTION_NAME,
				array(
					'stage'  => $stage,
					'cursor' => $last_id,
				),
				false
			);
			$this->schedule_site_discovery( get_current_blog_id() );
			return;
		}

		if ( self::STAGE_ORDERS === $stage ) {
			update_option(
				self::DISCOVERY_OPTION_NAME,
				array(
					'stage'  => self::STAGE_TOKENS,
					'cursor' => 0,
				),
				false
			);
			$this->schedule_site_discovery( get_current_blog_id() );
			return;
		}

		update_option( self::OPTION_NAME, self::STATE_NONE, true );
		delete_option( self::DISCOVERY_OPTION_NAME );
		$this->schedule_network_summary();
	}

	/**
	 * Mark the current site as having durable rollback evidence.
	 */
	private function mark_current_site_present(): void {
		update_option( self::OPTION_NAME, self::STATE_PRESENT, true );
		delete_option( self::DISCOVERY_OPTION_NAME );
		$this->schedule_network_summary();
	}

	/**
	 * Get one primary-key bounded evidence page.
	 *
	 * @param string $stage  Discovery stage.
	 * @param int    $cursor Last inspected primary key.
	 * @return array<int,array<string,mixed>>|null Null when data could not be read safely.
	 */
	private function get_evidence_batch( string $stage, int $cursor ): ?array {
		global $wpdb;

		if ( self::STAGE_TOKENS === $stage ) {
			$table_name = $wpdb->prefix . 'woocommerce_payment_tokens';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress table name.
			$query = $wpdb->prepare(
				"SELECT token_id AS id, gateway_id FROM {$table_name} WHERE token_id > %d ORDER BY token_id ASC LIMIT %d",
				$cursor,
				self::BATCH_SIZE
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$table_name = OrdersTableDataStore::get_orders_table_name();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WooCommerce table name.
			$query = $wpdb->prepare(
				"SELECT id, payment_method FROM {$table_name} WHERE id > %d ORDER BY id ASC LIMIT %d",
				$cursor,
				self::BATCH_SIZE
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$query = $wpdb->prepare(
				"SELECT meta_id AS id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id > %d ORDER BY meta_id ASC LIMIT %d",
				$cursor,
				self::BATCH_SIZE
			);
		}

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with trusted table names.
		return is_array( $rows ) ? $rows : null;
	}

	/**
	 * Tell whether one bounded discovery row records a WooPayments identity.
	 *
	 * @param string               $stage Discovery stage.
	 * @param array<string,mixed> $row   Database row.
	 * @return bool
	 */
	private function row_has_woopayments_evidence( string $stage, array $row ): bool {
		if ( self::STAGE_TOKENS === $stage ) {
			return isset( $row['gateway_id'] ) && is_string( $row['gateway_id'] ) && self::is_woopayments_gateway_id( $row['gateway_id'] );
		}

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return isset( $row['payment_method'] ) && is_string( $row['payment_method'] ) && self::is_woopayments_gateway_id( $row['payment_method'] );
		}

		return '_payment_method' === ( $row['meta_key'] ?? null ) && isset( $row['meta_value'] ) && is_string( $row['meta_value'] ) && self::is_woopayments_gateway_id( $row['meta_value'] );
	}

	/**
	 * Get one bounded page of current-network site IDs.
	 *
	 * @param int $cursor Last inspected site ID.
	 * @return int[]|null Null when the network catalog could not be read safely.
	 */
	private function get_network_site_id_batch( int $cursor ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress sites table name.
		$query = $wpdb->prepare(
			"SELECT blog_id FROM {$wpdb->blogs} WHERE site_id = %d AND blog_id > %d ORDER BY blog_id ASC LIMIT %d",
			get_current_network_id(),
			$cursor,
			self::BATCH_SIZE
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$site_ids = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with a trusted table name.
		if ( ! is_array( $site_ids ) ) {
			return null;
		}

		return array_values( array_filter( array_map( 'intval', $site_ids ) ) );
	}

	/**
	 * Schedule one site-local discovery action.
	 *
	 * @param int $site_id Site ID whose options and data are inspected.
	 */
	private function schedule_site_discovery( int $site_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$args = array( $site_id );
		if ( false === as_has_scheduled_action( self::DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP ) ) {
			as_schedule_single_action( time(), self::DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP, true );
		}
	}

	/** Schedule one bounded network-summary action. */
	private function schedule_network_summary(): void {
		if ( ! is_multisite() || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( false === as_has_scheduled_action( self::NETWORK_DISCOVERY_ACTION_HOOK, array(), self::ACTION_GROUP ) ) {
			as_schedule_single_action( time(), self::NETWORK_DISCOVERY_ACTION_HOOK, array(), self::ACTION_GROUP, true );
		}
	}

	/**
	 * Tell whether native payments own the current site's runtime.
	 *
	 * @return bool True when native payments own the current site.
	 */
	private function is_current_site_native_owned(): bool {
		if ( null === $this->arbiter ) {
			$this->arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		}

		return $this->arbiter->should_native_register();
	}

	/**
	 * Tell whether a value is a valid evidence state.
	 *
	 * @param mixed $state Candidate state.
	 * @return bool
	 */
	private static function is_valid_state( $state ): bool {
		return is_string( $state ) && in_array( $state, array( self::STATE_UNKNOWN, self::STATE_PRESENT, self::STATE_NONE ), true );
	}

	/**
	 * Tell whether a gateway ID is canonical WooPayments or a supported WooPayments variant.
	 *
	 * @param string $gateway_id Gateway identity.
	 * @return bool
	 */
	private static function is_woopayments_gateway_id( string $gateway_id ): bool {
		return self::GATEWAY_ID === $gateway_id || 0 === strpos( $gateway_id, self::GATEWAY_PREFIX );
	}
}
