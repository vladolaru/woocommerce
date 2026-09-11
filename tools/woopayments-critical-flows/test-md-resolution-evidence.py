#!/usr/bin/env python3
"""Behavioral and integrity regressions for MD-03/MD-04 evidence."""

from __future__ import annotations

import copy
import importlib.util
import json
import os
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
EVIDENCE_TOOL = ROOT / "tools/woopayments-critical-flows/flows/md-resolution-evidence.py"
RUN_STAMP = "20260720T010000Z-4242"
CONTEXT_KEY = "42" * 32


def load_evidence():
    spec = importlib.util.spec_from_file_location("md_resolution_evidence", EVIDENCE_TOOL)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_outcome_profiles_bind_exact_flow_marker_and_terminal_status() -> None:
    module = load_evidence()

    assert module.outcome_profile("won") == {
        "outcome": "won",
        "flow": "MD-03-winning-dispute",
        "evidence_marker": "winning_evidence",
        "terminal_status": "won",
    }
    assert module.outcome_profile("lost") == {
        "outcome": "lost",
        "flow": "MD-04-losing-dispute",
        "evidence_marker": "losing_evidence",
        "terminal_status": "lost",
    }


def winning_facts() -> tuple[dict, dict, dict]:
    submission = {
        "trusted": True,
        "response_class": "trusted_success",
        "submit": True,
        "marker": "winning_evidence",
        "http_status": 200,
        "dispute_id": "dp_ref_won",
    }
    store = {
        "available": True,
        "dispute_id": "dp_ref_won",
        "dispute_status": "won",
        "charge_id": "ch_ref_won",
        "intent_id": "pi_ref_won",
        "order_id": 101,
        "order_status": "processing",
        "order_total_minor": 5000,
        "currency": "usd",
        "notes": {
            "created": True,
            "evidence_submitted": True,
            "funds_reinstated": True,
            "fees_deducted": False,
        },
        "refunds": [],
    }
    provider = {
        "available": True,
        "livemode": False,
        "dispute_id": "dp_ref_won",
        "dispute_status": "won",
        "charge_id": "ch_ref_won",
        "intent_id": "pi_ref_won",
        "amount": 5000,
        "currency": "usd",
        "balance_transactions": [
            {"id": "txn_debit", "amount": -5000, "fee": 1500, "net": -6500},
            {"id": "txn_reversal", "amount": 5000, "fee": -1500, "net": 6500},
        ],
    }
    return submission, store, provider


def test_winning_assertions_prove_terminal_reinstatement_without_loss_shape() -> None:
    module = load_evidence()
    submission, store, provider = winning_facts()

    assertions = module.derive_outcome_assertions("won", submission, store, provider)

    assert assertions == {
        "trusted_exact_submission": True,
        "provider_test_mode": True,
        "exact_identity": True,
        "terminal_status": True,
        "created_note": True,
        "evidence_submitted_note": True,
        "outcome_note": True,
        "opposite_outcome_note_absent": True,
        "refund_shape": True,
        "original_dispute_debit": True,
        "outcome_amount_movement": True,
        "fee_shape_recorded": True,
        "amount_currency_binding": True,
    }


@pytest.mark.parametrize(
    "mutate,failed_assertion",
    [
        (lambda submission, store, provider: submission.update(submit=False), "trusted_exact_submission"),
        (lambda submission, store, provider: store.update(dispute_status="lost"), "terminal_status"),
        (lambda submission, store, provider: store["notes"].update(created=False), "created_note"),
        (
            lambda submission, store, provider: store["notes"].update(evidence_submitted=False),
            "evidence_submitted_note",
        ),
        (lambda submission, store, provider: store["notes"].update(funds_reinstated=False), "outcome_note"),
        (
            lambda submission, store, provider: store["notes"].update(fees_deducted=True),
            "opposite_outcome_note_absent",
        ),
        (
            lambda submission, store, provider: store["refunds"].append(
                {"amount_minor": 5000, "reason_family": "dispute"}
            ),
            "refund_shape",
        ),
        (
            lambda submission, store, provider: provider["balance_transactions"].pop(),
            "outcome_amount_movement",
        ),
        (lambda submission, store, provider: provider.update(livemode=True), "provider_test_mode"),
        (lambda submission, store, provider: provider.update(charge_id="ch_other"), "exact_identity"),
        (lambda submission, store, provider: provider.update(currency="eur"), "amount_currency_binding"),
    ],
)
def test_winning_assertions_fail_closed_on_semantic_drift(mutate, failed_assertion: str) -> None:
    module = load_evidence()
    submission, store, provider = winning_facts()
    mutate(submission, store, provider)

    assertions = module.derive_outcome_assertions("won", submission, store, provider)

    assert assertions[failed_assertion] is False


