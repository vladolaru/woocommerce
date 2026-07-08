#!/usr/bin/env python3
"""Regression checks for verify.sh full-evidence orchestration."""

from __future__ import annotations

import importlib.util
import subprocess
import tempfile
from pathlib import Path
from types import SimpleNamespace


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/verify.sh"
A5G_SCRIPT = REPO / "tools/woopayments-merge/a5g-multisite-runtime-gate.py"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
ALL_LPM_METHODS = "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay,eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay"


def assert_occurs_in_order(haystack: str, markers: list[str]) -> None:
    position = -1
    for marker in markers:
        next_position = haystack.find(marker, position + 1)
        assert next_position != -1, f"{marker!r} was not found after offset {position}"
        position = next_position


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


def load_a5g_gate_module():
    spec = importlib.util.spec_from_file_location("a5g_multisite_runtime_gate", A5G_SCRIPT)
    assert spec is not None
    assert spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_full_evidence_plan_lists_final_gates() -> None:
    final_evidence_out = str(REPO / ".agents" / "tmp" / "woopayments-final-evidence-test")
    result = run_verify(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--tracks-ref-store-id",
        "ref-store",
        "--tracks-target-store-id",
        "target-store",
        "--full-evidence-out-dir",
        final_evidence_out,
        "--print-full-evidence-plan",
    )

    assert result.returncode == 0, result.stderr
    assert "verify.sh --self-check" in result.stdout
    assert "verify.sh --ref" in result.stdout
    assert "--with-tracks" in result.stdout
    assert '--tracks-ref-store-id "ref-store"' in result.stdout
    assert '--tracks-target-store-id "target-store"' in result.stdout
    assert f'TRACKS_OUT_DIR="{final_evidence_out}/tracks-parity" bash' in result.stdout
    assert "lpm-checkout-gate.sh" in result.stdout
    assert ALL_LPM_METHODS in result.stdout
    assert "plugin-active-settings-gate.sh" in result.stdout
    assert "--stage-plugin-active-fixture" in result.stdout
    assert "mc-rates-gate.sh" in result.stdout
    assert "i18n-notes-gate.sh" in result.stdout
    assert "rest-route-parity.sh" in result.stdout
    assert "hook-shape-parity.sh" in result.stdout
    assert "subsystem-disposition-gate.sh" in result.stdout
    assert "subscriptions-renewal-gate.sh compare" in result.stdout
    assert "--ref-subscription-id" in result.stdout
    assert "--target-subscription-id" in result.stdout
    assert "subscriptions-renewal-gate.sh preflight" not in result.stdout
    assert "token-continuity-gate.sh" in result.stdout
    assert "a5f-cutover-rehearsal.py" in result.stdout
    assert "a5g-multisite-runtime-gate.py" in result.stdout
    assert "dispute-e2e-gate.sh" in result.stdout
    assert "payout-evidence-gate.sh" in result.stdout
    assert "converted-currency-gate.sh" in result.stdout
    assert "a4aq-accumulated-gate.py" in result.stdout
    assert "--plugin-repo" in result.stdout
    assert "/a4aq-accumulated" in result.stdout
    assert "bundle-size-gate.sh capture" not in result.stdout
    assert "perf-surface-gate.sh capture --wp" not in result.stdout
    assert "woopayments-critical-flows/test-inventory.py" in result.stdout
    assert "woopayments-critical-flows/setup/fixtures.sh fixture_all ref" in result.stdout
    assert "woopayments-critical-flows/setup/fixtures.sh fixture_all target" in result.stdout
    assert "woopayments-critical-flows/run.sh --store both --layer all" in result.stdout
    assert "EVIDENCE_DIR=" in result.stdout
    assert "/critical-flows" in result.stdout
    assert "--agent-results-dir" in result.stdout
    assert "/critical-flows-agent-results" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce test:php:env" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library test:js" in result.stdout
    assert "pnpm --filter=@woocommerce/admin-library ts:check" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch" in result.stdout
    assert "pnpm --filter=@woocommerce/plugin-woocommerce phpstan" in result.stdout
    assert_occurs_in_order(
        result.stdout,
        [
            "verify.sh --self-check",
            "verify.sh --ref",
            "rest-route-parity.sh",
            "hook-shape-parity.sh",
            "subsystem-disposition-gate.sh",
            "i18n-notes-gate.sh",
            "subscriptions-renewal-gate.sh compare",
            "plugin-active-settings-gate.sh",
            "lpm-checkout-gate.sh",
            "mc-rates-gate.sh",
            "token-continuity-gate.sh",
            "a5f-cutover-rehearsal.py",
            "a5g-multisite-runtime-gate.py",
            "dispute-e2e-gate.sh",
            "payout-evidence-gate.sh --wp",
            "payout-evidence-gate.sh --wp",
            "converted-currency-gate.sh",
            "a4aq-accumulated-gate.py",
            "woopayments-critical-flows/test-inventory.py",
            "woopayments-critical-flows/setup/fixtures.sh fixture_all ref",
            "woopayments-critical-flows/setup/fixtures.sh fixture_all target",
            "woopayments-critical-flows/run.sh --store both --layer all",
            "pnpm --filter=@woocommerce/plugin-woocommerce test:php:env",
            "pnpm --filter=@woocommerce/admin-library test:js",
            "pnpm --filter=@woocommerce/admin-library ts:check",
            "pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch",
            "pnpm --filter=@woocommerce/plugin-woocommerce phpstan",
        ],
    )


