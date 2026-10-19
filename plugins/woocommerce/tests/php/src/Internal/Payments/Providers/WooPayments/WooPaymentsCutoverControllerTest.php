<?php
/**
 * WooPaymentsCutoverController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WC_Payment_Token_CC;

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
	 * The preflight service the controller under test reads.
	 *
	 * @var WooPaymentsCutoverPreflightService
	 */
	private WooPaymentsCutoverPreflightService $preflight_service;

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
	private array $granted_cutover_capabilities = array();

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
	 * Arguments passed to the most recent wp_die call.
	 *
	 * @var array<string,mixed>
	 */
	private array $wp_die_arguments = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		// The owner is resolved once per request; a test that fakes the extension must not see one cached before it.
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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

		$this->preflight_service = $this->create_preflight_service();
		$this->sut               = $this->create_cutover_controller( null, $this->preflight_service );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		while ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) {
			restore_current_blog();
		}
		unset( $_GET[ WooPaymentsCutoverController::QUERY_ACTION ], $_GET[ WooPaymentsCutoverController::NONCE_NAME ], $_GET[ WooPaymentsCutoverController::QUERY_NOTICE ] );
		delete_transient( 'woocommerce_woopayments_native_cutover_status' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_woocommerce_payments_version' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( 'wcpay_multi_currency_exchange_rate_gbp' );

		remove_all_filters( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED );
		remove_all_filters( 'wp_die_handler' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
	}

	/** @testdox The controller registers the admin notice and click hooks; the plugin lifecycle hooks belong to the listener that loads on every request class. */
	public function test_registers_admin_hooks_only(): void {
		$this->sut->register();
		try {
			$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'handle_admin_init' ) ) );
			$this->assertSame( 10, has_action( 'admin_notices', array( $this->sut, 'output_admin_notices' ) ) );
			$this->assertFalse( has_action( 'activate_' . WooPaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) ) );
			$this->assertFalse( has_action( 'activated_plugin', array( $this->sut, 'handle_plugin_activated' ) ) );
			$this->assertFalse( has_action( 'deactivated_plugin', array( $this->sut, 'handle_plugin_deactivated' ) ) );
		} finally {
			remove_action( 'admin_init', array( $this->sut, 'handle_admin_init' ) );
			remove_action( 'admin_notices', array( $this->sut, 'output_admin_notices' ) );
			remove_action( 'activate_' . WooPaymentsRuntimeArbiter::PLUGIN_FILE, array( $this->sut, 'guard_woopayments_activation' ) );
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

		$this->assertStringContainsString(
			'Start the switch',
			$this->render_admin_notices( $this->sut ),
			'The notice should show only when the merchant can safely disable the plugin.'
		);
	}

	/**
	 * The built-in eligibility field `native_payments` (`eligible`, `cohort`, `reason`) exists only on an unmerged WPCOM branch,
	 * `748c7406e24`, never on trunk: `wp-content/rest-api-plugins/endpoints/wcpay/class-accounts-controller.php:1100-1104`
	 * builds it, `:1120-1121` sets the result and `:1206` adds it to the account response. WooCommerce no longer reads it.
	 *
	 * @testdox The start notice shows and the Start the switch click queues the switch whatever the cached account says about built-in eligibility.
	 */
	public function test_notice_is_offered_whatever_the_account_payload_says(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		// Clearing the in-memory cache deletes the option, so it goes first.
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		// The account cache envelope: client 11.1.0 includes/class-database-cache.php:377-382.
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id'      => 'acct_marked_ineligible',
					'is_live'         => true,
					'native_payments' => array( 'eligible' => false ),
				),
				'fetched' => time(),
				'errored' => false,
			),
			false
		);

		$controller = $this->create_cutover_controller();

		$this->assertStringContainsString( 'Start the switch', $this->render_admin_notices( $controller ) );
		$this->assertTrue( $controller->disable_woopayments_plugin() );
	}

	/**
	 * @testdox Job-backed cutover notice shows the owner-approved action even while preflight is blocked.
	 */
	public function test_job_backed_notice_is_shown_while_preflight_is_blocked(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return null;
			}

			/** Offer the first generation. */
			public function should_offer_start(): bool {
				return true;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'WooPayments is now part of WooCommerce. Start the switch: we will migrate what is needed and disable the WooPayments extension.', $notice );
		$this->assertStringContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'not ready', $notice );
	}

	/**
	 * @testdox The completion notice stays on every admin page for a store manager until the manager dismisses it.
	 */
	public function test_completion_notice_stays_until_a_manager_dismisses_it(): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_completed_notice_job();
		$controller = $this->create_cutover_controller( $job );
		$redirects  = array();
		$capture    = static function ( string $location ) use ( &$redirects ): string {
			$redirects[] = $location;
			return '';
		};
		add_filter( 'wp_redirect', $capture );
		$this->register_exit_mock( static fn() => null );
		$request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Restored after the test.

		try {
			for ( $page = 0; $page < 2; $page++ ) {
				$notices = $this->render_admin_notices( $controller );
				$this->assertStringContainsString( 'WooPayments is now fully native in WooCommerce.', $notices );
			}
			$this->assertSame( array(), $job->dismissed, 'Rendering must not use up a notice, since the screen may not show it.' );

			$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wc-settings';
			$this->follow_dismiss_link( $controller, $notices, WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS );
			$notices = $this->render_admin_notices( $controller );
		} finally {
			remove_filter( 'wp_redirect', $capture );
			if ( null === $request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $request_uri;
			}
		}

		$this->assertSame( array( WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS ), $job->dismissed );
		$this->assertSame( array( '/wp-admin/admin.php?page=wc-settings' ), $redirects, 'The dismissal returns to the same page without the action.' );
		$this->assertStringNotContainsString( 'fully native', $notices );
	}

	/**
	 * @testdox A store excluded for the bundled WooPayments subscriptions is told why it cannot switch, with no start button, until a manager dismisses it (spec section 7).
	 */
	public function test_bundled_store_is_told_why_it_cannot_switch_until_dismissed(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job        = $this->create_excluded_notice_job( 'bundled_stripe_billing_subscriptions_present' );
		$controller = $this->create_cutover_controller( $job );
		$this->register_exit_mock( static fn() => null );
		add_filter( 'wp_redirect', '__return_empty_string' );

		try {
			$notices = $this->render_admin_notices( $controller );
			$this->assertStringContainsString( 'WooPayments is now part of WooCommerce, but this store can&#039;t switch yet. Its subscriptions are billed through Stripe Billing, which needs the Woo Subscriptions extension. Install and activate Woo Subscriptions to make the switch available. Until then nothing changes and WooPayments keeps running from the plugin.', $notices );
			$this->assertStringNotContainsString( 'Start the switch', $notices );
			$this->assertStringNotContainsString( 'button', $notices );

			$this->follow_dismiss_link( $controller, $notices, WooPaymentsCutoverReconciliationJob::NOTICE_BUNDLED_EXCLUSION );
			$this->assertSame( array( WooPaymentsCutoverReconciliationJob::NOTICE_BUNDLED_EXCLUSION ), $job->dismissed );
			$this->assertSame( '', trim( $this->render_admin_notices( $controller ) ) );
		} finally {
			remove_filter( 'wp_redirect', '__return_empty_string' );
		}
	}

	/**
	 * @testdox The bundled-store notice shows only where the start notice would: not for another exclusion, and not for a user who cannot start the switch.
	 */
	public function test_bundled_store_notice_follows_who_may_start_the_switch(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );

		$this->assertSame( '', trim( $this->render_admin_notices( $this->create_cutover_controller( $this->create_excluded_notice_job( 'some_other_exclusion' ) ) ) ) );

		$this->fake_current_user_caps( false );
		$this->assertSame( '', trim( $this->render_admin_notices( $this->create_cutover_controller( $this->create_excluded_notice_job( 'bundled_stripe_billing_subscriptions_present' ) ) ) ) );
	}

	/**
	 * @testdox Activating or deactivating WooCommerce Subscriptions, in any folder, forgets the cached cutover classification; other plugins do not.
	 * @testWith ["woocommerce-subscriptions/woocommerce-subscriptions.php", "activated", false]
	 *           ["woocommerce-com-woocommerce-subscriptions/woocommerce-subscriptions.php", "deactivated", false]
	 *           ["hello.php", "activated", true]
	 *
	 * @param string $plugin          Plugin file.
	 * @param string $change          Activated or deactivated.
	 * @param bool   $expected_cached Whether the classification stays cached.
	 */
	public function test_woocommerce_subscriptions_changes_forget_the_classification( string $plugin, string $change, bool $expected_cached ): void {
		update_option(
			WooPaymentsCutoverReconciliationJob::ADMIN_CLASSIFICATION_OPTION,
			array(
				'key'        => 'none',
				'present'    => true,
				'expires_at' => time() + HOUR_IN_SECONDS,
			)
		);
		$controller = $this->create_cutover_controller();

		if ( 'activated' === $change ) {
			$controller->handle_plugin_activated( $plugin, false );
		} else {
			$controller->handle_plugin_deactivated( $plugin, false );
		}

		$this->assertSame( $expected_cached, is_array( get_option( WooPaymentsCutoverReconciliationJob::ADMIN_CLASSIFICATION_OPTION ) ) );
	}

	/**
	 * @testdox A user who cannot manage WooCommerce never sees the completion notices and cannot dismiss them.
	 */
	public function test_completion_notices_are_for_store_managers_only(): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_completed_notice_job();
		$controller = $this->create_cutover_controller( $job );
		$notices    = $this->render_admin_notices( $controller );
		$this->fake_current_user_caps( false );

		$this->assertSame( '', trim( $this->render_admin_notices( $controller ) ) );

		$this->fake_wp_die_handler();
		$this->expectException( WooPaymentsCutoverBlockedException::class );
		try {
			$this->follow_dismiss_link( $controller, $notices, WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS );
		} finally {
			$this->assertSame( array(), $job->dismissed );
		}
	}

	/**
	 * @testdox A completion notice dismissal with an invalid nonce dies before it changes the record.
	 */
	public function test_completion_notice_dismissal_requires_a_valid_nonce(): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_completed_notice_job();
		$controller = $this->create_cutover_controller( $job );
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_DISMISS_NOTICE;
		$_GET[ WooPaymentsCutoverController::QUERY_NOTICE ] = WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS;
		$_GET[ WooPaymentsCutoverController::NONCE_NAME ]   = wp_create_nonce( WooPaymentsCutoverController::NONCE_ACTION );
		$this->fake_wp_die_handler();

		$this->expectException( WooPaymentsCutoverBlockedException::class );
		try {
			$controller->handle_admin_init();
		} finally {
			$this->assertSame( array(), $job->dismissed );
		}
	}

	/**
	 * @testdox A switch waiting on the WooPayments version on a site that blocks plugin updates tells the merchant to update WooPayments (blocked: $blocked).
	 * @testWith [true]
	 *           [false]
	 *
	 * @param bool $blocked Whether the site disallows automatic file changes.
	 */
	public function test_blocked_plugin_update_explains_the_waiting_switch( bool $blocked ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $context ) use ( $blocked ) {
				return 'automatic_updater' === $context ? ! $blocked : $allowed;
			},
			10,
			2
		);
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'                  => WooPaymentsCutoverStateStore::STATE_DEFERRED,
					'deferred_codes'         => array( 'woopayments_plugin_version_unsupported' ),
					'informational_outcomes' => array(),
				);
			}

			/** Do not emit reconnect information. */
			public function consume_reconnect_notice(): bool {
				return false;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		if ( $blocked ) {
			$this->assertStringContainsString( 'Update WooPayments to continue the switch.', $notice );
		} else {
			$this->assertStringNotContainsString( 'Update WooPayments', $notice );
		}
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'                  => $this->state,
					'informational_outcomes' => array(),
				);
			}

			/** Do not emit reconnect information. */
			public function consume_reconnect_notice(): bool {
				return false;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		$this->assertStringNotContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'not ready', $notice );
	}

	/**
	 * @testdox An awaiting generation offers the start only to a user who may start the switch.
	 */
	public function test_awaiting_generation_offers_the_start_only_to_who_may_start_it(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return array(
					'state'        => WooPaymentsCutoverStateStore::STATE_PENDING,
					'current_step' => 'awaiting_merchant_start',
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

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
					'state'        => WooPaymentsCutoverStateStore::STATE_DEFERRED,
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
		$controller = $this->create_cutover_controller( $job );

		$this->fake_current_user_caps( false );
		$non_manager = $this->render_admin_notices( $controller );
		$this->fake_current_user_caps( true );
		$first  = $this->render_admin_notices( $controller );
		$second = $this->render_admin_notices( $controller );

		$this->assertSame( '', trim( $non_manager ), 'A user who cannot manage the store sees no switch notice and leaves the reconnect information for one who can.' );
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
					'state'                  => WooPaymentsCutoverStateStore::STATE_DEFERRED,
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

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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

			/**
			 * Return whether lifecycle work is job-owned.
			 *
			 * @return bool
			 */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}

			/** Do not emit reconnect information. */
			public function consume_reconnect_notice(): bool {
				return false;
			}
		};
		$controller = $this->create_cutover_controller( $job );

		$this->assertTrue( $controller->disable_woopayments_plugin() );
		$controller->handle_plugin_deactivated( $renamed_plugin_file, false );
		$controller->handle_plugin_activated( $renamed_plugin_file, true );

		$this->assertSame( array( 'merchant' ), $job->sources );
		$this->assertSame( array( array( $renamed_plugin_file, false ) ), $job->manual );
		$this->assertSame( array( true ), $job->activation_scopes );
		$this->assertSame( array(), $this->deactivate_plugin_calls );
	}

	/**
	 * @testdox Native WooPayments should block a first-time standalone plugin activation.
	 */
	public function test_native_activation_guard_blocks_a_store_that_never_ran_the_extension(): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		$this->enable_native_runtime_owner();
		$this->fake_wp_die_handler();

		try {
			$this->sut->guard_woopayments_activation();
			$this->fail( 'The native owner should block a first-time WooPayments plugin activation.' );
		} catch ( WooPaymentsCutoverBlockedException $exception ) {
			$this->assertSame( 'WooPayments is already available in WooCommerce. Set up WooPayments in Payments settings instead.', $exception->getMessage() );
		}

		$this->assertStringContainsString( 'page=wc-settings', $this->wp_die_arguments['link_url'] );
		$this->assertStringContainsString( 'tab=checkout', $this->wp_die_arguments['link_url'] );
		$this->assertStringNotContainsString( 'plugins.php', $this->wp_die_arguments['link_url'] );
	}

	/** @testdox Native WooPayments should allow plugin reactivation after a recorded version. */
	public function test_native_activation_guard_allows_a_store_with_a_woopayments_plugin_version(): void {
		$this->enable_native_runtime_owner();

		$this->sut->guard_woopayments_activation();

		$this->assertTrue( true );
	}

	/**
	 * The built-in WooPayments writes the extension's gateway ids, so its orders and tokens are not evidence that the extension ran.
	 *
	 * @testdox Native WooPayments should block a first-time plugin activation on a store with built-in WooPayments $_dataName.
	 * @dataProvider data_provider_builtin_payment_records
	 *
	 * @param string $record     The built-in WooPayments record the store holds: order or token.
	 * @param string $gateway_id WooPayments gateway identity.
	 */
	public function test_native_activation_guard_blocks_a_store_with_only_builtin_payment_records( string $record, string $gateway_id ): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		if ( 'order' === $record ) {
			$order = wc_create_order();
			$order->set_payment_method( $gateway_id );
			$order->save();
		} else {
			$this->create_woopayments_token( $gateway_id );
		}
		$this->enable_native_runtime_owner();
		$this->fake_wp_die_handler();

		$this->expectException( WooPaymentsCutoverBlockedException::class );
		$this->sut->guard_woopayments_activation();
	}

	/** @testdox Plugin activation remains available while native WooPayments does not own the runtime. */
	public function test_native_activation_guard_allows_plugin_activation_when_native_does_not_own_the_runtime(): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$this->sut->guard_woopayments_activation();

		$this->assertTrue( true );
	}

	/** @testdox Internal lifecycle changes bypass the native activation guard. */
	public function test_native_activation_guard_allows_internal_plugin_lifecycle_changes(): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		$this->enable_native_runtime_owner();
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/**
			 * Return whether lifecycle work is job-owned.
			 *
			 * @return bool
			 */
			public function is_internal_plugin_lifecycle_change(): bool {
				return true;
			}
		};

		$this->create_cutover_controller( $job )->guard_woopayments_activation();

		$this->assertTrue( true );
	}

	/** @testdox Merged feature development bypasses the native activation guard. */
	public function test_native_activation_guard_allows_merged_feature_development(): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		$this->enable_native_runtime_owner();
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );

		$this->sut->guard_woopayments_activation();

		$this->assertTrue( true );
	}

	/**
	 * Built-in WooPayments orders and tokens, with the canonical and a prefixed gateway id.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function data_provider_builtin_payment_records(): array {
		return array(
			'orders, canonical gateway ID' => array( 'order', 'woocommerce_payments' ),
			'orders, prefixed gateway ID'  => array( 'order', 'woocommerce_payments_card' ),
			'tokens, canonical gateway ID' => array( 'token', 'woocommerce_payments' ),
			'tokens, prefixed gateway ID'  => array( 'token', 'woocommerce_payments_card' ),
		);
	}

	/**
	 * @testdox Starting the switch needs manage_woocommerce plus the plugin capability for the plugin's scope: $scope with the given capabilities queues $expected.
	 * @dataProvider provider_start_capabilities
	 *
	 * @param string   $scope        Where WooPayments is active: site or network.
	 * @param string[] $capabilities Capabilities the user has.
	 * @param string[] $expected     Sources the job is asked to queue.
	 */
	public function test_starting_the_switch_requires_the_capabilities_for_the_plugin_scope( string $scope, array $capabilities, array $expected ): void {
		$this->fake_plugin_active( 'site' === $scope, 'network' === $scope );
		$this->fake_current_user_capabilities( $capabilities );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] */
			public array $sources = array();

			/**
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( $job );

		$controller->disable_woopayments_plugin();

		$this->assertSame( $expected, $job->sources );
	}

	/**
	 * Capability combinations for starting the switch.
	 *
	 * @return array<string,array{0:string,1:string[],2:string[]}>
	 */
	public function provider_start_capabilities(): array {
		return array(
			'site, store manager without activate_plugins' => array( 'site', array( 'manage_woocommerce' ), array() ),
			'site, activate_plugins without store manager' => array( 'site', array( 'activate_plugins' ), array() ),
			'site, both capabilities'                      => array( 'site', array( 'manage_woocommerce', 'activate_plugins' ), array( 'merchant' ) ),
			'network, without manage_network_plugins'      => array( 'network', array( 'manage_woocommerce', 'activate_plugins' ), array() ),
			'network, with manage_network_plugins'         => array( 'network', array( 'manage_woocommerce', 'manage_network_plugins' ), array( 'merchant' ) ),
		);
	}

	/**
	 * @testdox With mandatory cutover $label, an admin page on a store still running the plugin queues $expected.
	 * @testWith ["on", true, ["mandatory"]]
	 *           ["off by default", false, []]
	 *
	 * @param string   $label     Readable case name.
	 * @param bool     $mandatory Whether the mandatory cutover filter is turned on.
	 * @param string[] $expected  Sources the job is asked to queue.
	 */
	public function test_mandatory_cutover_queues_the_switch_only_when_turned_on( string $label, bool $mandatory, array $expected ): void {
		unset( $label );
		$this->fake_plugin_active();
		$this->enable_ready_cutover();
		if ( $mandatory ) {
			add_filter( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		}
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] */
			public array $sources = array();

			/**
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( $job );

		$controller->handle_admin_init();

		$this->assertSame( $expected, $job->sources );
	}

	/**
	 * @testdox A valid merchant action queues idempotent durable work and redirects to the bare Plugins screen.
	 */
	public function test_valid_cutover_action_enqueues_idempotently_and_redirects_to_plugins(): void {
		$arbiter      = new class() extends WooPaymentsRuntimeArbiter {
			/** Return enabled native runtime. */
			public function is_builtin_enabled(): bool {
				return true;
			}

			/** Return active plugin ownership. */
			public function is_extension_owner(): bool {
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
	 * @testdox A Start the switch click that queues nothing with native payments $label is $outcome.
	 * @testWith ["enabled", true, "logged once and reported for a retry"]
	 *           ["disabled", false, "sent to the plugins screen without an error"]
	 *
	 * @param string $label          Readable state of native payments.
	 * @param bool   $feature_on     Whether the WooPayments feature is on.
	 * @param string $outcome        Readable expected outcome.
	 */
	public function test_click_that_queues_nothing( string $label, bool $feature_on, string $outcome ): void {
		unset( $label, $outcome );
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature_on ? 'yes' : 'no' );
		$this->fake_wp_die_handler();
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/**
			 * Queue nothing, as when another request holds the cutover lease or native payments is off.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				unset( $source );
				return false;
			}
		};
		$controller = $this->create_cutover_controller( $job );
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_DISABLE;
		$_GET[ WooPaymentsCutoverController::NONCE_NAME ]   = wp_create_nonce( WooPaymentsCutoverController::NONCE_ACTION );
		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->expects( $feature_on ? $this->once() : $this->never() )
			->method( 'error' )
			->with( $this->stringContains( 'switch' ), array( 'source' => 'woocommerce-woopayments-cutover' ) );
		$this->register_legacy_proxy_function_mocks(
			array(
				'wc_get_logger' => static fn() => $logger,
			)
		);
		$this->register_exit_mock( static fn() => null );
		$redirects = array();
		$capture   = static function ( $location ) use ( &$redirects ) {
			$redirects[] = $location;
			return '';
		};
		add_filter( 'wp_redirect', $capture );

		$message = null;
		try {
			$controller->handle_admin_init();
		} catch ( WooPaymentsCutoverBlockedException $exception ) {
			$message = $exception->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		if ( $feature_on ) {
			$this->assertSame( 'Action failed. Please refresh the page and retry.', $message );
			$this->assertSame( array(), $redirects );
		} else {
			$this->assertNull( $message, 'A switch that is off is not an error to retry.' );
			$this->assertSame( array( admin_url( 'plugins.php' ) ), $redirects );
		}
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
		$controller = $this->create_cutover_controller( $job );
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
		$controller = $this->create_cutover_controller( $job );

		$controller->handle_admin_init();

		$this->assertSame( 0, $job->enqueue_calls, 'Developer bypass should not start mandatory reconciliation.' );
		$this->assertTrue( $this->plugin_active, 'Developer bypass should leave WooPayments active.' );
	}

	/**
	 * @testdox Cutover preflight short-circuits expensive checks while native runtime is disabled.
	 */
	public function test_preflight_short_circuits_when_native_runtime_is_disabled(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$failures = $this->preflight_service->get_failures();

		$this->assertSame( array( 'builtin_runtime_disabled' ), $failures, 'Disabled native runtime should be the only preflight result.' );
		$this->assertSame( 0, $this->native_provider_readiness_calls, 'Native transport readiness should not be checked while native runtime is disabled.' );
		$this->assertSame( 0, $this->platform_connection_preflight_calls, 'Platform connection preflight should not run while native runtime is disabled.' );
		$this->assertSame( 0, $this->fee_remediation_preflight_calls, 'Financial migration preflight should not run while native runtime is disabled.' );
	}

	/**
	 * @testdox Cutover preflight memoizes expensive checks for the current request.
	 */
	public function test_preflight_memoizes_expensive_checks_within_request(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->native_provider_ready = true;

		$first_failures  = $this->preflight_service->get_failures();
		$second_failures = $this->preflight_service->get_failures();

		$this->assertSame( $first_failures, $second_failures, 'Repeated preflight checks in one request should reuse the first result.' );
		$this->assertSame( 1, $this->native_provider_readiness_calls, 'Native transport readiness should be checked once per request.' );
		$this->assertSame( 1, $this->platform_connection_preflight_calls, 'Platform connection preflight should be checked once per request.' );
		$this->assertSame( 1, $this->fee_remediation_preflight_calls, 'Financial migration preflight should run once per request.' );
	}

	/**
	 * @testdox Cutover preflight blocks while the native provider cannot process payments.
	 */
	public function test_preflight_blocks_when_native_provider_cannot_process_payments(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->native_provider_ready = false;

		$this->assertContains( 'builtin_transport_unavailable', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks while platform connection owner user-token readiness is unavailable.
	 */
	public function test_preflight_blocks_when_platform_connection_user_token_is_unavailable(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->platform_connection_failures = array( 'wpcom_connection_owner_user_token_unavailable' );

		$this->assertContains( 'wpcom_connection_owner_user_token_unavailable', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks when automatic multi-currency rates have no provider.
	 */
	public function test_preflight_blocks_when_multi_currency_automatic_rates_have_no_provider(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'automatic' );

		$this->assertContains( 'multi_currency_rates_unavailable', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight allows manual multi-currency rates without a provider.
	 */
	public function test_preflight_allows_manual_multi_currency_rates_without_provider(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->enable_multi_currency_with_rate_type( 'manual' );

		$this->assertNotContains( 'multi_currency_rates_unavailable', $this->preflight_service->get_failures() );
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

		$this->assertNotContains( 'multi_currency_rates_unavailable', $this->preflight_service->get_failures() );
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

		$this->assertContains( 'woopayments_plugin_version_unsupported', $this->preflight_service->get_failures() );
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
	 * @testdox Cutover preflight discovers pending WooPayments actions without a static hook inventory.
	 */
	public function test_preflight_blocks_when_unknown_woopayments_action_is_pending(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->native_provider_ready = true;

		$hook_name                      = 'wcpay_synthetic_cutover_probe';
		$this->scheduled_action_hooks[] = $hook_name;
		$action_id                      = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook_name, array(), 'woocommerce-test-cutover', true );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertContains( 'operational_queue_hooks_unhandled', $this->preflight_service->get_failures() );
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->native_provider_ready = true;

		$this->scheduled_action_hooks[] = $hook_name;
		$action_id                      = as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook_name, array(), 'woocommerce-test-cutover', true );

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
		$this->assertNotContains( 'operational_queue_hooks_unhandled', $this->preflight_service->get_failures() );
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
			'Stripe Billing migration scheduling'   => array( 'wcpay_schedule_subscription_migrations' ),
			'Stripe Billing migration'              => array( 'wcpay_migrate_subscription' ),
			'Stripe Billing migration retry'        => array( 'wcpay_migrate_subscription_retry' ),
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

		$this->assertContains( 'financial_migrations_unavailable', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight no longer reports a queue blocker after A5a disposition.
	 */
	public function test_preflight_closes_the_queue_blocker_by_default(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->native_provider_ready = true;

		$failures = $this->preflight_service->get_failures();

		$this->assertNotContains( 'operational_queue_hooks_unhandled', $failures );
	}

	/**
	 * @testdox Cutover preflight blocks a store without WooCommerce Subscriptions while a subscription is still Stripe-billed (bundled flavor, spec section 7).
	 */
	public function test_preflight_blocks_when_legacy_stripe_billing_subscription_marker_exists(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'pending' );

		$this->assertContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks a bundled-flavor store whatever the status of its Stripe-billed subscription.
	 */
	public function test_preflight_blocks_cancelled_legacy_stripe_billing_subscription_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'cancelled' );

		$this->assertContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight allows a store whose subscriptions were all migrated off Stripe Billing: the migrator leaves only `_migrated_*` meta (client `class-wc-payments-subscriptions-migrator.php:271-300`).
	 */
	public function test_preflight_allows_a_store_migrated_off_stripe_billing(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_subscription( 'active', '_migrated_wcpay_subscription_id' );
		$this->create_legacy_stripe_billing_subscription( 'active', '_wcpay_subscription_migrated_during' );

		$this->assertNotContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight blocks a store with the bundled WooPayments subscriptions on, even with no Stripe-billed subscription.
	 */
	public function test_preflight_blocks_a_store_with_bundled_subscriptions_on(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		update_option( '_wcpay_feature_subscriptions', '1' );

		try {
			$this->assertContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
		} finally {
			delete_option( '_wcpay_feature_subscriptions' );
		}
	}

	/**
	 * @testdox Cutover preflight allows orders that keep their Stripe Billing invoice IDs after a migration.
	 */
	public function test_preflight_allows_stripe_billing_invoice_order_markers(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$order = wc_create_order();
		$order->update_meta_data( '_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$order->update_meta_data( '_migrated_wcpay_billing_invoice_id', 'in_1UM1VrBzWlxcwgpPgrIwNSlu' );
		$order->save();

		$this->assertNotContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight finds a Stripe-billed subscription in HPOS order meta.
	 */
	public function test_preflight_blocks_legacy_stripe_billing_hpos_marker(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		$this->create_legacy_stripe_billing_hpos_marker( '_wcpay_subscription_id' );

		$this->assertContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * @testdox Cutover preflight allows clean stores without legacy Stripe Billing markers.
	 */
	public function test_preflight_allows_clean_store_without_legacy_stripe_billing_markers(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();

		$this->assertNotContains( 'bundled_stripe_billing_subscriptions_present', $this->preflight_service->get_failures() );
	}

	/**
	 * Create a preflight service wired to this test's dependencies.
	 *
	 * @return WooPaymentsCutoverPreflightService
	 */
	private function create_preflight_service(): WooPaymentsCutoverPreflightService {
		$preflight_service = new WooPaymentsCutoverPreflightService();
		$this->init_preflight_service(
			$preflight_service,
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			wc_get_container()->get( LegacyProxy::class ),
			$this->provider,
			new WooPaymentsLegacySubscriptionsGuard(),
			$this->fee_remediation_service,
			$this->platform_connection_service,
			$this->native_rate_account,
			$this->native_rate_api_client
		);

		return $preflight_service;
	}

	/**
	 * Create a cutover controller wired to this test's dependencies.
	 *
	 * @param WooPaymentsCutoverReconciliationJob|null $job               Optional reconciliation job.
	 * @param WooPaymentsCutoverPreflightService|null  $preflight_service Optional preflight service; a new one by default.
	 * @return WooPaymentsCutoverController
	 */
	private function create_cutover_controller( ?WooPaymentsCutoverReconciliationJob $job = null, ?WooPaymentsCutoverPreflightService $preflight_service = null ): WooPaymentsCutoverController {
		$arbiter           = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$legacy_proxy      = wc_get_container()->get( LegacyProxy::class );
		$preflight_service = $preflight_service ?? $this->create_preflight_service();

		$job        = $job ?? new class() extends WooPaymentsCutoverReconciliationJob {
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

	/**
	 * Create a completed-cutover job double that records dismissals on its record.
	 *
	 * @return WooPaymentsCutoverReconciliationJob&object{dismissed: string[]}
	 */
	private function create_completed_notice_job(): WooPaymentsCutoverReconciliationJob {
		return new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Completed record. */
			private array $record = array(
				'state'                  => WooPaymentsCutoverStateStore::STATE_DONE,
				'informational_outcomes' => array(),
			);

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return $this->record;
			}

			/**
			 * Record the dismissal the way the durable record does.
			 *
			 * @param string $notice Notice to dismiss.
			 */
			public function dismiss_completion_notice( string $notice ): bool {
				if ( ! $this->is_completion_notice_due( $this->record, $notice ) ) {
					return false;
				}
				$this->dismissed[]                        = $notice;
				$this->record['informational_outcomes'][] = array( 'code' => $notice . '_notice_dismissed' );
				return true;
			}
		};
	}

	/**
	 * Create a job whose record is excluded for the given code, recording dismissals the way the durable record does.
	 *
	 * @param string $code Exclusion code.
	 * @return WooPaymentsCutoverReconciliationJob
	 */
	private function create_excluded_notice_job( string $code ): WooPaymentsCutoverReconciliationJob {
		return new class( $code ) extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Excluded record. */
			private array $record;

			/**
			 * @param string $code Exclusion code.
			 */
			public function __construct( string $code ) {
				$this->record = array(
					'state'                  => WooPaymentsCutoverStateStore::STATE_EXCLUDED,
					'current_step'           => 'excluded',
					'deferred_codes'         => array( $code ),
					'informational_outcomes' => array(),
				);
			}

			/** @return array<string,mixed>|null */
			public function classify_for_admin_notice(): ?array {
				return $this->record;
			}

			/**
			 * Record the dismissal the way the durable record does.
			 *
			 * @param string $notice Notice to dismiss.
			 */
			public function dismiss_completion_notice( string $notice ): bool {
				if ( self::NOTICE_BUNDLED_EXCLUSION !== $notice || ! $this->is_bundled_exclusion_notice_due( $this->record ) ) {
					return false;
				}
				$this->dismissed[]                        = $notice;
				$this->record['informational_outcomes'][] = array( 'code' => $notice . '_notice_dismissed' );
				return true;
			}
		};
	}

	/**
	 * Follow the dismiss link of one rendered completion notice.
	 *
	 * @param WooPaymentsCutoverController $controller Cutover controller.
	 * @param string                       $notices    Rendered notice markup.
	 * @param string                       $notice     Notice whose link to follow.
	 */
	private function follow_dismiss_link( WooPaymentsCutoverController $controller, string $notices, string $notice ): void {
		$this->assertSame( 1, preg_match( '/href="([^"]*' . preg_quote( WooPaymentsCutoverController::QUERY_NOTICE . '=' . $notice, '/' ) . '[^"]*)"/', $notices, $matches ), "The {$notice} notice should render a dismiss link." );
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $query );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$controller->handle_admin_init();
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
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->native_provider_ready = true;
	}

	/**
	 * Make the native runtime own the current site.
	 */
	private function enable_native_runtime_owner(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Create a saved WooPayments card token.
	 *
	 * @param string $gateway_id WooPayments gateway identity.
	 * @return WC_Payment_Token_CC
	 */
	private function create_woopayments_token( string $gateway_id ): WC_Payment_Token_CC {
		$token = new WC_Payment_Token_CC();
		$token->set_gateway_id( $gateway_id );
		$token->set_user_id( self::factory()->user->create() );
		$token->set_token( 'pm_native_activation_guard' );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( '2030' );
		$token->save();

		return $token;
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
		$entry                       = $plugin_file ?? WooPaymentsRuntimeArbiter::PLUGIN_FILE;

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
				'is_multisite'       => function () {
					return $this->plugin_network_active || is_multisite();
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
					? in_array( $capability, $this->granted_cutover_capabilities, true )
					: current_user_can( $capability ),
			)
		);
	}

	/**
	 * Simulate the next request after the WooPayments plugin was deactivated.
	 */
	private function fake_woopayments_class_unloaded(): void {
		$this->plugin_class_loaded = false;
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
		$this->granted_cutover_capabilities = $can_cutover ? array( 'manage_woocommerce', 'activate_plugins', 'manage_network_plugins' ) : array();
	}

	/**
	 * Grant exactly these cutover-relevant capabilities to the current user.
	 *
	 * @param string[] $capabilities Granted among manage_woocommerce, activate_plugins and manage_network_plugins.
	 */
	private function fake_current_user_capabilities( array $capabilities ): void {
		$this->granted_cutover_capabilities = $capabilities;
	}

	/**
	 * Replace wp_die with a test exception.
	 */
	private function fake_wp_die_handler(): void {
		add_filter(
			'wp_die_handler',
			function () {
				return function ( $message = '', $title = '', $arguments = array() ): void {
					unset( $title );
					$this->wp_die_arguments = is_array( $arguments ) ? $arguments : array();
					throw new WooPaymentsCutoverBlockedException( esc_html( wp_strip_all_tags( (string) $message ) ) );
				};
			}
		);
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
	 * @param WooPaymentsLegacySubscriptionsGuard                   $legacy_subscriptions_guard  The legacy subscription data guard.
	 * @param WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service     The fee remediation owner.
	 * @param WooPaymentsPlatformConnectionService                  $platform_connection_service The platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter                       $native_rate_account         The native rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter                     $native_rate_api_client      The native rate API client boundary.
	 */
	private function init_preflight_service( WooPaymentsCutoverPreflightService $service, WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider, WooPaymentsLegacySubscriptionsGuard $legacy_subscriptions_guard, WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service, WooPaymentsPlatformConnectionService $platform_connection_service, WooPaymentsNativeAccountAdapter $native_rate_account, WooPaymentsNativeApiClientAdapter $native_rate_api_client ): void {
		$service->init( $arbiter, $legacy_proxy );

		$collaborators = array(
			'provider'                    => $provider,
			'legacy_subscriptions_guard'  => $legacy_subscriptions_guard,
			'fee_remediation_service'     => $fee_remediation_service,
			'platform_connection_service' => $platform_connection_service,
			'native_rate_account'         => $native_rate_account,
			'native_rate_api_client'      => $native_rate_api_client,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( WooPaymentsCutoverPreflightService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}
	}
}
