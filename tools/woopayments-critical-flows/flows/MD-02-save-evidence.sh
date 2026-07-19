#!/usr/bin/env bash
# MD-02 — recoverable dispute evidence draft save with independent persistence proof.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"
source "$DIR/../lib/md02-shell-contract.sh"

S="${STORE_NAME:-}"
RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
FLOW_ID="MD-02-save-evidence"
GATE="${MD02_GATE:-$REPO_ROOT/tools/woopayments-merge/md02-save-evidence-gate.py}"
EVIDENCE_TOOL="${MD02_EVIDENCE_TOOL:-$DIR/md02-evidence.py}"
CONTEXT_FILE="${MD02_CONTEXT_FILE:-${EVIDENCE_CONTEXT_FILE:-}}"
REF_SOURCE_MANIFEST="${MD02_REF_SOURCE_MANIFEST:-}"
TARGET_SOURCE_MANIFEST="${MD02_TARGET_SOURCE_MANIFEST:-}"
REF_BROWSER_URL="${MD02_REF_URL:-${REF_URL:-}}"
TARGET_BROWSER_URL="${MD02_TARGET_URL:-${TARGET_URL:-}}"
DELAY_SECONDS="${MD02_DELAY_SECONDS:-30}"
FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/$FLOW_ID"
STORE_EVIDENCE_DIR="$FLOW_EVIDENCE_DIR/$S"
PACKET="$STORE_EVIDENCE_DIR/$S-md02-store-packet.json"
RAW_LOG_SCAN="$STORE_EVIDENCE_DIR/$S-raw-log-scan.json"
LOG_SCAN="$STORE_EVIDENCE_DIR/$S-log-scan.json"
COMPARISON="$STORE_EVIDENCE_DIR/comparison.json"
EXECUTION="$STORE_EVIDENCE_DIR/$S-execution.json"
MANIFEST="$STORE_EVIDENCE_DIR/$S-manifest.json"

block() {
	echo "[MD-02/${S:-unknown}] BLOCKED: $1"
	exit 3
}

cleanup_md02_resources() {
	local exit_code=$?
	trap - EXIT
	if ! critical_flows_log_observer_cleanup; then
		echo "[MD-02/${S:-unknown}] cleanup-fatal: log observer cleanup failed." >&2
		exit 70
	fi
	exit "$exit_code"
}

trap cleanup_md02_resources EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

write_payload() {
	local payload="$1" destination="$2"
	[ -n "$payload" ] || return 3
	[ ! -e "$destination" ] || return 3
	printf '%s\n' "$payload" > "$destination"
}

assert_md02_check() {
	local check="$1"
	python3 - "$EVIDENCE_TOOL" "$PACKET" "$S" "$RUN_STAMP" "$check" <<'PY'
import importlib.util
import json
import sys

tool_path, packet_path, store, run_stamp, check = sys.argv[1:]
spec = importlib.util.spec_from_file_location("md02_evidence", tool_path)
if spec is None or spec.loader is None:
    raise SystemExit(3)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
try:
    packet = json.loads(open(packet_path, encoding="utf-8").read())
    module.validate_store_packet(packet, store=store, run_stamp=run_stamp)
except Exception:
    raise SystemExit(3)
mapping = {
    "exact_description": "description_persisted",
    "customer_name_preserved": "customer_name_preserved",
    "no_files_attached": "no_files_attached",
    "not_submitted": "not_submitted",
    "deadline_unchanged": "deadline_unchanged",
    "delayed_state_stable": "delayed_state_stable",
}
assertion = mapping.get(check)
if assertion is None:
    raise SystemExit(3)
raise SystemExit(0 if packet.get("deterministic_assertions", {}).get(assertion) is True else 1)
PY
}

assertion_failed=0
assertion_blocked=0
record_assertion() {
	local output rc
	output="$("$@" 2>&1)"
	rc=$?
	if [ "$rc" -eq 0 ]; then
		echo "  PASS: ${*: -1}"
	elif [ "$rc" -eq 1 ]; then
		echo "  FAIL: ${*: -1}${output:+ — $output}"
		assertion_failed=1
	else
		echo "  BLOCKED: ${*: -1}${output:+ — $output}"
		assertion_blocked=1
	fi
}

