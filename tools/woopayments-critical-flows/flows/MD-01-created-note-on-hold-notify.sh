#!/usr/bin/env bash
# MD-01 — deterministic dispute-created state and parity evidence.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:-}"
RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
FLOW_ID="MD-01-created-note-on-hold-notify"
FLOW_DRIVER="${MD01_FLOW_DRIVER:-$REPO_ROOT/tools/woopayments-merge/flow-drive.sh}"
RECONCILER="${MD01_RECONCILER:-$REPO_ROOT/tools/woopayments-merge/financial-reconcile.sh}"
STATE_DRIVER="${MD01_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowsmd01driver.php}"
EVIDENCE_TOOL="${MD01_EVIDENCE_TOOL:-$DIR/md01-evidence.py}"
MD01_WPCOM_JOBS_DRIVER="${MD01_WPCOM_JOBS_DRIVER:-$DIR/md01-wpcom-jobs.php}"
MD01_POLL_TRIES="${MD01_POLL_TRIES:-60}"
MD01_POLL_SLEEP_SECONDS="${MD01_POLL_SLEEP_SECONDS:-1}"
FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/$FLOW_ID"
REF_PROBE="$FLOW_EVIDENCE_DIR/ref-probe.json"
TARGET_PROBE="$FLOW_EVIDENCE_DIR/target-probe.json"
COMPARISON="$FLOW_EVIDENCE_DIR/comparison.json"
BASELINE="$FLOW_EVIDENCE_DIR/$S-baseline.json"
DRIVE="$FLOW_EVIDENCE_DIR/$S-drive.json"
RAW_PROBE="$FLOW_EVIDENCE_DIR/$S-raw-probe.json"
PROBE="$FLOW_EVIDENCE_DIR/$S-probe.json"
RECONCILIATION="$FLOW_EVIDENCE_DIR/$S-reconciliation.log"
RAW_LOG_SCAN="$FLOW_EVIDENCE_DIR/$S-raw-log-scan.json"
LOG_SCAN="$FLOW_EVIDENCE_DIR/$S-log-scan.json"
EXECUTION="$FLOW_EVIDENCE_DIR/$S-execution.json"
MANIFEST="$FLOW_EVIDENCE_DIR/$S-manifest.json"
RAW_WPCOM_JOBS="$FLOW_EVIDENCE_DIR/$S-raw-wpcom-jobs.json"
WEBHOOK_ORDER="$FLOW_EVIDENCE_DIR/$S-webhook-order.json"
WPCOM_JOBS_STATUS="$FLOW_EVIDENCE_DIR/$S-wpcom-jobs-status.json"
WPCOM_JOBS_MODE_BEFORE="$FLOW_EVIDENCE_DIR/$S-wpcom-jobs-mode-before.json"
WPCOM_JOBS_MODE_MANUAL="$FLOW_EVIDENCE_DIR/$S-wpcom-jobs-mode-manual.json"
WPCOM_PAYMENT_JOB_RESULT="$FLOW_EVIDENCE_DIR/$S-wpcom-payment-job-result.json"
WPCOM_DISPUTE_JOB_RESULT="$FLOW_EVIDENCE_DIR/$S-wpcom-dispute-job-result.json"
WPCOM_JOBS_MODE_RESTORED="$FLOW_EVIDENCE_DIR/$S-wpcom-jobs-mode-restored.json"
WPCOM_JOBS_MODE_RESTORE_REQUIRED=0
WPCOM_JOBS_ORIGINAL_MODE=""

block() {
	echo "[MD-01/${S:-unknown}] BLOCKED: $1"
	exit 3
}

