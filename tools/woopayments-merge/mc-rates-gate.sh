#!/usr/bin/env bash
#
# Native WooPayments multi-currency automatic-rates gate.
#
# Configures automatic multi-currency rates on the reference plugin store and
# native target, refreshes the shared WooPayments cache option, and compares the
# rate payloads for the requested currency pair(s).

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

REF_WP=""
TARGET_WP=""
CURRENCY_FROM="USD"
CURRENCIES_TO_CSV="GBP"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/mc-rates-gate"
PRINT_PLAN=0

usage() {
	cat >&2 <<'USAGE'
usage:
  mc-rates-gate.sh --ref "<ref wp>" --target "<target wp>" [options]

Options:
  --ref "<wp>"                  Reference store WP-CLI command.
  --target "<wp>"               Target store WP-CLI command.
  --currency-from USD           Store/default currency to refresh rates from. Default: USD.
  --currencies-to GBP,EUR       Comma-separated target currencies. Default: GBP.
  --out-dir <path>              Evidence output directory.
  --print-plan                  Print the normalized rate probe plan as JSON, then exit.
  -h, --help                    Show this help.
USAGE
}

progress() {
	printf 'MC rates gate: %s\n' "$*" >&2
}

fail_usage() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--currency-from=*) CURRENCY_FROM="${1#--currency-from=}"; shift ;;
		--currency-from) CURRENCY_FROM="${2:-}"; shift 2 ;;
		--currencies-to=*) CURRENCIES_TO_CSV="${1#--currencies-to=}"; shift ;;
		--currencies-to) CURRENCIES_TO_CSV="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) fail_usage "unknown argument: $1" ;;
	esac
done

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	fail_usage "both --ref and --target are required."
fi

CURRENCY_FROM="$(printf '%s' "$CURRENCY_FROM" | tr '[:lower:]' '[:upper:]' | tr -d '[:space:]')"
if ! printf '%s' "$CURRENCY_FROM" | grep -qE '^[A-Z]{3}$'; then
	fail_usage "--currency-from must be a three-letter ISO currency code."
fi

normalize_currencies_to() {
	python3 - "$CURRENCIES_TO_CSV" <<'PY'
import sys

raw = sys.argv[1]
currencies = []
seen = set()
for part in raw.split(","):
    code = part.strip().upper()
    if not code:
        continue
    if len(code) != 3 or not code.isalpha():
        raise SystemExit(f"invalid currency code: {code}")
    if code not in seen:
        seen.add(code)
        currencies.append(code)
if not currencies:
    raise SystemExit("no target currencies")
print(",".join(currencies))
PY
}

if ! CURRENCIES_TO_CSV="$(normalize_currencies_to 2>/dev/null)"; then
	fail_usage "--currencies-to must contain at least one three-letter ISO currency code."
fi

print_plan() {
	python3 - "$REF_WP" "$TARGET_WP" "$CURRENCY_FROM" "$CURRENCIES_TO_CSV" <<'PY'
import json
import sys

ref, target, currency_from, currencies_to = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_mc_rates_gate_plan.v1",
            "ref_wp": ref,
            "target_wp": target,
            "currency_from": currency_from,
            "currencies_to": currencies_to.split(","),
        },
        sort_keys=True,
    )
)
PY
}

if [ "$PRINT_PLAN" -eq 1 ]; then
	print_plan
	exit 0
fi

mkdir -p "$OUT_DIR"
failures_file="$OUT_DIR/mc-rates-failures.txt"
: > "$failures_file"

last_json_line() {
	grep -E '^\{' | tail -1
}

wp_eval_json() {
	local wp_cmd="$1"
	local role="$2"
	local php="$3"
	local raw rc json

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval "$php" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | last_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf 'FAIL: %s WP probe failed.\n' "$role" >&2
		printf '%s\n' "$raw" | tail -40 >&2
		return 1
	fi

	printf '%s\n' "$json"
}

