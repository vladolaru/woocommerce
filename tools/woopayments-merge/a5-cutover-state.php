<?php
/**
 * Local A5 WooPayments native cutover state probe.
 *
 * Run through WP-CLI:
 * wp eval-file tools/woopayments-merge/a5-cutover-state.php
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;

$payload = array(
	'home_url'              => function_exists( 'home_url' ) ? home_url() : '',
	'current_user_id'       => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
	'active_plugin_slugs'   => function_exists( 'get_option' ) ? woopayments_merge_a5_cutover_active_plugin_slugs() : array(),
	'runtime_owner'         => null,
	'native_runtime_enabled' => null,
	'plugin_runtime_active' => null,
	'should_native_register' => null,
	'preflight_failures'    => array(),
	'soft_notice'           => null,
	'ready'                 => false,
	'failures'              => array(),
);

$failures = array();
$arbiter  = null;
$cutover  = null;

if ( ! class_exists( NativePaymentsRuntimeArbiter::class ) ) {
	$failures[] = 'native_payments_runtime_arbiter_class_unavailable';
}

if ( ! class_exists( WooPaymentsCutoverController::class ) ) {
	$failures[] = 'woopayments_cutover_controller_class_unavailable';
}

if ( ! function_exists( 'wc_get_container' ) ) {
	$failures[] = 'woocommerce_container_unavailable';
}

if ( empty( $failures ) ) {
	try {
		$container = wc_get_container();
		$arbiter   = $container->get( NativePaymentsRuntimeArbiter::class );
		$cutover   = $container->get( WooPaymentsCutoverController::class );
	} catch ( Throwable $e ) {
		$failures[] = 'container_resolution_exception:' . get_class( $e );
	}
}

if ( is_object( $arbiter ) ) {
	$payload['runtime_owner']          = woopayments_merge_a5_cutover_call( $arbiter, 'get_runtime_owner', $failures );
	$payload['native_runtime_enabled'] = woopayments_merge_a5_cutover_call( $arbiter, 'is_native_runtime_enabled', $failures );
	$payload['plugin_runtime_active']  = woopayments_merge_a5_cutover_call( $arbiter, 'is_plugin_runtime_active', $failures );
	$payload['should_native_register'] = woopayments_merge_a5_cutover_call( $arbiter, 'should_native_register', $failures );
}

if ( is_object( $cutover ) ) {
	$preflight_failures = woopayments_merge_a5_cutover_call( $cutover, 'get_preflight_failures', $failures );
	if ( null !== $preflight_failures && ! is_array( $preflight_failures ) ) {
		$failures[] = 'preflight_failures_not_array';
	}

	$payload['preflight_failures'] = woopayments_merge_a5_cutover_sanitize_failures(
		$preflight_failures
	);
	$payload['soft_notice']        = woopayments_merge_a5_cutover_call( $cutover, 'should_show_soft_cutover_notice', $failures );
}

woopayments_merge_a5_cutover_check_consistency( $payload, $failures );

$payload['ready']    = empty( $failures ) && empty( $payload['preflight_failures'] );
$payload['failures'] = array_values( array_unique( array_filter( array_map( 'strval', $failures ) ) ) );

woopayments_merge_a5_cutover_emit_json( $payload, empty( $payload['failures'] ) ? 0 : 1 );

/**
 * Return active plugin slugs without leaking plugin metadata.
 *
 * @return array<int,string>
 */
function woopayments_merge_a5_cutover_active_plugin_slugs(): array {
	$plugins = (array) get_option( 'active_plugins', array() );

	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$slugs = array();
	foreach ( $plugins as $plugin ) {
		$parts   = explode( '/', (string) $plugin );
		$slugs[] = sanitize_key( $parts[0] );
	}

	$slugs = array_values( array_unique( array_filter( $slugs ) ) );
	sort( $slugs );

	return $slugs;
}

/**
 * Safely call a public no-argument service method.
 *
 * @param object             $service Service instance.
 * @param string             $method Method name.
 * @param array<int,string>  $failures Failure codes.
 * @return mixed
 */
