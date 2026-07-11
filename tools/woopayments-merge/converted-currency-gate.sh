#!/usr/bin/env bash
#
# N7a converted-currency money-path gate.
#
# Proves fresh automatic WooPayments rates, drives real converted-currency
# charges on the plugin reference and native Core target, and reconciles both.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
MC_STATE_HELPER="$SELF_DIR/multi-currency-runtime-state.sh"
for required_library in "$LOCAL_RUNNER_SAFETY" "$MC_STATE_HELPER"; do
	if [ ! -f "$required_library" ]; then
		printf 'FAIL: required harness library is missing: %s\n' "$required_library" >&2
		exit 2
	fi
done
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
# shellcheck source=tools/woopayments-merge/multi-currency-runtime-state.sh
source "$MC_STATE_HELPER"

REF_WP=""
TARGET_WP=""
CURRENCY="GBP"
SKU="test-lab-beaker-001"
QUANTITY=2
OUT_DIR="${CONVERTED_CURRENCY_OUT_DIR:-${TMPDIR:-$SELF_DIR/.tmp}/converted-currency-gate.$$}"

REF_SNAPSHOT_FILE=""
TARGET_SNAPSHOT_FILE=""
REF_RATE_FILE=""
TARGET_RATE_FILE=""
REF_RESTORE_REQUIRED=0
TARGET_RESTORE_REQUIRED=0
REF_RESTORE_JSON='{"restored":true,"verified":true,"not_required":true}'
TARGET_RESTORE_JSON='{"restored":true,"verified":true,"not_required":true}'
REF_CHARGE_JSON=""
TARGET_CHARGE_JSON=""
REF_MONEY_META_JSON=""
TARGET_MONEY_META_JSON=""
REF_RECONCILIATION_ATTEMPTED=false
REF_RECONCILIATION_PASSED=false
TARGET_RECONCILIATION_ATTEMPTED=false
TARGET_RECONCILIATION_PASSED=false
GATE_STATUS="blocked"
GATE_MESSAGE="gate exited before producing complete evidence"
GATE_TRIGGER="exit"
FINALIZATION_DONE=0
FINALIZATION_RUNNING=0
FINALIZATION_STATUS="pass"
FINAL_RESULT_WRITTEN=0
PENDING_SIGNAL_EXIT_CODE=0
FINAL_EXIT_CODE=0

usage() {
	cat >&2 <<'USAGE'
usage:
  converted-currency-gate.sh --ref "<ref wp>" --target "<target wp>" [options]

Options:
  --currency GBP      Converted order currency. Default: GBP.
  --sku SKU           Product SKU used by the deterministic charge driver.
  --quantity N        Positive product quantity. Default: 2.
  --out-dir PATH      Structured evidence directory.
  -h, --help          Show this help.

Both WP runners must use an approved local Docker WP-CLI form. The gate snapshots
and exactly restores every shared multi-currency option row it may mutate.
USAGE
}

fail_usage() {
	printf 'FAIL: %s\n' "$1" >&2
	usage
	exit 2
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--currency=*) CURRENCY="${1#--currency=}"; shift ;;
		--currency) CURRENCY="${2:-}"; shift 2 ;;
		--sku=*) SKU="${1#--sku=}"; shift ;;
		--sku) SKU="${2:-}"; shift 2 ;;
		--quantity=*) QUANTITY="${1#--quantity=}"; shift ;;
		--quantity) QUANTITY="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) fail_usage "unknown argument: $1" ;;
	esac
done

CURRENCY="$(printf '%s' "$CURRENCY" | tr '[:lower:]' '[:upper:]' | tr -d '[:space:]')"
if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	fail_usage "both --ref and --target WP commands are required."
fi
if ! printf '%s' "$CURRENCY" | grep -qE '^[A-Z]{3}$'; then
	fail_usage "--currency must be a three-letter ISO currency code."
fi
if ! printf '%s' "$QUANTITY" | grep -qE '^[1-9][0-9]*$'; then
	fail_usage "--quantity must be a positive integer."
