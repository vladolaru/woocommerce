#!/usr/bin/env bash
#
# Plugin-active WooPayments settings gate for the WooPayments -> core merge harness.
#
# This local-only transition harness verifies that the target store still renders
# the standalone WooPayments settings screen while the plugin owns the payments
# surface. It fails closed before opening the browser if the plugin is not active.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BROWSER_DRIVER="$SELF_DIR/plugin-active-settings.playwriter.mjs"

TARGET_WP=""
TARGET_URL="${TARGET_URL:-}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/plugin-active-settings-gate"
PRINT_PLAN=0
PREFLIGHT_ONLY=0

usage() {
	cat >&2 <<'USAGE'
usage:
  plugin-active-settings-gate.sh --target "<target wp>" --target-url <url> [options]

Options:
  --target "<wp>"              Target store WP-CLI command.
  --target-url <url>           Target store browser base URL.
  --playwriter-session <id>    Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>             Evidence output directory.
  --preflight-only             Validate arguments, dependencies, and plugin-active state, then exit.
  --print-plan                 Print the normalized gate plan as JSON, then exit.
  -h, --help                   Show this help.

The gate requires the WooPayments plugin to be active on the target store. It
then opens the real wp-admin WooPayments settings URL and rejects blank screens,
login redirects, failed responses, and duplicate wc/payments/settings store
registration errors.
USAGE
}

progress() {
	printf 'Plugin-active settings gate: %s\n' "$*" >&2
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

while [ "$#" -gt 0 ]; do
	case "$1" in
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--target-url=*) TARGET_URL="${1#--target-url=}"; shift ;;
		--target-url) TARGET_URL="${2:-}"; shift 2 ;;
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

if [ -z "$TARGET_WP" ] || [ -z "$TARGET_URL" ]; then
	usage_error "--target and --target-url are required."
fi

TARGET_URL="${TARGET_URL%/}"
SETTINGS_URL="$TARGET_URL/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"

validate_local_url() {
	local label="$1"
	local value="$2"

	python3 - "$label" "$value" <<'PY'
import sys
from urllib.parse import urlparse

label, value = sys.argv[1:]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"{label} must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"{label} must stay local, got {value}")
PY
}

print_plan() {
	python3 - "$TARGET_WP" "$TARGET_URL" "$SETTINGS_URL" "$BROWSER_DRIVER" <<'PY'
import json
import sys

target_wp, target_url, settings_url, browser_driver = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_plugin_active_settings_gate_plan.v1",
            "target_wp": target_wp,
            "target_url": target_url,
            "settings_url": settings_url,
            "browser_driver": browser_driver,
            "checks": [
                "woocommerce-payments plugin is active before browser run",
                "authenticated wp-admin settings page renders",
                "WooPayments settings screen is present",
                "no duplicate wc/payments/settings store registration error",
            ],
        },
        sort_keys=True,
    )
)
PY
}

assert_plugin_active() {
	local output

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! output="$($TARGET_WP plugin is-active woocommerce-payments 2>&1)"; then
		blocked "WooPayments plugin is not active on the target store; run this gate before native cutover or restore the plugin-active fixture. $output"
	fi
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

validate_driver_evidence() {
	local evidence_path="$1"

	python3 - "$TARGET_URL" "$SETTINGS_URL" "$evidence_path" <<'PY'
import json
import sys

target_url, settings_url, evidence_path = sys.argv[1:]
errors = []
try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
    "target_url": target_url,
    "settings_url": settings_url,
}
for key, expected in expected_values.items():
    if payload.get(key) != expected:
        errors.append(f"{key} mismatch: expected {expected!r}, got {payload.get(key)!r}")

if payload.get("status") != "pass":
    errors.append(f"status is not pass: {payload.get('status')!r}")
if payload.get("plugin_active") is not True:
    errors.append("plugin_active is not true")
if payload.get("authenticated_wp_admin") is not True:
    errors.append("authenticated wp-admin settings page did not render")
if payload.get("settings_screen_present") is not True:
    errors.append("WooPayments settings screen is not present")
if payload.get("duplicate_store_errors"):
    errors.append("duplicate wc/payments/settings store registration error")
if payload.get("fatal_console_errors"):
    errors.append("fatal browser console errors were captured")
if payload.get("failed_responses"):
    errors.append("failed browser responses were captured")
for failure in payload.get("failures", []):
    errors.append(f"browser failure: {failure}")

for error in errors:
    print(error)

if errors:
    raise SystemExit(1)
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/plugin-active-settings-gate.json"
	local evidence_path="$1"

	python3 - "$rollup_path" "$TARGET_URL" "$SETTINGS_URL" "$evidence_path" "$FAILURES_FILE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, target_url, settings_url, evidence_path, failures_file = sys.argv[1:]

def load_json(path):
    candidate = Path(path)
    if not candidate.exists():
        return None
    return json.loads(candidate.read_text(encoding="utf-8"))

failures_path = Path(failures_file)
failures = []
if failures_path.exists():
    failures = [line for line in failures_path.read_text(encoding="utf-8").splitlines() if line.strip()]

payload = {
    "schema": "woopayments_plugin_active_settings_gate_rollup.v1",
    "status": "fail" if failures else "pass",
    "target_url": target_url,
    "settings_url": settings_url,
    "evidence": load_json(evidence_path),
    "failures": failures,
}
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

run_browser_gate() {
	local evidence_path="$OUT_DIR/plugin-active-settings.json"
	local log_path="$OUT_DIR/plugin-active-settings.playwriter.log"
	local exit_code
	local validation_output

	rm -f "$evidence_path"
	progress "driving settings page at $SETTINGS_URL"

	PLUGIN_SETTINGS_TARGET_URL="$TARGET_URL" \
	PLUGIN_SETTINGS_SETTINGS_URL="$SETTINGS_URL" \
	PLUGIN_SETTINGS_EVIDENCE_PATH="$evidence_path" \
	PLUGIN_SETTINGS_DATA_DIR="$OUT_DIR" \
	"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$BROWSER_DRIVER" --timeout "180000" >"$log_path" 2>&1
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "Playwriter exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "missing browser evidence $evidence_path"
		write_rollup "$evidence_path"
		return
	fi

	if ! validation_output="$(validate_driver_evidence "$evidence_path" 2>&1)"; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$line"
			fi
		done <<< "$validation_output"
	fi

	write_rollup "$evidence_path"
}

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
URL_VALIDATION_OUTPUT="$(validate_local_url "target URL" "$TARGET_URL" 2>&1)"
if [ "$?" -ne 0 ]; then
	blocked "$URL_VALIDATION_OUTPUT"
fi

if [ "$PRINT_PLAN" -eq 1 ]; then
	print_plan
	exit 0
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

mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
FAILURES_FILE="$OUT_DIR/plugin-active-settings-failures.txt"
: > "$FAILURES_FILE"

assert_plugin_active

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok for plugin-active settings gate."
	exit 0
fi

if [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running plugin-active settings browser flow."
fi

run_browser_gate || blocked "could not run plugin-active settings browser gate."

if [ -s "$FAILURES_FILE" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/plugin-active-settings-gate.json"
