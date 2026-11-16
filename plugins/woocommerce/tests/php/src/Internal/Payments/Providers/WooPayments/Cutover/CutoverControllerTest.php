<?php
/**
 * CutoverController tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\DataMigration;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ExtensionBoundRenewalPlugins;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverActionScheduler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PerSiteActivationController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeAccountAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\MultiCurrency\WooPaymentsNativeApiClientAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\BundledSubscriptionsGuard;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\CutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\ReconciliationJob;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PlatformConnectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;
use WC_Payment_Token_CC;

/**
 * Tests for the WooPayments native cutover controller.
 */
class CutoverControllerTest extends WC_Unit_Test_Case {

	/**
	 * The sentence telling a store that the WooPayments extension runs from outside Plugins.
	 *
	 * @var string
	 */
	private const LOADED_OUTSIDE_PLUGIN_LISTS_SENTENCE = "WooPayments is now part of WooCommerce, but this store can't switch yet. The WooPayments extension is loaded by this site's setup, not activated in Plugins, so the switch can't turn it off. WooPayments keeps running from the extension.";

	/**
	 * The line telling a store manager that the switch is in progress.
	 *
	 * @var string
	 */
	private const IN_PROGRESS_SENTENCE = 'WooPayments is now part of WooCommerce. This store is switching to the built-in WooPayments.';

	/**
	 * The prompt telling a store whose active WooPayments extension is too old to switch from to update it first.
	 *
	 * @var string
	 */
	private const UPDATE_FIRST_SENTENCE = 'WooPayments is now part of WooCommerce. To switch, first update the WooPayments extension to the latest version.';

	/**
	 * The System Under Test.
	 *
	 * @var CutoverController
	 */
	private CutoverController $sut;

	/**
	 * The preflight service the controller under test reads.
	 *
	 * @var PreflightService
	 */
	private PreflightService $preflight_service;

	/**
	 * Native WooPayments provider mock.
	 *
	 * @var WooPaymentsProvider&\PHPUnit\Framework\MockObject\MockObject
	 */
	private WooPaymentsProvider $provider;

	/**
	 * Platform connection readiness service mock.
	 *
	 * @var PlatformConnectionService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private PlatformConnectionService $platform_connection_service;

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
	 * Number of times the platform connection preflight was checked.
	 *
	 * @var int
	 */
	private int $platform_connection_preflight_calls = 0;

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
		update_option( 'woocommerce_woocommerce_payments_version', '10.6.0' );

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