fi
if [ -z "$OUT_DIR" ]; then
	fail_usage "--out-dir must not be empty."
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$REF_WP")"; then
	fail_usage "unsafe --ref WP runner: $runner_error"
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
	fail_usage "unsafe --target WP runner: $runner_error"
fi
if ! runner_error="$(woopayments_validate_approved_docker_runner "$REF_WP" ref)"; then
	fail_usage "unapproved --ref WP runner: $runner_error"
fi
if ! runner_error="$(woopayments_validate_approved_docker_runner "$TARGET_WP" target)"; then
	fail_usage "unapproved --target WP runner: $runner_error"
fi
if [ "$REF_WP" = "$TARGET_WP" ]; then
	fail_usage "--ref and --target must identify distinct local WP runners."
fi

mkdir -p "$OUT_DIR"
REF_SNAPSHOT_FILE="$OUT_DIR/reference-option-snapshot.json"
TARGET_SNAPSHOT_FILE="$OUT_DIR/target-option-snapshot.json"
REF_RATE_FILE="$OUT_DIR/reference-automatic-rate.json"
TARGET_RATE_FILE="$OUT_DIR/target-automatic-rate.json"
for stale_artifact in \
	"$REF_SNAPSHOT_FILE" \
	"$TARGET_SNAPSHOT_FILE" \
	"$REF_RATE_FILE" \
	"$TARGET_RATE_FILE" \
	"$OUT_DIR/converted-currency-gate.json"; do
	rm -f -- "$stale_artifact"
done

json_field() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

value = json.loads(sys.argv[1])
for part in sys.argv[2].split("."):
    if not isinstance(value, dict) or part not in value:
        raise SystemExit(1)
    value = value[part]
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
PY
}

