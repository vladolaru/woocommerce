#!/usr/bin/env bash
#
# Native WooPayments multi-currency automatic-rates gate.
#
# Reads the reference rate through the standalone plugin API client without
# changing that store, then proves the native target cache was regenerated and
# reconciles a public target-store product price against the observed rate.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	printf 'FAIL: local runner safety library is missing: %s\n' "$LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"

REF_WP=""
TARGET_WP=""
REF_URL=""
TARGET_URL=""
CURRENCY_FROM="USD"
CURRENCIES_TO_CSV="GBP"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/mc-rates-gate"
PLAYWRIGHT_SCRIPT_RUNNER_BIN="${PLAYWRIGHT_SCRIPT_RUNNER_BIN:-$SELF_DIR/playwright-script-runner.mjs}"
PRINT_PLAN=0
STOREFRONT_PRODUCT_ID=""
STOREFRONT_PRODUCT_SKU=""
STOREFRONT_PRODUCT_CLEANUP_REQUIRED=0
PRODUCT_CLEANUP_JSON=""
TARGET_SNAPSHOT_FILE=""
TARGET_RESTORE_REQUIRED=0
FINALIZATION_DONE=0
FINALIZATION_RUNNING=0
FINALIZATION_JSON=""
STORE_IDENTITIES_JSON=""

usage() {
	cat >&2 <<'USAGE'
usage:
  mc-rates-gate.sh --ref "<ref wp>" --target "<target wp>" [options]

Options:
  --ref "<wp>"                  Reference store WP-CLI command.
  --target "<wp>"               Target store WP-CLI command.
  --ref-url <url>               Expected local URL for the reference store.
  --target-url <url>            Expected local URL for the target store.
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
		--ref-url=*) REF_URL="${1#--ref-url=}"; shift ;;
		--ref-url) REF_URL="${2:-}"; shift 2 ;;
		--target-url=*) TARGET_URL="${1#--target-url=}"; shift ;;
		--target-url) TARGET_URL="${2:-}"; shift 2 ;;
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

if [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ] || [ -z "$REF_URL" ] || [ -z "$TARGET_URL" ]; then
	fail_usage "--ref, --target, --ref-url, and --target-url are required."
fi

normalize_local_site_url() {
	woopayments_normalize_local_url "$1"
}

if ! REF_URL="$(normalize_local_site_url "$REF_URL")"; then
	fail_usage "--ref-url must be an explicit local HTTP(S) URL."
fi
if ! TARGET_URL="$(normalize_local_site_url "$TARGET_URL")"; then
	fail_usage "--target-url must be an explicit local HTTP(S) URL."
fi
if [ "$REF_URL" = "$TARGET_URL" ]; then
	fail_usage "--ref-url and --target-url must identify distinct local stores."
fi

validate_local_wp_runner() {
	woopayments_validate_local_wp_runner "$1"
}

if ! runner_error="$(validate_local_wp_runner "$REF_WP")"; then
	fail_usage "unsafe --ref WP runner: $runner_error"
fi
if ! runner_error="$(validate_local_wp_runner "$TARGET_WP")"; then
	fail_usage "unsafe --target WP runner: $runner_error"
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
	python3 - "$REF_WP" "$TARGET_WP" "$REF_URL" "$TARGET_URL" "$CURRENCY_FROM" "$CURRENCIES_TO_CSV" <<'PY'
import json
import sys

