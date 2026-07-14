#!/usr/bin/env bash
#
# Bucket-C WC Subscriptions renewal conformance gate scaffold.
#
# Local-only harness for comparing WooPayments renewal behavior between the
# reference plugin store and the native target store. It intentionally does not
# create subscriptions: full conformance needs browser-created subscriptions so
# tokenization, customer IDs, setup_future_usage, order meta, and email behavior
# are real. Pass explicit subscription IDs created in each store.
#
# Examples:
#   tools/woopayments-merge/subscriptions-renewal-gate.sh preflight \
#     --ref "docker exec -i wcpay_wp_default wp --allow-root" \
#     --target "docker exec -i <target-cli-container> wp --allow-root --user=1"
#
#   tools/woopayments-merge/subscriptions-renewal-gate.sh compare \
#     --ref "docker exec -i wcpay_wp_default wp --allow-root" \
#     --target "docker exec -i <target-cli-container> wp --allow-root --user=1" \
#     --ref-subscription-id 123 \
#     --target-subscription-id 456

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DRIVER="$SELF_DIR/subscriptions-renewal-drive.php"

MODE=""
case "${1:-}" in
	preflight|--preflight)
		MODE="preflight"
		shift
		;;
	compare|drive|--compare|--drive)
		MODE="compare"
		shift
		;;
esac

REF_WP=""
TARGET_WP=""
REF_SUBSCRIPTION_ID=""
TARGET_SUBSCRIPTION_ID=""
PAYMENT_FAMILY="card"
EXPECTED_GATEWAY_ID="woocommerce_payments"
EXPECTED_TOKEN_TYPE="CC"
REF_TOKEN_ID="0"
TARGET_TOKEN_ID="0"
OUT_DIR=""

usage() {
	cat >&2 <<'USAGE'
usage:
  subscriptions-renewal-gate.sh preflight --ref "<ref wp>" --target "<target wp>" [--payment-family card|sepa]
  subscriptions-renewal-gate.sh compare --ref "<ref wp>" --target "<target wp>" --ref-subscription-id <id> --target-subscription-id <id> [--payment-family card|sepa] [--ref-token-id <id> --target-token-id <id>] [--out-dir <path>]

The compare mode requires explicit browser-created subscription IDs. This
scaffold does not seed subscriptions through CLI because that would bypass the
real checkout tokenization and email path this gate is meant to verify.

Options:
  --payment-family <family>  Select the card (default) or SEPA renewal policy.
  --ref-token-id <id>        Exact reference saved-token ID. Required for SEPA compare.
  --target-token-id <id>     Exact target saved-token ID. Required for SEPA compare.
  --out-dir <path>           Preserve preflight, drive, normalized, and rollup evidence.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--ref-subscription-id=*) REF_SUBSCRIPTION_ID="${1#--ref-subscription-id=}"; shift ;;
		--ref-subscription-id) REF_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--target-subscription-id=*) TARGET_SUBSCRIPTION_ID="${1#--target-subscription-id=}"; shift ;;
		--target-subscription-id) TARGET_SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--payment-family=*) PAYMENT_FAMILY="${1#--payment-family=}"; shift ;;
		--payment-family) PAYMENT_FAMILY="${2:-}"; shift 2 ;;
		--ref-token-id=*) REF_TOKEN_ID="${1#--ref-token-id=}"; shift ;;
		--ref-token-id) REF_TOKEN_ID="${2:-}"; shift 2 ;;
		--target-token-id=*) TARGET_TOKEN_ID="${1#--target-token-id=}"; shift ;;
		--target-token-id) TARGET_TOKEN_ID="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

if [ -z "$MODE" ]; then
	MODE="compare"
fi

if [ ! -f "$DRIVER" ]; then
	echo "FAIL: PHP driver not found at $DRIVER" >&2
	exit 2
fi

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "FAIL: both --ref and --target WP commands are required." >&2
	usage
	exit 2
fi

