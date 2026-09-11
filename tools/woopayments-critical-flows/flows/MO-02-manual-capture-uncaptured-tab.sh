#!/usr/bin/env bash
# MO-02 — deterministic Uncaptured-tab list and row-capture parity exercise.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:?run via run.sh}"
RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
FLOW_DRIVER="${MO02_FLOW_DRIVER:-$REPO_ROOT/tools/woopayments-merge/flow-drive.sh}"
STATE_DRIVER="${MO02_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowsmo02driver.php}"
COMPARATOR="${MO02_COMPARATOR:-$DIR/mo02-evidence.py}"
EVIDENCE_TOOL="$DIR/mo02-evidence.py"
SKU="${MO02_SKU:-test-lab-beaker-001}"
QUANTITY="${MO02_QUANTITY:-2}"
FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/MO-02-manual-capture-uncaptured-tab"
REF_PRE="$FLOW_EVIDENCE_DIR/ref-pre.json"
REF_CAPTURE="$FLOW_EVIDENCE_DIR/ref-capture.json"
REF_POST="$FLOW_EVIDENCE_DIR/ref-post.json"
TARGET_PRE="$FLOW_EVIDENCE_DIR/target-pre.json"
TARGET_CAPTURE="$FLOW_EVIDENCE_DIR/target-capture.json"
TARGET_POST="$FLOW_EVIDENCE_DIR/target-post.json"
COMPARISON="$FLOW_EVIDENCE_DIR/comparison.json"
MANIFEST="$FLOW_EVIDENCE_DIR/$S-manifest.json"
EXECUTION="$FLOW_EVIDENCE_DIR/$S-execution.json"
AUTHORIZATION_EXIT_CODE=""
AUTHORIZATION_ORDER_ID_PRESENT="false"
PRE_STATE_EXIT_CODE=""
CAPTURE_EXIT_CODE=""
CAPTURE_CLASSIFICATION=""
POST_STATE_EXIT_CODE=""
COMPARISON_EXIT_CODE=""
STATE_JSON='{"blockers":["State driver command failed."]}'

print_messages() {
  local payload="$1" field="$2"
  PAYLOAD="$payload" python3 - "$field" <<'PY'
import json
import os
import sys

try:
    payload = json.loads(os.environ["PAYLOAD"])
except (UnicodeError, ValueError):
    raise SystemExit(3)
for message in payload.get(sys.argv[1], []):
    print(f"  {message}")
PY
}

write_payload() {
  local payload="$1" destination="$2"
  PAYLOAD="$payload" DESTINATION="$destination" python3 <<'PY'
import os
from pathlib import Path

Path(os.environ["DESTINATION"]).write_text(os.environ["PAYLOAD"] + "\n", encoding="utf-8")
PY
}

extract_order_id() {
  python3 -c '
import json
import sys

raw = sys.stdin.read()
decoder = json.JSONDecoder()
for index, character in enumerate(raw):
    if character != "{":
        continue
    try:
        payload, _ = decoder.raw_decode(raw[index:])
    except json.JSONDecodeError:
        continue
    try:
        order_id = int(payload.get("order_id") or 0)
    except (AttributeError, TypeError, ValueError):
        order_id = 0
    if order_id > 0:
        print(order_id)
        sys.exit(0)
sys.exit(1)
'
}

state_identity() {
  local field="$1"
  PAYLOAD="$STATE_JSON" python3 - "$field" <<'PY'
import json
import os
import sys

try:
    payload = json.loads(os.environ["PAYLOAD"])
    value = payload["order"][sys.argv[1]]
except (KeyError, TypeError, UnicodeError, ValueError):
    raise SystemExit(3)
if not isinstance(value, str) or not value:
    raise SystemExit(3)
print(value)
PY
}