ref, target, ref_url, target_url, currency_from, currencies_to = sys.argv[1:]
print(
    json.dumps(
        {
            "schema": "woopayments_mc_rates_gate_plan.v1",
            "ref_wp": ref,
            "target_wp": target,
            "ref_url": ref_url,
            "target_url": target_url,
            "currency_from": currency_from,
            "currencies_to": currencies_to.split(","),
            "checks": [
                "local-only WP runner validation before invocation",
                "read-only site identity verification before target mutation",
                "reference API client oracle remains read-only",
                "target transactional option snapshot and exact restoration",
                "cache regeneration after deletion",
                "fresh provider/cache timestamps bounded by this run",
                "reference and target rate parity",
                "Playwright storefront product price derived from target rate",
                "independent JSON artifacts validated by the rollup",
            ],
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
for stale_artifact in \
	"$OUT_DIR/mc-rates-gate.json" \
	"$OUT_DIR/store-identities.json" \
	"$OUT_DIR/reference-rate-refresh.json" \
	"$OUT_DIR/target-rate-refresh.json" \
	"$OUT_DIR/target-storefront-price.json" \
	"$OUT_DIR/mc-rates-cleanup-restore.json" \
	"$OUT_DIR/target-option-snapshot.json" \
	"$OUT_DIR/mc-rates-storefront-driver.mjs"; do
	rm -f -- "$stale_artifact"
done
TARGET_SNAPSHOT_FILE="$OUT_DIR/target-option-snapshot.json"
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

wp_eval_json_stdin() {
	local wp_cmd="$1"
	local role="$2"
	local php="$3"
	local input_file="$4"
	local raw rc json

	# Intentionally split the WP runner string, matching this harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($wp_cmd eval "$php" < "$input_file" 2>&1)"
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

site_identity_php="$(cat <<'PHP'
/* mc_rates_site_identity */
global $wpdb;
$home_url = untrailingslashit( home_url( '/' ) );
$site_url = untrailingslashit( site_url( '/' ) );
$identity_material = wp_json_encode(
	array(
		'database' => defined( 'DB_NAME' ) ? DB_NAME : '',
		'database_host' => defined( 'DB_HOST' ) ? DB_HOST : '',
		'table_prefix' => (string) $wpdb->prefix,
		'blog_id' => (int) get_current_blog_id(),
		'home_url' => $home_url,
		'site_url' => $site_url,
	),
	JSON_UNESCAPED_SLASHES
);
WP_CLI::line(
	wp_json_encode(
		array(
			'schema' => 'woopayments_mc_site_identity.v1',
			'role' => $identity_role,
			'home_url' => $home_url,
			'site_url' => $site_url,
			'fingerprint' => hash( 'sha256', (string) $identity_material ),
		)
	)
);
PHP
)"

probe_site_identity() {
	local wp_cmd="$1"
	local role="$2"
	local php

	php="\$identity_role = '$role';
$site_identity_php"
	wp_eval_json "$wp_cmd" "$role site identity" "$php"
}

validate_site_identities() {
	local reference_json="$1"
	local target_json="$2"
	local reference_home_url reference_site_url target_home_url target_site_url
	local reference_home_matches=false
	local reference_site_matches=false
	local target_home_matches=false
	local target_site_matches=false

	reference_home_url="$(json_field "$reference_json" home_url 2>/dev/null || true)"
	reference_site_url="$(json_field "$reference_json" site_url 2>/dev/null || true)"
	target_home_url="$(json_field "$target_json" home_url 2>/dev/null || true)"
	target_site_url="$(json_field "$target_json" site_url 2>/dev/null || true)"
	if woopayments_local_url_matches "$REF_WP" "$reference_home_url" "$REF_URL"; then
		reference_home_matches=true
	fi
	if woopayments_local_url_matches "$REF_WP" "$reference_site_url" "$REF_URL"; then
		reference_site_matches=true
	fi
	if woopayments_local_url_matches "$TARGET_WP" "$target_home_url" "$TARGET_URL"; then
		target_home_matches=true
	fi
	if woopayments_local_url_matches "$TARGET_WP" "$target_site_url" "$TARGET_URL"; then
		target_site_matches=true
	fi

	python3 - "$OUT_DIR/store-identities.json" "$reference_json" "$target_json" "$reference_home_matches" "$reference_site_matches" "$target_home_matches" "$target_site_matches" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
reference = json.loads(sys.argv[2])
target = json.loads(sys.argv[3])
url_matches = {
    "reference": {"home_url": sys.argv[4] == "true", "site_url": sys.argv[5] == "true"},
    "target": {"home_url": sys.argv[6] == "true", "site_url": sys.argv[7] == "true"},
}

code = ""
message = ""
for role, identity in (("reference", reference), ("target", target)):
    if identity.get("schema") != "woopayments_mc_site_identity.v1" or identity.get("role") != role:
        code = "store_identity_invalid"
        message = f"{role} store identity response was invalid"
        break
    if not str(identity.get("fingerprint") or ""):
        code = "store_identity_invalid"
        message = f"{role} store identity fingerprint was missing"
        break
    if not any(url_matches[role].values()):
        code = "store_identity_url_mismatch"
        message = f"{role} WP runner did not resolve to its expected local URL"
        break

if not code and reference.get("fingerprint") == target.get("fingerprint"):
    code = "store_identity_collision"
    message = "reference and target WP runners resolved to the same store"

payload = {
    "schema": "woopayments_mc_store_identities.v1",
    "status": "blocked" if code else "pass",
    "reference": reference,
    "target": target,
    "failure_details": [] if not code else [{
        "code": code,
        "classification": "environment",
        "scope": "store_identity",
        "message": message,
    }],
}
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(json.dumps(payload, sort_keys=True))
PY
}

write_rollup() {
	local status="$1"
	local reference_json="$2"
	local target_json="$3"
	local failures_file="${4:-}"
	local diagnostics_json="${5:-}"
	local failure_details_json="${6:-[]}"

	python3 - "$OUT_DIR/mc-rates-gate.json" "$status" "$reference_json" "$target_json" "$failures_file" "$diagnostics_json" "$failure_details_json" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
status = sys.argv[2]
failures_file = sys.argv[5]
diagnostics_raw = sys.argv[6]
failure_details_raw = sys.argv[7]

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
    "failure_details": decode(failure_details_raw),
}
diagnostics = decode(diagnostics_raw)
if diagnostics:
    payload["diagnostics"] = diagnostics

evidence_files = {}
for key, filename in (
    ("store_identities", "store-identities.json"),
    ("reference_rate_refresh", "reference-rate-refresh.json"),
    ("target_rate_refresh", "target-rate-refresh.json"),
    ("target_storefront_price", "target-storefront-price.json"),
    ("cleanup_restore", "mc-rates-cleanup-restore.json"),
):
    evidence_path = path.parent / filename
    if evidence_path.is_file():
        evidence_files[key] = {
            "path": filename,
            "sha256": hashlib.sha256(evidence_path.read_bytes()).hexdigest(),
        }
payload["evidence_files"] = evidence_files
path.parent.mkdir(parents=True, exist_ok=True)
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
PY
}

json_field() {
	python3 - "$1" "$2" <<'PY'
import json
import sys

value = json.loads(sys.argv[1])
for part in sys.argv[2].split("."):
    if not isinstance(value, dict) or part not in value:
        raise SystemExit(1)
    value = value[part]
if isinstance(value, bool):
    print("true" if value else "false")
elif value is not None:
    print(value)
PY
}

write_refresh_evidence() {
	local role="$1"
	local configure_json="$2"
	local build_json="$3"
	local observation_json="$4"
	local path="$OUT_DIR/${role}-rate-refresh.json"

	python3 - "$path" "$role" "$configure_json" "$build_json" "$observation_json" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
role = sys.argv[2]
configure = json.loads(sys.argv[3])
build = json.loads(sys.argv[4])
observation = json.loads(sys.argv[5])

def number(value):
    if isinstance(value, bool):
        return None
    try:
        return float(value)
    except (TypeError, ValueError):
        return None

start = number(configure.get("refresh_started_at"))
fetched = number(observation.get("fetched"))
updated = number(observation.get("updated"))
observed = number(observation.get("observed_at"))
cache_absent = configure.get("cache_absent_after_delete") is True
state_built = build.get("state_built") is True
provider = observation.get("provider")
missing = observation.get("missing")
rates = observation.get("rates")

checks = {
    "configured": configure.get("configured") is True,
    "cache_absent_after_delete": cache_absent,
    "state_built": state_built,
    "provider_is_woopayments": provider == "woopayments",
    "timestamps_are_numeric": all(value is not None for value in (start, fetched, updated, observed)),
    "cache_fetched_during_run": False,
    "cache_updated_during_run": False,
    "requested_rates_present": isinstance(missing, list) and not missing and isinstance(rates, dict) and bool(rates),
    "cache_not_errored": observation.get("cache_errored") is False,
}

if all(value is not None for value in (start, fetched, updated, observed)):
    checks["cache_fetched_during_run"] = start - 2 <= fetched <= observed + 5
    checks["cache_updated_during_run"] = start - 2 <= updated <= observed + 5

details = []
if observation.get("cache_errored") is True:
    if role == "target":
        details.append({
            "code": "target_provider_refresh_unavailable",
            "classification": "provider_or_environment",
            "scope": "target_rate_refresh",
            "message": "target provider rate refresh unavailable",
        })
    else:
        details.append({
            "code": "reference_rate_oracle_unavailable",
            "classification": "reference_oracle",
            "scope": "reference_rate_refresh",
            "message": "reference rate oracle unavailable",
        })
elif not cache_absent:
    details.append({
        "code": f"{role}_cache_reset_unproven",
        "classification": "environment",
        "scope": f"{role}_rate_refresh",
        "message": f"{role} cache deletion could not be proven",
    })
elif role == "target" and build.get("provider_registered") is True and build.get("provider_available") is False:
    details.append({
        "code": "target_provider_environment_unavailable",
        "classification": "provider_or_environment",
        "scope": "target_rate_refresh",
        "message": "target WooPayments rate provider is registered but unavailable",
    })
elif not checks["provider_is_woopayments"]:
    details.append({
        "code": f"{role}_provider_not_woopayments",
        "classification": "core" if role == "target" else "reference_oracle",
        "scope": f"{role}_rate_refresh",
        "message": f"{role} provider is not woopayments",
    })
elif not checks["cache_fetched_during_run"] or not checks["cache_updated_during_run"]:
    details.append({
        "code": f"stale_{role}_rate_cache",
        "classification": "core" if role == "target" else "reference_oracle",
        "scope": f"{role}_rate_refresh",
        "message": f"{role} cache fetched timestamp predates this refresh",
    })
elif not all((checks["configured"], state_built, checks["provider_is_woopayments"], checks["requested_rates_present"])):
    details.append({
        "code": f"invalid_{role}_rate_refresh",
        "classification": "core" if role == "target" else "reference_oracle",
        "scope": f"{role}_rate_refresh",
        "message": f"{role} rate refresh did not produce complete WooPayments evidence",
    })

if not details:
    status = "pass"
elif any(item["classification"] == "core" for item in details):
    status = "fail"
else:
    status = "blocked"

payload = {
    "schema": "woopayments_mc_rate_refresh_evidence.v1",
    "role": role,
    "configure": configure,
    "build": build,
    "observation": observation,
    "freshness": {
        "status": status,
        "checks": checks,
        "failure_details": details,
    },
}
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(status)
PY
}

write_reference_oracle_evidence() {
	local client_json="$1"

	python3 - "$OUT_DIR/reference-rate-refresh.json" "$client_json" "$CURRENCIES_TO_CSV" <<'PY'
import json
import math
import sys
import time
from pathlib import Path

path = Path(sys.argv[1])
client = json.loads(sys.argv[2])
requested = [currency for currency in sys.argv[3].split(",") if currency]
canonical = client.get("transact_method")
canonical = canonical if isinstance(canonical, dict) else {}
route_control = client.get("wcpay_route_control")
route_control = route_control if isinstance(route_control, dict) else {}
if canonical.get("ok") is True:
    oracle = canonical
    source = "standalone_plugin_api_client"
elif route_control.get("ok") is True:
    oracle = route_control
    source = "standalone_plugin_wcpay_route_control"
else:
    oracle = {}
    source = "unavailable"
raw_rates = oracle.get("rates")
raw_rates = raw_rates if isinstance(raw_rates, dict) else {}
rates = {}
missing = []

for currency in requested:
    raw_rate = raw_rates.get(currency)
    try:
        rate = float(raw_rate)
    except (TypeError, ValueError):
        rate = 0.0
    if not math.isfinite(rate) or rate <= 0:
        missing.append(currency)
        continue
    rates[currency] = str(raw_rate)

checks = {
    "reference_store_mutated": False,
    "standalone_plugin_available": all(
        candidate.get("error_code") != "wc_payments_unavailable"
        for candidate in (canonical, route_control)
    ),
    "server_connected": client.get("server_connected"),
    "canonical_client_succeeded": canonical.get("ok") is True,
    "route_control_succeeded": route_control.get("ok") is True,
    "read_only_oracle_available": bool(oracle),
    "requested_rates_present": not missing and bool(rates),
}

details = []
if not checks["standalone_plugin_available"] or not checks["read_only_oracle_available"] or not checks["requested_rates_present"]:
    details.append(
        {
            "code": "reference_rate_oracle_unavailable",
            "classification": "reference_oracle",
            "scope": "reference_rate_oracle",
            "message": "reference rate oracle unavailable",
        }
    )

observation = {
    "provider": "woopayments",
    "source": source,
    "observed_at": int(time.time()),
    "rates": rates,
    "missing": missing,
}
payload = {
    "schema": "woopayments_mc_rate_refresh_evidence.v1",
    "role": "reference",
    "source": source,
    "configure": {"mutated": False, "not_required": True},
    "build": {"state_built": False, "not_required": True},
    "observation": observation,
    "freshness": {
        "status": "blocked" if details else "pass",
        "checks": checks,
        "failure_details": details,
    },
}
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(payload["freshness"]["status"])
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
EXPECTED_OPTION_NAMES_JSON="$(python3 - "$CURRENCIES_TO_CSV" <<'PY'
import json
import sys

currencies = [currency.lower() for currency in sys.argv[1].split(",") if currency]
option_names = [
    "woocommerce_currency",
    "_wcpay_feature_customer_multi_currency",
    "wcpay_multi_currency_setup_completed",
    "wcpay_multi_currency_enabled_currencies",
    "wcpay_multi_currency_cached_currencies",
    "_transient_wcpay_currency_format",
    "_transient_timeout_wcpay_currency_format",
    "_transient_wcpay_locale_info",
    "_transient_timeout_wcpay_locale_info",
]
for currency in currencies:
    option_names.extend([
        f"wcpay_multi_currency_exchange_rate_{currency}",
        f"wcpay_multi_currency_manual_rate_{currency}",
        f"wcpay_multi_currency_price_rounding_{currency}",
        f"wcpay_multi_currency_price_charm_{currency}",
    ])
print(json.dumps(option_names, separators=(",", ":")))
PY
)"

snapshot_php="$(cat <<PHP
/* mc_rates_snapshot_options */
\$currencies_to = $CURRENCIES_TO_PHP;
\$option_names = array(
	'woocommerce_currency',
	'_wcpay_feature_customer_multi_currency',
	'wcpay_multi_currency_setup_completed',
	'wcpay_multi_currency_enabled_currencies',
	'wcpay_multi_currency_cached_currencies',
	'_transient_wcpay_currency_format',
	'_transient_timeout_wcpay_currency_format',
	'_transient_wcpay_locale_info',
	'_transient_timeout_wcpay_locale_info',
);
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	\$option_names[] = 'wcpay_multi_currency_exchange_rate_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_manual_rate_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_price_rounding_' . \$currency_lc;
	\$option_names[] = 'wcpay_multi_currency_price_charm_' . \$currency_lc;
}
\$option_names = array_values( array_unique( \$option_names ) );
\$snapshot_options = array();
global \$wpdb;
foreach ( \$option_names as \$option_name ) {
	\$wpdb->last_error = '';
	\$row = \$wpdb->get_row(
		\$wpdb->prepare(
			"SELECT option_value, autoload FROM {\$wpdb->options} WHERE option_name = %s LIMIT 1",
			\$option_name
		),
		ARRAY_A
	);
	if ( '' !== (string) \$wpdb->last_error ) {
		throw new RuntimeException( 'snapshot_read_failed:' . \$option_name );
	}
	if ( null === \$row ) {
		\$snapshot_options[ \$option_name ] = array( 'exists' => false );
		continue;
	}
	\$snapshot_options[ \$option_name ] = array(
		'exists' => true,
		'option_value_b64' => base64_encode( (string) \$row['option_value'] ),
		'autoload' => (string) \$row['autoload'],
	);
}
\$snapshot_payload = array(
	'schema' => 'woopayments_mc_option_snapshot_payload.v1',
	'option_names' => \$option_names,
	'options' => \$snapshot_options,
);
\$snapshot_payload_json = wp_json_encode( \$snapshot_payload, JSON_UNESCAPED_SLASHES );
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_option_snapshot.v1',
	'role' => \$snapshot_role,
	'snapshot_b64' => base64_encode( \$snapshot_payload_json ),
	'snapshot_sha256' => hash( 'sha256', \$snapshot_payload_json ),
	'option_names' => \$option_names,
	'option_count' => count( \$option_names ),
) ) );
PHP
)"

