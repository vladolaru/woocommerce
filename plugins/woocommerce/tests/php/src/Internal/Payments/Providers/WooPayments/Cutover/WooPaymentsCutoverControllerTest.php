<?php
/**
 * WooPaymentsCutoverController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPerSiteActivationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsBundledSubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsPlatformConnectionService;
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
	private WooPaymentsNativeAccountAdapter $rate_account;

	/**
	 * Native rate API client boundary mock.
	 *
	 * @var WooPaymentsNativeApiClientAdapter&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsNativeApiClientAdapter $rate_api_client;

	/**
	 * Whether the native provider can process payments.
	 *
	 * @var bool
	 */
	private bool $builtin_provider_ready = false;

	/**
	 * Number of times the native provider readiness was checked.
	 *
	 * @var int
	 */
	private int $builtin_provider_readiness_calls = 0;

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
	 * Whether the WooPayments plugin should appear active for every site.
	 *
	 * @var bool
	 */
	private bool $plugin_sitewide_active = false;

	/**
	 * Whether the WooPayments plugin bootstrap class is loaded in this PHP request.
	 *
	 * @var bool
	 */
	private bool $extension_class_loaded = false;

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
					++$this->builtin_provider_readiness_calls;
					return $this->builtin_provider_ready;
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
					return true;
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
			->onlyMethods( array( 'get_preflight_failures' ) )
			->getMock();
		$this->platform_connection_service
			->method( 'get_preflight_failures' )
			->willReturnCallback(
				function (): array {
					++$this->platform_connection_preflight_calls;
					return array();
				}
			);

		$this->rate_account = $this->getMockBuilder( WooPaymentsNativeAccountAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_provider_connected', 'is_account_rejected' ) )
			->getMock();
		$this->rate_account->method( 'is_provider_connected' )->willReturn( true );
		$this->rate_account->method( 'is_account_rejected' )->willReturn( false );

		$this->rate_api_client = $this->getMockBuilder( WooPaymentsNativeApiClientAdapter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_server_connected' ) )
			->getMock();
		$this->rate_api_client->method( 'is_server_connected' )->willReturn( false );

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
		unset( $_GET[ WooPaymentsCutoverController::QUERY_ACTION ], $_GET[ WooPaymentsCutoverController::NONCE_NAME ], $_GET[ WooPaymentsCutoverController::QUERY_NOTICE ], $_GET['page'], $_GET['tab'] );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_woocommerce_payments_version' );

		remove_all_filters( WooPaymentsCutoverController::FILTER_MANDATORY_CUTOVER_ENABLED );
		remove_all_filters( 'wp_die_handler' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
	 * @testdox While the WooPayments extension is active for every site, the store is told a super admin first activates WooPayments per site, with no start button.
	 */
	public function test_extension_active_for_every_site_refuses_the_start_with_a_notice(): void {
		$this->fake_plugin_active( false, true );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer the first generation. */
			public function can_offer_start(): bool {
				return true;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'WooPayments is now part of WooCommerce. This store can&#039;t switch yet because the WooPayments extension is active for every site of the network. A super admin first needs to activate WooPayments on each site instead.', $notice );
		$this->assertStringNotContainsString( 'Start the switch', $notice );
	}

	/**
	 * @testdox The refusal shown while the extension is active for every site offers the per-site activation, posting to this site, only to a user who may manage network plugins ($_dataName).
	 * @dataProvider provide_refusal_audiences
	 *
	 * @param string[] $capabilities The current user's capabilities among manage_woocommerce, activate_plugins and manage_network_plugins.
	 * @param bool     $offered      Whether the notice offers the per-site activation.
	 */
	public function test_refusal_offers_the_per_site_activation_to_a_super_admin( array $capabilities, bool $offered ): void {
		$this->fake_plugin_active( false, true );
		$this->fake_current_user_capabilities( $capabilities );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer the first generation. */
			public function can_offer_start(): bool {
				return true;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'A super admin first needs to activate WooPayments on each site instead.', $notice );
		if ( ! $offered ) {
			$this->assertStringNotContainsString( 'Activate WooPayments per site', $notice );
			return;
		}
		$this->assertSame( 1, preg_match( '/<a class="button button-primary" href="([^"]*)">Activate WooPayments per site<\/a>/', $notice, $matches ), 'The refusal offers the per-site activation.' );
		$href = html_entity_decode( $matches[1] );
		$this->assertStringStartsWith( admin_url( 'plugins.php' ) . '?', $href, 'The button posts to this site.' );
		wp_parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
		$this->assertSame( WooPaymentsPerSiteActivationController::ACTION_CONVERT, $query[ WooPaymentsPerSiteActivationController::QUERY_ACTION ] ?? null );
		$this->assertSame( 1, wp_verify_nonce( $query[ WooPaymentsPerSiteActivationController::NONCE_NAME ] ?? '', WooPaymentsPerSiteActivationController::NONCE_ACTION ) );
	}

	/**
	 * Who reads the refusal: a super admin, and a site administrator who may not manage network plugins.
	 *
	 * @return array<string,array{string[],bool}>
	 */
	public function provide_refusal_audiences(): array {
		return array(
			'a super admin'        => array( array( 'manage_woocommerce', 'activate_plugins', 'manage_network_plugins' ), true ),
			'a site administrator' => array( array( 'manage_woocommerce', 'activate_plugins' ), false ),
		);
	}

	/**
	 * @testdox A switch deferred for the extension being active for every site says why under Switch in progress while it is, and stops once it is not: $scope.
	 * @testWith ["active for every site", true]
	 *           ["active per site after the conversion", false]
	 *
	 * @param string $scope           Where WooPayments is active.
	 * @param bool   $sitewide_active Whether the extension is active for every site now, which is when the sentence shows.
	 */
	public function test_switch_waiting_on_the_extension_for_every_site_says_why_only_while_it_is( string $scope, bool $sitewide_active ): void {
		unset( $scope );
		$this->fake_plugin_active( ! $sitewide_active, $sitewide_active );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'          => WooPaymentsCutoverStateStore::STATE_DEFERRED,
					'current_step'   => 'deferred',
					'deferred_codes' => array( WooPaymentsCutoverReconciliationJob::CODE_PLUGIN_ACTIVE_SITEWIDE ),
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		$sentence = 'This store can&#039;t switch yet because the WooPayments extension is active for every site of the network. A super admin first needs to activate WooPayments on each site instead.';
		if ( $sitewide_active ) {
			$this->assertStringContainsString( $sentence, $notice );
		} else {
			$this->assertStringNotContainsString( $sentence, $notice );
		}
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
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer the first generation. */
			public function can_offer_start(): bool {
				return true;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'WooPayments is now part of WooCommerce. Start the switch: we will migrate what is needed and disable the WooPayments extension.', $notice );
		$this->assertStringContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( 'not ready', $notice );
	}

	/**
	 * @testdox "Not now" hides the switch notice for 30 days on every admin page but Payments settings, where it stays without the link ($store).
	 * @testWith ["extension active"]
	 *           ["waiting for the switch"]
	 *
	 * @param string $store Which store offers the switch.
	 */
	public function test_not_now_snoozes_the_switch_notice_outside_payments_settings( string $store ): void {
		$this->arrange_switch_offer( $store );
		$now = 1800000000;
		$this->register_legacy_proxy_function_mocks( array( 'time' => static fn() => $now ) );
		$controller = $this->create_cutover_controller();
		$this->register_exit_mock( static fn() => null );
		add_filter( 'wp_redirect', '__return_empty_string' );

		try {
			$notices = $this->render_admin_notices( $controller );
			$this->assertStringContainsString( 'Start the switch', $notices );
			$this->assertStringContainsString( 'Not now', $notices );
			$this->follow_dismiss_link( $controller, $notices, WooPaymentsCutoverController::NOTICE_START_SWITCH );
		} finally {
			remove_filter( 'wp_redirect', '__return_empty_string' );
		}

		$this->assertSame( $now + 30 * DAY_IN_SECONDS, (int) get_option( WooPaymentsCutoverReconciliationJob::START_NOTICE_SNOOZED_UNTIL_OPTION ) );
		unset( $_GET[ WooPaymentsCutoverController::QUERY_ACTION ], $_GET[ WooPaymentsCutoverController::NONCE_NAME ], $_GET[ WooPaymentsCutoverController::QUERY_NOTICE ] );
		$this->assertStringNotContainsString( 'Start the switch', $this->render_admin_notices( $controller ), 'Snoozed on other admin pages.' );

		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$settings     = $this->render_admin_notices( $controller );
		$this->assertStringContainsString( 'Start the switch', $settings, 'Payments settings keeps the switch notice.' );
		$this->assertStringNotContainsString( 'Not now', $settings );
	}

	/**
	 * @testdox The switch notice is back on every admin page once the 30 days have passed.
	 */
	public function test_snoozed_switch_notice_returns_after_30_days(): void {
		$this->arrange_switch_offer( 'extension active' );
		$now = 1800000000;
		update_option( WooPaymentsCutoverReconciliationJob::START_NOTICE_SNOOZED_UNTIL_OPTION, $now + 30 * DAY_IN_SECONDS );
		$this->register_legacy_proxy_function_mocks( array( 'time' => static fn() => $now + 31 * DAY_IN_SECONDS ) );

		$notices = $this->render_admin_notices( $this->create_cutover_controller() );

		$this->assertStringContainsString( 'Start the switch', $notices );
		$this->assertStringContainsString( 'Not now', $notices );
	}

	/**
	 * @testdox The $_dataName stays on every admin page for a store manager until the manager dismisses it.
	 * @dataProvider provide_dismissable_notices
	 *
	 * @param string $notice Notice key.
	 * @param string $text   A sentence the notice shows.
	 */
	public function test_notice_stays_until_a_manager_dismisses_it( string $notice, string $text ): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_dismissable_notice_job( $notice );
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
				$this->assertStringContainsString( $text, $notices );
			}
			$this->assertSame( array(), $job->dismissed, 'Rendering must not use up a notice, since the screen may not show it.' );

			$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=wc-settings';
			$this->follow_dismiss_link( $controller, $notices, $notice );
			$notices = $this->render_admin_notices( $controller );
		} finally {
			remove_filter( 'wp_redirect', $capture );
			if ( null === $request_uri ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $request_uri;
			}
		}

		$this->assertSame( array( $notice ), $job->dismissed );
		$this->assertSame( array( '/wp-admin/admin.php?page=wc-settings' ), $redirects, 'The dismissal returns to the same page without the action.' );
		$this->assertStringNotContainsString( $text, $notices );
	}

	/**
	 * The notices a manager dismisses from the record: the completion notice, and the one reconnect notice of a switch that
	 * cannot heal by itself.
	 *
	 * @return array<string,array{string,string}>
	 */
	public function provide_dismissable_notices(): array {
		return array(
			'completion notice' => array( WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS, 'WooPayments is now built into WooCommerce. Everything works as before.' ),
			'reconnect notice'  => array( WooPaymentsCutoverReconciliationJob::NOTICE_RECONNECT, 'The connection owner is no longer available. Reconnect this site to continue the switch.' ),
		);
	}

	/**
	 * @testdox A store excluded for the bundled WooPayments subscriptions is told why it cannot switch, with no start button, until a manager dismisses it.
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
			$this->assertStringContainsString( 'WooPayments is now part of WooCommerce, but this store can&#039;t switch yet. Its subscriptions are billed through Stripe Billing, which needs the Woo Subscriptions extension. Install and activate Woo Subscriptions to make the switch available. Until then nothing changes and WooPayments keeps running from the WooPayments extension.', $notices );
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
	 * @testdox The bundled-store notice ends with what keeps payments running on a store with the extension active, and with how to keep taking payments on a store waiting for its switch ($_dataName).
	 * @dataProvider provide_bundled_notice_endings
	 *
	 * @param string $store    "extension active" or "waiting for the switch".
	 * @param string $sentence The notice's last sentence.
	 * @param string $other    The sentence the other store gets, which this one must not.
	 */
	public function test_bundled_store_notice_ends_with_what_keeps_payments_running( string $store, string $sentence, string $other ): void {
		$this->arrange_switch_offer( $store );

		$notices = $this->render_admin_notices( $this->create_cutover_controller( $this->create_excluded_notice_job( 'bundled_stripe_billing_subscriptions_present' ) ) );

		$this->assertStringContainsString( $sentence, $notices );
		$this->assertStringNotContainsString( $other, $notices );
	}

	/**
	 * With the extension active it keeps running; waiting for the switch, nothing owns payments until the extension is active again.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provide_bundled_notice_endings(): array {
		$running = 'Until then nothing changes and WooPayments keeps running from the WooPayments extension.';
		$waiting = 'Until then, activate the WooPayments extension again to keep taking payments.';

		return array(
			'the extension owns payments'         => array( 'extension active', $running, $waiting ),
			'the store is waiting for its switch' => array( 'waiting for the switch', $waiting, $running ),
		);
	}

	/**
	 * @testdox The bundled-store notice shows only for this exclusion, and only to a user who may manage WooCommerce.
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
			$controller->handle_plugin_deactivated( $plugin );
		}

		$this->assertSame( $expected_cached, is_array( get_option( WooPaymentsCutoverReconciliationJob::ADMIN_CLASSIFICATION_OPTION ) ) );
	}

	/**
	 * @testdox A user who cannot manage WooCommerce never sees the $_dataName and cannot dismiss it.
	 * @dataProvider provide_dismissable_notices
	 *
	 * @param string $notice Notice key.
	 */
	public function test_dismissable_notices_are_for_store_managers_only( string $notice ): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_dismissable_notice_job( $notice );
		$controller = $this->create_cutover_controller( $job );
		$notices    = $this->render_admin_notices( $controller );
		$this->fake_current_user_caps( false );

		$this->assertSame( '', trim( $this->render_admin_notices( $controller ) ) );

		$this->fake_wp_die_handler();
		$this->expectException( WooPaymentsCutoverBlockedException::class );
		try {
			$this->follow_dismiss_link( $controller, $notices, $notice );
		} finally {
			$this->assertSame( array(), $job->dismissed );
		}
	}

	/**
	 * @testdox A $_dataName dismissal with an invalid nonce dies before it changes the record.
	 * @dataProvider provide_dismissable_notices
	 *
	 * @param string $notice Notice key.
	 */
	public function test_notice_dismissal_requires_a_valid_nonce( string $notice ): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$job        = $this->create_dismissable_notice_job( $notice );
		$controller = $this->create_cutover_controller( $job );
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_DISMISS_NOTICE;
		$_GET[ WooPaymentsCutoverController::QUERY_NOTICE ] = $notice;
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
	 * @testdox A switch deferred on a connection owner failure while the site is disconnected too shows no reconnect notice.
	 */
	public function test_disconnected_site_shows_no_reconnect_notice(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => WooPaymentsCutoverStateStore::STATE_DEFERRED,
					'deferred_codes'         => array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable' ),
					'informational_outcomes' => array( array( 'code' => 'reconnect_required' ) ),
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		$this->assertStringNotContainsString( 'Reconnect this site', $notice );
	}

	/**
	 * The job tries to update the extension itself, and the update can fail where file changes are allowed too (a host that
	 * asks for FTP credentials, no update offered, a release that needs a newer WordPress or PHP). The warning follows the
	 * installed files, not the reason.
	 *
	 * @testdox A switch deferred on the WooPayments version tells the merchant to update WooPayments while the installed extension is below the supported version ($_dataName).
	 * @dataProvider provide_extension_update_warnings
	 *
	 * @param bool        $file_mods_blocked Whether the site disallows automatic file changes.
	 * @param bool        $installed_current Whether the installed extension files carry a supported version.
	 * @param string|null $warning           The reason the warning gives, or null when none shows.
	 */
	public function test_deferred_extension_update_explains_the_waiting_switch( bool $file_mods_blocked, bool $installed_current, ?string $warning ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		add_filter(
			'file_mod_allowed',
			static function ( $allowed, $context ) use ( $file_mods_blocked ) {
				return 'automatic_updater' === $context ? ! $file_mods_blocked : $allowed;
			},
			10,
			2
		);
		$job = new class( $installed_current ) extends WooPaymentsCutoverReconciliationJob {
			/** @var bool */
			private bool $installed_current;

			/**
			 * @param bool $installed_current Whether the installed extension files carry a supported version.
			 */
			public function __construct( bool $installed_current ) {
				$this->installed_current = $installed_current;
			}

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => WooPaymentsCutoverStateStore::STATE_DEFERRED,
					'deferred_codes'         => array( 'woopayments_plugin_version_unsupported' ),
					'informational_outcomes' => array(),
				);
			}

			/** Report the installed extension version the row sets. */
			public function is_installed_woopayments_plugin_version_supported(): bool {
				return $this->installed_current;
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( 'Switch in progress', $notice );
		if ( null === $warning ) {
			$this->assertStringNotContainsString( 'Update WooPayments', $notice );
		} else {
			$this->assertStringContainsString( 'The switch needs a newer version of the WooPayments extension, but ' . $warning . '. Update WooPayments to continue the switch.', $notice );
		}
	}

	/**
	 * @return array<string,array{bool,bool,string|null}>
	 */
	public function provide_extension_update_warnings(): array {
		return array(
			'file changes disallowed, extension below the floor' => array( true, false, 'this site does not allow automatic plugin updates' ),
			'file changes allowed but the update failed'   => array( false, false, 'it could not be updated automatically' ),
			'file changes allowed, extension at the floor' => array( false, true, null ),
			'file changes disallowed, extension updated by hand' => array( true, true, null ),
		);
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
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => $this->state,
					'informational_outcomes' => array(),
				);
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
			public function refresh_notice_record(): ?array {
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
	 * @testdox The controller sends the merchant start and a site's extension activation into the one reconciliation job; it leaves an activation for every site to the per-site activation controller, and deactivating the extension starts nothing.
	 */
	public function test_controller_routes_the_start_and_the_activation_into_the_job(): void {
		$renamed_plugin_file = 'renamed-woocommerce-payments/woocommerce-payments.php';
		$this->fake_plugin_active( true, false, $renamed_plugin_file );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] */
			public array $sources = array();

			/** @var int */
			public int $activations = 0;

			/**
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}

			/** Count the recorded activations. */
			public function record_plugin_activation(): bool {
				++$this->activations;
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
		};
		$controller = $this->create_cutover_controller( $job );

		$this->assertTrue( $controller->enqueue_merchant_start() );
		$controller->handle_plugin_deactivated( $renamed_plugin_file );
		$controller->handle_plugin_activated( $renamed_plugin_file, true );
		$controller->handle_plugin_activated( $renamed_plugin_file, false );

		$this->assertSame( array( 'merchant' ), $job->sources, 'Deactivating the extension enqueues nothing.' );
		$this->assertSame( 1, $job->activations, 'Only the site activation is recorded.' );
		$this->assertSame( array(), $this->deactivate_plugin_calls );
	}

	/**
	 * A store that ran the extension (its version option, seeded in setUp()) and deactivated it by hand waits for its switch.
	 *
	 * @testdox A store waiting for its switch is offered the switch while the WooPayments feature is on, and nothing while it is off ($_dataName).
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $feature The WooPayments feature option.
	 * @param bool   $offered Whether the switch is offered.
	 */
	public function test_store_waiting_for_its_switch_is_offered_it_only_with_the_feature_on( string $feature, bool $offered ): void {
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$controller = $this->create_cutover_controller();

		if ( $offered ) {
			$this->assertStringContainsString( 'Start the switch', $this->render_admin_notices( $controller ) );
			$this->assertTrue( $controller->enqueue_merchant_start() );
		} else {
			$this->assertSame( '', trim( $this->render_admin_notices( $controller ) ) );
			$this->assertFalse( $controller->enqueue_merchant_start() );
		}
	}

	/**
	 * The switch's version check reads the recorded version, which only a running extension
	 * updates, so a click on such a store would defer forever.
	 *
	 * @testdox A store waiting for its switch that last ran an extension below the switch's minimum is told to activate and update it, and is offered no click.
	 */
	public function test_store_waiting_with_an_old_extension_is_told_to_update_it_first(): void {
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$controller = $this->create_cutover_controller();

		$notices = $this->render_admin_notices( $controller );

		$this->assertTrue( wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_awaiting_switch() );
		$this->assertStringContainsString( 'To switch, first activate the WooPayments extension in Plugins and update it to the latest version.', $notices );
		$this->assertStringNotContainsString( 'Start the switch', $notices );
		$this->assertFalse( $controller->enqueue_merchant_start() );
	}

	/**
	 * Below the minimum, the switch's job updates the extension files, which every site of a network shares.
	 *
	 * @testdox Below the switch's minimum extension version, only a user who may update plugins starts the switch, and others are told to have the extension updated first ($_dataName).
	 * @dataProvider provide_update_rights_around_the_minimum_version
	 *
	 * @param string   $version      The extension version the store last ran.
	 * @param string[] $capabilities The user's capabilities among manage_woocommerce, activate_plugins and update_plugins.
	 * @param bool     $starts       Whether the user starts the switch.
	 */
	public function test_starting_the_switch_below_the_minimum_version_needs_update_rights( string $version, array $capabilities, bool $starts ): void {
		update_option( 'woocommerce_woocommerce_payments_version', $version );
		$this->fake_current_user_capabilities( $capabilities );

		$this->assert_start_offered_to_the_current_user( $starts );
	}

	/**
	 * @return array<string,array{0:string,1:string[],2:bool}>
	 */
	public function provide_update_rights_around_the_minimum_version(): array {
		return array(
			'below the minimum, without update_plugins' => array( '10.4.9', array( 'manage_woocommerce', 'activate_plugins' ), false ),
			'below the minimum, with update_plugins'    => array( '10.4.9', array( 'manage_woocommerce', 'activate_plugins', 'update_plugins' ), true ),
			'at the minimum, without update_plugins'    => array( '10.5.0', array( 'manage_woocommerce', 'activate_plugins' ), true ),
			'above the minimum, without update_plugins' => array( '11.1.0', array( 'manage_woocommerce', 'activate_plugins' ), true ),
		);
	}

	/**
	 * On a multisite whose Network Settings give site admins the Plugins menu, a site administrator may activate plugins but
	 * not update them; only a super admin may (WordPress 7.1.3 wp-includes/capabilities.php:619-635 and 654-666).
	 *
	 * @testdox On a network that gives site admins the Plugins menu, below the switch's minimum extension version a $who sees whether they may start the switch.
	 * @group multisite
	 * @testWith ["site administrator", false]
	 *           ["super admin", true]
	 *
	 * @param string $who    The current user: a site administrator or a super admin.
	 * @param bool   $starts Whether the user starts the switch.
	 */
	public function test_on_a_network_a_super_admin_updates_the_extension_before_the_switch( string $who, bool $starts ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_site_option( 'menu_items', array( 'plugins' => '1' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( 'super admin' === $who ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		$capabilities = array_values( array_filter( array( 'manage_woocommerce', 'activate_plugins', 'update_plugins' ), 'current_user_can' ) );
		$this->assertContains( 'activate_plugins', $capabilities, 'The Plugins menu lets the user activate plugins.' );
		$this->fake_current_user_capabilities( $capabilities );

		try {
			$this->assert_start_offered_to_the_current_user( $starts );
		} finally {
			revoke_super_admin( $user_id );
			wp_set_current_user( 0 );
			delete_site_option( 'menu_items' );
		}
	}

	/**
	 * The old-version rule guards the start action only; the bundled-exclusion notice is information.
	 *
	 * @testdox A store waiting for its switch with an old extension that is excluded for bundled Stripe Billing subscriptions is still told why, with no start button.
	 */
	public function test_old_extension_store_excluded_for_bundled_subscriptions_is_told_why(): void {
		update_option( 'woocommerce_woocommerce_payments_version', '10.4.9' );
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$notices = $this->render_admin_notices( $this->create_cutover_controller( $this->create_excluded_notice_job( 'bundled_stripe_billing_subscriptions_present' ) ) );

		$this->assertStringContainsString( 'this store can&#039;t switch yet. Its subscriptions are billed through Stripe Billing', $notices );
		$this->assertStringNotContainsString( 'Start the switch', $notices );
	}

	/**
	 * The built-in WooPayments writes the extension's gateway ids, so its orders and tokens are not evidence that the extension ran.
	 *
	 * @testdox The activation guard blocks a first-time activation of the WooPayments extension on a store the built-in owns: $_dataName.
	 * @dataProvider provide_first_activation_stores
	 *
	 * @param string $record     The built-in WooPayments record the store holds: none, order or token.
	 * @param string $gateway_id WooPayments gateway identity of that record.
	 */
	public function test_activation_guard_blocks_a_first_activation( string $record, string $gateway_id ): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		if ( 'order' === $record ) {
			$order = wc_create_order();
			$order->set_payment_method( $gateway_id );
			$order->save();
		} elseif ( 'token' === $record ) {
			$this->create_woopayments_token( $gateway_id );
		}
		$this->enable_builtin_runtime_owner();
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

	/**
	 * Stores that never ran the extension: without a payment record, and with built-in WooPayments orders and tokens under
	 * the canonical and a prefixed gateway id.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_first_activation_stores(): array {
		return array(
			'never ran the extension'      => array( 'none', '' ),
			'orders, canonical gateway ID' => array( 'order', 'woocommerce_payments' ),
			'orders, prefixed gateway ID'  => array( 'order', 'woocommerce_payments_card' ),
			'tokens, canonical gateway ID' => array( 'token', 'woocommerce_payments' ),
			'tokens, prefixed gateway ID'  => array( 'token', 'woocommerce_payments_card' ),
		);
	}

	/**
	 * @testdox The activation guard lets the WooPayments extension activate: $_dataName.
	 * @dataProvider provide_allowed_activations
	 *
	 * @param string $store Which allowed activation to arrange.
	 */
	public function test_activation_guard_allows_the_activation( string $store ): void {
		$controller = $this->sut;
		if ( 'recorded version' === $store ) {
			// setUp() records extension version 10.5.0.
			$this->enable_builtin_runtime_owner();
		} elseif ( 'not the owner' === $store ) {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		} elseif ( 'job change' === $store ) {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			$this->enable_builtin_runtime_owner();
			$job        = new class() extends WooPaymentsCutoverReconciliationJob {
				/**
				 * Return whether lifecycle work is job-owned.
				 *
				 * @return bool
				 */
				public function is_internal_plugin_lifecycle_change(): bool {
					return true;
				}
			};
			$controller = $this->create_cutover_controller( $job );
		} elseif ( 'merged feature development' === $store ) {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			$this->enable_builtin_runtime_owner();
			Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );
		}
		$this->fake_wp_die_handler();

		$controller->guard_woopayments_activation();

		$this->assertSame( array(), $this->wp_die_arguments, 'The guard lets the activation through without wp_die().' );
	}

	/**
	 * Activations the guard allows: a store that ran the extension, a store the built-in does not own, the job's own plugin
	 * change, and merged feature development.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function provide_allowed_activations(): array {
		return array(
			'a recorded extension version'       => array( 'recorded version' ),
			'the built-in does not own payments' => array( 'not the owner' ),
			'a plugin change the job makes'      => array( 'job change' ),
			'merged feature development'         => array( 'merged feature development' ),
		);
	}

	/**
	 * @testdox Starting the switch needs manage_woocommerce and activate_plugins, and never starts while the extension is active for every site: $scope with the given capabilities queues $expected.
	 * @dataProvider provider_start_capabilities
	 *
	 * @param string   $scope        Where WooPayments is active: site, or sitewide (for every site).
	 * @param string[] $capabilities Capabilities the user has.
	 * @param string[] $expected     Sources the job is asked to queue.
	 */
	public function test_starting_the_switch_requires_the_capabilities_and_a_site_activation( string $scope, array $capabilities, array $expected ): void {
		$this->fake_plugin_active( 'site' === $scope, 'sitewide' === $scope );
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

		$controller->enqueue_merchant_start();

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
			'sitewide, both capabilities'                  => array( 'sitewide', array( 'manage_woocommerce', 'activate_plugins' ), array() ),
		);
	}

	/**
	 * @testdox With mandatory cutover $label, an admin page on a store still running the extension queues $expected.
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
			/** The extension is active on this site only. */
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
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_START;
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
	 * @testdox A Start the switch click that queues nothing with the built-in WooPayments $label is $outcome.
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
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_START;
		$_GET[ WooPaymentsCutoverController::NONCE_NAME ]   = wp_create_nonce( WooPaymentsCutoverController::NONCE_ACTION );
		$logger = $this->createMock( \WC_Logger_Interface::class );
		$logger->expects( $feature_on ? $this->once() : $this->never() )
			->method( 'error' )
			->with( $this->stringContains( 'switch' ), array( 'source' => 'woopayments-cutover' ) );
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
		$_GET[ WooPaymentsCutoverController::QUERY_ACTION ] = WooPaymentsCutoverController::ACTION_START;
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
	 * @testdox Cutover preflight short-circuits expensive checks while the built-in runtime is disabled.
	 */
	public function test_preflight_short_circuits_when_the_builtin_runtime_is_disabled(): void {
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$failures = $this->preflight_service->get_failures();

		$this->assertSame( array( 'builtin_runtime_disabled' ), $failures, 'Disabled native runtime should be the only preflight result.' );
		$this->assertSame( 0, $this->builtin_provider_readiness_calls, 'Native transport readiness should not be checked while native runtime is disabled.' );
		$this->assertSame( 0, $this->platform_connection_preflight_calls, 'Platform connection preflight should not run while native runtime is disabled.' );
		$this->assertSame( 0, $this->fee_remediation_preflight_calls, 'Financial migration preflight should not run while native runtime is disabled.' );
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
			new WooPaymentsBundledSubscriptionsGuard(),
			$this->fee_remediation_service,
			$this->platform_connection_service,
			$this->rate_account,
			$this->rate_api_client
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
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer a new generation. */
			public function can_offer_start(): bool {
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
			public function refresh_notice_record(): ?array {
				return $this->record;
			}

			/**
			 * Record the dismissal the way the durable record does.
			 *
			 * @param string $notice Notice to dismiss.
			 */
			public function dismiss_notice( string $notice ): bool {
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
	 * Create the job double for a dismissable notice: a completed cutover, or a switch whose connection owner is gone.
	 *
	 * @param string $notice One of the WooPaymentsCutoverReconciliationJob::NOTICE_* constants.
	 * @return WooPaymentsCutoverReconciliationJob&object{dismissed: string[]}
	 */
	private function create_dismissable_notice_job( string $notice ): WooPaymentsCutoverReconciliationJob {
		if ( WooPaymentsCutoverReconciliationJob::NOTICE_SUCCESS === $notice ) {
			return $this->create_completed_notice_job();
		}

		return new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Deferred record that needs a reconnect. */
			private array $record = array(
				'state'                  => WooPaymentsCutoverStateStore::STATE_DEFERRED,
				'current_step'           => 'deferred',
				'deferred_codes'         => array( WooPaymentsPlatformConnectionService::FAILURE_CONNECTION_OWNER_UNAVAILABLE ),
				'informational_outcomes' => array( array( 'code' => 'reconnect_required' ) ),
			);

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return $this->record;
			}

			/**
			 * Record the dismissal the way the durable record does.
			 *
			 * @param string $notice Notice to dismiss.
			 */
			public function dismiss_notice( string $notice ): bool {
				if ( self::NOTICE_RECONNECT !== $notice || ! $this->is_reconnect_notice_due( $this->record ) ) {
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
			public function refresh_notice_record(): ?array {
				return $this->record;
			}

			/**
			 * Record the dismissal the way the durable record does.
			 *
			 * @param string $notice Notice to dismiss.
			 */
			public function dismiss_notice( string $notice ): bool {
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
	 * Arrange a store a manager is offered the switch on: the extension active with the feature on, or a store waiting for
	 * its switch (the extension ran, then was deactivated, the feature on).
	 *
	 * @param string $store "extension active" or "waiting for the switch".
	 */
	private function arrange_switch_offer( string $store ): void {
		$this->fake_plugin_active( 'extension active' === $store );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
	 * Render the notices of a store whose WooPayments extension is active with the feature on, then click the start, and
	 * assert whether the current user is offered and starts the switch or is told to have the extension updated first.
	 *
	 * @param bool $starts Whether the current user starts the switch.
	 */
	private function assert_start_offered_to_the_current_user( bool $starts ): void {
		$this->fake_plugin_active();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$job        = new class() extends WooPaymentsCutoverReconciliationJob {
			/** @var string[] */
			public array $sources = array();

			/** Return no record, as on a store that never started the switch. */
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer a new generation. */
			public function can_offer_start(): bool {
				return true;
			}

			/**
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( $job );

		$notices = $this->render_admin_notices( $controller );
		$controller->enqueue_merchant_start();

		$warning = is_multisite()
			? 'WooPayments is now part of WooCommerce. To switch, a super admin first needs to update the WooPayments extension to the latest version.'
			: 'WooPayments is now part of WooCommerce. To switch, first update the WooPayments extension to the latest version.';
		if ( $starts ) {
			$this->assertStringContainsString( 'Start the switch', $notices );
			$this->assertStringNotContainsString( 'to the latest version', $notices );
			$this->assertSame( array( 'merchant' ), $job->sources );
		} else {
			$this->assertStringContainsString( $warning, $notices );
			$this->assertStringNotContainsString( 'Start the switch', $notices );
			$this->assertSame( array(), $job->sources, 'The click queues nothing.' );
		}
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
		$this->builtin_provider_ready = true;
	}

	/**
	 * Make the native runtime own the current site.
	 */
	private function enable_builtin_runtime_owner(): void {
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
	 * Control the WooPayments plugin active signals.
	 *
	 * @param bool        $site_active    Whether the plugin is active for this site.
	 * @param bool        $sitewide_active Whether the plugin is active for every site.
	 * @param string|null $plugin_file     Plugin file path for nonstandard installs.
	 */
	private function fake_plugin_active( bool $site_active = true, bool $sitewide_active = false, ?string $plugin_file = null ): void {
		$this->plugin_active          = $site_active;
		$this->plugin_sitewide_active = $sitewide_active;
		$this->extension_class_loaded = $site_active || $sitewide_active;
		$entry                        = $plugin_file ?? WooPaymentsRuntimeArbiter::PLUGIN_FILE;

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
						return $this->plugin_sitewide_active ? array( $entry => 1234567890 ) : array();
					}
					return get_site_option( $name, $default_value );
				},
				'is_multisite'       => function () {
					return $this->plugin_sitewide_active || is_multisite();
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
						return $this->extension_class_loaded;
					}
					return class_exists( $class_name, $autoload );
				},
				'defined'            => function ( $constant_name ) {
					if ( 'WCPAY_PLUGIN_FILE' === $constant_name ) {
						return $this->extension_class_loaded;
					}
					return defined( $constant_name );
				},
				'deactivate_plugins' => function ( $plugin, $silent = false, $for_every_site = null ) use ( $entry ) {
					$for_every_site                  = (bool) $for_every_site;
					$this->deactivate_plugin_calls[] = array( (string) $plugin, (bool) $silent, $for_every_site );

					if ( $entry !== (string) $plugin ) {
						return;
					}

					if ( $for_every_site ) {
						$this->plugin_sitewide_active = false;
					} else {
						$this->plugin_active = false;
					}
				},
				'current_user_can'   => fn( $capability ) => in_array( $capability, array( 'manage_woocommerce', 'activate_plugins', 'update_plugins', 'manage_network_plugins' ), true )
					? in_array( $capability, $this->granted_cutover_capabilities, true )
					: current_user_can( $capability ),
			)
		);
	}

	/**
	 * Simulate the next request after the WooPayments plugin was deactivated.
	 */
	private function fake_woopayments_class_unloaded(): void {
		$this->extension_class_loaded = false;
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}

	/**
	 * Control current-user cutover capabilities.
	 *
	 * @param bool $can_cutover Whether the user can perform cutover actions.
	 */
	private function fake_current_user_caps( bool $can_cutover ): void {
		$this->granted_cutover_capabilities = $can_cutover ? array( 'manage_woocommerce', 'activate_plugins' ) : array();
	}

	/**
	 * Grant exactly these cutover-relevant capabilities to the current user.
	 *
	 * @param string[] $capabilities Granted among manage_woocommerce, activate_plugins, update_plugins and manage_network_plugins.
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
