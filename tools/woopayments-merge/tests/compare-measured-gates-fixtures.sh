#!/usr/bin/env bash
#
# Fixture checks for compare-measured-gates.py perf money-surface behavior.

set -euo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS_DIR="$(cd "$SELF_DIR/.." && pwd)"
COMPARE="$TOOLS_DIR/compare-measured-gates.py"
TMP_BASE="${TMPDIR:?TMPDIR is required}"
WORK_DIR="$(mktemp -d "$TMP_BASE/compare-measured-gates.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

write_perf_capture() {
	path="$1"
	process_status="$2"
	process_queries="$3"
	refund_status="$4"
	refund_queries="$5"
	capture_status="$6"
	capture_queries="$7"
	rest_controller_status="$8"
	rest_controller_count="$9"
	python3 - "$path" "$process_status" "$process_queries" "$refund_status" "$refund_queries" "$capture_status" "$capture_queries" "$rest_controller_status" "$rest_controller_count" <<'PY'
import json
import sys

path, process_status, process_queries, refund_status, refund_queries, capture_status, capture_queries, rest_controller_status, rest_controller_count = sys.argv[1:]

def money_probe(status, queries):
    if status != "measured":
        if status == "measured_no_boundary":
            return {
                "status": "measured",
                "metrics": {
                    "queries": int(queries),
                    "external_requests": 0,
                    "median_ms": 1.0,
                    "order_id": 100,
                    "result": "local_error",
                },
            }
        return {"status": status, "reason": "fixture unavailable"}
    return {
        "status": "measured",
        "metrics": {
            "queries": int(queries),
            "external_requests": 1,
            "median_ms": 1.0,
            "order_id": 100,
            "result": "success",
        },
    }

capture = {
    "schema": "woopayments_measured_gate.v1",
    "mode": "perf",
    "probes": {
        "gateway_registration": {
            "status": "measured",
            "metrics": {
                "gateway_count": 1,
                "action_callback_count": 1,
                "external_requests": 0,
                "median_ms": 1.0,
                "duplicate_gateway_ids": [],
            },
        },
        "rest_boot": {
            "status": "measured",
            "metrics": {
                "external_requests": 0,
                "controller_instantiation_count": int(rest_controller_count),
                "controller_instantiation_status": rest_controller_status,
                "route_registration_status": "preinitialized" if rest_controller_status == "preinitialized" else "measured",
                "median_ms": 1.0,
            },
        },
        "autoload_options": {
            "status": "measured",
            "metrics": {"autoload_bytes": 10},
        },
        "wcpay_account_data": {
            "status": "measured",
            "metrics": {"autoload": "no"},
        },
        "process_payment": money_probe(process_status, process_queries),
        "refund": money_probe(refund_status, refund_queries),
        "capture": money_probe(capture_status, capture_queries),
    },
}

with open(path, "w", encoding="utf-8") as handle:
    json.dump(capture, handle)
PY
}

expect_exit() {
	expected="$1"
	label="$2"
	shift 2
	set +e
	"$@" > "$WORK_DIR/out.txt" 2>&1
	rc=$?
	set -e
	if [ "$rc" -ne "$expected" ]; then
		echo "FAIL: $label expected exit $expected, got $rc" >&2
		cat "$WORK_DIR/out.txt" >&2
		exit 1
	fi
}

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 requires_fixture 0 measured 0
write_perf_capture "$WORK_DIR/target.json" measured 20 measured 1 requires_fixture 0 measured 0
expect_exit 1 "process_payment query regression fails" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "FAIL  process_payment: queries 1 -> 20 exceeds limit 2" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 requires_fixture 0 measured 0
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 20 requires_fixture 0 measured 0
expect_exit 1 "refund query regression fails" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "FAIL  refund: queries 1 -> 20 exceeds limit 2" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 measured 1
write_perf_capture "$WORK_DIR/target.json" measured 2 measured 2 measured 2 measured 1
expect_exit 0 "small money-path query drift stays advisory" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "RESULT: PASS perf measured gate" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 measured 1
write_perf_capture "$WORK_DIR/target.json" measured_no_boundary 1 measured 1 measured 1 measured 1
expect_exit 3 "money-path probes without provider-boundary signal stay incomplete" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "INCOMPLETE process_payment: measured without target provider-boundary HTTP" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 requires_fixture 0 measured 0
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 1 requires_fixture 0 measured 0
expect_exit 3 "unmeasured capture keeps gate incomplete" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "INCOMPLETE capture: requires_fixture" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 not_implemented 0
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 1 measured 1 not_implemented 0
expect_exit 3 "unmeasured rest controller count keeps gate incomplete" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "INCOMPLETE rest_boot: controller_instantiation_count" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 measured 1
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 1 measured 1 preinitialized 1
expect_exit 3 "preinitialized rest route registration keeps gate incomplete" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "INCOMPLETE rest_boot: route_registration_status ref=measured target=preinitialized" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 measured 1
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 1 measured 1 measured 2
expect_exit 1 "rest controller count regression fails" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "FAIL  rest_boot: controller_instantiation_count 1 -> 2" "$WORK_DIR/out.txt"

write_perf_capture "$WORK_DIR/ref.json" measured 1 measured 1 measured 1 measured 1
write_perf_capture "$WORK_DIR/target.json" measured 1 measured 1 measured 1 measured 1
expect_exit 0 "all money surfaces measured and equal passes" python3 "$COMPARE" perf --ref "$WORK_DIR/ref.json" --target "$WORK_DIR/target.json"
grep -q "RESULT: PASS perf measured gate" "$WORK_DIR/out.txt"

echo "PASS: compare-measured-gates fixture tests passed."
