#!/usr/bin/env bash
# SC-02 — deterministic guest Cart-Token checkout parity exercise.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:?run via run.sh}"
FLOW_ID="SC-02-blocks-card-checkout"
if [ "${CRITICAL_FLOWS_FLOW_ID:-}" != "$FLOW_ID" ] \
  || [ "${CRITICAL_FLOWS_LOG_PURPOSE:-}" != "clean-debug-log" ]; then
  echo "[SC-02/$S] BLOCKED: exact log evidence context is missing."
  exit 3
fi

RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
RUN_TOKEN="sc02-$RUN_STAMP-$S"
HTTP_DRIVER="${SC02_HTTP_DRIVER:-$DIR/sc02-store-api.py}"
STATE_DRIVER="${SC02_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowssc02driver.php}"
EVIDENCE_TOOL="${SC02_EVIDENCE_TOOL:-$DIR/sc02-evidence.py}"
SKU="${SC02_SKU:-test-lab-beaker-001}"
HTTP_TIMEOUT="${SC02_HTTP_TIMEOUT:-30}"

if [ -n "${SC02_BASE_URL:-}" ]; then
  BASE_URL="$SC02_BASE_URL"
elif [ "$S" = "ref" ]; then
  BASE_URL="${REF_URL:-}"
else
  BASE_URL="${TARGET_URL:-}"
fi

FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/$FLOW_ID"
REF_PREFLIGHT="$FLOW_EVIDENCE_DIR/ref-preflight.json"
REF_HTTP="$FLOW_EVIDENCE_DIR/ref-http.json"
REF_POST="$FLOW_EVIDENCE_DIR/ref-post.json"
REF_RESULT="$FLOW_EVIDENCE_DIR/ref-result.json"
REF_LOG_SCAN="$FLOW_EVIDENCE_DIR/ref-log-scan.json"
REF_EXECUTION="$FLOW_EVIDENCE_DIR/ref-execution.json"
TARGET_PREFLIGHT="$FLOW_EVIDENCE_DIR/target-preflight.json"
TARGET_HTTP="$FLOW_EVIDENCE_DIR/target-http.json"
TARGET_POST="$FLOW_EVIDENCE_DIR/target-post.json"
TARGET_RESULT="$FLOW_EVIDENCE_DIR/target-result.json"
TARGET_LOG_SCAN="$FLOW_EVIDENCE_DIR/target-log-scan.json"
TARGET_EXECUTION="$FLOW_EVIDENCE_DIR/target-execution.json"
COMPARISON="$FLOW_EVIDENCE_DIR/comparison.json"
PREFLIGHT="$FLOW_EVIDENCE_DIR/$S-preflight.json"
HTTP="$FLOW_EVIDENCE_DIR/$S-http.json"
POST="$FLOW_EVIDENCE_DIR/$S-post.json"
RESULT="$FLOW_EVIDENCE_DIR/$S-result.json"
LOG_SCAN="$FLOW_EVIDENCE_DIR/$S-log-scan.json"
RAW_LOG_SCAN="$FLOW_EVIDENCE_DIR/$S-log-scan.raw.json"
EXECUTION="$FLOW_EVIDENCE_DIR/$S-execution.json"
MANIFEST="$FLOW_EVIDENCE_DIR/$S-manifest.json"

PREFLIGHT_EXIT_CODE=""
HTTP_EXIT_CODE=""
POST_EXIT_CODE=""
RESULT_EXIT_CODE=""
COMPARISON_EXIT_CODE=""
LOG_EXIT_CODE=""
STAGE="preflight"
VERDICT_SOURCES=()

add_verdict_source() {
  local candidate="$1" existing
  for existing in ${VERDICT_SOURCES[@]+"${VERDICT_SOURCES[@]}"}; do
    [ "$existing" = "$candidate" ] && return 0
  done
  VERDICT_SOURCES+=("$candidate")
}

status_for_exit() {
  case "$1" in
    0) printf '%s\n' "pass" ;;
    1) printf '%s\n' "fail" ;;
    3) printf '%s\n' "blocked" ;;
    *) return 3 ;;
  esac
}

