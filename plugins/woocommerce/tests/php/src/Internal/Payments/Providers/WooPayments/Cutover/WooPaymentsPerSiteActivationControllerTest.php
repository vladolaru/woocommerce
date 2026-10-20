<?php
/**
 * WooPaymentsPerSiteActivationController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverDataMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPluginLifecycleListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPerSiteActivationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
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
	 * The message, title and arguments of the wp_die() call the test stopped, or an empty array.
	 *
	 * @var array<string,mixed>
	 */
	private array $wp_die = array();

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->site_ids as $site_id ) {
			wpmu_delete_blog( $site_id, true );
			wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate( $site_id );
		}
		Constants::clear_single_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' );
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
	 * @testdox A network-wide activation of the extension sends every switched site back to awaiting the merchant's start with a new generation, so once the network activation goes nothing owns payments there until the merchant starts the switch ($_dataName).
	 * @group multisite
	 * @dataProvider provider_extension_plugin_files
	 *
	 * @param string $plugin_file The extension's main file as WordPress activates it.
	 */
	public function test_network_activation_records_the_rollback_of_each_switched_site( string $plugin_file ): void {
		$this->skip_unless_multisite();
		$main = get_current_blog_id();
		$done = $this->create_site( 'done', true, true );
		$past = $this->create_site( 'past-finalize', true, true );
		$this->arrange_site( $main, false, true );
		$this->create_record( $done, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		$this->create_record( $past, WooPaymentsCutoverStateStore::STATE_DEFERRED, 'deferred', array( 'builtin_ownership_unverified' ), array( 'request_origin_token' => 'finalize-token' ) );
		$this->activate_for_every_site( $plugin_file );

		$this->fire_activated_plugin( true, $plugin_file );

		$this->assertSame( $main, get_current_blog_id(), 'The scan returns to the main site.' );
		foreach ( array( $done, $past ) as $site_id ) {
			$record = $this->read_record( $site_id );
			$this->assertSame( 4, $record['generation'], 'One rollback generation.' );
			$this->assertSame( WooPaymentsCutoverStateStore::STATE_PENDING, $record['state'] );
			$this->assertSame( 'awaiting_merchant_start', $record['current_step'] );
		}

		delete_site_option( 'active_sitewide_plugins' );
		foreach ( array( $done, $past ) as $site_id ) {
			$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $this->read_owner( $site_id ), 'Nothing owns payments until the merchant starts the switch.' );
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provider_extension_plugin_files(): array {
		return array(
			'the extension in its own folder'   => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ),
			'the extension in a renamed folder' => array( 'woocommerce-payments-10.5.0/woocommerce-payments.php' ),
		);
	}

	/**
	 * @testdox A network-wide activation leaves a site with nothing to roll back as it is, while it rolls back a switched site: $_dataName.
	 * @group multisite
	 * @dataProvider provider_sites_a_network_activation_leaves_alone
	 *
	 * @param string $kind The other site: no record, a record awaiting the merchant's start, a switch started before finalize, or a deleted site with a completed switch.
	 */
	public function test_network_activation_leaves_a_site_with_nothing_to_roll_back( string $kind ): void {
		$this->skip_unless_multisite();
		$main     = get_current_blog_id();
		$switched = $this->create_site( 'switched', true, true );
		$other    = $this->create_site( 'other', true, true );
		$this->arrange_site( $main, false, true );
		$this->create_record( $switched, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		if ( 'awaiting the merchant\'s start' === $kind ) {
			$this->create_record( $other, WooPaymentsCutoverStateStore::STATE_PENDING, 'awaiting_merchant_start', array() );
		} elseif ( 'started before finalize' === $kind ) {
			$this->create_record( $other, WooPaymentsCutoverStateStore::STATE_DEFERRED, 'deferred', array( 'plugin_active_sitewide' ) );
		} elseif ( 'deleted, with a completed switch' === $kind ) {
			$this->create_record( $other, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
			update_blog_status( $other, 'deleted', '1' );
		}
		$before = $this->get_record_on( $other );
		$this->activate_for_every_site();

		$this->fire_activated_plugin( true );

		$this->assertSame( 4, $this->read_record( $switched )['generation'], 'The switched site rolls back.' );
		$this->assertSame( $before, $this->get_record_on( $other ), 'The other site keeps its record.' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provider_sites_a_network_activation_leaves_alone(): array {
		return array(
			'no record'                        => array( 'no record' ),
			'awaiting the merchant\'s start'   => array( 'awaiting the merchant\'s start' ),
			'started before finalize'          => array( 'started before finalize' ),
			'deleted, with a completed switch' => array( 'deleted, with a completed switch' ),
		);
	}

	/**
	 * @testdox The rollback scan after a network-wide activation goes on past a site whose record cannot be read, and ends on the site it started from.
	 * @group multisite
	 */
	public function test_network_rollback_scan_goes_on_past_a_failing_site_and_restores_the_site(): void {
		$this->skip_unless_multisite();
		$main    = get_current_blog_id();
		$failing = $this->create_site( 'failing', true, true );
		$next    = $this->create_site( 'next', true, true );
		$this->arrange_site( $main, false, true );
		$this->create_record( $failing, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		$this->create_record( $next, WooPaymentsCutoverStateStore::STATE_DONE, 'done', array() );
		$job               = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int Site whose record read throws. */
			public int $failing_site = 0;

			/**
			 * Throw on the failing site.
			 *
			 * @throws \RuntimeException On the failing site.
			 */
			public function has_started_switch_before_finalize(): bool {
				if ( get_current_blog_id() === $this->failing_site ) {
					throw new \RuntimeException( 'Unreadable record.' );
				}
				return parent::has_started_switch_before_finalize();
			}
		};
		$job->failing_site = $failing;
		$job->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsCutoverStateStore::class ), wc_get_container()->get( WooPaymentsCutoverActionScheduler::class ), wc_get_container()->get( WooPaymentsCutoverPreflightService::class ), wc_get_container()->get( WooPaymentsCutoverDataMigration::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$sut = new WooPaymentsPerSiteActivationController();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsCutoverPreflightService::class ), $job, wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsSetupTier::class ) );

		$sut->record_network_activation_rollbacks();

		$this->assertSame( $main, get_current_blog_id() );
		$this->assertFalse( ms_is_switched(), 'No site switch is left open.' );
		$this->assertSame( WooPaymentsCutoverStateStore::STATE_DONE, $this->read_record( $failing )['state'] );
		$this->assertSame( 4, $this->read_record( $next )['generation'], 'The site after the failing one rolls back.' );
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
	 * The single-site guard refuses the extension only where its listener loads (a stored setup tier past disabled), on a
	 * store the built-in owns that never ran the extension; a network activation applies that rule to each site.
	 *
	 * @testdox A network-wide activation of the extension is refused, naming the sites, while sites with WooCommerce active and a setup tier past disabled have the WooPayments feature on and never ran the extension ($_dataName).
	 * @group multisite
	 * @dataProvider provider_network_activation_refused
	 *
	 * @param string $tier            The first site's stored setup tier.
	 * @param bool   $main_feature_on Whether the WooPayments feature is on at the main site, which ran the extension.
	 * @param bool   $providers_off   Whether a callback turns the payment providers off in the network request, which says nothing about the sites' own requests.
	 */
	public function test_network_activation_is_refused_while_sites_keep_woopayments_in_woocommerce( string $tier, bool $main_feature_on, bool $providers_off = false ): void {
		$this->skip_unless_multisite();
		if ( $providers_off ) {
			add_filter( PaymentsBootstrap::FILTER_LOAD_PAYMENT_PROVIDERS, '__return_false' );
		}
		$main   = get_current_blog_id();
		$first  = $this->create_site( 'first', true, false );
		$second = $this->create_site( 'second', true, false );
		$this->arrange_site( $main, $main_feature_on, true );
		$this->set_stored_tier( $first, $tier );
		$this->set_stored_tier( $second, WooPaymentsSetupTier::ACTIVE );
		$this->mock_plugin_functions();
		$this->fake_wp_die();

		try {
			$this->fire_activate_plugin( true );
			$this->fail( 'The network-wide activation must be refused.' );
		} catch ( \WPDieException $refused ) {
			unset( $refused );
		}

		$sites = $this->get_site_label( $first ) . ', ' . $this->get_site_label( $second );
		$this->assertSame( "The WooPayments extension can&#039;t be activated for the whole network because the built-in WooPayments is turned on for these sites: $sites. Activate the extension on each of the other sites instead.", $this->wp_die['message'] );
		$this->assertSame( 'Plugin activation error', $this->wp_die['title'] );
		$this->assertSame( network_admin_url( 'plugins.php' ), $this->wp_die['args']['link_url'] ?? null );
		$this->assertSame( 'Go to Plugins', $this->wp_die['args']['link_text'] ?? null );
	}

	/**
	 * Sites that keep WooPayments in WooCommerce under the activation rule, with the main site's own feature on or off.
	 *
	 * @return array<string,array{string,bool,bool}>
	 */
	public function provider_network_activation_refused(): array {
		return array(
			'a callback turns the providers off in the network request' => array( WooPaymentsSetupTier::AVAILABLE, true, true ),
			'stored tier available'           => array( WooPaymentsSetupTier::AVAILABLE, true ),
			'stored tier connected'           => array( WooPaymentsSetupTier::CONNECTED, true ),
			'stored tier active'              => array( WooPaymentsSetupTier::ACTIVE, true ),
			"the main site's own feature off" => array( WooPaymentsSetupTier::AVAILABLE, false ),
		);
	}

	/**
	 * @testdox A WooPayments activation goes ahead when no site keeps WooPayments in WooCommerce under the activation rule ($_dataName).
	 * @group multisite
	 * @dataProvider provider_activation_allowed
	 *
	 * @param string $store How the one other site, set up and with the WooPayments feature on, is made allowed.
	 */
	public function test_activation_is_allowed_where_no_site_keeps_woopayments_in_woocommerce( string $store ): void {
		$this->skip_unless_multisite();
		$main = get_current_blog_id();
		$site = $this->create_site( 'site', 'feature off' !== $store, 'plugin-era' === $store );
		$this->arrange_site( $main, true, true );
		$this->set_stored_tier( $site, 'never set up' === $store ? WooPaymentsSetupTier::DISABLED : WooPaymentsSetupTier::AVAILABLE );
		if ( 'without WooCommerce' === $store ) {
			$this->sites_without_woocommerce = array( $site );
		} elseif ( 'deleted' === $store ) {
			update_blog_status( $site, 'deleted', '1' );
		} elseif ( 'merged feature development' === $store ) {
			Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );
		}
		$this->mock_plugin_functions();
		$this->fake_wp_die();

		$this->fire_activate_plugin( 'site activation' !== $store );

		$this->assertSame( array(), $this->wp_die, 'The activation goes ahead without wp_die().' );
	}

	/**
	 * Allowed activations: the other site ran the extension, has the feature off, was never set up, has no WooCommerce
	 * (a bare sub-site), or is deleted; merged feature development; and an activation for the main site alone.
	 *
	 * @return array<string,array{string}>
	 */
	public function provider_activation_allowed(): array {
		return array(
			'the other site ran the extension'            => array( 'plugin-era' ),
			'the feature is off at the other site'        => array( 'feature off' ),
			'the other site was never set up'             => array( 'never set up' ),
			'WooCommerce is not active at the other site' => array( 'without WooCommerce' ),
			'the other site is deleted'                   => array( 'deleted' ),
			'merged feature development'                  => array( 'merged feature development' ),
			'an activation for the main site only'        => array( 'site activation' ),
		);
	}

	/**
	 * @testdox A conversion started from a store site's notice returns to that site's Plugins page with its result, and the result shows there.
	 * @group multisite
	 */
	public function test_conversion_started_on_a_site_returns_to_that_site_with_its_result(): void {
		$this->skip_unless_multisite();
		$main = get_current_blog_id();
		$shop = $this->create_site( 'shop', true, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		wp_set_current_user( 1 );
		$location = '';
		add_filter(
			'wp_redirect',
			function ( $url ) use ( &$location ) {
				$location = (string) $url;
				return false;
			}
		);
		$this->register_exit_mock(
			function (): void {
				throw new \LengthException( 'exit' );
			}
		);

		switch_to_blog( $shop );
		try {
			$_GET[ WooPaymentsPerSiteActivationController::QUERY_ACTION ] = WooPaymentsPerSiteActivationController::ACTION_CONVERT;
			$_GET[ WooPaymentsPerSiteActivationController::NONCE_NAME ]   = wp_create_nonce( WooPaymentsPerSiteActivationController::NONCE_ACTION );
			try {
				WooPaymentsPerSiteActivationController::handle_conversion_request();
			} catch ( \LengthException $exited ) {
				unset( $exited );
			}
			$shop_plugins = admin_url( 'plugins.php' );
			$notice       = '';
			if ( str_starts_with( $location, $shop_plugins . '?' ) ) {
				unset( $_GET[ WooPaymentsPerSiteActivationController::QUERY_ACTION ], $_GET[ WooPaymentsPerSiteActivationController::NONCE_NAME ] );
				wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
				foreach ( $query as $key => $value ) {
					$_GET[ $key ] = $value;
				}
				ob_start();
				WooPaymentsPerSiteActivationController::handle_site_admin_notices();
				$notice = (string) ob_get_clean();
			}
		} finally {
			restore_current_blog();
		}

		$this->assertStringStartsWith( $shop_plugins . '?', $location, 'The result returns to the store site the conversion started from.' );
		$this->assertStringContainsString( WooPaymentsPerSiteActivationController::QUERY_DONE . '=1', $location );
		$this->assertStringContainsString( 'WooPayments is now active on each site. Each site&#039;s administrators can start their switch.', $notice );
	}

	/**
	 * @testdox On a network whose main site has no WooCommerce, a store site still offers the per-site activation: its handler, its result notice and the network activation check are hooked outside network admin, and the store's refusal notice offers a super admin the button posting to that site.
	 * @group multisite
	 */
	public function test_store_site_offers_the_per_site_activation_without_woocommerce_on_the_main_site(): void {
		$this->skip_unless_multisite();
		$this->assertFalse( is_network_admin() );
		$this->assertSame( 10, has_action( 'admin_init', array( WooPaymentsPerSiteActivationController::class, 'handle_conversion_request' ) ), 'The handler is hooked outside network admin.' );
		$this->assertSame( 10, has_action( 'admin_notices', array( WooPaymentsPerSiteActivationController::class, 'handle_site_admin_notices' ) ), 'The site result notice is hooked outside network admin.' );
		$this->assertSame( 10, has_action( 'activate_plugin', array( WooPaymentsPerSiteActivationController::class, 'handle_activate_plugin' ) ), 'The network activation check is hooked outside the setup tiers.' );
		$main = get_current_blog_id();
		$shop = $this->create_site( 'shop', true, true );
		$this->arrange_site( $main, false, false );
		$this->activate_for_every_site();
		$this->sites_without_woocommerce = array( $main );
		wp_set_current_user( 1 );

		switch_to_blog( $shop );
		try {
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			ob_start();
			wc_get_container()->get( WooPaymentsCutoverController::class )->output_admin_notices();
			$notice       = (string) ob_get_clean();
			$shop_plugins = admin_url( 'plugins.php' );
		} finally {
			restore_current_blog();
		}

		$this->assertStringContainsString( 'A super admin first needs to activate WooPayments on each site instead.', $notice );
		$this->assertSame( 1, preg_match( '/<a class="button button-primary" href="([^"]*)">Activate WooPayments per site<\/a>/', $notice, $matches ), 'The refusal offers the per-site activation.' );
		$href = html_entity_decode( $matches[1] );
		$this->assertStringStartsWith( $shop_plugins . '?', $href, 'The button posts to the store site itself.' );
		$this->assertStringContainsString( WooPaymentsPerSiteActivationController::QUERY_ACTION . '=' . WooPaymentsPerSiteActivationController::ACTION_CONVERT, $href );
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
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), $preflight, wc_get_container()->get( WooPaymentsCutoverReconciliationJob::class ), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsSetupTier::class ) );
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
	 * Store a site's setup tier and forget the tier the container read for it.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $tier    Setup tier.
	 */
	private function set_stored_tier( int $site_id, string $tier ): void {
		switch_to_blog( $site_id );
		try {
			update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
		} finally {
			restore_current_blog();
		}
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate( $site_id );
	}

	/**
	 * Fire WordPress's activate_plugin hook for the extension, as activate_plugin() does before it writes the active list.
	 *
	 * WordPress 7.1 wp-admin/includes/plugin.php:688 fires it with the plugin file and whether the activation is network-wide.
	 *
	 * @param bool $network_wide Whether the activation is for every site.
	 */
	private function fire_activate_plugin( bool $network_wide ): void {
		do_action( 'activate_plugin', WooPaymentsRuntimeArbiter::PLUGIN_FILE, $network_wide ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WordPress's own hook, fired as the core function does.
	}

	/**
	 * Fire WordPress's activated_plugin hook for the extension, as activate_plugin() does after it writes the active list.
	 *
	 * WordPress 7.1.3 wp-admin/includes/plugin.php:736 fires it with the plugin file and whether the activation is network-wide.
	 *
	 * @param bool   $network_wide Whether the activation is for every site.
	 * @param string $plugin_file  The extension's main file.
	 */
	private function fire_activated_plugin( bool $network_wide, string $plugin_file = WooPaymentsRuntimeArbiter::PLUGIN_FILE ): void {
		do_action( 'activated_plugin', $plugin_file, $network_wide ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- WordPress's own hook, fired as the core function does.
	}

	/**
	 * Record the message, title and arguments of the next wp_die() call, then stop as WordPress's test handler does.
	 */
	private function fake_wp_die(): void {
		add_filter(
			'wp_die_handler',
			function () {
				return function ( $message = '', $title = '', $args = array() ): void {
					$this->wp_die = array(
						'message' => $message,
						'title'   => $title,
						'args'    => is_array( $args ) ? $args : array(),
					);
					throw new \WPDieException( esc_html( (string) $message ) );
				};
			}
		);
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
		$record = $this->get_record_on( $site_id );
		$this->assertIsArray( $record );

		return $record;
	}

	/**
	 * Get a site's cutover record, or null when it has none.
	 *
	 * @param int $site_id Site ID.
	 * @return array<string,mixed>|null
	 */
	private function get_record_on( int $site_id ): ?array {
		switch_to_blog( $site_id );
		try {
			wp_cache_delete( WooPaymentsCutoverStateStore::OPTION_NAME, 'options' );
			return wc_get_container()->get( WooPaymentsCutoverStateStore::class )->get_record();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Read who owns payments on a site, as a new request there would.
	 *
	 * @param int $site_id Site ID.
	 * @return string
	 */
	private function read_owner( int $site_id ): string {
		switch_to_blog( $site_id );
		try {
			$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
			$arbiter->invalidate();
			return $arbiter->get_runtime_owner();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Make the extension active for every site and stand in for WordPress's plugin activation functions.
	 *
	 * @param string $plugin_file The extension's main file.
	 */
	private function activate_for_every_site( string $plugin_file = WooPaymentsRuntimeArbiter::PLUGIN_FILE ): void {
		update_site_option( 'active_sitewide_plugins', array( $plugin_file => time() ) );
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
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsCutoverPreflightService::class ), wc_get_container()->get( WooPaymentsCutoverReconciliationJob::class ), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( WooPaymentsSetupTier::class ) );

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
