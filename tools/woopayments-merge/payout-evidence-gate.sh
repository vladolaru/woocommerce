#!/usr/bin/env bash
#
# N7a payout evidence gate.
#
# This gate is intentionally honest: it verifies provider payout creation/readability and
# order charge reconciliation, but it exits BLOCKED when manual Stripe test-mode payouts
# do not expose per-order membership through Stripe raw source.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WP_CMD=""
LABEL="store"
NATIVE=0
SKU="test-lab-beaker-001"
QUANTITY=2

usage() {
	cat >&2 <<'USAGE'
usage:
  payout-evidence-gate.sh --wp "<wp>" [--label reference] [--native]

The gate drives an instant-balance charge, creates a Test Lab payout from the
available balance when possible, reads the payout through Stripe CLI, and
attempts raw-source membership lookup. Manual test-mode payouts may block
membership lookup.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--wp=*) WP_CMD="${1#--wp=}"; shift ;;
		--wp) WP_CMD="${2:-}"; shift 2 ;;
		--label=*) LABEL="${1#--label=}"; shift ;;
		--label) LABEL="${2:-}"; shift 2 ;;
		--native) NATIVE=1; shift ;;
		--sku=*) SKU="${1#--sku=}"; shift ;;
		--sku) SKU="${2:-}"; shift 2 ;;
		--quantity=*) QUANTITY="${1#--quantity=}"; shift ;;
		--quantity) QUANTITY="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

if [ -z "$WP_CMD" ]; then
	echo "FAIL: --wp is required." >&2
	usage
	exit 2
fi

json_get() {
	python3 - "$1" "$2" <<'PY'
import json, sys
raw, key = sys.argv[1], sys.argv[2]
try:
    data = json.loads(raw)
except Exception:
    sys.exit(1)
value = data
for part in key.split("."):
    if isinstance(value, dict) and part in value:
        value = value[part]
    else:
        value = ""
        break
if value is not None:
    print(value)
PY
}

extract_payout_id() {
	python3 -c '
import json, sys
raw = sys.stdin.read()
start = raw.find("{")
if start < 0:
    sys.exit(1)
data = json.loads(raw[start:])
items = data.get("results", data)
if isinstance(items, dict):
    items = [items]
for item in items if isinstance(items, list) else []:
    if not isinstance(item, dict):
        continue
    payout_id = item.get("payout_id") or item.get("id")
    if item.get("success") is not False and isinstance(payout_id, str) and payout_id.startswith("po_"):
        print(payout_id)
        sys.exit(0)
sys.exit(1)
'
}

resolve_account() {
	local raw
	# Intentionally split the WP runner string, matching the rest of this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($WP_CMD eval '
		if ( class_exists( "WC_Payments" ) && method_exists( "WC_Payments", "get_account_service" ) ) {
			echo WC_Payments::get_account_service()->get_stripe_account_id();
			return;
		}
		if ( function_exists( "wc_get_container" ) && class_exists( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class ) ) {
			echo wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService::class )->get_account_id();
			return;
		}
	' 2>/dev/null | tr -d '[:space:]')"
	if [ -z "$raw" ]; then
		echo "BLOCKED ($LABEL): could not resolve connected Stripe account." >&2
		exit 3
	fi
	printf '%s\n' "$raw"
}

wait_order_money_meta() {
	local order_id="$1"
	local attempt raw rc json missing_count

	echo "Wait for order $order_id charge money metadata"
	for attempt in $(seq 1 20); do
		# Intentionally split the WP runner string, matching the rest of this harness's WP="docker exec ..." convention.
		# shellcheck disable=SC2086
		raw="$($WP_CMD eval "
			\$order = wc_get_order( $order_id );
			if ( ! \$order ) {
				WP_CLI::line( wp_json_encode( array( 'missing' => array( 'order' ) ) ) );
				return;
			}
			\$keys = array(
				'_charge_id',
				'_wcpay_payment_transaction_id',
				'_wcpay_transaction_fee',
				'_wcpay_net',
			);
			\$meta = array();
			\$missing = array();
			foreach ( \$keys as \$key ) {
				\$value = (string) \$order->get_meta( \$key, true );
				\$meta[ \$key ] = \$value;
				if ( '' === \$value ) {
					\$missing[] = \$key;
				}
			}
			WP_CLI::line( wp_json_encode( array( 'missing' => \$missing, 'meta' => \$meta ) ) );
		" 2>&1)"
		rc=$?
		json="$(printf '%s\n' "$raw" | grep -E '^\{' | tail -1)"
		if [ "$rc" -eq 0 ] && [ -n "$json" ]; then
			missing_count="$(python3 - "$json" <<'PY'
import json, sys
data = json.loads(sys.argv[1])
missing = data.get("missing", [])
print(len(missing) if isinstance(missing, list) else 1)
PY
)"
			if [ "$missing_count" = "0" ]; then
				echo "  ok: required charge money metadata is present"
				return 0
			fi
		fi
		sleep 2
	done

	echo "FAIL ($LABEL): order $order_id did not expose required charge money metadata before reconciliation." >&2
	if [ -n "${json:-}" ]; then
		printf '%s\n' "$json" >&2
	else
		printf '%s\n' "$raw" | tail -20 >&2
	fi
	exit 1
}

