<?php
/**
 * WooPaymentsPerSiteActivationController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPerSiteActivationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsPerSiteActivationController.
 */
class WooPaymentsPerSiteActivationControllerTest extends WC_Unit_Test_Case {

	/**
	 * Option the WooPayments extension writes when it runs on a site.
	 */
	private const EXTENSION_VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	/**
	 * Plugin files activate_plugin() was called with, by site ID.
	 *
	 * @var array<int,string[]>
	 */
	private array $activations = array();

	/**
	 * The arguments of every activate_plugin() call.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $activation_args = array();

	/**
	 * Whether the network deactivation throws, so a handler that wrongly reached the conversion stops before its redirect.
	 *
	 * @var bool
	 */
	private bool $stop_at_network_deactivation = false;

	/**
	 * Calls to deactivate_plugins(): plugin file, silent, for every site.
	 *
	 * @var array<int,array{0:string,1:bool,2:mixed}>
	 */
	private array $deactivations = array();

	/**
	 * The plugin calls in order: "activate:<site ID>:<still active for every site>" and "deactivate".
	 *
	 * @var string[]
	 */
	private array $calls = array();

	/**
	 * Sites where activate_plugin() fails.
	 *
	 * @var int[]
	 */
	private array $failing_sites = array();

	/**
	 * Sites where WooCommerce is not active.
	 *
	 * @var int[]
	 */
	private array $sites_without_woocommerce = array();