def losing_facts() -> tuple[dict, dict, dict]:
    submission, store, provider = copy.deepcopy(winning_facts())
    submission.update(marker="losing_evidence", dispute_id="dp_ref_lost")
    store.update(
        dispute_id="dp_ref_lost",
        dispute_status="lost",
        charge_id="ch_ref_lost",
        intent_id="pi_ref_lost",
    )
    store["notes"].update(funds_reinstated=False, fees_deducted=True)
    store["refunds"] = [{"amount_minor": 5000, "reason_family": "dispute"}]
    provider.update(
        dispute_id="dp_ref_lost",
        dispute_status="lost",
        charge_id="ch_ref_lost",
        intent_id="pi_ref_lost",
        balance_transactions=[
            {"id": "txn_debit", "amount": -5000, "fee": 1500, "net": -6500}
        ],
    )
    return submission, store, provider


def test_losing_assertions_prove_standing_debit_fee_and_refund_without_reinstatement() -> None:
    module = load_evidence()
    submission, store, provider = losing_facts()

    assertions = module.derive_outcome_assertions("lost", submission, store, provider)

    assert all(assertions.values()), assertions


@pytest.mark.parametrize(
    "mutate,failed_assertion",
    [
        (lambda submission, store, provider: store.update(dispute_status="won"), "terminal_status"),
        (lambda submission, store, provider: store["notes"].update(fees_deducted=False), "outcome_note"),
        (
            lambda submission, store, provider: store["notes"].update(funds_reinstated=True),
            "opposite_outcome_note_absent",
        ),
        (lambda submission, store, provider: store.update(refunds=[]), "refund_shape"),
        (
            lambda submission, store, provider: store["refunds"][0].update(amount_minor=4900),
            "refund_shape",
        ),
        (
            lambda submission, store, provider: provider["balance_transactions"].append(
                {"id": "txn_phantom", "amount": 5000, "fee": 0, "net": 5000}
            ),
            "outcome_amount_movement",
        ),
        (
            lambda submission, store, provider: provider["balance_transactions"][0].update(
                fee=0, net=-5000
            ),
            "fee_shape_recorded",
        ),
    ],
)
def test_losing_assertions_fail_closed_on_semantic_drift(mutate, failed_assertion: str) -> None:
    module = load_evidence()
    submission, store, provider = losing_facts()
    mutate(submission, store, provider)

    assertions = module.derive_outcome_assertions("lost", submission, store, provider)

    assert assertions[failed_assertion] is False


def test_verdict_passes_only_when_all_observable_contract_assertions_pass() -> None:
    module = load_evidence()
    submission, store, provider = winning_facts()
    assertions = module.derive_outcome_assertions("won", submission, store, provider)

    assert module.derive_verdict(submission, store, provider, assertions) == ("pass", [])


def test_verdict_fails_on_reachable_terminal_contract_mismatch() -> None:
    module = load_evidence()
    submission, store, provider = winning_facts()
    store["notes"]["funds_reinstated"] = False
    assertions = module.derive_outcome_assertions("won", submission, store, provider)

    assert module.derive_verdict(submission, store, provider, assertions) == (
        "fail",
        ["outcome_note"],
    )


