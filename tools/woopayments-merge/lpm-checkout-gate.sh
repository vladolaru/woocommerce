#!/usr/bin/env bash
#
# Local payment-method checkout gate for the WooPayments -> core merge harness.
#
# This local-only transition harness configures per-method fixtures and fails closed
# until the submit-capable Playwriter checkout driver is available.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	printf 'FAIL: local runner safety library is missing: %s\n' "$LOCAL_RUNNER_SAFETY" >&2
	exit 2
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
PARITY_DIFF_BIN="${LPM_PARITY_DIFF_BIN:-$SELF_DIR/parity-diff.sh}"
PAYMENT_METHOD_FIXTURE_STATE="$SELF_DIR/payment-method-fixture-state.php"
LPM_EVIDENCE_HELPER="$SELF_DIR/lpm_evidence.py"

METHODS_CSV=""
REF_WP=""
TARGET_WP=""
REF_URL="${REF_URL:-}"
TARGET_URL="${TARGET_URL:-}"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
BROWSER_RUNNER="${BROWSER_RUNNER:-playwriter}"
PLAYWRIGHT_SCRIPT_RUNNER_BIN="${PLAYWRIGHT_SCRIPT_RUNNER_BIN:-$SELF_DIR/playwright-script-runner.mjs}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/lpm-checkout-gate"
SURFACE="classic"
PRINT_PLAN=0
PREFLIGHT_ONLY=0
ACTION_SCHEDULER_DRAIN_ENABLED=1
RESTORE_LPM_FIXTURES=0
REF_LPM_FIXTURE_RESTORE_REQUIRED=0
TARGET_LPM_FIXTURE_RESTORE_REQUIRED=0
LPM_FIXTURE_SNAPSHOT_DIR=""
CURRENT_METHOD=""
REF_PRODUCT_ID=""
REF_PRODUCT_SKU=""
REF_PRODUCT_CLEANUP_REQUIRED=0
TARGET_PRODUCT_ID=""
TARGET_PRODUCT_SKU=""
TARGET_PRODUCT_CLEANUP_REQUIRED=0
HARNESS_INITIALIZED=0
HARNESS_FINALIZATION_RUNNING=0
HARNESS_FINALIZATION_DONE=0
HARNESS_CLEANUP_FAILED=0

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
  --browser-runner <runner>   Browser runner: playwriter or playwright. Defaults to BROWSER_RUNNER or playwriter.
  --playwriter-session <id>   Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>            Evidence output directory.
  --surface classic|blocks    Checkout surface to drive. Default: classic.
  --preflight-only            Validate arguments and local dependencies, then exit.
  --skip-action-scheduler-drain
                              Skip reference and target queue drains before Bucket-E parity.
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
		--browser-runner=*) BROWSER_RUNNER="${1#--browser-runner=}"; shift ;;
		--browser-runner) BROWSER_RUNNER="${2:-}"; shift 2 ;;
		--playwriter-session=*) PLAYWRITER_SESSION="${1#--playwriter-session=}"; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--surface=*) SURFACE="${1#--surface=}"; shift ;;
		--surface) SURFACE="${2:-}"; shift 2 ;;
		--preflight-only) PREFLIGHT_ONLY=1; shift ;;
		--skip-action-scheduler-drain) ACTION_SCHEDULER_DRAIN_ENABLED=0; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ -z "$METHODS_CSV" ] || [ -z "$REF_WP" ] || [ -z "$TARGET_WP" ] || [ -z "$REF_URL" ] || [ -z "$TARGET_URL" ]; then
	usage_error "--methods, --ref, --target, --ref-url, and --target-url are required."
fi

if ! REF_URL="$(woopayments_normalize_local_url "$REF_URL")"; then
	usage_error "--ref-url must be an explicit local HTTP(S) URL."
fi
if ! TARGET_URL="$(woopayments_normalize_local_url "$TARGET_URL")"; then
	usage_error "--target-url must be an explicit local HTTP(S) URL."
fi
if [ "$REF_URL" = "$TARGET_URL" ]; then
	usage_error "--ref-url and --target-url must identify distinct local stores."
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$REF_WP")"; then
	usage_error "unsafe --ref WP runner: $runner_error"
fi
if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
	usage_error "unsafe --target WP runner: $runner_error"
fi

case "$SURFACE" in
	classic|blocks) ;;
	*) usage_error "unsupported surface: $SURFACE" ;;
esac

case "$BROWSER_RUNNER" in
	playwriter|playwright) ;;
	*) usage_error "unsupported browser runner: $BROWSER_RUNNER" ;;
esac

IFS=',' read -r -a METHODS <<< "$METHODS_CSV"
if [ "${#METHODS[@]}" -eq 0 ]; then
	usage_error "--methods must name at least one method."
fi

usd_cny_wallet_fixture() {
	local method="$1"
	local gateway_id="$2"
	local stripe_type="$3"
	local family="$4"
	local automation_disposition="${5:-automated}"

	printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "$gateway_id" "$stripe_type" "$family" "$automation_disposition"
}

method_fixture() {
	local method="$1"

	case "$method" in
		sepa_debit)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_sepa_debit" "sepa_debit" "debit" "automated"
			;;
		ideal)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "NL" "woocommerce_payments_ideal" "ideal" "redirect" "automated"
			;;
		bancontact)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "BE" "woocommerce_payments_bancontact" "bancontact" "redirect" "automated"
			;;
		klarna)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "woocommerce_payments_klarna" "klarna" "hosted_action" "manual_customer_action"
			;;
		affirm)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "woocommerce_payments_affirm" "affirm" "bnpl" "automated"
			;;
		afterpay_clearpay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "USD" "US" "woocommerce_payments_afterpay_clearpay" "afterpay_clearpay" "bnpl" "automated"
			;;
		eps)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "AT" "woocommerce_payments_eps" "eps" "redirect" "automated"
			;;
		p24)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "PLN" "PL" "woocommerce_payments_p24" "p24" "redirect" "automated"
			;;
		multibanco)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "EUR" "PT" "woocommerce_payments_multibanco" "multibanco" "voucher" "automated"
			;;
		au_becs_debit)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "AUD" "AU" "woocommerce_payments_au_becs_debit" "au_becs_debit" "debit" "automated"
			;;
		grabpay)
			printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$method" "SGD" "SG" "woocommerce_payments_grabpay" "grabpay" "redirect" "automated"
			;;
		wechat_pay)
			usd_cny_wallet_fixture "$method" "woocommerce_payments_wechat_pay" "wechat_pay" "customer_action" "manual_customer_action"
			;;
		alipay)
			usd_cny_wallet_fixture "$method" "woocommerce_payments_alipay" "alipay" "redirect"
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
	python3 - "$REF_WP" "$TARGET_WP" "$REF_URL" "$TARGET_URL" "$SURFACE" "$ACTION_SCHEDULER_DRAIN_ENABLED" "${METHODS[@]}" <<'PY'
import json
import sys

ref, target, ref_url, target_url, surface, drain_enabled, *methods = sys.argv[1:]


def usd_cny_wallet_fixture(gateway_id, stripe_payment_method_type, family):
    return {
        "currency": "USD",
        "country": "US",
        "gateway_id": gateway_id,
        "stripe_payment_method_type": stripe_payment_method_type,
        "family": family,
    }


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
        "currency": "USD",
        "country": "US",
        "gateway_id": "woocommerce_payments_klarna",
        "stripe_payment_method_type": "klarna",
        "family": "hosted_action",
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
    "wechat_pay": usd_cny_wallet_fixture("woocommerce_payments_wechat_pay", "wechat_pay", "customer_action"),
    "alipay": usd_cny_wallet_fixture("woocommerce_payments_alipay", "alipay", "redirect"),
}
for fixture in fixtures.values():
    fixture["automation_disposition"] = "automated"
fixtures["klarna"]["automation_disposition"] = "manual_customer_action"
fixtures["wechat_pay"]["automation_disposition"] = "manual_customer_action"
print(
    json.dumps(
        {
            "schema": "woopayments_lpm_checkout_gate_plan.v1",
            "surface": surface,
            "ref_wp": ref,
            "target_wp": target,
            "ref_url": ref_url,
            "target_url": target_url,
            "action_scheduler_drain_enabled": drain_enabled == "1",
            "methods": methods,
            "fixtures": {method: fixtures[method] for method in methods},
        },
        sort_keys=True,
    )
)
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
	if [ -z "$url" ]; then
		blocked "$label store home URL probe returned an empty URL."
	fi
	if ! url="$(woopayments_normalize_local_url "$url")"; then
		blocked "$label store home URL failed local validation."
	fi

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

prepare_lpm_product() {
	local role="$1"
	local wp_cmd="$2"
	local sku="$3"
	local payload_b64 output json product_id observed_role observed_sku

	if [ "$role" = "reference" ]; then
		REF_PRODUCT_SKU="$sku"
		REF_PRODUCT_CLEANUP_REQUIRED=1
	else
		TARGET_PRODUCT_SKU="$sku"
		TARGET_PRODUCT_CLEANUP_REQUIRED=1
	fi
	payload_b64="$(
		python3 - "$role" "$sku" <<'PY'
import base64
import json
import sys

print(base64.b64encode(json.dumps({"role": sys.argv[1], "sku": sys.argv[2]}).encode("utf-8")).decode("ascii"))
PY
	)"

	if ! output="$(eval "$wp_cmd eval-file - prepare-lpm-product '$payload_b64'" <<'PHP' 2>&1
<?php
$tool_args   = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$payload     = json_decode( base64_decode( (string) ( $tool_args[1] ?? '' ) ), true );
$role        = is_array( $payload ) ? sanitize_key( (string) ( $payload['role'] ?? '' ) ) : '';
$sku         = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['sku'] ?? '' ) ) : '';
$product_id  = 0;
$error_code  = '';

try {
	if ( '' === $role || '' === $sku || ! class_exists( 'WC_Product_Simple' ) ) {
		throw new RuntimeException( 'invalid_product_fixture_payload' );
	}
	if ( 0 < wc_get_product_id_by_sku( $sku ) ) {
		throw new RuntimeException( 'product_fixture_sku_collision' );
	}
	$product = new WC_Product_Simple();
	$product->set_name( 'WooPayments LPM Checkout Gate Product' );
	$product->set_sku( $sku );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '65.00' );
	$product->set_price( '65.00' );
	$product->set_virtual( true );
	$product->set_tax_status( 'none' );
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product_id = (int) $product->save();
} catch ( Throwable $error ) {
	$error_code = $error->getMessage();
}

