#!/usr/bin/env bash
#
# Local payment-method checkout gate for the WooPayments -> core merge harness.
#
# This local-only transition harness configures per-method fixtures and fails closed
# until the submit-capable Playwriter checkout driver is available.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

METHODS_CSV=""
REF_WP=""
TARGET_WP=""
REF_URL="${REF_URL:-}"
TARGET_URL="${TARGET_URL:-}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/lpm-checkout-gate"
SURFACE="classic"
PRINT_PLAN=0
PREFLIGHT_ONLY=0
RESTORE_LPM_FIXTURES=0
LPM_FIXTURE_SNAPSHOT_DIR=""

usage() {
	cat >&2 <<'USAGE'
usage:
  lpm-checkout-gate.sh --methods <ids> --ref "<ref wp>" --target "<target wp>" [options]

Options:
  --methods <ids>             Comma-separated method ids.
  --ref "<wp>"                Reference store WP-CLI command.
  --target "<wp>"             Target store WP-CLI command.
  --ref-url <url>             Browser base URL for the reference store.
  --target-url <url>          Browser base URL for the target store.
  --playwriter-session <id>   Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>            Evidence output directory.
  --surface classic|blocks    Checkout surface to drive. Default: classic.
  --preflight-only            Validate arguments and local dependencies, then exit.
  --print-plan                Print the normalized method fixture plan as JSON, then exit.
  -h, --help                  Show this help.

Supported ids:
  Wave 1: sepa_debit, ideal, bancontact, klarna, affirm, afterpay_clearpay
  Wave 2: eps, p24, multibanco, au_becs_debit, grabpay, wechat_pay, alipay
USAGE
}

progress() {
	printf 'LPM checkout gate: %s\n' "$*" >&2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

fail() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--methods=*) METHODS_CSV="${1#--methods=}"; shift ;;
		--methods) METHODS_CSV="${2:-}"; shift 2 ;;
		--ref=*) REF_WP="${1#--ref=}"; shift ;;
		--ref) REF_WP="${2:-}"; shift 2 ;;
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--ref-url=*) REF_URL="${1#--ref-url=}"; shift ;;
		--ref-url) REF_URL="${2:-}"; shift 2 ;;
		--target-url=*) TARGET_URL="${1#--target-url=}"; shift ;;
		--target-url) TARGET_URL="${2:-}"; shift 2 ;;
		--playwriter-session=*) PLAYWRITER_SESSION="${1#--playwriter-session=}"; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--surface=*) SURFACE="${1#--surface=}"; shift ;;
		--surface) SURFACE="${2:-}"; shift 2 ;;
		--preflight-only) PREFLIGHT_ONLY=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ -z "$METHODS_CSV" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ]; then
	usage_error "both --methods, --ref, and --target are required."
fi

case "$SURFACE" in
	classic|blocks) ;;
	*) usage_error "unsupported surface: $SURFACE" ;;
esac

IFS=',' read -r -a METHODS <<< "$METHODS_CSV"
if [ "${#METHODS[@]}" -eq 0 ]; then
	usage_error "--methods must name at least one method."
fi

method_fixture() {
	local method="$1"

	case "$method" in
		sepa_debit)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_sepa_debit" "sepa_debit" "debit"
			;;
		ideal)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_ideal" "ideal" "redirect"
			;;
		bancontact)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "BE" "woocommerce_payments_bancontact" "bancontact" "redirect"
			;;
		klarna)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_klarna" "klarna" "bnpl"
			;;
		affirm)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "woocommerce_payments_affirm" "affirm" "bnpl"
			;;
		afterpay_clearpay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "woocommerce_payments_afterpay_clearpay" "afterpay_clearpay" "bnpl"
			;;
		eps)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "AT" "woocommerce_payments_eps" "eps" "redirect"
			;;
		p24)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "PLN" "PL" "woocommerce_payments_p24" "p24" "redirect"
			;;
		multibanco)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "PT" "woocommerce_payments_multibanco" "multibanco" "voucher"
			;;
		au_becs_debit)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "AUD" "AU" "woocommerce_payments_au_becs_debit" "au_becs_debit" "debit"
			;;
		grabpay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "SGD" "SG" "woocommerce_payments_grabpay" "grabpay" "redirect"
			;;
		wechat_pay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_wechat_pay" "wechat_pay" "redirect"
			;;
		alipay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_alipay" "alipay" "redirect"
			;;
		*)
			return 1
			;;
	esac
}

