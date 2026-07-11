#!/usr/bin/env bash
#
# First-pass measured perf-surface gate for the WooPayments merge harness.
#
# Captures bounded WP-CLI probes for gateway registration, autoloaded option
# bytes, wcpay_account_data autoload behavior, REST route boot, and optional
# local order fixtures for blocked-HTTP money-path probes.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPARE="$SELF_DIR/compare-measured-gates.py"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	echo "ERROR: local runner safety library is missing: $LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"

usage() {
	cat >&2 <<'EOF'
usage:
  perf-surface-gate.sh capture --wp <wp command> --out <json> [--process-order-id <id>] [--refund-order-id <id>] [--capture-order-id <id>] [--payment-method <pm>] [--refund-amount <amount>]
  perf-surface-gate.sh compare --ref <json> --target <json> [--gateway-initialization-only]

Example:
  perf-surface-gate.sh capture --wp "docker exec -i wcpay_wp_default wp --allow-root" --out ref.json
EOF
	exit 2
}

validate_local_wp_cmd() {
	cmd="$1"
	local error
	if ! error="$(woopayments_validate_local_wp_runner "$cmd")"; then
		echo "ERROR: refusing unsafe WP-CLI command: $error" >&2
		exit 2
	fi
	if ! error="$(woopayments_validate_approved_docker_runner "$cmd")"; then
		echo "ERROR: perf capture --wp must use an approved local Docker WP-CLI command: $error" >&2
		exit 2
	fi
	case "$cmd" in
		*wpcom.com*|*wordpress.com*|*a8c.com*|*--ssh*|*--http*|*$'\n'*|*$'\r'*|*";"*|*"&"*|*"|"*|*"<"*|*">"*|*"\`"*|*'$('*)
			echo "ERROR: refusing non-local or shell-expanded WP-CLI command: $cmd" >&2
			exit 2
			;;
	esac
}

mode="${1:-}"
[ -n "$mode" ] || usage
shift

write_gateway_initialization_probe() {
	local probe_file="$1"
	cat > "$probe_file" <<'PHP'
<?php
$probe_token = (string) getenv( 'WPMERGE_PERF_GATEWAY_INIT_TOKEN' );
if (
	'' === $probe_token ||
	! preg_match( '/^woopayments-perf-gateway-init-([a-f0-9]{32})\.php$/', basename( __FILE__ ), $probe_filename ) ||
	! hash_equals( $probe_filename[1], $probe_token )
) {
	return;
}

global $wpdb;

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

$probe_result_key = 'woopayments_perf_gateway_initialization_probe';
$incomplete_probe = function ( string $reason, string $measurement_mode, bool $preinitialized ) use ( $probe_result_key ): void {
	$GLOBALS[ $probe_result_key ] = array(
		'status'  => 'incomplete',
		'reason'  => $reason,
		'metrics' => array(
			'queries'                 => 0,
			'external_requests'       => 0,
			'timing_sample_count'     => 0,
			'measurement_mode'        => $measurement_mode,
			'gateway_preinitialized'  => $preinitialized,
			'lifecycle_start_hook'    => 'woocommerce_payment_gateways',
			'lifecycle_end_hook'      => 'wc_payment_gateways_initialized',
			'caller_backtrace'        => array(),
			'top_query_groups'        => array(),
		),
	);
};

if ( did_action( 'wc_payment_gateways_initialized' ) > 0 ) {
	$incomplete_probe(
		'WooCommerce payment gateways were already initialized before the MU bootstrap probe loaded.',
		'too_late_gateway_initialization_observer',
		true
	);
	return;
}

$normalize_query_sql = function ( string $query ): string {
	$sql = preg_replace( "/'[^']*'/", "'?'", $query );
	$sql = is_string( $sql ) ? preg_replace( '/"[^"]*"/', '"?"', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\b\d+\b/', '?', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\s+/', ' ', trim( $sql ) ) : $query;

	return is_string( $sql ) ? $sql : $query;
};

$summarize_query_caller = function ( string $caller ): string {
	$frames = array_values(
		array_filter(
			array_map( 'trim', explode( ', ', $caller ) ),
			function ( string $frame ): bool {
				return '' !== $frame;
			}
		)
	);
	$summary = array_slice( $frames, 0, 10 );
	if ( count( $frames ) > count( $summary ) ) {
		$summary[] = '...';
	}

	return implode( ' -> ', $summary );
};

$summarize_query_groups = function ( int $start, int $end ) use ( &$wpdb, $normalize_query_sql, $summarize_query_caller ): array {
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
				'count'   => 0,
				'sql'     => $sql,
				'callers' => array(),
			);
		}

		++$groups[ $sql ]['count'];
		$caller = is_array( $entry ) ? $summarize_query_caller( (string) ( $entry[2] ?? '' ) ) : '';
		if ( '' !== $caller ) {
			$groups[ $sql ]['callers'][ $caller ] = ( $groups[ $sql ]['callers'][ $caller ] ?? 0 ) + 1;
		}
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

	return array_map(
		function ( array $group ): array {
			arsort( $group['callers'] );
			return array(
				'count'       => $group['count'],
				'sql'         => $group['sql'],
				'top_callers' => array_keys( array_slice( $group['callers'], 0, 3, true ) ),
			);
		},
		array_slice( $groups, 0, 10 )
	);
};

$state = array(
	'started'           => false,
	'finished'          => false,
	'query_start'       => 0,
	'external_requests' => 0,
	'start_time'        => 0.0,
	'caller_backtrace'  => array(),
);

