#!/usr/bin/env bash
set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS_DIR="$(cd "$SELF_DIR/.." && pwd)"
COMPARE="$HARNESS_DIR/financial-reconcile-normalize.py"
TMP_BASE="${TMPDIR:-$HARNESS_DIR/.tmp}"

mkdir -p "$TMP_BASE"
WORK_DIR="$(mktemp -d "$TMP_BASE/financial-reconcile-fixtures.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

write_matching_fixtures() {
	cat > "$WORK_DIR/wc.json" <<'JSON'
{
  "order_id": 101,
	  "status": "processing",
	  "total": "50.00",
	  "currency": "USD",
	  "store_currency": "USD",
	  "transaction_id": "pi_123",
  "charge_id": "ch_123",
  "intent_id": "pi_123",
  "payment_transaction_id": "txn_123",
  "transaction_fee": "1.75",
  "net": "48.25",
  "intent_currency": "usd",
  "multi_currency": {
    "stripe_exchange_rate": "1.2345",
    "order_exchange_rate": "1.2345",
    "order_default_currency": "USD"
  },
  "refunds": [
    {
      "id": 201,
      "amount": "12.00",
      "currency": "USD",
      "provider_refund_id": "re_123"
    }
  ],
  "dispute_id": "dp_123",
  "payout_id": "po_123"
}
JSON

	cat > "$WORK_DIR/charge.json" <<'JSON'
{
  "id": "ch_123",
  "amount": 5000,
  "amount_captured": 5000,
  "amount_refunded": 1200,
  "currency": "usd",
  "captured": true,
  "livemode": false,
  "payment_intent": "pi_123",
  "balance_transaction": "txn_123",
  "refunds": {
    "data": [
      {
        "id": "re_123",
        "amount": 1200,
        "currency": "usd"
      }
    ]
  },
  "dispute": "dp_123"
}
JSON

	cat > "$WORK_DIR/intent.json" <<'JSON'
{
  "id": "pi_123",
  "amount": 5000,
  "amount_capturable": 0,
  "amount_received": 5000,
  "currency": "usd",
  "status": "succeeded",
  "latest_charge": "ch_123"
}
JSON

	cat > "$WORK_DIR/balance.json" <<'JSON'
{
  "id": "txn_123",
  "amount": 5000,
  "fee": 175,
  "net": 4825,
  "currency": "usd",
  "exchange_rate": 1.2345,
  "payout": "po_123"
}
JSON

	cat > "$WORK_DIR/refunds.json" <<'JSON'
{
  "data": [
    {
      "id": "re_123",
      "amount": 1200,
      "currency": "usd",
      "charge": "ch_123"
    }
  ]
}
JSON

	cat > "$WORK_DIR/disputes.json" <<'JSON'
{
  "data": [
    {
      "id": "dp_123",
      "charge": "ch_123",
      "amount": 5000,
      "currency": "usd",
      "reason": "fraudulent",
      "status": "needs_response"
    }
  ]
}
JSON

	cat > "$WORK_DIR/payout.json" <<'JSON'
{
  "id": "po_123",
  "amount": 4825,
  "currency": "usd",
  "status": "paid"
}
JSON
}

run_compare() {
	python3 "$COMPARE" \
		--wc "$WORK_DIR/wc.json" \
		--charge "$WORK_DIR/charge.json" \
		--intent "$WORK_DIR/intent.json" \
		--balance-transaction "$WORK_DIR/balance.json" \
		--refunds "$WORK_DIR/refunds.json" \
		--disputes "$WORK_DIR/disputes.json" \
		--payout "$WORK_DIR/payout.json"
}

expect_pass() {
	local name="$1"
	if ! output="$(run_compare 2>&1)"; then
		echo "FAIL: $name expected PASS"
		printf '%s\n' "$output"
		exit 1
	fi
	echo "ok: $name"
}

expect_fail() {
	local name="$1"
	if output="$(run_compare 2>&1)"; then
		echo "FAIL: $name expected FAIL"
		printf '%s\n' "$output"
		exit 1
	fi
	echo "ok: $name"
}

expect_blocked() {
	local name="$1"
	local rc
	output="$(run_compare 2>&1)"
	rc=$?
	if [ "$rc" -ne 3 ]; then
		echo "FAIL: $name expected BLOCKED (exit 3), got exit $rc"
		printf '%s\n' "$output"
		exit 1
	fi
	echo "ok: $name"
}