echo wp_json_encode(
	array(
		'success'    => 0 < $product_id && '' === $error_code,
		'mode'       => 'prepare-lpm-product',
		'role'       => $role,
		'product_id' => $product_id,
		'sku'        => $sku,
		'price'      => '65.00',
		'virtual'    => true,
		'error_code' => $error_code,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
	)"; then
		blocked "$role store product fixture failed: $output"
	fi
	json="$(printf '%s\n' "$output" | extract_json_line)"
	if [ -z "$json" ]; then
		blocked "$role store product fixture returned no JSON evidence: $output"
	fi
	read -r product_id observed_role observed_sku <<< "$(
		python3 - "$json" <<'PY'
import json
import sys

payload = json.loads(sys.argv[1])
if payload.get("success") is not True or payload.get("mode") != "prepare-lpm-product":
    raise SystemExit(1)
print(payload.get("product_id", ""), payload.get("role", ""), payload.get("sku", ""))
PY
	)" || blocked "$role store product fixture was not prepared: $json"
	if [ -z "$product_id" ] || [ "$product_id" -le 0 ] || [ "$observed_role" != "$role" ] || [ "$observed_sku" != "$sku" ]; then
		blocked "$role store product fixture identity was invalid: $json"
	fi

	if [ "$role" = "reference" ]; then
		REF_PRODUCT_ID="$product_id"
	else
		TARGET_PRODUCT_ID="$product_id"
	fi
}

cleanup_lpm_product() {
	local role="$1"
	local wp_cmd="$2"
	local product_id="$3"
	local sku="$4"
	local response_path="$OUT_DIR/${role}-product-cleanup.json"
	local payload_b64 output json

	if [ -z "$sku" ]; then
		printf '{"success":true,"mode":"cleanup-lpm-product","cleaned":true,"not_required":true}\n' > "$response_path"
		return 0
	fi
	payload_b64="$(
		python3 - "$role" "$product_id" "$sku" <<'PY'
import base64
import json
import sys

payload = {"role": sys.argv[1], "product_id": int(sys.argv[2] or 0), "sku": sys.argv[3]}
print(base64.b64encode(json.dumps(payload).encode("utf-8")).decode("ascii"))
PY
	)"
	if ! output="$(eval "$wp_cmd eval-file - cleanup-lpm-product '$payload_b64'" <<'PHP' 2>&1
<?php
$tool_args  = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$payload    = json_decode( base64_decode( (string) ( $tool_args[1] ?? '' ) ), true );
$role       = is_array( $payload ) ? sanitize_key( (string) ( $payload['role'] ?? '' ) ) : '';
$product_id = is_array( $payload ) ? (int) ( $payload['product_id'] ?? 0 ) : 0;
$sku        = is_array( $payload ) ? sanitize_text_field( (string) ( $payload['sku'] ?? '' ) ) : '';
$error_code = '';

if ( 0 >= $product_id && '' !== $sku ) {
	$product_id = (int) wc_get_product_id_by_sku( $sku );
}
$product = 0 < $product_id ? wc_get_product( $product_id ) : false;
if ( ! $product ) {
	$cleaned = 0 === wc_get_product_id_by_sku( $sku );
} elseif ( '' === $sku || $product->get_sku() !== $sku ) {
	$cleaned    = false;
	$error_code = 'cleanup_product_identity_mismatch';
} else {
	$product->delete( true );
	$cleaned = ! wc_get_product( $product_id ) && 0 === wc_get_product_id_by_sku( $sku );
}

echo wp_json_encode(
	array(
		'success'    => $cleaned,
		'mode'       => 'cleanup-lpm-product',
		'role'       => $role,
		'cleaned'    => $cleaned,
		'product_id' => $product_id,
		'sku'        => $sku,
		'error_code' => $error_code,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
	)"; then
		printf '{"success":false,"mode":"cleanup-lpm-product","cleaned":false,"error_code":"cleanup_command_failed"}\n' > "$response_path"
		return 1
	fi
	json="$(printf '%s\n' "$output" | extract_json_line)"
	if [ -z "$json" ]; then
		printf '{"success":false,"mode":"cleanup-lpm-product","cleaned":false,"error_code":"cleanup_response_invalid"}\n' > "$response_path"
		return 1
	fi
	printf '%s\n' "$json" > "$response_path"
	if ! python3 - "$response_path" "$role" "$sku" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
raise SystemExit(0 if (
    payload.get("success") is True
    and payload.get("cleaned") is True
    and payload.get("mode") == "cleanup-lpm-product"
    and payload.get("role") == sys.argv[2]
    and payload.get("sku") == sys.argv[3]
) else 1)
PY
	then
		return 1
	fi

	if [ "$role" = "reference" ]; then
		REF_PRODUCT_ID=""
		REF_PRODUCT_SKU=""
		REF_PRODUCT_CLEANUP_REQUIRED=0
	else
		TARGET_PRODUCT_ID=""
		TARGET_PRODUCT_SKU=""
		TARGET_PRODUCT_CLEANUP_REQUIRED=0
	fi
	return 0
}

browser_base_url() {
	local label="$1"
	local wp_cmd="$2"
	local explicit_url="$3"

	local home_url

	home_url="$(wp_home_url "$label" "$wp_cmd")"
	if ! explicit_url="$(woopayments_normalize_local_url "$explicit_url")"; then
		blocked "$label store browser URL failed local validation."
	fi
	if ! woopayments_local_url_matches "$wp_cmd" "$home_url" "$explicit_url"; then
		blocked "$label WP runner home URL does not match the expected local browser URL."
	fi
	printf '%s\n' "$explicit_url"
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

record_blocker() {
	printf '%s\n' "$*" >> "$BLOCKERS_FILE"
}

record_cleanup_blocker() {
	local code="$1"
	local role="$2"
	local artifact="$3"
	local message="$4"

	HARNESS_CLEANUP_FAILED=1
	record_blocker "$message"
	record_blocker_detail "$code" "$role" "${CURRENT_METHOD:-all}" "$artifact" "$message"
}

record_blocker_detail() {
	local code="$1"
	local role="$2"
	local method="$3"
	local artifact="$4"
	local message="$5"
	local provenance_json="${6:-}"

	python3 - "$BLOCKER_DETAILS_JSONL" "$code" "$role" "$method" "$artifact" "$message" "$provenance_json" <<'PY'
import json
import sys
from pathlib import Path

path, code, role, method, artifact, message, provenance_json = sys.argv[1:]
detail = {
    "code": code,
    "role": role,
    "method": method,
    "artifact": artifact,
    "message": message,
}
if provenance_json:
    try:
        provenance = json.loads(provenance_json)
    except Exception:
        provenance = None
    if isinstance(provenance, dict):
        detail["provenance"] = provenance

with Path(path).open("a", encoding="utf-8") as stream:
    stream.write(json.dumps(detail, sort_keys=True) + "\n")
PY
}

stage_fixture_blocker_code() {
	local response_path="$1"
	local method="$2"

	python3 - "$response_path" "$method" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
method = sys.argv[2]
try:
    payload = json.loads(path.read_text(encoding="utf-8"))
except Exception:
    print("fixture_stage_rejected")
    raise SystemExit(0)

previous = payload.get("previous") if isinstance(payload.get("previous"), dict) else {}
capability_key = f"{method}_payments"
capability_status = str(previous.get("capability_status") or "").lower()
errors = payload.get("errors") if isinstance(payload.get("errors"), list) else []
has_capability_error = any(
    isinstance(error, str)
    and "connected WooPayments account capability" in error
    and capability_key in error
    for error in errors
)

if (
    payload.get("success") is False
    and payload.get("mode") == "stage-lpm-fixture"
    and payload.get("method") == method
    and previous.get("capability_key") == capability_key
    and capability_status not in {"", "active"}
    and has_capability_error
):
    print("account_capability_unavailable")
else:
    print("fixture_stage_rejected")
PY
}

extract_json_line() {
	python3 -c '
import json
import sys

raw = sys.stdin.read()
decoder = json.JSONDecoder()
candidates = []

for index, char in enumerate(raw):
    if char not in "{[":
        continue
    try:
        value, offset = decoder.raw_decode(raw[index:])
    except Exception:
        continue
    candidates.append((index, index + offset, value))

top_level = []
for candidate in candidates:
    start, end, _value = candidate
    if any(other_start < start and end <= other_end for other_start, other_end, _ in candidates):
        continue
    top_level.append(candidate)

if not top_level:
    raise SystemExit(1)

_start, _end, value = max(top_level, key=lambda item: item[1])
print(json.dumps(value, separators=(",", ":"), sort_keys=True))
'
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

account_profile_id_for_method() {
	case "$1" in
		p24|au_becs_debit|grabpay)
			printf '%s\n' "$1"
			return 0
			;;
		*)
			return 1
			;;
	esac
}

record_account_profile_readiness() {
	local role="$1"
	local wp_cmd="$2"
	local method="$3"
	local profile_id raw rc json profile_path summary blocker_message

	if ! profile_id="$(account_profile_id_for_method "$method")"; then
		return 0
	fi

	profile_path="$OUT_DIR/${role}-${method}-account-profile.json"
	raw="$(eval "$wp_cmd wcpay-dev test-lab account profile --profile=$profile_id --format=json" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocker_message="$role/$method: account profile readiness probe failed: $raw"
		record_blocker "$blocker_message"
		record_blocker_detail "account_profile_probe_failed" "$role" "$method" "$profile_path" "$blocker_message"
		return 3
	fi

	printf '%s\n' "$json" > "$profile_path"
	summary="$(
		python3 - "$profile_path" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    print("unparseable")
    raise SystemExit(0)

profile = payload.get("profile") if isinstance(payload.get("profile"), dict) else {}
checks = payload.get("checks") if isinstance(payload.get("checks"), dict) else {}
manual_provisioning = False

parts = [
    "status={}".format(payload.get("status", "unknown")),
    "ready={}".format(str(bool(payload.get("ready"))).lower()),
]

if "test_lab_provisionable" in profile:
    provisionable = bool(profile.get("test_lab_provisionable"))
    parts.append("provisionable={}".format(str(provisionable).lower()))
    if provisionable and not bool(payload.get("ready")):
        manual_provisioning = True

if profile.get("country"):
    parts.append("country={}".format(str(profile.get("country"))))

check_parts = []
for key in sorted(checks):
    value = checks.get(key)
    if not isinstance(value, dict):
        continue
    status = value.get("status") or value.get("capability_status") or "unknown"
    check_parts.append("{}:{}".format(key, status))

if check_parts:
    parts.append("checks={}".format(",".join(check_parts)))

if manual_provisioning:
    parts.append("provisioning=manual_account_lifecycle")

reason = profile.get("blocked_reason") or payload.get("message")
if reason:
    parts.append("reason={}".format(" ".join(str(reason).split())[:180]))

print(" ".join(parts))
PY
	)"

	if python3 - "$profile_path" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    raise SystemExit(1)

raise SystemExit(0 if payload.get("success") and payload.get("ready") else 1)
PY
	then
		return 0
	fi

	blocker_message="$role/$method: account profile $profile_id readiness $summary; see $profile_path"
	record_blocker "$blocker_message"
	record_blocker_detail "account_profile_ineligible" "$role" "$method" "$profile_path" "$blocker_message"
	return 3
}