add_filter(
	'pre_http_request',
	function ( $preempt, $parsed_args, $url ) use ( &$state ) {
		unset( $parsed_args );
		if ( ! $state['started'] || $state['finished'] ) {
			return $preempt;
		}

		++$state['external_requests'];
		return new WP_Error(
			'woopayments_gateway_initialization_probe_blocked_http',
			'External HTTP blocked by gateway initialization probe.',
			array( 'url' => $url )
		);
	},
	PHP_INT_MIN,
	3
);

add_filter(
	'woocommerce_payment_gateways',
	function ( $gateways ) use ( &$state, &$wpdb ) {
		if ( $state['started'] ) {
			return $gateways;
		}

		$state['started']     = true;
		$state['query_start'] = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$state['start_time']  = microtime( true );
		$backtrace            = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		$state['caller_backtrace'] = array_map(
			function ( array $frame ): string {
				$class = isset( $frame['class'] ) ? (string) $frame['class'] . (string) ( $frame['type'] ?? '' ) : '';
				return $class . (string) ( $frame['function'] ?? '<unknown>' );
			},
			array_slice( $backtrace, 0, 12 )
		);

		return $gateways;
	},
	PHP_INT_MIN,
	1
);

add_action(
	'wc_payment_gateways_initialized',
	function ( $gateway_registry ) use ( &$state, &$wpdb, $incomplete_probe, $probe_result_key, $summarize_query_groups ): void {
		if ( ! $state['started'] ) {
			$incomplete_probe(
				'The gateway initialization completion hook fired before the observer saw woocommerce_payment_gateways.',
				'missing_gateway_initialization_start',
				true
			);
			return;
		}
		if ( $state['finished'] ) {
			return;
		}

		$state['finished'] = true;
		$query_end         = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
		$gateways          = is_object( $gateway_registry ) && is_callable( array( $gateway_registry, 'payment_gateways' ) )
			? $gateway_registry->payment_gateways()
			: array();
		$gateway_ids       = array();
		foreach ( is_array( $gateways ) ? $gateways : array() as $gateway ) {
			if ( is_object( $gateway ) && isset( $gateway->id ) ) {
				$gateway_ids[] = (string) $gateway->id;
			}
		}

		$GLOBALS[ $probe_result_key ] = array(
			'status'  => 'measured',
			'metrics' => array(
				'queries'                 => max( 0, $query_end - $state['query_start'] ),
				'external_requests'       => $state['external_requests'],
				'elapsed_ms'              => round( ( microtime( true ) - $state['start_time'] ) * 1000.0, 2 ),
				'timing_sample_count'     => 1,
				'measurement_mode'        => 'gateway_initialization_lifecycle',
				'gateway_preinitialized'  => false,
				'gateway_count'           => count( $gateway_ids ),
				'gateway_ids'             => $gateway_ids,
				'lifecycle_start_hook'    => 'woocommerce_payment_gateways',
				'lifecycle_end_hook'      => 'wc_payment_gateways_initialized',
				'caller_backtrace'        => $state['caller_backtrace'],
				'top_query_groups'        => $summarize_query_groups( $state['query_start'], $query_end ),
			),
		);
	},
	PHP_INT_MAX,
	1
);
PHP
}

write_probe() {
	local probe_file="$1"
	cat > "$probe_file" <<'PHP'
<?php
global $wpdb, $wp_filter;

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

$external_requests = 0;
add_filter(
	'pre_http_request',
	function ( $preempt, $parsed_args, $url ) use ( &$external_requests ) {
		$external_requests++;
		return new WP_Error( 'woopayments_measured_gate_blocked_http', 'External HTTP blocked by measured perf gate.', array( 'url' => $url ) );
	},
	10,
	3
);

$progress = function ( string $message ): void {
	fwrite( STDERR, "[perf-surface-gate] {$message}\n" );
};

$count_hook_callbacks = function ( string $hook ) use ( &$wp_filter ): int {
	if ( empty( $wp_filter[ $hook ] ) || ! is_object( $wp_filter[ $hook ] ) || empty( $wp_filter[ $hook ]->callbacks ) ) {
		return 0;
	}
	$count = 0;
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		$count += is_array( $callbacks ) ? count( $callbacks ) : 0;
	}
	return $count;
};