case "$S" in
	ref)
		SOURCE_MANIFEST="$REF_SOURCE_MANIFEST"
		BROWSER_URL="$REF_BROWSER_URL"
		if [ -n "${REF_WP_COMMAND:-}" ]; then
			WP_COMMAND="$REF_WP_COMMAND"
		else
			[[ "${REF_CONTAINER:-}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || block "reference WP command is unavailable."
			WP_COMMAND="docker exec -i $REF_CONTAINER wp --allow-root"
		fi
		;;
	target)
		SOURCE_MANIFEST="$TARGET_SOURCE_MANIFEST"
		BROWSER_URL="$TARGET_BROWSER_URL"
		WP_COMMAND="${TARGET_WP_COMMAND:-}"
		;;
	*) block "store role must be ref or target." ;;
esac

[[ "$RUN_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]] || block "runner invocation stamp is unavailable."
[ "$RUN_SCOPE" = "partial" ] || [ "$RUN_SCOPE" = "full" ] || block "runner scope is unavailable."
[[ "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]] || block "runner evidence context is unavailable."
[ "${CRITICAL_FLOWS_FLOW_ID:-}" = "$FLOW_ID" ] || block "exact flow context is unavailable."
[ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" = "clean-debug-log" ] || block "exact log context is unavailable."
[[ "$DELAY_SECONDS" =~ ^[0-9]+$ ]] && [ "$DELAY_SECONDS" -le 300 ] || block "delayed probe interval is invalid."
[ -n "$CONTEXT_FILE" ] && [ -f "$CONTEXT_FILE" ] && [ ! -L "$CONTEXT_FILE" ] || block "MD02_CONTEXT_FILE is unavailable."
[ -n "$SOURCE_MANIFEST" ] && [ -f "$SOURCE_MANIFEST" ] && [ ! -L "$SOURCE_MANIFEST" ] || block "the explicit MD-01 source manifest is unavailable."
[ -n "$WP_COMMAND" ] || block "local WP command is unavailable."
[ -n "$BROWSER_URL" ] || block "local browser URL is unavailable."
[ -f "$GATE" ] && [ ! -L "$GATE" ] || block "MD-02 Playwright gate is unavailable."
[ -f "$EVIDENCE_TOOL" ] && [ ! -L "$EVIDENCE_TOOL" ] || block "MD-02 evidence core is unavailable."
if [ -e "$STORE_EVIDENCE_DIR" ]; then
	block "evidence directory already exists; refusing to overwrite or replay the mutation."
fi
mkdir -p -- "$FLOW_EVIDENCE_DIR" || block "evidence archive parent is unavailable."

echo "[MD-02/$S] exercise: save one recoverable evidence draft on the exact retained dispute"
python3 "$GATE" \
	--repo "$REPO_ROOT" \
	--context-file "$CONTEXT_FILE" \
	--source-manifest "$SOURCE_MANIFEST" \
	--store "$S" \
	--wp "$WP_COMMAND" \
	--url "$BROWSER_URL" \
	--out-dir "$STORE_EVIDENCE_DIR" \
	--run-stamp "$RUN_STAMP" \
	--delay-seconds "$DELAY_SECONDS" \
	--browser-runner playwright
gate_rc=$?
[ "$gate_rc" -ne 70 ] || { echo "[MD-02/$S] cleanup-fatal: exact browser session cleanup failed." >&2; exit 70; }
case "$gate_rc" in 0|1|3) ;; *) block "Playwright gate returned an unsupported status." ;; esac
[ -f "$PACKET" ] || block "Playwright gate emitted no sealed store packet."

record_assertion assert_md02_check exact_description
record_assertion assert_md02_check customer_name_preserved
record_assertion assert_md02_check no_files_attached
record_assertion assert_md02_check not_submitted
record_assertion assert_md02_check deadline_unchanged
record_assertion assert_md02_check delayed_state_stable

LOG_SCAN_EVIDENCE_FILE="$RAW_LOG_SCAN" assert_log_clean "$S"
log_rc=$?
case "$log_rc" in 0|1|3) ;; *) log_rc=3 ;; esac
log_payload="$(python3 "$EVIDENCE_TOOL" normalize-log-scan \
	--input "$RAW_LOG_SCAN" --store "$S" --run-stamp "$RUN_STAMP" \
	--expected-exit-code "$log_rc" 2>/dev/null)"
normalized_log_rc=$?
[ "$normalized_log_rc" -eq "$log_rc" ] || block "debug-log evidence could not be normalized."
write_payload "$log_payload" "$LOG_SCAN" || block "normalized debug-log evidence could not be archived."

comparison_rc=0
if [ "$S" = "target" ]; then
	REF_PACKET="$FLOW_EVIDENCE_DIR/ref/ref-md02-store-packet.json"
	[ -f "$REF_PACKET" ] || block "reference MD-02 packet is unavailable for comparison."
	comparison_payload="$(python3 "$EVIDENCE_TOOL" compare \
		--reference "$REF_PACKET" --target "$PACKET" --run-stamp "$RUN_STAMP" 2>/dev/null)"
	comparison_rc=$?
	case "$comparison_rc" in 0|1|3) ;; *) comparison_rc=3 ;; esac
	write_payload "$comparison_payload" "$COMPARISON" || block "cross-store comparison could not be archived."