case "$PAYMENT_FAMILY" in
	card)
		EXPECTED_GATEWAY_ID="woocommerce_payments"
		EXPECTED_TOKEN_TYPE="CC"
		;;
	sepa)
		EXPECTED_GATEWAY_ID="woocommerce_payments_sepa_debit"
		EXPECTED_TOKEN_TYPE="wcpay_sepa"
		;;
	*)
		echo "FAIL: --payment-family must be card or sepa." >&2
		exit 2
		;;
esac

if [ "$MODE" = "compare" ]; then
	if [ -z "$REF_SUBSCRIPTION_ID" ] || [ -z "$TARGET_SUBSCRIPTION_ID" ]; then
		echo "FAIL: browser-created subscription IDs are required for compare mode." >&2
		echo "      Pass --ref-subscription-id and --target-subscription-id after creating equivalent WooPayments subscriptions in both stores." >&2
		exit 2
	fi

	case "$REF_SUBSCRIPTION_ID:$TARGET_SUBSCRIPTION_ID" in
		*[!0-9:]*|:*|*:)
			echo "FAIL: subscription IDs must be positive integers." >&2
			exit 2
			;;
	esac

	case "$REF_TOKEN_ID:$TARGET_TOKEN_ID" in
		*[!0-9:]*|:*|*:)
			echo "FAIL: token IDs must be positive integers when provided." >&2
			exit 2
			;;
	esac

	if [ "$PAYMENT_FAMILY" = "sepa" ] && { [ "$REF_TOKEN_ID" -le 0 ] || [ "$TARGET_TOKEN_ID" -le 0 ]; }; then
		echo "FAIL: SEPA compare requires --ref-token-id and --target-token-id." >&2
		exit 2
	fi
fi

run_eval() {
	local wp_cmd="$1"
	local role="$2"
	local out_file="$3"
	shift 3
	local raw rc json

	# Intentionally split the WP runner string, matching the rest of this
	# harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval-file - "$@" < "$DRIVER" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | grep -E '^\{' | tail -1)"

	if [ -z "$json" ]; then
		echo "FAIL ($role): driver produced no JSON output." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 2
	fi

	printf '%s\n' "$json" > "$out_file"

	if [ "$rc" -ne 0 ]; then
		echo "FAIL ($role): driver exited $rc." >&2
		php "$DRIVER" normalize "$out_file" 2>/dev/null || printf '%s\n' "$json" >&2
		return "$rc"
	fi

	return 0
}

json_success() {
	php -r '
		$payload = json_decode(file_get_contents($argv[1]), true);
		exit(is_array($payload) && !empty($payload["success"]) ? 0 : 1);
	' "$1"
}

print_errors() {
	php -r '
		$payload = json_decode(file_get_contents($argv[1]), true);
		if (!is_array($payload)) { exit(0); }
		foreach (($payload["errors"] ?? array()) as $error) {
			fwrite(STDERR, "  - " . $error . PHP_EOL);
		}
	' "$1"
}

normalize_file() {
	php "$DRIVER" normalize "$1"
}

tmp_root="${TMPDIR:-}"
if [ -z "$OUT_DIR" ] && [ -z "$tmp_root" ]; then
	echo "FAIL: TMPDIR is not set; refusing to write temp files outside the configured temp directory." >&2
	exit 2
fi
cleanup_work_dir=0
if [ -n "$OUT_DIR" ]; then
	work_dir="$OUT_DIR"
	mkdir -p "$work_dir" || {
		echo "FAIL: could not create evidence output directory: $work_dir" >&2
		exit 2
	}
else
	mkdir -p "$tmp_root"
	work_dir="$(mktemp -d "$tmp_root/woopayments-subscriptions-renewal.XXXXXX")"
	cleanup_work_dir=1
fi
if [ "$cleanup_work_dir" -eq 1 ]; then
	trap 'rm -rf "$work_dir"' EXIT
fi

ref_preflight="$work_dir/ref-preflight.json"
target_preflight="$work_dir/target-preflight.json"
rollup_json="$work_dir/subscriptions-renewal-gate.json"