stage_lpm_fixture() {
	local role="$1"
	local wp_cmd="$2"
	local method="$3"
	local currency="$4"
	local country="$5"
	local payload_b64 raw rc json response_path snapshot_path snapshot_tmp blocker_code blocker_message

	response_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-${method}-stage.json"
	snapshot_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-${method}-snapshot.json"
	payload_b64="$(
		python3 - "$method" "$currency" "$country" <<'PY'
import base64
import json
import sys

print(base64.b64encode(json.dumps(sys.argv[1:]).encode("utf-8")).decode("ascii"))
PY
	)"

	if ! record_account_profile_readiness "$role" "$wp_cmd" "$method"; then
		return 3
	fi

	progress "snapshotting $role LPM fixture state for $method"
	raw="$(eval "$wp_cmd eval-file - snapshot-lpm-fixture '$payload_b64'" < "$PAYMENT_METHOD_FIXTURE_STATE" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocker_message="$role/$method: could not snapshot LPM fixture state: $raw"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_snapshot_execution_failed" "$role" "$method" "$snapshot_path" "$blocker_message"
		return 3
	fi
	snapshot_tmp="$(mktemp "$LPM_FIXTURE_SNAPSHOT_DIR/.${role}-${method}-snapshot.XXXXXX")" || {
		blocker_message="$role/$method: could not allocate an atomic LPM fixture snapshot"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_snapshot_write_failed" "$role" "$method" "$snapshot_path" "$blocker_message"
		return 3
	}
	printf '%s\n' "$json" > "$snapshot_tmp"
	if ! python3 - "$snapshot_tmp" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
raise SystemExit(0 if (
    payload.get("success") is True
    and payload.get("mode") == "snapshot-lpm-fixture"
    and isinstance(payload.get("previous"), dict)
) else 1)
PY
	then
		rm -f "$snapshot_tmp"
		blocker_message="$role/$method: LPM fixture snapshot was invalid; see $snapshot_path"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_snapshot_rejected" "$role" "$method" "$snapshot_path" "$blocker_message"
		return 3
	fi
	if ! mv "$snapshot_tmp" "$snapshot_path"; then
		rm -f "$snapshot_tmp"
		blocker_message="$role/$method: could not commit the atomic LPM fixture snapshot"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_snapshot_write_failed" "$role" "$method" "$snapshot_path" "$blocker_message"
		return 3
	fi
	RESTORE_LPM_FIXTURES=1
	if [ "$role" = "reference" ]; then
		REF_LPM_FIXTURE_RESTORE_REQUIRED=1
	else
		TARGET_LPM_FIXTURE_RESTORE_REQUIRED=1
	fi

	progress "staging $role LPM fixture for $method ($currency/$country)"
	raw="$(eval "$wp_cmd eval-file - stage-lpm-fixture '$payload_b64'" < "$PAYMENT_METHOD_FIXTURE_STATE" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"

	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		blocker_message="$role/$method: could not stage LPM fixture: $raw"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_stage_execution_failed" "$role" "$method" "$response_path" "$blocker_message"
		record_account_profile_readiness "$role" "$wp_cmd" "$method" || true
		return 3
	fi

	printf '%s\n' "$json" > "$response_path"
	if ! json_success "$response_path"; then
		json_errors "$response_path"
		blocker_code="$(stage_fixture_blocker_code "$response_path" "$method")"
		blocker_message="$role/$method: could not stage LPM fixture; see $response_path"
		record_blocker "$blocker_message"
		record_blocker_detail "$blocker_code" "$role" "$method" "$response_path" "$blocker_message"
		record_account_profile_readiness "$role" "$wp_cmd" "$method" || true
		return 3
	fi
	if ! python3 - "$response_path" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
option_count = payload.get("stage_option_count")
verified_count = payload.get("stage_verified_count")
verified = (
    payload.get("success") is True
    and payload.get("mode") == "stage-lpm-fixture"
    and payload.get("stage_verified") is True
    and isinstance(option_count, int)
    and option_count > 0
    and verified_count == option_count
    and not payload.get("stage_mismatches")
)
raise SystemExit(0 if verified else 1)
PY
	then
		blocker_message="$role/$method: staged LPM fixture values failed runtime verification; see $response_path"
		record_blocker "$blocker_message"
		record_blocker_detail "fixture_stage_verification_failed" "$role" "$method" "$response_path" "$blocker_message"
		return 3
	fi

	return 0
}

restore_lpm_fixture_for_role() {
	local role="$1"
	local wp_cmd="$2"
	local snapshot_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-${CURRENT_METHOD}-snapshot.json"
	local response_path="$LPM_FIXTURE_SNAPSHOT_DIR/${role}-${CURRENT_METHOD}-restore.json"
	local payload_b64 raw rc json
	local restore_required

	if [ "$role" = "reference" ]; then
		restore_required="$REF_LPM_FIXTURE_RESTORE_REQUIRED"
	else
		restore_required="$TARGET_LPM_FIXTURE_RESTORE_REQUIRED"
	fi
	if [ "$restore_required" -ne 1 ]; then
		return 0
	fi

	if [ ! -f "$snapshot_path" ]; then
		record_cleanup_blocker "${role}_fixture_restore_failed" "$role" "$snapshot_path" "$role/$CURRENT_METHOD: required LPM fixture snapshot is missing"
		return 1
	fi

	progress "restoring $role LPM fixture"
	payload_b64="$(python3 - "$snapshot_path" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
)"

	raw="$(eval "$wp_cmd eval-file - restore-lpm-fixture '$payload_b64'" < "$PAYMENT_METHOD_FIXTURE_STATE" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | extract_json_line)"
	if [ "$rc" -ne 0 ] || [ -z "$json" ]; then
		printf '{"success":false,"mode":"restore-lpm-fixture","error_code":"restore_execution_failed"}\n' > "$response_path"
		record_cleanup_blocker "${role}_fixture_restore_failed" "$role" "$response_path" "$role/$CURRENT_METHOD: LPM fixture restoration command failed: $raw"
		return 1
	fi
	printf '%s\n' "$json" > "$response_path"
	if ! python3 - "$response_path" "$snapshot_path" <<'PY'
import json
import sys
from pathlib import Path

payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
snapshot = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
option_snapshot = snapshot.get("previous", {}).get("option_snapshot", {})
expected_count = len(option_snapshot) if isinstance(option_snapshot, dict) else 0
verified = payload.get("success") is True and payload.get("mode") == "restore-lpm-fixture"
if expected_count:
    verified = verified and (
        payload.get("option_count") == expected_count
        and payload.get("restored_count") == expected_count
        and payload.get("verified_count") == expected_count
        and not payload.get("mismatches")
    )
raise SystemExit(0 if verified else 1)
PY
	then
		record_cleanup_blocker "${role}_fixture_restore_failed" "$role" "$response_path" "$role/$CURRENT_METHOD: LPM fixture restoration failed verification; see $response_path"
		return 1
	fi
	if [ "$role" = "reference" ]; then
		REF_LPM_FIXTURE_RESTORE_REQUIRED=0
	else
		TARGET_LPM_FIXTURE_RESTORE_REQUIRED=0
	fi
	return 0
}

restore_lpm_fixtures() {
	if [ "$RESTORE_LPM_FIXTURES" -ne 1 ] || [ -z "$LPM_FIXTURE_SNAPSHOT_DIR" ]; then
		return
	fi

	local status=0

	restore_lpm_fixture_for_role target "$TARGET_WP" || status=1
	restore_lpm_fixture_for_role reference "$REF_WP" || status=1
	if [ "$REF_LPM_FIXTURE_RESTORE_REQUIRED" -eq 0 ] && [ "$TARGET_LPM_FIXTURE_RESTORE_REQUIRED" -eq 0 ]; then
		RESTORE_LPM_FIXTURES=0
	fi
	return "$status"
}

write_lpm_cleanup_evidence() {
	local trigger="$1"

	python3 - "$OUT_DIR/lpm-cleanup-restore.json" "$trigger" "$HARNESS_CLEANUP_FAILED" "$LPM_FIXTURE_SNAPSHOT_DIR" <<'PY'
import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
trigger = sys.argv[2]
cleanup_failed = sys.argv[3] == "1"
fixture_dir = Path(sys.argv[4])

def read_json(candidate, fallback):
    try:
        return json.loads(candidate.read_text(encoding="utf-8"))
    except Exception:
        return fallback

products = {}
for role in ("reference", "target"):
    products[role] = read_json(
        path.parent / f"{role}-product-cleanup.json",
        {"success": True, "cleaned": True, "not_required": True},
    )

fixture_restores = []
if fixture_dir.is_dir():
    for candidate in sorted(fixture_dir.glob("*-restore.json")):
        fixture_restores.append({"path": candidate.name, "result": read_json(candidate, {"success": False})})

payload = {
    "schema": "woopayments_lpm_cleanup_restore.v1",
    "status": "blocked" if cleanup_failed else "pass",
    "trigger": trigger,
    "products": products,
    "fixture_restores": fixture_restores,
}
path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps(payload, sort_keys=True))
PY
}

finalize_lpm_state() {
	local trigger="${1:-normal}"

	if [ "$HARNESS_FINALIZATION_DONE" -eq 1 ]; then
		return "$HARNESS_CLEANUP_FAILED"
	fi
	if [ "$HARNESS_FINALIZATION_RUNNING" -eq 1 ]; then
		HARNESS_CLEANUP_FAILED=1
		return 1
	fi
	HARNESS_FINALIZATION_RUNNING=1

	if ! restore_lpm_fixtures && [ "$RESTORE_LPM_FIXTURES" -eq 1 ]; then
		progress "retrying LPM fixture restoration once during finalization"
		restore_lpm_fixtures || true
	fi
	if [ "$TARGET_PRODUCT_CLEANUP_REQUIRED" -eq 1 ]; then
		if ! cleanup_lpm_product target "$TARGET_WP" "$TARGET_PRODUCT_ID" "$TARGET_PRODUCT_SKU"; then
			record_cleanup_blocker "target_product_cleanup_failed" "target" "$OUT_DIR/target-product-cleanup.json" "target: LPM evidence product cleanup failed"
		fi
	fi
	if [ "$REF_PRODUCT_CLEANUP_REQUIRED" -eq 1 ]; then
		if ! cleanup_lpm_product reference "$REF_WP" "$REF_PRODUCT_ID" "$REF_PRODUCT_SKU"; then
			record_cleanup_blocker "reference_product_cleanup_failed" "reference" "$OUT_DIR/reference-product-cleanup.json" "reference: LPM evidence product cleanup failed"
		fi
	fi
	if ! write_lpm_cleanup_evidence "$trigger" >/dev/null; then
		record_cleanup_blocker "cleanup_evidence_write_failed" "harness" "$OUT_DIR/lpm-cleanup-restore.json" "LPM cleanup/restore evidence could not be written"
	fi

	HARNESS_FINALIZATION_DONE=1
	HARNESS_FINALIZATION_RUNNING=0
	return "$HARNESS_CLEANUP_FAILED"
}

handle_lpm_signal() {
	local exit_code="$1"
	trap - HUP INT TERM EXIT
	finalize_lpm_state signal >/dev/null 2>&1 || true
	exit "$exit_code"
}

