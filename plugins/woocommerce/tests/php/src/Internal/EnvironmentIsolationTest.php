<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\Jetpack\Constants;
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
	 * @testdox Should observe E2E-only constants as absent.
	 */
	public function test_e2e_only_constants_are_absent(): void {
		foreach ( EnvironmentIsolation::get_expected_baseline()['cleared_constants'] as $name ) {
			$this->assertNull(
				Constants::get_constant( $name ),
				"The constant {$name} marks a served E2E site and must never be set during tests."
			);
		}
	}

	/**
	 * @testdox Should start with no ambient listeners on environment-injected hooks.
	 */
	public function test_environment_injected_hooks_have_no_ambient_listeners(): void {
		foreach ( EnvironmentIsolation::get_expected_baseline()['cleared_hooks'] as $hook ) {
			$this->assertFalse(
				has_filter( $hook ),
				"The hook {$hook} must have no listeners a test did not add itself; an mu-plugin registered one."
			);
		}
	}

	/**
	 * @testdox Should leave native payments unowned unless a test asks for it.
	 */
	public function test_native_payments_is_unowned_by_default(): void {
		// The end the isolation exists to protect: the concrete decision the
		// leaked filter was silently making for every other test in this suite.
		$this->assertFalse(
			// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Reading the runtime signal under test.
			(bool) apply_filters( 'woocommerce_native_payments_enabled', false ),
			'Native payments ownership must be opt-in per test, not an ambient property of the environment.'
		);
	}
}
