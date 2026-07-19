#!/usr/bin/env python3
"""Prevent shared-session browser compatibility paths from returning."""

from __future__ import annotations

import os
import subprocess
from pathlib import Path


MERGE_DIR = Path(__file__).resolve().parent
CRITICAL_FLOWS_DIR = MERGE_DIR.parent / "woopayments-critical-flows"
REPO = MERGE_DIR.parent.parent
EXECUTABLE_SUFFIXES = {".cjs", ".js", ".jsx", ".mjs", ".php", ".py", ".sh", ".ts", ".tsx"}
LEGACY_ALIAS_FILES = {
    CRITICAL_FLOWS_DIR / "build-agent-results.py",
    CRITICAL_FLOWS_DIR / "test-agent-results.py",
}


def tracked_executable_source_files(root: Path) -> list[Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z", "--", str(root.relative_to(REPO))],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=True,
    )
    return sorted(
        REPO / relative_path
        for relative_path in result.stdout.split("\0")
        if relative_path and Path(relative_path).suffix in EXECUTABLE_SUFFIXES
    )


def test_browser_runtime_is_direct_playwright_only() -> None:
    legacy_runner = "play" + "writer"
    legacy_filename = "plugin-active-settings." + legacy_runner + ".log"
    offenders: list[str] = []
    legacy_alias_counts = {path: 0 for path in LEGACY_ALIAS_FILES}

    for path in [
        *tracked_executable_source_files(MERGE_DIR),
        *tracked_executable_source_files(CRITICAL_FLOWS_DIR),
    ]:
        for line_number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
            if legacy_runner in line.lower():
                if (
                    path in legacy_alias_counts
                    and line.count(legacy_filename) == 1
                    and line.lower().count(legacy_runner) == 1
                ):
                    legacy_alias_counts[path] += 1
                else:
                    offenders.append(f"{path.relative_to(MERGE_DIR.parent.parent)}:{line_number}")

    agent_template = CRITICAL_FLOWS_DIR / "agent-specs" / "_template.md"
    for line_number, line in enumerate(agent_template.read_text(encoding="utf-8").splitlines(), start=1):
        if legacy_runner in line.lower():
            offenders.append(f"{agent_template.relative_to(MERGE_DIR.parent.parent)}:{line_number}")

    assert offenders == [], f"shared-session browser references remain in live harness sources: {offenders}"
    assert legacy_alias_counts == {path: 1 for path in LEGACY_ALIAS_FILES}


def test_every_browser_entrypoint_rejects_a_legacy_runner(tmp_path: Path) -> None:
    legacy_runner = "play" + "writer"
    reference_wp = "docker exec -i wcpay_wp_default wp --allow-root"
    target_wp = "docker exec -i target-cli-1 wp --allow-root --user=1"
    runner_log = tmp_path / "runner.log"
    fake_runner = tmp_path / "must-not-run"
    fake_runner.write_text(
        f"#!/usr/bin/env bash\nprintf 'invoked\\n' >> {runner_log!s}\n",
        encoding="utf-8",
    )
    fake_runner.chmod(0o755)
    output_dirs = {
        name: tmp_path / name
        for name in ("verify", "lpm", "plugin-active", "token", "a4aq", "a5f")
    }
    commands = [
        [
            "bash",
            str(MERGE_DIR / "verify.sh"),
            "--self-check",
            reference_wp,
            "--browser-runner",
            legacy_runner,
            "--full-evidence-out-dir",
            str(output_dirs["verify"]),
        ],
        [
            "bash",
            str(MERGE_DIR / "lpm-checkout-gate.sh"),
            "--methods",
            "ideal",
            "--ref",
            reference_wp,
            "--target",
            target_wp,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--browser-runner",
            legacy_runner,
            "--out-dir",
            str(output_dirs["lpm"]),
        ],
        [
            "bash",
            str(MERGE_DIR / "plugin-active-settings-gate.sh"),
            "--target",
            target_wp,
            "--target-url",
            "http://store8889.localhost:8889",
            "--browser-runner",
            legacy_runner,
            "--out-dir",
            str(output_dirs["plugin-active"]),
        ],
        [
            "bash",
            str(MERGE_DIR / "token-continuity-gate.sh"),
            "--target",
            target_wp,
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--browser-runner",
            legacy_runner,
            "--out-dir",
            str(output_dirs["token"]),
        ],
        [
            "python3",
            str(MERGE_DIR / "a4aq-accumulated-gate.py"),
            "--repo",
            str(MERGE_DIR.parent.parent),
            "--plugin-repo",
            str(MERGE_DIR.parent.parent.parent / "woocommerce-payments"),
            "--ref-wp",
            reference_wp,
            "--target-wp",
            target_wp,
            "--browser-runner",
            legacy_runner,
            "--out-dir",
            str(output_dirs["a4aq"]),
        ],
        [
            "python3",
            str(MERGE_DIR / "a5f-cutover-rehearsal.py"),
            "--target-wp",
            target_wp,
            "--browser-runner",
            legacy_runner,
            "--out-dir",
            str(output_dirs["a5f"]),
        ],
    ]
    env = {
        **os.environ,
        "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_runner),
        "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target-cli-1",
    }

    for command in commands:
        result = subprocess.run(
            command,
            cwd=MERGE_DIR.parent.parent,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            check=False,
        )
        assert result.returncode == 2, (command, result.stdout, result.stderr)
        assert "playwright" in result.stderr.lower(), (command, result.stderr)

    assert not runner_log.exists()
    assert all(not output_dir.exists() for output_dir in output_dirs.values())


def test_legacy_browser_log_alias_is_read_only_and_bounded() -> None:
    legacy_filename = "plugin-active-settings." + "play" + "writer.log"
    importer = (CRITICAL_FLOWS_DIR / "build-agent-results.py").read_text(encoding="utf-8")
    fixture = (CRITICAL_FLOWS_DIR / "test-agent-results.py").read_text(encoding="utf-8")

    assert importer.count(legacy_filename) == 1
    assert fixture.count(legacy_filename) == 1