@pytest.mark.parametrize(
    "mutate,reason",
    [
        (
            lambda submission, store, provider: submission.update(
                trusted=False, response_class="unknown"
            ),
            "submission_response_unknown",
        ),
        (lambda submission, store, provider: store.update(available=False), "store_observation_unavailable"),
        (
            lambda submission, store, provider: provider.update(available=False),
            "provider_observation_unavailable",
        ),
    ],
)
def test_verdict_blocks_when_required_observation_is_unavailable(mutate, reason: str) -> None:
    module = load_evidence()
    submission, store, provider = winning_facts()
    mutate(submission, store, provider)
    assertions = module.derive_outcome_assertions("won", submission, store, provider)

    status, reasons = module.derive_verdict(submission, store, provider, assertions)

    assert status == "blocked"
    assert reason in reasons


def target_winning_facts() -> tuple[dict, dict, dict]:
    submission, store, provider = copy.deepcopy(winning_facts())
    submission["dispute_id"] = "dp_target_won"
    store.update(
        dispute_id="dp_target_won",
        charge_id="ch_target_won",
        intent_id="pi_target_won",
        order_id=202,
    )
    provider.update(
        dispute_id="dp_target_won",
        charge_id="ch_target_won",
        intent_id="pi_target_won",
    )
    provider["balance_transactions"][0]["id"] = "txn_target_debit"
    provider["balance_transactions"][1]["id"] = "txn_target_reversal"
    return submission, store, provider


def test_comparison_ignores_store_specific_ids_but_preserves_portable_shape() -> None:
    module = load_evidence()
    _, ref_store, ref_provider = winning_facts()
    _, target_store, target_provider = target_winning_facts()

    comparison = module.compare_facts("won", ref_store, ref_provider, target_store, target_provider)

    assert comparison["status"] == "pass"
    assert comparison["differences"] == []
    assert "order_id" not in comparison["reference"]
    assert "dispute_id" not in comparison["reference"]
    assert "id" not in comparison["reference"]["provider_movements"][0]


@pytest.mark.parametrize(
    "mutate,difference",
    [
        (lambda store, provider: store.update(order_status="on-hold"), "order_status"),
        (lambda store, provider: store["notes"].update(funds_reinstated=False), "note_families"),
        (
            lambda store, provider: provider["balance_transactions"][0].update(fee=1600, net=-6600),
            "provider_movements",
        ),
        (lambda store, provider: provider.update(currency="eur"), "provider_currency"),
    ],
)
def test_comparison_fails_on_portable_semantic_drift(mutate, difference: str) -> None:
    module = load_evidence()
    _, ref_store, ref_provider = winning_facts()
    _, target_store, target_provider = target_winning_facts()
    mutate(target_store, target_provider)

    comparison = module.compare_facts("won", ref_store, ref_provider, target_store, target_provider)

    assert comparison["status"] == "fail"
    assert difference in comparison["differences"]


def valid_journal(module, outcome: str = "won", store: str = "ref") -> list[dict]:
    identity = {
        "order_id": 101 if store == "ref" else 202,
        "charge_id": f"ch_{store}_{outcome}",
        "intent_id": f"pi_{store}_{outcome}",
        "dispute_id": f"dp_{store}_{outcome}",
    }
    marker = module.outcome_profile(outcome)["evidence_marker"]
    return [
        module.build_journal_record(
            outcome, store, RUN_STAMP, 1, "fresh_dispute_create_armed", {}
        ),
        module.build_journal_record(
            outcome, store, RUN_STAMP, 2, "fresh_dispute_created", identity
        ),
        module.build_journal_record(
            outcome,
            store,
            RUN_STAMP,
            3,
            "evidence_submit_armed",
            identity,
            marker=marker,
        ),
        module.build_journal_record(
            outcome,
            store,
            RUN_STAMP,
            4,
            "evidence_submit_observed",
            identity,
            marker=marker,
            response_class="trusted_success",
        ),
    ]


def valid_drive(store: str = "ref", outcome: str = "won") -> dict:
    return {
        "op": "dispute",
        "order_id": 101 if store == "ref" else 202,
        "charge_id": f"ch_{store}_{outcome}",
        "intent_id": f"pi_{store}_{outcome}",
        "status": "processing",
        "order_currency": "USD",
    }


