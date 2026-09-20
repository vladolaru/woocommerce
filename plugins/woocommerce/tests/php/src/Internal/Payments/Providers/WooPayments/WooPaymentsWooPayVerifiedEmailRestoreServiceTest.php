<?php
/**
 * Tests for the WooPayments WooPay verified-email restore service.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Caches\OrderCache;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWooPayVerifiedEmailRestoreService;
use Automattic\WooCommerce\RestApi\UnitTests\HPOSToggleTrait;
use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayVerifiedEmailRestoreService class.
 */
class WooPaymentsWooPayVerifiedEmailRestoreServiceTest extends WC_Unit_Test_Case {

	use HPOSToggleTrait;

	private const ENABLED_OPTION     = 'woocommerce_native_payments_enabled';
	private const KILL_SWITCH_OPTION = 'woocommerce_native_payments_killswitch';
	private const MARKER_META        = 'woopay_merchant_customer_id';
	private const RESTORE_HOOK       = 'woopay_restore_order_customer_id';
	private const ORDER_CRUD_READY   = 'woocommerce_after_register_post_type';
	private const UNRELATED_OPTION   = 'woocommerce_verified_email_restore_unrelated';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsWooPayVerifiedEmailRestoreService|null
	 */
	private $sut;

	/**
	 * Real runtime arbiter supplied to the System Under Test.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private $arbiter;

	/**
	 * Whether the plugin should be detected as active.
	 *
	 * @var bool
	 */
	private $plugin_active = false;

	/**
	 * Optional final override for the real arbiter's native-enabled filter.
	 *
	 * @var bool|null
	 */
	private $native_enabled_override;

	/**
	 * Original site-option values.
	 *
	 * @var array<string,array{exists:bool,value:mixed}>
	 */
	private $original_options = array();

	/**
	 * Original network-active plugin option.
	 *
	 * @var array{exists:bool,value:mixed}
	 */
	private $original_network_plugins;

	/**
	 * Original restore-hook schedule entries.
	 *
	 * @var array<int|string,array<string,array<string,array<string,mixed>>>>
	 */
	private $original_restore_schedule = array();

	/**
	 * Original request-local arbiter cache.
	 *
	 * @var array<int,string>
	 */
	private $original_arbiter_cache = array();

	/**
	 * Original order-storage authority.
	 *
	 * @var bool
	 */
	private $original_hpos_enabled;

	/**
	 * Blog active when the test began.
	 *
	 * @var int
	 */
	private $original_blog_id;

	/**
	 * Orders created by each blog.
	 *
	 * @var array<int,int[]>
	 */
	private $created_order_ids = array();

	/**
	 * Ensure permanent HPOS tables exist before per-test transactions start.
	 */
	public static function wpSetUpBeforeClass(): void {
		self::setup_cot_tables();
	}

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->native_enabled_override   = null;
		$this->original_blog_id          = get_current_blog_id();
		$this->original_hpos_enabled     = OrderUtil::custom_orders_table_usage_is_enabled();
		$this->original_options          = array(
			self::ENABLED_OPTION     => $this->snapshot_option( self::ENABLED_OPTION ),
			self::KILL_SWITCH_OPTION => $this->snapshot_option( self::KILL_SWITCH_OPTION ),
			self::UNRELATED_OPTION   => $this->snapshot_option( self::UNRELATED_OPTION ),
			'active_plugins'         => $this->snapshot_option( 'active_plugins' ),
		);
		$this->original_network_plugins  = $this->snapshot_site_option( 'active_sitewide_plugins' );
		$this->original_restore_schedule = $this->snapshot_restore_schedule();
		$this->original_arbiter_cache    = $this->snapshot_arbiter_cache();