handle_lpm_exit() {
	local exit_code="$1"
	trap - HUP INT TERM EXIT
	if [ "$HARNESS_INITIALIZED" -eq 1 ] && [ "$HARNESS_FINALIZATION_DONE" -ne 1 ]; then
		finalize_lpm_state exit >/dev/null 2>&1 || true
	fi
	if [ "$exit_code" -eq 0 ] && [ "$HARNESS_CLEANUP_FAILED" -eq 1 ]; then
		exit_code=3
	fi
	exit "$exit_code"
}

enrich_driver_evidence_from_order() {
	local role="$1"
	local wp_cmd="$2"
	local gateway_id="$3"
	local evidence_path="$4"
	local payload_b64 raw rc

	if [ ! -f "$evidence_path" ]; then
		return
	fi

	payload_b64="$(
		python3 - "$evidence_path" <<'PY'
import base64
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    raise SystemExit(0)

try:
    order_id = int(payload.get("order_id") or 0)
except Exception:
    order_id = 0

payment_intent_id = str(payload.get("payment_intent_id") or "")
method_family = str(payload.get("method_family") or "")
can_resolve_by_intent = method_family in {"customer_action", "hosted_action"}
if order_id <= 0 and (not can_resolve_by_intent or not payment_intent_id.startswith("pi_")):
    raise SystemExit(0)

print(
    base64.b64encode(
        json.dumps(
            {
                "order_id": order_id,
                "payment_intent_id": payment_intent_id,
                "gateway_id": str(payload.get("gateway_id") or ""),
            }
        ).encode("utf-8")
    ).decode("ascii")
)
PY
	)"
	if [ -z "$payload_b64" ]; then
		return
	fi

	raw="$(
		export LPM_GATE_GATEWAY_ID="$gateway_id"
		eval "$wp_cmd eval-file - enrich-order-evidence '$payload_b64'" <<'PHP' 2>&1
<?php
$payload_b64       = isset( $args[1] ) ? (string) $args[1] : '';
$payload           = json_decode( base64_decode( $payload_b64 ), true );
$order_id          = is_array( $payload ) ? absint( $payload['order_id'] ?? 0 ) : 0;
$payment_intent_id = is_array( $payload ) ? (string) ( $payload['payment_intent_id'] ?? '' ) : '';
$gateway_id        = (string) getenv( 'LPM_GATE_GATEWAY_ID' );
$order             = $order_id > 0 ? wc_get_order( $order_id ) : false;
$resolved_by       = $order ? 'order_id' : '';

if ( ! $order && '' !== $payment_intent_id ) {
	$orders = wc_get_orders(
		array(
			'limit'      => 2,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_intent_id',
					'value' => $payment_intent_id,
				),
			),
		)
	);

	$matches = array();
	foreach ( $orders as $candidate ) {
		if ( $candidate instanceof WC_Order && ( '' === $gateway_id || $gateway_id === $candidate->get_payment_method() ) ) {
			$matches[] = $candidate;
		}
	}

	if ( 1 === count( $matches ) ) {
		$order       = $matches[0];
		$order_id    = $order->get_id();
		$resolved_by = 'payment_intent_id';
	}
}

if ( ! $order ) {
	echo wp_json_encode(
		array(
			'success'           => false,
			'mode'              => 'enrich-order-evidence',
			'order_id'          => $order_id,
			'payment_intent_id' => $payment_intent_id,
		),
		JSON_UNESCAPED_SLASHES
	) . "\n";
	return;
}

echo wp_json_encode(
	array(
		'success'              => true,
		'mode'                 => 'enrich-order-evidence',
		'order_id'             => $order->get_id(),
		'order_status'         => $order->get_status(),
		'order_payment_method' => $order->get_payment_method(),
		'payment_intent_id'    => (string) $order->get_meta( '_intent_id' ),
		'payment_method_id'    => (string) $order->get_meta( '_payment_method_id' ),
		'intention_status'     => (string) $order->get_meta( '_intention_status' ),
		'transaction_id'       => (string) $order->get_transaction_id(),
		'resolved_by'          => $resolved_by,
	),
	JSON_UNESCAPED_SLASHES
) . "\n";
PHP
	)"
	rc=$?
	if [ "$rc" -ne 0 ]; then
		printf 'LPM checkout gate: order evidence enrichment warning for %s: %s\n' "$role" "$raw" >&2
		return
	fi

	python3 - "$evidence_path" "$raw" <<'PY'
import json
import sys
from pathlib import Path

evidence_path = Path(sys.argv[1])
raw = sys.argv[2]
enrichment = None
for line in reversed(raw.splitlines()):
    line = line.strip()
    if not line:
        continue
    try:
        candidate = json.loads(line)
    except Exception:
        continue
    if isinstance(candidate, dict) and candidate.get("mode") == "enrich-order-evidence":
        enrichment = candidate
        break

if not enrichment or not enrichment.get("success"):
    raise SystemExit(0)

payload = json.loads(evidence_path.read_text(encoding="utf-8"))
identity_keys = ("order_id", "payment_intent_id", "order_payment_method")
conflicts = {}
for key in identity_keys:
    browser_value = payload.get(key)
    persisted_value = enrichment.get(key)
    payload[f"browser_{key}"] = browser_value
    if browser_value not in (None, "", 0) and persisted_value not in (None, "", 0):
        if str(browser_value) != str(persisted_value):
            conflicts[key] = {
                "browser": browser_value,
                "persisted": persisted_value,
            }
            continue
    if browser_value in (None, "", 0) and persisted_value not in (None, "", 0):
        payload[key] = persisted_value
payload["order_enrichment"] = {
    key: enrichment.get(key, "")
    for key in (
        "order_status",
        "order_payment_method",
        "payment_intent_id",
        "payment_method_id",
        "intention_status",
        "transaction_id",
        "resolved_by",
    )
}
if conflicts:
    payload["order_enrichment_conflicts"] = conflicts
else:
    payload.pop("order_enrichment_conflicts", None)
evidence_path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
raise SystemExit(1 if conflicts else 0)
PY
}

