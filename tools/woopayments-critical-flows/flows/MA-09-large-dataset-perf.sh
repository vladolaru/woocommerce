#!/usr/bin/env bash
# MA-09 — deterministic large-dataset transaction-list performance comparison.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:?run via run.sh}"
RUN_STAMP="${CRITICAL_FLOWS_RUN_STAMP:-}"
RUN_SCOPE="${CRITICAL_FLOWS_RUN_SCOPE:-}"
STATE_DRIVER="${MA09_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowsma09driver.php}"
COMPARATOR="${MA09_COMPARATOR:-$DIR/ma09-compare.py}"
FLOW_EVIDENCE_DIR="$EVIDENCE_DIR/runs/$RUN_STAMP-$RUN_SCOPE/MA-09-large-dataset-perf"
REF_PAYLOAD="$FLOW_EVIDENCE_DIR/ref.json"
TARGET_PAYLOAD="$FLOW_EVIDENCE_DIR/target.json"
COMPARISON_PAYLOAD="$FLOW_EVIDENCE_DIR/comparison.json"

print_messages() {
  local payload="$1" field="$2"
  PAYLOAD="$payload" python3 - "$field" <<'PY'
import json
import os
import sys

payload = json.loads(os.environ["PAYLOAD"])
for message in payload.get(sys.argv[1], []):
    print(f"  {message}")
PY
}

payload_field() {
  local payload="$1" path="$2"
  PAYLOAD="$payload" python3 - "$path" <<'PY'
import json
import os
import sys

value = json.loads(os.environ["PAYLOAD"])
for part in sys.argv[1].split("."):
    if not isinstance(value, dict) or part not in value:
        sys.exit(1)
    value = value[part]
if isinstance(value, bool):
    print("true" if value else "false")
elif isinstance(value, (dict, list)):
    print(json.dumps(value, separators=(",", ":")))
else:
    print(value)
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

if [ -z "$RUN_STAMP" ]; then
  echo "[MA-09/$S] BLOCKED: runner invocation stamp is unavailable."
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if [ "$RUN_SCOPE" != "partial" ] && [ "$RUN_SCOPE" != "full" ]; then
  echo "[MA-09/$S] BLOCKED: runner scope is unavailable."
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if [ ! -f "$STATE_DRIVER" ]; then
  echo "[MA-09/$S] BLOCKED: deterministic state driver is missing: $STATE_DRIVER"
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if [ ! -f "$COMPARATOR" ]; then
  echo "[MA-09/$S] BLOCKED: deterministic comparator is missing: $COMPARATOR"
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi

mkdir -p "$FLOW_EVIDENCE_DIR"
if [ "$S" = "ref" ]; then
  rm -f "$REF_PAYLOAD" "$TARGET_PAYLOAD" "$COMPARISON_PAYLOAD"
fi

echo "[MA-09/$S] exercise: reconcile and time the 500-row transactions REST fixture"
state_output="$(wp_store "$S" --user=1 eval-file - "$S" < "$STATE_DRIVER" 2>&1)"
state_rc=$?
if [ "$state_rc" -ne 0 ]; then
  echo "[MA-09/$S] BLOCKED: deterministic state driver could not run."
  printf '%s\n' "$state_output" | tail -20
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi

STATE_JSON="$(printf '%s\n' "$state_output" | python3 "$COMPARATOR" normalize --store "$S" --run-stamp "$RUN_STAMP" 2>/dev/null)"
normalize_rc=$?
if [ "$normalize_rc" -ne 0 ] || [ -z "$STATE_JSON" ]; then
  echo "[MA-09/$S] BLOCKED: deterministic state driver emitted no valid payload."
  printf '%s\n' "$state_output" | tail -20
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi

if [ "$S" = "ref" ]; then
  write_payload "$STATE_JSON" "$REF_PAYLOAD"
else
  write_payload "$STATE_JSON" "$TARGET_PAYLOAD"
fi

dataset_count="$(payload_field "$STATE_JSON" dataset_count 2>/dev/null || printf '?')"
ledger_sha="$(payload_field "$STATE_JSON" ledger_sha256 2>/dev/null || printf '?')"
echo "[MA-09/$S] captured dataset_count=$dataset_count ledger=$ledger_sha"

status="$(payload_field "$STATE_JSON" status 2>/dev/null || printf 'invalid')"
verdict_rc=0
verdict_payload="$STATE_JSON"
if [ "$status" = "blocked" ]; then
  verdict_rc=3
elif [ "$status" = "fail" ]; then
  verdict_rc=1
elif [ "$status" != "pass" ]; then
  verdict_rc=3
fi

if [ "$S" = "target" ] && [ "$verdict_rc" -ne 3 ]; then
  comparison_output="$(python3 "$COMPARATOR" compare --reference "$REF_PAYLOAD" --target "$TARGET_PAYLOAD" --run-stamp "$RUN_STAMP" 2>&1)"
  comparison_rc=$?
  if [ "$comparison_rc" -eq 0 ] || [ "$comparison_rc" -eq 1 ] || [ "$comparison_rc" -eq 3 ]; then
    verdict_rc=$comparison_rc
    verdict_payload="$comparison_output"
    write_payload "$comparison_output" "$COMPARISON_PAYLOAD"
  else
    echo "[MA-09/$S] BLOCKED: deterministic comparison could not run."
    printf '%s\n' "$comparison_output" | tail -20
    verdict_rc=3
  fi
fi

assert_log_clean "$S"
log_rc=$?
if [ "$log_rc" -eq 1 ] && [ "$verdict_rc" -ne 3 ]; then
  verdict_rc=1
elif [ "$log_rc" -ne 0 ] && [ "$log_rc" -ne 1 ]; then
  verdict_rc=3
fi

if [ "$verdict_rc" -eq 3 ]; then
  echo "[MA-09/$S] prerequisites unavailable:"
  print_messages "$verdict_payload" blockers
  echo "[MA-09/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if [ "$verdict_rc" -ne 0 ]; then
  echo "[MA-09/$S] product/performance mismatches:"
  print_messages "$verdict_payload" errors
  echo "[MA-09/$S] deterministic verdict: FAIL"
  exit 1
fi

if [ "$S" = "target" ]; then
  ratios="$(payload_field "$verdict_payload" target_to_reference_ratios 2>/dev/null || printf '{}')"
  echo "[MA-09/$S] target/reference measured ratios=$ratios"
fi
echo "[MA-09/$S] deterministic verdict: PASS"
exit 0
