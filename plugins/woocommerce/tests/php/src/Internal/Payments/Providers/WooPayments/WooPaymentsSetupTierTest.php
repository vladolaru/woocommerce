<?php
/**
 * WooPaymentsSetupTier tests.
 *
 * @package WooCommerce\Tests\Internal\Payments
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
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

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );

		$container                   = wc_get_container();
		$this->state                 = $container->get( WooPaymentsSetupTier::class );
		$this->account_service       = $container->get( WooPaymentsAccountService::class );
		$this->settings_synchronizer = $container->get( WooPaymentsGatewaySettingsSynchronizer::class );
		$container->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$this->state->invalidate();
		$this->account_service->clear_cache();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_update_option_' . WooPaymentsSetupTier::OPTION_NAME );
		remove_all_filters( 'pre_update_option_wcpay_account_data' );
		remove_all_filters( 'option_' . WooPaymentsSetupTier::OPTION_NAME );
		delete_option( WooPaymentsSetupTier::OPTION_NAME );
		delete_option( 'wcpay_account_data' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'active_plugins' );
		delete_site_option( 'active_sitewide_plugins' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
			array( array( 'error', 'WooPayments setup tier write failed: requested active, stored false, autoloaded yes.', 'woopayments' ) ),
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
	 * @testdox With the WooPayments feature on, a store the WooPayments extension owns reads available whatever is stored ($_dataName).
	 * @dataProvider provide_stored_tiers_and_none
	 *
	 * @param string|null $stored_state Stored tier, or null for a store that never stored one.
	 */
	public function test_extension_owner_reads_available_whatever_is_stored( ?string $stored_state ): void {
		if ( null === $stored_state ) {
			delete_option( WooPaymentsSetupTier::OPTION_NAME );
		} else {
			update_option( WooPaymentsSetupTier::OPTION_NAME, $stored_state );
		}
		$this->make_plugin_own_the_runtime();
		$this->state->invalidate();

		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		$this->assertSame( $stored_state ?? false, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'Reading the effective tier writes nothing.' );
	}

	/**
	 * @testdox With the WooPayments feature off, a stored active tier reads disabled and lists no class for any request type, whoever owns payments ($_dataName).
	 * @dataProvider provide_extension_states
	 *
	 * @param bool $extension_active Whether the WooPayments extension is active.
	 */
	public function test_feature_off_reads_disabled_and_lists_no_class( bool $extension_active ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		if ( $extension_active ) {
			$this->make_plugin_own_the_runtime();
		}
		$this->state->invalidate();

		$this->assertSame( WooPaymentsSetupTier::DISABLED, $this->state->get_effective_tier() );
		foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request_type ) {
			$this->assertSame( array(), $this->state->get_classes_for_request( $request_type ), $request_type );
		}
		$this->assertSame( WooPaymentsSetupTier::ACTIVE, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );

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
			// The WooPayments feature is a per-site option.
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
	 * Provide every stored tier, and a store that never stored one.
	 *
	 * @return array<string,array{string|null}>
	 */
	public function provide_stored_tiers_and_none(): array {
		return array(
			'never stored' => array( null ),
			'disabled'     => array( WooPaymentsSetupTier::DISABLED ),
			'available'    => array( WooPaymentsSetupTier::AVAILABLE ),
			'connected'    => array( WooPaymentsSetupTier::CONNECTED ),
			'active'       => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * Provide the WooPayments extension's states.
	 *
	 * @return array<string,array{bool}>
	 */
	public function provide_extension_states(): array {
		return array(
			'the extension active'   => array( true ),
			'the extension inactive' => array( false ),
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
	 * @testdox With the WooPayments feature off, neither the account writer nor the settings writer writes the tier.
	 */
	public function test_tier_writers_write_nothing_while_the_feature_is_off(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		$this->state->write_tier( WooPaymentsSetupTier::CONNECTED );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$this->state->invalidate();

		// The minimal account data, the account id (client 11.1.0 includes/class-wc-payments-account.php:164-171).
		$this->account_service->cache_account_data( array( 'account_id' => 'acct_456' ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'The account writer.' );

		$this->settings_synchronizer->persist( array( 'enabled' => 'yes' ) );
		$this->assertSame( WooPaymentsSetupTier::CONNECTED, get_option( WooPaymentsSetupTier::OPTION_NAME ), 'The settings writer.' );
	}

	/**
	 * The built-in eligibility field `native_payments` (`eligible`, `cohort`, `reason`) exists only on an unmerged WPCOM branch,
	 * `748c7406e24`, never on trunk: `wp-content/rest-api-plugins/endpoints/wcpay/class-accounts-controller.php:1100-1104`
	 * builds it, `:1120-1121` sets the result and `:1206` adds it to the account response. WooCommerce no longer reads it.
	 *
	 * @testdox The tier sync ignores the account's built-in eligibility field while $_dataName.
	 * @dataProvider provide_tier_sync_owners
	 *
	 * @param bool   $extension_owns Whether the WooPayments extension owns payments.
	 * @param string $expected       The tier the account write stores.
	 */
	public function test_sync_setup_tier_ignores_the_account_payload_eligibility_field( bool $extension_owns, string $expected ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'enabled' => 'yes' ) );
		// The minimal account data, the account id (client 11.1.0 includes/class-wc-payments-account.php:164-171), and the eligibility field.
		$data                    = array( 'account_id' => 'acct_plugin' );
		$data['native_payments'] = array( 'eligible' => false );
		if ( $extension_owns ) {
			$this->make_plugin_own_the_runtime();
		}

		$this->account_service->cache_account_data( $data );

		$this->assertSame( $expected, get_option( WooPaymentsSetupTier::OPTION_NAME ) );
	}

	/**
	 * Who owns payments when the account is written, and the tier the write stores.
	 *
	 * @return array<string,array{0:bool,1:string}>
	 */
	public function provide_tier_sync_owners(): array {
		return array(
			'the built-in WooPayments owns payments'  => array( false, WooPaymentsSetupTier::ACTIVE ),
			'the WooPayments extension owns payments' => array( true, WooPaymentsSetupTier::AVAILABLE ),
		);
	}

	/**
	 * A store that ran the extension (its version option) belongs to the built-in WooPayments only after its one-click switch:
	 * once the switch's finalize deactivated the extension (finalize's token on the record) or the record is done (N-484,
	 * N-504). Until then it has no WooPayments and is offered the switch while the feature is on.
	 *
	 * @testdox The owner, the effective tier and the registered classes follow the switch: $_dataName.
	 * @dataProvider provide_switch_states
	 *
	 * @param bool        $extension_ran    Whether the extension's version option is stored.
	 * @param string|null $record_state     The cutover record's state, or null for no record.
	 * @param bool        $finalize_token   Whether the record holds finalize's request token.
	 * @param bool        $extension_active Whether the extension is active.
	 * @param string      $feature          The WooPayments feature option.
	 * @param string      $owner            Expected owner.
	 * @param string      $tier             Expected effective tier.
	 */
	public function test_owner_and_tier_follow_the_switch( bool $extension_ran, ?string $record_state, bool $finalize_token, bool $extension_active, string $feature, string $owner, string $tier ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature );
		if ( $extension_ran ) {
			update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
		}
		if ( null === $record_state ) {
			update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD );
		} else {
			update_option(
				WooPaymentsCutoverStateStore::OPTION_NAME,
				array(
					'state'                => $record_state,
					'request_origin_token' => $finalize_token ? 'finalize-request-token' : null,
				)
			);
		}
		if ( $extension_active ) {
			$this->make_plugin_own_the_runtime();
		}
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();
		$this->state->invalidate();

		$this->assertSame( $owner, $arbiter->get_runtime_owner() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE === $owner && 'yes' === $feature, $arbiter->is_awaiting_switch() );
		$this->assertSame( $tier, $this->state->get_effective_tier() );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN === $owner, in_array( WooPaymentsProvider::class, $this->state->get_classes_for_request( 'front' ), true ), 'A WooPayments gateway at checkout only while the built-in owns payments.' );
		$this->assertSame( WooPaymentsSetupTier::DISABLED !== $tier, in_array( WooPaymentsCutoverController::class, $this->state->get_classes_for_request( 'admin' ), true ), 'The switch controller loads in admin wherever a tier is on.' );
		if ( WooPaymentsSetupTier::DISABLED === $tier ) {
			foreach ( array( 'front', 'admin', 'ajax', 'rest', 'cron', 'cli' ) as $request_type ) {
				$this->assertSame( array(), $this->state->get_classes_for_request( $request_type ), $request_type );
			}
		}
	}

	/**
	 * Switch states, with the owner and the effective tier each gives (the stored tier is active in every case).
	 *
	 * @return array<string,array{0:bool,1:string|null,2:bool,3:bool,4:string,5:string,6:string}>
	 */
	public function provide_switch_states(): array {
		$none      = WooPaymentsRuntimeArbiter::OWNER_NONE;
		$builtin   = WooPaymentsRuntimeArbiter::OWNER_BUILTIN;
		$extension = WooPaymentsRuntimeArbiter::OWNER_EXTENSION;
		$pending   = WooPaymentsCutoverStateStore::STATE_PENDING;
		$deferred  = WooPaymentsCutoverStateStore::STATE_DEFERRED;
		$done      = WooPaymentsCutoverStateStore::STATE_DONE;

		return array(
			'a store that never ran the extension'     => array( false, null, false, false, 'yes', $builtin, WooPaymentsSetupTier::ACTIVE ),
			'deactivated by hand, no record'           => array( true, null, false, false, 'yes', $none, WooPaymentsSetupTier::AVAILABLE ),
			'deactivated by hand, merchant start open' => array( true, $pending, false, false, 'yes', $none, WooPaymentsSetupTier::AVAILABLE ),
			'past finalize, before done'               => array( true, $pending, true, false, 'yes', $builtin, WooPaymentsSetupTier::ACTIVE ),
			'ownership check deferred for days'        => array( true, $deferred, true, false, 'yes', $builtin, WooPaymentsSetupTier::ACTIVE ),
			'switch done'                              => array( true, $done, false, false, 'yes', $builtin, WooPaymentsSetupTier::ACTIVE ),
			'extension active again during the check'  => array( true, $pending, true, true, 'yes', $extension, WooPaymentsSetupTier::AVAILABLE ),
			'feature off, extension deactivated'       => array( true, null, false, false, 'no', $none, WooPaymentsSetupTier::DISABLED ),
			'feature off after the switch'             => array( true, $done, false, false, 'no', $none, WooPaymentsSetupTier::DISABLED ),
		);
	}

	/**
	 * N-512 hardening: an extension store keeps the disabled tier of its install; once it switched, reading that as
	 * disabled would register nothing, not even the job that writes the tier. A store never set up stays dormant.
	 *
	 * @testdox A built-in owner reads a stored disabled tier as available after a switch from the extension, and as disabled on a store that never ran it ($_dataName).
	 * @testWith ["a store that switched from the extension", true, "available"]
	 *           ["a store that never ran the extension", false, "disabled"]
	 *
	 * @param string $label         Case label.
	 * @param bool   $extension_ran Whether the extension's version option is stored.
	 * @param string $tier          Expected effective tier.
	 */
	public function test_builtin_owner_reads_a_stored_disabled_tier_by_history( string $label, bool $extension_ran, string $tier ): void {
		unset( $label );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::DISABLED );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		if ( $extension_ran ) {
			update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
			update_option(
				WooPaymentsCutoverStateStore::OPTION_NAME,
				array(
					'state'                => WooPaymentsCutoverStateStore::STATE_DONE,
					'request_origin_token' => null,
				)
			);
		} else {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD );
		}
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();
		$this->state->invalidate();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $arbiter->get_runtime_owner() );
		$this->assertSame( $tier, $this->state->get_effective_tier() );
		$this->assertSame( $extension_ran, in_array( WooPaymentsCutoverReconciliationJob::class, $this->state->get_classes_for_request( 'cron' ), true ), 'The job loads where it can finish the tier.' );
	}

	/**
	 * Codex 405 G1: finalize's token, before the ownership check finished, counts as the switch too, so the stored disabled
	 * tier of the extension era reads as available and the job that writes the tier loads.
	 *
	 * @testdox A built-in owner past finalize, before its ownership check finished, reads a stored disabled tier as available and loads the job.
	 */
	public function test_builtin_owner_past_finalize_reads_a_stored_disabled_tier_as_available(): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::DISABLED );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'woocommerce_woocommerce_payments_version', '11.1.0' );
		update_option(
			WooPaymentsCutoverStateStore::OPTION_NAME,
			array(
				'state'                => WooPaymentsCutoverStateStore::STATE_PENDING,
				'request_origin_token' => 'finalize-request-token',
			)
		);
		$arbiter = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$arbiter->invalidate();
		$this->state->invalidate();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $arbiter->get_runtime_owner() );
		$this->assertSame( WooPaymentsSetupTier::AVAILABLE, $this->state->get_effective_tier() );
		$this->assertContains( WooPaymentsCutoverReconciliationJob::class, $this->state->get_classes_for_request( 'cron' ) );
	}

	/**
	 * Mark the WooPayments plugin active so it owns the payments runtime.
	 */
	private function make_plugin_own_the_runtime(): void {
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}
}