restore_wpcom_jobs_mode_now() {
	[ "$WPCOM_JOBS_MODE_RESTORE_REQUIRED" -eq 1 ] || return 0
	[ "$WPCOM_JOBS_ORIGINAL_MODE" = "automatic" ] || return 70
	wpcom-local --json jobs mode automatic > "$WPCOM_JOBS_MODE_RESTORED" 2>&1 || return 70
	python3 - "$WPCOM_JOBS_MODE_RESTORED" <<'PY' || return 70
import json
import sys

payload = json.loads(open(sys.argv[1], encoding="utf-8").read())
context = payload.get("context", {})
valid = (
    payload.get("command") == "jobs mode"
    and payload.get("status") == "success"
    and payload.get("exit_code") == 0
    and isinstance(context, dict)
    and context.get("jobs_mode") == "automatic"
)
raise SystemExit(0 if valid else 70)
PY
	WPCOM_JOBS_MODE_RESTORE_REQUIRED=0
}

restore_md01_resources() {
	local exit_code=$? restoration_failed=0
	trap - EXIT
	if ! restore_wpcom_jobs_mode_now; then
		echo "[MD-01/${S:-unknown}] cleanup-fatal: could not restore local WPCOM automatic jobs mode." >&2
		restoration_failed=1
	fi
	critical_flows_log_observer_cleanup
	[ "$restoration_failed" -eq 0 ] || exit 70
	exit "$exit_code"
}

trap restore_md01_resources EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

write_payload() {
	local payload="$1" destination="$2"
	rm -f -- "$destination"
	[ -n "$payload" ] || return 3
	printf '%s\n' "$payload" > "$destination"
}

extract_json_object() {
	local discriminator="$1" expected="$2"
	python3 "$EVIDENCE_TOOL" extract-json-object --discriminator "$discriminator" --expected "$expected"
}

flow_evidence_path_is_safe() {
	case "$FLOW_EVIDENCE_DIR" in
		/*) ;;
		*) return 3 ;;
	esac
	case "$FLOW_EVIDENCE_DIR" in
		*/../*|*/..|*/./*|*/.) return 3 ;;
	esac
	local component="$FLOW_EVIDENCE_DIR"
	while :; do
		[ ! -L "$component" ] || return 3
		[ "$component" != "/" ] || break
		component="${component%/*}"
		[ -n "$component" ] || component="/"
	done
}

capture_baseline() {
	local raw payload
	raw="$(wp_store "$S" --user=1 eval-file - "$S" baseline "$RUN_STAMP" < "$STATE_DRIVER" 2>&1)" || return 3
	payload="$(printf '%s\n' "$raw" | extract_json_object schema woopayments_md01_baseline.v1)" || return 3
	write_payload "$payload" "$BASELINE" || return 3
	python3 "$EVIDENCE_TOOL" validate-baseline --baseline "$BASELINE" --store "$S" --run-stamp "$RUN_STAMP" >/dev/null 2>&1
}

drive_dispute() {
	local output rc payload
	local -a args
	args=(dispute --deterministic --run-token "wcpay-verify-${CRITICAL_FLOWS_RUN_CONTEXT_KEY:0:32}")
	if [ "$S" = "target" ]; then
		args+=(--native)
	fi
	export REPO_ROOT WC_DIR REF_CONTAINER TARGET_WPENV_CWD REF_WP_COMMAND TARGET_WP_COMMAND
	export -f run_wp_command_string wp_ref wp_target
	output="$(WP="wp_$S" bash "$FLOW_DRIVER" "${args[@]}" 2>&1)"
	rc=$?
	[ "$rc" -eq 0 ] || return 3
	payload="$(printf '%s\n' "$output" | extract_json_object op dispute)" || return 3
	write_payload "$payload" "$DRIVE" || return 3

	read -r ORDER_ID CHARGE_ID INTENT_ID < <(python3 - "$DRIVE" <<'PY'
import json
import re
import sys

payload = json.loads(open(sys.argv[1], encoding="utf-8").read())
op = payload.get("op")
if op != "dispute":
    raise SystemExit(3)
order_id = payload.get("order_id")
charge_id = payload.get("charge_id")
intent_id = payload.get("intent_id")
if (
    not isinstance(order_id, int)
    or isinstance(order_id, bool)
    or order_id <= 0
    or not isinstance(charge_id, str)
    or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", charge_id) is None
    or not isinstance(intent_id, str)
    or re.fullmatch(r"pi_[A-Za-z0-9_]+", intent_id) is None
    or payload.get("charge_id") != charge_id
    or payload.get("intent_id") != intent_id
):
    raise SystemExit(3)
print(order_id, charge_id, intent_id)
PY
	) || return 3
	[ -n "${ORDER_ID:-}" ] && [ -n "${CHARGE_ID:-}" ] && [ -n "${INTENT_ID:-}" ]
}

