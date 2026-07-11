<?php
/**
 * Narrow per-surface payments performance smoke probe (WooPayments → core merge harness).
 *
 * Measures the first in-process gateway resolution separately from warmed gateway surfaces.
 * Query count is the enforced narrow smoke signal; first-resolution time is a single diagnostic
 * sample, while warmed surface time is captured as a best-of-N median for context. Broader
 * design-spec §5.3 performance verification remains a separate stage gate.
 *
 * Run via the wrapper:
 *   WP="docker exec -i wcpay_wp_default wp --allow-root" perf-baseline.sh capture > baseline.json
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

global $wpdb;

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

$external_requests = 0;
add_filter(
	'pre_http_request',
	function ( $preempt ) use ( &$external_requests ) {
		++$external_requests;
		return $preempt;
	},
	PHP_INT_MIN,
	3
);

$normalize_query_sql = function ( string $query ): string {
	$sql = preg_replace( "/'[^']*'/", "'?'", $query );
	$sql = is_string( $sql ) ? preg_replace( '/"[^"]*"/', '"?"', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\b\d+\b/', '?', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\s+/', ' ', trim( $sql ) ) : $query;

	return is_string( $sql ) ? $sql : $query;
};

$summarize_query_groups = function ( int $start, int $end ) use ( $normalize_query_sql, $wpdb ): array {
	if ( ! is_array( $wpdb->queries ) || $end <= $start ) {
		return array();
	}

	$groups = array();
	foreach ( array_slice( $wpdb->queries, $start, $end - $start ) as $entry ) {
		$query = is_array( $entry ) ? (string) ( $entry[0] ?? '' ) : (string) $entry;
		if ( '' === $query ) {
			continue;
		}

		$sql = $normalize_query_sql( $query );
		if ( ! isset( $groups[ $sql ] ) ) {
			$groups[ $sql ] = array(
				'count'       => 0,
				'sql'         => $sql,
				'top_callers' => array(),
			);
		}
		++$groups[ $sql ]['count'];
	}

	usort(
		$groups,
		function ( array $a, array $b ): int {
			if ( $a['count'] === $b['count'] ) {
				return strcmp( $a['sql'], $b['sql'] );
			}
			return $b['count'] <=> $a['count'];
		}
	);

	return array_slice( $groups, 0, 10 );
};

/**
 * Measure one invocation without warming or repeating it.
 *
 * @param callable $op The operation to measure.
 * @return array<string,mixed>
 */
$measure_once = function ( callable $op ) use ( &$external_requests, $summarize_query_groups, $wpdb ): array {
	$q_before    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_before = $external_requests;
	$start       = microtime( true );
	$thrown      = null;

	try {
		$op();
	} catch ( Throwable $throwable ) {
		$thrown = $throwable;
	}

	$elapsed_ms  = ( microtime( true ) - $start ) * 1000.0;
	$q_after     = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_after  = $external_requests;
	$query_delta = max( 0, $q_after - $q_before );

	return array(
		'queries'             => $query_delta,
		'external_requests'   => max( 0, $http_after - $http_before ),
		'elapsed_ms'          => round( $elapsed_ms, 2 ),
		'timing_sample_count' => 1,
		'threw'               => null !== $thrown,
		'throwable_class'     => $thrown ? get_class( $thrown ) : '',
		'top_query_groups'    => $query_delta > 0 ? $summarize_query_groups( $q_before, $q_after ) : array(),
	);
};

/**
 * Measure one surface: query-count delta + median wall time over $iterations.
 *
 * @param string   $name       Surface name.
 * @param callable $op         The operation to measure.
 * @param int      $iterations How many times to run for the time median (query count uses the first warm run).
 * @return array<string,mixed>
 */
$measure = function ( string $name, callable $op, int $iterations = 5 ) use ( $wpdb ) {
	// Warm caches once so the measured cost is steady-state, not cache-cold one-offs.
	$op();

	$q_before = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$op();
	$q_after = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$queries = $q_after - $q_before;

	$times = array();
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start = microtime( true );
		$op();
		$times[] = ( microtime( true ) - $start ) * 1000.0;
	}
	sort( $times );
	$median_ms = $times[ (int) floor( count( $times ) / 2 ) ];

	return array(
		'queries'             => $queries,
		'median_ms'           => round( $median_ms, 2 ),
		'timing_sample_count' => count( $times ),
		'measurement_mode'    => 'warmed_repeated',
	);
};

$instance_property      = new ReflectionProperty( WC_Payment_Gateways::class, '_instance' );
$gateway_preinitialized = null !== $instance_property->getValue();

$first_gateways                   = array();
$first_gateway_resolution_metrics = $measure_once(
	function () use ( &$first_gateways ) {
		$first_gateways = WC()->payment_gateways()->payment_gateways();
	}
);
$first_gateway_resolution_metrics['gateway_preinitialized'] = $gateway_preinitialized;
$first_gateway_resolution_metrics['measurement_mode']       = $gateway_preinitialized
	? 'preinitialized_first_observed_call'
	: 'first_in_process_gateway_resolution';
$first_gateway_ids = array();
foreach ( is_array( $first_gateways ) ? $first_gateways : array() as $gateway ) {
	if ( is_object( $gateway ) && isset( $gateway->id ) ) {
		$first_gateway_ids[] = (string) $gateway->id;
	}
}
$first_gateway_resolution_metrics['gateway_count'] = count( $first_gateway_ids );
$first_gateway_resolution_metrics['gateway_ids']   = $first_gateway_ids;

$first_gateway_resolution = array(
	'status'  => $gateway_preinitialized || $first_gateway_resolution_metrics['threw'] ? 'incomplete' : 'measured',
	'metrics' => $first_gateway_resolution_metrics,
);
if ( $gateway_preinitialized ) {
	$first_gateway_resolution['reason'] = 'The gateway registry was initialized before the probe; this is only the first observed call.';
} elseif ( $first_gateway_resolution_metrics['threw'] ) {
	$first_gateway_resolution['reason'] = 'The first gateway resolution threw before it completed.';
}

$surfaces = array();

// Surface 1: checkout gateway resolution — the hot path on every checkout render.
$surfaces['available_gateways'] = $measure(
	'available_gateways',
	function () {
		WC()->payment_gateways()->get_available_payment_gateways();
	}
);

// Surface 2: full gateway list construction (admin + checkout shared).
$surfaces['all_gateways'] = $measure(
	'all_gateways',
	function () {
		WC()->payment_gateways()->payment_gateways();
	}
);

// Surface 3: WooPayments gateway availability check (its is_available path).
$surfaces['wcpay_is_available'] = $measure(
	'wcpay_is_available',
	function () {
		$gateways = WC()->payment_gateways()->payment_gateways();
		foreach ( $gateways as $gateway ) {
			if ( 'woocommerce_payments' === $gateway->id ) {
				$gateway->is_available();
				break;
			}
		}
	}
);

$result = array(
	'first_gateway_resolution' => $first_gateway_resolution,
	'php_version'              => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
	'wc_version'               => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
	'has_wcpay'                => class_exists( 'WC_Payments' ),
	'peak_mem_mb'              => round( memory_get_peak_usage( true ) / 1048576, 1 ),
	'surfaces'                 => $surfaces,
);
ksort( $result );

WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