snapshot_store_options() {
	local wp_cmd="$1"
	local role="$2"
	local php

	php="\$snapshot_role = '$role';
$snapshot_php"
	wp_eval_json "$wp_cmd" "$role option snapshot" "$php"
}

validate_snapshot_json() {
	python3 - "$1" "$2" "$EXPECTED_OPTION_NAMES_JSON" <<'PY'
import base64
import hashlib
import json
import sys

from pathlib import Path

snapshot = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
role = sys.argv[2]
expected_names = json.loads(sys.argv[3])

if snapshot.get("schema") != "woopayments_mc_option_snapshot.v1":
    raise SystemExit("snapshot schema mismatch")
if snapshot.get("role") != role:
    raise SystemExit("snapshot role mismatch")
if snapshot.get("option_names") != expected_names or snapshot.get("option_count") != len(expected_names):
    raise SystemExit("snapshot option inventory mismatch")
try:
    payload_bytes = base64.b64decode(snapshot["snapshot_b64"], validate=True)
except Exception as error:
    raise SystemExit(f"invalid snapshot payload: {error}")
if hashlib.sha256(payload_bytes).hexdigest() != snapshot.get("snapshot_sha256"):
    raise SystemExit("snapshot digest mismatch")
payload = json.loads(payload_bytes)
if payload.get("schema") != "woopayments_mc_option_snapshot_payload.v1":
    raise SystemExit("snapshot payload schema mismatch")
if payload.get("option_names") != expected_names:
    raise SystemExit("snapshot payload option inventory mismatch")
options = payload.get("options")
if not isinstance(options, dict) or set(options) != set(expected_names):
    raise SystemExit("snapshot payload option records mismatch")
for option_name in expected_names:
    record = options[option_name]
    if not isinstance(record, dict) or not isinstance(record.get("exists"), bool):
        raise SystemExit(f"invalid snapshot record for {option_name}")
    if record["exists"]:
        if not isinstance(record.get("autoload"), str):
            raise SystemExit(f"missing autoload value for {option_name}")
        try:
            base64.b64decode(record["option_value_b64"], validate=True)
        except Exception as error:
            raise SystemExit(f"invalid serialized value for {option_name}: {error}")
PY
}

configure_php="$(cat <<PHP
/* mc_rates_configure */
\$currency_from = '$CURRENCY_FROM';
\$currencies_to = $CURRENCIES_TO_PHP;
\$cache_key = 'wcpay_multi_currency_cached_currencies';
update_option( 'woocommerce_currency', \$currency_from, false );
update_option( '_wcpay_feature_customer_multi_currency', '1', false );
update_option( 'wcpay_multi_currency_setup_completed', true, false );
update_option( 'wcpay_multi_currency_enabled_currencies', \$currencies_to, false );
foreach ( \$currencies_to as \$currency_code ) {
	\$currency_lc = strtolower( (string) \$currency_code );
	update_option( 'wcpay_multi_currency_exchange_rate_' . \$currency_lc, 'automatic', false );
	delete_option( 'wcpay_multi_currency_manual_rate_' . \$currency_lc );
	update_option( 'wcpay_multi_currency_price_rounding_' . \$currency_lc, '0', false );
	update_option( 'wcpay_multi_currency_price_charm_' . \$currency_lc, '0', false );
}
\$missing_marker = new stdClass();
\$cache_before = get_option( \$cache_key, \$missing_marker );
\$cache_was_present = \$missing_marker !== \$cache_before;
\$refresh_started_at = time();
\$cache_delete_result = delete_option( \$cache_key );
wp_cache_delete( \$cache_key, 'options' );
\$cache_after = get_option( \$cache_key, \$missing_marker );
\$cache_absent_after_delete = \$missing_marker === \$cache_after;
WP_CLI::line( wp_json_encode( array(
	'configured' => true,
	'currency_from' => \$currency_from,
	'currencies_to' => \$currencies_to,
	'refresh_started_at' => \$refresh_started_at,
	'cache_was_present' => \$cache_was_present,
	'cache_delete_result' => \$cache_delete_result,
	'cache_deleted' => \$cache_absent_after_delete && ( ! \$cache_was_present || \$cache_delete_result ),
	'cache_absent_after_delete' => \$cache_absent_after_delete,
) ) );
PHP
)"

