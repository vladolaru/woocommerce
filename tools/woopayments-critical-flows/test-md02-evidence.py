#!/usr/bin/env python3
"""Behavioral and integrity regressions for MD-02 evidence."""

from __future__ import annotations

import copy
import importlib.util
import json
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
EVIDENCE_TOOL = ROOT / "tools/woopayments-critical-flows/flows/md02-evidence.py"
DRIVER = ROOT / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsmd02driver.php"
RUN_STAMP = "20260719T220000Z-4242"
DESCRIPTION = "MD02 evidence product description"


def load_evidence():
    spec = importlib.util.spec_from_file_location("md02_evidence", EVIDENCE_TOOL)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def valid_state(
    phase: str,
    *,
    store: str = "ref",
    description: str = "Reference order products: beaker, hoodie, cap",
    has_evidence: bool = False,
) -> dict:
    return {
        "schema": "woopayments_md02_state.v1",
        "store": store,
        "run_stamp": RUN_STAMP,
        "phase": phase,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "identity": {
            "order_id": 2250 if store == "ref" else 1673,
            "charge_id": "ch_ref_md02" if store == "ref" else "ch_target_md02",
            "intent_id": "pi_ref_md02" if store == "ref" else "pi_target_md02",
            "dispute_id": "du_ref_md02" if store == "ref" else "du_target_md02",
        },
        "lifecycle": {
            "status": "needs_response",
            "due_by": 1785196799,
            "past_due": False,
            "has_evidence": has_evidence,
            "submission_count": 0,
        },
        "evidence": {
            "product_description": description,
            "customer_name_present": True,
            "customer_name_matches_order": True,
            "customer_name_hmac": "hmac-sha256:" + "1" * 64,
            "file_evidence_hmac": "hmac-sha256:" + "2" * 64,
            "decisive_state_hmac": "hmac-sha256:" + ("3" if phase == "pre" else "4") * 64,
        },
        "metadata_keys": [],
        "blockers": [],
    }


def valid_store_packet(store: str = "ref") -> dict:
    pre = valid_state("pre", store=store)
    post = valid_state("post", store=store, description=DESCRIPTION, has_evidence=True)
    delayed = valid_state("delayed", store=store, description=DESCRIPTION, has_evidence=True)
    delayed["evidence"]["decisive_state_hmac"] = post["evidence"]["decisive_state_hmac"]
    return {
        "schema": "woopayments_md02_store_packet.v1",
        "status": "pass",
        "store": store,
        "run_stamp": RUN_STAMP,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "source_manifest": {
            "path": f"evidence/{store}-manifest.json",
            "sha256": "sha256:" + "5" * 64,
            "historical_context_hmac": "unverifiable_without_original_run_key",
        },
        "context_binding": {"aggregate_run_id": "aggregate-md02", "context_sha256": "sha256:" + "6" * 64},
        "pre_state": pre,
        "post_state": post,
        "delayed_state": delayed,
        "deterministic_assertions": {},
        "deterministic_status": "pass",
        "browser": {
            "schema": "woopayments_md02_browser.v1",
            "store": store,
            "run_stamp": RUN_STAMP,
            "runtime_owner": "plugin" if store == "ref" else "native",
            "functional_assertions": {
                "authenticated_admin": True,
                "exact_dispute_row": True,
                "response_action_discovered": True,
                "exact_submit_false_post": True,
                "customer_name_preserved": True,
                "no_files_attached": True,
                "save_feedback": True,
                "description_reloaded": True,
                "description_editable": True,
            },
            "ux_assertions": {"save_for_later_copy": True, "customer_name_visible": True},
            "phase": "reloaded",
            "identity": {
                "order_id": 2250 if store == "ref" else 1673,
                "charge_id": "ch_ref_md02" if store == "ref" else "ch_target_md02",
                "dispute_id": "du_ref_md02" if store == "ref" else "du_target_md02",
            },
            "facts": {
                "authenticatedAdmin": True,
                "exactDisputeRow": True,
                "responseActionDiscovered": True,
                "requestSeen": True,
                "requestMethod": "POST",
                "requestPathMatches": True,
                "submitFalse": True,
                "descriptionMatches": True,
                "payloadCustomerNameMatches": True,
                "noFilesAttached": True,
                "responseOk": True,
                "saveFeedback": True,
                "reloadedDescriptionMatches": True,
                "descriptionEditable": True,
                "saveButtonLabel": "Save for later",
                "customerNameVisible": True,
            },
            "request": {
                "method": "POST",
                "path": "/wp-json/wc/v3/payments/disputes/du_ref_md02"
                if store == "ref"
                else "/wp-json/wc/v3/payments/disputes/du_target_md02",
                "submit_false": True,
                "description_matches": True,
                "customer_name_matches": True,
                "files_selected": 0,
            },
            "response": {"status": 200, "ok": True},
            "errors": [],
            "blockers": [],
            "failed_responses": [],
            "diagnostics": [],
            "console_errors": [],
            "page_errors": [],
            "screenshots": {
                f"{store}-md02-form.png": "sha256:" + "a" * 64,
                f"{store}-md02-saved.png": "sha256:" + "b" * 64,
                f"{store}-md02-reloaded.png": "sha256:" + "c" * 64,
            },
        },
        "browser_status": "pass",
        "diagnostics": [],
        "mutation_boundary": "trusted_mutation",
        "cleanup_failures": [],
    }


