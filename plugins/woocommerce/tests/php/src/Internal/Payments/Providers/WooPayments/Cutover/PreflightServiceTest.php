<?php
/**
 * PreflightService tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\BundledSubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the headless WooPayments cutover preflight service.
 */
class PreflightServiceTest extends WC_Unit_Test_Case {

	/**
	 * Whether the provider can process payments.
	 *
	 * @var bool
	 */
	private bool $provider_ready = true;

	/** @var string[] */
	private array $platform_failures = array();

	/** @var bool */
	private bool $rate_api_client_connected = true;

	/**
	 * Action Scheduler hooks scheduled by tests.
	 *
	 * @var string[]
	 */
	private array $scheduled_action_hooks = array();

	/**
	 * Whether this test registered the subscription order type.
	 *
	 * @var bool
	 */
	private bool $registered_subscription_order_type = false;

	/**
	 * Raw HPOS order rows created by tests.
	 *
	 * @var int[]
	 */
	private array $raw_hpos_order_ids = array();

	/**
	 * Whether this test created the HPOS tables for a disabled-after-use fixture.
	 *
	 * @var bool
	 */
	private bool $created_hpos_tables = false;

	/**
	 * Value of the HPOS table-created option before a disabled-after-use fixture.
	 *
	 * @var string|false
	 */
	private $previous_hpos_tables_created_option = false;

	/**
	 * Whether this test captured the HPOS table-created option.
	 *
	 * @var bool
	 */
	private bool $captured_hpos_tables_created_option = false;