prepare_manual_wpcom_jobs() {
	wpcom-local --json jobs status > "$WPCOM_JOBS_STATUS" 2>&1 || return 3
	wpcom-local --json jobs mode > "$WPCOM_JOBS_MODE_BEFORE" 2>&1 || return 3
	read -r WPCOM_COMPOSE_FILE WPCOM_JOBS_ORIGINAL_MODE < <(python3 - "$WPCOM_JOBS_STATUS" "$WPCOM_JOBS_MODE_BEFORE" <<'PY'
import json
import pathlib
import sys

status = json.loads(open(sys.argv[1], encoding="utf-8").read())
mode = json.loads(open(sys.argv[2], encoding="utf-8").read())
status_context = status.get("context", {})
mode_context = mode.get("context", {})
compose_file = pathlib.Path(str(status_context.get("compose_file", "")))
valid = (
    status.get("command") == "jobs status"
    and status.get("status") == "success"
    and status.get("exit_code") == 0
    and mode.get("command") == "jobs mode"
    and mode.get("status") == "success"
    and mode.get("exit_code") == 0
    and mode_context.get("jobs_mode") == "automatic"
    and mode_context.get("automatic_service") == "active"
    and compose_file.is_absolute()
    and compose_file.is_file()
    and not compose_file.is_symlink()
)
if not valid:
    raise SystemExit(3)
print(compose_file, mode_context["jobs_mode"])
PY
	) || return 3
	[ "$WPCOM_JOBS_ORIGINAL_MODE" = "automatic" ] || return 3
	WPCOM_JOBS_MODE_RESTORE_REQUIRED=1
	wpcom-local --json jobs mode manual > "$WPCOM_JOBS_MODE_MANUAL" 2>&1 || return 3
	python3 - "$WPCOM_JOBS_MODE_MANUAL" <<'PY'
import json
import sys

payload = json.loads(open(sys.argv[1], encoding="utf-8").read())
context = payload.get("context", {})
valid = (
    payload.get("command") == "jobs mode"
    and payload.get("status") == "success"
    and payload.get("exit_code") == 0
    and isinstance(context, dict)
    and context.get("jobs_mode") == "manual"
)
raise SystemExit(0 if valid else 3)
PY
}

capture_exact_wpcom_jobs() {
	local raw payload
	raw="$(docker compose -f "$WPCOM_COMPOSE_FILE" exec -T wpcom-web php /dev/stdin "$INTENT_ID" "$CHARGE_ID" < "$MD01_WPCOM_JOBS_DRIVER" 2>&1)" || return 3
	payload="$(printf '%s\n' "$raw" | extract_json_object schema woopayments_md01_wpcom_jobs_raw.v1)" || return 3
	write_payload "$payload" "$RAW_WPCOM_JOBS" || return 3
	python3 - "$RAW_WPCOM_JOBS" "$INTENT_ID" "$CHARGE_ID" <<'PY'
import json
import sys

payload = json.loads(open(sys.argv[1], encoding="utf-8").read())
payment = payload.get("payment_intent", [])
dispute = payload.get("dispute_created", [])
settled = (
    payload.get("schema") == "woopayments_md01_wpcom_jobs_raw.v1"
    and isinstance(payment, list)
    and len(payment) == 1
    and isinstance(dispute, list)
    and len(dispute) == 1
    and payment[0].get("event_type") == "payment_intent.succeeded"
    and payment[0].get("object_id") == sys.argv[2]
    and payment[0].get("charge_id") == sys.argv[3]
    and dispute[0].get("event_type") == "charge.dispute.created"
    and dispute[0].get("charge_id") == sys.argv[3]
    and payment[0].get("blog_id") == dispute[0].get("blog_id")
)
raise SystemExit(0 if settled else 1)
PY
}