write_result_evidence() {
	if [ "$FINAL_RESULT_WRITTEN" -eq 1 ]; then
		return 0
	fi

	python3 - \
		"$OUT_DIR/converted-currency-gate.json" \
		"$GATE_STATUS" \
		"$GATE_TRIGGER" \
		"$GATE_MESSAGE" \
		"$CURRENCY" \
		"$SKU" \
		"$QUANTITY" \
		"$REF_RATE_FILE" \
		"$TARGET_RATE_FILE" \
		"$REF_CHARGE_JSON" \
		"$TARGET_CHARGE_JSON" \
		"$REF_MONEY_META_JSON" \
		"$TARGET_MONEY_META_JSON" \
		"$REF_RECONCILIATION_ATTEMPTED" \
		"$REF_RECONCILIATION_PASSED" \
		"$TARGET_RECONCILIATION_ATTEMPTED" \
		"$TARGET_RECONCILIATION_PASSED" \
		"$REF_RESTORE_JSON" \
		"$TARGET_RESTORE_JSON" \
		"$REF_SNAPSHOT_FILE" \
		"$TARGET_SNAPSHOT_FILE" <<'PY'
import json
import sys
from pathlib import Path

(
    output_raw,
    status,
    trigger,
    message,
    currency,
    sku,
    quantity_raw,
    reference_rate_raw,
    target_rate_raw,
    reference_charge_raw,
    target_charge_raw,
    reference_money_meta_raw,
    target_money_meta_raw,
    reference_attempted_raw,
    reference_passed_raw,
    target_attempted_raw,
    target_passed_raw,
    reference_restore_raw,
    target_restore_raw,
    reference_snapshot_raw,
    target_snapshot_raw,
) = sys.argv[1:]
output = Path(output_raw)

def decode(raw, fallback):
    if not raw:
        return fallback
    try:
        value = json.loads(raw)
    except Exception:
        return fallback
    return value if isinstance(value, dict) else fallback

def load(path_raw, fallback):
    path = Path(path_raw)
    if not path.is_file():
        return fallback
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return fallback
    return value if isinstance(value, dict) else fallback

def snapshot_evidence(path_raw):
    snapshot = load(path_raw, {})
    if not snapshot:
        return {"captured": False}
    return {
        "captured": True,
        "path": Path(path_raw).name,
        "schema": snapshot.get("schema"),
        "snapshot_sha256": snapshot.get("snapshot_sha256"),
        "option_names": snapshot.get("option_names", []),
        "option_count": snapshot.get("option_count", 0),
    }

reference_charge = decode(reference_charge_raw, {})
target_charge = decode(target_charge_raw, {})
charges = {"reference": reference_charge, "target": target_charge}
money_metadata = {
    "reference": decode(reference_money_meta_raw, {}),
    "target": decode(target_money_meta_raw, {}),
}
created_order_ids = {}
retained_local_orders = []
provider_residue = []
for role, charge in charges.items():
    try:
        order_id = int(charge.get("order_id") or 0)
    except (TypeError, ValueError):
        order_id = 0
    if order_id > 0:
        created_order_ids[role] = order_id
        retained_local_orders.append(
            {
                "role": role,
                "order_id": order_id,
                "disposition": "retained_for_reconciliation_evidence",
            }
        )
    recorded_provider_ids = set()
    for resource, field in (("charge", "charge_id"), ("payment_intent", "intent_id")):
        resource_id = str(charge.get(field) or "")
        if resource_id:
            recorded_provider_ids.add(resource_id)
            provider_residue.append(
                {
                    "role": role,
                    "resource": resource,
                    "id": resource_id,
                    "disposition": "retained",
                    "reason": "provider-backed evidence cannot be rolled back by the harness",
                }
            )
    observed_meta = money_metadata[role].get("meta")
    observed_meta = observed_meta if isinstance(observed_meta, dict) else {}
    provider_transaction_id = str(
        observed_meta.get("_wcpay_payment_transaction_id")
        or charge.get("transaction_id")
        or ""
    )
    if provider_transaction_id and provider_transaction_id not in recorded_provider_ids:
        provider_residue.append(
            {
                "role": role,
                "resource": "provider_transaction",
                "id": provider_transaction_id,
                "disposition": "retained",
                "reason": "provider-backed evidence cannot be rolled back by the harness",
            }
        )

payload = {
    "schema": "woopayments_converted_currency_gate_result.v1",
    "status": status,
    "trigger": trigger,
    "message": message,
    "currency": currency,
    "sku": sku,
    "quantity": int(quantity_raw),
    "created_order_ids": created_order_ids,
    "retained_local_orders": retained_local_orders,
    "provider_residue": provider_residue,
    "reference": {
        "runtime": "plugin",
        "rate_refresh": load(
            reference_rate_raw,
            {"status": "not_run", "role": "reference", "runtime": "plugin"},
        ),
        "charge": reference_charge,
        "money_metadata": money_metadata["reference"],
        "reconciliation": {
            "attempted": reference_attempted_raw == "true",
            "passed": reference_passed_raw == "true",
        },
    },
    "target": {
        "runtime": "native",
        "rate_refresh": load(
            target_rate_raw,
            {"status": "not_run", "role": "target", "runtime": "native"},
        ),
        "charge": target_charge,
        "money_metadata": money_metadata["target"],
        "reconciliation": {
            "attempted": target_attempted_raw == "true",
            "passed": target_passed_raw == "true",
        },
    },
    "option_snapshots": {
        "reference": snapshot_evidence(reference_snapshot_raw),
        "target": snapshot_evidence(target_snapshot_raw),
    },
    "option_restore": {
        "reference": decode(reference_restore_raw, {"restored": False, "verified": False}),
        "target": decode(target_restore_raw, {"restored": False, "verified": False}),
    },
}
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
PY
	local write_status=$?
	if [ "$write_status" -eq 0 ]; then
		FINAL_RESULT_WRITTEN=1
	fi
	return "$write_status"
}