observe_provider_payment_method_type() {
	local role="$1"
	local wp_cmd="$2"
	local method="$3"
	local expected_type="$4"
	local automation_disposition="$5"
	local evidence_path="$6"
	local request_b64 raw rc response_json classification status blocker_code blocker_message provenance_json

	if [ ! -f "$evidence_path" ]; then
		return
	fi

	request_b64="$(
		python3 - "$evidence_path" "$role" "$method" "$expected_type" "$automation_disposition" <<'PY'
import base64
import json
import secrets
import sys
from pathlib import Path

evidence_path = Path(sys.argv[1])
role, method, expected_type, automation_disposition = sys.argv[2:]

try:
    payload = json.loads(evidence_path.read_text(encoding="utf-8"))
except Exception:
    raise SystemExit(0)

# Browser-owned evidence can never supply a provider observation.
payload.pop("provider_observation", None)
evidence_path.write_text(
    json.dumps(payload, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)

order_enrichment = payload.get("order_enrichment")
if not isinstance(order_enrichment, dict):
    raise SystemExit(0)

try:
    order_id = int(payload.get("order_id") or 0)
except Exception:
    order_id = 0

order_received_url = str(payload.get("order_received_url") or "")
payment_intent_id = str(order_enrichment.get("payment_intent_id") or "")
automated_completion = (
    payload.get("status") == "pass"
    and "order-received" in order_received_url
)
manual_completion_candidate = (
    automation_disposition == "manual_customer_action"
    and payload.get("automation_disposition") == automation_disposition
    and payload.get("status") == "fail"
    and not order_received_url
)
if (
    not (automated_completion or manual_completion_candidate)
    or order_id <= 0
    or not payment_intent_id.startswith("pi_")
):
    raise SystemExit(0)

request = {
    "role": role,
    "method": method,
    "order_id": order_id,
    "payment_intent_id": payment_intent_id,
    "expected_payment_method_type": expected_type,
    "observation_request_id": "lpm-provider-" + secrets.token_hex(16),
}
print(
    base64.b64encode(
        json.dumps(request, separators=(",", ":"), sort_keys=True).encode("utf-8")
    ).decode("ascii")
)
PY
	)"
	if [ -z "$request_b64" ]; then
		return
	fi

	progress "observing $role provider payment method for $method"
	raw="$(eval "$wp_cmd eval-file - observe-provider-payment-method '$request_b64'" <<'PHP' 2>&1
<?php
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\NativeWooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;

$payload_b64 = isset( $args[1] ) ? (string) $args[1] : '';
$request     = json_decode( base64_decode( $payload_b64 ), true );
$request     = is_array( $request ) ? $request : array();

$observation_request_id = (string) ( $request['observation_request_id'] ?? '' );
$payment_intent_id      = (string) ( $request['payment_intent_id'] ?? '' );
$order_id               = absint( $request['order_id'] ?? 0 );
$result                 = array(
	'success'                           => false,
	'mode'                              => 'observe-provider-payment-method',
	'availability'                      => 'blocked',
	'blocker_code'                      => 'provider_runtime_unavailable',
	'observation_request_id'            => $observation_request_id,
	'requested_payment_intent_id'       => $payment_intent_id,
	'provider_payment_intent_id'        => '',
	'provider_charge_payment_intent_id' => '',
	'observed_payment_method_type'      => '',
	'charge_id'                         => '',
	'payment_method_id'                 => '',
	'adapter'                           => '',
	'runtime_owner'                     => '',
	'api_client_class'                  => '',
	'gateway_class'                     => '',
	'source'                            => '',
	'transport_available'               => false,
	'account_id'                        => '',
	'test_mode'                         => null,
	'message'                           => '',
);

$emit = static function ( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
};

$resource_id = static function ( $resource ): string {
	if ( is_string( $resource ) ) {
		return $resource;
	}
	if ( is_array( $resource ) && isset( $resource['id'] ) ) {
		return (string) $resource['id'];
	}
	if ( is_object( $resource ) && method_exists( $resource, 'get_id' ) ) {
		return (string) $resource->get_id();
	}
	return '';
};

$normalize_charge = static function ( $charge ) use ( $resource_id ): array {
	if ( is_string( $charge ) ) {
		return array(
			'id'                          => $resource_id( $charge ),
			'payment_intent_id'           => '',
			'payment_method_id'           => '',
			'observed_payment_method_type' => '',
		);
	}

	if ( is_array( $charge ) ) {
		$details = is_array( $charge['payment_method_details'] ?? null ) ? $charge['payment_method_details'] : array();
		return array(
			'id'                          => $resource_id( $charge ),
			'payment_intent_id'           => $resource_id( $charge['payment_intent'] ?? '' ),
			'payment_method_id'           => $resource_id( $charge['payment_method'] ?? '' ),
			'observed_payment_method_type' => (string) ( $details['type'] ?? '' ),
		);
	}

	if ( is_object( $charge ) ) {
		$details = method_exists( $charge, 'get_payment_method_details' ) ? $charge->get_payment_method_details() : array();
		$details = is_array( $details ) ? $details : array();
		return array(
			'id'                          => $resource_id( $charge ),
			'payment_intent_id'           => method_exists( $charge, 'get_payment_intent' ) ? $resource_id( $charge->get_payment_intent() ) : '',
			'payment_method_id'           => method_exists( $charge, 'get_payment_method' ) ? $resource_id( $charge->get_payment_method() ) : '',
			'observed_payment_method_type' => (string) ( $details['type'] ?? '' ),
		);
	}

	return array(
		'id'                          => '',
		'payment_intent_id'           => '',
		'payment_method_id'           => '',
		'observed_payment_method_type' => '',
	);
};

$normalize_payment_method = static function ( $payment_method ) use ( $resource_id ): array {
	if ( is_array( $payment_method ) ) {
		return array(
			'id'   => $resource_id( $payment_method ),
			'type' => (string) ( $payment_method['type'] ?? '' ),
		);
	}
	if ( is_object( $payment_method ) ) {
		return array(
			'id'   => $resource_id( $payment_method ),
			'type' => method_exists( $payment_method, 'get_type' ) ? (string) $payment_method->get_type() : '',
		);
	}
	return array( 'id' => '', 'type' => '' );
};

if ( '' === $observation_request_id || '' === $payment_intent_id || $order_id <= 0 ) {
	$result['availability'] = 'invalid';
	$result['blocker_code'] = 'provider_observation_request_invalid';
	$result['message']      = 'Provider observation request context is incomplete.';
	$emit( $result );
	return;
}

$order = wc_get_order( $order_id );
if ( ! $order instanceof WC_Order ) {
	$result['availability'] = 'invalid';
	$result['blocker_code'] = 'provider_observation_context_mismatch';
	$result['message']      = 'Provider observation order does not exist.';
	$emit( $result );
	return;
}

$order_payment_intent_id = (string) $order->get_meta( '_intent_id' );
if ( $payment_intent_id !== $order_payment_intent_id ) {
	$result['availability'] = 'invalid';
	$result['blocker_code'] = 'provider_observation_context_mismatch';
	$result['message']      = 'Provider observation PaymentIntent does not match the persisted order.';
	$emit( $result );
	return;
}

$gateway                 = wc_get_payment_gateway_by_order( $order );
$result['gateway_class'] = is_object( $gateway ) ? get_class( $gateway ) : '';
$fetch_intent            = null;
$fetch_charge            = null;
$fetch_payment_method    = null;
$runtime_owner           = '';

if ( class_exists( NativePaymentsRuntimeArbiter::class ) ) {
	try {
		$runtime_arbiter = wc_get_container()->get( NativePaymentsRuntimeArbiter::class );
		if ( $runtime_arbiter instanceof NativePaymentsRuntimeArbiter ) {
			$runtime_owner = $runtime_arbiter->get_runtime_owner();
		}
	} catch ( Throwable $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Fall back to the registered gateway for older or partially bootstrapped runtimes.
	}
}

if ( '' === $runtime_owner ) {
	$runtime_owner = class_exists( NativeWooPaymentsGateway::class ) && $gateway instanceof NativeWooPaymentsGateway
		? 'native'
		: ( class_exists( 'WC_Payments' ) ? 'plugin' : 'none' );
}
$result['runtime_owner'] = $runtime_owner;

try {
	if ( 'native' === $runtime_owner ) {
		$api_client      = wc_get_container()->get( WooPaymentsApiClient::class );
		$account_service = wc_get_container()->get( WooPaymentsAccountService::class );

		$result['adapter']          = 'native_woocommerce_api_client';
		$result['api_client_class'] = is_object( $api_client ) ? get_class( $api_client ) : '';
		$result['account_id']       = $account_service instanceof WooPaymentsAccountService ? $account_service->get_account_id() : '';
		$result['test_mode']        = $account_service instanceof WooPaymentsAccountService ? $account_service->is_test_mode_enabled() : null;

		if ( ! $api_client instanceof WooPaymentsApiClient ) {
			$result['blocker_code'] = 'provider_runtime_unavailable';
			$result['message']      = 'Native WooPayments API client is unavailable.';
			$emit( $result );
			return;
		}
		if ( '' === $result['account_id'] ) {
			$result['blocker_code'] = 'provider_account_unavailable';
			$result['message']      = 'Native WooPayments account is unavailable.';
			$emit( $result );
			return;
		}

		$result['transport_available'] = $api_client->is_available();
		if ( ! $result['transport_available'] ) {
			$result['blocker_code'] = 'provider_transport_unavailable';
			$result['message']      = 'Native WooPayments provider transport is unavailable.';
			$emit( $result );
			return;
		}

		$fetch_intent = static function ( string $payment_intent_id ) use ( $api_client ) {
			return $api_client->get_payment_intention( $payment_intent_id );
		};
		$fetch_charge = static function ( string $charge_id ) use ( $api_client ) {
			return $api_client->get_charge( $charge_id );
		};
		$fetch_payment_method = static function ( string $payment_method_id ) use ( $api_client ) {
			return $api_client->get_payment_method( $payment_method_id );
		};
	} elseif ( 'plugin' === $runtime_owner && class_exists( 'WC_Payments' ) && method_exists( 'WC_Payments', 'get_payments_api_client' ) ) {
		$api_client      = WC_Payments::get_payments_api_client();
		$account_service = method_exists( 'WC_Payments', 'get_account_service' ) ? WC_Payments::get_account_service() : null;

		$result['adapter']          = 'legacy_woopayments_api_client';
		$result['api_client_class'] = is_object( $api_client ) ? get_class( $api_client ) : '';
		$result['account_id']       = is_object( $account_service ) && method_exists( $account_service, 'get_stripe_account_id' )
			? (string) $account_service->get_stripe_account_id()
			: '';
		$result['test_mode']        = class_exists( 'WC_Payments_Onboarding_Service' )
			? WC_Payments_Onboarding_Service::is_test_mode_enabled()
			: null;

		if ( ! is_object( $api_client ) || ! method_exists( $api_client, 'get_intent' ) ) {
			$result['blocker_code'] = 'provider_runtime_unavailable';
			$result['message']      = 'Legacy WooPayments API client is unavailable.';
			$emit( $result );
			return;
		}
		if ( '' === $result['account_id'] ) {
			$result['blocker_code'] = 'provider_account_unavailable';
			$result['message']      = 'Legacy WooPayments account is unavailable.';
			$emit( $result );
			return;
		}

		$result['transport_available'] = method_exists( $api_client, 'is_server_connected' )
			&& $api_client->is_server_connected()
			&& method_exists( $api_client, 'get_blog_id' )
			&& null !== $api_client->get_blog_id();
		if ( ! $result['transport_available'] ) {
			$result['blocker_code'] = 'provider_transport_unavailable';
			$result['message']      = 'Legacy WooPayments provider transport is unavailable.';
			$emit( $result );
			return;
		}

		$fetch_intent = static function ( string $payment_intent_id ) use ( $api_client ) {
			return $api_client->get_intent( $payment_intent_id );
		};
		$fetch_charge = static function ( string $charge_id ) use ( $api_client ) {
			return $api_client->get_charge( $charge_id );
		};
		$fetch_payment_method = static function ( string $payment_method_id ) use ( $api_client ) {
			return $api_client->get_payment_method( $payment_method_id );
		};
	} else {
		$result['blocker_code'] = 'provider_runtime_unavailable';
		$result['message']      = 'The order WooPayments runtime could not be resolved.';
		$emit( $result );
		return;
	}

	$intent = $fetch_intent( $payment_intent_id );
	if ( is_array( $intent ) ) {
		$provider_intent_id = $resource_id( $intent );
		$payment_method     = $intent['payment_method'] ?? '';
		$payment_method_id  = $resource_id( $payment_method );
		$charges            = is_array( $intent['charges']['data'] ?? null ) ? $intent['charges']['data'] : array();
		$charge             = empty( $charges ) ? ( $intent['latest_charge'] ?? null ) : end( $charges );
	} elseif ( is_object( $intent ) ) {
		$provider_intent_id = $resource_id( $intent );
		$payment_method_id  = method_exists( $intent, 'get_payment_method_id' ) ? $resource_id( $intent->get_payment_method_id() ) : '';
		$payment_method     = null;
		$charge             = method_exists( $intent, 'get_charge' ) ? $intent->get_charge() : null;
	} else {
		$provider_intent_id = '';
		$payment_method_id  = '';
		$payment_method     = null;
		$charge             = null;
	}

	$charge_fields = $normalize_charge( $charge );
	if ( '' === $charge_fields['observed_payment_method_type'] && '' !== $charge_fields['id'] ) {
		$charge        = $fetch_charge( $charge_fields['id'] );
		$charge_fields = $normalize_charge( $charge );
		$source        = 'charge.payment_method_details.type';
	} else {
		$source = 'intent.charge.payment_method_details.type';
	}

	if ( '' === $payment_method_id ) {
		$payment_method_id = $charge_fields['payment_method_id'];
	}

	$observed_type = $charge_fields['observed_payment_method_type'];
	if ( '' === $observed_type && null !== $payment_method ) {
		$payment_method_fields = $normalize_payment_method( $payment_method );
		$observed_type         = $payment_method_fields['type'];
		if ( '' === $payment_method_id ) {
			$payment_method_id = $payment_method_fields['id'];
		}
		$source = 'intent.payment_method.type';
	}
	if ( '' === $observed_type && '' !== $payment_method_id ) {
		$payment_method_fields = $normalize_payment_method( $fetch_payment_method( $payment_method_id ) );
		$observed_type         = $payment_method_fields['type'];
		$source                = 'payment_method.type';
	}

	$result['success']                           = true;
	$result['availability']                      = 'available';
	$result['blocker_code']                      = '';
	$result['provider_payment_intent_id']        = $provider_intent_id;
	$result['provider_charge_payment_intent_id'] = $charge_fields['payment_intent_id'];
	$result['observed_payment_method_type']      = $observed_type;
	$result['charge_id']                         = $charge_fields['id'];
	$result['payment_method_id']                 = $payment_method_id;
	$result['source']                            = $source;
	$result['message']                           = '';
	$emit( $result );
} catch ( Throwable $error ) {
	$provider_http_code = method_exists( $error, 'get_http_code' ) ? (int) $error->get_http_code() : 0;
	$non_retriable_client_error = $provider_http_code >= 400
		&& $provider_http_code < 500
		&& ! in_array( $provider_http_code, array( 401, 403, 408, 429 ), true );

	$result['availability'] = $non_retriable_client_error ? 'invalid' : 'blocked';
	$result['blocker_code'] = $non_retriable_client_error ? 'provider_identity_invalid' : 'provider_api_request_unavailable';
	$result['message']      = $error->getMessage();
	$result['exception']    = get_class( $error );
	if ( method_exists( $error, 'get_error_code' ) ) {
		$result['provider_error_code'] = (string) $error->get_error_code();
	}
	if ( method_exists( $error, 'get_http_code' ) ) {
		$result['provider_http_code'] = $provider_http_code;
	}
	$emit( $result );
}
PHP
	)"
	rc=$?
	response_json="$(printf '%s\n' "$raw" | extract_json_line 2>/dev/null || true)"

	classification="$(
		python3 - "$evidence_path" "$request_b64" "$response_json" "$rc" <<'PY'
import base64
import json
import sys
from pathlib import Path

