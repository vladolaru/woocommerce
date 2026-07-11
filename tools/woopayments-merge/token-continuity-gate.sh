#!/usr/bin/env bash
#
# Token-continuity cutover gate for the WooPayments -> core merge harness.
#
# This local-only transition harness proves that a WooPayments plugin-created
# SEPA token remains visible and renewable after the target store cuts over to
# native WooPayments. It fails closed: native token loading, browser evidence,
# and the renewal driver must all report success.

set -uo pipefail

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCAL_RUNNER_SAFETY="$SELF_DIR/local-runner-safety.sh"
STATE_DRIVER="$SELF_DIR/token-continuity-state.php"
RENEWAL_DRIVER="$SELF_DIR/subscriptions-renewal-drive.php"
BROWSER_DRIVER="$SELF_DIR/token-continuity.playwriter.mjs"
PAYMENT_METHOD_FIXTURE_STATE="$SELF_DIR/payment-method-fixture-state.php"

TARGET_WP=""
CUSTOMER_ID=""
SUBSCRIPTION_ID=""
SUBSCRIPTION_PRODUCT_ID=""
CHECKOUT_PRODUCT_ID=""
RENEWAL_PRODUCT_ID=""
SUBSCRIPTION_SOURCE="provided"
SOURCE_FLOW="checkout"
PLAYWRITER_SESSION="${PLAYWRITER_SESSION:-}"
BROWSER_RUNNER="${BROWSER_RUNNER:-playwriter}"
PLAYWRIGHT_SCRIPT_RUNNER_BIN="${PLAYWRIGHT_SCRIPT_RUNNER_BIN:-$SELF_DIR/playwright-script-runner.mjs}"
OUT_DIR="${TMPDIR:-$SELF_DIR/.tmp}/token-continuity-gate"
PRINT_PLAN=0
PREFLIGHT_ONLY=0
STAGE_SEPA_FIXTURE=0

METHOD="sepa_debit"
GATEWAY_ID="woocommerce_payments_sepa_debit"
STRIPE_PAYMENT_METHOD_TYPE="sepa_debit"
TOKEN_TYPE="wcpay_sepa"

RESTORE_NEEDED=0
RESTORE_SEPA_FIXTURE_NEEDED=0

usage() {
	cat >&2 <<'USAGE'
usage:
  token-continuity-gate.sh --target "<target wp>" --customer-id <id> (--subscription-id <id>|--subscription-product-id <id>|--renewal-product-id <id>) [options]

Options:
  --target "<wp>"              Target store WP-CLI command.
  --customer-id <id>           Customer/user ID expected to own the saved token.
  --subscription-id <id>       Browser-created SEPA subscription ID to renew after cutover.
  --subscription-product-id <id>
                               Subscription product to add to the browser checkout when the gate should
                               discover the subscription ID from the plugin-created checkout order.
  --checkout-product-id <id>   Simple product to add to the browser checkout before creating the source PaymentMethod.
                               Required with --renewal-product-id when --source-flow=checkout.
  --renewal-product-id <id>    Subscription product used to provision a renewal fixture from the source token.
  --source-flow <flow>         Source token acquisition flow: checkout, add-payment-method, or provider-setup-intent.
                               Defaults to checkout.
  --browser-runner <runner>    Browser runner: playwriter or playwright. Defaults to BROWSER_RUNNER or playwriter.
  --playwriter-session <id>    Existing Playwriter session id. Defaults to PLAYWRITER_SESSION.
  --out-dir <path>             Evidence output directory.
  --stage-sepa-fixture         Stage/restore local SEPA checkout settings before preflight.
  --preflight-only             Validate arguments, dependencies, and plugin-side preflight, then exit.
  --print-plan                 Print the normalized gate plan as JSON, then exit.
  -h, --help                   Show this help.

The gate starts with the separate WooPayments plugin active on the target,
creates a real SEPA PaymentMethod through the browser driver, validates the
source token through the WooPayments plugin token service, optionally creates
the source subscription through checkout or a state fixture bound to the saved
	token, cuts over to native WooPayments, asserts native-loader and My Account token
visibility, drives a renewal, then restores the plugin state.
USAGE
}

progress() {
	printf 'Token continuity gate: %s\n' "$*" >&2
}

blocked() {
	printf 'BLOCKED: %s\n' "$*" >&2
	exit 3
}

usage_error() {
	printf 'FAIL: %s\n' "$*" >&2
	usage
	exit 2
}

positive_int_or_usage() {
	local label="$1"
	local value="$2"

	case "$value" in
		''|*[!0-9]*)
			usage_error "$label must be a positive integer."
			;;
	esac
	if [ "$value" -le 0 ]; then
		usage_error "$label must be a positive integer."
	fi
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--target=*) TARGET_WP="${1#--target=}"; shift ;;
		--target) TARGET_WP="${2:-}"; shift 2 ;;
		--customer-id=*) CUSTOMER_ID="${1#--customer-id=}"; shift ;;
		--customer-id) CUSTOMER_ID="${2:-}"; shift 2 ;;
		--subscription-id=*) SUBSCRIPTION_ID="${1#--subscription-id=}"; shift ;;
		--subscription-id) SUBSCRIPTION_ID="${2:-}"; shift 2 ;;
		--subscription-product-id=*) SUBSCRIPTION_PRODUCT_ID="${1#--subscription-product-id=}"; shift ;;
		--subscription-product-id) SUBSCRIPTION_PRODUCT_ID="${2:-}"; shift 2 ;;
		--checkout-product-id=*) CHECKOUT_PRODUCT_ID="${1#--checkout-product-id=}"; shift ;;
		--checkout-product-id) CHECKOUT_PRODUCT_ID="${2:-}"; shift 2 ;;
		--renewal-product-id=*) RENEWAL_PRODUCT_ID="${1#--renewal-product-id=}"; shift ;;
		--renewal-product-id) RENEWAL_PRODUCT_ID="${2:-}"; shift 2 ;;
		--source-flow=*) SOURCE_FLOW="${1#--source-flow=}"; shift ;;
		--source-flow) SOURCE_FLOW="${2:-}"; shift 2 ;;
		--browser-runner=*) BROWSER_RUNNER="${1#--browser-runner=}"; shift ;;
		--browser-runner) BROWSER_RUNNER="${2:-}"; shift 2 ;;
		--playwriter-session=*) PLAYWRITER_SESSION="${1#--playwriter-session=}"; shift ;;
		--playwriter-session) PLAYWRITER_SESSION="${2:-}"; shift 2 ;;
		--out-dir=*) OUT_DIR="${1#--out-dir=}"; shift ;;
		--out-dir) OUT_DIR="${2:-}"; shift 2 ;;
		--stage-sepa-fixture) STAGE_SEPA_FIXTURE=1; shift ;;
		--preflight-only) PREFLIGHT_ONLY=1; shift ;;
		--print-plan) PRINT_PLAN=1; shift ;;
		--help|-h) usage; exit 0 ;;
		*) usage_error "unknown argument: $1" ;;
	esac
