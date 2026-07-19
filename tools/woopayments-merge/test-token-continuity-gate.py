#!/usr/bin/env python3
"""Focused regression checks for the token-continuity gate harness."""

from __future__ import annotations

import json
import os
import base64
import shlex
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/token-continuity-gate.sh"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
BROWSER_DRIVER = REPO / "tools/woopayments-merge/token-continuity.playwright.mjs"
PAYMENT_METHOD_FIXTURE_STATE = REPO / "tools/woopayments-merge/payment-method-fixture-state.php"


def run_gate(
    *args: str,
    env: dict[str, str] | None = None,
    wrap_fake_target: bool = True,
) -> subprocess.CompletedProcess[str]:
    command_args = list(args)
    command_env = dict(env or os.environ)
    if wrap_fake_target and "--target" in command_args:
        target_index = command_args.index("--target") + 1
        target_path = Path(command_args[target_index])
        if target_path.is_file():
            docker_bin_dir = target_path.parent / "fake-docker-bin"
            docker_bin_dir.mkdir(exist_ok=True)
            make_fake_docker(docker_bin_dir / "docker")
            command_args[target_index] = TARGET_WP
            command_env.update(
                {
                    "FAKE_WP_BIN": str(target_path),
                    "PATH": f"{docker_bin_dir}{os.pathsep}{command_env.get('PATH', '')}",
                    "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target-cli-1",
                }
            )

    return subprocess.run(
        ["bash", str(SCRIPT), *command_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=command_env,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_docker(path: Path) -> None:
    write_executable(
        path,
        """#!/usr/bin/env bash
set -euo pipefail
if [ "${1:-}" = "context" ] && [ "${2:-}" = "show" ]; then
    printf 'default\n'
    exit 0
fi
if [ "${1:-}" = "context" ] && [ "${2:-}" = "inspect" ]; then
    printf '%s\n' '[{"Endpoints":{"docker":{"Host":"unix:///var/run/docker.sock"}}}]'
    exit 0
fi
if [ "${1:-}" != "exec" ]; then
    printf 'unexpected fake docker args: %s\n' "$*" >&2
    exit 1
fi
shift
while [ "${1:-}" = "-i" ] || [ "${1:-}" = "--interactive" ]; do
    shift
done
if [ "${1:-}" != "target-cli-1" ] || [ "${2:-}" != "wp" ]; then
    printf 'unexpected fake docker exec target: %s\n' "$*" >&2
    exit 1
fi
shift 2
while [[ "${1:-}" == --allow-root || "${1:-}" == --user=* ]]; do
    shift
done
exec "${FAKE_WP_BIN:?}" "$@"
""",
    )


def run_real_payment_method_fixture_stage(
    capabilities: dict,
    *,
    capability_requirements: dict | None = None,
    fees: dict | None = None,
) -> dict:
    with tempfile.TemporaryDirectory(prefix="payment-method-fixture-state-test-") as tmp:
        tmp_path = Path(tmp)
        prepend = tmp_path / "wp-stubs.php"
        options = {
            "wcpay_account_data": {
                "data": {
                    "account_id": "acct_unit_fixture",
                    "test_publishable_key": "pk_test_unit",
                    "capabilities": capabilities,
                    "capability_requirements": capability_requirements or {},
                    "fees": fees or {},
                },
                "fetched": 123,
                "errored": False,
                "consecutive_errors": 0,
            },
            "woocommerce_woocommerce_payments_settings": {},
            "woocommerce_currency": "USD",
            "woocommerce_default_country": "US:CA",
        }
        prepend.write_text(
            """<?php
$GLOBALS['woopayments_merge_fixture_options'] = json_decode( __OPTIONS__, true );
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
function maybe_serialize( $value ) {
    return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}
class WooPaymentsMergeFixtureWpdb {
    public $options = 'wp_options';
    public $last_error = '';
    public function prepare( $query, ...$args ) {
        return array( 'query' => $query, 'args' => $args );
    }
    public function get_row( $prepared, $format ) {
        $name = $prepared['args'][0];
        if ( ! array_key_exists( $name, $GLOBALS['woopayments_merge_fixture_options'] ) ) {
            return null;
        }
        return array(
            'option_value' => maybe_serialize( $GLOBALS['woopayments_merge_fixture_options'][ $name ] ),
            'autoload' => 'no',
        );
    }
}
$wpdb = new WooPaymentsMergeFixtureWpdb();
function get_option( $name, $default = false ) {
    return array_key_exists( $name, $GLOBALS['woopayments_merge_fixture_options'] )
        ? $GLOBALS['woopayments_merge_fixture_options'][ $name ]
        : $default;
}
function update_option( $name, $value, $autoload = null ) {
    $GLOBALS['woopayments_merge_fixture_options'][ $name ] = $value;
    return true;
}
function wp_cache_delete( $key, $group = '' ) {
    return true;
}
function sanitize_key( $key ) {
    return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}
function wp_json_encode( $data, $flags = 0 ) {
    return json_encode( $data, $flags );
}
""".replace("__OPTIONS__", json.dumps(json.dumps(options))),
            encoding="utf-8",
        )

        payload = base64.b64encode(json.dumps(["sepa_debit", "EUR", "NL"]).encode("utf-8")).decode("ascii")
        result = subprocess.run(
            [
                "php",
                "-d",
                f"auto_prepend_file={prepend}",
                str(PAYMENT_METHOD_FIXTURE_STATE),
                "stage-lpm-fixture",
                payload,
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 0, result.stderr
    json_lines = [line for line in result.stdout.splitlines() if line.strip().startswith("{")]
    assert json_lines, result.stdout
    return json.loads(json_lines[-1])


def make_fake_wp(
    path: Path,
    home_url: str,
    *,
    omit_token: bool = False,
    payment_token_command_available: bool = True,
    restore_failure: bool = False,
    restore_semantic_failure: bool = False,
    sepa_stage_failure: bool = False,
    sepa_stage_failure_without_json: bool = False,
    sepa_restore_failure: bool = False,
    sepa_restore_semantic_failure: bool = False,
    cutover_failure_without_json: bool = False,
    subscription_product_id: int = 116,
    token_id: int = 4242,
) -> None:
    tokens_json = "[]" if omit_token else json.dumps(
        [
            {
                "token_id": token_id,
                "gateway_id": "woocommerce_payments_sepa_debit",
                "type": "wcpay_sepa",
            }
        ]
    )
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
invocations="${{FAKE_WP_INVOCATIONS:?}}"
printf '%s\\n' "$*" >> "$invocations"
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
    printf '%s\\n' {json.dumps(home_url)}
    exit 0
fi
if [ "$1" = "eval" ]; then
    code="${{2:-}}"
    if [[ "$code" == *"wc_get_checkout_url"* ]]; then
        printf '%s\\n' {json.dumps(home_url + "/?page_id=7")}
        exit 0
    fi
    if [[ "$code" == *"wc_get_cart_url"* ]]; then
        printf '%s\\n' {json.dumps(home_url + "/?page_id=6")}
        exit 0
    fi
    if [[ "$code" == *"add-payment-method"* ]]; then
        printf '%s\\n' {json.dumps(home_url + "/?page_id=8&add-payment-method")}
        exit 0
    fi
    if [[ "$code" == *"wc_get_endpoint_url"* ]]; then
        printf '%s\\n' {json.dumps(home_url + "/?page_id=8&payment-methods")}
        exit 0
    fi
fi
if [ "$1" = "wc" ] && [ "$2" = "payment_token" ] && [ "$3" = "list" ]; then
    if [ {str(not payment_token_command_available).lower()} = "true" ]; then
        printf "Error: 'payment_token' is not a registered subcommand of 'wc'.\n" >&2
        exit 1
    fi
    printf '%s\\n' {shlex.quote(tokens_json)}
    exit 0
fi
if [ "$1" = "wc" ] && [ "$2" = "product" ] && [ "$3" = "list" ]; then
    for arg in "$@"; do
        if [ "$arg" = "--field=id" ]; then
            printf '%s\\n' {subscription_product_id}
            exit 0
        fi
    done
fi
    if [ "$1" = "eval-file" ] && [ "$2" = "-" ]; then
	    mode="${{3:-}}"
	    if [ "$mode" = "snapshot-lpm-fixture" ]; then
	        printf '{{"success":true,"mode":"snapshot-lpm-fixture","method":"sepa_debit","currency":"EUR","country":"NL","errors":[],"previous":{{"settings_exists":false,"account_cache_exists":true,"currency_exists":true,"currency":"USD","country_exists":true,"country":"US:CA"}}}}\n'
	        exit 0
	    fi
	    if [ "$mode" = "stage-lpm-fixture" ]; then
	        if [ {str(sepa_stage_failure_without_json).lower()} = "true" ]; then
	            exit 1
	        fi
	        if [ {str(sepa_stage_failure).lower()} = "true" ]; then
	            printf '{{"success":false,"mode":"stage-lpm-fixture","method":"sepa_debit","currency":"EUR","country":"NL","errors":["simulated post-write stage verification failure"],"previous":{{"settings_exists":false,"account_cache_exists":true,"currency_exists":true,"currency":"USD","country_exists":true,"country":"US:CA"}}}}\\n'
	            exit 0
	        fi
	        printf '{{"success":true,"mode":"stage-lpm-fixture","method":"sepa_debit","currency":"EUR","country":"NL","errors":[],"previous":{{"settings_exists":false,"account_cache_exists":true,"currency_exists":true,"currency":"USD","country_exists":true,"country":"US:CA"}}}}\\n'
        exit 0
    fi
    if [ "$mode" = "restore-lpm-fixture" ]; then
        if [ {str(sepa_restore_semantic_failure).lower()} = "true" ]; then
            printf '{{"success":false,"mode":"restore-lpm-fixture","errors":["simulated semantic SEPA fixture restore failure"]}}\n'
            exit 0
        fi
        if [ {str(sepa_restore_failure).lower()} = "true" ]; then
            printf '{{"success":false,"mode":"restore-lpm-fixture","errors":["simulated SEPA fixture restore failure"]}}\\n'
            exit 1
        fi
        printf '{{"success":true,"mode":"restore-lpm-fixture","errors":[]}}\\n'
        exit 0
    fi
    if [ "$mode" = "restore" ]; then
        if [ {str(restore_semantic_failure).lower()} = "true" ]; then
            printf '{{"success":false,"mode":"restore","errors":["simulated semantic plugin-state restore failure"]}}\n'
            exit 0
        fi
        if [ {str(restore_failure).lower()} = "true" ]; then
            printf '{{"success":false,"mode":"restore","errors":["simulated plugin-state restore failure"]}}\\n'
            exit 1
        fi
        printf '{{"success":true,"mode":"restore","errors":[]}}\\n'
        exit 0
    fi
    if [ "$mode" = "cutover-native" ] && [ {str(cutover_failure_without_json).lower()} = "true" ]; then
        exit 1
    fi
    if [ "$mode" = "preflight-plugin" ] || [ "$mode" = "cutover-native" ]; then
        printf '{{"success":true,"mode":"%s","errors":[]}}\\n' "$mode"
        exit 0
    fi
    if [ "$mode" = "prepare-source-cart" ]; then
        printf '{{"success":true,"mode":"prepare-source-cart","customer_id":%s,"errors":[],"persistent_cart_keys_deleted":["_woocommerce_persistent_cart_1"],"cart_count_before":2,"cart_count_after":0}}\\n' "${{4:-0}}"
        exit 0
    fi
    if [ "$mode" = "persist-source-token" ]; then
        printf '{{"success":true,"mode":"persist-source-token","customer_id":%s,"wcpay_customer_id":"cus_unit_source","payment_method_id":"%s","mandate_id":"%s","source_payment_method_customer_ready":true,"customer_payment_method_ids":["%s"],"token_id":%s,"gateway_id":"woocommerce_payments_sepa_debit","token_type":"wcpay_sepa","created":true,"errors":[]}}\\n' "${{4:-0}}" "${{5:-}}" "${{6:-}}" "${{5:-}}" {token_id}
        exit 0
    fi
    if [ "$mode" = "provision-source-token" ]; then
        printf '{{"success":true,"mode":"provision-source-token","customer_id":%s,"wcpay_customer_id":"cus_unit_source","payment_method_id":"pm_unitsepa123","setup_intent_id":"seti_unitsepa123","mandate_id":"","source_payment_method_customer_ready":true,"customer_payment_method_ids":["pm_unitsepa123"],"token_id":%s,"gateway_id":"woocommerce_payments_sepa_debit","token_type":"wcpay_sepa","created":true,"errors":[]}}\\n' "${{4:-0}}" {token_id}
        exit 0
    fi
    if [ "$mode" = "subscription-from-order" ]; then
        printf '{{"success":true,"mode":"subscription-from-order","order_id":%s,"subscription_id":8888,"errors":[]}}\\n' "${{4:-0}}"
        exit 0
    fi
    if [ "$mode" = "provision-renewal-subscription" ]; then
        printf '{{"success":true,"mode":"provision-renewal-subscription","customer_id":%s,"token_id":%s,"product_id":%s,"mandate_id":"%s","subscription_id":9999,"errors":[]}}\\n' "${{4:-0}}" "${{5:-0}}" "${{6:-0}}" "${{7:-}}"
        exit 0
    fi
    if [ "$mode" = "assert-native-token" ]; then
        if [ {str(omit_token).lower()} = "true" ]; then
            printf '{{"success":false,"mode":"assert-native-token","token_id":%s,"errors":["saved token %s is missing from native token storage"]}}\\n' "${{5:-0}}" "${{5:-0}}"
            exit 1
        fi
        printf '{{"success":true,"mode":"assert-native-token","customer_id":%s,"token_id":%s,"gateway_id":"%s","token_type":"%s","token_class":"WooPaymentsSepaToken","errors":[]}}\\n' "${{4:-0}}" "${{5:-0}}" "${{6:-}}" "${{7:-}}"
        exit 0
    fi
    if [ "$mode" = "drive" ]; then
        printf '{{"success":true,"mode":"drive","subscription_id":%s,"renewal_order_payment_method":"%s","token_presence":{{"subscription_tokens":[%s],"renewal_tokens":[%s]}},"errors":[]}}\\n' "${{4:-0}}" "${{5:-}}" "${{6:-0}}" "${{6:-0}}"
        exit 0
    fi
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_playwright_runner(
    path: Path,
    *,
    omit_save_semantics: bool = False,
    source_token_requires_state_persistence: bool = False,
    mandate_id: str = "mandate_unitsepa123",
) -> None:
    omit_save_semantics_literal = "True" if omit_save_semantics else "False"
    state_persistence_literal = "True" if source_token_requires_state_persistence else "False"
    write_executable(
        path,
        """#!/usr/bin/env python3
import json
import os
import pathlib
import sys
from urllib.parse import parse_qsl, urlencode, urlparse, urlunparse

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRIGHT_INVOCATIONS"])
if "-e" in sys.argv[1:]:
    with invocation_path.open("a", encoding="utf-8") as stream:
        stream.write(json.dumps({"argv": sys.argv[1:], "env": {"phase": "config_seed"}}, sort_keys=True) + "\\n")
    raise SystemExit(0)

phase = os.environ["TOKEN_CONTINUITY_GATE_PHASE"]
base_url = os.environ["TOKEN_CONTINUITY_GATE_BASE_URL"]
checkout_url = os.environ["TOKEN_CONTINUITY_GATE_CHECKOUT_URL"]
payment_methods_url = os.environ["TOKEN_CONTINUITY_GATE_PAYMENT_METHODS_URL"]
add_payment_method_url = os.environ["TOKEN_CONTINUITY_GATE_ADD_PAYMENT_METHOD_URL"]
source_flow = os.environ["TOKEN_CONTINUITY_GATE_SOURCE_FLOW"]
checkout_product_id = int(os.environ.get("TOKEN_CONTINUITY_GATE_CHECKOUT_PRODUCT_ID") or 0)
omit_save_semantics = __OMIT_SAVE_SEMANTICS__
source_token_requires_state_persistence = __SOURCE_TOKEN_REQUIRES_STATE_PERSISTENCE__

def with_query(url, **params):
    parsed = urlparse(url)
    query = parse_qsl(parsed.query, keep_blank_values=True)
    query.extend(params.items())
    return urlunparse(parsed._replace(query=urlencode(query)))

if phase == "save_sepa_token" and source_flow == "add_payment_method":
    url = with_query(add_payment_method_url, **{"token-continuity-gate": "save-sepa-add-payment-method"})
elif phase == "save_sepa_token":
    url = with_query(checkout_url, **{"token-continuity-gate": "save-sepa"})
elif phase == "render_payment_methods":
    url = with_query(
        payment_methods_url,
        **{"token-continuity-gate": "render-sepa", "token_id": os.environ["TOKEN_CONTINUITY_GATE_TOKEN_ID"]},
    )
else:
    raise SystemExit(f"unexpected phase: {phase}")

payload = {
    "schema": "woopayments_token_continuity_browser_evidence.v1",
    "status": "pass",
    "phase": phase,
    "base_url": base_url,
    "url": "" if omit_save_semantics and phase == "save_sepa_token" else url,
    "method": os.environ["TOKEN_CONTINUITY_GATE_METHOD"],
    "gateway_id": os.environ["TOKEN_CONTINUITY_GATE_GATEWAY_ID"],
    "stripe_payment_method_type": os.environ["TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE"],
    "token_type": os.environ["TOKEN_CONTINUITY_GATE_TOKEN_TYPE"],
    "customer_id": int(os.environ["TOKEN_CONTINUITY_GATE_CUSTOMER_ID"]),
    "source_flow": source_flow,
    "failures": [],
}
if phase == "save_sepa_token":
    payload.update({
        "token_id": 0 if source_token_requires_state_persistence else 4242,
        "order_id": 909,
        "checkout_product_id": checkout_product_id,
        "subscription_product_id": int(os.environ.get("TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID") or 0),
        "selected_gateway_id": "" if omit_save_semantics else os.environ["TOKEN_CONTINUITY_GATE_GATEWAY_ID"],
        "payment_method_id": "" if omit_save_semantics else "pm_unitsepa123",
        "mandate_id": "" if omit_save_semantics else __MANDATE_ID__,
        "source_token_requires_state_persistence": source_token_requires_state_persistence,
    })
elif phase == "render_payment_methods":
    payload.update({
        "token_id": int(os.environ["TOKEN_CONTINUITY_GATE_TOKEN_ID"]),
        "token_visible": True,
    })

evidence_path = pathlib.Path(os.environ["TOKEN_CONTINUITY_GATE_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")

with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({"argv": sys.argv[1:], "env": payload}, sort_keys=True) + "\\n")
""".replace("__OMIT_SAVE_SEMANTICS__", omit_save_semantics_literal).replace(
            "__SOURCE_TOKEN_REQUIRES_STATE_PERSISTENCE__", state_persistence_literal
        ).replace(
            "__MANDATE_ID__", repr(mandate_id)
        ),
    )


def test_usage_requires_target_customer_and_subscription() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--customer-id" in result.stderr
    assert "--subscription-id" in result.stderr
    assert "--subscription-product-id" in result.stderr
    assert "--renewal-product-id" in result.stderr


def test_gate_rejects_unapproved_standalone_target_before_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--preflight-only",
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
            wrap_fake_target=False,
        )

        assert result.returncode == 2
        assert "approved local Docker" in result.stderr
        assert not wp_invocations.exists()


def test_print_plan_describes_sepa_cutover_evidence() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--subscription-id",
        "77",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["browser_runner"] == "playwright"
    assert payload["browser_driver"].endswith("token-continuity.playwright.mjs")
    assert payload["target_wp"] == TARGET_WP
    assert payload["customer_id"] == 7
    assert payload["subscription_id"] == 77
    assert payload["subscription_source"] == "provided"
    assert payload["subscription_product_id"] is None
    assert payload["method"] == "sepa_debit"
    assert payload["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert payload["token_type"] == "wcpay_sepa"
    assert payload["stripe_payment_method_type"] == "sepa_debit"
    assert payload["checks"] == [
        "plugin_checkout_creates_real_sepa_payment_method",
        "plugin_source_persists_sepa_token",
        "native_cutover_loads_token",
        "native_my_account_renders_token",
        "native_sepa_subscription_renewal_succeeds",
    ]


def test_print_plan_describes_browser_created_subscription_fixture() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--subscription-product-id",
        "116",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["subscription_id"] is None
    assert payload["subscription_source"] == "browser_checkout"
    assert payload["subscription_product_id"] == 116
    assert payload["renewal_product_id"] is None
    assert payload["checkout_product_id"] is None
    assert "plugin_checkout_creates_subscription" in payload["checks"]


def test_print_plan_describes_state_provisioned_renewal_fixture() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--checkout-product-id",
        "187",
        "--renewal-product-id",
        "116",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["subscription_id"] is None
    assert payload["subscription_source"] == "provisioned_renewal_fixture"
    assert payload["subscription_product_id"] is None
    assert payload["checkout_product_id"] == 187
    assert payload["renewal_product_id"] == 116
    assert payload["checks"] == [
        "plugin_checkout_creates_real_sepa_payment_method",
        "plugin_source_persists_sepa_token",
        "fixture_binds_saved_token_to_subscription",
        "native_cutover_loads_token",
        "native_my_account_renders_token",
        "native_sepa_subscription_renewal_succeeds",
    ]


def test_print_plan_describes_add_payment_method_source_flow() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--renewal-product-id",
        "116",
        "--source-flow",
        "add-payment-method",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["subscription_source"] == "provisioned_renewal_fixture"
    assert payload["source_flow"] == "add_payment_method"
    assert payload["checkout_product_id"] is None
    assert payload["renewal_product_id"] == 116
    assert payload["checks"] == [
        "plugin_add_payment_method_creates_reusable_sepa_token",
        "plugin_source_persists_sepa_token",
        "fixture_binds_saved_token_to_subscription",
        "native_cutover_loads_token",
        "native_my_account_renders_token",
        "native_sepa_subscription_renewal_succeeds",
    ]


def test_print_plan_describes_provider_setup_intent_source_flow() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--renewal-product-id",
        "116",
        "--source-flow",
        "provider-setup-intent",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["subscription_source"] == "provisioned_renewal_fixture"
    assert payload["source_flow"] == "provider_setup_intent"
    assert payload["checkout_product_id"] is None
    assert payload["renewal_product_id"] == 116
    assert payload["checks"] == [
        "provider_setup_intent_creates_reusable_sepa_token",
        "plugin_source_persists_sepa_token",
        "fixture_binds_saved_token_to_subscription",
        "native_cutover_loads_token",
        "native_my_account_renders_token",
        "native_sepa_subscription_renewal_succeeds",
    ]


def test_full_gate_supports_explicit_playwriter_compatibility_runner() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--browser-runner",
            "playwriter",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in playwright_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        seed_invocations = [item for item in invocations if "-e" in item["argv"]]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert len(seed_invocations) == 2
        assert len(driver_invocations) == 2
        assert all("state.tokenContinuityConfig" in " ".join(item["argv"]) for item in seed_invocations)
        assert all('"checkoutUrl": "http://store8889.localhost:8889/?page_id=7"' in " ".join(item["argv"]) for item in seed_invocations)
        assert [item["env"]["phase"] for item in driver_invocations] == [
            "save_sepa_token",
            "render_payment_methods",
        ]
        assert all("-s" in item["argv"] and "unit" in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/token-continuity.playwright.mjs") in item["argv"] for item in driver_invocations)

        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - prepare-source-cart" not in wp_log
        assert "eval-file - preflight-plugin" in wp_log
        assert "eval-file - cutover-native 4242" in wp_log
        assert "eval-file - assert-native-token 7 4242 woocommerce_payments_sepa_debit wcpay_sepa" in wp_log
        assert "wc payment_token list" not in wp_log
        assert "eval-file - drive 77 woocommerce_payments_sepa_debit 4242" in wp_log
        assert "eval-file - restore" in wp_log

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["browser_runner"] == "playwriter"
        assert rollup["token_id"] == 4242
        assert rollup["customer_id"] == 7
        assert rollup["native_token_loader"]["token_id"] == 4242
        assert "cli_token_list" not in rollup
        assert rollup["renewal"]["success"] is True
        assert rollup["failures"] == []


def test_gate_does_not_depend_on_unregistered_wc_payment_token_command() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            payment_token_command_available=False,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 0, result.stderr
        wp_log = (tmp_path / "wp-invocations.txt").read_text(encoding="utf-8")
        assert "wc payment_token list" not in wp_log
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["browser_runner"] == "playwright"
        assert rollup["native_token_loader"]["success"] is True
        assert "cli_token_list" not in rollup


def test_gate_fails_and_rolls_up_plugin_state_restore_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            restore_failure=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 70
        assert "plugin-state restore failed" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["restore"]["success"] is False
        assert any("plugin-state restore failed" in failure for failure in rollup["failures"])


def test_exit_zero_negative_plugin_restore_stays_armed_for_cleanup_retry() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            restore_semantic_failure=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 70
        assert wp_invocations.read_text(encoding="utf-8").count("eval-file - restore") == 2


def test_cutover_failure_without_json_still_restores_plugin_state() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-cutover-cleanup-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            cutover_failure_without_json=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - cutover-native 4242" in wp_log
        assert "eval-file - restore" in wp_log


def test_full_gate_validates_browser_token_against_reusable_customer_payment_method() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - persist-source-token 7 pm_unitsepa123 mandate_unitsepa123 4242" in wp_log
        assert wp_log.index("eval-file - persist-source-token 7 pm_unitsepa123 mandate_unitsepa123 4242") < wp_log.index(
            "eval-file - cutover-native 4242"
        )

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["token_id"] == 4242
        assert rollup["source_token"]["token_id"] == 4242
        assert rollup["source_token"]["payment_method_id"] == "pm_unitsepa123"
        assert rollup["source_token"]["source_payment_method_customer_ready"] is True
        assert rollup["source_token"]["customer_payment_method_ids"] == ["pm_unitsepa123"]
        assert rollup["failures"] == []


def test_full_gate_can_use_add_payment_method_source_flow_for_renewal_fixture() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", subscription_product_id=116)
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--renewal-product-id",
            "116",
            "--source-flow",
            "add-payment-method",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - prepare-source-cart" not in wp_log
        assert "eval-file - persist-source-token 7 pm_unitsepa123 mandate_unitsepa123" in wp_log
        assert "eval-file - provision-renewal-subscription 7 4242 116 mandate_unitsepa123" in wp_log
        assert "eval-file - drive 9999 woocommerce_payments_sepa_debit 4242" in wp_log

        invocations = [
            json.loads(line)
            for line in playwright_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = invocations
        assert len(driver_invocations) == 2
        assert all("-e" not in item["argv"] and "-s" not in item["argv"] for item in driver_invocations)
        assert driver_invocations[0]["env"]["phase"] == "save_sepa_token"
        assert driver_invocations[0]["env"]["source_flow"] == "add_payment_method"
        assert driver_invocations[0]["env"]["url"] == (
            "http://store8889.localhost:8889/?page_id=8&add-payment-method=&token-continuity-gate=save-sepa-add-payment-method"
        )

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["source_flow"] == "add_payment_method"
        assert rollup["source_token"]["source_payment_method_customer_ready"] is True
        assert rollup["subscription_id"] == 9999
        assert rollup["failures"] == []


def test_full_gate_can_use_provider_setup_intent_source_flow_for_renewal_fixture() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", subscription_product_id=116)
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--renewal-product-id",
            "116",
            "--source-flow",
            "provider-setup-intent",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - prepare-source-cart" not in wp_log
        assert "eval-file - provision-source-token 7" in wp_log
        assert "eval-file - persist-source-token" not in wp_log
        assert "eval-file - provision-renewal-subscription 7 4242 116" in wp_log
        assert "eval-file - drive 9999 woocommerce_payments_sepa_debit 4242" in wp_log

        invocations = [
            json.loads(line)
            for line in playwright_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = invocations
        assert len(driver_invocations) == 1
        assert "-e" not in driver_invocations[0]["argv"]
        assert driver_invocations[0]["env"]["phase"] == "render_payment_methods"
        assert driver_invocations[0]["env"]["source_flow"] == "provider_setup_intent"

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["source_flow"] == "provider_setup_intent"
        assert rollup["save_token"] is None
        assert rollup["source_token"]["mode"] == "provision-source-token"
        assert rollup["source_token"]["payment_method_id"] == "pm_unitsepa123"
        assert rollup["source_token"]["setup_intent_id"] == "seti_unitsepa123"
        assert rollup["source_token"]["source_payment_method_customer_ready"] is True
        assert rollup["subscription_id"] == 9999
        assert rollup["failures"] == []


def test_full_gate_can_discover_browser_created_subscription_from_checkout_order() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", subscription_product_id=116)
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-product-id",
            "116",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - prepare-source-cart 7" in wp_log
        assert "eval-file - preflight-plugin" in wp_log
        assert "eval-file - subscription-from-order 909" in wp_log
        assert "eval-file - drive 8888 woocommerce_payments_sepa_debit 4242" in wp_log
        assert wp_log.index("eval-file - prepare-source-cart 7") < wp_log.index("eval-file - preflight-plugin")

        invocations = [
            json.loads(line)
            for line in playwright_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = invocations
        assert len(driver_invocations) == 2
        assert all("-e" not in item["argv"] and "-s" not in item["argv"] for item in driver_invocations)
        assert driver_invocations[0]["env"]["phase"] == "save_sepa_token"
        assert driver_invocations[0]["env"]["subscription_product_id"] == 116

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["subscription_id"] == 8888
        assert rollup["subscription_source"] == "browser_checkout"
        assert rollup["save_token"]["order_id"] == 909
        assert rollup["renewal"]["subscription_id"] == 8888
        assert rollup["failures"] == []


def test_full_gate_can_provision_renewal_fixture_from_saved_token() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", subscription_product_id=116)
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--checkout-product-id",
            "187",
            "--renewal-product-id",
            "116",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - prepare-source-cart 7" in wp_log
        assert "eval-file - preflight-plugin" in wp_log
        assert "eval-file - subscription-from-order" not in wp_log
        assert "eval-file - provision-renewal-subscription 7 4242 116" in wp_log
        assert "eval-file - drive 9999 woocommerce_payments_sepa_debit 4242" in wp_log
        assert wp_log.index("eval-file - prepare-source-cart 7") < wp_log.index("eval-file - preflight-plugin")

        invocations = [
            json.loads(line)
            for line in playwright_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = invocations
        assert len(driver_invocations) == 2
        assert all("-e" not in item["argv"] and "-s" not in item["argv"] for item in driver_invocations)
        assert driver_invocations[0]["env"]["phase"] == "save_sepa_token"
        assert driver_invocations[0]["env"]["checkout_product_id"] == 187
        assert driver_invocations[0]["env"]["subscription_product_id"] == 0

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["subscription_id"] == 9999
        assert rollup["subscription_source"] == "provisioned_renewal_fixture"
        assert rollup["checkout_product_id"] == 187
        assert rollup["renewal_product_id"] == 116
        assert rollup["save_token"]["checkout_product_id"] == 187
        assert rollup["renewal"]["subscription_id"] == 9999
        assert rollup["failures"] == []


def test_full_gate_persists_source_token_from_real_payment_method_when_checkout_does_not_tokenize_sepa() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", subscription_product_id=116, token_id=5252)
        make_fake_playwright_runner(fake_playwright_runner, source_token_requires_state_persistence=True)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--checkout-product-id",
            "187",
            "--renewal-product-id",
            "116",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - persist-source-token 7 pm_unitsepa123 mandate_unitsepa123" in wp_log
        assert "eval-file - provision-renewal-subscription 7 5252 116 mandate_unitsepa123" in wp_log
        assert "eval-file - cutover-native 5252" in wp_log
        assert "eval-file - drive 9999 woocommerce_payments_sepa_debit 5252" in wp_log
        assert wp_log.index("eval-file - persist-source-token 7 pm_unitsepa123 mandate_unitsepa123") < wp_log.index(
            "eval-file - provision-renewal-subscription 7 5252 116 mandate_unitsepa123"
        )

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["token_id"] == 5252
        assert rollup["save_token"]["token_id"] == 0
        assert rollup["save_token"]["payment_method_id"] == "pm_unitsepa123"
        assert rollup["save_token"]["mandate_id"] == "mandate_unitsepa123"
        assert rollup["save_token"]["source_token_requires_state_persistence"] is True
        assert rollup["source_token"]["token_id"] == 5252
        assert rollup["source_token"]["payment_method_id"] == "pm_unitsepa123"
        assert rollup["source_token"]["mandate_id"] == "mandate_unitsepa123"
        assert rollup["source_token"]["wcpay_customer_id"] == "cus_unit_source"
        assert rollup["source_token"]["source_payment_method_customer_ready"] is True
        assert rollup["source_token"]["customer_payment_method_ids"] == ["pm_unitsepa123"]
        assert rollup["subscription_fixture"]["token_id"] == 5252
        assert rollup["subscription_fixture"]["mandate_id"] == "mandate_unitsepa123"
        assert rollup["renewal"]["subscription_id"] == 9999
        assert rollup["failures"] == []


def test_stage_sepa_fixture_stages_and_restores_checkout_readiness() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwright_invocations = tmp_path / "playwright-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(playwright_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--stage-sepa-fixture",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - stage-lpm-fixture" in wp_log
        assert "eval-file - preflight-plugin" in wp_log
        assert "eval-file - restore-lpm-fixture" in wp_log
        assert wp_log.index("eval-file - stage-lpm-fixture") < wp_log.index("eval-file - preflight-plugin")
        assert (out_dir / "sepa-fixture-stage.json").exists()
        assert json.loads((out_dir / "sepa-fixture-stage.json").read_text(encoding="utf-8"))["method"] == "sepa_debit"


def test_stage_verification_failure_still_restores_the_mutated_fixture() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", sepa_stage_failure=True)
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--stage-sepa-fixture",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 3
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - stage-lpm-fixture" in wp_log
        assert "eval-file - restore-lpm-fixture" in wp_log
        assert wp_log.index("eval-file - stage-lpm-fixture") < wp_log.index("eval-file - restore-lpm-fixture")


def test_gate_fails_and_rolls_up_sepa_fixture_restore_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            sepa_restore_failure=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--stage-sepa-fixture",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 70
        assert "SEPA fixture restore failed" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["sepa_fixture_restore"]["success"] is False


def test_exit_zero_negative_sepa_restore_stays_armed_for_cleanup_retry() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            sepa_restore_semantic_failure=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--stage-sepa-fixture",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 70
        assert wp_invocations.read_text(encoding="utf-8").count("eval-file - restore-lpm-fixture") == 2


def test_sepa_stage_failure_without_json_restores_prearmed_snapshot() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-sepa-snapshot-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(
            target_wp,
            "http://store8889.localhost:8889",
            sepa_stage_failure_without_json=True,
        )
        make_fake_playwright_runner(fake_playwright_runner)

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--stage-sepa-fixture",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
            },
        )

        assert result.returncode == 3
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - snapshot-lpm-fixture" in wp_log
        assert "eval-file - stage-lpm-fixture" in wp_log
        assert "eval-file - restore-lpm-fixture" in wp_log


def test_real_sepa_fixture_driver_blocks_missing_or_pending_method_capability() -> None:
    missing_capability = run_real_payment_method_fixture_stage({"card_payments": "active"})
    pending_capability = run_real_payment_method_fixture_stage(
        {
            "card_payments": "active",
            "sepa_debit_payments": "pending",
        }
    )
    disabled_without_fixture_evidence = run_real_payment_method_fixture_stage(
        {
            "card_payments": "active",
            "sepa_debit_payments": "disabled",
        }
    )
    disabled_with_open_requirements = run_real_payment_method_fixture_stage(
        {
            "card_payments": "active",
            "sepa_debit_payments": "disabled",
        },
        capability_requirements={
            "sepa_debit_payments": ["settings.payments.statement_descriptor"],
        },
        fees={
            "sepa_debit": {
                "base": {
                    "percentage_rate": 0.008,
                    "fixed_rate": 30,
                    "currency": "usd",
                },
            },
        },
    )

    assert missing_capability["success"] is False
    assert missing_capability["previous"]["capability_key"] == "sepa_debit_payments"
    assert missing_capability["previous"]["capability_status"] is None
    assert any("sepa_debit_payments" in error and "missing" in error for error in missing_capability["errors"])

    assert pending_capability["success"] is False
    assert pending_capability["previous"]["capability_key"] == "sepa_debit_payments"
    assert pending_capability["previous"]["capability_status"] == "pending"
    assert any("sepa_debit_payments" in error and "pending" in error for error in pending_capability["errors"])

    assert disabled_without_fixture_evidence["success"] is False
    assert disabled_without_fixture_evidence["previous"]["capability_key"] == "sepa_debit_payments"
    assert disabled_without_fixture_evidence["previous"]["capability_status"] == "disabled"
    assert any(
        "sepa_debit_payments" in error and "method fees" in error
        for error in disabled_without_fixture_evidence["errors"]
    )

    assert disabled_with_open_requirements["success"] is False
    assert disabled_with_open_requirements["previous"]["capability_key"] == "sepa_debit_payments"
    assert disabled_with_open_requirements["previous"]["capability_status"] == "disabled"
    assert any(
        "sepa_debit_payments" in error and "no capability requirements" in error
        for error in disabled_with_open_requirements["errors"]
    )


def test_real_sepa_fixture_driver_allows_wpcom_disabled_capability_with_fee_and_no_requirements() -> None:
    disabled_capability = run_real_payment_method_fixture_stage(
        {
            "card_payments": "active",
            "sepa_debit_payments": "disabled",
        },
        capability_requirements={
            "sepa_debit_payments": [],
        },
        fees={
            "sepa_debit": {
                "base": {
                    "percentage_rate": 0.008,
                    "fixed_rate": 30,
                    "currency": "usd",
                },
            },
        },
    )

    assert disabled_capability["success"] is True
    assert disabled_capability["previous"]["capability_key"] == "sepa_debit_payments"
    assert disabled_capability["previous"]["capability_status"] == "disabled"
    assert disabled_capability["previous"]["capability_fixture_override"] is True


def test_gate_fails_when_native_state_assertion_omits_saved_token() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", omit_token=True)
        make_fake_playwright_runner(fake_playwright_runner)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "saved token 4242 is missing from native token storage" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("saved token 4242" in failure for failure in rollup["failures"])


def test_gate_requires_save_token_browser_semantic_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwright_runner = tmp_path / "fake-playwright-runner"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwright_runner(fake_playwright_runner, omit_save_semantics=True)

        env = {
            **os.environ,
            "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_playwright_runner),
            "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
            "FAKE_PLAYWRIGHT_INVOCATIONS": str(tmp_path / "playwright-invocations.jsonl"),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "save_sepa_token: missing url" in result.stderr
        assert "save_sepa_token: missing selected_gateway_id" in result.stderr
        assert "save_sepa_token: missing payment_method_id" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_real_browser_driver_is_not_the_incomplete_capture_stub() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "Submit-capable SEPA token-continuity browser flow is not implemented yet" not in source
    assert "status: 'incomplete'" not in source
    assert "async function runSaveSepaTokenPhase" in source
    assert "async function runRenderPaymentMethodsPhase" in source
    assert "selected_gateway_id:" in source
    assert "payment_method_id:" in source
    assert "token_visible:" in source


def test_real_browser_driver_can_create_subscription_checkout_evidence() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "TOKEN_CONTINUITY_GATE_SUBSCRIPTION_PRODUCT_ID" in source
    assert "TOKEN_CONTINUITY_GATE_CART_URL" in source
    assert "TOKEN_CONTINUITY_GATE_CHECKOUT_URL" in source
    assert "TOKEN_CONTINUITY_GATE_PAYMENT_METHODS_URL" in source
    assert "state.tokenContinuityConfig" in source
    assert "async function clearBrowserCart" in source
    assert "clearBrowserCart( page )" in source
    assert "subscriptionAddToCartUrl" in source
    assert "add-to-cart" in source
    assert "orderIdFromUrl" in source
    assert "order_id:" in source
    assert "subscription_product_id:" in source


def test_real_browser_driver_can_save_source_token_through_add_payment_method_flow() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "TOKEN_CONTINUITY_GATE_SOURCE_FLOW" in source
    assert "TOKEN_CONTINUITY_GATE_ADD_PAYMENT_METHOD_URL" in source
    assert "addPaymentMethodUrl" in source
    assert "save-sepa-add-payment-method" in source
    assert "async function submitAddPaymentMethod" in source
    assert "button[name=\"woocommerce_add_payment_method\"]" in source
    assert "runAddPaymentMethodSourceFlow" in source
    assert "source_flow:" in source
    assert "sourceTokenRequiresStatePersistence = false" in source


def test_source_fixture_prepares_cart_and_sepa_billing_before_gateway_selection() -> None:
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")
    browser_source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "prepare-source-cart" in state_source
    assert "_woocommerce_persistent_cart_" in state_source
    assert "billing_country" in state_source
    assert "set_billing_country" in state_source
    assert "'DE'" in state_source
    assert browser_source.index("await fillBillingFields( page );") < browser_source.index(
        "const selectedGatewayId = await selectSepaGateway( page );"
    )


def test_state_driver_binds_renewal_fixture_to_raw_plugin_era_sepa_token_row() -> None:
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")
    provision_section = state_source.split(
        "function woopayments_merge_token_continuity_provision_renewal_subscription"
    )[1].split("function woopayments_merge_token_continuity_seed_customer_meta")[0]

    assert "woopayments_merge_token_continuity_get_raw_source_token" in state_source
    assert "woopayments_merge_token_continuity_insert_raw_source_token" in state_source
    assert "woocommerce_payment_tokens" in state_source
    assert "woopayments_merge_token_continuity_bind_token_id_to_order" in state_source
    assert "update_meta_data( '_payment_tokens'" in state_source
    assert "get_user_option( $key, $customer_id )" in state_source
    assert "WC_Payment_Tokens::get( $token_id )" not in provision_section


def test_source_fixture_carries_payment_intent_mandate_into_renewal_parent_order() -> None:
    gate_source = SCRIPT.read_text(encoding="utf-8")
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")
    browser_source = BROWSER_DRIVER.read_text(encoding="utf-8")
    provision_section = state_source.split(
        "function woopayments_merge_token_continuity_provision_renewal_subscription"
    )[1].split("function woopayments_merge_token_continuity_seed_customer_meta")[0]

    assert "mandateIdsFromPayloadText" in browser_source
    assert "observed_mandate_ids" in browser_source
    assert "mandate_id: mandateId" in browser_source
    assert "mandate_id = payload.get(\"mandate_id\")" in gate_source
    assert "persist-source-token\" \"$SOURCE_TOKEN_JSON\" \"$CUSTOMER_ID\" \"$payment_method_id\" \"$mandate_id\"" in gate_source
    assert "provision-renewal-subscription\" \"$SUBSCRIPTION_FIXTURE_JSON\" \"$CUSTOMER_ID\" \"$token_id\" \"$RENEWAL_PRODUCT_ID\" \"$mandate_id\"" in gate_source
    assert "preg_match( '/^mandate_[A-Za-z0-9]{8,}$/'" in state_source
    assert "update_meta_data( '_stripe_mandate_id', $mandate_id )" in provision_section


def test_source_token_persistence_requires_customer_listed_reusable_payment_method() -> None:
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")
    persist_section = state_source.split(
        "function woopayments_merge_token_continuity_persist_source_token"
    )[1].split("function woopayments_merge_token_continuity_get_raw_source_token_by_payment_method")[0]

    assert "woopayments_merge_token_continuity_assert_source_payment_method_customer_ready" in state_source
    assert "get_payment_methods( $wcpay_customer_id, 'sepa_debit' )" in state_source
    assert "source_payment_method_customer_ready" in persist_section
    assert "customer_payment_method_ids" in persist_section
    assert "Source PaymentMethod is not reusable for the WCPay customer." in state_source
    assert persist_section.index(
        "woopayments_merge_token_continuity_assert_source_payment_method_customer_ready"
    ) < persist_section.index("woopayments_merge_token_continuity_get_raw_source_token_by_payment_method")


def test_source_token_state_validation_can_derive_payment_method_from_browser_token_id() -> None:
    gate_source = SCRIPT.read_text(encoding="utf-8")
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")
    persist_section = state_source.split(
        "function woopayments_merge_token_continuity_persist_source_token"
    )[1].split("function woopayments_merge_token_continuity_assert_source_payment_method_customer_ready")[0]

    assert 'persist-source-token" "$SOURCE_TOKEN_JSON" "$CUSTOMER_ID" "$payment_method_id" "$mandate_id" "$TOKEN_ID"' in gate_source
    assert "$source_token_id" in state_source
    assert "woopayments_merge_token_continuity_get_raw_source_token( $source_token_id )" in persist_section
    assert "Browser-reported source token row" in persist_section
    assert "'' === $payment_method_id" in persist_section
    assert "$payment_method_id = (string) $browser_raw_token['token'];" in persist_section


def test_provider_setup_intent_source_flow_uses_provider_backed_sepa_setup_intent() -> None:
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")

    assert "'provision-source-token' === $mode" in state_source
    assert "function woopayments_merge_token_continuity_provision_source_token" in state_source
    assert "https://api.stripe.com/v1/payment_methods" in state_source
    assert "\"Stripe-Account\" => $account_id" in state_source or "'Stripe-Account' => $account_id" in state_source
    assert "Create_And_Confirm_Setup_Intention::create()" in state_source
    assert "set_payment_method_types( array( 'sepa_debit' ) )" in state_source
    assert "set_mandate_data" in state_source
    assert "customer_acceptance" in state_source
    assert "assign_hook( 'woopayments_merge_token_continuity_create_setup_intention_request' )" in state_source
    assert "add_payment_method_to_user( $payment_method_id, $user )" in state_source
    assert "woopayments_merge_token_continuity_assert_source_payment_method_customer_ready" in state_source


def test_browser_visible_mandate_is_optional_for_source_token_readiness() -> None:
    gate_source = SCRIPT.read_text(encoding="utf-8")
    state_source = (REPO / "tools/woopayments-merge/token-continuity-state.php").read_text(encoding="utf-8")

    assert "missing mandate_id" not in gate_source
    assert "browser evidence did not include a mandate ID" not in gate_source
    assert "A Stripe mandate ID is required to persist the SEPA source token." not in state_source
    assert "A Stripe mandate ID is required to provision the SEPA renewal fixture." not in state_source


def test_real_browser_driver_waits_for_stripe_iban_frame_after_sepa_selection() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert 'input[name="iban"]' in source
    assert "#payment-ibanInput" in source
    assert "for ( let attempt = 0; attempt < 30; attempt++ )" in source
    assert "SEPA IBAN field was not found in checkout Stripe Elements frames after waiting" in source


def test_real_browser_driver_filters_stripe_payment_method_ids_strictly() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert r"\bpm_[A-Za-z0-9]{8,}\b" in source
    assert r"^pm_[A-Za-z0-9]{8,}$" in source
    assert r"\bpm_[A-Za-z0-9_]+\b" not in source


def test_real_browser_driver_records_failed_response_body_samples() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "failedResponseBodySampleLimit" in source
    assert "function responseBodySample" in source
    assert "body_sample" in source
    assert "body_error" in source
    assert "failedResponses.push( failedResponse )" in source
    assert source.index("text = await response.text();") < source.index("failedResponses.push( failedResponse )")


def main() -> None:
    tests = [
        test_usage_requires_target_customer_and_subscription,
        test_gate_rejects_unapproved_standalone_target_before_invocation,
        test_print_plan_describes_sepa_cutover_evidence,
        test_print_plan_describes_browser_created_subscription_fixture,
        test_print_plan_describes_state_provisioned_renewal_fixture,
        test_print_plan_describes_add_payment_method_source_flow,
        test_print_plan_describes_provider_setup_intent_source_flow,
        test_full_gate_invokes_browser_native_token_loader_and_renewal_checks,
        test_full_gate_can_use_add_payment_method_source_flow_for_renewal_fixture,
        test_full_gate_can_use_provider_setup_intent_source_flow_for_renewal_fixture,
        test_full_gate_validates_browser_token_against_reusable_customer_payment_method,
        test_full_gate_can_discover_browser_created_subscription_from_checkout_order,
        test_full_gate_can_provision_renewal_fixture_from_saved_token,
        test_full_gate_persists_source_token_from_real_payment_method_when_checkout_does_not_tokenize_sepa,
        test_stage_sepa_fixture_stages_and_restores_checkout_readiness,
        test_stage_verification_failure_still_restores_the_mutated_fixture,
        test_real_sepa_fixture_driver_blocks_missing_or_pending_method_capability,
        test_real_sepa_fixture_driver_allows_wpcom_disabled_capability_with_fee_and_no_requirements,
        test_gate_fails_when_native_state_assertion_omits_saved_token,
        test_gate_requires_save_token_browser_semantic_evidence,
        test_real_browser_driver_is_not_the_incomplete_capture_stub,
        test_real_browser_driver_can_create_subscription_checkout_evidence,
        test_real_browser_driver_can_save_source_token_through_add_payment_method_flow,
        test_source_fixture_prepares_cart_and_sepa_billing_before_gateway_selection,
        test_state_driver_binds_renewal_fixture_to_raw_plugin_era_sepa_token_row,
        test_source_fixture_carries_payment_intent_mandate_into_renewal_parent_order,
        test_source_token_persistence_requires_customer_listed_reusable_payment_method,
        test_source_token_state_validation_can_derive_payment_method_from_browser_token_id,
        test_provider_setup_intent_source_flow_uses_provider_backed_sepa_setup_intent,
        test_browser_visible_mandate_is_optional_for_source_token_readiness,
        test_real_browser_driver_waits_for_stripe_iban_frame_after_sepa_selection,
        test_real_browser_driver_filters_stripe_payment_method_ids_strictly,
        test_real_browser_driver_records_failed_response_body_samples,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
