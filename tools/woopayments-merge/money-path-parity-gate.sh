#!/usr/bin/env bash
#
# Exact Bucket-E parity for refund and manual-capture lifecycles.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	echo "FAIL: local runner safety library is missing: $LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
FLOW_DRIVE="${FLOW_DRIVE:-$SELF_DIR/flow-drive.sh}"
PARITY_DIFF="${PARITY_DIFF:-$SELF_DIR/parity-diff.sh}"
PREFLIGHT_DRIVER="${PREFLIGHT_DRIVER:-$SELF_DIR/money-path-parity-preflight.php}"

REF_WP=""
TARGET_WP=""
OUT_DIR=""
SKU="test-lab-beaker-001"
REFUND_QUANTITY="2"
CAPTURE_QUANTITY="3"

usage() {
	cat >&2 <<'USAGE'
usage: money-path-parity-gate.sh --ref "<reference wp>" --target "<target wp>" [--sku <sku>] [--out-dir <path>]

Creates test-mode deterministic orders on both stores, performs a partial refund,
compares an authorized manual-capture order, captures both authorizations, and
compares the final captured surface. Readiness failures exit 3; parity/flow
regressions exit 1.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--sku=*) SKU="${1#--sku=}"; shift ;;
		--sku) SKU="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "FAIL: --ref and --target are required." >&2
	usage
	exit 2
fi

validate_local_wp_cmd() {
	local label="$1"
	local command="$2"
	local error

	if ! error="$(woopayments_validate_local_wp_runner "$command")"; then
		echo "FAIL: unsafe WP-CLI command for $label: $error" >&2
		exit 2
	fi
}

validate_local_wp_cmd reference "$REF_WP"
validate_local_wp_cmd target "$TARGET_WP"

for dependency in "$FLOW_DRIVE" "$PARITY_DIFF" "$PREFLIGHT_DRIVER"; do
	if [ ! -f "$dependency" ]; then
		echo "FAIL: required harness dependency is missing: $dependency" >&2
		exit 2
	fi
done

if [ -z "$OUT_DIR" ]; then
	tmp_root="${TMPDIR:?TMPDIR is required when --out-dir is omitted}"
	mkdir -p "$tmp_root"
	OUT_DIR="$(mktemp -d "$tmp_root/woopayments-money-path-parity.XXXXXX")"
else
	if [ -e "$OUT_DIR" ] && { [ ! -d "$OUT_DIR" ] || [ -n "$(find "$OUT_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; }; then
		echo "FAIL: evidence directory is not empty; use a fresh directory: $OUT_DIR" >&2
		exit 2
	fi
	mkdir -p "$OUT_DIR" || {
		echo "FAIL: could not create evidence directory: $OUT_DIR" >&2
		exit 2
	}
fi

ROLLUP="$OUT_DIR/money-path-parity.json"
REF_PREFLIGHT="$OUT_DIR/reference-preflight.json"
TARGET_PREFLIGHT="$OUT_DIR/target-preflight.json"

REF_REFUND_ORDER=0
TARGET_REFUND_ORDER=0
REF_CAPTURE_ORDER=0
TARGET_CAPTURE_ORDER=0
REFUND_CHECK="not_run"
AUTHORIZED_CHECK="not_run"
CAPTURED_CHECK="not_run"
BLOCKED_ROLE=""
FAILURE_STAGE=""
AGGREGATE_EXIT=0

record_parity_failure() {
	local stage="$1"

	if [ "$AGGREGATE_EXIT" -eq 0 ]; then
		FAILURE_STAGE="$stage"
		AGGREGATE_EXIT=1
	fi
}

record_parity_blocker() {
	local stage="$1"

	if [ "$AGGREGATE_EXIT" -ne 3 ]; then
		BLOCKED_ROLE="both"
		FAILURE_STAGE="$stage"
		AGGREGATE_EXIT=3
	fi
}

json_field() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

try:
    payload = json.load(open(sys.argv[1], encoding="utf-8"))
except (OSError, json.JSONDecodeError):
    sys.exit(2)
value = payload.get(sys.argv[2])
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
PY
}

