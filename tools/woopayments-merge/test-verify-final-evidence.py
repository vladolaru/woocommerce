#!/usr/bin/env python3
"""Regression checks for verify.sh full-evidence orchestration."""

from __future__ import annotations

import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
ALL_LPM_METHODS = "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"


def run_verify(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def test_full_evidence_plan_lists_final_gates() -> None:
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    assert "verify.sh --self-check" in result.stdout
    assert "verify.sh --ref" in result.stdout
    assert "--with-tracks" in result.stdout
    assert "lpm-checkout-gate.sh" in result.stdout
    assert ALL_LPM_METHODS in result.stdout
    assert "plugin-active-settings-gate.sh" in result.stdout
    assert "mc-rates-gate.sh" in result.stdout
    assert "i18n-notes-gate.sh" in result.stdout
    assert "rest-route-parity.sh" in result.stdout
    assert "hook-shape-parity.sh" in result.stdout
    assert "subsystem-disposition-gate.sh" in result.stdout
    assert "subscriptions-renewal-gate.sh preflight" in result.stdout
    assert "token-continuity-gate.sh" in result.stdout
    assert "a5f-cutover-rehearsal.py" in result.stdout
    assert "a5g-multisite-runtime-gate.py" in result.stdout
    assert "dispute-e2e-gate.sh" in result.stdout
    assert "payout-evidence-gate.sh" in result.stdout
    assert "converted-currency-gate.sh" in result.stdout
    assert "bundle-size-gate.sh capture" in result.stdout
    assert "bundle-size-gate.sh compare" in result.stdout
    assert "perf-surface-gate.sh capture" in result.stdout
    assert "perf-surface-gate.sh compare" in result.stdout
    assert "woopayments-critical-flows/test-inventory.py" in result.stdout
    assert "woopayments-critical-flows/run.sh --store both --layer all" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce test:php:env" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library test:js" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library ts:check" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce phpstan" in result.stdout


def test_full_evidence_executes_nested_self_check_and_tracks_verifier() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-final-evidence-") as tmp:
        repo = Path(tmp) / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        wcpay_repo = Path(tmp) / "woocommerce-payments"
        merge_dir.mkdir(parents=True)
        critical_dir.mkdir(parents=True)
        wcpay_repo.mkdir()

        invocations = Path(tmp) / "invocations.log"
        fake_wp = Path(tmp) / "fake-wp"
        fake_bin = Path(tmp) / "bin"
        fake_tmp = Path(tmp) / "tmp"
        fake_bin.mkdir()
        fake_tmp.mkdir()

        verify_copy = merge_dir / "verify.sh"
        verify_copy.write_text(SCRIPT.read_text(encoding="utf-8"), encoding="utf-8")
        verify_copy.chmod(0o755)
        (merge_dir / "a4aq-bundle-budget.json").write_text("{}\n", encoding="utf-8")
        (wcpay_repo / "woocommerce-payments.php").write_text("<?php\n", encoding="utf-8")

        fake_gate = """#!/usr/bin/env bash
set -eu
name="$(basename "$0")"
printf '%s|%s\n' "$name" "$*" >> "$INVOCATIONS_LOG"
out=""
previous=""
for arg in "$@"; do
	if [ "$previous" = "--out" ]; then
		out="$arg"
		previous=""
		continue
	fi
	previous="$arg"
done
if [ -n "$out" ]; then
	mkdir -p "$(dirname "$out")"
	printf '{}\n' > "$out"
fi
if [ "$name" = "flow-drive.sh" ]; then
	printf '{"order_id":123}\n'
fi
if [ "$name" = "tracks-parity.sh" ] && [ "${1:-}" = "normalize" ]; then
	printf '{"event":"checkout"}\n'
fi
exit 0
"""
        for script_name in (
            "bc-drift-gate.sh",
            "subsystem-disposition-gate.sh",
            "hook-shape-parity.sh",
            "rest-route-parity.sh",
            "i18n-notes-gate.sh",
            "flow-drive.sh",
            "parity-diff.sh",
            "perf-baseline.sh",
            "financial-reconcile.sh",
            "tracks-parity.sh",
            "subscriptions-renewal-gate.sh",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "token-continuity-gate.sh",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh",
            "converted-currency-gate.sh",
            "bundle-size-gate.sh",
            "perf-surface-gate.sh",
        ):
            write_executable(merge_dir / script_name, fake_gate)
        fake_python_gate = """#!/usr/bin/env python3
import os
import sys
from pathlib import Path

Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write(
    Path(sys.argv[0]).name + "|" + " ".join(sys.argv[1:]) + "\\n"
)
"""
        for script_name in (
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
        ):
            write_executable(merge_dir / script_name, fake_python_gate)

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
	printf 'ready\n'
else
	printf '{}\n'
fi
""",
        )
        write_executable(
            fake_bin / "pnpm",
            """#!/usr/bin/env bash
set -eu
printf 'pnpm|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "test-inventory.py",
            """#!/usr/bin/env python3
import os
from pathlib import Path
Path(os.environ["INVOCATIONS_LOG"]).open("a", encoding="utf-8").write("critical-inventory|\\n")
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|%s\n' "$*" >> "$INVOCATIONS_LOG"
""",
        )

        result = subprocess.run(
            [
                "bash",
                str(verify_copy),
                "--ref",
                str(fake_wp),
                "--target",
                str(fake_wp),
                "--full-evidence",
                "--playwriter-session",
                "session-1",
                "--token-customer-id",
                "cus_test",
                "--token-subscription-id",
                "sub_test",
            ],
            cwd=repo,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env={
                "INVOCATIONS_LOG": str(invocations),
                "TMPDIR": str(fake_tmp),
                "WCPAY_REPO": str(wcpay_repo),
                "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
            },
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr

        invocation_log = invocations.read_text(encoding="utf-8")
        assert "tracks-parity.sh|reset" in invocation_log
        assert "tracks-parity.sh|diff" in invocation_log
        assert "critical-run|--store both --layer all" in invocation_log
        assert invocation_log.count("flow-drive.sh|charge") >= 5
        assert invocation_log.count("rest-route-parity.sh|") >= 4
        assert invocation_log.count("hook-shape-parity.sh|") >= 4
        assert invocation_log.count("subsystem-disposition-gate.sh|") >= 4
        assert invocation_log.count("i18n-notes-gate.sh|") >= 3
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library test:js" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library ts:check" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce lint:changes:branch" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce phpstan" in invocation_log


def test_full_evidence_flag_is_documented_in_usage() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--full-evidence" in result.stderr
    assert "--print-full-evidence-plan" in result.stderr


def main() -> None:
    tests = [
        test_full_evidence_plan_lists_final_gates,
        test_full_evidence_flag_is_documented_in_usage,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