done

if [ -z "$TARGET_WP" ] || [ -z "$CUSTOMER_ID" ]; then
	usage_error "--target and --customer-id are required."
fi

SOURCE_FLOW="${SOURCE_FLOW//-/_}"
case "$SOURCE_FLOW" in
	checkout|add_payment_method|provider_setup_intent) ;;
	*) usage_error "--source-flow must be checkout, add-payment-method, or provider-setup-intent." ;;
esac

case "$BROWSER_RUNNER" in
	playwriter|playwright) ;;
	*) usage_error "unsupported browser runner: $BROWSER_RUNNER" ;;
esac

source_count=0
[ -n "$SUBSCRIPTION_ID" ] && source_count=$(( source_count + 1 ))
[ -n "$SUBSCRIPTION_PRODUCT_ID" ] && source_count=$(( source_count + 1 ))
[ -n "$RENEWAL_PRODUCT_ID" ] && source_count=$(( source_count + 1 ))
if [ "$source_count" -ne 1 ]; then
	usage_error "pass exactly one of --subscription-id, --subscription-product-id, or --renewal-product-id."
fi

positive_int_or_usage "--customer-id" "$CUSTOMER_ID"
if [ -n "$CHECKOUT_PRODUCT_ID" ]; then
	positive_int_or_usage "--checkout-product-id" "$CHECKOUT_PRODUCT_ID"
fi
if [ -n "$SUBSCRIPTION_ID" ]; then
	positive_int_or_usage "--subscription-id" "$SUBSCRIPTION_ID"
	SUBSCRIPTION_SOURCE="provided"
elif [ -n "$SUBSCRIPTION_PRODUCT_ID" ]; then
	positive_int_or_usage "--subscription-product-id" "$SUBSCRIPTION_PRODUCT_ID"
	if [ "$SOURCE_FLOW" != "checkout" ]; then
		usage_error "--subscription-product-id requires --source-flow=checkout because the subscription ID is discovered from the checkout order."
	fi
	SUBSCRIPTION_SOURCE="browser_checkout"
else
	positive_int_or_usage "--renewal-product-id" "$RENEWAL_PRODUCT_ID"
	if [ "$SOURCE_FLOW" = "checkout" ] && [ -z "$CHECKOUT_PRODUCT_ID" ]; then
		usage_error "--checkout-product-id is required with --renewal-product-id so token-save checkout uses a non-subscription cart."
	fi
	SUBSCRIPTION_SOURCE="provisioned_renewal_fixture"
fi