for raw_method in "${METHODS[@]}"; do
	method="$(printf '%s' "$raw_method" | tr -d '[:space:]')"
	if [ -z "$method" ]; then
		usage_error "--methods contains an empty method id."
	fi
	if ! method_fixture "$method" >/dev/null; then
		usage_error "unsupported method: $method"
	fi
done

print_plan() {
	python3 - "$REF_WP" "$TARGET_WP" "$SURFACE" "${METHODS[@]}" <<'PY'
import json
import sys

ref, target, surface, *methods = sys.argv[1:]
fixtures = {
    "sepa_debit": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_sepa_debit",
        "stripe_payment_method_type": "sepa_debit",
        "family": "debit",
    },
    "ideal": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_ideal",
        "stripe_payment_method_type": "ideal",
        "family": "redirect",
    },
    "bancontact": {
        "currency": "EUR",
        "country": "BE",
        "gateway_id": "woocommerce_payments_bancontact",
        "stripe_payment_method_type": "bancontact",
        "family": "redirect",
    },
    "klarna": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_klarna",
        "stripe_payment_method_type": "klarna",
        "family": "bnpl",
    },
    "affirm": {
        "currency": "USD",
        "country": "US",
        "gateway_id": "woocommerce_payments_affirm",
        "stripe_payment_method_type": "affirm",
        "family": "bnpl",
    },
    "afterpay_clearpay": {
        "currency": "USD",
        "country": "US",
        "gateway_id": "woocommerce_payments_afterpay_clearpay",
        "stripe_payment_method_type": "afterpay_clearpay",
        "family": "bnpl",
    },
    "eps": {
        "currency": "EUR",
        "country": "AT",
        "gateway_id": "woocommerce_payments_eps",
        "stripe_payment_method_type": "eps",
        "family": "redirect",
    },
    "p24": {
        "currency": "PLN",
        "country": "PL",
        "gateway_id": "woocommerce_payments_p24",
        "stripe_payment_method_type": "p24",
        "family": "redirect",
    },
    "multibanco": {
        "currency": "EUR",
        "country": "PT",
        "gateway_id": "woocommerce_payments_multibanco",
        "stripe_payment_method_type": "multibanco",
        "family": "voucher",
    },
    "au_becs_debit": {
        "currency": "AUD",
        "country": "AU",
        "gateway_id": "woocommerce_payments_au_becs_debit",
        "stripe_payment_method_type": "au_becs_debit",
        "family": "debit",
    },
    "grabpay": {
        "currency": "SGD",
        "country": "SG",
        "gateway_id": "woocommerce_payments_grabpay",
        "stripe_payment_method_type": "grabpay",
        "family": "redirect",
    },
    "wechat_pay": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_wechat_pay",
        "stripe_payment_method_type": "wechat_pay",
        "family": "redirect",
    },
    "alipay": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_alipay",
        "stripe_payment_method_type": "alipay",
        "family": "redirect",
    },
}
print(
    json.dumps(
        {
            "schema": "woopayments_lpm_checkout_gate_plan.v1",
            "surface": surface,
            "ref_wp": ref,
            "target_wp": target,
            "methods": methods,
            "fixtures": {method: fixtures[method] for method in methods},
        },
        sort_keys=True,
    )
)
PY
}

validate_local_url() {
	local label="$1"
	local url="$2"

	python3 - "$label" "$url" <<'PY'
import sys
from urllib.parse import urlparse

label, value = sys.argv[1:]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"{label} store browser URL must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"{label} store browser URL must stay local, got {value}")
PY
}