def valid_packet(module, outcome: str = "won", store: str = "ref") -> dict:
    if outcome == "won":
        submission, store_facts, provider_facts = winning_facts()
    else:
        submission, store_facts, provider_facts = losing_facts()
    if store == "target":
        if outcome == "won":
            submission, store_facts, provider_facts = target_winning_facts()
        else:
            submission, store_facts, provider_facts = copy.deepcopy(losing_facts())
            submission["dispute_id"] = "dp_target_lost"
            store_facts.update(
                dispute_id="dp_target_lost",
                charge_id="ch_target_lost",
                intent_id="pi_target_lost",
                order_id=202,
            )
            provider_facts.update(
                dispute_id="dp_target_lost",
                charge_id="ch_target_lost",
                intent_id="pi_target_lost",
            )
    return module.build_store_packet(
        outcome=outcome,
        store=store,
        run_stamp=RUN_STAMP,
        runtime_owner="plugin" if store == "ref" else "native",
        drive=valid_drive(store, outcome),
        journal=valid_journal(module, outcome, store),
        submission=submission,
        store_facts=store_facts,
        provider_facts=provider_facts,
        blockers=[],
    )


def test_store_packet_requires_ordered_write_ahead_journal_and_context_seal(monkeypatch) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()

    packet = valid_packet(module)
    module.validate_store_packet(packet, outcome="won", store="ref", run_stamp=RUN_STAMP)

    assert packet["schema"] == "woopayments_md_resolution_store_packet.v1"
    assert packet["flow"] == "MD-03-winning-dispute"
    assert packet["status"] == "pass"
    assert packet["payload_sha256"].startswith("sha256:")
    assert packet["context_hmac"].startswith("hmac-sha256:")


