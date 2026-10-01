<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Enums\WooPaymentsCutoverState;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsState;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverPreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusReport;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use WC_REST_System_Status_Tools_V2_Controller;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsStatusReport class.
 */
class WooPaymentsStatusReportTest extends WC_Unit_Test_Case {

	/**
	 * Expected option key for the native failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_native_woopayments_last_webhook_fetch';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsStatusReport|null
	 */
	private $sut = null;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->reset_cutover_preflight_memo();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( $this->sut instanceof WooPaymentsStatusReport ) {
			$this->remove_status_hooks( $this->sut );
		}

		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		wc_get_container()->reset_replacement( WooPaymentsApiClient::class );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, '0', true );
		update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD, true );
		update_option( NativePaymentsState::OPTION_NAME, NativePaymentsState::DISABLED, true );
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox Should build no diagnostic collaborator when initialized, so registering the report builds no payment code.
	 */
	public function test_init_resolves_no_diagnostic_collaborator(): void {
		$sut = new WooPaymentsStatusReport();
		$sut->init( wc_get_container()->get( NativePaymentsRuntimeArbiter::class ), wc_get_container()->get( NativePaymentsState::class ) );

		foreach ( array( 'account_service', 'frontend_styles_service', 'fee_remediation_service', 'cutover_controller', 'provider_registry_factory', 'cutover_state_store' ) as $property ) {
			$reflection = new \ReflectionProperty( WooPaymentsStatusReport::class, $property );
			$reflection->setAccessible( true );
			$this->assertNull( $reflection->getValue( $sut ), $property . ' must be resolved when a diagnostic runs, not when the report is initialized.' );
		}
	}

	/**
	 * @testdox Supportability hooks are registered even when the plugin owns runtime.
	 */
	public function test_registers_supportability_hooks_even_when_plugin_owns_runtime(): void {
		$this->fake_plugin( true );
		$this->set_native_state( NativePaymentsState::AVAILABLE );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_debug_tools', array( $sut, 'add_debug_tools' ) ) );
		$this->assertSame( 10, has_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) ) );
		$this->assertSame( 10, has_filter( 'site_status_tests', array( $sut, 'add_site_status_tests' ) ) );
		$this->assertSame( 10, has_action( 'wp_ajax_health-check-woocommerce-woopayments-native-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) ) );
	}

	/**
	 * @testdox A store without a connected native account or the plugin registers no supportability hook, so the Status page runs no preflight or account read (client 11.1.0 is absent there).
	 * @testWith ["disabled"]
	 *           ["available"]
	 *
	 * @param string $state Stored native payments state.
	 */
	public function test_registers_nothing_without_a_connected_account_or_the_plugin( string $state ): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->set_native_state( $state );
		// WC_Install::create_options() seeds these autoloaded, so a dormant store reads them for free.
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, '0', true );
		update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD, true );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->get_runtime_owner(); // Resolved at bootstrap, before register().
		wp_load_alloptions( true );
		$queries = array();
		$record  = static function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );

		try {
			$sut->register();
		} finally {
			remove_filter( 'query', $record );
		}

		$this->assertSame( array(), $queries, 'Deciding to skip the report must read only autoloaded options.' );

		$this->assertFalse( has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_debug_tools', array( $sut, 'add_debug_tools' ) ) );
		$this->assertFalse( has_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) ) );
		$this->assertFalse( has_filter( 'site_status_tests', array( $sut, 'add_site_status_tests' ) ) );
		$this->assertFalse( has_action( 'wp_ajax_health-check-woocommerce-woopayments-native-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) ) );
	}

	/**
	 * @testdox A connected native store registers the supportability hooks.
	 */
	public function test_registers_supportability_hooks_for_a_connected_native_store(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->set_native_state( NativePaymentsState::CONNECTED );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
	}

	/**
	 * @testdox A connected native store keeps the supportability hooks after support turns on the kill switch, which clamps its state to disabled.
	 */
	public function test_registers_supportability_hooks_for_a_killswitched_connected_store(): void {
		$this->fake_plugin( false );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, '1', true );
		$this->set_native_state( NativePaymentsState::CONNECTED );
		$this->assertSame( NativePaymentsState::DISABLED, wc_get_container()->get( NativePaymentsState::class )->get_state(), 'The kill switch should clamp the effective state.' );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
		$this->assertSame( 10, has_filter( 'woocommerce_debug_tools', array( $sut, 'add_debug_tools' ) ) );
		$this->assertSame( 10, has_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) ) );
	}

	/**
	 * @testdox A connected store whose native runtime the rollout filter turns off keeps the supportability hooks.
	 */
	public function test_registers_supportability_hooks_for_a_connected_store_with_the_runtime_off(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_false' );
		$this->set_native_state( NativePaymentsState::ACTIVE );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
	}

	/**
	 * @testdox A store with the kill switch on registers the supportability hooks even before it connects.
	 */
	public function test_registers_supportability_hooks_while_the_kill_switch_is_on(): void {
		$this->fake_plugin( false );
		update_option( NativePaymentsRuntimeArbiter::NATIVE_RUNTIME_KILL_SWITCH_OPTION, '1', true );
		$this->set_native_state( NativePaymentsState::AVAILABLE );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
	}

	/**
	 * @testdox A store with a cutover record registers the supportability hooks even when its state is not connected.
	 */
	public function test_registers_supportability_hooks_when_a_cutover_record_exists(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->set_native_state( NativePaymentsState::AVAILABLE );
		$this->assertTrue(
			( new WooPaymentsCutoverStateStore() )->save_record(
				array(
					'schema_version'         => 1,
					'generation'             => 1,
					'revision'               => 1,
					'state'                  => WooPaymentsCutoverState::DEFERRED,
					'started_at'             => 1_700_000_000,
					'updated_at'             => 1_700_000_100,
					'attempt'                => 1,
					'action_id'              => 0,
					'current_step'           => 'deferred',
					'step_log'               => array(),
					'deferred_codes'         => array( 'native_transport_unavailable' ),
					'informational_outcomes' => array(),
					'next_attempt_at'        => 1_700_001_000,
					'lease_token'            => null,
					'lease_expires_at'       => null,
				)
			)
		);
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
	}

	/**
	 * @testdox Site Health registers an asynchronous cutover check that reports runtime ownership and preflight state.
	 */
	public function test_site_health_registers_async_cutover_check_with_runtime_and_preflight_state(): void {
		$this->fake_plugin( true );

		$tests = $this->get_sut()->add_site_status_tests(
			array(
				'async' => array(
					'existing_test' => array(
						'label' => 'Existing test',
						'test'  => '__return_empty_array',
					),
				),
			)
		);

		$this->assertArrayHasKey( 'existing_test', $tests['async'] );
		$this->assertArrayHasKey( 'woocommerce_woopayments_native_cutover', $tests['async'] );
		$this->assertSame( 'woocommerce-woopayments-native-cutover', $tests['async']['woocommerce_woopayments_native_cutover']['test'] );
		$this->assertIsCallable( $tests['async']['woocommerce_woopayments_native_cutover']['async_direct_test'] );

		$result = $tests['async']['woocommerce_woopayments_native_cutover']['async_direct_test']();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'woocommerce_woopayments_native_cutover', $result['test'] );
		$this->assertStringContainsString( 'Runtime owner: plugin', $result['description'] );
		$this->assertStringContainsString( 'Preflight failures: native_runtime_disabled', $result['description'] );
	}

	/**
	 * @testdox Status data reports runtime, account, checkout, multi-currency, and webhook state.
	 */
	public function test_status_data_uses_native_services_and_runtime_filter_resolution(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->seed_connected_store();

		$data = $this->get_sut()->get_status_data();

		$this->assertSame( NativePaymentsRuntimeArbiter::OWNER_NATIVE, $data['runtime_owner'] );
		$this->assertTrue( $data['native_enabled'] );
		$this->assertSame( 'filter', $data['native_enabled_source'] );
		$this->assertSame( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, $data['native_enabled_filter'] );
		$this->assertStringContainsString( 'mu-plugin', $data['native_enabled_note'] );
		$this->assertSame( 'acct_native_test', $data['account_id'] );
		$this->assertTrue( $data['account_connected'] );
		$this->assertTrue( $data['gateway_enabled'] );
		$this->assertTrue( $data['test_mode'] );
		$this->assertSame( array( 'card', 'link' ), $data['enabled_payment_methods'] );
		$this->assertSame( array( 'cart', 'checkout' ), $data['woopay']['enabled_locations'] );
		$this->assertSame( array( 'product', 'checkout' ), $data['express_checkout']['payment_request'] );
		$this->assertTrue( $data['multi_currency']['enabled'] );
		$this->assertSame( 'woopayments', $data['multi_currency']['rate_provider'] );
		$this->assertSame( 1700000000, $data['last_webhook_fetch'] );
	}

	/**
	 * @testdox Status data defaults Multi-Currency to enabled when the option is absent.
	 */
	public function test_status_data_defaults_multi_currency_to_enabled_when_option_is_absent(): void {
		delete_option( '_wcpay_feature_customer_multi_currency' );

		$data = $this->get_sut()->get_status_data();

		$this->assertTrue( $data['multi_currency']['enabled'] );
	}

	/**
	 * @testdox Status data reports the WooPayments rate provider as unavailable when the registry has no available provider.
	 */
	public function test_status_data_reports_rate_provider_unavailable_from_registry_state(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->seed_connected_store();
		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars( array() );

		$data = $this->get_sut()->get_status_data();

		$this->assertTrue( $data['multi_currency']['enabled'] );
		$this->assertTrue( $data['account_connected'] );
		$this->assertFalse( $data['multi_currency']['rate_provider_available'], 'Connected account state alone must not make the rate provider look available.' );
	}

	/**
	 * @testdox WooCommerce debug tools include account, order, styles, and fee-remediation actions.
	 */
	public function test_debug_tools_include_support_callbacks(): void {
		$this->fake_plugin( false );
		$tools = $this->get_sut()->add_debug_tools( array() );

		foreach (
			array(
				'clear_wcpay_account_cache',
				'delete_wcpay_test_orders',
				'clear_wcpay_styles_cache',
				'remediate_canceled_auth_fees_dry_run',
				'remediate_canceled_auth_fees',
			) as $tool_id
		) {
			$this->assertArrayHasKey( $tool_id, $tools );
			$this->assertArrayNotHasKey( 'native-' . $tool_id, $tools );
			$this->assertArrayHasKey( 'callback', $tools[ $tool_id ] );
			$this->assertIsCallable( $tools[ $tool_id ]['callback'] );
		}
	}

	/**
	 * @testdox Native debug tools use distinct keys while the WooPayments plugin is active.
	 */
	public function test_debug_tools_use_native_prefix_without_overwriting_plugin_tools_during_coexistence(): void {
		$this->fake_plugin( true );
		$tool_ids     = array(
			'clear_wcpay_account_cache',
			'delete_wcpay_test_orders',
			'clear_wcpay_styles_cache',
			'remediate_canceled_auth_fees_dry_run',
			'remediate_canceled_auth_fees',
		);
		$plugin_tools = array();

		foreach ( $tool_ids as $tool_id ) {
			$plugin_tools[ $tool_id ] = array(
				'name'     => 'Plugin tool',
				'callback' => '__return_null',
			);
		}

		$tools = $this->get_sut()->add_debug_tools( $plugin_tools );

		foreach ( $tool_ids as $tool_id ) {
			$this->assertSame( $plugin_tools[ $tool_id ], $tools[ $tool_id ] );
			$this->assertArrayHasKey( 'native-' . $tool_id, $tools );
			$this->assertIsCallable( $tools[ 'native-' . $tool_id ]['callback'] );
		}
	}

	/**
	 * @testdox The account cache tool refetches the account once and leaves the cache as the client does ($fetch_fails, $had_cached_account).
	 *
	 * Client 11.1.0 runs `refresh_account_data()` (class-wc-payments-status.php:89-102), which is `get_cached_account_data( true )`
	 * (class-wc-payments-account.php:2534-2536): one fetch; a failed fetch keeps the old data marked errored, or returns false
	 * without it (class-database-cache.php:166-181, class-wc-payments-account.php:2487-2489). WooCommerce prints "Tool ran." for
	 * an array and an error for false (class-wc-rest-system-status-tools-v2-controller.php:738-746).
	 *
	 * @testWith [false, true, "fresh", false, 0, true, "Tool ran."]
	 *           [true, true, "old", true, 1, true, "Tool ran."]
	 *           [true, false, "none", true, 1, false, "There was an error calling "]
	 *
	 * @param bool   $fetch_fails        Whether the account fetch fails.
	 * @param bool   $had_cached_account Whether an account was cached before the tool ran.
	 * @param string $expected_data      Account the cache holds afterwards: fresh, old, or none.
	 * @param bool   $expected_errored   Expected errored flag on the cache.
	 * @param int    $expected_errors    Expected consecutive error count on the cache.
	 * @param bool   $expected_success   Whether WooCommerce reports the tool as successful.
	 * @param string $expected_message   Start of the message WooCommerce shows the merchant.
	 */
	public function test_clear_account_cache_tool_refetches_the_account( bool $fetch_fails, bool $had_cached_account, string $expected_data, bool $expected_errored, int $expected_errors, bool $expected_success, string $expected_message ): void {
		$old_account   = array(
			'account_id'        => 'acct_native_test',
			'is_live'           => true,
			'payments_enabled'  => true,
			'details_submitted' => true,
		);
		$fresh_account = array_merge( $old_account, array( 'payments_enabled' => false ) );
		$api_client    = new class( $fresh_account, $fetch_fails ) extends WooPaymentsApiClient {
			/**
			 * Number of account fetches.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Account to return.
			 *
			 * @var array<string,mixed>
			 */
			private array $account;

			/**
			 * Whether every fetch fails.
			 *
			 * @var bool
			 */
			private bool $fail;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account Account to return.
			 * @param bool                $fail    Whether every fetch fails.
			 */
			public function __construct( array $account, bool $fail ) {
				$this->account = $account;
				$this->fail    = $fail;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Return the account or fail.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 * @throws WooPaymentsApiException When set to fail.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				++$this->calls;
				if ( $this->fail ) {
					throw new WooPaymentsApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
				}

				return $this->account;
			}
		};
		wc_get_container()->replace( WooPaymentsApiClient::class, $api_client );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->seed_connected_store();
		$this->set_native_state( NativePaymentsState::CONNECTED );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );
		$sut->register();
		if ( ! $had_cached_account ) {
			wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		}

		$result = ( new WC_REST_System_Status_Tools_V2_Controller() )->execute_tool( 'clear_wcpay_account_cache' );

		$cache = get_option( 'wcpay_account_data' );
		$this->assertSame( 1, $api_client->calls, 'The tool must fetch the account exactly once.' );
		$this->assertIsArray( $cache, 'The tool must leave an account cache entry behind.' );
		$this->assertSame(
			array(
				'fresh' => $fresh_account,
				'old'   => $old_account,
				'none'  => null,
			)[ $expected_data ],
			$cache['data'],
			'The cache must hold what the client leaves after the refetch.'
		);
		$this->assertSame( $expected_errored, $cache['errored'] );
		$this->assertSame( $expected_errors, $cache['consecutive_errors'] );
		$this->assertSame( $expected_success, $result['success'] );
		$this->assertStringStartsWith( $expected_message, $result['message'] );
	}

	/**
	 * @testdox Site Health debug info exposes the native status fields and rollout note.
	 */
	public function test_site_health_debug_info_exposes_status_values(): void {
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->seed_connected_store();

		$info = $this->get_sut()->add_site_health_debug_info( array() );

		$this->assertArrayHasKey( 'woocommerce_native_payments', $info );
		$this->assertSame( 'WooPayments native payments', $info['woocommerce_native_payments']['label'] );
		$this->assertSame( 'native', $info['woocommerce_native_payments']['fields']['runtime_owner']['value'] );
		$this->assertSame( 'acct_native_test', $info['woocommerce_native_payments']['fields']['account_id']['value'] );
		$this->assertStringContainsString( 'mu-plugin', $info['woocommerce_native_payments']['fields']['native_enabled_note']['value'] );
	}

	/**
	 * Get the System Under Test.
	 *
	 * @return WooPaymentsStatusReport
	 */
	private function get_sut(): WooPaymentsStatusReport {
		$this->assertTrue( class_exists( WooPaymentsStatusReport::class ), 'WooPaymentsStatusReport should exist.' );

		$this->sut = wc_get_container()->get( WooPaymentsStatusReport::class );

		return $this->sut;
	}

	/**
	 * Seed a connected WooPayments store.
	 */
	private function seed_connected_store(): void {
		$this->assertTrue( defined( WooPaymentsWebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ), 'Webhook reliability should expose its last-fetch option key.' );
		$this->assertSame( self::EXPECTED_LAST_FETCH_OPTION, constant( WooPaymentsWebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ) );

		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array(
				'account_id'        => 'acct_native_test',
				'is_live'           => true,
				'payments_enabled'  => true,
				'details_submitted' => true,
			)
		);
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'                           => 'yes',
				'test_mode'                         => 'yes',
				'upe_enabled_payment_method_ids'    => array( 'card', 'link' ),
				'platform_checkout'                 => 'yes',
				'payment_request'                   => 'yes',
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array( 'woopay' ),
				'express_checkout_checkout_methods' => array( 'payment_request', 'woopay' ),
			)
		);
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000 );
	}

	/**
	 * Store a native payments tier and drop the request-local memo.
	 *
	 * @param string $state Native payments state.
	 */
	private function set_native_state( string $state ): void {
		update_option( NativePaymentsState::OPTION_NAME, $state, true );
		wc_get_container()->get( NativePaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( NativePaymentsState::class )->invalidate();
	}

	/**
	 * Remove registered supportability hooks for the given SUT.
	 *
	 * @param WooPaymentsStatusReport $sut Status report instance.
	 */
	private function remove_status_hooks( WooPaymentsStatusReport $sut ): void {
		remove_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ), 1 );
		remove_filter( 'woocommerce_debug_tools', array( $sut, 'add_debug_tools' ) );
		remove_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) );
		remove_filter( 'site_status_tests', array( $sut, 'add_site_status_tests' ) );
		remove_action( 'wp_ajax_health-check-woocommerce-woopayments-native-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) );
	}

	/**
	 * Reset request-local cutover state between PHPUnit test methods.
	 */
	private function reset_cutover_preflight_memo(): void {
		$controller = wc_get_container()->get( WooPaymentsCutoverController::class );
		$property   = new \ReflectionProperty( $controller, 'preflight_service' );
		$property->setAccessible( true );
		$preflight_service = $property->getValue( $controller );
		$this->assertInstanceOf( WooPaymentsCutoverPreflightService::class, $preflight_service );
		$preflight_service->invalidate_current_blog_memoization();
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $active Whether the WooPayments plugin should appear active.
	 */
	private function fake_plugin( bool $active ): void {
		$entry = NativePaymentsRuntimeArbiter::PLUGIN_FILE;
		$this->register_legacy_proxy_function_mocks(
			array(
				'get_option'      => function ( $name, $default_value = false ) use ( $active, $entry ) {
					if ( 'active_plugins' === $name ) {
						return $active ? array( $entry ) : array();
					}
					return get_option( $name, $default_value );
				},
				'get_site_option' => function ( $name, $default_value = false ) {
					if ( 'active_sitewide_plugins' === $name ) {
						return array();
					}
					return get_site_option( $name, $default_value );
				},
				'class_exists'    => function ( $class_name, $autoload = true ) use ( $active ) {
					if ( 'WC_Payments' === ltrim( (string) $class_name, '\\' ) ) {
						return $active;
					}
					return class_exists( $class_name, $autoload );
				},
			)
		);
	}
}