evidence_path = Path(sys.argv[1])
request = json.loads(base64.b64decode(sys.argv[2]).decode("utf-8"))
response_json = sys.argv[3]
command_exit_code = int(sys.argv[4])
payload = json.loads(evidence_path.read_text(encoding="utf-8"))

try:
    response = json.loads(response_json) if response_json else None
except Exception:
    response = None

observation = {
    "schema": "woopayments_lpm_provider_observation.v1",
    "status": "blocked",
    "role": request["role"],
    "method": request["method"],
    "order_id": request["order_id"],
    "payment_intent_id": request["payment_intent_id"],
    "expected_payment_method_type": request["expected_payment_method_type"],
    "observation_request_id": request["observation_request_id"],
    "provider_payment_intent_id": "",
    "provider_charge_payment_intent_id": "",
    "observed_payment_method_type": "",
    "charge_id": "",
    "payment_method_id": "",
    "adapter": "",
    "runtime_owner": "",
    "api_client_class": "",
    "gateway_class": "",
    "source": "",
    "transport_available": False,
    "account_id": "",
    "test_mode": None,
    "fetched": False,
    "matches_expected": False,
    "blocker_code": "provider_observation_command_failed",
    "errors": [],
    "message": "Provider observation command failed.",
    "command_exit_code": command_exit_code,
}

response_fields = (
    "provider_payment_intent_id",
    "provider_charge_payment_intent_id",
    "observed_payment_method_type",
    "charge_id",
    "payment_method_id",
    "adapter",
    "runtime_owner",
    "api_client_class",
    "gateway_class",
    "source",
    "transport_available",
    "account_id",
    "test_mode",
    "message",
    "exception",
    "provider_error_code",
    "provider_http_code",
    "blocker_code",
)
if isinstance(response, dict):
    for field in response_fields:
        if field in response:
            observation[field] = response[field]

errors = []
blocked = False
if command_exit_code != 0 or not isinstance(response, dict):
    blocked = True
elif response.get("mode") != "observe-provider-payment-method":
    blocked = True
    observation["blocker_code"] = "provider_observation_response_invalid"
    observation["message"] = "Provider observation response was invalid."
elif response.get("observation_request_id") != request["observation_request_id"]:
    errors.append("provider observation request mismatch: stale or mismatched response")
elif response.get("requested_payment_intent_id") != request["payment_intent_id"]:
    errors.append("provider observation requested PaymentIntent mismatch")
elif response.get("availability") == "invalid":
    errors.append(str(response.get("message") or "provider observation context mismatch"))
elif not response.get("success") or response.get("availability") != "available":
    blocked = True
    observation["blocker_code"] = str(
        response.get("blocker_code") or "provider_observation_unavailable"
    )
    observation["message"] = str(
        response.get("message") or "Provider observation is unavailable."
    )
else:
    observation["fetched"] = True
    provider_intent_id = str(response.get("provider_payment_intent_id") or "")
    provider_charge_intent_id = str(
        response.get("provider_charge_payment_intent_id") or ""
    )
    observed_type = str(response.get("observed_payment_method_type") or "")
    if not provider_intent_id:
        blocked = True
        observation["blocker_code"] = "provider_payment_intent_identity_unavailable"
        observation["message"] = "Provider PaymentIntent identity is unavailable."
    elif provider_intent_id != request["payment_intent_id"]:
        errors.append(
            "provider PaymentIntent mismatch: expected {!r}, got {!r}".format(
                request["payment_intent_id"], provider_intent_id
            )
        )
    if (
        provider_charge_intent_id
        and provider_charge_intent_id != request["payment_intent_id"]
    ):
        errors.append(
            "provider charge PaymentIntent mismatch: expected {!r}, got {!r}".format(
                request["payment_intent_id"], provider_charge_intent_id
            )
        )
    if not observed_type:
        blocked = True
        observation["blocker_code"] = "provider_payment_method_type_unavailable"
        observation["message"] = "Provider payment method type is unavailable."
    elif observed_type != request["expected_payment_method_type"]:
        errors.append(
            "provider payment method type mismatch (Core parity): expected {!r}, got {!r}".format(
                request["expected_payment_method_type"], observed_type
            )
        )

if errors:
    observation["status"] = "fail"
    if response.get("availability") != "invalid":
        observation["blocker_code"] = ""
    observation["errors"] = errors
    observation["message"] = errors[0]
elif blocked:
    observation["status"] = "blocked"
else:
    observation["status"] = "pass"
    observation["matches_expected"] = True
    observation["blocker_code"] = ""
    observation["message"] = ""

payload["provider_observation"] = observation
evidence_path.write_text(
    json.dumps(payload, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)

print(
    json.dumps(
        {
            "status": observation["status"],
            "blocker_code": observation["blocker_code"],
            "message": observation["message"],
            "provenance": observation,
        },
        separators=(",", ":"),
        sort_keys=True,
    )
)
PY
	)"

	status="$(jq -r '.status // ""' <<< "$classification")"
	if [ "$status" = "blocked" ]; then
		blocker_code="$(jq -r '.blocker_code // "provider_observation_unavailable"' <<< "$classification")"
		blocker_message="$(jq -r '.message // "Provider observation is unavailable."' <<< "$classification")"
		provenance_json="$(jq -c '.provenance' <<< "$classification")"
		blocker_message="$role/$method: provider payment-method observation blocked [$blocker_code]: $blocker_message; see $evidence_path"
		record_blocker "$blocker_message"
		record_blocker_detail "$blocker_code" "$role" "$method" "$evidence_path" "$blocker_message" "$provenance_json"
		return 3
	fi

	return 0
}

classify_manual_completion_evidence() {
	local role="$1"
	local method="$2"
	local base_url="$3"
	local gateway_id="$4"
	local stripe_type="$5"
	local method_family="$6"
	local automation_disposition="$7"
	local evidence_path="$8"
	local classification rc blocker_code blocker_message provenance_json

	if [ "$automation_disposition" != "manual_customer_action" ]; then
		return 0
	fi

	classification="$(
		python3 "$LPM_EVIDENCE_HELPER" classify-manual \
			--evidence "$evidence_path" \
			--role "$role" \
			--method "$method" \
			--base-url "$base_url" \
			--gateway-id "$gateway_id" \
			--stripe-type "$stripe_type" \
			--method-family "$method_family" \
			--automation-disposition "$automation_disposition" 2>&1
	)"
	rc=$?
	if [ "$rc" -ne 3 ]; then
		return "$rc"
	fi

	blocker_code="$(jq -r '.blocker_code // "manual_payment_authorization_required"' <<< "$classification")"
	provenance_json="$(jq -c '.provenance // {}' <<< "$classification")"
	blocker_message="$role/$method: provider-backed checkout requires customer authorization outside the automated local harness; see $evidence_path"
	record_blocker "$blocker_message"
	record_blocker_detail "$blocker_code" "$role" "$method" "$evidence_path" "$blocker_message" "$provenance_json"
	return 3
}

validate_manual_completion_pair() {
	local method="$1"
	local automation_disposition="$2"
	local reference_evidence="$OUT_DIR/reference-${method}-${SURFACE}.json"
	local target_evidence="$OUT_DIR/target-${method}-${SURFACE}.json"

	if [ "$automation_disposition" != "manual_customer_action" ]; then
		return 0
	fi

	python3 "$LPM_EVIDENCE_HELPER" validate-pair \
		--reference-evidence "$reference_evidence" \
		--target-evidence "$target_evidence" \
		--method "$method" \
		--automation-disposition "$automation_disposition"
}

validate_driver_evidence() {
	local role="$1"
	local method="$2"
	local base_url="$3"
	local gateway_id="$4"
	local stripe_type="$5"
	local method_family="$6"
	local automation_disposition="$7"
	local evidence_path="$8"
	local evidence_status manual_validation_output manual_validation_rc

	evidence_status="$(jq -r '.status // ""' "$evidence_path" 2>/dev/null || true)"
	if [ "$evidence_status" = "blocked" ]; then
		manual_validation_output="$(
			python3 "$LPM_EVIDENCE_HELPER" validate-manual \
				--evidence "$evidence_path" \
				--role "$role" \
				--method "$method" \
				--base-url "$base_url" \
				--gateway-id "$gateway_id" \
				--stripe-type "$stripe_type" \
				--method-family "$method_family" \
				--automation-disposition "$automation_disposition" 2>&1
		)"
		manual_validation_rc=$?
		if [ "$manual_validation_rc" -eq 0 ]; then
			return 3
		fi
		printf '%s\n' "$manual_validation_output"
		return 1
	fi

	python3 - "$role" "$method" "$base_url" "$gateway_id" "$stripe_type" "$evidence_path" <<'PY'
import json
import sys
from urllib.parse import parse_qs, urlparse

role, method, base_url, gateway_id, stripe_type, evidence_path = sys.argv[1:]
errors = []
provider_blocked = False
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

order_enrichment = payload.get("order_enrichment")
if not isinstance(order_enrichment, dict):
    errors.append("missing order_enrichment")
else:
    persisted_order_payment_method = order_enrichment.get("order_payment_method")
    if persisted_order_payment_method in (None, "", 0):
        errors.append("missing persisted order_payment_method")
    elif persisted_order_payment_method != gateway_id:
        errors.append(
            f"persisted order_payment_method mismatch: expected {gateway_id!r}, got {persisted_order_payment_method!r}"
        )

    persisted_payment_intent_id = order_enrichment.get("payment_intent_id")
    if persisted_payment_intent_id in (None, "", 0):
        errors.append("missing persisted payment_intent_id")
    elif not str(persisted_payment_intent_id).startswith("pi_"):
        errors.append("persisted payment_intent_id must be a Stripe PaymentIntent id")

    persisted_transaction_id = order_enrichment.get("transaction_id")
    if persisted_transaction_id in (None, "", 0):
        errors.append("missing persisted transaction_id")
    elif persisted_payment_intent_id not in (None, "", 0) and persisted_transaction_id != persisted_payment_intent_id:
        errors.append(
            f"persisted transaction_id mismatch: expected {persisted_payment_intent_id!r}, got {persisted_transaction_id!r}"
        )

order_enrichment_conflicts = payload.get("order_enrichment_conflicts")
if isinstance(order_enrichment_conflicts, dict):
    for key, conflict in sorted(order_enrichment_conflicts.items()):
        errors.append(f"browser/persisted {key} conflict: {conflict!r}")
elif order_enrichment_conflicts not in (None, {}):
    errors.append("order_enrichment_conflicts must be an object")

required_keys = ["selected_gateway_id", "order_payment_method", "payment_intent_id", "order_received_url"]

for key in required_keys:
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

if method == "multibanco":
    voucher = payload.get("multibanco_voucher")
    if not isinstance(voucher, dict):
        errors.append("missing Multibanco voucher rendering")
    else:
        if voucher.get("rendered") is not True or voucher.get("visible") is not True:
            errors.append("missing Multibanco voucher rendering")
        for key in ("entity", "reference", "amount"):
            if not isinstance(voucher.get(key), str) or not voucher.get(key):
                errors.append(f"Multibanco voucher rendering missing {key}")
        if voucher.get("share_link_present") is not True:
            errors.append("Multibanco voucher rendering missing share link")