restore_store_options() {
	local wp_cmd="$1"
	local role="$2"
	local snapshot_file="$3"
	local restore_php

	restore_php="$(cat <<PHP
/* mc_rates_restore_options */
\$role = '$role';
\$mismatches = array();
\$restored_count = 0;
\$cache_verified_count = 0;
\$expected_sha256 = '';
try {
	\$snapshot_envelope_json = file_get_contents( 'php://stdin' );
	if ( false === \$snapshot_envelope_json ) {
		throw new RuntimeException( 'snapshot_envelope_unavailable' );
	}
	\$snapshot_envelope = json_decode( \$snapshot_envelope_json, true, 512, JSON_THROW_ON_ERROR );
	if (
		! is_array( \$snapshot_envelope )
		|| 'woopayments_mc_option_snapshot.v1' !== ( \$snapshot_envelope['schema'] ?? '' )
		|| \$role !== ( \$snapshot_envelope['role'] ?? '' )
		|| ! isset( \$snapshot_envelope['snapshot_b64'], \$snapshot_envelope['snapshot_sha256'] )
	) {
		throw new RuntimeException( 'snapshot_envelope_invalid' );
	}
	\$snapshot_b64 = (string) \$snapshot_envelope['snapshot_b64'];
	\$expected_sha256 = (string) \$snapshot_envelope['snapshot_sha256'];
	\$snapshot_payload_json = base64_decode( \$snapshot_b64, true );
	if ( false === \$snapshot_payload_json || hash( 'sha256', \$snapshot_payload_json ) !== \$expected_sha256 ) {
		throw new RuntimeException( 'snapshot_digest_mismatch' );
	}
	\$snapshot = json_decode( \$snapshot_payload_json, true, 512, JSON_THROW_ON_ERROR );
	if (
		! is_array( \$snapshot )
		|| 'woopayments_mc_option_snapshot_payload.v1' !== ( \$snapshot['schema'] ?? '' )
		|| ! isset( \$snapshot['option_names'], \$snapshot['options'] )
		|| ! is_array( \$snapshot['option_names'] )
		|| ! is_array( \$snapshot['options'] )
	) {
		throw new RuntimeException( 'snapshot_payload_invalid' );
	}
	global \$wpdb;
	foreach ( \$snapshot['option_names'] as \$option_name ) {
		\$record = \$snapshot['options'][ \$option_name ] ?? null;
		if ( ! is_string( \$option_name ) || ! is_array( \$record ) || ! isset( \$record['exists'] ) ) {
			\$mismatches[] = (string) \$option_name . ':invalid_snapshot_record';
			continue;
		}
		if ( true === \$record['exists'] ) {
			\$option_value = isset( \$record['option_value_b64'] )
				? base64_decode( (string) \$record['option_value_b64'], true )
				: false;
			\$autoload = isset( \$record['autoload'] ) ? (string) \$record['autoload'] : null;
			if ( false === \$option_value || null === \$autoload ) {
				\$mismatches[] = \$option_name . ':invalid_serialized_value';
				continue;
			}
			\$write_result = \$wpdb->query(
				\$wpdb->prepare(
					"INSERT INTO {\$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
					\$option_name,
					\$option_value,
					\$autoload
				)
			);
			if ( false === \$write_result ) {
				\$mismatches[] = \$option_name . ':write_failed';
				continue;
			}
		} else {
			\$delete_result = \$wpdb->delete( \$wpdb->options, array( 'option_name' => \$option_name ), array( '%s' ) );
			if ( false === \$delete_result ) {
				\$mismatches[] = \$option_name . ':delete_failed';
				continue;
			}
		}
		wp_cache_delete( \$option_name, 'options' );
		++\$restored_count;
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( \$snapshot['option_names'] as \$option_name ) {
		\$record = \$snapshot['options'][ \$option_name ] ?? array();
		\$wpdb->last_error = '';
		\$row = \$wpdb->get_row(
			\$wpdb->prepare(
				"SELECT option_value, autoload FROM {\$wpdb->options} WHERE option_name = %s LIMIT 1",
				\$option_name
			),
			ARRAY_A
		);
		if ( '' !== (string) \$wpdb->last_error ) {
			\$mismatches[] = \$option_name . ':verification_read_failed';
			continue;
		}
		\$expected_value = isset( \$record['option_value_b64'] )
			? base64_decode( (string) \$record['option_value_b64'], true )
			: false;
		if ( false === ( \$record['exists'] ?? null ) ) {
			if ( null !== \$row ) {
				\$mismatches[] = \$option_name . ':expected_absent';
			}
		} elseif (
			null === \$row
			|| false === \$expected_value
			|| (string) \$row['option_value'] !== \$expected_value
			|| (string) \$row['autoload'] !== (string) ( \$record['autoload'] ?? '' )
		) {
			\$mismatches[] = \$option_name . ':value_mismatch';
		}

		wp_cache_delete( \$option_name, 'options' );
		\$cache_marker = new stdClass();
		\$cache_value = get_option( \$option_name, \$cache_marker );
		if ( false === ( \$record['exists'] ?? null ) ) {
			if ( \$cache_marker !== \$cache_value ) {
				\$mismatches[] = \$option_name . ':cache_expected_absent';
				continue;
			}
		} elseif (
			false === \$expected_value
			|| \$cache_marker === \$cache_value
			|| maybe_serialize( \$cache_value ) !== \$expected_value
		) {
			\$mismatches[] = \$option_name . ':cache_value_mismatch';
			continue;
		}
		++\$cache_verified_count;
	}
} catch ( Throwable \$e ) {
	\$mismatches[] = 'restore_exception:' . get_class( \$e ) . ':' . substr( \$e->getMessage(), 0, 120 );
}
\$mismatches = array_values( array_unique( \$mismatches ) );
WP_CLI::line( wp_json_encode( array(
	'schema' => 'woopayments_mc_option_restore.v1',
	'role' => \$role,
	'restored' => empty( \$mismatches ),
	'verified' => empty( \$mismatches ),
	'snapshot_sha256' => \$expected_sha256,
	'option_count' => isset( \$snapshot['option_names'] ) && is_array( \$snapshot['option_names'] ) ? count( \$snapshot['option_names'] ) : 0,
	'restored_count' => \$restored_count,
	'cache_verified_count' => \$cache_verified_count,
	'mismatches' => \$mismatches,
) ) );
PHP
	)"

	wp_eval_json_stdin "$wp_cmd" "$role option restore" "$restore_php" "$snapshot_file"
}

build_php="$(cat <<'PHP'
/* mc_rates_build_state */
$provider_id = '';
$provider_registered = false;
$provider_available = false;
$state_built = false;
if ( class_exists( '\Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory' ) ) {
	$registry = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory::class )->create();
	$registered_provider = $registry->get_provider( 'woopayments' );
	$provider_registered = null !== $registered_provider;
	$provider_available = $registered_provider ? (bool) $registered_provider->is_available() : false;
	$provider = $registry->get_available_provider();
	$provider_id = $provider ? $provider->get_id() : '';
	wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class )->create()->build();
	$state_built = true;
} elseif ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
	$multi_currency = WC_Payments_Multi_Currency();
	if ( $multi_currency && method_exists( $multi_currency, 'get_cached_currencies' ) ) {
		$multi_currency->get_cached_currencies();
		$provider_id = 'woopayments';
		$provider_registered = true;
		$provider_available = true;
		$state_built = true;
	}
}
WP_CLI::line( wp_json_encode( array(
	'provider' => $provider_id,
	'provider_registered' => $provider_registered,
	'provider_available' => $provider_available,
	'state_built' => $state_built,
	'built_at' => time(),
) ) );
PHP
)"

inspect_php="$(cat <<PHP
/* mc_rates_inspect_rates */
\$role = '';
\$provider_id = '';
\$provider_registered = false;
\$provider_available = false;
if ( class_exists( '\Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory' ) ) {
	\$registry = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory::class )->create();
	\$registered_provider = \$registry->get_provider( 'woopayments' );
	\$provider_registered = null !== \$registered_provider;
	\$provider_available = \$registered_provider ? (bool) \$registered_provider->is_available() : false;
	\$provider = \$registry->get_available_provider();
	\$provider_id = \$provider ? \$provider->get_id() : '';
} elseif ( function_exists( 'WC_Payments_Multi_Currency' ) ) {
	\$provider_id = 'woopayments';
	\$provider_registered = true;
	\$provider_available = true;
}
\$raw_cache = get_option( 'wcpay_multi_currency_cached_currencies', array() );
\$cache_errored = is_array( \$raw_cache ) && ! empty( \$raw_cache['errored'] );
\$consecutive_errors = is_array( \$raw_cache ) && isset( \$raw_cache['consecutive_errors'] ) ? (int) \$raw_cache['consecutive_errors'] : 0;
\$fetched = is_array( \$raw_cache ) && isset( \$raw_cache['fetched'] ) ? \$raw_cache['fetched'] : null;
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
	'provider_registered' => \$provider_registered,
	'provider_available' => \$provider_available,
	'cache_option' => 'wcpay_multi_currency_cached_currencies',
	'updated' => \$updated,
	'rates' => \$rates,
	'missing' => \$missing,
	'cache_errored' => \$cache_errored,
	'consecutive_errors' => \$consecutive_errors,
	'fetched' => \$fetched,
	'observed_at' => time(),
) ) );
PHP
)"

reference_client_probe_php="$(cat <<PHP
/* mc_rates_probe_reference_client */
\$currency_from = strtolower( '$CURRENCY_FROM' );
\$currencies_to = $CURRENCIES_TO_PHP;
\$pick_requested_rates = static function ( \$rates ) use ( \$currencies_to ) {
	\$picked = array();
	if ( ! is_array( \$rates ) ) {
		return \$picked;
	}
	foreach ( \$currencies_to as \$currency_code ) {
		\$currency_code = strtoupper( (string) \$currency_code );
		\$rate = \$rates[ \$currency_code ] ?? \$rates[ strtolower( \$currency_code ) ] ?? null;
		if ( is_numeric( \$rate ) ) {
			\$picked[ \$currency_code ] = (string) \$rate;
		}
	}
	return \$picked;
};
\$exception_payload = static function ( Throwable \$e ) {
	return array(
		'ok' => false,
		'error_class' => get_class( \$e ),
		'error_code' => method_exists( \$e, 'get_error_code' ) ? (string) \$e->get_error_code() : (string) \$e->getCode(),
		'error_message' => substr( \$e->getMessage(), 0, 160 ),
	);
};
\$out = array(
	'server_connected' => null,
	'transact_method' => array(
		'ok' => false,
		'error_code' => 'not_run',
	),
	'wcpay_route_control' => array(
		'ok' => false,
		'error_code' => 'not_run',
	),
);
if ( ! class_exists( 'WC_Payments' ) ) {
	\$out['transact_method'] = array(
		'ok' => false,
		'error_code' => 'wc_payments_unavailable',
	);
	\$out['wcpay_route_control'] = \$out['transact_method'];
	WP_CLI::line( wp_json_encode( \$out ) );
	return;
}
\$client = WC_Payments::get_payments_api_client();
try {
	\$out['server_connected'] = method_exists( \$client, 'is_server_connected' ) ? (bool) \$client->is_server_connected() : null;
} catch ( Throwable \$e ) {
	\$out['server_connected'] = false;
}
try {
	\$rates = \$client->get_currency_rates( \$currency_from, \$currencies_to );
	\$out['transact_method'] = array(
		'ok' => true,
		'rates' => \$pick_requested_rates( \$rates ),
	);
} catch ( Throwable \$e ) {
	\$out['transact_method'] = \$exception_payload( \$e );
}
try {
	\$method = new ReflectionMethod( \$client, 'request' );
	\$method->setAccessible( true );
	\$rates = \$method->invoke(
		\$client,
		array(
			'currency_from' => \$currency_from,
			'currencies_to' => \$currencies_to,
		),
		WC_Payments_API_Client::CURRENCY_API . '/rates',
		WC_Payments_API_Client::GET,
		true,
		false,
		false,
		false
	);
	\$out['wcpay_route_control'] = array(
		'ok' => true,
		'rates' => \$pick_requested_rates( \$rates ),
	);
} catch ( Throwable \$e ) {
	\$out['wcpay_route_control'] = \$exception_payload( \$e );
}
WP_CLI::line( wp_json_encode( \$out ) );
PHP
)"

