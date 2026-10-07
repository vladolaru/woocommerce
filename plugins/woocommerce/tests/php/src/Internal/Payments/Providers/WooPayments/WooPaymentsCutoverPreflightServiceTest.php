<?php
/**
 * WooPaymentsCutoverPreflightService tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
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
	private bool $navigation_ready = true;

	/** @var bool */
	private bool $rate_client_connected = true;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.0' );
		update_option( WooPaymentsSettingsService::SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card' ) ) );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		as_unschedule_all_actions( WooPaymentsCutoverActionScheduler::ACTION_HOOK );
		as_unschedule_all_actions( 'wcpay_preflight_service_test' );
		as_unschedule_all_actions( 'woocommerce_woopayments_cutover_network_reconcile' );
		parent::tearDown();
	}

	/**
	 * @testdox Should build no check collaborator when initialized, so the cutover surfaces build no payment provider until a preflight runs.
	 */
	public function test_init_resolves_no_check_collaborator(): void {
		$sut = new WooPaymentsCutoverPreflightService();
		$sut->init( wc_get_container()->get( NativePaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ) );

		foreach ( array( 'provider', 'legacy_subscriptions_guard', 'fee_remediation_service', 'platform_connection_service', 'native_rate_account', 'native_rate_api_client', 'admin_navigation_controller' ) as $property ) {
			$reflection = new \ReflectionProperty( WooPaymentsCutoverPreflightService::class, $property );
			$reflection->setAccessible( true );
			$this->assertNull( $reflection->getValue( $sut ), $property . ' must be resolved when a preflight runs, not when the service is initialized.' );
		}
	}

	/**
	 * @testdox A store with every check passing reports no failure, including no undispositioned provider events.
	 */
	public function test_ready_store_reports_no_failures(): void {
		$this->assertSame( array(), $this->create_sut()->get_reconciliation_failures() );
	}

	/**
	 * @testdox The induced reconciliation vocabulary is exactly the 12 conditions a store can reach.
	 */
	public function test_induced_reconciliation_vocabulary_is_exactly_the_twelve_reachable_conditions(): void {
		$codes = array();
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_false' );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->provider_ready        = false;
		$this->platform_failures     = array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable' );
		$this->fee_remediation_ready = false;
		$this->navigation_ready      = false;
		$this->rate_client_connected = false;
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$this->create_legacy_stripe_billing_subscription_marker();
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), 'test', true );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );

		$unique_codes = array_values( array_unique( $codes ) );

		$this->assertSame( array( 'native_runtime_disabled', 'woopayments_plugin_version_unsupported', 'native_transport_unavailable', 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable', 'multi_currency_rates_unavailable', 'native_admin_surfaces_unavailable', 'operational_queue_hooks_undispositioned', 'financial_migrations_unavailable', 'legacy_stripe_billing_subscriptions_present' ), $unique_codes );
		$this->assertCount( 12, $unique_codes );
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

		$this->assertContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );
		$this->provider_ready = true;
		$this->assertContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );

		$sut->invalidate_current_blog_memoization();
		$this->assertNotContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );
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
			WooPaymentsCutoverActionScheduler::GROUP_ID,
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
		$obsolete_network_hook_id = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'woocommerce_woopayments_cutover_network_reconcile', array(), WooPaymentsCutoverActionScheduler::GROUP_ID, true );
		$other_action_id          = as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), WooPaymentsCutoverActionScheduler::GROUP_ID, true );

		$this->assertIsInt( $cutover_action_id );
		$this->assertIsInt( $same_hook_other_group_id );
		$this->assertIsInt( $obsolete_network_hook_id );
		$this->assertIsInt( $other_action_id );
		$actions = $this->create_sut()->get_queued_operational_actions();

		$this->assertSame( array( WooPaymentsCutoverActionScheduler::ACTION_HOOK, 'woocommerce_woopayments_cutover_network_reconcile', 'wcpay_preflight_service_test' ), array_column( $actions, 'hook' ) );
		$this->assertSame( array( $same_hook_other_group_id, $obsolete_network_hook_id, $other_action_id ), array_column( $actions, 'action_id' ) );
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

		$actions = $this->create_sut()->get_queued_plugin_actions();

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
			$sut->get_queued_plugin_actions()
		);
		$this->assertSame( array( 'operational_queue_hooks_undispositioned' ), $sut->get_reconciliation_failures() );
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
	 * @testdox Deactivation succeeds without touching plugins when no WooPayments plugin is active on the site or the network.
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
		$rate_client = $this->getMockBuilder( WooPaymentsNativeApiClientAdapter::class )->disableOriginalConstructor()->onlyMethods( array( 'is_server_connected' ) )->getMock();
		$rate_client->method( 'is_server_connected' )->willReturnCallback(
			function (): bool {
				return $this->rate_client_connected;
			}
		);
		$navigation = new class( $this->navigation_ready ) extends WooPaymentsAdminNavigationController {
			/**
			 * Whether native navigation routes are ready.
			 *
			 * @var bool
			 */
			private bool $navigation_ready;

			/**
			 * Initialize the navigation test double.
			 *
			 * @param bool $navigation_ready Whether native navigation routes are ready.
			 */
			public function __construct( bool $navigation_ready ) {
				$this->navigation_ready = $navigation_ready;
			}

			/**
			 * Report whether all available routes are registered.
			 *
			 * @return bool
			 */
			public function are_all_available_routes_registered(): bool {
				return $this->navigation_ready;
			}
		};
		$sut        = null === $database ? new WooPaymentsCutoverPreflightService() : new class( $database ) extends WooPaymentsCutoverPreflightService {
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
		$this->init_preflight_service( $sut, wc_get_container()->get( NativePaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ), $provider, new WooPaymentsLegacySubscriptionsGuard(), $fee_remediation, $platform_connection, $rate_account, $rate_client, $navigation );
		return $sut;
	}

	/**
	 * Create a subscription still billed by Stripe Billing, which makes a store without WooCommerce Subscriptions bundled.
	 */
	private function create_legacy_stripe_billing_subscription_marker(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'shop_subscription',
				'post_status' => 'wc-active',
				'post_title'  => 'Stripe-billed subscription',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		update_post_meta( $post_id, '_wcpay_subscription_id', 'sub_legacy' );
	}

	/**
	 * Initialize a preflight service with its check collaborators.
	 *
	 * The service resolves them when a preflight runs, so the test doubles are set on the instance after init.
	 *
	 * @param WooPaymentsCutoverPreflightService                    $service                     The preflight service.
	 * @param NativePaymentsRuntimeArbiter                          $arbiter                     The runtime arbiter.
	 * @param LegacyProxy                                           $legacy_proxy                The legacy proxy.
	 * @param WooPaymentsProvider                                   $provider                    The native provider.
	 * @param WooPaymentsLegacySubscriptionsGuard                   $legacy_subscriptions_guard  The legacy subscription data guard.
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service     The fee remediation owner.
	 * @param WooPaymentsPlatformConnectionService                  $platform_connection_service The platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter                       $native_rate_account         The native rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter                     $native_rate_api_client      The native rate API client boundary.
	 * @param WooPaymentsAdminNavigationController                  $admin_navigation_controller The native admin navigation owner.
	 */
	private function init_preflight_service( WooPaymentsCutoverPreflightService $service, NativePaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider, WooPaymentsLegacySubscriptionsGuard $legacy_subscriptions_guard, WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service, WooPaymentsPlatformConnectionService $platform_connection_service, WooPaymentsNativeAccountAdapter $native_rate_account, WooPaymentsNativeApiClientAdapter $native_rate_api_client, WooPaymentsAdminNavigationController $admin_navigation_controller ): void {
		$service->init( $arbiter, $legacy_proxy );

		$collaborators = array(
			'provider'                    => $provider,
			'legacy_subscriptions_guard'  => $legacy_subscriptions_guard,
			'fee_remediation_service'     => $fee_remediation_service,
			'platform_connection_service' => $platform_connection_service,
			'native_rate_account'         => $native_rate_account,
			'native_rate_api_client'      => $native_rate_api_client,
			'admin_navigation_controller' => $admin_navigation_controller,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsCutoverPreflightService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}
	}
}