poll_exact_wpcom_jobs() {
	local attempt
	for (( attempt = 1; attempt <= MD01_POLL_TRIES; ++attempt )); do
		if capture_exact_wpcom_jobs; then
			return 0
		fi
		if [ "$attempt" -lt "$MD01_POLL_TRIES" ]; then
			sleep "$MD01_POLL_SLEEP_SECONDS"
		fi
	done
	return 3
}

dispatch_exact_wpcom_jobs() {
	read -r PAYMENT_JOB_ID DISPUTE_JOB_ID < <(python3 - "$RAW_WPCOM_JOBS" <<'PY'
import json
import sys

payload = json.loads(open(sys.argv[1], encoding="utf-8").read())
payment = payload.get("payment_intent", [])
dispute = payload.get("dispute_created", [])
if len(payment) != 1 or len(dispute) != 1:
    raise SystemExit(3)
payment_job_id = payment[0].get("job_id")
dispute_job_id = dispute[0].get("job_id")
if (
    not isinstance(payment_job_id, int)
    or isinstance(payment_job_id, bool)
    or payment_job_id <= 0
    or not isinstance(dispute_job_id, int)
    or isinstance(dispute_job_id, bool)
    or dispute_job_id <= 0
    or payment_job_id == dispute_job_id
):
    raise SystemExit(3)
print(payment_job_id, dispute_job_id)
PY
	) || return 3

	# Preserve the semantic lifecycle order: payment_intent.succeeded before charge.dispute.created.
	wpcom-local --json jobs run-one --id "$PAYMENT_JOB_ID" > "$WPCOM_PAYMENT_JOB_RESULT" 2>&1 || return 3
	wpcom-local --json jobs run-one --id "$DISPUTE_JOB_ID" > "$WPCOM_DISPUTE_JOB_RESULT" 2>&1 || return 3
}

seal_webhook_order() {
	local output
	output="$(python3 "$EVIDENCE_TOOL" seal-webhook-order \
		--store "$S" --run-stamp "$RUN_STAMP" --drive "$DRIVE" --jobs "$RAW_WPCOM_JOBS" \
		--original-mode "$WPCOM_JOBS_MODE_BEFORE" --payment-result "$WPCOM_PAYMENT_JOB_RESULT" \
		--dispute-result "$WPCOM_DISPUTE_JOB_RESULT" --restored-mode "$WPCOM_JOBS_MODE_RESTORED" 2>/dev/null)" || return 3
	write_payload "$output" "$WEBHOOK_ORDER"
}

capture_probe() {
	local raw payload
	raw="$(wp_store "$S" --user=1 eval-file - "$S" probe "$RUN_STAMP" "$ORDER_ID" "$CHARGE_ID" "$INTENT_ID" < "$STATE_DRIVER" 2>&1)" || return 3
	payload="$(printf '%s\n' "$raw" | extract_json_object schema woopayments_md01_probe.v1)" || return 3
	write_payload "$payload" "$RAW_PROBE" || return 3
	python3 - "$BASELINE" "$RAW_PROBE" "$ORDER_ID" "$CHARGE_ID" "$INTENT_ID" <<'PY'
import json
import sys

baseline = json.loads(open(sys.argv[1], encoding="utf-8").read())
probe = json.loads(open(sys.argv[2], encoding="utf-8").read())
order_id, charge_id, intent_id = int(sys.argv[3]), sys.argv[4], sys.argv[5]
order = probe.get("order", {})
dispute = probe.get("dispute", {})
settled = (
    not baseline.get("blockers")
    and not probe.get("blockers")
    and order.get("id") == order_id
    and order.get("charge_id") == charge_id
    and order.get("intent_id") == intent_id
    and dispute.get("found") is True
    and dispute.get("match_count") == 1
    and dispute.get("charge_id") == charge_id
    and probe.get("summary", {}).get("count") == baseline.get("summary", {}).get("count", -2) + 1
    and probe.get("status_counts", {}).get("awaiting_response")
        == baseline.get("status_counts", {}).get("awaiting_response", -2) + 1
)
raise SystemExit(0 if settled else 1)
PY
}