php_array_literal() {
	python3 - "$CURRENCIES_TO_CSV" <<'PY'
import sys

items = [item for item in sys.argv[1].split(",") if item]
print("array( " + ", ".join(repr(item) for item in items) + " )")
PY
}

write_rollup() {
	local status="$1"
	local reference_json="$2"
	local target_json="$3"
	local failures_file="${4:-}"

	python3 - "$OUT_DIR/mc-rates-gate.json" "$status" "$reference_json" "$target_json" "$failures_file" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
status = sys.argv[2]
failures_file = sys.argv[5]

def decode(raw):
    if not raw:
        return {}
    try:
        return json.loads(raw)
    except Exception:
        return {"raw": raw}

payload = {
    "schema": "woopayments_mc_rates_gate_result.v1",
    "status": status,
    "reference": decode(sys.argv[3]),
    "target": decode(sys.argv[4]),
    "failures": Path(failures_file).read_text(encoding="utf-8").splitlines() if failures_file else [],
}
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
PY
}

assert_target_native_owner() {
	local raw
	local owner

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	if ! raw="$($TARGET_WP wc-native-payments status 2>&1)"; then
		printf '%s\n' "target native payments status probe failed: $raw" >> "$failures_file"
		return 1
	fi

	owner="$(printf '%s\n' "$raw" | awk -F': ' '/^Owner:/ { print $2; exit }' | tr -d '\r')"
	if [ "$owner" != "native" ]; then
		printf '%s\n' "target native payments owner is not native: ${owner:-unknown}" >> "$failures_file"
		return 1
	fi
}

CURRENCIES_TO_PHP="$(php_array_literal)"

configure_php="$(cat <<PHP
/* mc_rates_configure */
\$currency_from = '$CURRENCY_FROM';
\$currencies_to = $CURRENCIES_TO_PHP;
update_option( 'woocommerce_currency', \$currency_from, false );
update_option( '_wcpay_feature_customer_multi_currency', '1', false );
update_option( 'wcpay_multi_currency_setup_completed', true, false );
update_option( 'wcpay_multi_currency_enabled_currencies', \$currencies_to, false );
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	update_option( 'wcpay_multi_currency_exchange_rate_' . \$currency_lc, 'automatic', false );
	delete_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc );
}
delete_option( 'wcpay_multi_currency_cached_currencies' );
wp_cache_delete( 'wcpay_multi_currency_cached_currencies', 'options' );
WP_CLI::line( wp_json_encode( array(
	'configured' => true,
	'currency_from' => \$currency_from,
	'currencies_to' => \$currencies_to,
) ) );
PHP
)"

build_php="$(cat <<'PHP'
/* mc_rates_build_state */
$provider_id = '';
$state_built = false;
if ( class_exists( '\Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory' ) ) {
	$registry = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory::class )->create();
	$provider = $registry->get_available_provider();
	$provider_id = $provider ? $provider->get_id() : '';
	wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class )->create()->build();
	$state_built = true;
} elseif ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
	$multi_currency = WC_Payments_Multi_Currency();
	if ( $multi_currency && method_exists( $multi_currency, 'get_cached_currencies' ) ) {
		$multi_currency->get_cached_currencies();
		$provider_id = 'woopayments';
		$state_built = true;
	}
}
WP_CLI::line( wp_json_encode( array(
	'provider' => $provider_id,
	'state_built' => $state_built,
) ) );
PHP
)"

inspect_php="$(cat <<PHP
/* mc_rates_inspect_rates */
\$role = '';
\$provider_id = '';
if ( class_exists( '\Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory' ) ) {
	\$registry = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory::class )->create();
	\$provider = \$registry->get_available_provider();
	\$provider_id = \$provider ? \$provider->get_id() : '';
} elseif ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
	\$provider_id = 'woopayments';
}
\$raw_cache = get_option( 'wcpay_multi_currency_cached_currencies', array() );
\$data = is_array( \$raw_cache ) && isset( \$raw_cache['data'] ) && is_array( \$raw_cache['data'] )
	? \$raw_cache['data']
	: \$raw_cache;
