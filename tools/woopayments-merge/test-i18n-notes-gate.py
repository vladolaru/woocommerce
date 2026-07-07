#!/usr/bin/env python3
"""Focused regression checks for the i18n order-notes gate harness."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/i18n-notes-gate.sh"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def run_gate(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_state(path: Path, *, locale: str = "de_DE", orders: list[dict] | None = None) -> None:
    if orders is None:
        orders = localized_orders()

    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_i18n_notes_capture.v1",
                "locale": locale,
                "orders": orders,
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


def localized_orders() -> list[dict]:
    return [
        {
            "flow": "charge",
            "order_id": 101,
            "notes": [
                "Zahlung abgeschlossen.",
                "<strong>Gebuehrendetails:</strong><div><p>Gebuehr (2.9% + $0.30): $1.75 USD</p><p>Nettoauszahlung: $48.25 USD</p></div>",
            ],
        },
        {
            "flow": "refund",
            "order_id": 101,
            "notes": [
                "Rueckerstattung erstellt.",
            ],
        },
        {
            "flow": "dispute",
            "order_id": 202,
            "notes": [
                "Zahlungsanfrage wurde erstellt.",
                "Zahlungsstreitigkeit wurde aktualisiert.",
            ],
        },
    ]


def test_usage_requires_target_or_snapshot_state() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--state" in result.stderr


def test_print_plan_describes_live_i18n_probe() -> None:
    result = run_gate("--target", TARGET_WP, "--print-plan")

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_i18n_notes_gate_plan.v1"
    assert payload["target_wp"] == TARGET_WP
    assert payload["locale"] == "de_DE"
    assert payload["required_flows"] == ["charge", "refund", "dispute"]
    assert "Payment complete." in payload["english_sentinels"]


def test_gate_passes_localized_snapshot() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        out_dir = tmp_path / "evidence"
        write_state(state)

        result = run_gate("--state", str(state), "--out-dir", str(out_dir))

        assert result.returncode == 0, result.stderr
        assert "PASS: native WooPayments order notes are localized and deduplicated." in result.stdout

        rollup = json.loads((out_dir / "i18n-notes-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["failures"] == []


def test_gate_fails_when_english_sentinel_is_present() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        out_dir = tmp_path / "evidence"
        orders = localized_orders()
        orders[0]["notes"].append("Payment complete.")
        write_state(state, orders=orders)

        result = run_gate("--state", str(state), "--out-dir", str(out_dir))

        assert result.returncode == 1
        assert "english sentinel found" in result.stderr

        rollup = json.loads((out_dir / "i18n-notes-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "Payment complete." in "\n".join(rollup["failures"])


def test_gate_fails_when_note_body_is_duplicated() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        orders = localized_orders()
        orders[2]["notes"].append("Zahlungsanfrage wurde erstellt.")
        write_state(state, orders=orders)

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "duplicate order note" in result.stderr


def test_gate_fails_when_required_flow_has_no_notes() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        orders = [order for order in localized_orders() if order["flow"] != "dispute"]
        write_state(state, orders=orders)

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "missing required flow notes: dispute" in result.stderr


def test_gate_fails_when_locale_was_not_switched() -> None:
    with tempfile.TemporaryDirectory(prefix="i18n-notes-gate-test-") as tmp:
        tmp_path = Path(tmp)
        state = tmp_path / "state.json"
        write_state(state, locale="en_US")

        result = run_gate("--state", str(state))

        assert result.returncode == 1
        assert "captured locale en_US does not match expected de_DE" in result.stderr


def test_verify_runs_i18n_notes_gate_for_cross_store_mode() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "i18n-notes-gate.sh" in verify_source