STOREFRONT_CURRENCY="${CURRENCIES_TO_CSV%%,*}"
STOREFRONT_PRODUCT_SKU="$(python3 - <<'PY'
import uuid

print("mc-rates-gate-" + uuid.uuid4().hex)
PY
)"
storefront_prepare_php="$(cat <<PHP
/* mc_rates_prepare_storefront_product */
\$currency = '$STOREFRONT_CURRENCY';
\$base_price = '100.00';
\$evidence_sku = '$STOREFRONT_PRODUCT_SKU';
if ( ! class_exists( 'WC_Product_Simple' ) ) {
	WP_CLI::line( wp_json_encode( array(
		'prepared' => false,
		'error_code' => 'woocommerce_product_api_unavailable',
	) ) );
	return;
}
\$product_id = 0;
try {
	\$currency_decimals = 2;
	if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\MultiCurrency\\Services\\MultiCurrencyStateBuilderFactory' ) ) {
		\$state = wc_get_container()->get( \Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class )->create()->build();
		\$enabled = \$state->get_enabled_currencies();
		if ( isset( \$enabled[ \$currency ] ) && \$enabled[ \$currency ]->get_is_zero_decimal() ) {
			\$currency_decimals = 0;
		}
	}
	\$product = new WC_Product_Simple();
	\$product->set_name( 'Multi-currency rate evidence product' );
	\$product->set_status( 'publish' );
	\$product->set_catalog_visibility( 'visible' );
	\$product->set_virtual( true );
	\$product->set_tax_status( 'none' );
	\$product->set_regular_price( \$base_price );
	\$product->set_price( \$base_price );
	\$product->set_sku( \$evidence_sku );
	\$product_id = \$product->save();
	\$product_url = \$product_id ? get_permalink( \$product_id ) : false;
	WP_CLI::line( wp_json_encode( array(
		'prepared' => (bool) ( \$product_id && \$product_url ),
		'product_id' => (int) \$product_id,
		'sku' => \$product->get_sku(),
		'product_url' => \$product_url ? (string) \$product_url : '',
		'base_price' => \$base_price,
		'currency' => \$currency,
		'currency_decimals' => \$currency_decimals,
	) ) );
} catch ( Throwable \$e ) {
	if ( 0 < \$product_id ) {
		wp_delete_post( \$product_id, true );
	}
	WP_CLI::line( wp_json_encode( array(
		'prepared' => false,
		'error_code' => 'storefront_product_prepare_failed',
		'error_class' => get_class( \$e ),
		'error_message' => substr( \$e->getMessage(), 0, 160 ),
	) ) );
}
PHP
)"

cleanup_storefront_product() {
	local product_id="${1:-$STOREFRONT_PRODUCT_ID}"
	local product_sku="${2:-$STOREFRONT_PRODUCT_SKU}"
	local cleanup_php

	if [ -z "$product_id" ] && [ -z "$product_sku" ]; then
		return 0
	fi

	cleanup_php="$(cat <<PHP
/* mc_rates_cleanup_storefront_product */
	\$product_id = (int) '$product_id';
	\$product_sku = '$product_sku';
	if ( 0 >= \$product_id && '' !== \$product_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
		\$product_id = (int) wc_get_product_id_by_sku( \$product_sku );
	}
	if ( 0 >= \$product_id ) {
		WP_CLI::line( wp_json_encode( array( 'cleaned' => true, 'not_found' => true, 'sku' => \$product_sku ) ) );
		return;
	}
	\$product = wc_get_product( \$product_id );
	if ( ! \$product || '' === \$product_sku || \$product->get_sku() !== \$product_sku ) {
		WP_CLI::line( wp_json_encode( array(
			'cleaned' => false,
			'product_id' => \$product_id,
			'sku' => \$product_sku,
			'error_code' => 'cleanup_product_identity_mismatch',
		) ) );
		return;
	}
	\$deleted = false !== wp_delete_post( \$product_id, true );
	clean_post_cache( \$product_id );
	WP_CLI::line( wp_json_encode( array(
		'cleaned' => \$deleted && null === get_post( \$product_id ),
		'product_id' => \$product_id,
		'sku' => \$product_sku,
	) ) );
PHP
)"

	wp_eval_json "$TARGET_WP" "target storefront cleanup" "$cleanup_php"
}

validate_local_storefront_url() {
	python3 - "$1" "$2" <<'PY'
import ipaddress
import sys
from urllib.parse import urlparse

parsed = urlparse(sys.argv[1])
expected = urlparse(sys.argv[2])
host = (parsed.hostname or "").lower()
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(1)
is_local = host in {"localhost", "host.docker.internal", "gateway.docker.internal"} or host.endswith(".localhost")
if not is_local:
    try:
        is_local = ipaddress.ip_address(host).is_loopback
    except ValueError:
        is_local = False
if not is_local:
    raise SystemExit(1)
if (parsed.scheme.lower(), host, parsed.port) != (
    expected.scheme.lower(),
    (expected.hostname or "").lower(),
    expected.port,
):
    raise SystemExit(1)
expected_path = expected.path.rstrip("/")
if expected_path and not parsed.path.startswith(expected_path + "/"):
    raise SystemExit(1)
PY
}

