<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusReport;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
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
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox Supportability hooks are registered even when the plugin owns runtime.
	 */
	public function test_registers_supportability_hooks_even_when_plugin_owns_runtime(): void {
		$this->fake_plugin( true );
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
			$this->assertArrayHasKey( 'callback', $tools[ $tool_id ] );
			$this->assertIsCallable( $tools[ $tool_id ]['callback'] );
		}
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