finalize_harness_state() {
	local trigger="${1:-normal}"
	local restore_failed=0
	local target_restore_ok=1
	local reference_restore_ok=1

	if [ "$FINALIZATION_DONE" -eq 1 ]; then
		[ "$FINALIZATION_STATUS" = "pass" ]
		return
	fi
	if [ "$FINALIZATION_RUNNING" -eq 1 ]; then
		FINALIZATION_STATUS="blocked"
		return 1
	fi
	FINALIZATION_RUNNING=1
	GATE_TRIGGER="$trigger"

	# Restore in reverse mutation order.
	if [ "$TARGET_RESTORE_REQUIRED" -eq 1 ]; then
		if ! TARGET_RESTORE_JSON="$(woopayments_mc_restore_options "$TARGET_WP" target "$TARGET_SNAPSHOT_FILE")"; then
			target_restore_ok=0
			if [ "$PENDING_SIGNAL_EXIT_CODE" -ne 0 ] && TARGET_RESTORE_JSON="$(woopayments_mc_restore_options "$TARGET_WP" target "$TARGET_SNAPSHOT_FILE")"; then
				target_restore_ok=1
			fi
		fi
		if [ "$target_restore_ok" -ne 1 ]; then
			TARGET_RESTORE_JSON='{"restored":false,"verified":false,"error_code":"restore_command_failed"}'
			restore_failed=1
		elif ! woopayments_mc_validate_restore "$TARGET_RESTORE_JSON" "$TARGET_SNAPSHOT_FILE" target; then
			restore_failed=1
		fi
	fi
	if [ "$REF_RESTORE_REQUIRED" -eq 1 ]; then
		if ! REF_RESTORE_JSON="$(woopayments_mc_restore_options "$REF_WP" reference "$REF_SNAPSHOT_FILE")"; then
			reference_restore_ok=0
			if [ "$PENDING_SIGNAL_EXIT_CODE" -ne 0 ] && REF_RESTORE_JSON="$(woopayments_mc_restore_options "$REF_WP" reference "$REF_SNAPSHOT_FILE")"; then
				reference_restore_ok=1
			fi
		fi
		if [ "$reference_restore_ok" -ne 1 ]; then
			REF_RESTORE_JSON='{"restored":false,"verified":false,"error_code":"restore_command_failed"}'
			restore_failed=1
		elif ! woopayments_mc_validate_restore "$REF_RESTORE_JSON" "$REF_SNAPSHOT_FILE" reference; then
			restore_failed=1
		fi
	fi

	FINALIZATION_STATUS="pass"
	if [ "$restore_failed" -ne 0 ]; then
		FINALIZATION_STATUS="blocked"
	fi
	FINALIZATION_DONE=1
	FINALIZATION_RUNNING=0
	[ "$FINALIZATION_STATUS" = "pass" ]
}

resolve_finalization_outcome() {
	local requested_exit_code="$1"
	FINAL_EXIT_CODE="$requested_exit_code"

	if [ "$PENDING_SIGNAL_EXIT_CODE" -ne 0 ]; then
		GATE_STATUS="blocked"
		GATE_MESSAGE="gate interrupted during option restoration"
		GATE_TRIGGER="signal"
		FINAL_EXIT_CODE="$PENDING_SIGNAL_EXIT_CODE"
	fi
	if [ "$FINALIZATION_STATUS" != "pass" ]; then
		GATE_STATUS="blocked"
		if [ "$PENDING_SIGNAL_EXIT_CODE" -ne 0 ]; then
			GATE_MESSAGE="gate interrupted during option restoration; exact option restoration was not verified"
		else
			GATE_MESSAGE="$GATE_MESSAGE; exact option restoration was not verified"
		fi
		FINAL_EXIT_CODE=70
	fi
}

handle_signal() {
	local exit_code="$1"
	if [ "$FINALIZATION_RUNNING" -eq 1 ]; then
		PENDING_SIGNAL_EXIT_CODE="$exit_code"
		GATE_STATUS="blocked"
		GATE_MESSAGE="gate interrupted during option restoration"
		GATE_TRIGGER="signal"
		trap '' HUP INT TERM
		return 0
	fi
	PENDING_SIGNAL_EXIT_CODE="$exit_code"
	trap '' HUP INT TERM
	GATE_STATUS="blocked"
	GATE_MESSAGE="gate interrupted; option restoration was attempted"
	finalize_harness_state signal || true
	resolve_finalization_outcome "$exit_code"
	write_result_evidence >/dev/null 2>&1 || true
	exit "$FINAL_EXIT_CODE"
}