run_flow_driver() {
  local native_flag=""
  if [ "$S" = "target" ]; then
    native_flag="--native"
  fi

  export REPO_ROOT WC_DIR REF_CONTAINER TARGET_WPENV_CWD REF_WP_COMMAND TARGET_WP_COMMAND
  export -f run_wp_command_string wp_ref wp_target

  if [ -n "$native_flag" ]; then
    WP="wp_$S" bash "$FLOW_DRIVER" charge --deterministic "$native_flag" "$@"
  else
    WP="wp_$S" bash "$FLOW_DRIVER" charge --deterministic "$@"
  fi
}

capture_state() {
  local phase="$1" destination="$2" raw state_rc normalized normalize_rc
  STATE_JSON='{"blockers":["State driver command failed."]}'
  raw="$(wp_store "$S" --user=1 eval-file - "$S" snapshot "$ORDER_ID" "$phase" < "$STATE_DRIVER" 2>&1)"
  state_rc=$?
  if [ "$state_rc" -ne 0 ]; then
    echo "[MO-02/$S] BLOCKED: $phase-capture state driver could not run."
    printf '%s\n' "$raw" | tail -20
    return 3
  fi

  normalized="$(printf '%s\n' "$raw" | python3 "$COMPARATOR" normalize-state \
    --store "$S" --phase "$phase" --run-stamp "$RUN_STAMP" 2>/dev/null)"
  normalize_rc=$?
  if [ -z "$normalized" ]; then
    echo "[MO-02/$S] BLOCKED: $phase-capture state could not be normalized."
    printf '%s\n' "$raw" | tail -20
    return 3
  fi
  write_payload "$normalized" "$destination"
  STATE_JSON="$normalized"
  if [ "$normalize_rc" -ne 0 ] && [ "$normalize_rc" -ne 1 ] && [ "$normalize_rc" -ne 3 ]; then
    STATE_JSON='{"blockers":["State normalizer returned an invalid exit code."]}'
    return 3
  fi
  return "$normalize_rc"
}

capture_row() {
  local destination="$1" raw capture_rc normalized normalize_rc
  raw="$(wp_store "$S" --user=1 eval-file - "$S" capture "$ORDER_ID" "$INTENT_ID" < "$STATE_DRIVER" 2>&1)"
  capture_rc=$?
  CAPTURE_EXIT_CODE="$capture_rc"
  normalized="$(printf '%s\n' "$raw" | python3 "$COMPARATOR" normalize-capture \
    --store "$S" --order-id "$ORDER_ID" --intent-id "$INTENT_ID" --charge-id "$CHARGE_ID" \
    --expected-exit-code "$capture_rc" --run-stamp "$RUN_STAMP" 2>/dev/null)"
  normalize_rc=$?
  if [ -n "$normalized" ]; then
    write_payload "$normalized" "$destination"
  fi

  if [ "$capture_rc" -eq 0 ] && [ "$normalize_rc" -eq 0 ]; then
    CAPTURE_CLASSIFICATION="pass"
    return 0
  fi
  if [ "$capture_rc" -eq 1 ] && [ "$normalize_rc" -eq 1 ]; then
    CAPTURE_CLASSIFICATION="product_failure"
    printf '%s\n' "$raw" | tail -20
    return 1
  fi
  CAPTURE_CLASSIFICATION="blocked"
  printf '%s\n' "$raw" | tail -20
  return 3
}

