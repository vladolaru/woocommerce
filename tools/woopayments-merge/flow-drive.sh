#!/usr/bin/env bash
#
# Flow driver (WooPayments → core merge verification harness).
#
# The reusable layer that EXERCISES a payments surface deterministically and emits the
# affected order id(s) as structured output, so the gates (parity-diff / financial-reconcile /
# tracks-parity) have known fixtures to verify. It drives real operations through the dev-tools
# Test Lab (which calls the actual gateway: WC_Payment_Gateway_WCPay::process_payment / refund),
# so the orders + Stripe objects are linked exactly as a real flow would link them.
#
#   WP="docker exec -i wcpay_wp_default wp --allow-root" flow-drive.sh charge  --count=1 --type=success
#   WP="..."                                             flow-drive.sh refund  --type=partial
#   WP="..."                                             flow-drive.sh capture --deterministic --order-id=123
#   WP="..."                                             flow-drive.sh dispute --deterministic
#   WP="..."                                             flow-drive.sh payout
#
# Output: one JSON object per line, e.g. {"op":"charge","order_id":348,"charge_id":"ch_..."}.
# Capture the order ids and feed them to the gates:
#   ids=$(flow-drive.sh charge | sed -n 's/.*"order_id":\([0-9]*\).*/\1/p')
#   parity-diff.sh --self-check "$WP" $ids
#
# PRECONDITIONS: a connected test account + the event listener running (operator-run) so the
# provider events propagate. See HARNESS.md. Local-only; never the remote WPCOM sandbox.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP="${WP:-wp}"
OP="${1:-}"
shift || true
DETERMINISTIC=0
NATIVE=0
SKU="test-lab-beaker-001"
QUANTITY=2
ORDER_ID=""
TYPE="success"
MANUAL_CAPTURE=0
CURRENCY=""
PASSTHRU=()

while [ "$#" -gt 0 ]; do
	case "$1" in
		--deterministic) DETERMINISTIC=1; shift ;;
		--native) NATIVE=1; shift ;;
		--sku=*) SKU="${1#--sku=}"; shift ;;
		--sku) SKU="$2"; shift 2 ;;
		--quantity=*) QUANTITY="${1#--quantity=}"; shift ;;
		--quantity) QUANTITY="$2"; shift 2 ;;
		--order-id=*) ORDER_ID="${1#--order-id=}"; shift ;;
		--order-id) ORDER_ID="$2"; shift 2 ;;
		--manual-capture) MANUAL_CAPTURE=1; shift ;;
		--currency=*) CURRENCY="${1#--currency=}"; shift ;;
		--currency) CURRENCY="$2"; shift 2 ;;
		--type=*) TYPE="${1#--type=}"; PASSTHRU+=("$1"); shift ;;
		--type) TYPE="$2"; PASSTHRU+=("$1" "$2"); shift 2 ;;
		*) PASSTHRU+=("$1"); shift ;;
	esac
done

if [ -z "$OP" ]; then
	echo "usage: WP='<wp runner>' flow-drive.sh <charge|refund|capture|dispute|payout> [--count=N] [--type=...]" >&2
	exit 2
fi

# Map the singular op to the Test Lab's plural subcommand.
case "$OP" in
	charge)  SUB="charges" ;;
	refund)  SUB="refunds" ;;
	capture) SUB="" ;;
	dispute) SUB="disputes" ;;
	payout)  SUB="payouts" ;;
	*) echo "Unknown op: $OP (use charge|refund|capture|dispute|payout)" >&2; exit 2 ;;
esac