echo "N7a payout evidence gate"
echo "  label: $LABEL"
echo "  native charge driver: $NATIVE"
echo

echo "Preflight: Stripe CLI raw-source access"
if ! stripe charges list --limit 1 --color off >/dev/null 2>&1; then
	echo "BLOCKED: Stripe CLI raw-source read is unavailable." >&2
	exit 3
fi
echo "  ok"

account_id="$(resolve_account)"
echo "Connected account: $account_id"

echo "Drive instant-balance charge"
if [ "$NATIVE" -eq 1 ]; then
	charge_raw="$(WP="$WP_CMD" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --native --sku="$SKU" --quantity="$QUANTITY" --type=instant 2>&1)"
else
	charge_raw="$(WP="$WP_CMD" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --sku="$SKU" --quantity="$QUANTITY" --type=instant 2>&1)"
fi
charge_rc=$?
charge_json="$(printf '%s\n' "$charge_raw" | grep -E '^\{' | tail -1)"
if [ "$charge_rc" -ne 0 ] || [ -z "$charge_json" ]; then
	echo "FAIL ($LABEL): instant-balance charge did not complete." >&2
	printf '%s\n' "$charge_raw" | tail -20 >&2
	exit 1
fi
order_id="$(json_get "$charge_json" order_id)"
charge_id="$(json_get "$charge_json" charge_id)"
if [ -z "$order_id" ] || [ "$order_id" = "0" ] || [ -z "$charge_id" ]; then
	echo "FAIL ($LABEL): instant-balance charge did not emit order and charge evidence." >&2
	printf '%s\n' "$charge_json" >&2
	exit 1
fi
echo "  ok: order $order_id charge $charge_id"

wait_order_money_meta "$order_id"

echo "Reconcile charge before payout membership check"
if ! WP="$WP_CMD" bash "$SELF_DIR/financial-reconcile.sh" "$order_id"; then
	echo "FAIL ($LABEL): order $order_id failed financial reconciliation before payout membership check." >&2
	exit 1
fi

echo
echo "Create Test Lab payout"
# Intentionally split the WP runner string, matching the rest of this harness's WP="docker exec ..." convention.
# shellcheck disable=SC2086
payout_raw="$($WP_CMD wcpay-dev test-lab payouts --count=1 --format=json 2>&1)"
payout_rc=$?
if [ "$payout_rc" -ne 0 ]; then
	echo "BLOCKED ($LABEL): Test Lab payout command is unavailable or unsupported in this runtime." >&2
	printf '%s\n' "$payout_raw" | tail -20 >&2
	exit 3
fi
payout_id="$(printf '%s\n' "$payout_raw" | extract_payout_id)"
if [ -z "$payout_id" ]; then
	echo "BLOCKED ($LABEL): Test Lab payout command emitted no payout id." >&2
	printf '%s\n' "$payout_raw" >&2
	exit 3
fi
echo "  ok: payout $payout_id"

echo "Read provider payout $payout_id"
payout_source="$(stripe payouts retrieve "$payout_id" --stripe-account "$account_id" --color off 2>&1)"
if [ "$?" -ne 0 ]; then
	echo "BLOCKED ($LABEL): Stripe CLI could not retrieve payout $payout_id." >&2
	printf '%s\n' "$payout_source" | head -5 >&2
	exit 3
fi
echo "  ok"

echo "Attempt payout membership lookup"
membership_raw="$(stripe balance_transactions list --payout "$payout_id" --limit 100 --stripe-account "$account_id" --color off 2>&1)"
membership_rc=$?
if [ "$membership_rc" -ne 0 ]; then
	if printf '%s' "$membership_raw" | grep -qi "automatic"; then
		echo "BLOCKED: manual Stripe test-mode payout $payout_id does not expose per-order payout membership through balance_transactions --payout." >&2
		echo "         Provider payout creation/readability and order charge reconciliation were verified; per-order payout linkage remains unverified." >&2
		exit 3
	fi
	echo "BLOCKED ($LABEL): payout membership lookup failed." >&2
	printf '%s\n' "$membership_raw" | head -5 >&2
	exit 3
fi

if ! printf '%s\n' "$membership_raw" | grep -q "$charge_id"; then
	echo "BLOCKED ($LABEL): payout membership source did not include charge $charge_id." >&2
	echo "         Provider payout creation/readability and order charge reconciliation were verified; Test Lab payout membership is not deterministic enough to assert per-order linkage." >&2
	exit 3
fi

echo
echo "PASS: payout $payout_id is source-readable and includes charge $charge_id for order $order_id."
