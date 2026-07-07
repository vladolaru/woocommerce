#!/usr/bin/env python3
"""Regression checks for the subsystem-disposition inventory gate."""

from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/subsystem-disposition-gate.sh"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"


def run_gate(extension_root: Path, manifest: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            "bash",
            str(SCRIPT),
            "--extension-root",
            str(extension_root),
            "--manifest",
            str(manifest),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_manifest(path: Path, rows: str) -> None:
    path.write_text(
        f"""# Test subsystem manifest

| Subsystem | Extension source | Disposition | Native owner | Verification | Signed-off by | Sign-off date | Reason |
| --- | --- | --- | --- | --- | --- | --- | --- |
{rows}
""",
        encoding="utf-8",
    )


def touch(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("<?php\n", encoding="utf-8")


def test_gate_fails_when_extension_file_is_not_matched_by_manifest_row() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/ported.php")
        touch(extension_root / "includes/missing.php")
        write_manifest(
            manifest,
            "| Ported file | `includes/ported.php` | `PORTED` | native owner | unit test |  |  |  |\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 1
        assert "unmatched extension files" in result.stdout
        assert "includes/missing.php" in result.stdout


def test_gate_accepts_exact_glob_and_signed_dropped_rows() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/ported.php")
        touch(extension_root / "src/Internal/Thing.php")
        touch(extension_root / "includes/dropped/survey.php")
        write_manifest(
            manifest,
            "\n".join(
                [
                    "| Ported file | `includes/ported.php` | `PORTED` | native owner | unit test |  |  |  |",
                    "| Superseded source tree | `src/**/*.php` | `SUPERSEDED` | native owner | unit test |  |  |  |",
                    "| Dropped survey | `includes/dropped/*.php` | `DROPPED` | none | product decision | Payments lead | 2026-07-07 | Plugin deactivation survey is obsolete after cutover. |",
                ]
            )
            + "\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 0, result.stdout + result.stderr
        assert "RESULT: PASS" in result.stdout
        assert "3 extension files covered" in result.stdout


def test_gate_rejects_dropped_rows_without_signoff_fields() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/survey.php")
        write_manifest(
            manifest,
            "| Dropped survey | `includes/survey.php` | `DROPPED` | none | product decision |  |  |  |\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 1
        assert "dropped rows missing sign-off" in result.stdout
        assert "Dropped survey" in result.stdout


def test_gate_rejects_manifest_source_patterns_that_match_no_extension_files() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/ported.php")
        write_manifest(
            manifest,
            "\n".join(
                [
                    "| Ported file | `includes/ported.php` | `PORTED` | native owner | unit test |  |  |  |",
                    "| Stale file | `includes/missing.php` | `PORTED` | native owner | unit test |  |  |  |",
                ]
            )
            + "\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 1
        assert "manifest source patterns matching no extension files" in result.stdout
        assert "includes/missing.php" in result.stdout


def test_gate_rejects_invalid_disposition_rows() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/unknown.php")
        write_manifest(
            manifest,
            "| Unknown row | `includes/unknown.php` | `OPEN` | native owner | unit test |  |  |  |\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 1
        assert "invalid disposition rows" in result.stdout
        assert "Unknown row" in result.stdout


def test_verify_runs_subsystem_disposition_gate() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "subsystem-disposition-gate.sh" in verify_source