fi

read -r status verdict_rc <<< "$(md02_classify_verdict "$gate_rc" "$assertion_failed" "$assertion_blocked" "$log_rc" "$comparison_rc")"

declare -a verdict_sources=()
[ "$gate_rc" -eq 0 ] || verdict_sources+=("browser_gate_$( [ "$gate_rc" -eq 1 ] && printf fail || printf blocked )")
[ "$assertion_failed" -eq 0 ] || verdict_sources+=("deterministic_assertion_failed")
[ "$assertion_blocked" -eq 0 ] || verdict_sources+=("deterministic_assertion_blocked")
[ "$log_rc" -eq 0 ] || verdict_sources+=("log_assertion_$( [ "$log_rc" -eq 1 ] && printf fail || printf blocked )")
[ "$comparison_rc" -eq 0 ] || verdict_sources+=("comparison_$( [ "$comparison_rc" -eq 1 ] && printf fail || printf blocked )")

execution_args=(
	execution --store-packet "$PACKET" --log-scan "$LOG_SCAN"
	--status "$status" --exit-code "$verdict_rc"
)
if [ "$S" = "target" ]; then
	execution_args+=(--comparison "$COMPARISON")
fi
for source in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
	execution_args+=(--verdict-source "$source")
done
execution_payload="$(python3 "$EVIDENCE_TOOL" "${execution_args[@]}" 2>/dev/null)" || block "execution evidence could not be sealed."
write_payload "$execution_payload" "$EXECUTION" || block "execution evidence could not be archived."

manifest_args=(
	manifest --store "$S" --status "$status" --exit-code "$verdict_rc"
	--run-stamp "$RUN_STAMP" --run-scope "$RUN_SCOPE"
	--file "$PACKET" --file "$LOG_SCAN" --file "$EXECUTION"
)
if [ "$S" = "target" ]; then
	manifest_args+=(--file "$COMPARISON")
fi
for source in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
	manifest_args+=(--verdict-source "$source")
done
manifest_payload="$(python3 "$EVIDENCE_TOOL" "${manifest_args[@]}" 2>/dev/null)" || block "manifest evidence could not be sealed."
write_payload "$manifest_payload" "$MANIFEST" || block "manifest evidence could not be archived."
python3 "$EVIDENCE_TOOL" validate-bound-manifest \
	--manifest "$MANIFEST" --store "$S" --status "$status" --exit-code "$verdict_rc" \
	--run-stamp "$RUN_STAMP" --run-scope "$RUN_SCOPE" >/dev/null || block "sealed manifest failed semantic validation."

case "$verdict_rc" in
	0) echo "[MD-02/$S] verdict: PASS" ;;
	1) echo "[MD-02/$S] verdict: FAIL" ;;
	*) echo "[MD-02/$S] verdict: BLOCKED" ;;
esac
exit "$verdict_rc"