def test_store_packet_preserves_terminal_contract_fail_over_later_observation_blocker(
    monkeypatch,
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    submission, store_facts, provider_facts = winning_facts()
    store_facts["notes"]["funds_reinstated"] = False

    packet = module.build_store_packet(
        outcome="won",
        store="ref",
        run_stamp=RUN_STAMP,
        runtime_owner="plugin",
        drive=valid_drive(),
        journal=valid_journal(module),
        submission=submission,
        store_facts=store_facts,
        provider_facts=provider_facts,
        blockers=["post_submit_observation_unavailable"],
    )

    assert packet["status"] == "fail"
    assert "outcome_note" in packet["reasons"]
    assert "post_submit_observation_unavailable" in packet["reasons"]


@pytest.mark.parametrize(
    "mutate",
    [
        lambda packet: packet.update(store="target"),
        lambda packet: packet.update(flow="MD-04-losing-dispute"),
        lambda packet: packet["journal"].pop(2),
        lambda packet: packet["journal"][2].update(sequence=4),
        lambda packet: packet["journal"][2]["identity"].update(charge_id="ch_other"),
        lambda packet: packet["submission"].update(marker="losing_evidence"),
        lambda packet: packet["assertions"].update(terminal_status=False),
        lambda packet: packet.update(status="fail"),
    ],
)
def test_store_packet_rejects_tamper_and_semantic_contradictions(monkeypatch, mutate) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    packet = valid_packet(module)
    mutate(packet)

    with pytest.raises(module.EvidenceError):
        module.validate_store_packet(packet, outcome="won", store="ref", run_stamp=RUN_STAMP)


@pytest.mark.parametrize("surface", ["submission", "store", "provider", "transaction", "refund"])
def test_store_packet_builder_rejects_unallowlisted_boundary_fields(monkeypatch, surface: str) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    submission, store_facts, provider_facts = losing_facts()
    if surface == "submission":
        submission["raw_response"] = {"customer_email": "private@example.invalid"}
    elif surface == "store":
        store_facts["customer_email"] = "private@example.invalid"
    elif surface == "provider":
        provider_facts["metadata"] = {"customer": "private"}
    elif surface == "transaction":
        provider_facts["balance_transactions"][0]["description"] = "raw provider text"
    else:
        store_facts["refunds"][0]["reason"] = "raw order note"

    with pytest.raises(module.EvidenceError):
        module.build_store_packet(
            outcome="lost",
            store="ref",
            run_stamp=RUN_STAMP,
            runtime_owner="plugin",
            drive=valid_drive("ref", "lost"),
            journal=valid_journal(module, "lost", "ref"),
            submission=submission,
            store_facts=store_facts,
            provider_facts=provider_facts,
            blockers=[],
        )


def valid_raw_log(store: str, flow: str, status: str = "pass") -> dict:
    return {
        "schema": "woopayments_debug_log_scan.v6",
        "store": store,
        "scan": {
            "status": status,
            "run_stamp": RUN_STAMP,
            "store": store,
            "flow_id": flow,
            "purpose": "clean-debug-log",
            "matches": [] if status != "fail" else [{"fingerprint": "sha256:" + "a" * 64}],
            "blocker_code": "" if status != "blocked" else "log_read_failed",
        },
    }


def test_comparison_artifact_binds_same_run_packets_and_rejects_tamper(monkeypatch) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    reference = valid_packet(module, "won", "ref")
    target = valid_packet(module, "won", "target")

    comparison = module.build_comparison(reference, target, outcome="won", run_stamp=RUN_STAMP)
    module.validate_comparison(comparison, outcome="won", run_stamp=RUN_STAMP)

    assert comparison["status"] == "pass"
    tampered = copy.deepcopy(comparison)
    tampered["target"]["order_status"] = "on-hold"
    with pytest.raises(module.EvidenceError):
        module.validate_comparison(tampered, outcome="won", run_stamp=RUN_STAMP)


@pytest.mark.parametrize(
    "status,exit_code",
    [("pass", 0), ("fail", 1), ("blocked", 3)],
)
def test_log_artifact_binds_generic_scan_to_exact_flow_exit_and_context(
    monkeypatch, status: str, exit_code: int
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    flow = module.outcome_profile("won")["flow"]

    log_scan = module.build_log_scan(
        valid_raw_log("ref", flow, status),
        outcome="won",
        store="ref",
        run_stamp=RUN_STAMP,
        expected_exit_code=exit_code,
    )
    module.validate_log_scan(log_scan, outcome="won", store="ref", run_stamp=RUN_STAMP)

    assert log_scan["status"] == status
    assert log_scan["exit_code"] == exit_code


def test_execution_combines_packet_log_and_target_comparison_without_weakening(monkeypatch) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    flow = module.outcome_profile("won")["flow"]
    reference = valid_packet(module, "won", "ref")
    target = valid_packet(module, "won", "target")
    comparison = module.build_comparison(reference, target, outcome="won", run_stamp=RUN_STAMP)
    clean_log = module.build_log_scan(
        valid_raw_log("target", flow),
        outcome="won",
        store="target",
        run_stamp=RUN_STAMP,
        expected_exit_code=0,
    )

    execution = module.build_execution(
        target, clean_log, comparison=comparison, outcome="won", store="target", run_stamp=RUN_STAMP
    )
    module.validate_execution(execution, outcome="won", store="target", run_stamp=RUN_STAMP)
    assert execution["status"] == "pass"
    assert execution["exit_code"] == 0

    failed_log = module.build_log_scan(
        valid_raw_log("target", flow, "fail"),
        outcome="won",
        store="target",
        run_stamp=RUN_STAMP,
        expected_exit_code=1,
    )
    failed = module.build_execution(
        target, failed_log, comparison=comparison, outcome="won", store="target", run_stamp=RUN_STAMP
    )
    assert failed["status"] == "fail"
    assert failed["exit_code"] == 1
    assert "log_scan_failed" in failed["verdict_sources"]

    blocked_target = module.build_store_packet(
        outcome="won",
        store="target",
        run_stamp=RUN_STAMP,
        runtime_owner="native",
        drive=target["drive"],
        journal=target["journal"],
        submission=target["submission"],
        store_facts=target["store_facts"],
        provider_facts=target["provider_facts"],
        blockers=["post_submit_observation_unavailable"],
    )
    blocked_comparison = module.build_comparison(
        reference,
        blocked_target,
        outcome="won",
        run_stamp=RUN_STAMP,
    )
    mixed = module.build_execution(
        blocked_target,
        failed_log,
        comparison=blocked_comparison,
        outcome="won",
        store="target",
        run_stamp=RUN_STAMP,
    )
    assert mixed["status"] == "fail"
    assert mixed["exit_code"] == 1
    assert set(mixed["verdict_sources"]) == {
        "store_packet_blocked",
        "log_scan_failed",
    }


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, sort_keys=True) + "\n", encoding="utf-8")


