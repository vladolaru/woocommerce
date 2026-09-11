#!/usr/bin/env bash
# SP-01 — deterministic saved-card state exerciser.
set -uo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$DIR/../lib/common.sh"

S="${STORE_NAME:?run via run.sh}"
STATE_DRIVER="${SP01_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowssp01driver.php}"

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

normalize_state_payload() {
  python3 -c '
import json
import sys

raw = sys.stdin.read()
decoder = json.JSONDecoder()
for index, char in enumerate(raw):
    if char != "{":
        continue
    try:
        payload, _ = decoder.raw_decode(raw[index:])
    except json.JSONDecodeError:
        continue
    if isinstance(payload, dict) and payload.get("schema") == "woopayments_sp01_deterministic.v1":
        print(json.dumps(payload, separators=(",", ":")))
        sys.exit(0)
sys.exit(1)
'
}

state_field() {
  local path="$1"
  STATE_JSON="$STATE_JSON" python3 - "$path" <<'PY'
import json
import os
import sys

value = json.loads(os.environ["STATE_JSON"])
for part in sys.argv[1].split("."):
    if not isinstance(value, dict) or part not in value:
        sys.exit(1)
    value = value[part]
if isinstance(value, bool):
    print("true" if value else "false")
elif value is None:
    print("")
elif isinstance(value, (dict, list)):
    print(json.dumps(value, separators=(",", ":")))
else:
    print(value)
PY
}

print_state_messages() {
  local field="$1"
  STATE_JSON="$STATE_JSON" python3 - "$field" <<'PY'
import json
import os
import sys

payload = json.loads(os.environ["STATE_JSON"])
for message in payload.get(sys.argv[1], []):
    print(f"  {message}")
PY
}

assert_state_check() {
  local check="$1" label="$2" observed
  observed="$(state_field "checks.$check" 2>/dev/null || printf 'missing')"
  if [ "$observed" = "true" ]; then
    echo "PASS $label"
    return 0
  fi
  echo "FAIL $label (observed=$observed)"
  return 1
}

echo "[SP-01/$S] exercise: provision one provider-backed Visa 4242 token through a SetupIntent"
if [ ! -f "$STATE_DRIVER" ]; then
  echo "[SP-01/$S] BLOCKED: deterministic state driver is missing: $STATE_DRIVER"
  exit 3
fi

state_output="$(wp_store "$S" eval-file - "$S" < "$STATE_DRIVER" 2>&1)"
state_rc=$?
if [ "$state_rc" -ne 0 ]; then
  echo "[SP-01/$S] BLOCKED: deterministic state driver could not run."
  printf '%s\n' "$state_output" | tail -20
  exit 3
fi

STATE_JSON="$(printf '%s\n' "$state_output" | normalize_state_payload)"
if [ -z "$STATE_JSON" ]; then
  echo "[SP-01/$S] BLOCKED: deterministic state driver emitted no valid payload."
  printf '%s\n' "$state_output" | tail -20
  exit 3
fi

payload_status="$(state_field status 2>/dev/null || printf 'invalid')"
if [ "$payload_status" = "blocked" ]; then
  echo "[SP-01/$S] prerequisites unavailable:"
  print_state_messages blockers
  echo "[SP-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi
if [ "$payload_status" != "pass" ] && [ "$payload_status" != "fail" ]; then
  echo "[SP-01/$S] BLOCKED: deterministic state payload has invalid status=$payload_status"
  exit 3
fi

echo "[SP-01/$S] captured user_id=$(state_field fixture.user_id 2>/dev/null || printf '?') token_id=$(state_field fixture.token_id 2>/dev/null || printf '?') payment_method_id=$(state_field fixture.payment_method_id 2>/dev/null || printf '?') setup_intent_id=$(state_field fixture.setup_intent_id 2>/dev/null || printf '?')"

failed=0
blocked=0
record_assertion assert_state_check exactly_one_woopayments_token "exactly one WooPayments token"
record_assertion assert_state_check visa_4242_token "saved token is Visa ending 4242"
record_assertion assert_state_check provider_payment_method_id "token preserves a provider pm_ PaymentMethod ID"
record_assertion assert_state_check customer_binding "token PaymentMethod is bound to the mapped WCPay customer"
record_assertion assert_state_check setup_intent_succeeded "provider SetupIntent succeeded"
record_assertion assert_state_check no_orders_created "add-payment-method created no order"
record_assertion assert_state_check no_charge_created "add-payment-method created no charge"
record_assertion assert_log_clean "$S"

if [ "$payload_status" = "fail" ]; then
  failed=1
fi

if [ "$blocked" -ne 0 ]; then
  echo "[SP-01/$S] deterministic verdict: BLOCKED"
  exit 3
fi

if [ "$failed" -ne 0 ]; then
  echo "[SP-01/$S] product/state mismatches:"
  print_state_messages errors
  echo "[SP-01/$S] deterministic verdict: FAIL"
  exit 1
fi

echo "[SP-01/$S] deterministic verdict: PASS"
exit 0
