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
		$this->register_maintenance_callbacks();
		add_action( 'woocommerce_new_order', array( $this, 'observe_order' ), 10, 2 );
		add_action( 'woocommerce_update_order', array( $this, 'observe_order' ), 10, 2 );
		add_action( 'woocommerce_new_payment_token', array( $this, 'observe_payment_token' ), 10, 2 );
		add_action( 'woocommerce_payment_token_object_updated_props', array( $this, 'observe_payment_token_update' ), 10, 2 );
	}

	/**
	 * Register only the Action Scheduler discovery callbacks.
	 *
	 * This small registration surface lets the WP-CLI Action Scheduler runner discover maintenance work without loading native payment roots.
	 *
	 * @internal
	 */
	public function register_maintenance_callbacks(): void {
		add_action( self::DISCOVERY_ACTION_HOOK, array( $this, 'discover_current_site' ), 10, 3 );
		add_action( self::NETWORK_DISCOVERY_ACTION_HOOK, array( $this, 'refresh_network_summary' ), 10, 3 );
		add_action( 'action_scheduler_init', array( $this, 'handle_action_scheduler_init' ) );

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
	 * @param mixed $site_id    Optional site ID supplied by Action Scheduler.
	 * @param mixed $generation Durable progress generation supplied by Action Scheduler.
	 * @param mixed $cursor     Durable progress cursor supplied by Action Scheduler.
	 */
	public function discover_current_site( $site_id = null, $generation = null, $cursor = null ): void {
		$target_site_id = null;
		if ( null !== $site_id ) {
			if ( ! is_int( $site_id ) || $site_id < 1 ) {
				return;
			}
			$target_site_id = $site_id;
		}
		if ( ( null === $generation ) !== ( null === $cursor ) || ( null !== $generation && ( ! is_int( $generation ) || $generation < 0 || ! is_int( $cursor ) || $cursor < 0 ) ) ) {
			return;
		}

		$current_site_id = get_current_blog_id();
		if ( null !== $target_site_id && $target_site_id !== $current_site_id ) {
			switch_to_blog( $target_site_id );
		}

		try {
			$this->discover_current_site_page( $generation, $cursor );
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
	 *
	 * @param mixed $generation   Network generation supplied by Action Scheduler.
	 * @param mixed $continuation Network continuation supplied by Action Scheduler.
	 * @param mixed $cursor       Network cursor supplied by Action Scheduler.
	 */
	public function refresh_network_summary( $generation = null, $continuation = null, $cursor = null ): void {
		if ( ! is_multisite() ) {
			return;
		}
		if ( ( null === $generation ) !== ( null === $continuation ) || ( null === $generation ) !== ( null === $cursor ) || ( null !== $generation && ( ! is_int( $generation ) || $generation < 0 || ! is_int( $continuation ) || $continuation < 0 || ! is_int( $cursor ) || $cursor < 0 ) ) ) {
			return;
		}

		$record   = self::get_network_record();
		$progress = $this->get_network_progress( $record['generation'] );
		if ( null !== $generation && ( $generation !== $record['generation'] || $generation !== $progress['generation'] || $continuation !== $progress['continuation'] || $cursor !== $progress['cursor'] ) ) {
			return;
		}

		$this->refresh_network_summary_page( $record, $progress );
	}

	/**
	 * Process one bounded network-summary page against one durable generation.
	 *
	 * @param array{state:string,generation:int,raw:mixed}                                        $record   Current summary record.
	 * @param array{cursor:int,state:string,has_native_site:bool,generation:int,continuation:int} $progress Current page progress.
	 */
	private function refresh_network_summary_page( array $record, array $progress ): void {
		$site_ids = $this->get_network_site_id_batch( $progress['cursor'] );
		if ( null === $site_ids ) {
			$this->advance_network_progress( $record, $progress, $progress['cursor'], $progress['state'], $progress['has_native_site'] );
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
					$progress['has_native_site'] = true;
					$site_state                  = self::get_current_site_state();
					if ( self::STATE_PRESENT === $site_state ) {
						$progress['state'] = self::STATE_PRESENT;
						break;
					}
					if ( self::STATE_UNKNOWN === $site_state ) {
						$progress['state'] = self::STATE_UNKNOWN;
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

		if ( self::STATE_PRESENT === $progress['state'] || self::STATE_UNKNOWN === $progress['state'] ) {
			if ( $this->commit_network_state( $record, $progress['state'] ) ) {
				delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
			}
			return;
		}

		if ( count( $site_ids ) === self::BATCH_SIZE ) {
			$last_site_id = (int) end( $site_ids );
			$this->advance_network_progress( $record, $progress, $last_site_id > $progress['cursor'] ? $last_site_id : $progress['cursor'], $progress['state'], $progress['has_native_site'] );
			return;
		}

		if ( $this->commit_network_state( $record, $progress['has_native_site'] ? self::STATE_NONE : self::STATE_UNKNOWN ) ) {
			delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
		}
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
		return self::get_network_record()['state'];
	}

	/**
	 * Process one current-site discovery page.
	 *
	 * @param int|null $scheduled_generation Generation supplied by the scheduled action.
	 * @param int|null $scheduled_cursor     Cursor supplied by the scheduled action.
	 */
	private function discover_current_site_page( ?int $scheduled_generation, ?int $scheduled_cursor ): void {
		$state = self::get_current_site_state();
		if ( self::STATE_PRESENT === $state ) {
			$this->mark_current_site_present();
			return;
		}
		if ( self::STATE_NONE === $state ) {
			return;
		}

		$progress = $this->get_current_site_progress();
		if ( null !== $scheduled_generation && ( $scheduled_generation !== $progress['generation'] || $scheduled_cursor !== $progress['cursor'] ) ) {
			return;
		}

		$rows = $this->get_evidence_batch( $progress['stage'], $progress['cursor'] );
		if ( null === $rows ) {
			$this->advance_current_site_progress( $progress, $progress['stage'], $progress['cursor'] );
			return;
		}

		foreach ( $rows as $row ) {
			if ( $this->row_has_woopayments_evidence( $progress['stage'], $row ) ) {
				$this->mark_current_site_present();
				return;
			}
		}

		if ( count( $rows ) === self::BATCH_SIZE ) {
			$last_row = end( $rows );
			$last_id  = is_array( $last_row ) && isset( $last_row['id'] ) ? (int) $last_row['id'] : 0;
			$this->advance_current_site_progress( $progress, $progress['stage'], $last_id > $progress['cursor'] ? $last_id : $progress['cursor'] );
			return;
		}

		if ( self::STAGE_ORDERS === $progress['stage'] ) {
			$this->advance_current_site_progress( $progress, self::STAGE_TOKENS, 0 );
			return;
		}

		if ( $this->compare_and_swap_current_site_state( self::STATE_UNKNOWN, self::STATE_NONE ) ) {
			delete_option( self::DISCOVERY_OPTION_NAME );
			$this->schedule_network_summary();
		}
	}

	/**
	 * Mark the current site as having durable rollback evidence.
	 */
	private function mark_current_site_present(): void {
		update_option( self::OPTION_NAME, self::STATE_PRESENT, true );
		delete_option( self::DISCOVERY_OPTION_NAME );
		$this->invalidate_network_summary();
		$this->schedule_network_summary();
	}

	/**
	 * Get normalized durable progress for the current site.
	 *
	 * @return array{stage:string,cursor:int,generation:int} Current site progress.
	 */
	private function get_current_site_progress(): array {
		$progress = get_option( self::DISCOVERY_OPTION_NAME, array() );

		return array(
			'stage'      => is_array( $progress ) && isset( $progress['stage'] ) && in_array( $progress['stage'], array( self::STAGE_ORDERS, self::STAGE_TOKENS ), true ) ? $progress['stage'] : self::STAGE_ORDERS,
			'cursor'     => is_array( $progress ) && isset( $progress['cursor'] ) && is_int( $progress['cursor'] ) && $progress['cursor'] >= 0 ? $progress['cursor'] : 0,
			'generation' => is_array( $progress ) && isset( $progress['generation'] ) && is_int( $progress['generation'] ) && $progress['generation'] >= 0 ? $progress['generation'] : 0,
		);
	}

	/**
	 * Persist and enqueue the next distinct current-site discovery page.
	 *
	 * @param array{stage:string,cursor:int,generation:int} $progress Current progress.
	 * @param string                                        $stage    Next discovery stage.
	 * @param int                                           $cursor   Next primary-key cursor.
	 */
	private function advance_current_site_progress( array $progress, string $stage, int $cursor ): void {
		$next_progress = array(
			'stage'      => $stage,
			'cursor'     => $cursor,
			'generation' => $progress['generation'] + 1,
		);
		update_option( self::DISCOVERY_OPTION_NAME, $next_progress, false );
		$this->schedule_site_discovery( get_current_blog_id(), $next_progress );
	}

	/**
	 * Atomically transition one unknown current-site marker to the conclusive none marker.
	 *
	 * @param string $expected Expected current state.
	 * @param string $next     Replacement current state.
	 * @return bool Whether this callback made the transition.
	 */
	private function compare_and_swap_current_site_state( string $expected, string $next ): bool {
		global $wpdb;

		if ( self::STATE_UNKNOWN !== $expected || ! self::is_valid_state( $next ) ) {
			return false;
		}

		if ( false === get_option( self::OPTION_NAME, false ) ) {
			return add_option( self::OPTION_NAME, $next, '', true );
		}

		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $next ),
			array(
				'option_name'  => self::OPTION_NAME,
				'option_value' => $expected,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);
		if ( 1 !== $updated ) {
			return false;
		}

		wp_cache_delete( self::OPTION_NAME, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return true;
	}

	/**
	 * Get the durable network state and its generation fence.
	 *
	 * @return array{state:string,generation:int,raw:mixed} Network summary record.
	 */
	private static function get_network_record(): array {
		$raw = get_site_option( self::NETWORK_OPTION_NAME, false );
		if ( is_array( $raw ) && isset( $raw['state'], $raw['generation'] ) && self::is_valid_state( $raw['state'] ) && is_int( $raw['generation'] ) && $raw['generation'] >= 0 ) {
			return array(
				'state'      => $raw['state'],
				'generation' => $raw['generation'],
				'raw'        => $raw,
			);
		}

		return array(
			'state'      => self::is_valid_state( $raw ) ? $raw : self::STATE_UNKNOWN,
			'generation' => 0,
			'raw'        => $raw,
		);
	}

	/**
	 * Get normalized current-network discovery progress for one generation.
	 *
	 * @param int $generation Current network generation.
	 * @return array{cursor:int,state:string,has_native_site:bool,generation:int,continuation:int} Network progress.
	 */
	private function get_network_progress( int $generation ): array {
		$progress = get_site_option( self::NETWORK_DISCOVERY_OPTION_NAME, array() );
		if ( ! is_array( $progress ) || ! isset( $progress['generation'] ) || ! is_int( $progress['generation'] ) || $progress['generation'] !== $generation ) {
			return array(
				'cursor'          => 0,
				'state'           => self::STATE_NONE,
				'has_native_site' => false,
				'generation'      => $generation,
				'continuation'    => 0,
			);
		}

		return array(
			'cursor'          => isset( $progress['cursor'] ) && is_int( $progress['cursor'] ) && $progress['cursor'] >= 0 ? $progress['cursor'] : 0,
			'state'           => isset( $progress['state'] ) && self::is_valid_state( $progress['state'] ) ? $progress['state'] : self::STATE_NONE,
			'has_native_site' => true === ( $progress['has_native_site'] ?? false ),
			'generation'      => $generation,
			'continuation'    => isset( $progress['continuation'] ) && is_int( $progress['continuation'] ) && $progress['continuation'] >= 0 ? $progress['continuation'] : 0,
		);
	}

	/**
	 * Persist and enqueue the next distinct network-summary page.
	 *
	 * @param array{state:string,generation:int,raw:mixed}                                        $record   Current summary record.
	 * @param array{cursor:int,state:string,has_native_site:bool,generation:int,continuation:int} $progress Current progress.
	 * @param int                                                                                 $cursor   Next network cursor.
	 * @param string                                                                              $state    Current aggregate state.
	 * @param bool                                                                                $has_site Whether a native-owned site was found.
	 */
	private function advance_network_progress( array $record, array $progress, int $cursor, string $state, bool $has_site ): void {
		$next_progress = array(
			'cursor'          => $cursor,
			'state'           => $state,
			'has_native_site' => $has_site,
			'generation'      => $record['generation'],
			'continuation'    => $progress['continuation'] + 1,
		);
		update_site_option( self::NETWORK_DISCOVERY_OPTION_NAME, $next_progress );
		if ( $this->commit_network_state( $record, self::STATE_UNKNOWN ) ) {
			$this->schedule_network_summary( self::get_network_record(), $next_progress );
		}
	}

	/**
	 * Commit a network state only if the callback still owns its original generation record.
	 *
	 * @param array{state:string,generation:int,raw:mixed} $record Expected current summary record.
	 * @param string                                       $state  State to store.
	 * @return bool Whether the state was committed or was already current.
	 */
	private function commit_network_state( array $record, string $state ): bool {
		return $this->replace_network_record(
			$record,
			array(
				'state'      => $state,
				'generation' => $record['generation'],
			)
		);
	}

	/**
	 * Invalidate a possibly blocking network summary before a present write returns.
	 */
	private function invalidate_network_summary(): void {
		if ( ! is_multisite() ) {
			return;
		}

		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$record = self::get_network_record();
			if ( $this->replace_network_record(
				$record,
				array(
					'state'      => self::STATE_UNKNOWN,
					'generation' => $record['generation'] + 1,
				)
			) ) {
				delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
				return;
			}
		}

		$record = self::get_network_record();
		update_site_option(
			self::NETWORK_OPTION_NAME,
			array(
				'state'      => self::STATE_UNKNOWN,
				'generation' => $record['generation'] + 1,
			)
		);
		delete_site_option( self::NETWORK_DISCOVERY_OPTION_NAME );
	}

	/**
	 * Compare and replace one network record without allowing a stale finalizer to overwrite a newer generation.
	 *
	 * @param array{state:string,generation:int,raw:mixed} $record Expected summary record.
	 * @param array{state:string,generation:int}           $next   Replacement summary record.
	 * @return bool Whether the replacement was committed or was already current.
	 */
	private function replace_network_record( array $record, array $next ): bool {
		global $wpdb;

		if ( ! self::is_valid_state( $next['state'] ) || $next['generation'] < 0 ) {
			return false;
		}
		if ( is_array( $record['raw'] ) && $record['raw'] === $next ) {
			return true;
		}
		if ( false === $record['raw'] ) {
			return add_site_option( self::NETWORK_OPTION_NAME, $next );
		}

		$network_id = get_current_network_id();
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- This is an atomic compare-and-set of one network option, constrained by the site ID and option key.
		$updated = $wpdb->update(
			$wpdb->sitemeta,
			array( 'meta_value' => maybe_serialize( $next ) ),
			array(
				'site_id'    => $network_id,
				'meta_key'   => self::NETWORK_OPTION_NAME,
				'meta_value' => maybe_serialize( $record['raw'] ),
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		if ( 1 !== $updated ) {
			return false;
		}

		wp_cache_delete( $network_id . ':' . self::NETWORK_OPTION_NAME, 'site-options' );
		return true;
	}

	/**
	 * Get one primary-key bounded evidence page.
	 *
	 * @param string $stage  Discovery stage.
	 * @param int    $cursor Last inspected primary key.
	 * @return array<int,array<string,mixed>>|null Null when data could not be read safely.
	 */
	protected function get_evidence_batch( string $stage, int $cursor ): ?array {
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
	 * @param string              $stage Discovery stage.
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
	 * @param int                                                $site_id Site ID whose options and data are inspected.
	 * @param array{stage:string,cursor:int,generation:int}|null $progress Current durable progress.
	 */
	private function schedule_site_discovery( int $site_id, ?array $progress = null ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$progress = $progress ?? $this->get_current_site_progress();
		$args     = array( $site_id, $progress['generation'], $progress['cursor'] );
		if ( false === as_has_scheduled_action( self::DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP ) ) {
			as_schedule_single_action( time(), self::DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP, true );
		}
	}

	/**
	 * Schedule one bounded network-summary action.
	 *
	 * @param array{state:string,generation:int,raw:mixed}|null                                        $record Current summary record.
	 * @param array{cursor:int,state:string,has_native_site:bool,generation:int,continuation:int}|null $progress Current page progress.
	 */
	private function schedule_network_summary( ?array $record = null, ?array $progress = null ): void {
		if ( ! is_multisite() || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$record   = $record ?? self::get_network_record();
		$progress = $progress ?? $this->get_network_progress( $record['generation'] );
		$args     = array( $record['generation'], $progress['continuation'], $progress['cursor'] );
		if ( false === as_has_scheduled_action( self::NETWORK_DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP ) ) {
			as_schedule_single_action( time(), self::NETWORK_DISCOVERY_ACTION_HOOK, $args, self::ACTION_GROUP, true );
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
