#!/usr/bin/env python3
"""Focused regression checks for the subscriptions-renewal gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/subscriptions-renewal-gate.sh"

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


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, *, role: str, drive_status: str = "processing") -> None:
    token_base = "1000" if role == "ref" else "2000"
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$*" >> "${{FAKE_WP_INVOCATIONS:?}}"
if [ "$1" = "eval-file" ] && [ "$2" = "-" ]; then
    mode="${{3:-}}"
    if [ "$mode" = "preflight" ]; then
        printf '{{"success":true,"mode":"preflight","role":"%s","errors":[]}}\\n' "${{4:-}}"
        exit 0
    fi
    if [ "$mode" = "drive" ]; then
        printf '%s\\n' {json.dumps(renewal_payload(token_base, drive_status))}
        exit 0
    fi
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def renewal_payload(token_base: str, drive_status: str) -> str:
    payload = {
        "success": True,
        "mode": "drive",
        "errors": [],
        "success_checks_failed": [],
        "subscription_id": int(token_base),
        "subscription_status": "active",
        "subscription_payment_method": "woocommerce_payments",
        "renewal_order_id": int(token_base) + 1,
        "renewal_order_status": drive_status,
        "renewal_order_payment_method": "woocommerce_payments",
        "expected_renewal_gateway_id": "woocommerce_payments",
        "expected_token_id": 0,
        "renewal_belongs_to_subscription": True,
        "wcs_order_contains_renewal_exists": True,
        "wcs_order_contains_renewal": True,
        "payment_meta_presence": {
            "_transaction_id": True,
            "_intent_id": True,
            "_charge_id": False,
            "_payment_method_id": True,
            "_stripe_customer_id": True,
            "_wcpay_payment_transaction_id": True,
            "_wcpay_payment_method_details": True,
        },
        "subscription_meta_presence": {
            "_payment_method_id": True,
            "_schedule_next_payment": True,
            "_stripe_customer_id": True,
        },
        "token_presence": {
            "subscription_has_tokens": True,
            "renewal_has_tokens": False,
            "customer_has_tokens": True,
            "customer_gateway_id": "woocommerce_payments",
            "subscription_token_ids": [int(token_base) + 10],
            "renewal_token_ids": [],
            "customer_token_ids": [int(token_base) + 20],
            "all_token_ids": [int(token_base) + 10, int(token_base) + 20],
        },
        "customer_meta_presence": {
            "_wcpay_customer_id": True,
            "_wcpay_customer_id_test": True,
            "_wcpay_customer_id_live": False,
        },
        "emails": [
            {
                "id": "customer_processing_order",
                "class": "WC_Email_Customer_Processing_Order",
                "subject": "renewal order",
            }
        ],
    }
    return json.dumps(payload)


def test_help_exits_zero_without_wp_args() -> None:
    result = run_gate("--help")

    assert result.returncode == 0
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_usage_requires_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_compare_requires_browser_created_subscription_ids() -> None:
    result = run_gate("compare", "--ref", REF_WP, "--target", TARGET_WP)

    assert result.returncode == 2
    assert "browser-created subscription IDs are required" in result.stderr


def test_preflight_runs_reference_and_target_without_subscription_ids() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        wp_invocations = tmp_path / "wp-invocations.txt"

        make_fake_wp(ref_wp, role="ref")
        make_fake_wp(target_wp, role="target")

        result = run_gate(
            "preflight",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations)},
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: WC Subscriptions renewal preflight passed" in result.stdout
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - preflight ref" in wp_log
        assert "eval-file - preflight target" in wp_log


def test_compare_normalizes_reference_and_target_renewal_facts() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        wp_invocations = tmp_path / "wp-invocations.txt"

        make_fake_wp(ref_wp, role="ref")
        make_fake_wp(target_wp, role="target")

        result = run_gate(
            "compare",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--ref-subscription-id",
            "101",
            "--target-subscription-id",
            "202",
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations)},
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: WC Subscriptions renewal facts match reference." in result.stdout
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - drive 101" in wp_log
        assert "eval-file - drive 202" in wp_log


def test_compare_fails_when_normalized_renewal_facts_differ() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"

        make_fake_wp(ref_wp, role="ref", drive_status="processing")
        make_fake_wp(target_wp, role="target", drive_status="failed")

        result = run_gate(
            "compare",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--ref-subscription-id",
            "101",
            "--target-subscription-id",
            "202",
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt")},
        )

        assert result.returncode == 1
        assert "FAIL: normalized WC Subscriptions renewal facts differ." in result.stderr
        assert '-    "renewal_order_status": "processing"' in result.stdout
        assert '+    "renewal_order_status": "failed"' in result.stdout