write_payload() {
  local payload="$1" destination="$2"
  PAYLOAD="$payload" DESTINATION="$destination" python3 <<'PY'
import os
import tempfile
from pathlib import Path

destination = Path(os.environ["DESTINATION"])
descriptor, temporary = tempfile.mkstemp(
    prefix=f".{destination.name}.", suffix=".tmp", dir=destination.parent
)
try:
    with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
        handle.write(os.environ["PAYLOAD"])
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    os.replace(temporary, destination)
except BaseException:
    try:
        os.unlink(temporary)
    except OSError:
        pass
    raise
PY
}

state_source() {
  local mode="$1" product_id="${2:-}" order_id="${3:-}"
  tail -n +2 "$STATE_DRIVER"
  if [ "$mode" = "preflight" ]; then
    printf "\nWooPaymentsCriticalFlowsSc02Driver::run( array( 'preflight', '%s', '%s' ) );\n" \
      "$S" "$RUN_STAMP"
  else
    printf "\nWooPaymentsCriticalFlowsSc02Driver::run( array( 'post', '%s', '%s', '%s', '%s', '%s' ) );\n" \
      "$S" "$RUN_STAMP" "$RUN_TOKEN" "$product_id" "$order_id"
  fi
}

capture_state() {
  local phase="$1" destination="$2" product_id="${3:-}" order_id="${4:-}"
  local source raw normalized normalize_rc
  source="$(state_source "$phase" "$product_id" "$order_id")"
  raw="$(critical_flows_wp_eval_with_context "$S" "$source" 2>&1)"
  normalized="$(printf '%s\n' "$raw" | python3 "$EVIDENCE_TOOL" normalize-state \
    --phase "$phase" --store "$S" --run-stamp "$RUN_STAMP" 2>/dev/null)"
  normalize_rc=$?
  if [ -z "$normalized" ]; then
    rm -f "$destination"
    return 3
  fi
  write_payload "$normalized" "$destination" || return 3
  case "$normalize_rc" in
    0|1|3) return "$normalize_rc" ;;
    *) return 3 ;;
  esac
}

json_positive_int() {
  local path="$1" expression="$2"
  python3 - "$path" "$expression" <<'PY'
import json
import sys
from pathlib import Path

try:
    value = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    for field in sys.argv[2].split("."):
        value = value[field]
except (KeyError, OSError, TypeError, UnicodeError, ValueError):
    raise SystemExit(3)
if not isinstance(value, int) or isinstance(value, bool) or value <= 0:
    raise SystemExit(3)
print(value)
PY
}

json_stage() {
  local path="$1"
  python3 - "$path" <<'PY'
import json
import sys
from pathlib import Path

try:
    payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
    stage = payload["stage"]
except (KeyError, OSError, TypeError, UnicodeError, ValueError):
    raise SystemExit(3)
if stage not in {"preflight", "http", "post"}:
    raise SystemExit(3)
print(stage)
PY
}

evaluate_store() {
  local -a arguments
  arguments=(
    evaluate-store
    --preflight "$PREFLIGHT"
    --output "$RESULT"
    --store "$S"
    --run-stamp "$RUN_STAMP"
  )
  [ -f "$HTTP" ] && arguments+=(--http "$HTTP")
  [ -f "$POST" ] && arguments+=(--post "$POST")
  rm -f "$RESULT"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
}

compare_stores() {
  rm -f "$COMPARISON"
  python3 "$EVIDENCE_TOOL" compare \
    --reference-result "$REF_RESULT" \
    --target-result "$TARGET_RESULT" \
    --reference-log "$REF_LOG_SCAN" \
    --target-log "$TARGET_LOG_SCAN" \
    --output "$COMPARISON" \
    --run-stamp "$RUN_STAMP"
}

create_execution() {
  local final_rc="$1" status execution_rc source
  local -a arguments
  status="$(status_for_exit "$final_rc")" || return 3
  arguments=(
    execution
    --store "$S"
    --status "$status"
    --exit-code "$final_rc"
    --stage "$STAGE"
    --run-stamp "$RUN_STAMP"
    --output "$EXECUTION"
    --preflight-exit-code "$PREFLIGHT_EXIT_CODE"
    --result-exit-code "$RESULT_EXIT_CODE"
    --log-exit-code "$LOG_EXIT_CODE"
    --result "$RESULT"
    --log-scan "$LOG_SCAN"
  )
  [ -n "$HTTP_EXIT_CODE" ] && arguments+=(--http-exit-code "$HTTP_EXIT_CODE")
  [ -n "$POST_EXIT_CODE" ] && arguments+=(--post-exit-code "$POST_EXIT_CODE")
  if [ -n "$COMPARISON_EXIT_CODE" ]; then
    arguments+=(--comparison-exit-code "$COMPARISON_EXIT_CODE" --comparison "$COMPARISON")
  fi
  for source in ${VERDICT_SOURCES[@]+"${VERDICT_SOURCES[@]}"}; do
    arguments+=(--verdict-source "$source")
  done

  rm -f "$EXECUTION"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
  execution_rc=$?
  [ "$execution_rc" -eq "$final_rc" ] && [ -f "$EXECUTION" ]
}