write_rollup() {
	local status="$1"
	local normalized_diff_matched="${2:-false}"

	php -r '
		$rollup_path = $argv[1];
		$status = $argv[2];
		$mode = $argv[3];
		$ref_subscription_id = $argv[4];
		$target_subscription_id = $argv[5];
		$normalized_diff_matched = "true" === $argv[12];
		$payment_family = $argv[13];
		$expected_gateway_id = $argv[14];
		$expected_token_type = $argv[15];
		$load = static function ( $path ) {
			if ( "" === $path || ! file_exists( $path ) ) {
				return null;
			}
			$payload = json_decode( file_get_contents( $path ), true );
			return is_array( $payload ) ? $payload : null;
		};
		$to_id = static function ( $value ) {
			return ctype_digit( (string) $value ) ? (int) $value : null;
		};
		$payload = array(
			"schema" => "woopayments_subscriptions_renewal_gate_rollup.v1",
			"status" => $status,
			"mode" => $mode,
			"ref_subscription_id" => $to_id( $ref_subscription_id ),
			"target_subscription_id" => $to_id( $target_subscription_id ),
			"payment_family" => $payment_family,
			"expected_gateway_id" => $expected_gateway_id,
			"expected_token_type" => $expected_token_type,
			"ref_expected_token_id" => $to_id( $argv[16] ),
			"target_expected_token_id" => $to_id( $argv[17] ),
			"normalized_diff_matched" => $normalized_diff_matched,
			"reference" => array(
				"preflight" => $load( $argv[6] ),
				"drive" => $load( $argv[8] ),
				"normalized" => $load( $argv[10] ),
			),
			"target" => array(
				"preflight" => $load( $argv[7] ),
				"drive" => $load( $argv[9] ),
				"normalized" => $load( $argv[11] ),
			),
		);
		file_put_contents( $rollup_path, json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL );
	' "$rollup_json" "$status" "$MODE" "$REF_SUBSCRIPTION_ID" "$TARGET_SUBSCRIPTION_ID" "$ref_preflight" "$target_preflight" "${ref_drive:-}" "${target_drive:-}" "${ref_norm:-}" "${target_norm:-}" "$normalized_diff_matched" "$PAYMENT_FAMILY" "$EXPECTED_GATEWAY_ID" "$EXPECTED_TOKEN_TYPE" "$REF_TOKEN_ID" "$TARGET_TOKEN_ID"
}

echo "Bucket-C WC Subscriptions renewal gate"
echo "  mode: $MODE"
echo "  payment family: $PAYMENT_FAMILY ($EXPECTED_GATEWAY_ID / $EXPECTED_TOKEN_TYPE)"
echo

echo "Preflight: reference"
if ! run_eval "$REF_WP" "ref preflight" "$ref_preflight" preflight ref "$EXPECTED_GATEWAY_ID" "$EXPECTED_TOKEN_TYPE" "$PAYMENT_FAMILY"; then
	print_errors "$ref_preflight"
	write_rollup blocked false
	exit 3
fi
if ! json_success "$ref_preflight"; then
	echo "BLOCKED: reference preflight prerequisites not met (not a renewal verdict)." >&2
	print_errors "$ref_preflight"
	write_rollup blocked false
	exit 3
fi
echo "  ok"

echo "Preflight: target"
if ! run_eval "$TARGET_WP" "target preflight" "$target_preflight" preflight target "$EXPECTED_GATEWAY_ID" "$EXPECTED_TOKEN_TYPE" "$PAYMENT_FAMILY"; then
	print_errors "$target_preflight"
	write_rollup blocked false
	exit 3
fi
if ! json_success "$target_preflight"; then
	echo "BLOCKED: target preflight prerequisites not met (not a renewal verdict)." >&2
	print_errors "$target_preflight"
	write_rollup blocked false
	exit 3
fi
echo "  ok"

if [ "$MODE" = "preflight" ]; then
	echo
	echo "PASS: WC Subscriptions renewal preflight passed on reference and target."
	write_rollup pass false
	exit 0
fi

if [ "$MODE" != "compare" ]; then
	echo "Unknown mode: $MODE" >&2
	usage
	exit 2
fi

ref_drive="$work_dir/ref-drive.json"
target_drive="$work_dir/target-drive.json"
ref_norm="$work_dir/ref-normalized.json"
target_norm="$work_dir/target-normalized.json"

