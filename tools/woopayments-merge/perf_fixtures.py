#!/usr/bin/env python3
"""Shared local perf fixture staging for WooPayments merge gates."""

from __future__ import annotations

import json
import shlex
import subprocess
from pathlib import Path
from typing import Any

from local_runner_safety import LocalRunnerError
from local_runner_safety import validate_local_wp_command as validate_shared_local_wp_command


EXIT_TIMEOUT = 124
WP_EVAL_TIMEOUT_SECONDS = 180


class PerfFixtureError(RuntimeError):
    """Raised when local perf fixtures cannot be staged or validated."""


def tail(text: str | bytes | None, limit: int = 6000) -> str:
    if text is None:
        return ""
    if isinstance(text, bytes):
        text = text.decode("utf-8", errors="replace")
    if len(text) <= limit:
        return text
    return text[-limit:]


def parse_last_json(text: str) -> Any:
    for line in reversed(text.splitlines()):
        stripped = line.strip()
        if not stripped or stripped[0] not in "[{":
            continue
        try:
            return json.loads(stripped)
        except json.JSONDecodeError:
            continue

    decoder = json.JSONDecoder()
    payload: Any = None
    for index, char in enumerate(text):
        if char not in "[{":
            continue
        try:
            candidate, end = decoder.raw_decode(text[index:])
        except json.JSONDecodeError:
            continue
        if text[index + end :].strip():
            continue
        payload = candidate
    return payload


def validate_local_wp_command(label: str, value: str) -> None:
    try:
        validate_shared_local_wp_command(label, value)
    except LocalRunnerError as exc:
        raise PerfFixtureError(str(exc)) from exc


def run_wp_eval_file(
    repo: Path,
    label: str,
    wp_cmd: str,
    script: Path,
    args: list[str] | None = None,
    timeout_seconds: float = WP_EVAL_TIMEOUT_SECONDS,
) -> dict[str, Any]:
    validate_local_wp_command(label, wp_cmd)
    if not script.is_file():
        raise PerfFixtureError(f"WP-CLI driver missing: {script}")

    command = shlex.split(wp_cmd) + ["eval-file", "-"] + [str(arg) for arg in (args or [])]
    try:
        completed = subprocess.run(
            command,
            cwd=str(repo),
            input=script.read_text(encoding="utf-8"),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=timeout_seconds,
        )
    except subprocess.TimeoutExpired as exc:
        return {
            "label": label,
            "command": command[:],
            "script": str(script),
            "args": args or [],
            "exit_code": EXIT_TIMEOUT,
            "stdout_tail": tail(exc.stdout),
            "stderr_tail": tail(f"{tail(exc.stderr)}\nWP-CLI eval-file timed out after {timeout_seconds:g}s."),
            "payload": None,
        }

    return {
        "label": label,
        "command": command[:],
        "script": str(script),
        "args": args or [],
        "exit_code": completed.returncode,
        "stdout_tail": tail(completed.stdout),
        "stderr_tail": tail(completed.stderr),
        "payload": parse_last_json(completed.stdout),
    }


def require_wp_payload(store: str, operation: str, result: dict[str, Any]) -> dict[str, Any]:
    if result["exit_code"] != 0:
        raise PerfFixtureError(f"{store} {operation} fixture command failed: {result['stderr_tail'] or result['stdout_tail']}")
    payload = result.get("payload")
    if not isinstance(payload, dict):
        raise PerfFixtureError(f"{store} {operation} fixture did not return JSON")
    return payload


def inspect_perf_order(repo: Path, scripts_dir: Path, store: str, wp_cmd: str, order_id: int) -> dict[str, Any]:
    result = run_wp_eval_file(
        repo,
        f"{store}-wp",
        wp_cmd,
        scripts_dir / "a4-perf-fixture-inspect.php",
        [str(order_id)],
    )
    return require_wp_payload(store, f"inspect_order_{order_id}", result)