create_execution() {
  local verdict_rc="$1" log_rc="$2" status source
  shift 2
  local -a verdict_sources=("$@")
  local -a arguments

  case "$verdict_rc" in
    0) status="pass" ;;
    1) status="fail" ;;
    3) status="blocked" ;;
    *) return 3 ;;
  esac
  arguments=(
    execution
    --store "$S"
    --status "$status"
    --exit-code "$verdict_rc"
    --run-stamp "$RUN_STAMP"
    --output "$EXECUTION"
    --authorization-exit-code "$AUTHORIZATION_EXIT_CODE"
    --authorization-order-id-present "$AUTHORIZATION_ORDER_ID_PRESENT"
    --log-assertion-exit-code "$log_rc"
  )
  for source in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
    arguments+=(--verdict-source "$source")
  done
  [ -n "$PRE_STATE_EXIT_CODE" ] && arguments+=(--pre-state-exit-code "$PRE_STATE_EXIT_CODE")
  [ -n "$CAPTURE_EXIT_CODE" ] && arguments+=(--capture-exit-code "$CAPTURE_EXIT_CODE")
  [ -n "$CAPTURE_CLASSIFICATION" ] && arguments+=(--capture-classification "$CAPTURE_CLASSIFICATION")
  [ -n "$POST_STATE_EXIT_CODE" ] && arguments+=(--post-state-exit-code "$POST_STATE_EXIT_CODE")
  [ -n "$COMPARISON_EXIT_CODE" ] && arguments+=(--comparison-exit-code "$COMPARISON_EXIT_CODE")

  rm -f "$EXECUTION"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
}

create_manifest() {
  local verdict_rc="$1" status candidate
  shift
  local -a verdict_sources=("$@")
  local -a arguments

  case "$verdict_rc" in
    0) status="pass" ;;
    1) status="fail" ;;
    3) status="blocked" ;;
    *) return 3 ;;
  esac
  arguments=(
    manifest
    --store "$S"
    --status "$status"
    --exit-code "$verdict_rc"
    --run-stamp "$RUN_STAMP"
    --run-scope "$RUN_SCOPE"
    --output "$MANIFEST"
  )
  for candidate in ${verdict_sources[@]+"${verdict_sources[@]}"}; do
    arguments+=(--verdict-source "$candidate")
  done
  if [ "$S" = "ref" ]; then
    for candidate in "$REF_PRE" "$REF_CAPTURE" "$REF_POST" "$FLOW_EVIDENCE_DIR/ref-execution.json"; do
      [ -f "$candidate" ] && arguments+=(--file "$candidate")
    done
  else
    for candidate in \
      "$REF_PRE" "$REF_CAPTURE" "$REF_POST" "$FLOW_EVIDENCE_DIR/ref-execution.json" \
      "$TARGET_PRE" "$TARGET_CAPTURE" "$TARGET_POST" "$FLOW_EVIDENCE_DIR/target-execution.json" \
      "$COMPARISON"; do
      [ -f "$candidate" ] && arguments+=(--file "$candidate")
    done
  fi

  rm -f "$MANIFEST"
  python3 "$EVIDENCE_TOOL" "${arguments[@]}"
}

finish_with_logs() {
  local verdict_rc="$1"
  shift
  local -a verdict_sources=("$@")
  assert_log_clean "$S"
  log_rc=$?
  if [ "$log_rc" -eq 1 ] && [ "$verdict_rc" -ne 3 ]; then
    verdict_rc=1
    verdict_sources+=("log_assertion_failed")
  elif [ "$log_rc" -ne 0 ] && [ "$log_rc" -ne 1 ]; then
    if [ "$verdict_rc" -eq 0 ]; then
      verdict_rc=3
      verdict_sources=("log_assertion_blocked")
    elif [ "$verdict_rc" -eq 3 ]; then
      verdict_sources+=("log_assertion_blocked")
    fi
  fi

  create_execution "$verdict_rc" "$log_rc" ${verdict_sources[@]+"${verdict_sources[@]}"}
  if [ $? -ne 0 ]; then
    rm -f "$EXECUTION" "$MANIFEST"
    echo "[MO-02/$S] BLOCKED: deterministic execution evidence could not be created."
    return 3
  fi
  create_manifest "$verdict_rc" ${verdict_sources[@]+"${verdict_sources[@]}"}
  if [ $? -ne 0 ]; then
    echo "[MO-02/$S] BLOCKED: deterministic evidence manifest could not be created."
    return 3
  fi

  if [ "$verdict_rc" -eq 3 ]; then
    echo "[MO-02/$S] deterministic verdict: BLOCKED"
    return 3
  fi
  if [ "$verdict_rc" -ne 0 ]; then
    echo "[MO-02/$S] deterministic verdict: FAIL"
    return 1
  fi
  echo "[MO-02/$S] deterministic verdict: PASS"
  return 0
}

