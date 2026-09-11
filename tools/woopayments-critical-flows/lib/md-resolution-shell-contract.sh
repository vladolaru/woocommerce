#!/usr/bin/env bash
# Shared, non-replayable MD-03/MD-04 resolution evidence contract.

md_resolution_classify_verdict() { # <gate-rc> <log-rc> <comparison-rc>
	local gate_rc="$1" log_rc="$2" comparison_rc="$3"
	if [ "$gate_rc" -eq 70 ]; then
		printf 'cleanup 70\n'
	elif [ "$gate_rc" -eq 1 ] || [ "$log_rc" -eq 1 ] || [ "$comparison_rc" -eq 1 ]; then
		printf 'fail 1\n'
	elif [ "$gate_rc" -eq 3 ] || [ "$log_rc" -eq 3 ] || [ "$comparison_rc" -eq 3 ]; then
		printf 'blocked 3\n'
	else
		printf 'pass 0\n'
	fi
}

md_resolution_wpcom_preflight() {
	local transact_status
	command -v wpcom-local >/dev/null 2>&1 || return 3
	transact_status="$(wpcom-local --json transact status 2>/dev/null)" || return 3
	printf '%s' "$transact_status" | python3 -c '
import json
import sys

try:
    payload = json.load(sys.stdin)
    context = payload["context"]
except (KeyError, TypeError, ValueError, json.JSONDecodeError):
    raise SystemExit(3)
ready = (
    payload.get("status") == "success"
    and context.get("readiness_status") == "ready"
    and context.get("async_jobs_ready") == "true"
    and context.get("async_jobs_mode") == "automatic"
    and context.get("async_jobs_service_state") == "active"
    and context.get("async_jobs_would_mutate") == "false"
    and context.get("stripe_webhook_secrets_ready") == "true"
)
raise SystemExit(0 if ready else 3)
' || return 3

	ps -axo command= | python3 -c '
import sys
from urllib.parse import urlparse

listeners = []
for raw in sys.stdin:
    command = raw.strip()
    if command.startswith("stripe listen "):
        listeners.append(command)
if len(listeners) != 1:
    raise SystemExit(3)
tokens = listeners[0].split()
urls = [token for token in tokens if token.startswith(("http://", "https://"))]
if len(urls) != 2:
    raise SystemExit(3)
for value in urls:
    if (urlparse(value).hostname or "").lower() not in {"wpcom.localhost", "localhost"}:
        raise SystemExit(3)
' || return 3
}