handle_exit() {
	local exit_code="$1"
	trap - EXIT
	if [ "$FINALIZATION_DONE" -ne 1 ]; then
		finalize_harness_state exit || true
	fi
	resolve_finalization_outcome "$exit_code"
	write_result_evidence >/dev/null 2>&1 || {
		[ "$FINAL_EXIT_CODE" -eq 0 ] && FINAL_EXIT_CODE=3
	}
	exit "$FINAL_EXIT_CODE"
}

# Traps are installed before either store can be mutated. Restore flags are armed
# only after both exact snapshots have been captured and validated.
trap 'handle_signal 129' HUP
trap 'handle_signal 130' INT
trap 'handle_signal 143' TERM
trap 'handle_exit $?' EXIT

finish_gate() {
	local status="$1"
	local exit_code="$2"
	local message="$3"

	GATE_STATUS="$status"
	GATE_MESSAGE="$message"
	finalize_harness_state normal || true
	resolve_finalization_outcome "$exit_code"
	if ! write_result_evidence; then
		printf 'BLOCKED: converted-currency evidence could not be written.\n' >&2
		[ "$FINAL_EXIT_CODE" -ne 70 ] && FINAL_EXIT_CODE=3
		exit "$FINAL_EXIT_CODE"
	fi

	case "$GATE_STATUS" in
		pass)
			printf 'PASS: converted-currency charge and reconciliation passed on reference order %s and target order %s.\n' \
				"$(json_field "$REF_CHARGE_JSON" order_id)" \
				"$(json_field "$TARGET_CHARGE_JSON" order_id)"
			;;
		blocked) printf 'BLOCKED: %s\n' "$GATE_MESSAGE" >&2 ;;
		*) printf 'FAIL: %s\n' "$GATE_MESSAGE" >&2 ;;
	esac
	exit "$FINAL_EXIT_CODE"
}

assert_target_native_owner() {
	local raw owner

	# shellcheck disable=SC2086
	if ! raw="$($TARGET_WP wc-native-payments status 2>&1)"; then
		return 1
	fi
	owner="$(printf '%s\n' "$raw" | awk -F': ' '/^Owner:/ { print $2; exit }' | tr -d '\r')"
	[ "$owner" = "native" ]
}

prepare_automatic_rates() {
	local wp_cmd="$1"
	local role="$2"
	local runtime="$3"
	local rate_file="$4"
	local configure_json build_json observation_json refresh_status

	if ! configure_json="$(woopayments_mc_configure_automatic "$wp_cmd" "$role" "$runtime" "$CURRENCY")"; then
		return 1
	fi
	if ! build_json="$(woopayments_mc_build_runtime_state "$wp_cmd" "$role" "$runtime")"; then
		return 1
	fi
	if ! observation_json="$(woopayments_mc_inspect_rates "$wp_cmd" "$role" "$runtime" "$CURRENCY")"; then
		return 1
	fi
	if ! refresh_status="$(woopayments_mc_write_refresh_evidence \
		"$rate_file" "$role" "$runtime" "$CURRENCY" \
		"$configure_json" "$build_json" "$observation_json")"; then
		return 1
	fi
	[ "$refresh_status" = "pass" ]
}

capture_known_charge() {
	local role="$1"
	local json="$2"
	local order_id

	if ! order_id="$(json_field "$json" order_id 2>/dev/null)"; then
		return 1
	fi
	if ! printf '%s' "$order_id" | grep -qE '^[1-9][0-9]*$'; then
		return 1
	fi
	if [ "$role" = "reference" ]; then
		REF_CHARGE_JSON="$json"
	else
		TARGET_CHARGE_JSON="$json"
	fi
}