if [ -z "$RUN_STAMP" ]; then
  echo "[MO-02/$S] BLOCKED: runner invocation stamp is unavailable."
  exit 3
fi
if [ "$RUN_SCOPE" != "partial" ] && [ "$RUN_SCOPE" != "full" ]; then
  echo "[MO-02/$S] BLOCKED: runner scope is unavailable."
  exit 3
fi
for dependency in "$FLOW_DRIVER" "$STATE_DRIVER" "$COMPARATOR" "$EVIDENCE_TOOL"; do
  if [ ! -f "$dependency" ]; then
    echo "[MO-02/$S] BLOCKED: deterministic dependency is missing: $dependency"
    exit 3
  fi
done

mkdir -p "$FLOW_EVIDENCE_DIR"
if [ "$S" = "ref" ]; then
  rm -f "$REF_PRE" "$REF_CAPTURE" "$REF_POST" "$TARGET_PRE" "$TARGET_CAPTURE" "$TARGET_POST" \
    "$COMPARISON" "$FLOW_EVIDENCE_DIR/ref-execution.json" "$FLOW_EVIDENCE_DIR/target-execution.json" \
    "$FLOW_EVIDENCE_DIR/ref-manifest.json" "$FLOW_EVIDENCE_DIR/target-manifest.json"
  PRE_PATH="$REF_PRE"
  CAPTURE_PATH="$REF_CAPTURE"
  POST_PATH="$REF_POST"
else
  PRE_PATH="$TARGET_PRE"
  CAPTURE_PATH="$TARGET_CAPTURE"
  POST_PATH="$TARGET_POST"
fi

echo "[MO-02/$S] exercise: authorize Visa 4242 for the Uncaptured tab"
charge_output="$(run_flow_driver --sku "$SKU" --quantity "$QUANTITY" --type success --manual-capture 2>&1)"
charge_rc=$?
AUTHORIZATION_EXIT_CODE="$charge_rc"
if [ "$charge_rc" -ne 0 ]; then
  echo "[MO-02/$S] BLOCKED: deterministic authorization driver failed."
  printf '%s\n' "$charge_output" | tail -20
  finish_with_logs 3 authorization_driver_blocked
  exit $?
fi

ORDER_ID="$(printf '%s\n' "$charge_output" | extract_order_id)"
if [ -z "$ORDER_ID" ]; then
  echo "[MO-02/$S] BLOCKED: deterministic authorization emitted no order_id."
  printf '%s\n' "$charge_output" | tail -20
  finish_with_logs 3 authorization_order_id_missing
  exit $?
fi
echo "[MO-02/$S] captured authorization order_id=$ORDER_ID"
AUTHORIZATION_ORDER_ID_PRESENT="true"

capture_state pre "$PRE_PATH"
pre_rc=$?
PRE_STATE_EXIT_CODE="$pre_rc"
if [ "$pre_rc" -eq 3 ]; then
  echo "[MO-02/$S] prerequisites unavailable:"
  print_messages "$STATE_JSON" blockers
  finish_with_logs 3 pre_state_blocked
  exit $?
fi
if [ "$pre_rc" -ne 0 ]; then
  echo "[MO-02/$S] pre-capture state mismatches:"
  print_messages "$STATE_JSON" errors
  finish_with_logs 1 pre_state_failed
  exit $?
fi

INTENT_ID="$(state_identity intent_id)"
CHARGE_ID="$(state_identity charge_id)"
if [ -z "$INTENT_ID" ] || [ -z "$CHARGE_ID" ]; then
  echo "[MO-02/$S] BLOCKED: normalized authorization identity is unavailable."
  finish_with_logs 3 pre_state_blocked
  exit $?
