#!/usr/bin/env python3
"""Regression checks for verify.sh Tracks parity orchestration."""

from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"


def run_verify(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def test_tracks_flags_are_documented_without_placeholder_language() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--with-tracks" in result.stderr
    assert "--tracks-ref-store-id" in result.stderr
    assert "--tracks-target-store-id" in result.stderr
    assert "placeholder" not in result.stderr.lower()


def test_with_tracks_uses_sink_parity_commands() -> None:
    source = SCRIPT.read_text(encoding="utf-8")

    assert "tracks-parity.sh\" reset" in source
    assert "tracks-parity.sh\" normalize" in source
    assert "tracks-parity.sh\" diff" in source
    assert "TRACKS_REF_STORE_ID" in source
    assert "TRACKS_TARGET_STORE_ID" in source
    assert "tracks parity (run via HARNESS.md recipe)" not in source


def main() -> None:
    tests = [
        test_tracks_flags_are_documented_without_placeholder_language,
        test_with_tracks_uses_sink_parity_commands,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