	/**
	 * Failing stored plugin-list reads, when a test sets them up.
	 *
	 * @var StoredPluginListReadFailure|null
	 */
	private ?StoredPluginListReadFailure $read_failure = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->provider_ready            = true;
		$this->platform_failures         = array();
		$this->rate_api_client_connected = true;
		update_option( 'woocommerce_woocommerce_payments_version', '10.6.0' );
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		as_unschedule_all_actions( CutoverActionScheduler::ACTION_HOOK );
		as_unschedule_all_actions( 'wcpay_preflight_service_test' );
		as_unschedule_all_actions( 'woocommerce_woopayments_cutover_foreign_hook' );
		foreach ( $this->scheduled_action_hooks as $hook_name ) {
			as_unschedule_all_actions( $hook_name );
		}
		$this->scheduled_action_hooks = array();
		delete_option( '_wcpay_feature_subscriptions' );
		if ( $this->registered_subscription_order_type ) {
			global $wc_order_types;
			unset( $wc_order_types['shop_subscription'] );
			unregister_post_type( 'shop_subscription' );
			$this->registered_subscription_order_type = false;
		}
		$this->delete_raw_hpos_orders();
		$this->clean_up_disabled_hpos_fixture();
		if ( null !== $this->read_failure ) {
			$this->read_failure->remove();
			$this->read_failure = null;
		}
		parent::tearDown();
	}

	/**
	 * @testdox A store with every check passing reports no failure, including no undispositioned provider events.
	 */
	public function test_ready_store_reports_no_failures(): void {
		$this->assertSame( array(), $this->create_sut()->get_failures() );
	}

	/**
	 * WooPayments 10.6.0 is the first release that loads Amazon Pay tokens and registers the Amazon Pay gateway by default
	 * (client 10.6.0 includes/class-wc-payments.php:516, includes/class-wc-payments-features.php:381-382; 10.5.x keeps the
	 * gateway off, includes/class-wc-payments-features.php:361-362), so a store rolled back to 10.5.x could not refund or
	 * renew the Amazon Pay payments the built-in WooPayments took.
	 *
	 * @testdox The switch supports a store that last ran WooPayments $version: $supported.
	 * @testWith ["10.5.2", false]
	 *           ["10.6.0", true]
	 *
	 * @param string $version   Recorded WooPayments extension version.
	 * @param bool   $supported Whether the switch supports it.
	 */
	public function test_the_switch_supports_woopayments_from_10_6_0( string $version, bool $supported ): void {
		update_option( 'woocommerce_woocommerce_payments_version', $version );
		// No installed extension files, so the row does not depend on a copy mounted in the test environment.
		$this->register_legacy_proxy_function_mocks( array( 'is_readable' => fn() => false ) );

		$this->assertSame( $supported, $this->create_sut()->is_woopayments_plugin_version_supported() );
	}

	/**
	 * A rollback runs the extension code: the version the running extension loaded, or the installed files when it is not
	 * running. WooPayments 10.5.1 installed over 11.2.0 keeps the recorded 11.2.0: it writes the version only when its own
	 * is higher (client 10.5.1 includes/class-wc-payments.php:1509-1519).
	 *
	 * @testdox The lower of the recorded WooPayments version and the code a rollback would run decides the switch: $_dataName.
	 * @dataProvider provide_recorded_and_code_versions
	 *
	 * @param string               $recorded       Recorded extension version.
	 * @param array<int,string>    $active_plugins The site's active plugin files.
	 * @param array<string,string> $headers        Readable plugin files (relative to the plugins folder) and their header versions.
	 * @param string|null          $loaded         WCPAY_VERSION_NUMBER, or null when it is not defined.
	 * @param bool                 $supported      Whether the switch supports the store.
	 */
	public function test_the_lower_of_the_recorded_and_code_versions_decides( string $recorded, array $active_plugins, array $headers, ?string $loaded, bool $supported ): void {
		update_option( 'woocommerce_woocommerce_payments_version', $recorded );
		$paths = array();
		foreach ( $headers as $plugin_file => $header_version ) {
			$paths[ WP_PLUGIN_DIR . '/' . $plugin_file ] = $header_version;
		}
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => fn( $name, $default_value = false ) => 'active_plugins' === $name ? $active_plugins : get_option( $name, $default_value ),
				'get_site_option' => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array() : get_site_option( $name, $default_value ),
				'is_readable'     => fn( $path ) => array_key_exists( $path, $paths ),
				// The header shape of WordPress get_plugin_data() (wp-admin/includes/plugin.php:74-120).
				'get_plugin_data' => fn( $path ) => array( 'Version' => $paths[ $path ] ?? '' ),
				// The active plugin list alone decides ownership; WCPAY_PLUGIN_FILE is not defined. The extension defines
				// WCPAY_VERSION_NUMBER from its own header when it loads (client 11.1.0 includes/class-wc-payments.php:349-353).
				'defined'         => fn( $name ) => 'WCPAY_VERSION_NUMBER' === $name && null !== $loaded,
				'constant'        => fn( $name ) => 'WCPAY_VERSION_NUMBER' === $name ? $loaded : null,
			)
		);
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$this->assertSame( $supported, $this->create_sut()->is_woopayments_plugin_version_supported() );
	}

	/**
	 * Once the built-in WooPayments owns payments its compatibility facades define WCPAY_VERSION_NUMBER
	 * (WooPaymentsExtensionFacadeLoader), so the constant must not stand in for the inactive extension files a rollback runs.
	 *
	 * @testdox A switched store checks the inactive extension files, not the facades' version constant.
	 */
	public function test_a_switched_store_checks_the_installed_files_not_the_facades_constant(): void {
		update_option( 'woocommerce_woocommerce_payments_version', '11.2.0' );
		update_option( StateStore::OPTION_NAME, array( 'state' => StateStore::STATE_DONE ) );
		$standard_path = WP_PLUGIN_DIR . '/' . WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => fn( $name, $default_value = false ) => 'active_plugins' === $name ? array() : get_option( $name, $default_value ),
				'get_site_option' => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array() : get_site_option( $name, $default_value ),
				'is_readable'     => fn( $path ) => $standard_path === $path,
				'get_plugin_data' => fn() => array( 'Version' => '10.5.1' ),
				'defined'         => fn( $name ) => 'WCPAY_VERSION_NUMBER' === $name,
				'constant'        => fn( $name ) => 'WCPAY_VERSION_NUMBER' === $name ? '11.2.0' : null,
			)
		);
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();

		$this->assertTrue( $arbiter->is_builtin_owner(), 'The switch is done, so the built-in WooPayments owns payments.' );
		$this->assertFalse( $this->create_sut()->is_woopayments_plugin_version_supported() );
	}

	/**
	 * Recorded, running and installed WooPayments versions around the 10.6.0 floor.
	 *
	 * @return array<string,array{0:string,1:array<int,string>,2:array<string,string>,3:string|null,4:bool}>
	 */
	public function provide_recorded_and_code_versions(): array {
		$standard = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		$renamed  = 'woopayments-renamed/woocommerce-payments.php';
		// The active copy is older than a standard copy left in place.
		$renamed_older = array(
			$renamed  => '10.5.1',
			$standard => '11.2.0',
		);

		return array(
			'recorded 11.2.0, inactive files 10.5.1'     => array( '11.2.0', array(), array( $standard => '10.5.1' ), null, false ),
			'recorded 11.2.0, renamed files 10.5.1'      => array( '11.2.0', array( $renamed ), $renamed_older, null, false ),
			'recorded 10.5.1, files 11.2.0'              => array( '10.5.1', array( $standard ), array( $standard => '11.2.0' ), null, false ),
			'recorded 11.2.0, files 11.2.0'              => array( '11.2.0', array( $standard ), array( $standard => '11.2.0' ), null, true ),
			'recorded 11.2.0, files absent'              => array( '11.2.0', array(), array(), null, true ),
			'running 10.5.1, recorded and files 11.2.0'  => array( '11.2.0', array( $standard ), array( $standard => '11.2.0' ), '10.5.1', false ),
			'running 11.2.0, files 10.5.1'               => array( '11.2.0', array( $standard ), array( $standard => '10.5.1' ), '11.2.0', true ),
			'running, no version constant, files 10.5.1' => array( '11.2.0', array( $standard ), array( $standard => '10.5.1' ), null, false ),
			'waiting, constant 11.2.0, files 10.5.1'     => array( '11.2.0', array(), array( $standard => '10.5.1' ), '11.2.0', false ),
		);
	}

	/**
	 * @testdox The induced reconciliation vocabulary is exactly the 10 conditions a store can reach.
	 */
	public function test_induced_reconciliation_vocabulary_is_exactly_the_ten_reachable_conditions(): void {
		$codes = array();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$codes = array_merge( $codes, $this->create_sut()->get_failures() );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->provider_ready            = false;
		$this->platform_failures         = array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable' );
		$this->rate_api_client_connected = false;
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.2' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$this->create_bundled_stripe_billing_subscription( 'active' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), 'test', true );
		$this->fake_active_plugins( array( 'yith-woocommerce-subscription-premium/init.php' ), array(), false, true );
		update_option( 'active_plugins', array() );
		$codes = array_merge( $codes, $this->create_sut()->get_failures() );

		$unique_codes = array_values( array_unique( $codes ) );

		$this->assertSame( array( 'builtin_runtime_disabled', 'woopayments_plugin_version_unsupported', 'builtin_transport_unavailable', 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'multi_currency_rates_unavailable', 'operational_queue_hooks_unhandled', 'bundled_stripe_billing_subscriptions_present', 'extension_renewal_plugin_active' ), $unique_codes );
		$this->assertCount( 10, $unique_codes );
	}

	/**
	 * The switch's deactivation can only remove an extension that a stored plugin list holds. Only the admin request reads
	 * that as the site's setup: a queue process can have loaded the code before the merchant deactivated the extension.
	 *
	 * @testdox The extension's code running while $_dataName is seen for the admin side and never refuses the switch in the preflight.
	 * @dataProvider provide_loaded_outside_plugin_lists
	 *
	 * @param bool $code_loaded Whether this process runs the extension's code.
	 * @param bool $stored      Whether the stored site list holds the extension.
	 * @param bool $read_fails  Whether the stored site list cannot be read.
	 * @param bool $seen        Whether the check sees the extension loaded outside the stored lists.
	 */
	public function test_an_extension_loaded_outside_the_plugin_lists_is_seen_but_never_refused( bool $code_loaded, bool $stored, bool $read_fails, bool $seen ): void {
		$this->fake_active_plugins( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), array(), false, $code_loaded );
		update_option( 'active_plugins', $stored ? array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) : array() );
		$sut = $this->create_sut();
		if ( $read_fails ) {
			$this->read_failure                = new StoredPluginListReadFailure();
			$this->read_failure->site_failures = 2;
		}

		$this->assertSame( $seen, $sut->is_woopayments_loaded_outside_plugin_lists() );
		$this->assertNotContains( 'woopayments_loaded_outside_plugin_lists', $sut->get_failures() );
	}

	/**
	 * @return array<string,array{bool,bool,bool,bool}>
	 */
	public function provide_loaded_outside_plugin_lists(): array {
		return array(
			'no stored list holds it'                => array( true, false, false, true ),
			'the stored site list holds it'          => array( true, true, false, false ),
			'the stored list cannot be read'         => array( true, false, true, false ),
			'its code is not loaded either (no run)' => array( false, false, false, false ),
		);
	}

	/**
	 * @testdox Invalidating the current blog memoization re-evaluates preflight facts.
	 */
	public function test_invalidate_current_blog_memoization_re_evaluates_facts(): void {
		$this->provider_ready = false;
		$sut                  = $this->create_sut();

		$this->assertContains( 'builtin_transport_unavailable', $sut->get_failures() );
		$this->provider_ready = true;
		$this->assertContains( 'builtin_transport_unavailable', $sut->get_failures() );

		$sut->invalidate_current_blog_memoization();
		$this->assertNotContains( 'builtin_transport_unavailable', $sut->get_failures() );
	}

	/**
	 * @testdox A cutover action excludes itself while another prefixed action remains operational.
	 */
	public function test_operational_action_discovery_excludes_only_cutover_actions_in_the_cutover_group(): void {
		$cutover_action_id        = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			CutoverActionScheduler::ACTION_HOOK,
			array(
				'generation' => 1,
				'attempt'    => 1,
			),
			CutoverActionScheduler::ACTION_GROUP,
			true
		);
		$same_hook_other_group_id = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			CutoverActionScheduler::ACTION_HOOK,
			array(
				'generation' => 2,
				'attempt'    => 1,
			),
			'other-group',
			true
		);
		$foreign_hook_id          = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'woocommerce_woopayments_cutover_foreign_hook', array(), CutoverActionScheduler::ACTION_GROUP, true );
		$other_action_id          = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), CutoverActionScheduler::ACTION_GROUP, true );

		$this->assertIsInt( $cutover_action_id );
		$this->assertIsInt( $same_hook_other_group_id );
		$this->assertIsInt( $foreign_hook_id );
		$this->assertIsInt( $other_action_id );
		$actions = $this->create_sut()->get_queued_operational_actions();

		$this->assertSame( array( CutoverActionScheduler::ACTION_HOOK, 'woocommerce_woopayments_cutover_foreign_hook', 'wcpay_preflight_service_test' ), array_column( $actions, 'hook' ) );
		$this->assertSame( array( $same_hook_other_group_id, $foreign_hook_id, $other_action_id ), array_column( $actions, 'action_id' ) );
	}

	/**
	 * @testdox The operational queue scan reads one row per hook and group, however many actions share them.
	 */
	public function test_operational_action_discovery_reads_one_row_per_hook_and_group(): void {
		$first_id = 0;
		for ( $i = 1; $i <= 3; $i++ ) {
			$action_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array( 'order_id' => $i ), 'woocommerce-payments', true );
			$this->assertIsInt( $action_id );
			$first_id = 0 === $first_id ? $action_id : $first_id;
		}
		$other_group_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array( 'order_id' => 4 ), 'other-group', true );
		$this->assertIsInt( $other_group_id );

		$actions = $this->create_sut()->get_queued_woopayments_actions();

		$this->assertCount( 2, $actions, 'A store with thousands of queued plugin actions must not read each one.' );
		$this->assertSame( array( $first_id, $other_group_id ), array_column( $actions, 'action_id' ) );
		$this->assertSame( array( 'woocommerce-payments', 'other-group' ), array_column( $actions, 'group' ) );
	}

	/**
	 * @testdox The operational queue scan gives each hook and group the latest and the earliest due time of its pending and running actions.
	 */
	public function test_operational_action_discovery_reads_the_latest_and_earliest_due_time_of_each_hook_and_group(): void {
		$now      = time();
		$near_id  = as_schedule_single_action( $now + 10, 'wcpay_preflight_service_test', array( 'order_id' => 1 ), 'woocommerce-payments', true );
		$later_id = as_schedule_single_action( $now + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array( 'order_id' => 2 ), 'woocommerce-payments', true );
		$this->assertIsInt( $near_id );
		$this->assertIsInt( $later_id );

		$actions = $this->create_sut()->get_queued_woopayments_actions();

		$this->assertSame(
			array(
				array(
					'action_id'       => $near_id,
					'hook'            => 'wcpay_preflight_service_test',
					'group'           => 'woocommerce-payments',
					'due_at'          => $now + HOUR_IN_SECONDS,
					'earliest_due_at' => $now + 10,
				),
			),
			$actions
		);
	}

	/**
	 * An action with no date or a zero date beside one due now must not let the group read as due now.
	 *
	 * @testdox A group holding an action whose stored due date is $stored has no earliest due time, whatever its other actions say.
	 * @testWith ["NULL"]
	 *           ["'0000-00-00 00:00:00'"]
	 *
	 * @param string $stored SQL value written as the action's due date.
	 */
	public function test_an_undated_action_leaves_its_group_without_an_earliest_due_time( string $stored ): void {
		global $wpdb;
		$now     = time();
		$undated = as_schedule_single_action( $now + 10, 'wcpay_preflight_service_test', array( 'order_id' => 1 ), 'woocommerce-payments' );
		$near_id = as_schedule_single_action( $now + 10, 'wcpay_preflight_service_test', array( 'order_id' => 2 ), 'woocommerce-payments' );
		$this->assertIsInt( $undated );
		$this->assertIsInt( $near_id );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}actionscheduler_actions SET scheduled_date_gmt = {$stored} WHERE action_id = %d", $undated ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- A fixed test literal.

		$actions = $this->create_sut()->get_queued_woopayments_actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( $now + 10, $actions[0]['due_at'], 'The latest due time is still read.' );
		$this->assertNull( $actions[0]['earliest_due_at'] );
	}

	/**
	 * Rows written by an older Action Scheduler copy can carry a zero date for an async action.
	 *
	 * @testdox A queued action whose due date is $stored has no due time.
	 * @testWith ["0000-00-00 00:00:00"]
	 *           ["not a date"]
	 *           ["2026-02-30 10:00:00"]
	 *           [null]
	 *
	 * @param string|null $stored Stored due date.
	 */
	public function test_an_unreadable_due_date_reads_as_no_due_time( ?string $stored ): void {
		$wpdb                          = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'esc_like', 'get_results' ) )
			->getMock();
		$wpdb->actionscheduler_actions = 'wp_actionscheduler_actions';
		$wpdb->actionscheduler_groups  = 'wp_actionscheduler_groups';
		$wpdb->last_error              = '';
		$wpdb->method( 'has_cap' )->willReturn( true );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->method( 'get_results' )->willReturn(
			array(
				array(
					'action_id'    => '7',
					'hook'         => 'wcpay_preflight_service_test',
					'action_group' => 'woocommerce-payments',
					'due_gmt'      => $stored,
					'earliest_gmt' => $stored,
					'undated'      => '0',
				),
			)
		);

		$actions = $this->create_sut( $wpdb )->get_queued_woopayments_actions();
		$this->assertSame( array( null ), array_column( $actions, 'due_at' ) );
		$this->assertSame( array( null ), array_column( $actions, 'earliest_due_at' ) );
	}

	/**
	 * @testdox The unhandled queued actions are the rows of the unhandled queue hooks, and the hooks list is unchanged.
	 */
	public function test_unhandled_queue_actions_back_the_unhandled_queue_hooks(): void {
		$handled_id                     = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_track_new_order', array(), 'woocommerce-payments', true );
		$unhandled_id                   = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), 'woocommerce-payments', true );
		$this->scheduled_action_hooks[] = 'wcpay_track_new_order';
		$this->assertIsInt( $handled_id );
		$this->assertIsInt( $unhandled_id );
		$sut = $this->create_sut();

		$this->assertSame( array( $unhandled_id ), array_column( $sut->get_unhandled_queue_actions(), 'action_id' ) );
		$this->assertSame( array( 'wcpay_preflight_service_test' ), $sut->get_unhandled_queue_hooks() );
	}

	/**
	 * @testdox An Action Scheduler query failure remains an operational cutover blocker.
	 * @dataProvider operational_action_query_failure_provider
	 *
	 * @param array|false $database_results Action Scheduler query result.
	 * @param string      $database_error   Action Scheduler query error.
	 */
	public function test_operational_action_query_failure_fails_closed( $database_results, string $database_error ): void {
		$wpdb                          = $this->getMockBuilder( \wpdb::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'has_cap', 'prepare', 'esc_like', 'get_results' ) )
			->getMock();
		$wpdb->actionscheduler_actions = 'wp_actionscheduler_actions';
		$wpdb->actionscheduler_groups  = 'wp_actionscheduler_groups';
		$wpdb->last_error              = '';
		$wpdb->method( 'has_cap' )->with( 'identifier_placeholders' )->willReturn( true );
		$wpdb->method( 'prepare' )->willReturnArgument( 0 );
		$wpdb->method( 'esc_like' )->willReturnArgument( 0 );
		$wpdb->expects( $this->exactly( 2 ) )
			->method( 'get_results' )
			->willReturnCallback(
				static function () use ( $wpdb, $database_results, $database_error ) {
					$wpdb->last_error = $database_error;
					return $database_results;
				}
			);
		$sut = $this->create_sut( $wpdb );

		$this->assertSame(
			array(
				array(
					'action_id'       => 0,
					'hook'            => 'woocommerce_woopayments_operational_queue_query_failed',
					'group'           => '',
					'due_at'          => null,
					'earliest_due_at' => null,
				),
			),
			$sut->get_queued_woopayments_actions()
		);
		$this->assertSame( array( 'operational_queue_hooks_unhandled' ), $sut->get_failures() );
	}

	/**
	 * Provide Action Scheduler query failures that must block cutover.
	 *
	 * @return array<string,array{0:array|false,1:string}>
	 */
	public function operational_action_query_failure_provider(): array {
		return array(
			'non-array result'              => array( false, '' ),
			'empty result with query error' => array( array(), 'Action Scheduler query failed.' ),
		);
	}

	/**
	 * @testdox Deactivation succeeds without touching plugins when no WooPayments plugin is active.
	 */
	public function test_deactivation_succeeds_when_woopayments_is_already_inactive(): void {
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		$deactivations = 0;
		add_action(
			'deactivated_plugin',
			static function () use ( &$deactivations ): void {
				++$deactivations;
			}
		);

		$this->assertTrue( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertSame( 0, $deactivations );
	}

	/**
	 * The network list is read from the stored row, so a filter that hides the extension from it does not hide it here.
	 *
	 * @testdox Deactivation never reaches beyond the site: an extension active for every site stays active, and it reports false.
	 * @group multisite
	 */
	public function test_deactivation_never_reaches_beyond_the_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_option( 'active_plugins', array() );
		// WordPress stores active_sitewide_plugins as a plugin-file => activation-timestamp map (wp-admin/includes/plugin.php:712-715).
		update_site_option( 'active_sitewide_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => 1 ) );
		add_filter( 'site_option_active_sitewide_plugins', static fn() => array() );
		$calls = array();
		$this->register_legacy_proxy_function_mocks(
			array(
				// WordPress 7.1.3 wp-admin/includes/plugin.php:764 deactivate_plugins( $plugins, $silent = false, $network_wide = null ).
				'deactivate_plugins' => function ( $plugin, $silent = false, $for_every_site = null ) use ( &$calls ): void {
					$calls[] = array( $plugin, $silent, $for_every_site );
				},
			)
		);

		$this->assertFalse( $this->create_sut()->deactivate_woopayments_plugin(), 'The extension is still active for every site.' );
		$this->assertSame( array( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false, false ) ), $calls );
	}

	/**
	 * WordPress's deactivate_plugins() reads active_plugins through get_option() and writes back what it read
	 * (wp-admin/includes/plugin.php:768, :851), so the hooks it runs during the call must read the stored list too.
	 *
	 * @testdox The deactivation's hooks read the stored plugin list, and the request's own filter applies again after the call, also when a hook throws.
	 */
	public function test_the_stored_view_ends_with_the_deactivation_even_when_a_hook_throws(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		add_filter( 'option_active_plugins', static fn( $plugins ) => array_values( array_diff( (array) $plugins, array( 'hello-dolly/hello.php' ) ) ) );
		$seen_during_call = null;
		add_action(
			'deactivate_plugin',
			static function () use ( &$seen_during_call ): void {
				$seen_during_call = get_option( 'active_plugins' );
				throw new \RuntimeException( 'A deactivation hook failed.' );
			}
		);

		$thrown = null;
		try {
			$this->create_sut()->deactivate_woopayments_plugin();
		} catch ( \RuntimeException $error ) {
			$thrown = $error;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown, 'The hook failure reaches the caller.' );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ), $seen_during_call, 'A hook during the call reads the stored list.' );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), get_option( 'active_plugins' ), "After the call this request's filter applies again." );
		$this->assertSame( array(), $GLOBALS['wp_filter']['pre_option']->callbacks[ PHP_INT_MAX ] ?? array(), 'No callback is left on the generic pre_option filter.' );
	}

	/**
	 * @testdox Deactivation reports success from the stored list while this request's filter still shows the extension.
	 */
	public function test_deactivation_reports_from_the_stored_list(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		add_filter( 'option_active_plugins', static fn() => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$sut = $this->create_sut();

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertFalse( $sut->is_woopayments_stored_site_active() );
		$this->assertTrue( $sut->is_woopayments_site_active(), "This request's filtered list still shows the extension." );
	}

	/**
	 * @testdox Every other option read during the deactivation comes back as it would without the switch's stored view.
	 */
	public function test_the_stored_view_leaves_every_other_option_untouched(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		update_option( 'blogname', 'Stored store name' );
		add_filter( 'pre_option', static fn( $pre, $option ) => 'woocommerce_cutover_test_probe' === $option ? 'from another pre_option callback' : $pre, 10, 2 );
		$seen_during_call = null;
		add_action(
			'deactivate_plugin',
			static function () use ( &$seen_during_call ): void {
				update_option( 'woocommerce_cutover_test_written', array( 'another option' ) );
				$seen_during_call = array( get_option( 'woocommerce_cutover_test_probe' ), get_option( 'blogname' ), get_option( 'active_plugins' ) );
			}
		);

		$this->assertTrue( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertSame( array( 'from another pre_option callback', 'Stored store name', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ), $seen_during_call, 'A write of another option leaves the plugin list as read.' );
	}

	/**
	 * The start notice asks on every admin page of an extension store, so a list holding the canonical path must not read
	 * the installed plugin registry, whatever is listed before it.
	 *
	 * @testdox The stored lists are found to hold WooPayments by its canonical path without reading the installed plugins; a list without it is checked against one registry read.
	 */
	public function test_the_stored_list_check_reads_the_plugin_registry_only_without_the_canonical_path(): void {
		$registry_reads = 0;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_plugins' => function () use ( &$registry_reads ): array {
					++$registry_reads;
					return array(
						'hello-dolly/hello.php' => array(
							'Name'       => 'Hello Dolly',
							'TextDomain' => 'hello-dolly',
						),
						'akismet/akismet.php'   => array(
							'Name'       => 'Akismet Anti-spam',
							'TextDomain' => 'akismet',
						),
						'payments-moved/woocommerce-payments.php' => array(
							'Name'       => 'WooPayments',
							'TextDomain' => 'woocommerce-payments',
						),
					);
				},
			)
		);
		$sut = $this->create_sut();

		update_option( 'active_plugins', array( 'hello-dolly/hello.php', WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$this->assertTrue( $sut->is_woopayments_in_stored_lists() );
		$this->assertSame( 0, $registry_reads, 'The canonical path is found without the installed plugins.' );

		update_option( 'active_plugins', array( 'hello-dolly/hello.php', 'akismet/akismet.php', 'payments-moved/woocommerce-payments.php' ) );
		$this->assertTrue( $sut->is_woopayments_stored_site_active(), 'WooPayments in another folder is still found by its header.' );
		$this->assertSame( 1, $registry_reads, 'One read of the installed plugins for the whole list.' );

		update_option( 'active_plugins', array( 'hello-dolly/hello.php', 'akismet/akismet.php' ) );
		$this->assertFalse( $sut->is_woopayments_stored_site_active() );
		$this->assertSame( 2, $registry_reads, 'One read of the installed plugins for the whole list.' );
	}

	/**
	 * The switch decides from the stored lists, so a read that failed must not look like a list without the extension.
	 *
	 * @testdox A stored site list that cannot be read is unknown, not empty, and the next read that works answers again.
	 */
	public function test_a_stored_site_list_that_cannot_be_read_is_unknown(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$sut                               = $this->create_sut();
		$this->read_failure                = new StoredPluginListReadFailure();
		$this->read_failure->site_failures = 2;

		$this->assertNull( $sut->is_woopayments_stored_site_active() );
		$this->assertNull( $sut->is_woopayments_in_stored_lists() );
		$this->assertTrue( $sut->is_woopayments_stored_site_active() );
	}

	/**
	 * @testdox On a multisite, a stored network list that cannot be read is unknown, unless the site list holds WooPayments.
	 * @group multisite
	 */
	public function test_a_stored_network_list_that_cannot_be_read_is_unknown(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_option( 'active_plugins', array() );
		update_site_option( 'active_sitewide_plugins', array() );
		$sut                                  = $this->create_sut();
		$this->read_failure                   = new StoredPluginListReadFailure();
		$this->read_failure->network_failures = 2;

		$this->assertNull( $sut->is_woopayments_stored_network_active() );
		$this->assertNull( $sut->is_woopayments_in_stored_lists(), 'A clear site list says nothing about the network list.' );

		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$this->read_failure->network_failures = 1;

		$this->assertTrue( $sut->is_woopayments_in_stored_lists(), 'The site list holding WooPayments is a reading.' );
	}

	/**
	 * @testdox Deactivation reports false, deactivates nothing and runs none of the extension's deactivation callbacks when the stored list cannot be read to find WooPayments.
	 */
	public function test_deactivation_fails_closed_when_the_stored_list_cannot_be_read(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$deactivations = 0;
		$count         = static function () use ( &$deactivations ): void {
			++$deactivations;
		};
		add_action( 'deactivate_plugin', $count );
		add_action( 'deactivate_' . WooPaymentsRuntimeArbiter::PLUGIN_FILE, $count );
		$sut                               = $this->create_sut();
		$this->read_failure                = new StoredPluginListReadFailure();
		$this->read_failure->site_failures = 1;

		$this->assertFalse( $sut->deactivate_woopayments_plugin() );
		$this->assertSame( 0, $deactivations );
		$this->assertTrue( $sut->is_woopayments_stored_site_active(), 'The extension stays stored.' );
	}

	/**
	 * @testdox On a multisite, deactivation reports false when the site list is clear and the network list cannot be read.
	 * @group multisite
	 */
	public function test_deactivation_fails_closed_when_the_stored_network_list_cannot_be_read(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_option( 'active_plugins', array() );
		// WordPress stores active_sitewide_plugins as a plugin-file => activation-timestamp map (wp-admin/includes/plugin.php:712-715).
		update_site_option( 'active_sitewide_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => 1 ) );
		$sut                                  = $this->create_sut();
		$this->read_failure                   = new StoredPluginListReadFailure();
		$this->read_failure->network_failures = 1;

		$this->assertFalse( $sut->deactivate_woopayments_plugin(), 'Not "nothing to deactivate".' );
		$this->assertSame( 0, $this->read_failure->network_failures );
	}

	/**
	 * update_option() writes only a value that differs from the old one it reads. When that read fails, WordPress answers it
	 * from its own cached list, which still holds the extension.
	 *
	 * @testdox When WooPayments is the site list's only entry, the deactivation writes an empty list, even when update_option()'s read of the old list fails.
	 */
	public function test_the_sole_site_entry_is_written_as_an_empty_list(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$sut = $this->create_sut();
		$this->fail_a_stored_read_after_the_deactivation_callbacks();

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertSame( 0, $this->read_failure->site_failures, 'The failure was spent inside the call.' );
		$this->assertSame( array(), $this->read_stored_active_plugins() );
	}

	/**
	 * A read from the generic update_option action runs after WordPress has prepared the new list and before it writes it.
	 *
	 * @testdox A read from the update_option action, before WordPress writes the list, sees the stored list with WooPayments still in it.
	 */
	public function test_a_read_before_the_deactivation_write_sees_the_stored_list(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		$seen = null;
		add_action(
			'update_option',
			static function ( $option ) use ( &$seen ): void {
				if ( 'active_plugins' === $option ) {
					$seen = get_option( 'active_plugins' );
				}
			}
		);

		$this->assertTrue( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ), $seen );
		$this->assertSame( array( 'hello-dolly/hello.php' ), array_values( (array) $this->read_stored_active_plugins() ) );
	}

	/**
	 * A guard registered during the call at the last priority runs after every callback already there, so a list kept from
	 * an earlier filter would miss its change; a read of the stored row sees it.
	 *
	 * @testdox A write guard added during the deactivation at the last priority is in what later reads see, and a nested plugin change keeps it.
	 */
	public function test_a_write_guard_added_during_the_deactivation_is_kept(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'other-plugin/other-plugin.php' ) );
		// Adds its entry when WooPayments leaves the list, so it acts on the deactivation's write only.
		$guard = static function ( $value, $option, $old_value ) {
			if ( 'active_plugins' === $option && is_array( $value ) && in_array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, (array) $old_value, true ) && ! in_array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, $value, true ) ) {
				$value[] = 'guarded/guarded.php';
			}
			return $value;
		};
		add_action(
			'deactivate_plugin',
			static function () use ( $guard ): void {
				add_filter( 'pre_update_option', $guard, PHP_INT_MAX, 3 );
			}
		);
		$seen   = null;
		$result = 'not run';
		add_action(
			'updated_option',
			static function ( $option ) use ( &$seen, &$result ): void {
				if ( 'active_plugins' === $option && null === $seen ) {
					$seen   = get_option( 'active_plugins' );
					$result = activate_plugin( 'hello.php', '', false, true );
				}
			}
		);

		$this->assertTrue( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertSame(
			array(
				1 => 'other-plugin/other-plugin.php',
				2 => 'guarded/guarded.php',
			),
			$seen,
			'The read after the write sees the guarded list as stored.'
		);
		$this->assertNull( $result, 'The nested activation succeeded.' );
		$this->assertSame( array( 'guarded/guarded.php', 'hello.php', 'other-plugin/other-plugin.php' ), $this->read_stored_active_plugins() );
	}

	/**
	 * A plugin load manager answers through pre_option_active_plugins or filters option_active_plugins; a read of ours that
	 * fails must leave the answer to WordPress and those filters, not invent one.
	 *
	 * @dataProvider failed_read_provider
	 * @testdox A stored read that fails inside the deactivation is answered by WordPress as if the switch's filter were not there: $_dataName.
	 *
	 * @param string   $read_filter Which read filter this request has, if any.
	 * @param string[] $expected    What the failed read answers.
	 */
	public function test_a_failed_read_inside_the_deactivation_falls_back_to_wordpress( string $read_filter, array $expected ): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		if ( 'option' === $read_filter ) {
			add_filter( 'option_active_plugins', static fn( $plugins ) => array_values( array_diff( (array) $plugins, array( 'hello-dolly/hello.php' ) ) ) );
		} elseif ( 'pre_option' === $read_filter ) {
			add_filter( 'pre_option_active_plugins', static fn() => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		}
		$sut                = $this->create_sut();
		$this->read_failure = new StoredPluginListReadFailure();
		$read_failure       = $this->read_failure;
		$seen               = null;
		$spent              = null;
		add_action(
			'deactivate_plugin',
			static function () use ( $read_failure, &$seen, &$spent ): void {
				$read_failure->site_failures = 1;
				$seen                        = get_option( 'active_plugins' );
				$spent                       = 0 === $read_failure->site_failures;
			}
		);

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertTrue( $spent, 'The read inside the call queried the stored row.' );
		$this->assertSame( $expected, $seen );
		$this->assertSame( array( 'hello-dolly/hello.php' ), array_values( (array) $this->read_stored_active_plugins() ) );
	}

	/**
	 * @return array<string,array{string,string[]}>
	 */
	public function failed_read_provider(): array {
		return array(
			'no read filter, the stored list'            => array( '', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) ),
			'an option_active_plugins filter'            => array( 'option', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ),
			'a pre_option_active_plugins filter answers' => array( 'pre_option', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ),
		);
	}

	/**
	 * Plain WordPress answers this read from its cache, so the listener's own read runs no query. Inside the call each read
	 * of the list is a query, which runs the listener again. The listener stops reading past a small depth, so a missing
	 * guard fails this row instead of hanging the suite.
	 *
	 * @testdox A listener on the database's query filter that reads plugin activity during the deactivation does not re-enter it without end.
	 * @group multisite
	 */
	public function test_a_query_listener_that_reads_plugin_activity_does_not_recurse(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		$network_plugins = array( 'network-plugin/network-plugin.php' => 1 );
		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', $network_plugins );
		}
		$sut      = $this->create_sut();
		$depth    = 0;
		$deepest  = 0;
		$listener = static function ( $query ) use ( &$depth, &$deepest ) {
			if ( $depth >= 5 ) {
				$deepest = $depth + 1;
				return $query;
			}
			++$depth;
			$deepest = max( $deepest, $depth );
			try {
				is_plugin_active( 'hello-dolly/hello.php' );
			} finally {
				--$depth;
			}
			return $query;
		};
		add_filter( 'query', $listener );

		$result = $sut->deactivate_woopayments_plugin();
		remove_filter( 'query', $listener );

		// A read under one of WordPress's own queries runs the switch's query once more; the read under that query runs none.
		$this->assertSame( 2, $deepest, 'The listener ran at most one level inside itself.' );
		$this->assertTrue( $result );
		$this->assertSame( array( 1 => 'hello-dolly/hello.php' ), $this->read_stored_active_plugins() );
		if ( is_multisite() ) {
			global $wpdb;
			$stored_network = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = 'active_sitewide_plugins'", get_current_network_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row is what the row checks.
			$this->assertSame( $network_plugins, maybe_unserialize( (string) $stored_network ) );
		}
		$this->assertSame( array(), $GLOBALS['wp_filter']['pre_option']->callbacks[ PHP_INT_MAX ] ?? array(), 'No callback is left on the generic pre_option filter.' );
	}

	/**
	 * A plugin load manager's read filter hides hello-dolly, so WordPress's own answer differs from the stored row. A listener
	 * that throws out of the switch's query leaves the next read to query the stored row again.
	 *
	 * @dataProvider nested_read_provider
	 * @testdox A read of the plugin list made while the deactivation's own query runs gets WordPress's answer, and the read around it gets the stored list: $_dataName.
	 *
	 * @param bool $listener_throws Whether the query listener throws after its read.
	 */
	public function test_a_read_during_the_deactivation_query_gets_the_wordpress_answer( bool $listener_throws ): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		add_filter( 'option_active_plugins', static fn( $plugins ) => array_values( array_diff( (array) $plugins, array( 'hello-dolly/hello.php' ) ) ) );
		$sut    = $this->create_sut();
		$armed  = false;
		$nested = null;
		add_filter(
			'query',
			static function ( $query ) use ( &$armed, &$nested, $listener_throws ) {
				if ( $armed && is_string( $query ) && false !== strpos( $query, "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'active_plugins' LIMIT 1" ) ) {
					$armed  = false;
					$nested = get_option( 'active_plugins' );
					if ( $listener_throws ) {
						throw new \RuntimeException( 'Query listener failure.' );
					}
				}
				return $query;
			}
		);
		$outer = null;
		add_action(
			'deactivate_plugin',
			static function () use ( &$armed, &$outer ): void {
				$armed = true;
				try {
					$outer = get_option( 'active_plugins' );
				} catch ( \RuntimeException $e ) {
					$outer = get_option( 'active_plugins' );
				}
			}
		);

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), $nested, "The nested read gets WordPress's own, filtered answer." );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ), $outer, 'The read around it gets the stored list.' );
		$this->assertSame( array( 'hello-dolly/hello.php' ), array_values( (array) $this->read_stored_active_plugins() ) );
	}

	/**
	 * @return array<string,array{bool}>
	 */
	public function nested_read_provider(): array {
		return array(
			'the listener returns'                     => array( false ),
			'the listener throws and the read repeats' => array( true ),
		);
	}

	/**
	 * WordPress runs the query log hook after a query runs and before it reads that query's result. Plain WordPress answers
	 * the listener's read from its cache; a query of the switch's there would replace the other query's result. The listener
	 * does not read inside itself, so a missing check cannot hang the suite. Query logging stays on for the whole process.
	 *
	 * @testdox With query logging on, a log listener that reads plugin activity leaves an uncached option read during the deactivation its own value.
	 * @group multisite
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_query_log_listener_that_reads_plugin_activity_leaves_another_read_its_own_value(): void {
		define( 'SAVEQUERIES', true );
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		$network_plugins = array( 'network-plugin/network-plugin.php' => 1 );
		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', $network_plugins );
		}
		add_option( 'woocommerce_cutover_test_uncached', 'its own value', '', false );
		wp_cache_delete( 'woocommerce_cutover_test_uncached', 'options' );
		$sut  = $this->create_sut();
		$seen = null;
		add_action(
			'deactivate_plugin',
			static function () use ( &$seen ): void {
				$seen = get_option( 'woocommerce_cutover_test_uncached' );
			}
		);
		$listening = false;
		add_filter(
			'log_query_custom_data',
			static function ( $query_data ) use ( &$listening ) {
				if ( ! $listening ) {
					$listening = true;
					try {
						is_plugin_active( 'hello-dolly/hello.php' );
					} finally {
						$listening = false;
					}
				}
				return $query_data;
			}
		);

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertSame( 'its own value', $seen, 'The option read returns its own value.' );
		$this->assertSame( 'its own value', wp_cache_get( 'woocommerce_cutover_test_uncached', 'options' ), 'The option caches its own value.' );
		$this->assertSame( array( 1 => 'hello-dolly/hello.php' ), $this->read_stored_active_plugins() );
		if ( is_multisite() ) {
			global $wpdb;
			$stored_network = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = 'active_sitewide_plugins'", get_current_network_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row is what the row checks.
			$this->assertSame( $network_plugins, maybe_unserialize( (string) $stored_network ) );
		}
		$this->assertSame( array(), $GLOBALS['wp_filter']['pre_option']->callbacks[ PHP_INT_MAX ] ?? array(), 'No callback is left on the generic pre_option filter.' );
	}

	/**
	 * Each read queries the options table of the blog current at that moment.
	 *
	 * @testdox On a multisite, a plugin activated on another site by a listener inside the deactivation leaves both sites' stored lists exact.
	 * @group multisite
	 */
	public function test_a_nested_activation_on_another_site_keeps_both_lists(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'other-plugin/other-plugin.php' ) );
		$second_blog_id = self::factory()->blog->create();
		switch_to_blog( $second_blog_id );
		update_option( 'active_plugins', array( 'site-two/site-two.php' ) );
		restore_current_blog();
		$result = 'not run';
		add_action(
			'updated_option',
			static function ( $option ) use ( $second_blog_id, &$result ): void {
				if ( 'active_plugins' === $option && 'not run' === $result ) {
					$result = null;
					switch_to_blog( $second_blog_id );
					$result = activate_plugin( 'hello.php', '', false, true );
					restore_current_blog();
				}
			}
		);

		$this->assertTrue( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertNull( $result, 'The nested activation succeeded.' );
		$this->assertSame( array( 1 => 'other-plugin/other-plugin.php' ), $this->read_stored_active_plugins() );
		switch_to_blog( $second_blog_id );
		$second_site_plugins = $this->read_stored_active_plugins();
		restore_current_blog();
		$this->assertSame( array( 'hello.php', 'site-two/site-two.php' ), $second_site_plugins );
	}

	/**
	 * A write guard on the generic pre_update_option filter changes the value after the option's own filter; a read after
	 * the write gets the stored row, so it sees that change.
	 *
	 * @testdox A read made after WordPress wrote the list, inside the deactivation, sees the list as stored, a late write guard's change included.
	 */
	public function test_a_read_after_the_deactivation_write_sees_the_committed_list(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		add_filter(
			'pre_update_option',
			static function ( $value, $option ) {
				if ( 'active_plugins' === $option && is_array( $value ) && ! in_array( 'guarded/guarded.php', $value, true ) ) {
					$value[] = 'guarded/guarded.php';
				}
				return $value;
			},
			1000,
			2
		);
		$seen = array();
		add_action(
			'update_option_active_plugins',
			static function () use ( &$seen ): void {
				$seen['update_option_active_plugins'] = get_option( 'active_plugins' );
			}
		);
		add_action(
			'updated_option',
			static function ( $option ) use ( &$seen ): void {
				if ( 'active_plugins' === $option ) {
					$seen['updated_option'] = get_option( 'active_plugins' );
				}
			}
		);
		$sut = $this->create_sut();
		$this->fail_a_stored_read_after_the_deactivation_callbacks();

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$stored = $this->read_stored_active_plugins();
		$this->assertSame( array( 'hello-dolly/hello.php', 'guarded/guarded.php' ), array_values( (array) $stored ) );
		$this->assertSame(
			array(
				'update_option_active_plugins' => $stored,
				'updated_option'               => $stored,
			),
			$seen
		);
	}

	/**
	 * @testdox A plugin-list change made after WordPress wrote the list, inside the deactivation, keeps WooPayments out and persists.
	 */
	public function test_a_plugin_list_change_after_the_deactivation_write_does_not_restore_woopayments(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		$changed = false;
		add_action(
			'updated_option',
			static function ( $option ) use ( &$changed ): void {
				if ( 'active_plugins' === $option && ! $changed ) {
					$changed = true;
					deactivate_plugins( 'hello-dolly/hello.php', true, false );
				}
			}
		);
		$sut = $this->create_sut();

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertTrue( $changed );
		$this->assertFalse( $sut->is_woopayments_stored_site_active(), 'WooPayments stays out of the stored list.' );
		$this->assertSame( array(), array_values( (array) get_option( 'active_plugins' ) ), 'The other change persists.' );
	}

	/**
	 * WordPress's activate_plugin() reads active_plugins, adds its plugin and writes the list back, so a listener after the
	 * deactivation's write that activates a plugin keeps whatever that read answers.
	 *
	 * @dataProvider nested_activation_provider
	 * @testdox A plugin activated after WordPress wrote the list, inside the deactivation, is added to the stored list and every other plugin stays: $_dataName.
	 *
	 * @param bool $hide_a_plugin Whether this request's read filter hides one of the other plugins.
	 */
	public function test_a_nested_activation_after_the_deactivation_write_keeps_the_other_plugins( bool $hide_a_plugin ): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'other-plugin/other-plugin.php', 'woocommerce/woocommerce.php' ) );
		if ( $hide_a_plugin ) {
			add_filter( 'option_active_plugins', static fn( $plugins ) => array_values( array_diff( (array) $plugins, array( 'other-plugin/other-plugin.php' ) ) ) );
		}
		$activated = false;
		$result    = 'not run';
		add_action(
			'updated_option',
			static function ( $option ) use ( &$activated, &$result ): void {
				if ( 'active_plugins' === $option && ! $activated ) {
					$activated = true;
					// Hello Dolly ships with WordPress; silent, so its activation hooks do not run.
					$result = activate_plugin( 'hello.php', '', false, true );
				}
			}
		);
		$sut = $this->create_sut();
		$this->fail_a_stored_read_after_the_deactivation_callbacks();

		$this->assertTrue( $sut->deactivate_woopayments_plugin() );
		$this->assertNull( $result, 'The nested activation succeeded.' );
		$this->assertSame( array( 'hello.php', 'other-plugin/other-plugin.php', 'woocommerce/woocommerce.php' ), $this->read_stored_active_plugins() );
	}

	/**
	 * @return array<string,array{bool}>
	 */
	public function nested_activation_provider(): array {
		return array(
			'no read filter'                  => array( false ),
			'a read filter hides one of them' => array( true ),
		);
	}

	/**
	 * @testdox A write guard on the generic pre_update_option filter that restores the old list leaves the stored list unchanged, and the deactivation reports false.
	 */
	public function test_a_write_guard_that_restores_the_list_leaves_it_stored(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ) );
		add_filter( 'pre_update_option', static fn( $value, $option, $old_value ) => 'active_plugins' === $option ? $old_value : $value, 1000, 3 );

		$this->assertFalse( $this->create_sut()->deactivate_woopayments_plugin() );
		$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, 'hello-dolly/hello.php' ), $this->read_stored_active_plugins() );
	}

	/**
	 * @testdox Deactivation reports false when the stored list cannot be read after WordPress wrote it.
	 */
	public function test_deactivation_reports_false_when_the_check_after_cannot_read(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$sut                = $this->create_sut();
		$this->read_failure = new StoredPluginListReadFailure();
		$read_failure       = $this->read_failure;
		add_action(
			'update_option_active_plugins',
			static function () use ( $read_failure ): void {
				$read_failure->site_failures = 1;
			}
		);

		$this->assertFalse( $sut->deactivate_woopayments_plugin() );
		$this->assertFalse( $sut->is_woopayments_stored_site_active(), 'WordPress did deactivate it; the check could not tell.' );
	}

	/**
	 * @testdox A single site never reports the extension as active for every site, whatever its network plugin list holds.
	 */
	public function test_network_activity_is_only_reported_on_a_multisite(): void {
		$is_multisite = false;
		// WordPress stores active_sitewide_plugins as a plugin-file => activation-timestamp map (wp-admin/includes/plugin.php:712-715).
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_site_option' => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => 1 ) : get_site_option( $name, $default_value ),
				'is_multisite'    => function () use ( &$is_multisite ) {
					return $is_multisite;
				},
			)
		);
		$sut = $this->create_sut();

		$this->assertFalse( $sut->is_woopayments_network_active(), 'A single site has no network plugin list.' );

		$is_multisite = true;

		$this->assertTrue( $sut->is_woopayments_network_active(), 'On a multisite the network plugin list counts.' );
	}

	/**
	 * Which plugins count, and how they are found and named, is ExtensionBoundRenewalPluginsTest's.
	 *
	 * @testdox The switch is held back while a plugin that renews through the extension is active: $_dataName.
	 * @dataProvider provide_renewal_plugin_holdbacks
	 *
	 * @param string[] $active_plugins Active plugin files of the site.
	 * @param bool     $held_back      Whether the switch is held back.
	 */
	public function test_extension_renewal_plugin_holds_the_switch_back( array $active_plugins, bool $held_back ): void {
		$this->fake_active_plugins( $active_plugins, array(), false );

		$this->assertSame( $held_back ? array( PreflightService::FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE ) : array(), $this->create_sut()->get_failures() );
	}

	/**
	 * @return array<string,array{string[],bool}>
	 */
	public function provide_renewal_plugin_holdbacks(): array {
		return array(
			'a listed plugin' => array( array( 'woocommerce/woocommerce.php', 'order-subscriptions/order-subscriptions.php' ), true ),
			'none of them'    => array( array( 'woocommerce/woocommerce.php', 'yith-woocommerce-subscription/init.php' ), false ),
		);
	}

	/**
	 * @testdox The preflight reports the failure the store causes: $code ($_dataName).
	 * @dataProvider reported_failure_provider
	 *
	 * @param string   $code    Failure code the arrangement causes.
	 * @param callable $arrange Arranges the store; receives this test.
	 */
	public function test_preflight_reports_the_failure_the_store_causes( string $code, callable $arrange ): void {
		$arrange( $this );

		$this->assertContains( $code, $this->create_sut()->get_failures() );
	}

	/**
	 * Stores that cause one failure each, with the one check the failure belongs to arranged on its own.
	 *
	 * @return array<string,array{string,callable}>
	 */
	public function reported_failure_provider(): array {
		$unsupported_version = static function ( string $version ): callable {
			return static function ( self $test ) use ( $version ): void {
				unset( $test );
				update_option( 'woocommerce_woocommerce_payments_version', $version );
			};
		};

		return array(
			'the built-in provider cannot process payments' => array(
				'builtin_transport_unavailable',
				static function ( self $test ): void {
					$test->provider_ready = false;
				},
			),
			'the platform connection owner is unavailable' => array(
				'wpcom_connection_owner_unavailable',
				static function ( self $test ): void {
					$test->platform_failures = array( 'wpcom_connection_owner_unavailable' );
				},
			),
			'automatic multi-currency rates have no provider' => array(
				'multi_currency_rates_unavailable',
				static function ( self $test ): void {
					$test->enable_multi_currency_with_rate_type( 'automatic' );
					$test->rate_api_client_connected = false;
				},
			),
			'the recorded WooPayments version is missing'  => array( 'woopayments_plugin_version_unsupported', $unsupported_version( '' ) ),
			'the recorded WooPayments version is malformed' => array( 'woopayments_plugin_version_unsupported', $unsupported_version( 'not-a-version' ) ),
			'the recorded WooPayments version is older than the floor' => array( 'woopayments_plugin_version_unsupported', $unsupported_version( '10.5.2' ) ),
			'an unknown WooPayments action is pending'     => array(
				'operational_queue_hooks_unhandled',
				static function ( self $test ): void {
					$test->schedule_queue_action( 'wcpay_synthetic_cutover_probe' );
				},
			),
			// The extension's check writes only the extension's own option, so the switch waits until the extension has run it.
			'an extension affected-order check is pending' => array(
				'operational_queue_hooks_unhandled',
				static function ( self $test ): void {
					$test->schedule_queue_action( 'wcpay_check_affected_auth_fee_orders' );
				},
			),
			'a Stripe-billed subscription marker exists'   => array(
				'bundled_stripe_billing_subscriptions_present',
				static function ( self $test ): void {
					$test->create_bundled_stripe_billing_subscription( 'pending' );
				},
			),
			'the bundled WooPayments subscriptions are on' => array(
				'bundled_stripe_billing_subscriptions_present',
				static function ( self $test ): void {
					unset( $test );
					update_option( '_wcpay_feature_subscriptions', '1' );
				},
			),
			'a Stripe-billed subscription marker sits in HPOS order meta' => array(
				'bundled_stripe_billing_subscriptions_present',
				static function ( self $test ): void {
					$test->create_bundled_stripe_billing_hpos_marker( '_wcpay_subscription_id' );
				},
			),
		);
	}

	/**
	 * @testdox The preflight does not report $code when the store is arranged this way ($_dataName).
	 * @dataProvider unreported_failure_provider
	 *
	 * @param string   $code    Failure code the arrangement does not cause.
	 * @param callable $arrange Arranges the store; receives this test.
	 */
	public function test_preflight_does_not_report_a_failure_the_store_does_not_cause( string $code, callable $arrange ): void {
		$arrange( $this );

		$this->assertNotContains( $code, $this->create_sut()->get_failures() );
	}

	/**
	 * Stores close to a failure that must not report it: manual rates, an available rate provider, queued actions the
	 * built-in WooPayments handles, and Stripe Billing leftovers a migration leaves behind.
	 *
	 * @return array<string,array{string,callable}>
	 */
	public function unreported_failure_provider(): array {
		$rows = array(
			'manual multi-currency rates without a rate provider'           => array(
				'multi_currency_rates_unavailable',
				static function ( self $test ): void {
					$test->enable_multi_currency_with_rate_type( 'manual' );
					$test->rate_api_client_connected = false;
				},
			),
			'automatic multi-currency rates with an available rate provider' => array(
				'multi_currency_rates_unavailable',
				static function ( self $test ): void {
					$test->enable_multi_currency_with_rate_type( 'automatic' );
					$test->rate_api_client_connected = true;
				},
			),
		);
		foreach ( $this->builtin_handled_queue_hook_provider() as $label => $hook_row ) {
			$hook                          = $hook_row[0];
			$rows[ 'a pending ' . $label ] = array(
				'operational_queue_hooks_unhandled',
				static function ( self $test ) use ( $hook ): void {
					$test->schedule_queue_action( $hook );
				},
			);
		}
		// The extension's migrator leaves only `_migrated_*` meta on subscriptions it moved off Stripe Billing (client
		// `includes/subscriptions/class-wc-payments-subscriptions-migrator.php:271-300`).
		$rows['a store migrated off Stripe Billing']               = array(
			'bundled_stripe_billing_subscriptions_present',
			static function ( self $test ): void {
				$test->create_bundled_stripe_billing_subscription( 'active', '_migrated_wcpay_subscription_id' );
				$test->create_bundled_stripe_billing_subscription( 'active', '_wcpay_subscription_migrated_during' );
			},
		);
		$rows['orders that keep their Stripe Billing invoice IDs'] = array(
			'bundled_stripe_billing_subscriptions_present',
			static function ( self $test ): void {
				unset( $test );
				$order = wc_create_order();
				$order->update_meta_data( '_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
				$order->update_meta_data( '_migrated_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
				$order->save();
			},
		);

		return $rows;
	}

	/**
	 * Native-owned Action Scheduler hooks.
	 *
	 * @return array<string,array{string}>
	 */
	public function builtin_handled_queue_hook_provider(): array {
		return array(
			'store setup sync'                      => array( 'wcpay_store_setup_sync' ),
			'update saved payment method'           => array( 'wcpay_update_saved_payment_method' ),
			'fee breakdown order note'              => array( 'wcpay_add_fee_breakdown_to_order_notes' ),
			'compatibility data update'             => array( 'wcpay_update_compatibility_data' ),
			'instant deposit reminder'              => array( 'wcpay_instant_deposit_reminder' ),
			'post-KYC activation email'             => array( 'wcpay_post_kyc_activation_email_send' ),
			'new-order tracking'                    => array( 'wcpay_track_new_order' ),
			'updated-order tracking'                => array( 'wcpay_track_update_order' ),
			'authorization-fee remediation'         => array( 'wcpay_remediate_canceled_authorization_fees' ),
			'authorization-fee dry run'             => array( 'wcpay_remediate_canceled_authorization_fees_dry_run' ),
			'built-in authorization-fee order scan' => array( 'woocommerce_woopayments_check_canceled_auth_orders' ),
			'failed webhook fetch'                  => array( 'wcpay_webhook_fetch_events' ),
			'failed webhook processing'             => array( 'wcpay_webhook_process_event' ),
			'Stripe Billing migration scheduling'   => array( 'wcpay_schedule_subscription_migrations' ),
			'Stripe Billing migration'              => array( 'wcpay_migrate_subscription' ),
			'Stripe Billing migration retry'        => array( 'wcpay_migrate_subscription_retry' ),
		);
	}

	/**
	 * Set the next stored site-list read to fail once the deactivation's callbacks have run, just before WordPress writes
	 * the list: the read that fails is update_option()'s read of the old list.
	 */
	private function fail_a_stored_read_after_the_deactivation_callbacks(): void {
		$this->read_failure = new StoredPluginListReadFailure();
		$read_failure       = $this->read_failure;
		add_action(
			'deactivated_plugin',
			static function () use ( $read_failure ): void {
				$read_failure->site_failures = 1;
			}
		);
	}

	/**
	 * Read this site's active plugin list as stored, by SQL.
	 *
	 * @return mixed The stored value, unserialized.
	 */
	private function read_stored_active_plugins() {
		global $wpdb;
		return maybe_unserialize( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The stored row is what the rows check.
	}

	/**
	 * Create a headless preflight service with deterministic provider seams.
	 *
	 * @param \wpdb|null $database Optional WordPress database connection.
	 * @return PreflightService
	 */
	private function create_sut( ?\wpdb $database = null ): PreflightService {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )->disableOriginalConstructor()->onlyMethods( array( 'can_process_payments' ) )->getMock();
		$provider->method( 'can_process_payments' )->willReturnCallback(
			function (): bool {
				return $this->provider_ready;
			}
		);
		$platform_connection = $this->getMockBuilder( PlatformConnectionService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_preflight_failures' ) )->getMock();
		$platform_connection->method( 'get_preflight_failures' )->willReturnCallback(
			function (): array {
				return $this->platform_failures;
			}
		);
		$rate_account = $this->getMockBuilder( WooPaymentsNativeAccountAdapter::class )->disableOriginalConstructor()->onlyMethods( array( 'is_provider_connected', 'is_account_rejected' ) )->getMock();
		$rate_account->method( 'is_provider_connected' )->willReturn( true );
		$rate_account->method( 'is_account_rejected' )->willReturn( false );
		$rate_api_client = $this->getMockBuilder( WooPaymentsNativeApiClientAdapter::class )->disableOriginalConstructor()->onlyMethods( array( 'is_server_connected' ) )->getMock();
		$rate_api_client->method( 'is_server_connected' )->willReturnCallback(
			function (): bool {
				return $this->rate_api_client_connected;
			}
		);
		$sut = null === $database ? new PreflightService() : new class( $database ) extends PreflightService {
			/**
			 * WordPress database connection.
			 *
			 * @var \wpdb
			 */
			private \wpdb $database;

			/**
			 * Initialize the database-backed test double.
			 *
			 * @param \wpdb $database WordPress database access abstraction.
			 */
			public function __construct( \wpdb $database ) {
				$this->database = $database;
			}

			/**
			 * Get the WordPress database connection.
			 *
			 * @return \wpdb
			 */
			protected function get_database(): \wpdb {
				return $this->database;
			}
		};
		$this->init_preflight_service( $sut, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ), $provider, new BundledSubscriptionsGuard(), $platform_connection, $rate_account, $rate_api_client );
		return $sut;
	}

	/**
	 * Control the site and network active plugin lists.
	 *
	 * WordPress stores active_sitewide_plugins as a plugin-file => activation-timestamp map (wp-admin/includes/plugin.php:712-715).
	 *
	 * @param string[] $site_plugins    Active plugin files of the site.
	 * @param string[] $network_plugins Plugin files active for the whole network.
	 * @param bool     $is_multisite    Whether the install is a multisite.
	 * @param bool     $code_loaded     Whether this process runs the WooPayments extension's code (WCPAY_PLUGIN_FILE defined).
	 */
	private function fake_active_plugins( array $site_plugins, array $network_plugins, bool $is_multisite, bool $code_loaded = false ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => fn( $name, $default_value = false ) => 'active_plugins' === $name ? $site_plugins : get_option( $name, $default_value ),
				'get_site_option' => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array_fill_keys( $network_plugins, 1 ) : get_site_option( $name, $default_value ),
				'is_multisite'    => fn() => $is_multisite,
				'defined'         => fn( $name ) => 'WCPAY_PLUGIN_FILE' === $name ? $code_loaded : defined( $name ),
			)
		);
	}

	/**
	 * Schedule a pending single action on a hook, and unschedule it at tear down.
	 *
	 * @param string $hook_name Action Scheduler hook.
	 */
	private function schedule_queue_action( string $hook_name ): void {
		$this->scheduled_action_hooks[] = $hook_name;
		$action_id                      = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook_name, array(), 'woocommerce-test-cutover', true );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
	}

	/**
	 * Enable multi-currency with a single GBP rate type.
	 *
	 * @param string $rate_type Exchange rate type.
	 */
	private function enable_multi_currency_with_rate_type( string $rate_type ): void {
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', $rate_type );
	}

	/**
	 * Create a legacy WCPay/Stripe Billing subscription fixture, which makes a store without WooCommerce Subscriptions bundled.
	 *
	 * @param string $status   Subscription status without the wc- prefix.
	 * @param string $meta_key Legacy marker meta key.
	 * @return int Subscription post ID.
	 */
	private function create_bundled_stripe_billing_subscription( string $status, string $meta_key = '_wcpay_subscription_id' ): int {
		$this->register_subscription_order_type();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-' . $status,
				'post_title'  => 'Legacy Stripe Billing subscription',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		update_post_meta( $post_id, $meta_key, 'sub_legacy_' . $status );

		return $post_id;
	}

	/**
	 * Create a raw HPOS legacy marker fixture without hydrating an order object.
	 *
	 * @param string $meta_key Legacy marker meta key.
	 * @return int Raw HPOS order ID.
	 */
	private function create_bundled_stripe_billing_hpos_marker( string $meta_key ): int {
		global $wpdb;

		$this->create_disabled_hpos_fixture();

		$orders_table = OrdersTableDataStore::get_orders_table_name();
		$meta_table   = OrdersTableDataStore::get_meta_table_name();
		$order_id     = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id), 0) + 1 FROM {$orders_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->insert(
			$orders_table,
			array(
				'id'               => $order_id,
				'status'           => 'wc-active',
				'currency'         => 'USD',
				'type'             => 'shop_subscription',
				'date_created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'date_updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->insert(
			$meta_table,
			array(
				'order_id'   => $order_id,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Raw HPOS test fixture row.
				'meta_key'   => $meta_key,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Raw HPOS test fixture row.
				'meta_value' => 'in_legacy_hpos',
			)
		);

		$this->raw_hpos_order_ids[] = $order_id;

		return $order_id;
	}

	/**
	 * Create HPOS tables while keeping HPOS disabled, as on a store that used HPOS before disabling it.
	 */
	private function create_disabled_hpos_fixture(): void {
		if ( ! $this->captured_hpos_tables_created_option ) {
			$this->previous_hpos_tables_created_option = get_option( DataSynchronizer::ORDERS_TABLE_CREATED, false );
			$this->captured_hpos_tables_created_option = true;
		}

		$synchronizer = wc_get_container()->get( DataSynchronizer::class );
		if ( $synchronizer->check_orders_table_exists() ) {
			return;
		}

		$this->created_hpos_tables = true;
		$this->assertTrue( $synchronizer->create_database_tables() );
	}

	/**
	 * Delete the temporary HPOS tables and restore the original table-created option.
	 */
	private function clean_up_disabled_hpos_fixture(): void {
		if ( ! $this->captured_hpos_tables_created_option ) {
			return;
		}

		if ( $this->created_hpos_tables ) {
			wc_get_container()->get( DataSynchronizer::class )->delete_database_tables();
			$this->created_hpos_tables = false;
		}

		if ( false === $this->previous_hpos_tables_created_option ) {
			delete_option( DataSynchronizer::ORDERS_TABLE_CREATED );
		} else {
			update_option( DataSynchronizer::ORDERS_TABLE_CREATED, $this->previous_hpos_tables_created_option );
		}
		$this->captured_hpos_tables_created_option = false;
		$this->previous_hpos_tables_created_option = false;
	}

	/**
	 * Delete raw HPOS order rows created by this test.
	 */
	private function delete_raw_hpos_orders(): void {
		global $wpdb;

		if ( array() === $this->raw_hpos_order_ids ) {
			return;
		}

		$order_ids    = implode( ',', array_map( 'absint', $this->raw_hpos_order_ids ) );
		$orders_table = OrdersTableDataStore::get_orders_table_name();
		$meta_table   = OrdersTableDataStore::get_meta_table_name();

		$wpdb->query( "DELETE FROM {$meta_table} WHERE order_id IN ({$order_ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$orders_table} WHERE id IN ({$order_ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->raw_hpos_order_ids = array();
	}

	/**
	 * Register a lightweight subscription order type for the bundled-subscriptions fixtures.
	 */
	private function register_subscription_order_type(): void {
		if ( post_type_exists( 'shop_subscription' ) ) {
			return;
		}

		wc_register_order_type(
			'shop_subscription',
			array(
				'label'                      => 'Subscriptions',
				'public'                     => false,
				'exclude_from_order_views'   => false,
				'exclude_from_order_count'   => true,
				'exclude_from_order_reports' => true,
				'class_name'                 => 'WC_Order',
			)
		);
		$this->registered_subscription_order_type = true;
	}

	/**
	 * Initialize a preflight service with its check collaborators.
	 *
	 * The service resolves them when a preflight runs, so the test doubles are set on the instance after init.
	 *
	 * @param PreflightService                  $service                     The preflight service.
	 * @param WooPaymentsRuntimeArbiter         $arbiter                     The runtime arbiter.
	 * @param LegacyProxy                       $legacy_proxy                The legacy proxy.
	 * @param WooPaymentsProvider               $provider                    The native provider.
	 * @param BundledSubscriptionsGuard         $bundled_subscriptions_guard  The bundled subscriptions guard.
	 * @param PlatformConnectionService         $platform_connection_service The platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter   $rate_account         The rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter $rate_api_client      The rate API client boundary.
	 */
	private function init_preflight_service( PreflightService $service, WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider, BundledSubscriptionsGuard $bundled_subscriptions_guard, PlatformConnectionService $platform_connection_service, WooPaymentsNativeAccountAdapter $rate_account, WooPaymentsNativeApiClientAdapter $rate_api_client ): void {
		$service->init( $arbiter, $legacy_proxy );

		$collaborators = array(
			'provider'                    => $provider,
			'bundled_subscriptions_guard' => $bundled_subscriptions_guard,
			'platform_connection_service' => $platform_connection_service,
			'rate_account'                => $rate_account,
			'rate_api_client'             => $rate_api_client,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( PreflightService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}
	}
}
