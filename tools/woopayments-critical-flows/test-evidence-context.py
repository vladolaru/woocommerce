#!/usr/bin/env python3
"""Regression checks for critical-flow evidence provenance."""

from __future__ import annotations

import importlib.util
import json
import os
import subprocess
import tempfile
from pathlib import Path

import pytest


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"


def load_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


@pytest.fixture(scope="module")
def module():
    return load_module()


def sample_context(module):
    return module.build_context(
        aggregate_run_id="run-123",
        source={"head_sha": "a" * 40, "worktree_sha256": "sha256:" + "b" * 64},
        stores={
            "ref": {
                "store_fingerprint": "sha256:" + "c" * 64,
                "runtime_owner": "plugin",
                "account_state_sha256": "sha256:" + "d" * 64,
            },
            "target": {
                "store_fingerprint": "sha256:" + "e" * 64,
                "runtime_owner": "native",
                "account_state_sha256": "sha256:" + "f" * 64,
            },
        },
        fixtures={
            "ref": {"subscription_id": "1283"},
            "target": {"subscription_id": "874"},
        },
    )


def sample_result(evidence_path: Path) -> dict:
    return {
        "flow": "MS-07-admin-change-method",
        "store_results": [
            {
                "store": "ref",
                "verdict": "PASS",
                "evidence_paths": [str(evidence_path)],
            },
            {
                "store": "target",
                "verdict": "PASS",
                "evidence_paths": [str(evidence_path)],
            },
        ],
        "parity_verdict": "PASS",
        "regression_note": "current capture",
    }


def test_context_digest_is_stable_and_detects_changes(module) -> None:
    first = sample_context(module)
    second = sample_context(module)

    assert first == second
    assert first["schema"] == "woopayments_critical_flow_context.v1"
    assert first["context_sha256"].startswith("sha256:")

    changed = json.loads(json.dumps(second))
    changed["fixtures"]["target"]["subscription_id"] = "875"

    with pytest.raises(module.EvidenceContextError, match="context digest") as error:
        module.validate_context(changed)
    assert error.value.code == "evidence_context_digest_mismatch"


def test_context_requires_subscription_fixture_ids_for_both_stores(module) -> None:
    context = sample_context(module)
    context["fixtures"]["target"]["subscription_id"] = ""
    context["context_sha256"] = module.context_digest(context)

    with pytest.raises(module.EvidenceContextError, match="subscription fixture") as error:
        module.validate_context(context)
    assert error.value.code == "evidence_context_fixture_missing"


def test_generated_result_is_stamped_and_validated_with_artifact_hashes(module, tmp_path: Path) -> None:
    context = sample_context(module)
    evidence = tmp_path / "selected.png"
    evidence.write_bytes(b"current screenshot")

    stamped = module.stamp_generated_result(sample_result(evidence), context)

    assert stamped["schema"] == "woopayments_critical_flow_result.v2"
    assert stamped["provenance"]["capture"]["capture_id"] == "run-123"
    assert stamped["provenance"]["import"]["aggregate_run_id"] == "run-123"
    assert stamped["provenance"]["import"]["context_sha256"] == context["context_sha256"]
    assert "evidence_paths" not in stamped["store_results"][0]
    assert stamped["store_results"][0]["evidence"][0]["sha256"].startswith("sha256:")
    module.validate_imported_result(stamped, context, "MS-07-admin-change-method", "target")

    evidence.write_bytes(b"stale screenshot")
    with pytest.raises(module.EvidenceContextError, match="artifact hash") as error:
        module.validate_imported_result(stamped, context, "MS-07-admin-change-method", "target")
    assert error.value.code == "evidence_artifact_mismatch"


