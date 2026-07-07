#!/usr/bin/env bash
#
# Token-continuity cutover gate for the WooPayments -> core merge harness.
#
# This local-only transition harness proves that a WooPayments plugin-created
# SEPA token remains visible and renewable after the target store cuts over to
# native WooPayments. It fails closed: browser evidence, CLI token visibility,
# and the renewal driver must all report success.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_DRIVER="$SELF_DIR/token-continuity-state.php"
RENEWAL_DRIVER="$SELF_DIR/subscriptions-renewal-drive.php"
BROWSER_DRIVER="$SELF_DIR/token-continuity.playwriter.mjs"

TARGET_WP=""
CUSTOMER_ID=""
SUBSCRIPTION_ID=""
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/token-continuity-gate"
PRINT_PLAN=0
PREFLIGHT_ONLY=0

METHOD="sepa_debit"
GATEWAY_ID="woocommerce_payments_sepa_debit"
STRIPE_PAYMENT_METHOD_TYPE="sepa_debit"
TOKEN_TYPE="wcpay_sepa"

RESTORE_NEEDED=0

usage() {
	cat >&2 <<'USAGE'
usage:
  token-continuity-gate.sh --target "<target wp>" --customer-id <id> --subscription-id <id> [options]

Options:
  --target "<wp>"              Target store WP-CLI command.
  --customer-id <id>           Customer/user ID expected to own the saved token.
  --subscription-id <id>       Browser-created SEPA subscription ID to renew after cutover.
  --playwriter-session <id>    Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>             Evidence output directory.
  --preflight-only             Validate arguments, dependencies, and plugin-side preflight, then exit.
  --print-plan                 Print the normalized gate plan as JSON, then exit.
  -h, --help                   Show this help.

The gate starts with the separate WooPayments plugin active on the target,
saves a SEPA token through the browser driver, cuts over to native WooPayments,
asserts WP-CLI and My Account token visibility, drives a renewal for the given
subscription ID, then restores the plugin state.
USAGE
}

progress() {
	printf 'Token continuity gate: %s\n' "$*" >&2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

positive_int_or_usage() {
	local label="$1"
	local value="$2"

	case "$value" in
		''|*[!0-9]*)
			usage_error "$label must be a positive integer."
			;;
	esac
	if [ "$value" -le 0 ]; then
		usage_error "$label must be a positive integer."
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--customer-id=*) CUSTOMER_ID="${1#--customer-id=}"; shift ;;
		--customer-id) CUSTOMER_ID="${2:-}"; shift 2 ;;
		--subscription-id=*) SUBSCRIPTION_ID="${1#--subscription-id=}"; shift ;;
		--subscription-id) SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--playwriter-session=*) PLAYWRITER_SESSION="${1#--playwriter-session=}"; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--preflight-only) PREFLIGHT_ONLY=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ -z "$TARGET_WP" ] || [ -z "$CUSTOMER_ID" ] || [ -z "$SUBSCRIPTION_ID" ]; then
	usage_error "--target, --customer-id, and --subscription-id are required."
fi

positive_int_or_usage "--customer-id" "$CUSTOMER_ID"
positive_int_or_usage "--subscription-id" "$SUBSCRIPTION_ID"

print_plan() {
	python3 - "$TARGET_WP" "$CUSTOMER_ID" "$SUBSCRIPTION_ID" "$METHOD" "$GATEWAY_ID" "$STRIPE_PAYMENT_METHOD_TYPE" "$TOKEN_TYPE" <<'PY'
import json
import sys

target, customer_id, subscription_id, method, gateway_id, stripe_type, token_type = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_token_continuity_gate_plan.v1",
            "target_wp": target,
            "customer_id": int(customer_id),
            "subscription_id": int(subscription_id),
            "method": method,
            "gateway_id": gateway_id,
            "stripe_payment_method_type": stripe_type,
            "token_type": token_type,
            "checks": [
                "plugin_checkout_saves_sepa_token",
                "native_cutover_cli_lists_token",
                "native_my_account_renders_token",
                "native_sepa_subscription_renewal_succeeds",
            ],
        },
        sort_keys=True,
    )
)
PY
}

