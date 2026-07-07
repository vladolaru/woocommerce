#!/usr/bin/env python3
"""Focused regression checks for the A5f cutover rehearsal harness."""

from __future__ import annotations

import importlib.util
import sys
import tempfile
from pathlib import Path
from types import SimpleNamespace


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a5f-cutover-rehearsal.py"


def load_module():
    assert MODULE_PATH.exists()
    spec = importlib.util.spec_from_file_location("a5f_cutover_rehearsal", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


def make_args(out_dir: str):
    return SimpleNamespace(
        target_wp="docker exec -i target-cli-1 wp --allow-root --user=1",
        target_url="http://store8889.localhost:8889",
        store_dir=str(REPO),
        playwriter_session="unit",
        out_dir=out_dir,
        skip_wpcom_readiness=False,
    )


def assert_raises(fn, expected: str):
    try:
        fn()
    except Exception as exc:
        assert expected in str(exc)
    else:
        raise AssertionError(f"Expected exception containing {expected!r}")


def test_validate_local_wp_command_rejects_shell_and_remote_transports():
    module = load_module()

    invalid_commands = [
        "docker exec -i target-cli-1 wp --allow-root --user=1; rm -rf .",
        "docker exec -i target-cli-1 wp --allow-root --user=1 && wp option get siteurl",
        "wp --http=https://example.com option get siteurl",
        "ssh example wp option get siteurl",
        "wpcom-local wp option get siteurl",
    ]

    for command in invalid_commands:
        assert_raises(lambda command=command: module.validate_local_wp_command(command), "unsafe or remote")


def test_validate_local_wp_command_accepts_local_docker_wp():
    module = load_module()

    assert module.validate_local_wp_command("docker exec -i target-cli-1 wp --allow-root --user=1") == [
        "docker",
        "exec",
        "-i",
        "target-cli-1",
        "wp",
        "--allow-root",
        "--user=1",
    ]


def test_rollup_is_written_after_failed_phase():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        rehearsal = module.Rehearsal(make_args(out_dir))
        rehearsal.record_phase("baseline-state", {"status": "pass", "ready": True})
        rehearsal.record_failure("soft-cutover", "missing notice")

        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert payload["status"] == "fail"
    assert payload["pass"] is False
    assert payload["phase_results"][0]["id"] == "baseline-state"
    assert payload["failures"] == [{"phase": "soft-cutover", "message": "missing notice"}]


def test_required_store_profiles_cover_lpm_mc_and_sepa_token_fixtures():
    module = load_module()

    profiles = {profile["id"]: profile for profile in module.required_store_profiles()}

    assert set(profiles) == {
        "lpm-wave-1-checkout",
        "multi-currency-rates",
        "sepa-token-continuity",
    }

    lpm = profiles["lpm-wave-1-checkout"]
    assert lpm["gate"] == "lpm-checkout-gate.sh"
    assert lpm["gate_plan_schema"] == "woopayments_lpm_checkout_gate_plan.v1"
    assert lpm["methods"] == [
        "sepa_debit",
        "ideal",
        "bancontact",
        "klarna",
        "affirm",
        "afterpay_clearpay",
    ]
    assert lpm["fixtures"]["sepa_debit"]["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert lpm["fixtures"]["affirm"]["currency"] == "USD"

    multi_currency = profiles["multi-currency-rates"]
    assert multi_currency["gate"] == "mc-rates-gate.sh"
    assert multi_currency["gate_plan_schema"] == "woopayments_mc_rates_gate_plan.v1"
    assert multi_currency["currency_from"] == "USD"
    assert multi_currency["currencies_to"] == ["GBP", "EUR"]

    token = profiles["sepa-token-continuity"]
    assert token["gate"] == "token-continuity-gate.sh"
    assert token["gate_plan_schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert token["method"] == "sepa_debit"
    assert token["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert token["token_type"] == "wcpay_sepa"
    assert token["required_fixture_inputs"] == ["customer_id", "subscription_id"]


def test_rollup_records_required_store_profiles():
    module = load_module()

    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        module.Rehearsal(make_args(out_dir))
        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert payload["required_store_profiles"] == module.required_store_profiles()


def test_parse_json_prefers_top_level_probe_payload():
    module = load_module()

    output = """
{
  "ready": true,
  "failures": [],
  "captured_requests": [
    {
      "body": {
        "statement_descriptor": "A5 LOCAL PROBE"
      }
    }
  ]
}
""".strip()

    payload = module.parse_json_from_output(output)

    assert payload["ready"] is True
    assert payload["captured_requests"][0]["body"]["statement_descriptor"] == "A5 LOCAL PROBE"


def test_expected_failure_command_records_pass_phase():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        rehearsal = module.Rehearsal(make_args(out_dir))
        result = rehearsal.run_command(
            "activation-guard-blocks-under-mandatory",
            [
                sys.executable,
                "-c",
                "import sys; print('now included in WooCommerce core'); sys.exit(1)",
            ],
            expected_failure_message="now included in WooCommerce core",
        )

        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert result["status"] == "pass"
    assert result["expected_failure"] is True
    assert result["observed_exit_code"] == 1
    assert payload["phase_results"][0]["status"] == "pass"
    assert payload["phase_results"][0]["expected_failure"] is True


def test_orchestrator_uses_browser_for_blocked_mandatory_gate():
    source = MODULE_PATH.read_text(encoding="utf-8")

    assert "a5-blocked-mandatory-browser-gate.playwriter.mjs" in source
    assert "trigger-blocked-mandatory-admin-init" not in source


def test_playwriter_gate_copies_failed_source_evidence():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        out_path = Path(out_dir)
        source_evidence = out_path.parent / "a5e-failing-gate.json"
        try:
            rehearsal = module.Rehearsal(make_args(out_dir))

            def fail_command(*args, **kwargs):
                source_evidence.write_text('{"status":"failed"}\n', encoding="utf-8")
                raise module.HarnessError("browser gate failed")

            rehearsal.run_command = fail_command
            assert_raises(
                lambda: rehearsal.run_playwriter_gate("failing-gate", MODULE_PATH, source_evidence),
                "browser gate failed",
            )

            copied = out_path / "a5f-failing-gate.json"
            assert copied.exists()
            assert module.read_json(copied)["status"] == "failed"
            assert str(copied) in rehearsal.browser_evidence_paths
        finally:
            source_evidence.unlink(missing_ok=True)


def test_browser_gates_use_isolated_pages_and_close_them():
    scripts = [
        REPO / "tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-mandatory-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-blocked-mandatory-browser-gate.playwriter.mjs",
    ]

    for script in scripts:
        source = script.read_text(encoding="utf-8")
        assert "state.page = await context.newPage();" in source
        assert "await page.close(" in source
        assert "context.pages().find" not in source


def main() -> None:
    tests = [
        test_validate_local_wp_command_rejects_shell_and_remote_transports,
        test_validate_local_wp_command_accepts_local_docker_wp,
        test_rollup_is_written_after_failed_phase,
        test_required_store_profiles_cover_lpm_mc_and_sepa_token_fixtures,
        test_rollup_records_required_store_profiles,
        test_parse_json_prefers_top_level_probe_payload,
        test_expected_failure_command_records_pass_phase,
        test_orchestrator_uses_browser_for_blocked_mandatory_gate,
        test_playwriter_gate_copies_failed_source_evidence,
        test_browser_gates_use_isolated_pages_and_close_them,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
