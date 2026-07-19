#!/usr/bin/env python3
"""Structural and safety regressions for the MD-01 flow coordinator."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FLOW = ROOT / "tools/woopayments-critical-flows/flows/MD-01-created-note-on-hold-notify.sh"
DRIVER = ROOT / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsmd01driver.php"
WPCOM_JOBS_DRIVER = ROOT / "tools/woopayments-critical-flows/flows/md01-wpcom-jobs.php"


def test_flow_binds_one_exact_fixture_and_uses_native_driver_only_on_target() -> None:
    source = FLOW.read_text(encoding="utf-8")
    assert 'FLOW_DRIVER="${MD01_FLOW_DRIVER:-$REPO_ROOT/tools/woopayments-merge/flow-drive.sh}"' in source
    assert 'RECONCILER="${MD01_RECONCILER:-$REPO_ROOT/tools/woopayments-merge/financial-reconcile.sh}"' in source
    assert 'args=(dispute --deterministic' in source
    assert 'args+=(--native)' in source
    assert 'if [ "$S" = "target" ]' in source
    assert '"$ORDER_ID" "$CHARGE_ID" "$INTENT_ID"' in source
    assert 'op != "dispute"' in source
    assert 'payload.get("charge_id")' in source
    assert 'payload.get("intent_id")' in source
    assert 'extract_json_object schema woopayments_md01_baseline.v1' in source
    assert 'extract_json_object schema woopayments_md01_probe.v1' in source
    assert 'extract_json_object op dispute' in source
    assert 'validate-baseline --baseline "$BASELINE"' in source


def test_flow_uses_bounded_polling_financial_source_and_context_bound_artifacts() -> None:
    source = FLOW.read_text(encoding="utf-8")
    for required in (
        "MD01_POLL_TRIES",
        "MD01_POLL_SLEEP_SECONDS",
        "woopayments_md01_execution.v1",
        "normalize-probe",
        "compare",
        "manifest",
        "assert_log_clean",
        "normalize-log-scan",
        "LOG_SCAN_EVIDENCE_FILE",
        "--log-scan \"$LOG_SCAN\"",
        "ref-log-scan.json",
        "target-log-scan.json",
        "CRITICAL_FLOWS_RUN_CONTEXT_KEY",
        "financial reconciliation",
        "ref-probe.json",
        "target-probe.json",
        "comparison.json",
    ):
        assert required in source
    assert "rm -rf" not in source
    assert "wp post delete" not in source
    assert "wcpay-dev test-lab disputes" not in source
    assert "delete_all" not in source


def test_flow_requires_approved_local_wp_runner_for_raw_source_reconciliation() -> None:
    source = FLOW.read_text(encoding="utf-8")
    assert "WOOPAYMENTS_APPROVED_TARGET_CONTAINER" in source
    assert 'docker exec -i "$REF_CONTAINER" wp --allow-root' in source
    assert 'expected_target_runner="docker exec -i ${WOOPAYMENTS_APPROVED_TARGET_CONTAINER} wp --allow-root"' in source
    assert "TARGET_WP_COMMAND" in source
    assert 'WP="$RECONCILE_WP" bash "$RECONCILER" "$ORDER_ID"' in source


def test_driver_uses_supported_dispute_sort_and_decodes_note_entities() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    assert "'sort'      => 'created'" in source
    assert "wp_specialchars_decode( wp_strip_all_tags( $content ), ENT_QUOTES )" in source
    assert "'sort'      => 'date'" not in source


def test_flow_orders_exact_real_wpcom_jobs_and_restores_automatic_mode() -> None:
    source = FLOW.read_text(encoding="utf-8")
    for required in (
        "MD01_WPCOM_JOBS_DRIVER",
        "wpcom-local --json jobs mode manual",
        "wpcom-local --json jobs run-one --id",
        "seal-webhook-order",
        "webhook-order.json",
        "restore_wpcom_jobs_mode",
        "restore_md01_resources",
        "critical_flows_log_observer_cleanup",
        "trap restore_md01_resources EXIT",
    ):
        assert required in source
    assert source.index("payment_intent.succeeded") < source.index("charge.dispute.created")
    assert "jobs mode disabled" not in source
    assert "DELETE FROM" not in source
    assert "wpj_jobs` SET" not in source


def test_wpcom_job_projector_is_read_only_bounded_and_pii_free() -> None:
    source = WPCOM_JOBS_DRIVER.read_text(encoding="utf-8")
    for required in (
        "woopayments_md01_wpcom_jobs_raw.v1",
        "WCPay\\Remote_Site\\Remote_Site_Client",
        "handle_async_webhook_send",
        "payment_intent.succeeded",
        "charge.dispute.created",
        "`workerpid` IS NULL",
        "LIMIT 1000",
    ):
        assert required in source
    for forbidden in (
        "DELETE ",
        "UPDATE ",
        "INSERT ",
        "customer_email",
        "customer_name",
        "client_secret",
        "receipt_url",
    ):
        assert forbidden not in source


def test_wpcom_jobs_mode_is_restored_on_interruption_and_failure_is_cleanup_fatal() -> None:
    source = FLOW.read_text(encoding="utf-8")
    start = source.index("restore_wpcom_jobs_mode_now()")
    end = source.index("trap restore_md01_resources EXIT")
    functions = source[start:end]

    with tempfile.TemporaryDirectory(prefix="md01-mode-trap-") as tmp_name:
        tmp = Path(tmp_name)
        fake_bin = tmp / "bin"
        fake_bin.mkdir()
        command_log = tmp / "commands.log"
        observer_log = tmp / "observer.log"
        fake = fake_bin / "wpcom-local"
        fake.write_text(
            "#!/usr/bin/env bash\n"
            "printf '%s\\n' \"$*\" >> \"$MD01_COMMAND_LOG\"\n"
            "if [ \"${MD01_FAKE_FAIL:-0}\" = 1 ]; then exit 1; fi\n"
            "printf '%s\\n' '{\"command\":\"jobs mode\",\"status\":\"success\",\"exit_code\":0,\"context\":{\"jobs_mode\":\"automatic\"}}'\n",
            encoding="utf-8",
        )
        fake.chmod(0o755)
        restored = tmp / "restored.json"
        script = f"""