$normalize_query_sql = function ( string $query ): string {
	$sql = preg_replace( "/'[^']*'/", "'?'", $query );
	$sql = is_string( $sql ) ? preg_replace( '/"[^"]*"/', '"?"', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\b\d+\b/', '?', $sql ) : $query;
	$sql = is_string( $sql ) ? preg_replace( '/\s+/', ' ', trim( $sql ) ) : $query;

	return is_string( $sql ) ? $sql : $query;
};

$summarize_query_caller = function ( string $caller ): string {
	if ( '' === $caller ) {
		return '';
	}

	$frames = array_values(
		array_filter(
			array_map( 'trim', explode( ', ', $caller ) ),
			function ( string $frame ): bool {
				return '' !== $frame;
			}
		)
	);
	if ( empty( $frames ) ) {
		return '';
	}

	$start_index = 0;
	foreach ( $frames as $index => $frame ) {
		if ( str_contains( $frame, 'EvalFile_Command::{closure}' ) ) {
			$start_index = $index + 1;
		}
	}

	$operation_frames = array_slice( $frames, $start_index );
	$summary_frames   = array_slice( $operation_frames, 0, 10 );
	if ( count( $operation_frames ) > count( $summary_frames ) ) {
		$summary_frames[] = '...';
	}

	return implode( ' -> ', $summary_frames );
};

$summarize_query_groups = function ( int $start, int $end ) use ( &$wpdb, $normalize_query_sql, $summarize_query_caller ): array {
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
				'count'   => 0,
				'sql'     => $sql,
				'callers' => array(),
			);
		}

		++$groups[ $sql ]['count'];
		$caller = is_array( $entry ) ? $summarize_query_caller( (string) ( $entry[2] ?? '' ) ) : '';
		if ( '' !== $caller ) {
			$groups[ $sql ]['callers'][ $caller ] = ( $groups[ $sql ]['callers'][ $caller ] ?? 0 ) + 1;
		}
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

	return array_map(
		function ( array $group ): array {
			arsort( $group['callers'] );
			return array(
				'count'       => $group['count'],
				'sql'         => $group['sql'],
				'top_callers' => array_keys( array_slice( $group['callers'], 0, 3, true ) ),
			);
		},
		array_slice( $groups, 0, 10 )
	);
};

$measure = function ( callable $op, int $iterations = 5 ) use ( &$wpdb, &$external_requests ): array {
	$op();
	$q_before    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_before = $external_requests;
	$op();
	$q_after    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_after = $external_requests;

	$times = array();
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start = microtime( true );
		$op();
		$times[] = ( microtime( true ) - $start ) * 1000.0;
	}
	sort( $times );

	return array(
		'queries'             => max( 0, $q_after - $q_before ),
		'external_requests'   => max( 0, $http_after - $http_before ),
		'median_ms'           => round( $times[ (int) floor( count( $times ) / 2 ) ], 2 ),
		'timing_sample_count' => count( $times ),
		'measurement_mode'    => 'warmed_repeated',
	);
};

$measure_once = function ( callable $op ) use ( &$wpdb, &$external_requests, $summarize_query_groups ): array {
	$q_before    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_before = $external_requests;
	$start       = microtime( true );
	$result      = null;
	$thrown      = null;

	try {
		$result = $op();
	} catch ( Throwable $throwable ) {
		$thrown = $throwable;
	}

	$elapsed_ms  = ( microtime( true ) - $start ) * 1000.0;
	$q_after     = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_after  = $external_requests;
	$query_delta = max( 0, $q_after - $q_before );

	$result_kind = gettype( $result );
	$result_code = '';
	if ( $thrown ) {
		$result_kind = 'throwable';
		$result_code = get_class( $thrown );
	} elseif ( is_wp_error( $result ) ) {
		$result_kind = 'wp_error';
		$result_code = $result->get_error_code();
	} elseif ( is_array( $result ) ) {
		$result_kind = 'array';
		$result_code = isset( $result['result'] ) ? (string) $result['result'] : '';
	} elseif ( is_bool( $result ) ) {
		$result_code = $result ? 'true' : 'false';
	} elseif ( is_scalar( $result ) ) {
		$result_code = (string) $result;
	}

	return array(
		'queries'             => $query_delta,
		'external_requests'   => max( 0, $http_after - $http_before ),
		'elapsed_ms'          => round( $elapsed_ms, 2 ),
		'timing_sample_count' => 1,
		'result_kind'         => $result_kind,
		'result_code'         => $result_code,
		'threw'               => null !== $thrown,
		'top_query_groups'    => $query_delta > 0 ? $summarize_query_groups( $q_before, $q_after ) : array(),
	);
};

$requires_fixture = function ( string $reason ): array {
	return array(
		'status' => 'requires_fixture',
		'reason' => $reason,
	);
};

$incomplete = function ( string $reason ): array {
	return array(
		'status' => 'incomplete',
		'reason' => $reason,
	);
};

$gateway_id = class_exists( '\Automattic\WooCommerce\Internal\Payments\OrderPaymentStore' )
	? \Automattic\WooCommerce\Internal\Payments\OrderPaymentStore::GATEWAY_ID
	: 'woocommerce_payments';

$get_gateway = function () use ( $gateway_id ) {
	$gateways = WC()->payment_gateways()->payment_gateways();
	return $gateways[ $gateway_id ] ?? null;
};

$tool_args        = isset( $args ) && is_array( $args ) ? $args : array();
$process_order_id = absint( $tool_args[0] ?? getenv( 'WPMERGE_PERF_PROCESS_ORDER_ID' ) );
$refund_order_id  = absint( $tool_args[1] ?? getenv( 'WPMERGE_PERF_REFUND_ORDER_ID' ) );
$capture_order_id = absint( $tool_args[2] ?? getenv( 'WPMERGE_PERF_CAPTURE_ORDER_ID' ) );
$payment_method   = (string) ( $tool_args[3] ?? getenv( 'WPMERGE_PERF_PAYMENT_METHOD' ) ?: 'pm_card_visa' );
$refund_amount    = (string) ( $tool_args[4] ?? getenv( 'WPMERGE_PERF_REFUND_AMOUNT' ) );

