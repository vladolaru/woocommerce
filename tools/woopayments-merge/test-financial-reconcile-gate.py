from __future__ import annotations

import json
import os
import shutil
import stat
import subprocess
from pathlib import Path

from tools.woopayments_test_runner import adapt_single_wp_runner


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/financial-reconcile.sh"
COMPARATOR = REPO / "tools/woopayments-merge/financial-reconcile-normalize.py"
LOCAL_RUNNER_SAFETY = REPO / "tools/woopayments-merge/local-runner-safety.sh"

FAKE_STRIPE = """#!/usr/bin/env bash
# Test stand-in for the Stripe CLI: serves canned raw-source JSON from $FR_FIXTURE_DIR.
set -euo pipefail
args="$*"
case "$args" in
    "charges list --limit 1 --color off"*)
        printf '{"data":[]}\\n' ;;
    "charges retrieve "*)
        charge_id="$3"
        if [ -f "$FR_FIXTURE_DIR/charge-$charge_id.json" ]; then
            cat "$FR_FIXTURE_DIR/charge-$charge_id.json"
        else
            echo "No such charge: '$charge_id'" >&2
            exit 1
        fi ;;
    "payment_intents retrieve "*)
        cat "$FR_FIXTURE_DIR/intent.json" ;;
    "balance_transactions retrieve "*)
        cat "$FR_FIXTURE_DIR/balance.json" ;;
    "refunds list "*)
        cat "$FR_FIXTURE_DIR/refunds.json" ;;
    "disputes list "*)
        cat "$FR_FIXTURE_DIR/disputes.json" ;;
    "payouts retrieve "*)
        cat "$FR_FIXTURE_DIR/payout.json" ;;
    *)
        echo "fake stripe: unhandled invocation: $args" >&2
        exit 1 ;;
esac
"""

FAKE_WP = """#!/usr/bin/env bash
# Test stand-in for the WP runner: answers the connected-account eval and serves
# canned WC-side money state per order id from $FR_FIXTURE_DIR/wc-<id>.json.
set -euo pipefail
if [ "${1:-}" = "eval" ]; then
    echo "acct_test123"
    exit 0
fi
if [ "${1:-}" = "eval-file" ]; then
    cat > /dev/null  # consume the PHP driver on stdin
    order_id="${3:-}"
    if [ -f "$FR_FIXTURE_DIR/wc-$order_id.json" ]; then
        cat "$FR_FIXTURE_DIR/wc-$order_id.json"
    else
        printf '{"error":"not_found"}\\n'
    fi
    exit 0
fi
echo "fake wp: unhandled invocation: $*" >&2
exit 1
"""

WC_RECONCILABLE = {
    "order_id": 101,
    "status": "processing",
    "total": "50.00",
    "currency": "USD",
    "store_currency": "USD",
    "transaction_id": "pi_123",
    "charge_id": "ch_123",
    "intent_id": "pi_123",
    "payment_transaction_id": "txn_123",
    "transaction_fee": "1.75",
    "net": "48.25",
    "intent_currency": "usd",
    "multi_currency": {
        "stripe_exchange_rate": "1.2345",
        "order_exchange_rate": "1.2345",
        "order_default_currency": "USD",
    },
    "refunds": [
        {
            "id": 201,
            "amount": "12.00",
            "currency": "USD",
            "provider_refund_id": "re_123",
        }
    ],
    "dispute_id": "dp_123",
    "payout_id": "po_123",
}

CHARGE = {
    "id": "ch_123",
    "amount": 5000,
    "amount_captured": 5000,
    "amount_refunded": 1200,
    "currency": "usd",
    "captured": True,
    "livemode": False,
    "payment_intent": "pi_123",
    "balance_transaction": "txn_123",
    "refunds": {"data": [{"id": "re_123", "amount": 1200, "currency": "usd"}]},
    "dispute": "dp_123",
}

INTENT = {
    "id": "pi_123",
    "amount": 5000,
    "amount_capturable": 0,
    "amount_received": 5000,
    "currency": "usd",
    "status": "succeeded",
    "latest_charge": "ch_123",
}

BALANCE = {
    "id": "txn_123",
    "amount": 5000,
    "fee": 175,
    "net": 4825,
    "currency": "usd",
    "exchange_rate": 1.2345,
    "payout": "po_123",
}

REFUNDS = {"data": [{"id": "re_123", "amount": 1200, "currency": "usd", "charge": "ch_123"}]}

DISPUTES = {
    "data": [
        {
            "id": "dp_123",
            "charge": "ch_123",
            "amount": 5000,
            "currency": "usd",
            "reason": "fraudulent",
            "status": "needs_response",
        }
    ]
}

PAYOUT = {"id": "po_123", "amount": 4825, "currency": "usd", "status": "paid"}


