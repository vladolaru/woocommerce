<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProviderInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RateService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsClientVersion;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\PreflightService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\DebugTools;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusReport;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WebhookReliabilityService;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;
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
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_woopayments_last_webhook_fetch';

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
		delete_option( 'woocommerce_woocommerce_payments_apple_pay_settings' );
		delete_option( 'woocommerce_woocommerce_payments_google_pay_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		delete_option( 'woocommerce_woocommerce_payments_version' );
		wc_get_container()->reset_replacement( Client::class );
		wc_get_container()->reset_replacement( HttpClient::class );
		delete_transient( 'wcpay_fraud_protection_settings' );
		delete_option( 'current_protection_level' );
		update_option( StateStore::OPTION_NAME, StateStore::ABSENT_RECORD, true );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::DISABLED, true );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox Should build no diagnostic collaborator when initialized, so registering the report builds no payment code.
	 */
	public function test_init_resolves_no_diagnostic_collaborator(): void {
		$arbiter    = wc_get_container()->get( WooPaymentsRuntimeArbiter::class );
		$setup_tier = wc_get_container()->get( WooPaymentsSetupTier::class );
		$sut        = new WooPaymentsStatusReport();

		$on_init = $this->get_container_requests_during( fn() => $sut->init( $arbiter, $setup_tier ) );
		$on_use  = $this->get_container_requests_during( fn() => $sut->add_debug_tools( array() ) );

		$this->assertSame( array(), $on_init, 'The report must not ask the container for a collaborator when it is initialized.' );
		$this->assertContains( DebugTools::class, $on_use, 'The report must ask the container for the debug tools when they are first needed.' );
	}

	/**
	 * @testdox While the extension owns payments, the supportability hooks are registered only with the WooPayments feature on ($_dataName).
	 * @testWith ["yes", true]
	 *           ["no", false]
	 *
	 * @param string $feature  The WooPayments feature option.
	 * @param bool   $expected Whether the hooks are registered.
	 */
	public function test_registers_supportability_hooks_while_the_extension_owns_runtime_only_with_the_feature_on( string $feature, bool $expected ): void {
		$this->fake_plugin( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature, true );
		$this->set_setup_tier( WooPaymentsSetupTier::AVAILABLE );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assert_supportability_hooks( $sut, $expected );
	}

	/**
	 * @testdox A store without a connected account or the extension registers no supportability hook, so the Status page runs no preflight or account read (client 11.1.0 is absent there).
	 * @testWith ["disabled"]
	 *           ["available"]
	 *
	 * @param string $state Stored native payments state.
	 */
	public function test_registers_nothing_without_a_connected_account_or_the_extension( string $state ): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes', true );
		$this->set_setup_tier( $state );
		// WC_Install::create_options() seeds this autoloaded, so a dormant store reads it for free.
		update_option( StateStore::OPTION_NAME, StateStore::ABSENT_RECORD, true );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->get_runtime_owner(); // Resolved at bootstrap, before register().
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
		$this->assertFalse( has_action( 'wp_ajax_health-check-woocommerce-woopayments-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) ) );
	}

	/**
	 * @testdox A store with something for support to read registers all five supportability hooks ($_dataName).
	 * @dataProvider provide_stores_with_supportability_data
	 *
	 * @param string $feature        The WooPayments feature option.
	 * @param string $stored_tier    The stored setup tier.
	 * @param string $effective_tier The tier the store reads as.
	 * @param bool   $cutover_record Whether the store holds a cutover record.
	 */
	public function test_registers_supportability_hooks_for_a_store_with_something_to_read( string $feature, string $stored_tier, string $effective_tier, bool $cutover_record ): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature );
		$this->set_setup_tier( $stored_tier );
		if ( $cutover_record ) {
			$this->assertTrue(
				( new StateStore() )->create_record(
					array(
						'schema_version'         => 1,
						'generation'             => 1,
						'revision'               => 1,
						'state'                  => StateStore::STATE_DEFERRED,
						'started_at'             => 1_700_000_000,
						'updated_at'             => 1_700_000_100,
						'attempt'                => 1,
						'action_id'              => 0,
						'current_step'           => 'deferred',
						'step_log'               => array(),
						'deferred_codes'         => array( 'builtin_transport_unavailable' ),
						'informational_outcomes' => array(),
						'next_attempt_at'        => 1_700_001_000,
						'lease_token'            => null,
						'lease_expires_at'       => null,
					)
				)
			);
		}
		$this->assertSame( $effective_tier, wc_get_container()->get( WooPaymentsSetupTier::class )->get_effective_tier() );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assert_supportability_hooks( $sut, true );
	}

	/**
	 * Stores the extension does not own that still have something for support to read: a connected account, a connected
	 * account whose feature is off (its effective tier reads disabled), and a cutover record on a store that is not connected.
	 *
	 * @return array<string,array{string,string,string,bool}>
	 */
	public function provide_stores_with_supportability_data(): array {
		return array(
			'a connected store'                            => array( 'yes', WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::CONNECTED, false ),
			'a connected store with the feature off'       => array( 'no', WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::DISABLED, false ),
			'a store with a cutover record, not connected' => array( 'yes', WooPaymentsSetupTier::AVAILABLE, WooPaymentsSetupTier::AVAILABLE, true ),
		);
	}

	/**
	 * @testdox A store waiting for its WooPayments switch registers the supportability hooks, and its owner row says it awaits the switch while the status data keeps none.
	 */
	public function test_store_waiting_for_its_switch_reports_that_it_awaits_the_switch(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes', true );
		// The extension's version option is the evidence that it ran (WooPaymentsRuntimeArbiter::has_run_woopayments_extension()); the extension writes it in client 11.1.0 `includes/class-wc-payments.php:1579-1580`.
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.0', true );
		update_option( StateStore::OPTION_NAME, StateStore::ABSENT_RECORD, true );
		$this->set_setup_tier( WooPaymentsSetupTier::DISABLED );
		$sut = $this->get_sut();
		$this->remove_status_hooks( $sut );

		$sut->register();

		$this->assertSame( 1, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
		$this->assertSame( 10, has_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) ) );
		$this->assertSame( 10, has_filter( 'site_status_tests', array( $sut, 'add_site_status_tests' ) ) );
		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_NONE, $sut->get_status_data()['runtime_owner'] );
		$this->assertSame( 'none (awaiting the switch)', $sut->get_status_fields()['runtime_owner']['value'] );
		$this->assertArrayHasKey( 'woocommerce_woopayments_cutover', $sut->add_site_status_tests( array() )['async'], 'The waiting store is offered the switch, so Site Health checks it.' );
		// The tool IDs are the extension's (client 11.1.0 `includes/class-wc-payments-status.php:85-140`).
		$this->assertSame( array( 'clear_wcpay_account_cache', 'delete_wcpay_test_orders', 'clear_wcpay_styles_cache' ), array_keys( $sut->add_debug_tools( array() ) ), 'The canceled-authorization tools need handlers that load only while the built-in WooPayments owns payments.' );

		ob_start();
		$sut->render_status_report_section();

		$this->assertSame( array( 'Built-in WooPayments' ), array_keys( $this->get_status_tables( (string) ob_get_clean() ) ), 'No gateway is registered while the store waits, so the WooPayments gateway table has nothing true to say.' );
	}

	/**
	 * @testdox The status report leaves the WooPayments gateway table out unless the built-in WooPayments owns payments ($_dataName).
	 * @dataProvider provide_stores_without_a_running_built_in
	 *
	 * @param string $feature    The WooPayments feature option.
	 * @param bool   $ran_before Whether the store ran the extension before.
	 * @param string $tier       The stored setup tier.
	 */
	public function test_status_report_leaves_the_gateway_table_out_unless_the_built_in_owns_payments( string $feature, bool $ran_before, string $tier ): void {
		$this->set_store_shape( $feature, false, $ran_before, $tier );

		ob_start();
		$this->get_sut()->render_status_report_section();

		$this->assertSame( array( 'Built-in WooPayments' ), array_keys( $this->get_status_tables( (string) ob_get_clean() ) ) );
	}

	/**
	 * @return array<string,array{string,bool,string}>
	 */
	public function provide_stores_without_a_running_built_in(): array {
		return array(
			'waiting for the switch'                       => array( 'yes', true, WooPaymentsSetupTier::DISABLED ),
			'connected store, feature off, extension gone' => array( 'no', true, WooPaymentsSetupTier::CONNECTED ),
		);
	}

	/**
	 * @testdox Site Health registers an asynchronous cutover check that reports runtime ownership and preflight state.
	 */
	public function test_site_health_registers_async_cutover_check_with_runtime_and_preflight_state(): void {
		// The extension owns payments with the feature on, so the switch can be offered; no extension version is stored, which fails the version check.
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
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
		$this->assertArrayHasKey( 'woocommerce_woopayments_cutover', $tests['async'] );
		$this->assertSame( 'woocommerce-woopayments-cutover', $tests['async']['woocommerce_woopayments_cutover']['test'] );
		$this->assertIsCallable( $tests['async']['woocommerce_woopayments_cutover']['async_direct_test'] );

		$result = $tests['async']['woocommerce_woopayments_cutover']['async_direct_test']();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'woocommerce_woopayments_cutover', $result['test'] );
		$this->assertStringContainsString( 'WooPayments runs as the WooPayments extension.', $result['description'] );
		$this->assertStringContainsString( 'Failed switch checks: ', $result['description'] );
		$this->assertStringContainsString( 'woopayments_plugin_version_unsupported', $result['description'] );
		$this->assertSame( 'Payments', $result['badge']['label'], 'The check is about payments, not speed or security.' );
	}

	/**
	 * @testdox Site Health lists the switch check only while the switch can be offered: the extension owns payments with the feature on, or the store waits for it ($_dataName).
	 * @dataProvider provide_site_health_stores
	 *
	 * @param string $feature    The WooPayments feature option.
	 * @param bool   $plugin     Whether the WooPayments extension is active.
	 * @param bool   $ran_before Whether the store ran the extension before.
	 * @param string $tier       The stored setup tier.
	 * @param bool   $expected   Whether the check is listed.
	 */
	public function test_site_health_lists_the_switch_check_only_while_the_switch_can_be_offered( string $feature, bool $plugin, bool $ran_before, string $tier, bool $expected ): void {
		$this->set_store_shape( $feature, $plugin, $ran_before, $tier );

		$tests = $this->get_sut()->add_site_status_tests( array( 'async' => array() ) );

		$this->assertSame( $expected, isset( $tests['async']['woocommerce_woopayments_cutover'] ) );
	}

	/**
	 * @return array<string,array{string,bool,bool,string,bool}>
	 */
	public function provide_site_health_stores(): array {
		return array(
			'extension owns payments, feature on'          => array( 'yes', true, true, WooPaymentsSetupTier::AVAILABLE, true ),
			'waiting for the switch'                       => array( 'yes', false, true, WooPaymentsSetupTier::DISABLED, true ),
			'extension owns payments, feature off'         => array( 'no', true, true, WooPaymentsSetupTier::AVAILABLE, false ),
			'connected store the built-in runs, never switched' => array( 'yes', false, false, WooPaymentsSetupTier::CONNECTED, false ),
			'connected store, feature off, extension gone' => array( 'no', false, true, WooPaymentsSetupTier::CONNECTED, false ),
		);
	}

	/**
	 * @testdox Status data reports runtime, account, checkout, multi-currency, and webhook state.
	 */
	public function test_status_data_uses_the_builtin_services_and_the_feature_option(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$this->seed_connected_store();

		$data = $this->get_sut()->get_status_data();

		$this->assertSame( WooPaymentsRuntimeArbiter::OWNER_BUILTIN, $data['runtime_owner'] );
		$this->assertTrue( $data['builtin_enabled'] );
		$this->assertSame( get_option( WooPaymentsSetupTier::OPTION_NAME ), $data['setup_tier'] );
		$this->assertSame( wc_get_container()->get( WooPaymentsSetupTier::class )->get_effective_tier(), $data['effective_setup_tier'] );
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
	 * @testdox On a store the WooPayments extension owns, with the feature on and a stored disabled tier, the status data, the status table and Site Health report the stored tier and the effective tier apart.
	 */
	public function test_status_data_reports_the_stored_and_the_effective_tier_apart(): void {
		$this->fake_plugin( true );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes', true );
		$this->set_setup_tier( WooPaymentsSetupTier::DISABLED );

		$data = $this->get_sut()->get_status_data();

		$this->assertSame( 'disabled', $data['setup_tier'] );
		$this->assertSame( 'available', $data['effective_setup_tier'] );

		ob_start();
		$this->get_sut()->render_status_report_section();
		$html  = (string) ob_get_clean();
		$table = $this->get_status_tables( $html )['Built-in WooPayments'];
		$this->assertSame( 'disabled', $table['Stored setup tier'] ?? null );
		$this->assertSame( 'available', $table['Effective setup tier'] ?? null );
		$this->assertStringContainsString( '>Stored setup tier:</td>', $html );
		$this->assertStringContainsString( '>Effective setup tier:</td>', $html );

		$fields = $this->get_sut()->add_site_health_debug_info( array() )['woocommerce_woopayments']['fields'];
		$this->assertSame(
			array(
				'label' => 'Stored setup tier',
				'value' => 'disabled',
			),
			$fields['setup_tier']
		);
		$this->assertSame(
			array(
				'label' => 'Effective setup tier',
				'value' => 'available',
			),
			$fields['effective_setup_tier']
		);
	}

	/**
	 * @testdox Status data and the Multi-currency row report whether core Multi-Currency runs: $_dataName.
	 *
	 * Client 11.1.0 reports its own module's state (includes/class-wc-payments-status.php:600); the WooPayments flag an
	 * earlier plugin install left decides nothing once WooCommerce runs the payments.
	 *
	 * @dataProvider provide_multi_currency_states
	 *
	 * @param string      $core_option The core Multi-Currency feature option.
	 * @param string|null $flag        The WooPayments Multi-Currency flag, or null for never written.
	 * @param bool        $expected    Whether Multi-Currency is reported enabled.
	 */
	public function test_status_reports_whether_core_multi_currency_runs( string $core_option, ?string $flag, bool $expected ): void {
		$options = array(
			MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION => $core_option,
			'_wcpay_feature_customer_multi_currency' => $flag,
		);

		$section = $this->get_status_tables( $this->render_report_for_connected_store( array(), array(), 'basic', array(), $options ) )['WooPayments'];

		$this->assertSame( $expected, $this->get_sut()->get_status_data()['multi_currency']['enabled'] );
		$this->assertSame( $expected ? 'Enabled' : 'Disabled', $section['Multi-currency'] );
	}

	/**
	 * Core Multi-Currency states, each against the WooPayments flag value that used to decide the answer.
	 *
	 * @return array<string,array{string,string|null,bool}>
	 */
	public function provide_multi_currency_states(): array {
		return array(
			'core Multi-Currency off, flag never written' => array( 'no', null, false ),
			'core Multi-Currency on, flag off'            => array( 'yes', '0', true ),
		);
	}

	/**
	 * @testdox Status data reports the WooPayments rate provider as unavailable when the registry has no available provider.
	 */
	public function test_status_data_reports_rate_provider_unavailable_from_registry_state(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$this->seed_connected_store();
		wc_get_container()->reset_all_resolved();

		$data = $this->get_sut()->get_status_data();

		$this->assertTrue( $data['multi_currency']['enabled'] );
		$this->assertTrue( $data['account_connected'] );
		$this->assertFalse( $data['multi_currency']['rate_provider_available'], 'Connected account state alone must not make the rate provider look available.' );
	}

	/**
	 * @testdox Status data reports the WooPayments rate provider available only while Multi-Currency holds it and it is available: $label.
	 * @testWith ["WooPayments provider available", "woopayments", true, true]
	 *           ["WooPayments provider unavailable", "woopayments", false, false]
	 *           ["another provider available", "other-rates", true, false]
	 *
	 * @param string $label       Readable case name.
	 * @param string $provider_id ID of the provider Multi-Currency holds.
	 * @param bool   $available   Whether that provider is available.
	 * @param bool   $expected    Expected rate_provider_available.
	 */
	public function test_status_data_reports_rate_provider_availability_from_the_rate_service( string $label, string $provider_id, bool $available, bool $expected ): void {
		unset( $label );
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$this->seed_connected_store();
		wc_get_container()->reset_all_resolved();
		$provider = $this->createMock( CurrencyRateProviderInterface::class );
		$provider->method( 'get_id' )->willReturn( $provider_id );
		$provider->method( 'is_available' )->willReturn( $available );
		wc_get_container()->get( RateService::class )->set_provider( $provider );

		try {
			$data = $this->get_sut()->get_status_data();
		} finally {
			// A fresh rate service holds no provider.
			wc_get_container()->reset_all_resolved();
		}

		$this->assertTrue( $data['multi_currency']['enabled'] );
		$this->assertSame( $expected, $data['multi_currency']['rate_provider_available'] );
	}

	/**
	 * @testdox The account cache tool refetches the account once and leaves the cache as the extension does ($fetch_fails, $had_cached_account).
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
		$api_client    = $this->create_account_api_client( $fresh_account, $fetch_fails, true );
		wc_get_container()->replace( Client::class, $api_client );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->seed_connected_store();
		$this->set_setup_tier( WooPaymentsSetupTier::CONNECTED );
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
	 * @testdox The account cache tool neither fetches nor touches the cache when the platform connection is unavailable.
	 *
	 * Client 11.1.0 `get_cached_account_data()` returns [] before any cache work when the server is not connected
	 * (class-wc-payments-account.php:2442-2444), and WooCommerce prints "Tool ran." for an array.
	 */
	public function test_clear_account_cache_tool_skips_the_fetch_without_a_platform_connection(): void {
		$api_client = $this->create_account_api_client( array( 'account_id' => 'acct_unused' ), false, false );
		wc_get_container()->replace( Client::class, $api_client );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->seed_connected_store();
		$this->set_setup_tier( WooPaymentsSetupTier::CONNECTED );
		$cache_before = get_option( 'wcpay_account_data' );
		$sut          = $this->get_sut();
		$this->remove_status_hooks( $sut );
		$sut->register();

		$result = ( new WC_REST_System_Status_Tools_V2_Controller() )->execute_tool( 'clear_wcpay_account_cache' );

		$this->assertSame( 0, $api_client->calls, 'The tool must not fetch without a platform connection.' );
		$this->assertSame( $cache_before, get_option( 'wcpay_account_data' ), 'The tool must leave the account cache untouched.' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Tool ran.', $result['message'] );
	}

	/**
	 * @testdox Site Health debug info exposes the built-in status fields and the WooPayments feature.
	 */
	public function test_site_health_debug_info_exposes_status_values(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		// A connected store: without a connection the account read returns no account, like the client.
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$this->seed_connected_store();

		$info = $this->get_sut()->add_site_health_debug_info( array() );

		$this->assertArrayHasKey( 'woocommerce_woopayments', $info );
		$this->assertSame( 'Built-in WooPayments', $info['woocommerce_woopayments']['label'] );
		$this->assertSame( 'builtin', $info['woocommerce_woopayments']['fields']['runtime_owner']['value'] );
		$this->assertSame( 'acct_native_test', $info['woocommerce_woopayments']['fields']['account_id']['value'] );
		$this->assertSame( 'WooPayments feature', $info['woocommerce_woopayments']['fields']['builtin_enabled']['label'] );
		$this->assertSame( 'Enabled', $info['woocommerce_woopayments']['fields']['builtin_enabled']['value'] );
	}

	/**
	 * @testdox The built-in WooPayments table and Site Health list their rows in order and name the WooPayments feature as its Features page entry does.
	 *
	 * The Features page names the feature "WooPayments" (WooPaymentsFeatureController::add_feature_definition()).
	 */
	public function test_runtime_rows_name_the_woopayments_feature(): void {
		$html = $this->render_report_for_connected_store();

		$this->assertSame(
			array( 'Runtime owner', 'WooPayments feature', 'Stored setup tier', 'Effective setup tier', 'Cutover preflight failures', 'WooPayments rate provider', 'Last fetch of failed webhook events' ),
			array_keys( $this->get_status_tables( $html )['Built-in WooPayments'] )
		);
		$this->assertSame(
			array(
				'Runtime owner',
				'WooPayments feature',
				'Stored setup tier',
				'Effective setup tier',
				'Cutover preflight failures',
				'Account ID',
				'Account connected',
				'Gateway enabled',
				'Test mode',
				'Enabled payment methods',
				'WooPay express checkout',
				'Apple Pay / Google Pay express checkout',
				'Multi-currency',
				'WooPayments rate provider',
				'Last fetch of failed webhook events',
			),
			array_column( $this->get_sut()->add_site_health_debug_info( array() )['woocommerce_woopayments']['fields'], 'label' )
		);
	}

	/**
	 * @testdox The status report keeps the extension's WooPayments section labels and values, and adds the built-in diagnostics as their own table.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:427-641`; support macros and copied reports key on these labels.
	 *
	 * @testWith ["yes", "Enabled"]
	 *           ["no", "Disabled"]
	 *           ["1", "Disabled"]
	 *
	 * @param string $manual_capture The manual_capture gateway setting.
	 * @param string $expected       The Auth and Capture value.
	 */
	public function test_status_report_renders_the_extension_section_for_a_connected_store( string $manual_capture, string $expected ): void {
		$html = $this->render_report_for_connected_store( array(), array( 'manual_capture' => $manual_capture ) );

		$tables = $this->get_status_tables( $html );
		$this->assertSame( array( 'WooPayments', 'Built-in WooPayments' ), array_keys( $tables ) );
		$client = $tables['WooPayments'];
		$this->assertSame( WooPaymentsClientVersion::get_reported_version(), $client['Version'] );
		$this->assertSame( 'Yes', $client['Connected to WPCOM'] );
		$this->assertSame( '12345', $client['WPCOM Blog ID'] );
		$this->assertSame( 'acct_native_test', $client['Account ID'] );
		$this->assertSame( 'Enabled', $client['Payment Gateway'] );
		$this->assertSame( 'Enabled', $client['Test Mode'] );
		$this->assertSame( 'card,link', $client['Enabled APMs'] );
		$this->assertSame( 'Not eligible', $client['WooPay'] );
		$this->assertArrayNotHasKey( 'WooPay Incompatible Extensions', $client );
		$this->assertSame( 'Enabled (product,checkout)', $client['Apple Pay / Google Pay'] );
		$this->assertSame( 'basic', $client['Fraud Protection Level'] );
		$this->assertArrayNotHasKey( 'Enabled Fraud Filters', $client );
		$this->assertSame( 'Enabled', $client['Multi-currency'] );
		$this->assertSame( $expected, $client['Auth and Capture'] );
		$this->assertSame( '+15555550123', $client['Support Phone'] );
		$this->assertSame( 'Enabled', $client['Documents'] );
		$this->assertSame( 'Disabled', $client['Dev Mode'] );
		$this->assertSame( 'Disabled', $client['Logging'] );
		$this->assertSame(
			array(
				'label' => 'Auth and Capture:',
				'help'  => 'Whether the store has the Auth & Capture feature enabled or not.',
			),
			array_intersect_key( $this->get_status_cells( $html, 'WooPayments', 'Auth and Capture' ), array_flip( array( 'label', 'help' ) ) )
		);
		$this->assertSame( 'builtin', $tables['Built-in WooPayments']['Runtime owner'] );
		$this->assertArrayNotHasKey( 'Account ID', $tables['Built-in WooPayments'] );
		$this->assertStringNotContainsString( 'SENTINEL', $html, 'Keys in the account data never reach the report.' );
	}

	/**
	 * @testdox The runtime table exports fixed English labels in a translated admin, as core's status tables do.
	 */
	public function test_runtime_table_exports_english_labels_in_a_translated_admin(): void {
		$translate = static function ( $translation, $text, $domain ) {
			return 'woocommerce' === $domain && 'Runtime owner' === $text ? 'Propriétaire du runtime' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		$html = $this->render_report_for_connected_store();

		remove_filter( 'gettext', $translate, 10 );
		$runtime_owner = $this->get_status_cells( $html, 'Built-in WooPayments', 'Runtime owner' );
		$this->assertSame( 'Propriétaire du runtime:', $runtime_owner['label'] );
		$this->assertSame( 'builtin', $runtime_owner['value'] );
	}

	/**
	 * @testdox The status report shows the extension's enabled WooPay rows for a WooPay-eligible account.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:507-535`; WooPay counts as enabled only for 'yes'
	 * (`includes/class-wc-payments-features.php:54-59`).
	 *
	 * @testWith ["yes", "Enabled (cart,checkout)"]
	 *           ["no", "Disabled"]
	 *           ["1", "Disabled"]
	 *           [true, "Disabled"]
	 *
	 * @param mixed  $platform_checkout The platform_checkout gateway setting.
	 * @param string $expected          The WooPay value.
	 */
	public function test_status_report_shows_the_enabled_woopay_rows_for_an_eligible_account( $platform_checkout, string $expected ): void {
		update_option( 'woopay_invalid_extension_found', true );

		$html = $this->render_report_for_connected_store( array( 'platform_checkout_eligible' => true ), array( 'platform_checkout' => $platform_checkout ) );

		$client = $this->get_status_tables( $html )['WooPayments'];
		delete_option( 'woopay_invalid_extension_found' );
		$this->assertSame( $expected, $client['WooPay'] );
		$this->assertSame( 'Yes', $client['WooPay Incompatible Extensions'] );
		$woopay = $this->get_status_cells( $html, 'WooPayments', 'WooPay' );
		$this->assertSame( 'WooPay Express Checkout:', $woopay['label'] );
		$this->assertSame( 'Whether the new WooPay Express Checkout is enabled or not.', $woopay['help'] );
	}

	/**
	 * @testdox The status report lists every express location for location settings never saved ($row).
	 *
	 * A missing location setting reads as its form default, all three locations, as client 11.1.0
	 * `includes/class-wc-payments-status.php:66-78` reads it (`includes/class-wc-payment-gateway-wcpay.php:510-540`).
	 *
	 * @testWith ["WooPay"]
	 *           ["Apple Pay / Google Pay"]
	 *
	 * @param string $row Status report row.
	 */
	public function test_status_report_lists_every_express_location_for_settings_never_saved( string $row ): void {
		$drop_locations = static function ( $settings ) {
			unset( $settings['express_checkout_product_methods'], $settings['express_checkout_cart_methods'], $settings['express_checkout_checkout_methods'] );

			return $settings;
		};
		add_filter( 'option_woocommerce_woocommerce_payments_settings', $drop_locations );

		$html = $this->render_report_for_connected_store( array( 'platform_checkout_eligible' => true ) );

		remove_filter( 'option_woocommerce_woocommerce_payments_settings', $drop_locations );
		$this->assertSame( 'Enabled (product,cart,checkout)', $this->get_status_tables( $html )['WooPayments'][ $row ] );
	}

	/**
	 * @testdox The status report names the advanced fraud filters in force, as the extension does.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:554-594`.
	 */
	public function test_status_report_names_the_advanced_fraud_filters(): void {
		$html = $this->render_report_for_connected_store(
			array(),
			array(),
			'advanced',
			// Only the rule keys, the part of the platform's fraud ruleset the client's status section reads
			// (client 11.1.0 `includes/class-wc-payments-status.php:555-592`, rules per `includes/fraud-prevention/models/class-rule.php`).
			array( array( 'key' => 'avs_verification' ), array( 'key' => 'address_mismatch' ), array( 'key' => 'unknown_rule' ) )
		);

		$client = $this->get_status_tables( $html )['WooPayments'];
		$this->assertSame( 'advanced', $client['Fraud Protection Level'] );
		$this->assertSame( 'AVS Verification,Address Mismatch', $client['Enabled Fraud Filters'] );
	}

	/**
	 * @testdox The status report lists the extension's connection rows and flags the missing connection or account ($_dataName).
	 * @dataProvider provide_extension_connection_states
	 *
	 * @param bool          $wpcom_connected Whether the store has a WPCOM connection.
	 * @param array<string> $expected_labels Expected export labels of the rows.
	 * @param int           $warning_row     Index of the row that carries the warning.
	 * @param string        $expected_value  Expected value of that row.
	 */
	public function test_status_report_flags_a_missing_wpcom_connection_or_account( bool $wpcom_connected, array $expected_labels, int $warning_row, string $expected_value ): void {
		$http_client = $this->createMock( HttpClient::class );
		$http_client->method( 'is_connected' )->willReturn( $wpcom_connected );
		$http_client->method( 'get_blog_id' )->willReturn( 12345 );
		wc_get_container()->replace( HttpClient::class, $http_client );
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();

		$rows = $this->get_sut()->get_woopayments_status_rows();

		$this->assertSame( $expected_labels, array_column( $rows, 'export_label' ) );
		$this->assertSame( $expected_value, $rows[ $warning_row ]['value'] );
		$this->assertTrue( $rows[ $warning_row ]['warning'] );
	}

	/**
	 * A store connected to WPCOM without an account, and a store without a WPCOM connection.
	 *
	 * @return array<string,array{bool,array<string>,int,string}>
	 */
	public function provide_extension_connection_states(): array {
		return array(
			'WPCOM connected, no account' => array( true, array( 'Version', 'Connected to WPCOM', 'WPCOM Blog ID', 'Account ID', 'Logging' ), 3, 'Not connected' ),
			'no WPCOM connection'         => array( false, array( 'Version', 'Connected to WPCOM', 'Logging' ), 1, 'No' ),
		);
	}

	/**
	 * @testdox While the extension owns payments the report leaves the WooPayments section to the extension and reads no fraud settings.
	 */
	public function test_status_report_leaves_the_extension_section_to_the_extension_while_it_owns_payments(): void {
		$this->fake_plugin( true );
		$this->set_setup_tier( WooPaymentsSetupTier::AVAILABLE );
		$api_client = $this->createMock( Client::class );
		$api_client->expects( $this->never() )->method( 'get_latest_fraud_ruleset' );
		wc_get_container()->replace( Client::class, $api_client );

		ob_start();
		$this->get_sut()->render_status_report_section();
		$html = (string) ob_get_clean();

		$this->assertSame( array( 'Built-in WooPayments' ), array_keys( $this->get_status_tables( $html ) ) );
	}

	/**
	 * @testdox Dev mode triggers follow the wcpay_dev_mode filter, which can turn dev mode on or off, as in the extension.
	 *
	 * Client 11.1.0 `includes/core/class-mode.php:72-111`.
	 */
	public function test_dev_mode_triggers_follow_the_dev_mode_filter(): void {
		$account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		add_filter( 'wcpay_dev_mode', '__return_false' );
		$this->assertSame( array(), $account_service->get_dev_mode_triggers() );
		remove_filter( 'wcpay_dev_mode', '__return_false' );

		add_filter( 'wcpay_dev_mode', '__return_true' );
		$triggers = $account_service->get_dev_mode_triggers();

		$this->assertNotEmpty( $triggers );
		$this->assertTrue( $account_service->is_dev_mode_enabled() );
		foreach ( $triggers as $trigger ) {
			$this->assertMatchesRegularExpression( '/^(WCPAY_DEV_MODE|WP_ENVIRONMENT_TYPE=\\w+|WP_DEVELOPMENT_MODE=\\w+|wcpay_dev_mode filter)$/', $trigger );
		}
	}

	/**
	 * Render the report for a WPCOM-connected store with a connected account.
	 *
	 * @param array<string,mixed> $account_extra    Account data to add.
	 * @param array<string,mixed> $settings_extra   Gateway settings to add.
	 * @param string              $protection_level Fraud protection level.
	 * @param array<int,mixed>    $fraud_ruleset    Cached advanced fraud ruleset.
	 * @param array<string,mixed> $options          Options to set after the store is seeded; null deletes one.
	 * @return string Rendered HTML.
	 */
	private function render_report_for_connected_store( array $account_extra = array(), array $settings_extra = array(), string $protection_level = 'basic', array $fraud_ruleset = array(), array $options = array() ): string {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
		$http_client = $this->createMock( HttpClient::class );
		$http_client->method( 'is_connected' )->willReturn( true );
		$http_client->method( 'get_blog_id' )->willReturn( 12345 );
		wc_get_container()->replace( HttpClient::class, $http_client );
		$this->seed_connected_store();
		// Account fields per the platform account response the client reads (client 11.1.0 `includes/class-wc-payments-account.php:367-377`
		// status flags, `:489-492` support phone, `:181-192` publishable keys, `includes/class-wc-payments-features.php:193-220` WooPay and
		// documents); the keys are sentinels the report must never print.
		wc_get_container()->get( WooPaymentsAccountService::class )->cache_account_data(
			array_merge(
				array(
					'account_id'           => 'acct_native_test',
					'status'               => 'complete',
					'is_live'              => true,
					'payments_enabled'     => true,
					'details_submitted'    => true,
					'is_documents_enabled' => true,
					'business_profile'     => array( 'support_phone' => '+15555550123' ),
					'live_publishable_key' => 'pk_live_SENTINEL',
					'test_publishable_key' => 'pk_test_SENTINEL',
				),
				$account_extra
			)
		);
		update_option( 'woocommerce_woocommerce_payments_settings', array_merge( get_option( 'woocommerce_woocommerce_payments_settings' ), $settings_extra ) );
		set_transient( 'wcpay_fraud_protection_settings', $fraud_ruleset, HOUR_IN_SECONDS );
		update_option( 'current_protection_level', $protection_level );
		foreach ( $options as $option => $value ) {
			null === $value ? delete_option( $option ) : update_option( $option, $value );
		}
		add_filter( 'wcpay_dev_mode', '__return_false' );

		ob_start();
		$this->get_sut()->render_status_report_section();

		return (string) ob_get_clean();
	}

	/**
	 * Read one rendered row's label, help tip and value.
	 *
	 * @param string $html         Rendered status report section.
	 * @param string $table_title  Table export label.
	 * @param string $export_label Row export label.
	 * @return array{label:string,help:string,value:string}
	 */
	private function get_status_cells( string $html, string $table_title, string $export_label ): array {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		foreach ( $document->getElementsByTagName( 'table' ) as $table ) {
			if ( $table_title !== $table->getElementsByTagName( 'th' )->item( 0 )->getAttribute( 'data-export-label' ) ) {
				continue;
			}
			foreach ( $table->getElementsByTagName( 'tbody' )->item( 0 )->getElementsByTagName( 'tr' ) as $row ) {
				$cells = $row->getElementsByTagName( 'td' );
				if ( $export_label !== $cells->item( 0 )->getAttribute( 'data-export-label' ) ) {
					continue;
				}
				$tip = $cells->item( 1 )->getElementsByTagName( 'span' )->item( 0 );

				return array(
					'label' => trim( $cells->item( 0 )->textContent ),
					'help'  => $tip ? $tip->getAttribute( 'data-tip' ) : '',
					'value' => trim( $cells->item( 2 )->textContent ),
				);
			}
		}

		$this->fail( "No {$export_label} row in the {$table_title} table." );
	}

	/**
	 * Read each rendered status table as export label => value text.
	 *
	 * @param string $html Rendered status report section.
	 * @return array<string,array<string,string>>
	 */
	private function get_status_tables( string $html ): array {
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();

		$tables = array();
		foreach ( $document->getElementsByTagName( 'table' ) as $table ) {
			$title = $table->getElementsByTagName( 'th' )->item( 0 )->getAttribute( 'data-export-label' );
			$rows  = array();
			foreach ( $table->getElementsByTagName( 'tbody' )->item( 0 )->getElementsByTagName( 'tr' ) as $row ) {
				$cells = $row->getElementsByTagName( 'td' );
				$rows[ $cells->item( 0 )->getAttribute( 'data-export-label' ) ] = trim( $cells->item( 2 )->textContent );
			}
			$tables[ $title ] = $rows;
		}

		return $tables;
	}

	/**
	 * Create a fake API client that counts account fetches.
	 *
	 * @param array<string,mixed> $account   Account to return.
	 * @param bool                $fail      Whether every fetch fails.
	 * @param bool                $available Whether the platform connection is available.
	 * @return Client
	 */
	private function create_account_api_client( array $account, bool $fail, bool $available ): Client {
		return new class( $account, $fail, $available ) extends Client {
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
			 * Whether the platform connection is available.
			 *
			 * @var bool
			 */
			private bool $available;

			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $account   Account to return.
			 * @param bool                $fail      Whether every fetch fails.
			 * @param bool                $available Whether the platform connection is available.
			 */
			public function __construct( array $account, bool $fail, bool $available ) {
				$this->account   = $account;
				$this->fail      = $fail;
				$this->available = $available;
			}

			/**
			 * Tell whether the fake client is available.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return $this->available;
			}

			/**
			 * Return the account or fail.
			 *
			 * @param string $woocommerce_store_id WooCommerce store ID.
			 * @return array<string,mixed>
			 * @throws ApiException When set to fail.
			 */
			public function get_account( string $woocommerce_store_id = '' ): array {
				++$this->calls;
				if ( $this->fail ) {
					throw new ApiException( 'Temporary failure.', 'wcpay_temporary_failure', 500 );
				}

				return $this->account;
			}
		};
	}

	/**
	 * Get the System Under Test.
	 *
	 * @return WooPaymentsStatusReport
	 */
	private function get_sut(): WooPaymentsStatusReport {
		$this->sut = wc_get_container()->get( WooPaymentsStatusReport::class );

		return $this->sut;
	}

	/**
	 * Seed a connected WooPayments store.
	 */
	private function seed_connected_store(): void {
		$this->assertTrue( defined( WebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ), 'Webhook reliability should expose its last-fetch option key.' );
		$this->assertSame( self::EXPECTED_LAST_FETCH_OPTION, constant( WebhookReliabilityService::class . '::LAST_FETCH_OPTION_KEY' ) );

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
				'express_checkout_product_methods'  => array( 'payment_request' ),
				'express_checkout_cart_methods'     => array( 'woopay' ),
				'express_checkout_checkout_methods' => array( 'payment_request', 'woopay' ),
			)
		);
		foreach ( array( 'apple_pay', 'google_pay' ) as $wallet_method_id ) {
			update_option( 'woocommerce_woocommerce_payments_' . $wallet_method_id . '_settings', array( 'enabled' => 'yes' ) );
		}
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000 );
	}

	/**
	 * Put the store in one ownership shape and drop the request-local memos.
	 *
	 * @param string $feature    The WooPayments feature option.
	 * @param bool   $plugin     Whether the WooPayments extension is active.
	 * @param bool   $ran_before Whether the store ran the extension before (its version option is stored).
	 * @param string $tier       The stored setup tier.
	 */
	private function set_store_shape( string $feature, bool $plugin, bool $ran_before, string $tier ): void {
		$this->fake_plugin( $plugin );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature, true );
		if ( $ran_before ) {
			// The extension writes its version here (client 11.1.0 `includes/class-wc-payments.php:1579-1580`).
			update_option( 'woocommerce_woocommerce_payments_version', '10.5.0', true );
		}
		update_option( StateStore::OPTION_NAME, StateStore::ABSENT_RECORD, true );
		$this->set_setup_tier( $tier );
	}

	/**
	 * Store a native payments tier and drop the request-local memo.
	 *
	 * @param string $state Native payments state.
	 */
	private function set_setup_tier( string $state ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $state, true );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Assert the five supportability hooks of the given SUT are registered, or none is.
	 *
	 * @param WooPaymentsStatusReport $sut        Status report instance.
	 * @param bool                    $registered Whether the hooks are expected to be registered.
	 */
	private function assert_supportability_hooks( WooPaymentsStatusReport $sut, bool $registered ): void {
		$this->assertSame( $registered ? 1 : false, has_action( 'woocommerce_system_status_report', array( $sut, 'render_status_report_section' ) ) );
		$this->assertSame( $registered ? 10 : false, has_filter( 'woocommerce_debug_tools', array( $sut, 'add_debug_tools' ) ) );
		$this->assertSame( $registered ? 10 : false, has_filter( 'debug_information', array( $sut, 'add_site_health_debug_info' ) ) );
		$this->assertSame( $registered ? 10 : false, has_filter( 'site_status_tests', array( $sut, 'add_site_status_tests' ) ) );
		$this->assertSame( $registered ? 10 : false, has_action( 'wp_ajax_health-check-woocommerce-woopayments-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) ) );
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
		remove_action( 'wp_ajax_health-check-woocommerce-woopayments-cutover', array( $sut, 'run_cutover_site_health_ajax_test' ) );
	}

	/**
	 * Reset request-local cutover state between PHPUnit test methods.
	 */
	private function reset_cutover_preflight_memo(): void {
		wc_get_container()->get( PreflightService::class )->invalidate_current_blog_memoization();
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration.
	 *
	 * @param bool $active Whether the WooPayments plugin should appear active.
	 */
	private function fake_plugin( bool $active ): void {
		$entry = WooPaymentsRuntimeArbiter::PLUGIN_FILE;
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

	/**
	 * Run a callback while recording every class the global container is asked for.
	 *
	 * @param callable $callback Code to run.
	 * @return string[] Class names requested from the container, in order.
	 */
	private function get_container_requests_during( callable $callback ): array {
		$container = wc_get_container();
		$requested = array();
		$recorder  = $this->getMockBuilder( TestingContainer::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get' ) )
			->getMock();
		$recorder->method( 'get' )->willReturnCallback(
			static function ( string $class_name ) use ( $container, &$requested ) {
				$requested[] = $class_name;
				return $container->get( $class_name );
			}
		);

		$GLOBALS['wc_container'] = $recorder;
		try {
			$callback();
		} finally {
			$GLOBALS['wc_container'] = $container;
		}

		return $requested;
	}
}