order_received_url = payload.get("order_received_url")
if order_received_url not in (None, "", 0):
    parsed = urlparse(str(order_received_url))
    host = parsed.hostname or ""
    normalized_base = base_url.rstrip("/")
    query_order_id = parse_qs(parsed.query).get("order-received", [""])[0]
    if parsed.scheme not in {"http", "https"}:
        errors.append(f"order_received_url must be http(s), got {order_received_url!r}")
    if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
        errors.append(f"order_received_url must stay local, got {order_received_url!r}")
    uses_pretty_order_received = str(order_received_url).startswith(f"{normalized_base}/checkout/order-received/")
    uses_query_order_received = str(order_received_url).startswith(f"{normalized_base}/") and query_order_id
    if not uses_pretty_order_received and not uses_query_order_received:
        errors.append(
            "order_received_url must use the expected store checkout order-received URL"
        )
    order_id = payload.get("order_id")
    if (
        order_id not in (None, "", 0)
        and f"/order-received/{order_id}" not in str(order_received_url)
        and str(order_id) != str(query_order_id)
    ):
        errors.append("order_received_url does not include order_id")

payment_intent_id = payload.get("payment_intent_id")
if payment_intent_id not in (None, "", 0) and not str(payment_intent_id).startswith("pi_"):
    errors.append("payment_intent_id must be a Stripe PaymentIntent id")

provider_observation = payload.get("provider_observation")
if not isinstance(provider_observation, dict):
    errors.append("missing provider_observation")
else:
    provider_expected_values = {
        "schema": "woopayments_lpm_provider_observation.v1",
        "role": role,
        "method": method,
        "order_id": payload.get("order_id"),
        "payment_intent_id": payment_intent_id,
        "expected_payment_method_type": stripe_type,
    }
    for key, expected in provider_expected_values.items():
        if provider_observation.get(key) != expected:
            errors.append(
                f"provider_observation {key} mismatch: expected {expected!r}, "
                f"got {provider_observation.get(key)!r}"
            )

    observation_request_id = provider_observation.get("observation_request_id")
    if not isinstance(observation_request_id, str) or not observation_request_id.startswith(
        "lpm-provider-"
    ):
        errors.append("provider_observation is stale or lacks a gate-owned request id")

    provider_status = provider_observation.get("status")
    expected_runtime_owner = {"reference": "plugin", "target": "native"}.get(role)
    observed_runtime_owner = provider_observation.get("runtime_owner")
    if (
        observed_runtime_owner not in (None, "", 0)
        and observed_runtime_owner != expected_runtime_owner
    ):
        errors.append(
            f"{role} provider observation runtime_owner must be {expected_runtime_owner}"
        )
    if provider_observation.get("test_mode") is False:
        errors.append("provider_observation must prove test mode")

    if provider_status == "blocked":
        provider_blocked = True
        if not provider_observation.get("blocker_code"):
            errors.append("blocked provider_observation lacks blocker_code")
    elif provider_status == "fail":
        provider_errors = provider_observation.get("errors")
        if isinstance(provider_errors, list) and provider_errors:
            errors.extend(str(error) for error in provider_errors if str(error))
        else:
            errors.append("provider_observation status is fail")
    elif provider_status == "pass":
        provider_intent_id = provider_observation.get("provider_payment_intent_id")
        if provider_intent_id != payment_intent_id:
            errors.append(
                f"provider PaymentIntent mismatch: expected {payment_intent_id!r}, got {provider_intent_id!r}"
            )

        provider_charge_intent_id = provider_observation.get(
            "provider_charge_payment_intent_id"
        )
        if provider_charge_intent_id not in (None, "", payment_intent_id):
            errors.append(
                "provider charge PaymentIntent mismatch: "
                f"expected {payment_intent_id!r}, got {provider_charge_intent_id!r}"
            )

        observed_type = provider_observation.get("observed_payment_method_type")
        if observed_type != stripe_type:
            errors.append(
                "provider payment method type mismatch (Core parity): "
                f"expected {stripe_type!r}, got {observed_type!r}"
            )

        if provider_observation.get("fetched") is not True:
            errors.append("passing provider_observation must be fetched")
        if provider_observation.get("matches_expected") is not True:
            errors.append("passing provider_observation must match the expected payment method type")
        if provider_observation.get("transport_available") is not True:
            errors.append("passing provider_observation must use an available provider transport")
        if provider_observation.get("test_mode") not in (True, False):
            errors.append("provider_observation must prove test mode")
        for key in ("adapter", "runtime_owner", "api_client_class", "account_id", "source"):
            if provider_observation.get(key) in (None, "", 0):
                errors.append(f"passing provider_observation missing {key}")
    else:
        errors.append(f"provider_observation status is invalid: {provider_status!r}")

for error in errors:
    print(error)

if errors:
    raise SystemExit(1)
if provider_blocked:
    raise SystemExit(3)
PY
}

evidence_order_id() {
	local evidence_path="$1"

	python3 - "$evidence_path" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    print(0)
    raise SystemExit(0)

try:
    print(int(payload.get("order_id") or 0))
except Exception:
    print(0)
PY
}

failure_count() {
	if [ ! -f "$FAILURES_FILE" ]; then
		printf '0\n'
		return
	fi
	wc -l < "$FAILURES_FILE" | tr -d '[:space:]'
}

blocker_count() {
	if [ ! -f "$BLOCKERS_FILE" ]; then
		printf '0\n'
		return
	fi
	wc -l < "$BLOCKERS_FILE" | tr -d '[:space:]'
}

drain_action_scheduler() {
	local role="$1"
	local wp_cmd="$2"
	local output

	progress "draining $role Action Scheduler queue before parity"
	if ! output="$(eval "$wp_cmd action-scheduler run --batch-size=100 --batches=5 --force" 2>&1)"; then
		progress "$role Action Scheduler drain skipped: $(printf '%s' "$output" | tr '\n' ' ' | cut -c 1-240)"
	fi
}

run_bucket_e_parity_for_method() {
	local method="$1"
	local ref_evidence_path="$2"
	local target_evidence_path="$3"
	local ref_order_id
	local target_order_id
	local log_path="$OUT_DIR/${method}-${SURFACE}-bucket-e-parity.log"
	local output
	local rc

	ref_order_id="$(evidence_order_id "$ref_evidence_path")"
	target_order_id="$(evidence_order_id "$target_evidence_path")"

	if [ -z "$ref_order_id" ] || [ "$ref_order_id" -le 0 ] || [ -z "$target_order_id" ] || [ "$target_order_id" -le 0 ]; then
		record_failure "$method: Bucket-E parity skipped because browser evidence did not contain both order IDs."
		return
	fi

	if [ "$ACTION_SCHEDULER_DRAIN_ENABLED" -eq 1 ]; then
		drain_action_scheduler reference "$REF_WP"
		drain_action_scheduler target "$TARGET_WP"
	else
		progress "Action Scheduler queue drain intentionally disabled before Bucket-E parity for $method"
	fi
	progress "checking Bucket-E parity for $method orders reference=$ref_order_id target=$target_order_id"
	output="$(
		"$PARITY_DIFF_BIN" --ref "$REF_WP" --target "$TARGET_WP" --target-ids "$target_order_id" "$ref_order_id" 2>&1
	)"
	rc=$?
	printf '%s\n' "$output" > "$log_path"
	if [ "$rc" -ne 0 ]; then
		record_failure "$method: Bucket-E parity failed; see $log_path"
	fi
}

