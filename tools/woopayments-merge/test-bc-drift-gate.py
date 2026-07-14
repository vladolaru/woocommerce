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


# One representative line per probe category, so a healthy fixture yields a non-empty
# baseline for every category (the gate refuses --update on empty probe output).
CATEGORY_FIXTURE_SOURCES = {
    "woocommerce-payments.php": "<?php\n",
    "includes/class-wc-payments.php": (
        "<?php\n"
        "class WC_Payments {\n"
        "\tpublic static function get_gateway() {}\n"
        "}\n"
        "as_schedule_single_action( time(), array(), 'wcpay_fixture_job' );\n"
        "update_option( 'wcpay_fixture_option', 1 );\n"
        "register_rest_route( 'wc/v3', '/payments/fixture' );\n"
        "do_action( 'wcpay_fixture_hook' );\n"
    ),
    "includes/class-wc-payments-order-service.php": "<?php\n",
    "includes/class-database-cache.php": "<?php\n",
    "includes/class-wc-payments-localization-service.php": "<?php\n",
    "includes/constants/class-track-events.php": (
        "<?php\nclass Track_Events {\n\tconst FIXTURE_EVENT = 'wcpay_fixture_event';\n}\n"
    ),
}


def make_pinned_source(
    tmp_path: Path, sources: dict[str, str] | None = None
) -> tuple[Path, Path, dict[str, str]]:
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
    for relative, body in (sources or CATEGORY_FIXTURE_SOURCES).items():
        (source / relative).write_text(body, encoding="utf-8")

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


def test_update_writes_nonempty_baseline_for_every_category(tmp_path: Path) -> None:
    _source, gate, env = make_pinned_source(tmp_path)

    update = run("bash", str(gate), "--update", cwd=gate.parent, env=env)

    assert update.returncode == 0, update.stdout
    baseline = gate.parent / "bc-drift-baseline"
    for category in ("scheduler", "php_api", "persisted_data", "endpoints", "hooks_filters", "tracks"):
        content = (baseline / f"{category}.txt").read_text(encoding="utf-8").strip()
        assert content, f"category {category} baked an empty baseline"


def test_update_refuses_empty_probe_output_and_writes_nothing(tmp_path: Path) -> None:
    # A tracks source file with no matchable lines (probe drift / renamed source) must
    # refuse the whole update rather than bake a permanently vacuous baseline.
    sources = dict(CATEGORY_FIXTURE_SOURCES)
    sources["includes/constants/class-track-events.php"] = "<?php\n"
    _source, gate, env = make_pinned_source(tmp_path, sources=sources)

    update = run("bash", str(gate), "--update", cwd=gate.parent, env=env)

    assert update.returncode == 2, update.stdout
    assert "tracks" in update.stdout
    assert "refusing --update" in update.stdout
    assert not (gate.parent / "bc-drift-baseline").exists(), "refused update must write nothing"


def test_update_refusal_does_not_clobber_existing_baseline(tmp_path: Path) -> None:
    source, gate, env = make_pinned_source(tmp_path)
    assert run("bash", str(gate), "--update", cwd=gate.parent, env=env).returncode == 0
    before = (gate.parent / "bc-drift-baseline/tracks.txt").read_text(encoding="utf-8")

    # Re-pin a new tag whose tracks probe yields nothing; the refused update must leave
    # the previously-committed baseline (including provenance) untouched.
    (source / "includes/constants/class-track-events.php").write_text("<?php\n", encoding="utf-8")
    run("git", "add", ".", cwd=source)
    run("git", "commit", "-qm", "drop tracks constants", cwd=source)
    run("git", "tag", "10.8.1", cwd=source)
    env = dict(env)
    env["WCPAY_SOURCE_REF"] = "10.8.1"
    provenance_before = (gate.parent / "bc-drift-baseline/source-provenance.txt").read_text(encoding="utf-8")

    update = run("bash", str(gate), "--update", cwd=gate.parent, env=env)

    assert update.returncode == 2, update.stdout
    assert (gate.parent / "bc-drift-baseline/tracks.txt").read_text(encoding="utf-8") == before
    assert (
        gate.parent / "bc-drift-baseline/source-provenance.txt"
    ).read_text(encoding="utf-8") == provenance_before


def test_check_rejects_dirty_pinned_source(tmp_path: Path) -> None:
    source, gate, env = make_pinned_source(tmp_path)
    assert run("bash", str(gate), "--update", cwd=gate.parent, env=env).returncode == 0
    (source / "includes/class-wc-payments.php").write_text("<?php\n// Dirty source.\n", encoding="utf-8")

    result = run("bash", str(gate), cwd=gate.parent, env=env)

    assert result.returncode != 0
    assert "WooPayments source must be clean at ref 10.8.0" in result.stdout