def test_evidence_module_exists() -> None:
    assert EVIDENCE_TOOL.is_file()


def test_deterministic_assertions_prove_persisted_unsubmitted_draft() -> None:
    module = load_evidence()
    pre = valid_state("pre")
    post = valid_state("post", description=DESCRIPTION, has_evidence=True)
    delayed = valid_state("delayed", description=DESCRIPTION, has_evidence=True)
    delayed["evidence"]["decisive_state_hmac"] = post["evidence"]["decisive_state_hmac"]

    assertions = module.derive_deterministic_assertions(pre, post, delayed, DESCRIPTION)

    assert assertions == {
        "exact_identity": True,
        "description_was_new": True,
        "description_persisted": True,
        "customer_name_preserved": True,
        "no_files_attached": True,
        "draft_exists": True,
        "not_submitted": True,
        "deadline_unchanged": True,
        "delayed_state_stable": True,
    }


@pytest.mark.parametrize(
    "mutate,failed_assertion",
    [
        (lambda pre, post, delayed: post["lifecycle"].update(due_by=1785196800), "deadline_unchanged"),
        (lambda pre, post, delayed: post["lifecycle"].update(submission_count=1), "not_submitted"),
        (lambda pre, post, delayed: post["evidence"].update(customer_name_matches_order=False), "customer_name_preserved"),
        (lambda pre, post, delayed: post["evidence"].update(file_evidence_hmac="hmac-sha256:" + "9" * 64), "no_files_attached"),
        (lambda pre, post, delayed: delayed["evidence"].update(product_description="reverted"), "description_persisted"),
        (lambda pre, post, delayed: delayed["evidence"].update(decisive_state_hmac="hmac-sha256:" + "8" * 64), "delayed_state_stable"),
        (lambda pre, post, delayed: pre["evidence"].update(product_description=DESCRIPTION), "description_was_new"),
        (lambda pre, post, delayed: delayed["identity"].update(dispute_id="du_other"), "exact_identity"),
    ],
)
def test_deterministic_assertions_fail_closed_on_semantic_drift(mutate, failed_assertion: str) -> None:
    module = load_evidence()
    pre = valid_state("pre")
    post = valid_state("post", description=DESCRIPTION, has_evidence=True)
    delayed = valid_state("delayed", description=DESCRIPTION, has_evidence=True)
    delayed["evidence"]["decisive_state_hmac"] = post["evidence"]["decisive_state_hmac"]
    mutate(pre, post, delayed)

    assertions = module.derive_deterministic_assertions(pre, post, delayed, DESCRIPTION)

    assert assertions[failed_assertion] is False


