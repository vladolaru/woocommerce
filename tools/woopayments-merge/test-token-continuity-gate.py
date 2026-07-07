#!/usr/bin/env python3
"""Focused regression checks for the token-continuity gate harness."""

from __future__ import annotations

import json
import os
import shlex
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/token-continuity-gate.sh"
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


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, home_url: str, *, omit_token: bool = False) -> None:
    tokens_json = "[]" if omit_token else json.dumps(
        [
            {
                "token_id": 4242,
                "gateway_id": "woocommerce_payments_sepa_debit",
                "type": "wcpay_sepa",
            }
        ]
    )
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
invocations="${{FAKE_WP_INVOCATIONS:?}}"
printf '%s\\n' "$*" >> "$invocations"
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
    printf '%s\\n' {json.dumps(home_url)}
    exit 0
fi
if [ "$1" = "wc" ] && [ "$2" = "payment_token" ] && [ "$3" = "list" ]; then
    printf '%s\\n' {shlex.quote(tokens_json)}
    exit 0
fi
if [ "$1" = "eval-file" ] && [ "$2" = "-" ]; then
    mode="${{3:-}}"
    if [ "$mode" = "preflight-plugin" ] || [ "$mode" = "cutover-native" ] || [ "$mode" = "restore" ]; then
        printf '{{"success":true,"mode":"%s","errors":[]}}\\n' "$mode"
        exit 0
    fi
    if [ "$mode" = "drive" ]; then
        printf '{{"success":true,"mode":"drive","subscription_id":%s,"renewal_order_payment_method":"%s","token_presence":{{"subscription_tokens":[%s],"renewal_tokens":[%s]}},"errors":[]}}\\n' "${{4:-0}}" "${{5:-}}" "${{6:-0}}" "${{6:-0}}"
        exit 0
    fi
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_fake_playwriter(path: Path, *, omit_save_semantics: bool = False) -> None:
    omit_save_semantics_literal = "True" if omit_save_semantics else "False"
    write_executable(
        path,
        """#!/usr/bin/env python3
import json
import os
import pathlib
import sys

phase = os.environ["TOKEN_CONTINUITY_GATE_PHASE"]
base_url = os.environ["TOKEN_CONTINUITY_GATE_BASE_URL"]
omit_save_semantics = __OMIT_SAVE_SEMANTICS__

if phase == "save_sepa_token":
    url = base_url + "/checkout/?token-continuity-gate=save-sepa"
elif phase == "render_payment_methods":
    url = base_url + "/my-account/payment-methods/?token-continuity-gate=render-sepa&token_id=" + os.environ["TOKEN_CONTINUITY_GATE_TOKEN_ID"]
else:
    raise SystemExit(f"unexpected phase: {phase}")

payload = {
    "schema": "woopayments_token_continuity_browser_evidence.v1",
    "status": "pass",
    "phase": phase,
    "base_url": base_url,
    "url": "" if omit_save_semantics and phase == "save_sepa_token" else url,
    "method": os.environ["TOKEN_CONTINUITY_GATE_METHOD"],
    "gateway_id": os.environ["TOKEN_CONTINUITY_GATE_GATEWAY_ID"],
    "stripe_payment_method_type": os.environ["TOKEN_CONTINUITY_GATE_STRIPE_PAYMENT_METHOD_TYPE"],
    "token_type": os.environ["TOKEN_CONTINUITY_GATE_TOKEN_TYPE"],
    "customer_id": int(os.environ["TOKEN_CONTINUITY_GATE_CUSTOMER_ID"]),
    "failures": [],
}
if phase == "save_sepa_token":
    payload.update({
        "token_id": 4242,
        "selected_gateway_id": "" if omit_save_semantics else os.environ["TOKEN_CONTINUITY_GATE_GATEWAY_ID"],
        "payment_method_id": "" if omit_save_semantics else "pm_unit_sepa",
    })
elif phase == "render_payment_methods":
    payload.update({
        "token_id": int(os.environ["TOKEN_CONTINUITY_GATE_TOKEN_ID"]),
        "token_visible": True,
    })

evidence_path = pathlib.Path(os.environ["TOKEN_CONTINUITY_GATE_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({"argv": sys.argv[1:], "env": payload}, sort_keys=True) + "\\n")
""".replace("__OMIT_SAVE_SEMANTICS__", omit_save_semantics_literal),
    )


