<?php
/**
 * EnvironmentIsolation class file.
 *
 * @package Automattic\WooCommerce\Testing\Tools
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Testing\Tools;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;

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
	 * Environment variable that sets the run's WooPayments feature baseline: 'yes' runs the suite with the feature on (the
	 * second CI leg); anything else keeps the install value, 'no'.
	 */
	private const WOOPAYMENTS_FEATURE_ENV = 'WOOPAYMENTS_FEATURE';

	/**
	 * Native payments test namespaces kept off the network: no native test may depend on an outside service, so they get a fixed geolocation answer and any un-mocked HTTP request fails.
	 *
	 * @var string[]
	 */
	private const OFFLINE_TEST_NAMESPACES = array(
		'Automattic\\WooCommerce\\Tests\\Internal\\Payments\\',
		'Automattic\\WooCommerce\\Tests\\Internal\\MultiCurrency\\',
		'Automattic\\WooCommerce\\Tests\\Internal\\Admin\\Settings\\PaymentsProviders\\',
	);

	/**
	 * Country the fixed geolocation answer reports.
	 */
	private const OFFLINE_GEOLOCATION_COUNTRY = 'US';

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

		foreach ( self::get_restored_options() as $option => $value ) {
			update_option( $option, $value );
		}
	}

	/**
	 * Options a development store may have changed, with the value a test starts from. A test that needs another value
	 * writes it itself.
	 *
	 * @return array<string,string>
	 */
	private static function get_restored_options(): array {
		return array(
			// The WooPayments feature turns the built-in WooPayments on; the program's stores keep it on.
			WooPaymentsFeatureController::FEATURE_ENABLE_OPTION => 'yes' === getenv( self::WOOPAYMENTS_FEATURE_ENV ) ? 'yes' : 'no',
		);
	}

	/**
	 * Apply the per-test parts of the baseline that depend on the test class.
	 *
	 * Runs from each test's setUp, after the hooks backup, so WordPress removes what it adds when the test ends. A test
	 * that is about geolocation adds its own `woocommerce_geolocate_ip` filter and wins; a test that expects a request
	 * answers it with its own `pre_http_request` callback, which runs before the refusal.
	 *
	 * @param string $test_class Fully qualified name of the running test class.
	 */
	public static function apply_for_test( string $test_class ): void {
		foreach ( self::OFFLINE_TEST_NAMESPACES as $namespace ) {
			if ( 0 === strpos( $test_class, $namespace ) ) {
				add_filter( 'woocommerce_geolocate_ip', array( self::class, 'answer_offline_geolocation' ) );
				add_filter( 'pre_http_request', array( self::class, 'refuse_unmocked_request' ), PHP_INT_MAX, 3 );
				return;
			}
		}
	}

	/**
	 * Fail an HTTP request that no test callback answered, naming it, instead of letting it reach the network.
	 *
	 * @param mixed  $response Response from earlier callbacks, or false.
	 * @param mixed  $args     Request arguments.
	 * @param string $url      Request URL.
	 * @return mixed
	 */
	public static function refuse_unmocked_request( $response, $args, $url ) {
		if ( false !== $response ) {
			return $response;
		}

		$method = is_array( $args ) && isset( $args['method'] ) ? (string) $args['method'] : 'GET';

		return new \WP_Error( 'woocommerce_test_unmocked_http_request', sprintf( 'Un-mocked HTTP request in a native payments test: %s %s', $method, $url ) );
	}

	/**
	 * Answer a geolocation lookup without the network, unless an earlier callback already answered.
	 *
	 * @param mixed $country_code Country code from earlier callbacks, or false.
	 * @return mixed
	 */
	public static function answer_offline_geolocation( $country_code ) {
		return false === $country_code ? self::OFFLINE_GEOLOCATION_COUNTRY : $country_code;
	}

	/**
	 * Describe the baseline this class guarantees.
	 *
	 * Exposed so a guard test can assert the baseline actually held, rather
	 * than trusting that it was applied.
	 *
	 * @return array{pinned_constants:array<string,mixed>,restored_options:array<string,string>}
	 */
	public static function get_expected_baseline(): array {
		return array(
			'pinned_constants' => self::PINNED_CONSTANTS,
			'restored_options' => self::get_restored_options(),
		);
	}
}