if [ "$DETERMINISTIC" -eq 1 ]; then
	if [ "$OP" = "charge" ]; then
		if [ "$NATIVE" -eq 1 ]; then
			case "$TYPE" in
				success) PAYMENT_METHOD="pm_card_visa" ;;
				instant) PAYMENT_METHOD="pm_card_bypassPending" ;;
				3ds) PAYMENT_METHOD="pm_card_threeDSecure2Required" ;;
				fail) PAYMENT_METHOD="pm_card_chargeDeclined" ;;
				dispute) PAYMENT_METHOD="pm_card_createDispute" ;;
				*) PAYMENT_METHOD="$TYPE" ;;
			esac
				raw="$($WP eval-file - "$SKU" "$QUANTITY" "$PAYMENT_METHOD" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-native-charge.php" 2>&1)"
			else
				raw="$($WP eval-file - "$SKU" "$QUANTITY" "$TYPE" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-deterministic-charge.php" 2>&1)"
			fi
		elif [ "$OP" = "dispute" ]; then
			if [ "$NATIVE" -eq 1 ]; then
				raw="$($WP eval-file - "$SKU" "$QUANTITY" "pm_card_createDispute" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-native-charge.php" 2>&1)"
			else
				raw="$($WP eval-file - "$SKU" "$QUANTITY" "dispute" "$MANUAL_CAPTURE" "$CURRENCY" < "$SELF_DIR/flow-drive-deterministic-charge.php" 2>&1)"
			fi
	elif [ "$OP" = "refund" ]; then
		if [ -z "$ORDER_ID" ]; then
			echo "FLOW-DRIVE FAIL ($OP): deterministic refund requires --order-id=<paid-order-id>." >&2
			exit 2
		fi
		raw="$($WP eval-file - "$ORDER_ID" "$TYPE" < "$SELF_DIR/flow-drive-refund.php" 2>&1)"
	elif [ "$OP" = "capture" ]; then
		if [ -z "$ORDER_ID" ]; then
			echo "FLOW-DRIVE FAIL ($OP): deterministic capture requires --order-id=<authorized-order-id>." >&2
			exit 2
		fi
		raw="$($WP eval-file - "$ORDER_ID" < "$SELF_DIR/flow-drive-capture.php" 2>&1)"
	else
		echo "FLOW-DRIVE FAIL ($OP): deterministic mode supports charge, refund, capture, and dispute." >&2
		exit 2
	fi
	rc=$?
	json="$(printf '%s\n' "$raw" | grep -vE 'textdomain|Debugging in WordPress|_load_textdomain|^Creating ')"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		echo "FLOW-DRIVE FAIL ($OP): deterministic operation did not complete." >&2
		printf '%s\n' "$raw" | tail -5 >&2
		exit 1
	fi

	emitted="$(printf '%s\n' "$json" | python3 -c '
import json
import sys

op = sys.argv[1]
raw = sys.stdin.read()
start = raw.find("{")
if start < 0:
    sys.exit(2)
payload = json.loads(raw[start:])
try:
    order_id = int(payload.get("order_id") or 0)
except (TypeError, ValueError):
    order_id = 0
if order_id <= 0:
    sys.exit(3)
payload["op"] = op
print(json.dumps(payload, separators=(",", ":")))
' "$OP")"
	if [ -z "$emitted" ]; then
		echo "FLOW-DRIVE FAIL ($OP): deterministic operation emitted no order_id." >&2
		printf '%s\n' "$json" >&2
		exit 1
	fi
	printf '%s\n' "$emitted"
	exit 0
fi

# Drive the operation through the Test Lab and capture its JSON.
if [ "$OP" = "capture" ]; then
	echo "FLOW-DRIVE FAIL ($OP): capture requires deterministic mode and --order-id=<authorized-order-id>." >&2
	exit 2
fi

if [ "${#PASSTHRU[@]}" -gt 0 ]; then
	raw="$($WP wcpay-dev test-lab "$SUB" --format=json "${PASSTHRU[@]}" 2>&1)"
else
	raw="$($WP wcpay-dev test-lab "$SUB" --format=json 2>&1)"
fi
rc=$?

# Strip WP notices/log lines so only the JSON payload remains.
json="$(printf '%s\n' "$raw" | grep -vE 'textdomain|Debugging in WordPress|_load_textdomain|^Creating ')"

if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
	echo "FLOW-DRIVE FAIL ($OP): the Test Lab command did not complete." >&2
	printf '%s\n' "$raw" | tail -5 >&2
	exit 1
fi

emitted="$(printf '%s\n' "$json" | python3 -c '
import json
import sys

op = sys.argv[1]
try:
    raw = sys.stdin.read()
    start = raw.find("{")
    if start < 0:
        sys.exit(2)
    payload = json.loads(raw[start:])
except json.JSONDecodeError:
    sys.exit(2)

results = payload.get("results")
if isinstance(results, dict):
    results = [results]
if not isinstance(results, list):
    results = [payload]

for item in results:
    if not isinstance(item, dict):
        continue
    if item.get("success") is False:
        continue
    try:
        order_id = int(item.get("order_id") or 0)
    except (TypeError, ValueError):
        order_id = 0
    if order_id <= 0:
        continue
    out = {"op": op, "order_id": order_id}
    charge_id = item.get("charge_id") or payload.get("charge_id")
    if isinstance(charge_id, str) and charge_id:
        out["charge_id"] = charge_id
    print(json.dumps(out, separators=(",", ":")))
' "$OP")"

if [ -z "$emitted" ]; then
	echo "FLOW-DRIVE FAIL ($OP): Test Lab emitted no successful order ids." >&2
	printf '%s\n' "$json" >&2
	exit 1
fi

printf '%s\n' "$emitted"

exit 0