write_storefront_driver() {
	cat > "$OUT_DIR/mc-rates-storefront-driver.mjs" <<'JS'
const page = await context.newPage();
const url = new URL( state.product_url );
url.searchParams.set( 'currency', state.currency );

let response = null;
try {
	response = await page.goto( url.toString(), { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await waitForPageLoad( { page, timeout: 30000, minWait: 250 } );
} catch ( error ) {
	console.error( error?.stack || error?.message || String( error ) );
	process.exitCode = 1;
	return;
}

const amount = page.locator(
	'body.single-product div.product .summary .price .woocommerce-Price-amount.amount, ' +
	'body.single-product .wp-block-woocommerce-product-price .woocommerce-Price-amount.amount'
).first();
const priceElementVisible = ( await amount.count() ) > 0 && await amount.isVisible().catch( () => false );
const displayedPriceText = priceElementVisible ? ( await amount.innerText() ).trim() : '';
const displayedAmountText = priceElementVisible
	? await amount.evaluate( ( element ) => {
		const clone = element.cloneNode( true );
		clone.querySelectorAll( '.woocommerce-Price-currencySymbol' ).forEach( ( symbol ) => symbol.remove() );
		return ( clone.textContent || '' ).trim();
	} )
	: '';

const structured = await page.locator( 'script[type="application/ld+json"]' ).allTextContents();
let displayedCurrency = '';
let structuredPrice = '';
const visit = ( value ) => {
	if ( ! value || displayedCurrency || typeof value !== 'object' ) {
		return;
	}
	if ( Array.isArray( value ) ) {
		value.forEach( visit );
		return;
	}
	if ( value.priceCurrency && value.price !== undefined ) {
		displayedCurrency = String( value.priceCurrency ).toUpperCase();
		structuredPrice = String( value.price );
		return;
	}
	Object.values( value ).forEach( visit );
};
for ( const raw of structured ) {
	try {
		visit( JSON.parse( raw ) );
	} catch ( error ) {
		// Invalid unrelated structured data is not evidence for this product.
	}
}

console.log( JSON.stringify( {
	schema: 'woopayments_mc_storefront_browser_observation.v1',
	probe_status: 'observed',
	http_status: response ? response.status() : 0,
	product_id: state.product_id,
	sku: state.sku,
	requested_currency: state.currency,
	product_identity_matched: await page.locator( `body.postid-${ state.product_id }` ).count() > 0,
	price_element_visible: priceElementVisible,
	displayed_price_text: displayedPriceText,
	displayed_amount_text: displayedAmountText,
	displayed_currency: displayedCurrency,
	structured_price: structuredPrice,
	page_url: page.url(),
} ) );
JS
}

write_storefront_evidence() {
	local prepare_json="$1"
	local browser_json="$2"
	local cleanup_json="$3"

	python3 - "$OUT_DIR/target-storefront-price.json" "$OUT_DIR/target-rate-refresh.json" "$prepare_json" "$browser_json" "$cleanup_json" <<'PY'
import hashlib
import json
import math
import re
import sys
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from pathlib import Path

path = Path(sys.argv[1])
rate_path = Path(sys.argv[2])
rate_evidence = json.loads(rate_path.read_text(encoding="utf-8"))
prepare = json.loads(sys.argv[3])
browser = json.loads(sys.argv[4])
cleanup = json.loads(sys.argv[5])
currency = str(prepare.get("currency") or "").upper()
rate_raw = (rate_evidence.get("observation", {}).get("rates") or {}).get(currency)
details = []

def detail(code, classification, message):
    details.append({
        "code": code,
        "classification": classification,
        "scope": "target_storefront_price",
        "message": message,
    })

def parse_amount(raw):
    text = re.sub(r"[^0-9,.-]", "", str(raw or ""))
    if not text or not re.search(r"\d", text):
        return None
    if "," in text and "." in text:
        decimal_separator = "," if text.rfind(",") > text.rfind(".") else "."
        thousands_separator = "." if decimal_separator == "," else ","
        text = text.replace(thousands_separator, "").replace(decimal_separator, ".")
    elif "," in text:
        text = text.replace(",", ".")
    try:
        return Decimal(text)
    except InvalidOperation:
        return None

try:
    rate = Decimal(str(rate_raw))
    base_price = Decimal(str(prepare.get("base_price")))
    decimals = int(prepare.get("currency_decimals", 2))
    quantum = Decimal(1).scaleb(-decimals)
    expected = (base_price * rate).quantize(quantum, rounding=ROUND_HALF_UP)
except (InvalidOperation, TypeError, ValueError):
    rate = None
    expected = None

actual = parse_amount(browser.get("displayed_amount_text"))
if browser.get("probe_status") != "observed":
    detail("storefront_browser_unavailable", "environment", "target storefront browser probe unavailable")
elif not isinstance(browser.get("http_status"), int) or not 200 <= browser["http_status"] < 300:
    detail("storefront_http_unavailable", "environment", "target storefront returned no successful HTTP response")
elif browser.get("product_id") != prepare.get("product_id") or browser.get("sku") != prepare.get("sku") or browser.get("product_identity_matched") is not True:
    detail("storefront_product_identity_mismatch", "core", "storefront response did not render the evidence product")
elif browser.get("price_element_visible") is not True or actual is None:
    detail("storefront_price_missing", "core", "storefront evidence product exposed no visible numeric price")
elif str(browser.get("requested_currency") or "").upper() != currency or str(browser.get("displayed_currency") or "").upper() != currency:
    detail("storefront_currency_mismatch", "core", "storefront price did not render in the requested secondary currency")
elif expected is None or rate is None:
    detail("storefront_rate_evidence_invalid", "core", "target rate evidence could not derive the expected storefront price")
elif not math.isclose(float(actual), float(expected), rel_tol=0.0, abs_tol=0.011):
    detail(
        "storefront_price_mismatch",
        "core",
        f"storefront price mismatch: expected {expected} from rate {rate_raw}, observed {actual}",
    )

if cleanup.get("cleaned") is not True:
    detail("storefront_fixture_cleanup_failed", "environment", "target storefront evidence product cleanup failed")

if not details:
    status = "pass"
elif any(item["classification"] == "core" for item in details):
    status = "fail"
else:
    status = "blocked"

payload = {
    "schema": "woopayments_mc_storefront_price_evidence.v1",
    "status": status,
    "rate_evidence_sha256": hashlib.sha256(rate_path.read_bytes()).hexdigest(),
    "observed_rate": None if rate_raw is None else str(rate_raw),
    "expected_price": None if expected is None else format(expected, "f"),
    "product": prepare,
    "browser": browser,
    "cleanup": cleanup,
    "failure_details": details,
}
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(status)
PY
}

write_cleanup_restore_evidence() {
	local trigger="$1"
	local product_cleanup_json="$2"
	local target_restore_json="$3"

	python3 - \
		"$OUT_DIR/mc-rates-cleanup-restore.json" \
		"$trigger" \
		"$product_cleanup_json" \
		"$TARGET_SNAPSHOT_FILE" \
		"$target_restore_json" \
		"$TARGET_RESTORE_REQUIRED" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
trigger = sys.argv[2]

def decode(raw, fallback):
    if not raw:
        return fallback
    try:
        value = json.loads(raw)
    except Exception:
        return {"raw": raw}
    return value if isinstance(value, dict) else fallback

product_cleanup = decode(sys.argv[3], {"cleaned": True, "not_required": True})
snapshot_path = Path(sys.argv[4])
target_snapshot = (
    json.loads(snapshot_path.read_text(encoding="utf-8"))
    if snapshot_path.is_file()
    else {}
)
target_restore = decode(sys.argv[5], {"restored": True, "verified": True, "not_required": True})
target_required = sys.argv[6] == "1"

def snapshot_evidence(snapshot):
    if not snapshot:
        return {"captured": False}
    return {
        "captured": True,
        "schema": snapshot.get("schema"),
        "snapshot_b64": snapshot.get("snapshot_b64"),
        "snapshot_sha256": snapshot.get("snapshot_sha256"),
        "option_names": snapshot.get("option_names", []),
        "option_count": snapshot.get("option_count", 0),
    }

details = []

def add_detail(code, message):
    details.append({
        "code": code,
        "classification": "environment",
        "scope": "harness_cleanup_restore",
        "message": message,
    })

if product_cleanup.get("cleaned") is not True:
    add_detail("storefront_fixture_cleanup_failed", "target storefront evidence product cleanup failed")

for role, required, snapshot, restore in (
    ("target", target_required, target_snapshot, target_restore),
):
    if not required:
        continue
    restored_exactly = (
        restore.get("schema") == "woopayments_mc_option_restore.v1"
        and restore.get("role") == role
        and restore.get("restored") is True
        and restore.get("verified") is True
        and restore.get("snapshot_sha256") == snapshot.get("snapshot_sha256")
        and restore.get("option_count") == snapshot.get("option_count")
        and restore.get("restored_count") == snapshot.get("option_count")
        and restore.get("cache_verified_count") == snapshot.get("option_count")
        and not restore.get("mismatches")
    )
    if not restored_exactly:
        add_detail(f"{role}_option_restore_failed", f"{role} option restoration failed verification")

payload = {
    "schema": "woopayments_mc_cleanup_restore.v1",
    "status": "blocked" if details else "pass",
    "trigger": trigger,
    "product_cleanup": product_cleanup,
    "reference_snapshot": {"captured": False},
    "target_snapshot": snapshot_evidence(target_snapshot),
    "reference_option_restore": {"restored": True, "verified": True, "not_required": True},
    "target_option_restore": target_restore,
    "failure_details": details,
}
path.write_text(json.dumps(payload, sort_keys=True, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"status": payload["status"], "failure_details": details}, sort_keys=True))
PY
}

finalize_harness_state() {
	local trigger="${1:-normal}"
	local product_cleanup_json target_restore_json cleaned cleanup_evidence_required=0

	if [ "$FINALIZATION_DONE" -eq 1 ]; then
		printf '%s\n' "$FINALIZATION_JSON"
		return 0
	fi
	if [ "$FINALIZATION_RUNNING" -eq 1 ]; then
		printf '%s\n' '{"status":"blocked","failure_details":[{"code":"cleanup_reentry","classification":"environment","scope":"harness_cleanup_restore","message":"cleanup/restore re-entry detected"}]}'
		return 1
	fi
	FINALIZATION_RUNNING=1

	if [ "$STOREFRONT_PRODUCT_CLEANUP_REQUIRED" -eq 1 ]; then
		cleanup_evidence_required=1
		if product_cleanup_json="$(cleanup_storefront_product "$STOREFRONT_PRODUCT_ID" "$STOREFRONT_PRODUCT_SKU")"; then
			cleaned="$(json_field "$product_cleanup_json" cleaned 2>/dev/null || true)"
			if [ "$cleaned" = "true" ]; then
				STOREFRONT_PRODUCT_ID=""
				STOREFRONT_PRODUCT_SKU=""
				STOREFRONT_PRODUCT_CLEANUP_REQUIRED=0
			fi
		else
			product_cleanup_json="$(python3 - "$STOREFRONT_PRODUCT_ID" "$STOREFRONT_PRODUCT_SKU" <<'PY'
import json
import sys

print(json.dumps({
    "cleaned": False,
    "product_id": int(sys.argv[1] or 0),
    "sku": sys.argv[2],
    "error_code": "cleanup_command_failed",
}, sort_keys=True))
PY
)"
		fi
	elif [ -n "$PRODUCT_CLEANUP_JSON" ]; then
		cleanup_evidence_required=1
		product_cleanup_json="$PRODUCT_CLEANUP_JSON"
	else
		product_cleanup_json='{"cleaned":true,"not_required":true}'
	fi
	PRODUCT_CLEANUP_JSON="$product_cleanup_json"

	target_restore_json='{"restored":true,"verified":true,"not_required":true}'
	if [ "$TARGET_RESTORE_REQUIRED" -eq 1 ]; then
		if ! target_restore_json="$(restore_store_options "$TARGET_WP" "target" "$TARGET_SNAPSHOT_FILE")"; then
			target_restore_json='{"restored":false,"verified":false,"error_code":"restore_command_failed"}'
		fi
	fi

	if [ "$TARGET_RESTORE_REQUIRED" -eq 0 ] && [ "$cleanup_evidence_required" -eq 0 ]; then
		FINALIZATION_JSON='{"status":"pass","failure_details":[],"not_required":true}'
	else
		if ! FINALIZATION_JSON="$(write_cleanup_restore_evidence "$trigger" "$product_cleanup_json" "$target_restore_json")"; then
			FINALIZATION_JSON='{"status":"blocked","failure_details":[{"code":"cleanup_evidence_write_failed","classification":"environment","scope":"harness_cleanup_restore","message":"cleanup/restore evidence could not be written"}]}'
		fi
	fi
	FINALIZATION_DONE=1
	FINALIZATION_RUNNING=0
	printf '%s\n' "$FINALIZATION_JSON"
}