poll_probe() {
	local attempt
	for (( attempt = 1; attempt <= MD01_POLL_TRIES; ++attempt )); do
		if capture_probe; then
			return 0
		fi
		if [ "$attempt" -lt "$MD01_POLL_TRIES" ]; then
			sleep "$MD01_POLL_SLEEP_SECONDS"
		fi
	done
	return 1
}

run_financial_reconciliation() {
	local expected_target_runner
	if [ "$S" = "ref" ]; then
		if [ -n "$REF_WP_COMMAND" ]; then
			RECONCILE_WP="$REF_WP_COMMAND"
		else
			# Equivalent argv: docker exec -i "$REF_CONTAINER" wp --allow-root
			RECONCILE_WP="$(printf 'docker exec -i %q wp --allow-root' "$REF_CONTAINER")"
		fi
	else
		if ! [[ "${WOOPAYMENTS_APPROVED_TARGET_CONTAINER:-}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
			printf '%s\n' 'BLOCKED: financial reconciliation requires an approved local target container.' > "$RECONCILIATION"
			return 3
		fi
		expected_target_runner="docker exec -i ${WOOPAYMENTS_APPROVED_TARGET_CONTAINER} wp --allow-root"
		if [ -z "$TARGET_WP_COMMAND" ] || [ "$TARGET_WP_COMMAND" != "$expected_target_runner" ]; then
			printf '%s\n' 'BLOCKED: TARGET_WP_COMMAND does not match the approved local target container.' > "$RECONCILIATION"
			return 3
		fi
		RECONCILE_WP="$TARGET_WP_COMMAND"
	fi
	WP="$RECONCILE_WP" bash "$RECONCILER" "$ORDER_ID" > "$RECONCILIATION" 2>&1
}

normalize_probe() {
	local output rc
	output="$(python3 "$EVIDENCE_TOOL" normalize-probe \
		--store "$S" --run-stamp "$RUN_STAMP" --baseline "$BASELINE" --drive "$DRIVE" \
		--reconciliation "$RECONCILIATION" --reconciliation-exit-code "$reconciliation_rc" \
		< "$RAW_PROBE" 2>/dev/null)"
	rc=$?
	if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ] && [ "$rc" -ne 3 ]; then
		return 3
	fi
	write_payload "$output" "$PROBE" || return 3
	probe_rc="$rc"
}

compare_stores() {
	local output rc
	output="$(python3 "$EVIDENCE_TOOL" compare --reference "$REF_PROBE" --target "$TARGET_PROBE" --run-stamp "$RUN_STAMP" 2>/dev/null)"
	rc=$?
	if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ] && [ "$rc" -ne 3 ]; then
		return 3
	fi
	write_payload "$output" "$COMPARISON" || return 3
	comparison_rc="$rc"
}

normalize_log_scan() {
	local output rc
	output="$(python3 "$EVIDENCE_TOOL" normalize-log-scan \
		--input "$RAW_LOG_SCAN" --store "$S" --run-stamp "$RUN_STAMP" \
		--expected-exit-code "$log_rc" 2>/dev/null)"
	rc=$?
	if [ "$rc" -ne 0 ] && [ "$rc" -ne 1 ] && [ "$rc" -ne 3 ]; then
		rc=3
	fi
	write_payload "$output" "$LOG_SCAN" || return 3
	log_rc="$rc"
}