def test_generated_result_can_bind_archive_relative_artifact_paths(module, tmp_path: Path) -> None:
    context = sample_context(module)
    evidence_dir = tmp_path / "evidence"
    result_dir = tmp_path / "results"
    evidence_dir.mkdir()
    result_dir.mkdir()
    evidence = evidence_dir / "selected.png"
    evidence.write_bytes(b"current screenshot")
    result_path = result_dir / "result.json"

    stamped = module.stamp_generated_result(
        sample_result(evidence),
        context,
        evidence_base_dir=result_dir,
    )
    recorded = stamped["store_results"][0]["evidence"][0]["path"]

    assert not Path(recorded).is_absolute()
    assert "vladolaru" not in recorded
    module.validate_imported_result(
        stamped,
        context,
        "MS-07-admin-change-method",
        "target",
        result_path=result_path,
    )


def test_manual_capture_must_match_current_context_before_import(module, tmp_path: Path) -> None:
    context = sample_context(module)
    evidence = tmp_path / "result.json"
    evidence.write_text("manual source", encoding="utf-8")
    captured = sample_result(evidence)
    captured["schema"] = "woopayments_critical_flow_result.v2"
    captured["provenance"] = {"capture": module.build_capture(context, "capture-456")}

    imported = module.import_captured_result(captured, context, evidence)
    module.validate_imported_result(imported, context, "MS-07-admin-change-method", "ref")

    wrong_context = sample_context(module)
    wrong_context["stores"]["target"]["account_state_sha256"] = "sha256:" + "0" * 64
    wrong_context["context_sha256"] = module.context_digest(wrong_context)
    with pytest.raises(module.EvidenceContextError, match="capture context") as error:
        module.import_captured_result(captured, wrong_context, evidence)
    assert error.value.code == "evidence_capture_context_mismatch"


def test_legacy_result_is_non_gating(module, tmp_path: Path) -> None:
    context = sample_context(module)
    source = tmp_path / "legacy.json"
    source.write_text("{}\n", encoding="utf-8")

    with pytest.raises(module.EvidenceContextError, match="provenance") as error:
        module.import_captured_result(sample_result(source), context, source)
    assert error.value.code == "evidence_provenance_missing"


def test_source_snapshot_changes_for_dirty_and_untracked_content(module) -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flow-source-") as tmp:
        repo = Path(tmp)
        subprocess.run(["git", "init", "-q"], cwd=repo, check=True)
        subprocess.run(["git", "config", "user.email", "test@example.com"], cwd=repo, check=True)
        subprocess.run(["git", "config", "user.name", "Test"], cwd=repo, check=True)
        tracked = repo / "tracked.txt"
        tracked.write_text("one\n", encoding="utf-8")
        subprocess.run(["git", "add", "tracked.txt"], cwd=repo, check=True)
        subprocess.run(["git", "commit", "-qm", "initial"], cwd=repo, check=True)

        clean = module.source_snapshot(repo)
        tracked.write_text("two\n", encoding="utf-8")
        dirty = module.source_snapshot(repo)
        (repo / "untracked.txt").write_text("three\n", encoding="utf-8")
        untracked = module.source_snapshot(repo)

    assert clean["head_sha"] == dirty["head_sha"] == untracked["head_sha"]
    assert clean["worktree_sha256"] != dirty["worktree_sha256"]
    assert dirty["worktree_sha256"] != untracked["worktree_sha256"]


def test_local_store_capture_rejects_remote_or_shell_commands(module) -> None:
    invalid = (
        "wp --http=https://example.com option get home",
        "docker exec -i target-cli-1 wp option get home; rm -rf .",
        "ssh example wp option get home",
    )

    for command in invalid:
        with pytest.raises(module.EvidenceContextError, match="local Docker"):
            module.validate_local_wp_command(command, "target")


