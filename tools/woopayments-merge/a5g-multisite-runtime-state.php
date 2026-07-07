<?php
/**
 * Local A5g WooPayments multisite runtime state probe.
 *
 * Run through WP-CLI:
 * wp eval-file tools/woopayments-merge/a5g-multisite-runtime-state.php --url=http://example.test
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;

add_filter( 'woocommerce_native_payments_enabled', '__return_true' );

$payload = array(
	'is_multisite'           => function_exists( 'is_multisite' ) ? is_multisite() : false,
	'blog_id'                => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
	'site_url'               => function_exists( 'site_url' ) ? site_url() : '',
	'home_url'               => function_exists( 'home_url' ) ? home_url() : '',
	'active_site_plugins'    => function_exists( 'get_option' ) ? woopayments_merge_a5g_plugin_files( (array) get_option( 'active_plugins', array() ) ) : array(),
	'network_active_plugins' => function_exists( 'get_site_option' ) ? woopayments_merge_a5g_plugin_files( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) : array(),
	'runtime_owner'          => null,
	'native_runtime_enabled' => null,
	'plugin_runtime_active'  => null,
	'should_native_register' => null,
	'ready'                  => false,
	'failures'               => array(),
);

$failures = array();
$arbiter  = null;

if ( ! $payload['is_multisite'] ) {
	$failures[] = 'not_multisite';
}

if ( ! class_exists( NativePaymentsRuntimeArbiter::class ) ) {
	$failures[] = 'native_payments_runtime_arbiter_class_unavailable';
}

if ( ! function_exists( 'wc_get_container' ) ) {
	$failures[] = 'woocommerce_container_unavailable';
}

if ( empty( $failures ) ) {
	try {
		$arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
	} catch ( Throwable $e ) {
		$failures[] = 'container_resolution_exception:' . get_class( $e );
	}
}

if ( is_object( $arbiter ) ) {
	$payload['runtime_owner']          = woopayments_merge_a5g_call( $arbiter, 'get_runtime_owner', $failures );
	$payload['native_runtime_enabled'] = woopayments_merge_a5g_call( $arbiter, 'is_native_runtime_enabled', $failures );
	$payload['plugin_runtime_active']  = woopayments_merge_a5g_call( $arbiter, 'is_plugin_runtime_active', $failures );
	$payload['should_native_register'] = woopayments_merge_a5g_call( $arbiter, 'should_native_register', $failures );
}

woopayments_merge_a5g_check_consistency( $payload, $failures );

$payload['ready']    = empty( $failures );
$payload['failures'] = array_values( array_unique( array_filter( array_map( 'strval', $failures ) ) ) );

woopayments_merge_a5g_emit_json( $payload, empty( $payload['failures'] ) ? 0 : 1 );

/**
 * Return normalized plugin file paths.
 *
 * @param array<int,string> $plugins Plugin file paths.
 * @return array<int,string>
 */
function woopayments_merge_a5g_plugin_files( array $plugins ): array {
	$files = array();
	foreach ( $plugins as $plugin ) {
		$plugin = sanitize_text_field( (string) $plugin );
		if ( '' !== $plugin ) {
			$files[] = $plugin;
		}
	}

	$files = array_values( array_unique( $files ) );
	sort( $files );

	return $files;
}

/**
 * Safely call a public no-argument service method.
 *
 * @param object            $service Service instance.
 * @param string            $method Method name.
 * @param array<int,string> $failures Failure codes.
 * @return mixed
 */
function woopayments_merge_a5g_call( object $service, string $method, array &$failures ) {
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
 * Add failures for contradictory runtime state.
 *
 * @param array<string,mixed> $payload Probe payload.
 * @param array<int,string>   $failures Failure codes.
 */
function woopayments_merge_a5g_check_consistency( array $payload, array &$failures ): void {
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

	if ( 'plugin' === $owner && true !== $payload['plugin_runtime_active'] ) {
		$failures[] = 'plugin_owner_without_plugin_runtime';
	}

	if ( 'native' === $owner && true !== $payload['should_native_register'] ) {
		$failures[] = 'native_owner_without_native_registration';
	}

	if ( 'native' === $owner && true !== $payload['native_runtime_enabled'] ) {
		$failures[] = 'native_owner_runtime_disabled';
	}

	if ( true === $payload['plugin_runtime_active'] && true === $payload['should_native_register'] ) {
		$failures[] = 'dual_runtime_active';
	}
}

/**
 * Emit JSON and exit through WP-CLI when available.
 *
 * @param array<string,mixed> $payload Payload.
 * @param int                 $exit_code Exit code.
 */
function woopayments_merge_a5g_emit_json( array $payload, int $exit_code ): void {
	$json = wp_json_encode( $payload, JSON_PRETTY_PRINT );
	if ( false === $json ) {
		$json      = '{"ready":false,"failures":["json_encode_failed"]}';
		$exit_code = 1;
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::line( $json );
		WP_CLI::halt( $exit_code );
	}

	echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Local WP-CLI probe emits machine JSON.
	exit( $exit_code );
}
