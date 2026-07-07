<?php
/**
 * Local A5 user-token readiness probe.
 *
 * Run through WP-CLI:
 * wp eval-file tools/woopayments-merge/a5-user-token-readiness.php
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsHttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPlatformConnectionService;

$failures = array();
$manager  = null;

$payload = array(
	'home_url'                            => home_url(),
	'current_user_id'                     => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
	'jetpack_options_class_available'     => class_exists( 'Jetpack_Options' ),
	'jetpack_connection_class_available'  => class_exists( JetpackConnection::class ),
	'jetpack_manager_available'           => false,
	'blog_id'                             => null,
	'has_blog_id'                         => false,
	'is_store_connected'                  => false,
	'connection_owner_id'                 => 0,
		'has_connection_owner'                   => false,
		'connection_owner_user_token_present'    => false,
		'native_transport_client_available'      => class_exists( WooPaymentsHttpClient::class ),
		'native_transport_is_connected'          => false,
		'native_transport_blog_id'               => null,
		'native_platform_service_available'      => class_exists( WooPaymentsPlatformConnectionService::class ),
		'native_platform_readiness_failures'     => array(),
		'active_plugin_slugs'                    => woopayments_merge_a5_active_plugin_slugs(),
);

if ( $payload['jetpack_options_class_available'] ) {
	$blog_id                = Jetpack_Options::get_option( 'id' );
	$payload['blog_id']     = is_numeric( $blog_id ) ? (int) $blog_id : null;
	$payload['has_blog_id'] = null !== $payload['blog_id'] && $payload['blog_id'] > 0;
}

if ( $payload['jetpack_connection_class_available'] ) {
	try {
		$manager                              = JetpackConnection::get_manager();
		$payload['jetpack_manager_available'] = is_object( $manager );
	} catch ( Throwable $e ) {
		$payload['native_platform_readiness_failures'][] = 'jetpack_manager_exception:' . $e->getMessage();
	}
}

if ( is_object( $manager ) ) {
	try {
		$payload['is_store_connected'] = is_callable( array( $manager, 'is_connected' ) ) && (bool) $manager->is_connected();
	} catch ( Throwable $e ) {
		$payload['native_platform_readiness_failures'][] = 'is_connected_exception:' . $e->getMessage();
	}

	try {
		$owner_id                       = is_callable( array( $manager, 'get_connection_owner_id' ) ) ? (int) $manager->get_connection_owner_id() : 0;
		$payload['connection_owner_id'] = $owner_id;
		$payload['has_connection_owner'] = $owner_id > 0;
	} catch ( Throwable $e ) {
		$payload['native_platform_readiness_failures'][] = 'connection_owner_exception:' . $e->getMessage();
	}
}

if ( $payload['jetpack_options_class_available'] && $payload['connection_owner_id'] > 0 ) {
	$user_tokens = Jetpack_Options::get_option( 'user_tokens' );
	if ( is_array( $user_tokens ) ) {
		$payload['connection_owner_user_token_present'] = ! empty( $user_tokens[ $payload['connection_owner_id'] ] );
	}
}

if ( $payload['native_transport_client_available'] ) {
	try {
		$client                                   = new WooPaymentsHttpClient();
		$payload['native_transport_is_connected'] = $client->is_connected();
		$payload['native_transport_blog_id']      = $client->get_blog_id();
	} catch ( Throwable $e ) {
		$payload['native_platform_readiness_failures'][] = 'native_transport_exception:' . $e->getMessage();
	}
}

if ( $payload['native_platform_service_available'] ) {
	try {
		$platform_service = new WooPaymentsPlatformConnectionService();
		$payload['native_platform_readiness_failures'] = array_merge(
			$payload['native_platform_readiness_failures'],
			$platform_service->get_cutover_preflight_failures()
		);
	} catch ( Throwable $e ) {
		$payload['native_platform_readiness_failures'][] = 'native_platform_exception:' . $e->getMessage();
	}
}

foreach ( array(
	'jetpack_options_class_available',
	'jetpack_connection_class_available',
	'jetpack_manager_available',
	'has_blog_id',
	'is_store_connected',
	'has_connection_owner',
	'connection_owner_user_token_present',
	'native_transport_client_available',
	'native_transport_is_connected',
	'native_platform_service_available',
) as $required_key ) {
	if ( empty( $payload[ $required_key ] ) ) {
		$failures[] = $required_key;
	}
}

if ( null === $payload['native_transport_blog_id'] || (int) $payload['native_transport_blog_id'] <= 0 ) {
	$failures[] = 'native_transport_blog_id';
}

	$payload['ready']    = empty( $failures ) && empty( $payload['native_platform_readiness_failures'] );
	$payload['failures'] = array_values( array_unique( array_merge( $failures, $payload['native_platform_readiness_failures'] ) ) );

woopayments_merge_a5_emit_json( $payload, $payload['ready'] ? 0 : 1 );

/**
 * Return active plugin slugs without leaking plugin metadata.
 *
 * @return array<int,string>
 */
function woopayments_merge_a5_active_plugin_slugs(): array {
	$plugins = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
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
 * Emit JSON and exit through WP-CLI when available.
 *
 * @param array<string,mixed> $payload Payload.
 * @param int                 $exit_code Exit code.
 */
function woopayments_merge_a5_emit_json( array $payload, int $exit_code ): void {
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
