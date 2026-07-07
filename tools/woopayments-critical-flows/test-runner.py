#!/usr/bin/env python3
"""Regression checks for the critical-flows runner fail-closed contract."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
RUNNER = REPO / "tools/woopayments-critical-flows/run.sh"


def run_runner(*args: str, evidence_dir: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(RUNNER), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "EVIDENCE_DIR": str(evidence_dir)},
        check=False,
    )


def test_unwired_deterministic_flow_blocks_and_writes_rollup() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "SC-01-card-checkout" in result.stdout
        assert "BLOCKED" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0
        assert rollup["results"] == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
            }
        ]


def main() -> None:
    test_unwired_deterministic_flow_blocks_and_writes_rollup()
    print("PASS test_unwired_deterministic_flow_blocks_and_writes_rollup")


if __name__ == "__main__":
    main()