set -uo pipefail
S=ref
WPCOM_JOBS_MODE_RESTORE_REQUIRED=1
WPCOM_JOBS_ORIGINAL_MODE=automatic
WPCOM_JOBS_MODE_RESTORED={restored!s}
critical_flows_log_observer_cleanup() {{ printf '%s\n' cleanup >> "$MD01_OBSERVER_LOG"; }}
{functions}
trap restore_md01_resources EXIT
trap 'exit 143' TERM
kill -TERM $$
"""
        env = {
            **os.environ,
            "PATH": f"{fake_bin}:{os.environ['PATH']}",
            "MD01_COMMAND_LOG": str(command_log),
            "MD01_OBSERVER_LOG": str(observer_log),
        }
        interrupted = subprocess.run(["bash", "-c", script], env=env, check=False)
        assert interrupted.returncode == 143
        assert command_log.read_text(encoding="utf-8").strip() == "--json jobs mode automatic"
        assert restored.is_file()
        assert observer_log.read_text(encoding="utf-8").strip() == "cleanup"

        command_log.write_text("", encoding="utf-8")
        observer_log.write_text("", encoding="utf-8")
        failed = subprocess.run(
            ["bash", "-c", script.replace("kill -TERM $$", "exit 3")],
            env={**env, "MD01_FAKE_FAIL": "1"},
            check=False,
        )
        assert failed.returncode == 70
        assert command_log.read_text(encoding="utf-8").strip() == "--json jobs mode automatic"
        assert observer_log.read_text(encoding="utf-8").strip() == "cleanup"
