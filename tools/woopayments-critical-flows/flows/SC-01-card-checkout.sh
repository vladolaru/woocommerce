#!/usr/bin/env bash
# SC-01 — Card checkout, shortcode (new card). LAYER D example (deterministic exerciser + state assert).
# Driven per-store by run.sh with STORE_NAME set. Overlap: SC-01 is also Layer-A (see matrix) for the
# UX checkpoints (card fields, error states, test-mode badge) a script can't judge.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"
S="${STORE_NAME:?run via run.sh}"
FLOW_DRIVER="${SC01_FLOW_DRIVER:-$REPO_ROOT/tools/woopayments-merge/flow-drive.sh}"
SKU="${SC01_SKU:-test-lab-beaker-001}"
QUANTITY="${SC01_QUANTITY:-2}"

extract_order_id() {
  python3 -c '
import json
import sys

raw = sys.stdin.read()
for line in raw.splitlines():
    line = line.strip()
    if not line or not line.startswith("{"):
        continue
    try:
        payload = json.loads(line)
    except json.JSONDecodeError:
        continue
    try:
        order_id = int(payload.get("order_id") or 0)
    except (TypeError, ValueError):
        order_id = 0
    if order_id > 0:
        print(order_id)
        sys.exit(0)
sys.exit(1)
'
}

run_charge_exerciser() {
  local native_flag=()

  if [ "$S" = "target" ]; then
    native_flag=(--native)
  fi

  export REPO_ROOT WC_DIR REF_CONTAINER TARGET_WPENV_CWD REF_WP_COMMAND TARGET_WP_COMMAND
  export -f wp_ref wp_target

  WP="wp_$S" bash "$FLOW_DRIVER" charge --deterministic "${native_flag[@]}" --sku "$SKU" --quantity "$QUANTITY" --type success
}

record_assertion() {
  local rc
  "$@"
  rc=$?

  if [ "$rc" -eq 0 ]; then
    return
  fi

  if [ "$rc" -eq 2 ] || [ "$rc" -eq 3 ]; then
    blocked=1
  else
    failed=1
  fi
}

echo "[SC-01/$S] exercise: create+pay a card order with 4242 via Store API / REST"
if [ ! -f "$FLOW_DRIVER" ]; then
  echo "[SC-01/$S] BLOCKED: deterministic charge exerciser is missing: $FLOW_DRIVER"
  exit 3
fi

charge_output="$(run_charge_exerciser 2>&1)"
charge_rc=$?
if [ "$charge_rc" -ne 0 ]; then
  echo "[SC-01/$S] BLOCKED: deterministic charge exerciser failed."
  printf '%s\n' "$charge_output" | tail -20
  exit 3
fi

ORDER_ID="$(printf '%s\n' "$charge_output" | extract_order_id)"
if [ -z "$ORDER_ID" ]; then
  echo "[SC-01/$S] BLOCKED: deterministic charge exerciser emitted no order_id."
  printf '%s\n' "$charge_output" | tail -20
  exit 3
fi
echo "[SC-01/$S] captured order_id=$ORDER_ID"

# State assertions (Bucket-E + status) — the deterministic verdict.
failed=0
blocked=0
record_assertion assert_order_status "$S" "$ORDER_ID" "processing"
record_assertion assert_meta_present "$S" "$ORDER_ID" "_intent_id"
record_assertion assert_meta_present "$S" "$ORDER_ID" "_charge_id"
record_assertion assert_log_clean "$S"

if [ "$failed" -ne 0 ]; then
  echo "[SC-01/$S] deterministic verdict: FAIL"
  exit 1
fi

if [ "$blocked" -ne 0 ]; then
  echo "[SC-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi

echo "[SC-01/$S] deterministic verdict: PASS"
exit 0