wp_home_url() {
	local label="$1"
	local wp_cmd="$2"
	local output
	local url

	if ! output="$(eval "$wp_cmd option get home" 2>&1)"; then
		blocked "$label store home URL probe failed: $output"
	fi

	url="$(printf '%s\n' "$output" | awk 'NF { value = $0 } END { print value }')"
	url="${url%/}"
	if [ -z "$url" ]; then
		blocked "$label store home URL probe returned an empty URL."
	fi

	validate_local_url "$label" "$url" || blocked "$label store home URL failed local validation."

	printf '%s\n' "$url"
}

wp_checkout_page_id() {
	local label="$1"
	local wp_cmd="$2"
	local output
	local page_id

	if [ "$SURFACE" = "classic" ]; then
		if output="$(eval "$wp_cmd post list --post_type=page --post_status=publish --fields=ID,post_title,post_name --format=json" 2>&1)"; then
			page_id="$(
				python3 - "$output" <<'PY'
import json
import sys

raw = sys.argv[1]
start = raw.find("[")
if start == -1:
    raise SystemExit(0)
try:
    pages = json.loads(raw[start:])
except Exception:
    raise SystemExit(0)
for page in pages:
    title = str(page.get("post_title", "")).lower()
    slug = str(page.get("post_name", "")).lower()
    if "classic-checkout" in slug or "classic checkout" in title:
        print(page.get("ID", ""))
        break
PY
			)"
			if [ -n "$page_id" ] && [ "$page_id" -gt 0 ]; then
				printf '%s\n' "$page_id"
				return
			fi
		fi
	fi

	if ! output="$(eval "$wp_cmd option get woocommerce_checkout_page_id" 2>&1)"; then
		blocked "$label store checkout page probe failed: $output"
	fi

	page_id="$(printf '%s\n' "$output" | awk '/^[0-9]+$/ { value = $0 } END { print value }')"
	if [ -z "$page_id" ] || [ "$page_id" -le 0 ]; then
		blocked "$label store checkout page probe returned no positive page ID: $output"
	fi

	printf '%s\n' "$page_id"
}

wp_product_id() {
	local label="$1"
	local wp_cmd="$2"
	local output
	local product_id

	if ! output="$(eval "$wp_cmd wc product list --user=1 --type=simple --status=publish --orderby=id --order=asc --per_page=1 --field=id" 2>&1)"; then
		blocked "$label store product probe failed: $output"
	fi

	product_id="$(printf '%s\n' "$output" | awk '/^[0-9]+$/ { value = $0 } END { print value }')"
	if [ -z "$product_id" ] || [ "$product_id" -le 0 ]; then
		blocked "$label store product probe returned no positive product ID: $output"
	fi

	printf '%s\n' "$product_id"
}