def validate_perf_order_fixture(store: str, kind: str, order: dict[str, Any]) -> None:
    order_id = order.get("order_id")
    if not order.get("exists"):
        raise PerfFixtureError(f"{store} {kind} fixture order #{order_id} does not exist")
    if order.get("payment_method") != order.get("gateway_id"):
        raise PerfFixtureError(f"{store} {kind} fixture order #{order_id} payment method is {order.get('payment_method')}, expected {order.get('gateway_id')}")

    if kind == "process_payment":
        if not order.get("needs_payment"):
            raise PerfFixtureError(f"{store} process_payment fixture order #{order_id} does not need payment")
        return

    if kind == "refund":
        if not str(order.get("intent_id") or "").startswith("pi_"):
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} missing intent id")
        if not str(order.get("charge_id") or "").startswith("ch_"):
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} missing charge id")
        if not order.get("transaction_id"):
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} has no transaction id")
        if float(order.get("total") or 0) <= 0:
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} has no positive total")
        if float(order.get("remaining_refund_amount") or 0) <= 0:
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} has no remaining refundable amount")
        if float(order.get("refunded_amount") or 0) > 0:
            raise PerfFixtureError(f"{store} refund fixture order #{order_id} is already refunded")
        return

    if kind == "capture":
        if not str(order.get("intent_id") or "").startswith("pi_"):
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} missing intent id")
        if not str(order.get("charge_id") or "").startswith("ch_"):
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} missing charge id")
        if not order.get("transaction_id"):
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} has no transaction id")
        if float(order.get("total") or 0) <= 0:
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} has no positive total")
        if float(order.get("refunded_amount") or 0) > 0:
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} is already refunded")
        if order.get("intention_status") != "requires_capture":
            raise PerfFixtureError(f"{store} capture fixture order #{order_id} intention status is {order.get('intention_status')}, expected requires_capture")
        return

    raise PerfFixtureError(f"Unknown perf fixture kind: {kind}")


def create_store_perf_fixtures(repo: Path, scripts_dir: Path, store: str, wp_cmd: str, native: bool) -> dict[str, Any]:
    sku = "test-lab-beaker-001"
    quantity = "2"
    fixture: dict[str, Any] = {"store": store, "native": native, "operations": {}}

    process_result = run_wp_eval_file(
        repo,
        f"{store}-wp",
        wp_cmd,
        scripts_dir / "flow-drive-unpaid-order.php",
        [sku, quantity],
    )
    fixture["operations"]["process_order"] = require_wp_payload(store, "process_order", process_result)
    process_order_id = int(fixture["operations"]["process_order"].get("order_id") or 0)

    paid_script = scripts_dir / ("flow-drive-native-charge.php" if native else "flow-drive-deterministic-charge.php")
    paid_args = [sku, quantity, "pm_card_visa" if native else "success", "0", ""]
    paid_result = run_wp_eval_file(repo, f"{store}-wp", wp_cmd, paid_script, paid_args)
    fixture["operations"]["refund_order"] = require_wp_payload(store, "refund_order", paid_result)
    refund_order_id = int(fixture["operations"]["refund_order"].get("order_id") or 0)

    capture_args = [sku, quantity, "pm_card_visa" if native else "success", "1", ""]
    capture_result = run_wp_eval_file(repo, f"{store}-wp", wp_cmd, paid_script, capture_args)
    fixture["operations"]["capture_order"] = require_wp_payload(store, "capture_order", capture_result)
    capture_order_id = int(fixture["operations"]["capture_order"].get("order_id") or 0)

    fixture["order_ids"] = {
        "process_payment": process_order_id,
        "refund": refund_order_id,
        "capture": capture_order_id,
    }
    fixture["preflight"] = {
        "process_payment": inspect_perf_order(repo, scripts_dir, store, wp_cmd, process_order_id),
        "refund": inspect_perf_order(repo, scripts_dir, store, wp_cmd, refund_order_id),
        "capture": inspect_perf_order(repo, scripts_dir, store, wp_cmd, capture_order_id),
    }
    validate_perf_order_fixture(store, "process_payment", fixture["preflight"]["process_payment"])
    validate_perf_order_fixture(store, "refund", fixture["preflight"]["refund"])
    validate_perf_order_fixture(store, "capture", fixture["preflight"]["capture"])

    return fixture


def create_perf_fixtures(repo: Path, scripts_dir: Path, ref_wp: str, target_wp: str) -> dict[str, Any]:
    stores = {
        "reference": create_store_perf_fixtures(repo, scripts_dir, "reference", ref_wp, False),
        "target": create_store_perf_fixtures(repo, scripts_dir, "target", target_wp, True),
    }
    return {
        "schema": "woopayments_perf_fixtures.v1",
        "status": "pass",
        "stores": stores,
    }