def test_full_evidence_executes_nested_self_check_and_tracks_verifier() -> None:
    with tempfile.TemporaryDirectory(prefix="verify-final-evidence-") as tmp:
        repo = Path(tmp) / "repo"
        merge_dir = repo / "tools" / "woopayments-merge"
        critical_dir = repo / "tools" / "woopayments-critical-flows"
        critical_setup_dir = critical_dir / "setup"
        wcpay_repo = Path(tmp) / "woocommerce-payments"
        merge_dir.mkdir(parents=True)
        critical_dir.mkdir(parents=True)
        critical_setup_dir.mkdir()
        wcpay_repo.mkdir()

        invocations = Path(tmp) / "invocations.log"
        fake_wp = Path(tmp) / "fake-wp"
        fake_bin = Path(tmp) / "bin"
        fake_tmp = Path(tmp) / "tmp"
        fake_wpcom_home = Path(tmp) / "wpcom-local-home"
        fake_bin.mkdir()
        fake_tmp.mkdir()
        full_evidence_dir = Path(tmp) / "final-evidence"
        (fake_wpcom_home / "secrets").mkdir(parents=True)
        (fake_wpcom_home / "secrets" / "tracks.json").write_text(
            '{"sink_token":"wpcom_local_tracks_test"}\n',
            encoding="utf-8",
        )

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
            "a4aq-accumulated-gate.py",
        ):
            write_executable(merge_dir / script_name, fake_python_gate)

        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
set -eu
printf 'wp|%s\n' "$*" >> "$INVOCATIONS_LOG"
if [ "${1:-}" = "eval-file" ]; then
    body="$(cat)"
    if printf '%s' "$body" | grep -q "HELPER_OPTION:"; then
        printf 'HELPER_OPTION:enabled:MQ==\n'
        printf 'HELPER_OPTION:sink_endpoint:aHR0cDovL29sZA==\n'
        printf 'HELPER_OPTION:sink_token:ZXhpc3Rpbmc=\n'
        printf 'HELPER_OPTION:capture_browser:MQ==\n'
        printf 'HELPER_OPTION:capture_server:MQ==\n'
    elif printf '%s' "$body" | grep -q "HELPER_RESTORED"; then
        printf 'HELPER_RESTORED\n'
    elif printf '%s' "$body" | grep -q "TRACKING:"; then
        printf 'TRACKING:yes\n'
    elif printf '%s' "$body" | grep -q "TRACKING_SET:"; then
        printf 'TRACKING_SET:%s\n' "${3:-}"
    else
        printf 'ready\n'
    fi