handle_signal() {
	local exit_code="$1"
	trap - HUP INT TERM
	finalize_harness_state "signal" >/dev/null 2>&1 || true
	exit "$exit_code"
}

handle_exit() {
	local exit_code="$1"
	local cleanup_status
	trap - EXIT
	if [ "$FINALIZATION_DONE" -ne 1 ]; then
		finalize_harness_state "exit" >/dev/null 2>&1 || true
	fi
	if [ "$exit_code" -eq 0 ]; then
		cleanup_status="$(json_field "$FINALIZATION_JSON" status 2>/dev/null || true)"
		if [ "$cleanup_status" != "pass" ]; then
			exit_code=3
		fi
	fi
	exit "$exit_code"
}

trap 'handle_signal 129' HUP
trap 'handle_signal 130' INT
trap 'handle_signal 143' TERM
trap 'handle_exit $?' EXIT

finish_gate() {
	local status="$1"
	local reference_json="${2:-}"
	local target_json="${3:-}"
	local diagnostics_json="${4:-}"
	local failure_details_json="${5:-[]}"
	local merged_result_json
	local prefix="FAIL"
	local exit_code=1

	finalize_harness_state "normal" >/dev/null
	merged_result_json="$(python3 - "$status" "$failure_details_json" "$FINALIZATION_JSON" "$failures_file" <<'PY'
import json
import sys
from pathlib import Path

original_status = sys.argv[1]
try:
    original_details = json.loads(sys.argv[2])
except Exception:
    original_details = []
if not isinstance(original_details, list):
    original_details = []
try:
    finalization = json.loads(sys.argv[3])
except Exception:
    finalization = {
        "status": "blocked",
        "failure_details": [{
            "code": "cleanup_result_invalid",
            "classification": "environment",
            "scope": "harness_cleanup_restore",
            "message": "cleanup/restore result was invalid",
        }],
    }
cleanup_details = finalization.get("failure_details", [])
if not isinstance(cleanup_details, list):
    cleanup_details = []
status_details = []
if original_status not in {"pass", "fail", "blocked"}:
    status_details.append({
        "code": "invalid_evidence_status",
        "classification": "environment",
        "scope": "harness_evidence",
        "message": f"evidence producer returned an invalid status: {original_status or 'empty'}",
    })
details = [*original_details, *cleanup_details, *status_details]

if finalization.get("status") != "pass":
    effective_status = "blocked"
elif original_status == "fail":
    effective_status = "fail"
elif original_status == "blocked":
    effective_status = "blocked"
elif original_status == "pass":
    effective_status = "pass"
else:
    effective_status = "blocked"

failures_path = Path(sys.argv[4])
failures = failures_path.read_text(encoding="utf-8").splitlines() if failures_path.exists() else []
for item in details:
    message = str(item.get("message") or "unknown evidence failure")
    if message not in failures:
        failures.append(message)
failures_path.write_text("\n".join(failures) + ("\n" if failures else ""), encoding="utf-8")
print(json.dumps({"status": effective_status, "failure_details": details}, sort_keys=True))
PY
)"
	status="$(json_field "$merged_result_json" status)"
	failure_details_json="$(python3 - "$merged_result_json" <<'PY'
import json
import sys

print(json.dumps(json.loads(sys.argv[1]).get("failure_details", []), sort_keys=True))
PY
)"

	if [ "$status" = "pass" ]; then
		: > "$failures_file"
		if ! write_rollup "pass" "$reference_json" "$target_json" "" "$diagnostics_json" "$failure_details_json"; then
			printf 'BLOCKED: could not write multi-currency rollup evidence.\n' >&2
			exit 3
		fi
		printf 'PASS: native multi-currency rate refresh and storefront pricing matched the reference rate oracle.\n'
		exit 0
	elif [ "$status" = "blocked" ]; then
		prefix="BLOCKED"
		exit_code=3
	fi
	if ! write_rollup "$status" "$reference_json" "$target_json" "$failures_file" "$diagnostics_json" "$failure_details_json"; then
		printf 'BLOCKED: could not write multi-currency rollup evidence.\n' >&2
		exit 3
	fi
	while IFS= read -r failure; do
		[ -n "$failure" ] && printf '%s: %s\n' "$prefix" "$failure" >&2
	done < "$failures_file"
	exit "$exit_code"
}

block_probe_boundary() {
	local code="$1"
	local message="$2"
	local reference_json="${3:-}"
	local target_json="${4:-}"
	local diagnostics_json="${5:-}"
	local details_json

	printf '%s\n' "$message" > "$failures_file"
	details_json="$(python3 - "$code" "$message" <<'PY'
import json
import sys

print(json.dumps([{
    "code": sys.argv[1],
    "classification": "environment",
    "scope": "harness_boundary",
    "message": sys.argv[2],
}], sort_keys=True))
PY
)"
	finish_gate "blocked" "$reference_json" "$target_json" "$diagnostics_json" "$details_json"
}

progress "verify distinct local store identities"
if ! reference_identity_json="$(probe_site_identity "$REF_WP" "reference")"; then
	block_probe_boundary "reference_store_identity_unavailable" "reference store identity probe unavailable"
fi
if ! target_identity_json="$(probe_site_identity "$TARGET_WP" "target")"; then
	block_probe_boundary "target_store_identity_unavailable" "target store identity probe unavailable"
fi
if ! STORE_IDENTITIES_JSON="$(validate_site_identities "$reference_identity_json" "$target_identity_json")"; then
	block_probe_boundary "store_identity_evidence_write_failed" "store identity evidence could not be written"
fi
identity_status="$(json_field "$STORE_IDENTITIES_JSON" status 2>/dev/null || true)"
if [ "$identity_status" != "pass" ]; then
	identity_failure_details="$(python3 - "$STORE_IDENTITIES_JSON" <<'PY'
import json
import sys

payload = json.loads(sys.argv[1])
print(json.dumps(payload.get("failure_details", []), sort_keys=True))
PY
)"
	identity_message="$(python3 - "$STORE_IDENTITIES_JSON" <<'PY'
import json
import sys

details = json.loads(sys.argv[1]).get("failure_details", [])
print(str(details[0].get("message") if details else "store identity verification failed"))
PY
)"
	printf '%s\n' "$identity_message" > "$failures_file"
	finish_gate "blocked" "" "" "$STORE_IDENTITIES_JSON" "$identity_failure_details"
fi
diagnostics_json="$STORE_IDENTITIES_JSON"

if ! assert_target_native_owner; then
	owner_message="$(head -1 "$failures_file")"
	owner_details="$(python3 - "$owner_message" <<'PY'
import json
import sys

print(json.dumps([{
    "code": "target_native_owner_unavailable",
    "classification": "environment",
    "scope": "target_preflight",
    "message": sys.argv[1],
}], sort_keys=True))
PY
)"
	finish_gate "blocked" "" "" "$diagnostics_json" "$owner_details"
fi

progress "read reference rates through the standalone plugin API client"
if ! reference_client_json="$(wp_eval_json "$REF_WP" "reference client diagnostics" "$reference_client_probe_php")"; then
	block_probe_boundary "reference_rate_oracle_unavailable" "reference rate oracle unavailable"
fi
diagnostics_json="$(python3 - "$STORE_IDENTITIES_JSON" "$reference_client_json" <<'PY'
import json
import sys

try:
    identities = json.loads(sys.argv[1])
except Exception:
    identities = {"raw": sys.argv[1]}
try:
    reference_client = json.loads(sys.argv[2])
except Exception:
    reference_client = {"raw": sys.argv[2]}

print(json.dumps({"store_identities": identities, "reference_client": reference_client}, sort_keys=True))
PY
)"
if ! reference_status="$(write_reference_oracle_evidence "$reference_client_json")"; then
	block_probe_boundary "reference_evidence_write_failed" "reference rate evidence could not be written" "" "" "$diagnostics_json"
