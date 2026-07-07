#!/usr/bin/env python3
"""Regression checks for verify.sh full-evidence orchestration."""

from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
ALL_LPM_METHODS = "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"


def run_verify(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_full_evidence_plan_lists_final_gates() -> None:
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    assert "lpm-checkout-gate.sh" in result.stdout
    assert ALL_LPM_METHODS in result.stdout
    assert "mc-rates-gate.sh" in result.stdout
    assert "subscriptions-renewal-gate.sh preflight" in result.stdout
    assert "token-continuity-gate.sh" in result.stdout
    assert "a5f-cutover-rehearsal.py" in result.stdout
    assert "a5g-multisite-runtime-gate.py" in result.stdout
    assert "woopayments-critical-flows/test-inventory.py" in result.stdout


def test_full_evidence_flag_is_documented_in_usage() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--full-evidence" in result.stderr
    assert "--print-full-evidence-plan" in result.stderr


def main() -> None:
    tests = [
        test_full_evidence_plan_lists_final_gates,
        test_full_evidence_flag_is_documented_in_usage,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
