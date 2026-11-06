<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Testing\Tools\EnvironmentIsolation;
use PHPUnit\Framework\AssertionFailedError;
use WC_Unit_Test_Case;

/**
 * Guards the baseline that makes this suite independent of the machine it runs on.
 *
 * The PHPUnit container inherits state from the surrounding development
 * environment: wp-env applies its top-level mappings and config to every
 * environment, `.wp-env.override.json` is git-ignored local wiring, and other
 * tooling installs its own mu-plugins alongside. Left alone, that state decides
 * behaviour for unrelated tests, and the resulting failures appear only on
 * configured developer machines while CI stays green.
 *
 * If a test here fails, the environment leaked something new. Fix it by adding
 * the leak to EnvironmentIsolation rather than by defending the test that
 * happened to notice.
 */
class EnvironmentIsolationTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should observe production values for constants a local environment may override.
	 */
	public function test_locally_overridable_constants_hold_production_values(): void {
		foreach ( EnvironmentIsolation::get_expected_baseline()['pinned_constants'] as $name => $expected ) {
			$this->assertSame(
				$expected,
				Constants::get_constant( $name ),
				"The constant {$name} must hold its production value during tests; this environment overrode it."
			);
		}
	}

	/**
	 * @testdox Should start from the install value of options a development store may have changed.
	 */
	public function test_restored_options_hold_their_install_values(): void {
		foreach ( EnvironmentIsolation::get_expected_baseline()['restored_options'] as $option => $expected ) {
			$this->assertSame( $expected, get_option( $option ), "The option {$option} must start at its install value unless a test opts in." );
		}
	}

	/**
	 * @testdox Should run the built-in WooPayments only on the run that asks for it through WOOPAYMENTS_FEATURE.
	 */
	public function test_builtin_woopayments_follows_the_run_baseline(): void {
		// The end the isolation exists to protect: a store where the WooPayments feature is on must not make every test run under the
		// built-in WooPayments. Only the feature-on CI leg sets WOOPAYMENTS_FEATURE=yes.
		$feature_on = 'yes' === getenv( 'WOOPAYMENTS_FEATURE' );

		$this->assertSame( $feature_on ? 'yes' : 'no', get_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION ) );
		$this->assertSame( $feature_on, wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_builtin_enabled(), 'The built-in WooPayments runs only on the leg that asks for it.' );
	}

	/**
	 * @testdox Should fail the test that makes an HTTP request nothing answered, even though the code under test only receives an error.
	 */
	public function test_unmocked_http_request_fails_the_test_that_made_it(): void {
		$response = wp_remote_get( 'https://example.test/unmocked' );

		$this->assertWPError( $response );
		$this->expectException( AssertionFailedError::class );
		$this->expectExceptionMessage( 'GET https://example.test/unmocked' );

		EnvironmentIsolation::assert_no_unmocked_requests();
	}

	/**
	 * @testdox Should give the next test the run's own session when an earlier test left the base class's mock session, whatever real session came before.
	 */
	public function test_mock_session_left_by_an_earlier_test_is_replaced_before_the_next_test(): void {
		// Some tests install a real session of their own and leave it, as WC_Cart_Test does.
		$other_session = new \WC_Session_Handler();
		$other_session->init();
		WC()->session = $other_session;

		$this->leave_mock_session_then_start_next_test();
		$run_session = WC()->session;

		$this->assertNotInstanceOf( \WC_Mock_Session_Handler::class, $run_session, 'The next test does not start with the mock.' );
		$this->assertNotSame( $other_session, $run_session, 'The next test starts with the run\'s session, not one an earlier test installed.' );

		add_filter( 'woocommerce_session_handler', array( $this, 'set_mock_session_handler' ) );
		$this->leave_mock_session_then_start_next_test();

		$this->assertSame( $run_session, WC()->session, 'Every next test starts with the same run session object.' );
	}

	/**
	 * @testdox Should give the next test a clean session when the run's session had notices and data before a mock replaced it.
	 */
	public function test_restored_session_keeps_no_state_from_before_a_mock_replaced_it(): void {
		$this->leave_mock_session_then_start_next_test();
		$customer_id = WC()->session->get_customer_id();

		// An earlier test writes into the run's session, then initializes the session under the base class filter.
		wc_add_notice( 'Left by an earlier test.', 'error' );
		WC()->session->set( 'left_by_an_earlier_test', 'yes' );
		add_filter( 'woocommerce_session_handler', array( $this, 'set_mock_session_handler' ) );
		$this->leave_mock_session_then_start_next_test();

		$this->assertNotInstanceOf( \WC_Mock_Session_Handler::class, WC()->session, 'The next test does not start with the mock.' );
		$this->assertSame( 0, wc_notice_count(), 'The next test sees no notice from before the mock.' );
		$this->assertNull( WC()->session->get( 'left_by_an_earlier_test' ), 'The next test sees no session data from before the mock.' );
		$this->assertNotSame( $customer_id, WC()->session->get_customer_id(), 'The next test gets a new customer, as a new request would.' );
	}

	/**
	 * Leave a mock session the way a polluting test does, clean up as the base class teardown does, then start the next test.
	 */
	private function leave_mock_session_then_start_next_test(): void {
		WC()->initialize_session();
		$this->assertInstanceOf( \WC_Mock_Session_Handler::class, WC()->session, 'Under the base class filter, initializing the session gives the mock.' );

		$this->clear_wc_singleton_state();

		// The next test's setUp applies the baseline before the base class adds its session filter again.
		remove_filter( 'woocommerce_session_handler', array( $this, 'set_mock_session_handler' ) );
		EnvironmentIsolation::apply_for_test( static::class );
	}
}
