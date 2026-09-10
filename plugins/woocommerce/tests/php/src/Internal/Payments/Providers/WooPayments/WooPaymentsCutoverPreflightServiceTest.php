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

	/** @var bool */
	private bool $fee_remediation_ready = true;

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
		add_filter( WooPaymentsCutoverPreflightService::FILTER_NATIVE_TRANSPORT_READY, '__return_true' );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_TRANSPORT_READY );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_ADMIN_SURFACES_READY );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES );
		as_unschedule_all_actions( WooPaymentsCutoverActionScheduler::ACTION_HOOK );
		as_unschedule_all_actions( 'wcpay_preflight_service_test' );
		as_unschedule_all_actions( 'woocommerce_woopayments_cutover_network_reconcile' );
		parent::tearDown();
	}

	/**
	 * @testdox Reconciliation preflight keeps each invalid nested filter observable.
	 */
	public function test_reconciliation_failures_preserve_invalid_nested_filter_codes(): void {
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_true' );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_true' );

		$failures = $this->create_sut()->get_reconciliation_failures();

		$this->assertContains( 'provider_events_filter_invalid', $failures );
		$this->assertContains( 'operational_queue_hooks_filter_invalid', $failures );
		$this->assertNotContains( 'provider_events_undispositioned', $failures );
		$this->assertNotContains( 'operational_queue_hooks_undispositioned', $failures );
	}

	/**
	 * @testdox Final preflight filters cannot remove built-in safety conditions.
	 */
	public function test_final_preflight_filter_cannot_remove_built_in_conditions(): void {
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );

		$this->assertSame( array( 'woopayments_plugin_version_unsupported' ), $this->create_sut()->get_reconciliation_failures() );
	}

	/**
	 * @testdox Compatibility preflight still allows filters to remove non-protected conditions.
	 */
	public function test_compatibility_filter_can_remove_non_protected_conditions_while_reconciliation_keeps_them(): void {
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_TRANSPORT_READY );
		$this->provider_ready = false;
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES, static fn( array $failures ): array => array_values( array_diff( $failures, array( 'native_transport_unavailable' ) ) ) );
		$sut = $this->create_sut();

		$this->assertNotContains( 'native_transport_unavailable', $sut->get_preflight_failures() );
		$this->assertContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );
	}

	/**
	 * @testdox Valid filter additions are preserved without collapsed nested-error codes in reconciliation.
	 */
	public function test_valid_filter_addition_is_preserved_without_collapsed_nested_error_codes(): void {
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_true' );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES, static fn( array $failures ): array => array_merge( $failures, array( 'extension_deferred' ) ) );
		$sut = $this->create_sut();

		$this->assertContains( 'extension_deferred', $sut->get_preflight_failures() );
		$this->assertContains( 'extension_deferred', $sut->get_reconciliation_failures() );
		$this->assertContains( 'provider_events_filter_invalid', $sut->get_reconciliation_failures() );
		$this->assertNotContains( 'provider_events_undispositioned', $sut->get_reconciliation_failures() );
	}

	/**
	 * @testdox The induced reconciliation vocabulary is exactly the 17 planned conditions.
	 */
	public function test_induced_reconciliation_vocabulary_is_exactly_the_planned_seventeen_conditions(): void {
		$codes = array();
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_false' );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_TRANSPORT_READY );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_ADMIN_SURFACES_READY );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER );
		$this->provider_ready        = false;
		$this->platform_failures     = array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable' );
		$this->fee_remediation_ready = false;
		$this->navigation_ready      = false;
		$this->rate_client_connected = false;
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		update_option( WooPaymentsSettingsService::SETTINGS_OPTION, array( 'upe_enabled_payment_method_ids' => array( 'card', 'unknown_method' ) ) );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP' ) );
		$this->create_legacy_stripe_billing_subscription_marker();
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_preflight_service_test', array(), 'test', true );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, static fn(): array => array( 'event.type' ) );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_true' );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_true' );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES, '__return_true' );
		$codes = array_merge( $codes, $this->create_sut()->get_reconciliation_failures() );

		$unique_codes = array_values( array_unique( $codes ) );

		$this->assertSame( array( 'native_runtime_disabled', 'woopayments_plugin_version_unsupported', 'native_transport_unavailable', 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable', 'wpcom_connection_owner_user_token_unavailable', 'unsupported_payment_methods_enabled', 'multi_currency_rates_unavailable', 'native_admin_surfaces_unavailable', 'provider_events_undispositioned', 'operational_queue_hooks_undispositioned', 'financial_migrations_unavailable', 'legacy_stripe_billing_subscriptions_present', 'provider_events_filter_invalid', 'operational_queue_hooks_filter_invalid', 'preflight_filter_invalid' ), $unique_codes );
		$this->assertCount( 17, $unique_codes );
	}

	/**
	 * @testdox An invalid final preflight filter yields a direct reconciliation condition.
	 */
	public function test_invalid_final_preflight_filter_yields_direct_condition(): void {
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES, '__return_true' );

		$this->assertSame( array( 'preflight_filter_invalid' ), $this->create_sut()->get_reconciliation_failures() );
	}

	/**
	 * @testdox Compatibility and reconciliation projections evaluate the final filter once.
	 */
	public function test_compatibility_and_reconciliation_projections_share_one_final_filter_evaluation(): void {
		$filter_calls = 0;
		add_filter(
			WooPaymentsCutoverPreflightService::FILTER_PREFLIGHT_FAILURES,
			static function ( array $failures ) use ( &$filter_calls ): array {
				++$filter_calls;
				return array_merge( $failures, array( 'extension_deferred' ) );
			}
		);
		$sut = $this->create_sut();

		$this->assertSame( array( 'extension_deferred' ), $sut->get_preflight_failures() );
		$this->assertSame( array( 'extension_deferred' ), $sut->get_reconciliation_failures() );
		$this->assertSame( 1, $filter_calls );
	}

	/**
	 * @testdox Compatibility preflight retains generic nested filter blockers.
	 */
	public function test_compatibility_preflight_collapses_nested_filter_errors(): void {
		add_filter( WooPaymentsCutoverPreflightService::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_true' );

		$this->assertSame( array( 'provider_events_undispositioned' ), $this->create_sut()->get_preflight_failures() );
	}

	/**
	 * @testdox Invalidating the current blog memoization re-evaluates preflight facts.
	 */
	public function test_invalidate_current_blog_memoization_re_evaluates_facts(): void {
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_NATIVE_TRANSPORT_READY );
		$this->provider_ready = false;
		$sut                  = $this->create_sut();

		$this->assertContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );
		$this->provider_ready = true;
		$this->assertContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );

		$sut->invalidate_current_blog_memoization();
		$this->assertNotContains( 'native_transport_unavailable', $sut->get_reconciliation_failures() );
	}

	/**
	 * @testdox Removing unsupported methods preserves unrelated canonical settings.
	 */
	public function test_removing_unsupported_methods_preserves_unrelated_settings(): void {
		update_option(
			WooPaymentsSettingsService::SETTINGS_OPTION,
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'unknown_method' ),
				'platform_checkout'              => 'yes',
			)
		);
		$sut = $this->create_sut();

		$this->assertSame( array( 'unknown_method' ), $sut->get_unsupported_enabled_payment_method_ids() );
		$this->assertSame( array( 'unknown_method' ), $sut->remove_unsupported_enabled_payment_method_ids() );
		$this->assertSame(
			array(
				'upe_enabled_payment_method_ids' => array( 'card' ),
				'platform_checkout'              => 'yes',
			),
			get_option( WooPaymentsSettingsService::SETTINGS_OPTION )
		);
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
		remove_all_filters( WooPaymentsCutoverPreflightService::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER );
		$this->assertSame( array( 'operational_queue_hooks_undispositioned' ), $sut->get_preflight_failures() );
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
		$fee_remediation->method( 'ensure_scheduled' )->willReturn( 'scheduled' );
		$platform_connection = $this->getMockBuilder( WooPaymentsPlatformConnectionService::class )->disableOriginalConstructor()->onlyMethods( array( 'get_cutover_preflight_failures' ) )->getMock();
		$platform_connection->method( 'get_cutover_preflight_failures' )->willReturnCallback(
			function (): array {
				return $this->platform_failures;
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
		$sut->init( wc_get_container()->get( NativePaymentsRuntimeArbiter::class ), wc_get_container()->get( LegacyProxy::class ), $provider, new WooPaymentsLegacySubscriptionsGuard(), $fee_remediation, $platform_connection, $rate_account, $rate_client, $navigation );
		return $sut;
	}

	/**
	 * Create a legacy Stripe Billing marker on an order post.
	 */
	private function create_legacy_stripe_billing_subscription_marker(): void {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'shop_order',
				'post_status' => 'wc-pending',
				'post_title'  => 'Legacy Stripe Billing order',
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );
		update_post_meta( $post_id, '_wcpay_subscription_id', 'sub_legacy' );
	}
}