function woopayments_merge_a5_cutover_call( object $service, string $method, array &$failures ) {
	if ( ! is_callable( array( $service, $method ) ) ) {
		$failures[] = 'method_unavailable:' . get_class( $service ) . '::' . $method;
		return null;
	}

	try {
		return $service->{$method}();
	} catch ( Throwable $e ) {
		$failures[] = 'method_exception:' . get_class( $service ) . '::' . $method . ':' . get_class( $e );
		return null;
	}
}

/**
 * Sanitize failure-code arrays for JSON output.
 *
 * @param mixed $failures Failure values.
 * @return array<int,string>
 */
function woopayments_merge_a5_cutover_sanitize_failures( $failures ): array {
	if ( ! is_array( $failures ) ) {
		return array( 'preflight_failures_invalid' );
	}

	$sanitized = array();
	foreach ( $failures as $failure ) {
		$failure = sanitize_key( (string) $failure );
		if ( '' !== $failure ) {
			$sanitized[] = $failure;
		}
	}

	return array_values( array_unique( $sanitized ) );
}

/**
 * Add failures for contradictory runtime/cutover state.
 *
 * @param array<string,mixed> $payload Probe payload.
 * @param array<int,string>   $failures Failure codes.
 */
function woopayments_merge_a5_cutover_check_consistency( array $payload, array &$failures ): void {
	$owner = $payload['runtime_owner'];

	if ( ! in_array( $owner, array( 'plugin', 'native', 'none' ), true ) ) {
		$failures[] = 'runtime_owner_invalid';
	}

	if ( null !== $payload['native_runtime_enabled'] && ! is_bool( $payload['native_runtime_enabled'] ) ) {
		$failures[] = 'native_runtime_enabled_not_boolean';
	}

	if ( null !== $payload['plugin_runtime_active'] && ! is_bool( $payload['plugin_runtime_active'] ) ) {
		$failures[] = 'plugin_runtime_active_not_boolean';
	}

	if ( null !== $payload['should_native_register'] && ! is_bool( $payload['should_native_register'] ) ) {
		$failures[] = 'should_native_register_not_boolean';
	}

	if ( null !== $payload['soft_notice'] && ! is_bool( $payload['soft_notice'] ) ) {
		$failures[] = 'soft_notice_not_boolean';
	}

	if ( is_string( $owner ) && is_bool( $payload['plugin_runtime_active'] ) && ( 'plugin' === $owner ) !== $payload['plugin_runtime_active'] ) {
		$failures[] = 'plugin_runtime_active_owner_mismatch';
	}

	if ( is_string( $owner ) && is_bool( $payload['should_native_register'] ) && ( 'native' === $owner ) !== $payload['should_native_register'] ) {
		$failures[] = 'should_native_register_owner_mismatch';
	}

	if ( 'native' === $owner && false === $payload['native_runtime_enabled'] ) {
		$failures[] = 'native_owner_runtime_disabled';
	}

	if ( true === $payload['plugin_runtime_active'] && true === $payload['should_native_register'] ) {
		$failures[] = 'dual_runtime_active';
	}

	if ( true === $payload['soft_notice'] ) {
		if ( true !== $payload['plugin_runtime_active'] ) {
			$failures[] = 'soft_notice_without_plugin_owner';
		}

		if ( true !== $payload['native_runtime_enabled'] ) {
			$failures[] = 'soft_notice_without_native_runtime_enabled';
		}

		if ( ! empty( $payload['preflight_failures'] ) ) {
			$failures[] = 'soft_notice_with_preflight_failures';
		}
	}
}

/**
 * Emit JSON and exit through WP-CLI when available.
 *
 * @param array<string,mixed> $payload Payload.
 * @param int                 $exit_code Exit code.
 */
function woopayments_merge_a5_cutover_emit_json( array $payload, int $exit_code ): void {
	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT );
	if ( false === $json ) {
		$json      = '{"ready":false,"failures":["json_encode_failed"]}';
		$exit_code = 1;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::line( $json );
		if ( 0 !== $exit_code ) {
			WP_CLI::halt( $exit_code );
		}
		return;
	}

	echo $json . PHP_EOL;
	exit( $exit_code );
}
