<?php
/**
 * WooPaymentsPerSiteActivationController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPerSiteActivationController;
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
	 * Calls to deactivate_plugins(): plugin file, silent, for every site.
	 *
	 * @var array<int,array{0:string,1:bool,2:mixed}>
	 */
	private array $deactivations = array();

	/**
	 * Sites where activate_plugin() fails.
	 *
	 * @var int[]
	 */
	private array $failing_sites = array();

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
		}
		unset( $_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_FAILED ], $_GET[ WooPaymentsPerSiteActivationController::QUERY_KEPT ] );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		parent::tearDown();
	}

	/**
	 * @testdox The conversion deactivates the extension for every site once, then activates it on each site that is not deleted, an archived one included.
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

		$result = $this->create_sut()->convert();

		$this->assertSame( array( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE, false, true ) ), $this->deactivations, 'One deactivation for every site, not silent.' );
		$this->assertSame( array( $main, $feature, $archived ), $result['activated'] );
		$this->assertSame( array(), $result['failed'] );
		foreach ( array( $main, $feature, $archived ) as $site_id ) {
			$this->assertSame( array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ), $this->activations[ $site_id ] ?? array(), "Site $site_id gets the extension." );
		}
		$this->assertArrayNotHasKey( $deleted, $this->activations, 'A deleted site is left alone.' );
	}

	/**
	 * @testdox A run after a partial failure lists the failed site with the button, and the next run activates only that site.
	 * @group multisite
	 */
	public function test_a_run_after_a_partial_failure_finishes_the_conversion(): void {
		$this->skip_unless_multisite();
		$main  = get_current_blog_id();
		$other = $this->create_site( 'other', true, true );
		$this->arrange_site( $main, true, true );
		$this->activate_for_every_site();
		$this->failing_sites = array( $other );

		$first = $this->create_sut()->convert();
		$this->assertSame( array( $other ), $first['failed'] );
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ]   = '1';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_FAILED ] = (string) $other;
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_KEPT ]   = '';
		$notice = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'WooPayments could not be activated on these sites: ' . $this->get_site_label( $other ) . '. Activate WooPayments per site again to finish.', $notice );
		$this->assertStringContainsString( 'Activate WooPayments per site</a>', $notice );

		$this->failing_sites = array();
		$this->activations   = array();
		$this->deactivations = array();
		$second              = $this->create_sut()->convert();

		$this->assertSame( array(), $this->deactivations, 'Nothing is active for every site any more.' );
		$this->assertSame( array( $other => array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) ), $this->activations, 'Only the failed site is activated.' );
		$this->assertSame( array( $main ), $second['skipped'] );
		$this->assertSame( array(), $second['failed'] );
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
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_DONE ]   = '1';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_FAILED ] = '';
		$_GET[ WooPaymentsPerSiteActivationController::QUERY_KEPT ]   = (string) $fresh;
		$notice = $this->render_notice( $this->create_sut() );
		$this->assertStringContainsString( 'Kept WooPayments in WooCommerce: ' . $this->get_site_label( $fresh ) . '.', $notice );
		$this->assertStringNotContainsString( 'could not be activated', $notice );
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
		$this->assertStringContainsString( 'Activate WooPayments per site so each site&#039;s administrators can start their switch. Best run off-peak', $offered );
		$this->assertStringContainsString( 'Activate WooPayments per site</a>', $offered );

		$this->assertSame( '', $this->render_notice( $this->create_sut(), false ), 'Nothing shows to a user who cannot manage network plugins.' );

		delete_site_option( 'active_sitewide_plugins' );
		$this->assertSame( '', $this->render_notice( $this->create_sut() ), 'Nothing shows once the extension is no longer active for every site.' );
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
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), $preflight, wc_get_container()->get( LegacyProxy::class ) );
		$this->mock_plugin_functions();

		$this->assertSame( '', $this->render_notice( $sut ) );
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
	 * Create a site with the WooPayments feature and the extension's run evidence set.
	 *
	 * @param string $slug          Site path slug.
	 * @param bool   $feature_on    Whether the WooPayments feature is on.
	 * @param bool   $ran_extension Whether the extension ran there.
	 * @return int Site ID.
	 */
	private function create_site( string $slug, bool $feature_on, bool $ran_extension ): int {
		$site_id          = self::factory()->blog->create( array( 'path' => '/' . $slug . '/' ) );
		$this->site_ids[] = $site_id;
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
	 * Make the extension active for every site and stand in for WordPress's plugin activation functions.
	 */
	private function activate_for_every_site(): void {
		update_site_option( 'active_sitewide_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => time() ) );
		$this->mock_plugin_functions();
	}

	/**
	 * Stand in for activate_plugin(), deactivate_plugins() and the capability check.
	 *
	 * @param bool $super_admin Whether the user may manage network plugins.
	 */
	private function mock_plugin_functions( bool $super_admin = true ): void {
		$this->register_legacy_proxy_function_mocks(
			array(
				'activate_plugin'    => function ( $plugin ) {
					$site_id = get_current_blog_id();
					if ( in_array( $site_id, $this->failing_sites, true ) ) {
						return new \WP_Error( 'activation_failed', 'Activation failed.' );
					}
					$this->activations[ $site_id ][] = $plugin;
					update_option( 'active_plugins', array_merge( (array) get_option( 'active_plugins', array() ), array( $plugin ) ) );
					return null;
				},
				'deactivate_plugins' => function ( $plugin, $silent = false, $for_every_site = null ): void {
					$this->deactivations[] = array( $plugin, $silent, $for_every_site );
					if ( true === $for_every_site ) {
						$active = (array) get_site_option( 'active_sitewide_plugins', array() );
						unset( $active[ $plugin ] );
						update_site_option( 'active_sitewide_plugins', $active );
					}
				},
				'current_user_can'   => fn( $capability ) => 'manage_network_plugins' === $capability ? $super_admin : current_user_can( $capability ),
			)
		);
	}

	/**
	 * Create the system under test with the container's arbiter, preflight and legacy proxy.
	 *
	 * @return WooPaymentsPerSiteActivationController
	 */
	private function create_sut(): WooPaymentsPerSiteActivationController {
		$sut = new WooPaymentsPerSiteActivationController();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsCutoverPreflightService::class ), wc_get_container()->get( LegacyProxy::class ) );

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
