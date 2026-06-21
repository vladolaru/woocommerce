#!/usr/bin/env bash
#
# N7a converted-currency money-path gate.
#
# Drives real converted-currency WooPayments charges on the reference plugin store and
# the native Core target, then reconciles both orders against Stripe raw source.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REF_WP=""
TARGET_WP=""
CURRENCY="GBP"
SKU="test-lab-beaker-001"
QUANTITY=2

usage() {
	cat >&2 <<'USAGE'
usage:
  converted-currency-gate.sh --ref "<ref wp>" --target "<target wp>" [--currency GBP] [--sku SKU] [--quantity N]

The gate configures the requested currency with a deterministic manual rate,
drives real orders, and fails if a requested converted-currency order remains
in the store default currency.
USAGE
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--currency=*) CURRENCY="${1#--currency=}"; shift ;;
		--currency) CURRENCY="${2:-}"; shift 2 ;;
		--sku=*) SKU="${1#--sku=}"; shift ;;
		--sku) SKU="${2:-}"; shift 2 ;;
		--quantity=*) QUANTITY="${1#--quantity=}"; shift ;;
		--quantity) QUANTITY="${2:-}"; shift 2 ;;
		--help|-h) usage; exit 0 ;;
		*) echo "Unknown argument: $1" >&2; usage; exit 2 ;;
	esac
done

CURRENCY="$(printf '%s' "$CURRENCY" | tr '[:lower:]' '[:upper:]')"
if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	echo "FAIL: both --ref and --target WP commands are required." >&2
	usage
	exit 2
fi
if ! printf '%s' "$CURRENCY" | grep -qE '^[A-Z]{3}$'; then
	echo "FAIL: --currency must be a three-letter ISO currency code." >&2
	exit 2
fi
if ! printf '%s' "$QUANTITY" | grep -qE '^[1-9][0-9]*$'; then
	echo "FAIL: --quantity must be a positive integer." >&2
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

last_json_line() {
	grep -E '^\{' | tail -1
}

configure_currency() {
	local wp_cmd="$1"
	local role="$2"
	local currency_lc
	local raw rc json
	currency_lc="$(printf '%s' "$CURRENCY" | tr '[:upper:]' '[:lower:]')"

	echo "Configure $role multi-currency: $CURRENCY"
	# Intentionally split the WP runner string, matching the rest of this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval "
		\$currency = '$CURRENCY';
		\$currency_lc = '$currency_lc';
		update_option( '_wcpay_feature_customer_multi_currency', '1', false );
		update_option( 'wcpay_multi_currency_setup_completed', true, false );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( \$currency ), false );
		update_option( 'wcpay_multi_currency_exchange_rate_' . \$currency_lc, 'manual', false );
		update_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc, '0.80', false );
		update_option( 'wcpay_multi_currency_price_rounding_' . \$currency_lc, '0', false );
		update_option( 'wcpay_multi_currency_price_charm_' . \$currency_lc, '0', false );
		WP_CLI::line( wp_json_encode( array(
			'store_currency' => get_option( 'woocommerce_currency' ),
			'enabled' => get_option( 'wcpay_multi_currency_enabled_currencies' ),
			'manual_rate' => get_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc ),
		) ) );
	" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		echo "FAIL ($role): could not configure multi-currency." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		exit 1
	fi
	echo "  ok: $json"
}

drive_charge() {
	local wp_cmd="$1"
	local role="$2"
	local native="$3"
	local raw rc json order_id order_currency charge_id

	echo "Drive $role converted charge" >&2
	if [ "$native" = "1" ]; then
		raw="$(WP="$wp_cmd" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --native --sku="$SKU" --quantity="$QUANTITY" --type=success --currency="$CURRENCY" 2>&1)"
	else
		raw="$(WP="$wp_cmd" bash "$SELF_DIR/flow-drive.sh" charge --deterministic --sku="$SKU" --quantity="$QUANTITY" --type=success --currency="$CURRENCY" 2>&1)"
	fi
	rc=$?
	json="$(printf '%s\n' "$raw" | last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		echo "FAIL ($role): converted charge did not complete." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		exit 1
	fi

	order_id="$(json_get "$json" order_id)"
	order_currency="$(json_get "$json" order_currency)"
	charge_id="$(json_get "$json" charge_id)"
	if [ -z "$order_id" ] || [ "$order_id" = "0" ]; then
		echo "FAIL ($role): converted charge emitted no order id." >&2
		printf '%s\n' "$json" >&2
		exit 1
	fi
	if [ "$order_currency" != "$CURRENCY" ]; then
		echo "FAIL ($role): requested $CURRENCY but order $order_id currency is ${order_currency:-<empty>}." >&2
		printf '%s\n' "$json" >&2
		exit 1
	fi

	echo "  ok: order $order_id charge ${charge_id:-<empty>} currency $order_currency" >&2
	printf '%s\n' "$order_id"
}

wait_order_money_meta() {
	local wp_cmd="$1"
	local role="$2"
	local order_id="$3"
	local attempt raw rc json missing_count

	echo "Wait for $role order $order_id money metadata"
	for attempt in $(seq 1 20); do
		# Intentionally split the WP runner string, matching the rest of this harness's WP="docker exec ..." convention.
		# shellcheck disable=SC2086
		raw="$($wp_cmd eval "
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
				'_wcpay_intent_currency',
				'_wcpay_multi_currency_stripe_exchange_rate',
				'_wcpay_multi_currency_order_exchange_rate',
				'_wcpay_multi_currency_order_default_currency',
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
		json="$(printf '%s\n' "$raw" | last_json_line)"
		if [ "$rc" -eq 0 ] && [ -n "$json" ]; then
			missing_count="$(python3 - "$json" <<'PY'
import json, sys
data = json.loads(sys.argv[1])
missing = data.get("missing", [])
print(len(missing) if isinstance(missing, list) else 1)
PY
)"
			if [ "$missing_count" = "0" ]; then
				echo "  ok: required money metadata is present"
				return 0
			fi
		fi
		sleep 2
	done

	echo "FAIL ($role): order $order_id did not expose required money metadata before reconciliation." >&2
	if [ -n "${json:-}" ]; then
		printf '%s\n' "$json" >&2
	else
		printf '%s\n' "$raw" | tail -20 >&2
	fi
	exit 1
}

reconcile_order() {
	local wp_cmd="$1"
	local role="$2"
	local order_id="$3"
	echo "Reconcile $role order $order_id against Stripe raw source"
	if ! WP="$wp_cmd" bash "$SELF_DIR/financial-reconcile.sh" "$order_id"; then
		echo "FAIL ($role): financial reconciliation failed for converted-currency order $order_id." >&2
		exit 1
	fi
}

echo "N7a converted-currency gate"
echo "  currency: $CURRENCY"
echo "  sku: $SKU"
echo "  quantity: $QUANTITY"
echo

configure_currency "$REF_WP" reference
configure_currency "$TARGET_WP" target
echo

ref_order_id="$(drive_charge "$REF_WP" reference 0)"
echo
target_order_id="$(drive_charge "$TARGET_WP" target 1)"
echo

wait_order_money_meta "$REF_WP" reference "$ref_order_id"
reconcile_order "$REF_WP" reference "$ref_order_id"
echo
wait_order_money_meta "$TARGET_WP" target "$target_order_id"
reconcile_order "$TARGET_WP" target "$target_order_id"

echo
echo "PASS: converted-currency charge and reconciliation passed on reference order $ref_order_id and target order $target_order_id."
