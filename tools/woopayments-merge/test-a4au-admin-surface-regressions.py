#!/usr/bin/env python3
"""Focused regression checks for A4au admin source-gate compatibility aliases."""

from __future__ import annotations

import importlib.util
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a4-admin-surface-gate.py"


def load_module():
    spec = importlib.util.spec_from_file_location("a4_admin_surface_gate", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


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


def main() -> None:
    module = load_module()
    tests = [
        test_a4at_legacy_aliases_are_allowed_redirects,
    ]
    for test in tests:
        test(module)
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
