#!/usr/bin/env python3
"""Focused regression checks for the LPM checkout gate harness."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/lpm-checkout-gate.sh"
REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
	return subprocess.run(
		["bash", str(SCRIPT), *args],
		cwd=REPO,
		text=True,
		stdout=subprocess.PIPE,
		stderr=subprocess.PIPE,
		env=env,
		check=False,
	)


def test_usage_requires_methods_ref_and_target() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--methods" in result.stderr
    assert "--ref" in result.stderr
    assert "--target" in result.stderr


def test_unknown_method_is_usage_error() -> None:
    result = run_gate(
        "--methods",
        "bogus",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsupported method: bogus" in result.stderr


def test_print_plan_describes_wave_1_methods() -> None:
    result = run_gate(
        "--methods",
        "sepa_debit,ideal,bancontact,klarna,affirm,afterpay_clearpay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "sepa_debit",
        "ideal",
        "bancontact",
        "klarna",
        "affirm",
        "afterpay_clearpay",
    ]
    assert payload["fixtures"]["sepa_debit"]["currency"] == "EUR"
    assert payload["fixtures"]["sepa_debit"]["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert payload["fixtures"]["ideal"]["currency"] == "EUR"
    assert payload["fixtures"]["bancontact"]["country"] == "BE"
    assert payload["fixtures"]["klarna"]["gateway_id"] == "woocommerce_payments_klarna"
    assert payload["fixtures"]["affirm"]["currency"] == "USD"
    assert payload["fixtures"]["afterpay_clearpay"]["country"] == "US"


def test_print_plan_describes_wave_2_methods() -> None:
    result = run_gate(
        "--methods",
        "eps,p24,multibanco,au_becs_debit,grabpay,wechat_pay,alipay",
        "--ref",
        REF_WP,
        "--target",
        TARGET_WP,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["methods"] == [
        "eps",
        "p24",
        "multibanco",
        "au_becs_debit",
        "grabpay",
        "wechat_pay",
        "alipay",
    ]
    assert payload["fixtures"]["eps"]["country"] == "AT"
    assert payload["fixtures"]["p24"]["currency"] == "PLN"
    assert payload["fixtures"]["multibanco"]["family"] == "voucher"
    assert payload["fixtures"]["au_becs_debit"]["gateway_id"] == "woocommerce_payments_au_becs_debit"
    assert payload["fixtures"]["grabpay"]["currency"] == "SGD"
    assert payload["fixtures"]["wechat_pay"]["stripe_payment_method_type"] == "wechat_pay"
    assert payload["fixtures"]["alipay"]["country"] == "NL"


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, home_url: str) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env bash
if [ "$1" = "option" ] && [ "$2" = "get" ]; then
\tprintf '%s\\n' {json.dumps(home_url)}
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_playwriter(path: Path, *, omit_order_id: bool = False) -> None:
    order_id_expr = "None" if omit_order_id else "1001"
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import pathlib
import sys

payload = {{
    "schema": "woopayments_lpm_checkout_browser_evidence.v1",
    "status": "pass",
    "role": os.environ["LPM_GATE_ROLE"],
    "method": os.environ["LPM_GATE_METHOD"],
    "surface": os.environ["LPM_GATE_SURFACE"],
    "base_url": os.environ["LPM_GATE_BASE_URL"],
    "gateway_id": os.environ["LPM_GATE_GATEWAY_ID"],
    "stripe_payment_method_type": os.environ["LPM_GATE_STRIPE_PAYMENT_METHOD_TYPE"],
    "order_id": {order_id_expr},
    "order_received_url": os.environ["LPM_GATE_BASE_URL"] + "/checkout/order-received/1001/",
    "payment_intent_id": "pi_unit_" + os.environ["LPM_GATE_METHOD"],
    "failures": [],
}}

evidence_path = pathlib.Path(os.environ["LPM_GATE_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({{"argv": sys.argv[1:], "env": payload}}, sort_keys=True) + "\\n")
""",
    )


def test_full_gate_invokes_playwriter_driver_for_each_store_and_validates_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 2
        assert {item["env"]["role"] for item in invocations} == {"reference", "target"}
        assert {item["env"]["base_url"] for item in invocations} == {
            "http://localhost:8082",
            "http://store8889.localhost:8889",
        }
        assert all("-s" in item["argv"] and "unit" in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/lpm-checkout.playwriter.mjs") in item["argv"] for item in invocations)

        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert len(rollup["results"]) == 2
        assert all(item["method"] == "ideal" for item in rollup["results"])
        assert all(item["gateway_id"] == "woocommerce_payments_ideal" for item in rollup["results"])
        assert all(item["stripe_payment_method_type"] == "ideal" for item in rollup["results"])
        assert all(item["order_id"] == 1001 for item in rollup["results"])


def test_gate_fails_when_driver_evidence_omits_required_order_fields() -> None:
    with tempfile.TemporaryDirectory(prefix="lpm-gate-test-") as tmp:
        tmp_path = Path(tmp)
        ref_wp = tmp_path / "ref-wp"
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(ref_wp, "http://localhost:8082")
        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_order_id=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--methods",
            "ideal",
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "missing order_id" in result.stderr
        rollup = json.loads((out_dir / "lpm-checkout-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("missing order_id" in failure for failure in rollup["failures"])


def main() -> None:
    tests = [
        test_usage_requires_methods_ref_and_target,
        test_unknown_method_is_usage_error,
        test_print_plan_describes_wave_1_methods,
        test_print_plan_describes_wave_2_methods,
        test_full_gate_invokes_playwriter_driver_for_each_store_and_validates_evidence,
        test_gate_fails_when_driver_evidence_omits_required_order_fields,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