$measure_process_payment = function () use ( $gateway_id, $get_gateway, $incomplete, $measure_once, $payment_method, $process_order_id, $progress, $requires_fixture ): array {
	if ( $process_order_id <= 0 ) {
		return $requires_fixture( 'Supply --process-order-id for an unpaid local WooPayments order fixture. The probe blocks outbound HTTP and measures one gateway process_payment invocation.' );
	}

	$order = wc_get_order( $process_order_id );
	if ( ! $order instanceof WC_Order ) {
		return $incomplete( "Order #{$process_order_id} was not found." );
	}
	if ( $gateway_id !== $order->get_payment_method() ) {
		return $incomplete( "Order #{$process_order_id} payment method is {$order->get_payment_method()}, expected {$gateway_id}." );
	}
	if ( ! $order->needs_payment() ) {
		return $incomplete( "Order #{$process_order_id} does not need payment; re-processing paid orders is not deterministic." );
	}

	$gateway = $get_gateway();
	if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_payment' ) ) ) {
		return $incomplete( 'WooPayments gateway process_payment is not available.' );
	}

	$status_before                    = $order->get_status();
	$_POST['wcpay-payment-method']    = $payment_method;
	$_POST['wcpay-payment-method-id'] = $payment_method;

	$progress( "measuring process_payment on order #{$process_order_id} with outbound HTTP blocked" );
	$metrics = $measure_once(
		function () use ( $gateway, $process_order_id ) {
			return $gateway->process_payment( $process_order_id );
		}
	);
	if ( empty( $metrics['external_requests'] ) ) {
		return $incomplete( "Order #{$process_order_id} process_payment did not reach the blocked provider boundary." );
	}

	$order_after = wc_get_order( $process_order_id );

	return array(
		'status'  => 'measured',
		'metrics' => array_merge(
			$metrics,
			array(
				'order_id'            => $process_order_id,
				'order_status_before' => $status_before,
				'order_status_after'  => $order_after instanceof WC_Order ? $order_after->get_status() : '',
				'measurement_mode'    => 'single_invocation_blocked_http',
			)
		),
	);
};

$measure_refund = function () use ( $gateway_id, $get_gateway, $incomplete, $measure_once, $progress, $refund_amount, $refund_order_id, $requires_fixture ): array {
	if ( $refund_order_id <= 0 ) {
		return $requires_fixture( 'Supply --refund-order-id for a paid local WooPayments order fixture. The probe blocks outbound HTTP and measures one gateway process_refund invocation.' );
	}

	$order = wc_get_order( $refund_order_id );
	if ( ! $order instanceof WC_Order ) {
		return $incomplete( "Order #{$refund_order_id} was not found." );
	}
	if ( $gateway_id !== $order->get_payment_method() ) {
		return $incomplete( "Order #{$refund_order_id} payment method is {$order->get_payment_method()}, expected {$gateway_id}." );
	}

	$remaining = (float) $order->get_remaining_refund_amount();
	if ( $remaining <= 0.0 ) {
		return $incomplete( "Order #{$refund_order_id} has no remaining refundable amount." );
	}

	$amount = false === $refund_amount || '' === $refund_amount
		? max( 0.01, round( min( $remaining, max( 0.01, $remaining / 2 ) ), 2 ) )
		: round( (float) $refund_amount, 2 );
	if ( $amount <= 0.0 || $amount > $remaining ) {
		return $incomplete( "Refund amount {$amount} is invalid for remaining amount {$remaining}." );
	}

	$gateway = $get_gateway();
	if ( ! is_object( $gateway ) || ! is_callable( array( $gateway, 'process_refund' ) ) ) {
		return $incomplete( 'WooPayments gateway process_refund is not available.' );
	}

	$status_before = $order->get_status();
	$progress( "measuring refund on order #{$refund_order_id} for {$amount} with outbound HTTP blocked" );
	$metrics = $measure_once(
		function () use ( $amount, $gateway, $refund_order_id ) {
			return $gateway->process_refund( $refund_order_id, $amount, 'Harness perf probe blocked-HTTP refund' );
		}
	);
	if ( empty( $metrics['external_requests'] ) ) {
		return $incomplete( "Order #{$refund_order_id} refund did not reach the blocked provider boundary." );
	}

	$order_after = wc_get_order( $refund_order_id );

	return array(
		'status'  => 'measured',
		'metrics' => array_merge(
			$metrics,
			array(
				'order_id'            => $refund_order_id,
				'amount'              => $amount,
				'order_status_before' => $status_before,
				'order_status_after'  => $order_after instanceof WC_Order ? $order_after->get_status() : '',
				'measurement_mode'    => 'single_invocation_blocked_http',
			)
		),
	);
};