fi

echo "[MO-02/$S] exercise: capture the exact Uncaptured-tab row"
capture_row "$CAPTURE_PATH"
capture_rc=$?

capture_state post "$POST_PATH"
post_rc=$?
POST_STATE_EXIT_CODE="$post_rc"
verdict_rc=0
declare -a verdict_sources=()
if [ "$CAPTURE_CLASSIFICATION" = "product_failure" ]; then
  echo "[MO-02/$S] row capture operation failed"
  verdict_rc=1
  verdict_sources+=("capture_operation_failed")
elif [ "$CAPTURE_CLASSIFICATION" = "blocked" ]; then
  echo "[MO-02/$S] row capture operation blocked"
  verdict_rc=3
  verdict_sources+=("capture_operation_blocked")
fi
if [ "$post_rc" -eq 3 ]; then
  echo "[MO-02/$S] post-capture state unavailable:"
  print_messages "$STATE_JSON" blockers
  if [ "$verdict_rc" -eq 0 ]; then
    verdict_rc=3
    verdict_sources+=("post_state_blocked")
  fi
elif [ "$post_rc" -ne 0 ]; then
  echo "[MO-02/$S] post-capture state mismatches:"
  print_messages "$STATE_JSON" errors
  if [ "$verdict_rc" -ne 3 ]; then
    verdict_rc=1
    verdict_sources+=("post_state_failed")
  fi
fi

if [ "$capture_rc" -eq 0 ] && [ "$post_rc" -eq 0 ]; then
  echo "[MO-02/$S] authorizations row transition: PASS"
else
  echo "[MO-02/$S] authorizations row transition: FAIL"
fi

if [ "$S" = "target" ] && [ "$verdict_rc" -ne 3 ]; then
  comparison_output="$(python3 "$COMPARATOR" compare \
    --reference-pre "$REF_PRE" --reference-capture "$REF_CAPTURE" --reference-post "$REF_POST" \
    --target-pre "$TARGET_PRE" --target-capture "$TARGET_CAPTURE" --target-post "$TARGET_POST" \
    --run-stamp "$RUN_STAMP" 2>&1)"
  comparison_rc=$?
  COMPARISON_EXIT_CODE="$comparison_rc"
  comparison_valid=0
  if [ "$comparison_rc" -ne 0 ] && [ "$comparison_rc" -ne 1 ] && [ "$comparison_rc" -ne 3 ]; then
    comparison_valid=1
  elif ! printf '%s\n' "$comparison_output" | python3 "$EVIDENCE_TOOL" validate-comparison \
    --run-stamp "$RUN_STAMP" --expected-exit-code "$comparison_rc"; then
    comparison_valid=1
  fi
  if [ "$comparison_valid" -ne 0 ]; then
    rm -f "$COMPARISON"
    COMPARISON_EXIT_CODE=3
    echo "[MO-02/$S] cross-store pre/post parity: BLOCKED"
    echo "  Comparator emitted malformed or contradictory evidence."
    verdict_rc=3
    verdict_sources+=("comparison_blocked")
  else
    write_payload "$comparison_output" "$COMPARISON"
    if [ "$comparison_rc" -eq 0 ]; then
      echo "[MO-02/$S] cross-store pre/post parity: PASS"
    elif [ "$comparison_rc" -eq 1 ]; then
      echo "[MO-02/$S] cross-store pre/post parity: FAIL"
      print_messages "$comparison_output" errors
      verdict_rc=1
      verdict_sources+=("comparison_failed")
    else
      echo "[MO-02/$S] cross-store pre/post parity: BLOCKED"
      print_messages "$comparison_output" blockers
      verdict_rc=3
      verdict_sources+=("comparison_blocked")
    fi
  fi
fi

finish_with_logs "$verdict_rc" ${verdict_sources[@]+"${verdict_sources[@]}"}
exit $?