write_rollup() {
	local status="$1"
	python3 - "$ROLLUP" "$status" "$BLOCKED_ROLE" "$FAILURE_STAGE" \
		"$REF_REFUND_ORDER" "$TARGET_REFUND_ORDER" "$REF_CAPTURE_ORDER" "$TARGET_CAPTURE_ORDER" \
		"$REFUND_CHECK" "$AUTHORIZED_CHECK" "$CAPTURED_CHECK" "$REF_PREFLIGHT" "$TARGET_PREFLIGHT" <<'PY'
import json
import os
import sys

(
    path,
    status,
    blocked_role,
    failure_stage,
    ref_refund,
    target_refund,
    ref_capture,
    target_capture,
    refund_check,
    authorized_check,
    captured_check,
    ref_preflight_path,
    target_preflight_path,
) = sys.argv[1:]


def load(path):
    if not path or not os.path.isfile(path):
        return None
    try:
        return json.load(open(path, encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None


def positive_int(value):
    try:
        number = int(value)
    except (TypeError, ValueError):
        return None
    return number if number > 0 else None


payload = {
    "schema": "woopayments_money_path_parity.v1",
    "status": status,
    "blocked_role": blocked_role or None,
    "failure_stage": failure_stage or None,
    "orders": {
        "refund": {
            "reference": positive_int(ref_refund),
            "target": positive_int(target_refund),
        },
        "manual_capture": {
            "reference": positive_int(ref_capture),
            "target": positive_int(target_capture),
        },
    },
    "checks": {
        "refund_bucket_e": refund_check,
        "authorized_bucket_e": authorized_check,
        "captured_bucket_e": captured_check,
    },
    "preflight": {
        "reference": load(ref_preflight_path),
        "target": load(target_preflight_path),
    },
}
with open(path, "w", encoding="utf-8") as handle:
    json.dump(payload, handle, indent=2, sort_keys=True)
    handle.write("\n")
PY
}

extract_json() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

raw = open(sys.argv[1], encoding="utf-8").read()
decoder = json.JSONDecoder()
for index, character in enumerate(raw):
    if character != "{":
        continue
    try:
        payload, _ = decoder.raw_decode(raw[index:])
    except json.JSONDecodeError:
        continue
    if isinstance(payload, dict):
        with open(sys.argv[2], "w", encoding="utf-8") as handle:
            json.dump(payload, handle, indent=2, sort_keys=True)
            handle.write("\n")
        sys.exit(0)
sys.exit(2)
PY
}

run_preflight() {
	local role="$1"
	local wp_cmd="$2"
	local out="$3"
	local raw_file="$out.raw"
	local expected_owner expected_plugin_active expected_gateway_class site_url
	local rc

	# Deliberately split the validated local runner string, matching the existing harness convention.
	# shellcheck disable=SC2086
	$wp_cmd eval-file - "$role" "$SKU" < "$PREFLIGHT_DRIVER" > "$raw_file" 2>&1
	rc=$?
	if ! extract_json "$raw_file" "$out"; then
		return 2
	fi
	[ "$rc" -eq 0 ] || return 2
	[ "$(json_field "$out" success)" = "true" ] || return 3
	[ "$(json_field "$out" mode)" = "preflight" ] || return 3
	[ "$(json_field "$out" role)" = "$role" ] || return 3
	if [ "$role" = reference ]; then
		expected_owner="plugin"
		expected_plugin_active="true"
		expected_gateway_class="WC_Payment_Gateway_WCPay"
	else
		expected_owner="native"
		expected_plugin_active="false"
		expected_gateway_class='Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway'
	fi
	[ "$(json_field "$out" runtime_owner)" = "$expected_owner" ] || return 3
	[ "$(json_field "$out" wcpay_plugin_active)" = "$expected_plugin_active" ] || return 3
	[ "$(json_field "$out" gateway_id)" = "woocommerce_payments" ] || return 3
	[ "$(json_field "$out" gateway_class)" = "$expected_gateway_class" ] || return 3
	[ "$(json_field "$out" account_ready)" = "true" ] || return 3
	[ "$(json_field "$out" test_mode)" = "true" ] || return 3
	[ "$(json_field "$out" deterministic_product_ready)" = "true" ] || return 3
	site_url="$(json_field "$out" site_url)"
	[ -n "$site_url" ] || return 3
	woopayments_normalize_local_url "$site_url" >/dev/null || return 3
	return 0
}

run_flow() {
	local role="$1"
	local wp_cmd="$2"
	local out="$3"
	shift 3
	local raw_file="$out.raw"
	local rc

	WP="$wp_cmd" bash "$FLOW_DRIVE" "$@" > "$raw_file" 2>&1
	rc=$?
	if ! extract_json "$raw_file" "$out"; then
		return 1
	fi
	[ "$rc" -eq 0 ] || return 1
	[ "$(json_field "$out" order_id)" -gt 0 ] 2>/dev/null || return 1
	if [ "$(json_field "$out" success)" = "false" ]; then
		return 1
	fi
	return 0
}

handle_flow_failure() {
	local role="$1"
	local stage="$2"

	FAILURE_STAGE="$stage"
	if [ "$role" = reference ]; then
		BLOCKED_ROLE="reference"
		write_rollup blocked
		echo "BLOCKED: reference flow could not establish the oracle at $stage." >&2
		exit 3
	fi

	write_rollup fail
	echo "FAIL: target native flow failed at $stage." >&2
	exit 1
}

run_parity() {
	local name="$1"
	local ref_id="$2"
	local target_id="$3"
	local log="$OUT_DIR/$name-bucket-e.log"
	bash "$PARITY_DIFF" --ref "$REF_WP" --target "$TARGET_WP" --target-ids "$target_id" "$ref_id" > "$log" 2>&1
}

echo "WooPayments refund/manual-capture Bucket-E parity"
echo "  evidence: $OUT_DIR"

run_preflight reference "$REF_WP" "$REF_PREFLIGHT"
rc=$?
if [ "$rc" -ne 0 ]; then
	BLOCKED_ROLE="reference"
	FAILURE_STAGE="preflight"
	write_rollup blocked
	echo "BLOCKED: reference money-path prerequisites are not ready." >&2
	exit 3
fi

run_preflight target "$TARGET_WP" "$TARGET_PREFLIGHT"
rc=$?
if [ "$rc" -ne 0 ]; then
	BLOCKED_ROLE="target"
	FAILURE_STAGE="preflight"
	write_rollup blocked
	echo "BLOCKED: target money-path prerequisites are not ready." >&2
	exit 3
fi

REF_SITE_URL="$(woopayments_normalize_local_url "$(json_field "$REF_PREFLIGHT" site_url)")"
TARGET_SITE_URL="$(woopayments_normalize_local_url "$(json_field "$TARGET_PREFLIGHT" site_url)")"
if [ -z "$REF_SITE_URL" ] || [ -z "$TARGET_SITE_URL" ] || [ "$REF_SITE_URL" = "$TARGET_SITE_URL" ]; then
	BLOCKED_ROLE="both"
	FAILURE_STAGE="store_topology"
	write_rollup blocked
	echo "BLOCKED: reference and target preflights must resolve to distinct local stores." >&2
	exit 3
fi

REF_REFUND_CHARGE="$OUT_DIR/reference-refund-charge.json"
TARGET_REFUND_CHARGE="$OUT_DIR/target-refund-charge.json"
REF_REFUND="$OUT_DIR/reference-refund.json"
TARGET_REFUND="$OUT_DIR/target-refund.json"

run_flow reference "$REF_WP" "$REF_REFUND_CHARGE" charge --deterministic --sku="$SKU" --quantity="$REFUND_QUANTITY" --type=success || handle_flow_failure reference reference_refund_charge
run_flow target "$TARGET_WP" "$TARGET_REFUND_CHARGE" charge --deterministic --native --sku="$SKU" --quantity="$REFUND_QUANTITY" --type=success || handle_flow_failure target target_refund_charge
REF_REFUND_ORDER="$(json_field "$REF_REFUND_CHARGE" order_id)"
TARGET_REFUND_ORDER="$(json_field "$TARGET_REFUND_CHARGE" order_id)"

run_flow reference "$REF_WP" "$REF_REFUND" refund --deterministic --order-id="$REF_REFUND_ORDER" --type=partial || handle_flow_failure reference reference_refund
run_flow target "$TARGET_WP" "$TARGET_REFUND" refund --deterministic --native --order-id="$TARGET_REFUND_ORDER" --type=partial || handle_flow_failure target target_refund
run_parity refund "$REF_REFUND_ORDER" "$TARGET_REFUND_ORDER"
rc=$?
if [ "$rc" -eq 0 ]; then
	REFUND_CHECK="pass"
elif [ "$rc" -eq 1 ]; then
	REFUND_CHECK="fail"
	record_parity_failure refund_bucket_e
else
	REFUND_CHECK="blocked"
	record_parity_blocker refund_bucket_e_infrastructure
fi

REF_MANUAL_CHARGE="$OUT_DIR/reference-manual-charge.json"
TARGET_MANUAL_CHARGE="$OUT_DIR/target-manual-charge.json"
REF_CAPTURE="$OUT_DIR/reference-capture.json"
TARGET_CAPTURE="$OUT_DIR/target-capture.json"

run_flow reference "$REF_WP" "$REF_MANUAL_CHARGE" charge --deterministic --sku="$SKU" --quantity="$CAPTURE_QUANTITY" --type=success --manual-capture || handle_flow_failure reference reference_manual_charge
run_flow target "$TARGET_WP" "$TARGET_MANUAL_CHARGE" charge --deterministic --native --sku="$SKU" --quantity="$CAPTURE_QUANTITY" --type=success --manual-capture || handle_flow_failure target target_manual_charge
REF_CAPTURE_ORDER="$(json_field "$REF_MANUAL_CHARGE" order_id)"
TARGET_CAPTURE_ORDER="$(json_field "$TARGET_MANUAL_CHARGE" order_id)"

if [ "$(json_field "$REF_MANUAL_CHARGE" manual_capture)" != yes ] \
	|| [ "$(json_field "$TARGET_MANUAL_CHARGE" manual_capture)" != yes ] \
	|| [ "$(json_field "$REF_MANUAL_CHARGE" intention_status)" != requires_capture ] \
	|| [ "$(json_field "$TARGET_MANUAL_CHARGE" intention_status)" != requires_capture ]; then
	FAILURE_STAGE="manual_capture_contract"
	write_rollup fail
	exit 1
fi

run_parity authorized "$REF_CAPTURE_ORDER" "$TARGET_CAPTURE_ORDER"
rc=$?
if [ "$rc" -eq 0 ]; then
	AUTHORIZED_CHECK="pass"
elif [ "$rc" -eq 1 ]; then
	AUTHORIZED_CHECK="fail"
	record_parity_failure authorized_bucket_e
else
	AUTHORIZED_CHECK="blocked"
	record_parity_blocker authorized_bucket_e_infrastructure
fi

run_flow reference "$REF_WP" "$REF_CAPTURE" capture --deterministic --order-id="$REF_CAPTURE_ORDER" || handle_flow_failure reference reference_capture
run_flow target "$TARGET_WP" "$TARGET_CAPTURE" capture --deterministic --native --order-id="$TARGET_CAPTURE_ORDER" || handle_flow_failure target target_capture

run_parity captured "$REF_CAPTURE_ORDER" "$TARGET_CAPTURE_ORDER"
rc=$?
if [ "$rc" -eq 0 ]; then
	CAPTURED_CHECK="pass"
elif [ "$rc" -eq 1 ]; then
	CAPTURED_CHECK="fail"
	record_parity_failure captured_bucket_e
else
	CAPTURED_CHECK="blocked"
	record_parity_blocker captured_bucket_e_infrastructure
fi

if [ "$AGGREGATE_EXIT" -eq 3 ]; then
	write_rollup blocked
	echo "BLOCKED: one or more Bucket-E parity checks could not complete." >&2
	exit 3
fi

if [ "$AGGREGATE_EXIT" -eq 1 ]; then
	write_rollup fail
	echo "FAIL: one or more Bucket-E parity checks differ." >&2
	exit 1
fi

write_rollup pass
echo "PASS: refund, authorized, and captured Bucket-E surfaces match."