def test_browser_verdict_separates_ux_from_functional_failure() -> None:
    module = load_evidence()
    browser = valid_store_packet()["browser"]
    assert module.derive_browser_verdict(browser) == ("pass", [])

    ux_browser = copy.deepcopy(browser)
    ux_browser["ux_assertions"]["save_for_later_copy"] = False
    assert module.derive_browser_verdict(ux_browser) == ("fail_ux", ["save_for_later_copy"])

    functional_browser = copy.deepcopy(browser)
    functional_browser["functional_assertions"]["description_reloaded"] = False
    assert module.derive_browser_verdict(functional_browser) == ("fail_functional", ["description_reloaded"])

    blocked_browser = copy.deepcopy(browser)
    blocked_browser["blockers"] = ["browser_process_interrupted"]
    assert module.derive_browser_verdict(blocked_browser) == ("blocked", ["browser_process_interrupted"])


def test_cross_store_comparison_recomputes_equivalent_persistence() -> None:
    module = load_evidence()
    reference = valid_store_packet("ref")
    target = valid_store_packet("target")
    reference["deterministic_assertions"] = module.derive_deterministic_assertions(
        reference["pre_state"], reference["post_state"], reference["delayed_state"], DESCRIPTION
    )
    target["deterministic_assertions"] = module.derive_deterministic_assertions(
        target["pre_state"], target["post_state"], target["delayed_state"], DESCRIPTION
    )

    comparison = module.compare_store_packets(reference, target, RUN_STAMP)

    assert comparison["status"] == "pass"
    assert comparison["assertions"] == {
        "run_binding": True,
        "runtime_owners": True,
        "both_persisted": True,
        "both_unsubmitted": True,
        "both_deadlines_unchanged": True,
        "both_delayed_stable": True,
    }


def test_context_seal_rejects_coherent_rewrite(monkeypatch: pytest.MonkeyPatch) -> None:
    module = load_evidence()
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    payload = {"schema": "example.v1", "status": "pass"}
    sealed = module.seal(payload, b"md02-test\0")
    module.validate_seal(sealed, b"md02-test\0")

    rewritten = copy.deepcopy(sealed)
    rewritten["status"] = "fail"
    unsigned = dict(rewritten)
    unsigned.pop("payload_sha256")
    rewritten["payload_sha256"] = "sha256:" + module.hashlib.sha256(module.canonical(unsigned)).hexdigest()

    with pytest.raises(module.EvidenceError, match="context HMAC"):
        module.validate_seal(rewritten, b"md02-test\0")


def test_driver_contract_does_not_emit_customer_pii() -> None:
    assert DRIVER.is_file()
    source = DRIVER.read_text(encoding="utf-8")
    assert "woopayments_md02_state_raw.v1" in source
    assert "create-auth-session" in source
    assert "destroy-auth-session" in source
    assert "/wc/v3/payments/disputes/" in source
    assert "customer_name_hmac" in source
    assert "customer_name_matches_order" in source
    assert "hash_hmac" in source
    emitted_projection = source[source.index("private static function probe") : source.index("private static function create_auth_session")]
    assert "customer_email" not in emitted_projection
    assert "billing_address" not in emitted_projection
    assert "'customer_name' =>" not in emitted_projection


def test_cli_reports_invalid_json_as_blocked(tmp_path: Path) -> None:
    invalid = tmp_path / "invalid.json"
    invalid.write_text("not-json\n", encoding="utf-8")
    completed = __import__("subprocess").run(
        ["python3", str(EVIDENCE_TOOL), "validate-state", "--state", str(invalid), "--store", "ref", "--run-stamp", RUN_STAMP, "--phase", "pre"],
        text=True,
        stdout=__import__("subprocess").PIPE,
        stderr=__import__("subprocess").STDOUT,
        check=False,
    )
    assert completed.returncode == 3
    assert "BLOCKED" in completed.stdout
    assert "not-json" not in completed.stdout


def test_valid_state_fixture_is_json_serializable() -> None:
    assert json.loads(json.dumps(valid_state("pre")))["schema"] == "woopayments_md02_state.v1"


