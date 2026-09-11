#!/usr/bin/env python3
"""Focused regression checks for the subscriptions-renewal gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/subscriptions-renewal-gate.sh"
DRIVER = REPO / "tools/woopayments-merge/subscriptions-renewal-drive.php"

REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def make_fake_reconciler(path: Path, exit_code: int = 0) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env bash
printf 'reconcile %s wp=%s\\n' "$*" "${{WP:-}}" >> "${{FAKE_RECONCILE_INVOCATIONS:?}}"
exit {exit_code}
""",
    )


def reconciler_env(tmp_path: Path, exit_code: int = 0) -> dict[str, str]:
    reconciler = tmp_path / "fake-reconcile.sh"
    make_fake_reconciler(reconciler, exit_code=exit_code)
    return {
        "WOOPAYMENTS_RENEWAL_RECONCILER": str(reconciler),
        "FAKE_RECONCILE_INVOCATIONS": str(tmp_path / "reconcile-invocations.txt"),
    }


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    command_args, process_env = adapt_wp_runner_arguments(
        list(args),
        os.environ.copy() if env is None else env,
    )
    return subprocess.run(
        ["bash", str(SCRIPT), *command_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
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
if [ "$1" = "eval" ]; then
    # The gate's pre-reconcile wait polls for async fee/net money meta. Simulate the
    # real asynchrony: report pending for the first N probes (per FAKE_MONEY_META_PENDING),
    # then ready — so the poll loop's retry path is genuinely exercised.
    pending_budget="${{FAKE_MONEY_META_PENDING:-1}}"
    probe_count_file="${{FAKE_WP_INVOCATIONS:?}}.money-meta-probes"
    probes=$(( $(cat "$probe_count_file" 2>/dev/null || echo 0) + 1 ))
    printf '%s' "$probes" > "$probe_count_file"
    if [ "$probes" -le "$pending_budget" ]; then
        printf '%s\\n' "money_meta=pending"
    else
        printf '%s\\n' "money_meta=ready"
    fi
    exit 0
fi
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
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations), **reconciler_env(tmp_path)},
        )

        assert result.returncode == 0, result.stderr
        assert (
            "PASS: WC Subscriptions renewal facts match reference and renewal charges "
            "reconcile against the provider." in result.stdout
        )
        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - drive 101" in wp_log
        assert "eval-file - drive 202" in wp_log
        reconcile_log = (tmp_path / "reconcile-invocations.txt").read_text(encoding="utf-8")
        reconcile_lines = [line for line in reconcile_log.splitlines() if line.startswith("reconcile ")]
        assert len(reconcile_lines) == 2  # one renewal order per store
        # The test transport wraps the fake runners in Docker-shaped commands, so the
        # reconciler sees the adapted runner keyed by the per-role container name.
        assert reconcile_lines[0].startswith("reconcile 1001 wp=")
        assert "exec -i woopayments-test-reference-wp wp" in reconcile_lines[0]
        assert reconcile_lines[1].startswith("reconcile 2001 wp=")
        assert "exec -i woopayments-test-target-cli-1 wp" in reconcile_lines[1]


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
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations), **reconciler_env(tmp_path)},
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
            env={
                **os.environ,
                "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
                **reconciler_env(tmp_path),
            },
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


def test_compare_blocks_when_renewal_charge_cannot_be_reconciled() -> None:
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
            env={
                **os.environ,
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                **reconciler_env(tmp_path, exit_code=3),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "could not be reconciled against the provider" in result.stderr
        assert "PASS" not in result.stdout


def test_compare_fails_when_renewal_charge_diverges_from_provider() -> None:
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
            env={
                **os.environ,
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                **reconciler_env(tmp_path, exit_code=1),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "diverges from the provider raw source" in result.stderr
        assert "PASS" not in result.stdout


def test_remote_wp_runners_are_rejected_before_any_store_command() -> None:
    with tempfile.TemporaryDirectory(prefix="subscriptions-renewal-gate-test-") as tmp:
        tmp_path = Path(tmp)
        wp_invocations = tmp_path / "wp-invocations.txt"

        result = run_gate(
            "compare",
            "--ref",
            "wp --ssh=user@remote.example",
            "--target",
            TARGET_WP,
            "--ref-subscription-id",
            "101",
            "--target-subscription-id",
            "202",
            env={**os.environ, "FAKE_WP_INVOCATIONS": str(wp_invocations)},
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr
        assert "--ssh" in result.stderr
        assert not wp_invocations.exists()


def test_compare_blocks_when_money_metadata_never_arrives() -> None:
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
            env={
                **os.environ,
                "FAKE_WP_INVOCATIONS": str(wp_invocations),
                # Metadata stays pending past the (shortened) wait budget.
                "FAKE_MONEY_META_PENDING": "99",
                "WOOPAYMENTS_RENEWAL_MONEY_META_TRIES": "2",
                **reconciler_env(tmp_path),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "money metadata (fee/net) never arrived" in result.stderr
        assert "PASS" not in result.stdout
        # The reconciler must never run when metadata never arrived.
        assert not (tmp_path / "reconcile-invocations.txt").exists()
