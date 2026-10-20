<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\WooPaymentsDebugTools;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDebugTools class.
 */
class WooPaymentsDebugToolsTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_replacement( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		$this->reset_legacy_proxy_mocks();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		parent::tearDown();
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
			$this->assertArrayNotHasKey( 'builtin-' . $tool_id, $tools );
			$this->assertArrayHasKey( 'callback', $tools[ $tool_id ] );
			$this->assertIsCallable( $tools[ $tool_id ]['callback'] );
		}
	}

	/**
	 * @testdox The built-in debug tools use distinct keys while the WooPayments extension is active.
	 */
	public function test_debug_tools_use_the_builtin_prefix_without_overwriting_extension_tools_during_coexistence(): void {
		$this->fake_plugin( true );
		$tool_ids        = array(
			'clear_wcpay_account_cache',
			'delete_wcpay_test_orders',
			'clear_wcpay_styles_cache',
			'remediate_canceled_auth_fees_dry_run',
			'remediate_canceled_auth_fees',
		);
		$extension_tools = array();

		foreach ( $tool_ids as $tool_id ) {
			$extension_tools[ $tool_id ] = array(
				'name'     => 'Plugin tool',
				'callback' => '__return_null',
			);
		}

		$tools = $this->get_sut()->add_debug_tools( $extension_tools );

		foreach ( $tool_ids as $tool_id ) {
			$this->assertSame( $extension_tools[ $tool_id ], $tools[ $tool_id ] );
			$this->assertArrayHasKey( 'builtin-' . $tool_id, $tools );
			$this->assertIsCallable( $tools[ 'builtin-' . $tool_id ]['callback'] );
		}
	}

	/**
	 * @testdox A pending remediation dry run alone leaves the remediation tools enabled.
	 *
	 * Client 11.1.0 disables both tools from `is_remediation_action_scheduled()`, which checks only the full-run hook
	 * (class-wc-payments-status.php:400-422).
	 */
	public function test_pending_dry_run_alone_leaves_remediation_tools_enabled(): void {
		$this->fake_plugin( false );
		delete_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATUS_OPTION_KEY );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );

		$tools = $this->get_sut()->add_debug_tools( array() );

		$this->assertFalse( $tools['remediate_canceled_auth_fees_dry_run']['disabled'], 'A pending dry run alone must not disable the dry-run tool.' );
		$this->assertFalse( $tools['remediate_canceled_auth_fees']['disabled'], 'A pending dry run alone must not disable the remediation tool.' );
	}

	/**
	 * @testdox The remediation tools report the extension's error message when scheduling throws.
	 *
	 * Client 11.1.0 wraps the scheduling in try/catch and returns "Error scheduling remediation: %s" or
	 * "Error scheduling dry run: %s" (class-wc-payments-status.php:249-255, 293-299).
	 *
	 * @testWith ["schedule_canceled_auth_remediation", "schedule_remediation", "Error scheduling remediation: Scheduler is down."]
	 *           ["schedule_canceled_auth_dry_run", "schedule_dry_run", "Error scheduling dry run: Scheduler is down."]
	 *
	 * @param string $tool_callback    Debug tool callback.
	 * @param string $service_method   Remediation service scheduling method.
	 * @param string $expected_message Message the merchant sees.
	 */
	public function test_remediation_tools_report_a_scheduling_error( string $tool_callback, string $service_method, string $expected_message ): void {
		$remediation_service = $this->createMock( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		$remediation_service->method( 'is_complete' )->willReturn( false );
		$remediation_service->method( $service_method )->willThrowException( new \Exception( 'Scheduler is down.' ) );
		wc_get_container()->replace( WooPaymentsCanceledAuthorizationFeeRemediationService::class, $remediation_service );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$sut = new WooPaymentsDebugTools();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );

		$this->assertSame( $expected_message, $sut->$tool_callback() );
	}

	/**
	 * Get the System Under Test.
	 *
	 * @return WooPaymentsDebugTools
	 */
	private function get_sut(): WooPaymentsDebugTools {
		return wc_get_container()->get( WooPaymentsDebugTools::class );
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
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
	}
}