append_store_packet() {
  local role="$1" packet_stage="$2"
  MANIFEST_ARGUMENTS+=(
    --file "$FLOW_EVIDENCE_DIR/$role-preflight.json"
    --file "$FLOW_EVIDENCE_DIR/$role-result.json"
    --file "$FLOW_EVIDENCE_DIR/$role-log-scan.json"
    --file "$FLOW_EVIDENCE_DIR/$role-execution.json"
  )
  if [ "$packet_stage" = "http" ] || [ "$packet_stage" = "post" ]; then
    MANIFEST_ARGUMENTS+=(--file "$FLOW_EVIDENCE_DIR/$role-http.json")
  fi
  if [ "$packet_stage" = "post" ]; then
    MANIFEST_ARGUMENTS+=(--file "$FLOW_EVIDENCE_DIR/$role-post.json")
  fi
}

create_manifest() {
  local final_rc="$1" status manifest_rc source comparison_bound="false" reference_stage
  status="$(status_for_exit "$final_rc")" || return 3
  MANIFEST_ARGUMENTS=(
    manifest
    --store "$S"
    --status "$status"
    --exit-code "$final_rc"
    --stage "$STAGE"
    --run-stamp "$RUN_STAMP"
    --run-scope "$RUN_SCOPE"
    --output "$MANIFEST"
  )
  for source in ${VERDICT_SOURCES[@]+"${VERDICT_SOURCES[@]}"}; do
    MANIFEST_ARGUMENTS+=(--verdict-source "$source")
    case "$source" in
      comparison_failed|comparison_blocked) comparison_bound="true" ;;
    esac
  done
  append_store_packet "$S" "$STAGE"
  if [ "$S" = "target" ] && { [ "$status" = "pass" ] || [ "$comparison_bound" = "true" ]; }; then
    reference_stage="$(json_stage "$REF_EXECUTION")" || return 3
    append_store_packet "ref" "$reference_stage"
    MANIFEST_ARGUMENTS+=(--file "$COMPARISON")
  fi

  rm -f "$MANIFEST"
  python3 "$EVIDENCE_TOOL" "${MANIFEST_ARGUMENTS[@]}"
  manifest_rc=$?
  [ "$manifest_rc" -eq "$final_rc" ] && [ -f "$MANIFEST" ]
}

