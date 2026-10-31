<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCLICommand;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\StateStore;
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
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION );
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		delete_option( 'woocommerce_woocommerce_payments_version' );
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		parent::tearDown();
	}

	/**
	 * @testdox Status lines report owner, the WooPayments feature, preflight failures, and account summary.
	 */
	public function test_status_lines_report_runtime_feature_preflight_and_account_summary(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->seed_connected_store();

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();
		$text  = implode( "\n", $lines );

		$this->assertStringContainsString( 'Runtime owner: builtin', $text );
		$this->assertStringContainsString( 'WooPayments feature: yes', $text );
		$this->assertStringNotContainsString( 'filter', $text );
		$this->assertStringContainsString( 'Cutover preflight failures:', $text );
		$this->assertStringContainsString( 'Account: acct_native_test (connected)', $text );
	}

	/**
	 * @testdox Status lines come in a fixed order and name the WooPayments feature as its Features page entry does.
	 */
	public function test_status_lines_name_the_woopayments_feature(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		$this->seed_connected_store();

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();

		$this->assertSame(
			array( 'Runtime owner', 'WooPayments feature', 'Cutover preflight failures', 'Account', 'Gateway enabled', 'Test mode', 'Enabled payment methods', 'WooPay', 'Multi-currency', 'Last fetch of failed webhook events' ),
			array_map( static fn( string $line ): string => (string) strstr( $line, ':', true ), $lines )
		);
	}

	/**
	 * @testdox On a store waiting for its WooPayments switch the owner line says it awaits the switch.
	 */
	public function test_owner_line_says_a_waiting_store_awaits_the_switch(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		// The extension's version option is the evidence that it ran (WooPaymentsRuntimeArbiter::has_run_woopayments_extension()).
		update_option( 'woocommerce_woocommerce_payments_version', '10.5.0' );
		update_option( StateStore::OPTION_NAME, StateStore::ABSENT_RECORD, true );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();

		$this->assertSame( 'Runtime owner: none (awaiting the switch)', $lines[0] );
	}

	/**
	 * @testdox Status lines report whether core Multi-Currency runs and the WooPayments rate provider's availability: $_dataName.
	 *
	 * The command is registered whoever owns payments (includes/class-wc-cli.php:80), so it also reports while the
	 * WooPayments extension does, when core Multi-Currency never runs.
	 *
	 * @dataProvider provide_multi_currency_lines
	 *
	 * @param string $core_option   The core Multi-Currency feature option.
	 * @param string $flag          The WooPayments Multi-Currency flag an earlier plugin install left.
	 * @param bool   $builtin_owner Whether the built-in WooPayments owns payments, rather than the extension.
	 * @param string $expected      Expected status line.
	 */
	public function test_status_lines_report_multi_currency_and_rate_provider( string $core_option, string $flag, bool $builtin_owner, string $expected ): void {
		$this->fake_plugin( ! $builtin_owner );
		if ( $builtin_owner ) {
			update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		}
		$this->seed_connected_store();
		update_option( MultiCurrencyFeatureController::FEATURE_ENABLE_OPTION, $core_option );
		update_option( '_wcpay_feature_customer_multi_currency', $flag );
		wc_get_container()->reset_all_resolved();

		$lines = wc_get_container()->get( WooPaymentsCLICommand::class )->get_status_lines();

		$this->assertContains( $expected, $lines );
	}

	/**
	 * Core Multi-Currency states against the WooPayments flag, and the line each prints.
	 *
	 * @return array<string,array{string,string,bool,string}>
	 */
	public function provide_multi_currency_lines(): array {
		return array(
			'core Multi-Currency on, flag off' => array( 'yes', '0', true, 'Multi-currency: enabled (rate provider: woopayments, unavailable)' ),
			'core Multi-Currency off, flag on' => array( 'no', '1', true, 'Multi-currency: disabled (rate provider: woopayments, unavailable)' ),
			'core option on, extension owner'  => array( 'yes', '1', false, 'Multi-currency: disabled (rate provider: woopayments, unavailable)' ),
		);
	}

	/**
	 * Seed a connected WooPayments store.
	 */
	private function seed_connected_store(): void {
		// A connected store: without a connection the account read returns no account, like the client (client 11.1.0 `includes/class-wc-payments-account.php:2441-2444`).
		$connected_api_client = $this->createMock( Client::class );
		$connected_api_client->method( 'is_available' )->willReturn( true );
		wc_get_container()->replace( Client::class, $connected_api_client );
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
