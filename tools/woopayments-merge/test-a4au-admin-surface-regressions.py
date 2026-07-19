#!/usr/bin/env python3
"""Focused regression checks for A4au admin source-gate compatibility aliases."""

from __future__ import annotations

import importlib.util
import re
import tempfile
from pathlib import Path

try:
    import pytest
except ImportError:  # pragma: no cover - keeps direct script execution dependency-light.
    pytest = None


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a4-admin-surface-gate.py"


def load_module():
    spec = importlib.util.spec_from_file_location("a4_admin_surface_gate", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


if pytest is not None:

    @pytest.fixture(scope="module")
    def module():
        return load_module()


def test_a4at_legacy_aliases_are_allowed_redirects(module) -> None:
    expected = {
        "/payments/connect",
        "/payments/onboarding",
        "/payments/onboarding/kyc",
        "/payments/fraud-protection",
        "/payments/multi-currency-setup",
        "/payments/additional-payment-methods",
    }

    missing = expected - module.EXPECTED_LEGACY_REDIRECT_ROUTES

    assert not missing, f"A4at compatibility aliases missing from source-gate allowlist: {sorted(missing)}"


def test_pm_promotion_rest_route_expectations_match_reference_plugin(module) -> None:
    gate_source = MODULE_PATH.read_text(encoding="utf-8")
    fixture_source = (REPO / "tools/woopayments-merge/tests/a4-admin-surface-fixtures.sh").read_text(encoding="utf-8")

    assert "/payments/pm-promotions/(?P<id>[^/]+)/activate" in gate_source
    assert "/payments/pm-promotions/(?P<id>[^/]+)/dismiss" in gate_source
    assert "/payments/pm-promotions/(?P<promotion_id>" not in gate_source
    assert "/payments/pm-promotions/(?P<id>[^/]+)/activate" in fixture_source
    assert "/payments/pm-promotions/(?P<id>[^/]+)/dismiss" in fixture_source


def test_reference_baseline_requires_entrypoints_but_not_later_split_chunks(module) -> None:
    with tempfile.TemporaryDirectory(prefix="a4-reference-baseline-") as temp_dir:
        plugin_repo = Path(temp_dir)
        for relative_path in (
            "dist/index.js",
            "dist/index.css",
            "dist/settings.js",
            "dist/settings.css",
        ):
            path = plugin_repo / relative_path
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("reference asset\n", encoding="utf-8")
        failures: list[str] = []

        baseline = module.capture_plugin_baseline(plugin_repo, failures)

    assert failures == []
    assert baseline["overview-js"]["status"] == "not-published-in-reference"


def test_reference_fees_report_is_optional_without_weakening_target_route(module) -> None:
    del module
    browser_source = (
        REPO / "tools/woopayments-merge/a4-admin-browser-gate.playwright.mjs"
    ).read_text(encoding="utf-8")
    reports_surface = re.search(
        r"id: 'reports-fees',(?P<body>.*?)targetAssets:",
        browser_source,
        flags=re.DOTALL,
    )

    assert reports_surface is not None
    assert "referenceOptional:" in reports_surface.group("body")
    assert "protectedPath: '/woopayments/reports'" in reports_surface.group("body")


def main() -> None:
    module = load_module()
    tests = [
        test_a4at_legacy_aliases_are_allowed_redirects,
        test_pm_promotion_rest_route_expectations_match_reference_plugin,
        test_reference_baseline_requires_entrypoints_but_not_later_split_chunks,
        test_reference_fees_report_is_optional_without_weakening_target_route,
    ]
    for test in tests:
        test(module)
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