$measure_capture = function () use ( $capture_order_id, $gateway_id, $get_gateway, $incomplete, $measure_once, $progress, $requires_fixture ): array {
	if ( $capture_order_id <= 0 ) {
		return $requires_fixture( 'Supply --capture-order-id for an authorized local WooPayments order fixture. The probe blocks outbound HTTP and measures one gateway capture invocation.' );
	}

	$order = wc_get_order( $capture_order_id );
	if ( ! $order instanceof WC_Order ) {
		return $incomplete( "Order #{$capture_order_id} was not found." );
	}
	if ( $gateway_id !== $order->get_payment_method() ) {
		return $incomplete( "Order #{$capture_order_id} payment method is {$order->get_payment_method()}, expected {$gateway_id}." );
	}
	if ( '' === (string) $order->get_transaction_id() ) {
		return $incomplete( "Order #{$capture_order_id} has no transaction ID to capture." );
	}
	if ( 0.0 >= (float) $order->get_total() ) {
		return $incomplete( "Order #{$capture_order_id} has no positive total to capture." );
	}
	if ( $order->get_remaining_refund_amount() < (float) $order->get_total() ) {
		return $incomplete( "Order #{$capture_order_id} is partially or fully refunded; capture measurement would not hit the normal capture path." );
	}
	$intention_status = (string) $order->get_meta( '_intention_status', true );
	if ( 'requires_capture' !== $intention_status ) {
		return $incomplete( "Order #{$capture_order_id} intention status is {$intention_status}, expected requires_capture." );
	}

	$gateway = $get_gateway();
	if ( ! is_object( $gateway ) ) {
		return $incomplete( 'WooPayments gateway is not available.' );
	}

	$native_gateway_class = '\Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway';
	$is_native_gateway    = class_exists( $native_gateway_class ) && $gateway instanceof $native_gateway_class;
	if ( ! $is_native_gateway && ! is_callable( array( $gateway, 'capture_charge' ) ) ) {
		return $incomplete( 'WooPayments gateway capture path is not available.' );
	}

	$status_before = $order->get_status();
	$progress( "measuring capture on order #{$capture_order_id} with outbound HTTP blocked" );
	$metrics = $measure_once(
		function () use ( $capture_order_id, $gateway, $gateway_id, $is_native_gateway, $order ) {
			if ( $is_native_gateway ) {
				$container = wc_get_container();
				return $container->get( \Automattic\WooCommerce\Internal\Payments\PaymentProcessingService::class )->capture(
					\Automattic\WooCommerce\Internal\Payments\PaymentContext::for_capture( $order, $gateway_id ),
					$container->get( \Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::class )
				);
			}

			return $gateway->capture_charge( wc_get_order( $capture_order_id ) );
		}
	);
	if ( empty( $metrics['external_requests'] ) ) {
		return $incomplete( "Order #{$capture_order_id} capture did not reach the blocked provider boundary." );
	}

	$order_after = wc_get_order( $capture_order_id );

	return array(
		'status'  => 'measured',
		'metrics' => array_merge(
			$metrics,
			array(
				'order_id'            => $capture_order_id,
				'order_status_before' => $status_before,
				'order_status_after'  => $order_after instanceof WC_Order ? $order_after->get_status() : '',
				'measurement_mode'    => 'single_invocation_blocked_http',
			)
		),
	);
};

$progress( 'measuring warmed gateway registration' );
$gateway_measure = $measure(
	function () {
		WC()->payment_gateways()->payment_gateways();
	}
);
$gateways        = WC()->payment_gateways()->payment_gateways();
$gateway_initialization_probe = $GLOBALS['woopayments_perf_gateway_initialization_probe'] ?? array(
	'status'  => 'incomplete',
	'reason'  => 'The MU bootstrap observer did not see a complete gateway initialization lifecycle.',
	'metrics' => array(
		'queries'                => 0,
		'external_requests'      => 0,
		'timing_sample_count'    => 0,
		'measurement_mode'       => 'gateway_initialization_not_observed',
		'gateway_preinitialized' => true,
		'lifecycle_start_hook'   => 'woocommerce_payment_gateways',
		'lifecycle_end_hook'     => 'wc_payment_gateways_initialized',
		'caller_backtrace'       => array(),
		'top_query_groups'       => array(),
	),
);
$gateway_ids = array();
foreach ( $gateways as $gateway ) {
	if ( is_object( $gateway ) && isset( $gateway->id ) ) {
		$gateway_ids[] = (string) $gateway->id;
	}
}
$gateway_counts       = array_count_values( $gateway_ids );
$duplicate_gateway_ids = array_values(
	array_keys(
		array_filter(
			$gateway_counts,
			function ( $count ) {
				return $count > 1;
			}
		)
	)
);
$gateway_hooks = array(
	'woocommerce_payment_gateways',
	'woocommerce_blocks_payment_method_type_registration',
);
$gateway_hook_counts = array();
foreach ( $gateway_hooks as $hook ) {
	$gateway_hook_counts[ $hook ] = $count_hook_callbacks( $hook );
}

$progress( 'measuring autoload options' );
$autoload_rows = $wpdb->get_results(
	"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')",
	ARRAY_A
);
$autoload_bytes = 0;
foreach ( is_array( $autoload_rows ) ? $autoload_rows : array() as $row ) {
	$autoload_bytes += strlen( (string) $row['option_name'] ) + strlen( (string) $row['option_value'] );
}

$account_row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
		'wcpay_account_data'
	),
	ARRAY_A
);
$account_autoload = is_array( $account_row ) && isset( $account_row['autoload'] ) ? strtolower( (string) $account_row['autoload'] ) : null;