		$this->platform_connection_service = $this->getMockBuilder( PlatformConnectionService::class )
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
		unset( $_GET[ CutoverController::QUERY_ACTION ], $_GET[ CutoverController::NONCE_NAME ], $_GET[ CutoverController::QUERY_NOTICE ], $_GET['page'], $_GET['tab'] );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'woocommerce_woocommerce_payments_version' );
		delete_option( 'active_plugins' );

		remove_all_filters( CutoverController::FILTER_MANDATORY_CUTOVER_ENABLED );
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
	 * @testdox While the WooPayments extension is active for every site, the store is told a super admin first activates WooPayments per site, with no start button, and only a user who may manage network plugins is offered the per-site activation, posting to this site ($_dataName).
	 * @dataProvider provide_refusal_audiences
	 *
	 * @param string[] $capabilities The current user's capabilities among manage_woocommerce, activate_plugins and manage_network_plugins.
	 * @param bool     $offered      Whether the notice offers the per-site activation.
	 */
	public function test_refusal_offers_the_per_site_activation_to_a_super_admin( array $capabilities, bool $offered ): void {
		$this->fake_plugin_active( false, true );
		$this->fake_current_user_capabilities( $capabilities );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class() extends ReconciliationJob {
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
		if ( ! $offered ) {
			$this->assertStringNotContainsString( 'Activate WooPayments per site', $notice );
			return;
		}
		$this->assertSame( 1, preg_match( '/<a class="button button-primary" href="([^"]*)">Activate WooPayments per site<\/a>/', $notice, $matches ), 'The refusal offers the per-site activation.' );
		$href = html_entity_decode( $matches[1] );
		$this->assertStringStartsWith( admin_url( 'plugins.php' ) . '?', $href, 'The button posts to this site.' );
		wp_parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
		$this->assertSame( PerSiteActivationController::ACTION_CONVERT, $query[ PerSiteActivationController::QUERY_ACTION ] ?? null );
		$this->assertSame( 1, wp_verify_nonce( $query[ PerSiteActivationController::NONCE_NAME ] ?? '', PerSiteActivationController::NONCE_ACTION ) );
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
	 * @testdox A switch deferred for the extension being active for every site says why under the in-progress line while it is, and stops once it is not: $scope.
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
		$job = new class() extends ReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'          => StateStore::STATE_DEFERRED,
					'current_step'   => 'deferred',
					'deferred_codes' => array( ReconciliationJob::CODE_PLUGIN_ACTIVE_SITEWIDE ),
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( self::IN_PROGRESS_SENTENCE, $notice );
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
		$job = new class() extends ReconciliationJob {
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
	 * @testdox "Not now" hides the switch notice for 30 days on every admin page but Payments settings, where it stays without the link, and the notice is back with the link once the 30 days have passed ($store).
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
			$this->follow_dismiss_link( $controller, $notices, CutoverController::NOTICE_START_SWITCH );
		} finally {
			remove_filter( 'wp_redirect', '__return_empty_string' );
		}

		$this->assertSame( $now + 30 * DAY_IN_SECONDS, (int) get_option( ReconciliationJob::START_NOTICE_SNOOZED_UNTIL_OPTION ) );
		unset( $_GET[ CutoverController::QUERY_ACTION ], $_GET[ CutoverController::NONCE_NAME ], $_GET[ CutoverController::QUERY_NOTICE ] );
		$this->assertStringNotContainsString( 'Start the switch', $this->render_admin_notices( $controller ), 'Snoozed on other admin pages.' );

		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';
		$settings     = $this->render_admin_notices( $controller );
		$this->assertStringContainsString( 'Start the switch', $settings, 'Payments settings keeps the switch notice.' );
		$this->assertStringNotContainsString( 'Not now', $settings );

		unset( $_GET['page'], $_GET['tab'] );
		$this->register_legacy_proxy_function_mocks( array( 'time' => static fn() => $now + 31 * DAY_IN_SECONDS ) );
		$later = $this->render_admin_notices( $controller );
		$this->assertStringContainsString( 'Start the switch', $later, 'The switch notice is back once the 30 days have passed.' );
		$this->assertStringContainsString( 'Not now', $later );
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
			'completion notice' => array( ReconciliationJob::NOTICE_SUCCESS, 'WooPayments is now built into WooCommerce. Everything works as before.' ),
			'reconnect notice'  => array( ReconciliationJob::NOTICE_RECONNECT, 'The connection owner is no longer available. Reconnect this site to continue the switch.' ),
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

			$this->follow_dismiss_link( $controller, $notices, ReconciliationJob::NOTICE_BUNDLED_EXCLUSION );
			$this->assertSame( array( ReconciliationJob::NOTICE_BUNDLED_EXCLUSION ), $job->dismissed );
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
			ReconciliationJob::ADMIN_CLASSIFICATION_OPTION,
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

		$this->assertSame( $expected_cached, is_array( get_option( ReconciliationJob::ADMIN_CLASSIFICATION_OPTION ) ) );
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
		$job                                     = $this->create_dismissable_notice_job( $notice );
		$controller                              = $this->create_cutover_controller( $job );
		$_GET[ CutoverController::QUERY_ACTION ] = CutoverController::ACTION_DISMISS_NOTICE;
		$_GET[ CutoverController::QUERY_NOTICE ] = $notice;
		$_GET[ CutoverController::NONCE_NAME ]   = wp_create_nonce( CutoverController::NONCE_ACTION );
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
		$job = new class() extends ReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => StateStore::STATE_DEFERRED,
					'deferred_codes'         => array( 'wpcom_connection_unavailable', 'wpcom_blog_id_unavailable', 'wpcom_connection_owner_unavailable' ),
					'informational_outcomes' => array( array( 'code' => 'reconnect_required' ) ),
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringContainsString( self::IN_PROGRESS_SENTENCE, $notice );
		$this->assertStringNotContainsString( 'Reconnect this site', $notice );
	}

	/**
	 * An mu-plugin or a host loader can include the extension with no entry in the plugin lists; the switch's deactivation
	 * could not turn it off, so the switch is not offered there.
	 *
	 * @testdox A store whose WooPayments extension runs from outside Plugins is told why it cannot switch instead of being offered the switch, and a Start click queues nothing.
	 */
	public function test_extension_loaded_outside_the_plugin_lists_is_not_offered_the_switch(): void {
		$this->arrange_switch_offer( 'extension active' );
		update_option( 'active_plugins', array() );
		$job = $this->create_start_recording_job();

		$controller = $this->create_cutover_controller( $job );
		$notices    = $this->render_admin_notices( $controller );

		$this->assertStringContainsString( esc_html( self::LOADED_OUTSIDE_PLUGIN_LISTS_SENTENCE ), $notices );
		$this->assertStringNotContainsString( 'Start the switch', $notices, 'The switch is not offered.' );
		$this->assertStringContainsString( 'Not now', $notices, 'The notice can be hidden as the switch notice can.' );
		$this->assertFalse( $controller->enqueue_merchant_start() );
		$this->assertSame( array(), $job->sources, 'A hand-made Start link queues nothing.' );

		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		$notices = $this->render_admin_notices( $controller );

		$this->assertStringContainsString( 'Start the switch', $notices, 'An extension activated in Plugins is offered the switch.' );
		$this->assertStringNotContainsString( esc_html( self::LOADED_OUTSIDE_PLUGIN_LISTS_SENTENCE ), $notices );
	}

	/**
	 * Create a job double that offers the start and records each start it is asked for.
	 *
	 * @return ReconciliationJob&object{sources: string[]}
	 */
	private function create_start_recording_job(): ReconciliationJob {
		return new class() extends ReconciliationJob {
			/** @var string[] Sources of the starts asked for. */
			public array $sources = array();

			/** No durable record. */
			public function refresh_notice_record(): ?array {
				return null;
			}

			/** Offer a new generation. */
			public function can_offer_start(): bool {
				return true;
			}

			/**
			 * Record a start.
			 *
			 * @param string $source Trigger source.
			 */
			public function enqueue( string $source ): bool {
				$this->sources[] = $source;
				return true;
			}

			/** An external lifecycle event. */
			public function is_internal_plugin_lifecycle_change(): bool {
				return false;
			}
		};
	}

	/**
	 * @testdox Under the in-progress line, a switch waiting for scheduled WooPayments tasks past the short retry says so, with a link to them ($_dataName).
	 * @dataProvider provide_queued_action_waits
	 *
	 * @param string[]    $codes     The deferral's codes.
	 * @param int         $delay     Seconds from the deferral to the next attempt.
	 * @param string[]    $hooks     Queued WooPayments hooks the built-in does not handle.
	 * @param string|null $link_hook The hook the link filters on; null for no line, '' for an unfiltered link.
	 */
	public function test_switch_waiting_for_queued_tasks_says_so_past_the_short_retry( array $codes, int $delay, array $hooks, ?string $link_hook ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		foreach ( $hooks as $hook ) {
			$this->assertIsInt( as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, array(), 'woocommerce-payments' ) );
		}
		$deferred_at = time() - 30;
		$record      = array(
			'state'                  => StateStore::STATE_DEFERRED,
			'deferred_codes'         => $codes,
			'informational_outcomes' => array(),
			'next_attempt_at'        => $deferred_at + $delay,
			// An earlier deferral at the cadence comes first; the last one decides.
			'step_log'               => array(
				array(
					'step'    => 'deferred',
					'at'      => $deferred_at - 15 * MINUTE_IN_SECONDS,
					'context' => array( 'codes' => $codes ),
				),
				array(
					'step' => 'running',
					'at'   => $deferred_at,
				),
				array(
					'step'    => 'deferred',
					'at'      => $deferred_at,
					'context' => array( 'codes' => $codes ),
				),
			),
		);
		$job = new class( $record ) extends ReconciliationJob {
			/** @var array<string,mixed> */
			private array $record;

			/**
			 * @param array<string,mixed> $record The record the notice renders.
			 */
			public function __construct( array $record ) {
				$this->record = $record;
			}

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return $this->record;
			}
		};

		try {
			$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );
		} finally {
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook );
			}
		}

		$this->assertStringContainsString( self::IN_PROGRESS_SENTENCE, $notice );
		$sentence = 'The switch to the built-in WooPayments is waiting for scheduled WooPayments tasks to finish. It continues on its own once they have run.';
		if ( null === $link_hook ) {
			$this->assertStringNotContainsString( $sentence, $notice );
			return;
		}
		$this->assertStringContainsString( $sentence, $notice );
		$this->assertSame( 1, preg_match( '/<a href="([^"]*)">View scheduled tasks<\/a>/', $notice, $matches ) );
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'wc-status', $query['page'] ?? null );
		$this->assertSame( 'action-scheduler', $query['tab'] ?? null );
		$this->assertSame( 'pending', $query['status'] ?? null );
		$this->assertSame( $link_hook, $query['s'] ?? '' );
	}

	/**
	 * @testdox A switch waiting for scheduled WooPayments tasks on a queue that could not be read links to every pending task.
	 */
	public function test_queued_tasks_link_is_unfiltered_when_the_queue_could_not_be_read(): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$deferred_at = time();
		$job         = new class( $deferred_at ) extends ReconciliationJob {
			/** @var int */
			private int $deferred_at;

			/**
			 * @param int $deferred_at When the switch deferred.
			 */
			public function __construct( int $deferred_at ) {
				$this->deferred_at = $deferred_at;
			}

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => StateStore::STATE_DEFERRED,
					'deferred_codes'         => array( PreflightService::FAILURE_QUEUE_HOOKS_UNHANDLED ),
					'informational_outcomes' => array(),
					'next_attempt_at'        => $this->deferred_at + 15 * MINUTE_IN_SECONDS,
					'step_log'               => array(
						array(
							'step' => 'deferred',
							'at'   => $this->deferred_at,
						),
					),
				);
			}
		};
		$preflight   = $this->create_preflight_service(
			new class() extends PreflightService {
				/** @return array<int,array{action_id:int,hook:string,group:string,due_at:int|null,earliest_due_at:int|null}> */
				public function get_unhandled_queue_actions(): array {
					return array(
						array(
							'action_id'       => 0,
							'hook'            => 'woocommerce_woopayments_operational_queue_query_failed',
							'group'           => '',
							'due_at'          => null,
							'earliest_due_at' => null,
						),
					);
				}
			}
		);

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job, $preflight ) );

		$this->assertSame( 1, preg_match( '/<a href="([^"]*)">View scheduled tasks<\/a>/', $notice, $matches ) );
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'pending', $query['status'] ?? null );
		$this->assertArrayNotHasKey( 's', $query );
	}

	/**
	 * @return array<string,array{string[],int,string[],string|null}>
	 */
	public function provide_queued_action_waits(): array {
		$code = PreflightService::FAILURE_QUEUE_HOOKS_UNHANDLED;
		return array(
			'past the short retry, one hook'  => array( array( $code ), 15 * MINUTE_IN_SECONDS, array( 'wcpay_check_affected_auth_fee_orders' ), 'wcpay_check_affected_auth_fee_orders' ),
			'past the short retry, two hooks' => array( array( $code ), 15 * MINUTE_IN_SECONDS, array( 'wcpay_check_affected_auth_fee_orders', 'wcpay_controller_test_other' ), '' ),
			'within the short retry'          => array( array( $code ), MINUTE_IN_SECONDS, array( 'wcpay_check_affected_auth_fee_orders' ), null ),
			'deferred for another reason'     => array( array( PreflightService::FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE ), 15 * MINUTE_IN_SECONDS, array( 'wcpay_check_affected_auth_fee_orders' ), null ),
			'queued tasks and another reason' => array( array( $code, PreflightService::FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE ), 15 * MINUTE_IN_SECONDS, array( 'wcpay_check_affected_auth_fee_orders' ), 'wcpay_check_affected_auth_fee_orders' ),
		);
	}

	/**
	 * @testdox A plugin deactivation brings forward a switch held back by a renewal plugin.
	 */
	public function test_plugin_events_bring_the_waiting_switch_forward(): void {
		$job        = new class() extends ReconciliationJob {
			/** @var string[] Codes the controller asked to bring forward. */
			public array $codes = array();

			/**
			 * Record the code.
			 *
			 * @param string|null $code Deferral code, or null for an admin page.
			 */
			public function bring_deferred_attempt_forward( ?string $code = null ): bool {
				$this->codes[] = $code;
				return true;
			}
		};
		$controller = $this->create_cutover_controller( $job );

		$controller->handle_plugin_deactivated( 'yith-woocommerce-subscription-premium/init.php' );

		$this->assertSame( array( PreflightService::FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE ), $job->codes );
	}

	/**
	 * @testdox Only a deferred switch on a store manager's admin page asks the job to bring its next attempt forward: $_dataName.
	 * @dataProvider provide_admin_page_bring_forward_calls
	 *
	 * @param string $state        The record's state.
	 * @param string $current_step The record's current step.
	 * @param bool   $can_manage   Whether the user may manage WooCommerce.
	 * @param bool   $expected     Whether the page asks the job, with no code.
	 */
	public function test_only_a_deferred_switch_on_a_managers_page_is_brought_forward( string $state, string $current_step, bool $can_manage, bool $expected ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( $can_manage );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class( $state, $current_step ) extends ReconciliationJob {
			/** @var array<int,string|null> Codes the page asked to bring forward. */
			public array $codes = array();

			/** @var array<string,mixed> */
			private array $record;

			/**
			 * @param string $state        The record's state.
			 * @param string $current_step The record's current step.
			 */
			public function __construct( string $state, string $current_step ) {
				$this->record = array(
					'state'                  => $state,
					'current_step'           => $current_step,
					'deferred_codes'         => array( PreflightService::FAILURE_BUILTIN_TRANSPORT_UNAVAILABLE ),
					'informational_outcomes' => array(),
					'next_attempt_at'        => time() + 15 * MINUTE_IN_SECONDS,
					'step_log'               => array(),
				);
			}

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return $this->record;
			}

			/**
			 * Record the call.
			 *
			 * @param string|null $code Deferral code, or null for an admin page.
			 */
			public function bring_deferred_attempt_forward( ?string $code = null ): bool {
				$this->codes[] = $code;
				return true;
			}
		};

		$this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertSame( $expected ? array( null ) : array(), $job->codes );
	}

	/**
	 * @return array<string,array{string,string,bool,bool}>
	 */
	public function provide_admin_page_bring_forward_calls(): array {
		return array(
			'deferred'                              => array( StateStore::STATE_DEFERRED, 'deferred', true, true ),
			'deferred, a user who cannot manage it' => array( StateStore::STATE_DEFERRED, 'deferred', false, false ),
			'queued'                                => array( StateStore::STATE_PENDING, 'queued', true, false ),
			'the ownership check pending'           => array( StateStore::STATE_PENDING, 'verify_builtin_ownership', true, false ),
			'running'                               => array( StateStore::STATE_RUNNING, 'running', true, false ),
			'awaiting the merchant start'           => array( StateStore::STATE_PENDING, 'awaiting_merchant_start', true, false ),
			'done'                                  => array( StateStore::STATE_DONE, 'done', true, false ),
			'excluded'                              => array( StateStore::STATE_EXCLUDED, 'excluded', true, false ),
		);
	}

	/**
	 * @testdox A switch waiting for tasks queued far ahead shows the waiting line on the admin page that moves its next attempt and on the next page ($_dataName).
	 * @testWith ["past the floor", 61]
	 *           ["inside the floor", 30]
	 *
	 * @param string $label Where the page lands.
	 * @param int    $age   Seconds since the deferral.
	 */
	public function test_waiting_line_shows_on_the_page_that_moves_the_switch_and_the_next( string $label, int $age ): void {
		unset( $label );
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->assertIsInt( as_schedule_single_action( time() + HOUR_IN_SECONDS, 'wcpay_check_affected_auth_fee_orders', array(), 'woocommerce-payments' ) );
		$state_store       = wc_get_container()->get( StateStore::class );
		$scheduler         = new RecordingCutoverActionScheduler( $state_store );
		$preflight_service = $this->create_preflight_service();
		$job               = new ReconciliationJob();
		$job->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), $state_store, $scheduler, $preflight_service, wc_get_container()->get( DataMigration::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		$this->assertTrue( $job->enqueue( ReconciliationJob::SOURCE_MERCHANT ) );
		$queued = $state_store->get_record();
		$this->assertIsArray( $queued );
		$scheduler->cancel( $queued['generation'], 1 );
		$deferred_at                 = time() - $age;
		$deferred                    = $queued;
		$deferred['revision']        = $queued['revision'] + 1;
		$deferred['state']           = StateStore::STATE_DEFERRED;
		$deferred['current_step']    = 'deferred';
		$deferred['attempt']         = 1;
		$deferred['deferred_codes']  = array( PreflightService::FAILURE_QUEUE_HOOKS_UNHANDLED );
		$deferred['updated_at']      = $deferred_at;
		$deferred['step_log'][]      = array(
			'step'    => 'deferred',
			'at'      => $deferred_at,
			'context' => array( 'codes' => $deferred['deferred_codes'] ),
		);
		$deferred['next_attempt_at'] = $deferred_at + 15 * MINUTE_IN_SECONDS;
		$deferred['action_id']       = $scheduler->schedule( $deferred['next_attempt_at'], $queued['generation'], 2 );
		$this->assertTrue( $state_store->compare_and_set_record( $queued, $deferred ) );
		$controller = $this->create_cutover_controller( $job, $preflight_service );
		$sentence   = 'The switch to the built-in WooPayments is waiting for scheduled WooPayments tasks to finish.';

		try {
			$moving_page = $this->render_admin_notices( $controller );
			$moved       = $state_store->get_record();
			$next_page   = $this->render_admin_notices( $controller );
		} finally {
			as_unschedule_all_actions( 'wcpay_check_affected_auth_fee_orders' );
		}

		$this->assertIsArray( $moved );
		$this->assertLessThan( $deferred['next_attempt_at'], $moved['next_attempt_at'], 'The page moved the next attempt.' );
		$this->assertStringContainsString( $sentence, $moving_page );
		$this->assertStringContainsString( $sentence, $next_page );
	}

	/**
	 * @testdox While a plugin that renews through the WooPayments extension is active, the switch notice names it and says why the store cannot switch instead of offering the switch ($_dataName).
	 * @dataProvider provide_renewal_plugin_holdback_offers
	 *
	 * @param string      $store    "extension active" or "waiting for the switch".
	 * @param string[]    $names    Names of the active plugins that hold the switch back.
	 * @param string|null $expected The notice text, or null when the switch is offered.
	 */
	public function test_switch_offer_names_the_plugin_that_holds_it_back( string $store, array $names, ?string $expected ): void {
		$this->arrange_switch_offer( $store );

		$notices = $this->render_admin_notices( $this->create_cutover_controller( null, null, $this->create_renewal_plugins( array_combine( $names, $names ) ) ) );

		if ( null === $expected ) {
			$this->assertStringContainsString( 'Start the switch', $notices );
			$this->assertStringNotContainsString( 'renewals and scheduled payments', $notices );
			return;
		}
		$this->assertStringContainsString( esc_html( $expected ), $notices );
		$this->assertStringNotContainsString( 'Start the switch', $notices, 'A held-back switch is not offered.' );
		$this->assertStringContainsString( 'Not now', $notices, 'The notice can be hidden as the switch notice can.' );
	}

	/**
	 * @return array<string,array{string,string[],string|null}>
	 */
	public function provide_renewal_plugin_holdback_offers(): array {
		$waits = "WooPayments is now part of WooCommerce, but this store can't switch yet. YITH WooCommerce Subscription Premium charges renewals and scheduled payments through the WooPayments extension, so the switch waits while it is active.";
		return array(
			'extension active, one plugin'       => array( 'extension active', array( 'YITH WooCommerce Subscription Premium' ), $waits . ' Until then nothing changes and WooPayments keeps running from the WooPayments extension.' ),
			'waiting for the switch, one plugin' => array( 'waiting for the switch', array( 'YITH WooCommerce Subscription Premium' ), $waits . ' Until then, activate the WooPayments extension again to keep taking payments.' ),
			'extension active, two plugins'      => array( 'extension active', array( 'YITH WooCommerce Subscription Premium', 'Ultimate Payment Plans' ), "WooPayments is now part of WooCommerce, but this store can't switch yet. YITH WooCommerce Subscription Premium and Ultimate Payment Plans charge renewals and scheduled payments through the WooPayments extension, so the switch waits while they are active." ),
			'extension active, no such plugin'   => array( 'extension active', array(), null ),
		);
	}

	/**
	 * @testdox A switch deferred by a plugin that renews through the WooPayments extension says why under the in-progress line while the plugin is active, and stops once it is not ($_dataName).
	 * @dataProvider provide_deferred_renewal_plugin_holdbacks
	 *
	 * @param string   $code  The deferral code on the record.
	 * @param string[] $names Names of the active plugins that hold the switch back.
	 * @param bool     $shown Whether the notice says why.
	 */
	public function test_deferred_switch_names_the_plugin_that_holds_it_back( string $code, array $names, bool $shown ): void {
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job = new class( $code ) extends ReconciliationJob {
			/** @var string */
			private string $code;

			/**
			 * @param string $code The deferral code on the record.
			 */
			public function __construct( string $code ) {
				$this->code = $code;
			}

			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'                  => StateStore::STATE_DEFERRED,
					'deferred_codes'         => array( $this->code ),
					'informational_outcomes' => array(),
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job, null, $this->create_renewal_plugins( array_combine( $names, $names ) ) ) );

		$this->assertStringContainsString( self::IN_PROGRESS_SENTENCE, $notice );
		if ( $shown ) {
			$this->assertStringContainsString( esc_html( "WooPayments is now part of WooCommerce, but this store can't switch yet. Order Subscriptions for WooCommerce charges renewals and scheduled payments through the WooPayments extension, so the switch waits while it is active. Until then nothing changes and WooPayments keeps running from the WooPayments extension." ), $notice );
			$this->assertStringNotContainsString( 'Not now', $notice );
		} else {
			$this->assertStringNotContainsString( 'renewals and scheduled payments', $notice );
		}
	}

	/**
	 * @return array<string,array{string,string[],bool}>
	 */
	public function provide_deferred_renewal_plugin_holdbacks(): array {
		return array(
			'held back, plugin active'         => array( PreflightService::FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE, array( 'Order Subscriptions for WooCommerce' ), true ),
			'held back, plugin since inactive' => array( PreflightService::FAILURE_EXTENSION_RENEWAL_PLUGIN_ACTIVE, array(), false ),
			'deferred for another reason'      => array( 'operational_queue_hooks_unhandled', array( 'Order Subscriptions for WooCommerce' ), false ),
		);
	}

	/**
	 * The listed plugins charge renewals or installments through the extension's own classes, which the built-in
	 * WooPayments does not declare (Order Subscriptions 1.2.15 and Ultimate Payment Plans 4.5.0 build
	 * WCPay\Payment_Information; YITH 4.26.3 class-ywsbs-wc-payments.php extends WC_Payment_Gateway_WCPay).
	 *
	 * @testdox On a store the built-in WooPayments owns, a warning names the active plugins whose renewals need the WooPayments extension ($_dataName).
	 * @dataProvider provide_built_in_store_renewal_plugins
	 *
	 * @param array<string,string> $plugins  Active renewal plugin names by plugin file.
	 * @param string|null          $expected The warning text, or null when there is none.
	 */
	public function test_built_in_store_names_the_active_renewal_plugins( array $plugins, ?string $expected ): void {
		$this->arrange_builtin_store();

		$notices = $this->render_admin_notices( $this->create_cutover_controller( null, null, $this->create_renewal_plugins( $plugins ) ) );

		if ( null === $expected ) {
			$this->assertStringNotContainsString( 'need the WooPayments extension', $notices );
			return;
		}
		$this->assertStringContainsString( esc_html( $expected ), $notices );
		$this->assertStringContainsString( CutoverController::QUERY_NOTICE . '=' . CutoverController::NOTICE_RENEWAL_PLUGINS, $notices, 'The warning can be dismissed.' );
	}

	/**
	 * @return array<string,array{array<string,string>,string|null}>
	 */
	public function provide_built_in_store_renewal_plugins(): array {
		return array(
			'one plugin'  => array(
				array( 'order-subscriptions/order-subscriptions.php' => 'Order Subscriptions for WooCommerce' ),
				"Renewal and installment payments set up with Order Subscriptions for WooCommerce need the WooPayments extension. WooPayments is built into WooCommerce on this store, so it can't charge them.",
			),
			'two plugins' => array(
				array(
					'yith-woocommerce-subscription-premium/init.php' => 'YITH WooCommerce Subscription Premium',
					'ultimate-payment-plans/ultimate-payment-plans.php' => 'Ultimate Payment Plans',
				),
				"Renewal and installment payments set up with YITH WooCommerce Subscription Premium and Ultimate Payment Plans need the WooPayments extension. WooPayments is built into WooCommerce on this store, so it can't charge them.",
			),
			'none active' => array( array(), null ),
		);
	}

	/**
	 * @testdox No renewal plugins warning shows on a store the built-in WooPayments does not own; the switch notice says why it waits instead ($store).
	 * @testWith ["extension active"]
	 *           ["waiting for the switch"]
	 *
	 * @param string $store Which store offers the switch.
	 */
	public function test_renewal_plugins_warning_shows_only_on_built_in_stores( string $store ): void {
		$this->arrange_switch_offer( $store );

		$notices = $this->render_admin_notices( $this->create_cutover_controller( null, null, $this->create_renewal_plugins( array( 'order-subscriptions/order-subscriptions.php' => 'Order Subscriptions for WooCommerce' ) ) ) );

		$this->assertStringNotContainsString( 'need the WooPayments extension', $notices );
		$this->assertStringContainsString( 'Order Subscriptions for WooCommerce charges renewals and scheduled payments', $notices, 'The switch notice names the plugin.' );
	}

	/**
	 * @testdox The renewal plugins warning stays off Payments settings, which keep only the compliance and switch notices.
	 */
	public function test_renewal_plugins_warning_is_not_shown_on_payments_settings(): void {
		$this->arrange_builtin_store();
		$controller = $this->create_cutover_controller( null, null, $this->create_renewal_plugins( array( 'order-subscriptions/order-subscriptions.php' => 'Order Subscriptions for WooCommerce' ) ) );
		$this->assertStringContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ), 'Shown on other admin pages.' );

		$_GET['page'] = 'wc-settings';
		$_GET['tab']  = 'checkout';

		$this->assertStringNotContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ) );
	}

	/**
	 * @testdox A dismissed renewal plugins warning is stored for its plugin files and stays hidden while the same plugins are active, in any order, shows again once the set of active renewal plugins changes, and is gone once none is active, which deletes the dismissal so the last dismissed plugin's warning shows again when it is active.
	 */
	public function test_dismissed_renewal_plugins_warning_shows_again_when_the_plugins_change(): void {
		$this->arrange_builtin_store();
		$renewal_plugins = $this->create_renewal_plugins(
			array(
				'ultimate-payment-plans/ultimate-payment-plans.php' => 'Ultimate Payment Plans',
				'order-subscriptions/order-subscriptions.php'       => 'Order Subscriptions for WooCommerce',
			)
		);
		$controller      = $this->create_cutover_controller( null, null, $renewal_plugins );
		$this->register_exit_mock( static fn() => null );
		add_filter( 'wp_redirect', '__return_empty_string' );

		try {
			$this->follow_dismiss_link( $controller, $this->render_admin_notices( $controller ), CutoverController::NOTICE_RENEWAL_PLUGINS );
			unset( $_GET[ CutoverController::QUERY_ACTION ], $_GET[ CutoverController::NONCE_NAME ], $_GET[ CutoverController::QUERY_NOTICE ] );
			$this->assertStringNotContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ), 'Dismissed for the same plugins.' );

			$renewal_plugins->plugins = array_reverse( $renewal_plugins->plugins, true );
			$this->assertStringNotContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ), 'The same plugins in another order.' );

			unset( $renewal_plugins->plugins['order-subscriptions/order-subscriptions.php'] );
			$this->assertStringContainsString( 'set up with Ultimate Payment Plans need the WooPayments extension', $this->render_admin_notices( $controller ), 'Shown again once a plugin is no longer active.' );

			$this->follow_dismiss_link( $controller, $this->render_admin_notices( $controller ), CutoverController::NOTICE_RENEWAL_PLUGINS );
			unset( $_GET[ CutoverController::QUERY_ACTION ], $_GET[ CutoverController::NONCE_NAME ], $_GET[ CutoverController::QUERY_NOTICE ] );
			$this->assertStringNotContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ), 'Dismissed again for the smaller set.' );
			$this->assertSame( array( 'ultimate-payment-plans/ultimate-payment-plans.php' ), get_option( 'woocommerce_woopayments_renewal_plugins_notice_dismissed' ), 'The dismissal is kept for the plugin.' );

			$renewal_plugins->plugins['deposit-and-partial-payment-plan/deposit-and-partial-payment-plan.php'] = 'Deposit and Partial Payment Plan';
			$this->assertStringContainsString( 'set up with Ultimate Payment Plans and Deposit and Partial Payment Plan need the WooPayments extension', $this->render_admin_notices( $controller ), 'Shown again once another plugin is active.' );

			$renewal_plugins->plugins = array();
			$this->assertStringNotContainsString( 'need the WooPayments extension', $this->render_admin_notices( $controller ), 'Nothing to warn about once none is active.' );
			$this->assertFalse( get_option( 'woocommerce_woopayments_renewal_plugins_notice_dismissed' ), 'An admin page with no listed plugin active deletes the dismissal.' );

			$renewal_plugins->plugins = array( 'ultimate-payment-plans/ultimate-payment-plans.php' => 'Ultimate Payment Plans' );
			$this->assertStringContainsString( 'set up with Ultimate Payment Plans need the WooPayments extension', $this->render_admin_notices( $controller ), 'Shown again for the last dismissed plugin once the dismissal is deleted.' );
		} finally {
			remove_filter( 'wp_redirect', '__return_empty_string' );
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
		$job = new class( $state ) extends ReconciliationJob {
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

		$this->assertStringContainsString( self::IN_PROGRESS_SENTENCE, $notice );
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
		$job = new class() extends ReconciliationJob {
			/** @return array<string,mixed>|null */
			public function refresh_notice_record(): ?array {
				return array(
					'state'        => StateStore::STATE_PENDING,
					'current_step' => 'awaiting_merchant_start',
				);
			}
		};

		$notice = $this->render_admin_notices( $this->create_cutover_controller( $job ) );

		$this->assertStringNotContainsString( 'Start the switch', $notice );
		$this->assertStringNotContainsString( self::IN_PROGRESS_SENTENCE, $notice );
	}

	/**
	 * @testdox The controller sends the merchant start and a site's extension activation into the one reconciliation job; it leaves an activation for every site to the per-site activation controller, and deactivating the extension starts nothing.
	 */
	public function test_controller_routes_the_start_and_the_activation_into_the_job(): void {
		$renamed_plugin_file = 'renamed-woocommerce-payments/woocommerce-payments.php';
		$this->fake_plugin_active( true, false, $renamed_plugin_file );
		$this->fake_current_user_caps( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$job        = new class() extends ReconciliationJob {
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
			 * No switch waits here.
			 *
			 * @param string|null $code Deferral code, or null for an admin page.
			 */
			public function bring_deferred_attempt_forward( ?string $code = null ): bool {
				unset( $code );
				return false;
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
	 * The switch never updates the extension, so below its minimum version no user is offered the switch, whatever they may
	 * update, and the store is told to update it first. One row per start state of the switch's start rule.
	 *
	 * @testdox A store manager sees the notice the state names, and is offered and starts the switch only at or above the minimum extension version ($_dataName).
	 * @dataProvider provide_start_states
	 *
	 * @param string   $version      The extension version the store last ran.
	 * @param string   $store        The extension's state: active, deactivated, network active, loaded outside, excluded or feature off.
	 * @param string[] $capabilities The user's capabilities among manage_woocommerce, activate_plugins and update_plugins.
	 * @param string   $notice       A sentence the notices show, or an empty string for no notice at all.
	 * @param bool     $starts       Whether the user is offered the switch and a Start click queues it.
	 */
	public function test_start_state_decides_the_notice_and_the_start( string $version, string $store, array $capabilities, string $notice, bool $starts ): void {
		update_option( 'woocommerce_woocommerce_payments_version', $version );
		$this->fake_plugin_active( in_array( $store, array( 'active', 'loaded outside' ), true ), 'network active' === $store );
		if ( 'loaded outside' === $store ) {
			// The extension's code runs with no entry in the plugin lists.
			update_option( 'active_plugins', array() );
		}
		$this->fake_current_user_capabilities( $capabilities );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'feature off' === $store ? 'no' : 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$job        = 'excluded' === $store ? $this->create_excluded_notice_job( PreflightService::FAILURE_BUNDLED_SUBSCRIPTIONS ) : $this->create_start_recording_job();
		$controller = $this->create_cutover_controller( $job );

		$notices = $this->render_admin_notices( $controller );
		$queued  = $controller->enqueue_merchant_start();

		if ( '' === $notice ) {
			$this->assertSame( '', trim( $notices ) );
		} else {
			$this->assertStringContainsString( esc_html( $notice ), $notices );
		}
		if ( $starts ) {
			$this->assertStringContainsString( 'Start the switch', $notices );
		} else {
			$this->assertStringNotContainsString( 'Start the switch', $notices );
		}
		$this->assertSame( $starts, $queued );
	}

	/**
	 * Start states of the switch on a single site: the extension version the store last ran, the extension's state, the
	 * user's capabilities, a sentence the notices show and whether the user starts the switch.
	 *
	 * @return array<string,array{string,string,string[],string,bool}>
	 */
	public function provide_start_states(): array {
		$manager  = array( 'manage_woocommerce', 'activate_plugins' );
		$updater  = array( 'manage_woocommerce', 'activate_plugins', 'update_plugins' );
		$activate = 'WooPayments is now part of WooCommerce. To switch, first activate the WooPayments extension in Plugins and update it to the latest version.';
		$sitewide = "This store can't switch yet because the WooPayments extension is active for every site of the network.";
		$excluded = 'Its subscriptions are billed through Stripe Billing';

		return array(
			'at the minimum, active'                       => array( '10.6.0', 'active', $manager, 'Start the switch', true ),
			'above the minimum, active'                    => array( '11.1.0', 'active', $manager, 'Start the switch', true ),
			'at the minimum, waiting for the switch'       => array( '10.6.0', 'deactivated', $manager, 'Start the switch', true ),
			'below, active, a user who may update plugins' => array( '10.5.2', 'active', $updater, self::UPDATE_FIRST_SENTENCE, false ),
			'below, active, a user who may not update plugins' => array( '10.5.2', 'active', $manager, self::UPDATE_FIRST_SENTENCE, false ),
			'below, waiting for the switch'                => array( '10.5.2', 'deactivated', $updater, $activate, false ),
			'below, active for every site'                 => array( '10.5.2', 'network active', $updater, $sitewide, false ),
			'below, loaded outside the plugin lists'       => array( '10.5.2', 'loaded outside', $updater, self::UPDATE_FIRST_SENTENCE, false ),
			'at the minimum, loaded outside the plugin lists' => array( '10.6.0', 'loaded outside', $updater, self::LOADED_OUTSIDE_PLUGIN_LISTS_SENTENCE, false ),
			'below, excluded for bundled subscriptions'    => array( '10.5.2', 'excluded', $updater, $excluded, false ),
			'below, a shop manager'                        => array( '10.5.2', 'active', array( 'manage_woocommerce' ), '', false ),
			'below, the WooPayments feature off'           => array( '10.5.2', 'feature off', $updater, '', false ),
		);
	}

	/**
	 * On a multisite whose Network Settings give site admins the Plugins menu, a site administrator may activate plugins but
	 * not update them, and a super admin may do both (WordPress 7.1.3 wp-includes/capabilities.php:619-635 and 654-666).
	 * Below the minimum version neither starts the switch, since the switch never updates the extension.
	 *
	 * @testdox On a network that gives site admins the Plugins menu, below the switch's minimum extension version a $who is told a super admin first updates the extension, and a Start click queues nothing.
	 * @group multisite
	 * @testWith ["site administrator"]
	 *           ["super admin"]
	 *
	 * @param string $who The current user: a site administrator or a super admin.
	 */
	public function test_on_a_network_below_the_minimum_no_user_starts_the_switch( string $who ): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		update_site_option( 'menu_items', array( 'plugins' => '1' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( 'super admin' === $who ) {
			grant_super_admin( $user_id );
		}
		wp_set_current_user( $user_id );
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.2' );
		$capabilities = array_values( array_filter( array( 'manage_woocommerce', 'activate_plugins', 'update_plugins' ), 'current_user_can' ) );
		$this->assertContains( 'activate_plugins', $capabilities, 'The Plugins menu lets the user activate plugins.' );
		$this->fake_current_user_capabilities( $capabilities );
		$this->fake_plugin_active();
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		$job        = $this->create_start_recording_job();
		$controller = $this->create_cutover_controller( $job );

		try {
			$notices = $this->render_admin_notices( $controller );
			$queued  = $controller->enqueue_merchant_start();
		} finally {
			revoke_super_admin( $user_id );
			wp_set_current_user( 0 );
			delete_site_option( 'menu_items' );
		}

		$this->assertStringContainsString( 'WooPayments is now part of WooCommerce. To switch, a super admin first needs to update the WooPayments extension to the latest version.', $notices );
		$this->assertStringNotContainsString( 'Start the switch', $notices );
		$this->assertFalse( $queued );
		$this->assertSame( array(), $job->sources, 'The click queues nothing.' );
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
			// setUp() records extension version 10.6.0.
			$this->enable_builtin_runtime_owner();
		} elseif ( 'not the owner' === $store ) {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'no' );
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		} elseif ( 'job change' === $store ) {
			delete_option( 'woocommerce_woocommerce_payments_version' );
			$this->enable_builtin_runtime_owner();
			$job        = new class() extends ReconciliationJob {
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
		$job        = new class() extends ReconciliationJob {
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
	 * The switch never updates the extension, so the lever starts nothing below the minimum version and the store keeps the
	 * update-first prompt. The version is read last, so a store with the lever off reads none.
	 *
	 * @testdox With mandatory cutover $label, an admin page on a store still running extension $version queues $expected.
	 * @testWith ["on", true, "10.6.0", ["mandatory"]]
	 *           ["on", true, "10.5.2", []]
	 *           ["off by default", false, "10.5.2", []]
	 *
	 * @param string   $label     Readable case name.
	 * @param bool     $mandatory Whether the mandatory cutover filter is turned on.
	 * @param string   $version   The extension version the store last ran.
	 * @param string[] $expected  Sources the job is asked to queue.
	 */
	public function test_mandatory_cutover_queues_the_switch_only_when_turned_on( string $label, bool $mandatory, string $version, array $expected ): void {
		unset( $label );
		update_option( 'woocommerce_woocommerce_payments_version', $version );
		$this->fake_plugin_active();
		$this->fake_current_user_caps( true );
		$this->enable_ready_cutover();
		if ( $mandatory ) {
			add_filter( CutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		}
		$job           = $this->create_start_recording_job();
		$controller    = $this->create_cutover_controller( $job );
		$version_reads = 0;
		$count         = static function ( $value ) use ( &$version_reads ) {
			++$version_reads;
			return $value;
		};

		add_filter( 'option_woocommerce_woocommerce_payments_version', $count );
		try {
			$controller->handle_admin_init();
		} finally {
			remove_filter( 'option_woocommerce_woocommerce_payments_version', $count );
		}

		$this->assertSame( $expected, $job->sources );
		if ( ! $mandatory ) {
			$this->assertSame( 0, $version_reads, 'A store with the lever off reads no version.' );
		}
		if ( array() === $expected ) {
			$notices = $this->render_admin_notices( $controller );
			$this->assertStringContainsString( self::UPDATE_FIRST_SENTENCE, $notices );
			$this->assertStringNotContainsString( 'Start the switch', $notices );
		}
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
		$preflight    = new class() extends PreflightService {
			/** The extension is active on this site only. */
			public function is_woopayments_network_active(): bool {
				return false;
			}

			/** The extension loads from the plugin list. */
			public function is_woopayments_loaded_outside_plugin_lists(): bool {
				return false;
			}
		};
		$job          = new class() extends ReconciliationJob {
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
		// No readable extension files, so the recorded version decides alone.
		$preflight_proxy = $this->getMockBuilder( LegacyProxy::class )
			->onlyMethods( array( 'call_function' ) )
			->getMock();
		$preflight_proxy->method( 'call_function' )->willReturnCallback(
			static fn( string $function_name, ...$args ) => 'is_readable' === $function_name ? false : $function_name( ...$args )
		);
		$preflight->init( $arbiter, $preflight_proxy );
		$controller = new CutoverController();
		$controller->init( $arbiter, $legacy_proxy, $preflight, $job, $this->create_renewal_plugins( array() ) );
		$redirects        = array();
		$capture_redirect = static function ( string $location ) use ( &$redirects ): string {
			$redirects[] = $location;
			return '';
		};
		add_filter( 'wp_redirect', $capture_redirect );
		$_GET[ CutoverController::QUERY_ACTION ] = CutoverController::ACTION_START;
		$_GET[ CutoverController::NONCE_NAME ]   = wp_create_nonce( CutoverController::NONCE_ACTION );

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
		$job                                     = new class() extends ReconciliationJob {
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
		$controller                              = $this->create_cutover_controller( $job );
		$_GET[ CutoverController::QUERY_ACTION ] = CutoverController::ACTION_START;
		$_GET[ CutoverController::NONCE_NAME ]   = wp_create_nonce( CutoverController::NONCE_ACTION );
		$logger                                  = $this->createMock( \WC_Logger_Interface::class );
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
		$job                                     = new class() extends ReconciliationJob {
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
		$controller                              = $this->create_cutover_controller( $job );
		$_GET[ CutoverController::QUERY_ACTION ] = CutoverController::ACTION_START;
		$_GET[ CutoverController::NONCE_NAME ]   = 'invalid';

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
		add_filter( CutoverController::FILTER_MANDATORY_CUTOVER_ENABLED, '__return_true' );
		Constants::set_constant( 'WC_ALLOW_MERGED_FEATURE_PLUGINS', true );
		$job        = new class() extends ReconciliationJob {
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
	}

	/**
	 * Create a preflight service wired to this test's dependencies.
	 *
	 * @param PreflightService|null $preflight_service Optional preflight double to wire; a new one by default.
	 * @return PreflightService
	 */
	private function create_preflight_service( ?PreflightService $preflight_service = null ): PreflightService {
		$preflight_service = $preflight_service ?? new PreflightService();
		$this->init_preflight_service(
			$preflight_service,
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class ),
			wc_get_container()->get( LegacyProxy::class ),
			$this->provider,
			new BundledSubscriptionsGuard(),
			$this->platform_connection_service,
			$this->rate_account,
			$this->rate_api_client
		);

		return $preflight_service;
	}

	/**
	 * Create a renewal plugins double whose active plugins are the given ones, which a test may change between renders.
	 *
	 * @param array<string,string> $plugins Active plugin names by plugin file.
	 * @return ExtensionBoundRenewalPlugins&object{plugins: array<string,string>}
	 */
	private function create_renewal_plugins( array $plugins ): ExtensionBoundRenewalPlugins {
		return new class( $plugins ) extends ExtensionBoundRenewalPlugins {
			/** @var array<string,string> Active plugin names by plugin file. */
			public array $plugins;

			/**
			 * @param array<string,string> $plugins Active plugin names by plugin file.
			 */
			public function __construct( array $plugins ) {
				$this->plugins = $plugins;
			}

			/** @return string[] */
			public function get_active_plugin_files(): array {
				return array_keys( $this->plugins );
			}

			/** @return string[] */
			public function get_active_plugin_names(): array {
				return array_values( $this->plugins );
			}
		};
	}

	/**
	 * Create a cutover controller wired to this test's dependencies.
	 *
	 * @param ReconciliationJob|null            $job               Optional reconciliation job.
	 * @param PreflightService|null             $preflight_service Optional preflight service; a new one by default.
	 * @param ExtensionBoundRenewalPlugins|null $renewal_plugins   Optional renewal plugins; none active by default.
	 * @return CutoverController
	 */
	private function create_cutover_controller( ?ReconciliationJob $job = null, ?PreflightService $preflight_service = null, ?ExtensionBoundRenewalPlugins $renewal_plugins = null ): CutoverController {
		$arbiter           = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$legacy_proxy      = wc_get_container()->get( LegacyProxy::class );
		$preflight_service = $preflight_service ?? $this->create_preflight_service();

		$job = $job ?? new class() extends ReconciliationJob {
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

			/**
			 * No durable record, so nothing waits.
			 *
			 * @param string|null $code Deferral code, or null for an admin page.
			 */
			public function bring_deferred_attempt_forward( ?string $code = null ): bool {
				unset( $code );
				return false;
			}
		};
		// A deferred record under the in-progress line calls the job's own bring-forward, which reads the job's store. Doubles that
		// only stub what the notice reads get the real services, where no record waits, so nothing moves.
		$job_state_store = new \ReflectionProperty( ReconciliationJob::class, 'state_store' );
		$job_state_store->setAccessible( true );
		if ( ! $job_state_store->isInitialized( $job ) ) {
			$job->init( $arbiter, wc_get_container()->get( StateStore::class ), wc_get_container()->get( CutoverActionScheduler::class ), $preflight_service, wc_get_container()->get( DataMigration::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );
		}
		$controller = new CutoverController();
		$controller->init( $arbiter, $legacy_proxy, $preflight_service, $job, $renewal_plugins ?? $this->create_renewal_plugins( array() ) );

		return $controller;
	}

	/**
	 * Create a completed-cutover job double that records dismissals on its record.
	 *
	 * @return ReconciliationJob&object{dismissed: string[]}
	 */
	private function create_completed_notice_job(): ReconciliationJob {
		return new class() extends ReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Completed record. */
			private array $record = array(
				'state'                  => StateStore::STATE_DONE,
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
	 * @param string $notice One of the ReconciliationJob::NOTICE_* constants.
	 * @return ReconciliationJob&object{dismissed: string[]}
	 */
	private function create_dismissable_notice_job( string $notice ): ReconciliationJob {
		if ( ReconciliationJob::NOTICE_SUCCESS === $notice ) {
			return $this->create_completed_notice_job();
		}

		return new class() extends ReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Deferred record that needs a reconnect. */
			private array $record = array(
				'state'                  => StateStore::STATE_DEFERRED,
				'current_step'           => 'deferred',
				'deferred_codes'         => array( PlatformConnectionService::FAILURE_CONNECTION_OWNER_UNAVAILABLE ),
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
	 * @return ReconciliationJob
	 */
	private function create_excluded_notice_job( string $code ): ReconciliationJob {
		return new class( $code ) extends ReconciliationJob {
			/** @var string[] Dismissed notices, in order. */
			public array $dismissed = array();

			/** @var array<string,mixed> Excluded record. */
			private array $record;

			/**
			 * @param string $code Exclusion code.
			 */
			public function __construct( string $code ) {
				$this->record = array(
					'state'                  => StateStore::STATE_EXCLUDED,
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
	 * Arrange a store the built-in WooPayments owns because it never ran the extension, with a store manager signed in.
	 */
	private function arrange_builtin_store(): void {
		delete_option( 'woocommerce_woocommerce_payments_version' );
		$this->fake_plugin_active( false );
		$this->fake_current_user_caps( true );
		$this->enable_builtin_runtime_owner();
	}

	/**
	 * Follow the dismiss link of one rendered completion notice.
	 *
	 * @param CutoverController $controller Cutover controller.
	 * @param string            $notices    Rendered notice markup.
	 * @param string            $notice     Notice whose link to follow.
	 */
	private function follow_dismiss_link( CutoverController $controller, string $notices, string $notice ): void {
		$this->assertSame( 1, preg_match( '/href="([^"]*' . preg_quote( CutoverController::QUERY_NOTICE . '=' . $notice, '/' ) . '[^"]*)"/', $notices, $matches ), "The {$notice} notice should render a dismiss link." );
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $query );
		foreach ( $query as $key => $value ) {
			$_GET[ $key ] = $value;
		}
		$controller->handle_admin_init();
	}

	/**
	 * Render admin notices for a cutover controller.
	 *
	 * @param CutoverController $controller Cutover controller.
	 * @return string Rendered notice markup.
	 */
	private function render_admin_notices( CutoverController $controller ): string {
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
		// The extension's code loads where a stored list holds it; a single site has no network list to hold it.
		$this->extension_class_loaded = $site_active || ( $sitewide_active && is_multisite() );
		$entry                        = $plugin_file ?? WooPaymentsRuntimeArbiter::PLUGIN_FILE;
		update_option( 'active_plugins', $site_active ? array( $entry ) : array() );

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
	 * @param PreflightService                  $service                     The preflight service.
	 * @param WooPaymentsRuntimeArbiter         $arbiter                     The runtime arbiter.
	 * @param LegacyProxy                       $legacy_proxy                The legacy proxy.
	 * @param WooPaymentsProvider               $provider                    The native provider.
	 * @param BundledSubscriptionsGuard         $bundled_subscriptions_guard  The bundled subscriptions guard.
	 * @param PlatformConnectionService         $platform_connection_service The platform connection readiness service.
	 * @param WooPaymentsNativeAccountAdapter   $rate_account         The rate account boundary.
	 * @param WooPaymentsNativeApiClientAdapter $rate_api_client      The rate API client boundary.
	 */
	private function init_preflight_service( PreflightService $service, WooPaymentsRuntimeArbiter $arbiter, LegacyProxy $legacy_proxy, WooPaymentsProvider $provider, BundledSubscriptionsGuard $bundled_subscriptions_guard, PlatformConnectionService $platform_connection_service, WooPaymentsNativeAccountAdapter $rate_account, WooPaymentsNativeApiClientAdapter $rate_api_client ): void {
		$service->init( $arbiter, $legacy_proxy );

		$collaborators = array(
			'provider'                    => $provider,
			'bundled_subscriptions_guard' => $bundled_subscriptions_guard,
			'platform_connection_service' => $platform_connection_service,
			'rate_account'                => $rate_account,
			'rate_api_client'             => $rate_api_client,
		);
		foreach ( $collaborators as $property => $collaborator ) {
			$reflection = new \ReflectionProperty( PreflightService::class, $property );
			$reflection->setAccessible( true );
			$reflection->setValue( $service, $collaborator );
		}
	}
}