md_resolution_run() {
	local S RUN_STAMP RUN_SCOPE FLOW_ID OUTCOME RUNTIME_OWNER
	local GATE EVIDENCE_TOOL STORE_ADAPTER FLOW_DRIVE LOCAL_RUNNER_SAFETY STRIPE_COMMAND
	local FLOW_EVIDENCE_DIR STORE_EVIDENCE_DIR PACKET REF_PACKET REF_MANIFEST TEMP_EVIDENCE_DIR RAW_LOG_SCAN LOG_SCAN COMPARISON EXECUTION MANIFEST
	local WP_COMMAND gate_rc log_rc normalized_log_rc comparison_rc execution_rc status verdict_rc OBSERVER_CLEANED
	local log_payload comparison_payload execution_payload manifest_payload runner_error
	local -a execution_args manifest_args

	S="${STORE_NAME:-}"
	RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
	RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
	FLOW_ID="${MD_RESOLUTION_FLOW_ID:-}"
	OUTCOME="${MD_RESOLUTION_OUTCOME:-}"
	GATE="${MD_RESOLUTION_GATE:-$REPO_ROOT/tools/woopayments-critical-flows/flows/md-resolution-gate.py}"
	EVIDENCE_TOOL="${MD_RESOLUTION_EVIDENCE_TOOL:-$REPO_ROOT/tools/woopayments-critical-flows/flows/md-resolution-evidence.py}"
	STORE_ADAPTER="${MD_RESOLUTION_STORE_ADAPTER:-$REPO_ROOT/tools/woopayments-critical-flows/flows/class-woopayments-critical-flows-md-resolution-store.php}"
	FLOW_DRIVE="${MD_RESOLUTION_FLOW_DRIVE:-$REPO_ROOT/tools/woopayments-merge/flow-drive.sh}"
	LOCAL_RUNNER_SAFETY="$REPO_ROOT/tools/woopayments-merge/local-runner-safety.sh"
	STRIPE_COMMAND="${MD_RESOLUTION_STRIPE_COMMAND:-stripe}"
	FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/$FLOW_ID"
	STORE_EVIDENCE_DIR="$FLOW_EVIDENCE_DIR/$S"
	PACKET="$STORE_EVIDENCE_DIR/$S-store-packet.json"
	REF_PACKET="$FLOW_EVIDENCE_DIR/ref/ref-store-packet.json"
	REF_MANIFEST="$FLOW_EVIDENCE_DIR/ref/ref-manifest.json"
	TEMP_EVIDENCE_DIR=""
	RAW_LOG_SCAN=""
	LOG_SCAN="$STORE_EVIDENCE_DIR/$S-log-scan.json"
	COMPARISON="$STORE_EVIDENCE_DIR/comparison.json"
	EXECUTION="$STORE_EVIDENCE_DIR/$S-execution.json"
	MANIFEST="$STORE_EVIDENCE_DIR/$S-manifest.json"
	OBSERVER_CLEANED=0

	md_resolution_block() {
		echo "[$FLOW_ID/${S:-unknown}] BLOCKED: $1"
		exit 3
	}

	md_resolution_remove_temp_evidence() {
		local cleanup_status=0
		if [ -n "${RAW_LOG_SCAN:-}" ] && [ -e "$RAW_LOG_SCAN" ]; then
			rm -f -- "$RAW_LOG_SCAN" || cleanup_status=1
		fi
		if [ -n "${TEMP_EVIDENCE_DIR:-}" ] && [ -d "$TEMP_EVIDENCE_DIR" ]; then
			rmdir -- "$TEMP_EVIDENCE_DIR" || cleanup_status=1
		fi
		if [ "$cleanup_status" -eq 0 ]; then
			RAW_LOG_SCAN=""
			TEMP_EVIDENCE_DIR=""
		fi
		return "$cleanup_status"
	}

	# shellcheck disable=SC2329 # Invoked indirectly by the EXIT trap below.
	md_resolution_cleanup() {
		local exit_code=$? cleanup_status=0
		trap - EXIT
		if [ "${OBSERVER_CLEANED:-0}" -ne 1 ] && ! critical_flows_log_observer_cleanup; then
			echo "[${FLOW_ID:-unknown}/${S:-unknown}] cleanup-fatal: log observer cleanup failed." >&2
			cleanup_status=1
		fi
		if ! md_resolution_remove_temp_evidence; then
			echo "[${FLOW_ID:-unknown}/${S:-unknown}] cleanup-fatal: temporary evidence cleanup failed." >&2
			cleanup_status=1
		fi
		[ "$cleanup_status" -eq 0 ] || exit 70
		exit "$exit_code"
	}

	md_resolution_write_payload() {
		local payload="$1" destination="$2"
		[ -n "$payload" ] || return 3
		[ ! -e "$destination" ] || return 3
		printf '%s\n' "$payload" > "$destination"
	}

	md_resolution_finalize_observer() {
		if ! critical_flows_log_observer_cleanup; then
			echo "[$FLOW_ID/${S:-unknown}] cleanup-fatal: log observer cleanup failed." >&2
			exit 70
		fi
		OBSERVER_CLEANED=1
		trap - EXIT
	}

	trap md_resolution_cleanup EXIT
	trap 'exit 129' HUP
	trap 'exit 130' INT
	trap 'exit 143' TERM

	case "$OUTCOME:$FLOW_ID" in
		won:MD-03-winning-dispute) ;;
		lost:MD-04-losing-dispute) ;;
		*) md_resolution_block "the outcome/flow profile is invalid." ;;
	esac
	case "$S" in
		ref)
			RUNTIME_OWNER="plugin"
			if [ -n "${REF_WP_COMMAND:-}" ]; then
				WP_COMMAND="$REF_WP_COMMAND"
			else
				[[ "${REF_CONTAINER:-}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || md_resolution_block "reference WP command is unavailable."
				WP_COMMAND="docker exec -i $REF_CONTAINER wp --allow-root"
			fi
			;;
		target)
			RUNTIME_OWNER="native"
			if [[ "${APPROVED_TARGET_CONTAINER:-}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
				WP_COMMAND="docker exec -i $APPROVED_TARGET_CONTAINER wp --allow-root"
			else
				WP_COMMAND="${TARGET_WP_COMMAND:-}"
			fi
			;;
		*) md_resolution_block "store role must be ref or target." ;;
	esac

	[[ "$RUN_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]] || md_resolution_block "runner invocation stamp is unavailable."
	[ "$RUN_SCOPE" = "partial" ] || [ "$RUN_SCOPE" = "full" ] || md_resolution_block "runner scope is unavailable."
	[[ "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]] || md_resolution_block "runner evidence context is unavailable."
	[ "${CRITICAL_FLOWS_FLOW_ID:-}" = "$FLOW_ID" ] || md_resolution_block "exact flow context is unavailable."
	[ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" = "clean-debug-log" ] || md_resolution_block "exact log context is unavailable."
	[ -f "$LOCAL_RUNNER_SAFETY" ] && [ ! -L "$LOCAL_RUNNER_SAFETY" ] || md_resolution_block "local runner safety library is unavailable."
	[ -f "$GATE" ] && [ ! -L "$GATE" ] || md_resolution_block "resolution gate is unavailable."
	[ -f "$EVIDENCE_TOOL" ] && [ ! -L "$EVIDENCE_TOOL" ] || md_resolution_block "resolution evidence core is unavailable."
	[ -f "$STORE_ADAPTER" ] && [ ! -L "$STORE_ADAPTER" ] || md_resolution_block "resolution store adapter is unavailable."
	[ -f "$FLOW_DRIVE" ] && [ ! -L "$FLOW_DRIVE" ] || md_resolution_block "deterministic flow driver is unavailable."
	[ -n "$WP_COMMAND" ] || md_resolution_block "local WP command is unavailable."
	if [ -e "$STORE_EVIDENCE_DIR" ]; then
		md_resolution_block "evidence directory already exists; refusing to overwrite or replay the mutation."
	fi
	if [ "$S" = "target" ]; then
		[ -f "$REF_MANIFEST" ] && [ ! -L "$REF_MANIFEST" ] || md_resolution_block "passing same-run reference resolution manifest is unavailable."
		python3 "$EVIDENCE_TOOL" validate-bound-manifest \
			--manifest "$REF_MANIFEST" --outcome "$OUTCOME" --store ref \
			--run-stamp "$RUN_STAMP" --run-scope "$RUN_SCOPE" \
			--status pass --exit-code 0 >/dev/null 2>&1 || md_resolution_block "passing same-run reference resolution manifest is unavailable."
	fi

	# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
	source "$LOCAL_RUNNER_SAFETY"
	if ! runner_error="$(woopayments_validate_local_wp_runner "$WP_COMMAND")"; then
		md_resolution_block "the WP runner is unsafe: $runner_error"
	fi
	if ! runner_error="$(woopayments_validate_approved_docker_runner "$WP_COMMAND" "$S")"; then
		md_resolution_block "the WP runner is not the approved store container: $runner_error"
	fi
	md_resolution_wpcom_preflight || md_resolution_block "local WPCOM Transact jobs/listener readiness is unavailable."
	mkdir -p -- "$FLOW_EVIDENCE_DIR" || md_resolution_block "evidence archive parent is unavailable."

	echo "[$FLOW_ID/$S] exercise: submit one exact $OUTCOME dispute marker and observe terminal delivery"
	python3 "$GATE" \
		--outcome "$OUTCOME" \
		--store "$S" \
		--runtime-owner "$RUNTIME_OWNER" \
		--run-stamp "$RUN_STAMP" \
		--out-dir "$STORE_EVIDENCE_DIR" \
		--wp-command "$WP_COMMAND" \
		--stripe-command "$STRIPE_COMMAND" \
		--flow-drive "$FLOW_DRIVE" \
		--store-adapter "$STORE_ADAPTER" \
		--poll-tries "${MD_RESOLUTION_POLL_TRIES:-120}" \
		--poll-delay "${MD_RESOLUTION_POLL_DELAY:-1}"
	gate_rc=$?
	[ "$gate_rc" -ne 70 ] || { echo "[$FLOW_ID/$S] cleanup-fatal: resolution gate cleanup failed." >&2; exit 70; }
	case "$gate_rc" in 0|1|3) ;; *) md_resolution_block "resolution gate returned an unsupported status." ;; esac
	[ -f "$PACKET" ] || md_resolution_block "resolution gate emitted no sealed store packet."

	[ -n "${TMPDIR:-}" ] || md_resolution_block "temporary evidence root is unavailable."
	mkdir -p -- "$TMPDIR" || md_resolution_block "temporary evidence root could not be prepared."
	TEMP_EVIDENCE_DIR="$(mktemp -d "${TMPDIR%/}/woopayments-md-resolution.XXXXXX")" || md_resolution_block "temporary evidence directory could not be created."
	RAW_LOG_SCAN="$TEMP_EVIDENCE_DIR/$S-raw-log-scan.json"
	LOG_SCAN_EVIDENCE_FILE="$RAW_LOG_SCAN" assert_log_clean "$S"
	log_rc=$?
	if [ "$log_rc" -eq 70 ]; then
		echo "[$FLOW_ID/$S] cleanup-fatal: log observer finish cleanup failed." >&2
		exit 70
	fi
	case "$log_rc" in 0|1|3) ;; *) log_rc=3 ;; esac
	log_payload="$(python3 "$EVIDENCE_TOOL" normalize-log-scan \
		--input "$RAW_LOG_SCAN" --outcome "$OUTCOME" --store "$S" --run-stamp "$RUN_STAMP" \
		--expected-exit-code "$log_rc" 2>/dev/null)"
	normalized_log_rc=$?
	md_resolution_remove_temp_evidence || md_resolution_block "temporary debug-log evidence could not be removed."
	[ "$normalized_log_rc" -eq "$log_rc" ] || md_resolution_block "debug-log evidence could not be normalized."
	md_resolution_write_payload "$log_payload" "$LOG_SCAN" || md_resolution_block "normalized debug-log evidence could not be archived."
	md_resolution_finalize_observer

	comparison_rc=0
	if [ "$S" = "target" ]; then
		comparison_payload="$(python3 "$EVIDENCE_TOOL" compare \
			--reference "$REF_PACKET" --target "$PACKET" --outcome "$OUTCOME" --run-stamp "$RUN_STAMP" 2>/dev/null)"
		comparison_rc=$?
		case "$comparison_rc" in 0|1|3) ;; *) comparison_rc=3 ;; esac
		md_resolution_write_payload "$comparison_payload" "$COMPARISON" || md_resolution_block "cross-store comparison could not be archived."
	fi

	execution_args=(
		execution --packet "$PACKET" --log-scan "$LOG_SCAN"
		--outcome "$OUTCOME" --store "$S" --run-stamp "$RUN_STAMP"
	)
	if [ "$S" = "target" ]; then
		execution_args+=(--comparison "$COMPARISON")
	fi
	execution_payload="$(python3 "$EVIDENCE_TOOL" "${execution_args[@]}" 2>/dev/null)"
	execution_rc=$?
	case "$execution_rc" in 0|1|3) ;; *) md_resolution_block "execution evidence could not be sealed." ;; esac
	md_resolution_write_payload "$execution_payload" "$EXECUTION" || md_resolution_block "execution evidence could not be archived."

	read -r status verdict_rc <<< "$(md_resolution_classify_verdict "$gate_rc" "$log_rc" "$comparison_rc")"
	[ "$status" != "cleanup" ] || exit 70
	[ "$execution_rc" -eq "$verdict_rc" ] || md_resolution_block "execution verdict contradicts its component evidence."
	manifest_args=(
		manifest --outcome "$OUTCOME" --store "$S" --run-stamp "$RUN_STAMP"
		--run-scope "$RUN_SCOPE" --status "$status" --exit-code "$verdict_rc"
		--file "$PACKET" --file "$LOG_SCAN" --file "$EXECUTION"
	)
	if [ "$S" = "target" ]; then
		manifest_args+=(--file "$COMPARISON")
	fi
	manifest_payload="$(python3 "$EVIDENCE_TOOL" "${manifest_args[@]}" 2>/dev/null)" || md_resolution_block "manifest evidence could not be sealed."
	md_resolution_write_payload "$manifest_payload" "$MANIFEST" || md_resolution_block "manifest evidence could not be archived."
	python3 "$EVIDENCE_TOOL" validate-bound-manifest \
		--manifest "$MANIFEST" --outcome "$OUTCOME" --store "$S" \
		--run-stamp "$RUN_STAMP" --run-scope "$RUN_SCOPE" \
		--status "$status" --exit-code "$verdict_rc" >/dev/null || md_resolution_block "sealed manifest failed semantic validation."

	case "$verdict_rc" in
		0) echo "[$FLOW_ID/$S] verdict: PASS" ;;
		1) echo "[$FLOW_ID/$S] verdict: FAIL" ;;
		*) echo "[$FLOW_ID/$S] verdict: BLOCKED" ;;
	esac
	exit "$verdict_rc"
}
