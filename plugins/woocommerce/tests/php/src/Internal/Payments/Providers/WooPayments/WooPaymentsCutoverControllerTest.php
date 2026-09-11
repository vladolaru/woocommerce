<?php
/**
 * WooPaymentsCutoverController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsAdminNavigationController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPayments native cutover controller.
 */
class WooPaymentsCutoverControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCutoverController
	 */
	private WooPaymentsCutoverController $sut;

	/**
	 * Native WooPayments provider mock.
	 *
	 * @var WooPaymentsProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Canceled-authorization fee remediation service mock.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service;

	/**
	 * Platform connection readiness service mock.
	 *
	 * @var WooPaymentsPlatformConnectionService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsPlatformConnectionService $platform_connection_service;

	/**
	 * Native rate account boundary mock.
	 *
	 * @var WooPaymentsNativeAccountAdapter&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsNativeAccountAdapter $native_rate_account;

	/**
	 * Native rate API client boundary mock.
	 *
	 * @var WooPaymentsNativeApiClientAdapter&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsNativeApiClientAdapter $native_rate_api_client;

	/**
	 * Whether the native provider can process payments.
	 *
	 * @var bool
	 */
	private bool $native_provider_ready = false;

	/**
	 * Whether the native rate account is connected.
	 *
	 * @var bool
	 */
	private bool $native_rate_account_connected = true;

	/**
	 * Whether the native rate account is rejected.
	 *
	 * @var bool
	 */
	private bool $native_rate_account_rejected = false;

	/**
	 * Whether the native rate API client is connected.
	 *
	 * @var bool
	 */
	private bool $native_rate_api_client_connected = false;

	/**
	 * Whether canceled-authorization fee remediation can be scheduled during cutover.
	 *
	 * @var bool
	 */
	private bool $fee_remediation_schedulable = true;

	/**
	 * Number of times the native provider readiness was checked.
	 *
	 * @var int
	 */
	private int $native_provider_readiness_calls = 0;

	/**
	 * Number of times the fee remediation preflight was checked.
	 *
	 * @var int
	 */
	private int $fee_remediation_preflight_calls = 0;

	/**
	 * Number of times the platform connection preflight was checked.
	 *
	 * @var int
	 */
	private int $platform_connection_preflight_calls = 0;

	/**
	 * Whether canceled-authorization fee remediation scheduling succeeds after deactivation.
	 *
	 * @var string
	 */
	private string $fee_remediation_schedule_result = 'scheduled';

	/**
	 * Platform connection preflight failures.
	 *
	 * @var string[]
	 */
	private array $platform_connection_failures = array();

	/**
	 * Number of times the cutover asked the remediation service to adopt the queue.
	 *
	 * @var int
	 */
	private int $fee_remediation_schedule_calls = 0;

	/**
	 * Whether the WooPayments plugin should appear active.
	 *
	 * @var bool
	 */
	private bool $plugin_active = false;

	/**
	 * Whether the WooPayments plugin should appear network active.
	 *
	 * @var bool
	 */
	private bool $plugin_network_active = false;

	/**
	 * Whether the WooPayments plugin bootstrap class is loaded in this PHP request.
	 *
	 * @var bool
	 */
	private bool $plugin_class_loaded = false;

	/**
	 * Whether the current user can perform cutover actions.
	 *
	 * @var bool
	 */
	private bool $current_user_can_cutover = false;

	/**
	 * Deactivate plugin calls recorded by the legacy proxy mock.
	 *
	 * @var array<int,array{0:string,1:bool,2:bool}>
	 */
	private array $deactivate_plugin_calls = array();

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
	 * Whether this test class created the HPOS tables for a disabled-after-use fixture.
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
	 * Whether this test class captured the HPOS table-created option.
	 *
	 * @var bool
	 */
	private bool $captured_hpos_tables_created_option = false;

	/**
	 * Action Scheduler hooks created by tests.
	 *
	 * @var string[]
	 */
	private array $scheduled_action_hooks = array();

	/**
	 * Multisite blogs created by tests.
	 *
	 * @var int[]
	 */
	private array $multisite_blog_ids = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.0' );

		$this->provider = $this->getMockBuilder( WooPaymentsProvider::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_process_payments' ) )
			->getMock();
		$this->provider
			->method( 'can_process_payments' )
			->willReturnCallback(
				function (): bool {
					++$this->native_provider_readiness_calls;
					return $this->native_provider_ready;
				}
			);

		$this->fee_remediation_service = $this->getMockBuilder( WooPaymentsCanceledAuthorizationFeeRemediationService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'can_schedule_cutover_remediation', 'ensure_scheduled' ) )
			->getMock();
		$this->fee_remediation_service
			->method( 'can_schedule_cutover_remediation' )
			->willReturnCallback(
				function (): bool {
					++$this->fee_remediation_preflight_calls;
					return $this->fee_remediation_schedulable;
				}
			);
		$this->fee_remediation_service
			->method( 'ensure_scheduled' )
			->willReturnCallback(
				function (): string {
					++$this->fee_remediation_schedule_calls;
					return $this->fee_remediation_schedule_result;
				}
			);

		$this->platform_connection_service = $this->getMockBuilder( WooPaymentsPlatformConnectionService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cutover_preflight_failures' ) )
			->getMock();
		$this->platform_connection_service
			->method( 'get_cutover_preflight_failures' )
			->willReturnCallback(
				function (): array {
					++$this->platform_connection_preflight_calls;
					return $this->platform_connection_failures;
				}
			);

		$this->native_rate_account = $this->getMockBuilder( WooPaymentsNativeAccountAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_provider_connected', 'is_account_rejected' ) )
			->getMock();
		$this->native_rate_account
			->method( 'is_provider_connected' )
			->willReturnCallback(
				function ( bool $on_error = false ): bool {
					unset( $on_error );
					return $this->native_rate_account_connected;
				}
			);
		$this->native_rate_account
			->method( 'is_account_rejected' )
			->willReturnCallback(
				function (): bool {
					return $this->native_rate_account_rejected;
				}
			);

		$this->native_rate_api_client = $this->getMockBuilder( WooPaymentsNativeApiClientAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_server_connected' ) )
			->getMock();
		$this->native_rate_api_client
			->method( 'is_server_connected' )
			->willReturnCallback(
				function (): bool {
					return $this->native_rate_api_client_connected;
				}
			);

		$this->sut = $this->create_cutover_controller();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}
		$multisite_blog_ids       = $this->multisite_blog_ids;
		$this->multisite_blog_ids = array();
		unset( $_GET[ WooPaymentsCutoverController::QUERY_ACTION ], $_GET[ WooPaymentsCutoverController::NONCE_NAME ], $_GET[ WooPaymentsCutoverController::QUERY_STATUS ] );
		delete_transient( 'woocommerce_woopayments_native_cutover_status' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_woocommerce_payments_version' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( 'wcpay_multi_currency_exchange_rate_gbp' );

		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		remove_all_filters( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY );
		remove_all_filters( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY );
		remove_all_filters( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER );
		remove_all_filters( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER );
		remove_all_filters( WooPaymentsCutoverController::FILTER_SOFT_CUTOVER_ENABLED );
		remove_all_filters( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED );
		remove_all_filters( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES );
		remove_all_filters( 'wp_die_handler' );
		if ( $this->registered_subscription_order_type ) {
			global $wc_order_types;
			unset( $wc_order_types['shop_subscription'] );
			unregister_post_type( 'shop_subscription' );
			$this->registered_subscription_order_type = false;
		}
		$this->delete_raw_hpos_orders();
		$this->clean_up_disabled_hpos_fixture();
		foreach ( $this->scheduled_action_hooks as $hook_name ) {
			as_unschedule_all_actions( $hook_name );
		}
		$this->scheduled_action_hooks = array();
		Constants::clear_single_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS' );
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();

		if ( array() !== $multisite_blog_ids ) {
			foreach ( $multisite_blog_ids as $blog_id ) {
				if ( get_site( $blog_id ) ) {
					wpmu_delete_blog( $blog_id, true );
				}
			}
			wp_cache_flush();
		}
	}

	/** @testdox Lifecycle guards register only on WordPress's exact WooPayments activation and deactivation hooks. */
	public function test_registers_exact_plugin_lifecycle_hooks(): void {
		$this->sut->register();
		try {
			$this->assertSame( 10, has_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) ) );
			$this->assertSame( 10, has_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ) ) );
			$this->assertSame( 10, has_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ) ) );
			$this->assertFalse( has_action( 'activate_plugin', array( $this->sut, 'guard_woopayments_activation' ) ) );
		} finally {
			remove_action( 'admin_init', array( $this->sut, 'handle_admin_init' ) );
			remove_action( 'admin_notices', array( $this->sut, 'output_admin_notices' ) );
			remove_action( 'activate_' . NativePaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) );
			remove_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ), 10 );
			remove_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ), 10 );
		}
	}

	/**
	 * @testdox Soft cutover notice is shown when plugin runtime owns the site and preflight is ready.
	 */
	public function test_soft_notice_is_shown_when_preflight_is_ready(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();

		$this->assertTrue(
			$this->sut->should_show_soft_cutover_notice(),
			'The notice should show only when the merchant can safely disable the plugin.'
		);
	}

	/**
	 * @testdox Job-backed cutover notice shows the owner-approved action even while preflight is blocked.
	 */
	public function test_job_backed_notice_is_shown_while_preflight_is_blocked(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function get_state_record(): ?array {
				return null;
			}

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return null;
			}

			/** Offer the first generation. */
			public function should_offer_start(): bool {
				return true;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( null, $job ) );

		$this->assertStringContainsString( 'WooPayments is now part of WooCommerce. Start the switch: we will migrate what is needed and disable the WooPayments extension.', $notice );
		$this->assertStringContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'not ready', $notice );
	}

	/**
	 * @testdox Active job states show only the switch-in-progress notice.
	 * @testWith ["pending"]
	 *           ["running"]
	 *           ["deferred"]
	 *
	 * @param string $state Active cutover state.
	 */
	public function test_active_job_states_show_only_progress( string $state ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$job = new class( $state ) extends WooPaymentsCutoverReconciliationJob {
			/** @var string */
			private string $state;

			/**
			 * @param string $state Active cutover state.
			 */
			public function __construct( string $state ) {
				$this->state = $state;
			}

			/** @return array<string,mixed>|null */
			public function get_state_record(): ?array {
				return array(
					'state'                  => $this->state,
					'informational_outcomes' => array(),
				);
			}

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return $this->get_state_record();
			}

			/** Do not emit reconnect information. */
			public function consume_reconnect_notice(): bool {
				return false;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( null, $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		$this->assertStringNotContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'not ready', $notice );
	}

	/**
	 * @testdox An awaiting generation never bypasses the ordinary start-notice eligibility boundary.
	 */
	public function test_awaiting_generation_requires_start_notice_eligibility(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'        => WooPaymentsCutoverState::PENDING,
					'current_step' => 'awaiting_merchant_start',
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( null, $job ) );

		$this->assertStringNotContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'Switch in progress', $notice );
	}

	/**
	 * @testdox The reconnect exception is rendered only for the request that atomically consumes it.
	 */
	public function test_reconnect_notice_is_rendered_only_when_atomically_consumed(): void {
		$this->fake_plugin_active();
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var bool */
			private bool $available = true;

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'        => WooPaymentsCutoverState::DEFERRED,
					'current_step' => 'deferred',
				);
			}

			/** Consume once. */
			public function consume_reconnect_notice(): bool {
				$available       = $this->available;
				$this->available = false;
				return $available;
			}
		};
		$controller = $this->create_cutover_controller( null, $job );

		$first  = $this->render_admin_notices( $controller );
		$second = $this->render_admin_notices( $controller );

		$this->assertStringContainsString( 'The connection owner is no longer available. Reconnect this site to continue the switch.', $first );
		$this->assertStringNotContainsString( 'Reconnect this site', $second );
	}

	/**
	 * @testdox Manual-deactivation work is silent unless the atomic reconnect information is available.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $reconnect_available Whether Decision 7 reconnect information can be consumed.
	 */
	public function test_manual_deactivation_notice_only_renders_reconnect_information( bool $reconnect_available ): void {
		$job = new class( $reconnect_available ) extends WooPaymentsCutoverReconciliationJob {
			/** @var bool */
			private bool $reconnect_available;

			/**
			 * @param bool $reconnect_available Whether reconnect information is available.
			 */
			public function __construct( bool $reconnect_available ) {
				$this->reconnect_available = $reconnect_available;
			}

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'                  => WooPaymentsCutoverState::DEFERRED,
					'current_step'           => 'deferred',
					'origin_plugin_file'     => 'renamed-wcpay/woocommerce-payments.php',
					'origin_plugin_scope'    => 'site',
					'informational_outcomes' => $this->reconnect_available ? array( array( 'code' => 'reconnect_required' ) ) : array(),
				);
			}

			/** Consume controlled reconnect information once. */
			public function consume_reconnect_notice(): bool {
				$available                 = $this->reconnect_available;
				$this->reconnect_available = false;
				return $available;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( null, $job ) );

		$this->assertStringNotContainsString( 'Switch in progress', $notice );
		$this->assertStringNotContainsString( 'Start the switch', $notice );
		if ( $reconnect_available ) {
			$this->assertStringContainsString( 'The connection owner is no longer available. Reconnect this site to continue the switch.', $notice );
		} else {
			$this->assertStringNotContainsString( 'The connection owner is no longer available. Reconnect this site to continue the switch.', $notice );
		}
	}

	/**
	 * @testdox The controller sends merchant, activation, and manual-deactivation triggers into the one reconciliation job with exact scope.
	 */
	public function test_controller_routes_merchant_and_manual_triggers_into_the_job(): void {
		$renamed_plugin_file = 'renamed-woocommerce-payments/woocommerce-payments.php';
		$this->fake_plugin_active( true, false, $renamed_plugin_file );
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] */
			public array $sources = array();

			/** @var array<int,array{string,bool}> */
			public array $manual = array();

			/** @var bool[] */
			public array $activation_scopes = array();

			/**
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}

			/**
			 * @param string $plugin_file  Exact plugin path.
			 * @param bool   $network_wide Network scope.
			 */
			public function enqueue_manual_deactivation( string $plugin_file, bool $network_wide ): bool {
				$this->manual[] = array( $plugin_file, $network_wide );
				return true;
			}

			/**
			 * Record the external activation scope.
			 *
			 * @param bool $network_wide Network scope.
			 */
			public function record_plugin_activation( bool $network_wide = false ): bool {
				$this->activation_scopes[] = $network_wide;
				return true;
			}

			/** Return whether lifecycle work is job-owned. */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}

			/** Do not emit reconnect information. */
			public function consume_reconnect_notice(): bool {
				return false;
			}
		};
		$controller = $this->create_cutover_controller( null, $job );

		$this->assertTrue( $controller->disable_woopayments_plugin() );
		$controller->handle_plugin_deactivated( $renamed_plugin_file, false );
		$controller->handle_plugin_activated( $renamed_plugin_file, true );

		$this->assertSame( array( 'merchant' ), $job->sources );
		$this->assertSame( array( array( $renamed_plugin_file, false ) ), $job->manual );
		$this->assertSame( array( true ), $job->activation_scopes );
		$this->assertSame( array(), $this->deactivate_plugin_calls );
	}

	/**
	 * @testdox Mandatory activation guard blocks WooPayments reactivation when mandatory cutover is enabled.
	 */
	public function test_mandatory_activation_guard_blocks_reactivation_when_enabled(): void {
		$this->fake_wp_die_handler();
		$this->enable_ready_cutover();
		add_filter( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		$controller = $this->create_cutover_controller( null, $this->create_completed_cutover_job() );

		$this->expectException( WooPaymentsCutoverBlockedException::class );

		$controller->guard_woopayments_activation();
	}

	/**
	 * @testdox Mandatory activation guard stays open until a cutover generation is durably complete.
	 */
	public function test_mandatory_activation_guard_stays_open_before_done(): void {
		$this->fake_wp_die_handler();
		add_filter( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** Return active durable work. */
			public function get_state_record(): ?array {
				return array( 'state' => WooPaymentsCutoverState::DEFERRED );
			}

			/** Return an external lifecycle event. */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}
		};
		$controller = $this->create_cutover_controller( null, $job );

		$controller->guard_woopayments_activation();

		$this->assertTrue( true, 'WooPayments reactivation remains a merchant option until cutover completes.' );
	}

	/**
	 * @testdox Mandatory activation guard remains default-off when native preflight is ready.
	 */
	public function test_mandatory_activation_guard_remains_default_off_when_preflight_is_ready(): void {
		$this->fake_wp_die_handler();
		$this->enable_ready_cutover();

		$this->sut->guard_woopayments_activation();

		$this->assertTrue( true, 'Mandatory cutover must still require an explicit rollout filter.' );
	}

	/**
	 * @testdox Mandatory cutover rollout default is fail-closed.
	 */
	public function test_mandatory_cutover_rollout_default_is_fail_closed(): void {
		$this->fake_wp_die_handler();
		$this->enable_ready_cutover();

		$this->assertFalse( WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED );
		$this->sut->guard_woopayments_activation();
		$this->assertTrue( true, 'Mandatory cutover remains fail-closed until the release rollout default is explicitly flipped.' );
	}

	/**
	 * @testdox Mandatory cutover rollout filter receives the fail-closed default and can enable mandatory cutover.
	 */
	public function test_mandatory_cutover_rollout_filter_receives_default_and_can_enable(): void {
		$this->fake_wp_die_handler();
		$this->enable_ready_cutover();
		$observed_default = null;
		add_filter(
			WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED,
			static function ( bool $enabled ) use ( &$observed_default ): bool {
				$observed_default = $enabled;
				return true;
			}
		);
		$controller = $this->create_cutover_controller( null, $this->create_completed_cutover_job() );

		$this->expectException( WooPaymentsCutoverBlockedException::class );
		try {
			$controller->guard_woopayments_activation();
		} finally {
			$this->assertSame( WooPaymentsCutoverController::DEFAULT_MANDATORY_CUTOVER_ENABLED, $observed_default, 'The mandatory cutover filter should receive the explicit default value.' );
		}
	}

	/**
	 * @testdox A valid merchant action queues idempotent durable work and redirects to the bare Plugins screen.
	 */
	public function test_valid_cutover_action_enqueues_idempotently_and_redirects_to_plugins(): void {
		$arbiter      = new class() extends NativePaymentsRuntimeArbiter {
			/** Return enabled native runtime. */
			public function is_native_runtime_enabled(): bool {
				return true;
			}

			/** Return active plugin ownership. */
			public function is_plugin_runtime_active(): bool {
				return true;
			}
		};
		$preflight    = new class() extends WooPaymentsCutoverPreflightService {
			/** Return site activation scope. */
			public function is_woopayments_network_active(): bool {
				return false;
			}
		};
		$job          = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int Number of controller enqueue requests. */
			public int $enqueue_calls = 0;

			/** @var int Number of durable generations opened. */
			public int $generation_count = 0;

			/**
			 * Enqueue idempotently.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				unset( $source );
				++$this->enqueue_calls;
				if ( 1 === $this->enqueue_calls ) {
					++$this->generation_count;
				}
				return true;
			}
		};
		$legacy_proxy = $this->getMockBuilder( LegacyProxy::class )
			->onlyMethods( array( 'call_function', 'exit' ) )
			->getMock();
		$legacy_proxy->method( 'call_function' )->willReturn( true );
		$legacy_proxy->expects( $this->exactly( 2 ) )->method( 'exit' );
		$controller = new WooPaymentsCutoverController();
		$controller->init( $arbiter, $legacy_proxy, $preflight, $job );
		$redirects        = array();
		$capture_redirect = static function ( string $location ) use ( &$redirects ): string {
			$redirects[] = $location;
			return '';
		};
		add_filter( 'wp_redirect', $capture_redirect );
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_DISABLE;
		$_GET[ WooPaymentsCutoverController::NONCE_NAME ]   = wp_create_nonce( WooPaymentsCutoverController::NONCE_ACTION );

		try {
			$controller->handle_admin_init();
			$controller->handle_admin_init();
		} finally {
			remove_filter( 'wp_redirect', $capture_redirect );
		}

		$this->assertSame( 2, $job->enqueue_calls );
		$this->assertSame( 1, $job->generation_count );
		$this->assertSame( array( admin_url( 'plugins.php' ), admin_url( 'plugins.php' ) ), $redirects );
	}

	/**
	 * @testdox An invalid merchant action nonce dies before any cutover work is queued.
	 */
	public function test_invalid_cutover_action_nonce_dies_before_enqueue(): void {
		$this->fake_wp_die_handler();
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int Number of enqueue calls. */
			public int $enqueue_calls = 0;

			/**
			 * Record an unexpected enqueue call.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				unset( $source );
				++$this->enqueue_calls;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( null, $job );
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_DISABLE;
		$_GET[ WooPaymentsCutoverController::NONCE_NAME ]   = 'invalid';

		$this->expectException( WooPaymentsCutoverBlockedException::class );
		try {
			$controller->handle_admin_init();
		} finally {
			$this->assertSame( 0, $job->enqueue_calls );
		}
	}

	/**
	 * @testdox Mandatory auto-deactivation allows merged feature plugins when developer bypass is enabled.
	 */
	public function test_mandatory_auto_deactivation_allows_merged_feature_plugins_when_bypass_enabled(): void {
		$this->fake_plugin_active();
		$this->enable_ready_cutover();
		add_filter( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var int Number of enqueue calls. */
			public int $enqueue_calls = 0;

			/**
			 * Record an unexpected mandatory enqueue.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				unset( $source );
				++$this->enqueue_calls;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( null, $job );

		$controller->handle_admin_init();

		$this->assertSame( 0, $job->enqueue_calls, 'Developer bypass should not start mandatory reconciliation.' );
		$this->assertTrue( $this->plugin_active, 'Developer bypass should leave WooPayments active.' );
	}

	/**
	 * @testdox Network preflight reports failing site IDs in its compatibility support surface.
	 * @group multisite
	 */
	public function test_network_preflight_reports_failing_site_ids(): void {
		$site_ids        = $this->create_multisite_preflight_sites();
		$failing_site_id = (int) end( $site_ids );
		$this->create_multisite_legacy_subscription_marker( $failing_site_id );

		$this->fake_plugin_active( false, true );
		$this->enable_ready_cutover();

		$this->assertSame( array( $failing_site_id ), $this->sut->get_network_preflight_failing_site_ids() );
	}

	/**
	 * @testdox Cutover preflight short-circuits expensive checks while native runtime is disabled.
	 */
	public function test_preflight_short_circuits_when_native_runtime_is_disabled(): void {
		$provider_event_filter_calls    = 0;
		$operational_queue_filter_calls = 0;
		$preflight_filter_calls         = 0;
		add_filter(
			WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER,
			static function () use ( &$provider_event_filter_calls ): array {
				++$provider_event_filter_calls;
				return array( 'example.event' );
			}
		);
		add_filter(
			WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER,
			static function () use ( &$operational_queue_filter_calls ): array {
				++$operational_queue_filter_calls;
				return array( 'example_hook' );
			}
		);
		add_filter(
			WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES,
			static function () use ( &$preflight_filter_calls ): array {
				++$preflight_filter_calls;
				return array();
			}
		);

		$failures = $this->sut->get_preflight_failures();

		$this->assertSame( array( 'native_runtime_disabled' ), $failures, 'Disabled native runtime should be the only preflight result.' );
		$this->assertSame( 0, $this->native_provider_readiness_calls, 'Native transport readiness should not be checked while native runtime is disabled.' );
		$this->assertSame( 0, $this->platform_connection_preflight_calls, 'Platform connection preflight should not run while native runtime is disabled.' );
		$this->assertSame( 0, $provider_event_filter_calls, 'Provider event disposition scans should not run while native runtime is disabled.' );
		$this->assertSame( 0, $operational_queue_filter_calls, 'Operational queue disposition scans should not run while native runtime is disabled.' );
		$this->assertSame( 0, $this->fee_remediation_preflight_calls, 'Financial migration preflight should not run while native runtime is disabled.' );
		$this->assertSame( 0, $preflight_filter_calls, 'The preflight failure filter should not run when native runtime is disabled.' );
	}

	/**
	 * @testdox Cutover preflight memoizes expensive checks for the current request.
	 */
	public function test_preflight_memoizes_expensive_checks_within_request(): void {
		$provider_event_filter_calls    = 0;
		$operational_queue_filter_calls = 0;
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter(
			WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER,
			static function () use ( &$provider_event_filter_calls ): array {
				++$provider_event_filter_calls;
				return array();
			}
		);
		add_filter(
			WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER,
			static function () use ( &$operational_queue_filter_calls ): array {
				++$operational_queue_filter_calls;
				return array();
			}
		);
		$this->native_provider_ready = true;

		$first_failures  = $this->sut->get_preflight_failures();
		$second_failures = $this->sut->get_preflight_failures();

		$this->assertSame( $first_failures, $second_failures, 'Repeated preflight checks in one request should reuse the first result.' );
		$this->assertSame( 1, $this->native_provider_readiness_calls, 'Native transport readiness should be checked once per request.' );
		$this->assertSame( 1, $this->platform_connection_preflight_calls, 'Platform connection preflight should be checked once per request.' );
		$this->assertSame( 1, $provider_event_filter_calls, 'Provider event disposition scans should run once per request.' );
		$this->assertSame( 1, $operational_queue_filter_calls, 'Operational queue disposition scans should run once per request.' );
		$this->assertSame( 1, $this->fee_remediation_preflight_calls, 'Financial migration preflight should run once per request.' );
	}

	/**
	 * @testdox Transport readiness filter can still block cutover when the native provider is ready.
	 */
	public function test_transport_filter_can_block_provider_backed_preflight(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_false' );

		$this->assertContains( 'native_transport_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks while platform connection owner user-token readiness is unavailable.
	 */
	public function test_preflight_blocks_when_platform_connection_user_token_is_unavailable(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->platform_connection_failures = array( 'wpcom_connection_owner_user_token_unavailable' );

		$this->assertContains( 'wpcom_connection_owner_user_token_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight filters cannot remove platform connection blockers.
	 */
	public function test_preflight_filter_cannot_remove_platform_connection_blocker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->platform_connection_failures = array( 'wpcom_blog_id_unavailable' );
		add_filter( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );

		$this->assertContains( 'wpcom_blog_id_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks while unsupported payment methods are enabled in legacy settings.
	 */
	public function test_preflight_blocks_when_unsupported_payment_methods_are_enabled(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'future_lpm' ),
			)
		);

		$this->assertContains( 'unsupported_payment_methods_enabled', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight allows the currently natively chargeable payment methods.
	 */
	public function test_preflight_allows_currently_natively_chargeable_payment_methods(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array(
					'card',
					'link',
					'sepa_debit',
					'ideal',
					'bancontact',
					'klarna',
					'affirm',
					'afterpay_clearpay',
					'eps',
					'p24',
					'multibanco',
					'au_becs_debit',
					'grabpay',
					'wechat_pay',
					'alipay',
				),
			)
		);

		$this->assertNotContains( 'unsupported_payment_methods_enabled', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight filters cannot remove unsupported payment method blockers.
	 */
	public function test_preflight_filter_cannot_remove_unsupported_payment_method_blocker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'upe_enabled_payment_method_ids' => array( 'card', 'future_lpm' ),
			)
		);
		add_filter( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );

		$this->assertContains( 'unsupported_payment_methods_enabled', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks when automatic multi-currency rates have no provider.
	 */
	public function test_preflight_blocks_when_multi_currency_automatic_rates_have_no_provider(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'automatic' );

		$this->assertContains( 'multi_currency_rates_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight allows manual multi-currency rates without a provider.
	 */
	public function test_preflight_allows_manual_multi_currency_rates_without_provider(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'manual' );

		$this->assertNotContains( 'multi_currency_rates_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight allows automatic multi-currency rates when a provider is available.
	 */
	public function test_preflight_allows_multi_currency_automatic_rates_with_available_provider(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'automatic' );
		$this->enable_available_native_rate_transport();

		$this->assertNotContains( 'multi_currency_rates_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight filters cannot remove multi-currency rate-provider blockers.
	 */
	public function test_preflight_filter_cannot_remove_multi_currency_rate_provider_blocker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'automatic' );
		add_filter( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );

		$this->assertContains( 'multi_currency_rates_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks while native admin surfaces are unavailable.
	 */
	public function test_preflight_blocks_when_native_admin_surfaces_are_unavailable(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_false' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;

		$this->assertContains( 'native_admin_surfaces_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks when an allowed admin route is not registered.
	 */
	public function test_preflight_blocks_when_admin_route_registry_cannot_resolve_allowed_route(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;
		$sut                         = $this->create_cutover_controller( $this->create_admin_navigation_controller( false ) );

		$this->assertContains( 'native_admin_surfaces_unavailable', $sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight defaults admin surfaces to ready after the N12 parity gate passes.
	 */
	public function test_preflight_defaults_admin_surfaces_ready_after_n12_parity_gate_passes(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;

		$failures = $this->sut->get_preflight_failures();

		$this->assertNotContains( 'native_admin_surfaces_unavailable', $failures );
	}

	/**
	 * @testdox Cutover preflight blocks when the last active WooPayments version is unsupported.
	 * @dataProvider provide_unsupported_woopayments_versions
	 *
	 * @param string $version Recorded WooPayments version.
	 */
	public function test_preflight_blocks_unsupported_woopayments_versions( string $version ): void {
		$this->fake_plugin_active();
		$this->enable_ready_cutover();
		update_option( 'woocommerce_woocommerce_payments_version', $version );
		add_filter( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );

		$this->assertContains( 'woopayments_plugin_version_unsupported', $this->sut->get_preflight_failures() );
	}

	/**
	 * Provide unsupported recorded WooPayments versions.
	 *
	 * @return array<string,array{string}>
	 */
	public function provide_unsupported_woopayments_versions(): array {
		return array(
			'missing version'   => array( '' ),
			'malformed version' => array( 'not-a-version' ),
			'older version'     => array( '10.4.9' ),
		);
	}

	/**
	 * @testdox Cutover preflight blocks while provider event types remain undispositioned.
	 */
	public function test_preflight_blocks_when_provider_events_are_undispositioned(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, static fn() => array( 'example.event' ) );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;

		$this->assertContains( 'provider_events_undispositioned', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks while operational queue hooks remain undispositioned.
	 */
	public function test_preflight_blocks_when_operational_queue_hooks_are_undispositioned(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, static fn() => array( 'example_hook' ) );
		$this->native_provider_ready = true;

		$this->assertContains( 'operational_queue_hooks_undispositioned', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight discovers pending WooPayments actions without a static hook inventory.
	 */
	public function test_preflight_blocks_when_unknown_woopayments_action_is_pending(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;

		$hook_name                      = 'wcpay_synthetic_cutover_probe';
		$this->scheduled_action_hooks[] = $hook_name;
		$action_id                      = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook_name, array(), 'woocommerce-test-cutover', true );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertContains( 'operational_queue_hooks_undispositioned', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight allows queued WooPayments actions that have native consumers.
	 *
	 * @dataProvider native_owned_operational_action_provider
	 *
	 * @param string $hook_name Native-owned Action Scheduler hook.
	 */
	public function test_preflight_allows_pending_native_owned_operational_action( string $hook_name ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;

		$this->scheduled_action_hooks[] = $hook_name;
		$action_id                      = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook_name, array(), 'woocommerce-test-cutover', true );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertNotContains( 'operational_queue_hooks_undispositioned', $this->sut->get_preflight_failures() );
	}

	/**
	 * Native-owned Action Scheduler hooks.
	 *
	 * @return array<string,array{string}>
	 */
	public function native_owned_operational_action_provider(): array {
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
		);
	}

	/**
	 * @testdox Cutover preflight blocks while required financial migrations cannot be scheduled.
	 */
	public function test_preflight_blocks_when_financial_migrations_cannot_be_scheduled(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->fee_remediation_schedulable = false;

		$this->assertContains( 'financial_migrations_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight no longer reports event or queue blockers after A5a disposition.
	 */
	public function test_preflight_closes_event_and_queue_blockers_by_default(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		$this->native_provider_ready = true;

		$failures = $this->sut->get_preflight_failures();

		$this->assertNotContains( 'native_admin_surfaces_unavailable', $failures );
		$this->assertNotContains( 'provider_events_undispositioned', $failures );
		$this->assertNotContains( 'operational_queue_hooks_undispositioned', $failures );
	}

	/**
	 * @testdox Cutover preflight blocks while legacy Stripe Billing subscription markers exist.
	 */
	public function test_preflight_blocks_when_legacy_stripe_billing_subscription_marker_exists(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'pending' );

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks cancelled legacy Stripe Billing subscription markers.
	 */
	public function test_preflight_blocks_cancelled_legacy_stripe_billing_subscription_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'cancelled' );

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks migrated legacy Stripe Billing subscription marker variants.
	 */
	public function test_preflight_blocks_migrated_legacy_stripe_billing_subscription_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'cancelled', '_migrated_wcpay_subscription_id' );

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight filters cannot remove the legacy Stripe Billing marker blocker.
	 */
	public function test_preflight_filter_cannot_remove_legacy_stripe_billing_marker_blocker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'active' );
		add_filter( WooPaymentsCutoverController::FILTER_PREFLIGHT_FAILURES, '__return_empty_array' );

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks legacy Stripe Billing invoice order markers.
	 */
	public function test_preflight_blocks_legacy_stripe_billing_invoice_order_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$order = wc_create_order();
		$order->update_meta_data( '_migrated_wcpay_billing_invoice_id', 'in_migrated_123' );
		$order->save();

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks legacy Stripe Billing markers in HPOS order meta.
	 */
	public function test_preflight_blocks_legacy_stripe_billing_hpos_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_hpos_marker( '_wcpay_pending_invoice_id' );

		$this->assertContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Cutover preflight allows clean stores without legacy Stripe Billing markers.
	 */
	public function test_preflight_allows_clean_store_without_legacy_stripe_billing_markers(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();

		$this->assertNotContains( 'legacy_stripe_billing_subscriptions_present', $this->sut->get_preflight_failures() );
	}

	/**
	 * @testdox Transport readiness filter can still force cutover readiness for controlled rollouts.
	 */
	public function test_transport_filter_can_force_preflight_when_provider_is_not_ready(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_TRANSPORT_READY, '__return_true' );

		$this->assertNotContains( 'native_transport_unavailable', $this->sut->get_preflight_failures() );
	}

	/**
	 * Create a cutover controller wired to this test's dependencies.
	 *
	 * @param WooPaymentsAdminNavigationController|null $admin_navigation_controller Optional admin navigation owner.
	 * @param WooPaymentsCutoverReconciliationJob|null  $job                         Optional reconciliation job.
	 * @return WooPaymentsCutoverController
	 */
	private function create_cutover_controller( ?WooPaymentsAdminNavigationController $admin_navigation_controller = null, ?WooPaymentsCutoverReconciliationJob $job = null ): WooPaymentsCutoverController {
		$arbiter           = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		$legacy_proxy      = wc_get_container()->get( LegacyProxy::class );
		$preflight_service = new WooPaymentsCutoverPreflightService();
		$preflight_service->init(
			$arbiter,
			$legacy_proxy,
			$this->provider,
			new WooPaymentsLegacySubscriptionsGuard(),
			$this->fee_remediation_service,
			$this->platform_connection_service,
			$this->native_rate_account,
			$this->native_rate_api_client,
			$admin_navigation_controller ?? $this->create_admin_navigation_controller( true )
		);
		$job        = $job ?? new class() extends WooPaymentsCutoverReconciliationJob {
			/** Return no durable state. */
			public function get_state_record(): ?array {
				return null;
			}

			/** Return no durable state after notice classification. */
			public function classify_for_admin_notice(): ?array {
				return null;
			}

			/** Offer a new generation. */
			public function should_offer_start(): bool {
				return true;
			}

			/**
			 * Accept controller routing without executing reconciliation inline.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				unset( $source );
				return true;
			}

			/** Return an external lifecycle event. */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}
		};
		$controller = new WooPaymentsCutoverController();
		$controller->init( $arbiter, $legacy_proxy, $preflight_service, $job );

		return $controller;
	}

	/** Create a durable completed-state double for activation-guard tests. */
	private function create_completed_cutover_job(): WooPaymentsCutoverReconciliationJob {
		return new class() extends WooPaymentsCutoverReconciliationJob {
			/** Return one completed generation. */
			public function get_state_record(): ?array {
				return array( 'state' => WooPaymentsCutoverState::DONE );
			}

			/** Return an external lifecycle event. */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}
		};
	}

	/**
	 * Create an admin navigation readiness double.
	 *
	 * @param bool $routes_registered Whether every available route is registered.
	 * @return WooPaymentsAdminNavigationController
	 */
	private function create_admin_navigation_controller( bool $routes_registered ): WooPaymentsAdminNavigationController {
		return new class( $routes_registered ) extends WooPaymentsAdminNavigationController {
			/**
			 * Whether every available admin route is registered.
			 *
			 * @var bool
			 */
			private bool $routes_registered;

			/**
			 * Initialize the double.
			 *
			 * @param bool $routes_registered Whether every available route is registered.
			 */
			public function __construct( bool $routes_registered ) {
				$this->routes_registered = $routes_registered;
			}

			/**
			 * Tell whether every available admin route is registered.
			 *
			 * @return bool
			 */
			public function are_all_available_routes_registered(): bool {
				return $this->routes_registered;
			}
		};
	}

	/**
	 * Render admin notices for a cutover controller.
	 *
	 * @param WooPaymentsCutoverController $controller Cutover controller.
	 * @return string Rendered notice markup.
	 */
	private function render_admin_notices( WooPaymentsCutoverController $controller ): string {
		ob_start();
		$controller->output_admin_notices();
		return (string) ob_get_clean();
	}

	/**
	 * Make the native cutover preflight ready.
	 */
	private function enable_ready_cutover(): void {
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY, '__return_true' );
		add_filter( WooPaymentsCutoverController::FILTER_PROVIDER_EVENT_TYPES_PENDING_CUTOVER, '__return_empty_array' );
		add_filter( WooPaymentsCutoverController::FILTER_OPERATIONAL_QUEUE_HOOKS_PENDING_CUTOVER, '__return_empty_array' );
		$this->native_provider_ready = true;
	}

	/**
	 * Create two sites with the minimum supported cutover version.
	 *
	 * @return int[] Current-network site IDs in preflight order.
	 */
	private function create_multisite_preflight_sites(): array {
		$this->skipWithoutMultisite();

		for ( $index = 0; $index < 2; ++$index ) {
			$blog_id                    = self::factory()->blog->create();
			$this->multisite_blog_ids[] = $blog_id;
			switch_to_blog( $blog_id );
			\WC_Install::create_tables();
			( new \ActionScheduler_StoreSchema() )->register_tables( true );
			update_option( 'woocommerce_woocommerce_payments_version', '10.5.0' );
			restore_current_blog();
		}

		return array_map(
			'intval',
			get_sites(
				array(
					'fields'     => 'ids',
					'network_id' => get_current_network_id(),
					'number'     => 0,
					'orderby'    => 'id',
					'order'      => 'ASC',
				)
			)
		);
	}

	/**
	 * Add a legacy Stripe Billing subscription marker to one multisite blog.
	 *
	 * @param int $blog_id Blog ID.
	 */
	private function create_multisite_legacy_subscription_marker( int $blog_id ): void {
		switch_to_blog( $blog_id );
		$this->create_legacy_stripe_billing_subscription( 'active' );
		restore_current_blog();
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
	 * Make the native rate transport available.
	 */
	private function enable_available_native_rate_transport(): void {
		$this->native_rate_account_connected    = true;
		$this->native_rate_account_rejected     = false;
		$this->native_rate_api_client_connected = true;
	}

	/**
	 * Control the WooPayments plugin active signals.
	 *
	 * @param bool        $site_active    Whether the plugin is active for this site.
	 * @param bool        $network_active Whether the plugin is active network-wide.
	 * @param string|null $plugin_file    Plugin file path for nonstandard installs.
	 */
	private function fake_plugin_active( bool $site_active = true, bool $network_active = false, ?string $plugin_file = null ): void {
		$this->plugin_active         = $site_active;
		$this->plugin_network_active = $network_active;
		$this->plugin_class_loaded   = $site_active || $network_active;
		$entry                       = $plugin_file ?? NativePaymentsRuntimeArbiter::PLUGIN_FILE;

		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'         => function ( $name, $default_value = false ) use ( $entry ) {
					if ( 'active_plugins' === $name ) {
						return $this->plugin_active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option'    => function ( $name, $default_value = false ) use ( $entry ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return $this->plugin_network_active ? array( $entry => 1234567890 ) : array();
					}
					return get_site_option( $name, $default_value );
				},
				'get_plugins'        => function () use ( $entry ) {
					return array(
						$entry => array(
							'Name' => 'WooPayments',
						),
					);
				},
				'class_exists'       => function ( $class_name, $autoload = true ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $this->plugin_class_loaded;
					}
					return class_exists( $class_name, $autoload );
				},
				'defined'            => function ( $constant_name ) {
					if ( 'WCPAY_PLUGIN_FILE' === $constant_name ) {
						return $this->plugin_class_loaded;
					}
					return defined( $constant_name );
				},
				'deactivate_plugins' => function ( $plugin, $silent = false, $network_wide = null ) use ( $entry ) {
					$network_wide                    = (bool) $network_wide;
					$this->deactivate_plugin_calls[] = array( (string) $plugin, (bool) $silent, $network_wide );

					if ( $entry !== (string) $plugin ) {
						return;
					}

					if ( $network_wide ) {
						$this->plugin_network_active = false;
					} else {
						$this->plugin_active = false;
					}
				},
				'current_user_can'   => fn( $capability ) => in_array( $capability, array( 'manage_woocommerce', 'activate_plugins', 'manage_network_plugins' ), true )
					? $this->current_user_can_cutover
					: current_user_can( $capability ),
			)
		);
	}

	/**
	 * Simulate the next request after the WooPayments plugin was deactivated.
	 */
	private function fake_woopayments_class_unloaded(): void {
		$this->plugin_class_loaded = false;
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Create a legacy WCPay/Stripe Billing subscription fixture.
	 *
	 * @param string $status Subscription status without the wc- prefix.
	 * @param string $meta_key Legacy marker meta key.
	 * @return int Subscription post ID.
	 */
	private function create_legacy_stripe_billing_subscription( string $status, string $meta_key = '_wcpay_subscription_id' ): int {
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
	private function create_legacy_stripe_billing_hpos_marker( string $meta_key ): int {
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
	 * Register a lightweight subscription order type for cutover guard tests.
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
	 * Control current-user cutover capabilities.
	 *
	 * @param bool $can_cutover Whether the user can perform cutover actions.
	 */
	private function fake_current_user_caps( bool $can_cutover ): void {
		$this->current_user_can_cutover = $can_cutover;
	}

	/**
	 * Replace wp_die with a test exception.
	 */
	private function fake_wp_die_handler(): void {
		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message = '' ): void {
					throw new WooPaymentsCutoverBlockedException( esc_html( wp_strip_all_tags( (string) $message ) ) );
				};
			}
		);
	}
}
