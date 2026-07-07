#!/usr/bin/env python3
"""Focused regression checks for the hook-shape parity gate harness."""

from __future__ import annotations

import json
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/hook-shape-parity.sh"
DRIVER = REPO / "tools/woopayments-merge/hook-shape-parity.php"
VERIFY = REPO / "tools/woopayments-merge/verify.sh"

REF_WP = "docker exec -i wcpay_wp_default wp --allow-root"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"


REQUIRED_HOOKS = [
    "wcpay_metadata_from_order",
    "wcpay_payment_fields_js_config",
    "wcpay_list_transactions_request",
    "wcpay_list_disputes_request",
    "wcpay_list_deposits_request",
    "wcpay_list_authorizations_request",
    "woocommerce_payments_before_webhook_delivery",
    "woocommerce_payments_after_webhook_delivery",
    "wcpay_woopay_is_signed_with_blog_token",
]


def run_gate(*args: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", str(SCRIPT), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_snapshot(path: Path, *, role: str, hooks: dict[str, dict]) -> None:
    path.write_text(
        json.dumps(
            {
                "schema": "woopayments_hook_shape_capture.v1",
                "role": role,
                "hooks": hooks,
                "errors": [],
            },
            sort_keys=True,
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )


def base_hooks() -> dict[str, dict]:
    request_arg = {
        "type": "object",
        "class": "WCPay\\Core\\Server\\Request\\List_Transactions",
        "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Transactions"],
        "methods": ["get_params", "send"],
    }
    metadata_arg = {
        "type": "array",
        "keys": ["customer_email", "customer_name", "order_id", "payment_type"],
        "values": {},
    }
    order_arg = {"type": "object", "class": "WC_Order", "legacy_classes": ["WC_Order"], "methods": []}
    payment_type_arg = {
        "type": "object",
        "class": "WCPay\\Constants\\Payment_Type",
        "legacy_classes": ["WCPay\\Constants\\Payment_Type"],
        "methods": ["__toString", "equals"],
    }
    config_arg = {
        "type": "array",
        "keys": ["accountId", "gatewayId", "paymentMethodsConfig", "publishableKey"],
        "values": {},
    }
    webhook_args = [
        {"type": "string"},
        {"type": "array", "keys": ["data", "id", "type"], "values": {}},
    ]

    hooks: dict[str, dict] = {
        "wcpay_metadata_from_order": {"args": [metadata_arg, order_arg, payment_type_arg]},
        "wcpay_payment_fields_js_config": {"args": [config_arg]},
        "wcpay_list_transactions_request": {"args": [request_arg]},
        "wcpay_list_disputes_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Disputes",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Disputes"],
                }
            ]
        },
        "wcpay_list_deposits_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Deposits",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Deposits"],
                }
            ]
        },
        "wcpay_list_authorizations_request": {
            "args": [
                {
                    **request_arg,
                    "class": "WCPay\\Core\\Server\\Request\\List_Authorizations",
                    "legacy_classes": ["WCPay\\Core\\Server\\Request\\List_Authorizations"],
                }
            ]
        },
        "woocommerce_payments_before_webhook_delivery": {"args": webhook_args},
        "woocommerce_payments_after_webhook_delivery": {"args": webhook_args},
        "wcpay_woopay_is_signed_with_blog_token": {"args": [{"type": "bool"}]},
    }

    assert sorted(hooks) == sorted(REQUIRED_HOOKS)
    return hooks


def test_usage_requires_ref_target_or_snapshot_files() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--ref" in result.stderr
    assert "--ref-state" in result.stderr


def test_print_plan_lists_required_hooks() -> None:
    result = run_gate("--ref", REF_WP, "--target", TARGET_WP, "--print-plan")

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_hook_shape_gate_plan.v1"
    assert payload["ref_wp"] == REF_WP
    assert payload["target_wp"] == TARGET_WP
    assert payload["required_hooks"] == REQUIRED_HOOKS


def test_gate_passes_matching_snapshot_files() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=base_hooks())

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 0, result.stderr
        assert "PASS: preserved WooPayments hook argument shapes match." in result.stdout

        rollup = json.loads((out_dir / "hook-shape-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["failures"] == []


def test_gate_fails_on_argument_shape_drift() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        target_hooks["wcpay_metadata_from_order"]["args"][2] = {"type": "string"}

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "wcpay_metadata_from_order arg[2]" in result.stderr

        rollup = json.loads((out_dir / "hook-shape-parity.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "wcpay_metadata_from_order arg[2]" in "\n".join(rollup["failures"])


def test_gate_fails_when_required_hook_is_missing() -> None:
    with tempfile.TemporaryDirectory(prefix="hook-shape-parity-test-") as tmp:
        tmp_path = Path(tmp)
        ref_state = tmp_path / "ref.json"
        target_state = tmp_path / "target.json"
        out_dir = tmp_path / "evidence"
        target_hooks = base_hooks()
        del target_hooks["wcpay_payment_fields_js_config"]

        write_snapshot(ref_state, role="reference", hooks=base_hooks())
        write_snapshot(target_state, role="target", hooks=target_hooks)

        result = run_gate(
            "--ref-state",
            str(ref_state),
            "--target-state",
            str(target_state),
            "--out-dir",
            str(out_dir),
        )

        assert result.returncode == 1
        assert "target missing required hook: wcpay_payment_fields_js_config" in result.stderr


def test_php_driver_exports_inventory_without_wordpress() -> None:
    result = subprocess.run(
        ["php", str(DRIVER), "--inventory"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_hook_shape_inventory.v1"
    assert payload["required_hooks"] == REQUIRED_HOOKS
    assert "wcpay_metadata_from_order" in payload["preserved_hooks"]


def test_verify_runs_hook_shape_gate() -> None:
    verify_source = VERIFY.read_text(encoding="utf-8")

    assert "hook-shape-parity.sh" in verify_source