finish_with_logs() {
  local final_rc observed_log_rc normalized_log_rc

  rm -f "$RAW_LOG_SCAN" "$LOG_SCAN"
  LOG_SCAN_EVIDENCE_FILE="$RAW_LOG_SCAN" assert_log_clean "$S"
  observed_log_rc=$?
  python3 "$EVIDENCE_TOOL" normalize-log-scan \
    --input "$RAW_LOG_SCAN" --output "$LOG_SCAN" --store "$S" \
    --run-stamp "$RUN_STAMP" --expected-exit-code "$observed_log_rc" \
    --flow-id "$FLOW_ID" --purpose "clean-debug-log"
  normalized_log_rc=$?
  rm -f "$RAW_LOG_SCAN"
  case "$normalized_log_rc" in
    0|1|3) LOG_EXIT_CODE="$normalized_log_rc" ;;
    *) LOG_EXIT_CODE=3 ;;
  esac

  if [ "$S" = "target" ] && [ "$RESULT_EXIT_CODE" -eq 0 ]; then
    compare_stores
    COMPARISON_EXIT_CODE=$?
    case "$COMPARISON_EXIT_CODE" in
      0|1|3) ;;
      *) COMPARISON_EXIT_CODE=3 ;;
    esac
  fi

  final_rc="$RESULT_EXIT_CODE"
  if [ -n "$COMPARISON_EXIT_CODE" ]; then
    if [ "$COMPARISON_EXIT_CODE" -eq 3 ]; then
      final_rc=3
      add_verdict_source "comparison_blocked"
    elif [ "$COMPARISON_EXIT_CODE" -eq 1 ] && [ "$final_rc" -ne 3 ]; then
      final_rc=1
      add_verdict_source "comparison_failed"
    fi
  fi
  if [ "$LOG_EXIT_CODE" -eq 1 ] && [ "$final_rc" -ne 3 ]; then
    final_rc=1
    add_verdict_source "log_assertion_failed"
  elif [ "$LOG_EXIT_CODE" -eq 3 ]; then
    [ "$final_rc" -eq 0 ] && final_rc=3
    add_verdict_source "log_assertion_blocked"
  fi

  if ! create_execution "$final_rc"; then
    rm -f "$EXECUTION" "$MANIFEST"
    echo "[SC-02/$S] BLOCKED: bound execution evidence could not be created."
    return 3
  fi
  if ! create_manifest "$final_rc"; then
    rm -f "$MANIFEST"
    echo "[SC-02/$S] BLOCKED: bound evidence manifest could not be created."
    return 3
  fi

  echo "[SC-02/$S] evidence boundary: Layer D proves Store API/order/provider behavior, not Payment Element rendering or browser field UX."
  case "$final_rc" in
    0) echo "[SC-02/$S] deterministic verdict: PASS"; return 0 ;;
    1) echo "[SC-02/$S] deterministic verdict: FAIL"; return 1 ;;
    *) echo "[SC-02/$S] deterministic verdict: BLOCKED"; return 3 ;;
  esac
}

if [[ ! "$RUN_STAMP" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9]+$ ]]; then
  echo "[SC-02/$S] BLOCKED: runner invocation stamp is unavailable."
  exit 3
fi
if [ "$RUN_SCOPE" != "partial" ] && [ "$RUN_SCOPE" != "full" ]; then
  echo "[SC-02/$S] BLOCKED: runner scope is unavailable."
  exit 3
fi
if [[ ! "${CRITICAL_FLOWS_RUN_CONTEXT_KEY:-}" =~ ^[0-9a-f]{64}$ ]]; then
  echo "[SC-02/$S] BLOCKED: private run-context key is unavailable."
  exit 3
fi
if [ -z "$BASE_URL" ]; then
  echo "[SC-02/$S] BLOCKED: explicit local Store API base URL is unavailable."
  exit 3
fi
if [ "$SKU" != "test-lab-beaker-001" ]; then
  echo "[SC-02/$S] BLOCKED: the fixed product SKU binding is invalid."
  exit 3
fi
for dependency in "$HTTP_DRIVER" "$STATE_DRIVER" "$EVIDENCE_TOOL"; do
  if [ ! -f "$dependency" ]; then
    echo "[SC-02/$S] BLOCKED: deterministic dependency is missing: $dependency"
    exit 3
  fi
done
if [ "$(sed -n '1p' "$STATE_DRIVER")" != "<?php" ]; then
  echo "[SC-02/$S] BLOCKED: state collector source boundary is invalid."
  exit 3
fi

mkdir -p "$FLOW_EVIDENCE_DIR"
if [ "$S" = "ref" ]; then
  rm -f \
    "$REF_PREFLIGHT" "$REF_HTTP" "$REF_POST" "$REF_RESULT" "$REF_LOG_SCAN" "$REF_EXECUTION" \
    "$TARGET_PREFLIGHT" "$TARGET_HTTP" "$TARGET_POST" "$TARGET_RESULT" "$TARGET_LOG_SCAN" "$TARGET_EXECUTION" \
    "$FLOW_EVIDENCE_DIR/ref-manifest.json" "$FLOW_EVIDENCE_DIR/target-manifest.json" \
    "$FLOW_EVIDENCE_DIR/ref-log-scan.raw.json" "$FLOW_EVIDENCE_DIR/target-log-scan.raw.json" "$COMPARISON"
else
  rm -f \
    "$TARGET_PREFLIGHT" "$TARGET_HTTP" "$TARGET_POST" "$TARGET_RESULT" "$TARGET_LOG_SCAN" "$TARGET_EXECUTION" \
    "$FLOW_EVIDENCE_DIR/target-manifest.json" "$FLOW_EVIDENCE_DIR/target-log-scan.raw.json" "$COMPARISON"
fi

