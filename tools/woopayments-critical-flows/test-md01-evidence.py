#!/usr/bin/env python3
"""Adversarial tests for MD-01 dispute-created evidence."""

from __future__ import annotations

import hashlib
import hmac
import json
import os
import subprocess
import tempfile
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
TOOL = ROOT / "tools/woopayments-critical-flows/flows/md01-evidence.py"
DRIVER = ROOT / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsmd01driver.php"
WPCOM_JOBS_DRIVER = ROOT / "tools/woopayments-critical-flows/flows/md01-wpcom-jobs.php"
RUN_STAMP = "20260719T140000Z-4242"
CONTEXT_KEY = "42" * 32
LOG_HMAC_FIELDS = (
    "schema",
    "status",
    "store",
    "run_stamp",
    "flow_id",
    "purpose",
    "exit_code",
    "scan_observed",
    "match_count",
    "blocker_code",
    "source_payload_sha256",
)
WEBHOOK_ORDER_HMAC_FIELDS = (
    "schema",
    "status",
    "store",
    "run_stamp",
    "wpcom_blog_id",
    "original_jobs_mode",
    "restored_jobs_mode",
    "dispatches",
)


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode()
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def context_hmac(payload: dict[str, Any], domain: bytes, fields: tuple[str, ...]) -> str:
    semantics = {field: payload[field] for field in fields}
    encoded = json.dumps(semantics, sort_keys=True, separators=(",", ":")).encode()
    return "hmac-sha256:" + hmac.new(bytes.fromhex(CONTEXT_KEY), domain + encoded, hashlib.sha256).hexdigest()


def baseline(store: str) -> dict[str, Any]:
    count = 7 if store == "ref" else 47
    return {
        "schema": "woopayments_md01_baseline.v1",
        "store": store,
        "run_stamp": RUN_STAMP,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "summary": {"http_status": 200, "count": count, "currencies": ["usd"]},
        "status_counts": {
            "needs_response": count,
            "warning_needs_response": 0,
            "awaiting_response": count,
        },
        "blockers": [],
    }


def drive(store: str) -> dict[str, Any]:
    return {
        "op": "dispute",
        "order_id": 101 if store == "ref" else 202,
        "charge_id": f"ch_{store}_md01",
        "intent_id": f"pi_{store}_md01",
        "status": "processing",
        "order_currency": "USD",
    }


def raw_probe(store: str) -> dict[str, Any]:
    initial = baseline(store)["summary"]["count"]
    order_id = drive(store)["order_id"]
    return {
        "schema": "woopayments_md01_probe.v1",
        "store": store,
        "run_stamp": RUN_STAMP,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "order": {
            "id": order_id,
            "exists": True,
            "status": "on-hold",
            "currency": "USD",
            "total_minor": 5000,
            "charge_id": f"ch_{store}_md01",
            "intent_id": f"pi_{store}_md01",
            "edit_path": f"/wp-admin/admin.php?page=wc-orders&action=edit&id={order_id}",
            "notes": {
                "created_dispute": True,
                "reason_context": True,
                "response_due_context": True,
                "on_hold_transition": True,
                "created_note_count": 1,
                "safe_excerpt": 'Payment has been disputed for $50.00 with reason "Transaction unauthorized". Response due by July 26, 2026.',
            },
        },
        "dispute": {
            "found": True,
            "match_count": 1,
            "pages_scanned": 1,
            "id": f"dp_{store}_md01",
            "charge_id": f"ch_{store}_md01",
            "status": "needs_response",
            "amount": 5000,
            "currency": "usd",
            "reason": "fraudulent",
            "due_by": "2026-07-26T12:00:00+00:00",
            "order_number": order_id,
        },
        "summary": {
            "http_status": 200,
            "count": initial + 1,
            "currencies": ["usd"],
        },
        "status_counts": {
            "needs_response": initial + 1,
            "warning_needs_response": 0,
            "awaiting_response": initial + 1,
        },
        "blockers": [],
    }


