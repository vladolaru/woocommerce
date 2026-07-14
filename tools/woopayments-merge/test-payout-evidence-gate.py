"""Regression checks for the payout evidence gate."""

from __future__ import annotations

import shutil
import os
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_single_wp_runner


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools" / "woopayments-merge" / "payout-evidence-gate.sh"
LOCAL_RUNNER_SAFETY = REPO / "tools" / "woopayments-merge" / "local-runner-safety.sh"


def write_executable(path: Path, contents: str) -> None:
    path.write_text(contents, encoding="utf-8")
    path.chmod(0o755)


def test_payout_gate_creates_available_balance_payout_for_membership_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="payout-evidence-gate-") as tmp:
        work_dir = Path(tmp)
        gate_dir = work_dir / "gate"
        bin_dir = work_dir / "bin"
        gate_dir.mkdir()
        bin_dir.mkdir()

        shutil.copy2(GATE, gate_dir / "payout-evidence-gate.sh")
        (gate_dir / "payout-evidence-gate.sh").chmod(0o755)
        shutil.copy2(LOCAL_RUNNER_SAFETY, gate_dir / "local-runner-safety.sh")

        invocation_log = work_dir / "invocations.log"
        write_executable(
            gate_dir / "flow-drive.sh",
            """#!/usr/bin/env bash
printf '{"order_id":123,"charge_id":"ch_gate"}\\n'
""",
        )
        write_executable(
            gate_dir / "financial-reconcile.sh",
            """#!/usr/bin/env bash
exit 0
""",
        )
        write_executable(
            bin_dir / "stripe",
            """#!/usr/bin/env bash
printf 'stripe|%s\\n' "$*" >> "$INVOCATION_LOG"
if [ "$1" = "charges" ] && [ "$2" = "list" ]; then
  printf '{"data":[]}\\n'
  exit 0
fi
if [ "$1" = "payouts" ] && [ "$2" = "retrieve" ]; then
  printf '{"id":"po_gate"}\\n'
  exit 0
fi
if [ "$1" = "balance_transactions" ] && [ "$2" = "list" ]; then
  printf '{"data":[{"source":"ch_gate"}]}\\n'
  exit 0
fi
printf 'unexpected stripe call: %s\\n' "$*" >&2
exit 2
""",
        )
        fake_wp = work_dir / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
printf 'wp|%s\\n' "$*" >> "$INVOCATION_LOG"
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"get_stripe_account_id"* ]] || [[ "$2" == *"get_account_id"* ]]; then
    printf 'acct_gate\\n'
    exit 0
  fi
  if [[ "$2" == *"_wcpay_transaction_fee"* ]]; then
    printf '{"missing":[],"meta":{"_charge_id":"ch_gate","_wcpay_payment_transaction_id":"txn_gate","_wcpay_transaction_fee":"175","_wcpay_net":"4825"}}\\n'
    exit 0
  fi
fi
if [ "$1" = "wcpay-dev" ] && [ "$2" = "test-lab" ] && [ "$3" = "payouts" ]; then
  printf '{"results":[{"success":true,"payout_id":"po_gate"}],"summary":{"succeeded":1}}\\n'
  exit 0
fi
printf 'unexpected wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
        env.update(
            {
                "PATH": f"{bin_dir}:{work_dir}:{os.environ.get('PATH', '')}",
                "INVOCATION_LOG": str(invocation_log),
            }
        )
        result = subprocess.run(
            [
                "bash",
                str(gate_dir / "payout-evidence-gate.sh"),
                "--wp",
                runner,
                "--label",
                "test",
            ],
            cwd=REPO,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        invocations = invocation_log.read_text(encoding="utf-8")
        assert "wp|wcpay-dev test-lab payouts --count=1 --format=json" in invocations
        assert "--amount=1" not in invocations


def test_payout_gate_rejects_remote_wp_runner_before_any_invocation() -> None:
    with tempfile.TemporaryDirectory(prefix="payout-evidence-gate-") as tmp:
        work_dir = Path(tmp)
        gate_dir = work_dir / "gate"
        gate_dir.mkdir()
        shutil.copy2(GATE, gate_dir / "payout-evidence-gate.sh")
        (gate_dir / "payout-evidence-gate.sh").chmod(0o755)
        shutil.copy2(LOCAL_RUNNER_SAFETY, gate_dir / "local-runner-safety.sh")
        invocation_log = work_dir / "invocations.log"

        result = subprocess.run(
            [
                "bash",
                str(gate_dir / "payout-evidence-gate.sh"),
                "--wp",
                "wp --ssh=user@remote.example",
                "--label",
                "test",
            ],
            cwd=REPO,
            env={**os.environ, "INVOCATION_LOG": str(invocation_log)},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 2, result.stdout + result.stderr
        assert "unsafe WP-CLI command" in result.stderr
        assert "--ssh" in result.stderr
        assert not invocation_log.exists()