echo "[SC-02/$S] exercise: guest Cart-Token Store API checkout with the fixed Visa test method"
capture_state "preflight" "$PREFLIGHT"
PREFLIGHT_EXIT_CODE=$?
if [ "$PREFLIGHT_EXIT_CODE" -ne 0 ]; then
  PREFLIGHT_EXIT_CODE=3
  add_verdict_source "preflight_blocked"
  evaluate_store
  RESULT_EXIT_CODE=$?
  [ "$RESULT_EXIT_CODE" -eq 3 ] || {
    RESULT_EXIT_CODE=3
    add_verdict_source "evidence_contract_blocked"
  }
  finish_with_logs
  exit $?
fi

PRODUCT_ID="$(json_positive_int "$PREFLIGHT" "facts.product.id")"
if [ $? -ne 0 ] || [ -z "$PRODUCT_ID" ]; then
  add_verdict_source "evidence_contract_blocked"
  evaluate_store
  RESULT_EXIT_CODE=$?
  [ "$RESULT_EXIT_CODE" -eq 3 ] || RESULT_EXIT_CODE=3
  finish_with_logs
  exit $?
fi

STAGE="http"
rm -f "$HTTP"
python3 "$HTTP_DRIVER" \
  --base-url "$BASE_URL" \
  --store "$S" \
  --run-stamp "$RUN_STAMP" \
  --run-token "$RUN_TOKEN" \
  --product-id "$PRODUCT_ID" \
  --timeout "$HTTP_TIMEOUT" \
  --output "$HTTP"
HTTP_EXIT_CODE=$?
case "$HTTP_EXIT_CODE" in
  0) HTTP_STATUS="pass" ;;
  1) HTTP_STATUS="fail" ;;
  3) HTTP_STATUS="blocked" ;;
  *) HTTP_EXIT_CODE=3; HTTP_STATUS="blocked" ;;
esac

if [ -f "$HTTP" ]; then
  python3 "$EVIDENCE_TOOL" validate-http \
    --input "$HTTP" --store "$S" --run-stamp "$RUN_STAMP" \
    --expected-status "$HTTP_STATUS" --expected-exit-code "$HTTP_EXIT_CODE" >/dev/null
  if [ $? -ne 0 ]; then
    HTTP_EXIT_CODE=3
    add_verdict_source "evidence_contract_blocked"
  fi
else
  HTTP_EXIT_CODE=3
  add_verdict_source "evidence_contract_blocked"
fi

if [ "$HTTP_EXIT_CODE" -ne 0 ]; then
  if [ "$HTTP_EXIT_CODE" -eq 1 ]; then
    add_verdict_source "http_failed"
  else
    add_verdict_source "http_blocked"
  fi
  evaluate_store
  RESULT_EXIT_CODE=$?
  case "$RESULT_EXIT_CODE" in
    1|3) ;;
    *) RESULT_EXIT_CODE=3; add_verdict_source "evidence_contract_blocked" ;;
  esac
  finish_with_logs
  exit $?
fi

ORDER_ID="$(json_positive_int "$HTTP" "order_id")"
if [ $? -ne 0 ] || [ -z "$ORDER_ID" ]; then
  HTTP_EXIT_CODE=3
  add_verdict_source "evidence_contract_blocked"
  evaluate_store
  RESULT_EXIT_CODE=$?
  [ "$RESULT_EXIT_CODE" -eq 3 ] || RESULT_EXIT_CODE=3
  finish_with_logs
  exit $?
fi

STAGE="post"
capture_state "post" "$POST" "$PRODUCT_ID" "$ORDER_ID"
POST_EXIT_CODE=$?
case "$POST_EXIT_CODE" in
  0) ;;
  1) add_verdict_source "post_failed" ;;
  3) add_verdict_source "post_blocked" ;;
  *) POST_EXIT_CODE=3; add_verdict_source "evidence_contract_blocked" ;;
esac

evaluate_store
RESULT_EXIT_CODE=$?
case "$RESULT_EXIT_CODE" in
  0) ;;
  1)
    [ "$POST_EXIT_CODE" -eq 1 ] || add_verdict_source "result_failed"
    ;;
  3)
    [ "$POST_EXIT_CODE" -eq 3 ] || add_verdict_source "result_blocked"
    ;;
  *)
    RESULT_EXIT_CODE=3
    add_verdict_source "evidence_contract_blocked"
    ;;
esac

finish_with_logs
exit $?
