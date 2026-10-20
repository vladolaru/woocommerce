<?php
/**
 * WooPaymentsCutoverPreflightService tests.
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
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsBundledSubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the headless WooPayments cutover preflight service.
 */
class WooPaymentsCutoverPreflightServiceTest extends WC_Unit_Test_Case {

	/**
	 * Whether the provider can process payments.
	 *
	 * @var bool
	 */
	private bool $provider_ready = true;

	/** @var string[] */
	private array $platform_failures = array();

	/**
	 * Connection-owner details exposed by the platform boundary.
	 *
	 * @var array{owner_id:int,owner_exists:bool,user_token_available:bool}
	 */
	private array $platform_owner_status = array(
		'owner_id'             => 1,
		'owner_exists'         => true,
		'user_token_available' => true,
	);

	/** @var bool */
	private bool $fee_remediation_ready = true;

	/** @var string */
	private string $fee_remediation_status = 'scheduled';

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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.0' );
		update_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		as_unschedule_all_actions( WooPaymentsCutoverActionScheduler::ACTION_HOOK );
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
		parent::tearDown();
	}

	/**
	 * @testdox A store with every check passing reports no failure, including no undispositioned provider events.
	 */
	public function test_ready_store_reports_no_failures(): void {
		$this->assertSame( array(), $this->create_sut()->get_failures() );
	}

	/**
	 * @testdox The induced reconciliation vocabulary is exactly the 11 conditions a store can reach.
	 */
	public function test_induced_reconciliation_vocabulary_is_exactly_the_eleven_reachable_conditions(): void {
		$codes = array();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		$codes = array_merge( $codes, $this->create_sut()->get_failures() );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->provider_ready            = false;
		$this->platform_failures         = array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable' );
		$this->fee_remediation_ready     = false;
		$this->rate_api_client_connected = false;
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$this->create_bundled_stripe_billing_subscription( 'active' );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), 'test', true );
		$codes = array_merge( $codes, $this->create_sut()->get_failures() );

		$unique_codes = array_values( array_unique( $codes ) );

		$this->assertSame( array( 'builtin_runtime_disabled', 'woopayments_plugin_version_unsupported', 'builtin_transport_unavailable', 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable', 'multi_currency_rates_unavailable', 'operational_queue_hooks_unhandled', 'financial_migrations_unavailable', 'bundled_stripe_billing_subscriptions_present' ), $unique_codes );
		$this->assertCount( 11, $unique_codes );
	}

	/**
	 * @testdox Owner-token preflight exposes only the deleted-owner fact needed by reconciliation.
	 */
	public function test_connection_owner_user_missing_distinguishes_deleted_owner_from_missing_token(): void {
		$this->platform_owner_status = array(
			'owner_id'             => 7,
			'owner_exists'         => true,
			'user_token_available' => false,
		);
		$this->assertFalse( $this->create_sut()->is_cutover_connection_owner_user_missing() );

		$this->platform_owner_status['owner_exists'] = false;
		$this->assertTrue( $this->create_sut()->is_cutover_connection_owner_user_missing() );

		$this->platform_owner_status['owner_id'] = 0;
		$this->assertFalse( $this->create_sut()->is_cutover_connection_owner_user_missing() );
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
			WooPaymentsCutoverActionScheduler::ACTION_HOOK,
			array(
				'generation' => 1,
				'attempt'    => 1,
			),
			WooPaymentsCutoverActionScheduler::ACTION_GROUP,
			true
		);
		$same_hook_other_group_id = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			WooPaymentsCutoverActionScheduler::ACTION_HOOK,
			array(
				'generation' => 2,
				'attempt'    => 1,
			),
			'other-group',
			true
		);
		$foreign_hook_id          = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'woocommerce_woopayments_cutover_foreign_hook', array(), WooPaymentsCutoverActionScheduler::ACTION_GROUP, true );
		$other_action_id          = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), WooPaymentsCutoverActionScheduler::ACTION_GROUP, true );

		$this->assertIsInt( $cutover_action_id );
		$this->assertIsInt( $same_hook_other_group_id );
		$this->assertIsInt( $foreign_hook_id );
		$this->assertIsInt( $other_action_id );
		$actions = $this->create_sut()->get_queued_operational_actions();

		$this->assertSame( array( WooPaymentsCutoverActionScheduler::ACTION_HOOK, 'woocommerce_woopayments_cutover_foreign_hook', 'wcpay_preflight_service_test' ), array_column( $actions, 'hook' ) );
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
					'action_id' => 0,
					'hook'      => 'woocommerce_woopayments_operational_queue_query_failed',
					'group'     => '',
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
	 * @testdox Deactivation never reaches beyond the site: an extension active for every site stays active, and it reports false.
	 */
	public function test_deactivation_never_reaches_beyond_the_site(): void {
		$calls = array();
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'         => fn( $name, $default_value = false ) => 'active_plugins' === $name ? array() : get_option( $name, $default_value ),
				'get_site_option'    => fn( $name, $default_value = false ) => 'active_sitewide_plugins' === $name ? array( WooPaymentsRuntimeArbiter::PLUGIN_FILE => 1 ) : get_site_option( $name, $default_value ),
				'is_multisite'       => fn() => true,
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
	 * @testdox Fee remediation scheduling reports failure only when the remediation job cannot be scheduled.
	 */
	public function test_fee_remediation_scheduling_reports_unavailable(): void {
		$sut = $this->create_sut();
		$this->assertTrue( $sut->ensure_fee_remediation_scheduled() );

		$this->fee_remediation_status = 'not_needed';
		$this->assertTrue( $sut->ensure_fee_remediation_scheduled() );

		$this->fee_remediation_status = 'unavailable';
		$this->assertFalse( $sut->ensure_fee_remediation_scheduled() );
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
			'the platform connection owner user token is unavailable' => array(
				'wpcom_connection_owner_user_token_unavailable',
				static function ( self $test ): void {
					$test->platform_failures = array( 'wpcom_connection_owner_user_token_unavailable' );
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
			'the recorded WooPayments version is older than the floor' => array( 'woopayments_plugin_version_unsupported', $unsupported_version( '10.4.9' ) ),
			'an unknown WooPayments action is pending'     => array(
				'operational_queue_hooks_unhandled',
				static function ( self $test ): void {
					$test->schedule_queue_action( 'wcpay_synthetic_cutover_probe' );
				},
			),
			'financial migrations cannot be scheduled'     => array(
				'financial_migrations_unavailable',
				static function ( self $test ): void {
					$test->fee_remediation_ready = false;
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
			'Apple Pay domain retry'                => array( 'wcpay_register_apple_pay_domain' ),
			'authorization-fee remediation'         => array( 'wcpay_remediate_canceled_authorization_fees' ),
			'authorization-fee dry run'             => array( 'wcpay_remediate_canceled_authorization_fees_dry_run' ),
			'authorization-fee affected-order scan' => array( 'wcpay_check_affected_auth_fee_orders' ),
			'failed webhook fetch'                  => array( 'wcpay_webhook_fetch_events' ),
			'failed webhook processing'             => array( 'wcpay_webhook_process_event' ),
			'Stripe Billing migration scheduling'   => array( 'wcpay_schedule_subscription_migrations' ),
			'Stripe Billing migration'              => array( 'wcpay_migrate_subscription' ),
			'Stripe Billing migration retry'        => array( 'wcpay_migrate_subscription_retry' ),
		);
	}

	/**
	 * Create a headless preflight service with deterministic provider seams.
	 *
	 * @param \wpdb|null $database Optional WordPress database connection.
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_sut( ?\wpdb $database = null ): WooPaymentsCutoverPreflightService {
		$provider = $this->getMockBuilder( WooPaymentsProvider::class )->disableOriginalConstructor()->onlyMethods( array( 'can_process_payments' ) )->getMock();
		$provider->method( 'can_process_payments' )->willReturnCallback(
			function (): bool {
				return $this->provider_ready;
			}
		);
		$fee_remediation = $this->getMockBuilder( WooPaymentsCanceledAuthorizationFeeRemediationService::class )->disableOriginalConstructor()->onlyMethods( array( 'can_schedule_cutover_remediation', 'ensure_scheduled' ) )->getMock();
		$fee_remediation->method( 'can_schedule_cutover_remediation' )->willReturnCallback(
			function (): bool {
				return $this->fee_remediation_ready;
			}
		);
		$fee_remediation->method( 'ensure_scheduled' )->willReturnCallback(
			function (): string {
				return $this->fee_remediation_status;
			}
		);
		$platform_connection = $this->getMockBuilder( WooPaymentsPlatformConnectionService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_cutover_preflight_failures', 'get_cutover_connection_owner_user_token_status' ) )->getMock();
		$platform_connection->method( 'get_cutover_preflight_failures' )->willReturnCallback(
			function (): array {
				return $this->platform_failures;
			}
		);
		$platform_connection->method( 'get_cutover_connection_owner_user_token_status' )->willReturnCallback(
			function (): array {
				return $this->platform_owner_status;
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
		$sut = null === $database ? new WooPaymentsCutoverPreflightService() : new class( $database ) extends WooPaymentsCutoverPreflightService {
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
		$this->init_preflight_service( $sut, wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ), $provider, new WooPaymentsBundledSubscriptionsGuard(), $fee_remediation, $platform_connection, $rate_account, $rate_api_client );
		return $sut;
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
	 * @param WooPaymentsCutoverPreflightService                    $service                     The preflight service.
	 * @param WooPaymentsRuntimeArbiter                             $arbiter                     The runtime arbiter.
	 * @param LegacyProxy                                           $legacy_proxy                The legacy proxy.
	 * @param WooPaymentsProvider                                   $provider                    The native provider.
	 * @param WooPaymentsBundledSubscriptionsGuard                  $bundled_subscriptions_guard  The bundled subscriptions guard.
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service     The fee remediation owner.
	 * @param WooPaymentsPlatformConnectionService                  $platform_connection_service The platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter                       $rate_account         The rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter                     $rate_api_client      The rate API client boundary.
	 */
	private function init_preflight_service( WooPaymentsCutoverPreflightService $service, WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider, WooPaymentsBundledSubscriptionsGuard $bundled_subscriptions_guard, WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service, WooPaymentsPlatformConnectionService $platform_connection_service, WooPaymentsNativeAccountAdapter $rate_account, WooPaymentsNativeApiClientAdapter $rate_api_client ): void {
		$service->init( $arbiter, $legacy_proxy );

		$collaborators = array(
			'provider'                    => $provider,
			'bundled_subscriptions_guard' => $bundled_subscriptions_guard,
			'fee_remediation_service'     => $fee_remediation_service,
			'platform_connection_service' => $platform_connection_service,
			'rate_account'                => $rate_account,
			'rate_api_client'             => $rate_api_client,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsCutoverPreflightService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}
	}
}
