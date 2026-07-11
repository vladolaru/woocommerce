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
DRIVER = REPO / "tools/woopayments-merge/subscriptions-renewal-drive.php"

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


def run_driver(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", str(DRIVER), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(
    path: Path,
    *,
    role: str,
    drive_status: str = "processing",
    payment_family: str = "card",
) -> None:
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
	        printf '%s\\n' {json.dumps(renewal_payload(token_base, drive_status, payment_family))}
        exit 0
    fi
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def renewal_payload(
    token_base: str, drive_status: str, payment_family: str = "card"
) -> str:
    is_sepa = payment_family == "sepa"
    gateway_id = (
        "woocommerce_payments_sepa_debit"
        if is_sepa
        else "woocommerce_payments"
    )
    token_type = "wcpay_sepa" if is_sepa else "CC"
    expected_token_id = int(token_base) + 10
    payload = {
        "success": True,
        "mode": "drive",
        "errors": [],
        "success_checks_failed": [],
        "subscription_id": int(token_base),
        "subscription_status": "on-hold" if is_sepa else "active",
        "subscription_payment_method": gateway_id,
        "renewal_order_id": int(token_base) + 1,
        "renewal_order_status": "pending" if is_sepa else drive_status,
        "renewal_order_payment_method": gateway_id,
        "payment_family": payment_family,
        "expected_renewal_gateway_id": gateway_id,
        "expected_token_type": token_type,
        "expected_token_id": expected_token_id,
        "renewal_processing_model": (
            "asynchronous_processing" if is_sepa else "synchronous_capture"
        ),
        "renewal_requires_email_evidence": not is_sepa,
        "async_payment_processing_accepted": is_sepa,
        "renewal_intention_status": "processing" if is_sepa else "",
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
            "customer_gateway_id": gateway_id,
            "expected_token_type": token_type,
            "matching_expected_token": True,
            "expected_token_id_matches_policy": True,
            "subscription_token_ids": [expected_token_id],
            "renewal_token_ids": [],
            "customer_token_ids": [int(token_base) + 20],
            "all_token_ids": [expected_token_id, int(token_base) + 20],
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


def success_context() -> dict:
    return json.loads(renewal_payload("1000", "processing"))


def evaluate_success_context(payload: dict) -> tuple[subprocess.CompletedProcess[str], dict]:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-evaluate-test-") as tmp:
        context_path = Path(tmp) / "context.json"
        context_path.write_text(json.dumps(payload), encoding="utf-8")
        result = run_driver("evaluate-success", str(context_path))
        output = json.loads(result.stdout)
        return result, output


def test_help_exits_zero_without_wp_args() -> None:
    result = run_gate("--help")

    assert result.returncode == 0
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_driver_accepts_sepa_async_processing_renewal_without_email() -> None:
    payload = success_context()
    payload.update(
        {
            "subscription_status": "on-hold",
            "subscription_payment_method": "woocommerce_payments_sepa_debit",
            "renewal_order_status": "pending",
            "renewal_order_payment_method": "woocommerce_payments_sepa_debit",
            "payment_family": "sepa",
            "expected_renewal_gateway_id": "woocommerce_payments_sepa_debit",
            "expected_token_type": "wcpay_sepa",
            "renewal_intention_status": "processing",
            "emails": [],
        }
    )
    payload["payment_meta_presence"]["_intention_status"] = True
    payload["token_presence"]["customer_gateway_id"] = "woocommerce_payments_sepa_debit"
    payload["token_presence"]["expected_token_type"] = "wcpay_sepa"

    result, output = evaluate_success_context(payload)

    assert result.returncode == 0, result.stderr
    assert output["success"] is True
    assert output["renewal_processing_model"] == "asynchronous_processing"
    assert output["renewal_requires_email_evidence"] is False
    assert output["async_payment_processing_accepted"] is True
    assert output["success_checks_failed"] == []


def test_driver_keeps_card_renewal_synchronous_email_requirement() -> None:
    payload = success_context()
    payload["emails"] = []

    result, output = evaluate_success_context(payload)

    assert result.returncode == 1
    assert output["success"] is False
    assert output["renewal_processing_model"] == "synchronous_capture"
    assert output["renewal_requires_email_evidence"] is True
    assert "No renewal email evidence was captured." in output["success_checks_failed"]


def test_driver_rejects_a_token_that_does_not_match_the_selected_policy() -> None:
    payload = success_context()
    payload["token_presence"]["matching_expected_token"] = False
    payload["token_presence"]["expected_token_id_matches_policy"] = False

    result, output = evaluate_success_context(payload)

    assert result.returncode == 1
    assert output["success"] is False
    assert (
        "No CC token for woocommerce_payments was present on the subscription, "
        "renewal order, or customer token list."
        in output["success_checks_failed"]
    )


def test_driver_rejects_sepa_async_renewal_without_processing_intent() -> None:
    payload = success_context()
    payload.update(
        {
            "subscription_status": "on-hold",
            "subscription_payment_method": "woocommerce_payments_sepa_debit",
            "renewal_order_status": "pending",
            "renewal_order_payment_method": "woocommerce_payments_sepa_debit",
            "payment_family": "sepa",
            "expected_renewal_gateway_id": "woocommerce_payments_sepa_debit",
            "expected_token_type": "wcpay_sepa",
            "renewal_intention_status": "requires_payment_method",
            "emails": [],
        }
    )
    payload["payment_meta_presence"]["_intention_status"] = True
    payload["token_presence"]["customer_gateway_id"] = "woocommerce_payments_sepa_debit"
    payload["token_presence"]["expected_token_type"] = "wcpay_sepa"

    result, output = evaluate_success_context(payload)

    assert result.returncode == 1
    assert output["success"] is False
    assert output["renewal_processing_model"] == "asynchronous_processing"
    assert output["async_payment_processing_accepted"] is False
    assert "Asynchronous renewal intent is not processing or succeeded." in output["success_checks_failed"]


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


def test_sepa_compare_requires_explicit_saved_token_ids() -> None:
    result = run_gate(
        "compare",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--ref-subscription-id",
        "101",
        "--target-subscription-id",
        "202",
        "--payment-family",
        "sepa",
    )

    assert result.returncode == 2
    assert "SEPA compare requires --ref-token-id and --target-token-id" in result.stderr


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


def test_compare_selects_and_records_sepa_gateway_and_token_policy() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        wp_invocations = tmp_path / "wp-invocations.txt"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="ref", payment_family="sepa")
        make_fake_wp(target_wp, role="target", payment_family="sepa")

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
            "--payment-family",
            "sepa",
            "--ref-token-id",
            "1010",
            "--target-token-id",
            "2010",
            "--out-dir",
            str(out_dir),
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations)},
        )

        assert result.returncode == 0, result.stderr
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert (
            "eval-file - preflight ref woocommerce_payments_sepa_debit "
            "wcpay_sepa sepa" in wp_log
        )
        assert (
            "eval-file - drive 101 woocommerce_payments_sepa_debit 1010 "
            "wcpay_sepa sepa" in wp_log
        )
        assert (
            "eval-file - drive 202 woocommerce_payments_sepa_debit 2010 "
            "wcpay_sepa sepa" in wp_log
        )

        rollup = json.loads(
            (out_dir / "subscriptions-renewal-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["payment_family"] == "sepa"
        assert rollup["expected_gateway_id"] == "woocommerce_payments_sepa_debit"
        assert rollup["expected_token_type"] == "wcpay_sepa"
        assert rollup["ref_expected_token_id"] == 1010
        assert rollup["target_expected_token_id"] == 2010


def test_compare_writes_durable_evidence_to_out_dir() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        out_dir = tmp_path / "evidence"

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
            "--out-dir",
            str(out_dir),
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt")},
        )

        assert result.returncode == 0, result.stderr
        expected_files = {
            "ref-preflight.json",
            "target-preflight.json",
            "ref-drive.json",
            "target-drive.json",
            "ref-normalized.json",
            "target-normalized.json",
            "subscriptions-renewal-gate.json",
        }
        assert expected_files == {path.name for path in out_dir.iterdir()}

        rollup = json.loads((out_dir / "subscriptions-renewal-gate.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_subscriptions_renewal_gate_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["mode"] == "compare"
        assert rollup["ref_subscription_id"] == 101
        assert rollup["target_subscription_id"] == 202
        assert rollup["normalized_diff_matched"] is True


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
