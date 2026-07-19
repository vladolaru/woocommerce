#!/usr/bin/env python3
"""Wiring and safety regressions for the MD-02 supervisor flow."""

from __future__ import annotations

import os
import subprocess
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
FLOW = ROOT / "tools/woopayments-critical-flows/flows/MD-02-save-evidence.sh"
RUNNER = ROOT / "tools/woopayments-critical-flows/run.sh"
SHELL_CONTRACT = ROOT / "tools/woopayments-critical-flows/lib/md02-shell-contract.sh"


def test_md02_flow_exists_and_uses_the_shared_supervisor_contract() -> None:
    assert FLOW.is_file()
    source = FLOW.read_text(encoding="utf-8")
    for required in (
        'source "$DIR/../lib/common.sh"',
        'source "$DIR/../lib/md02-shell-contract.sh"',
        "MD02_CONTEXT_FILE",
        "MD02_REF_SOURCE_MANIFEST",
        "MD02_TARGET_SOURCE_MANIFEST",
        "md02-save-evidence-gate.py",
        "--browser-runner playwright",
        "record_assertion assert_md02_check exact_description",
        "record_assertion assert_md02_check customer_name_preserved",
        "record_assertion assert_md02_check no_files_attached",
        "record_assertion assert_md02_check not_submitted",
        "record_assertion assert_md02_check deadline_unchanged",
        "record_assertion assert_md02_check delayed_state_stable",
        'assert_log_clean "$S"',
        "normalize-log-scan",
        "validate-bound-manifest",
        "cleanup-fatal",
        "exit 70",
    ):
        assert required in source
    assert "play" + "writer" not in source.lower()
    assert "docker system prune" not in source
    assert "wp db reset" not in source


def test_md02_flow_is_single_attempt_and_never_replays_the_mutation() -> None:
    source = FLOW.read_text(encoding="utf-8")
    assert source.count('python3 "$GATE"') == 1
    assert "for attempt" not in source
    assert "retry" not in source.lower()
    assert "rm -rf" not in source
    assert "evidence directory already exists" in source


def test_runner_requires_a_semantically_valid_md02_manifest() -> None:
    source = RUNNER.read_text(encoding="utf-8")
    assert "validate_md02_manifest()" in source
    assert 'elif [ "$base" = "MD-02-save-evidence" ]; then' in source
    assert "MD-02 deterministic evidence manifest is missing" in source
    assert 'flows/md02-evidence.py" validate-bound-manifest' in source
    assert '$s/$s-manifest.json' in source


def test_md02_shell_classifies_pass_fail_blocked_and_cleanup_with_blocked_precedence() -> None:
    cases = {
        (0, 0, 0, 0, 0): "pass 0",
        (1, 0, 0, 0, 0): "fail 1",
        (3, 0, 0, 0, 0): "blocked 3",
        (70, 0, 0, 0, 0): "cleanup 70",
        (1, 0, 0, 3, 0): "blocked 3",
        (0, 1, 1, 0, 0): "blocked 3",
    }
    for arguments, expected in cases.items():
        completed = subprocess.run(
            [
                "bash",
                "-c",
                'source "$1"; shift; md02_classify_verdict "$@"',
                "bash",
                str(SHELL_CONTRACT),
                *(str(value) for value in arguments),
            ],
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert completed.returncode == 0, completed.stderr
        assert completed.stdout.strip() == expected


def test_md02_flow_preserves_gate_cleanup_exit_70(tmp_path: Path) -> None:
    fake_gate = tmp_path / "fake-gate.py"
    fake_gate.write_text("raise SystemExit(70)\n", encoding="utf-8")
    context = tmp_path / "context.json"
    source_manifest = tmp_path / "source-manifest.json"
    context.write_text("{}\n", encoding="utf-8")
    source_manifest.write_text("{}\n", encoding="utf-8")
    completed = subprocess.run(
        ["bash", str(FLOW)],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        env={
            **os.environ,
            "STORE_NAME": "ref",
            "CRITICAL_FLOWS_RUN_STAMP": "20260719T220000Z-4242",
            "CRITICAL_FLOWS_RUN_SCOPE": "partial",
            "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "42" * 32,
            "CRITICAL_FLOWS_FLOW_ID": "MD-02-save-evidence",
            "CRITICAL_FLOWS_LOG_PURPOSE": "clean-debug-log",
            "EVIDENCE_DIR": str(tmp_path / "evidence"),
            "MD02_CONTEXT_FILE": str(context),
            "MD02_REF_SOURCE_MANIFEST": str(source_manifest),
            "MD02_REF_URL": "http://localhost:8082",
            "MD02_GATE": str(fake_gate),
            "REF_WP_COMMAND": "fake-local-wp",
            "MD02_DELAY_SECONDS": "0",
        },
    )
    assert completed.returncode == 70
    assert "cleanup-fatal" in completed.stderr