browser_base_url() {
	local label="$1"
	local wp_cmd="$2"
	local explicit_url="$3"

	if [ -n "$explicit_url" ]; then
		explicit_url="${explicit_url%/}"
		validate_local_url "$label" "$explicit_url" || blocked "$label store browser URL failed local validation."
		printf '%s\n' "$explicit_url"
		return
	fi

	wp_home_url "$label" "$wp_cmd"
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

extract_json_line() {
	grep -E '^\{' | tail -1
}

json_success() {
	python3 - "$1" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    payload = json.load(stream)
raise SystemExit(0 if isinstance(payload, dict) and payload.get("success") else 1)
PY
}

json_errors() {
	python3 - "$1" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    raise SystemExit(0)
for error in payload.get("errors", []):
    print(f"  - {error}", file=sys.stderr)
PY
}

stage_lpm_fixture() {
	local role="$1"
	local wp_cmd="$2"
	local method="$3"
	local currency="$4"
	local country="$5"
	local payload_b64 raw rc json response_path snapshot_path

	response_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-${method}-stage.json"
	snapshot_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-initial-stage.json"
	payload_b64="$(
		python3 - "$method" "$currency" "$country" <<'PY'
import base64
import json
import sys

print(base64.b64encode(json.dumps(sys.argv[1:]).encode("utf-8")).decode("ascii"))
PY
	)"

	progress "staging $role LPM fixture for $method ($currency/$country)"
	raw="$(eval "$wp_cmd eval-file - stage-lpm-fixture '$payload_b64'" <<'PHP' 2>&1
<?php
$payload_b64     = isset( $args[1] ) ? (string) $args[1] : '';
$decoded_payload = json_decode( base64_decode( $payload_b64 ), true );
$errors          = array();
$settings_option = 'woocommerce_woocommerce_payments_settings';
$account_option = 'wcpay_account_data';
$missing_marker  = '__woopayments_merge_missing_option__';

if ( ! is_array( $decoded_payload ) || 3 !== count( $decoded_payload ) ) {
	$errors[]        = 'LPM fixture payload was invalid.';
	$method          = '';
	$currency        = '';
	$country         = '';
} else {
	$method   = sanitize_key( (string) $decoded_payload[0] );
	$currency = strtoupper( sanitize_text_field( (string) $decoded_payload[1] ) );
	$country  = strtoupper( sanitize_text_field( (string) $decoded_payload[2] ) );
}

$split_settings_option = 'woocommerce_woocommerce_payments_' . $method . '_settings';
$capability_key = $method . '_payments';
$previous_settings        = get_option( $settings_option, $missing_marker );
$previous_split_settings  = get_option( $split_settings_option, $missing_marker );
$previous_account_cache   = get_option( $account_option, $missing_marker );
$previous_currency        = get_option( 'woocommerce_currency', $missing_marker );
$previous_country         = get_option( 'woocommerce_default_country', $missing_marker );

if ( '' === $method || '' === $currency || '' === $country ) {
	$errors[] = 'LPM fixture method, currency, and country are required.';
}

if ( empty( $errors ) ) {
	$enabled_methods                            = array_values( array_unique( array( 'card', $method ) ) );
	$settings                                   = is_array( $previous_settings ) ? $previous_settings : array();
	$settings['enabled']                       = 'yes';
	$settings['test_mode']                     = 'yes';
	$settings['manual_capture']                = 'no';
	$settings['upe_enabled_payment_method_ids'] = array_values( array_unique( array( 'card', $method ) ) );
	$split_settings                             = is_array( $previous_split_settings ) ? $previous_split_settings : array();
	$split_settings['enabled']                 = 'yes';
	$split_settings['saved_cards']             = 'yes';
	$split_settings['test_mode']               = 'yes';
	$split_settings['manual_capture']          = 'no';
	$split_settings['upe_enabled_payment_method_ids'] = $enabled_methods;
	$account_cache                             = is_array( $previous_account_cache ) ? $previous_account_cache : array();
	$account_data                              = isset( $account_cache['data'] ) && is_array( $account_cache['data'] )
		? $account_cache['data']
		: array();
	$account_data['account_id']                = isset( $account_data['account_id'] ) && is_scalar( $account_data['account_id'] )
		? (string) $account_data['account_id']
		: 'acct_lpm_fixture';
	$account_data['payments_enabled']          = true;
	$account_data['details_submitted']         = true;
	$account_data['status']                    = 'complete';
	$account_data['is_live']                   = false;
	$account_data['is_test_drive']             = true;
	$account_data['country'] = $country;
	$account_data['capabilities']              = isset( $account_data['capabilities'] ) && is_array( $account_data['capabilities'] )
		? $account_data['capabilities']
		: array();
	$account_data['capabilities']['card_payments'] = 'active';
	$account_data['capabilities'][ $capability_key ] = 'active';
	$account_data['capability_requirements']   = isset( $account_data['capability_requirements'] ) && is_array( $account_data['capability_requirements'] )
		? $account_data['capability_requirements']
		: array();
	$account_data['capability_requirements']['card_payments'] = array();
	$account_data['capability_requirements'][ $capability_key ] = array();
	$account_data['store_currencies']          = isset( $account_data['store_currencies'] ) && is_array( $account_data['store_currencies'] )
		? $account_data['store_currencies']
		: array();
	$account_data['store_currencies']['default'] = strtolower( $currency );
	$account_data['store_currencies']['supported'] = array_values(
		array_unique(
			array_merge(
				isset( $account_data['store_currencies']['supported'] ) && is_array( $account_data['store_currencies']['supported'] )
					? $account_data['store_currencies']['supported']
					: array(),
				array( strtolower( $currency ) )
			)
		)
	);
	$account_cache['data']                      = $account_data;
	$account_cache['fetched']                   = time();
	$account_cache['errored']                   = false;
	$account_cache['consecutive_errors']        = 0;

	update_option( $settings_option, $settings );
	update_option( $split_settings_option, $split_settings );
	update_option( $account_option, $account_cache, 'no' );
	wp_cache_delete( $account_option, 'options' );
	update_option( 'woocommerce_currency', $currency );
	update_option( 'woocommerce_default_country', $country );
}

echo wp_json_encode(
	array(
		'success'  => empty( $errors ),
		'mode'     => 'stage-lpm-fixture',
		'errors'   => $errors,
		'method'   => $method,
		'currency' => $currency,
		'country'  => $country,
		'previous' => array(
			'settings_exists' => $missing_marker !== $previous_settings,
			'settings'        => $missing_marker !== $previous_settings ? $previous_settings : null,
			'split_settings_option' => $split_settings_option,
			'split_settings_exists' => $missing_marker !== $previous_split_settings,
			'split_settings' => $missing_marker !== $previous_split_settings ? $previous_split_settings : null,
			'account_cache_exists' => $missing_marker !== $previous_account_cache,
			'account_cache' => $missing_marker !== $previous_account_cache ? $previous_account_cache : null,
			'currency_exists' => $missing_marker !== $previous_currency,
			'currency'        => $missing_marker !== $previous_currency ? $previous_currency : null,
			'country_exists'  => $missing_marker !== $previous_country,
			'country'         => $missing_marker !== $previous_country ? $previous_country : null,
		),
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocked "could not stage $role LPM fixture for $method: $raw"
	fi

	printf '%s\n' "$json" > "$response_path"
	if ! json_success "$response_path"; then
		json_errors "$response_path"
		blocked "could not stage $role LPM fixture for $method; see $response_path"
	fi

	if [ ! -f "$snapshot_path" ]; then
		cp "$response_path" "$snapshot_path" || blocked "could not preserve $role LPM fixture snapshot."
	fi
	RESTORE_LPM_FIXTURES=1
}

restore_lpm_fixture_for_role() {
	local role="$1"
	local wp_cmd="$2"
	local snapshot_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-initial-stage.json"
	local payload_b64 raw rc

	if [ ! -f "$snapshot_path" ]; then
		return
	fi

	progress "restoring $role LPM fixture"
	payload_b64="$(python3 - "$snapshot_path" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
)"

	raw="$(eval "$wp_cmd eval-file - restore-lpm-fixture '$payload_b64'" <<'PHP' 2>&1
<?php
$payload_b64     = isset( $args[1] ) ? (string) $args[1] : '';
$payload         = json_decode( base64_decode( $payload_b64 ), true );
$errors          = array();
$settings_option = 'woocommerce_woocommerce_payments_settings';
$account_option = 'wcpay_account_data';

if ( ! is_array( $payload ) || ! is_array( $payload['previous'] ?? null ) ) {
	$errors[] = 'Restore payload was invalid.';
} else {
	$previous = $payload['previous'];

	if ( ! empty( $previous['settings_exists'] ) ) {
		update_option( $settings_option, is_array( $previous['settings'] ) ? $previous['settings'] : array() );
	} else {
		delete_option( $settings_option );
	}

	$split_settings_option = isset( $previous['split_settings_option'] ) ? (string) $previous['split_settings_option'] : '';
	if ( '' !== $split_settings_option ) {
		if ( ! empty( $previous['split_settings_exists'] ) ) {
			update_option( $split_settings_option, is_array( $previous['split_settings'] ) ? $previous['split_settings'] : array() );
		} else {
			delete_option( $split_settings_option );
		}
	}

	if ( ! empty( $previous['account_cache_exists'] ) ) {
		update_option( $account_option, is_array( $previous['account_cache'] ) ? $previous['account_cache'] : array(), 'no' );
		wp_cache_delete( $account_option, 'options' );
	} else {
		delete_option( $account_option );
	}

	if ( ! empty( $previous['currency_exists'] ) ) {
		update_option( 'woocommerce_currency', (string) $previous['currency'] );
	} else {
		delete_option( 'woocommerce_currency' );
	}

	if ( ! empty( $previous['country_exists'] ) ) {
		update_option( 'woocommerce_default_country', (string) $previous['country'] );
	} else {
		delete_option( 'woocommerce_default_country' );
	}
}

echo wp_json_encode(
	array(
		'success' => empty( $errors ),
		'mode'    => 'restore-lpm-fixture',
		'errors'  => $errors,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
)"
	rc=$?

	if [ "$rc" -ne 0 ]; then
		printf 'LPM checkout gate: restore warning for %s: %s\n' "$role" "$raw" >&2
	fi
}

restore_lpm_fixtures() {
	if [ "$RESTORE_LPM_FIXTURES" -ne 1 ] || [ -z "$LPM_FIXTURE_SNAPSHOT_DIR" ]; then
		return
	fi

	RESTORE_LPM_FIXTURES=0
	restore_lpm_fixture_for_role target "$TARGET_WP"
	restore_lpm_fixture_for_role reference "$REF_WP"
}

validate_driver_evidence() {
	local role="$1"
	local method="$2"
	local base_url="$3"
	local gateway_id="$4"
	local stripe_type="$5"
	local evidence_path="$6"

	python3 - "$role" "$method" "$base_url" "$gateway_id" "$stripe_type" "$evidence_path" <<'PY'
import json
import sys
from urllib.parse import urlparse

role, method, base_url, gateway_id, stripe_type, evidence_path = sys.argv[1:]
errors = []
try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "role": role,
    "method": method,
    "base_url": base_url,
    "gateway_id": gateway_id,
    "stripe_payment_method_type": stripe_type,
}
for key, expected in expected_values.items():
    if payload.get(key) != expected:
        errors.append(f"{key} mismatch: expected {expected!r}, got {payload.get(key)!r}")

if payload.get("status") != "pass":
    errors.append(f"status is not pass: {payload.get('status')!r}")
if payload.get("order_id") in (None, "", 0):
    errors.append("missing order_id")

for key in ("selected_gateway_id", "order_payment_method", "order_received_url", "payment_intent_id"):
    if payload.get(key) in (None, "", 0):
        errors.append(f"missing {key}")

if payload.get("selected_gateway_id") not in (None, "", 0) and payload.get("selected_gateway_id") != gateway_id:
    errors.append(
        f"selected_gateway_id mismatch: expected {gateway_id!r}, got {payload.get('selected_gateway_id')!r}"
    )

if payload.get("order_payment_method") not in (None, "", 0) and payload.get("order_payment_method") != gateway_id:
    errors.append(
        f"order_payment_method mismatch: expected {gateway_id!r}, got {payload.get('order_payment_method')!r}"
    )

if payload.get("used_base_card_gateway") is not False:
    errors.append("used_base_card_gateway must be false")

order_received_url = payload.get("order_received_url")
if order_received_url not in (None, "", 0):
    parsed = urlparse(str(order_received_url))
    host = parsed.hostname or ""
    normalized_base = base_url.rstrip("/")
    if parsed.scheme not in {"http", "https"}:
        errors.append(f"order_received_url must be http(s), got {order_received_url!r}")
    if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
        errors.append(f"order_received_url must stay local, got {order_received_url!r}")
    if not str(order_received_url).startswith(f"{normalized_base}/checkout/order-received/"):
        errors.append(
            "order_received_url must use the expected store checkout order-received URL"
        )
    order_id = payload.get("order_id")
    if order_id not in (None, "", 0) and f"/order-received/{order_id}" not in str(order_received_url):
        errors.append("order_received_url does not include order_id")

payment_intent_id = payload.get("payment_intent_id")
if payment_intent_id not in (None, "", 0) and not str(payment_intent_id).startswith("pi_"):
    errors.append("payment_intent_id must be a Stripe PaymentIntent id")

for error in errors:
    print(error)

if errors:
    raise SystemExit(1)
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/lpm-checkout-gate.json"

	python3 - "$rollup_path" "$SURFACE" "$RESULTS_JSONL" "$FAILURES_FILE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, surface, results_jsonl, failures_file = sys.argv[1:]
results = []
results_path = Path(results_jsonl)
if results_path.exists():
    for line in results_path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            results.append(json.loads(line))

failures_path = Path(failures_file)
failures = []
if failures_path.exists():
    failures = [line for line in failures_path.read_text(encoding="utf-8").splitlines() if line.strip()]

payload = {
    "schema": "woopayments_lpm_checkout_gate_rollup.v1",
    "surface": surface,
    "status": "fail" if failures else "pass",
    "results": results,
    "failures": failures,
}
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

run_driver_for_store() {
	local role="$1"
	local base_url="$2"
	local method="$3"
	local currency="$4"
	local country="$5"
	local gateway_id="$6"
	local stripe_type="$7"
	local method_family="$8"
	local product_id="$9"
	local checkout_page_id="${10}"
	local evidence_path="$OUT_DIR/${role}-${method}-${SURFACE}.json"
	local log_path="$OUT_DIR/${role}-${method}-${SURFACE}.playwriter.log"
	local exit_code
	local validation_output
	local browser_config_js

	rm -f "$evidence_path"
	progress "driving $role checkout for $method on $base_url"

	browser_config_js="$(
		python3 - "$role" "$method" "$SURFACE" "$base_url" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$product_id" "$checkout_page_id" "$evidence_path" <<'PY'
import json
import sys

(
    role,
    method,
    surface,
    base_url,
    currency,
    country,
    gateway_id,
    stripe_type,
    method_family,
    product_id,
    checkout_page_id,
    evidence_path,
) = sys.argv[1:]
print(
    "state.lpmCheckoutConfig = "
    + json.dumps(
        {
            "role": role,
            "method": method,
            "surface": surface,
            "baseUrl": base_url,
            "currency": currency,
            "country": country,
            "gatewayId": gateway_id,
            "stripePaymentMethodType": stripe_type,
            "methodFamily": method_family,
            "productId": product_id,
            "checkoutPageId": checkout_page_id,
            "evidencePath": evidence_path,
        },
        sort_keys=True,
    )
    + ";"
)
PY
	)"

	"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -e "$browser_config_js" --timeout "30000" >"$log_path" 2>&1
	exit_code=$?
	if [ "$exit_code" -ne 0 ]; then
		record_failure "$role/$method: Playwriter config seed exited $exit_code; see $log_path"
		return
	fi

	LPM_GATE_ROLE="$role" \
	LPM_GATE_METHOD="$method" \
	LPM_GATE_SURFACE="$SURFACE" \
	LPM_GATE_BASE_URL="$base_url" \
	LPM_GATE_CURRENCY="$currency" \
	LPM_GATE_COUNTRY="$country" \
	LPM_GATE_GATEWAY_ID="$gateway_id" \
	LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE="$stripe_type" \
	LPM_GATE_METHOD_FAMILY="$method_family" \
	LPM_GATE_PRODUCT_ID="$product_id" \
	LPM_GATE_CHECKOUT_PAGE_ID="$checkout_page_id" \
	LPM_GATE_EVIDENCE_PATH="$evidence_path" \
	"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$SELF_DIR/lpm-checkout.playwriter.mjs" --timeout "300000" >>"$log_path" 2>&1
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "$role/$method: Playwriter exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "$role/$method: missing browser evidence $evidence_path"
		return
	fi

	if ! validation_output="$(validate_driver_evidence "$role" "$method" "$base_url" "$gateway_id" "$stripe_type" "$evidence_path" 2>&1)"; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$role/$method: $line"
			fi
		done <<< "$validation_output"
	fi

	if ! jq -c . "$evidence_path" >> "$RESULTS_JSONL"; then
		record_failure "$role/$method: could not append browser evidence to rollup."
	fi
}

if [ "$PRINT_PLAN" -eq 1 ]; then
	print_plan
	exit 0
fi

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
if ! command -v jq >/dev/null 2>&1; then
	blocked "jq is required."
fi
if ! command -v stripe >/dev/null 2>&1; then
	blocked "Stripe CLI is required."
fi
if [ ! -x "$SELF_DIR/parity-diff.sh" ]; then
	blocked "Bucket-E parity differ is missing or not executable: $SELF_DIR/parity-diff.sh"
fi

if [ -n "${PLAYWRITER_BIN:-}" ]; then
	# shellcheck disable=SC2206
	PLAYWRITER_CMD=( $PLAYWRITER_BIN )
elif command -v playwriter >/dev/null 2>&1; then
	PLAYWRITER_CMD=( playwriter )
elif command -v npx >/dev/null 2>&1; then
	PLAYWRITER_CMD=( npx --yes playwriter@latest )
else
	blocked "Playwriter is required. Install playwriter or provide PLAYWRITER_BIN."
fi

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok for methods: $METHODS_CSV"
	exit 0
fi

if [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running browser checkout flows."
fi

mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
LPM_FIXTURE_SNAPSHOT_DIR="$OUT_DIR/lpm-fixtures"
mkdir -p "$LPM_FIXTURE_SNAPSHOT_DIR" || blocked "could not create LPM fixture snapshot directory: $LPM_FIXTURE_SNAPSHOT_DIR"
RESULTS_JSONL="$OUT_DIR/lpm-checkout-results.jsonl"
FAILURES_FILE="$OUT_DIR/lpm-checkout-failures.txt"
: > "$RESULTS_JSONL"
: > "$FAILURES_FILE"

trap restore_lpm_fixtures EXIT

if [ ! -f "$SELF_DIR/lpm-checkout.playwriter.mjs" ]; then
	blocked "submit-capable browser driver is missing: $SELF_DIR/lpm-checkout.playwriter.mjs"
fi

REF_BASE_URL="$(browser_base_url reference "$REF_WP" "$REF_URL")"
TARGET_BASE_URL="$(browser_base_url target "$TARGET_WP" "$TARGET_URL")"
REF_PRODUCT_ID="$(wp_product_id reference "$REF_WP")"
TARGET_PRODUCT_ID="$(wp_product_id target "$TARGET_WP")"
REF_CHECKOUT_PAGE_ID="$(wp_checkout_page_id reference "$REF_WP")"
TARGET_CHECKOUT_PAGE_ID="$(wp_checkout_page_id target "$TARGET_WP")"

for raw_method in "${METHODS[@]}"; do
	method="$(printf '%s' "$raw_method" | tr -d '[:space:]')"
	IFS=$'\t' read -r method_id currency country gateway_id stripe_type method_family <<< "$(method_fixture "$method")"
	stage_lpm_fixture reference "$REF_WP" "$method_id" "$currency" "$country"
	stage_lpm_fixture target "$TARGET_WP" "$method_id" "$currency" "$country"
	run_driver_for_store reference "$REF_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$REF_PRODUCT_ID" "$REF_CHECKOUT_PAGE_ID"
	run_driver_for_store target "$TARGET_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$TARGET_PRODUCT_ID" "$TARGET_CHECKOUT_PAGE_ID"
done

write_rollup || blocked "could not write LPM checkout rollup."

if [ -s "$FAILURES_FILE" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/lpm-checkout-gate.json"
