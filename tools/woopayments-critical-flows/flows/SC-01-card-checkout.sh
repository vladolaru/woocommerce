#!/usr/bin/env bash
# SC-01 — Card checkout, shortcode (new card). LAYER D example (deterministic exerciser + state assert).
# Driven per-store by run.sh with STORE_NAME set. Overlap: SC-01 is also Layer-A (see matrix) for the
# UX checkpoints (card fields, error states, test-mode badge) a script can't judge.
set -uo pipefail
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"
S="${STORE_NAME:?run via run.sh}"

echo "[SC-01/$S] exercise: create+pay a card order with 4242 via Store API / REST"
# Deterministic exerciser options (pick what's stable in this env):
#   (a) Store API checkout call with a tokenized test PM, OR
#   (b) playwright-cli script that fills the card iframe on the shortcode checkout and places the order.
# Capture the resulting order id.
ORDER_ID="${ORDER_ID:-}"   # TODO set from the exerciser output
if [ -z "$ORDER_ID" ]; then echo "[SC-01/$S] BLOCKED: EXERCISER NOT WIRED - fill in (a) or (b)"; exit 3; fi

# State assertions (Bucket-E + status) — the deterministic verdict.
rc=0
assert_order_status "$S" "$ORDER_ID" "processing" || rc=1
assert_meta_present "$S" "$ORDER_ID" "_intent_id" || rc=1
assert_meta_present "$S" "$ORDER_ID" "_charge_id" || rc=1
assert_log_clean "$S"
echo "[SC-01/$S] deterministic verdict: $([ $rc -eq 0 ] && echo PASS || echo FAIL)"
exit $rc