if [ "$PRINT_PLAN" -eq 1 ]; then
	print_plan
	exit 0
fi

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
if [ ! -f "$STATE_DRIVER" ]; then
	blocked "state driver is missing: $STATE_DRIVER"
fi
if [ ! -f "$RENEWAL_DRIVER" ]; then
	blocked "renewal driver is missing: $RENEWAL_DRIVER"
fi
if [ ! -f "$BROWSER_DRIVER" ]; then
	blocked "browser driver is missing: $BROWSER_DRIVER"
fi

if [ -n "${PLAYWRITER_BIN:-}" ]; then
	# shellcheck disable=SC2206
	PLAYWRITER_CMD=( $PLAYWRITER_BIN )
elif command -v playwriter >/dev/null 2>&1; then
	PLAYWRITER_CMD=( playwriter )
elif command -v npx >/dev/null 2>&1; then
	PLAYWRITER_CMD=( npx --yes playwriter@latest )
else
	blocked "Playwriter is required. Install playwriter or provide PLAYWRITER_BIN."
fi

wp_home_url() {
	local output
	local url

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! output="$($TARGET_WP option get home 2>&1)"; then
		blocked "target store home URL probe failed: $output"
	fi

	url="$(printf '%s\n' "$output" | awk 'NF { value = $0 } END { print value }')"
	url="${url%/}"
	if [ -z "$url" ]; then
		blocked "target store home URL probe returned an empty URL."
	fi

	python3 - "$url" <<'PY'
import sys
from urllib.parse import urlparse

value = sys.argv[1]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"target store home URL must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"target store home URL must stay local, got {value}")
PY

	printf '%s\n' "$url"
}

run_eval_file() {
	local driver="$1"
	local role="$2"
	local out_file="$3"
	shift 3
	local raw rc json

	# Intentionally split the WP runner string, matching the rest of this
	# harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - "$@" < "$driver" 2>&1)"
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
		printf '%s\n' "$json" >&2
		return "$rc"
	fi

	return 0
}

json_success() {
	python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    payload = json.load(stream)
raise SystemExit(0 if isinstance(payload, dict) and payload.get("success") else 1)
PY
}

