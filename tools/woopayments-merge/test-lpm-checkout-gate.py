#!/usr/bin/env python3
"""Focused regression checks for the LPM checkout gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/lpm-checkout-gate.sh"
BROWSER_DRIVER = REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
	return subprocess.run(
		["bash", str(SCRIPT), *args],
		cwd=REPO,
		text=True,
		stdout=subprocess.PIPE,
		stderr=subprocess.PIPE,
		env=env,
		check=False,
	)


def test_usage_requires_methods_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--methods" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_unknown_method_is_usage_error() -> None:
    result = run_gate(
        "--methods",
        "bogus",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsupported method: bogus" in result.stderr


def test_print_plan_describes_wave_1_methods() -> None:
    result = run_gate(
        "--methods",
        "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "sepa_debit",
        "ideal",
        "bancontact",
        "klarna",
        "affirm",
        "afterpay_clearpay",
    ]
    assert payload["fixtures"]["sepa_debit"]["currency"] == "EUR"
    assert payload["fixtures"]["sepa_debit"]["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert payload["fixtures"]["ideal"]["currency"] == "EUR"
    assert payload["fixtures"]["bancontact"]["country"] == "BE"
    assert payload["fixtures"]["klarna"]["gateway_id"] == "woocommerce_payments_klarna"
    assert payload["fixtures"]["affirm"]["currency"] == "USD"
    assert payload["fixtures"]["afterpay_clearpay"]["country"] == "US"


def test_print_plan_describes_wave_2_methods() -> None:
    result = run_gate(
        "--methods",
        "eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "eps",
        "p24",
        "multibanco",
        "au_becs_debit",
        "grabpay",
        "wechat_pay",
        "alipay",
    ]
    assert payload["fixtures"]["eps"]["country"] == "AT"
    assert payload["fixtures"]["p24"]["currency"] == "PLN"
    assert payload["fixtures"]["multibanco"]["family"] == "voucher"
    assert payload["fixtures"]["au_becs_debit"]["gateway_id"] == "woocommerce_payments_au_becs_debit"
    assert payload["fixtures"]["grabpay"]["currency"] == "SGD"
    assert payload["fixtures"]["wechat_pay"]["stripe_payment_method_type"] == "wechat_pay"
    assert payload["fixtures"]["alipay"]["country"] == "NL"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(
    path: Path,
    home_url: str,
    *,
    checkout_page_id: int = 7,
    classic_checkout_page_id: int = 90,
    product_id: int = 1001,
    stageable: bool = True,
) -> None:
    stageable_literal = "1" if stageable else "0"
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
if [ -n "${{FAKE_WP_INVOCATIONS:-}}" ]; then
\tprintf '%s\\n' "$*" >> "$FAKE_WP_INVOCATIONS"
fi
stageable={stageable_literal}
state_file="${{FAKE_WP_STATE:-$0.state.json}}"
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
\tprintf '%s\\n' {json.dumps(home_url)}
\texit 0
fi
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "woocommerce_checkout_page_id" ]; then
\tprintf '%s\\n' {checkout_page_id}
\texit 0
fi
if [ "$1" = "wc" ] && [ "$2" = "product" ] && [ "$3" = "list" ]; then
\tprintf '%s\\n' {product_id}
\texit 0
fi
if [ "$1" = "post" ] && [ "$2" = "list" ]; then
\tprintf '[{{"ID":{classic_checkout_page_id},"post_title":"Codex Classic Checkout","post_name":"codex-classic-checkout"}},{{"ID":{checkout_page_id},"post_title":"Checkout","post_name":"checkout"}}]\\n'
\texit 0
fi
if [ "$1" = "post" ] && [ "$2" = "list-legacy-products" ]; then
\tprintf '11\\n'
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "stage-lpm-fixture" ]; then
\tcat >/dev/null
\tpython3 - "$state_file" "${{4:-}}" "${{FAKE_WP_INVOCATIONS:-}}" <<'PY'
import base64
import json
import sys
from pathlib import Path

state_path = Path(sys.argv[1])
method, currency, country = json.loads(base64.b64decode(sys.argv[2]).decode("utf-8"))
invocations_path = Path(sys.argv[3]) if len(sys.argv) > 3 and sys.argv[3] else None
state_path.write_text(
    json.dumps(
        {{
            "settings": {{"upe_enabled_payment_method_ids": ["card", method]}},
            "currency": currency,
            "country": country,
        }},
        sort_keys=True,
    )
    + "\\n",
    encoding="utf-8",
)
if invocations_path:
    with invocations_path.open("a", encoding="utf-8") as stream:
        stream.write(f"stage-lpm-fixture decoded {{method}} {{currency}} {{country}}\\n")
print(json.dumps({{"success": True, "mode": "stage-lpm-fixture", "previous": {{"settings": {{"upe_enabled_payment_method_ids": ["card"]}}, "currency": "USD", "country": "US:CA"}}}}))
PY
\texit 0
fi
if [ "$stageable" = "1" ] && [ "$1" = "eval-file" ] && [ "$2" = "-" ] && [ "${{3:-}}" = "restore-lpm-fixture" ]; then
\tcat >/dev/null
\tprintf '{{"settings":{{"upe_enabled_payment_method_ids":["card"]}},"currency":"USD","country":"US:CA"}}\\n' > "$state_file"
\tprintf '{{"success":true,"mode":"restore-lpm-fixture","errors":[]}}\\n'
\texit 0
fi
if [ "$1" = "eval" ]; then
\tprintf '%s\\n' {product_id}
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_playwriter(
    path: Path,
    *,
    omit_order_id: bool = False,
    omit_semantic_fields: bool = False,
    use_base_card_gateway: bool = False,
) -> None:
    order_id_expr = "None" if omit_order_id else "1001"
    selected_gateway_expr = "None" if omit_semantic_fields else 'os.environ["LPM_GATE_GATEWAY_ID"]'
    order_payment_method_expr = (
        '"woocommerce_payments"' if use_base_card_gateway else selected_gateway_expr
    )
    order_received_url_expr = (
        '""'
        if omit_semantic_fields
        else 'os.environ["LPM_GATE_BASE_URL"] + "/checkout/order-received/1001/"'
    )
    payment_intent_expr = (
        '""'
        if omit_semantic_fields
        else '"pi_unit_" + os.environ["LPM_GATE_METHOD"]'
    )
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import pathlib
import sys

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({{
        "argv": sys.argv[1:],
        "env": {{
            "role": os.environ.get("LPM_GATE_ROLE", ""),
            "method": os.environ.get("LPM_GATE_METHOD", ""),
            "surface": os.environ.get("LPM_GATE_SURFACE", ""),
            "base_url": os.environ.get("LPM_GATE_BASE_URL", ""),
            "gateway_id": os.environ.get("LPM_GATE_GATEWAY_ID", ""),
            "stripe_payment_method_type": os.environ.get("LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE", ""),
            "product_id": os.environ.get("LPM_GATE_PRODUCT_ID", ""),
            "checkout_page_id": os.environ.get("LPM_GATE_CHECKOUT_PAGE_ID", ""),
            "evidence_path": os.environ.get("LPM_GATE_EVIDENCE_PATH", ""),
        }},
    }}, sort_keys=True) + "\\n")

if "-e" in sys.argv:
    sys.exit(0)

payload = {{
    "schema": "woopayments_lpm_checkout_browser_evidence.v1",
    "status": "pass",
    "role": os.environ["LPM_GATE_ROLE"],
    "method": os.environ["LPM_GATE_METHOD"],
    "surface": os.environ["LPM_GATE_SURFACE"],
    "base_url": os.environ["LPM_GATE_BASE_URL"],
    "gateway_id": os.environ["LPM_GATE_GATEWAY_ID"],
    "stripe_payment_method_type": os.environ["LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE"],
    "order_id": {order_id_expr},
    "selected_gateway_id": {selected_gateway_expr},
    "order_payment_method": {order_payment_method_expr},
    "order_received_url": {order_received_url_expr},
    "payment_intent_id": {payment_intent_expr},
    "used_base_card_gateway": {str(use_base_card_gateway)},
    "failures": [],
}}

evidence_path = pathlib.Path(os.environ["LPM_GATE_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
""",
    )