def valid_artifacts(module, directory: Path, store: str = "ref") -> list[Path]:
    outcome = "won"
    flow = module.outcome_profile(outcome)["flow"]
    packet = valid_packet(module, outcome, store)
    log_scan = module.build_log_scan(
        valid_raw_log(store, flow),
        outcome=outcome,
        store=store,
        run_stamp=RUN_STAMP,
        expected_exit_code=0,
    )
    comparison = None
    paths: list[Path] = []
    if store == "target":
        comparison = module.build_comparison(
            valid_packet(module, outcome, "ref"), packet, outcome=outcome, run_stamp=RUN_STAMP
        )
        comparison_path = directory / "comparison.json"
        write_json(comparison_path, comparison)
        paths.append(comparison_path)
    execution = module.build_execution(
        packet,
        log_scan,
        comparison=comparison,
        outcome=outcome,
        store=store,
        run_stamp=RUN_STAMP,
    )
    for path, payload in (
        (directory / f"{store}-store-packet.json", packet),
        (directory / f"{store}-log-scan.json", log_scan),
        (directory / f"{store}-execution.json", execution),
    ):
        write_json(path, payload)
        paths.append(path)
    return paths


def write_valid_manifest_archive(module, root: Path, store: str) -> tuple[dict, list[Path], Path]:
    if store == "target" and not (root / "ref/ref-manifest.json").is_file():
        write_valid_manifest_archive(module, root, "ref")
    store_dir = root / store
    store_dir.mkdir(parents=True, exist_ok=True)
    paths = valid_artifacts(module, store_dir, store)
    manifest = module.build_manifest(
        paths,
        outcome="won",
        store=store,
        run_stamp=RUN_STAMP,
        run_scope="partial",
        status="pass",
        exit_code=0,
    )
    write_json(store_dir / f"{store}-manifest.json", manifest)
    return manifest, paths, store_dir


