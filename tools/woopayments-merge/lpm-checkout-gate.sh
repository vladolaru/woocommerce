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
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/lpm-checkout-gate"
SURFACE="classic"
PRINT_PLAN=0
PREFLIGHT_ONLY=0

usage() {
	cat >&2 <<'USAGE'
usage:
  lpm-checkout-gate.sh --methods <ids> --ref "<ref wp>" --target "<target wp>" [options]

Options:
  --methods <ids>             Comma-separated method ids.
  --ref "<wp>"                Reference store WP-CLI command.
  --target "<wp>"             Target store WP-CLI command.
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

	python3 - "$label" "$url" <<'PY'
import sys
from urllib.parse import urlparse

label, value = sys.argv[1:]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"{label} store home URL must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"{label} store home URL must stay local, got {value}")
PY

	printf '%s\n' "$url"
}

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

validate_driver_evidence() {
	local role="$1"
	local method="$2"
	local gateway_id="$3"
	local stripe_type="$4"
	local evidence_path="$5"

	python3 - "$role" "$method" "$gateway_id" "$stripe_type" "$evidence_path" <<'PY'
import json
import sys

role, method, gateway_id, stripe_type, evidence_path = sys.argv[1:]
errors = []
try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "role": role,
    "method": method,
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
	local evidence_path="$OUT_DIR/${role}-${method}-${SURFACE}.json"
	local log_path="$OUT_DIR/${role}-${method}-${SURFACE}.playwriter.log"
	local exit_code
	local validation_output

	rm -f "$evidence_path"
	progress "driving $role checkout for $method on $base_url"

	LPM_GATE_ROLE="$role" \
	LPM_GATE_METHOD="$method" \
	LPM_GATE_SURFACE="$SURFACE" \
	LPM_GATE_BASE_URL="$base_url" \
	LPM_GATE_CURRENCY="$currency" \
	LPM_GATE_COUNTRY="$country" \
	LPM_GATE_GATEWAY_ID="$gateway_id" \
	LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE="$stripe_type" \
	LPM_GATE_METHOD_FAMILY="$method_family" \
	LPM_GATE_EVIDENCE_PATH="$evidence_path" \
	"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$SELF_DIR/lpm-checkout.playwriter.mjs" --timeout "300000" >"$log_path" 2>&1
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "$role/$method: Playwriter exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "$role/$method: missing browser evidence $evidence_path"
		return
	fi

	if ! validation_output="$(validate_driver_evidence "$role" "$method" "$gateway_id" "$stripe_type" "$evidence_path" 2>&1)"; then
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
RESULTS_JSONL="$OUT_DIR/lpm-checkout-results.jsonl"
FAILURES_FILE="$OUT_DIR/lpm-checkout-failures.txt"
: > "$RESULTS_JSONL"
: > "$FAILURES_FILE"

if [ ! -f "$SELF_DIR/lpm-checkout.playwriter.mjs" ]; then
	blocked "submit-capable browser driver is missing: $SELF_DIR/lpm-checkout.playwriter.mjs"
fi

REF_BASE_URL="$(wp_home_url reference "$REF_WP")"
TARGET_BASE_URL="$(wp_home_url target "$TARGET_WP")"

for raw_method in "${METHODS[@]}"; do
	method="$(printf '%s' "$raw_method" | tr -d '[:space:]')"
	IFS=$'\t' read -r method_id currency country gateway_id stripe_type method_family <<< "$(method_fixture "$method")"
	run_driver_for_store reference "$REF_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family"
	run_driver_for_store target "$TARGET_BASE_URL" "$method_id" "$currency" "$country" "$gateway_id" "$stripe_type" "$method_family"
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