def test_local_store_capture_accepts_only_the_orchestrator_approved_reference_container(
    module,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_REF_CONTAINER", "wcpay_wp_codex_oracle_10_8")

    parts = module.validate_local_wp_command(
        "docker exec -i wcpay_wp_codex_oracle_10_8 wp --allow-root --user=1",
        "ref",
    )

    assert parts[3] == "wcpay_wp_codex_oracle_10_8"
    with pytest.raises(module.EvidenceContextError, match="wcpay_wp_codex_oracle_10_8"):
        module.validate_local_wp_command(
            "docker exec -i wcpay_wp_other_oracle wp --allow-root --user=1",
            "ref",
        )


def test_store_probe_is_reduced_to_stable_hashed_context(module) -> None:
    probe = {
        "ready": True,
        "blog_id": 4,
        "home_url": "http://store8889.localhost:8889",
        "jetpack_blog_id_sha256": "sha256:" + "1" * 64,
        "runtime_owner": "native",
        "account": {
            "account_id_sha256": "sha256:" + "2" * 64,
            "country": "US",
            "test_mode": True,
            "business_type": "company",
            "capabilities": {"card_payments": "active", "p24_payments": "unrequested"},
        },
        "failures": [],
    }

    normalized = module.normalize_store_probe(probe, "target", "native")

    assert normalized["runtime_owner"] == "native"
    assert normalized["store_fingerprint"].startswith("sha256:")
    assert normalized["account_state_sha256"].startswith("sha256:")
    assert "account" not in normalized
    assert "home_url" not in normalized

    probe["runtime_owner"] = "plugin"
    with pytest.raises(module.EvidenceContextError, match="runtime owner") as error:
        module.normalize_store_probe(probe, "target", "native")
    assert error.value.code == "evidence_store_owner_mismatch"


def test_create_cli_captures_source_stores_and_fixture_context(tmp_path: Path) -> None:
    fake_bin = tmp_path / "bin"
    fake_bin.mkdir()
    fake_docker = fake_bin / "docker"
    fake_docker.write_text(
        """#!/usr/bin/env bash
set -eu
if [ "$1" = "context" ] && [ "$2" = "show" ]; then
    printf 'default\n'
    exit 0
fi
if [ "$1" = "context" ] && [ "$2" = "inspect" ]; then
    printf '[{"Endpoints":{"docker":{"Host":"unix:///woopayments-test/docker.sock"}}}]\n'
    exit 0
fi
container="$3"
owner="native"
blog_id=4
home="http://store8889.localhost:8889"
if [ "$container" = "wcpay_wp_default" ]; then
    owner="plugin"
    blog_id=1
    home="http://localhost:8082"
fi
printf '{"ready":true,"blog_id":%s,"home_url":"%s","jetpack_blog_id_sha256":"sha256:%064d","runtime_owner":"%s","account":{"account_id_sha256":"sha256:%064d","country":"US","test_mode":true,"business_type":"company","capabilities":{"card_payments":"active"}},"failures":[]}\n' "$blog_id" "$home" 1 "$owner" "$blog_id"
""",
        encoding="utf-8",
    )
    fake_docker.chmod(0o755)
    context_path = tmp_path / "critical-flow-context.json"
    command = [
        "python3",
        str(MODULE_PATH),
        "create",
        "--repo",
        str(REPO),
        "--out",
        str(context_path),
        "--ref-wp",
        "docker exec -i wcpay_wp_default wp --allow-root --user=1",
        "--target-wp",
        "docker exec -i target-cli-1 wp --allow-root --user=1",
        "--ref-subscription-id",
        "1283",
        "--target-subscription-id",
        "874",
    ]

    result = subprocess.run(
        command,
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            **os.environ,
            "PATH": f"{fake_bin}:{os.environ['PATH']}",
            "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target-cli-1",
        },
        check=False,
    )

    assert result.returncode == 0, result.stderr
    context = json.loads(context_path.read_text(encoding="utf-8"))
    assert context["schema"] == "woopayments_critical_flow_context.v1"
    assert context["stores"]["ref"]["runtime_owner"] == "plugin"
    assert context["stores"]["target"]["runtime_owner"] == "native"
    assert context["fixtures"] == {
        "ref": {"subscription_id": "1283"},
        "target": {"subscription_id": "874"},
    }

    repeated = subprocess.run(
        command,
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            **os.environ,
            "PATH": f"{fake_bin}:{os.environ['PATH']}",
            "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target-cli-1",
        },
        check=False,
    )
    assert repeated.returncode == 1
    assert "already exists" in repeated.stderr
