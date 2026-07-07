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

usage() {
	cat >&2 <<'EOF'
usage:
  perf-surface-gate.sh capture --wp <wp command> --out <json> [--process-order-id <id>] [--refund-order-id <id>] [--capture-order-id <id>] [--payment-method <pm>] [--refund-amount <amount>]
  perf-surface-gate.sh compare --ref <json> --target <json>

Example:
  perf-surface-gate.sh capture --wp "docker exec -i wcpay_wp_default wp --allow-root" --out ref.json
EOF
	exit 2
}

validate_local_wp_cmd() {
	cmd="$1"
	case "$cmd" in
		*wpcom.com*|*wordpress.com*|*a8c.com*|*--ssh*|*--http*|*$'\n'*|*$'\r'*|*";"*|*"&"*|*"|"*|*"<"*|*">"*|*"\`"*|*'$('*)
			echo "ERROR: refusing non-local or shell-expanded WP-CLI command: $cmd" >&2
			exit 2
			;;
	esac
	case "$cmd" in
		"docker exec -i wcpay_wp_default wp "*)
			return 0
			;;
		"docker exec -i "*"-cli-1 wp "*)
			case "$cmd" in
				*wpcom*)
					echo "ERROR: refusing wpcom-local CLI container for perf gate: $cmd" >&2
					exit 2
					;;
			esac
			return 0
			;;
	esac
	echo "ERROR: perf capture --wp must use an approved local Docker WP-CLI command" >&2
	exit 2
}

mode="${1:-}"
[ -n "$mode" ] || usage
shift

write_probe() {
	probe_file="$1"
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

$measure = function ( callable $op, int $iterations = 5 ) use ( &$wpdb, &$external_requests ): array {
	$op();
	$q_before    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_before = $external_requests;
	$op();
	$q_after    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_after = $external_requests;

	$times = array();
	for ( $i = 0; $i < $iterations; $i++ ) {
		$start   = microtime( true );
		$op();
		$times[] = ( microtime( true ) - $start ) * 1000.0;
	}
	sort( $times );

	return array(
		'queries'           => max( 0, $q_after - $q_before ),
		'external_requests' => max( 0, $http_after - $http_before ),
		'median_ms'         => round( $times[ (int) floor( count( $times ) / 2 ) ], 2 ),
	);
};

$measure_once = function ( callable $op ) use ( &$wpdb, &$external_requests ): array {
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

	$elapsed_ms = ( microtime( true ) - $start ) * 1000.0;
	$q_after    = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$http_after = $external_requests;

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
		'queries'           => max( 0, $q_after - $q_before ),
		'external_requests' => max( 0, $http_after - $http_before ),
		'median_ms'         => round( $elapsed_ms, 2 ),
		'result_kind'       => $result_kind,
		'result_code'       => $result_code,
		'threw'             => null !== $thrown,
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

	$native_gateway_class = '\Automattic\WooCommerce\Internal\Payments\NativeWooPaymentsGateway';
	$is_native_gateway   = class_exists( $native_gateway_class ) && $gateway instanceof $native_gateway_class;
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

$progress( 'measuring gateway registration' );
$gateway_measure = $measure(
	function () {
		WC()->payment_gateways()->payment_gateways();
	}
);
$gateways = WC()->payment_gateways()->payment_gateways();
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
		'queries'           => 0,
		'external_requests' => 0,
		'median_ms'         => 0,
		'measurement_mode'  => 'preinitialized_snapshot_only',
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
		'queries'           => $rest_measure_raw['queries'],
		'external_requests' => $rest_measure_raw['external_requests'],
		'median_ms'         => $rest_measure_raw['median_ms'],
		'measurement_mode'  => 'single_invocation_rest_api_init',
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
		'gateway_registration' => array(
			'status'  => 'measured',
			'metrics' => array_merge(
				$gateway_measure,
				array(
					'gateway_count'          => count( $gateway_ids ),
					'gateway_ids'            => $gateway_ids,
					'duplicate_gateway_ids'  => $duplicate_gateway_ids,
					'action_callback_count'  => array_sum( $gateway_hook_counts ),
					'action_callback_counts' => $gateway_hook_counts,
					'measurement_mode'       => 'warmed_in_process',
				)
			),
		),
		'autoload_options'     => array(
			'status'  => 'measured',
			'metrics' => array(
				'autoload_bytes' => $autoload_bytes,
				'option_count'    => is_array( $autoload_rows ) ? count( $autoload_rows ) : 0,
			),
		),
		'wcpay_account_data'   => array(
			'status'  => 'measured',
			'metrics' => array(
				'exists'     => null !== $account_autoload,
				'autoload'   => $account_autoload,
				'autoloaded' => in_array( $account_autoload, array( 'yes', 'on', 'auto-on' ), true ),
			),
		),
		'rest_boot'            => array_filter(
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
		'process_payment'      => $process_payment_probe,
		'refund'               => $refund_probe,
		'capture'              => $capture_probe,
	),
);

WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
PHP
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
		tmpdir="${TMPDIR:?TMPDIR is required for temporary files}"
		mkdir -p "$tmpdir" "$(dirname "$out")"
		probe_file="$(mktemp "$tmpdir/woopayments-perf-surface.XXXXXX")"
		trap 'rm -f "$probe_file"' EXIT
		write_probe "$probe_file"
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
		if ! bash -c "$wp_cmd eval-file - '$process_order_id' '$refund_order_id' '$capture_order_id' '$payment_method' '$refund_amount' < '$probe_file'" > "$out"; then
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
		while [ "$#" -gt 0 ]; do
			case "$1" in
				--ref)
					ref="${2:-}"; shift 2 ;;
				--target)
					target="${2:-}"; shift 2 ;;
				*)
					usage ;;
			esac
		done
		[ -n "$ref" ] && [ -n "$target" ] || usage
		python3 "$COMPARE" perf --ref "$ref" --target "$target"
		;;
	*)
		usage ;;
esac