def test_full_gate_invokes_playwriter_driver_for_each_store_and_validates_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 4
        seed_invocations = [item for item in invocations if "-e" in item["argv"]]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert len(seed_invocations) == 2
        assert len(driver_invocations) == 2
        assert all("state.lpmCheckoutConfig" in " ".join(item["argv"]) for item in seed_invocations)
        assert all("woocommerce_payments_ideal" in " ".join(item["argv"]) for item in seed_invocations)
        assert all('"productId": "1001"' in " ".join(item["argv"]) for item in seed_invocations)
        assert all('"checkoutPageId": "90"' in " ".join(item["argv"]) for item in seed_invocations)
        assert {item["env"]["role"] for item in driver_invocations} == {"reference", "target"}
        assert {item["env"]["base_url"] for item in driver_invocations} == {
            "http://localhost:8082",
            "http://store8889.localhost:8889",
        }
        assert all("-s" in item["argv"] and "unit" in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs") in item["argv"] for item in driver_invocations)

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert len(rollup["results"]) == 2
        assert all(item["method"] == "ideal" for item in rollup["results"])
        assert all(item["gateway_id"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["stripe_payment_method_type"] == "ideal" for item in rollup["results"])
        assert all(item["order_id"] == 1001 for item in rollup["results"])
        assert all(item["selected_gateway_id"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["order_payment_method"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["payment_intent_id"].startswith("pi_unit_") for item in rollup["results"])


def test_full_gate_stages_and_restores_per_method_lpm_fixtures() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        wp_invocations = tmp_path / "wp-invocations.txt"
        ref_state = tmp_path / "ref-state.json"
        target_state = tmp_path / "target-state.json"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082", stageable=True)
        make_fake_wp(target_wp, "http://store8889.localhost:8889", stageable=True)
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_WP_STATE": str(ref_state),
        }

        result = run_gate(
            "--methods",
            "sepa_debit",
            "--ref",
            f"FAKE_WP_STATE={ref_state} {ref_wp}",
            "--target",
            f"FAKE_WP_STATE={target_state} {target_wp}",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert wp_log.count("eval-file - stage-lpm-fixture") == 2
        assert wp_log.count("eval-file - restore-lpm-fixture") == 2
        assert wp_log.count("stage-lpm-fixture decoded sepa_debit EUR NL") == 2
        assert json.loads(ref_state.read_text(encoding="utf-8")) == {
            "settings": {"upe_enabled_payment_method_ids": ["card"]},
            "currency": "USD",
            "country": "US:CA",
        }
        assert json.loads(target_state.read_text(encoding="utf-8")) == {
            "settings": {"upe_enabled_payment_method_ids": ["card"]},
            "currency": "USD",
            "country": "US:CA",
        }


def test_gate_prefers_explicit_browser_urls_over_wp_home_options() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost")
        make_fake_wp(target_wp, "http://internal-target.localhost")
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        driver_invocations = [item for item in invocations if "-f" in item["argv"]]
        assert {item["env"]["base_url"] for item in driver_invocations} == {
            "http://localhost:8082",
            "http://store8889.localhost:8889",
        }


def test_gate_fails_when_driver_evidence_omits_required_order_fields() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_order_id=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing order_id" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("missing order_id" in failure for failure in rollup["failures"])


def test_gate_requires_semantic_lpm_checkout_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_semantic_fields=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing selected_gateway_id" in result.stderr
        assert "missing order_payment_method" in result.stderr
        assert "missing order_received_url" in result.stderr
        assert "missing payment_intent_id" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_rejects_base_card_gateway_fallback_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, use_base_card_gateway=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "order_payment_method mismatch" in result.stderr
        assert "used_base_card_gateway must be false" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_real_browser_driver_is_not_the_incomplete_capture_stub() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "Submit-capable LPM checkout flow is not implemented yet" not in source
    assert "status: 'incomplete'" not in source
    assert "async function selectPaymentMethod" in source
    assert "async function submitCheckout" in source
    assert "async function approveExternalAuthorization" in source
    assert "async function extractOrderEvidence" in source
    assert "async function waitForPaymentElementReady" in source
    assert "selected_gateway_id:" in source
    assert "order_payment_method:" in source
    assert "used_base_card_gateway:" in source
    assert "state.lpmCheckoutConfig" in source
    assert "addToCartUrl" in source
    assert "async function gotoLocalPage" in source
    assert "gateway_html_sample" in source
    assert "frame_diagnostics" in source
    assert "requestfailed" in source
    assert "failed_requests" in source
    assert "stripe_runtime" in source


def test_debit_browser_filler_does_not_fill_every_visible_input_for_sepa() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "const inputType = await input.getAttribute( 'type' )" in source
    assert "/^(checkbox|radio|hidden|submit|button|reset)$/i.test( inputType || '' )" in source
    assert "const isStripeFrame =" in source
    assert "const isSelectedGatewayInput =" in source
    assert "if ( ! isStripeFrame && ! isSelectedGatewayInput )" in source
    assert "method === 'sepa_debit' && isStripeFrame" in source
    assert "await waitForPaymentElementReady( page )" in source


def test_payment_element_option_selector_is_gateway_scoped() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")

    assert "async function selectPaymentElementOptions( page )" in source
    assert "const isSelectedGatewaySelect = await select.evaluate" in source
    assert "if ( ! isStripeFrame && ! isSelectedGatewaySelect )" in source
    assert "const paymentElement = await waitForPaymentElementReady( page )" in source
    assert "const selectedOptions = await selectPaymentElementOptions( page )" in source


def test_lpm_fixture_stages_split_gateway_settings_option() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "$split_settings_option = 'woocommerce_woocommerce_payments_'" in source
    assert "$split_settings['enabled']" in source
    assert "$split_settings['upe_enabled_payment_method_ids']" in source
    assert "'split_settings_exists'" in source
    assert "delete_option( $split_settings_option )" in source


def test_lpm_fixture_stages_account_capability_cache() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "$account_option = 'wcpay_account_data'" in source
    assert "$capability_key = $method . '_payments'" in source
    assert "$account_data['country'] = $country" in source
    assert "$account_data['capabilities'][ $capability_key ] = 'active'" in source
    assert "$account_data['store_currencies']['default'] = strtolower( $currency )" in source
    assert "'account_cache_exists'" in source
    assert "delete_option( $account_option )" in source


def main() -> None:
    tests = [
        test_usage_requires_methods_ref_and_target,
        test_unknown_method_is_usage_error,
        test_print_plan_describes_wave_1_methods,
        test_print_plan_describes_wave_2_methods,
        test_full_gate_invokes_playwriter_driver_for_each_store_and_validates_evidence,
        test_full_gate_stages_and_restores_per_method_lpm_fixtures,
        test_gate_prefers_explicit_browser_urls_over_wp_home_options,
        test_gate_fails_when_driver_evidence_omits_required_order_fields,
        test_gate_requires_semantic_lpm_checkout_evidence,
        test_gate_rejects_base_card_gateway_fallback_evidence,
        test_real_browser_driver_is_not_the_incomplete_capture_stub,
        test_debit_browser_filler_does_not_fill_every_visible_input_for_sepa,
        test_payment_element_option_selector_is_gateway_scoped,
        test_lpm_fixture_stages_split_gateway_settings_option,
        test_lpm_fixture_stages_account_capability_cache,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