create_execution() {
	local output source
	local -a arguments=(
		execution --store "$S" --status "$status" --exit-code "$verdict_rc"
		--run-stamp "$RUN_STAMP" --probe "$PROBE" --probe-exit-code "$probe_rc"
		--log-assertion-exit-code "$log_rc" --log-scan "$LOG_SCAN"
		--webhook-order-exit-code 0 --webhook-order "$WEBHOOK_ORDER"
	)
	for source in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
		arguments+=(--verdict-source "$source")
	done
	if [ "$S" = "target" ]; then
		arguments+=(--comparison "$COMPARISON" --comparison-exit-code "$comparison_rc")
	fi
	output="$(python3 "$EVIDENCE_TOOL" "${arguments[@]}" 2>/dev/null)" || return 3
	write_payload "$output" "$EXECUTION"
}

create_manifest() {
	local output source file
	local -a arguments=(
		manifest --store "$S" --status "$status" --exit-code "$verdict_rc"
		--run-stamp "$RUN_STAMP" --run-scope "$RUN_SCOPE"
	)
	for source in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
		arguments+=(--verdict-source "$source")
	done
	if [ "$S" = "ref" ]; then
		arguments+=(--file "$REF_PROBE" --file "$FLOW_EVIDENCE_DIR/ref-log-scan.json" --file "$FLOW_EVIDENCE_DIR/ref-webhook-order.json" --file "$FLOW_EVIDENCE_DIR/ref-execution.json")
	else
		for file in "$REF_PROBE" "$FLOW_EVIDENCE_DIR/ref-log-scan.json" "$FLOW_EVIDENCE_DIR/ref-webhook-order.json" "$TARGET_PROBE" "$FLOW_EVIDENCE_DIR/target-log-scan.json" "$FLOW_EVIDENCE_DIR/target-webhook-order.json" "$COMPARISON" "$FLOW_EVIDENCE_DIR/target-execution.json"; do
			arguments+=(--file "$file")
		done
	fi
	output="$(python3 "$EVIDENCE_TOOL" "${arguments[@]}" 2>/dev/null)" || return 3
	write_payload "$output" "$MANIFEST"
}

if [ "$S" != "ref" ] && [ "$S" != "target" ]; then
	block "store role must be ref or target."
