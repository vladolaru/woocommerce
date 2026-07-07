<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\WooPaymentsWooPayExtensionSync;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsWooPayExtensionSync class.
 */
class WooPaymentsWooPayExtensionSyncTest extends WC_Unit_Test_Case {

	/**
	 * Created sync instances whose hooks must be removed after each test.
	 *
	 * @var WooPaymentsWooPayExtensionSync[]
	 */
	private array $syncs = array();

	/**
	 * Previous active plugins option value.
	 *
	 * @var mixed
	 */
	private $previous_active_plugins;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->previous_active_plugins = get_option( 'active_plugins', array() );
		$this->clear_scheduled_actions();
		$this->delete_sync_options();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->syncs as $sync ) {
			$this->remove_sync_hooks( $sync );
		}

		update_option( 'active_plugins', $this->previous_active_plugins );
		$this->clear_scheduled_actions();
		$this->delete_sync_options();

		parent::tearDown();
	}

	/**
	 * @testdox WooPay extension sync hooks are registered only when native owns runtime.
	 */
	public function test_registers_hooks_only_when_native_owns_runtime(): void {
		$native_sync = $this->create_sync( true );

		$native_sync->register();

		$this->assertSame( 10, has_action( 'init', array( $native_sync, 'schedule' ) ) );
		$this->assertSame( 10, has_action( 'validate_woopay_compatibility', array( $native_sync, 'update_compatibility_and_maybe_show_incompatibility_warning' ) ) );
		$this->assertSame( 10, has_action( 'activated_plugin', array( $native_sync, 'show_warning_when_incompatible_extension_is_enabled' ) ) );
		$this->assertSame( 10, has_action( 'deactivated_plugin', array( $native_sync, 'hide_warning_when_incompatible_extension_is_disabled' ) ) );
		$this->assertSame( 10, has_action( 'woocommerce_woocommerce_payments_updated', array( $native_sync, 'remove_legacy_schedule_action_name_on_update' ) ) );

		$plugin_sync = $this->create_sync( false );
		$plugin_sync->register();

		$this->assertFalse( has_action( 'init', array( $plugin_sync, 'schedule' ) ) );
		$this->assertFalse( has_action( 'validate_woopay_compatibility', array( $plugin_sync, 'update_compatibility_and_maybe_show_incompatibility_warning' ) ) );
		$this->assertFalse( has_action( 'activated_plugin', array( $plugin_sync, 'show_warning_when_incompatible_extension_is_enabled' ) ) );
		$this->assertFalse( has_action( 'deactivated_plugin', array( $plugin_sync, 'hide_warning_when_incompatible_extension_is_disabled' ) ) );
		$this->assertFalse( has_action( 'woocommerce_woocommerce_payments_updated', array( $plugin_sync, 'remove_legacy_schedule_action_name_on_update' ) ) );
	}

	/**
	 * @testdox WooPay compatibility validation is scheduled as a preserved recurring Action Scheduler hook.
	 */
	public function test_schedule_creates_recurring_compatibility_action(): void {
		$sync = $this->create_sync( true );

		$sync->schedule();
		$sync->schedule();

		$this->assertSame( 1, $this->count_pending_actions( 'validate_woopay_compatibility' ) );
	}

	/**
	 * @testdox Legacy incompatible-extension cron hook is cleared on WooPayments updates.
	 */
	public function test_remove_legacy_schedule_action_name_on_update_clears_wp_cron_hook(): void {
		$sync = $this->create_sync( true );

		wp_schedule_event( time(), 'daily', 'validate_incompatible_extensions' );
		$this->assertNotFalse( wp_next_scheduled( 'validate_incompatible_extensions' ) );

		$sync->remove_legacy_schedule_action_name_on_update();

		$this->assertFalse( wp_next_scheduled( 'validate_incompatible_extensions' ) );
	}

	/**
	 * @testdox Compatibility sync preserves WooPay option names and recomputes active incompatible/adapted extensions.
	 */
	public function test_update_compatibility_and_maybe_show_incompatibility_warning_updates_options(): void {
		update_option(
			'active_plugins',
			array(
				'bad-extension/bad-extension.php',
				'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
			)
		);
		update_option( 'woopay_invalid_extension_found', false );
		update_option( 'woopay_enabled_adapted_extensions', array( 'stale-extension' ) );

		$sync = $this->create_sync(
			true,
			array(
				'incompatible_extensions' => array( 'bad-extension' ),
				'adapted_extensions'      => array( 'woocommerce-points-and-rewards', 'woocommerce-gift-cards' ),
				'available_countries'     => array( 'US', 'BR' ),
			)
		);

		$sync->update_compatibility_and_maybe_show_incompatibility_warning();

		$this->assertSame( array( 'bad-extension' ), get_option( 'woopay_incompatible_extensions' ) );
		$this->assertTrue( get_option( 'woopay_invalid_extension_found' ) );
		$this->assertSame( array( 'woocommerce-points-and-rewards', 'woocommerce-gift-cards' ), get_option( 'woopay_adapted_extensions' ) );
		$this->assertSame( array( 'woocommerce-points-and-rewards' ), get_option( 'woopay_enabled_adapted_extensions' ) );
		$this->assertSame( '["US","BR"]', get_option( 'woocommerce_woocommerce_payments_woopay_available_countries' ) );
	}

	/**
	 * @testdox Compatibility sync clears the warning option when no active incompatible extension remains.
	 */
	public function test_update_compatibility_clears_warning_when_no_incompatible_extension_is_active(): void {
		update_option( 'active_plugins', array( 'safe-extension/safe-extension.php' ) );
		update_option( 'woopay_invalid_extension_found', true );

		$sync = $this->create_sync(
			true,
			array(
				'incompatible_extensions' => array( 'bad-extension' ),
				'adapted_extensions'      => array(),
				'available_countries'     => array(),
			)
		);

		$sync->update_compatibility_and_maybe_show_incompatibility_warning();

		$this->assertNull( get_option( 'woopay_invalid_extension_found', null ) );
	}

	/**
	 * @testdox Plugin activation and deactivation update WooPay incompatibility warning state.
	 */
	public function test_activation_and_deactivation_update_incompatibility_warning_state(): void {
		update_option( 'woopay_incompatible_extensions', array( 'bad-extension' ) );
		update_option( 'woopay_adapted_extensions', array( 'good-extension' ) );
		update_option(
			'active_plugins',
			array(
				'bad-extension/bad-extension.php',
				'good-extension/good-extension.php',
			)
		);

		$sync = $this->create_sync( true );

		$sync->show_warning_when_incompatible_extension_is_enabled( 'bad-extension/bad-extension.php' );

		$this->assertTrue( get_option( 'woopay_invalid_extension_found' ) );
		$this->assertSame( array( 'good-extension' ), get_option( 'woopay_enabled_adapted_extensions' ) );

		$sync->hide_warning_when_incompatible_extension_is_disabled( 'bad-extension/bad-extension.php' );

		$this->assertNull( get_option( 'woopay_invalid_extension_found', null ) );
		$this->assertSame( array( 'good-extension' ), get_option( 'woopay_enabled_adapted_extensions' ) );
	}

	/**
	 * Create a sync instance.
	 *
	 * @param bool                $native_register Whether native should register.
	 * @param array<string,mixed> $compatibility   Compatibility response.
	 * @return WooPaymentsWooPayExtensionSync
	 */
	private function create_sync( bool $native_register, array $compatibility = array() ): WooPaymentsWooPayExtensionSync {
		$this->assertTrue( class_exists( WooPaymentsWooPayExtensionSync::class ), 'WooPaymentsWooPayExtensionSync should exist.' );

		$api_client = $this->getMockBuilder( WooPaymentsApiClient::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_woopay_compatibility' ) )
			->getMock();
		$api_client->method( 'get_woopay_compatibility' )
			->willReturn( $compatibility );

		$sync = new WooPaymentsWooPayExtensionSync();
		$sync->init( new StaticNativeRuntimeArbiter( $native_register ), $api_client );

		$this->syncs[] = $sync;

		return $sync;
	}

	/**
	 * Remove hooks registered by a sync instance.
	 *
	 * @param WooPaymentsWooPayExtensionSync $sync Sync instance.
	 */
	private function remove_sync_hooks( WooPaymentsWooPayExtensionSync $sync ): void {
		remove_action( 'init', array( $sync, 'schedule' ) );
		remove_action( 'validate_woopay_compatibility', array( $sync, 'update_compatibility_and_maybe_show_incompatibility_warning' ) );
		remove_action( 'activated_plugin', array( $sync, 'show_warning_when_incompatible_extension_is_enabled' ) );
		remove_action( 'deactivated_plugin', array( $sync, 'hide_warning_when_incompatible_extension_is_disabled' ) );
		remove_action( 'woocommerce_woocommerce_payments_updated', array( $sync, 'remove_legacy_schedule_action_name_on_update' ) );
	}

	/**
	 * Count pending Action Scheduler actions.
	 *
	 * @param string $hook Hook name.
	 * @return int
	 */
	private function count_pending_actions( string $hook ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'   => $hook,
				'group'  => 'woocommerce_payments',
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		return count( $actions );
	}

	/**
	 * Clear scheduled compatibility actions.
	 */
	private function clear_scheduled_actions(): void {
		wp_clear_scheduled_hook( 'validate_woopay_compatibility' );
		wp_clear_scheduled_hook( 'validate_incompatible_extensions' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'validate_woopay_compatibility', array(), 'woocommerce_payments' );
			as_unschedule_all_actions( 'validate_incompatible_extensions', array(), 'woocommerce_payments' );
		}
	}

	/**
	 * Delete WooPay extension sync options.
	 */
	private function delete_sync_options(): void {
		delete_option( 'woopay_invalid_extension_found' );
		delete_option( 'woopay_incompatible_extensions' );
		delete_option( 'woopay_enabled_adapted_extensions' );
		delete_option( 'woopay_adapted_extensions' );
		delete_option( 'woocommerce_woocommerce_payments_woopay_available_countries' );
	}
}