@pytest.mark.parametrize("store", ["ref", "target"])
def test_manifest_binds_complete_artifact_set_and_revalidates_files(
    monkeypatch, tmp_path: Path, store: str
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    manifest, paths, store_dir = write_valid_manifest_archive(module, tmp_path, store)
    module.validate_manifest(
        manifest,
        artifact_dir=store_dir,
        outcome="won",
        store=store,
        run_stamp=RUN_STAMP,
        run_scope="partial",
        status="pass",
        exit_code=0,
    )

    assert set(manifest["files"]) == {path.name for path in paths}
    if store == "target":
        reference_manifest = json.loads((tmp_path / "ref/ref-manifest.json").read_text())
        assert manifest["reference_manifest_sha256"] == reference_manifest["payload_sha256"]


def test_target_manifest_revalidates_the_current_reference_archive(
    monkeypatch, tmp_path: Path
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    manifest, _, target_dir = write_valid_manifest_archive(module, tmp_path, "target")
    reference_packet = tmp_path / "ref/ref-store-packet.json"
    reference_packet.write_text(
        reference_packet.read_text(encoding="utf-8") + "\n",
        encoding="utf-8",
    )

    with pytest.raises(module.EvidenceError, match="digest|reference"):
        module.validate_manifest(
            manifest,
            artifact_dir=target_dir,
            outcome="won",
            store="target",
            run_stamp=RUN_STAMP,
            run_scope="partial",
            status="pass",
            exit_code=0,
        )


@pytest.mark.parametrize("attack", ["tamper", "symlink", "missing", "extra"])
def test_manifest_rejects_artifact_tamper_and_unsafe_file_sets(
    monkeypatch, tmp_path: Path, attack: str
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    paths = valid_artifacts(module, tmp_path, "ref")
    manifest = module.build_manifest(
        paths,
        outcome="won",
        store="ref",
        run_stamp=RUN_STAMP,
        run_scope="partial",
        status="pass",
        exit_code=0,
    )

    if attack == "tamper":
        paths[0].write_text("{}\n", encoding="utf-8")
    elif attack == "symlink":
        paths[0].unlink()
        paths[0].symlink_to(paths[1].name)
    elif attack == "missing":
        manifest["files"].pop(paths[0].name)
    else:
        manifest["files"]["extra.json"] = {
            "sha256": "sha256:" + "1" * 64,
            "payload_sha256": "sha256:" + "2" * 64,
        }

    with pytest.raises(module.EvidenceError):
        module.validate_manifest(
            manifest,
            artifact_dir=tmp_path,
            outcome="won",
            store="ref",
            run_stamp=RUN_STAMP,
            run_scope="partial",
            status="pass",
            exit_code=0,
        )


def run_tool(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["python3", str(EVIDENCE_TOOL), *args],
        cwd=ROOT,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": CONTEXT_KEY},
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_cli_builds_and_revalidates_shell_consumed_artifacts(tmp_path: Path, monkeypatch) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", CONTEXT_KEY)
    module = load_evidence()
    archive_root = tmp_path / "run"
    write_valid_manifest_archive(module, archive_root, "ref")
    target_dir = archive_root / "target"
    target_dir.mkdir()
    reference = json.loads((archive_root / "ref/ref-store-packet.json").read_text())
    target = valid_packet(module, "won", "target")
    ref_path = archive_root / "ref/ref-store-packet.json"
    target_path = target_dir / "target-store-packet.json"
    raw_log_path = tmp_path / "raw-log.json"
    write_json(target_path, target)
    write_json(raw_log_path, valid_raw_log("target", module.outcome_profile("won")["flow"]))

    compare = run_tool(
        "compare",
        "--reference",
        str(ref_path),
        "--target",
        str(target_path),
        "--outcome",
        "won",
        "--run-stamp",
        RUN_STAMP,
    )
    assert compare.returncode == 0, compare.stderr
    comparison = json.loads(compare.stdout)
    comparison_path = target_dir / "comparison.json"
    write_json(comparison_path, comparison)

    normalize = run_tool(
        "normalize-log-scan",
        "--input",
        str(raw_log_path),
        "--outcome",
        "won",
        "--store",
        "target",
        "--run-stamp",
        RUN_STAMP,
        "--expected-exit-code",
        "0",
    )
    assert normalize.returncode == 0, normalize.stderr
    log_path = target_dir / "target-log-scan.json"
    write_json(log_path, json.loads(normalize.stdout))

    execution = run_tool(
        "execution",
        "--packet",
        str(target_path),
        "--log-scan",
        str(log_path),
        "--comparison",
        str(comparison_path),
        "--outcome",
        "won",
        "--store",
        "target",
        "--run-stamp",
        RUN_STAMP,
    )
    assert execution.returncode == 0, execution.stderr
    execution_path = target_dir / "target-execution.json"
    write_json(execution_path, json.loads(execution.stdout))

    manifest = run_tool(
        "manifest",
        "--file",
        str(target_path),
        "--file",
        str(log_path),
        "--file",
        str(comparison_path),
        "--file",
        str(execution_path),
        "--outcome",
        "won",
        "--store",
        "target",
        "--run-stamp",
        RUN_STAMP,
        "--run-scope",
        "partial",
        "--status",
        "pass",
        "--exit-code",
        "0",
    )
    assert manifest.returncode == 0, manifest.stderr
    manifest_path = target_dir / "target-manifest.json"
    write_json(manifest_path, json.loads(manifest.stdout))

    validated = run_tool(
        "validate-bound-manifest",
        "--manifest",
        str(manifest_path),
        "--outcome",
        "won",
        "--store",
        "target",
        "--run-stamp",
        RUN_STAMP,
        "--run-scope",
        "partial",
        "--status",
        "pass",
        "--exit-code",
        "0",
    )
    assert validated.returncode == 0, validated.stderr
    assert validated.stdout.strip() == json.loads(manifest.stdout)["payload_sha256"]