def make_harness(tmp_path: Path) -> tuple[Path, Path, dict[str, str]]:
    harness = tmp_path / "harness"
    harness.mkdir()
    gate = harness / "financial-reconcile.sh"
    shutil.copy2(GATE, gate)
    shutil.copy2(COMPARATOR, harness / "financial-reconcile-normalize.py")
    shutil.copy2(LOCAL_RUNNER_SAFETY, harness / "local-runner-safety.sh")

    bin_dir = tmp_path / "bin"
    bin_dir.mkdir()
    for name, body in (("stripe", FAKE_STRIPE), ("wp", FAKE_WP)):
        executable = bin_dir / name
        executable.write_text(body, encoding="utf-8")
        executable.chmod(executable.stat().st_mode | stat.S_IXUSR)

    fixtures = tmp_path / "fixtures"
    fixtures.mkdir()
    (fixtures / "charge-ch_123.json").write_text(json.dumps(CHARGE), encoding="utf-8")
    (fixtures / "intent.json").write_text(json.dumps(INTENT), encoding="utf-8")
    (fixtures / "balance.json").write_text(json.dumps(BALANCE), encoding="utf-8")
    (fixtures / "refunds.json").write_text(json.dumps(REFUNDS), encoding="utf-8")
    (fixtures / "disputes.json").write_text(json.dumps(DISPUTES), encoding="utf-8")
    (fixtures / "payout.json").write_text(json.dumps(PAYOUT), encoding="utf-8")

    env = os.environ.copy()
    env["PATH"] = f"{bin_dir}:{env['PATH']}"
    env["FR_FIXTURE_DIR"] = str(fixtures)
    runner, env = adapt_single_wp_runner(str(bin_dir / "wp"), env)
    env["WP"] = runner
    return gate, fixtures, env


def write_wc_fixture(fixtures: Path, order_id: int, **overrides: object) -> None:
    record = dict(WC_RECONCILABLE)
    record["order_id"] = order_id
    record.update(overrides)
    (fixtures / f"wc-{order_id}.json").write_text(json.dumps(record), encoding="utf-8")


def run_gate(gate: Path, env: dict[str, str], *ids: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(gate), *ids],
        cwd=gate.parent,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def test_reconcilable_order_passes_and_reports_count(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101)

    result = run_gate(gate, env, "101")

    assert result.returncode == 0, result.stdout
    assert "PASS" in result.stdout
    assert "1 reconciled order(s)" in result.stdout


def test_missing_order_is_blocked_not_pass(tmp_path: Path) -> None:
    gate, _fixtures, env = make_harness(tmp_path)

    result = run_gate(gate, env, "999999")

    assert result.returncode == 3, result.stdout
    assert "PASS" not in result.stdout
    assert "999999" in result.stdout


def test_order_without_charge_id_is_blocked_not_pass(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 42, charge_id="", transaction_id="")

    result = run_gate(gate, env, "42")

    assert result.returncode == 3, result.stdout
    assert "PASS" not in result.stdout


def test_mixed_reconciled_and_skipped_is_blocked(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101)
    write_wc_fixture(fixtures, 42, charge_id="", transaction_id="")

    result = run_gate(gate, env, "101", "42")

    assert result.returncode == 3, result.stdout
    # Order 101's own verification may print, but the gate-level summary must not be a PASS.
    assert "PASS: WC-side money records match" not in result.stdout
    assert "BLOCKED: reconciled 1 order(s)" in result.stdout
    assert "widened money matrix matches provider. ok" in result.stdout  # 101 was verified
    assert "42" in result.stdout


def test_provider_divergence_fails(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101)
    diverged = dict(CHARGE)
    diverged["amount"] = 4900
    (fixtures / "charge-ch_123.json").write_text(json.dumps(diverged), encoding="utf-8")

    result = run_gate(gate, env, "101")

    assert result.returncode == 1, result.stdout
    assert "FAIL" in result.stdout


def test_charge_missing_at_provider_fails(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101, charge_id="ch_unknown", transaction_id="ch_unknown")

    result = run_gate(gate, env, "101")

    assert result.returncode == 1, result.stdout
    assert "not found at provider" in result.stdout


def test_remote_wp_runner_is_rejected_before_any_store_read(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101)
    env["WP"] = "wp --ssh=user@remote.example"

    result = run_gate(gate, env, "101")

    assert result.returncode == 2, result.stdout
    assert "unsafe WP-CLI command" in result.stdout
    assert "--ssh" in result.stdout
    # The gate must refuse before its Stripe raw-source preflight or any store read.
    assert "Preflight:" not in result.stdout
    assert "PASS" not in result.stdout


def test_live_mode_charge_blocks_reconciliation(tmp_path: Path) -> None:
    gate, fixtures, env = make_harness(tmp_path)
    write_wc_fixture(fixtures, 101)
    live_charge = dict(CHARGE)
    live_charge["livemode"] = True
    (fixtures / "charge-ch_123.json").write_text(json.dumps(live_charge), encoding="utf-8")

    result = run_gate(gate, env, "101")

    assert result.returncode == 3, result.stdout
    assert "refusing to operate on live-mode money" in result.stdout
    assert "PASS" not in result.stdout
