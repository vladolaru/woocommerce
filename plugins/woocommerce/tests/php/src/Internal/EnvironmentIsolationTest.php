<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Testing\Tools\EnvironmentIsolation;
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
}