write_converted_fixtures() {
	write_matching_fixtures
	python3 - "$WORK_DIR/wc.json" "$WORK_DIR/charge.json" "$WORK_DIR/intent.json" "$WORK_DIR/balance.json" "$WORK_DIR/refunds.json" <<'PY'
import json, sys
wc_path, charge_path, intent_path, balance_path, refunds_path = sys.argv[1:]
wc = json.load(open(wc_path))
wc["currency"] = "GBP"
wc["store_currency"] = "USD"
wc["transaction_fee"] = "2.18"
wc["net"] = "47.82"
wc["intent_currency"] = "gbp"
wc["multi_currency"]["stripe_exchange_rate"] = "1.3414"
wc["multi_currency"]["order_exchange_rate"] = "0.8"
wc["multi_currency"]["order_default_currency"] = "USD"
for refund in wc["refunds"]:
    refund["currency"] = "GBP"
with open(wc_path, "w") as handle:
    json.dump(wc, handle)
charge = json.load(open(charge_path))
charge["currency"] = "gbp"
charge["refunds"]["data"][0]["currency"] = "gbp"
with open(charge_path, "w") as handle:
    json.dump(charge, handle)
intent = json.load(open(intent_path))
intent["currency"] = "gbp"
with open(intent_path, "w") as handle:
    json.dump(intent, handle)
balance = json.load(open(balance_path))
balance["amount"] = 6707
balance["fee"] = 292
balance["net"] = 6415
balance["currency"] = "usd"
balance["exchange_rate"] = 1.3414
with open(balance_path, "w") as handle:
    json.dump(balance, handle)
refunds = json.load(open(refunds_path))
refunds["data"][0]["currency"] = "gbp"
with open(refunds_path, "w") as handle:
    json.dump(refunds, handle)
PY
}

write_matching_fixtures
expect_pass "matching charge/refund/fee/dispute/payout/multi-currency fixture"

# Structural live-money guard: a live-mode charge (or one that cannot prove test
# mode) must BLOCK before any money comparison — never PASS, never a mere FAIL.
write_matching_fixtures
python3 - "$WORK_DIR/charge.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["livemode"] = True
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_blocked "live-mode provider charge refuses to reconcile"

write_matching_fixtures
python3 - "$WORK_DIR/charge.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
del data["livemode"]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_blocked "provider charge without livemode flag cannot prove test mode"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["dispute_id"] = ""
data["notes"] = [
    {
        "content": "Payment has been disputed for $50.00 with reason \"Transaction unauthorized\".",
        "by_system": True,
    }
]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_pass "provider dispute with WC dispute note and no WC dispute id"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["dispute_id"] = ""
data["notes"] = [
    {
        "content": "Payment complete.",
        "by_system": True,
    }
]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "provider dispute with no WC dispute side effect"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["dispute_id"] = ""
data["status"] = "on-hold"
data["notes"] = [
    {
        "content": "Order status changed from Processing to On hold.",
        "by_system": True,
    }
]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "provider dispute with on-hold status but no dispute note"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["dispute_id"] = ""
data["notes"] = [
    {
        "content": "Payment has been disputed for $10.00 with reason \"Transaction unauthorized\".",
        "by_system": True,
    }
]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "provider dispute with mismatched WC dispute note amount"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["dispute_id"] = ""
data["notes"] = [
    {
        "content": "Payment has been disputed for $150.00 with reason \"Transaction unauthorized\".",
        "by_system": True,
    }
]
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "provider dispute with superstring WC dispute note amount"

write_converted_fixtures
expect_pass "multi-currency fee and net conversion fixture"

write_converted_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["multi_currency"]["order_exchange_rate"] = ""
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "converted order missing order exchange rate meta"

write_converted_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["multi_currency"]["order_default_currency"] = ""
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "converted order missing default currency meta"

write_converted_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["multi_currency"]["stripe_exchange_rate"] = ""
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "converted order missing provider exchange rate meta"

write_converted_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["intent_currency"] = ""
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "converted order missing intent currency meta"

write_matching_fixtures
python3 - "$WORK_DIR/charge.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["amount"] = 4900
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "charge amount mismatch"

write_matching_fixtures
python3 - "$WORK_DIR/balance.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["fee"] = 200
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "fee mismatch"

write_matching_fixtures
python3 - "$WORK_DIR/wc.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["refunds"][0]["provider_refund_id"] = ""
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "refund provider id missing"

write_matching_fixtures
python3 - "$WORK_DIR/charge.json" "$WORK_DIR/disputes.json" <<'PY'
import json, sys
charge_path, disputes_path = sys.argv[1], sys.argv[2]
charge = json.load(open(charge_path))
charge["dispute"] = "dp_different"
with open(charge_path, "w") as handle:
    json.dump(charge, handle)
disputes = json.load(open(disputes_path))
disputes["data"][0]["id"] = "dp_different"
with open(disputes_path, "w") as handle:
    json.dump(disputes, handle)
PY
expect_fail "dispute mismatch"

write_matching_fixtures
python3 - "$WORK_DIR/balance.json" <<'PY'
import json, sys
path = sys.argv[1]
data = json.load(open(path))
data["exchange_rate"] = 1.1111
with open(path, "w") as handle:
    json.dump(data, handle)
PY
expect_fail "multi-currency exchange-rate mismatch"

echo "PASS: financial reconciliation fixture tests passed."
