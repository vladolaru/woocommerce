#!/usr/bin/env python3
"""Regression checks for the subsystem-disposition inventory gate."""

from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/subsystem-disposition-gate.sh"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"
MANIFEST = REPO / "tools/woopayments-merge/subsystem-disposition.md"


def run_gate(
    extension_root: Path,
    manifest: Path,
    *,
    extension_ref: str = "worktree",
) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [
            "bash",
            str(SCRIPT),
            "--extension-root",
            str(extension_root),
            "--manifest",
            str(manifest),
            "--extension-ref",
            extension_ref,
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


def test_gate_accepts_exact_rows_and_signed_dropped_rows() -> None:
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
                    "| Superseded source file | `src/Internal/Thing.php` | `SUPERSEDED` | native owner | unit test |  |  |  |",
                    "| Dropped survey | `includes/dropped/survey.php` | `DROPPED` | none | product decision | Payments lead | 2026-07-07 | Plugin deactivation survey is obsolete after cutover. |",
                ]
            )
            + "\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 0, result.stdout + result.stderr
        assert "RESULT: PASS" in result.stdout
        assert "3 extension files covered" in result.stdout
        assert "disposition counts: PORTED=1 SUPERSEDED=1 DROPPED=1" in result.stdout
        assert "signed DROPPED rows: 1" in result.stdout


def test_gate_reads_the_versioned_oracle_tree_without_changing_the_worktree() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"
        versioned_file = extension_root / "includes/deprecated.php"

        extension_root.mkdir()
        subprocess.run(["git", "init", "-q"], cwd=extension_root, check=True)
        touch(versioned_file)
        subprocess.run(["git", "add", "."], cwd=extension_root, check=True)
        subprocess.run(
            [
                "git",
                "-c",
                "user.name=Test",
                "-c",
                "user.email=test@example.test",
                "commit",
                "-qm",
                "oracle",
            ],
            cwd=extension_root,
            check=True,
        )
        subprocess.run(["git", "tag", "10.8.0"], cwd=extension_root, check=True)
        versioned_file.unlink()
        write_manifest(
            manifest,
            "| Deprecated file | `includes/deprecated.php` | `SUPERSEDED` | deprecation cleanup | unit test |  |  |  |\n",
        )

        versioned_result = run_gate(extension_root, manifest, extension_ref="10.8.0")
        worktree_result = run_gate(extension_root, manifest)

        assert versioned_result.returncode == 0, versioned_result.stdout + versioned_result.stderr
        assert "extension ref: 10.8.0" in versioned_result.stdout
        assert worktree_result.returncode == 1
        assert "manifest source patterns matching no extension files" in worktree_result.stdout


def test_gate_rejects_wildcard_manifest_source_patterns() -> None:
    with tempfile.TemporaryDirectory(prefix="subsystem-disposition-gate-test-") as tmp:
        root = Path(tmp)
        extension_root = root / "extension"
        manifest = root / "manifest.md"

        touch(extension_root / "includes/admin/controller.php")
        write_manifest(
            manifest,
            "| Admin tree | `includes/admin/**/*.php` | `PORTED` | native owner | unit test |  |  |  |\n",
        )

        result = run_gate(extension_root, manifest)

        assert result.returncode == 1
        assert "non-exact manifest source patterns" in result.stdout
        assert "includes/admin/**/*.php" in result.stdout


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


def test_plan_specific_payment_method_dispositions_are_pinned() -> None:
    manifest = MANIFEST.read_text(encoding="utf-8")
    rows = {
        cells[1].strip(" `"): cells
        for line in manifest.splitlines()
        if line.startswith("|")
        for cells in ([cell.strip() for cell in line.split("|")[1:-1]],)
        if len(cells) == 8
    }

    apple_pay = rows["includes/class-wc-payments-apple-pay-registration.php"]
    giropay = rows["includes/payment-methods/Configs/Definitions/GiropayDefinition.php"]
    sofort = rows["includes/payment-methods/Configs/Definitions/SofortDefinition.php"]

    assert apple_pay[2] == "`PORTED`"
    for row in (giropay, sofort):
        assert row[2] == "`DROPPED`"
        assert row[5] == "Native WooPayments plan D13"
        assert row[6]
        assert "deprecated" in row[7].lower()


def test_wc_payments_global_service_locator_drop_is_scoped_and_signed() -> None:
    manifest = MANIFEST.read_text(encoding="utf-8")
    matching_rows = [
        cells
        for line in manifest.splitlines()
        if line.startswith("|")
        for cells in ([cell.strip() for cell in line.split("|")[1:-1]],)
        if len(cells) == 8
        and cells[1].strip(" `") == "includes/class-wc-payments.php"
    ]

    assert len(matching_rows) == 1

    row = matching_rows[0]
    assert row[0] == (
        "Legacy global object/service-locator and mutable-map accessors (excluding "
        "scalar/metadata getters); underlying extension initialization, composition, "
        "hooks, and payment responsibilities are superseded"
    )
    assert row[2] == "`DROPPED`"
    assert row[3] == (
        "`plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/`; "
        "`plugins/woocommerce/src/Internal/Payments/PaymentProcessingService.php`; "
        "`plugins/woocommerce/src/Internal/Payments/NativePaymentsGatewayRegistry.php`"
    )
    assert row[4] == (
        "The 2026-07-13 audit found zero current native-owner callers across the searched "
        "preserved scopes: WooCommerce Subscriptions release code, WC Calypso Bridge, "
        "WooPayments Dev Tools, hosted WooPay, and TumblrPay/server-inbound. This is not "
        "an ecosystem-wide zero-use claim. Hosted WooPay, TumblrPay, and remaining "
        "plugin-only Dev Tools paths must migrate or use client-owned runtime adapters or "
        "deliberately public leaf APIs before native ownership changes."
    )
    assert row[5] == "Native WooPayments owner decision (Task 3.6)"
    assert row[6] == "2026-07-13"
    assert row[7] == (
        "Owner-approved leaf principle: Core does not define the legacy global "
        "object/service-locator and mutable-map accessor surface; scalar/metadata getters "
        "are excluded, and the file's underlying extension responsibilities remain "
        "represented by the named Core owners."
    )