fi
[[ "$RUN_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]] || block "runner invocation stamp is unavailable."
[ "$RUN_SCOPE" = "partial" ] || [ "$RUN_SCOPE" = "full" ] || block "runner scope is unavailable."
[[ "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]] || block "runner evidence context is unavailable."
[ "${CRITICAL_FLOWS_FLOW_ID:-}" = "$FLOW_ID" ] || block "exact flow context is unavailable."
[ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" = "clean-debug-log" ] || block "exact log context is unavailable."
[[ "$MD01_POLL_TRIES" =~ ^[1-9][0-9]*$ ]] || block "poll bound is invalid."
[[ "$MD01_POLL_SLEEP_SECONDS" =~ ^[0-9]+([.][0-9]+)?$ ]] || block "poll interval is invalid."
for dependency in "$FLOW_DRIVER" "$RECONCILER" "$STATE_DRIVER" "$EVIDENCE_TOOL" "$MD01_WPCOM_JOBS_DRIVER"; do
	[ -f "$dependency" ] || block "deterministic dependency is missing: $dependency"
done
flow_evidence_path_is_safe || block "evidence archive path is unsafe."
mkdir -p -- "$FLOW_EVIDENCE_DIR" || block "evidence archive directory is unavailable."
flow_evidence_path_is_safe || block "evidence archive directory is unsafe."

if [ "$S" = "ref" ]; then
	rm -f -- "$FLOW_EVIDENCE_DIR"/{ref,target}-{baseline,drive,raw-probe,probe,reconciliation.log,raw-log-scan.json,log-scan.json,execution.json,manifest.json} "$COMPARISON"
	rm -f -- "$FLOW_EVIDENCE_DIR"/{ref,target}-{raw-wpcom-jobs,webhook-order,wpcom-jobs-status,wpcom-jobs-mode-before,wpcom-jobs-mode-manual,wpcom-payment-job-result,wpcom-dispute-job-result,wpcom-jobs-mode-restored}.json
else
	rm -f -- "$FLOW_EVIDENCE_DIR"/target-{baseline,drive,raw-probe,probe,reconciliation.log,raw-log-scan.json,log-scan.json,execution.json,manifest.json} "$COMPARISON"
	rm -f -- "$FLOW_EVIDENCE_DIR"/target-{raw-wpcom-jobs,webhook-order,wpcom-jobs-status,wpcom-jobs-mode-before,wpcom-jobs-mode-manual,wpcom-payment-job-result,wpcom-dispute-job-result,wpcom-jobs-mode-restored}.json
fi

echo "[MD-01/$S] exercise: create one provider-backed dispute and verify its exact order"
capture_baseline || block "aggregate baseline could not be captured."
prepare_manual_wpcom_jobs || block "local WPCOM automatic jobs could not be paused safely."
drive_dispute || block "deterministic dispute driver emitted no valid exact fixture."
poll_exact_wpcom_jobs || block "exact provider-originated WPCOM forwarding jobs did not settle."
dispatch_exact_wpcom_jobs || block "exact WPCOM forwarding jobs did not complete in semantic order."
if ! restore_wpcom_jobs_mode_now; then
	echo "[MD-01/$S] cleanup-fatal: could not restore local WPCOM automatic jobs mode." >&2
	exit 70
fi
seal_webhook_order || block "exact WPCOM webhook-order evidence could not be sealed."
poll_probe || echo "[MD-01/$S] financial reconciliation will classify the unsettled end state."
run_financial_reconciliation
reconciliation_rc=$?
if [ "$reconciliation_rc" -ne 0 ] && [ "$reconciliation_rc" -ne 1 ] && [ "$reconciliation_rc" -ne 3 ]; then
	reconciliation_rc=3
fi
normalize_probe || block "state and financial reconciliation evidence could not be normalized."

comparison_rc=0
if [ "$S" = "target" ]; then
	compare_stores || comparison_rc=3
fi

rm -f -- "$RAW_LOG_SCAN" "$LOG_SCAN"
LOG_SCAN_EVIDENCE_FILE="$RAW_LOG_SCAN" assert_log_clean "$S"
log_rc=$?
if [ "$log_rc" -ne 0 ] && [ "$log_rc" -ne 1 ] && [ "$log_rc" -ne 3 ]; then
	log_rc=3
fi
normalize_log_scan || block "debug-log evidence could not be normalized."

declare -a verdict_sources=()
if [ "$probe_rc" -eq 1 ] || [ "$comparison_rc" -eq 1 ] || [ "$log_rc" -eq 1 ]; then
	status="fail"
	verdict_rc=1
	[ "$probe_rc" -eq 1 ] && verdict_sources+=("probe_failed")
	[ "$S" = "target" ] && [ "$comparison_rc" -eq 1 ] && verdict_sources+=("comparison_failed")
	[ "$log_rc" -eq 1 ] && verdict_sources+=("log_assertion_failed")
elif [ "$probe_rc" -ne 0 ] || [ "$comparison_rc" -ne 0 ] || [ "$log_rc" -ne 0 ]; then
	status="blocked"
	verdict_rc=3
	[ "$probe_rc" -ne 0 ] && verdict_sources+=("probe_blocked")
	[ "$S" = "target" ] && [ "$comparison_rc" -ne 0 ] && verdict_sources+=("comparison_blocked")
	[ "$log_rc" -ne 0 ] && verdict_sources+=("log_assertion_blocked")
else
	status="pass"
	verdict_rc=0
fi

create_execution || block "woopayments_md01_execution.v1 could not be created."
create_manifest || block "context-bound manifest could not be created."
echo "[MD-01/$S] evidence boundary: Layer D does not prove the dispute-list or order-detail browser affordance."
if [ "$verdict_rc" -eq 1 ]; then
	echo "[MD-01/$S] deterministic verdict: FAIL"
	exit 1
fi
if [ "$verdict_rc" -ne 0 ]; then
	echo "[MD-01/$S] deterministic verdict: BLOCKED"
	exit 3
fi
echo "[MD-01/$S] deterministic verdict: PASS"
exit 0