$progress( 'measuring REST route registration' );
$rest_preinitialized        = did_action( 'rest_api_init' ) > 0;
$route_registration_status  = 'measured';
$route_registration_message = '';
if ( $rest_preinitialized ) {
	$server = rest_get_server();
	$rest_measure = array(
		'queries'             => 0,
		'external_requests'   => 0,
		'timing_sample_count' => 0,
		'measurement_mode'    => 'preinitialized_snapshot_only',
	);
	$route_registration_status  = 'preinitialized';
	$route_registration_message = 'REST API was initialized before the perf probe, so route registration timing is not isolated.';
} else {
	$rest_measure_raw = $measure_once(
		function () {
			$server = rest_get_server();
			return $server->get_routes();
		}
	);
	$rest_measure = array(
		'queries'             => $rest_measure_raw['queries'],
		'external_requests'   => $rest_measure_raw['external_requests'],
		'elapsed_ms'          => $rest_measure_raw['elapsed_ms'],
		'timing_sample_count' => $rest_measure_raw['timing_sample_count'],
		'measurement_mode'    => 'single_invocation_rest_api_init',
	);
	$server       = rest_get_server();
}
$routes = array_keys( $server->get_routes() );
$is_payment_provider_route = function ( string $route ): bool {
	return 1 === preg_match( '#^/(wc/v[0-9]+/payments|payments/woopay)(/|$)#', $route );
};
$payment_routes            = array_values( array_filter( $routes, $is_payment_provider_route ) );
$payment_controller_callbacks = array();
foreach ( $server->get_routes() as $route => $endpoints ) {
	if ( ! $is_payment_provider_route( (string) $route ) ) {
		continue;
	}

	foreach ( is_array( $endpoints ) ? $endpoints : array() as $endpoint ) {
		if ( ! is_array( $endpoint ) || ! isset( $endpoint['callback'] ) ) {
			continue;
		}

		$callback = $endpoint['callback'];
		if ( is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) && ! $callback[0] instanceof WP_REST_Server ) {
			$payment_controller_callbacks[ spl_object_hash( $callback[0] ) ] = get_class( $callback[0] );
		} elseif ( $callback instanceof Closure ) {
			$reflection = new ReflectionFunction( $callback );
			$owner      = $reflection->getClosureThis();
			if ( is_object( $owner ) && ! $owner instanceof WP_REST_Server ) {
				$payment_controller_callbacks[ spl_object_hash( $owner ) ] = get_class( $owner );
			}
		}
	}
}

$process_payment_probe = $measure_process_payment();
$refund_probe          = $measure_refund();
$capture_probe         = $measure_capture();

$result = array(
	'schema'       => 'woopayments_measured_gate.v1',
	'mode'         => 'perf',
	'php_version'  => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
	'wc_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
	'has_wcpay'    => class_exists( 'WC_Payments' ),
	'probes'       => array(
		'gateway_initialization'   => $gateway_initialization_probe,
		'gateway_first_resolution' => $gateway_initialization_probe,
		'gateway_registration'     => array(
			'status'  => 'measured',
			'metrics' => array_merge(
				$gateway_measure,
				array(
					'gateway_count'          => count( $gateway_ids ),
					'gateway_ids'            => $gateway_ids,
					'duplicate_gateway_ids'  => $duplicate_gateway_ids,
					'action_callback_count'  => array_sum( $gateway_hook_counts ),
					'action_callback_counts' => $gateway_hook_counts,
				)
			),
		),
		'autoload_options'         => array(
			'status'  => 'measured',
			'metrics' => array(
				'autoload_bytes' => $autoload_bytes,
				'option_count'    => is_array( $autoload_rows ) ? count( $autoload_rows ) : 0,
			),
		),
		'wcpay_account_data'       => array(
			'status'  => 'measured',
			'metrics' => array(
				'exists'     => null !== $account_autoload,
				'autoload'   => $account_autoload,
				'autoloaded' => in_array( $account_autoload, array( 'yes', 'on', 'auto-on' ), true ),
			),
		),
		'rest_boot'                => array_filter(
			array(
				'status'  => 'measured',
				'metrics' => array_merge(
					$rest_measure,
					array(
						'route_count'                     => count( $routes ),
						'payment_route_count'             => count( $payment_routes ),
						'route_registration_status'       => $route_registration_status,
						'route_registration_message'      => $route_registration_message,
						'controller_instantiation_count'  => count( $payment_controller_callbacks ),
						'controller_instantiation_status' => 'measured',
						'controller_callback_classes'     => array_values( array_unique( $payment_controller_callbacks ) ),
					)
				),
			),
			function ( $value ) {
				return '' !== $value;
			}
		),
		'process_payment'          => $process_payment_probe,
		'refund'                   => $refund_probe,
		'capture'                  => $capture_probe,
	),
);

$result_json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE );
if ( false === $result_json ) {
	WP_CLI::error( 'WP-CLI perf probe JSON serialization failed.' );
}
WP_CLI::line( $result_json );
PHP
}

compare_gateway_initialization() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

reference = json.load(open(sys.argv[1], encoding="utf-8"))
target = json.load(open(sys.argv[2], encoding="utf-8"))
incomplete = []