validate_multibanco_voucher_pair() {
	local method="$1"
	local ref_evidence_path="$2"
	local target_evidence_path="$3"

	if [ "$method" != "multibanco" ]; then
		return 0
	fi

	python3 - "$ref_evidence_path" "$target_evidence_path" <<'PY'
import json
import sys
from pathlib import Path

reference = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8"))
target = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
reference_voucher = reference.get("multibanco_voucher")
target_voucher = target.get("multibanco_voucher")
if not isinstance(reference_voucher, dict) or not isinstance(target_voucher, dict):
    raise SystemExit("both stores must provide Multibanco voucher rendering evidence")

for key in ("entity", "reference", "amount"):
    if reference_voucher.get(key) != target_voucher.get(key):
        raise SystemExit(
            f"Multibanco voucher {key} mismatch: "
            f"reference={reference_voucher.get(key)!r}, target={target_voucher.get(key)!r}"
        )
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/lpm-checkout-gate.json"

	python3 - "$rollup_path" "$SURFACE" "$ACTION_SCHEDULER_DRAIN_ENABLED" "$RESULTS_JSONL" "$FAILURES_FILE" "$BLOCKERS_FILE" "$BLOCKER_DETAILS_JSONL" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, surface, drain_enabled, results_jsonl, failures_file, blockers_file, blocker_details_jsonl = sys.argv[1:]
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

blockers_path = Path(blockers_file)
blockers = []
if blockers_path.exists():
    blockers = [line for line in blockers_path.read_text(encoding="utf-8").splitlines() if line.strip()]

blocker_details = []
blocker_details_path = Path(blocker_details_jsonl)
if blocker_details_path.exists():
    blocker_details = [
        json.loads(line)
        for line in blocker_details_path.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]

cleanup_path = Path(rollup_path).parent / "lpm-cleanup-restore.json"
try:
    cleanup_restore = json.loads(cleanup_path.read_text(encoding="utf-8"))
except Exception:
    cleanup_restore = {"status": "blocked", "error_code": "cleanup_evidence_missing"}

provider_statuses = [
    result.get("provider_observation", {}).get("status")
    if isinstance(result.get("provider_observation"), dict)
    else None
    for result in results
]

status = "pass"
cleanup_codes = {
    "target_fixture_restore_failed",
    "reference_fixture_restore_failed",
    "target_product_cleanup_failed",
    "reference_product_cleanup_failed",
    "cleanup_evidence_write_failed",
}
cleanup_blocked = cleanup_restore.get("status") != "pass" or any(
    detail.get("code") in cleanup_codes for detail in blocker_details
)
if cleanup_blocked:
    status = "blocked"
elif failures or any(provider_status not in {"pass", "blocked"} for provider_status in provider_statuses):
    status = "fail"
elif blockers or any(provider_status == "blocked" for provider_status in provider_statuses):
    status = "blocked"

payload = {
    "schema": "woopayments_lpm_checkout_gate_rollup.v1",
    "surface": surface,
    "action_scheduler_drain_enabled": drain_enabled == "1",
    "status": status,
    "results": results,
    "failures": failures,
    "blockers": blockers,
    "blocker_details": blocker_details,
    "cleanup_restore": cleanup_restore,
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
	local automation_disposition="$9"
	local product_id="${10}"
	local checkout_page_id="${11}"
	local evidence_path="$OUT_DIR/${role}-${method}-${SURFACE}.json"
	local log_path="$OUT_DIR/${role}-${method}-${SURFACE}.playwriter.log"
	local exit_code
	local validation_output
	local validation_exit_code
	local browser_config_js
	local wp_cmd
	local driver_exit_message=""

	rm -f "$evidence_path"
	progress "driving $role checkout for $method on $base_url"

	browser_config_js="$(
		python3 - "$role" "$method" "$SURFACE" "$base_url" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$automation_disposition" "$product_id" "$checkout_page_id" "$evidence_path" <<'PY'
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
    automation_disposition,
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
            "automationDisposition": automation_disposition,
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

	if [ "$BROWSER_RUNNER" = "playwriter" ]; then
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
		LPM_GATE_AUTOMATION_DISPOSITION="$automation_disposition" \
		LPM_GATE_PRODUCT_ID="$product_id" \
		LPM_GATE_CHECKOUT_PAGE_ID="$checkout_page_id" \
		LPM_GATE_EVIDENCE_PATH="$evidence_path" \
		"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$SELF_DIR/lpm-checkout.playwriter.mjs" --timeout "300000" >>"$log_path" 2>&1
	else
		LPM_GATE_ROLE="$role" \
		LPM_GATE_METHOD="$method" \
		LPM_GATE_SURFACE="$SURFACE" \
		LPM_GATE_BASE_URL="$base_url" \
		LPM_GATE_CURRENCY="$currency" \
		LPM_GATE_COUNTRY="$country" \
		LPM_GATE_GATEWAY_ID="$gateway_id" \
		LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE="$stripe_type" \
		LPM_GATE_METHOD_FAMILY="$method_family" \
		LPM_GATE_AUTOMATION_DISPOSITION="$automation_disposition" \
		LPM_GATE_PRODUCT_ID="$product_id" \
		LPM_GATE_CHECKOUT_PAGE_ID="$checkout_page_id" \
		LPM_GATE_EVIDENCE_PATH="$evidence_path" \
		"${PLAYWRIGHT_RUNNER_CMD[@]}" "$SELF_DIR/lpm-checkout.playwriter.mjs" --timeout "300000" >"$log_path" 2>&1
	fi
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		driver_exit_message="$role/$method: $BROWSER_RUNNER exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		if [ -n "$driver_exit_message" ]; then
			record_failure "$driver_exit_message"
		fi
		record_failure "$role/$method: missing browser evidence $evidence_path"
		return
	fi

	if [ "$role" = "reference" ]; then
		wp_cmd="$REF_WP"
	else
		wp_cmd="$TARGET_WP"
	fi
	enrich_driver_evidence_from_order "$role" "$wp_cmd" "$gateway_id" "$evidence_path"
	observe_provider_payment_method_type "$role" "$wp_cmd" "$method" "$stripe_type" "$automation_disposition" "$evidence_path"
	classify_manual_completion_evidence "$role" "$method" "$base_url" "$gateway_id" "$stripe_type" "$method_family" "$automation_disposition" "$evidence_path" || true

	validation_output="$(validate_driver_evidence "$role" "$method" "$base_url" "$gateway_id" "$stripe_type" "$method_family" "$automation_disposition" "$evidence_path" 2>&1)"
	validation_exit_code=$?
	if [ "$validation_exit_code" -ne 0 ] && [ "$validation_exit_code" -ne 3 ]; then
		if [ -n "$driver_exit_message" ]; then
			record_failure "$driver_exit_message"
		fi
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$role/$method: $line"
			fi
		done <<< "$validation_output"
	elif [ -n "$driver_exit_message" ] && [ "$validation_exit_code" -eq 0 ]; then
		progress "$driver_exit_message; accepted after persisted order enrichment"
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
if [ ! -x "$PARITY_DIFF_BIN" ]; then
	blocked "Bucket-E parity differ is missing or not executable: $PARITY_DIFF_BIN"
fi
if [ ! -f "$PAYMENT_METHOD_FIXTURE_STATE" ]; then
	blocked "payment-method fixture state driver is missing: $PAYMENT_METHOD_FIXTURE_STATE"
fi
if [ ! -f "$LPM_EVIDENCE_HELPER" ]; then
	blocked "LPM evidence helper is missing: $LPM_EVIDENCE_HELPER"
fi

if [ "$BROWSER_RUNNER" = "playwriter" ]; then
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

	playwriter_launcher="${PLAYWRITER_CMD[0]}"
	if [[ "$playwriter_launcher" == */* ]]; then
		if [ ! -x "$playwriter_launcher" ]; then
			blocked "Playwriter launcher is missing or not executable: $playwriter_launcher"
		fi
	elif ! command -v "$playwriter_launcher" >/dev/null 2>&1; then
		blocked "Playwriter launcher is missing or not executable: $playwriter_launcher"
	fi
else
	# shellcheck disable=SC2206
	PLAYWRIGHT_RUNNER_CMD=( $PLAYWRIGHT_SCRIPT_RUNNER_BIN )
fi

if [ ! -f "$SELF_DIR/lpm-checkout.playwriter.mjs" ]; then
	blocked "submit-capable browser driver is missing: $SELF_DIR/lpm-checkout.playwriter.mjs"
fi
if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running browser checkout flows."
fi
if [ "$BROWSER_RUNNER" = "playwright" ] && [ ! -x "$PLAYWRIGHT_SCRIPT_RUNNER_BIN" ]; then
	blocked "Playwright script runner is missing or not executable: $PLAYWRIGHT_SCRIPT_RUNNER_BIN"
fi

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok for methods: $METHODS_CSV"
	exit 0
fi

if [ -e "$OUT_DIR" ] && { [ ! -d "$OUT_DIR" ] || [ -n "$(find "$OUT_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; }; then
	blocked "LPM checkout requires a fresh empty --out-dir and will not overwrite prior evidence: $OUT_DIR"
fi
mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
LPM_FIXTURE_SNAPSHOT_DIR="$OUT_DIR/lpm-fixtures"
mkdir -p "$LPM_FIXTURE_SNAPSHOT_DIR" || blocked "could not create LPM fixture snapshot directory: $LPM_FIXTURE_SNAPSHOT_DIR"
RESULTS_JSONL="$OUT_DIR/lpm-checkout-results.jsonl"
FAILURES_FILE="$OUT_DIR/lpm-checkout-failures.txt"
BLOCKERS_FILE="$OUT_DIR/lpm-checkout-blockers.txt"
BLOCKER_DETAILS_JSONL="$OUT_DIR/lpm-checkout-blocker-details.jsonl"
: > "$RESULTS_JSONL"
: > "$FAILURES_FILE"
: > "$BLOCKERS_FILE"
: > "$BLOCKER_DETAILS_JSONL"

HARNESS_INITIALIZED=1
trap 'handle_lpm_signal 129' HUP
trap 'handle_lpm_signal 130' INT
trap 'handle_lpm_signal 143' TERM
trap 'handle_lpm_exit $?' EXIT

if ! REF_BASE_URL="$(browser_base_url reference "$REF_WP" "$REF_URL")"; then
	blocked "reference store identity validation failed."
fi
if ! TARGET_BASE_URL="$(browser_base_url target "$TARGET_WP" "$TARGET_URL")"; then
	blocked "target store identity validation failed."
fi
LPM_RUN_ID="$(python3 - <<'PY'
import uuid

print(uuid.uuid4().hex)
PY
)"
prepare_lpm_product reference "$REF_WP" "woopayments-lpm-gate-${LPM_RUN_ID}-reference"
prepare_lpm_product target "$TARGET_WP" "woopayments-lpm-gate-${LPM_RUN_ID}-target"
if ! REF_CHECKOUT_PAGE_ID="$(wp_checkout_page_id reference "$REF_WP")"; then
	blocked "reference checkout page identity probe failed."
fi
if ! TARGET_CHECKOUT_PAGE_ID="$(wp_checkout_page_id target "$TARGET_WP")"; then
	blocked "target checkout page identity probe failed."
fi

for raw_method in "${METHODS[@]}"; do
	method="$(printf '%s' "$raw_method" | tr -d '[:space:]')"
	IFS=$'\t' read -r method_id currency country gateway_id stripe_type method_family automation_disposition <<< "$(method_fixture "$method")"
	CURRENT_METHOD="$method_id"
	failure_count_before="$(failure_count)"
	blocker_count_before="$(blocker_count)"
	stage_lpm_fixture reference "$REF_WP" "$method_id" "$currency" "$country" || true
	stage_lpm_fixture target "$TARGET_WP" "$method_id" "$currency" "$country" || true
	if [ "$(blocker_count)" != "$blocker_count_before" ]; then
		progress "skipping $method_id checkout because fixture staging is blocked."
		if ! restore_lpm_fixtures; then
			break
		fi
		continue
	fi
	run_driver_for_store reference "$REF_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$automation_disposition" "$REF_PRODUCT_ID" "$REF_CHECKOUT_PAGE_ID"
	run_driver_for_store target "$TARGET_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family" "$automation_disposition" "$TARGET_PRODUCT_ID" "$TARGET_CHECKOUT_PAGE_ID"
	voucher_pair_output="$(validate_multibanco_voucher_pair "$method_id" "$OUT_DIR/reference-${method_id}-${SURFACE}.json" "$OUT_DIR/target-${method_id}-${SURFACE}.json" 2>&1)"
	voucher_pair_rc=$?
	if [ "$voucher_pair_rc" -ne 0 ]; then
		record_failure "$method_id: Multibanco voucher rendering does not match across stores: $voucher_pair_output"
	fi
	manual_pair_output="$(validate_manual_completion_pair "$method_id" "$automation_disposition" 2>&1)"
	manual_pair_rc=$?
	if [ "$manual_pair_rc" -ne 0 ]; then
		record_failure "$method_id: manual-completion evidence is not symmetric across stores: $manual_pair_output"
	fi
	if [ "$(failure_count)" = "$failure_count_before" ] && [ "$(blocker_count)" = "$blocker_count_before" ]; then
		run_bucket_e_parity_for_method "$method_id" "$OUT_DIR/reference-${method_id}-${SURFACE}.json" "$OUT_DIR/target-${method_id}-${SURFACE}.json"
	fi
	if ! restore_lpm_fixtures; then
		break
	fi
done

finalize_lpm_state normal || true
write_rollup || blocked "could not write LPM checkout rollup."
rollup_status="$(jq -r '.status // "blocked"' "$OUT_DIR/lpm-checkout-gate.json" 2>/dev/null || printf 'blocked')"

if [ "$rollup_status" = "blocked" ]; then
	if [ -s "$FAILURES_FILE" ]; then
		while IFS= read -r failure_message; do
			if [ -n "$failure_message" ]; then
				printf 'FAIL (recorded with blocked cleanup): %s\n' "$failure_message" >&2
			fi
		done < "$FAILURES_FILE"
	fi
	while IFS= read -r blocker_message; do
		if [ -n "$blocker_message" ]; then
			printf 'BLOCKED: %s\n' "$blocker_message" >&2
		fi
	done < "$BLOCKERS_FILE"
	exit 3
fi

if [ "$rollup_status" = "fail" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

if [ "$rollup_status" != "pass" ]; then
	printf 'BLOCKED: LPM checkout rollup returned invalid status: %s\n' "$rollup_status" >&2
	exit 3
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/lpm-checkout-gate.json"
