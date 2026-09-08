<?php
/**
 * NativePaymentsState tests.
 *
 * @package WooCommerce\Tests\Internal\Payments
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use ReflectionProperty;
use WC_Unit_Test_Case;

/**
 * Tests for NativePaymentsState and its authoritative writers.
 */
class NativePaymentsStateTest extends WC_Unit_Test_Case {

	/** @var NativePaymentsState */
	private NativePaymentsState $state;

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
		$this->state                 = $container->get( NativePaymentsState::class );
		$this->account_service       = $container->get( WooPaymentsAccountService::class );
		$this->settings_synchronizer = $container->get( WooPaymentsGatewaySettingsSynchronizer::class );
		$this->state->invalidate();
		$this->account_service->clear_cache();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		remove_all_filters( 'pre_update_option_' . NativePaymentsState::OPTION_NAME );
		remove_all_filters( 'pre_update_option_wcpay_account_data' );
		remove_all_filters( 'option_' . NativePaymentsState::OPTION_NAME );
		delete_option( NativePaymentsState::OPTION_NAME );
		delete_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );
		$this->state->invalidate();

		parent::tearDown();
	}

	/**
	 * @testdox The runtime container resolves one state store and injects it into both authoritative writers.
	 */
	public function test_runtime_container_injects_state_into_authoritative_writers(): void {
		$this->assertSame( $this->state, wc_get_container()->get( NativePaymentsState::class ) );
		$this->assertSame( $this->state, $this->read_private_property( $this->account_service, 'native_payments_state' ) );
		$this->assertSame( $this->state, $this->read_private_property( $this->settings_synchronizer, 'native_payments_state' ) );
	}

	/**
	 * @testdox State persistence accepts exact tiers, rejects unknown values, and verifies readback.
	 */
	public function test_write_state_validates_and_verifies_persistence(): void {
		$this->assertTrue( $this->state->write_state( NativePaymentsState::CONNECTED ) );
		$this->assertSame( NativePaymentsState::CONNECTED, get_option( NativePaymentsState::OPTION_NAME ) );
		$this->assertFalse( $this->state->write_state( 'CONNECTED' ) );

		add_filter( 'pre_update_option_' . NativePaymentsState::OPTION_NAME, '__return_false' );
		$this->assertFalse( $this->state->write_state( NativePaymentsState::ACTIVE ) );
		$this->assertSame( NativePaymentsState::CONNECTED, $this->state->get_state() );
	}

	/**
	 * @testdox Rewriting an existing tier repairs its option to autoload even when the value is unchanged.
	 */
	public function test_write_state_repairs_autoload_for_an_existing_same_value(): void {
		add_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::ACTIVE, '', false );
		$this->assertArrayNotHasKey( NativePaymentsState::OPTION_NAME, wp_load_alloptions( true ) );

		$this->assertTrue( $this->state->write_state( NativePaymentsState::ACTIVE ) );
		$this->assertArrayHasKey( NativePaymentsState::OPTION_NAME, wp_load_alloptions( true ) );
	}

	/**
	 * @testdox State reads are memoized until explicitly invalidated.
	 */
	public function test_get_state_memoizes_reads_until_invalidated(): void {
		$reads = 0;
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::AVAILABLE );
		add_filter(
			'option_' . NativePaymentsState::OPTION_NAME,
			static function ( $value ) use ( &$reads ) {
				++$reads;
				return $value;
			}
		);

		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
		$this->assertSame( 1, $reads );

		$this->state->invalidate();
		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
		$this->assertSame( 2, $reads );
	}

	/**
	 * @testdox Plugin ownership clamps connected and active stored tiers to available.
	 * @dataProvider provide_connected_tiers
	 *
	 * @param string $stored_state Stored connected tier.
	 */
	public function test_get_state_clamps_connected_tiers_while_plugin_is_active( string $stored_state ): void {
		update_option( NativePaymentsState::OPTION_NAME, $stored_state );
		$this->state->invalidate();
		$this->assertSame( $stored_state, $this->state->get_state() );

		update_option( 'active_plugins', array( NativePaymentsRuntimeArbiter::PLUGIN_FILE ) );

		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
	}

	/**
	 * @testdox State memoization remains isolated by blog.
	 * @group multisite
	 */
	public function test_get_state_memoizes_per_blog(): void {
		$this->skipWithoutMultisite();
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::AVAILABLE );
		$this->state->invalidate();
		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );

		$blog_id = self::factory()->blog->create();
		try {
			switch_to_blog( $blog_id );
			update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::CONNECTED );
			$this->assertSame( NativePaymentsState::CONNECTED, $this->state->get_state() );
			restore_current_blog();

			$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
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
			'connected' => array( NativePaymentsState::CONNECTED ),
			'active'    => array( NativePaymentsState::ACTIVE ),
		);
	}

	/**
	 * @testdox A verified account write connects, while a verified empty reset disconnects.
	 */
	public function test_account_writer_connects_and_disconnects(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'no' ) );

		$this->account_service->cache_account_data( array( 'account_id' => 'acct_123' ) );
		$this->assertSame( NativePaymentsState::CONNECTED, $this->state->get_state() );

		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( NativePaymentsState::AVAILABLE, $this->state->get_state() );
	}

	/**
	 * @testdox Failed persistence and cache deletion do not infer disconnection.
	 */
	public function test_account_writer_preserves_state_for_failed_persistence_and_deletion(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		$this->account_service->cache_account_data( array( 'account_id' => 'acct_123' ) );
		$this->assertSame( NativePaymentsState::ACTIVE, $this->state->get_state() );

		add_filter( 'pre_update_option_wcpay_account_data', '__return_false' );
		$this->account_service->overwrite_cache_with_no_account();
		$this->assertSame( NativePaymentsState::ACTIVE, $this->state->get_state() );

		remove_all_filters( 'pre_update_option_wcpay_account_data' );
		$this->account_service->clear_cache();
		$this->assertSame( NativePaymentsState::ACTIVE, $this->state->get_state() );
	}

	/**
	 * @testdox Canonical settings enable only for exact yes and otherwise return active accounts to connected.
	 */
	public function test_settings_writer_enables_and_disables_an_existing_connection(): void {
		$this->state->write_state( NativePaymentsState::CONNECTED );

		$this->settings_synchronizer->persist( array( 'enabled' => 'yes' ) );
		$this->assertSame( NativePaymentsState::ACTIVE, $this->state->get_state() );

		$this->settings_synchronizer->persist( array( 'enabled' => 'YES' ) );
		$this->assertSame( NativePaymentsState::CONNECTED, $this->state->get_state() );
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
		$this->assertSame( NativePaymentsState::DISABLED, $this->state->get_state() );

		$this->state->write_state( NativePaymentsState::ACTIVE );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, true );
		$this->account_service->cache_account_data( array( 'account_id' => 'acct_456' ) );
		$this->assertSame( NativePaymentsState::DISABLED, $this->state->get_state() );
	}

	/**
	 * Read a private property for the DI wiring regression.
	 *
	 * @param object $instance      Object to inspect.
	 * @param string $property_name Property name.
	 * @return mixed
	 */
	private function read_private_property( object $instance, string $property_name ) {
		$property = new ReflectionProperty( $instance, $property_name );
		$property->setAccessible( true );

		return $property->getValue( $instance );
	}
}