def gateway_initialization(capture, label):
    probes = capture.get("probes")
    entry = probes.get("gateway_initialization") if isinstance(probes, dict) else None
    legacy_entry = probes.get("gateway_first_resolution") if isinstance(probes, dict) else None
    using_legacy_entry = not isinstance(entry, dict) and isinstance(legacy_entry, dict)
    if using_legacy_entry:
        entry = legacy_entry
    if not isinstance(entry, dict):
        incomplete.append(f"{label}: gateway_initialization is missing")
        return None
    if entry.get("status") != "measured":
        incomplete.append(
            f"{label}: gateway_initialization is {entry.get('status', 'invalid')} "
            f"({entry.get('reason', 'no reason recorded')})"
        )
        return None

    metrics = entry.get("metrics")
    if not isinstance(metrics, dict):
        incomplete.append(f"{label}: gateway_initialization metrics are missing")
        return None

    problems = []
    expected_mode = "first_in_process_gateway_resolution" if using_legacy_entry else "gateway_initialization_lifecycle"
    if metrics.get("measurement_mode") != expected_mode:
        problems.append(f"measurement_mode={metrics.get('measurement_mode')!r}")
    if metrics.get("gateway_preinitialized") is not False:
        problems.append("gateway_preinitialized is not false")
    if metrics.get("timing_sample_count") != 1:
        problems.append(f"timing_sample_count={metrics.get('timing_sample_count')!r}")
    if "median_ms" in metrics:
        problems.append("single sample is mislabeled median_ms")
    for field in ("queries", "external_requests", "elapsed_ms"):
        if not isinstance(metrics.get(field), (int, float)):
            problems.append(f"{field} is not numeric")
    if not using_legacy_entry:
        if metrics.get("lifecycle_start_hook") != "woocommerce_payment_gateways":
            problems.append(f"lifecycle_start_hook={metrics.get('lifecycle_start_hook')!r}")
        if metrics.get("lifecycle_end_hook") != "wc_payment_gateways_initialized":
            problems.append(f"lifecycle_end_hook={metrics.get('lifecycle_end_hook')!r}")
        backtrace = metrics.get("caller_backtrace")
        if not isinstance(backtrace, list) or len(backtrace) > 12:
            problems.append("caller_backtrace is missing or unbounded")
    if problems:
        incomplete.append(f"{label}: invalid gateway_initialization ({', '.join(problems)})")
        return None
    return metrics


def print_query_groups(label, metrics):
    groups = metrics.get("top_query_groups", [])
    print(f"note  gateway_initialization: {label} top query groups")
    if not isinstance(groups, list) or not groups:
        print("note  gateway_initialization:   not captured")
        return
    for group in groups[:5]:
        if not isinstance(group, dict):
            continue
        callers = group.get("top_callers", [])
        suffix = f" callers={', '.join(map(str, callers[:3]))}" if isinstance(callers, list) and callers else ""
        print(f"note  gateway_initialization:   {group.get('count', '?')}x {group.get('sql', '<unknown sql>')}{suffix}")


ref_initialization = gateway_initialization(reference, "ref")
target_initialization = gateway_initialization(target, "target")
if incomplete:
    for message in incomplete:
        print(f"INCOMPLETE gateway_initialization: {message}")
    print("RESULT: INCOMPLETE gateway initialization evidence")
    sys.exit(3)

failures = []
for field in ("queries", "external_requests"):
    before = ref_initialization[field]
    after = target_initialization[field]
    if after > before:
        print(f"REGRESS gateway_initialization: {field} {before:g} -> {after:g} (gateway-initialization count fail)")
        failures.append(field)
        if field == "queries":
            print_query_groups("target", target_initialization)
            print_query_groups("ref", ref_initialization)
    else:
        print(f"ok    gateway_initialization: {field} {before:g} -> {after:g}")

print(
    f"note  gateway_initialization: elapsed_ms {ref_initialization['elapsed_ms']:g} -> "
    f"{target_initialization['elapsed_ms']:g} (single gateway-initialization sample; diagnostic only)"
)
if failures:
    print("RESULT: FAIL gateway initialization count gate")
    sys.exit(1)
PY
}