def test_usage_requires_target_customer_and_subscription() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--customer-id" in result.stderr
    assert "--subscription-id" in result.stderr


def test_print_plan_describes_sepa_cutover_evidence() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--customer-id",
        "7",
        "--subscription-id",
        "77",
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_token_continuity_gate_plan.v1"
    assert payload["target_wp"] == TARGET_WP
    assert payload["customer_id"] == 7
    assert payload["subscription_id"] == 77
    assert payload["method"] == "sepa_debit"
    assert payload["gateway_id"] == "woocommerce_payments_sepa_debit"
    assert payload["token_type"] == "wcpay_sepa"
    assert payload["stripe_payment_method_type"] == "sepa_debit"
    assert payload["checks"] == [
        "plugin_checkout_saves_sepa_token",
        "native_cutover_cli_lists_token",
        "native_my_account_renders_token",
        "native_sepa_subscription_renewal_succeeds",
    ]


def test_full_gate_invokes_browser_cutover_token_list_and_renewal_checks() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        wp_invocations = tmp_path / "wp-invocations.txt"
        playwriter_invocations = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_WP_INVOCATIONS": str(wp_invocations),
            "FAKE_PLAYWRITER_INVOCATIONS": str(playwriter_invocations),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in playwriter_invocations.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert [item["env"]["phase"] for item in invocations] == [
            "save_sepa_token",
            "render_payment_methods",
        ]
        assert all("-s" in item["argv"] and "unit" in item["argv"] for item in invocations)
        assert all(str(REPO / "tools/woopayments-merge/token-continuity.playwriter.mjs") in item["argv"] for item in invocations)

        wp_log = wp_invocations.read_text(encoding="utf-8")
        assert "eval-file - preflight-plugin" in wp_log
        assert "eval-file - cutover-native 4242" in wp_log
        assert "wc payment_token list --user=7 --format=json" in wp_log
        assert "eval-file - drive 77 woocommerce_payments_sepa_debit 4242" in wp_log
        assert "eval-file - restore" in wp_log

        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["token_id"] == 4242
        assert rollup["customer_id"] == 7
        assert rollup["renewal"]["success"] is True
        assert rollup["failures"] == []


def test_gate_fails_when_native_token_list_omits_saved_token() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889", omit_token=True)
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "saved token 4242 is missing from native payment_token list" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("saved token 4242" in failure for failure in rollup["failures"])


def test_gate_requires_save_token_browser_semantic_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="token-continuity-gate-test-") as tmp:
        tmp_path = Path(tmp)
        target_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(target_wp, "http://store8889.localhost:8889")
        make_fake_playwriter(fake_playwriter, omit_save_semantics=True)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_WP_INVOCATIONS": str(tmp_path / "wp-invocations.txt"),
            "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
        }

        result = run_gate(
            "--target",
            str(target_wp),
            "--customer-id",
            "7",
            "--subscription-id",
            "77",
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        assert "save_sepa_token: missing url" in result.stderr
        assert "save_sepa_token: missing selected_gateway_id" in result.stderr
        assert "save_sepa_token: missing payment_method_id" in result.stderr
        rollup = json.loads((out_dir / "token-continuity-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def main() -> None:
    tests = [
        test_usage_requires_target_customer_and_subscription,
        test_print_plan_describes_sepa_cutover_evidence,
        test_full_gate_invokes_browser_cutover_token_list_and_renewal_checks,
        test_gate_fails_when_native_token_list_omits_saved_token,
        test_gate_requires_save_token_browser_semantic_evidence,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
