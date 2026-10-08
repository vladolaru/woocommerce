<?php
/**
 * WooPaymentsSetupTier tests.
 *
 * @package WooCommerce\Tests\Internal\Payments
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use WC_Unit_Test_Case;

/**
 * Tests for WooPaymentsSetupTier and its authoritative writers.
 */
class WooPaymentsSetupTierTest extends WC_Unit_Test_Case {

	/** @var WooPaymentsSetupTier */
	private WooPaymentsSetupTier $state;

	/** @var WooPaymentsAccountService */
	private WooPaymentsAccountService $account_service;

	/** @var WooPaymentsGatewaySettingsSynchronizer */
	private WooPaymentsGatewaySettingsSynchronizer $settings_synchronizer;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );
		delete_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION );

		$container                   = wc_get_container();
		$this->state                 = $container->get( WooPaymentsSetupTier::class );
		$this->account_service       = $container->get( WooPaymentsAccountService::class );
		$this->settings_synchronizer = $container->get( WooPaymentsGatewaySettingsSynchronizer::class );
		$container->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		$this->state->invalidate();
		$this->account_service->clear_cache();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		remove_all_filters( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME );
		remove_all_filters( 'pre_update_option_wcpay_account_data' );
		remove_all_filters( 'option_' . WooPaymentsSetupTier::OPTION_NAME );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		delete_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		$this->state->invalidate();

		parent::tearDown();
	}

	/**
	 * @testdox State persistence accepts exact tiers, rejects unknown values, and verifies readback.
	 */
	public function test_write_tier_validates_and_verifies_persistence(): void {
		$this->assertTrue( $this->state->write_tier( WooPaymentsSetupTier::CONNECTED ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
		$this->assertFalse( $this->state->write_tier( 'CONNECTED' ) );

		add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, '__return_false' );
		$this->assertFalse( $this->state->write_tier( WooPaymentsSetupTier::ACTIVE ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox A state write that does not read back logs one error with the requested and stored values.
	 */
	public function test_write_tier_logs_a_failed_write(): void {
		$logger = RecordingWcLogger::install();
		$this->assertTrue( $this->state->write_tier( WooPaymentsSetupTier::CONNECTED ) );

		// The filter turns the written value into false, so false is what reads back.
		add_filter( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME, '__return_false' );
		$this->assertFalse( $this->state->write_tier( WooPaymentsSetupTier::ACTIVE ) );

		$this->assertSame(
			array( array( 'error', 'Native payments state write failed: requested active, stored false, autoloaded yes.', 'native-payments' ) ),
			$logger->lines
		);
	}

	/**
	 * @testdox Rewriting an existing tier repairs its option to autoload even when the value is unchanged.
	 */
	public function test_write_tier_repairs_autoload_for_an_existing_same_value(): void {
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		add_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE, '', false );
		$this->assertArrayNotHasKey( WooPaymentsSetupTier::OPTION_NAME, wp_load_alloptions( true ) );

		$this->assertTrue( $this->state->write_tier( WooPaymentsSetupTier::ACTIVE ) );
		$this->assertArrayHasKey( WooPaymentsSetupTier::OPTION_NAME, wp_load_alloptions( true ) );
	}

	/**
	 * @testdox Rewriting the unchanged autoloaded tier after an account refresh runs no query.
	 */
	public function test_write_tier_runs_no_query_when_the_autoloaded_tier_is_unchanged(): void {
		global $wpdb;
		$this->assertTrue( $this->state->write_tier( WooPaymentsSetupTier::ACTIVE ) );
		wp_load_alloptions( true );

		$queries = $wpdb->num_queries;
		$this->assertTrue( $this->state->write_tier( WooPaymentsSetupTier::ACTIVE ) );

		$this->assertSame( 0, $wpdb->num_queries - $queries, 'Client 11.1.0 Database_Cache::write_to_cache() stops at update_option() and a cache delete.' );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox A disabled tier on a store the built-in WooPayments owns lists no class for any request type.
	 */
	public function test_disabled_tier_lists_no_class_for_any_request_type(): void {
		$this->state->write_tier( WooPaymentsSetupTier::DISABLED );

		foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request_type ) {
			$this->assertSame( array(), $this->state->get_classes_for_request( $request_type ), $request_type );
		}
	}

	/**
	 * @testdox State reads are memoized until explicitly invalidated.
	 */
	public function test_get_effective_tier_memoizes_reads_until_invalidated(): void {
		$reads = 0;
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		add_filter(
			'option_' . WooPaymentsSetupTier::OPTION_NAME,
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		$this->assertSame( 1, $reads );

		$this->state->invalidate();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		$this->assertSame( 2, $reads );
	}

	/**
	 * @testdox Plugin ownership clamps connected and active stored tiers to available.
	 * @dataProvider provide_connected_tiers
	 *
	 * @param string $stored_state Stored connected tier.
	 */
	public function test_get_effective_tier_clamps_connected_tiers_while_plugin_is_active( string $stored_state ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_state );
		$this->state->invalidate();
		$this->assertSame( $stored_state, $this->state->get_effective_tier() );

		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox Owner-less sites return disabled without overwriting their stored state.
	 * @dataProvider provide_native_payments_states
	 *
	 * @param string $stored_state Stored native payments tier.
	 */
	public function test_get_effective_tier_returns_disabled_without_overwriting_the_stored_state_when_no_runtime_owns_the_site( string $stored_state ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_state );
		$this->state->invalidate();
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );

		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );
		$this->assertSame( $stored_state, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
	}

	/**
	 * @testdox State memoization remains isolated by blog.
	 * @group multisite
	 */
	public function test_get_effective_tier_memoizes_per_blog(): void {
		$this->skipWithoutMultisite();
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$this->state->invalidate();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );

		$blog_id = self::factory()->blog->create();
		try {
			switch_to_blog( $blog_id );
			update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::CONNECTED );
			$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->state->get_effective_tier() );
			restore_current_blog();

			$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		} finally {
			while ( ms_is_switched() ) {
				restore_current_blog();
			}
			$this->state->invalidate( $blog_id );
			wpmu_delete_blog( $blog_id, true );
		}
	}

	/**
	 * Provide connected state tiers.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_connected_tiers(): array {
		return array(
			'connected' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'    => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * Provide every stored native payments tier.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_native_payments_states(): array {
		return array(
			'disabled'  => array( WooPaymentsSetupTier::DISABLED ),
			'available' => array( WooPaymentsSetupTier::AVAILABLE ),
			'connected' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'    => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * @testdox A verified account write connects, while a verified empty reset disconnects.
	 */
	public function test_account_writer_connects_and_disconnects(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'no' ) );

		$this->account_service->cache_account_data( array( 'account_id' => 'acct_123' ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->state->get_effective_tier() );

		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox Failed persistence and cache deletion do not infer disconnection.
	 */
	public function test_account_writer_preserves_state_for_failed_persistence_and_deletion(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		$this->account_service->cache_account_data( array( 'account_id' => 'acct_123' ) );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->state->get_effective_tier() );

		add_filter( 'pre_update_option_wcpay_account_data', '__return_false' );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->state->get_effective_tier() );

		remove_all_filters( 'pre_update_option_wcpay_account_data' );
		$this->account_service->clear_cache();
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox Canonical settings enable only for exact yes and otherwise return active accounts to connected.
	 */
	public function test_settings_writer_enables_and_disables_an_existing_connection(): void {
		$this->state->write_tier( WooPaymentsSetupTier::CONNECTED );

		$this->settings_synchronizer->persist( array( 'enabled' => 'yes' ) );
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, $this->state->get_effective_tier() );

		$this->settings_synchronizer->persist( array( 'enabled' => 'YES' ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox Kill-switch and authoritative ineligibility inputs disable native payments.
	 */
	public function test_account_writer_applies_disable_precedence(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		$this->account_service->cache_account_data(
			array(
				'account_id'      => 'acct_123',
				'native_payments' => array( 'eligible' => false ),
			)
		);
		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );

		$this->state->write_tier( WooPaymentsSetupTier::ACTIVE );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, true );
		$this->account_service->cache_account_data( array( 'account_id' => 'acct_456' ) );
		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox A plugin account cache write moves the state both ways with the account's native eligibility.
	 */
	public function test_plugin_account_cache_write_follows_native_eligibility(): void {
		$this->make_plugin_own_the_runtime();
		$this->state->write_tier( WooPaymentsSetupTier::DISABLED );

		$this->account_service->sync_setup_tier_from_extension_account_cache( $this->plugin_account_cache( array( 'eligible' => true ) ) );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier(), 'An account the platform made eligible after the upgrade repair must reach the start notice.' );

		$this->account_service->sync_setup_tier_from_extension_account_cache( $this->plugin_account_cache( array( 'eligible' => false ) ) );
		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier(), 'Withdrawn eligibility must write disabled again.' );

		$this->account_service->sync_setup_tier_from_extension_account_cache( $this->plugin_account_cache( null ) );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier(), 'An account without the eligibility block counts as eligible, as in the upgrade repair.' );
	}

	/**
	 * @testdox Errored or data-less plugin account cache writes keep the prior state.
	 */
	public function test_plugin_account_cache_write_ignores_errored_and_empty_writes(): void {
		$this->make_plugin_own_the_runtime();
		$this->state->write_tier( WooPaymentsSetupTier::DISABLED );

		$errored            = $this->plugin_account_cache( array( 'eligible' => true ) );
		$errored['errored'] = true;
		$this->account_service->sync_setup_tier_from_extension_account_cache( $errored );
		$this->account_service->sync_setup_tier_from_extension_account_cache(
			array(
				'data'    => null,
				'fetched' => time(),
				'errored' => false,
			)
		);
		$this->account_service->sync_setup_tier_from_extension_account_cache( false );

		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox A plugin account cache write changes nothing once the plugin no longer owns the runtime.
	 */
	public function test_plugin_account_cache_write_needs_the_plugin_runtime(): void {
		$this->state->write_tier( WooPaymentsSetupTier::DISABLED );

		$this->account_service->sync_setup_tier_from_extension_account_cache( $this->plugin_account_cache( array( 'eligible' => true ) ) );

		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );
	}

	/**
	 * @testdox A store without the WooPayments plugin adds no account cache listener.
	 */
	public function test_store_without_the_plugin_adds_no_account_cache_listener(): void {
		$this->assertFalse( has_action( 'add_option_wcpay_account_data' ) );
		$this->assertFalse( has_action( 'update_option_wcpay_account_data' ) );
	}

	/**
	 * Mark the WooPayments plugin active so it owns the payments runtime.
	 */
	private function make_plugin_own_the_runtime(): void {
		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Build account cache contents in the shape the plugin's Database_Cache writes.
	 *
	 * @param array<string,mixed>|null $native_payments Native payments block, or null to leave it out.
	 * @return array<string,mixed>
	 */
	private function plugin_account_cache( ?array $native_payments ): array {
		$data = array( 'account_id' => 'acct_plugin' );
		if ( null !== $native_payments ) {
			$data['native_payments'] = $native_payments;
		}

		return array(
			'data'               => $data,
			'fetched'            => time(),
			'errored'            => false,
			'consecutive_errors' => 0,
		);
	}
}