	/**
	 * Sites created by the test.
	 *
	 * @var int[]
	 */
	private array $site_ids = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->site_ids as $site_id ) {
			wpmu_delete_blog( $site_id, true );
		}
		if ( is_multisite() ) {
			delete_site_option( 'active_sitewide_plugins' );
			delete_site_transient( WooPaymentsPerSiteActivationController::FEATURE_SCAN_TRANSIENT );
		}
		unset( $_GET[ WooPaymentsPerSiteActivationController::QUERY_ACTION ], $_GET[ WooPaymentsPerSiteActivationController::NONCE_NAME ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_FAILED ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_KEPT ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_WITHOUT_WOOCOMMERCE ] );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		parent::tearDown();
	}

	/**
	 * @testdox The conversion activates the extension on each site that is not deleted, an archived one included, every site of the network read, then deactivates it for every site once.
	 * @group multisite
	 */
	public function test_conversion_activates_each_site_that_is_not_deleted(): void {
		$this->skip_unless_multisite();
		$main     = get_current_blog_id();
		$feature  = $this->create_site( 'feature-off', false, true );
		$archived = $this->create_site( 'archived', true, true );
		$deleted  = $this->create_site( 'deleted', true, true );
		update_blog_status( $archived, 'archived', '1' );
		update_blog_status( $deleted, 'deleted', '1' );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		$site_limits = array();
		add_filter(
			'sites_pre_query',
			function ( $site_data, $query ) use ( &$site_limits ) {
				$site_limits[] = $query->query_vars['number'];
				return $site_data;
			},
			10,
			2
		);

		$result = $this->create_sut()->convert();

		$this->assertSame( array( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false, true ) ), $this->deactivations, 'One deactivation for every site, not silent.' );
		$this->assertSame( array( $main, $feature, $archived ), $result['activated'] );
		$this->assertSame( array(), $result['failed'] );
		foreach ( array( $main, $feature, $archived ) as $site_id ) {
			$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), $this->activations[ $site_id ] ?? array(), "Site $site_id gets the extension." );
		}
		$this->assertArrayNotHasKey( $deleted, $this->activations, 'A deleted site is left alone.' );
		$this->assertSame( array_fill( 0, 3, array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ), $this->activation_args, 'activate_plugin() gets only the file: no redirect, site scope, not silent, so its hooks run.' );
		$this->assertNotEmpty( $site_limits );
		$this->assertSame( array( 0 ), array_values( array_unique( $site_limits ) ), 'Every site of the network is read, beyond the default page of sites.' );
	}

	/**
	 * @testdox Each site is activated while the extension still runs for every site, and the network deactivation comes last.
	 * @group multisite
	 */
	public function test_each_site_is_activated_before_the_network_deactivation(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', true, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();

		$this->create_sut()->convert();

		$this->assertSame( array( "activate:$main:yes", "activate:$other:yes", 'deactivate' ), $this->calls );
	}

	/**
	 * @testdox A site whose activation fails keeps the extension: the network activation stays, and the result lists the site with the button.
	 * @group multisite
	 */
	public function test_a_failed_site_keeps_the_network_activation(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', true, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		$this->failing_sites = array( $other );

		$result = $this->create_sut()->convert();

		$this->assertSame( array( $other ), $result['failed'] );
		$this->assertSame( array(), $this->deactivations, 'No network deactivation while a site failed.' );
		$this->assertArrayHasKey( WooPaymentsRuntimeArbiter::PLUGIN_FILE, (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ]   = '1';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_FAILED ] = (string) $other;
		$notice = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'WooPayments could not be activated on these sites: ' . $this->get_site_label( $other ) . '. Activate WooPayments per site again to finish.', $notice );
		$this->assertStringContainsString( 'Activate WooPayments per site</a>', $notice );
	}

	/**
	 * @testdox A run after a partial failure activates only the failed site, then removes the network activation.
	 * @group multisite
	 */
	public function test_a_run_after_a_partial_failure_finishes_the_conversion(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', true, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		$this->failing_sites = array( $other );
		$this->create_sut()->convert();

		$this->failing_sites = array();
		$this->activations   = array();
		$second              = $this->create_sut()->convert();

		$this->assertSame( array( $other => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ), $this->activations, 'Only the failed site is activated.' );
		$this->assertSame( array( $main ), $second['skipped'] );
		$this->assertSame( array(), $second['failed'] );
		$this->assertSame( array( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false, true ) ), $this->deactivations, 'The network activation goes once every site has its own.' );
	}

	/**
	 * @testdox A second conversion after a finished one changes nothing: a switched site and a started switch keep their record and no plugin is activated or deactivated.
	 * @group multisite
	 */
	public function test_second_conversion_after_a_finished_one_changes_nothing(): void {
		$this->skip_unless_multisite();
		$main     = get_current_blog_id();
		$switched = $this->create_site( 'switched', true, true );
		$started  = $this->create_site( 'started', true, true );
		$this->arrange_site( $main, false, true );
		$this->activate_for_every_site();
		$this->create_sut()->convert();
		$this->assertArrayNotHasKey( WooPaymentsRuntimeArbiter::PLUGIN_FILE, (array) get_site_option( 'active_sitewide_plugins', array() ), 'The first conversion finished.' );
		// Since then, one site finished its switch (its extension deactivated) and another started one.
		switch_to_blog( $switched );
		try {
			update_option( 'active_plugins', array() );
		} finally {
			restore_current_blog();
		}
		$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		$this->create_record( $started, WooPaymentsCutoverStateStore::STATE_DEFERRED, 'deferred', array() );
		$done_before    = $this->read_record( $switched );
		$started_before = $this->read_record( $started );
		$this->calls    = array();

		$result = $this->create_sut()->convert();

		$this->assertNull( $result, 'Nothing to convert.' );
		$this->assertSame( array(), $this->calls, 'No plugin is activated or deactivated.' );
		$this->assertSame( $done_before, $this->read_record( $switched ), 'The switched site keeps its done record.' );
		$this->assertSame( $started_before, $this->read_record( $started ), 'The started switch keeps its record.' );
	}

	/**
	 * @testdox A site with the WooPayments feature on that never ran the extension keeps WooPayments in WooCommerce: it is not activated, not counted as failed, and named in the result.
	 * @group multisite
	 */
	public function test_site_that_never_ran_the_extension_keeps_woopayments_in_woocommerce(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$fresh = $this->create_site( 'fresh', true, false );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();

		$result = $this->create_sut()->convert();

		$this->assertSame( array( $fresh ), $result['kept'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertArrayNotHasKey( $fresh, $this->activations );
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ] = '1';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_KEPT ] = (string) $fresh;
		$notice = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'Kept WooPayments in WooCommerce: ' . $this->get_site_label( $fresh ) . '.', $notice );
		$this->assertStringNotContainsString( 'could not be activated', $notice );
	}

	/**
	 * @testdox A site where WooCommerce is not active is skipped and listed apart, not counted as failed, so the network activation still goes.
	 * @group multisite
	 */
	public function test_site_without_woocommerce_is_skipped_and_listed_apart(): void {
		$this->skip_unless_multisite();
		$main = get_current_blog_id();
		$blog = $this->create_site( 'blog', false, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		$this->sites_without_woocommerce = array( $blog );

		$result = $this->create_sut()->convert();

		$this->assertSame( array( $blog ), $result['without_woocommerce'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertArrayNotHasKey( $blog, $this->activations );
		$this->assertCount( 1, $this->deactivations );
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ]                = '1';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_WITHOUT_WOOCOMMERCE ] = (string) $blog;
		$notice = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'Skipped, WooCommerce is not active: ' . $this->get_site_label( $blog ) . '.', $notice );
		$this->assertStringNotContainsString( 'could not be activated', $notice );
	}

	/**
	 * @testdox A switch that started and has not reached finalize continues when the conversion activates the extension on its site, or finds it active there: state $state, listener loaded: $listener, already active: $already_active.
	 * @group multisite
	 * @dataProvider provider_started_switch_without_token
	 *
	 * @param string $state          Record state.
	 * @param bool   $listener       Whether the plugin lifecycle listener is loaded in the request.
	 * @param bool   $already_active Whether the site already has the extension in its own active list.
	 */
	public function test_conversion_keeps_a_started_switch_without_a_token( string $state, bool $listener, bool $already_active ): void {
		$this->skip_unless_multisite();
		if ( $listener ) {
			wc_get_container()->get( WooPaymentsCutoverPluginLifecycleListener::class )->register();
		}
		$main    = get_current_blog_id();
		$started = $this->create_site( 'started', true, true );
		$this->arrange_site( $main, false, true );
		$this->activate_for_every_site();
		if ( $already_active ) {
			$this->activate_on_its_own( $started );
		}
		$this->create_record( $started, $state, $this->get_record_step( $state ), array(), $this->get_record_overrides( $state ) );
		$action_id = $this->schedule_next_attempt( $started );
		$before    = $this->read_record( $started );

		$result = $this->create_sut()->convert();

		$this->assertContains( $started, $result[ $already_active ? 'skipped' : 'activated' ] );
		$this->assertSame( $already_active ? array() : array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), $this->activations[ $started ] ?? array() );
		$this->assertSame( $before, $this->read_record( $started ), 'The generation, state and every field of the started switch are unchanged.' );
		$this->assertSame( $action_id, $this->read_scheduled_action_id( $started ), 'The pending attempt stays scheduled.' );
	}

	/**
	 * @return array<string,array{0:string,1:bool,2:bool}> State, listener loaded, already active.
	 */
	public function provider_started_switch_without_token(): array {
		$rows = array();
		foreach ( array( WooPaymentsCutoverStateStore::STATE_PENDING, WooPaymentsCutoverStateStore::STATE_RUNNING, WooPaymentsCutoverStateStore::STATE_DEFERRED ) as $state ) {
			foreach ( array( true, false ) as $listener ) {
				foreach ( array( false, true ) as $already_active ) {
					$rows[ sprintf( '%s, listener %s, %s', $state, $listener ? 'loaded' : 'not loaded', $already_active ? 'already active' : 'activated' ) ] = array( $state, $listener, $already_active );
				}
			}
		}

		return $rows;
	}

	/**
	 * @testdox A switch that is done, or past finalize with its request token, goes back to awaiting the merchant's start when the conversion activates the extension on its site or finds it active there, with one new generation: $kind, listener loaded: $listener, already active: $already_active.
	 * @group multisite
	 * @dataProvider provider_switch_that_rolls_back
	 *
	 * @param string $kind           done, or past finalize with the request token: deferred, pending ownership verification, or running ownership verification.
	 * @param bool   $listener       Whether the plugin lifecycle listener is loaded in the request.
	 * @param bool   $already_active Whether the site already has the extension in its own active list.
	 */
	public function test_conversion_rolls_back_a_switch_that_is_done_or_past_finalize( string $kind, bool $listener, bool $already_active ): void {
		$this->skip_unless_multisite();
		if ( $listener ) {
			wc_get_container()->get( WooPaymentsCutoverPluginLifecycleListener::class )->register();
		}
		$main     = get_current_blog_id();
		$switched = $this->create_site( 'switched', true, true );
		$this->arrange_site( $main, false, true );
		$this->activate_for_every_site();
		if ( $already_active ) {
			$this->activate_on_its_own( $switched );
		}
		$token = array( 'request_origin_token' => 'finalize-token' );
		if ( 'done' === $kind ) {
			$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		} elseif ( 'pending verification' === $kind ) {
			$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_PENDING, 'verify_builtin_ownership', array(), $token );
		} elseif ( 'running verification' === $kind ) {
			$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_RUNNING, 'verify_builtin_ownership', array(), array_merge( $token, $this->get_record_overrides( WooPaymentsCutoverStateStore::STATE_RUNNING ) ) );
		} else {
			$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_DEFERRED, 'deferred', array( 'builtin_ownership_unverified' ), $token );
		}
		$activated_hooks = array();
		add_action(
			'activated_plugin',
			function ( $plugin, $network_wide ) use ( &$activated_hooks ): void {
				$activated_hooks[] = array( get_current_blog_id(), $plugin, $network_wide );
			},
			10,
			2
		);

		$this->create_sut()->convert();

		if ( $already_active ) {
			$this->assertArrayNotHasKey( $switched, $this->activations, 'No second activation.' );
		} else {
			$this->assertContains( array( $switched, WooPaymentsRuntimeArbiter::PLUGIN_FILE, false ), $activated_hooks, 'The site activation fires activated_plugin for that site.' );
		}
		$record = $this->read_record( $switched );
		$this->assertSame( 4, $record['generation'], 'One rollback generation, with or without the listener.' );
		$this->assertSame( WooPaymentsCutoverStateStore::STATE_PENDING, $record['state'] );
		$this->assertSame( 'awaiting_merchant_start', $record['current_step'] );
	}

	/**
	 * @return array<string,array{0:string,1:bool,2:bool}> Kind, listener loaded, already active.
	 */
	public function provider_switch_that_rolls_back(): array {
		$rows = array();
		foreach ( array( 'done', 'finalized', 'pending verification', 'running verification' ) as $kind ) {
			foreach ( array( true, false ) as $listener ) {
				foreach ( array( false, true ) as $already_active ) {
					$rows[ sprintf( '%s, listener %s, %s', $kind, $listener ? 'loaded' : 'not loaded', $already_active ? 'already active' : 'activated' ) ] = array( $kind, $listener, $already_active );
				}
			}
		}

		return $rows;
	}

	/**
	 * @testdox The conversion button stops before any plugin change ($_dataName).
	 * @group multisite
	 * @dataProvider provider_conversion_requests_that_change_no_plugin
	 *
	 * @param string $nonce                      The nonce the request carries: none, a wrong one, or 'valid' for a valid one.
	 * @param bool   $can_manage_network_plugins Whether the user may manage network plugins.
	 */
	public function test_handler_with_a_refused_request_changes_no_plugin( string $nonce, bool $can_manage_network_plugins ): void {
		$this->skip_unless_multisite();
		$this->arrange_site( get_current_blog_id(), true, true );
		$this->activate_for_every_site();
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_ACTION ] = WooPaymentsPerSiteActivationController::ACTION_CONVERT;
		if ( 'valid' === $nonce ) {
			wp_set_current_user( 1 );
			$_GET[ WooPaymentsPerSiteActivationController::NONCE_NAME ] = wp_create_nonce( WooPaymentsPerSiteActivationController::NONCE_ACTION );
		} elseif ( '' !== $nonce ) {
			$_GET[ WooPaymentsPerSiteActivationController::NONCE_NAME ] = $nonce;
		}
		if ( ! $can_manage_network_plugins ) {
			$this->mock_plugin_functions( false );
		}

		$this->assert_handler_stops_without_changes( $this->create_sut() );
	}

	/**
	 * Requests the handler refuses: no nonce, a wrong nonce, and a valid nonce without the manage_network_plugins capability.
	 *
	 * @return array<string,array{string,bool}>
	 */
	public function provider_conversion_requests_that_change_no_plugin(): array {
		return array(
			'no nonce'                                     => array( '', true ),
			'a wrong nonce'                                => array( 'not-a-nonce', true ),
			'a valid nonce without manage_network_plugins' => array( 'valid', false ),
		);
	}

	/**
	 * @testdox The network admin notice shows only to a super admin, only while the extension is active for every site and the WooPayments feature is on at one site or more.
	 * @group multisite
	 */
	public function test_notice_needs_the_extension_for_every_site_and_the_feature_somewhere(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', false, true );
		$this->arrange_site( $main, false, true );
		$this->activate_for_every_site();

		$this->assertSame( '', $this->render_notice( $this->create_sut() ), 'Nothing shows with the feature off everywhere.' );

		$this->arrange_site( $other, true, true );
		$offered = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'No site can switch while the WooPayments extension is active for the whole network. Activate WooPayments per site so each site&#039;s administrators can start their switch.', $offered );
		$this->assertStringContainsString( 'Activate WooPayments per site</a>', $offered );

		$this->assertSame( '', $this->render_notice( $this->create_sut(), false ), 'Nothing shows to a user who cannot manage network plugins.' );

		delete_site_option( 'active_sitewide_plugins' );
		$this->assertSame( '', $this->render_notice( $this->create_sut() ), 'Nothing shows once the extension is no longer active for every site.' );
	}

	/**
	 * @testdox With the feature off everywhere, network admin pages scan the sites once; turning the feature on at a site clears the answer.
	 * @group multisite
	 */
	public function test_feature_scan_is_kept_until_the_feature_changes(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', false, true );
		$this->arrange_site( $main, false, true );
		$this->activate_for_every_site();
		delete_site_transient( WooPaymentsPerSiteActivationController::FEATURE_SCAN_TRANSIENT );
		$switches = 0;
		add_action(
			'switch_blog',
			function () use ( &$switches ): void {
				++$switches;
			}
		);

		$this->assertSame( '', $this->render_notice( $this->create_sut() ) );
		$first_scan = $switches;
		$this->assertSame( '', $this->render_notice( $this->create_sut() ) );

		$this->assertGreaterThan( 0, $first_scan, 'The first page scans the sites.' );
		$this->assertSame( $first_scan, $switches, 'A later page reads the cached answer.' );

		$this->arrange_site( $other, true, true );
		$this->assertStringContainsString( 'Activate WooPayments per site</a>', $this->render_notice( $this->create_sut() ), 'The feature change clears the answer.' );
	}

	/**
	 * @testdox Nothing shows on a single site, whatever the plugin lists say.
	 */
	public function test_nothing_shows_on_a_single_site(): void {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on a single site.' );
		}
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$preflight = new class() extends WooPaymentsCutoverPreflightService {
			/** Claim the extension is active for every site. */
			public function is_woopayments_network_active(): bool {
				return true;
			}
		};
		$sut       = new WooPaymentsPerSiteActivationController();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), $preflight, wc_get_container()->get( WooPaymentsCutoverReconciliationJob::class ), wc_get_container()->get( LegacyProxy::class ) );
		$this->mock_plugin_functions();

		$this->assertSame( '', $this->render_notice( $sut ) );
	}

	/**
	 * Run the handler, expect it to stop through wp_die(), and assert it activated and deactivated nothing.
	 *
	 * @param WooPaymentsPerSiteActivationController $sut The controller.
	 */
	private function assert_handler_stops_without_changes( WooPaymentsPerSiteActivationController $sut ): void {
		$this->stop_at_network_deactivation = true;
		try {
			$sut->handle_admin_init();
			$this->fail( 'The handler must stop before the conversion.' );
		} catch ( \WPDieException $stopped ) {
			unset( $stopped );
		}

		$this->assertSame( array(), $this->calls, 'No plugin is activated or deactivated.' );
	}

	/**
	 * Skip a multisite-only row on a single site.
	 */
	private function skip_unless_multisite(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
	}

	/**
	 * Create a site with the WooPayments feature and the extension's run evidence set, and Action Scheduler tables.
	 *
	 * @param string $slug          Site path slug.
	 * @param bool   $feature_on    Whether the WooPayments feature is on.
	 * @param bool   $ran_extension Whether the extension ran there.
	 * @return int Site ID.
	 */
	private function create_site( string $slug, bool $feature_on, bool $ran_extension ): int {
		$site_id          = self::factory()->blog->create( array( 'path' => '/' . $slug . '/' ) );
		$this->site_ids[] = $site_id;
		switch_to_blog( $site_id );
		try {
			( new \ActionScheduler_StoreSchema() )->register_tables( true );
			( new \ActionScheduler_LoggerSchema() )->register_tables( true );
		} finally {
			restore_current_blog();
		}
		$this->arrange_site( $site_id, $feature_on, $ran_extension );

		return $site_id;
	}

	/**
	 * Set a site's WooPayments feature and the extension's run evidence; the site starts with no active plugins.
	 *
	 * @param int  $site_id       Site ID.
	 * @param bool $feature_on    Whether the WooPayments feature is on.
	 * @param bool $ran_extension Whether the extension ran there.
	 */
	private function arrange_site( int $site_id, bool $feature_on, bool $ran_extension ): void {
		switch_to_blog( $site_id );
		try {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature_on ? 'yes' : 'no' );
			// The extension writes its version option when it runs: client 11.1.0 includes/class-wc-payments.php:1580.
			if ( $ran_extension ) {
				update_option( self::EXTENSION_VERSION_OPTION, '11.1.0' );
			} else {
				delete_option( self::EXTENSION_VERSION_OPTION );
			}
			update_option( 'active_plugins', array() );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Store a cutover record of generation 3 on a site.
	 *
	 * @param int                 $site_id   Site ID.
	 * @param string              $state     Record state.
	 * @param string              $step      Current step.
	 * @param string[]            $codes     Deferred codes.
	 * @param array<string,mixed> $overrides Record fields to replace.
	 */
	private function create_record( int $site_id, string $state, string $step, array $codes, array $overrides = array() ): void {
		$now = time();
		switch_to_blog( $site_id );
		try {
			$this->assertTrue(
				wc_get_container()->get( WooPaymentsCutoverStateStore::class )->create_record(
					array_merge(
						array(
							'schema_version'         => WooPaymentsCutoverStateStore::SCHEMA_VERSION,
							'generation'             => 3,
							'revision'               => 1,
							'state'                  => $state,
							'started_at'             => $now,
							'updated_at'             => $now,
							'attempt'                => 1,
							'action_id'              => 0,
							'current_step'           => $step,
							'step_log'               => array(),
							'deferred_codes'         => $codes,
							'informational_outcomes' => array(),
							'next_attempt_at'        => WooPaymentsCutoverStateStore::STATE_DEFERRED === $state ? $now + HOUR_IN_SECONDS : null,
							'lease_token'            => null,
							'lease_expires_at'       => null,
							'request_origin_token'   => null,
						),
						$overrides
					)
				)
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Get the current step a started state records.
	 *
	 * @param string $state Record state.
	 * @return string
	 */
	private function get_record_step( string $state ): string {
		return array(
			WooPaymentsCutoverStateStore::STATE_PENDING  => 'queued',
			WooPaymentsCutoverStateStore::STATE_RUNNING  => 'running',
			WooPaymentsCutoverStateStore::STATE_DEFERRED => 'deferred',
		)[ $state ];
	}

	/**
	 * Get the record fields a started state needs beyond the defaults: a running claim holds a lease.
	 *
	 * @param string $state Record state.
	 * @return array<string,mixed>
	 */
	private function get_record_overrides( string $state ): array {
		return WooPaymentsCutoverStateStore::STATE_RUNNING === $state
			? array(
				'lease_token'      => 'claim',
				'lease_expires_at' => time() + HOUR_IN_SECONDS,
			)
			: array();
	}

	/**
	 * Schedule the next attempt of the generation-3 record on a site and return its action ID.
	 *
	 * @param int $site_id Site ID.
	 * @return int
	 */
	private function schedule_next_attempt( int $site_id ): int {
		switch_to_blog( $site_id );
		try {
			$action_id = wc_get_container()->get( WooPaymentsCutoverActionScheduler::class )->schedule( time() + HOUR_IN_SECONDS, 3, 2 );
		} finally {
			restore_current_blog();
		}
		$this->assertGreaterThan( 0, $action_id );

		return $action_id;
	}

	/**
	 * Get the ID of the scheduled next attempt of the generation-3 record on a site, or zero when none is scheduled.
	 *
	 * @param int $site_id Site ID.
	 * @return int
	 */
	private function read_scheduled_action_id( int $site_id ): int {
		switch_to_blog( $site_id );
		try {
			return wc_get_container()->get( WooPaymentsCutoverActionScheduler::class )->get_scheduled_action_id( 3, 2 );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Put the extension in a site's own active list, as WordPress leaves it when the extension is network-activated later.
	 *
	 * WordPress 7.1.3 wp-admin/includes/plugin.php:712-720 writes only the network list.
	 *
	 * @param int $site_id Site ID.
	 */
	private function activate_on_its_own( int $site_id ): void {
		switch_to_blog( $site_id );
		try {
			update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Read a site's cutover record.
	 *
	 * @param int $site_id Site ID.
	 * @return array<string,mixed>
	 */
	private function read_record( int $site_id ): array {
		switch_to_blog( $site_id );
		try {
			wp_cache_delete( WooPaymentsCutoverStateStore::OPTION_NAME, 'options' );
			$record = wc_get_container()->get( WooPaymentsCutoverStateStore::class )->get_record();
		} finally {
			restore_current_blog();
		}
		$this->assertIsArray( $record );

		return $record;
	}

	/**
	 * Make the extension active for every site and stand in for WordPress's plugin activation functions.
	 */
	private function activate_for_every_site(): void {
		update_site_option( 'active_sitewide_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => time() ) );
		$this->mock_plugin_functions();
	}

	/**
	 * Stand in for activate_plugin(), deactivate_plugins(), WooCommerce's activity and the capability check.
	 *
	 * @param bool $super_admin Whether the user may manage network plugins.
	 */
	private function mock_plugin_functions( bool $super_admin = true ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				// WordPress 7.1.3 wp-admin/includes/plugin.php:647 activate_plugin( $plugin, $redirect = '', $network_wide = false,
				// $silent = false ): a site activation reads the site's own list (:655) and activates a plugin missing from it
				// (:668-669), firing activate_plugin (:694) unless silent, writing active_plugins (:720), firing activated_plugin
				// (:736) unless silent, and returning null (:747) or a WP_Error.
				'activate_plugin'    => function ( ...$args ) {
					$plugin                  = $args[0];
					$silent                  = (bool) ( $args[3] ?? false );
					$this->activation_args[] = $args;
					$site_id                 = get_current_blog_id();
					$sitewide                = array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', array() ) ) ? 'yes' : 'no';
					$this->calls[]           = "activate:$site_id:$sitewide";
					if ( in_array( $site_id, $this->failing_sites, true ) ) {
						return new \WP_Error( 'activation_failed', 'Activation failed.' );
					}
					if ( ! $silent ) {
						do_action( 'activate_plugin', $plugin, false ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WordPress's own hook, fired as the core function does.
					}
					$this->activations[ $site_id ][] = $plugin;
					update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( $plugin ) ) );
					if ( ! $silent ) {
						do_action( 'activated_plugin', $plugin, false ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WordPress's own hook, fired as the core function does.
					}
					return null;
				},
				// WordPress 7.1.3 wp-admin/includes/plugin.php:764 deactivate_plugins( $plugins, $silent = false, $network_wide = null ):
				// a network-wide deactivation writes active_sitewide_plugins (:854).
				'deactivate_plugins' => function ( $plugin, $silent = false, $for_every_site = null ): void {
					if ( $this->stop_at_network_deactivation ) {
						throw new \LogicException( 'The conversion ran.' );
					}
					$this->calls[]         = 'deactivate';
					$this->deactivations[] = array( $plugin, $silent, $for_every_site );
					if ( true === $for_every_site ) {
						$active = (array) get_site_option( 'active_sitewide_plugins', array() );
						unset( $active[ $plugin ] );
						update_site_option( 'active_sitewide_plugins', $active );
					}
				},
				'is_plugin_active'   => fn( $plugin ) => plugin_basename( WC_PLUGIN_FILE ) === $plugin
					? ! in_array( get_current_blog_id(), $this->sites_without_woocommerce, true )
					: is_plugin_active( $plugin ),
				'current_user_can'   => fn( $capability ) => 'manage_network_plugins' === $capability ? $super_admin : current_user_can( $capability ),
			)
		);
	}

	/**
	 * Create the system under test with the container's arbiter, preflight, job and legacy proxy.
	 *
	 * @return WooPaymentsPerSiteActivationController
	 */
	private function create_sut(): WooPaymentsPerSiteActivationController {
		$sut = new WooPaymentsPerSiteActivationController();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsCutoverPreflightService::class ), wc_get_container()->get( WooPaymentsCutoverReconciliationJob::class ), wc_get_container()->get( LegacyProxy::class ) );

		return $sut;
	}

	/**
	 * Render the network admin notice.
	 *
	 * @param WooPaymentsPerSiteActivationController $sut         The controller.
	 * @param bool                                   $super_admin Whether the user may manage network plugins.
	 * @return string
	 */
	private function render_notice( WooPaymentsPerSiteActivationController $sut, bool $super_admin = true ): string {
		$this->mock_plugin_functions( $super_admin );
		ob_start();
		$sut->output_network_admin_notice();

		return (string) ob_get_clean();
	}

	/**
	 * Get a site's label as the result notice writes it.
	 *
	 * @param int $site_id Site ID.
	 * @return string
	 */
	private function get_site_label( int $site_id ): string {
		$site = get_site( $site_id );
		$this->assertInstanceOf( \WP_Site::class, $site );

		return untrailingslashit( $site->domain . $site->path );
	}
}