\$currencies = is_array( \$data ) && isset( \$data['currencies'] ) && is_array( \$data['currencies'] )
	? \$data['currencies']
	: array();
\$updated = is_array( \$data ) && isset( \$data['updated'] ) ? \$data['updated'] : null;
\$requested = $CURRENCIES_TO_PHP;
\$rates = array();
\$missing = array();
foreach ( \$requested as \$currency_code ) {
	\$currency_code = strtoupper( (string) \$currency_code );
	\$rate = \$currencies[ \$currency_code ] ?? \$currencies[ strtolower( \$currency_code ) ] ?? null;
	if ( ! is_numeric( \$rate ) || 0 >= (float) \$rate ) {
		\$missing[] = \$currency_code;
		continue;
	}
	\$rates[ \$currency_code ] = (string) \$rate;
}
WP_CLI::line( wp_json_encode( array(
	'provider' => \$provider_id,
	'cache_option' => 'wcpay_multi_currency_cached_currencies',
	'updated' => \$updated,
	'rates' => \$rates,
	'missing' => \$missing,
) ) );
PHP
)"

if ! assert_target_native_owner; then
	write_rollup "fail" "" "" "$failures_file"
	while IFS= read -r failure; do
		printf 'FAIL: %s\n' "$failure" >&2
	done < "$failures_file"
	exit 1
fi

progress "configure reference automatic rates"
if ! wp_eval_json "$REF_WP" "reference" "$configure_php" >/dev/null; then
	exit 1
fi
progress "configure target automatic rates"
if ! wp_eval_json "$TARGET_WP" "target" "$configure_php" >/dev/null; then
	exit 1
fi

progress "refresh reference rate cache"
if ! wp_eval_json "$REF_WP" "reference" "$build_php" >/dev/null; then
	exit 1
fi
progress "refresh target rate cache"
if ! wp_eval_json "$TARGET_WP" "target" "$build_php" >/dev/null; then
	exit 1
fi

reference_json="$(wp_eval_json "$REF_WP" "reference" "$inspect_php")" || exit 1
target_json="$(wp_eval_json "$TARGET_WP" "target" "$inspect_php")" || exit 1

python3 - "$reference_json" "$target_json" > "$failures_file" <<'PY'
import json
import math
import sys

reference = json.loads(sys.argv[1])
target = json.loads(sys.argv[2])
failures = []

if target.get("provider") != "woopayments":
    failures.append("target provider is not woopayments")

for role, payload in (("reference", reference), ("target", target)):
    missing = payload.get("missing") or []
    if missing:
        failures.append(f"{role} cache is missing rates for {','.join(missing)}")
    if not payload.get("updated"):
        failures.append(f"{role} cache has no updated timestamp")

reference_rates = reference.get("rates") or {}
target_rates = target.get("rates") or {}
for currency, reference_rate in sorted(reference_rates.items()):
    if currency not in target_rates:
        failures.append(f"target cache is missing {currency}")
        continue
    try:
        ref_value = float(reference_rate)
        target_value = float(target_rates[currency])
    except Exception:
        failures.append(f"non-numeric rate for {currency}")
        continue
    if not math.isclose(ref_value, target_value, rel_tol=0.000001, abs_tol=0.000001):
        failures.append(f"rate mismatch for {currency}: reference={reference_rate} target={target_rates[currency]}")

for failure in failures:
    print(failure)
PY

failure_count="$(grep -c . "$failures_file" 2>/dev/null || true)"
if [ "$failure_count" -gt 0 ]; then
	write_rollup "fail" "$reference_json" "$target_json" "$failures_file"
	while IFS= read -r failure; do
		printf 'FAIL: %s\n' "$failure" >&2
	done < "$failures_file"
	exit 1
fi

write_rollup "pass" "$reference_json" "$target_json"
printf 'PASS: native multi-currency rate transport matched reference rates.\n'
