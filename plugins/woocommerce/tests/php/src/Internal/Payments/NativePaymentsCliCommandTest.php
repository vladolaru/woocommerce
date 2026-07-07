<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\NativePaymentsCliCommand;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusReport;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsWebhookReliabilityService;
use WC_Unit_Test_Case;

/**
 * Tests for the NativePaymentsCliCommand class.
 */
class NativePaymentsCliCommandTest extends WC_Unit_Test_Case {

	/**
	 * Expected option key for the native failed-webhook fetch timestamp.
	 *
	 * @var string
	 */
	private const EXPECTED_LAST_FETCH_OPTION = 'woocommerce_native_woopayments_last_webhook_fetch';

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		delete_option( 'wcpay_account_data' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( self::EXPECTED_LAST_FETCH_OPTION );
		remove_all_filters( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED );
		$this->reset_legacy_proxy_mocks();

		parent::tearDown();
	}

	/**
	 * @testdox Status lines report owner, filter resolution, preflight failures, and account summary.
	 */
	public function test_status_lines_report_runtime_filter_preflight_and_account_summary(): void {
		$this->assertTrue( class_exists( WooPaymentsStatusReport::class ), 'WooPaymentsStatusReport should exist before CLI status can format support data.' );
		$this->assertTrue( class_exists( NativePaymentsCliCommand::class ), 'NativePaymentsCliCommand should exist.' );
		$this->fake_plugin( false );
		add_filter( NativePaymentsRuntimeArbiter::FILTER_NATIVE_ENABLED, '__return_true' );
		$this->seed_connected_store();

		$lines = wc_get_container()->get( NativePaymentsCliCommand::class )->get_status_lines();
		$text  = implode( "\n", $lines );

		$this->assertStringContainsString( 'Owner: native', $text );
		$this->assertStringContainsString( 'Native enabled: yes', $text );
		$this->assertStringContainsString( 'Filter: woocommerce_native_payments_enabled (source: filter)', $text );
		$this->assertStringContainsString( 'Preflight failures:', $text );
		$this->assertStringContainsString( 'Account: acct_native_test (connected)', $text );
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
				'enabled'                        => 'yes',
				'test_mode'                      => 'yes',
				'upe_enabled_payment_method_ids' => array( 'card', 'link' ),
				'platform_checkout'              => 'yes',
				'payment_request'                => 'yes',
			)
		);
		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( self::EXPECTED_LAST_FETCH_OPTION, 1700000000 );
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