print_errors() {
	python3 - "$1" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    raise SystemExit(0)
for error in payload.get("errors", []):
    print(f"  - {error}", file=sys.stderr)
PY
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

json_field() {
	local path="$1"
	local field="$2"

	python3 - "$path" "$field" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    payload = json.load(stream)
value = payload.get(sys.argv[2])
if value is None:
    raise SystemExit(1)
print(value)
PY
}

validate_browser_evidence() {
	local phase="$1"
	local evidence_path="$2"
	local expected_token_id="${3:-}"

	python3 - "$phase" "$METHOD" "$GATEWAY_ID" "$STRIPE_PAYMENT_METHOD_TYPE" "$TOKEN_TYPE" "$CUSTOMER_ID" "$expected_token_id" "$evidence_path" <<'PY'
import json
import sys

phase, method, gateway_id, stripe_type, token_type, customer_id, expected_token_id, evidence_path = sys.argv[1:]
errors = []
try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "phase": phase,
    "method": method,
    "gateway_id": gateway_id,
    "stripe_payment_method_type": stripe_type,
    "token_type": token_type,
    "customer_id": int(customer_id),
}
for key, expected in expected_values.items():
    if payload.get(key) != expected:
        errors.append(f"{key} mismatch: expected {expected!r}, got {payload.get(key)!r}")

if payload.get("status") != "pass":
    errors.append(f"status is not pass: {payload.get('status')!r}")

token_id = payload.get("token_id")
if not isinstance(token_id, int) or token_id <= 0:
    errors.append("missing token_id")
elif expected_token_id and token_id != int(expected_token_id):
    errors.append(f"token_id mismatch: expected {expected_token_id}, got {token_id}")

if phase == "render_payment_methods" and payload.get("token_visible") is not True:
    errors.append("token_visible is not true")

for error in errors:
    print(error)

if errors:
    raise SystemExit(1)
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/token-continuity-gate.json"
	local token_id="${1:-0}"
	local renewal_path="${2:-}"

	python3 - "$rollup_path" "$CUSTOMER_ID" "$SUBSCRIPTION_ID" "$token_id" "$SAVE_EVIDENCE" "$RENDER_EVIDENCE" "$renewal_path" "$FAILURES_FILE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, customer_id, subscription_id, token_id, save_path, render_path, renewal_path, failures_file = sys.argv[1:]

def load_json(path):
    if not path:
        return None
    candidate = Path(path)
    if not candidate.exists():
        return None
    return json.loads(candidate.read_text(encoding="utf-8"))

failures_path = Path(failures_file)
failures = []
if failures_path.exists():
    failures = [line for line in failures_path.read_text(encoding="utf-8").splitlines() if line.strip()]

payload = {
    "schema": "woopayments_token_continuity_gate_rollup.v1",
    "status": "fail" if failures else "pass",
    "customer_id": int(customer_id),
    "subscription_id": int(subscription_id),
    "token_id": int(token_id or 0),
    "save_token": load_json(save_path),
    "render_payment_methods": load_json(render_path),
    "renewal": load_json(renewal_path),
    "failures": failures,
}
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

run_state_checked() {
	local mode="$1"
	local out_file="$2"
	shift 2

	progress "state check: $mode"
	if ! run_eval_file "$STATE_DRIVER" "$mode" "$out_file" "$mode" "$@"; then
		print_errors "$out_file"
		return 1
	fi
	if ! json_success "$out_file"; then
		print_errors "$out_file"
		return 1
	fi
	return 0
}

restore_if_needed() {
	if [ "$RESTORE_NEEDED" -eq 1 ]; then
		progress "restoring WooPayments plugin state"
		run_eval_file "$STATE_DRIVER" "restore" "$RESTORE_JSON" restore >/dev/null 2>&1 || true
		RESTORE_NEEDED=0
	fi
}

run_browser_phase() {
	local phase="$1"
	local evidence_path="$2"
	local token_id="${3:-}"
	local log_path="$OUT_DIR/${phase}.playwriter.log"
	local exit_code
	local validation_output

	progress "browser phase: $phase"
	rm -f "$evidence_path"

	TOKEN_CONTINUITY_GATE_PHASE="$phase" \
	TOKEN_CONTINUITY_GATE_BASE_URL="$TARGET_BASE_URL" \
	TOKEN_CONTINUITY_GATE_METHOD="$METHOD" \
	TOKEN_CONTINUITY_GATE_GATEWAY_ID="$GATEWAY_ID" \
	TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE="$STRIPE_PAYMENT_METHOD_TYPE" \
	TOKEN_CONTINUITY_GATE_TOKEN_TYPE="$TOKEN_TYPE" \
	TOKEN_CONTINUITY_GATE_CUSTOMER_ID="$CUSTOMER_ID" \
	TOKEN_CONTINUITY_GATE_TOKEN_ID="$token_id" \
	TOKEN_CONTINUITY_GATE_EVIDENCE_PATH="$evidence_path" \
	"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$BROWSER_DRIVER" --timeout "300000" >"$log_path" 2>&1
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "$phase: Playwriter exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "$phase: missing browser evidence $evidence_path"
		return
	fi

	if ! validation_output="$(validate_browser_evidence "$phase" "$evidence_path" "$token_id" 2>&1)"; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$phase: $line"
			fi
		done <<< "$validation_output"
	fi
}

assert_native_token_list_contains() {
	local token_id="$1"
	local raw
	local validation_output

	progress "checking native WP-CLI payment token list for token $token_id"
	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! raw="$($TARGET_WP wc payment_token list --user="$CUSTOMER_ID" --format=json 2>&1)"; then
		record_failure "native payment_token list command failed: $raw"
		return
	fi

	printf '%s\n' "$raw" > "$TOKEN_LIST_RAW"
	if ! validation_output="$(python3 - "$token_id" "$GATEWAY_ID" "$TOKEN_TYPE" "$TOKEN_LIST_RAW" 2>&1 <<'PY'
import json
import sys
from pathlib import Path

token_id, gateway_id, token_type, path = sys.argv[1:]
text = Path(path).read_text(encoding="utf-8")
payload = None
for line in reversed([line.strip() for line in text.splitlines() if line.strip()]):
    try:
        payload = json.loads(line)
        break
    except json.JSONDecodeError:
        continue
if isinstance(payload, str):
    payload = json.loads(payload)
if not isinstance(payload, list):
    raise SystemExit("payment_token list did not produce a JSON list")

for row in payload:
    row_token_id = row.get("token_id", row.get("id"))
    if str(row_token_id) != str(token_id):
        continue
    errors = []
    if row.get("gateway_id") != gateway_id:
        errors.append(f"gateway_id mismatch: expected {gateway_id!r}, got {row.get('gateway_id')!r}")
    if row.get("type") != token_type:
        errors.append(f"type mismatch: expected {token_type!r}, got {row.get('type')!r}")
    if errors:
        raise SystemExit("; ".join(errors))
    raise SystemExit(0)

raise SystemExit(f"saved token {token_id} is missing from native payment_token list")
PY
)"; then
		record_failure "$validation_output"
	fi
}

