#!/usr/bin/env python3
"""Local-only runner enforcement regressions for the dispute e2e gate."""

from __future__ import annotations

import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/dispute-e2e-gate.sh"


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def test_remote_wp_runners_are_rejected_before_any_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="dispute-e2e-gate-") as tmp:
        tmp_path = Path(tmp)
        invocation_log = tmp_path / "invocations.log"
        bin_dir = tmp_path / "bin"
        # A logging Stripe CLI stand-in on PATH proves the gate refused BEFORE its
        # raw-source preflight (the first external call after runner validation).
        write_executable(
            bin_dir / "stripe",
            "#!/usr/bin/env bash\n"
            'printf \'stripe|%s\\n\' "$*" >> "$INVOCATION_LOG"\n'
            "printf '{\"data\":[]}\\n'\n",
        )

        result = subprocess.run(
            [
                "bash",
                str(GATE),
                "--ref",
                "wp --ssh=user@remote.example",
                "--target",
                "wp --http=remote.example",
            ],
            cwd=REPO,
            env={
                **os.environ,
                "PATH": f"{bin_dir}:{os.environ.get('PATH', '')}",
                "INVOCATION_LOG": str(invocation_log),
            },
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr
        assert not invocation_log.exists()


def test_usage_still_reports_required_arguments_without_runners() -> None:
    result = subprocess.run(
        ["bash", str(GATE)],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 2
    assert "--ref" in result.stderr
    assert "--target" in result.stderr
