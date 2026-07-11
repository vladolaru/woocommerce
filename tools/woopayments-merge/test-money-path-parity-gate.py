#!/usr/bin/env python3
"""Offline regressions for exact refund/manual-capture Bucket-E parity."""

from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
GATE = REPO / "tools/woopayments-merge/money-path-parity-gate.sh"
NORMALIZER = REPO / "tools/woopayments-merge/normalize-bucket-e-cross.py"


def write_executable(path: Path, source: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def run_gate(*args: str, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    command_args, process_env = adapt_wp_runner_arguments(
        list(args),
        os.environ.copy() if env is None else env,
    )
    return subprocess.run(
        ["bash", str(GATE), *command_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
        check=False,
    )


def assert_flow_invocation(invocations: str, role: str, command: str) -> None:
    container = (
        "woopayments-test-reference-wp"
        if role == "reference"
        else "woopayments-test-target-cli-1"
    )
    assert any(
        line.startswith("flow:")
        and f" exec -i {container} wp:" in line
        and f":{command}" in line
        for line in invocations.splitlines()
    )


def make_fixture_tools(tmp_path: Path) -> tuple[Path, Path, Path, Path]:
    ref_wp = tmp_path / "reference" / "wp"
    target_wp = tmp_path / "target" / "wp"
    flow = tmp_path / "flow-drive"
    parity = tmp_path / "parity-diff"

    wp_source = """#!/usr/bin/env bash
set -euo pipefail
printf '%s:%s\n' "$(basename "$0")" "$*" >> "${INVOCATIONS:?}"
role="${3:-unknown}"
if [ "${BLOCK_ROLE:-}" = "$role" ]; then
    printf '{"success":false,"mode":"preflight","role":"%s","errors":["account_not_ready"]}\n' "$role"
    exit 0
fi
owner=plugin
plugin_active=true
gateway_class=WC_Payment_Gateway_WCPay
site_url=http://reference.localhost/
if [ "$role" = target ]; then
    owner=native
    plugin_active=false
    gateway_class='Automattic\\\\WooCommerce\\\\Internal\\\\Payments\\\\Providers\\\\WooPayments\\\\NativeWooPaymentsGateway'
    site_url=http://target.localhost/
fi
if [ "${BAD_OWNER_ROLE:-}" = "$role" ]; then
    owner=wrong
fi
if [ "${SAME_SITE:-0}" -eq 1 ]; then
    site_url=http://same.localhost/
fi
printf '{"success":true,"mode":"preflight","role":"%s","errors":[],"runtime_owner":"%s","wcpay_plugin_active":%s,"gateway_id":"woocommerce_payments","gateway_class":"%s","account_ready":true,"test_mode":true,"deterministic_product_ready":true,"site_url":"%s"}\n' "$role" "$owner" "$plugin_active" "$gateway_class" "$site_url"
"""
    write_executable(ref_wp, wp_source)
    write_executable(target_wp, wp_source)

    write_executable(
        flow,
        """#!/usr/bin/env bash
set -euo pipefail
printf 'flow:%s:%s\n' "${WP:?}" "$*" >> "${INVOCATIONS:?}"
role=reference
case "$WP" in *woopayments-test-target-cli-1*) role=target ;; esac
op="${1:?}"
shift
manual=0
order_id=0
for arg in "$@"; do
    [ "$arg" = "--manual-capture" ] && manual=1
    case "$arg" in --order-id=*) order_id="${arg#--order-id=}" ;; esac
done
if [ "$op" = charge ]; then
    if [ "$role" = reference ]; then
        [ "$manual" -eq 1 ] && order_id=102 || order_id=101
    else
        [ "$manual" -eq 1 ] && order_id=202 || order_id=201
    fi
fi
intention_status=succeeded
[ "$manual" -eq 0 ] || intention_status=requires_capture
[ "${BAD_MANUAL_ROLE:-}" != "$role" ] || intention_status=succeeded
success=true
[ "${FAIL_FLOW_ROLE:-}" != "$role" ] || success=false
printf '{"op":"%s","order_id":%s,"success":%s,"manual_capture":"%s","intention_status":"%s"}\n' "$op" "$order_id" "$success" "$([ "$manual" -eq 1 ] && printf yes || printf no)" "$intention_status"
""",
    )

    write_executable(
        parity,
        """#!/usr/bin/env bash
set -euo pipefail
count_file="${PARITY_COUNT_FILE:?}"
count=0
[ ! -f "$count_file" ] || count="$(cat "$count_file")"
count=$((count + 1))
printf '%s' "$count" > "$count_file"
printf 'parity:%s\n' "$*" >> "${INVOCATIONS:?}"
if [ "${FAIL_PARITY_CALL:-0}" -eq "$count" ]; then
    printf 'forced parity failure\n' >&2
    exit 1
fi
if [ "${BLOCK_PARITY_CALL:-0}" -eq "$count" ]; then
    printf 'forced parity infrastructure blocker\n' >&2
    exit 2
fi
printf 'PASS: zero Bucket-E parity diff\n'
""",
    )
    return ref_wp, target_wp, flow, parity


def fixture_env(tmp_path: Path, flow: Path, parity: Path) -> dict[str, str]:
    preflight = tmp_path / "preflight.php"
    preflight.write_text("<?php // fake stdin payload\n", encoding="utf-8")
    return {
        **os.environ,
        "FLOW_DRIVE": str(flow),
        "PARITY_DIFF": str(parity),
        "PREFLIGHT_DRIVER": str(preflight),
        "INVOCATIONS": str(tmp_path / "invocations.log"),
        "PARITY_COUNT_FILE": str(tmp_path / "parity-count"),
    }


def test_help_does_not_require_store_commands() -> None:
    result = run_gate("--help")

    assert result.returncode == 0
    assert "--ref" in result.stderr
    assert "--target" in result.stderr
    assert "--sku" in result.stderr


def test_gate_drives_refund_and_both_manual_capture_states() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = fixture_env(tmp_path, flow, parity)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["orders"] == {
            "refund": {"reference": 101, "target": 201},
            "manual_capture": {"reference": 102, "target": 202},
        }
        assert rollup["checks"] == {
            "refund_bucket_e": "pass",
            "authorized_bucket_e": "pass",
            "captured_bucket_e": "pass",
        }

        invocations = (tmp_path / "invocations.log").read_text(encoding="utf-8")
        assert_flow_invocation(invocations, "reference", "charge --deterministic")
        assert_flow_invocation(invocations, "target", "charge --deterministic --native")
        assert "--manual-capture" in invocations
        assert_flow_invocation(
            invocations,
            "reference",
            "refund --deterministic --order-id=101 --type=partial",
        )
        assert_flow_invocation(
            invocations,
            "target",
            "capture --deterministic --native --order-id=202",
        )
        assert invocations.count("parity:") == 3


def test_custom_sku_is_used_by_readiness_and_money_flows() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        env = fixture_env(tmp_path, flow, parity)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--sku",
            "custom-fixture-sku",
            "--out-dir",
            str(tmp_path / "evidence"),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = (tmp_path / "invocations.log").read_text(encoding="utf-8")
        assert "wp:eval-file - reference custom-fixture-sku" in invocations
        assert "wp:eval-file - target custom-fixture-sku" in invocations
        assert invocations.count("--sku=custom-fixture-sku") == 4


def test_readiness_failure_blocks_without_driving_money() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "BLOCK_ROLE": "target"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["blocked_role"] == "target"
        assert "flow:" not in (tmp_path / "invocations.log").read_text(encoding="utf-8")


def test_preflight_owner_invariant_blocks_before_money_flows() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "BAD_OWNER_ROLE": "target"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["blocked_role"] == "target"
        assert "flow:" not in (tmp_path / "invocations.log").read_text(encoding="utf-8")


def test_same_site_topology_blocks_before_money_flows() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "SAME_SITE": "1"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["failure_stage"] == "store_topology"
        assert "flow:" not in (tmp_path / "invocations.log").read_text(encoding="utf-8")


def test_reference_flow_explicit_failure_blocks_instead_of_passing() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "FAIL_FLOW_ROLE": "reference"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["blocked_role"] == "reference"
        assert rollup["failure_stage"] == "reference_refund_charge"


def test_refund_difference_runs_later_checks_before_aggregate_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "FAIL_PARITY_CALL": "1"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["failure_stage"] == "refund_bucket_e"
        assert rollup["checks"] == {
            "refund_bucket_e": "fail",
            "authorized_bucket_e": "pass",
            "captured_bucket_e": "pass",
        }

        invocations = (tmp_path / "invocations.log").read_text(encoding="utf-8")
        assert invocations.count("parity:") == 3
        assert_flow_invocation(invocations, "reference", "capture --deterministic --order-id=102")
        assert_flow_invocation(invocations, "target", "capture --deterministic --native --order-id=202")


def test_authorized_difference_is_a_failure_with_durable_later_phase_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "FAIL_PARITY_CALL": "2"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["checks"] == {
            "refund_bucket_e": "pass",
            "authorized_bucket_e": "fail",
            "captured_bucket_e": "pass",
        }
        assert (out_dir / "authorized-bucket-e.log").is_file()
        assert (out_dir / "captured-bucket-e.log").is_file()

        invocations = (tmp_path / "invocations.log").read_text(encoding="utf-8")
        assert_flow_invocation(invocations, "reference", "capture --deterministic --order-id=102")
        assert_flow_invocation(invocations, "target", "capture --deterministic --native --order-id=202")


def test_bucket_e_infrastructure_error_blocks_instead_of_failing_parity() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "BLOCK_PARITY_CALL": "2"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 3
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["failure_stage"] == "authorized_bucket_e_infrastructure"
        assert rollup["checks"] == {
            "refund_bucket_e": "pass",
            "authorized_bucket_e": "blocked",
            "captured_bucket_e": "pass",
        }

        invocations = (tmp_path / "invocations.log").read_text(encoding="utf-8")
        assert_flow_invocation(invocations, "reference", "capture --deterministic --order-id=102")
        assert_flow_invocation(invocations, "target", "capture --deterministic --native --order-id=202")


def test_manual_capture_must_reach_requires_capture_before_parity() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        env = {**fixture_env(tmp_path, flow, parity), "BAD_MANUAL_ROLE": "target"}

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 1
        rollup = json.loads((out_dir / "money-path-parity.json").read_text(encoding="utf-8"))
        assert rollup["failure_stage"] == "manual_capture_contract"
        assert rollup["checks"]["authorized_bucket_e"] == "not_run"


def test_gate_rejects_remote_wp_runners() -> None:
    result = run_gate(
        "--ref",
        "wp --ssh=example.test",
        "--target",
        "wp --http=example.test",
    )

    assert result.returncode == 2
    assert "unsafe WP-CLI command" in result.stderr


def test_gate_rejects_nonempty_evidence_directory_before_store_commands() -> None:
    with tempfile.TemporaryDirectory(prefix="money-path-parity-") as tmp:
        tmp_path = Path(tmp)
        ref_wp, target_wp, flow, parity = make_fixture_tools(tmp_path)
        out_dir = tmp_path / "evidence"
        out_dir.mkdir()
        (out_dir / "stale.json").write_text("{}\n", encoding="utf-8")
        env = fixture_env(tmp_path, flow, parity)

        result = run_gate(
            "--ref",
            str(ref_wp),
            "--target",
            str(target_wp),
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 2
        assert "not empty" in result.stderr
        assert not (tmp_path / "invocations.log").exists()


def test_cross_store_normalizer_masks_refund_ids_but_not_missing_refund_meta() -> None:
    def normalize(record: dict) -> str:
        result = subprocess.run(
            ["python3", str(NORMALIZER)],
            cwd=REPO,
            text=True,
            input=json.dumps(record) + "\n",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert result.returncode == 0, result.stderr
        return result.stdout

    base = {
        "order_id": 101,
        "meta": {},
        "refunds": [
            {
                "amount": "25.00",
                "currency": "USD",
                "reason": "Harness deterministic partial refund",
                "meta": {"_wcpay_refund_id": ["re_reference123"]},
            }
        ],
    }
    target = json.loads(json.dumps(base))
    target["order_id"] = 202
    target["refunds"][0]["meta"]["_wcpay_refund_id"] = ["re_target456"]
    missing = json.loads(json.dumps(target))
    missing["refunds"][0]["meta"] = {}

    assert normalize(base) == normalize(target)
    assert normalize(base) != normalize(missing)


def test_cross_store_normalizer_masks_provider_capture_deadlines() -> None:
    def normalize(record: dict) -> str:
        result = subprocess.run(
            ["python3", str(NORMALIZER)],
            cwd=REPO,
            text=True,
            input=json.dumps(record) + "\n",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert result.returncode == 0, result.stderr
        return result.stdout

    reference = {"order_id": 101, "intent": {"capture_before": 1786351000}}
    target = {"order_id": 202, "intent": {"capture_before": 1786351005}}

    assert normalize(reference) == normalize(target)


def test_cross_store_normalizer_recursively_ignores_only_test_lab_metadata() -> None:
    def normalize(record: dict) -> str:
        result = subprocess.run(
            ["python3", str(NORMALIZER)],
            cwd=REPO,
            text=True,
            input=json.dumps(record) + "\n",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        assert result.returncode == 0, result.stderr
        return result.stdout

    reference = {
        "order_id": 101,
        "meta": {},
        "refunds": [
            {
                "amount": "25.00",
                "currency": "USD",
                "created_at": "2026-07-11T01:00:00Z",
                "meta": {
                    "_wcpay_refund_id": ["re_reference123"],
                    "_wcpay_test_lab": {
                        "created_at": "2026-07-11T01:01:00Z",
                        "protocol": "harness-deterministic-refund",
                    },
                    "_wcpay_test_lab_account": {
                        "created_at": "2026-07-11T00:59:00Z",
                        "account_id": "acct_reference123",
                    },
                },
            }
        ],
    }
    target = json.loads(json.dumps(reference))
    target["order_id"] = 202
    target_refund = target["refunds"][0]
    target_refund["meta"]["_wcpay_refund_id"] = ["re_target456"]
    target_refund["meta"]["_wcpay_test_lab"]["created_at"] = "2026-07-11T02:01:00Z"
    target_refund["meta"]["_wcpay_test_lab_account"] = {
        "created_at": "2026-07-11T02:00:00Z",
        "account_id": "acct_target456",
    }

    assert normalize(reference) == normalize(target)

    missing_provider_id = json.loads(json.dumps(target))
    del missing_provider_id["refunds"][0]["meta"]["_wcpay_refund_id"]
    assert normalize(reference) != normalize(missing_provider_id)

    changed_contract_timestamp = json.loads(json.dumps(target))
    changed_contract_timestamp["refunds"][0]["created_at"] = "2026-07-11T03:00:00Z"
    assert normalize(reference) != normalize(changed_contract_timestamp)

    native_fee_marker = json.loads(json.dumps(target))
    native_fee_marker["refunds"][0]["meta"]["_wcpay_fee_breakdown_note_ids"] = [321]
    assert normalize(reference) != normalize(native_fee_marker)

    native_refund_note_marker = json.loads(json.dumps(target))
    native_refund_note_marker["refunds"][0]["meta"][
        "_wc_native_woopayments_refund_note_deadbeef"
    ] = [654]
    assert normalize(reference) != normalize(native_refund_note_marker)