print_plan() {
	python3 - "$TARGET_WP" "$CUSTOMER_ID" "$SUBSCRIPTION_ID" "$SUBSCRIPTION_PRODUCT_ID" "$CHECKOUT_PRODUCT_ID" "$RENEWAL_PRODUCT_ID" "$SUBSCRIPTION_SOURCE" "$SOURCE_FLOW" "$METHOD" "$GATEWAY_ID" "$STRIPE_PAYMENT_METHOD_TYPE" "$TOKEN_TYPE" <<'PY'
import json
import sys

target, customer_id, subscription_id, subscription_product_id, checkout_product_id, renewal_product_id, subscription_source, source_flow, method, gateway_id, stripe_type, token_type = sys.argv[1:]
if source_flow == "provider_setup_intent":
    source_check = "provider_setup_intent_creates_reusable_sepa_token"
elif source_flow == "add_payment_method":
    source_check = "plugin_add_payment_method_creates_reusable_sepa_token"
else:
    source_check = "plugin_checkout_creates_real_sepa_payment_method"
checks = [
	    source_check,
	    "plugin_source_persists_sepa_token",
	    "native_cutover_loads_token",
	    "native_my_account_renders_token",
	    "native_sepa_subscription_renewal_succeeds",
]
if subscription_source == "browser_checkout":
    checks.insert(2, "plugin_checkout_creates_subscription")
elif subscription_source == "provisioned_renewal_fixture":
    checks.insert(2, "fixture_binds_saved_token_to_subscription")
print(
    json.dumps(
        {
            "schema": "woopayments_token_continuity_gate_plan.v1",
            "target_wp": target,
            "customer_id": int(customer_id),
            "subscription_id": int(subscription_id) if subscription_id else None,
            "subscription_product_id": int(subscription_product_id) if subscription_product_id else None,
            "checkout_product_id": int(checkout_product_id) if checkout_product_id else None,
            "renewal_product_id": int(renewal_product_id) if renewal_product_id else None,
            "subscription_source": subscription_source,
            "source_flow": source_flow,
            "method": method,
            "gateway_id": gateway_id,
            "stripe_payment_method_type": stripe_type,
            "token_type": token_type,
            "checks": checks,
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

if [ ! -f "$LOCAL_RUNNER_SAFETY" ]; then
	usage_error "local runner safety library is missing: $LOCAL_RUNNER_SAFETY"
fi
# shellcheck source=tools/woopayments-merge/local-runner-safety.sh
source "$LOCAL_RUNNER_SAFETY"
if ! runner_error="$(woopayments_validate_local_wp_runner "$TARGET_WP")"; then
	usage_error "target must use an approved local Docker WP-CLI command: $runner_error"
fi
if ! runner_error="$(woopayments_validate_approved_docker_runner "$TARGET_WP" target)"; then
	usage_error "target must use an approved local Docker WP-CLI command: $runner_error"
fi

if ! command -v python3 >/dev/null 2>&1; then
	blocked "python3 is required."
fi
if [ ! -f "$STATE_DRIVER" ]; then
	blocked "state driver is missing: $STATE_DRIVER"
fi
if [ ! -f "$RENEWAL_DRIVER" ]; then
	blocked "renewal driver is missing: $RENEWAL_DRIVER"
fi
if [ ! -f "$BROWSER_DRIVER" ]; then
	blocked "browser driver is missing: $BROWSER_DRIVER"
fi
if [ "$STAGE_SEPA_FIXTURE" -eq 1 ] && [ ! -f "$PAYMENT_METHOD_FIXTURE_STATE" ]; then
	blocked "payment-method fixture state driver is missing: $PAYMENT_METHOD_FIXTURE_STATE"
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
else
	# shellcheck disable=SC2206
	PLAYWRIGHT_RUNNER_CMD=( $PLAYWRIGHT_SCRIPT_RUNNER_BIN )
fi

wp_home_url() {
	local output
	local url

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! output="$($TARGET_WP option get home 2>&1)"; then
		blocked "target store home URL probe failed: $output"
	fi

	url="$(printf '%s\n' "$output" | awk 'NF { value = $0 } END { print value }')"
	url="${url%/}"
	if [ -z "$url" ]; then
		blocked "target store home URL probe returned an empty URL."
	fi

	python3 - "$url" <<'PY'
import sys
from urllib.parse import urlparse

value = sys.argv[1]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"target store home URL must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"target store home URL must stay local, got {value}")
PY

	printf '%s\n' "$url"
}

run_eval_file() {
	local driver="$1"
	local role="$2"
	local out_file="$3"
	shift 3
	local raw rc json

	# Intentionally split the WP runner string, matching the rest of this
	# harness's WP="docker exec ..." convention.
	# shellcheck disable=SC2086
	raw="$($TARGET_WP eval-file - "$@" < "$driver" 2>&1)"
	rc=$?
	json="$(printf '%s\n' "$raw" | grep -E '^\{' | tail -1)"

	if [ -z "$json" ]; then
		echo "FAIL ($role): driver produced no JSON output." >&2
		printf '%s\n' "$raw" | tail -20 >&2
		return 2
	fi

	printf '%s\n' "$json" > "$out_file"

	if [ "$rc" -ne 0 ]; then
		echo "FAIL ($role): driver exited $rc." >&2
		printf '%s\n' "$json" >&2
		return "$rc"
	fi

	return 0
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

print_errors() {
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

record_failure() {
	printf '%s\n' "$*" >> "$FAILURES_FILE"
}

record_blocker() {
	printf '%s\n' "$*" >> "$BLOCKERS_FILE"
}

json_field() {
	local path="$1"
	local field="$2"

	python3 - "$path" "$field" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as stream:
    payload = json.load(stream)
value = payload.get(sys.argv[2])
if value is None:
    raise SystemExit(1)
print(value)
PY
}

validate_browser_evidence() {
	local phase="$1"
	local evidence_path="$2"
	local expected_token_id="${3:-}"

	python3 - "$phase" "$TARGET_BASE_URL" "$TARGET_CHECKOUT_URL" "$TARGET_ADD_PAYMENT_METHOD_URL" "$TARGET_PAYMENT_METHODS_URL" "$SOURCE_FLOW" "$METHOD" "$GATEWAY_ID" "$STRIPE_PAYMENT_METHOD_TYPE" "$TOKEN_TYPE" "$CUSTOMER_ID" "$expected_token_id" "$SUBSCRIPTION_ID" "$SUBSCRIPTION_PRODUCT_ID" "$CHECKOUT_PRODUCT_ID" "$evidence_path" <<'PY'
import json
import sys
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

phase, base_url, checkout_url, add_payment_method_url, payment_methods_url, source_flow, method, gateway_id, stripe_type, token_type, customer_id, expected_token_id, subscription_id, subscription_product_id, checkout_product_id, evidence_path = sys.argv[1:]
errors = []

def with_query(url, pairs):
    parsed = urlparse(url)
    query = parse_qsl(parsed.query, keep_blank_values=True)
    query.extend(pairs)
    return urlunparse(parsed._replace(query=urlencode(query)))

try:
    with open(evidence_path, encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception as exc:
    raise SystemExit(f"invalid evidence JSON: {exc}")

expected_values = {
    "phase": phase,
    "base_url": base_url,
    "method": method,
    "gateway_id": gateway_id,
    "stripe_payment_method_type": stripe_type,
    "token_type": token_type,
    "customer_id": int(customer_id),
    "source_flow": source_flow,
}
for key, expected in expected_values.items():
    if payload.get(key) != expected:
        errors.append(f"{key} mismatch: expected {expected!r}, got {payload.get(key)!r}")

if payload.get("status") != "pass":
    errors.append(f"status is not pass: {payload.get('status')!r}")

url = payload.get("url")
if url in (None, "", 0):
    errors.append("missing url")
else:
    parsed = urlparse(str(url))
    host = parsed.hostname or ""
    expected_url = with_query(checkout_url, [("token-continuity-gate", "save-sepa")])
    if phase == "save_sepa_token" and source_flow == "add_payment_method":
        expected_url = with_query(
            add_payment_method_url,
            [("token-continuity-gate", "save-sepa-add-payment-method")],
        )
    if phase == "render_payment_methods":
        expected_url = with_query(
            payment_methods_url,
            [("token-continuity-gate", "render-sepa"), ("token_id", expected_token_id)],
        )
    if parsed.scheme not in {"http", "https"}:
        errors.append(f"url must be http(s), got {url!r}")
    if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
        errors.append(f"url must stay local, got {url!r}")
    if str(url) != expected_url:
        errors.append(f"url mismatch: expected {expected_url!r}, got {url!r}")

token_id = payload.get("token_id")
source_token_requires_state_persistence = payload.get("source_token_requires_state_persistence") is True
if phase == "save_sepa_token" and source_token_requires_state_persistence:
    if token_id not in (None, 0):
        if not isinstance(token_id, int) or token_id <= 0:
            errors.append("token_id must be zero or a positive integer")
elif not isinstance(token_id, int) or token_id <= 0:
    errors.append("missing token_id")
elif expected_token_id and token_id != int(expected_token_id):
    errors.append(f"token_id mismatch: expected {expected_token_id}, got {token_id}")

if phase == "save_sepa_token":
    selected_gateway_id = payload.get("selected_gateway_id")
    payment_method_id = payload.get("payment_method_id")
    mandate_id = payload.get("mandate_id")
    order_id = payload.get("order_id")
    if checkout_product_id:
        if payload.get("checkout_product_id") not in (None, "", 0, int(checkout_product_id)):
            errors.append(
                f"checkout_product_id mismatch: expected {int(checkout_product_id)!r}, got {payload.get('checkout_product_id')!r}"
            )
    if selected_gateway_id in (None, "", 0):
        errors.append("missing selected_gateway_id")
    elif selected_gateway_id != gateway_id:
        errors.append(
            f"selected_gateway_id mismatch: expected {gateway_id!r}, got {selected_gateway_id!r}"
        )
    if payment_method_id in (None, "", 0):
        errors.append("missing payment_method_id")
    elif not str(payment_method_id).startswith("pm_"):
        errors.append("payment_method_id must be a Stripe PaymentMethod id")
    if mandate_id not in (None, "", 0) and not str(mandate_id).startswith("mandate_"):
        errors.append("mandate_id must be a Stripe mandate id")
    if not subscription_id and subscription_product_id:
        if not isinstance(order_id, int) or order_id <= 0:
            errors.append("missing browser-created checkout order_id")
        if payload.get("subscription_product_id") not in (None, "", 0, int(subscription_product_id)):
            errors.append(
                f"subscription_product_id mismatch: expected {int(subscription_product_id)!r}, got {payload.get('subscription_product_id')!r}"
            )

if phase == "render_payment_methods" and payload.get("token_visible") is not True:
    errors.append("token_visible is not true")

for error in errors:
    print(error)

if errors:
    raise SystemExit(1)
PY
}

write_rollup() {
	local rollup_path="$OUT_DIR/token-continuity-gate.json"
	local token_id="${1:-0}"
	local renewal_path="${2:-}"

	python3 - "$rollup_path" "$CUSTOMER_ID" "$SUBSCRIPTION_ID" "$SUBSCRIPTION_SOURCE" "$SOURCE_FLOW" "$SUBSCRIPTION_PRODUCT_ID" "$CHECKOUT_PRODUCT_ID" "$RENEWAL_PRODUCT_ID" "$token_id" "$SAVE_EVIDENCE" "$SOURCE_TOKEN_JSON" "$RENDER_EVIDENCE" "$renewal_path" "$SUBSCRIPTION_FIXTURE_JSON" "$NATIVE_TOKEN_JSON" "$RESTORE_JSON" "$SEPA_FIXTURE_RESTORE_JSON" "$FAILURES_FILE" "$BLOCKERS_FILE" <<'PY'
import json
import sys
from pathlib import Path

rollup_path, customer_id, subscription_id, subscription_source, source_flow, subscription_product_id, checkout_product_id, renewal_product_id, token_id, save_path, source_token_path, render_path, renewal_path, subscription_fixture_path, native_token_path, restore_path, sepa_fixture_restore_path, failures_file, blockers_file = sys.argv[1:]

def load_json(path):
    if not path:
        return None
    candidate = Path(path)
    if not candidate.exists():
        return None
    return json.loads(candidate.read_text(encoding="utf-8"))

failures_path = Path(failures_file)
failures = []
if failures_path.exists():
    failures = [line for line in failures_path.read_text(encoding="utf-8").splitlines() if line.strip()]

blockers_path = Path(blockers_file)
blockers = []
if blockers_path.exists():
    blockers = [line for line in blockers_path.read_text(encoding="utf-8").splitlines() if line.strip()]

payload = {
    "schema": "woopayments_token_continuity_gate_rollup.v1",
    "status": "fail" if failures else "blocked" if blockers else "pass",
    "customer_id": int(customer_id),
    "subscription_id": int(subscription_id or 0),
    "subscription_source": subscription_source,
    "source_flow": source_flow,
    "subscription_product_id": int(subscription_product_id) if subscription_product_id else None,
    "checkout_product_id": int(checkout_product_id) if checkout_product_id else None,
    "renewal_product_id": int(renewal_product_id) if renewal_product_id else None,
    "token_id": int(token_id or 0),
    "save_token": load_json(save_path),
    "source_token": load_json(source_token_path),
	    "render_payment_methods": load_json(render_path),
	    "subscription_fixture": load_json(subscription_fixture_path),
	    "native_token_loader": load_json(native_token_path),
	    "renewal": load_json(renewal_path),
    "restore": load_json(restore_path),
    "sepa_fixture_restore": load_json(sepa_fixture_restore_path),
    "failures": failures,
    "blockers": blockers,
}
Path(rollup_path).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

run_state_checked() {
	local mode="$1"
	local out_file="$2"
	shift 2

	progress "state check: $mode"
	if ! run_eval_file "$STATE_DRIVER" "$mode" "$out_file" "$mode" "$@"; then
		print_errors "$out_file"
		return 1
	fi
	if ! json_success "$out_file"; then
		print_errors "$out_file"
		return 1
	fi
	return 0
}

prepare_source_cart_if_needed() {
	if [ "$SOURCE_FLOW" != "checkout" ]; then
		return 0
	fi

	if [ -z "$CHECKOUT_PRODUCT_ID" ] && [ -z "$SUBSCRIPTION_PRODUCT_ID" ]; then
		return 0
	fi

	progress "preparing source checkout cart for controlled token-continuity fixture"
	if ! run_state_checked prepare-source-cart "$PREPARE_CART_JSON" "$CUSTOMER_ID"; then
		record_failure "prepare-source-cart state check failed"
		return 1
	fi
	return 0
}

persist_source_token_if_needed() {
	local payment_method_id
	local mandate_id
	local persisted_token_id

	payment_method_id="$(json_field "$SAVE_EVIDENCE" payment_method_id 2>/dev/null || printf '')"
	if [ -z "$payment_method_id" ]; then
		record_failure "save_sepa_token: browser evidence did not include a PaymentMethod ID for source token validation"
		return 1
	fi

	mandate_id="$(json_field "$SAVE_EVIDENCE" mandate_id 2>/dev/null || printf '')"

	progress "validating plugin source token from browser-created PaymentMethod $payment_method_id"
	if ! run_state_checked "persist-source-token" "$SOURCE_TOKEN_JSON" "$CUSTOMER_ID" "$payment_method_id" "$mandate_id" "$TOKEN_ID"; then
		record_failure "persist-source-token state check failed"
		return 1
	fi

	persisted_token_id="$(json_field "$SOURCE_TOKEN_JSON" token_id 2>/dev/null || printf '0')"
	if [ "$persisted_token_id" -le 0 ]; then
		record_failure "persist-source-token did not return a positive token_id"
		return 1
	fi

	if [ "$TOKEN_ID" -gt 0 ] && [ "$persisted_token_id" -ne "$TOKEN_ID" ]; then
		record_failure "persist-source-token returned token_id $persisted_token_id, but browser evidence reported token_id $TOKEN_ID"
		return 1
	fi

	TOKEN_ID="$persisted_token_id"
	return 0
}

provision_source_token_if_needed() {
	local provisioned_token_id

	if [ "$SOURCE_FLOW" != "provider_setup_intent" ]; then
		return 0
	fi

	progress "provisioning provider-backed SEPA source token through setup intent"
	if ! run_state_checked "provision-source-token" "$SOURCE_TOKEN_JSON" "$CUSTOMER_ID"; then
		record_failure "provision-source-token state check failed"
		return 1
	fi

	provisioned_token_id="$(json_field "$SOURCE_TOKEN_JSON" token_id 2>/dev/null || printf '0')"
	if [ "$provisioned_token_id" -le 0 ]; then
		record_failure "provision-source-token did not return a positive token_id"
		return 1
	fi

	TOKEN_ID="$provisioned_token_id"
	return 0
}

restore_if_needed() {
	if [ "$RESTORE_NEEDED" -ne 1 ]; then
		return 0
	fi

	progress "restoring WooPayments plugin state"
	RESTORE_NEEDED=0
	if ! run_eval_file "$STATE_DRIVER" "restore" "$RESTORE_JSON" restore; then
		print_errors "$RESTORE_JSON"
		record_failure "plugin-state restore failed"
		return 1
	fi
	if ! json_success "$RESTORE_JSON"; then
		print_errors "$RESTORE_JSON"
		record_failure "plugin-state restore failed"
		return 1
	fi

	return 0
}

stage_sepa_fixture() {
	local payload_b64

	payload_b64="$(
		python3 - <<'PY'
import base64
import json

print(base64.b64encode(json.dumps(["sepa_debit", "EUR", "NL"]).encode("utf-8")).decode("ascii"))
PY
	)"

	progress "staging local SEPA checkout fixture"
	RESTORE_SEPA_FIXTURE_NEEDED=1
	if ! run_eval_file "$PAYMENT_METHOD_FIXTURE_STATE" "stage-sepa-fixture" "$SEPA_FIXTURE_JSON" stage-lpm-fixture "$payload_b64"; then
		print_errors "$SEPA_FIXTURE_JSON"
		return 1
	fi
	if ! json_success "$SEPA_FIXTURE_JSON"; then
		print_errors "$SEPA_FIXTURE_JSON"
		return 1
	fi
	return 0
}

restore_sepa_fixture_if_needed() {
	local payload_b64

	if [ "$RESTORE_SEPA_FIXTURE_NEEDED" -ne 1 ] || [ ! -f "$SEPA_FIXTURE_JSON" ]; then
		return
	fi

	progress "restoring local SEPA checkout fixture"
	RESTORE_SEPA_FIXTURE_NEEDED=0
	payload_b64="$(
		python3 - "$SEPA_FIXTURE_JSON" <<'PY'
import base64
import sys
from pathlib import Path

print(base64.b64encode(Path(sys.argv[1]).read_bytes()).decode("ascii"))
PY
	)"

	if ! run_eval_file "$PAYMENT_METHOD_FIXTURE_STATE" "restore-sepa-fixture" "$SEPA_FIXTURE_RESTORE_JSON" restore-lpm-fixture "$payload_b64"; then
		print_errors "$SEPA_FIXTURE_RESTORE_JSON"
		record_failure "SEPA fixture restore failed"
		return 1
	fi
	if ! json_success "$SEPA_FIXTURE_RESTORE_JSON"; then
		print_errors "$SEPA_FIXTURE_RESTORE_JSON"
		record_failure "SEPA fixture restore failed"
		return 1
	fi

	return 0
}

cleanup_if_needed() {
	local original_exit_code=$?
	local cleanup_failed=0

	trap - EXIT
	restore_if_needed || cleanup_failed=1
	restore_sepa_fixture_if_needed || cleanup_failed=1

	if [ "$cleanup_failed" -eq 1 ]; then
		write_rollup "${TOKEN_ID:-0}" "${RENEWAL_JSON:-}" >/dev/null 2>&1 || true
		exit 1
	fi

	exit "$original_exit_code"
}

run_browser_phase() {
	local phase="$1"
	local evidence_path="$2"
	local token_id="${3:-}"
	local log_path="$OUT_DIR/${phase}.playwriter.log"
	local exit_code
	local validation_output
	local browser_config_js

	progress "browser phase: $phase"
	rm -f "$evidence_path"

	browser_config_js="$(
		python3 - "$phase" "$TARGET_BASE_URL" "$TARGET_CHECKOUT_URL" "$TARGET_CART_URL" "$TARGET_ADD_PAYMENT_METHOD_URL" "$TARGET_PAYMENT_METHODS_URL" "$SOURCE_FLOW" "$METHOD" "$GATEWAY_ID" "$STRIPE_PAYMENT_METHOD_TYPE" "$TOKEN_TYPE" "$CUSTOMER_ID" "$token_id" "$SUBSCRIPTION_PRODUCT_ID" "$CHECKOUT_PRODUCT_ID" "$evidence_path" <<'PY'
import json
import sys

(
    phase,
    base_url,
    checkout_url,
    cart_url,
    add_payment_method_url,
    payment_methods_url,
    source_flow,
    method,
    gateway_id,
    stripe_type,
    token_type,
    customer_id,
    token_id,
    subscription_product_id,
    checkout_product_id,
    evidence_path,
) = sys.argv[1:]
print(
    "state.tokenContinuityConfig = "
    + json.dumps(
        {
            "phase": phase,
            "baseUrl": base_url,
            "checkoutUrl": checkout_url,
            "cartUrl": cart_url,
            "addPaymentMethodUrl": add_payment_method_url,
            "paymentMethodsUrl": payment_methods_url,
            "sourceFlow": source_flow,
            "method": method,
            "gatewayId": gateway_id,
            "stripePaymentMethodType": stripe_type,
            "tokenType": token_type,
            "customerId": customer_id,
            "tokenId": token_id,
            "subscriptionProductId": subscription_product_id,
            "checkoutProductId": checkout_product_id,
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
			record_failure "$phase: Playwriter config seed exited $exit_code; see $log_path"
			return
		fi

		TOKEN_CONTINUITY_GATE_PHASE="$phase" \
		TOKEN_CONTINUITY_GATE_BASE_URL="$TARGET_BASE_URL" \
		TOKEN_CONTINUITY_GATE_CHECKOUT_URL="$TARGET_CHECKOUT_URL" \
		TOKEN_CONTINUITY_GATE_CART_URL="$TARGET_CART_URL" \
		TOKEN_CONTINUITY_GATE_ADD_PAYMENT_METHOD_URL="$TARGET_ADD_PAYMENT_METHOD_URL" \
		TOKEN_CONTINUITY_GATE_PAYMENT_METHODS_URL="$TARGET_PAYMENT_METHODS_URL" \
		TOKEN_CONTINUITY_GATE_SOURCE_FLOW="$SOURCE_FLOW" \
		TOKEN_CONTINUITY_GATE_METHOD="$METHOD" \
		TOKEN_CONTINUITY_GATE_GATEWAY_ID="$GATEWAY_ID" \
		TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE="$STRIPE_PAYMENT_METHOD_TYPE" \
		TOKEN_CONTINUITY_GATE_TOKEN_TYPE="$TOKEN_TYPE" \
		TOKEN_CONTINUITY_GATE_CUSTOMER_ID="$CUSTOMER_ID" \
		TOKEN_CONTINUITY_GATE_TOKEN_ID="$token_id" \
		TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID="$SUBSCRIPTION_PRODUCT_ID" \
		TOKEN_CONTINUITY_GATE_CHECKOUT_PRODUCT_ID="$CHECKOUT_PRODUCT_ID" \
		TOKEN_CONTINUITY_GATE_EVIDENCE_PATH="$evidence_path" \
		"${PLAYWRITER_CMD[@]}" -s "$PLAYWRITER_SESSION" -f "$BROWSER_DRIVER" --timeout "300000" >>"$log_path" 2>&1
	else
		TOKEN_CONTINUITY_GATE_PHASE="$phase" \
		TOKEN_CONTINUITY_GATE_BASE_URL="$TARGET_BASE_URL" \
		TOKEN_CONTINUITY_GATE_CHECKOUT_URL="$TARGET_CHECKOUT_URL" \
		TOKEN_CONTINUITY_GATE_CART_URL="$TARGET_CART_URL" \
		TOKEN_CONTINUITY_GATE_ADD_PAYMENT_METHOD_URL="$TARGET_ADD_PAYMENT_METHOD_URL" \
		TOKEN_CONTINUITY_GATE_PAYMENT_METHODS_URL="$TARGET_PAYMENT_METHODS_URL" \
		TOKEN_CONTINUITY_GATE_SOURCE_FLOW="$SOURCE_FLOW" \
		TOKEN_CONTINUITY_GATE_METHOD="$METHOD" \
		TOKEN_CONTINUITY_GATE_GATEWAY_ID="$GATEWAY_ID" \
		TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE="$STRIPE_PAYMENT_METHOD_TYPE" \
		TOKEN_CONTINUITY_GATE_TOKEN_TYPE="$TOKEN_TYPE" \
		TOKEN_CONTINUITY_GATE_CUSTOMER_ID="$CUSTOMER_ID" \
		TOKEN_CONTINUITY_GATE_TOKEN_ID="$token_id" \
		TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID="$SUBSCRIPTION_PRODUCT_ID" \
		TOKEN_CONTINUITY_GATE_CHECKOUT_PRODUCT_ID="$CHECKOUT_PRODUCT_ID" \
		TOKEN_CONTINUITY_GATE_EVIDENCE_PATH="$evidence_path" \
		"${PLAYWRIGHT_RUNNER_CMD[@]}" "$BROWSER_DRIVER" --timeout "300000" >"$log_path" 2>&1
	fi
	exit_code=$?

	if [ "$exit_code" -ne 0 ]; then
		record_failure "$phase: $BROWSER_RUNNER exited $exit_code; see $log_path"
	fi
	if [ ! -f "$evidence_path" ]; then
		record_failure "$phase: missing browser evidence $evidence_path"
		return
	fi

	if ! validation_output="$(validate_browser_evidence "$phase" "$evidence_path" "$token_id" 2>&1)"; then
		while IFS= read -r line; do
			if [ -n "$line" ]; then
				record_failure "$phase: $line"
				printf '%s\n' "$phase: $line" >&2
			fi
		done <<< "$validation_output"
	fi
}

discover_subscription_id_from_checkout_order() {
	local order_id
	local subscription_id

	order_id="$(json_field "$SAVE_EVIDENCE" order_id 2>/dev/null || printf '0')"
	if [ "$order_id" -le 0 ]; then
		record_failure "save_sepa_token: browser evidence did not include a positive order_id for subscription discovery"
		return 1
	fi

	progress "resolving browser-created subscription from checkout order $order_id"
	if ! run_state_checked subscription-from-order "$SUBSCRIPTION_LOOKUP_JSON" "$order_id"; then
		record_failure "subscription-from-order state check failed"
		return 1
	fi

	subscription_id="$(json_field "$SUBSCRIPTION_LOOKUP_JSON" subscription_id 2>/dev/null || printf '0')"
	if [ "$subscription_id" -le 0 ]; then
		record_failure "subscription-from-order did not return a positive subscription_id"
		return 1
	fi

	SUBSCRIPTION_ID="$subscription_id"
	return 0
}

provision_subscription_id_from_saved_token() {
	local token_id="$1"
	local subscription_id
	local mandate_id

	progress "provisioning renewal fixture subscription from saved token $token_id"
	mandate_id="$(json_field "$SAVE_EVIDENCE" mandate_id 2>/dev/null || printf '')"
	if ! run_state_checked "provision-renewal-subscription" "$SUBSCRIPTION_FIXTURE_JSON" "$CUSTOMER_ID" "$token_id" "$RENEWAL_PRODUCT_ID" "$mandate_id"; then
		record_failure "provision-renewal-subscription state check failed"
		return 1
	fi

	subscription_id="$(json_field "$SUBSCRIPTION_FIXTURE_JSON" subscription_id 2>/dev/null || printf '0')"
	if [ "$subscription_id" -le 0 ]; then
		record_failure "provision-renewal-subscription did not return a positive subscription_id"
		return 1
	fi

	SUBSCRIPTION_ID="$subscription_id"
	return 0
}

assert_native_token_list_contains() {
	local token_id="$1"
	local state_errors

	progress "checking native token loader for token $token_id"
	if ! run_state_checked assert-native-token "$NATIVE_TOKEN_JSON" "$CUSTOMER_ID" "$token_id" "$GATEWAY_ID" "$TOKEN_TYPE"; then
		state_errors="$(python3 - "$NATIVE_TOKEN_JSON" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as stream:
        payload = json.load(stream)
except Exception:
    payload = {}
for error in payload.get("errors", []):
    print(error)
PY
)"
		while IFS= read -r state_error; do
			if [ -n "$state_error" ]; then
				record_failure "$state_error"
			fi
		done <<< "$state_errors"
		record_failure "assert-native-token state check failed"
	fi
}

run_renewal() {
	local token_id="$1"

	progress "driving SEPA subscription renewal for subscription $SUBSCRIPTION_ID"
	if ! run_eval_file "$RENEWAL_DRIVER" "renewal" "$RENEWAL_JSON" drive "$SUBSCRIPTION_ID" "$GATEWAY_ID" "$token_id"; then
		print_errors "$RENEWAL_JSON"
		record_failure "renewal driver exited with failure"
		return
	fi
	if ! json_success "$RENEWAL_JSON"; then
		print_errors "$RENEWAL_JSON"
		record_failure "renewal driver did not report success"
	fi
}

TARGET_BASE_URL="$(wp_home_url)"
wp_local_url_from_eval() {
	local label="$1"
	local code="$2"
	local output
	local url

	# Intentionally split the WP runner string, matching the harness convention.
	# shellcheck disable=SC2086
	if ! output="$($TARGET_WP eval "$code" 2>&1)"; then
		blocked "target store $label URL probe failed: $output"
	fi

	url="$(printf '%s\n' "$output" | awk '/^https?:\/\// { value = $0 } END { print value }')"
	if [ -z "$url" ]; then
		blocked "target store $label URL probe returned an empty URL."
	fi

	python3 - "$label" "$url" <<'PY'
import sys
from urllib.parse import urlparse

label, value = sys.argv[1:]
parsed = urlparse(value)
host = parsed.hostname or ""
if parsed.scheme not in {"http", "https"}:
    raise SystemExit(f"{label} URL must be http(s), got {value}")
if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
    raise SystemExit(f"{label} URL must stay local, got {value}")
PY

	printf '%s\n' "$url"
}

TARGET_CHECKOUT_URL="$(wp_local_url_from_eval checkout 'echo function_exists("wc_get_checkout_url") ? wc_get_checkout_url() : "";')"
TARGET_CART_URL="$(wp_local_url_from_eval cart 'echo function_exists("wc_get_cart_url") ? wc_get_cart_url() : "";')"
TARGET_ADD_PAYMENT_METHOD_URL="$(wp_local_url_from_eval add-payment-method 'echo function_exists("wc_get_endpoint_url") ? wc_get_endpoint_url("add-payment-method", "", wc_get_page_permalink("myaccount")) : "";')"
TARGET_PAYMENT_METHODS_URL="$(wp_local_url_from_eval payment-methods 'echo function_exists("wc_get_endpoint_url") ? wc_get_endpoint_url("payment-methods", "", wc_get_page_permalink("myaccount")) : "";')"

mkdir -p "$OUT_DIR" || blocked "could not create evidence output directory: $OUT_DIR"
FAILURES_FILE="$OUT_DIR/token-continuity-failures.txt"
BLOCKERS_FILE="$OUT_DIR/token-continuity-blockers.txt"
SAVE_EVIDENCE="$OUT_DIR/save-sepa-token.json"
SOURCE_TOKEN_JSON="$OUT_DIR/source-token.json"
RENDER_EVIDENCE="$OUT_DIR/render-payment-methods.json"
PREFLIGHT_JSON="$OUT_DIR/preflight-plugin.json"
PREPARE_CART_JSON="$OUT_DIR/prepare-source-cart.json"
CUTOVER_JSON="$OUT_DIR/cutover-native.json"
RESTORE_JSON="$OUT_DIR/restore.json"
SUBSCRIPTION_LOOKUP_JSON="$OUT_DIR/subscription-from-order.json"
SUBSCRIPTION_FIXTURE_JSON="$OUT_DIR/provision-renewal-subscription.json"
SEPA_FIXTURE_JSON="$OUT_DIR/sepa-fixture-stage.json"
SEPA_FIXTURE_RESTORE_JSON="$OUT_DIR/sepa-fixture-restore.json"
RENEWAL_JSON="$OUT_DIR/renewal.json"
NATIVE_TOKEN_JSON="$OUT_DIR/native-token.json"
: > "$FAILURES_FILE"
: > "$BLOCKERS_FILE"

trap cleanup_if_needed EXIT

if [ "$STAGE_SEPA_FIXTURE" -eq 1 ]; then
	if ! stage_sepa_fixture; then
		blocked "could not stage local SEPA checkout fixture."
	fi
fi

if ! prepare_source_cart_if_needed; then
	blocked "could not isolate the source checkout cart before SEPA token preflight."
fi

if ! run_state_checked preflight-plugin "$PREFLIGHT_JSON"; then
	blocked "target store must start with the separate WooPayments plugin active and SEPA checkout ready."
fi

if [ "$PREFLIGHT_ONLY" -eq 1 ]; then
	progress "preflight ok"
	exit 0
fi

if [ "$BROWSER_RUNNER" = "playwriter" ] && [ -z "$PLAYWRITER_SESSION" ]; then
	blocked "pass --playwriter-session or set PLAYWRITER_SESSION before running browser token-continuity flows."
fi

if [ "$SOURCE_FLOW" = "provider_setup_intent" ]; then
	if ! provision_source_token_if_needed; then
		write_rollup "$TOKEN_ID" "$RENEWAL_JSON"
		exit 1
	fi
else
	run_browser_phase save_sepa_token "$SAVE_EVIDENCE"
	TOKEN_ID="$(json_field "$SAVE_EVIDENCE" token_id 2>/dev/null || printf '0')"

	if ! persist_source_token_if_needed; then
		write_rollup "$TOKEN_ID" "$RENEWAL_JSON"
		exit 1
	fi
fi

if [ "$SUBSCRIPTION_SOURCE" = "browser_checkout" ]; then
	if ! discover_subscription_id_from_checkout_order; then
		write_rollup "$TOKEN_ID" "$RENEWAL_JSON"
		exit 1
	fi
elif [ "$SUBSCRIPTION_SOURCE" = "provisioned_renewal_fixture" ]; then
	if ! provision_subscription_id_from_saved_token "$TOKEN_ID"; then
		write_rollup "$TOKEN_ID" "$RENEWAL_JSON"
		exit 1
	fi
fi

if run_state_checked cutover-native "$CUTOVER_JSON" "$TOKEN_ID"; then
	RESTORE_NEEDED=1
else
	record_failure "cutover-native state check failed"
fi

assert_native_token_list_contains "$TOKEN_ID"
run_browser_phase render_payment_methods "$RENDER_EVIDENCE" "$TOKEN_ID"
run_renewal "$TOKEN_ID"

restore_if_needed || true
restore_sepa_fixture_if_needed || true

write_rollup "$TOKEN_ID" "$RENEWAL_JSON" || blocked "could not write token-continuity rollup."

if [ -s "$FAILURES_FILE" ]; then
	while IFS= read -r failure_message; do
		if [ -n "$failure_message" ]; then
			printf 'FAIL: %s\n' "$failure_message" >&2
		fi
	done < "$FAILURES_FILE"
	exit 1
fi

if [ -s "$BLOCKERS_FILE" ]; then
	while IFS= read -r blocker_message; do
		if [ -n "$blocker_message" ]; then
			printf 'BLOCKED: %s\n' "$blocker_message" >&2
		fi
	done < "$BLOCKERS_FILE"
	exit 3
fi

progress "wrote passing browser evidence rollup: $OUT_DIR/token-continuity-gate.json"
exit 0