def sealed_store_packet(module, store: str = "ref", screenshot_dir: Path | None = None) -> dict:
    packet = valid_store_packet(store)
    if screenshot_dir is not None:
        for name in packet["browser"]["screenshots"]:
            path = screenshot_dir / name
            path.write_bytes(b"\x89PNG\r\n\x1a\n" + name.encode("utf-8"))
            packet["browser"]["screenshots"][name] = (
                "sha256:" + module.hashlib.sha256(path.read_bytes()).hexdigest()
            )
    packet["deterministic_assertions"] = module.derive_deterministic_assertions(
        packet["pre_state"], packet["post_state"], packet["delayed_state"], DESCRIPTION
    )
    return module.seal(packet, b"woopayments-md02-store-packet-context-v1\0")


def test_store_packet_validation_recomputes_every_verdict(monkeypatch: pytest.MonkeyPatch) -> None:
    module = load_evidence()
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    packet = sealed_store_packet(module)
    module.validate_store_packet(packet, store="ref", run_stamp=RUN_STAMP)

    tampered = copy.deepcopy(packet)
    tampered["browser_status"] = "fail_ux"
    tampered["payload_sha256"] = module.payload_digest(tampered)
    with pytest.raises(module.EvidenceError, match="HMAC"):
        module.validate_store_packet(tampered, store="ref", run_stamp=RUN_STAMP)

    contradictory = valid_store_packet()
    contradictory["deterministic_assertions"] = packet["deterministic_assertions"]
    contradictory["browser"]["ux_assertions"]["save_for_later_copy"] = False
    contradictory = module.seal(contradictory, b"woopayments-md02-store-packet-context-v1\0")
    with pytest.raises(module.EvidenceError, match="browser assertions"):
        module.validate_store_packet(contradictory, store="ref", run_stamp=RUN_STAMP)

    leaking_path = valid_store_packet()
    leaking_path["deterministic_assertions"] = packet["deterministic_assertions"]
    leaking_path["source_manifest"]["path"] = "/Users/example/evidence/ref-manifest.json"
    leaking_path = module.seal(leaking_path, b"woopayments-md02-store-packet-context-v1\0")
    with pytest.raises(module.EvidenceError, match="provenance"):
        module.validate_store_packet(leaking_path, store="ref", run_stamp=RUN_STAMP)