def run_tool(
    *args: str,
    stdin: dict[str, Any] | None = None,
    input_text: str | None = None,
) -> subprocess.CompletedProcess[str]:
    assert stdin is None or input_text is None
    return subprocess.run(
        ["python3", str(TOOL), *args],
        cwd=ROOT,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": CONTEXT_KEY},
        input=json.dumps(stdin) if stdin is not None else input_text,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_json(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(json.dumps(payload) + "\n", encoding="utf-8")


def clean_log(store: str) -> dict[str, Any]:
    payload = {
        "schema": "woopayments_md01_log_scan.v1",
        "status": "pass",
        "store": store,
        "run_stamp": RUN_STAMP,
        "flow_id": "MD-01-created-note-on-hold-notify",
        "purpose": "clean-debug-log",
        "exit_code": 0,
        "scan_observed": True,
        "match_count": 0,
        "blocker_code": "",
        "source_payload_sha256": "sha256:" + "7" * 64,
        "context_hmac": "",
    }
    payload["context_hmac"] = context_hmac(
        payload,
        b"woopayments-md01-log-context-v1\0",
        LOG_HMAC_FIELDS,
    )
    payload["payload_sha256"] = payload_digest(payload)
    return payload


def raw_webhook_jobs(store: str) -> dict[str, Any]:
    suffix = 1 if store == "ref" else 2
    return {
        "schema": "woopayments_md01_wpcom_jobs_raw.v1",
        "payment_intent": [
            {
                "job_id": 800 + suffix,
                "event_id": f"evt_{store}_payment",
                "event_type": "payment_intent.succeeded",
                "object_id": f"pi_{store}_md01",
                "charge_id": f"ch_{store}_md01",
                "blog_id": 100 + suffix,
            }
        ],
        "dispute_created": [
            {
                "job_id": 900 + suffix,
                "event_id": f"evt_{store}_dispute",
                "event_type": "charge.dispute.created",
                "object_id": f"dp_{store}_md01",
                "charge_id": f"ch_{store}_md01",
                "blog_id": 100 + suffix,
            }
        ],
    }


def jobs_mode_result(mode: str) -> dict[str, Any]:
    return {
        "command": "jobs mode",
        "status": "success",
        "exit_code": 0,
        "context": {"jobs_mode": mode},
        "summary": "Loaded local async jobs mode.",
        "messages": [],
        "errors": [],
        "artifacts": [],
    }


def job_run_result(job_id: int) -> dict[str, Any]:
    return {
        "command": "jobs run-one",
        "status": "success",
        "exit_code": 0,
        "context": {
            "id": str(job_id),
            "exit_code": "0",
            "jobs_mode": "manual",
            "prefix": "wpj_",
        },
        "summary": "Ran one queued async job.",
        "messages": [],
        "errors": [],
        "artifacts": [],
    }


def seal_webhook_order(
    tmp: Path,
    store: str,
    *,
    prepare: bool = True,
) -> subprocess.CompletedProcess[str]:
    paths = {
        "drive": (tmp / f"{store}-drive.json", drive(store)),
        "jobs": (tmp / f"{store}-raw-jobs.json", raw_webhook_jobs(store)),
        "original": (tmp / f"{store}-jobs-mode-before.json", jobs_mode_result("automatic")),
        "payment": (
            tmp / f"{store}-payment-job-result.json",
            job_run_result(raw_webhook_jobs(store)["payment_intent"][0]["job_id"]),
        ),
        "dispute": (
            tmp / f"{store}-dispute-job-result.json",
            job_run_result(raw_webhook_jobs(store)["dispute_created"][0]["job_id"]),
        ),
        "restored": (tmp / f"{store}-jobs-mode-restored.json", jobs_mode_result("automatic")),
    }
    if prepare:
        for path, payload in paths.values():
            write_json(path, payload)
    return run_tool(
        "seal-webhook-order",
        "--store",
        store,
        "--run-stamp",
        RUN_STAMP,
        "--drive",
        str(paths["drive"][0]),
        "--jobs",
        str(paths["jobs"][0]),
        "--original-mode",
        str(paths["original"][0]),
        "--payment-result",
        str(paths["payment"][0]),
        "--dispute-result",
        str(paths["dispute"][0]),
        "--restored-mode",
        str(paths["restored"][0]),
    )


def normalize(tmp: Path, store: str, probe: dict[str, Any] | None = None, reconcile_rc: int = 0) -> subprocess.CompletedProcess[str]:
    baseline_path = tmp / f"{store}-baseline.json"
    drive_path = tmp / f"{store}-drive.json"
    reconcile_path = tmp / f"{store}-reconcile.log"
    write_json(baseline_path, baseline(store))
    write_json(drive_path, drive(store))
    reconcile_path.write_text("PASS: 1 order(s) reconciled cleanly.\n", encoding="utf-8")
    return run_tool(
        "normalize-probe",
        "--store",
        store,
        "--run-stamp",
        RUN_STAMP,
        "--baseline",
        str(baseline_path),
        "--drive",
        str(drive_path),
        "--reconciliation",
        str(reconcile_path),
        "--reconciliation-exit-code",
        str(reconcile_rc),
        stdin=probe or raw_probe(store),
    )


def test_valid_probes_pass_and_distinct_ids_compare_semantically() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-evidence-") as tmp_name:
        tmp = Path(tmp_name)
        ref_result = normalize(tmp, "ref")
        target_result = normalize(tmp, "target")
        assert ref_result.returncode == 0, ref_result.stdout + ref_result.stderr
        assert target_result.returncode == 0, target_result.stdout + target_result.stderr

        ref_path = tmp / "ref-probe.json"
        target_path = tmp / "target-probe.json"
        ref_path.write_text(ref_result.stdout, encoding="utf-8")
        target_path.write_text(target_result.stdout, encoding="utf-8")
        comparison = run_tool(
            "compare",
            "--reference",
            str(ref_path),
            "--target",
            str(target_path),
            "--run-stamp",
            RUN_STAMP,
        )
        assert comparison.returncode == 0, comparison.stdout + comparison.stderr
        payload = json.loads(comparison.stdout)
        assert payload["status"] == "pass"
        assert payload["parity"]["semantic_end_state"] is True
        assert payload["reference"]["order_id"] != payload["target"]["order_id"]
        assert payload["payload_sha256"] == payload_digest(payload)


def test_baseline_validation_blocks_before_drive_on_invalid_capture() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-baseline-") as tmp_name:
        tmp = Path(tmp_name)
        valid_path = tmp / "ref-baseline.json"
        write_json(valid_path, baseline("ref"))
        valid = run_tool(
            "validate-baseline",
            "--baseline",
            str(valid_path),
            "--store",
            "ref",
            "--run-stamp",
            RUN_STAMP,
        )
        assert valid.returncode == 0, valid.stdout + valid.stderr

        write_json(valid_path, baseline("ref")["status_counts"])
        invalid = run_tool(
            "validate-baseline",
            "--baseline",
            str(valid_path),
            "--store",
            "ref",
            "--run-stamp",
            RUN_STAMP,
        )
        assert invalid.returncode != 0


def test_discriminated_extractor_returns_envelope_not_nested_status_counts() -> None:
    envelope = baseline("ref")
    raw = "WP-CLI notice\n" + json.dumps(envelope) + "\n"
    result = run_tool(
        "extract-json-object",
        "--discriminator",
        "schema",
        "--expected",
        "woopayments_md01_baseline.v1",
        input_text=raw,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert json.loads(result.stdout) == envelope

    missing = run_tool(
        "extract-json-object",
        "--discriminator",
        "schema",
        "--expected",
        "woopayments_md01_probe.v1",
        input_text=raw,
    )
    assert missing.returncode == 3


def test_exact_wpcom_webhook_order_is_sealed_and_ambiguity_fails_closed() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-webhook-order-") as tmp_name:
        tmp = Path(tmp_name)
        result = seal_webhook_order(tmp, "ref")
        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["schema"] == "woopayments_md01_webhook_order.v1"
        assert payload["status"] == "pass"
        assert payload["original_jobs_mode"] == "automatic"
        assert payload["restored_jobs_mode"] == "automatic"
        assert [item["event_type"] for item in payload["dispatches"]] == [
            "payment_intent.succeeded",
            "charge.dispute.created",
        ]
        assert [item["sequence"] for item in payload["dispatches"]] == [1, 2]
        assert payload["context_hmac"] == context_hmac(
            payload,
            b"woopayments-md01-webhook-order-context-v1\0",
            WEBHOOK_ORDER_HMAC_FIELDS,
        )
        assert payload["payload_sha256"] == payload_digest(payload)

        jobs_path = tmp / "ref-raw-jobs.json"
        ambiguous = raw_webhook_jobs("ref")
        ambiguous["payment_intent"].append(dict(ambiguous["payment_intent"][0], job_id=999))
        write_json(jobs_path, ambiguous)
        blocked = run_tool(
            "seal-webhook-order",
            "--store",
            "ref",
            "--run-stamp",
            RUN_STAMP,
            "--drive",
            str(tmp / "ref-drive.json"),
            "--jobs",
            str(jobs_path),
            "--original-mode",
            str(tmp / "ref-jobs-mode-before.json"),
            "--payment-result",
            str(tmp / "ref-payment-job-result.json"),
            "--dispute-result",
            str(tmp / "ref-dispute-job-result.json"),
            "--restored-mode",
            str(tmp / "ref-jobs-mode-restored.json"),
        )
        assert blocked.returncode == 3


def test_webhook_order_rejects_failed_dispatch_wrong_mode_and_identity() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-webhook-order-invalid-") as tmp_name:
        tmp = Path(tmp_name)
        assert seal_webhook_order(tmp, "target").returncode == 0

        restored = tmp / "target-jobs-mode-restored.json"
        write_json(restored, jobs_mode_result("manual"))
        assert seal_webhook_order(tmp, "target", prepare=False).returncode == 3

        assert seal_webhook_order(tmp, "target").returncode == 0
        payment = tmp / "target-payment-job-result.json"
        failed = job_run_result(raw_webhook_jobs("target")["payment_intent"][0]["job_id"])
        failed.update(status="failure", exit_code=1)
        write_json(payment, failed)
        assert seal_webhook_order(tmp, "target", prepare=False).returncode == 3

        assert seal_webhook_order(tmp, "target").returncode == 0
        failed = job_run_result(raw_webhook_jobs("target")["payment_intent"][0]["job_id"])
        failed["context"]["exit_code"] = "1"
        write_json(payment, failed)
        assert seal_webhook_order(tmp, "target", prepare=False).returncode == 3

        assert seal_webhook_order(tmp, "target").returncode == 0
        jobs = tmp / "target-raw-jobs.json"
        mismatched = raw_webhook_jobs("target")
        mismatched["dispute_created"][0]["charge_id"] = "ch_wrong"
        write_json(jobs, mismatched)
        assert seal_webhook_order(tmp, "target", prepare=False).returncode == 3


def test_execution_requires_webhook_order_bound_to_normalized_probe() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-execution-order-") as tmp_name:
        tmp = Path(tmp_name)
        probe_result = normalize(tmp, "ref")
        probe_path = tmp / "ref-probe.json"
        probe_path.write_text(probe_result.stdout, encoding="utf-8")
        log_path = tmp / "ref-log-scan.json"
        write_json(log_path, clean_log("ref"))
        webhook_result = seal_webhook_order(tmp, "ref")
        webhook_path = tmp / "ref-webhook-order.json"
        webhook_path.write_text(webhook_result.stdout, encoding="utf-8")

        args = (
            "execution",
            "--store",
            "ref",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--probe",
            str(probe_path),
            "--probe-exit-code",
            "0",
            "--log-assertion-exit-code",
            "0",
            "--log-scan",
            str(log_path),
            "--webhook-order-exit-code",
            "0",
            "--webhook-order",
            str(webhook_path),
        )
        result = run_tool(*args)
        assert result.returncode == 0, result.stdout + result.stderr

        jobs = raw_webhook_jobs("ref")
        jobs["dispute_created"][0]["object_id"] = "dp_other_md01"
        write_json(tmp / "ref-raw-jobs.json", jobs)
        mismatched = seal_webhook_order(tmp, "ref", prepare=False)
        assert mismatched.returncode == 0
        webhook_path.write_text(mismatched.stdout, encoding="utf-8")
        rejected = run_tool(*args)
        assert rejected.returncode != 0


def test_exact_identity_and_contract_mismatches_are_functional_failures() -> None:
    mutations = {
        "charge binding": lambda raw: raw["order"].update(charge_id="ch_wrong"),
        "order status": lambda raw: raw["order"].update(status="processing"),
        "created note": lambda raw: raw["order"]["notes"].update(created_dispute=False),
        "reason context": lambda raw: raw["order"]["notes"].update(reason_context=False),
        "response due": lambda raw: raw["order"]["notes"].update(response_due_context=False),
        "exact dispute": lambda raw: raw["dispute"].update(
            found=False,
            match_count=0,
            id="",
            charge_id="",
            status="",
            amount=0,
            currency="",
            reason="",
            due_by="",
            order_number=0,
        ),
        "dispute status": lambda raw: raw["dispute"].update(status="won"),
        "amount": lambda raw: raw["dispute"].update(amount=4999),
        "currency": lambda raw: raw["dispute"].update(currency="eur"),
        "summary delta": lambda raw: raw["summary"].update(count=7),
        "badge delta": lambda raw: raw["status_counts"].update(needs_response=7, awaiting_response=7),
    }
    with tempfile.TemporaryDirectory(prefix="md01-mismatch-") as tmp_name:
        tmp = Path(tmp_name)
        for label, mutate in mutations.items():
            raw = raw_probe("ref")
            mutate(raw)
            result = normalize(tmp, "ref", raw)
            assert result.returncode == 1, f"{label}: {result.stdout}{result.stderr}"
            payload = json.loads(result.stdout)
            assert payload["status"] == "fail", label
            assert payload["errors"], label


def test_infrastructure_blockers_and_reconciliation_classify_fail_closed() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-classification-") as tmp_name:
        tmp = Path(tmp_name)
        blocked_raw = raw_probe("ref")
        blocked_raw["blockers"] = ["disputes_api_unavailable"]
        blocked = normalize(tmp, "ref", blocked_raw)
        assert blocked.returncode == 3
        assert json.loads(blocked.stdout)["status"] == "blocked"

        reconcile_fail = normalize(tmp, "ref", reconcile_rc=1)
        assert reconcile_fail.returncode == 1
        assert "financial_reconciliation_failed" in json.loads(reconcile_fail.stdout)["errors"]

        reconcile_blocked = normalize(tmp, "ref", reconcile_rc=3)
        assert reconcile_blocked.returncode == 3
        assert "financial_reconciliation_blocked" in json.loads(reconcile_blocked.stdout)["blockers"]


def test_unknown_fields_wrong_types_and_unsealed_packets_cannot_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-schema-") as tmp_name:
        tmp = Path(tmp_name)
        cases = []
        extra = raw_probe("ref")
        extra["unexpected"] = True
        cases.append(extra)
        boolean_amount = raw_probe("ref")
        boolean_amount["dispute"]["amount"] = True
        cases.append(boolean_amount)
        duplicate = raw_probe("ref")
        duplicate["dispute"]["match_count"] = 2
        cases.append(duplicate)
        for raw in cases:
            result = normalize(tmp, "ref", raw)
            assert result.returncode == 3, result.stdout + result.stderr
            assert json.loads(result.stdout)["status"] == "blocked"

        valid = normalize(tmp, "ref")
        assert valid.returncode == 0
        packet = json.loads(valid.stdout)
        packet["facts"]["order"]["status_on_hold"] = False
        path = tmp / "forged.json"
        write_json(path, packet)
        result = run_tool(
            "validate-probe",
            "--probe",
            str(path),
            "--store",
            "ref",
            "--run-stamp",
            RUN_STAMP,
            "--expected-exit-code",
            "0",
        )
        assert result.returncode != 0


def test_context_bound_manifest_rejects_stale_or_tampered_artifacts() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-manifest-") as tmp_name:
        tmp = Path(tmp_name)
        probe_result = normalize(tmp, "ref")
        assert probe_result.returncode == 0
        probe_path = tmp / "ref-probe.json"
        probe_path.write_text(probe_result.stdout, encoding="utf-8")
        log_path = tmp / "ref-log-scan.json"
        write_json(log_path, clean_log("ref"))
        webhook_order_result = seal_webhook_order(tmp, "ref")
        assert webhook_order_result.returncode == 0
        webhook_order_path = tmp / "ref-webhook-order.json"
        webhook_order_path.write_text(webhook_order_result.stdout, encoding="utf-8")

        execution = {
            "schema": "woopayments_md01_execution.v1",
            "status": "pass",
            "store": "ref",
            "run_stamp": RUN_STAMP,
            "exit_code": 0,
            "verdict_sources": [],
            "probe_exit_code": 0,
            "probe_payload_sha256": json.loads(probe_result.stdout)["payload_sha256"],
            "log_assertion_exit_code": 0,
            "log_payload_sha256": clean_log("ref")["payload_sha256"],
            "webhook_order_exit_code": 0,
            "webhook_order_payload_sha256": json.loads(webhook_order_result.stdout)["payload_sha256"],
        }
        execution["payload_sha256"] = payload_digest(execution)
        execution_path = tmp / "ref-execution.json"
        write_json(execution_path, execution)

        manifest_result = run_tool(
            "manifest",
            "--store",
            "ref",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
            "--file",
            str(probe_path),
            "--file",
            str(log_path),
            "--file",
            str(webhook_order_path),
            "--file",
            str(execution_path),
        )
        assert manifest_result.returncode == 0, manifest_result.stdout + manifest_result.stderr
        manifest_path = tmp / "ref-manifest.json"
        manifest_path.write_text(manifest_result.stdout, encoding="utf-8")

        validate = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest_path),
            "--store",
            "ref",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
        )
        assert validate.returncode == 0, validate.stdout + validate.stderr
        assert validate.stdout.strip().startswith("sha256:")

        probe_path.write_text(probe_path.read_text(encoding="utf-8") + " ", encoding="utf-8")
        tampered = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest_path),
            "--store",
            "ref",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
        )
        assert tampered.returncode != 0