case "$mode" in
	capture)
		wp_cmd=""
		out=""
		process_order_id=""
		refund_order_id=""
		capture_order_id=""
		payment_method="pm_card_visa"
		refund_amount=""
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--wp)
					wp_cmd="${2:-}"; shift 2 ;;
				--out)
					out="${2:-}"; shift 2 ;;
				--process-order-id)
					process_order_id="${2:-}"; shift 2 ;;
				--refund-order-id)
					refund_order_id="${2:-}"; shift 2 ;;
				--capture-order-id)
					capture_order_id="${2:-}"; shift 2 ;;
				--payment-method)
					payment_method="${2:-}"; shift 2 ;;
				--refund-amount)
					refund_amount="${2:-}"; shift 2 ;;
				*)
					usage ;;
			esac
		done
		[ -n "$wp_cmd" ] && [ -n "$out" ] || usage
		validate_local_wp_cmd "$wp_cmd"
		case "$process_order_id" in
			""|*[!0-9]*) [ -z "$process_order_id" ] || usage ;;
		esac
		case "$refund_order_id" in
			""|*[!0-9]*) [ -z "$refund_order_id" ] || usage ;;
		esac
		case "$capture_order_id" in
			""|*[!0-9]*) [ -z "$capture_order_id" ] || usage ;;
		esac
		runner_details="$(woopayments_docker_runner_details "$wp_cmd")" || {
			echo "ERROR: approved Docker WP runner could not be parsed" >&2
			exit 2
		}
		IFS=$'\t' read -r docker_bin docker_container <<< "$runner_details"
		read -r -a wp_runner_parts <<< "$wp_cmd"
		if [ -z "$docker_bin" ] || [ -z "$docker_container" ] || \
			[ "${wp_runner_parts[0]:-}" != "$docker_bin" ] || \
			[ "${wp_runner_parts[1]:-}" != "exec" ] || \
			[ "${wp_runner_parts[2]:-}" != "-i" ] || \
			[ "${wp_runner_parts[3]:-}" != "$docker_container" ] || \
			[ "${wp_runner_parts[4]:-}" != "wp" ]; then
			echo "ERROR: approved Docker WP runner did not resolve to the exact parsed container" >&2
			exit 2
		fi

		tmpdir="${TMPDIR:?TMPDIR is required for temporary files}"
		mkdir -p "$tmpdir" "$(dirname "$out")"
		probe_file="$(mktemp "$tmpdir/woopayments-perf-surface.XXXXXX")"
		mu_probe_file="$(mktemp "$tmpdir/woopayments-perf-gateway-init.XXXXXX")"
		probe_token="$(python3 -c 'import secrets; print(secrets.token_hex(16))')" || {
			echo "ERROR: failed to generate gateway initialization probe token" >&2
			rm -f "$probe_file" "$mu_probe_file"
			exit 2
		}
		mu_probe_container_path=""
		mu_probe_created=0

		cleanup_capture() {
			capture_status=$?
			cleanup_status=0
			trap - EXIT HUP INT TERM
			if [ "$mu_probe_created" -eq 1 ] && [ -n "$mu_probe_container_path" ]; then
				if ! "$docker_bin" exec -i "$docker_container" rm -f -- "$mu_probe_container_path" >/dev/null 2>&1; then
					echo "ERROR: failed to remove temporary MU probe: $mu_probe_container_path" >&2
					cleanup_status=2
				fi
			fi
			rm -f "$probe_file" "$mu_probe_file"
			if [ "$capture_status" -eq 0 ] && [ "$cleanup_status" -ne 0 ]; then
				capture_status="$cleanup_status"
			fi
			exit "$capture_status"
		}
		trap cleanup_capture EXIT
		trap 'exit 129' HUP
		trap 'exit 130' INT
		trap 'exit 143' TERM

		write_probe "$probe_file"
		write_gateway_initialization_probe "$mu_probe_file"
		if ! mu_dir_output="$("${wp_runner_parts[@]}" eval 'WP_CLI::line( "WPMERGE_PERF_MU_PLUGIN_DIR:" . WPMU_PLUGIN_DIR );' 2>&1)"; then
			echo "ERROR: failed to resolve the approved container MU plugin directory" >&2
			exit 2
		fi
		mu_plugin_dir="$(printf '%s\n' "$mu_dir_output" | sed -n 's/^WPMERGE_PERF_MU_PLUGIN_DIR://p' | tail -1)"
		case "$mu_plugin_dir" in
			/*) ;;
			*)
				echo "ERROR: approved container returned an invalid MU plugin directory" >&2
				exit 2
				;;
		esac
		case "/$mu_plugin_dir/" in
			*"/../"*|*"/./"*)
				echo "ERROR: approved container returned an unsafe MU plugin directory" >&2
				exit 2
				;;
		esac
		if ! "$docker_bin" exec -i "$docker_container" test -d "$mu_plugin_dir"; then
			echo "ERROR: MU plugin directory is unavailable in approved container: $mu_plugin_dir" >&2
			exit 2
		fi
		mu_probe_container_path="${mu_plugin_dir%/}/woopayments-perf-gateway-init-${probe_token}.php"
		if ! "$docker_bin" exec -i "$docker_container" test ! -e "$mu_probe_container_path"; then
			echo "ERROR: refusing to overwrite temporary MU probe path: $mu_probe_container_path" >&2
			exit 2
		fi
		mu_probe_created=1
		if ! "$docker_bin" exec -i "$docker_container" tee "$mu_probe_container_path" < "$mu_probe_file" >/dev/null; then
			echo "ERROR: failed to install temporary MU probe in approved container" >&2
			exit 2
		fi
		activated_wp_runner=(
			"$docker_bin" exec -i
			-e "WPMERGE_PERF_GATEWAY_INIT_TOKEN=$probe_token"
			"$docker_container"
			"${wp_runner_parts[@]:4}"
		)
		echo "[perf-surface-gate] starting capture via WP-CLI" >&2
		if [ -n "$process_order_id" ]; then
			echo "[perf-surface-gate] process_payment fixture: order #$process_order_id" >&2
		else
			echo "[perf-surface-gate] process_payment fixture: not supplied" >&2
		fi
		if [ -n "$refund_order_id" ]; then
			echo "[perf-surface-gate] refund fixture: order #$refund_order_id" >&2
		else
			echo "[perf-surface-gate] refund fixture: not supplied" >&2
		fi
		if [ -n "$capture_order_id" ]; then
			echo "[perf-surface-gate] capture fixture: order #$capture_order_id" >&2
		else
			echo "[perf-surface-gate] capture fixture: not supplied" >&2
		fi
		if ! "${activated_wp_runner[@]}" eval-file - "$process_order_id" "$refund_order_id" "$capture_order_id" "$payment_method" "$refund_amount" < "$probe_file" > "$out"; then
			echo "ERROR: WP-CLI perf probe failed" >&2
			exit 2
		fi
		if [ ! -s "$out" ]; then
			echo "ERROR: WP-CLI perf probe produced no output" >&2
			exit 2
		fi
		echo "Captured perf surface metrics to $out"
		;;
	compare)
		ref=""
		target=""
		gateway_initialization_only=0
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--ref)
					ref="${2:-}"; shift 2 ;;
				--target)
					target="${2:-}"; shift 2 ;;
				--gateway-initialization-only)
					gateway_initialization_only=1; shift ;;
				*)
					usage ;;
			esac
		done
		[ -n "$ref" ] && [ -n "$target" ] || usage
		compare_gateway_initialization "$ref" "$target"
		gateway_initialization_rc=$?
		[ "$gateway_initialization_rc" -eq 0 ] || exit "$gateway_initialization_rc"
		if [ "$gateway_initialization_only" -eq 1 ]; then
			exit 0
		fi
		python3 "$COMPARE" perf --ref "$ref" --target "$target"
		;;
	*)
		usage ;;
esac