echo
echo "Drive renewal: reference subscription $REF_SUBSCRIPTION_ID"
if ! run_eval "$REF_WP" "ref drive" "$ref_drive" drive "$REF_SUBSCRIPTION_ID" "$EXPECTED_GATEWAY_ID" "$REF_TOKEN_ID" "$EXPECTED_TOKEN_TYPE" "$PAYMENT_FAMILY"; then
	print_errors "$ref_drive"
	write_rollup fail false
	exit 1
fi
if ! json_success "$ref_drive"; then
	echo "FAIL: reference renewal drive did not pass." >&2
	print_errors "$ref_drive"
	write_rollup fail false
	exit 1
fi
echo "  ok"

echo "Drive renewal: target subscription $TARGET_SUBSCRIPTION_ID"
if ! run_eval "$TARGET_WP" "target drive" "$target_drive" drive "$TARGET_SUBSCRIPTION_ID" "$EXPECTED_GATEWAY_ID" "$TARGET_TOKEN_ID" "$EXPECTED_TOKEN_TYPE" "$PAYMENT_FAMILY"; then
	print_errors "$target_drive"
	write_rollup fail false
	exit 1
fi
if ! json_success "$target_drive"; then
	echo "FAIL: target renewal drive did not pass." >&2
	print_errors "$target_drive"
	write_rollup fail false
	exit 1
fi
echo "  ok"

normalize_file "$ref_drive" > "$ref_norm"
normalize_file "$target_drive" > "$target_norm"

echo
echo "Compare normalized renewal facts"
if ! diff -u "$ref_norm" "$target_norm"; then
	echo "FAIL: normalized WC Subscriptions renewal facts differ." >&2
	echo "      Raw facts retained until process exit under $work_dir" >&2
	write_rollup fail false
	exit 1
fi

json_int_field() { # <file> <key>
	python3 - "$1" "$2" <<'PYEOF'
import json, sys
try:
    payload = json.load(open(sys.argv[1]))
except Exception:
    print(0)
    raise SystemExit(0)
value = payload.get(sys.argv[2], 0)
print(value if isinstance(value, int) and value > 0 else 0)
PYEOF
}

# Renewal money is RULE 0: the drive performed a REAL provider charge, so the
# renewal order must reconcile against the provider raw source on both stores.
# Matching meta presence and status with a wrong amount must not ship.
RECONCILE="${WOOPAYMENTS_RENEWAL_RECONCILER:-$SELF_DIR/financial-reconcile.sh}"
if [ ! -f "$RECONCILE" ]; then
	echo "BLOCKED: financial reconciler missing: $RECONCILE" >&2
	write_rollup blocked true
	exit 3
fi
echo
echo "Reconcile renewal charges against provider raw source"
for side in ref target; do
	if [ "$side" = "ref" ]; then
		side_wp="$REF_WP"; side_drive="$ref_drive"
	else
		side_wp="$TARGET_WP"; side_drive="$target_drive"
	fi
	renewal_order_id="$(json_int_field "$side_drive" renewal_order_id)"
	if [ "$renewal_order_id" -eq 0 ]; then
		echo "BLOCKED: $side drive emitted no renewal order id to reconcile." >&2
		write_rollup blocked true
		exit 3
	fi
	WP="$side_wp" bash "$RECONCILE" "$renewal_order_id"
	reconcile_rc=$?
	if [ "$reconcile_rc" -eq 3 ] || [ "$reconcile_rc" -eq 2 ]; then
		echo "BLOCKED: $side renewal order $renewal_order_id could not be reconciled against the provider (exit $reconcile_rc)." >&2
		write_rollup blocked true
		exit 3
	fi
	if [ "$reconcile_rc" -ne 0 ]; then
		echo "FAIL: $side renewal order $renewal_order_id diverges from the provider raw source (RULE 0 money-path)." >&2
		write_rollup fail true
		exit 1
	fi
done

write_rollup pass true
echo "PASS: WC Subscriptions renewal facts match reference and renewal charges reconcile against the provider."
exit 0