def test_log_artifact_is_required_bound_and_malformed_common_scan_blocks() -> None:
    with tempfile.TemporaryDirectory(prefix="md01-log-") as tmp_name:
        tmp = Path(tmp_name)
        malformed_path = tmp / "raw-log-scan.json"
        write_json(
            malformed_path,
            {
                "schema": "woopayments_debug_log_scan.v6",
                "store": "ref",
                "scan": {},
            },
        )
        result = run_tool(
            "normalize-log-scan",
            "--input",
            str(malformed_path),
            "--store",
            "ref",
            "--run-stamp",
            RUN_STAMP,
            "--expected-exit-code",
            "0",
        )
        assert result.returncode == 3, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["schema"] == "woopayments_md01_log_scan.v1"
        assert payload["status"] == "blocked"
        assert payload["blocker_code"] == "invalid_scan_evidence"
        assert payload["context_hmac"].startswith("hmac-sha256:")
        assert payload["payload_sha256"] == payload_digest(payload)


def test_state_driver_has_exact_runtime_rest_pagination_and_session_boundaries() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    for required in (
        "WooPaymentsCriticalFlowsMd01Driver",
        "woopayments_md01_baseline.v1",
        "woopayments_md01_probe.v1",
        "needs_response",
        "/wc/v3/payments/disputes",
        "/wc/v3/payments/disputes/summary",
        "get_dispute_status_counts",
        "disputes/status_counts",
        "rest_do_request",
        "WP_Session_Tokens::get_instance",
        "wp_generate_auth_cookie",
        "'auth'",
        "'secure_auth'",
        "'logged_in'",
        "auth_cookies",
        "create-auth-session",
        "destroy-auth-session",
        "destroy( $session_token )",
    ):
        assert required in source
    assert "delete_all" not in source
    assert "wp_delete_post" not in source
    assert "wp_delete_user" not in source
    assert "wcpay-dev test-lab" not in source