elif [ "${1:-}" = "wpcom-local" ] && [ "${2:-}" = "tracks" ] && [ "${3:-}" = "enable" ]; then
    printf 'Success: Local Tracks capture enabled.\n'
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
            fake_bin / "wpcom-local",
            """#!/usr/bin/env bash
set -eu
if [ "${1:-}" = "tracks" ] && [ "${2:-}" = "path" ]; then
    printf 'Command: tracks path\n'
    printf 'Status: success\n'
    printf 'Context:\n'
    printf '%s\n' '- endpoint_url: http://wpcom.localhost:30001/__wpcom-local/tracks/events'
    exit 0
fi
exit 1
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
            critical_setup_dir / "fixtures.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-fixtures|EVIDENCE_DIR=%s|REF=%s|TARGET=%s|%s\n' "${EVIDENCE_DIR:-}" "${REF_WP_COMMAND:-}" "${TARGET_WP_COMMAND:-}" "$*" >> "$INVOCATIONS_LOG"
""",
        )
        write_executable(
            critical_dir / "run.sh",
            """#!/usr/bin/env bash
set -eu
printf 'critical-run|EVIDENCE_DIR=%s|REF=%s|TARGET=%s|%s\n' "${EVIDENCE_DIR:-}" "${REF_WP_COMMAND:-}" "${TARGET_WP_COMMAND:-}" "$*" >> "$INVOCATIONS_LOG"
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
                "--tracks-ref-store-id",
                "ref-store",
                "--tracks-target-store-id",
                "target-store",
                "--full-evidence-out-dir",
                str(full_evidence_dir),
                "--full-evidence",
                "--playwriter-session",
                "session-1",
                "--ref-subscription-id",
                "101",
                "--target-subscription-id",
                "202",
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
                "WPCOM_LOCAL_HOME": str(fake_wpcom_home),
                "PATH": str(fake_bin) + ":" + "/bin:/usr/bin:/usr/local/bin",
            },
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        final_summary = result.stdout.rsplit("\nSummary:", maxsplit=1)[-1]
        assert "RESULT: PASS - full-evidence gates passed" in final_summary
        assert "this is NOT full merge verification" not in final_summary

        log_dir = full_evidence_dir / "logs"
        assert log_dir.is_dir()
        gate_logs = sorted(log_dir.glob("woopayments-merge-gate.*.log"))
        assert len(gate_logs) >= 20
        assert all("XXXXXX" not in path.name for path in gate_logs)
        self_check_logs = [
            path for path in gate_logs if "final-evidence-self-check-verifier" in path.name
        ]
        assert self_check_logs
        self_check_log = self_check_logs[0].read_text(encoding="utf-8")
        assert "GATE: final evidence self-check verifier" in self_check_log
        assert "COMMAND: bash" in self_check_log
        assert any(
            "WooPayments-merge verification loop" in path.read_text(encoding="utf-8")
            for path in gate_logs
        )

        invocation_log = invocations.read_text(encoding="utf-8")
        assert "tracks-parity.sh|reset" in invocation_log
        assert "tracks-parity.sh|normalize --store ref-store" in invocation_log
        assert "tracks-parity.sh|normalize --store target-store" in invocation_log
        assert (
            "tracks-parity.sh|diff "
            f"{full_evidence_dir}/tracks-parity/reference-tracks.txt "
            f"{full_evidence_dir}/tracks-parity/target-tracks.txt"
        ) in invocation_log
        assert "tracks-parity.sh|diff" in invocation_log
        assert "wp|wpcom-local tracks enable" in invocation_log
        assert "critical-run|EVIDENCE_DIR=" in invocation_log
        assert "critical-fixtures|EVIDENCE_DIR=" in invocation_log
        assert f"critical-fixtures|EVIDENCE_DIR={full_evidence_dir}/critical-flows|REF={fake_wp}|TARGET={fake_wp}|" in invocation_log
        assert f"critical-run|EVIDENCE_DIR={full_evidence_dir}/critical-flows|REF={fake_wp}|TARGET={fake_wp}|" in invocation_log
        assert "|fixture_all ref" in invocation_log
        assert "|fixture_all target" in invocation_log
        assert "|--store both --layer all --agent-results-dir " in invocation_log
        assert "/critical-flows-agent-results" in invocation_log
        assert invocation_log.count("flow-drive.sh|charge") == 3
        assert invocation_log.count("rest-route-parity.sh|") == 3
        assert invocation_log.count("hook-shape-parity.sh|") == 3
        assert invocation_log.count("subsystem-disposition-gate.sh|") == 3
        assert invocation_log.count("i18n-notes-gate.sh|") == 2
        assert "subscriptions-renewal-gate.sh|compare" in invocation_log
        assert "--ref-subscription-id 101" in invocation_log
        assert "--target-subscription-id 202" in invocation_log
        assert "plugin-active-settings-gate.sh|--target " in invocation_log
        assert "--stage-plugin-active-fixture" in invocation_log
        assert "--out-dir " in invocation_log
        assert "/subscriptions-renewal" in invocation_log
        assert "a4aq-accumulated-gate.py|" in invocation_log
        assert "--plugin-repo " in invocation_log
        assert "/a4aq-accumulated" in invocation_log
        assert invocation_log.index("a4aq-accumulated-gate.py|") < invocation_log.index("critical-inventory|")
        assert invocation_log.index("critical-inventory|") < invocation_log.index("critical-fixtures|")
        assert invocation_log.index("|fixture_all ref") < invocation_log.index("|fixture_all target")
        assert invocation_log.index("|fixture_all target") < invocation_log.index("critical-run|")
        assert invocation_log.index("critical-run|") < invocation_log.index("pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env")
        assert_occurs_in_order(
            invocation_log,
            [
                "subscriptions-renewal-gate.sh|compare",
                "plugin-active-settings-gate.sh|--target ",
                "lpm-checkout-gate.sh|--methods",
                "mc-rates-gate.sh|--ref ",
                "token-continuity-gate.sh|--target ",
                "a5f-cutover-rehearsal.py|",
                "a5g-multisite-runtime-gate.py|",
                "dispute-e2e-gate.sh|--ref ",
                "payout-evidence-gate.sh|--wp ",
                "payout-evidence-gate.sh|--wp ",
                "converted-currency-gate.sh|--ref ",
                "a4aq-accumulated-gate.py|",
                "critical-inventory|",
                "critical-fixtures|",
                "critical-fixtures|",
                "critical-run|",
                "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env",
                "pnpm|--filter=@woocommerce/admin-library test:js",
                "pnpm|--filter=@woocommerce/admin-library ts:check",
                "pnpm|--filter=@woocommerce/plugin-woocommerce lint:changes:branch",
                "pnpm|--filter=@woocommerce/plugin-woocommerce phpstan",
            ],
        )
        assert "perf-surface-gate.sh|capture --wp" not in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce test:php:env" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library test:js" in invocation_log
        assert "pnpm|--filter=@woocommerce/admin-library ts:check" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce lint:changes:branch" in invocation_log
        assert "pnpm|--filter=@woocommerce/plugin-woocommerce phpstan" in invocation_log


def test_full_evidence_flag_is_documented_in_usage() -> None:
    result = run_verify()

    assert result.returncode == 2
    assert "--full-evidence" in result.stderr
    assert "accumulated final evidence plan" in result.stderr
    assert "--print-full-evidence-plan" in result.stderr
    assert "--critical-flows-agent-results-dir" in result.stderr


def test_a5g_disposable_cleanup_answers_wp_env_destroy_prompt(monkeypatch, tmp_path: Path) -> None:
    module = load_a5g_gate_module()
    work_dir = tmp_path / "a5g-wp-env"
    calls = []
    args = SimpleNamespace(
        repo=str(tmp_path / "repo"),
        wcpay_repo=str(tmp_path / "woocommerce-payments"),
        existing_wp_env_dir=None,
        out_dir=str(tmp_path / "out"),
        runtime_mode="disposable",
        keep_env=False,
        port=8899,
        start_attempts=1,
        existing_tests_url="http://store8889.localhost:8087",
    )

    work_dir.mkdir()
    gate = module.MultisiteRuntimeGate(args)
    gate.work_dir = work_dir
    gate.wp_env_started = True

    def fake_run_wp_env(phase_id: str, command: list[str], **kwargs):
        calls.append((phase_id, command, kwargs))
        return {"status": "pass"}

    monkeypatch.setattr(gate, "run_wp_env", fake_run_wp_env)

    gate.cleanup()

    assert calls
    assert calls[0][0] == "destroy-disposable-wp-env"
    assert calls[0][1] == ["destroy"]
    assert calls[0][2]["input_text"] == "y\n"
    assert not work_dir.exists()
    assert gate.work_dir is None


def main() -> None:
    tests = [
        test_full_evidence_plan_lists_final_gates,
        test_full_evidence_executes_nested_self_check_and_tracks_verifier,
        test_full_evidence_flag_is_documented_in_usage,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
