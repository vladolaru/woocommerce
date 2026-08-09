<?php
/**
 * EnvironmentIsolation class file.
 *
 * @package Automattic\WooCommerce\Testing\Tools
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Testing\Tools;

use Automattic\Jetpack\Constants;

/**
 * Restores a production-shaped baseline for the PHPUnit run.
 *
 * The PHPUnit container is not a clean WordPress install. It inherits whatever
 * the surrounding development environment injects:
 *
 * - wp-env applies its top-level `mappings` and `config` blocks to *every*
 *   environment, so an mu-plugin or constant meant for the served E2E site is
 *   also present here.
 * - `.wp-env.override.json` is git-ignored, so a developer's local wiring — for
 *   example pointing WPCOM API calls at a local WPCOM — reaches this container
 *   too.
 * - External tooling installs its own mu-plugins into the same directory.
 *
 * That produces the worst possible asymmetry: tests pass on CI, where none of
 * this exists, and fail on a correctly configured developer machine. Every such
 * failure looks like a defect in the code under test rather than in the
 * environment, and the cost is paid again by whoever hits it next.
 *
 * Rather than have each test defend itself, this normalizes the ambient state
 * once, before any test runs. Tests that genuinely need one of these signals
 * still opt in explicitly, which is the contract they were written against.
 *
 * Adding to the lists below is the correct response to discovering a new leak.
 */
final class EnvironmentIsolation {

	/**
	 * Constants a development environment may override, with the value a test
	 * run must observe. These are pinned rather than cleared because the
	 * production default is what assertions are written against.
	 *
	 * @var array<string,mixed>
	 */
	private const PINNED_CONSTANTS = array(
		// Local WPCOM setups point this at a local host, which rewrites every
		// WPCOM API URL the code under test builds.
		'JETPACK__WPCOM_JSON_API_BASE' => 'https://public-api.wordpress.com',
	);

	/**
	 * Constants that must appear absent to a test run. Cleared rather than
	 * pinned because their meaning is "this environment is an E2E site", which
	 * is never true of a PHPUnit run.
	 *
	 * @var string[]
	 */
	private const CLEARED_CONSTANTS = array(
		// Activates the native WooPayments runtime for the served E2E store.
		'E2E_WOOPAYMENTS_NATIVE',
	);

	/**
	 * Hooks that environment-injected mu-plugins register, which would
	 * otherwise silently decide behaviour for every test.
	 *
	 * @var string[]
	 */
	private const CLEARED_HOOKS = array(
		// Decides whether core-native payments own the site. Tests that want
		// native ownership add this filter themselves.
		'woocommerce_native_payments_enabled',
	);

	/**
	 * Apply the baseline.
	 *
	 * Runs on `muplugins_loaded` after WooCommerce is loaded, so the Jetpack
	 * constants layer is autoloadable, and still long before the WooCommerce
	 * singleton is constructed on `plugins_loaded`.
	 */
	public static function apply(): void {
		foreach ( self::PINNED_CONSTANTS as $name => $value ) {
			Constants::set_constant( $name, $value );
		}

		foreach ( self::CLEARED_CONSTANTS as $name ) {
			Constants::set_constant( $name, null );
		}

		foreach ( self::CLEARED_HOOKS as $hook ) {
			remove_all_filters( $hook );
		}
	}

	/**
	 * Describe the baseline this class guarantees.
	 *
	 * Exposed so a guard test can assert the baseline actually held, rather
	 * than trusting that it was applied.
	 *
	 * @return array{pinned_constants:array<string,mixed>,cleared_constants:string[],cleared_hooks:string[]}
	 */
	public static function get_expected_baseline(): array {
		return array(
			'pinned_constants'  => self::PINNED_CONSTANTS,
			'cleared_constants' => self::CLEARED_CONSTANTS,
			'cleared_hooks'     => self::CLEARED_HOOKS,
		);
	}
}