fi
if ! reference_json="$(python3 - "$OUT_DIR/reference-rate-refresh.json" <<'PY'
import json
import sys
from pathlib import Path

print(json.dumps(json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))["observation"], sort_keys=True))
PY
)"; then
	block_probe_boundary "reference_evidence_read_failed" "reference rate evidence could not be read" "" "" "$diagnostics_json"
fi
if [ "$reference_status" != "pass" ]; then
	reference_failure_details="$(python3 - "$OUT_DIR/reference-rate-refresh.json" "$failures_file" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
details = payload["freshness"].get("failure_details", [])
failures = [str(item.get("message") or "reference rate oracle unavailable") for item in details]
Path(sys.argv[2]).write_text("\n".join(failures) + ("\n" if failures else ""), encoding="utf-8")
print(json.dumps(details, sort_keys=True))
PY
)"
	finish_gate "blocked" "$reference_json" "" "$diagnostics_json" "$reference_failure_details"
fi

progress "snapshot target multi-currency options"
if ! snapshot_store_options "$TARGET_WP" "target" > "$TARGET_SNAPSHOT_FILE"; then
	block_probe_boundary "target_option_snapshot_unavailable" "target option snapshot probe unavailable" "$reference_json" "" "$diagnostics_json"
fi
if ! snapshot_error="$(validate_snapshot_json "$TARGET_SNAPSHOT_FILE" "target" 2>&1)"; then
	block_probe_boundary "target_option_snapshot_invalid" "target option snapshot is invalid: $snapshot_error" "$reference_json" "" "$diagnostics_json"
fi

progress "configure target automatic rates"
TARGET_RESTORE_REQUIRED=1
if ! target_configure_json="$(wp_eval_json "$TARGET_WP" "target" "$configure_php")"; then
	block_probe_boundary "target_configuration_unavailable" "target automatic-rate configuration probe unavailable" "$reference_json" "" "$diagnostics_json"
fi

progress "refresh target rate cache"
if ! target_build_json="$(wp_eval_json "$TARGET_WP" "target" "$build_php")"; then
	block_probe_boundary "target_refresh_probe_unavailable" "target rate refresh probe unavailable" "$reference_json" "" "$diagnostics_json"
fi

if ! target_json="$(wp_eval_json "$TARGET_WP" "target" "$inspect_php")"; then
	block_probe_boundary "target_rate_inspection_unavailable" "target rate inspection probe unavailable" "$reference_json" "" "$diagnostics_json"
fi

if ! write_refresh_evidence "target" "$target_configure_json" "$target_build_json" "$target_json" >/dev/null; then
	block_probe_boundary "target_evidence_write_failed" "target rate evidence could not be written" "$reference_json" "$target_json" "$diagnostics_json"
fi

rate_validation_json="$(python3 - "$OUT_DIR/reference-rate-refresh.json" "$OUT_DIR/target-rate-refresh.json" "$failures_file" <<'PY'
import json
import math
import sys
from pathlib import Path

reference = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
target = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
failures_file = Path(sys.argv[3])
details = [
    *reference["freshness"].get("failure_details", []),
    *target["freshness"].get("failure_details", []),
]

if not details:
    reference_rates = reference["observation"].get("rates") or {}
    target_rates = target["observation"].get("rates") or {}
    for currency, reference_rate in sorted(reference_rates.items()):
        target_rate = target_rates.get(currency)
        try:
            matches = target_rate is not None and math.isclose(
                float(reference_rate),
                float(target_rate),
                rel_tol=0.000001,
                abs_tol=0.000001,
            )
        except (TypeError, ValueError):
            matches = False
        if not matches:
            details.append({
                "code": "rate_oracle_mismatch",
                "classification": "core",
                "scope": "rate_parity",
                "message": f"rate mismatch for {currency}: reference={reference_rate} target={target_rate}",
            })

if any(item.get("classification") == "core" for item in details):
    status = "fail"
elif details:
    status = "blocked"
else:
    status = "pass"

failures = []
for item in details:
    message = str(item.get("message") or "unknown multi-currency evidence failure")
    if message not in failures:
        failures.append(message)
failures_file.write_text("\n".join(failures) + ("\n" if failures else ""), encoding="utf-8")
print(json.dumps({"status": status, "failure_details": details}, sort_keys=True))
PY
)"
rate_status="$(json_field "$rate_validation_json" status)"
rate_failure_details="$(python3 - "$rate_validation_json" <<'PY'
import json
import sys

print(json.dumps(json.loads(sys.argv[1]).get("failure_details", []), sort_keys=True))
PY
)"
if [ "$rate_status" != "pass" ]; then
	finish_gate "$rate_status" "$reference_json" "$target_json" "$diagnostics_json" "$rate_failure_details"
fi

progress "prepare target storefront evidence product"
STOREFRONT_PRODUCT_CLEANUP_REQUIRED=1
if ! storefront_prepare_json="$(wp_eval_json "$TARGET_WP" "target storefront product" "$storefront_prepare_php")"; then
	block_probe_boundary "storefront_product_prepare_unavailable" "target storefront evidence product probe unavailable" "$reference_json" "$target_json" "$diagnostics_json"
fi

STOREFRONT_PRODUCT_ID="$(json_field "$storefront_prepare_json" product_id 2>/dev/null || true)"
storefront_product_sku="$(json_field "$storefront_prepare_json" sku 2>/dev/null || true)"
storefront_product_url="$(json_field "$storefront_prepare_json" product_url 2>/dev/null || true)"
storefront_prepared="$(json_field "$storefront_prepare_json" prepared 2>/dev/null || true)"

browser_json='{"schema":"woopayments_mc_storefront_browser_observation.v1","probe_status":"blocked","error_code":"storefront_product_not_prepared"}'
if [ "$storefront_prepared" = "true" ] && [ "$storefront_product_sku" = "$STOREFRONT_PRODUCT_SKU" ] && [ -n "$STOREFRONT_PRODUCT_ID" ] && [ -n "$storefront_product_url" ]; then
		if validate_local_storefront_url "$storefront_product_url" "$TARGET_URL"; then
		write_storefront_driver
		progress "probe target storefront price with Playwright"
		browser_raw="$(PLAYWRIGHT_RUNNER_STATE_JSON="$storefront_prepare_json" "$PLAYWRIGHT_SCRIPT_RUNNER_BIN" "$OUT_DIR/mc-rates-storefront-driver.mjs" --timeout 60000 2>&1)"
		browser_rc=$?
		browser_json="$(printf '%s\n' "$browser_raw" | last_json_line)"
		if [ "$browser_rc" -ne 0 ] || [ -z "$browser_json" ]; then
			browser_json="$(python3 - "$browser_raw" <<'PY'
import json
import sys

print(json.dumps({
    "schema": "woopayments_mc_storefront_browser_observation.v1",
    "probe_status": "blocked",
    "error_code": "browser_runner_failed",
    "diagnostic_tail": "\n".join(sys.argv[1].splitlines()[-20:]),
}, sort_keys=True))
PY
)"
		fi
	else
		browser_json='{"schema":"woopayments_mc_storefront_browser_observation.v1","probe_status":"blocked","error_code":"non_local_storefront_url"}'
	fi
fi

progress "clean up target storefront evidence product"
if cleanup_json="$(cleanup_storefront_product "$STOREFRONT_PRODUCT_ID" "$STOREFRONT_PRODUCT_SKU")"; then
	cleanup_confirmed="$(json_field "$cleanup_json" cleaned 2>/dev/null || true)"
	if [ "$cleanup_confirmed" = "true" ]; then
		STOREFRONT_PRODUCT_ID=""
		STOREFRONT_PRODUCT_SKU=""
		STOREFRONT_PRODUCT_CLEANUP_REQUIRED=0
	fi
else
	cleanup_json="$(python3 - "$STOREFRONT_PRODUCT_ID" "$STOREFRONT_PRODUCT_SKU" <<'PY'
import json
import sys

print(json.dumps({"cleaned": False, "product_id": int(sys.argv[1] or 0), "sku": sys.argv[2]}, sort_keys=True))
PY
)"
fi
PRODUCT_CLEANUP_JSON="$cleanup_json"

if ! storefront_status="$(write_storefront_evidence "$storefront_prepare_json" "$browser_json" "$cleanup_json")"; then
	block_probe_boundary "storefront_evidence_write_failed" "target storefront evidence could not be written" "$reference_json" "$target_json" "$diagnostics_json"
fi
storefront_failure_details="$(python3 - "$OUT_DIR/target-storefront-price.json" "$failures_file" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
details = payload.get("failure_details", [])
failures = []
for item in details:
    message = str(item.get("message") or "unknown storefront evidence failure")
    if message not in failures:
        failures.append(message)
Path(sys.argv[2]).write_text("\n".join(failures) + ("\n" if failures else ""), encoding="utf-8")
print(json.dumps(details, sort_keys=True))
PY
)"
if [ "$storefront_status" != "pass" ]; then
	finish_gate "$storefront_status" "$reference_json" "$target_json" "$diagnostics_json" "$storefront_failure_details"
fi

: > "$failures_file"
finish_gate "pass" "$reference_json" "$target_json" "$diagnostics_json" "[]"
