#!/usr/bin/env python3
"""Focused regression checks for the multi-currency rates gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/mc-rates-gate.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def run_gate(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=os.environ.copy(),
        check=False,
    )


def test_usage_requires_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_print_plan_describes_rate_probe() -> None:
    result = run_gate(
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--currency-from",
        "USD",
        "--currencies-to",
        "GBP,EUR",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_mc_rates_gate_plan.v1"
    assert payload["currency_from"] == "USD"
    assert payload["currencies_to"] == ["GBP", "EUR"]
    assert payload["ref_wp"] == REF_WP
    assert payload["target_wp"] == TARGET_WP


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(
    path: Path,
    *,
    role: str,
    provider: str = "woopayments",
    rate: str = "0.80",
    native_owner: str = "native",
) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import sys

args = " ".join(sys.argv[1:])
role = {json.dumps(role)}
provider = {json.dumps(provider)}
rate = {json.dumps(rate)}
native_owner = {json.dumps(native_owner)}

if args == "wc-native-payments status":
    print("Owner: " + native_owner)
    print("Native enabled: " + ("yes" if native_owner == "native" else "no"))
    raise SystemExit(0)

if "mc_rates_configure" in args:
    print(json.dumps({{"role": role, "configured": True, "currency_from": "USD", "currencies_to": ["GBP"]}}))
    raise SystemExit(0)

if "mc_rates_build_state" in args:
    print(json.dumps({{"role": role, "provider": provider, "state_built": True}}))
    raise SystemExit(0)

if "mc_rates_inspect_rates" in args:
    print(json.dumps({{
        "role": role,
        "provider": provider,
        "cache_option": "wcpay_multi_currency_cached_currencies",
        "updated": "2026-07-07T20:28:00+03:00",
        "rates": {{"GBP": rate}},
        "missing": [],
    }}))
    raise SystemExit(0)

print("unexpected fake wp args: " + args, file=sys.stderr)
raise SystemExit(1)
""",
    )


def test_full_gate_compares_reference_and_target_rates() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: native multi-currency rate transport matched reference rates." in result.stdout

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["target"]["provider"] == "woopayments"
        assert rollup["target"]["rates"] == {"GBP": "0.80"}
        assert rollup["reference"]["rates"] == {"GBP": "0.80"}


def test_gate_fails_when_target_provider_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", provider="")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target provider is not woopayments" in result.stderr

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "target provider is not woopayments" in rollup["failures"]


def test_gate_fails_before_rate_mutation_when_target_native_runtime_is_not_owner() -> None:
    with tempfile.TemporaryDirectory(prefix="mc-rates-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, role="reference")
        make_fake_wp(target_wp, role="target", native_owner="none")

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP",
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target native payments owner is not native: none" in result.stderr

        rollup = json.loads((out_dir / "mc-rates-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "target native payments owner is not native: none" in rollup["failures"]


def main() -> None:
    tests = [
        test_usage_requires_ref_and_target,
        test_print_plan_describes_rate_probe,
        test_full_gate_compares_reference_and_target_rates,
        test_gate_fails_when_target_provider_is_unavailable,
        test_gate_fails_before_rate_mutation_when_target_native_runtime_is_not_owner,
    ]
    for test in tests:
        test()
    print(f"PASS: {len(tests)} mc-rates gate regression tests passed.")


if __name__ == "__main__":
    main()
