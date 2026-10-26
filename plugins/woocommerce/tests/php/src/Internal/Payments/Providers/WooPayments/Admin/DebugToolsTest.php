<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin\DebugTools;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverStateStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use WC_Unit_Test_Case;

/**
 * Tests for the DebugTools class.
 */
class DebugToolsTest extends WC_Unit_Test_Case {

	/**
	 * The option the WooPayments extension stores its version in; its presence says the store ran the extension.
	 *
	 * Client 11.1.0 `includes/class-wc-payments.php:1579-1580` writes it.
	 */
	private const EXTENSION_VERSION_OPTION = 'woocommerce_woocommerce_payments_version';

	/**
	 * The tools the built-in WooPayments lists on every store it serves with support diagnostics.
	 *
	 * Client 11.1.0 `includes/class-wc-payments-status.php:85-140` defines these IDs and the remediation ones below.
	 */
	private const ACCOUNT_AND_ORDER_TOOLS = array( 'clear_wcpay_account_cache', 'delete_wcpay_test_orders', 'clear_wcpay_styles_cache' );

	/**
	 * The two canceled-authorization tools, whose Action Scheduler handlers register only while the built-in WooPayments owns payments.
	 */
	private const REMEDIATION_TOOLS = array( 'remediate_canceled_auth_fees_dry_run', 'remediate_canceled_auth_fees' );

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_replacement( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		$this->reset_legacy_proxy_mocks();
		delete_option( self::EXTENSION_VERSION_OPTION );
		update_option( WooPaymentsCutoverStateStore::OPTION_NAME, WooPaymentsCutoverStateStore::ABSENT_RECORD, true );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::DISABLED, true );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		parent::tearDown();
	}

	/**
	 * @testdox The debug tools follow who runs WooPayments: all five for a connected or active built-in, three while it waits, is off or sits in the available tier, none beside the extension ($_dataName).
	 * @dataProvider provide_debug_tool_stores
	 *
	 * @param string   $feature      The WooPayments feature option.
	 * @param bool     $plugin       Whether the WooPayments extension is active.
	 * @param bool     $ran_before   Whether the store ran the extension before (its version option is stored).
	 * @param string   $tier         The stored setup tier.
	 * @param bool     $switched     Whether the store holds a completed switch record.
	 * @param string[] $input_ids    Tools already listed, as the extension lists its own.
	 * @param string[] $expected_ids Tool IDs expected after the filter, in order.
	 */
	public function test_debug_tools_follow_who_runs_woopayments( string $feature, bool $plugin, bool $ran_before, string $tier, bool $switched, array $input_ids, array $expected_ids ): void {
		$this->fake_plugin( $plugin );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, $feature );
		if ( $ran_before ) {
			update_option( self::EXTENSION_VERSION_OPTION, '10.5.0' );
		}
		update_option( WooPaymentsSetupTier::OPTION_NAME, $tier, true );
		if ( $switched ) {
			// The arbiter reads only the record's state, so a one-field record is enough for a completed switch.
			update_option( WooPaymentsCutoverStateStore::OPTION_NAME, array( 'state' => WooPaymentsCutoverStateStore::STATE_DONE ), true );
		}
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		// Minimal sentinels: the tests assert only that a listed tool comes through untouched, not the extension's full tool definitions.
		$input = array();
		foreach ( $input_ids as $tool_id ) {
			$input[ $tool_id ] = array(
				'name'     => 'Plugin tool',
				'callback' => '__return_null',
			);
		}

		$tools = $this->get_sut()->add_debug_tools( $input );

		$this->assertSame( $expected_ids, array_keys( $tools ) );
		foreach ( array_diff( $expected_ids, $input_ids ) as $tool_id ) {
			$this->assertIsCallable( $tools[ $tool_id ]['callback'], $tool_id );
		}
		foreach ( $input_ids as $tool_id ) {
			$this->assertSame( $input[ $tool_id ], $tools[ $tool_id ], 'The extension\'s own tool must come through untouched.' );
		}
	}

	/**
	 * @return array<string,array{string,bool,bool,string,bool,string[],string[]}>
	 */
	public function provide_debug_tool_stores(): array {
		$all = array_merge( self::ACCOUNT_AND_ORDER_TOOLS, self::REMEDIATION_TOOLS );

		return array(
			'built-in owns a connected store, keeping other tools' => array( 'yes', false, false, WooPaymentsSetupTier::CONNECTED, false, array( 'clear_transients' ), array_merge( array( 'clear_transients' ), $all ) ),
			'built-in owns an active store'              => array( 'yes', false, false, WooPaymentsSetupTier::ACTIVE, false, array(), $all ),
			'switched store reset to the available tier' => array( 'yes', false, true, WooPaymentsSetupTier::AVAILABLE, true, array(), self::ACCOUNT_AND_ORDER_TOOLS ),
			'waiting for the switch'                     => array( 'yes', false, true, WooPaymentsSetupTier::DISABLED, false, array(), self::ACCOUNT_AND_ORDER_TOOLS ),
			'feature off, extension not running'         => array( 'no', false, true, WooPaymentsSetupTier::CONNECTED, false, array(), self::ACCOUNT_AND_ORDER_TOOLS ),
			'extension owns payments, feature on'        => array( 'yes', true, true, WooPaymentsSetupTier::AVAILABLE, false, $all, $all ),
			'extension owns payments, no extra tools'    => array( 'yes', true, true, WooPaymentsSetupTier::AVAILABLE, false, array(), array() ),
		);
	}

	/**
	 * @testdox A pending remediation dry run alone leaves the remediation tools enabled.
	 *
	 * Client 11.1.0 disables both tools from `is_remediation_action_scheduled()`, which checks only the full-run hook
	 * (class-wc-payments-status.php:400-422).
	 */
	public function test_pending_dry_run_alone_leaves_remediation_tools_enabled(): void {
		$this->fake_plugin( false );
		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::CONNECTED, true );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
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
		$sut = new DebugTools();
		$sut->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( WooPaymentsSetupTier::class ) );

		$this->assertSame( $expected_message, $sut->$tool_callback() );
	}

	/**
	 * Get the System Under Test.
	 *
	 * @return DebugTools
	 */
	private function get_sut(): DebugTools {
		return wc_get_container()->get( DebugTools::class );
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
