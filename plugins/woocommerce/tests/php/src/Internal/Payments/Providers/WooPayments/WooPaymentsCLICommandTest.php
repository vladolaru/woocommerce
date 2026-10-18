<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCLICommand;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCLICommand class.
 */
class WooPaymentsCLICommandTest extends WC_Unit_Test_Case {

	/**
	 * Expected option key for the native failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_woopayments_last_webhook_fetch';

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();
		delete_option( 'woocommerce_woopayments_builtin_kill_switch' );
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		remove_all_filters( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER );
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		parent::tearDown();
	}

	/**
	 * @testdox Status lines report owner, filter resolution, preflight failures, and account summary.
	 */
	public function test_status_lines_report_runtime_filter_preflight_and_account_summary(): void {
		$this->fake_plugin( false );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->seed_connected_store();

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();
		$text  = implode( "\n", $lines );

		$this->assertStringContainsString( 'Runtime owner: builtin', $text );
		$this->assertStringContainsString( 'Built-in WooPayments enabled: yes', $text );
		$this->assertStringContainsString( 'Built-in WooPayments filter: woocommerce_woopayments_builtin_enabled (source: filter)', $text );
		$this->assertStringContainsString( 'woocommerce_woopayments_builtin_kill_switch', $text );
		$this->assertStringContainsString( 'final authority', $text );
		$this->assertStringContainsString( 'Cutover preflight failures:', $text );
		$this->assertStringContainsString( 'Account: acct_native_test (connected)', $text );
	}

	/**
	 * @testdox Status lines report whether core Multi-Currency runs and the WooPayments rate provider's availability: $_dataName.
	 *
	 * @dataProvider provide_multi_currency_lines
	 *
	 * @param string $core_option The core Multi-Currency feature option.
	 * @param string $flag        The WooPayments Multi-Currency flag an earlier plugin install left.
	 * @param string $expected    Expected status line.
	 */
	public function test_status_lines_report_multi_currency_and_rate_provider( string $core_option, string $flag, string $expected ): void {
		$this->fake_plugin( false );
		add_filter( WooPaymentsRuntimeArbiter::BUILTIN_ENABLED_FILTER, '__return_true' );
		$this->seed_connected_store();
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, $core_option );
		update_option( '_wcpay_feature_customer_multi_currency', $flag );
		wc_get_container()->get( CurrencyRateProviderRegistryFactory::class )->set_provider_registrars( array() );

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();

		$this->assertContains( $expected, $lines );
	}

	/**
	 * Core Multi-Currency states against the WooPayments flag, and the line each prints.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function provide_multi_currency_lines(): array {
		return array(
			'core Multi-Currency on, flag off' => array( 'yes', '0', 'Multi-currency: enabled (rate provider: woopayments, unavailable)' ),
			'core Multi-Currency off, flag on' => array( 'no', '1', 'Multi-currency: disabled (rate provider: woopayments, unavailable)' ),
		);
	}

	/**
	 * Seed a connected WooPayments store.
	 */
	private function seed_connected_store(): void {
		// A connected store: without a connection the account read returns no account, like the client (client 11.1.0 `includes/class-wc-payments-account.php:2441-2444`).
		$connected_api_client = $this->createMock( WooPaymentsApiClient::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( WooPaymentsApiClient::class, $connected_api_client );
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
				'enabled'                        => 'yes',
				'test_mode'                      => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
				'platform_checkout'              => 'yes',
				'payment_request'                => 'yes',
			)
		);
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000 );
	}

	/**
	 * Control every WooPayments-plugin detection signal in a single mock registration, so a test process that loaded
	 * the plugin (WCPAY_PLUGIN_FILE defined) or activated it still gets the requested owner.
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
				'defined'         => function ( $constant_name ) use ( $active ) {
					if ( 'WCPAY_PLUGIN_FILE' === $constant_name ) {
						return $active;
					}
					return defined( $constant_name );
				},
			)
		);
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}
}
