from __future__ import annotations

import subprocess
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/tracks-parity.sh"


def run_diff(a: Path, b: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(GATE), "diff", str(a), str(b)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def test_identical_nonempty_captures_pass(tmp_path: Path) -> None:
    a = tmp_path / "a.txt"
    b = tmp_path / "b.txt"
    a.write_text("wcpay_checkout | amount=str:<n>\n", encoding="utf-8")
    b.write_text("wcpay_checkout | amount=str:<n>\n", encoding="utf-8")

    result = run_diff(a, b)

    assert result.returncode == 0, result.stdout
    assert "PASS: zero Tracks contract drift (1 events)." in result.stdout


def test_differing_captures_fail(tmp_path: Path) -> None:
    a = tmp_path / "a.txt"
    b = tmp_path / "b.txt"
    a.write_text("wcpay_checkout | amount=str:<n>\n", encoding="utf-8")
    b.write_text("wcpay_checkout | amount=str:<n> currency=usd\n", encoding="utf-8")

    result = run_diff(a, b)

    assert result.returncode == 1, result.stdout
    assert "FAIL: Tracks contract drift" in result.stdout


def test_two_empty_captures_are_blocked_not_pass(tmp_path: Path) -> None:
    a = tmp_path / "a.txt"
    b = tmp_path / "b.txt"
    a.write_text("", encoding="utf-8")
    b.write_text("", encoding="utf-8")

    result = run_diff(a, b)

    assert result.returncode == 3, result.stdout
    assert "PASS" not in result.stdout
    assert "BLOCKED" in result.stdout


def test_one_empty_capture_is_blocked(tmp_path: Path) -> None:
    a = tmp_path / "a.txt"
    b = tmp_path / "b.txt"
    a.write_text("wcpay_checkout | amount=str:<n>\n", encoding="utf-8")
    b.write_text("", encoding="utf-8")

    result = run_diff(a, b)

    assert result.returncode == 3, result.stdout
    assert "PASS" not in result.stdout
    assert str(b) in result.stdout
