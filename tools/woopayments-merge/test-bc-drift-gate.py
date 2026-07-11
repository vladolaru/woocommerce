from __future__ import annotations

import os
import shutil
import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/bc-drift-gate.sh"


def run(*args: str, cwd: Path, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        list(args),
        cwd=cwd,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def make_pinned_source(tmp_path: Path) -> tuple[Path, Path, dict[str, str]]:
    source = tmp_path / "woocommerce-payments"
    source.mkdir()
    for directory in (
        "includes/payment-methods/Configs/Definitions",
        "includes/subscriptions",
        "includes/core/server/request",
        "includes/constants",
        "src",
        "client",
    ):
        (source / directory).mkdir(parents=True, exist_ok=True)
    for relative in (
        "woocommerce-payments.php",
        "includes/class-wc-payments.php",
        "includes/class-wc-payments-order-service.php",
        "includes/class-database-cache.php",
        "includes/class-wc-payments-localization-service.php",
        "includes/constants/class-track-events.php",
    ):
        (source / relative).write_text("<?php\n", encoding="utf-8")

    run("git", "init", "-q", cwd=source)
    run("git", "config", "user.email", "oracle@example.test", cwd=source)
    run("git", "config", "user.name", "Oracle Fixture", cwd=source)
    run("git", "add", ".", cwd=source)
    run("git", "commit", "-qm", "WooPayments 10.8 fixture", cwd=source)
    run("git", "tag", "10.8.0", cwd=source)

    harness = tmp_path / "harness"
    harness.mkdir()
    gate = harness / "bc-drift-gate.sh"
    shutil.copy2(GATE, gate)
    env = os.environ.copy()
    env.update(
        {
            "WCPAY_SRC": str(source),
            "WCPAY_SOURCE_REF": "10.8.0",
        }
    )
    return source, gate, env


def test_update_records_source_ref_and_commit_provenance(tmp_path: Path) -> None:
    source, gate, env = make_pinned_source(tmp_path)
    expected_commit = run("git", "rev-parse", "10.8.0^{commit}", cwd=source).stdout.strip()

    update = run("bash", str(gate), "--update", cwd=gate.parent, env=env)
    check = run("bash", str(gate), cwd=gate.parent, env=env)

    assert update.returncode == 0, update.stdout
    assert check.returncode == 0, check.stdout
    assert (gate.parent / "bc-drift-baseline/source-provenance.txt").read_text(encoding="utf-8") == (
        f"source_ref=10.8.0\nsource_commit={expected_commit}\n"
    )


def test_check_rejects_source_checked_out_after_pinned_ref(tmp_path: Path) -> None:
    source, gate, env = make_pinned_source(tmp_path)
    assert run("bash", str(gate), "--update", cwd=gate.parent, env=env).returncode == 0
    (source / "includes/class-wc-payments.php").write_text("<?php\n// Later source.\n", encoding="utf-8")
    run("git", "add", ".", cwd=source)
    run("git", "commit", "-qm", "Later source", cwd=source)

    result = run("bash", str(gate), cwd=gate.parent, env=env)

    assert result.returncode != 0
    assert "must be checked out at WooPayments ref 10.8.0" in result.stdout


def test_check_rejects_dirty_pinned_source(tmp_path: Path) -> None:
    source, gate, env = make_pinned_source(tmp_path)
    assert run("bash", str(gate), "--update", cwd=gate.parent, env=env).returncode == 0
    (source / "includes/class-wc-payments.php").write_text("<?php\n// Dirty source.\n", encoding="utf-8")

    result = run("bash", str(gate), cwd=gate.parent, env=env)

    assert result.returncode != 0
    assert "WooPayments source must be clean at ref 10.8.0" in result.stdout