drive_charge() {
	local wp_cmd="$1"
	local role="$2"
	local native="$3"
	local raw rc json order_id order_currency charge_id

	printf 'Drive %s converted charge\n' "$role" >&2
	if [ "$native" = "1" ]; then
		raw="$(WP="$wp_cmd" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --native --sku="$SKU" --quantity="$QUANTITY" --type=success --currency="$CURRENCY" 2>&1)"
	else
		raw="$(WP="$wp_cmd" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --sku="$SKU" --quantity="$QUANTITY" --type=success --currency="$CURRENCY" 2>&1)"
	fi
	rc=$?
	json="$(printf '%s\n' "$raw" | woopayments_mc_last_json_line)"
	if [ -n "$json" ]; then
		capture_known_charge "$role" "$json" >/dev/null 2>&1 || true
	fi
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'FAIL (%s): converted charge did not complete.\n' "$role" >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 1
	fi

	order_id="$(json_field "$json" order_id 2>/dev/null || true)"
	order_currency="$(json_field "$json" order_currency 2>/dev/null || true)"
	charge_id="$(json_field "$json" charge_id 2>/dev/null || true)"
	if ! printf '%s' "$order_id" | grep -qE '^[1-9][0-9]*$' || [ -z "$charge_id" ]; then
		printf 'FAIL (%s): converted charge emitted no provider-backed order.\n' "$role" >&2
		return 1
	fi
	if [ "$order_currency" != "$CURRENCY" ]; then
		printf 'FAIL (%s): requested %s but order %s currency is %s.\n' \
			"$role" "$CURRENCY" "$order_id" "${order_currency:-<empty>}" >&2
		return 1
	fi

	printf '  ok: order %s charge %s currency %s\n' "$order_id" "$charge_id" "$order_currency" >&2
}

wait_order_money_meta() {
	local wp_cmd="$1"
	local role="$2"
	local order_id="$3"
	local attempt raw rc json missing_count

	printf 'Wait for %s order %s money metadata\n' "$role" "$order_id"
	for attempt in $(seq 1 20); do
		# shellcheck disable=SC2086
		raw="$($wp_cmd eval "
			\$order = wc_get_order( $order_id );
			if ( ! \$order ) {
				WP_CLI::line( wp_json_encode( array( 'missing' => array( 'order' ) ) ) );
				return;
			}
			\$keys = array(
				'_charge_id',
				'_wcpay_payment_transaction_id',
				'_wcpay_transaction_fee',
				'_wcpay_net',
				'_wcpay_intent_currency',
				'_wcpay_multi_currency_stripe_exchange_rate',
				'_wcpay_multi_currency_order_exchange_rate',
				'_wcpay_multi_currency_order_default_currency',
			);
			\$meta = array();
			\$missing = array();
			foreach ( \$keys as \$key ) {
				\$value = (string) \$order->get_meta( \$key, true );
				\$meta[ \$key ] = \$value;
				if ( '' === \$value ) {
					\$missing[] = \$key;
				}
			}
			WP_CLI::line( wp_json_encode( array( 'missing' => \$missing, 'meta' => \$meta ) ) );
		" 2>&1)"
		rc=$?
		json="$(printf '%s\n' "$raw" | woopayments_mc_last_json_line)"
		if [ "$rc" -eq 0 ] && [ -n "$json" ]; then
			missing_count="$(python3 - "$json" <<'PY'
import json
import sys

missing = json.loads(sys.argv[1]).get("missing")
print(len(missing) if isinstance(missing, list) else 1)
PY
)"
			if [ "$missing_count" = "0" ]; then
				if [ "$role" = "reference" ]; then
					REF_MONEY_META_JSON="$json"
				else
					TARGET_MONEY_META_JSON="$json"
				fi
				printf '  ok: required money metadata is present\n'
				return 0
			fi
		fi
		sleep 2
	done

	printf 'FAIL (%s): order %s did not expose required money metadata before reconciliation.\n' "$role" "$order_id" >&2
	return 1
}

