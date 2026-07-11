#!/usr/bin/env python3
"""Inventory checks for the WooPayments critical-flows suite."""

from __future__ import annotations

from pathlib import Path


ROOT = Path(__file__).resolve().parent
README = ROOT / "README.md"
AGENT_RESULT_BUILDER = ROOT / "build-agent-results.py"
AGENT_TEMPLATE = ROOT / "agent-specs" / "_template.md"
RUNNER = ROOT / "run.sh"
SS10_FLOW = ROOT / "flows" / "SS-10-sepa-token-renewal-cutover.md"

REQUIRED_NATIVE_MERGE_FLOWS = {
    "SC-14": {
        "title": "LPM wave-1 checkout",
        "file": "SC-14-lpm-wave-1-checkout.md",
        "tokens": [
            "lpm-checkout-gate.sh",
            "sepa_debit",
            "ideal",
            "bancontact",
            "klarna",
            "affirm",
            "afterpay_clearpay",
        ],
    },
    "SS-10": {
        "title": "SEPA-token renewal after cutover",
        "file": "SS-10-sepa-token-renewal-cutover.md",
        "tokens": [
            "token-continuity-gate.sh",
            "subscriptions-renewal-gate.sh",
            "provider_setup_intent",
            "woocommerce_payments_sepa_debit",
            "wcpay_sepa",
        ],
    },
    "MC-06": {
        "title": "Automatic rates refresh",
        "file": "MC-06-automatic-rates-refresh.md",
        "tokens": [
            "mc-rates-gate.sh",
            "USD",
            "GBP",
            "EUR",
        ],
    },
    "MA-10": {
        "title": "Localized WooPayments order notes",
        "file": "MA-10-i18n-order-notes.md",
        "tokens": [
            "i18n-notes-gate.sh",
            "French",
            "native order notes",
        ],
    },
    "MA-11": {
        "title": "Plugin-active WooPayments settings screen",
        "file": "MA-11-plugin-active-settings-screen.md",
        "tokens": [
            "plugin-active",
            "woocommerce_payments",
            "settings screen",
        ],
    },
}


def test_readme_lists_native_merge_required_flows() -> None:
    text = README.read_text(encoding="utf-8")

    for flow_id, flow in REQUIRED_NATIVE_MERGE_FLOWS.items():
        assert f"| {flow_id} |" in text
        assert flow["title"] in text


def test_required_flow_specs_exist_and_name_fail_closed_gates() -> None:
    for flow_id, flow in REQUIRED_NATIVE_MERGE_FLOWS.items():
        path = ROOT / "flows" / flow["file"]
        assert path.exists(), f"{flow_id} spec is missing"

        text = path.read_text(encoding="utf-8")
        assert flow_id in text
        assert "reference" in text.lower()
        assert "target" in text.lower()
        assert "BLOCKED" in text or "fail-closed" in text
        for token in flow["tokens"]:
            assert token in text, f"{flow_id} spec does not mention {token}"


def test_readme_documents_agent_result_ingestion_contract() -> None:
    text = README.read_text(encoding="utf-8")

    assert "--agent-results-dir" in text
    assert "agent-results" in text
    assert "<flow>.json" in text
    assert "parity_verdict" in text
    assert "build-agent-results.py" in text
    assert "BLOCKED" in text


def test_agent_result_builder_exists_and_exposes_required_final_evidence_inputs() -> None:
    assert AGENT_RESULT_BUILDER.exists(), "build-agent-results.py is required for final-evidence Layer-A synthesis"

    text = AGENT_RESULT_BUILDER.read_text(encoding="utf-8")
    for token in (
        "--copy-agent-result",
        "--require-agent-flow",
        "--require-ms07",
        "--ms07-reference-browser",
        "--ms07-reference-state",
        "--ms07-target-browser",
        "--ms07-target-state",
        "--plugin-active-reference-gate",
        "--lpm-gate",
        "--token-continuity-gate",
    ):
        assert token in text, f"build-agent-results.py does not expose {token}"


def test_ss10_is_documented_as_target_only_and_not_cross_store_parity() -> None:
    readme = README.read_text(encoding="utf-8").lower()
    flow = SS10_FLOW.read_text(encoding="utf-8").lower()

    assert "ss-10" in readme
    assert "target-only" in readme
    assert "not comparable" in readme
    assert "target-only" in flow
    assert "does not claim cross-store parity" in flow


def test_agent_dispatch_contract_supports_target_only_flows() -> None:
    template = AGENT_TEMPLATE.read_text(encoding="utf-8").lower()
    runner = RUNNER.read_text(encoding="utf-8").lower()
    flow = SS10_FLOW.read_text(encoding="utf-8").lower()

    assert "{{oracle_mode}}" in template
    assert "target-only" in template
    assert "reference" in template and "not comparable" in template
    assert "each flow spec's oracle mode" in runner
    assert "agent oracle mode: target-only" in flow


def main() -> None:
    tests = [
        test_readme_lists_native_merge_required_flows,
        test_required_flow_specs_exist_and_name_fail_closed_gates,
        test_readme_documents_agent_result_ingestion_contract,
        test_agent_result_builder_exists_and_exposes_required_final_evidence_inputs,
        test_ss10_is_documented_as_target_only_and_not_cross_store_parity,
        test_agent_dispatch_contract_supports_target_only_flows,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