def test_store_packet_rejects_vacuous_assertions_and_late_browser_failures(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    module = load_evidence()
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    for mutate in (
        lambda browser: browser.update(functional_assertions={}, ux_assertions={}),
        lambda browser: browser["failed_responses"].append(
            {
                "store": "ref",
                "status": 500,
                "method": "GET",
                "path": "/wp-json/wc/v3/payments/disputes/du_ref_md02",
                "phase": "reloaded",
            }
        ),
        lambda browser: browser["console_errors"].append(
            {"type": "error", "text_sha256": "sha256:" + "d" * 64, "phase": "reloaded"}
        ),
    ):
        packet = valid_store_packet()
        packet["deterministic_assertions"] = module.derive_deterministic_assertions(
            packet["pre_state"], packet["post_state"], packet["delayed_state"], DESCRIPTION
        )
        mutate(packet["browser"])
        sealed = module.seal(packet, b"woopayments-md02-store-packet-context-v1\0")
        with pytest.raises(module.EvidenceError):
            module.validate_store_packet(sealed, store="ref", run_stamp=RUN_STAMP)


def test_execution_and_manifest_bind_store_packet_log_and_comparison(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    module = load_evidence()
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    reference = sealed_store_packet(module, "ref")
    target = sealed_store_packet(module, "target", tmp_path)
    comparison = module.seal(
        module.compare_store_packets(reference, target, RUN_STAMP),
        b"woopayments-md02-comparison-context-v1\0",
    )
    log_scan = module.seal(
        {
            "schema": "woopayments_md02_log_scan.v1",
            "status": "pass",
            "store": "target",
            "run_stamp": RUN_STAMP,
            "flow_id": "MD-02-save-evidence",
            "purpose": "clean-debug-log",
            "exit_code": 0,
            "scan_observed": True,
            "match_count": 0,
            "blocker_code": "",
            "source_payload_sha256": "sha256:" + "7" * 64,
        },
        b"woopayments-md02-log-context-v1\0",
    )
    execution = module.build_execution(
        target,
        log_scan,
        status="pass",
        exit_code=0,
        verdict_sources=[],
        comparison=comparison,
    )
    execution = module.seal(execution, b"woopayments-md02-execution-context-v1\0")

    paths = {}
    for name, payload in {
        "target-md02-store-packet.json": target,
        "target-log-scan.json": log_scan,
        "comparison.json": comparison,
        "target-execution.json": execution,
    }.items():
        path = tmp_path / name
        path.write_text(json.dumps(payload), encoding="utf-8")
        paths[name] = path
    manifest = module.build_manifest(
        store="target",
        status="pass",
        exit_code=0,
        run_stamp=RUN_STAMP,
        run_scope="partial",
        verdict_sources=[],
        paths=list(paths.values()),
    )
    manifest = module.seal(manifest, b"woopayments-md02-manifest-context-v1\0")
    manifest_path = tmp_path / "target-manifest.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    module.validate_manifest(
        manifest_path,
        store="target",
        status="pass",
        exit_code=0,
        run_stamp=RUN_STAMP,
        run_scope="partial",
    )
    runner_validator = subprocess.run(
        [
            "python3",
            str(EVIDENCE_TOOL),
            "validate-bound-manifest",
            "--manifest",
            str(manifest_path),
            "--store",
            "target",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    assert runner_validator.returncode == 0, runner_validator.stdout

    screenshot_path = tmp_path / "target-md02-saved.png"
    original_screenshot = screenshot_path.read_bytes()
    screenshot_path.write_bytes(original_screenshot + b"tampered")
    with pytest.raises(module.EvidenceError, match="screenshot digest"):
        module.validate_manifest(
            manifest_path,
            store="target",
            status="pass",
            exit_code=0,
            run_stamp=RUN_STAMP,
            run_scope="partial",
        )
    runner_validator = subprocess.run(
        [
            "python3",
            str(EVIDENCE_TOOL),
            "validate-bound-manifest",
            "--manifest",
            str(manifest_path),
            "--store",
            "target",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    assert runner_validator.returncode == 3
    screenshot_path.write_bytes(original_screenshot)

    paths["target-md02-store-packet.json"].write_text("{}", encoding="utf-8")
    with pytest.raises(module.EvidenceError, match="digest"):
        module.validate_manifest(
            manifest_path,
            store="target",
            status="pass",
            exit_code=0,
            run_stamp=RUN_STAMP,
            run_scope="partial",
        )


def test_manifest_rejects_execution_verdict_that_contradicts_manifest(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    module = load_evidence()
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    packet = sealed_store_packet(module, "ref", tmp_path)
    log_scan = module.seal(
        {
            "schema": "woopayments_md02_log_scan.v1",
            "status": "pass",
            "store": "ref",
            "run_stamp": RUN_STAMP,
            "flow_id": "MD-02-save-evidence",
            "purpose": "clean-debug-log",
            "exit_code": 0,
            "scan_observed": True,
            "match_count": 0,
            "blocker_code": "",
            "source_payload_sha256": "sha256:" + "7" * 64,
        },
        module.LOG_DOMAIN,
    )
    execution = module.seal(
        module.build_execution(packet, log_scan, status="pass", exit_code=0, verdict_sources=[]),
        module.EXECUTION_DOMAIN,
    )
    for name, payload in {
        "ref-md02-store-packet.json": packet,
        "ref-log-scan.json": log_scan,
        "ref-execution.json": execution,
    }.items():
        (tmp_path / name).write_text(json.dumps(payload), encoding="utf-8")
    with pytest.raises(module.EvidenceError, match="verdict"):
        module.build_manifest(
            store="ref",
            status="fail",
            exit_code=1,
            run_stamp=RUN_STAMP,
            run_scope="partial",
            verdict_sources=["forced_fail"],
            paths=list(tmp_path.glob("*.json")),
        )


def test_cli_exposes_the_exact_md02_evidence_commands() -> None:
    completed = subprocess.run(
        ["python3", str(EVIDENCE_TOOL), "--help"],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )
    assert completed.returncode == 0
    for command in (
        "extract-json-object",
        "normalize-state",
        "validate-state",
        "seal-store-packet",
        "compare",
        "normalize-log-scan",
        "execution",
        "manifest",
        "validate-bound-manifest",
    ):
        assert command in completed.stdout
