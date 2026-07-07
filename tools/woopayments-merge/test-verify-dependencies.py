#!/usr/bin/env python3
"""Regression checks for verify.sh tracked helper dependencies."""

from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]

VERIFY_DEPENDENCIES = (
    "tools/woopayments-merge/bc-drift-gate.sh",
    "tools/woopayments-merge/bc-drift-baseline/endpoints.txt",
    "tools/woopayments-merge/bc-drift-baseline/hooks_filters.txt",
    "tools/woopayments-merge/bc-drift-baseline/persisted_data.txt",
    "tools/woopayments-merge/bc-drift-baseline/php_api.txt",
    "tools/woopayments-merge/bc-drift-baseline/scheduler.txt",
    "tools/woopayments-merge/bc-drift-baseline/tracks.txt",
    "tools/woopayments-merge/parity-diff.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.sh",
    "tools/woopayments-merge/dump-bucket-e-surface.php",
    "tools/woopayments-merge/normalize-bucket-e-cross.py",
    "tools/woopayments-merge/perf-baseline.sh",
    "tools/woopayments-merge/perf-baseline.php",
    "tools/woopayments-merge/perf-baseline.json",
    "tools/woopayments-merge/tracks-parity.sh",
    "tools/woopayments-merge/tracks-normalize.py",
)


def is_tracked(path: str) -> bool:
    return (
        subprocess.run(
            ["git", "ls-files", "--error-unmatch", path],
            cwd=REPO,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        ).returncode
        == 0
    )


def test_verify_base_dependencies_are_tracked() -> None:
    missing = [path for path in VERIFY_DEPENDENCIES if not is_tracked(path)]

    assert missing == []


def main() -> None:
    test_verify_base_dependencies_are_tracked()
    print("PASS test_verify_base_dependencies_are_tracked")


if __name__ == "__main__":
    main()