reconcile_order() {
	local wp_cmd="$1"
	local role="$2"
	local order_id="$3"

	printf 'Reconcile %s order %s against Stripe raw source\n' "$role" "$order_id"
	if [ "$role" = "reference" ]; then
		REF_RECONCILIATION_ATTEMPTED=true
	else
		TARGET_RECONCILIATION_ATTEMPTED=true
	fi
	if ! WP="$wp_cmd" bash "$SELF_DIR/financial-reconcile.sh" "$order_id"; then
		printf 'FAIL (%s): financial reconciliation failed for converted-currency order %s.\n' "$role" "$order_id" >&2
		return 1
	fi
	if [ "$role" = "reference" ]; then
		REF_RECONCILIATION_PASSED=true
	else
		TARGET_RECONCILIATION_PASSED=true
	fi
}

printf 'N7a converted-currency gate\n'
printf '  currency: %s\n' "$CURRENCY"
printf '  sku: %s\n' "$SKU"
printf '  quantity: %s\n\n' "$QUANTITY"

if ! assert_target_native_owner; then
	finish_gate blocked 3 "target native WooPayments runtime is not the active owner"
fi

printf 'Snapshot reference multi-currency option rows\n'
if ! woopayments_mc_snapshot_options "$REF_WP" reference "$CURRENCY" > "$REF_SNAPSHOT_FILE"; then
	finish_gate blocked 3 "reference option snapshot could not be captured"
fi
if ! snapshot_error="$(woopayments_mc_validate_snapshot_file "$REF_SNAPSHOT_FILE" reference "$CURRENCY" 2>&1)"; then
	finish_gate blocked 3 "reference option snapshot is invalid: $snapshot_error"
fi

printf 'Snapshot target multi-currency option rows\n'
if ! woopayments_mc_snapshot_options "$TARGET_WP" target "$CURRENCY" > "$TARGET_SNAPSHOT_FILE"; then
	finish_gate blocked 3 "target option snapshot could not be captured"
fi
if ! snapshot_error="$(woopayments_mc_validate_snapshot_file "$TARGET_SNAPSHOT_FILE" target "$CURRENCY" 2>&1)"; then
	finish_gate blocked 3 "target option snapshot is invalid: $snapshot_error"
fi

# Arm both restores before the first update_option/delete_option operation.
REF_RESTORE_REQUIRED=1
TARGET_RESTORE_REQUIRED=1

printf 'Configure and prove reference plugin automatic rates\n'
if ! prepare_automatic_rates "$REF_WP" reference plugin "$REF_RATE_FILE"; then
	finish_gate blocked 3 "reference plugin automatic-rate refresh was not proven"
fi
printf 'Configure and prove target native automatic rates\n'
if ! prepare_automatic_rates "$TARGET_WP" target native "$TARGET_RATE_FILE"; then
	finish_gate blocked 3 "target native automatic-rate refresh was not proven"
fi
if ! drive_charge "$REF_WP" reference 0; then
	finish_gate fail 1 "reference converted charge did not complete"
fi
if ! drive_charge "$TARGET_WP" target 1; then
	finish_gate fail 1 "target converted charge did not complete"
fi

ref_order_id="$(json_field "$REF_CHARGE_JSON" order_id)"
target_order_id="$(json_field "$TARGET_CHARGE_JSON" order_id)"
if ! wait_order_money_meta "$REF_WP" reference "$ref_order_id"; then
	finish_gate fail 1 "reference order money metadata remained incomplete"
fi
if ! reconcile_order "$REF_WP" reference "$ref_order_id"; then
	finish_gate fail 1 "reference financial reconciliation failed"
fi
if ! wait_order_money_meta "$TARGET_WP" target "$target_order_id"; then
	finish_gate fail 1 "target order money metadata remained incomplete"
fi
if ! reconcile_order "$TARGET_WP" target "$target_order_id"; then
	finish_gate fail 1 "target financial reconciliation failed"
fi

finish_gate pass 0 "converted-currency automatic rates, charges, and reconciliation passed"