run_renewal() {
	local token_id="$1"

	progress "driving SEPA subscription renewal for subscription $SUBSCRIPTION_ID"
	if ! run_eval_file "$RENEWAL_DRIVER" "renewal" "$RENEWAL_JSON" drive "$SUBSCRIPTION_ID" "$GATEWAY_ID" "$token_id"; then
		print_errors "$RENEWAL_JSON"
		record_failure "renewal driver exited with failure"
		return
	fi
	if ! json_success "$RENEWAL_JSON"; then
		print_errors "$RENEWAL_JSON"
		record_failure "renewal driver did not report success"
	fi
}

TARGET_BASE_URL="$(wp_home_url)"

mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
FAILURES_FILE="$OUT_DIR/token-continuity-failures.txt"
SAVE_EVIDENCE="$OUT_DIR/save-sepa-token.json"
RENDER_EVIDENCE="$OUT_DIR/render-payment-methods.json"
PREFLIGHT_JSON="$OUT_DIR/preflight-plugin.json"
CUTOVER_JSON="$OUT_DIR/cutover-native.json"
RESTORE_JSON="$OUT_DIR/restore.json"
RENEWAL_JSON="$OUT_DIR/renewal.json"
TOKEN_LIST_RAW="$OUT_DIR/native-token-list.raw"
: > "$FAILURES_FILE"

trap restore_if_needed EXIT

if ! run_state_checked preflight-plugin "$PREFLIGHT_JSON"; then
	blocked "target store must start with the separate WooPayments plugin active and SEPA checkout ready."
fi

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok"
	exit 0
fi

if [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running browser token-continuity flows."
fi

run_browser_phase save_sepa_token "$SAVE_EVIDENCE"
TOKEN_ID="$(json_field "$SAVE_EVIDENCE" token_id 2>/dev/null || printf '0')"

if [ "$TOKEN_ID" -le 0 ]; then
	record_failure "save_sepa_token: browser evidence did not include a positive token_id"
	write_rollup "$TOKEN_ID" "$RENEWAL_JSON"
	exit 1
fi

if run_state_checked cutover-native "$CUTOVER_JSON" "$TOKEN_ID"; then
	RESTORE_NEEDED=1
else
	record_failure "cutover-native state check failed"
fi

assert_native_token_list_contains "$TOKEN_ID"
run_browser_phase render_payment_methods "$RENDER_EVIDENCE" "$TOKEN_ID"
run_renewal "$TOKEN_ID"

restore_if_needed

write_rollup "$TOKEN_ID" "$RENEWAL_JSON" || blocked "could not write token-continuity rollup."

if [ -s "$FAILURES_FILE" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/token-continuity-gate.json"
exit 0