		$this->clear_restore_schedule();
		delete_option( self::ENABLED_OPTION );
		delete_option( self::KILL_SWITCH_OPTION );
		delete_option( self::UNRELATED_OPTION );
		add_filter( 'wc_allow_changing_orders_storage_while_sync_is_pending', '__return_true' );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, array( $this, 'override_native_runtime_enabled' ), PHP_INT_MAX );

		$this->arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		$this->control_plugin_detection();
		$this->arbiter->invalidate();
		$this->sut = new WooPaymentsWooPayVerifiedEmailRestoreService();
		$this->sut->init( $this->arbiter );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		try {
			$this->remove_service_hooks();
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, array( $this, 'override_native_runtime_enabled' ), PHP_INT_MAX );

			if ( is_multisite() && function_exists( 'ms_is_switched' ) ) {
				while ( ms_is_switched() ) {
					restore_current_blog();
				}
			}

			$this->delete_created_orders();
			$this->restore_option( self::ENABLED_OPTION, $this->original_options[ self::ENABLED_OPTION ] );
			$this->restore_option( self::KILL_SWITCH_OPTION, $this->original_options[ self::KILL_SWITCH_OPTION ] );
			$this->restore_option( self::UNRELATED_OPTION, $this->original_options[ self::UNRELATED_OPTION ] );
			$this->restore_option( 'active_plugins', $this->original_options['active_plugins'] );
			$this->restore_site_option( 'active_sitewide_plugins', $this->original_network_plugins );
			$this->restore_restore_schedule( $this->original_restore_schedule );
			$this->restore_arbiter_cache( $this->original_arbiter_cache );
			$this->clean_up_cot_setup();
			$this->toggle_cot_authoritative( $this->original_hpos_enabled );
			remove_all_filters( 'wc_allow_changing_orders_storage_while_sync_is_pending' );
			$this->reset_legacy_proxy_mocks();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox The restore hook is registered without querying orders for every runtime owner.
	 * @dataProvider runtime_owner_provider
	 *
	 * @param string $runtime_owner Runtime owner.
	 */
	public function test_registers_restore_hook_for_every_runtime_owner( string $runtime_owner ): void {
		$this->configure_runtime_owner( $runtime_owner );
		$this->assertSame( $runtime_owner, $this->arbiter->get_runtime_owner(), 'The fixture must establish the requested owner.' );
		$this->arbiter->invalidate();
		$query_count = 0;
		add_filter(
			'woocommerce_order_query_args',
			static function ( array $query_args ) use ( &$query_count ): array {
				++$query_count;
				return $query_args;
			}
		);

		$this->sut->register();
		$this->sut->register();

		$this->assert_arbiter_is_cold();
		$this->assertSame( 10, has_action( self::RESTORE_HOOK, array( $this->sut, 'restore_order_customer_id' ) ), 'The recovery callback must remain registered under every owner.' );
		$this->assertSame( 1, $this->count_service_callbacks( self::RESTORE_HOOK, 'restore_order_customer_id' ), 'Repeated registration must leave exactly one restore callback.' );
		$this->assertSame( 0, $query_count, 'Registration must not query orders.' );
	}

	/**
	 * @testdox Registration attaches exactly the six option-specific post-write actions idempotently.
	 */
	public function test_registers_only_option_specific_hooks_idempotently(): void {
		$this->sut->register();
		$this->sut->register();

		$expected_callback_counts = array();
		foreach ( array( self::ENABLED_OPTION, self::KILL_SWITCH_OPTION ) as $option_name ) {
			foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
				$expected_callback_counts[ "{$operation}_option_{$option_name}" ] = 1;
			}
			$expected_callback_counts[ "sanitize_option_{$option_name}" ] = 0;
		}
		foreach ( array( 'add_option', 'added_option', 'update_option', 'updated_option', 'delete_option', 'deleted_option' ) as $generic_hook ) {
			$expected_callback_counts[ $generic_hook ] = 0;
		}

		$actual_callback_counts = array();
		foreach ( array_keys( $expected_callback_counts ) as $hook ) {
			$actual_callback_counts[ $hook ] = $this->count_service_callbacks_on_hook( $hook );
		}

		$this->assertSame( $expected_callback_counts, $actual_callback_counts, 'Only the six option-specific post-write actions may contain service callbacks.' );
	}

	/**
	 * Apply the test's optional final native-enabled result.
	 *
	 * @param mixed $enabled Native-enabled state resolved before this test filter.
	 * @return bool
	 */
	public function override_native_runtime_enabled( $enabled ): bool {
		return null === $this->native_enabled_override ? (bool) $enabled : $this->native_enabled_override;
	}

	/**
	 * Provide every runtime owner.
	 *
	 * @return array<string,array{string}>
	 */
	public function runtime_owner_provider(): array {
		return array(
			'native owner' => array( NativePaymentsRuntimeArbiter::OWNER_NATIVE ),
			'no owner'     => array( NativePaymentsRuntimeArbiter::OWNER_NONE ),
			'plugin owner' => array( NativePaymentsRuntimeArbiter::OWNER_PLUGIN ),
		);
	}

	/**
	 * @testdox A drain restores every marked order and leaves an unmarked control unchanged in either order store.
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $hpos_enabled Whether HPOS is authoritative.
	 */
	public function test_drain_restores_all_marked_orders_in_each_order_store( bool $hpos_enabled ): void {
		$this->toggle_cot_authoritative( $hpos_enabled );
		$this->sut->register();

		$first_customer_id   = self::factory()->user->create();
		$second_customer_id  = self::factory()->user->create();
		$third_customer_id   = self::factory()->user->create();
		$control_customer_id = self::factory()->user->create();
		$first_order_id      = $this->create_order( 0, $first_customer_id, true, true );
		$second_order_id     = $this->create_order( 0, (string) $second_customer_id, true, true );
		$third_order_id      = $this->create_order( 0, $third_customer_id, true, true );
		$control_order_id    = $this->create_order( $control_customer_id );

		$this->sut->drain_current_blog();

		$this->assert_order_state( $first_order_id, $first_customer_id, false );
		$this->assert_order_state( $second_order_id, $second_customer_id, false );
		$this->assert_order_state( $third_order_id, $third_customer_id, false );
		$this->assert_order_state( $control_order_id, $control_customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $first_order_id ) ) );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_order_id ) ) );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $third_order_id ) ) );
	}

	/**
	 * @testdox Restoring one order twice converges on the stored customer and an absent marker.
	 */
	public function test_restore_order_customer_id_is_idempotent(): void {
		$this->sut->register();
		$customer_id = self::factory()->user->create();
		$order_id    = $this->create_order( 0, $customer_id, true, false );
		$this->assert_order_state( $order_id, 0, true, $customer_id );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ), 'The one-order fixture must carry a marker without a schedule.' );

		do_action( self::RESTORE_HOOK, $order_id );
		do_action( self::RESTORE_HOOK, $order_id );

		$this->assert_order_state( $order_id, $customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ) );
	}

	/**
	 * @testdox Draining the current blog twice leaves the same final state and no schedules.
	 */
	public function test_drain_current_blog_is_idempotent(): void {
		$this->sut->register();
		$customer_id = self::factory()->user->create();
		$order_id    = $this->create_order( 0, $customer_id, true, true );

		$this->sut->drain_current_blog();
		$this->sut->drain_current_blog();

		$this->assert_order_state( $order_id, $customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ) );
	}

	/**
	 * @testdox A schedule created before the clear is gone before scanning while its marker is drained.
	 */
	public function test_drain_clears_existing_schedule_before_scanning_orders(): void {
		$this->sut->register();
		$customer_id = self::factory()->user->create();
		$order_id    = $this->create_order( 0, $customer_id, true, true );
		$observed    = false;
		add_filter(
			'woocommerce_order_query_args',
			function ( array $query_args ) use ( $order_id, &$observed ): array {
				if ( ! $observed && self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
					$observed = true;
					$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ), 'The schedule must be cleared before the first marked-order query.' );
				}
				return $query_args;
			}
		);

		$this->sut->drain_current_blog();

		$this->assertTrue( $observed, 'The test must observe the marked-order scan.' );
		$this->assert_order_state( $order_id, $customer_id, false );
	}

	/**
	 * @testdox A detach saved at the terminal scan boundary keeps its event and is restored by the permanent callback.
	 */
	public function test_detach_saved_after_scan_is_restored_by_late_event(): void {
		$this->sut->register();
		$seed_customer_id = self::factory()->user->create();
		$seed_order_id    = $this->create_order( 0, $seed_customer_id, true, true );
		$late_customer_id = self::factory()->user->create();
		$late_order_id    = 0;
		add_filter(
			'woocommerce_order_query',
			function ( $results, array $query_args ) use ( $late_customer_id, &$late_order_id ) {
				if ( 0 === $late_order_id && self::MARKER_META === ( $query_args['meta_key'] ?? null ) && empty( $results ) ) {
					$late_order_id = $this->create_order( 0, $late_customer_id, true, true );
				}
				return $results;
			},
			10,
			2
		);

		$this->sut->drain_current_blog();

		$this->assertGreaterThan( 0, $late_order_id, 'The late detach must be injected after the terminal order query has returned.' );
		$this->assert_order_state( $seed_order_id, $seed_customer_id, false );
		$this->assert_order_state( $late_order_id, 0, true, $late_customer_id );
		$timestamp = wp_next_scheduled( self::RESTORE_HOOK, array( $late_order_id ) );
		$this->assertNotFalse( $timestamp, 'A detach saved after the scan must retain its recovery event.' );
		wp_unschedule_event( $timestamp, self::RESTORE_HOOK, array( $late_order_id ) );
		do_action( self::RESTORE_HOOK, $late_order_id );

		$this->assert_order_state( $late_order_id, $late_customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $late_order_id ) ) );
	}

	/**
	 * @testdox Cron restoring an order during the scan converges without overwriting its customer.
	 */
	public function test_cron_restore_during_scan_converges(): void {
		$this->sut->register();
		$customer_id          = self::factory()->user->create();
		$order_id             = $this->create_order( 0, $customer_id, true, true );
		$restored_during_scan = false;
		add_filter(
			'woocommerce_order_query_args',
			function ( array $query_args ) use ( $order_id, &$restored_during_scan ): array {
				if ( ! $restored_during_scan && self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
					$restored_during_scan = true;
					do_action( self::RESTORE_HOOK, $order_id );
				}
				return $query_args;
			}
		);

		$this->sut->drain_current_blog();

		$this->assertTrue( $restored_during_scan, 'The cron callback must run during the marked-order scan.' );
		$this->assert_order_state( $order_id, $customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ) );
	}

	/**
	 * @testdox Owner loss before order CRUD is ready preserves recovery state and defers one current-blog drain.
	 */
	public function test_owner_loss_before_order_crud_ready_defers_current_blog_drain(): void {
		global $wp_actions;

		update_option( self::ENABLED_OPTION, 'yes' );
		$first_fixture  = $this->create_marked_order_for_owner_transition();
		$second_fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		$had_ready_action_count = is_array( $wp_actions ) && array_key_exists( self::ORDER_CRUD_READY, $wp_actions );
		$ready_action_count     = $had_ready_action_count ? $wp_actions[ self::ORDER_CRUD_READY ] : null;
		$order_query_count      = 0;
		$count_order_queries    = static function ( array $query_args ) use ( &$order_query_count ): array {
			if ( self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
				++$order_query_count;
			}
			return $query_args;
		};
		$prevent_early_results  = static function ( $results, array $query_args ) {
			if ( ! did_action( self::ORDER_CRUD_READY ) && self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
				return array();
			}
			return $results;
		};

		unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate an option transition before order CRUD is ready.
		add_filter( 'woocommerce_order_query_args', $count_order_queries );
		add_filter( 'woocommerce_order_query', $prevent_early_results, PHP_INT_MAX, 2 );

		try {
			update_option( self::ENABLED_OPTION, 'no' );
			update_option( self::ENABLED_OPTION, 'yes' );
			update_option( self::ENABLED_OPTION, 'no' );

			$queries_before_ready = $order_query_count;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Mark only the readiness state without firing unrelated global lifecycle callbacks.
			$wp_actions[ self::ORDER_CRUD_READY ] = 1;

			$this->assertSame( 0, $queries_before_ready, 'Owner loss before order CRUD readiness must not query orders.' );
			$this->assert_order_state( $first_fixture['order_id'], 0, true, $first_fixture['customer_id'] );
			$this->assert_order_state( $second_fixture['order_id'], 0, true, $second_fixture['customer_id'] );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $first_fixture['order_id'] ) ), 'The first argument-bearing schedule must survive until the deferred drain.' );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_fixture['order_id'] ) ), 'The second argument-bearing schedule must survive until the deferred drain.' );
			$this->assertSame( 1, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'Repeated early owner loss must register exactly one deferred-ready callback.' );

			$this->sut->drain_current_blog();

			$this->assertSame( 0, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The completed deferred drain must remove its readiness callback.' );
			$this->assert_order_state( $first_fixture['order_id'], $first_fixture['customer_id'], false );
			$this->assert_order_state( $second_fixture['order_id'], $second_fixture['customer_id'], false );
			$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $first_fixture['order_id'] ) ) );
			$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_fixture['order_id'] ) ) );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $count_order_queries );
			remove_filter( 'woocommerce_order_query', $prevent_early_results, PHP_INT_MAX );
			if ( $had_ready_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact pre-test lifecycle count.
				$wp_actions[ self::ORDER_CRUD_READY ] = $ready_action_count;
			} else {
				unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore an originally absent lifecycle count.
			}
		}
	}

	/**
	 * @testdox A deferred drain rechecks ownership and preserves recovery state when native ownership returns before order CRUD readiness.
	 */
	public function test_deferred_drain_rechecks_owner_when_order_crud_becomes_ready(): void {
		global $wp_actions;

		update_option( self::ENABLED_OPTION, 'no' );
		delete_option( self::KILL_SWITCH_OPTION );
		$this->arbiter->invalidate();
		$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NONE, $this->arbiter->get_runtime_owner(), 'The fixture must begin with no runtime owner.' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		$had_ready_action_count = is_array( $wp_actions ) && array_key_exists( self::ORDER_CRUD_READY, $wp_actions );
		$ready_action_count     = $had_ready_action_count ? $wp_actions[ self::ORDER_CRUD_READY ] : null;
		$order_query_count      = 0;
		$count_order_queries    = static function ( array $query_args ) use ( &$order_query_count ): array {
			if ( self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
				++$order_query_count;
			}
			return $query_args;
		};

		unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate an option transition before order CRUD is ready.
		add_filter( 'woocommerce_order_query_args', $count_order_queries );

		try {
			update_option( self::ENABLED_OPTION, '0' );

			$this->assertSame( 0, $order_query_count, 'The initial owner-none write must defer without querying marked orders.' );
			$this->assertSame( 1, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The owner-none write must queue one same-request readiness drain.' );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $fixture['order_id'] ) ), 'The per-order recovery event must remain scheduled while readiness is deferred.' );

			update_option( self::ENABLED_OPTION, 'yes' );

			$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NATIVE );
			$this->assertSame( 1, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The queued callback must still be observable before its guarded readiness entry.' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Enter only the queued service callback without firing unrelated global lifecycle callbacks.
			$wp_actions[ self::ORDER_CRUD_READY ] = 1;
			$this->sut->drain_current_blog();

			$this->assertSame( 0, $order_query_count, 'A deferred drain must not query marked orders after ownership returns to native.' );
			$this->assert_order_state( $fixture['order_id'], 0, true, $fixture['customer_id'] );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $fixture['order_id'] ) ), 'The guarded deferred drain must preserve the per-order recovery event.' );
			$this->assertSame( 0, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The guarded readiness entry must remove its completed deferred callback.' );
		} finally {
			remove_filter( 'woocommerce_order_query_args', $count_order_queries );
			if ( $had_ready_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact pre-test lifecycle count.
				$wp_actions[ self::ORDER_CRUD_READY ] = $ready_action_count;
			} else {
				unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore an originally absent lifecycle count.
			}
		}
	}

	/**
	 * @testdox Enabling the kill switch drains when native ownership is lost.
	 */
	public function test_kill_switch_update_drains_on_native_owner_loss(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		update_option( self::KILL_SWITCH_OPTION, '0' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		update_option( self::KILL_SWITCH_OPTION, '1' );

		$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Disabling the native-enabled option drains when ownership becomes none.
	 */
	public function test_native_enabled_option_update_drains_on_owner_loss(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		update_option( self::ENABLED_OPTION, 'no' );

		$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Deleting the native-enabled option drains when ownership becomes none.
	 */
	public function test_native_enabled_option_delete_drains_on_owner_loss(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		delete_option( self::ENABLED_OPTION );

		$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Adding an active kill switch drains when ownership becomes none.
	 */
	public function test_kill_switch_option_add_drains_on_owner_loss(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		delete_option( self::KILL_SWITCH_OPTION );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		add_option( self::KILL_SWITCH_OPTION, '1' );

		$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox A relevant $operation action recomputes the filtered post-write owner.
	 * @dataProvider stateful_filter_post_write_provider
	 *
	 * @param string $operation Relevant option operation.
	 */
	public function test_relevant_option_action_recomputes_stateful_post_write_owner( string $operation ): void {
		update_option( self::ENABLED_OPTION, 'no' );
		if ( 'add' === $operation ) {
			delete_option( self::KILL_SWITCH_OPTION );
		} else {
			update_option( self::KILL_SWITCH_OPTION, '0' );
		}
		$fixture         = $this->create_marked_order_for_owner_transition();
		$stateful_filter = static function (): bool {
			return ! (bool) get_option( self::KILL_SWITCH_OPTION, false );
		};
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $stateful_filter, PHP_INT_MAX - 1 );

		try {
			$this->arbiter->invalidate();
			$this->assertSame( 'no', get_option( self::ENABLED_OPTION ), 'Raw native enablement must remain disabled so the stateful filter determines ownership.' );
			$this->assertFalse( (bool) get_option( self::KILL_SWITCH_OPTION, false ), 'The kill switch must begin inactive.' );
			$this->assertTrue( $this->arbiter->is_native_runtime_enabled(), 'The stateful filter must resolve native enablement before the write.' );
			$this->sut->register();
			$this->assert_arbiter_is_cold();

			if ( 'add' === $operation ) {
				add_option( self::KILL_SWITCH_OPTION, '1' );
			} else {
				update_option( self::KILL_SWITCH_OPTION, '1' );
			}

			$this->assertTrue( (bool) get_option( self::KILL_SWITCH_OPTION, false ), 'The real option write must persist the active kill switch before the post-change callback completes.' );
			$this->assertFalse( $this->arbiter->is_native_runtime_enabled(), 'The stateful filter must read the post-write database state as disabled.' );
			$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
		} finally {
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $stateful_filter, PHP_INT_MAX - 1 );
		}
	}

	/**
	 * Provide real add and update transitions whose filter depends on stored state.
	 *
	 * @return array<string,array{string}>
	 */
	public function stateful_filter_post_write_provider(): array {
		return array(
			'kill-switch add absent to active'      => array( 'add' ),
			'kill-switch update inactive to active' => array( 'update' ),
		);
	}

	/**
	 * @testdox A native-to-plugin ownership change never drains plugin-owned recovery data.
	 */
	public function test_native_to_plugin_owner_change_does_not_drain(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NATIVE, $this->arbiter->get_runtime_owner(), 'The fixture must begin under native ownership.' );
		$fixture             = $this->create_marked_order_for_owner_transition();
		$this->plugin_active = true;
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		$this->sut->drain_current_blog();

		$this->assert_detached_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox An option transition while the plugin owns the runtime does not drain.
	 */
	public function test_option_transition_while_plugin_owned_does_not_drain(): void {
		$this->plugin_active = true;
		update_option( self::ENABLED_OPTION, 'yes' );
		update_option( self::KILL_SWITCH_OPTION, '0' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		update_option( self::KILL_SWITCH_OPTION, '1' );

		$this->assert_detached_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Relevant option updates do not drain while the resulting owner remains native.
	 */
	public function test_relevant_option_updates_while_owner_native_do_not_drain(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		update_option( self::KILL_SWITCH_OPTION, '0' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		update_option( self::KILL_SWITCH_OPTION, '' );
		update_option( self::KILL_SWITCH_OPTION, '0' );

		$this->assert_detached_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox A relevant $operation action invalidates, recomputes, and drains when the resulting owner is none.
	 * @dataProvider owner_none_option_write_provider
	 *
	 * @param string $operation    Option write operation.
	 * @param string $option_name  Native runtime option name.
	 * @param mixed  $initial_value Existing value for update and delete operations.
	 * @param mixed  $new_value     Value for add and update operations.
	 */
	public function test_relevant_option_action_drains_when_resulting_owner_is_none( string $operation, string $option_name, $initial_value, $new_value ): void {
		delete_option( self::ENABLED_OPTION );
		delete_option( self::KILL_SWITCH_OPTION );
		if ( self::KILL_SWITCH_OPTION === $option_name ) {
			update_option( self::ENABLED_OPTION, 'no' );
		}
		if ( 'add' !== $operation ) {
			update_option( $option_name, $initial_value );
		}

		$post_write_owner_recomputation_count  = 0;
		$count_post_write_owner_recomputations = static function ( $enabled ) use ( &$post_write_owner_recomputation_count, $operation, $option_name, $new_value ) {
			$stored_value_matches = 'delete' === $operation
				? null === get_option( $option_name, null )
				: 0 === strcmp( (string) $new_value, (string) get_option( $option_name, null ) );
			if ( $stored_value_matches ) {
				++$post_write_owner_recomputation_count;
			}
			return $enabled;
		};
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $count_post_write_owner_recomputations, PHP_INT_MAX - 1 );
		$this->arbiter->invalidate();

		try {
			$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NONE, $this->arbiter->get_runtime_owner(), 'The fixture must begin with no runtime owner.' );
			$post_write_owner_recomputation_count = 0;
			$fixture                              = $this->create_marked_order_for_owner_transition();
			$this->sut->register();

			if ( 'add' === $operation ) {
				add_option( $option_name, $new_value );
			} elseif ( 'update' === $operation ) {
				update_option( $option_name, $new_value );
			} else {
				delete_option( $option_name );
			}

			$this->assertGreaterThan( 0, $post_write_owner_recomputation_count, 'Every relevant post-write action must recompute the current-blog owner after persistence.' );
			$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NONE );
			$this->assert_restored_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
		} finally {
			remove_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $count_post_write_owner_recomputations, PHP_INT_MAX - 1 );
		}
	}

	/**
	 * Provide every option-specific action with a none-to-none write.
	 *
	 * @return array<string,array{string,string,mixed,mixed}>
	 */
	public function owner_none_option_write_provider(): array {
		return array(
			'native-enabled add'        => array( 'add', self::ENABLED_OPTION, null, 'no' ),
			'native-enabled update'     => array( 'update', self::ENABLED_OPTION, 'no', '0' ),
			'native-enabled delete'     => array( 'delete', self::ENABLED_OPTION, 'no', null ),
			'native-kill-switch add'    => array( 'add', self::KILL_SWITCH_OPTION, null, '1' ),
			'native-kill-switch update' => array( 'update', self::KILL_SWITCH_OPTION, '0', '1' ),
			'native-kill-switch delete' => array( 'delete', self::KILL_SWITCH_OPTION, '1', null ),
		);
	}

	/**
	 * @testdox The permanent restore hook does not consume a plugin-owned marker.
	 */
	public function test_restore_hook_does_not_consume_plugin_owned_marker(): void {
		$this->plugin_active = true;
		$fixture             = $this->create_marked_order_for_owner_transition();
		$this->arbiter->invalidate();
		$this->sut->register();
		$this->assert_arbiter_is_cold();

		do_action( self::RESTORE_HOOK, $fixture['order_id'] );

		$this->assert_detached_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Unrelated add, update, and delete option actions do not drain.
	 */
	public function test_unrelated_option_actions_do_not_drain(): void {
		update_option( self::ENABLED_OPTION, 'yes' );
		$this->arbiter->invalidate();
		$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NATIVE, $this->arbiter->get_runtime_owner(), 'The fixture must warm a native owner before the underlying signal changes.' );
		$fixture = $this->create_marked_order_for_owner_transition();
		$this->sut->register();
		$query_count = 0;
		add_filter(
			'woocommerce_order_query_args',
			static function ( array $query_args ) use ( &$query_count ): array {
				++$query_count;
				return $query_args;
			}
		);
		$this->native_enabled_override = false;
		$this->assertFalse( $this->arbiter->is_native_runtime_enabled(), 'The mutable signal must now resolve native enablement to false without changing the warmed owner cache.' );
		$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NATIVE );

		add_option( self::UNRELATED_OPTION, 'first' );
		$this->assertSame( 0, $query_count, 'Adding an unrelated option must not query orders.' );
		$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		update_option( self::UNRELATED_OPTION, 'second' );
		$this->assertSame( 0, $query_count, 'Updating an unrelated option must not query orders.' );
		$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NATIVE );
		delete_option( self::UNRELATED_OPTION );
		$this->assertSame( 0, $query_count, 'Deleting an unrelated option must not query orders.' );
		$this->assert_arbiter_cached_owner( NativePaymentsRuntimeArbiter::OWNER_NATIVE );

		$this->assert_detached_owner_transition_order( $fixture['order_id'], $fixture['customer_id'] );
	}

	/**
	 * @testdox Malformed markers never overwrite a customer and existing malformed markers are removed.
	 * @dataProvider malformed_marker_provider
	 *
	 * @param bool  $marker_exists Whether the order has a marker.
	 * @param mixed $marker_value Marker value.
	 */
	public function test_malformed_marker_never_overwrites_customer( bool $marker_exists, $marker_value ): void {
		$this->sut->register();
		$customer_id = self::factory()->user->create();
		$order_id    = $this->create_order( $customer_id, $marker_value, $marker_exists, $marker_exists );

		$this->sut->drain_current_blog();

		$this->assert_order_state( $order_id, $customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ) );
	}

	/**
	 * Provide malformed marker values.
	 *
	 * @return array<string,array{bool,mixed}>
	 */
	public function malformed_marker_provider(): array {
		return array(
			'missing'     => array( false, null ),
			'non-scalar'  => array( true, array( 'customer' => 17 ) ),
			'non-numeric' => array( true, 'customer-17' ),
			'zero'        => array( true, '0' ),
			'negative'    => array( true, '-17' ),
		);
	}

	/**
	 * @testdox The drain uses bounded no-offset ID queries and consumes every marker.
	 */
	public function test_drain_uses_bounded_progress_queries(): void {
		$this->sut->register();
		$first_customer_id  = self::factory()->user->create();
		$second_customer_id = self::factory()->user->create();
		$third_customer_id  = self::factory()->user->create();
		$first_order_id     = $this->create_order( 0, $first_customer_id, true, true );
		$second_order_id    = $this->create_order( 0, $second_customer_id, true, true );
		$third_order_id     = $this->create_order( 0, $third_customer_id, true, true );
		$drain_queries      = array();
		add_filter(
			'woocommerce_order_query_args',
			static function ( array $query_args ) use ( &$drain_queries ): array {
				if ( self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
					$drain_queries[]     = $query_args;
					$query_args['limit'] = 1;
				}
				return $query_args;
			}
		);

		$this->sut->drain_current_blog();

		$this->assertGreaterThanOrEqual( 4, count( $drain_queries ), 'Three one-order batches plus a terminal empty query must complete without an offset.' );
		foreach ( $drain_queries as $query_args ) {
			$this->assertSame( 'ids', $query_args['return'] ?? null, 'The drain must request IDs instead of hydrating an unbounded order collection.' );
			$this->assertIsInt( $query_args['limit'] ?? null, 'The batch limit must be an integer.' );
			$this->assertGreaterThan( 0, $query_args['limit'], 'The batch limit must be positive.' );
			$this->assertLessThanOrEqual( 1000, $query_args['limit'], 'The drain must use a practical bounded batch.' );
			$this->assertEmpty( $query_args['offset'] ?? 0, 'Deleting markers between batches must not combine with an advancing offset.' );
		}
		$this->assert_order_state( $first_order_id, $first_customer_id, false );
		$this->assert_order_state( $second_order_id, $second_customer_id, false );
		$this->assert_order_state( $third_order_id, $third_customer_id, false );
	}

	/**
	 * @testdox A drain stops when a save leaves the same marker durable and a subsequent drain can recover it.
	 */
	public function test_drain_stops_when_restore_does_not_durably_consume_marker(): void {
		$this->sut->register();
		$customer_id                = self::factory()->user->create();
		$order_id                   = $this->create_order( 0, $customer_id, true, true );
		$save_count                 = 0;
		$query_count                = 0;
		$restore_marker_before_save = static function ( $order ) use ( $order_id, $customer_id, &$save_count ): void {
			if ( $order instanceof WC_Order && $order_id === $order->get_id() && ! $order->meta_exists( self::MARKER_META ) ) {
				$order->add_meta_data( self::MARKER_META, $customer_id, true );
				++$save_count;
			}
		};
		$bound_broken_loop          = static function ( $results, array $query_args ) use ( &$query_count ) {
			if ( self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
				++$query_count;
				if ( 1 < $query_count ) {
					return array();
				}
			}
			return $results;
		};
		add_action( 'woocommerce_before_order_object_save', $restore_marker_before_save );
		add_filter( 'woocommerce_order_query', $bound_broken_loop, PHP_INT_MAX, 2 );

		try {
			$this->sut->drain_current_blog();
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $restore_marker_before_save );
			remove_filter( 'woocommerce_order_query', $bound_broken_loop, PHP_INT_MAX );
		}

		$this->assert_order_state( $order_id, $customer_id, true, $customer_id );
		$this->assertSame( 1, $save_count, 'The realistic save hook must make exactly one restore attempt leave its marker durable.' );
		$this->assertSame( 1, $query_count, 'A durable marker that made no progress must stop the no-offset drain after one bounded query.' );

		$this->sut->drain_current_blog();

		$this->assert_order_state( $order_id, $customer_id, false );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ) );
	}

	/**
	 * @testdox An owner-loss drain affects only the current multisite blog.
	 * @group multisite
	 */
	public function test_owner_loss_drain_is_isolated_to_current_blog(): void {
		$this->skipWithoutMultisite();
		$this->sut->register();
		$main_customer_id   = self::factory()->user->create();
		$main_order_id      = $this->create_order( 0, $main_customer_id, true, true );
		$second_blog_id     = self::factory()->blog->create();
		$second_order_id    = 0;
		$second_customer_id = 0;

		try {
			switch_to_blog( $second_blog_id );
			try {
				\WC_Install::create_tables();
				update_option( self::ENABLED_OPTION, 'yes' );
				$second_customer_id = self::factory()->user->create();
				$second_order_id    = $this->create_order( 0, $second_customer_id, true, true );
				$this->arbiter->invalidate();

				update_option( self::ENABLED_OPTION, 'no' );

				$this->assert_order_state( $second_order_id, $second_customer_id, false );
				$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_order_id ) ) );
			} finally {
				restore_current_blog();
			}

			$this->assertSame( $this->original_blog_id, get_current_blog_id(), 'The main-site context must be restored after the second-site transition.' );
			$this->assert_order_state( $main_order_id, 0, true, $main_customer_id );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $main_order_id ) ), 'The main-site schedule must remain intact.' );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			unset( $this->created_order_ids[ $second_blog_id ] );
			wpmu_delete_blog( $second_blog_id, true );
		}
	}

	/**
	 * @testdox A deferred drain does not cross multisite blog context before order CRUD readiness.
	 * @group multisite
	 */
	public function test_deferred_drain_is_bound_to_its_originating_multisite_blog(): void {
		global $wp_actions;

		$this->skipWithoutMultisite();
		update_option( self::ENABLED_OPTION, 'no' );
		delete_option( self::KILL_SWITCH_OPTION );
		$this->arbiter->invalidate();
		$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NONE, $this->arbiter->get_runtime_owner(), 'The main-site fixture must remain eligible to expose a stale cross-context drain.' );
		$main_customer_id         = self::factory()->user->create();
		$main_order_id            = $this->create_order( 0, $main_customer_id, true, true );
		$second_blog_id           = self::factory()->blog->create();
		$second_order_id          = 0;
		$second_customer_id       = 0;
		$query_counts             = array();
		$count_order_queries      = static function ( array $query_args ) use ( &$query_counts ): array {
			if ( self::MARKER_META === ( $query_args['meta_key'] ?? null ) ) {
				$blog_id                  = get_current_blog_id();
				$query_counts[ $blog_id ] = ( $query_counts[ $blog_id ] ?? 0 ) + 1;
			}
			return $query_args;
		};
		$production_blog_switches = 0;
		$count_blog_switches      = static function () use ( &$production_blog_switches ): void {
			++$production_blog_switches;
		};
		$had_ready_action_count   = is_array( $wp_actions ) && array_key_exists( self::ORDER_CRUD_READY, $wp_actions );
		$ready_action_count       = $had_ready_action_count ? $wp_actions[ self::ORDER_CRUD_READY ] : null;
		$query_filter_registered  = false;
		$switch_action_registered = false;

		try {
			switch_to_blog( $second_blog_id );
			try {
				\WC_Install::create_tables();
				update_option( self::ENABLED_OPTION, 'yes' );
				delete_option( self::KILL_SWITCH_OPTION );
				$second_customer_id = self::factory()->user->create();
				$second_order_id    = $this->create_order( 0, $second_customer_id, true, true );
				$this->arbiter->invalidate();
				$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NATIVE, $this->arbiter->get_runtime_owner(), 'The second-site fixture must begin under native ownership.' );
				$this->arbiter->invalidate();
			} finally {
				restore_current_blog();
			}

			$this->sut->register();
			add_filter( 'woocommerce_order_query_args', $count_order_queries );
			$query_filter_registered = true;

			switch_to_blog( $second_blog_id );
			try {
				unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulate a second-site transition before order CRUD is ready.
				update_option( self::ENABLED_OPTION, 'no' );

				$this->assertSame( 0, $query_counts[ $second_blog_id ] ?? 0, 'The second-site transition must defer without querying marked orders.' );
				$this->assertSame( 1, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The second-site transition must queue exactly one readiness callback.' );
				$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_order_id ) ), 'The second-site per-order recovery event must remain scheduled while readiness is deferred.' );
			} finally {
				restore_current_blog();
			}

			$this->assertSame( $this->original_blog_id, get_current_blog_id(), 'The main-site context must be restored before entering the queued callback.' );
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Mark only the readiness state without firing unrelated global lifecycle callbacks.
			$wp_actions[ self::ORDER_CRUD_READY ] = 1;
			add_action( 'switch_blog', $count_blog_switches, 10, 0 );
			$switch_action_registered = true;
			try {
				$this->sut->drain_current_blog();
			} finally {
				remove_action( 'switch_blog', $count_blog_switches, 10 );
				$switch_action_registered = false;
			}

			$this->assertSame( 0, $production_blog_switches, 'The mismatched deferred callback must not switch blogs.' );
			$this->assertSame( 0, $query_counts[ $this->original_blog_id ] ?? 0, 'A callback originating on the second site must not query marked orders on the main site.' );
			$this->assertSame( 0, $this->count_service_callbacks( self::ORDER_CRUD_READY, 'drain_current_blog' ), 'The mismatched readiness callback must remove itself.' );
			$this->assert_order_state( $main_order_id, 0, true, $main_customer_id );
			$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $main_order_id ) ), 'The main-site per-order recovery event must remain scheduled.' );

			switch_to_blog( $second_blog_id );
			try {
				$this->assert_order_state( $second_order_id, 0, true, $second_customer_id );
				$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $second_order_id ) ), 'The second-site per-order recovery event must remain scheduled.' );
			} finally {
				restore_current_blog();
			}
		} finally {
			if ( $switch_action_registered ) {
				remove_action( 'switch_blog', $count_blog_switches, 10 );
			}
			if ( $query_filter_registered ) {
				remove_filter( 'woocommerce_order_query_args', $count_order_queries );
			}
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			if ( $had_ready_action_count ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact pre-test lifecycle count.
				$wp_actions[ self::ORDER_CRUD_READY ] = $ready_action_count;
			} else {
				unset( $wp_actions[ self::ORDER_CRUD_READY ] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore an originally absent lifecycle count.
			}
			unset( $this->created_order_ids[ $second_blog_id ] );
			if ( get_site( $second_blog_id ) ) {
				wpmu_delete_blog( $second_blog_id, true );
			}
		}
	}

	/**
	 * Control every plugin-ownership detection signal used by the real arbiter.
	 */
	private function control_plugin_detection(): void {
		$plugin_file = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $plugin_file ) {
					if ( 'active_plugins' === $name ) {
						return $this->plugin_active ? array( $plugin_file ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => static function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'defined'         => static function ( $constant_name ): bool {
					if ( 'WCPAY_PLUGIN_FILE' === $constant_name ) {
						return false;
					}
					return defined( $constant_name );
				},
			)
		);
	}

	/**
	 * Configure a cold arbiter to resolve the requested current owner.
	 *
	 * @param string $runtime_owner Runtime owner.
	 */
	private function configure_runtime_owner( string $runtime_owner ): void {
		$this->plugin_active = NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $runtime_owner;
		delete_option( self::KILL_SWITCH_OPTION );
		update_option( self::ENABLED_OPTION, NativePaymentsRuntimeArbiter::OWNER_NATIVE === $runtime_owner ? 'yes' : 'no' );
		$this->arbiter->invalidate();
	}

	/**
	 * Count one service callback across every priority on a hook.
	 *
	 * @param string $hook Hook name.
	 * @param string $method Service method.
	 * @return int
	 */
	private function count_service_callbacks( string $hook, string $method ): int {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && ( $function[0] ?? null ) === $this->sut && ( $function[1] ?? null ) === $method ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Count all callbacks registered by the service on a hook.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_service_callbacks_on_hook( string $hook ): int {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && ( $function[0] ?? null ) === $this->sut ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Remove all callbacks registered by the service on a hook.
	 *
	 * @param string $hook Hook name.
	 */
	private function remove_service_callbacks_from_hook( string $hook ): void {
		global $wp_filter;
		if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof \WP_Hook ) {
			return;
		}

		$callbacks_to_remove = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && ( $function[0] ?? null ) === $this->sut ) {
					$callbacks_to_remove[] = array( $function, $priority );
				}
			}
		}

		foreach ( $callbacks_to_remove as $callback_to_remove ) {
			remove_filter( $hook, $callback_to_remove[0], $callback_to_remove[1] );
		}
	}

	/**
	 * Create a saved order, optionally with a restore marker and event.
	 *
	 * @param int   $customer_id Current order customer ID.
	 * @param mixed $marker Marker value.
	 * @param bool  $marker_exists Whether to add the marker.
	 * @param bool  $schedule Whether to schedule restoration.
	 * @return int
	 */
	private function create_order( int $customer_id = 0, $marker = null, bool $marker_exists = false, bool $schedule = false ): int {
		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		if ( $marker_exists ) {
			$order->add_meta_data( self::MARKER_META, $marker, true );
		}
		$order->save();
		$order_id = $order->get_id();
		$this->created_order_ids[ get_current_blog_id() ][] = $order_id;

		if ( $schedule ) {
			$this->assertNotFalse( wp_schedule_single_event( time() + ( 10 * MINUTE_IN_SECONDS ), self::RESTORE_HOOK, array( $order_id ) ), 'The test restore event must be scheduled.' );
		}

		return $order_id;
	}

	/**
	 * Create a marked order used by owner-transition tests.
	 *
	 * @return array{order_id:int,customer_id:int}
	 */
	private function create_marked_order_for_owner_transition(): array {
		$customer_id = self::factory()->user->create();
		return array(
			'order_id'    => $this->create_order( 0, $customer_id, true, true ),
			'customer_id' => $customer_id,
		);
	}

	/**
	 * Assert that an owner-transition order was restored.
	 *
	 * @param int $order_id Order ID.
	 * @param int $expected_customer_id Expected customer ID.
	 */
	private function assert_restored_owner_transition_order( int $order_id, int $expected_customer_id ): void {
		$order = $this->reload_order( $order_id );
		$this->assertSame( $expected_customer_id, $order->get_customer_id(), 'Owner loss must restore the exact marker customer ID.' );
		$this->assertFalse( $order->meta_exists( self::MARKER_META ), 'Owner loss must remove the durable marker.' );
		$this->assertFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ), 'Owner loss must clear the scheduled restore.' );
	}

	/**
	 * Assert that an owner-transition order remains detached.
	 *
	 * @param int $order_id Order ID.
	 * @param int $expected_customer_id Expected marker customer ID.
	 */
	private function assert_detached_owner_transition_order( int $order_id, int $expected_customer_id ): void {
		$order = $this->reload_order( $order_id );
		$this->assertSame( 0, $order->get_customer_id(), 'Plugin ownership or retained native ownership must not restore the order.' );
		$this->assertTrue( $order->meta_exists( self::MARKER_META ), 'Plugin ownership or retained native ownership must preserve the marker.' );
		$this->assertSame( (string) $expected_customer_id, (string) $order->get_meta( self::MARKER_META ), 'Plugin ownership or retained native ownership must preserve the exact marker customer ID.' );
		$this->assertNotFalse( wp_next_scheduled( self::RESTORE_HOOK, array( $order_id ) ), 'Plugin ownership or retained native ownership must preserve the schedule.' );
	}

	/**
	 * Assert an order's persisted customer and marker state after cache invalidation.
	 *
	 * @param int        $order_id Order ID.
	 * @param int        $expected_customer_id Expected customer ID.
	 * @param bool       $marker_exists Whether the marker should exist.
	 * @param mixed|null $expected_marker Expected marker value when present.
	 */
	private function assert_order_state( int $order_id, int $expected_customer_id, bool $marker_exists, $expected_marker = null ): void {
		$order = $this->reload_order( $order_id );
		$this->assertSame( $expected_customer_id, $order->get_customer_id(), 'The persisted customer ID must match.' );
		$this->assertSame( $marker_exists, $order->meta_exists( self::MARKER_META ), 'The persisted marker presence must match.' );
		if ( $marker_exists ) {
			$this->assertSame( (string) $expected_marker, (string) $order->get_meta( self::MARKER_META ), 'The persisted marker value must match.' );
		}
	}

	/**
	 * Reload an order after invalidating both order-store caches.
	 *
	 * @param int $order_id Order ID.
	 * @return WC_Order
	 */
	private function reload_order( int $order_id ): WC_Order {
		wc_get_container()->get( OrderCache::class )->remove( $order_id );
		clean_post_cache( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order, 'The test order must remain readable through WooCommerce CRUD.' );

		return $order;
	}

	/**
	 * Remove every hook registered by the service.
	 */
	private function remove_service_hooks(): void {
		if ( ! $this->sut instanceof WooPaymentsWooPayVerifiedEmailRestoreService ) {
			return;
		}

		$hooks = array(
			self::RESTORE_HOOK,
			self::ORDER_CRUD_READY,
			'add_option',
			'added_option',
			'update_option',
			'updated_option',
			'delete_option',
			'deleted_option',
		);
		foreach ( array( self::ENABLED_OPTION, self::KILL_SWITCH_OPTION ) as $option_name ) {
			$hooks[] = "add_option_{$option_name}";
			$hooks[] = "update_option_{$option_name}";
			$hooks[] = "delete_option_{$option_name}";
			$hooks[] = "sanitize_option_{$option_name}";
		}

		foreach ( $hooks as $hook ) {
			$this->remove_service_callbacks_from_hook( $hook );
		}
	}

	/**
	 * Delete all explicitly tracked orders from their owning blogs.
	 */
	private function delete_created_orders(): void {
		foreach ( $this->created_order_ids as $blog_id => $order_ids ) {
			if ( is_multisite() && ! get_site( $blog_id ) ) {
				continue;
			}

			if ( get_current_blog_id() !== $blog_id ) {
				switch_to_blog( $blog_id );
				try {
					$this->delete_orders( $order_ids );
				} finally {
					restore_current_blog();
				}
			} else {
				$this->delete_orders( $order_ids );
			}
		}
	}

	/**
	 * Delete orders on the current blog.
	 *
	 * @param int[] $order_ids Order IDs.
	 */
	private function delete_orders( array $order_ids ): void {
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				$order->delete( true );
			}
		}
	}

	/**
	 * Snapshot one site option without conflating an absent option with false.
	 *
	 * @param string $option_name Option name.
	 * @return array{exists:bool,value:mixed}
	 */
	private function snapshot_option( string $option_name ): array {
		$missing = new \stdClass();
		$value   = get_option( $option_name, $missing );

		return array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore one site option snapshot.
	 *
	 * @param string                         $option_name Option name.
	 * @param array{exists:bool,value:mixed} $snapshot Option snapshot.
	 */
	private function restore_option( string $option_name, array $snapshot ): void {
		if ( $snapshot['exists'] ) {
			update_option( $option_name, $snapshot['value'] );
		} else {
			delete_option( $option_name );
		}
	}

	/**
	 * Snapshot one network option without conflating an absent option with false.
	 *
	 * @param string $option_name Option name.
	 * @return array{exists:bool,value:mixed}
	 */
	private function snapshot_site_option( string $option_name ): array {
		$missing = new \stdClass();
		$value   = get_site_option( $option_name, $missing );

		return array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore one network option snapshot.
	 *
	 * @param string                         $option_name Option name.
	 * @param array{exists:bool,value:mixed} $snapshot Option snapshot.
	 */
	private function restore_site_option( string $option_name, array $snapshot ): void {
		if ( $snapshot['exists'] ) {
			update_site_option( $option_name, $snapshot['value'] );
		} else {
			delete_site_option( $option_name );
		}
	}

	/**
	 * Snapshot every scheduled instance of the shared restore hook.
	 *
	 * @return array<int|string,array<string,array<string,array<string,mixed>>>>
	 */
	private function snapshot_restore_schedule(): array {
		$snapshot = array();
		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			if ( isset( $hooks[ self::RESTORE_HOOK ] ) ) {
				$snapshot[ $timestamp ][ self::RESTORE_HOOK ] = $hooks[ self::RESTORE_HOOK ];
			}
		}

		return $snapshot;
	}

	/**
	 * Remove every scheduled instance of the shared restore hook, regardless of arguments.
	 */
	private function clear_restore_schedule(): void {
		$cron = _get_cron_array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( isset( $hooks[ self::RESTORE_HOOK ] ) ) {
				unset( $cron[ $timestamp ][ self::RESTORE_HOOK ] );
				if ( empty( $cron[ $timestamp ] ) ) {
					unset( $cron[ $timestamp ] );
				}
			}
		}
		_set_cron_array( $cron );
	}

	/**
	 * Restore every scheduled instance of the shared restore hook.
	 *
	 * @param array<int|string,array<string,array<string,array<string,mixed>>>> $snapshot Schedule snapshot.
	 */
	private function restore_restore_schedule( array $snapshot ): void {
		$this->clear_restore_schedule();
		$cron = _get_cron_array();
		foreach ( $snapshot as $timestamp => $hooks ) {
			$cron[ $timestamp ][ self::RESTORE_HOOK ] = $hooks[ self::RESTORE_HOOK ];
		}
		_set_cron_array( $cron );
	}

	/**
	 * Snapshot the real arbiter's request-local owner cache.
	 *
	 * @return array<int,string>
	 */
	private function snapshot_arbiter_cache(): array {
		return $this->read_arbiter_cache();
	}

	/**
	 * Assert that the real arbiter has no current-blog owner memoized.
	 */
	private function assert_arbiter_is_cold(): void {
		$this->assertArrayNotHasKey( get_current_blog_id(), $this->read_arbiter_cache(), 'The transition must begin with an unprimed current-blog arbiter.' );
	}

	/**
	 * Assert the real arbiter retains the expected current-blog owner.
	 *
	 * @param string $expected_owner Expected cached owner.
	 */
	private function assert_arbiter_cached_owner( string $expected_owner ): void {
		$cache   = $this->read_arbiter_cache();
		$blog_id = get_current_blog_id();
		$this->assertArrayHasKey( $blog_id, $cache, 'The unrelated option action must preserve the current-blog owner cache.' );
		$this->assertSame( $expected_owner, $cache[ $blog_id ], 'The unrelated option action must preserve the cached current-blog owner.' );
	}

	/**
	 * Read the real arbiter's request-local owner cache.
	 *
	 * @return array<int,string>
	 */
	private function read_arbiter_cache(): array {
		$arbiter  = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		$property = new \ReflectionProperty( NativePaymentsRuntimeArbiter::class, 'runtime_owners' );
		$property->setAccessible( true );

		return (array) $property->getValue( $arbiter );
	}

	/**
	 * Restore the real arbiter's request-local owner cache.
	 *
	 * @param array<int,string> $snapshot Arbiter-cache snapshot.
	 */
	private function restore_arbiter_cache( array $snapshot ): void {
		$arbiter  = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		$property = new \ReflectionProperty( NativePaymentsRuntimeArbiter::class, 'runtime_owners' );
		$property->setAccessible( true );
		$property->setValue( $arbiter, $snapshot );
	}
}
