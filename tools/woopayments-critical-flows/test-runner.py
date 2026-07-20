#!/usr/bin/env python3
"""Regression checks for the critical-flows runner fail-closed contract."""

from __future__ import annotations

import array
import calendar
import fcntl
import hashlib
import hmac
import importlib.util
import json
import os
import re
import select
import shlex
import shutil
import signal
import stat
import subprocess
import tempfile
import termios
import time
from pathlib import Path

import pytest

from tools.woopayments_test_runner import adapt_single_wp_runner


REPO = Path(__file__).resolve().parents[2]
RUNNER = REPO / "tools/woopayments-critical-flows/run.sh"
COMMON = REPO / "tools/woopayments-critical-flows/lib/common.sh"
FLOW_DRIVE = REPO / "tools/woopayments-merge/flow-drive.sh"
FLOW_CAPTURE_DRIVER = REPO / "tools/woopayments-merge/flow-drive-capture.php"
CONTEXT_MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"
MA09_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsma09driver.php"
)
MA10_VALIDATOR = REPO / "tools/woopayments-critical-flows/flows/ma10-validate.py"
LOG_OBSERVER_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowslogobserver.php"
)
MO01_COMPARATOR = REPO / "tools/woopayments-critical-flows/flows/mo01-compare.py"
MO02_EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/mo02-evidence.py"
MO03_EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/mo03-evidence.py"
MA01_EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/ma01-evidence.py"
MO03_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsmo03driver.php"
)
SC02_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowssc02driver.php"
)
SC02_FLOW = (
    REPO / "tools/woopayments-critical-flows/flows/SC-02-blocks-card-checkout.sh"
)
MD01_EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/md01-evidence.py"
MD01_FLOW = (
    REPO
    / "tools/woopayments-critical-flows/flows/MD-01-created-note-on-hold-notify.sh"
)
MD02_FLOW = REPO / "tools/woopayments-critical-flows/flows/MD-02-save-evidence.sh"
MD_RESOLUTION_EVIDENCE = (
    REPO / "tools/woopayments-critical-flows/flows/md-resolution-evidence.py"
)
MO03_CONTRACT = (
    REPO
    / "tools/woopayments-critical-flows/flows/"
    "MO-03-manual-capture-payment-details.md"
)
MO02_TEST_EPOCH = int(time.time())
MO03_TEST_EPOCH = MO02_TEST_EPOCH
TEST_RUN_STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime(MO03_TEST_EPOCH)) + "-30303"
TEST_MARKER_CREATED_AT = time.strftime(
    "%Y-%m-%dT%H:%M:%SZ", time.gmtime(MO03_TEST_EPOCH)
)
TEST_RUN_CONTEXT_KEY = "11" * 32
STARTUP_DRAIN_MAX_BYTES = 8 * 1024 * 1024
MO03_LOG_CONTEXT_ARGS = (
    "--flow-id",
    "MO-03-manual-capture-payment-details",
    "--purpose",
    "clean-debug-log",
)
MA10_LOG_CONTEXT_ARGS = (
    "--store",
    "target",
    "--flow-id",
    "MA-10-i18n-order-notes",
    "--purpose",
    "clean-debug-log",
)


def load_context_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", CONTEXT_MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTEXT_MODULE = load_context_module()


def ensure_context(evidence_dir: Path) -> tuple[Path, dict]:
    path = evidence_dir / "critical-flow-context.json"
    if path.exists():
        return path, json.loads(path.read_text(encoding="utf-8"))
    context = CONTEXT_MODULE.build_context(
        aggregate_run_id="runner-test",
        source={"head_sha": "a" * 40, "worktree_sha256": "sha256:" + "b" * 64},
        stores={
            "ref": {
                "store_fingerprint": "sha256:" + "c" * 64,
                "runtime_owner": "plugin",
                "account_state_sha256": "sha256:" + "d" * 64,
            },
            "target": {
                "store_fingerprint": "sha256:" + "e" * 64,
                "runtime_owner": "native",
                "account_state_sha256": "sha256:" + "f" * 64,
            },
        },
        fixtures={
            "ref": {"subscription_id": "1283"},
            "target": {"subscription_id": "874"},
        },
    )
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(context, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path, context


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def strip_recorded_at(rollup: dict) -> list[dict]:
    """Return rollup result rows with the volatile recorded_at stamp asserted and removed."""
    rows = rollup["results"]
    for row in rows:
        recorded_at = row.pop("recorded_at", None)
        assert isinstance(recorded_at, str) and recorded_at.endswith("Z"), (
            f"result row must carry a UTC recorded_at stamp: {row}"
        )
    return rows


def file_sha256(path: Path) -> str:
    """Return the runner's prefixed digest format for one evidence file."""
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def sc01_fake_wp_source(
    owner: str,
    home: str,
    intent_id: str,
    charge_id: str,
    *,
    command_prefix: str = "",
) -> str:
    """Fake wp CLI that answers the store-identity probe plus the SC-01 state asserts."""
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="SC-01-card-checkout",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    prefix_shift = (
        f'if [ "$1" = {shlex.quote(command_prefix)} ]; then shift; fi'
        if command_prefix
        else ""
    )
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload)}
{prefix_shift}
if [ "$1" = "option" ] && [ "$2" = "get" ]; then
  printf '%s\n' '{home}'
  exit 0
fi
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == "<?php"* && "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == "<?php"* && "$body" == *"ignored_matches"* ]]; then
    printf '%s\n' '{log_json}'
    exit 0
  fi
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value={intent_id}"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value={charge_id}"
    exit 0
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def probe_only_fake_wp_source(
    owner: str,
    home: str,
    *,
    flow_id: str = "SC-01-card-checkout",
) -> str:
    """Fake wp CLI that answers the store-identity probe and log-clean evals only."""
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id=flow_id,
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload)}
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == "<?php"* && "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == "<?php"* && "$body" == *"ignored_matches"* ]]; then
    printf '%s\n' '{log_json}'
    exit 0
  fi
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def sp01_fake_wp_source(
    owner: str,
    home: str,
    state_payload: dict,
    *,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for the SP-01 state driver and shared log assertions."""
    payload = json.dumps(state_payload, separators=(",", ":"))
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="SP-01-add-payment-method-card",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload)}
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    if [ {log_probe_exit_code} -ne 0 ]; then
      printf '%s\\n' 'fake log probe unavailable' >&2
      exit {log_probe_exit_code}
    fi
    printf '%s\\n' '{log_json}'
    exit 0
  fi
  printf '%s\\n' '{payload}'
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo01_state_payload(
    store: str,
    phase: str,
    *,
    status: str = "raw",
    intent_status: str | None = None,
    total_minor: int = 5000,
    runtime_owner: str | None = None,
    blockers: list[str] | None = None,
    provider_currency: str = "USD",
) -> dict:
    """Return one strict fake MO-01 provider/order state snapshot."""
    pre_capture = phase == "pre"
    order_id = 101 if store == "ref" else 202
    intent_id = f"pi_{store}_manual"
    charge_id = f"ch_{store}_manual"
    return {
        "schema": "woopayments_mo01_state.v1",
        "status": status,
        "blockers": blockers
        if blockers is not None
        else ([] if status != "blocked" else ["Provider state is unavailable."]),
        "store": store,
        "phase": phase,
        "runtime_owner": runtime_owner or ("plugin" if store == "ref" else "native"),
        "order": {
            "id": order_id,
            "status": "on-hold" if pre_capture else "processing",
            "paid": not pre_capture,
            "currency": "USD",
            "total_minor": total_minor,
            "payment_method": "woocommerce_payments",
            "intent_id": intent_id,
            "charge_id": charge_id,
            "intention_status": "requires_capture" if pre_capture else "succeeded",
        },
        "provider": {
            "intent_id": intent_id,
            "intent_status": intent_status
            or ("requires_capture" if pre_capture else "succeeded"),
            "intent_amount_minor": total_minor,
            "intent_currency": provider_currency,
            "charge_id": charge_id,
            "charge_amount_minor": total_minor,
            "charge_amount_captured_minor": 0 if pre_capture else total_minor,
            "charge_captured": not pre_capture,
            "charge_currency": provider_currency,
        },
        "notes": {
            "authorization_count": 1,
            "capture_success_count": 0 if pre_capture else 1,
            "capture_failure_count": 0,
        },
    }


def mo01_fake_wp_source(
    owner: str,
    home: str,
    pre_payload: dict,
    post_payload: dict,
    *,
    state_exit_code: int = 0,
    post_state_exit_code: int | None = None,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for MO-01 state snapshots plus shared runner probes."""
    pre = json.dumps(pre_payload, separators=(",", ":"))
    post = json.dumps(post_payload, separators=(",", ":"))
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="MO-01-manual-capture-order",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    post_exit_code = state_exit_code if post_state_exit_code is None else post_state_exit_code
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload)}
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == "<?php"* && "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == "<?php"* && "$body" == *"ignored_matches"* ]]; then
    if [ {log_probe_exit_code} -ne 0 ]; then
      printf '%s\\n' 'fake log probe unavailable' >&2
      exit {log_probe_exit_code}
    fi
    printf '%s\\n' '{log_json}'
    exit 0
  fi
  state_exit_code={state_exit_code}
  if [ "$5" = "post" ]; then
    state_exit_code={post_exit_code}
  fi
  if [ "$state_exit_code" -ne 0 ]; then
    printf '%s\\n' 'fake provider transport unavailable' >&2
    exit "$state_exit_code"
  fi
  if [ "$5" = "pre" ]; then
    printf '%s\\n' '{pre}'
  else
    printf '%s\\n' '{post}'
  fi
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo02_state_payload(
    store: str,
    phase: str,
    *,
    status: str = "raw",
    authorization_present: bool | None = None,
    total_minor: int = 5000,
    runtime_owner: str | None = None,
    blockers: list[str] | None = None,
) -> dict:
    """Return one strict fake MO-02 authorization-list/order/provider snapshot."""
    pre_capture = phase == "pre"
    present = pre_capture if authorization_present is None else authorization_present
    order_id = 101 if store == "ref" else 202
    intent_id = f"pi_{store}_uncaptured"
    charge_id = f"ch_{store}_uncaptured"
    matched_rows = []
    if present:
        matched_rows.append(
            {
                "charge_id": charge_id,
                "payment_intent_id": intent_id,
                "order_id": order_id,
                "amount_minor": total_minor,
                "amount_captured_minor": 0,
                "currency": "USD",
                "status": "succeeded",
                "created": MO02_TEST_EPOCH,
            }
        )
    return {
        "schema": "woopayments_mo02_state.v1",
        "status": status,
        "blockers": blockers
        if blockers is not None
        else ([] if status != "blocked" else ["Authorizations endpoint is unavailable."]),
        "store": store,
        "phase": phase,
        "runtime_owner": runtime_owner or ("plugin" if store == "ref" else "native"),
        "order": {
            "id": order_id,
            "created": MO02_TEST_EPOCH,
            "status": "on-hold" if pre_capture else "processing",
            "paid": not pre_capture,
            "currency": "USD",
            "total_minor": total_minor,
            "payment_method": "woocommerce_payments",
            "intent_id": intent_id,
            "charge_id": charge_id,
            "intention_status": "requires_capture" if pre_capture else "succeeded",
        },
        "provider": {
            "intent_id": intent_id,
            "intent_status": "requires_capture" if pre_capture else "succeeded",
            "intent_amount_minor": total_minor,
            "intent_currency": "USD",
            "charge_id": charge_id,
            "charge_amount_minor": total_minor,
            "charge_amount_captured_minor": 0 if pre_capture else total_minor,
            "charge_captured": not pre_capture,
            "charge_currency": "USD",
        },
        "authorizations": {
            "route": "/wc/v3/payments/authorizations",
            "http_status": 200,
            "pages_scanned": 1,
            "rows_scanned": 1 if present else 0,
            "observed_at": MO02_TEST_EPOCH,
            "exact_match_count": 1 if present else 0,
            "matched_rows": matched_rows,
        },
        "notes": {
            "authorization_count": 1,
            "capture_success_count": 0 if pre_capture else 1,
            "capture_failure_count": 0,
        },
    }


def mo02_capture_payload(store: str, *, status: str = "pass") -> dict:
    """Return one fake result from the MO-02 row-level capture REST route."""
    order_id = 101 if store == "ref" else 202
    payload = {
        "schema": "woopayments_mo02_capture.v1",
        "status": status,
        "store": store,
        "route": f"/wc/v3/payments/orders/{order_id}/capture_authorization",
        "order_id": order_id,
        "intent_id": f"pi_{store}_uncaptured",
        "charge_id": f"ch_{store}_uncaptured",
        "http_status": 200 if status == "pass" else 503 if status == "blocked" else 400,
        "success": status == "pass",
        "error_code": "" if status == "pass" else "capture_declined",
        "error_message": "" if status == "pass" else "Capture was declined.",
    }
    return payload


def mo02_fake_wp_source(
    owner: str,
    home: str,
    pre_payload: dict,
    post_payload: dict,
    capture_payload: dict,
    call_log: Path,
    *,
    state_exit_code: int = 0,
    post_state_exit_code: int | None = None,
    capture_exit_code: int = 0,
    log_probe_exit_code: int = 0,
) -> str:
    """Fake wp CLI for MO-02 snapshots, row capture, and shared runner probes."""
    pre = json.dumps(pre_payload, separators=(",", ":"))
    post = json.dumps(post_payload, separators=(",", ":"))
    capture = json.dumps(capture_payload, separators=(",", ":"))
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="MO-02-manual-capture-uncaptured-tab",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    post_exit_code = state_exit_code if post_state_exit_code is None else post_state_exit_code
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload)}
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == "<?php"* && "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == "<?php"* && "$body" == *"ignored_matches"* ]]; then
    if [ {log_probe_exit_code} -ne 0 ]; then
      printf '%s\\n' 'fake log probe unavailable' >&2
      exit {log_probe_exit_code}
    fi
    printf '%s\\n' '{log_json}'
    exit 0
  fi
  if [ "$4" = "capture" ]; then
    printf '%s\\n' '{pre_payload["store"]}:capture' >> {shlex.quote(str(call_log))}
    printf '%s\\n' '{capture}'
    exit {capture_exit_code}
  fi
  state_exit_code={state_exit_code}
  if [ "$6" = "post" ]; then
    state_exit_code={post_exit_code}
  fi
  if [ "$state_exit_code" -ne 0 ]; then
    printf '%s\\n' 'fake authorizations transport unavailable' >&2
    exit "$state_exit_code"
  fi
  if [ "$6" = "pre" ]; then
    printf '%s\\n' '{pre}'
  else
    printf '%s\\n' '{post}'
  fi
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo03_state_payload(
    store: str,
    phase: str,
    *,
    status: str = "raw",
    authorization_present: bool | None = None,
    total_minor: int = 5000,
    runtime_owner: str | None = None,
    blockers: list[str] | None = None,
    order_created: int | None = None,
    observed_at: int | None = None,
    row_created: int | None = None,
    authorization_note_count: int = 1,
) -> dict:
    """Return one strict fake MO-03 payment-details state snapshot."""
    pre_capture = phase == "pre"
    present = pre_capture if authorization_present is None else authorization_present
    order_id = 301 if store == "ref" else 302
    intent_id = f"pi_{store}_details"
    charge_id = f"ch_{store}_details"
    matched_rows = []
    if present:
        matched_rows.append(
            {
                "charge_id": charge_id,
                "payment_intent_id": intent_id,
                "order_id": order_id,
                "amount_minor": total_minor,
                "amount_captured_minor": 0,
                "currency": "USD",
                "status": "succeeded",
                "created": row_created if row_created is not None else MO03_TEST_EPOCH,
            }
        )
    return {
        "schema": "woopayments_mo03_state.v1",
        "status": status,
        "blockers": blockers
        if blockers is not None
        else ([] if status != "blocked" else ["Payment details state is unavailable."]),
        "store": store,
        "phase": phase,
        "runtime_owner": runtime_owner or ("plugin" if store == "ref" else "native"),
        "order": {
            "id": order_id,
            "created": order_created if order_created is not None else MO03_TEST_EPOCH,
            "status": "on-hold" if pre_capture else "processing",
            "paid": not pre_capture,
            "currency": "USD",
            "total_minor": total_minor,
            "payment_method": "woocommerce_payments",
            "intent_id": intent_id,
            "charge_id": charge_id,
            "intention_status": "requires_capture" if pre_capture else "succeeded",
        },
        "provider": {
            "intent_id": intent_id,
            "intent_status": "requires_capture" if pre_capture else "succeeded",
            "intent_amount_minor": total_minor,
            "intent_currency": "USD",
            "charge_id": charge_id,
            "charge_amount_minor": total_minor,
            "charge_amount_captured_minor": 0 if pre_capture else total_minor,
            "charge_captured": not pre_capture,
            "charge_currency": "USD",
        },
        "authorizations": {
            "route": "/wc/v3/payments/authorizations",
            "http_status": 200,
            "pages_scanned": 1,
            "rows_scanned": 1 if present else 0,
            "observed_at": observed_at if observed_at is not None else MO03_TEST_EPOCH,
            "exact_match_count": 1 if present else 0,
            "matched_rows": matched_rows,
        },
        "notes": {
            "authorization_count": authorization_note_count,
            "capture_success_count": 0 if pre_capture else 1,
            "capture_failure_count": 0,
        },
    }


def mo03_capture_payload(store: str, *, status: str = "pass") -> dict:
    """Return one fake provider/order capture record from flow-drive.sh."""
    order_id = 301 if store == "ref" else 302
    passing = status == "pass"
    transient = status == "blocked"
    native = store == "target"
    payload = {
        "op": "capture",
        "order_id": order_id,
        "intent_id": f"pi_{store}_details",
        "charge_id": f"ch_{store}_details",
        "status": "processing" if passing else "on-hold",
        "intention_status": "succeeded" if passing else "requires_capture",
        "success": passing,
        "transport_kind": "native_outcome" if native else "plugin_http",
        "provider_status": "completed" if passing and native else "succeeded" if passing else "failed",
        "http_code": 0 if native and not transient else 200 if passing else 503 if transient else 402,
    }
    if not passing:
        payload.update(
            {
                "error_code": "http_503" if transient else "capture_declined",
                "error_message": (
                    "Provider service temporarily unavailable."
                    if transient
                    else "Capture was declined."
                ),
            }
        )
    return payload


def safe_log_record(
    *,
    path: str = "fake-debug.log",
    line: int = 12,
    category: str = "warning",
    diagnostic: str = "PHP Warning: deterministic fake warning",
) -> dict:
    """Return one secret-free log match projection."""
    return {
        "path": path,
        "line": line,
        "category": category,
        "fingerprint": "sha256:" + hashlib.sha256(diagnostic.encode("utf-8")).hexdigest(),
    }


def log_scan_v2(
    *,
    status: str = "pass",
    marker_created_at: str = "2026-07-16T16:00:00Z",
    run_stamp: str | None = None,
    start_line_count: int = 4,
    end_line_count: int = 4,
    path: str = "fake-debug.log",
    matches: list[dict] | None = None,
    ignored_matches: list[dict] | None = None,
    blocker_code: str = "",
) -> dict:
    """Return one current strict shared log-scan packet for legacy-named fixtures."""
    if run_stamp is None:
        try:
            run_stamp = time.strftime(
                "%Y%m%dT%H%M%SZ",
                time.strptime(marker_created_at, "%Y-%m-%dT%H:%M:%SZ"),
            ) + "-30303"
        except ValueError:
            run_stamp = "20260716T160000Z-30303"
    identity = "sha256:" + hashlib.sha256(b"fake-log-identity").hexdigest()
    prefix = "sha256:" + hashlib.sha256(b"fake-log-prefix").hexdigest()
    canary = "sha256:" + hashlib.sha256(b"fake-log-canary").hexdigest()
    return {
        "status": status,
        "run_stamp": run_stamp,
        "marker_created_at": marker_created_at,
        "observations": [
            {
                "path": path,
                "start_line_count": start_line_count,
                "end_line_count": end_line_count,
                "marker_identity_fingerprint": identity,
                "observed_identity_fingerprint": identity,
                "marker_prefix_fingerprint": prefix,
                "observed_prefix_fingerprint": prefix,
                "marker_canary_fingerprint": canary,
                "observed_canary_fingerprint": canary,
            }
        ],
        "matches": [] if matches is None else matches,
        "ignored_matches": [] if ignored_matches is None else ignored_matches,
        "blocker_code": blocker_code,
    }


def anchored_log_scan_v3(
    *,
    status: str = "pass",
    marker_created_at: str = "2026-07-16T16:00:00Z",
    start_line_count: int = 4,
    end_line_count: int = 4,
    path: str = "fake-debug.log",
    matches: list[dict] | None = None,
    ignored_matches: list[dict] | None = None,
    blocker_code: str = "",
    marker_identity_fingerprint: str | None = None,
    observed_identity_fingerprint: str | None = None,
    marker_prefix_fingerprint: str | None = None,
    observed_prefix_fingerprint: str | None = None,
) -> dict:
    """Return one strict anchored shared log-scan v3 payload."""
    identity = "sha256:" + hashlib.sha256(b"fake-log-identity").hexdigest()
    prefix = "sha256:" + hashlib.sha256(b"fake-log-prefix").hexdigest()
    return {
        "status": status,
        "marker_created_at": marker_created_at,
        "observations": [
            {
                "path": path,
                "start_line_count": start_line_count,
                "end_line_count": end_line_count,
                "marker_identity_fingerprint": marker_identity_fingerprint or identity,
                "observed_identity_fingerprint": observed_identity_fingerprint or identity,
                "marker_prefix_fingerprint": marker_prefix_fingerprint or prefix,
                "observed_prefix_fingerprint": observed_prefix_fingerprint or prefix,
            }
        ],
        "matches": [] if matches is None else matches,
        "ignored_matches": [] if ignored_matches is None else ignored_matches,
        "blocker_code": blocker_code,
    }


def canary_log_scan_v4(
    *,
    status: str = "pass",
    run_stamp: str = "20260716T160000Z-30303",
    marker_created_at: str = "2026-07-16T16:00:00Z",
    start_line_count: int = 5,
    end_line_count: int = 5,
    path: str = "fake-debug.log",
    matches: list[dict] | None = None,
    ignored_matches: list[dict] | None = None,
    blocker_code: str = "",
    observed_identity_fingerprint: str | None = None,
    observed_prefix_fingerprint: str | None = None,
    observed_canary_fingerprint: str | None = None,
) -> dict:
    """Return one exact-origin, canary-bound shared log-scan v4 payload."""
    identity = "sha256:" + hashlib.sha256(b"fake-log-identity").hexdigest()
    prefix = "sha256:" + hashlib.sha256(b"fake-log-prefix-with-canary").hexdigest()
    canary = "sha256:" + hashlib.sha256(b"fake-log-canary").hexdigest()
    return {
        "status": status,
        "run_stamp": run_stamp,
        "marker_created_at": marker_created_at,
        "observations": [
            {
                "path": path,
                "start_line_count": start_line_count,
                "end_line_count": end_line_count,
                "marker_identity_fingerprint": identity,
                "observed_identity_fingerprint": observed_identity_fingerprint or identity,
                "marker_prefix_fingerprint": prefix,
                "observed_prefix_fingerprint": observed_prefix_fingerprint or prefix,
                "marker_canary_fingerprint": canary,
                "observed_canary_fingerprint": observed_canary_fingerprint or canary,
            }
        ],
        "matches": [] if matches is None else matches,
        "ignored_matches": [] if ignored_matches is None else ignored_matches,
        "blocker_code": blocker_code,
    }


def common_log_scan_v5(
    *,
    store: str = "target",
    flow_id: str = "MO-03-manual-capture-payment-details",
    purpose: str = "clean-debug-log",
    status: str = "pass",
    run_stamp: str = "20260716T160000Z-30303",
    marker_created_at: str = "2026-07-16T16:00:00Z",
    start_line_count: int = 4,
    end_line_count: int = 4,
    start_byte_count: int = 128,
    end_byte_count: int = 128,
    path: str = "fake-debug.log",
    matches: list[dict] | None = None,
    ignored_matches: list[dict] | None = None,
    blocker_code: str = "",
    observed_identity_fingerprint: str | None = None,
    observed_prefix_fingerprint: str | None = None,
    observed_canary_fingerprint: str | None = None,
    origin_nonce: str = "00000000-0000-4000-8000-000000000111",
    observer_id: str = "00000000-0000-4000-8000-000000000777",
) -> dict:
    """Return one strict authenticated common log-scan v5 producer payload."""
    identity = "sha256:" + hashlib.sha256(b"fake-log-identity").hexdigest()
    prefix = "sha256:" + hashlib.sha256(b"fake-log-prefix-with-canary").hexdigest()
    canary = "sha256:" + hashlib.sha256(b"fake-log-canary").hexdigest()
    owner = 501
    group = 20
    mode = 0o640
    marker_path = f"/tmp/{path}"
    path_id = "hmac-sha256:" + hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        b"woopayments_debug_log_path.v1\0" + marker_path.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    marker = {
        "schema": "woopayments_debug_log_marker.v6",
        "created_at": marker_created_at,
        "run_stamp": run_stamp,
        "store": store,
        "flow_id": flow_id,
        "purpose": purpose,
        "origin_nonce": origin_nonce,
        "observer_id": observer_id,
        "key_fingerprint": "sha256:"
        + hashlib.sha256(bytes.fromhex(TEST_RUN_CONTEXT_KEY)).hexdigest(),
        "origin_binding": "",
        "paths": {
            marker_path: {
                "path_id": path_id,
                "line_count": start_line_count,
                "byte_count": start_byte_count,
                "identity_fingerprint": identity,
                "prefix_fingerprint": prefix,
                "canary_fingerprint": canary,
                "owner": owner,
                "group": group,
                "mode": mode,
            }
        },
    }
    marker["origin_binding"] = "hmac-sha256:" + hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        log_observer_origin_material(marker),
        hashlib.sha256,
    ).hexdigest()
    return {
        "status": status,
        "run_stamp": run_stamp,
        "store": store,
        "flow_id": flow_id,
        "purpose": purpose,
        "marker_created_at": marker_created_at,
        "origin_nonce": origin_nonce,
        "observer_id": observer_id,
        "key_fingerprint": marker["key_fingerprint"],
        "origin_binding": marker["origin_binding"],
        "observations": [
            {
                "path": path,
                "path_id": path_id,
                "start_line_count": start_line_count,
                "end_line_count": end_line_count,
                "start_byte_count": start_byte_count,
                "end_byte_count": end_byte_count,
                "marker_identity_fingerprint": identity,
                "observed_identity_fingerprint": observed_identity_fingerprint or identity,
                "marker_prefix_fingerprint": prefix,
                "observed_prefix_fingerprint": observed_prefix_fingerprint or prefix,
                "marker_canary_fingerprint": canary,
                "observed_canary_fingerprint": observed_canary_fingerprint or canary,
                "marker_owner": owner,
                "observed_owner": owner,
                "marker_group": group,
                "observed_group": group,
                "marker_mode": mode,
                "observed_mode": mode,
            }
        ],
        "matches": [] if matches is None else matches,
        "ignored_matches": [] if ignored_matches is None else ignored_matches,
        "blocker_code": blocker_code,
    }


def ma10_log_scan_v5(**overrides: object) -> dict:
    """Return one exact target/MA-10/clean-debug-log common scan fixture."""
    return common_log_scan_v5(
        store="target",
        flow_id="MA-10-i18n-order-notes",
        purpose="clean-debug-log",
        **overrides,
    )


def mo03_ref_log_scan_v5(**overrides: object) -> dict:
    """Return one exact reference/MO-03/clean-debug-log common scan fixture."""
    return common_log_scan_v5(
        store="ref",
        flow_id="MO-03-manual-capture-payment-details",
        purpose="clean-debug-log",
        **overrides,
    )


def common_log_evidence_v5(
    *,
    store: str = "target",
    scan: dict | None = None,
    extra_observer_categories: tuple[str, ...] = (),
) -> dict:
    """Return one strict archived common v5 packet with its observer summary."""
    current_scan = common_log_scan_v5(store=store) if scan is None else scan
    records: list[dict] = []
    previous = "0" * 64
    path_contexts = sorted(
        (
            {"path": item["path"], "path_id": item["path_id"]}
            for item in current_scan.get("observations", [])
        ),
        key=lambda item: item["path_id"],
    )

    def append_record(kind: str, **fields: object) -> None:
        nonlocal previous
        record = {
            "schema": "woopayments_debug_log_observer_record.v2",
            "sequence": len(records) + 1,
            "kind": kind,
            "run_stamp": current_scan.get("run_stamp", ""),
            "store": current_scan.get("store", ""),
            "flow_id": current_scan.get("flow_id", ""),
            "purpose": current_scan.get("purpose", ""),
            "marker_created_at": current_scan.get("marker_created_at", ""),
            "observer_id": current_scan.get("observer_id", ""),
            "paths": path_contexts,
            "previous_hmac": "hmac-sha256:" + previous,
            **fields,
        }
        canonical = json.dumps(
            record, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        )
        previous = hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            (record["previous_hmac"].removeprefix("hmac-sha256:") + "\0" + canonical).encode(
                "utf-8"
            ),
            hashlib.sha256,
        ).hexdigest()
        record["hmac"] = "hmac-sha256:" + previous
        records.append(record)

    observations = current_scan.get("observations", [])
    append_record(
        "ready",
        status="pass",
        path_count=len(observations),
        key_fingerprint=current_scan.get("key_fingerprint", ""),
        origin_binding=current_scan.get("origin_binding", ""),
    )
    for projected in (
        *current_scan.get("matches", []),
        *current_scan.get("ignored_matches", []),
    ):
        append_record("line", **projected)
    for index, category in enumerate(extra_observer_categories, start=1):
        observation = observations[0]
        append_record(
            "line",
            path=observation["path"],
            line=observation["end_line_count"],
            category=category,
            fingerprint="sha256:"
            + hashlib.sha256(f"extra:{category}:{index}".encode()).hexdigest(),
        )
    for observation in observations:
        append_record(
            "line",
            path=observation["path"],
            line=observation["end_line_count"],
            category="terminal",
            fingerprint="sha256:"
            + hashlib.sha256(
                f"terminal:{observation['path']}:{observation['end_line_count']}".encode()
            ).hexdigest(),
        )
    append_record("complete", status="pass")
    counts: dict[str, int] = {}
    for record in records:
        if record["kind"] == "line":
            category = str(record["category"])
            counts[category] = counts.get(category, 0) + 1
    line_event_count = sum(counts.values())
    summary = {
        "schema": "woopayments_debug_log_observer_summary.v2",
        "store": current_scan.get("store", ""),
        "flow_id": current_scan.get("flow_id", ""),
        "purpose": current_scan.get("purpose", ""),
        "marker_created_at": current_scan.get("marker_created_at", ""),
        "observer_id": current_scan.get("observer_id", ""),
        "paths": path_contexts,
        "key_fingerprint": current_scan.get("key_fingerprint", ""),
        "origin_binding": current_scan.get("origin_binding", ""),
        "records": records,
        "record_count": len(records),
        "line_event_count": line_event_count,
        "category_counts": counts,
        "chain_head": "hmac-sha256:" + previous,
    }
    archived_scan = {**current_scan, "observer_summary": summary}
    return {
        "schema": "woopayments_debug_log_scan.v6",
        "store": store,
        "scan": archived_scan,
    }


def rewrite_warning_fail_to_pass_log_evidence(packet: dict) -> dict:
    """Rewrite one warning failure and its unsigned summary while retaining its origin."""
    forged = json.loads(json.dumps(packet))
    scan = forged["scan"]
    retained_origin = scan["origin_binding"]
    retained_observer = scan["observer_id"]
    scan.update(
        {
            "status": "pass",
            "matches": [],
            "ignored_matches": [],
            "blocker_code": "",
        }
    )
    scan["observer_summary"].update(
        {
            "record_count": 3,
            "line_event_count": 1,
            "category_counts": {"terminal": 1},
            "chain_head": "hmac-sha256:" + "a" * 64,
        }
    )
    assert scan["origin_binding"] == retained_origin
    assert scan["observer_summary"]["origin_binding"] == retained_origin
    assert scan["observer_id"] == retained_observer
    return forged


def forged_warning_summary_pass(
    *, store: str = "target", flow_id: str = "MO-03-manual-capture-payment-details"
) -> tuple[dict, dict]:
    """Return an authentic warning failure and a coherent unsigned PASS rewrite."""
    warning = safe_log_record(
        line=5,
        category="warning",
        diagnostic="PHP Warning: authenticated history must not disappear",
    )
    failing = common_log_evidence_v5(
        store=store,
        scan=common_log_scan_v5(
            store=store,
            flow_id=flow_id,
            status="fail",
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
            end_line_count=5,
            end_byte_count=192,
            matches=[warning],
        ),
    )
    return failing, rewrite_warning_fail_to_pass_log_evidence(failing)


def run_common_archived_log_validator(packet: dict) -> subprocess.CompletedProcess[str]:
    """Execute common.sh's literal archived log validator with a supplied summary."""
    source = COMMON.read_text(encoding="utf-8")
    shell_start = source.index('LOG_SCAN_RAW="$raw" python3 -')
    python_start = source.index("import collections", shell_start)
    python_end = source.index("\nPY\n}", python_start)
    validator = source[python_start:python_end]
    raw_scan = json.loads(json.dumps(packet["scan"]))
    summary = raw_scan.pop("observer_summary")
    flow_id = raw_scan.get("flow_id", "")
    purpose = raw_scan.get("purpose", "")
    with tempfile.TemporaryDirectory(prefix="critical-flows-common-log-validator-") as tmp:
        script = Path(tmp) / "common-log-validator.py"
        script.write_text(validator, encoding="utf-8")
        return subprocess.run(
            [
                "python3",
                str(script),
                packet["store"],
                "",
                TEST_RUN_STAMP,
                flow_id,
                purpose,
            ],
            cwd=REPO,
            env={
                **os.environ,
                "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
                "CRITICAL_FLOWS_LOG_OBSERVER_SUMMARY": json.dumps(
                    summary, separators=(",", ":")
                ),
                "LOG_SCAN_RAW": json.dumps(raw_scan, separators=(",", ":")),
            },
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


def run_mo03_log_normalizer(
    packet: dict, *, store: str, expected_exit_code: int
) -> tuple[subprocess.CompletedProcess[str], dict]:
    """Run the literal MO-03 log normalizer against one archived common packet."""
    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-normalizer-") as tmp:
        root = Path(tmp)
        input_path = root / "raw.json"
        output_path = root / "typed.json"
        input_path.write_text(json.dumps(packet), encoding="utf-8")
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-log-scan",
                "--input",
                str(input_path),
                "--output",
                str(output_path),
                "--store",
                store,
                "--run-stamp",
                TEST_RUN_STAMP,
                "--expected-exit-code",
                str(expected_exit_code),
                "--flow-id",
                "MO-03-manual-capture-payment-details",
                "--purpose",
                "clean-debug-log",
            ],
            cwd=REPO,
            env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        output = (
            json.loads(output_path.read_text(encoding="utf-8"))
            if output_path.is_file()
            else {}
        )
        return result, output


def unobserved_common_log_evidence_v5(
    *,
    store: str = "target",
    flow_id: str = "MA-10-i18n-order-notes",
    purpose: str = "clean-debug-log",
    run_stamp: str = TEST_RUN_STAMP,
    blocker_code: str,
) -> dict:
    """Return the sole strict common-v5 shape allowed without trusted origin evidence."""
    return {
        "schema": "woopayments_debug_log_scan.v6",
        "store": store,
        "scan": {
            "status": "blocked",
            "run_stamp": run_stamp,
            "store": store,
            "flow_id": flow_id,
            "purpose": purpose,
            "marker_created_at": "",
            "origin_nonce": "",
            "observer_id": "",
            "key_fingerprint": "",
            "origin_binding": "",
            "observations": [],
            "matches": [],
            "ignored_matches": [],
            "blocker_code": blocker_code,
            "observer_summary": {},
        },
    }


def validate_ma10_log_scan_for_test(module, payload: dict, run_stamp: str) -> str:
    """Validate MA-10 log evidence under the deterministic current-run test key."""
    prior_key = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY")
    os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = TEST_RUN_CONTEXT_KEY
    try:
        return module.validate_log_scan(payload, run_stamp)
    finally:
        if prior_key is None:
            os.environ.pop("CRITICAL_FLOWS_RUN_CONTEXT_KEY", None)
        else:
            os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = prior_key


def test_common_log_rejects_forged_pass_summary_with_retained_origin() -> None:
    failing, forged = forged_warning_summary_pass(store="target")

    authentic_failure = run_common_archived_log_validator(failing)
    assert authentic_failure.returncode == 1, (
        authentic_failure.stdout + authentic_failure.stderr
    )
    rewritten_pass = run_common_archived_log_validator(forged)
    assert rewritten_pass.returncode == 3, (
        "common.sh accepted a warning FAIL->PASS rewrite with a fabricated chain head; "
        + rewritten_pass.stdout
        + rewritten_pass.stderr
    )


def test_mo03_log_rejects_forged_pass_summary_with_retained_origin() -> None:
    failing, forged = forged_warning_summary_pass(store="ref")

    authentic_failure, _ = run_mo03_log_normalizer(
        failing, store="ref", expected_exit_code=1
    )
    assert authentic_failure.returncode == 1, (
        authentic_failure.stdout + authentic_failure.stderr
    )
    rewritten_pass, evidence = run_mo03_log_normalizer(
        forged, store="ref", expected_exit_code=0
    )
    assert rewritten_pass.returncode == 3, (
        "MO-03 accepted a warning FAIL->PASS rewrite with retained origin evidence; "
        + rewritten_pass.stdout
        + rewritten_pass.stderr
    )
    assert evidence.get("status") == "blocked"


def test_ma10_log_rejects_forged_pass_summary_with_retained_origin() -> None:
    spec = importlib.util.spec_from_file_location(
        "ma10_validator_unsigned_summary_for_test", MA10_VALIDATOR
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    failing, forged = forged_warning_summary_pass(
        store="target", flow_id="MA-10-i18n-order-notes"
    )

    assert validate_ma10_log_scan_for_test(module, failing, TEST_RUN_STAMP) == "fail"
    try:
        validate_ma10_log_scan_for_test(module, forged, TEST_RUN_STAMP)
    except module.EvidenceError:
        pass
    else:
        raise AssertionError(
            "MA-10 accepted a warning FAIL->PASS rewrite with a fabricated chain head"
        )


def test_v6_retained_observer_records_reject_malformed_and_reordered_chains() -> None:
    spec = importlib.util.spec_from_file_location(
        "ma10_validator_retained_records_for_test", MA10_VALIDATOR
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    current_mo03 = common_log_evidence_v5(
        scan=common_log_scan_v5(
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
        )
    )
    current_ma10 = common_log_evidence_v5(
        scan=ma10_log_scan_v5(
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
        )
    )
    assert run_common_archived_log_validator(current_mo03).returncode == 0
    assert run_common_archived_log_validator(current_ma10).returncode == 0
    valid_mo03, _ = run_mo03_log_normalizer(
        current_mo03, store="target", expected_exit_code=0
    )
    assert valid_mo03.returncode == 0, valid_mo03.stdout + valid_mo03.stderr
    assert validate_ma10_log_scan_for_test(module, current_ma10, TEST_RUN_STAMP) == "pass"

    def adversaries(packet: dict) -> dict[str, dict]:
        """Return identical retained-chain attacks bound to one literal flow packet."""
        malformed_hmac = json.loads(json.dumps(packet))
        malformed_hmac["scan"]["observer_summary"]["records"][1]["hmac"] = (
            "hmac-sha256:" + "0" * 64
        )
        reordered = json.loads(json.dumps(packet))
        records = reordered["scan"]["observer_summary"]["records"]
        records[0], records[1] = records[1], records[0]
        extra_field = json.loads(json.dumps(packet))
        extra_field["scan"]["observer_summary"]["records"][1]["diagnostic"] = (
            "Authorization: Bearer MUST_NOT_ESCAPE"
        )
        downgraded_summary = json.loads(json.dumps(packet))
        downgraded_summary["scan"]["observer_summary"]["schema"] = (
            "woopayments_debug_log_observer_summary.v1"
        )
        return {
            "malformed_hmac": malformed_hmac,
            "reordered": reordered,
            "extra_field": extra_field,
            "downgraded_summary": downgraded_summary,
        }

    ma10_adversaries = adversaries(current_ma10)
    for name, mo03_adversary in adversaries(current_mo03).items():
        ma10_adversary = ma10_adversaries[name]
        for packet in (mo03_adversary, ma10_adversary):
            common_result = run_common_archived_log_validator(packet)
            assert common_result.returncode == 3, (
                name,
                common_result.stdout,
                common_result.stderr,
            )
            assert "MUST_NOT_ESCAPE" not in common_result.stdout + common_result.stderr
        mo03_result, mo03_evidence = run_mo03_log_normalizer(
            mo03_adversary, store="target", expected_exit_code=0
        )
        assert mo03_result.returncode == 3, (
            name,
            mo03_result.stdout,
            mo03_result.stderr,
        )
        assert "MUST_NOT_ESCAPE" not in json.dumps(mo03_evidence)
        try:
            validate_ma10_log_scan_for_test(module, ma10_adversary, TEST_RUN_STAMP)
        except module.EvidenceError:
            pass
        else:
            raise AssertionError(f"MA-10 accepted malformed retained chain: {name}")


def extract_common_wp_eval(function_name: str) -> str:
    """Extract one exact embedded WP-CLI PHP producer from common.sh."""
    source = COMMON.read_text(encoding="utf-8")
    function_start = source.index(f"{function_name}()")
    function_source = source[function_start:]
    php_start_marker = (
        "raw=\"$(critical_flows_wp_eval_with_context \"$s\" '\n"
    )
    php_start = function_source.index(php_start_marker) + len(php_start_marker)
    php_end = function_source.index("\n' 2>&1)\"", php_start)
    php_source = function_source[php_start:php_end]
    replacements = {
        '$run_stamp = \'"$run_stamp_literal"\';': '$run_stamp = getenv( "CRITICAL_FLOWS_RUN_STAMP" );',
        '$store = \'"$store_literal"\';': '$store = getenv( "CRITICAL_FLOWS_STORE" );',
        '$flow_id = \'"$flow_literal"\';': '$flow_id = getenv( "CRITICAL_FLOWS_FLOW_ID" );',
        '$purpose = \'"$purpose_literal"\';': '$purpose = getenv( "CRITICAL_FLOWS_LOG_PURPOSE" );',
        '$expected_store = \'"$store_literal"\';': '$expected_store = getenv( "CRITICAL_FLOWS_STORE" );',
        '$expected_flow_id = \'"$flow_literal"\';': '$expected_flow_id = getenv( "CRITICAL_FLOWS_FLOW_ID" );',
        '$expected_purpose = \'"$purpose_literal"\';': '$expected_purpose = getenv( "CRITICAL_FLOWS_LOG_PURPOSE" );',
    }
    for literal, replacement in replacements.items():
        php_source = php_source.replace(literal, replacement)
    return php_source


def common_log_producer_php_source() -> str:
    """Return an executable seam around the exact shared marker and scan PHP."""
    marker_php = extract_common_wp_eval("mark_log_clean_start")
    scan_php = extract_common_wp_eval("assert_log_clean")
    return f"""<?php
putenv( 'CRITICAL_FLOWS_RUN_CONTEXT_KEY={TEST_RUN_CONTEXT_KEY}' );
putenv( 'CRITICAL_FLOWS_STORE=target' );
putenv( 'CRITICAL_FLOWS_FLOW_ID=MO-03-manual-capture-payment-details' );
putenv( 'CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log' );
define( 'WP_DEBUG_LOG', $argv[1] );
define( 'WP_CONTENT_DIR', dirname( $argv[1] ) );
$GLOBALS['critical_flow_options'] = array();
$GLOBALS['critical_flow_output']  = array();

function update_option( $key, $value ) {{
    $GLOBALS['critical_flow_options'][ $key ] = $value;
}}

function get_option( $key, $default = array() ) {{
    return $GLOBALS['critical_flow_options'][ $key ] ?? $default;
}}

function wp_json_encode( $value ) {{
    return json_encode( $value );
}}

function wp_generate_uuid4() {{
    return '00000000-0000-4000-8000-000000000001';
}}

class WP_CLI {{
    public static function line( $line ) {{
        $GLOBALS['critical_flow_output'][] = $line;
    }}

    public static function error( $message ) {{
        throw new RuntimeException( $message );
    }}
}}

$original_content = file_get_contents( $argv[1] );

{marker_php}

$post_marker_content = file_get_contents( $argv[1] );

if ( 'same_count_replacement' === $argv[2] ) {{
    rename( $argv[1], $argv[1] . '.rotated' );
    file_put_contents( $argv[1], "replacement one\nreplacement two\nreplacement three\nreplacement four\nreplacement five\n" );
}} elseif ( 'truncate_regrow' === $argv[2] ) {{
    file_put_contents( $argv[1], "PHP Warning: hidden one\nreplacement two\nreplacement three\nreplacement four\nreplacement five\n" );
}} elseif ( 'exact_content_restore' === $argv[2] ) {{
    file_put_contents( $argv[1], "PHP Warning: erased after marker\n", FILE_APPEND );
    file_put_contents( $argv[1], $original_content );
}} elseif ( 'post_canary_snapshot_restore' === $argv[2] ) {{
    file_put_contents( $argv[1], "PHP Warning: erased after readable canary\n", FILE_APPEND );
    file_put_contents( $argv[1], $post_marker_content );
}} elseif ( 'post_canary_restore_safe_append' === $argv[2] ) {{
    file_put_contents( $argv[1], "PHP Warning: erased before safe append\n", FILE_APPEND );
    file_put_contents( $argv[1], $post_marker_content );
    file_put_contents( $argv[1], "ordinary application line\n", FILE_APPEND );
}} elseif ( 'ordinary_append' === $argv[2] ) {{
    file_put_contents( $argv[1], "ordinary application line\n", FILE_APPEND );
}} elseif ( 'invalid_utf8_rewrite' === $argv[2] ) {{
    $binary_lines    = file( $argv[1], FILE_IGNORE_NEW_LINES );
    $binary_lines[1] = chr( 254 ) . " rewritten invalid byte";
    file_put_contents( $argv[1], implode( "\n", $binary_lines ) . "\n" );
}} elseif ( 'crlf_to_lf_rewrite' === $argv[2] ) {{
    file_put_contents( $argv[1], str_replace( "\r\n", "\n", $post_marker_content ) );
}}
clearstatcache( true, $argv[1] );

{scan_php}

echo end( $GLOBALS['critical_flow_output'] ), "\n";
"""


def common_log_marker_php_source() -> str:
    """Return an executable seam around the exact shared v5 marker producer."""
    marker_php = extract_common_wp_eval("mark_log_clean_start")
    return f"""<?php
putenv( 'CRITICAL_FLOWS_STORE=target' );
putenv( 'CRITICAL_FLOWS_FLOW_ID=MO-03-manual-capture-payment-details' );
putenv( 'CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log' );
define( 'WP_DEBUG_LOG', $argv[1] );
define( 'WP_CONTENT_DIR', dirname( $argv[1] ) );
$GLOBALS['critical_flow_options'] = array();

function update_option( $key, $value ) {{
    $GLOBALS['critical_flow_options'][ $key ] = $value;
}}

function wp_json_encode( $value ) {{
    return json_encode( $value );
}}

function wp_generate_uuid4() {{
    static $sequence = 1;
    return sprintf( '00000000-0000-4000-8000-%012d', $sequence++ );
}}

class WP_CLI {{
    public static function line( $line ) {{}}

    public static function error( $message ) {{
        throw new RuntimeException( $message );
    }}
}}

{marker_php}

echo json_encode( $GLOBALS['critical_flow_options']['woopayments_critical_flows_debug_log_marker'] ), "\n";
"""


def log_observer_origin_material(marker: dict) -> bytes:
    """Return the versioned marker-origin material shared with the observer contract."""
    parts = [
        "woopayments_debug_log_origin.v2",
        marker["run_stamp"],
        marker["store"],
        marker["flow_id"],
        marker["purpose"],
        marker["created_at"],
        marker["origin_nonce"],
        marker["observer_id"],
        marker["key_fingerprint"],
    ]
    for path, observation in sorted(
        marker["paths"].items(), key=lambda item: item[1]["path_id"]
    ):
        parts.extend(
            [
                observation["path_id"],
                Path(path).name,
                str(observation["line_count"]),
                str(observation["byte_count"]),
                observation["identity_fingerprint"],
                observation["prefix_fingerprint"],
                observation["canary_fingerprint"],
                str(observation["owner"]),
                str(observation["group"]),
                str(observation["mode"]),
            ]
        )
    return "\0".join(parts).encode("utf-8")


def observer_terminal_line_for_path(case: dict, debug_log: Path) -> bytes:
    """Return the authenticated terminal line for one exact observed path."""
    marker = case["marker"]
    path = str(debug_log)
    observation = marker["paths"][path]
    material = "\0".join(
        (
            "terminal",
            marker["run_stamp"],
            marker["store"],
            marker["flow_id"],
            marker["purpose"],
            marker["created_at"],
            marker["observer_id"],
            observation["path_id"],
            debug_log.name,
        )
    ).encode()
    signature = hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY), material, hashlib.sha256
    ).hexdigest()
    return (
        "[woopayments-critical-flows-log-observer-stop] hmac-sha256:"
        + signature
        + "\n"
    ).encode()


def observer_terminal_line(case: dict) -> bytes:
    """Return the authenticated terminal line for the primary observed path."""
    return observer_terminal_line_for_path(case, case["debug_log"])


def add_log_observer_paths(case: dict, *, count: int) -> list[Path]:
    """Extend one observer fixture with exact authenticated sibling paths."""
    assert count >= 1
    debug_logs = [case["debug_log"]]
    for index in range(2, count + 1):
        debug_log = case["debug_log"].with_name(f"debug-{index}.log").resolve()
        debug_log.write_bytes(case["original"])
        debug_log.chmod(0o640)
        initial_stat = debug_log.stat()
        path_id = "hmac-sha256:" + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            b"woopayments_debug_log_path.v1\0" + str(debug_log).encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        canary = case["original"].splitlines()[-1]
        case["marker"]["paths"][str(debug_log)] = {
            "path_id": path_id,
            "line_count": case["original"].count(b"\n"),
            "byte_count": len(case["original"]),
            "identity_fingerprint": "sha256:"
            + hashlib.sha256(
                f"{initial_stat.st_dev}:{initial_stat.st_ino}".encode()
            ).hexdigest(),
            "prefix_fingerprint": "sha256:"
            + hashlib.sha256(case["original"]).hexdigest(),
            "canary_fingerprint": "sha256:" + hashlib.sha256(canary).hexdigest(),
            "owner": initial_stat.st_uid,
            "group": initial_stat.st_gid,
            "mode": stat.S_IMODE(initial_stat.st_mode),
        }
        debug_logs.append(debug_log)

    case["marker"]["origin_binding"] = "hmac-sha256:" + hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        log_observer_origin_material(case["marker"]),
        hashlib.sha256,
    ).hexdigest()
    assert len({item["path_id"] for item in case["marker"]["paths"].values()}) == count
    case["debug_logs"] = debug_logs
    return debug_logs


def prepare_log_observer_case(root: Path) -> dict:
    """Prepare one exact marker option and standalone WP-CLI observer harness."""
    debug_log = (root / "debug.log").resolve()
    canary_line = (
        "[woopayments-critical-flows-log-canary] sha256:"
        + hashlib.sha256(b"deterministic-observer-canary").hexdigest()
    )
    original = b"one\ntwo\nthree\nfour\n" + canary_line.encode() + b"\n"
    debug_log.write_bytes(original)
    debug_log.chmod(0o640)
    initial_stat = debug_log.stat()
    run_stamp = "20260716T160000Z-30303"
    observer_id = "00000000-0000-4000-8000-000000000777"
    path_id = "hmac-sha256:" + hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        b"woopayments_debug_log_path.v1\0" + str(debug_log).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    marker = {
        "schema": "woopayments_debug_log_marker.v6",
        "created_at": "2026-07-16T16:00:00Z",
        "run_stamp": run_stamp,
        "store": "target",
        "flow_id": "MO-03-manual-capture-payment-details",
        "purpose": "clean-debug-log",
        "origin_nonce": "00000000-0000-4000-8000-000000000666",
        "observer_id": observer_id,
        "key_fingerprint": "sha256:"
        + hashlib.sha256(bytes.fromhex(TEST_RUN_CONTEXT_KEY)).hexdigest(),
        "origin_binding": "",
        "paths": {
            str(debug_log): {
                "path_id": path_id,
                "line_count": 5,
                "byte_count": len(original),
                "identity_fingerprint": "sha256:"
                + hashlib.sha256(
                    f"{initial_stat.st_dev}:{initial_stat.st_ino}".encode()
                ).hexdigest(),
                "prefix_fingerprint": "sha256:" + hashlib.sha256(original).hexdigest(),
                "canary_fingerprint": "sha256:"
                + hashlib.sha256(canary_line.encode()).hexdigest(),
                "owner": initial_stat.st_uid,
                "group": initial_stat.st_gid,
                "mode": stat.S_IMODE(initial_stat.st_mode),
            }
        },
    }
    marker["origin_binding"] = "hmac-sha256:" + hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        log_observer_origin_material(marker),
        hashlib.sha256,
    ).hexdigest()
    wrapper = root / "observer-harness.php"
    wrapper.write_text(
        """<?php
$GLOBALS['critical_flow_marker'] = json_decode( getenv( 'TEST_LOG_MARKER_JSON' ), true );
function get_option( $key, $default = array() ) {
    return $GLOBALS['critical_flow_marker'] ?? $default;
}
function wp_json_encode( $value ) {
    static $lease_hook_used = false;
    static $unsigned_lease_calls = 0;
    $barrier = getenv( 'TEST_LEASE_BARRIER_DIR' );
    $unsigned_lease = is_array( $value )
        && 'woopayments_debug_log_forwarder_lease.v2' === ( $value['schema'] ?? '' )
        && ! isset( $value['hmac'] );
    if ( $unsigned_lease ) {
        ++$unsigned_lease_calls;
    }
    if ( ! $lease_hook_used && $unsigned_lease && is_string( $barrier ) && '' !== $barrier ) {
        $lease_hook_used = true;
        file_put_contents( $barrier . '/lease-encode-reached', (string) getmypid() );
        $deadline = hrtime( true ) + 5000000000;
        while ( ! file_exists( $barrier . '/lease-encode-release' ) && hrtime( true ) < $deadline ) {
            usleep( 1000 );
        }
        $file_size_limit = getenv( 'TEST_RLIMIT_FSIZE_AFTER_BARRIER' );
        if ( is_string( $file_size_limit ) && preg_match( '/^[0-9]+$/', $file_size_limit ) ) {
            if (
                ! function_exists( 'posix_setrlimit' )
                || ! defined( 'POSIX_RLIMIT_FSIZE' )
                || ! posix_setrlimit( POSIX_RLIMIT_FSIZE, (int) $file_size_limit, (int) $file_size_limit )
            ) {
                throw new RuntimeException( 'test RLIMIT_FSIZE setup failed' );
            }
        }
        if ( '1' === getenv( 'TEST_FAIL_LEASE_ENCODE' ) ) {
            return false;
        }
    }
    $validation_barrier = getenv( 'TEST_LEASE_VALIDATION_BARRIER_DIR' );
    if (
        $unsigned_lease
        && 2 === $unsigned_lease_calls
        && is_string( $validation_barrier )
        && '' !== $validation_barrier
    ) {
        file_put_contents( $validation_barrier . '/lease-validation-reached', (string) getmypid() );
        $deadline = hrtime( true ) + 5000000000;
        while ( ! file_exists( $validation_barrier . '/lease-validation-release' ) && hrtime( true ) < $deadline ) {
            usleep( 1000 );
        }
    }
    return json_encode( $value );
}
class WP_CLI {
    public static function line( $line ) { fwrite( STDOUT, $line . "\\n" ); fflush( STDOUT ); }
    public static function error( $message ) { throw new RuntimeException( $message ); }
}
if ( '1' === getenv( 'TEST_REAP_STARTUP_CHILD_EARLY' ) ) {
    pcntl_async_signals( true );
    pcntl_signal(
        SIGCHLD,
        static function () {
            $status = 0;
            while ( pcntl_waitpid( -1, $status, WNOHANG ) > 0 ) {
            }
        }
    );
}
if ( '1' === getenv( 'TEST_IGNORE_SIGXFSZ' ) && defined( 'SIGXFSZ' ) ) {
    if (
        ! function_exists( 'pcntl_sigprocmask' )
        || ! pcntl_sigprocmask( SIG_BLOCK, array( SIGXFSZ ) )
    ) {
        throw new RuntimeException( 'test SIGXFSZ mask setup failed' );
    }
}
$args = array( getenv( 'TEST_RUN_STAMP' ), getenv( 'TEST_OBSERVER_ACTION' ) ?: 'observe' );
require getenv( 'TEST_LOG_OBSERVER_DRIVER' );
""",
        encoding="utf-8",
    )
    backing_path = debug_log.with_name(
        f"{debug_log.name}.woopayments-critical-flows.{observer_id}.backing"
    )
    return {
        "backing_path": backing_path,
        "debug_log": debug_log,
        "initial_stat": initial_stat,
        "marker": marker,
        "original": original,
        "run_stamp": run_stamp,
        "observer_id": observer_id,
        "wrapper": wrapper,
    }


def launch_log_observer(
    case: dict,
    *,
    maximum_seconds: str = "2",
    action: str = "observe",
    context_key: str = TEST_RUN_CONTEXT_KEY,
    extra_environment: dict[str, str] | None = None,
) -> subprocess.Popen[str]:
    """Launch the standalone observer without exposing its context key in argv."""
    return subprocess.Popen(
        ["php", str(case["wrapper"])],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            **os.environ,
            "CRITICAL_FLOWS_RUN_CONTEXT_KEY": context_key,
            "CRITICAL_FLOWS_STORE": case["marker"]["store"],
            "CRITICAL_FLOWS_FLOW_ID": case["marker"]["flow_id"],
            "CRITICAL_FLOWS_LOG_PURPOSE": case["marker"]["purpose"],
            "CRITICAL_FLOWS_LOG_OBSERVER_MAX_SECONDS": maximum_seconds,
            "TEST_LOG_MARKER_JSON": json.dumps(case["marker"], separators=(",", ":")),
            "TEST_LOG_OBSERVER_DRIVER": str(LOG_OBSERVER_DRIVER),
            "TEST_OBSERVER_ACTION": action,
            "TEST_RUN_STAMP": case["run_stamp"],
            **(extra_environment or {}),
        },
    )


def wait_for_test_path(path: Path, *, timeout: float = 3.0) -> None:
    """Wait until one test synchronization path exists."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if path.exists() or path.is_symlink():
            return
        time.sleep(0.005)
    raise AssertionError(f"test path did not appear before deadline: {path.name}")


def direct_child_pids(parent_pid: int) -> list[int]:
    """Return the current direct children of one test-owned process."""
    result = subprocess.run(
        ["ps", "-axo", "pid=,ppid="],
        text=True,
        capture_output=True,
        check=True,
    )
    children = []
    for line in result.stdout.splitlines():
        fields = line.split()
        if len(fields) == 2 and int(fields[1]) == parent_pid:
            children.append(int(fields[0]))
    return sorted(children)


def process_exists(pid: int) -> bool:
    """Return whether one process identifier still names a process."""
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def wait_for_no_process(pid: int, *, timeout: float = 2.0) -> bool:
    """Wait conditionally until one exact process identifier disappears."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if not process_exists(pid):
            return True
        time.sleep(0.01)
    return not process_exists(pid)


def stop_test_owned_observer_child(pid: int, wrapper: Path) -> None:
    """Kill only a still-running child whose command names this test wrapper."""
    if not process_exists(pid):
        return
    command = subprocess.run(
        ["ps", "-p", str(pid), "-o", "command="],
        text=True,
        capture_output=True,
        check=False,
    ).stdout.strip()
    if str(wrapper) not in command:
        return
    os.kill(pid, signal.SIGKILL)
    wait_for_no_process(pid)


def wait_log_observer_record(
    process: subprocess.Popen[str],
    kind: str,
    *,
    timeout: float = 3.0,
    category: str | None = None,
) -> tuple[dict, list[str]]:
    """Wait conditionally for one safe observer record, never for an arbitrary sleep."""
    assert process.stdout is not None
    deadline = time.monotonic() + timeout
    encoded_records: list[str] = []
    while True:
        remaining = deadline - time.monotonic()
        assert remaining > 0, f"observer did not emit {kind} before its deadline"
        ready, _, _ = select.select([process.stdout], [], [], remaining)
        assert ready, f"observer did not emit {kind} before its deadline"
        line = process.stdout.readline()
        if not line:
            assert process.stderr is not None
            diagnostic = process.stderr.read()
            raise AssertionError(
                f"observer exited before {kind}; rc={process.poll()}; stderr={diagnostic}"
            )
        if not line.strip():
            continue
        encoded_records.append(line)
        try:
            record = json.loads(line)
        except json.JSONDecodeError as error:
            raise AssertionError("observer emitted non-JSON output") from error
        if record.get("kind") == kind and (
            category is None or record.get("category") == category
        ):
            return record, encoded_records


def write_observer_fifo(path: Path, content: bytes) -> None:
    """Write one nonblocking chunk to an observer FIFO after readiness."""
    descriptor = os.open(path, os.O_WRONLY | os.O_NONBLOCK)
    try:
        assert os.write(descriptor, content) == len(content)
    finally:
        os.close(descriptor)


def write_observer_fifo_fully(
    path: Path, content: bytes, *, timeout: float = 10.0
) -> None:
    """Write arbitrarily many bytes without blocking the test process."""
    descriptor = os.open(path, os.O_WRONLY | os.O_NONBLOCK)
    pending = memoryview(content)
    deadline = time.monotonic() + timeout
    try:
        while pending:
            assert time.monotonic() < deadline, "FIFO write exceeded its bounded deadline"
            _, writable, _ = select.select([], [descriptor], [], 0.1)
            if writable:
                pending = pending[os.write(descriptor, pending) :]
    finally:
        os.close(descriptor)


def stop_observer_process(process: subprocess.Popen[str]) -> None:
    """Bound test cleanup so a RED observer cannot leak a process."""
    if process.poll() is None:
        process.terminate()
        try:
            process.wait(timeout=2)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=2)


def wait_log_forwarder_lease(case: dict, *, timeout: float = 1.0) -> tuple[Path, dict]:
    """Wait conditionally for the authenticated lease that identifies the detached child."""
    lease_path = case["debug_log"].with_name(
        f"{case['debug_log'].name}.woopayments-critical-flows."
        f"{case['observer_id']}.lease"
    )
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if lease_path.is_file():
            lease = json.loads(lease_path.read_text(encoding="utf-8"))
            child_pid = lease.get("child_pid", lease.get("forwarder_pid"))
            assert isinstance(child_pid, int) and child_pid > 1
            return lease_path, lease
        time.sleep(0.02)
    raise AssertionError("observer ready did not create an authenticated forwarder lease")


def log_forwarder_artifact_paths(case: dict) -> dict[str, Path]:
    """Return the exact same-directory durable forwarder artifact paths."""
    prefix = (
        f"{case['debug_log'].name}.woopayments-critical-flows."
        f"{case['observer_id']}"
    )
    return {
        kind: case["debug_log"].with_name(f"{prefix}.{kind}")
        for kind in ("lease", "journal", "control")
    }


def php_json_bytes(value: object) -> bytes:
    """Encode the ASCII fixture shape like the observer harness's PHP json_encode."""
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).replace(
        "/", "\\/"
    ).encode("utf-8")


def validated_log_forwarder_journal(case: dict) -> list[dict]:
    """Independently validate the complete current observer journal HMAC chain."""
    journal_path = log_forwarder_artifact_paths(case)["journal"]
    encoded = journal_path.read_bytes()
    assert encoded.endswith(b"\n")
    records = [json.loads(line) for line in encoded.decode("utf-8").splitlines()]
    assert records
    previous = "0" * 64
    for sequence, signed_record in enumerate(records, start=1):
        record = dict(signed_record)
        signature = record.pop("hmac")
        canonical = {key: record[key] for key in sorted(record)}
        expected = "hmac-sha256:" + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            previous.encode("ascii") + b"\0" + php_json_bytes(canonical),
            hashlib.sha256,
        ).hexdigest()
        assert hmac.compare_digest(signature, expected)
        assert record["schema"] == "woopayments_debug_log_observer_record.v2"
        assert record["sequence"] == sequence
        assert record["previous_hmac"] == "hmac-sha256:" + previous
        assert record["run_stamp"] == case["run_stamp"]
        assert record["store"] == case["marker"]["store"]
        assert record["flow_id"] == case["marker"]["flow_id"]
        assert record["purpose"] == case["marker"]["purpose"]
        assert record["marker_created_at"] == case["marker"]["created_at"]
        assert record["observer_id"] == case["observer_id"]
        previous = signature.removeprefix("hmac-sha256:")
    return records


def write_signed_log_forwarder_lease(path: Path, lease: dict) -> None:
    """Rewrite one coherent lease fixture with its schema-keyed HMAC."""
    unsigned = dict(lease)
    unsigned.pop("hmac", None)
    signature = hmac.new(
        bytes.fromhex(TEST_RUN_CONTEXT_KEY),
        unsigned["schema"].encode("ascii") + b"\0" + php_json_bytes(unsigned),
        hashlib.sha256,
    ).hexdigest()
    unsigned["hmac"] = "hmac-sha256:" + signature
    path.write_bytes(php_json_bytes(unsigned))
    path.chmod(0o600)


def wait_log_forwarder_terminal(case: dict, *, timeout: float = 4.0) -> tuple[dict, list[dict]]:
    """Wait until the authenticated journal ends in a child terminal record."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            records = validated_log_forwarder_journal(case)
        except (AssertionError, FileNotFoundError, json.JSONDecodeError, UnicodeError):
            time.sleep(0.01)
            continue
        terminal = records[-1]
        if terminal.get("kind") in {"complete", "blocked"}:
            return terminal, records
        time.sleep(0.01)
    raise AssertionError("forwarder did not persist an authenticated terminal record")


def wait_log_forwarder_control(
    case: dict, action: str, *, timeout: float = 4.0
) -> dict:
    """Wait for and authenticate one exact forwarder control action."""
    control_path = log_forwarder_artifact_paths(case)["control"]
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            control = json.loads(control_path.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError):
            time.sleep(0.01)
            continue
        if control.get("action") != action:
            time.sleep(0.01)
            continue
        signature = control.pop("hmac")
        expected = "hmac-sha256:" + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            b"woopayments_debug_log_forwarder_control.v1\0"
            + php_json_bytes(control),
            hashlib.sha256,
        ).hexdigest()
        assert hmac.compare_digest(signature, expected)
        assert control["run_stamp"] == case["run_stamp"]
        assert control["store"] == case["marker"]["store"]
        assert control["flow_id"] == case["marker"]["flow_id"]
        assert control["purpose"] == case["marker"]["purpose"]
        assert control["marker_created_at"] == case["marker"]["created_at"]
        assert control["observer_id"] == case["observer_id"]
        return control
    raise AssertionError(f"observer did not write authenticated {action} control")


def fifo_bytes_available(path: Path) -> int:
    """Return the exact unread byte count currently held by one named FIFO."""
    descriptor = os.open(path, os.O_RDONLY | os.O_NONBLOCK)
    try:
        available = array.array("i", [0])
        fcntl.ioctl(descriptor, termios.FIONREAD, available, True)
        return available[0]
    finally:
        os.close(descriptor)


def fifo_descriptor_bytes_available(descriptor: int) -> int:
    """Return unread bytes without opening or consuming the retained FIFO."""
    available = array.array("i", [0])
    fcntl.ioctl(descriptor, termios.FIONREAD, available, True)
    return available[0]


def launch_counted_fifo_writer(
    path: Path,
    total_bytes: int,
    *,
    pause_after: int = 0,
    pause_seconds: float = 0.0,
) -> subprocess.Popen[str]:
    """Write bounded FIFO bytes and report only the count accepted by the kernel."""
    source = r"""
import os
import select
import signal
import sys
import time

path = sys.argv[1]
target = int(sys.argv[2])
pause_after = int(sys.argv[3])
pause_seconds = float(sys.argv[4])
descriptor = os.open(path, os.O_WRONLY | os.O_NONBLOCK)
accepted = 0
paused = False
running = True
deadline = time.monotonic() + 10
chunk = b"x" * 4096
def stop(_signum, _frame):
    global running
    running = False
signal.signal(signal.SIGTERM, stop)
try:
    while running and accepted < target and time.monotonic() < deadline:
        if pause_after and not paused and accepted >= pause_after:
            time.sleep(pause_seconds)
            paused = True
        try:
            accepted += os.write(descriptor, chunk[: min(len(chunk), target - accepted)])
        except BlockingIOError:
            select.select([], [descriptor], [], 0.01)
        except BrokenPipeError:
            break
finally:
    os.close(descriptor)
print(accepted, flush=True)
"""
    return subprocess.Popen(
        [
            "python3",
            "-c",
            source,
            str(path),
            str(total_bytes),
            str(pause_after),
            str(pause_seconds),
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
    )


def wait_counted_fifo_writer(writer: subprocess.Popen[str]) -> int:
    """Return one test-owned writer's exact accepted-byte count."""
    stdout, stderr = writer.communicate(timeout=12)
    assert writer.returncode == 0, stdout + stderr
    assert re.fullmatch(r"[0-9]+\n", stdout), stdout + stderr
    return int(stdout)


def wait_fifo_bytes_available(
    path: Path, expected: int, *, timeout: float = 2.0
) -> None:
    """Wait conditionally until the FIFO kernel queue has the exact byte count."""
    deadline = time.monotonic() + timeout
    observed = -1
    while time.monotonic() < deadline:
        observed = fifo_bytes_available(path)
        if observed == expected:
            return
        time.sleep(0.01)
    raise AssertionError(
        f"FIFO did not reach {expected} unread bytes; last observed {observed}"
    )


def forwarder_child_pid(lease: dict) -> int:
    """Return the detached child PID from the strict lease."""
    child_pid = lease.get("child_pid", lease.get("forwarder_pid"))
    assert isinstance(child_pid, int) and child_pid > 1
    return child_pid


def process_exists(pid: int) -> bool:
    """Return whether a process still answers the non-mutating signal-zero probe."""
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def wait_process_absent(pid: int, *, timeout: float = 2.0) -> None:
    """Require a forwarder to disappear before the bounded recovery deadline."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if not process_exists(pid):
            return
        time.sleep(0.02)
    raise AssertionError(f"forwarder child {pid} remained orphaned after recovery")


def terminate_forwarder_for_test(pid: int | None) -> None:
    """Best-effort bounded cleanup for a RED test's detached process."""
    if pid is None or not process_exists(pid):
        return
    for signum in (signal.SIGCONT, signal.SIGTERM):
        try:
            os.kill(pid, signum)
        except ProcessLookupError:
            return
    deadline = time.monotonic() + 1.0
    while time.monotonic() < deadline and process_exists(pid):
        time.sleep(0.02)
    if process_exists(pid):
        try:
            os.kill(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass


def default_wp_boundary_shim_source(kind: str) -> str:
    """Return a Docker/pnpm shim that validates stdin key transport without retaining it."""
    return f"""#!/usr/bin/env bash
set -eu
source_payload="$(cat)"
unset CRITICAL_FLOWS_RUN_CONTEXT_KEY
fingerprint="$(printf '%s' "$source_payload" | python3 -c '
import hashlib
import re
import sys
source = sys.stdin.read()
match = re.search(r\"putenv\\( [\\\"]CRITICAL_FLOWS_RUN_CONTEXT_KEY=([0-9a-f]{{64}})[\\\"] \\);\", source)
if match is None:
    raise SystemExit(3)
print(\"sha256:\" + hashlib.sha256(bytes.fromhex(match.group(1))).hexdigest())
')" || fingerprint=""
printf '%s\t' {shlex.quote(kind)} >> "$DEFAULT_BOUNDARY_CALL_LOG"
printf '%q ' "$@" >> "$DEFAULT_BOUNDARY_CALL_LOG"
printf 'source_key_fingerprint=%s\n' "$fingerprint" >> "$DEFAULT_BOUNDARY_CALL_LOG"
[ "$fingerprint" = "$EXPECTED_CONTEXT_KEY_FINGERPRINT" ] || exit 3
printf '%s\n' '{{"status":"pass"}}'
"""


def test_default_store_wrapper_transports_observer_key_for_all_actions() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-default-observer-wrapper-") as tmp:
        root = Path(tmp)
        bin_dir = root / "bin"
        bin_dir.mkdir()
        call_log = root / "calls.log"
        write_executable(bin_dir / "docker", default_wp_boundary_shim_source("docker"))
        write_executable(bin_dir / "pnpm", default_wp_boundary_shim_source("pnpm"))
        fingerprint = "sha256:" + hashlib.sha256(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY)
        ).hexdigest()
        results: dict[tuple[str, str], subprocess.CompletedProcess[str]] = {}
        for store in ("ref", "target"):
            for action in ("observe", "stop", "recover"):
                script = f"""
source {shlex.quote(str(COMMON))}
REF_CONTAINER=test-ref-container
REF_WP_COMMAND=
TARGET_WPENV_CWD=test-target-cwd
TARGET_WP_COMMAND=
CRITICAL_FLOWS_RUN_STAMP={TEST_RUN_STAMP}
CRITICAL_FLOWS_RUN_CONTEXT_KEY={TEST_RUN_CONTEXT_KEY}
CRITICAL_FLOWS_FLOW_ID=MO-03-manual-capture-payment-details
CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log
export CRITICAL_FLOWS_RUN_STAMP CRITICAL_FLOWS_RUN_CONTEXT_KEY
export CRITICAL_FLOWS_FLOW_ID CRITICAL_FLOWS_LOG_PURPOSE
if declare -F critical_flows_log_observer_action >/dev/null; then
  critical_flows_log_observer_action {store} {action}
else
  wp_store {store} eval-file - "$CRITICAL_FLOWS_RUN_STAMP" {action} < "$LOG_OBSERVER_DRIVER"
fi
"""
                results[(store, action)] = subprocess.run(
                    ["bash", "-c", script],
                    cwd=REPO,
                    env={
                        **os.environ,
                        "PATH": f"{bin_dir}:{os.environ['PATH']}",
                        "DEFAULT_BOUNDARY_CALL_LOG": str(call_log),
                        "EXPECTED_CONTEXT_KEY_FINGERPRINT": fingerprint,
                    },
                    text=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    check=False,
                )

        failures = [
            f"{store}/{action}: rc={result.returncode}: {result.stderr.strip()}"
            for (store, action), result in results.items()
            if result.returncode != 0
        ]
        assert not failures, "default observer actions did not cross WP stdin safely: " + "; ".join(
            failures
        )
        calls = call_log.read_text(encoding="utf-8").splitlines()
        assert len(calls) == 6
        for action in ("observe", "stop", "recover"):
            assert any(
                line.startswith("docker\t")
                and "exec -i -u www-data test-ref-container wp eval-file - " in line
                and f" {action} source_key_fingerprint={fingerprint}" in line
                for line in calls
            )
            assert any(
                line.startswith("pnpm\t")
                and "wp-env run --env-cwd=test-target-cwd cli wp eval-file - " in line
                and f" {action} source_key_fingerprint={fingerprint}" in line
                for line in calls
            )
        disclosure = call_log.read_text(encoding="utf-8")
        for result in results.values():
            disclosure += result.stdout + result.stderr
        for path in root.rglob("*"):
            if path.is_file():
                disclosure += path.read_text(encoding="utf-8", errors="replace")
        assert TEST_RUN_CONTEXT_KEY not in disclosure


def assert_log_observer_cleaned(case: dict, required_bytes: bytes = b"") -> None:
    """Require exact regular-file restoration, no leftovers, and post-failure usability."""
    debug_log = case["debug_log"]
    current_stat = debug_log.stat()
    assert stat.S_ISREG(current_stat.st_mode)
    assert current_stat.st_uid == case["initial_stat"].st_uid
    assert current_stat.st_gid == case["initial_stat"].st_gid
    assert stat.S_IMODE(current_stat.st_mode) == stat.S_IMODE(case["initial_stat"].st_mode)
    restored = debug_log.read_bytes()
    assert restored.startswith(case["original"])
    assert required_bytes in restored
    assert not case["backing_path"].exists()
    assert not list(debug_log.parent.glob(f"{debug_log.name}.woopayments-critical-flows.*"))
    with debug_log.open("ab") as stream:
        stream.write(b"store-usable-after-observer-cleanup\n")
    assert debug_log.read_bytes().endswith(b"store-usable-after-observer-cleanup\n")


def native_capture_driver_php_source() -> str:
    """Return an executable native-outcome seam around the real capture driver."""
    return """<?php
namespace Automattic\\WooCommerce\\Internal\\Payments {
    class NativePaymentsRuntimeArbiter {
        public const OWNER_NATIVE = 'native';
        public function get_runtime_owner() { return self::OWNER_NATIVE; }
    }
    class OrderPaymentStore { public const GATEWAY_ID = 'woocommerce_payments'; }
    class PaymentContext {
        public static function for_capture( $order, $gateway_id ) { return new self(); }
    }
    class PaymentOutcome {
        public const STATUS_COMPLETED = 'completed';
        public const STATUS_FAILED = 'failed';
        public function __construct( private $status, private $data ) {}
        public function get_status() { return $this->status; }
        public function get_provider_payment_id() { return 'pi_ref_details'; }
        public function get_data() { return $this->data; }
    }
    class PaymentProcessingService {
        public function capture( $context, $provider ) {
            $status = getenv( 'FAKE_OUTCOME_STATUS' ) ?: PaymentOutcome::STATUS_COMPLETED;
            $data = array();
            if ( PaymentOutcome::STATUS_FAILED === $status ) {
                $data = array(
                    'error_code' => getenv( 'FAKE_ERROR_CODE' ) ?: 'api_error',
                    'error_message' => getenv( 'FAKE_ERROR_MESSAGE' ) ?: 'Provider failure.',
                );
            }
            return new PaymentOutcome( $status, $data );
        }
    }
}
namespace Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments {
    class WooPaymentsProvider {}
}
namespace {
    class WC_Order {
        public function get_payment_method() { return 'woocommerce_payments'; }
        public function get_status() {
            return $GLOBALS['order_reads'] > 1 && 'completed' === getenv( 'FAKE_OUTCOME_STATUS' )
                ? 'processing'
                : 'on-hold';
        }
        public function get_meta( $key, $single ) {
            if ( '_intent_id' === $key ) { return 'pi_ref_details'; }
            if ( '_charge_id' === $key ) { return 'ch_ref_details'; }
            if ( '_intention_status' === $key ) {
                return $GLOBALS['order_reads'] > 1 && 'completed' === getenv( 'FAKE_OUTCOME_STATUS' )
                    ? 'succeeded'
                    : 'requires_capture';
            }
            return '';
        }
    }
    class CriticalFlowContainer {
        public function get( $class ) { return new $class(); }
    }
    class WP_CLI {
        public static function line( $line ) { echo $line, "\n"; }
        public static function error( $message ) { echo $message, "\n"; exit( 1 ); }
    }
    function wc_get_order( $order_id ) {
        ++$GLOBALS['order_reads'];
        return new WC_Order();
    }
    function wc_get_container() { return new CriticalFlowContainer(); }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    $GLOBALS['order_reads'] = 0;
    $args = array( 301 );
    require $argv[1];
}
"""


def native_transport_exception_codec_php_source() -> str:
    """Project one real native transport exception through the production codec."""
    return """<?php
function esc_html( $value ) { return $value; }
function __( $value, $domain = '' ) { return $value; }
function _x( $value, $context = '', $domain = '' ) { return $value; }
function apply_filters( $hook, $value ) { return $value; }
require $argv[1];

$exception = new Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\Api\\WooPaymentsApiException(
    'Your card was declined.',
    'card_declined',
    503,
    'card_error',
    'card_declined'
);
$outcome = Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsIntentCodec::failed_transport_outcome(
    'capture',
    $exception,
    'pi_ref_details'
);
$data = $outcome->get_data();
echo json_encode(
    array(
        'op'               => 'capture',
        'order_id'         => 301,
        'intent_id'        => 'pi_ref_details',
        'charge_id'        => 'ch_ref_details',
        'status'           => 'on-hold',
        'intention_status' => 'requires_capture',
        'success'          => false,
        'transport_kind'   => 'native_outcome',
        'provider_status'  => $outcome->get_status(),
        'http_code'        => (int) ( $data['http_code'] ?? 0 ),
        'error_code'       => (string) ( $data['error_code'] ?? '' ),
        'error_message'    => (string) ( $data['error_message'] ?? '' ),
    )
), "\\n";
"""


def mo03_fake_wp_source(
    owner: str,
    home: str,
    pre_payload: dict,
    post_payload: dict,
    *,
    state_exit_code: int = 0,
    post_state_exit_code: int | None = None,
    log_probe_exit_code: int = 0,
    log_probe_status: str = "pass",
) -> str:
    """Fake wp CLI for MO-03 snapshots and shared runner probes."""
    pre = json.dumps(pre_payload, separators=(",", ":"))
    post = json.dumps(post_payload, separators=(",", ":"))
    post_exit_code = state_exit_code if post_state_exit_code is None else post_state_exit_code
    log_matches = (
        []
        if log_probe_status != "fail"
        else [safe_log_record(line=5, diagnostic="PHP Warning: deterministic capture warning")]
    )
    store = "ref" if owner == "plugin" else "target"
    log_payload = common_log_scan_v5(
        status=log_probe_status,
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="MO-03-manual-capture-payment-details",
        purpose="clean-debug-log",
        marker_created_at=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(MO03_TEST_EPOCH)),
        end_line_count=5 if log_matches else 4,
        end_byte_count=160 if log_matches else 128,
        matches=log_matches,
        blocker_code="no_configured_paths" if log_probe_status == "blocked" else "",
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload, observer_categories='warning' if log_probe_status == 'fail' else '')}
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    if [ {log_probe_exit_code} -ne 0 ]; then
      printf '%s\n' 'fake log probe unavailable' >&2
      exit {log_probe_exit_code}
    fi
    printf '%s\n' '{log_json}'
    exit 0
  fi
  state_exit_code={state_exit_code}
  if [ "$5" = "post" ]; then
    state_exit_code={post_exit_code}
  fi
  if [ "$state_exit_code" -ne 0 ]; then
    printf '%s\\n' 'fake payment details state unavailable' >&2
    exit "$state_exit_code"
  fi
  if [ "$5" = "pre" ]; then
    printf '%s\\n' '{pre}'
  else
    printf '%s\\n' '{post}'
  fi
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def mo03_fake_flow_driver_source(
    target_capture: dict,
    target_capture_exit_code: int,
    call_log: Path,
) -> str:
    """Fake deterministic charge/capture driver with a capture call ledger."""
    target_capture_json = json.dumps(target_capture, separators=(",", ":"))
    ref_capture_json = json.dumps(mo03_capture_payload("ref"), separators=(",", ":"))
    return f"""#!/usr/bin/env bash
case "$1" in
  charge)
    if [ "$STORE_NAME" = "ref" ]; then
      printf '%s\\n' '{{"op":"charge","order_id":301,"charge_id":"ch_ref_details","intent_id":"pi_ref_details"}}'
    else
      printf '%s\\n' '{{"op":"charge","order_id":302,"charge_id":"ch_target_details","intent_id":"pi_target_details"}}'
    fi
    ;;
  capture)
    printf '%s\\n' "$STORE_NAME:capture:$*" >> {shlex.quote(str(call_log))}
    if [ "$STORE_NAME" = "ref" ]; then
      printf '%s\\n' '{ref_capture_json}'
      exit 0
    fi
    printf '%s\\n' '{target_capture_json}'
    exit {target_capture_exit_code}
    ;;
  *)
    printf 'unexpected flow operation: %s\\n' "$*" >&2
    exit 9
    ;;
esac
"""


def ma09_payload(
    store: str,
    *,
    dataset_count: int = 500,
    status: str = "pass",
    check_overrides: dict[str, bool] | None = None,
    measured_seconds: tuple[float, float] = (0.5, 0.6),
) -> dict:
    """Return one strict fake MA-09 deterministic payload."""
    checks = {
        "route_registered": True,
        "dataset_minimum": dataset_count >= 500,
        "unique_ledger": True,
        "stable_ledger": True,
        "page_one_full": True,
        "deep_page_full": True,
        "pagination_exact": True,
        "type_filter_exact": True,
        "date_filter_exact": True,
    }
    checks.update(check_overrides or {})
    timings = {
        query: {
            "samples_seconds": [0.4, *measured_seconds],
            "measured_median_seconds": sum(measured_seconds) / 2,
        }
        for query in ("page_1", "page_20", "type_filter", "date_filter")
    }
    return {
        "schema": "woopayments_ma09_deterministic.v1",
        "status": status,
        "store": store,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "dataset_count": dataset_count,
        "seeded_count": dataset_count,
        "page_size": 25,
        "deep_page": 20,
        "ledger_sha256": "sha256:" + ("a" if store == "ref" else "b") * 64,
        "type_filter": "charge",
        "type_filter_count": 350,
        "date_filter_after": "2026-07-16 00:00:00",
        "date_filter_before": "2026-07-16 23:59:59",
        "date_filter_count": 125,
        "checks": checks,
        "timings": timings,
        "errors": [] if status != "fail" else ["A deterministic state check failed."],
        "blockers": [] if status != "blocked" else ["The exact 500-row fixture is unavailable."],
    }


def ma09_fake_wp_source(
    owner: str,
    home: str,
    state_payload: dict,
    *,
    log_probe_exit_code: int = 0,
    dirty_log: bool = False,
) -> str:
    """Fake wp CLI for the MA-09 performance driver and shared log assertions."""
    payload = json.dumps(state_payload, separators=(",", ":"))
    log_matches = (
        [safe_log_record(line=5, diagnostic="PHP Warning: fake MA-09 warning")]
        if dirty_log
        else []
    )
    log_scan = common_log_scan_v5(
        status="fail" if dirty_log else "pass",
        run_stamp=TEST_RUN_STAMP,
        store="ref" if owner == "plugin" else "target",
        flow_id="MA-09-large-dataset-perf",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
        end_line_count=5 if dirty_log else 4,
        end_byte_count=160 if dirty_log else 128,
        matches=log_matches,
    )
    log_payload = json.dumps(log_scan, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_scan, observer_categories="warning" if dirty_log else "")}
if [ "$1" = "--user=1" ]; then
  shift
fi
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    if [ {log_probe_exit_code} -ne 0 ]; then
      printf '%s\\n' 'fake log probe unavailable' >&2
      exit {log_probe_exit_code}
    fi
    printf '%s\\n' '{log_payload}'
    exit 0
  fi
  printf '%s\\n' 'fake WP wrapper banner'
  printf '%s\\n' '{payload}'
  printf '%s\\n' 'fake WP wrapper footer'
  exit 0
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner={owner}"
    printf '%s\\n' "store_identity_home={home}"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  if [[ "$2" == *"ignored_matches"* ]] && [ {log_probe_exit_code} -ne 0 ]; then
    printf '%s\\n' 'fake log probe unavailable' >&2
    exit {log_probe_exit_code}
  fi
  printf '%s\\n' '{log_payload}'
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def run_ma09_php_driver_with_endpoint_exception(
    failure_mode: str,
) -> subprocess.CompletedProcess[str]:
    """Run the real PHP collector with a registered endpoint that throws in one phase."""
    driver_path = json.dumps(str(MA09_DRIVER))
    encoded_failure_mode = json.dumps(failure_mode)
    source = f"""<?php
namespace Automattic\\WooCommerce\\Internal\\Payments {{
final class NativePaymentsRuntimeArbiter {{}}
}}

namespace {{
final class WP_REST_Request {{
    public array $query = array();
    public function __construct( string $method, string $route ) {{}}
    public function set_query_params( array $query ): void {{ $this->query = $query; }}
}}

final class FakeRestServer {{
    public function get_routes(): array {{
        return array( '/wc/v3/payments/transactions' => array() );
    }}
}}

final class FakeRestResponse {{
    public function __construct( private array $data ) {{}}
    public function get_data(): array {{ return array( 'data' => $this->data ); }}
    public function get_status(): int {{ return 200; }}
}}

function get_option( string $name, $default = false ) {{ return $default; }}
function wc_get_container(): object {{
    return new class {{
        public function get( string $class_name ): object {{
            return new class {{
                public function get_runtime_owner(): string {{ return 'native'; }}
            }};
        }}
    }};
}}
function wp_get_current_user(): object {{
    return new class {{
        public function exists(): bool {{ return true; }}
    }};
}}
function current_user_can( string $capability ): bool {{ return true; }}
function rest_get_server(): object {{ return new FakeRestServer(); }}
function is_wp_error( $response ): bool {{ return false; }}
function wp_json_encode( $value, int $flags = 0 ): string {{ return json_encode( $value, $flags ); }}

$ma09_rows = array();
for ( $index = 500; $index >= 1; $index-- ) {{
    $ma09_rows[] = array(
        'transaction_id' => 'txn_' . $index,
        'type'           => 'charge',
        'date'           => '2026-07-16 12:00:00',
        'amount'         => 1000,
        'fees'           => 30,
        'net'            => 970,
        'currency'       => 'usd',
    );
}}
$ma09_page_twenty_requests = 0;
$ma09_failure_mode = {encoded_failure_mode};

function rest_do_request( WP_REST_Request $request ): FakeRestResponse {{
    global $ma09_rows, $ma09_page_twenty_requests, $ma09_failure_mode;
    $page      = (int) ( $request->query['page'] ?? 1 );
    $page_size = (int) ( $request->query['per_page'] ?? 25 );
    if (
        'type_filter' === $ma09_failure_mode
        && 1 === $page
        && 100 === $page_size
        && 'charge' === ( $request->query['type_is'] ?? '' )
    ) {{
        throw new \\RuntimeException( 'type filter endpoint failure' );
    }}
    if (
        'date_filter' === $ma09_failure_mode
        && 1 === $page
        && 100 === $page_size
        && isset( $request->query['date_after'], $request->query['date_before'] )
    ) {{
        throw new \\RuntimeException( 'date filter endpoint failure' );
    }}
    if ( 'timed' === $ma09_failure_mode && 20 === $page && 25 === $page_size ) {{
        $ma09_page_twenty_requests++;
        if ( 3 === $ma09_page_twenty_requests ) {{
            throw new \\RuntimeException( 'timed endpoint failure' );
        }}
    }}
    usleep( 1000 );
    $offset = ( $page - 1 ) * $page_size;
    return new FakeRestResponse( array_slice( $ma09_rows, $offset, $page_size ) );
}}

$args = array( 'target' );
require {driver_path};
}}
"""

    with tempfile.TemporaryDirectory(prefix="critical-flows-ma09-php-") as tmp:
        harness = Path(tmp) / "ma09-timed-exception.php"
        harness.write_text(source, encoding="utf-8")
        return subprocess.run(
            ["php", str(harness)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


MA10_SENTINELS = [
    "Payment complete.",
    "Payment failed.",
    "Payment authorization expired.",
    "Fee details:",
    "Fee (",
    "Base fee:",
    "Currency conversion fee:",
    "Net payout:",
    "The refund returned status",
    "Refunded",
    "Payment dispute and fees have been deducted",
    "Payment dispute funds have been reinstated",
    "Payment dispute has been updated",
    "dispute overview",
    "Payment has been disputed",
    "Payment inquiry has been raised",
    "A test payment",
]


def ma10_gate_payload(status: str = "pass", *, english_refund: bool = False) -> dict:
    """Return a strict fake MA-10 borrowed-gate result."""
    translated = status != "blocked"
    messages = {
        "charge": "A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).",
        "refund": "A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)",
        "dispute": 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.',
    }
    refund_note = "[wcpay-i18n:refund] Eine Rueckerstattung re_test"
    if english_refund:
        refund_note = "A refund of 25 USD using WooPayments. Reason: test. (<code>re_test</code>)"
    state = {
        "schema": "woopayments_i18n_notes_capture.v1",
        "locale": "de_DE",
        "translation_source": "deterministic_gettext_probe",
        "catalog_evidence": {
            "schema": "woopayments_i18n_catalog_evidence.v1",
            "locale": "de_DE",
            "textdomain": "woocommerce",
            "textdomain_loaded": True,
            "messages": {
                flow: {
                    "message_id": message_id,
                    "translation": f"translated {flow}" if translated else message_id,
                    "translated": translated,
                }
                for flow, message_id in messages.items()
            },
        },
        "orders": [
            {
                "flow": "charge",
                "order_id": 101,
                "notes": ["[wcpay-i18n:charge] Eine Zahlung pi_test_charge"],
            },
            {
                "flow": "refund",
                "order_id": 101,
                "notes": [refund_note, "E-Mail [wcpay-i18n:refund] Rueckerstattete Bestellung"],
            },
            {
                "flow": "dispute",
                "order_id": 202,
                "notes": ["[wcpay-i18n:dispute] Zahlung angefochten ch_test_dispute"],
            },
        ],
    }
    if status == "fail":
        state["orders"][0]["notes"].append("Payment complete.")
    return {
        "schema": "woopayments_i18n_notes_gate_result.v1",
        "status": status,
        "implementation_status": "fail" if status == "fail" else "pass",
        "catalog_status": "blocked" if status == "blocked" else "pass",
        "failures": ["english sentinel found"] if status == "fail" else [],
        "blockers": ["catalog translation unavailable"] if status == "blocked" else [],
        "expected_locale": "de_DE",
        "required_flows": ["charge", "refund", "dispute"],
        "english_sentinels": MA10_SENTINELS,
        "state": state,
    }


def ma10_fake_gate_source(
    status: str = "pass",
    *,
    malformed: bool = False,
    english_refund: bool = False,
    exit_code_override: int | None = None,
) -> str:
    """Fake the borrowed gate while preserving its archived evidence contract."""
    payload = {"schema": "woopayments_i18n_notes_gate_result.v1", "status": "pass"}
    if not malformed:
        payload = ma10_gate_payload(status, english_refund=english_refund)
    encoded_payload = json.dumps(payload, separators=(",", ":"))
    encoded_state = json.dumps(payload.get("state", {}), separators=(",", ":"))
    encoded_catalog = json.dumps(payload.get("state", {}).get("catalog_evidence", {}), separators=(",", ":"))
    exit_code = exit_code_override if exit_code_override is not None else 0 if status == "pass" else 1 if status == "fail" else 3
    return f"""#!/usr/bin/env bash
if [ -n "${{FAKE_I18N_GATE_CALLS:-}}" ]; then
  printf 'gate %s\\n' "$*" >> "$FAKE_I18N_GATE_CALLS"
fi
out_dir=''
while [ "$#" -gt 0 ]; do
  if [ "$1" = "--out-dir" ]; then
    out_dir="$2"
    break
  fi
  shift
done
[ -n "$out_dir" ] || exit 2
mkdir -p "$out_dir"
printf '%s\\n' '{encoded_payload}' > "$out_dir/i18n-notes-gate.json"
printf '%s\\n' '{encoded_state}' > "$out_dir/i18n-notes-state.json"
printf '%s\\n' '{encoded_catalog}' > "$out_dir/i18n-catalog-evidence.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_language_snapshot.v1","success":true,"exists":true,"value":"","autoload":"auto"}}' > "$out_dir/i18n-language-snapshot.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_language_restore.v1","success":true,"restored_snapshot_exact":true,"errors":[]}}' > "$out_dir/i18n-language-restore.json"
printf '%s\\n' '{{"schema":"woopayments_i18n_probe_cleanup.v1","success":true,"errors":[]}}' > "$out_dir/i18n-probe-cleanup.json"
printf '%s\\n' '{{"op":"charge","order_id":101,"charge_id":"ch_test_charge","intent_id":"pi_test_charge","transaction_id":"pi_test_charge","intention_status":"succeeded","status":"processing","result":"success"}}' > "$out_dir/charge-flow.json"
printf '%s\\n' '{{"op":"refund","order_id":101,"refund_id":102,"provider_refund_id":"re_test","success":true}}' > "$out_dir/refund-flow.json"
printf '%s\\n' '{{"op":"dispute","order_id":202,"charge_id":"ch_test_dispute","intent_id":"pi_test_dispute","transaction_id":"pi_test_dispute","intention_status":"succeeded","status":"processing","result":"success"}}' > "$out_dir/dispute-flow.json"
if [ "${{FAKE_I18N_GATE_REMOVE_FILE:-}}" ]; then
  rm -f "$out_dir/$FAKE_I18N_GATE_REMOVE_FILE"
fi
if [ -n "${{FAKE_I18N_GATE_CONTRADICT_STATE:-}}" ]; then
  printf '%s\\n' '{{"schema":"woopayments_i18n_notes_capture.v1","tampered":true}}' > "$out_dir/i18n-notes-state.json"
fi
exit {exit_code}
"""


def fake_authenticated_log_observer_source() -> str:
    """Return the fake WP seam for the runner's authenticated observer lifecycle."""
    return r'''
observer_state="$EVIDENCE_DIR/.fake-log-observer-$CRITICAL_FLOWS_RUN_STAMP"
observer_args=("$@")
observer_action=""
observer_run_stamp=""
for observer_index in "${!observer_args[@]}"; do
  if [ "${observer_args[$observer_index]}" = "eval-file" ]; then
    observer_action="${observer_args[$((observer_index + 3))]:-}"
    observer_run_stamp="${observer_args[$((observer_index + 2))]:-}"
    break
  fi
done
if [ -n "$observer_action" ]; then
  case "$observer_action" in
    observe)
      while IFS= read -r _line; do :; done
      rm -f "$observer_state.stop"
      FAKE_OBSERVER_STATE="$observer_state" FAKE_OBSERVER_RUN_STAMP="$observer_run_stamp" python3 - <<'PY'
import hashlib
import hmac
import json
import os
import time
from pathlib import Path

key = bytes.fromhex(os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"])
run_stamp = os.environ["FAKE_OBSERVER_RUN_STAMP"]
observer_id = os.environ.get(
    "FAKE_OBSERVER_ID", "00000000-0000-4000-8000-000000000777"
)
store = os.environ.get("FAKE_OBSERVER_STORE", "target")
flow_id = os.environ.get("FAKE_OBSERVER_FLOW_ID", "MO-03-manual-capture-payment-details")
purpose = os.environ.get("FAKE_OBSERVER_PURPOSE", "clean-debug-log")
marker_created_at = os.environ.get("FAKE_OBSERVER_MARKER_CREATED_AT", "")
path_contexts = [
    {
        "path": os.environ.get("FAKE_OBSERVER_PATH", "fake-debug.log"),
        "path_id": os.environ.get("FAKE_OBSERVER_PATH_ID", ""),
    }
]
stop_path = Path(os.environ["FAKE_OBSERVER_STATE"] + ".stop")
previous = "0" * 64
sequence = 0


def emit(kind, **fields):
    global previous, sequence
    sequence += 1
    record = {
        "schema": "woopayments_debug_log_observer_record.v2",
        "sequence": sequence,
        "run_stamp": run_stamp,
        "store": store,
        "flow_id": flow_id,
        "purpose": purpose,
        "marker_created_at": marker_created_at,
        "observer_id": observer_id,
        "paths": path_contexts,
        "previous_hmac": "hmac-sha256:" + previous,
        "kind": kind,
        **fields,
    }
    canonical = json.dumps(
        record, sort_keys=True, separators=(",", ":"), ensure_ascii=True
    )
    previous = hmac.new(
        key,
        (record["previous_hmac"].removeprefix("hmac-sha256:") + "\0" + canonical).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    record["hmac"] = "hmac-sha256:" + previous
    print(json.dumps(record, separators=(",", ":"), ensure_ascii=True), flush=True)


emit(
    "ready",
    status="pass",
    path_count=1,
    key_fingerprint="sha256:" + hashlib.sha256(key).hexdigest(),
    origin_binding=os.environ["CRITICAL_FLOWS_RUN_CONTEXT_BINDING"],
)
deadline = time.monotonic() + 10
while not stop_path.is_file() and time.monotonic() < deadline:
    time.sleep(0.02)
if not stop_path.is_file():
    raise SystemExit(3)
for category in filter(None, os.environ.get("FAKE_OBSERVER_CATEGORIES", "").split(",")):
    emit(
        "line",
        path=os.environ.get("FAKE_OBSERVER_PATH", "fake-debug.log"),
        line=5,
        category=category,
        fingerprint="sha256:" + hashlib.sha256(category.encode("utf-8")).hexdigest(),
    )
emit(
    "line",
    path=os.environ.get("FAKE_OBSERVER_PATH", "fake-debug.log"),
    line=int(
        os.environ.get(
            "FAKE_OBSERVER_TERMINAL_LINE",
            "5" if os.environ.get("FAKE_OBSERVER_CATEGORIES") else "4",
        )
    ),
    category="terminal",
    fingerprint="sha256:" + hashlib.sha256(b"fake terminal").hexdigest(),
)
emit("complete", status="pass")
PY
      exit $?
      ;;
    stop|recover)
      while IFS= read -r _line; do :; done
      : > "$observer_state.stop"
      exit 0
      ;;
  esac
fi
'''


def authenticated_log_fake_prelude(
    scan_payload: dict, *, observer_categories: str = ""
) -> str:
    """Return the strict-v5 fake observer environment and lifecycle dispatcher."""
    observation = scan_payload["observations"][0]
    return f"""CRITICAL_FLOWS_RUN_CONTEXT_BINDING={shlex.quote(scan_payload['origin_binding'])}
FAKE_OBSERVER_ID={shlex.quote(scan_payload['observer_id'])}
FAKE_OBSERVER_CATEGORIES={shlex.quote(observer_categories)}
FAKE_OBSERVER_STORE={shlex.quote(scan_payload['store'])}
FAKE_OBSERVER_FLOW_ID={shlex.quote(scan_payload['flow_id'])}
FAKE_OBSERVER_PURPOSE={shlex.quote(scan_payload['purpose'])}
FAKE_OBSERVER_MARKER_CREATED_AT={shlex.quote(scan_payload['marker_created_at'])}
FAKE_OBSERVER_PATH={shlex.quote(observation['path'])}
FAKE_OBSERVER_PATH_ID={shlex.quote(observation['path_id'])}
export CRITICAL_FLOWS_RUN_CONTEXT_BINDING FAKE_OBSERVER_ID FAKE_OBSERVER_CATEGORIES
export FAKE_OBSERVER_STORE FAKE_OBSERVER_FLOW_ID FAKE_OBSERVER_PURPOSE
export FAKE_OBSERVER_MARKER_CREATED_AT FAKE_OBSERVER_PATH FAKE_OBSERVER_PATH_ID
{fake_authenticated_log_observer_source()}
"""


def ma10_fake_wp_source(
    *,
    log_status: str = "pass",
    marker_ok: bool = True,
    scanned_paths: list[str] | None = None,
) -> str:
    """Return a target WP seam with marker-bounded log evidence."""
    log_matches = (
        [safe_log_record(line=5, diagnostic="PHP Warning: MA-10 fake warning")]
        if log_status == "fail"
        else []
    )
    scan_payload = ma10_log_scan_v5(
        status=log_status,
        run_stamp=TEST_RUN_STAMP,
        marker_created_at=TEST_MARKER_CREATED_AT,
        end_line_count=5 if log_matches else 4,
        end_byte_count=160 if log_matches else 128,
        matches=log_matches,
        blocker_code="no_configured_paths" if log_status == "blocked" else "",
    )
    observer_scan = scan_payload
    if scanned_paths == []:
        scan_payload = {**scan_payload, "observations": []}
    encoded_scan = json.dumps(scan_payload, separators=(",", ":"))
    marker_exit = 0 if marker_ok else 1
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(observer_scan, observer_categories='warning' if log_status == 'fail' else '')}
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\n' marker >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\n' '{{"status":"pass"}}'
    exit {marker_exit}
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\n' scan >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\n' '{encoded_scan}'
    exit 0
  fi
fi
if [ "$1" = "eval" ]; then
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' 'store_identity_owner=native'
    printf '%s\\n' 'store_identity_home=http://target.fake.test'
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\\n' marker >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":4}}}}'
    exit {marker_exit}
  fi
  if [[ "$2" == *"ignored_matches"* ]]; then
    [ -z "${{FAKE_I18N_GATE_CALLS:-}}" ] || printf '%s\\n' scan >> "$FAKE_I18N_GATE_CALLS"
    printf '%s\\n' '{encoded_scan}'
    exit 0
  fi
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
"""


def ma10_live_failure_wp_source() -> str:
    """Return a WP seam that supports the real gate through an early flow failure."""
    catalog = ma10_gate_payload()["state"]["catalog_evidence"]
    encoded_catalog = json.dumps(catalog, separators=(",", ":"))
    scan = ma10_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    encoded_scan = json.dumps(scan, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
set -u
{authenticated_log_fake_prelude(scan)}
if [[ "${{1:-}}" == --exec=* ]]; then shift; fi
if [ "${{1:-}}" = "eval" ]; then
  if [[ "${{2:-}}" == *"store_identity_owner"* ]]; then
    printf '%s\\n' 'store_identity_owner=native'
    printf '%s\\n' 'store_identity_home=http://target.fake.test'
  elif [[ "${{2:-}}" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
  elif [[ "${{2:-}}" == *"ignored_matches"* ]]; then
    printf '%s\\n' '{encoded_scan}'
  else
    exit 2
  fi
  exit 0
fi
if [ "${{1:-}}" = "wc-native-payments" ] && [ "${{2:-}}" = "status" ]; then
  printf '%s\\n' 'Owner: native'
  exit 0
fi
if [ "${{1:-}}" = "language" ] || [ "${{1:-}}" = "site" ]; then exit 0; fi
if [ "${{1:-}}" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
  elif [[ "$body" == *"ignored_matches"* ]]; then
    printf '%s\n' '{encoded_scan}'
  elif [[ "$body" == *"woopayments_i18n_language_restore.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_language_restore.v1","success":true,"restored_snapshot_exact":true,"errors":[]}}'
  elif [[ "$body" == *"woopayments_i18n_language_snapshot.v1"* ]]; then
    printf '%s\\n' 'WPLANG:en_US'
    printf '%s\\n' '{{"schema":"woopayments_i18n_language_snapshot.v1","success":true,"exists":true,"value":"en_US","autoload":"auto"}}'
  elif [[ "$body" == *"woopayments_i18n_catalog_evidence.v1"* ]]; then
    printf '%s\\n' '{encoded_catalog}'
  elif [[ "$body" == *"Translation probe path already exists"* ]]; then
    printf '%s\\n' '{{"success":true,"path":"/fake/probe.php","errors":[]}}'
  elif [[ "$body" == *"woopayments_i18n_probe_install.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_probe_install.v1","success":true,"path":"/fake/probe.php","sha256":"fake"}}'
  elif [[ "$body" == *"woopayments_i18n_probe_cleanup.v1"* ]]; then
    printf '%s\\n' '{{"schema":"woopayments_i18n_probe_cleanup.v1","success":true,"errors":[]}}'
  else
    exit 2
  fi
  exit 0
fi
exit 2
"""


def sc02_raw_preflight(store: str) -> dict:
    """Return one complete raw SC-02 preflight projection."""
    owner = "plugin" if store == "ref" else "native"
    gateway_class = "WC_Payment_Gateway_WCPay" if store == "ref" else "NativeWooPaymentsGateway"
    return {
        "schema": "woopayments_sc02_state_raw.v1",
        "phase": "preflight",
        "store": store,
        "run_stamp": TEST_RUN_STAMP,
        "runtime_owner": owner,
        "gateway": {
            "id": "woocommerce_payments",
            "class": gateway_class,
            "available": True,
            "test_mode": True,
            "connected": True,
        },
        "product": {
            "id": 24 if store == "ref" else 34,
            "sku": "test-lab-beaker-001",
            "price": "25.00",
            "purchasable": True,
            "in_stock": True,
        },
        "store_currency": "USD",
        "connected_account_id": f"acct_{store}fixture1234",
        "blockers": [],
    }


def sc02_raw_post(store: str) -> dict:
    """Return one complete raw SC-02 post-checkout projection."""
    owner = "plugin" if store == "ref" else "native"
    product_id = 24 if store == "ref" else 34
    order_id = 1701 if store == "ref" else 2701
    intent_id = f"pi_{store}_sc02_fixture"
    charge_id = f"ch_{store}_sc02_fixture"
    payment_method = f"pm_{store}_sc02_fixture"
    return {
        "schema": "woopayments_sc02_state_raw.v1",
        "phase": "post",
        "store": store,
        "run_stamp": TEST_RUN_STAMP,
        "runtime_owner": owner,
        "order": {
            "id": order_id,
            "status": "processing",
            "created_via": "store-api",
            "payment_method": "woocommerce_payments",
            "customer_id": 0,
            "customer_note": f"sc02-{TEST_RUN_STAMP}-{store}",
            "total": "25.00",
            "currency": "USD",
            "date_paid_present": True,
            "transaction_id": intent_id,
            "intent_id": intent_id,
            "charge_id": charge_id,
            "line_items": [
                {
                    "product_id": product_id,
                    "sku": "test-lab-beaker-001",
                    "quantity": 1,
                    "total": "25.00",
                }
            ],
        },
        "provider": {
            "intent": {
                "id": intent_id,
                "object": "payment_intent",
                "status": "succeeded",
                "amount": 2500,
                "currency": "usd",
                "latest_charge": charge_id,
                "payment_method": payment_method,
            },
            "charge": {
                "id": charge_id,
                "object": "charge",
                "status": "succeeded",
                "paid": True,
                "amount": 2500,
                "amount_captured": 2500,
                "currency": "usd",
                "payment_intent": intent_id,
                "payment_method": payment_method,
            },
        },
        "blockers": [],
    }


def sc02_fake_wp_source(
    store: str, *, connected: bool = True, log_status: str = "pass"
) -> str:
    """Return a WP seam for SC-02 state and authenticated log collection."""
    owner = "plugin" if store == "ref" else "native"
    home = f"http://{store}.sc02.fake.test"
    preflight_payload = sc02_raw_preflight(store)
    preflight_payload["gateway"]["connected"] = connected
    preflight = json.dumps(preflight_payload, separators=(",", ":"))
    post = json.dumps(sc02_raw_post(store), separators=(",", ":"))
    log_matches = (
        [safe_log_record(line=5, diagnostic="PHP Warning: SC-02 fake warning")]
        if log_status == "fail"
        else []
    )
    log_payload = common_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="SC-02-blocks-card-checkout",
        purpose="clean-debug-log",
        status=log_status,
        marker_created_at=TEST_MARKER_CREATED_AT,
        end_line_count=5 if log_matches else 4,
        end_byte_count=160 if log_matches else 128,
        matches=log_matches,
        blocker_code="observer_unavailable" if log_status == "blocked" else "",
    )
    log_json = json.dumps(log_payload, separators=(",", ":"))
    return f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(log_payload, observer_categories='warning' if log_status == 'fail' else '')}
if [ "${{1:-}}" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    printf '%s\n' '{log_json}'
    exit 0
  fi
  if [[ "$body" == *"WooPaymentsCriticalFlowsSc02Driver"* ]]; then
    if [[ "$body" == *"array( 'post'"* ]]; then
      printf '%s\n' '{post}'
    else
      printf '%s\n' '{preflight}'
    fi
    exit 0
  fi
fi
if [ "${{1:-}}" = "eval" ]; then
  if [[ "${{2:-}}" == *"store_identity_owner"* ]]; then
    printf '%s\n' 'store_identity_owner={owner}'
    printf '%s\n' 'store_identity_home={home}'
    exit 0
  fi
  if [[ "${{2:-}}" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":4}}}}'
    exit 0
  fi
  if [[ "${{2:-}}" == *"ignored_matches"* ]]; then
    printf '%s\n' '{log_json}'
    exit 0
  fi
fi
printf 'unexpected SC-02 fake wp call: %s\n' "$*" >&2
exit 2
"""


def sc02_fake_http_source(call_log: Path) -> str:
    """Return a one-shot HTTP transcript producer with pass/fail/block modes."""
    client_path = REPO / "tools/woopayments-critical-flows/flows/sc02-store-api.py"
    return f"""#!/usr/bin/env python3
import argparse
import hashlib
import importlib.util
import os
from pathlib import Path

spec = importlib.util.spec_from_file_location("sc02_store_api_fixture", {str(client_path)!r})
if spec is None or spec.loader is None:
    raise SystemExit(3)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

parser = argparse.ArgumentParser()
parser.add_argument("--base-url", required=True)
parser.add_argument("--store", required=True)
parser.add_argument("--run-stamp", required=True)
parser.add_argument("--run-token", required=True)
parser.add_argument("--product-id", type=int, required=True)
parser.add_argument("--timeout", required=True)
parser.add_argument("--output", required=True)
args = parser.parse_args()
with Path({str(call_log)!r}).open("a", encoding="utf-8") as handle:
    handle.write(args.store + "\\n")

key = bytes.fromhex(os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"])
fingerprint = "hmac-sha256:" + hashlib.sha256((args.store + "-lineage").encode()).hexdigest()
payload = module.empty_transcript(args.store, args.run_stamp, args.product_id, fingerprint)
mode = os.environ.get(
    f"SC02_FAKE_HTTP_{{args.store.upper()}}_MODE",
    os.environ.get("SC02_FAKE_HTTP_MODE", "pass"),
)
if mode in {{"pass", "fail"}}:
    payload.update({{
        "order_id": 1701 if args.store == "ref" and mode == "pass" else 2701 if mode == "pass" else 0,
        "cart_token_fingerprint": fingerprint,
        "request_sequence": list(module.EXPECTED_SEQUENCE),
        "cart_token_lineage": True,
        "local_origin": True,
        "redirect_count": 0,
    }})
    payload["cart"].update({{
        "started_empty": True,
        "item_count": 1,
        "product_id": args.product_id,
        "sku": "test-lab-beaker-001",
        "quantity": 1,
        "selected_shipping_rate_count": 1,
        "selected_shipping_rate_cost": "0",
        "total_price": "2500",
        "currency_code": "USD",
        "currency_minor_unit": 2,
        "payment_methods": ["woocommerce_payments"],
    }})
    payload["checkout"].update({{
        "http_status": 200 if mode == "pass" else 400,
        "payment_status": "success" if mode == "pass" else "",
        "order_status": "processing" if mode == "pass" else "",
        "structured_error": mode == "fail",
    }})
module.derive_verdict(payload)
raise SystemExit(module.write_payload(payload, Path(args.output), key))
"""


def sc02_adversarial_evidence_source(mode: str) -> str:
    """Proxy the SC-02 evidence tool and corrupt only the target final packet."""
    evidence_tool = REPO / "tools/woopayments-critical-flows/flows/sc02-evidence.py"
    return f"""#!/usr/bin/env python3
import json
import subprocess
import sys
from pathlib import Path

result = subprocess.run([sys.executable, {str(evidence_tool)!r}, *sys.argv[1:]], check=False)
if sys.argv[1] == "manifest" and "--output" in sys.argv:
    output = Path(sys.argv[sys.argv.index("--output") + 1])
    if output.name == "target-manifest.json" and output.exists():
        mode = {mode!r}
        if mode == "missing_manifest":
            output.unlink()
        elif mode == "missing_artifact":
            (output.parent / "target-post.json").unlink()
        else:
            payload = json.loads(output.read_text(encoding="utf-8"))
            if mode == "tampered_hmac":
                payload["context_hmac"] = "hmac-sha256:" + "0" * 64
            elif mode == "wrong_binding":
                payload["run_stamp"] = "20260718T000000Z-1"
            output.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
raise SystemExit(result.returncode)
"""


def run_runner(
    *args: str,
    evidence_dir: Path,
    extra_env: dict[str, str] | None = None,
    with_context: bool = True,
) -> subprocess.CompletedProcess[str]:
    runner_args = [*args]
    if with_context and "--layer" in args and args[args.index("--layer") + 1] != "deterministic":
        context_path, _ = ensure_context(evidence_dir)
        runner_args.extend(("--context-file", str(context_path)))
    return subprocess.run(
        ["bash", str(RUNNER), *runner_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={
            **os.environ,
            "EVIDENCE_DIR": str(evidence_dir),
            "CRITICAL_FLOWS_RUN_STAMP": TEST_RUN_STAMP,
            "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
            **(extra_env or {}),
        },
        check=False,
    )


def run_fixed_ma10_verifier(
    evidence_dir: Path,
    *,
    gate_exit: int,
    run_stamp: str = TEST_RUN_STAMP,
    context_key: str = TEST_RUN_CONTEXT_KEY,
) -> subprocess.CompletedProcess[str]:
    """Run the literal repository MA-10 verifier against an existing packet."""
    return subprocess.run(
        [
            "python3",
            str(MA10_VALIDATOR),
            "--verify-manifest",
            "--evidence-dir",
            str(evidence_dir),
            "--gate-exit",
            str(gate_exit),
            "--run-stamp",
            run_stamp,
            "--store",
            "target",
            "--flow-id",
            "MA-10-i18n-order-notes",
            "--purpose",
            "clean-debug-log",
        ],
        cwd=REPO,
        env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": context_key},
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_agent_result(
    results_dir: Path,
    flow: str,
    store: str,
    verdict: str,
) -> Path:
    results_dir.mkdir(parents=True, exist_ok=True)
    path = results_dir / f"{flow}.json"
    _, context = ensure_context(results_dir.parent)
    evidence_dir = results_dir.parent / "artifacts" / flow
    evidence_dir.mkdir(parents=True, exist_ok=True)
    store_results = []
    for result_store in ("ref", "target"):
        artifact = evidence_dir / f"{result_store}.png"
        artifact.write_bytes(f"{flow}:{result_store}".encode("utf-8"))
        store_results.append(
            {
                "store": result_store,
                "verdict": verdict,
                "end_state": "order paid",
                "ux_observations": ["expected controls were usable"],
                "visual_diffs": [],
                "evidence_paths": [str(artifact)],
            }
        )
    payload = CONTEXT_MODULE.stamp_generated_result(
        {
            "flow": flow,
            "store_results": store_results,
            "parity_verdict": verdict,
            "regression_note": "",
        },
        context,
    )
    path.write_text(
        json.dumps(payload, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return path


def write_agent_result_payload(results_dir: Path, flow: str, payload: dict) -> Path:
    results_dir.mkdir(parents=True, exist_ok=True)
    path = results_dir / f"{flow}.json"
    _, context = ensure_context(results_dir.parent)
    for store_result in payload.get("store_results", []):
        if isinstance(store_result, dict):
            store_result["evidence_paths"] = []
    stamped = CONTEXT_MODULE.stamp_generated_result(payload, context)
    path.write_text(json.dumps(stamped, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def test_card_checkout_flow_passes_with_clean_exercised_order() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        write_executable(
            fake_wp,
            sc01_fake_wp_source(
                "native", "http://target.fake.test", "pi_fake", "ch_fake"
            ),
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0
        assert "SC-01-card-checkout" in result.stdout
        assert "scope=partial" in result.stdout
        assert "[target] store identity: owner=native home=http://target.fake.test" in result.stdout
        assert "captured order_id=123" in result.stdout
        assert "EXERCISER NOT WIRED" not in result.stdout
        assert "PASS log-clean target" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout
        assert "Matrix coverage:" in result.stdout
        assert "run archived ->" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["scope"] == "partial"
        assert rollup["run_stamp"]
        assert rollup["matrix"]["total"] == 76
        assert rollup["matrix"]["covered"] == 1
        assert "SC-01" not in rollup["matrix"]["uncovered_ids"]
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
            }
        ]


def test_sc02_deterministic_flow_runs_each_store_once_and_binds_manifests() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-pass-") as tmp:
        evidence_dir = Path(tmp)
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(fake_ref_wp, sc02_fake_wp_source("ref"))
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--ref-url",
            "http://ref.sc02.localhost:8082",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["ref", "target"]
        assert "EXERCISER NOT WIRED" not in result.stdout
        assert result.stdout.count("deterministic verdict: PASS") == 2
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["summary"]["passed"] == 2
        for row in rollup["results"]:
            manifest_path = Path(row["evidence_path"])
            assert manifest_path.is_file()
            assert row["evidence_sha256"] == file_sha256(manifest_path)
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            assert manifest["schema"] == "woopayments_sc02_manifest.v1"
            assert manifest["status"] == "pass"
            assert manifest["stage"] == "post"


def test_sc02_transport_block_invokes_checkout_once_and_never_collects_post() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-block-") as tmp:
        evidence_dir = Path(tmp)
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
                "SC02_FAKE_HTTP_MODE": "blocked",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["target"]
        flow_root = (
            evidence_dir
            / "runs"
            / f"{TEST_RUN_STAMP}-partial"
            / "SC-02-blocks-card-checkout"
        )
        assert not (flow_root / "target-post.json").exists()
        manifest_path = flow_root / "target-manifest.json"
        assert manifest_path.is_file()
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        assert manifest["status"] == "blocked"
        assert manifest["stage"] == "http"


def test_sc02_structured_checkout_failure_remains_fail_without_post_collection() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-fail-") as tmp:
        evidence_dir = Path(tmp)
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
                "SC02_FAKE_HTTP_MODE": "fail",
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["target"]
        flow_root = (
            evidence_dir
            / "runs"
            / f"{TEST_RUN_STAMP}-partial"
            / "SC-02-blocks-card-checkout"
        )
        assert not (flow_root / "target-post.json").exists()
        manifest = json.loads(
            (flow_root / "target-manifest.json").read_text(encoding="utf-8")
        )
        assert manifest["status"] == "fail"
        assert manifest["stage"] == "http"
        assert manifest["verdict_sources"] == ["http_failed"]


def test_sc02_parity_fail_binds_reference_actual_http_prefix() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-parity-fail-") as tmp:
        evidence_dir = Path(tmp)
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(fake_ref_wp, sc02_fake_wp_source("ref"))
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--ref-url",
            "http://ref.sc02.localhost:8082",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
                "SC02_FAKE_HTTP_REF_MODE": "fail",
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["ref", "target"]
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert [(row["store"], row["status"]) for row in rollup["results"]] == [
            ("ref", "FAIL"),
            ("target", "FAIL"),
        ]
        target = next(row for row in rollup["results"] if row["store"] == "target")
        manifest_path = Path(target["evidence_path"])
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        assert manifest["status"] == "fail"
        assert manifest["stage"] == "post"
        assert manifest["verdict_sources"] == ["comparison_failed"]
        assert "ref-http.json" in manifest["files"]
        assert "ref-post.json" not in manifest["files"]


@pytest.mark.parametrize(
    "reference_log_status,expected_status,expected_source",
    [
        pytest.param("fail", "FAIL", "comparison_failed", id="diagnostic-fail"),
        pytest.param(
            "blocked", "BLOCKED", "comparison_blocked", id="diagnostic-blocked"
        ),
    ],
)
def test_sc02_dual_store_verdict_includes_reference_diagnostics(
    reference_log_status: str, expected_status: str, expected_source: str
) -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-ref-log-") as tmp:
        evidence_dir = Path(tmp)
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(
            fake_ref_wp,
            sc02_fake_wp_source("ref", log_status=reference_log_status),
        )
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--ref-url",
            "http://ref.sc02.localhost:8082",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
            },
        )

        expected_exit = 1 if expected_status == "FAIL" else 3
        assert result.returncode == expected_exit, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["ref", "target"]
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert [(row["store"], row["status"]) for row in rollup["results"]] == [
            ("ref", expected_status),
            ("target", expected_status),
        ]
        target = next(row for row in rollup["results"] if row["store"] == "target")
        manifest = json.loads(Path(target["evidence_path"]).read_text(encoding="utf-8"))
        assert manifest["verdict_sources"] == [expected_source]
        comparison = json.loads(
            (Path(target["evidence_path"]).parent / "comparison.json").read_text(
                encoding="utf-8"
            )
        )
        assert comparison["status"].upper() == expected_status
        assert comparison["parity"]["clean_diagnostics"] is False


def test_sc02_blocked_preflight_builds_manifest_without_http_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sc02-preflight-") as tmp:
        evidence_dir = Path(tmp)
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(
            fake_target_wp, sc02_fake_wp_source("target", connected=False)
        )
        write_executable(fake_http, sc02_fake_http_source(calls))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert not calls.exists()
        flow_root = (
            evidence_dir
            / "runs"
            / f"{TEST_RUN_STAMP}-partial"
            / "SC-02-blocks-card-checkout"
        )
        assert not (flow_root / "target-http.json").exists()
        assert not (flow_root / "target-post.json").exists()
        manifest = json.loads(
            (flow_root / "target-manifest.json").read_text(encoding="utf-8")
        )
        assert manifest["status"] == "blocked"
        assert manifest["stage"] == "preflight"
        assert manifest["verdict_sources"] == ["preflight_blocked"]


@pytest.mark.parametrize(
    "mode",
    ("missing_manifest", "missing_artifact", "tampered_hmac", "wrong_binding"),
)
def test_sc02_runner_rejects_unbound_or_incomplete_target_manifest(mode: str) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-sc02-{mode}-") as tmp:
        evidence_dir = Path(tmp)
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_http = evidence_dir / "fake-sc02-http.py"
        fake_evidence = evidence_dir / "fake-sc02-evidence.py"
        calls = evidence_dir / "http-calls.txt"
        write_executable(fake_ref_wp, sc02_fake_wp_source("ref"))
        write_executable(fake_target_wp, sc02_fake_wp_source("target"))
        write_executable(fake_http, sc02_fake_http_source(calls))
        write_executable(fake_evidence, sc02_adversarial_evidence_source(mode))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-02",
            "--ref-url",
            "http://ref.sc02.localhost:8082",
            "--target-url",
            "http://target.sc02.localhost:8889",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "SC02_HTTP_DRIVER": str(fake_http),
                "SC02_EVIDENCE_TOOL": str(fake_evidence),
            },
        )

        assert result.returncode == 3, (mode, result.stdout, result.stderr)
        assert calls.read_text(encoding="utf-8").splitlines() == ["ref", "target"]
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        target = next(row for row in rollup["results"] if row["store"] == "target")
        assert target["status"] == "BLOCKED"
        assert "evidence_path" not in target


def test_mo01_deterministic_flow_classifies_pass_fail_and_blocked() -> None:
    cases = (
        {
            "name": "pass",
            "target_status": "raw",
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "fail",
            "target_status": "raw",
            "target_intent_status": "succeeded",
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "trusted_fail_log_blocked",
            "target_status": "raw",
            "target_intent_status": "succeeded",
            "target_log_probe_exit_code": 3,
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "pass_log_blocked",
            "target_status": "raw",
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "parity_fail",
            "target_status": "raw",
            "target_total_minor": 5100,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "blocked",
            "target_status": "blocked",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "attribution_blocked",
            "target_status": "raw",
            "target_runtime_owner": "plugin",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "contradictory_blocked",
            "target_status": "raw",
            "target_blockers": ["Provider state is unavailable."],
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "currency_fail",
            "target_status": "raw",
            "target_provider_currency": "EUR",
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "malformed_comparison_blocked",
            "target_status": "raw",
            "malformed_comparison": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_prerequisite_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 3,
            "target_capture_product_failure": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_transport_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "target_capture_product_failure": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_product_fail_post_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 1,
            "target_capture_product_failure": True,
            "post_state_exit_code": 3,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_usage_blocked",
            "target_status": "raw",
            "target_capture_exit_code": 2,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_pass_post_blocked",
            "target_status": "raw",
            "post_state_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "transport_blocked",
            "target_status": "raw",
            "state_exit_code": 2,
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
    )

    for case in cases:
        name = case["name"]
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-mo01-{name}-") as tmp:
            evidence_dir = Path(tmp)
            flow_driver = evidence_dir / "fake-flow-drive.sh"
            fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
            fake_target_wp = evidence_dir / "fake-target-wp.sh"
            calls = evidence_dir / "calls.txt"
            comparator = MO01_COMPARATOR

            write_executable(
                flow_driver,
                """#!/usr/bin/env bash
printf '%s:%s\\n' "$STORE_NAME" "$1" >> "$MO01_CALL_LOG"
if [ "$1" = "charge" ]; then
  if [ "$STORE_NAME" = "ref" ]; then
    printf '%s\\n' '{"op":"charge","order_id":101,"charge_id":"ch_ref_manual","intent_id":"pi_ref_manual"}'
  else
    printf '%s\\n' '{"op":"charge","order_id":202,"charge_id":"ch_target_manual","intent_id":"pi_target_manual"}'
  fi
  exit 0
fi
if [ "$STORE_NAME" = "target" ] && [ "${MO01_FAKE_CAPTURE_EXIT:-0}" -ne 0 ]; then
  if [ "${MO01_FAKE_CAPTURE_PRODUCT_FAILURE:-0}" -eq 1 ]; then
    printf '%s\n' '{"op":"capture","order_id":202,"intent_id":"pi_target_manual","charge_id":"ch_target_manual","status":"on-hold","intention_status":"requires_capture","success":false,"provider_status":"failed","error_message":"Capture was declined.","error_code":"capture_declined"}' >&2
  else
    printf '%s\n' 'fake WP-CLI/provider transport unavailable' >&2
  fi
  exit "$MO01_FAKE_CAPTURE_EXIT"
fi
printf '%s\\n' "{\"op\":\"capture\",\"order_id\":${!#},\"success\":true}"
""",
            )
            write_executable(
                fake_ref_wp,
                mo01_fake_wp_source(
                    "plugin",
                    "http://ref.fake.test",
                    mo01_state_payload("ref", "pre"),
                    mo01_state_payload("ref", "post"),
                ),
            )
            target_post_payload = mo01_state_payload(
                "target",
                "post",
                total_minor=case.get("target_total_minor", 5000),
                provider_currency=case.get("target_provider_currency", "USD"),
            )
            if case.get("target_capture_exit_code", 0) != 0:
                target_post_payload = mo01_state_payload(
                    "target",
                    "pre",
                    total_minor=case.get("target_total_minor", 5000),
                    provider_currency=case.get("target_provider_currency", "USD"),
                )
                target_post_payload["phase"] = "post"
            write_executable(
                fake_target_wp,
                mo01_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    mo01_state_payload(
                        "target",
                        "pre",
                        status=case["target_status"],
                        intent_status=case.get("target_intent_status"),
                        total_minor=case.get("target_total_minor", 5000),
                        runtime_owner=case.get("target_runtime_owner"),
                        blockers=case.get("target_blockers"),
                        provider_currency=case.get("target_provider_currency", "USD"),
                    ),
                    target_post_payload,
                    state_exit_code=case.get("state_exit_code", 0),
                    post_state_exit_code=case.get("post_state_exit_code"),
                    log_probe_exit_code=case.get("target_log_probe_exit_code", 0),
                ),
            )
            if case.get("malformed_comparison"):
                comparator = evidence_dir / "malformed-comparator.py"
                write_executable(
                    comparator,
                    f"""#!/usr/bin/env python3
import os
import sys

if sys.argv[1] == "compare":
    print("Traceback: comparator crashed")
    raise SystemExit(1)
os.execv(sys.executable, [sys.executable, {json.dumps(str(MO01_COMPARATOR))}, *sys.argv[1:]])
""",
                )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MO-01",
                evidence_dir=evidence_dir,
                extra_env={
                    "MO01_FLOW_DRIVER": str(flow_driver),
                    "MO01_STATE_DRIVER": str(COMMON),
                    "MO01_CALL_LOG": str(calls),
                    "MO01_COMPARATOR": str(comparator),
                    "MO01_FAKE_CAPTURE_EXIT": str(case.get("target_capture_exit_code", 0)),
                    "MO01_FAKE_CAPTURE_PRODUCT_FAILURE": (
                        "1" if case.get("target_capture_product_failure") else "0"
                    ),
                    "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                },
            )

            assert result.returncode == case["expected_rc"], result.stdout + result.stderr
            assert "MO-01-manual-capture-order" in result.stdout
            assert "EXERCISER NOT WIRED" not in result.stdout
            assert "captured authorization order_id=101" in result.stdout
            assert "PASS log-clean ref" in result.stdout
            assert calls.read_text(encoding="utf-8").count("target:capture") == case["target_capture_calls"]

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"] == {
                "blocked": case["blocked"],
                "failed": case["failed"],
                "passed": 2 - case["failed"] - case["blocked"],
                "queued_agent_specs": 0,
            }

            if name == "pass":
                assert "cross-store pre/post parity: PASS" in result.stdout
                assert "[MO-01/target] deterministic verdict: PASS" in result.stdout
                rows = {
                    row["store"]: row
                    for row in rollup["results"]
                    if row["flow"] == "MO-01-manual-capture-order"
                }
                expected_files = {
                    "ref": {"ref-pre.json", "ref-post.json"},
                    "target": {
                        "ref-pre.json",
                        "ref-post.json",
                        "ref-execution.json",
                        "target-pre.json",
                        "target-post.json",
                        "target-execution.json",
                        "comparison.json",
                    },
                }
                expected_files["ref"].add("ref-execution.json")
                for store in ("ref", "target"):
                    row = rows[store]
                    manifest_path = Path(row["evidence_path"])
                    assert manifest_path.name == f"{store}-manifest.json"
                    assert row["evidence_sha256"] == file_sha256(manifest_path)
                    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                    assert manifest["schema"] == "woopayments_mo01_manifest.v1"
                    assert manifest["run_stamp"] == rollup["run_stamp"]
                    assert manifest["run_scope"] == "partial"
                    assert manifest["store"] == store
                    assert manifest["status"] == "pass"
                    assert manifest["exit_code"] == 0
                    assert manifest["verdict_sources"] == []
                    assert set(manifest["files"]) == expected_files[store]
                    unsigned = dict(manifest)
                    unsigned.pop("payload_sha256")
                    assert manifest["payload_sha256"] == "sha256:" + hashlib.sha256(
                        json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode(
                            "utf-8"
                        )
                    ).hexdigest()
                    for filename, binding in manifest["files"].items():
                        artifact = manifest_path.parent / filename
                        payload = json.loads(artifact.read_text(encoding="utf-8"))
                        assert binding == {
                            "file_sha256": file_sha256(artifact),
                            "payload_sha256": payload["payload_sha256"],
                        }

                mutated_dir = evidence_dir / "mutated-coherent-chain"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, mutated_dir)
                mutated_pre_path = mutated_dir / "target-pre.json"
                mutated_pre = json.loads(mutated_pre_path.read_text(encoding="utf-8"))
                mutated_pre["order"]["total_minor"] = 5100
                mutated_pre_unsigned = dict(mutated_pre)
                mutated_pre_unsigned.pop("payload_sha256")
                mutated_pre["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_pre_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_pre_path.write_text(json.dumps(mutated_pre), encoding="utf-8")

                mutated_comparison_path = mutated_dir / "comparison.json"
                mutated_comparison = json.loads(
                    mutated_comparison_path.read_text(encoding="utf-8")
                )
                mutated_comparison["inputs"]["target_pre"] = mutated_pre["payload_sha256"]
                mutated_comparison_unsigned = dict(mutated_comparison)
                mutated_comparison_unsigned.pop("payload_sha256")
                mutated_comparison["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_comparison_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_comparison_path.write_text(
                    json.dumps(mutated_comparison),
                    encoding="utf-8",
                )

                mutated_manifest_path = mutated_dir / "target-manifest.json"
                mutated_manifest = json.loads(
                    mutated_manifest_path.read_text(encoding="utf-8")
                )
                for filename in ("target-pre.json", "comparison.json"):
                    artifact = mutated_dir / filename
                    payload = json.loads(artifact.read_text(encoding="utf-8"))
                    mutated_manifest["files"][filename] = {
                        "file_sha256": file_sha256(artifact),
                        "payload_sha256": payload["payload_sha256"],
                    }
                mutated_manifest_unsigned = dict(mutated_manifest)
                mutated_manifest_unsigned.pop("payload_sha256")
                mutated_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_manifest_path.write_text(
                    json.dumps(mutated_manifest),
                    encoding="utf-8",
                )
                coherent_rehash = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(mutated_manifest_path),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert coherent_rehash.returncode == 3

                symlink_dir = evidence_dir / "symlinked-chain"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, symlink_dir)
                outside_pre = evidence_dir / "outside-target-pre.json"
                shutil.copy2(symlink_dir / "target-pre.json", outside_pre)
                (symlink_dir / "target-pre.json").unlink()
                (symlink_dir / "target-pre.json").symlink_to(outside_pre)
                symlink_validation = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(symlink_dir / "target-manifest.json"),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert symlink_validation.returncode == 3

                relabeled_dir = evidence_dir / "all-pass-relabeled-fail"
                shutil.copytree(Path(rows["target"]["evidence_path"]).parent, relabeled_dir)
                relabeled_execution_path = relabeled_dir / "target-execution.json"
                relabeled_execution = json.loads(
                    relabeled_execution_path.read_text(encoding="utf-8")
                )
                relabeled_execution.update(
                    {
                        "status": "fail",
                        "exit_code": 1,
                        "verdict_sources": ["capture_operation_failed"],
                    }
                )
                relabeled_execution_unsigned = dict(relabeled_execution)
                relabeled_execution_unsigned.pop("payload_sha256")
                relabeled_execution["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        relabeled_execution_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                relabeled_execution_path.write_text(
                    json.dumps(relabeled_execution),
                    encoding="utf-8",
                )
                relabeled_manifest_path = relabeled_dir / "target-manifest.json"
                relabeled_manifest = json.loads(
                    relabeled_manifest_path.read_text(encoding="utf-8")
                )
                relabeled_manifest.update(
                    {
                        "status": "fail",
                        "exit_code": 1,
                        "verdict_sources": ["capture_operation_failed"],
                    }
                )
                relabeled_manifest["files"]["target-execution.json"] = {
                    "file_sha256": file_sha256(relabeled_execution_path),
                    "payload_sha256": relabeled_execution["payload_sha256"],
                }
                relabeled_manifest_unsigned = dict(relabeled_manifest)
                relabeled_manifest_unsigned.pop("payload_sha256")
                relabeled_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        relabeled_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                relabeled_manifest_path.write_text(
                    json.dumps(relabeled_manifest),
                    encoding="utf-8",
                )
                relabeled_validation = subprocess.run(
                    [
                        "python3",
                        str(MO01_COMPARATOR),
                        "validate-bound-manifest",
                        "--manifest",
                        str(relabeled_manifest_path),
                        "--store",
                        "target",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "fail",
                        "--expected-exit-code",
                        "1",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert relabeled_validation.returncode == 3
            elif name == "fail":
                assert "provider intent status=succeeded want=requires_capture" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "trusted_fail_log_blocked":
                assert "provider intent status=succeeded want=requires_capture" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                assert Path(target_row["evidence_path"]).is_file()
            elif name == "pass_log_blocked":
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest = json.loads(
                    Path(target_row["evidence_path"]).read_text(encoding="utf-8")
                )
                assert manifest["verdict_sources"] == ["log_assertion_blocked"]
            elif name == "parity_fail":
                assert "cross-store pre/post parity: FAIL" in result.stdout
                assert "pre-capture canonical state differs" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "blocked":
                assert "Provider state is unavailable." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "attribution_blocked":
                assert "runtime owner=plugin want=native" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "contradictory_blocked":
                assert "Provider state is unavailable." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "currency_fail":
                assert "provider intent currency=EUR want=USD" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
            elif name == "malformed_comparison_blocked":
                assert "Comparator emitted malformed or contradictory evidence." in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                assert "evidence_path" in target_row, (
                    target_row,
                    result.stdout,
                    result.stderr,
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["comparison_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["comparison_exit_code"] == 3
                assert execution["verdict_sources"] == ["comparison_blocked"]
            elif name in {
                "capture_prerequisite_blocked",
                "capture_usage_blocked",
                "capture_transport_blocked",
            }:
                assert "capture operation blocked" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["status"] == "blocked"
                assert manifest["verdict_sources"] == ["capture_operation_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == case["target_capture_exit_code"]
                assert execution["capture_classification"] == "blocked"
                assert execution["post_state_exit_code"] == 1
            elif name == "capture_product_fail":
                assert "capture operation failed" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == [
                    "capture_operation_failed",
                    "comparison_failed",
                    "post_state_failed",
                ]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 1
                assert execution["capture_classification"] == "product_failure"
            elif name == "capture_product_fail_post_blocked":
                assert "post-capture state unavailable" in result.stdout
                assert "[MO-01/target] deterministic verdict: FAIL" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["capture_operation_failed"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 1
                assert execution["capture_classification"] == "product_failure"
                assert execution["post_state_exit_code"] == 3
            elif name == "capture_pass_post_blocked":
                assert "post-capture state unavailable" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout
                target_row = next(
                    row for row in rollup["results"] if row["store"] == "target"
                )
                manifest_path = Path(target_row["evidence_path"])
                manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
                assert manifest["verdict_sources"] == ["post_state_blocked"]
                execution = json.loads(
                    (manifest_path.parent / "target-execution.json").read_text(
                        encoding="utf-8"
                    )
                )
                assert execution["capture_exit_code"] == 0
                assert execution["capture_classification"] == "pass"
                assert execution["post_state_exit_code"] == 3
            else:
                assert "pre-capture state driver could not run" in result.stdout
                assert "[MO-01/target] deterministic verdict: BLOCKED" in result.stdout


def test_mo02_deterministic_flow_uses_authorizations_api_and_row_capture() -> None:
    cases = (
        {
            "name": "pass",
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "pre_authorization_missing",
            "pre_authorization_present": False,
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "post_authorization_retained",
            "post_authorization_present": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "parity_fail",
            "target_total_minor": 5100,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "blocked",
            "target_status": "blocked",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "post_authorization_present": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "capture_transport_blocked",
            "capture_status": "blocked",
            "capture_exit_code": 2,
            "post_authorization_present": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "pass_log_blocked",
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "malformed_comparison_blocked",
            "malformed_comparison": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
    )

    for case in cases:
        name = case["name"]
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-mo02-{name}-") as tmp:
            evidence_dir = Path(tmp)
            flow_driver = evidence_dir / "fake-flow-drive.sh"
            fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
            fake_target_wp = evidence_dir / "fake-target-wp.sh"
            calls = evidence_dir / "calls.txt"
            comparator = MO02_EVIDENCE

            write_executable(
                flow_driver,
                """#!/usr/bin/env bash
if [ "$1" != "charge" ]; then
  printf '%s\\n' 'MO-02 must capture through the authorizations REST route.' >&2
  exit 9
fi
if [ "$STORE_NAME" = "ref" ]; then
  printf '%s\\n' '{"op":"charge","order_id":101,"charge_id":"ch_ref_uncaptured","intent_id":"pi_ref_uncaptured"}'
else
  printf '%s\\n' '{"op":"charge","order_id":202,"charge_id":"ch_target_uncaptured","intent_id":"pi_target_uncaptured"}'
fi
""",
            )
            write_executable(
                fake_ref_wp,
                mo02_fake_wp_source(
                    "plugin",
                    "http://ref.fake.test",
                    mo02_state_payload("ref", "pre"),
                    mo02_state_payload("ref", "post"),
                    mo02_capture_payload("ref"),
                    calls,
                ),
            )
            target_total = case.get("target_total_minor", 5000)
            write_executable(
                fake_target_wp,
                mo02_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    mo02_state_payload(
                        "target",
                        "pre",
                        status=case.get("target_status", "raw"),
                        authorization_present=case.get("pre_authorization_present"),
                        total_minor=target_total,
                    ),
                    mo02_state_payload(
                        "target",
                        "post",
                        authorization_present=case.get("post_authorization_present"),
                        total_minor=target_total,
                    ),
                    mo02_capture_payload(
                        "target",
                        status=case.get("capture_status", "pass"),
                    ),
                    calls,
                    capture_exit_code=case.get("capture_exit_code", 0),
                    log_probe_exit_code=case.get("target_log_probe_exit_code", 0),
                ),
            )
            if case.get("malformed_comparison"):
                comparator = evidence_dir / "malformed-mo02-evidence.py"
                write_executable(
                    comparator,
                    f"""#!/usr/bin/env python3
import os
import sys

if sys.argv[1] == "compare":
    print("Traceback: comparator crashed")
    raise SystemExit(1)
os.execv(sys.executable, [sys.executable, {json.dumps(str(MO02_EVIDENCE))}, *sys.argv[1:]])
""",
                )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MO-02",
                evidence_dir=evidence_dir,
                extra_env={
                    "MO02_FLOW_DRIVER": str(flow_driver),
                    "MO02_STATE_DRIVER": str(COMMON),
                    "MO02_COMPARATOR": str(comparator),
                    "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                },
            )

            assert result.returncode == case["expected_rc"], result.stdout + result.stderr
            assert "MO-02-manual-capture-uncaptured-tab" in result.stdout
            assert "EXERCISER NOT WIRED" not in result.stdout
            assert "captured authorization order_id=101" in result.stdout
            call_lines = calls.read_text(encoding="utf-8").splitlines() if calls.exists() else []
            assert call_lines.count("target:capture") == case["target_capture_calls"]

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"] == {
                "blocked": case["blocked"],
                "failed": case["failed"],
                "passed": 2 - case["failed"] - case["blocked"],
                "queued_agent_specs": 0,
            }

            target_row = next(row for row in rollup["results"] if row["store"] == "target")
            assert "evidence_path" in target_row, (
                name,
                target_row,
                result.stdout,
                result.stderr,
            )
            manifest_path = Path(target_row["evidence_path"])
            assert target_row["evidence_sha256"] == file_sha256(manifest_path)
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            assert manifest["schema"] == "woopayments_mo02_manifest.v1"
            assert manifest["flow"] == "MO-02-manual-capture-uncaptured-tab"
            assert manifest["run_stamp"] == rollup["run_stamp"]

            if name == "pass":
                assert "authorizations row transition: PASS" in result.stdout
                assert "cross-store pre/post parity: PASS" in result.stdout
                assert "[MO-02/target] deterministic verdict: PASS" in result.stdout
                assert set(manifest["files"]) == {
                    "ref-pre.json",
                    "ref-capture.json",
                    "ref-post.json",
                    "ref-execution.json",
                    "target-pre.json",
                    "target-capture.json",
                    "target-post.json",
                    "target-execution.json",
                    "comparison.json",
                }

                ref_row = next(
                    row
                    for row in rollup["results"]
                    if row["store"] == "ref"
                    and row["flow"] == "MO-02-manual-capture-uncaptured-tab"
                )
                mutated_dir = evidence_dir / "mo02-ref-capture-rebound"
                shutil.copytree(Path(ref_row["evidence_path"]).parent, mutated_dir)
                mutated_capture_path = mutated_dir / "ref-capture.json"
                mutated_capture = json.loads(
                    mutated_capture_path.read_text(encoding="utf-8")
                )
                mutated_capture["order_id"] = 999
                mutated_capture["route"] = (
                    "/wc/v3/payments/orders/999/capture_authorization"
                )
                mutated_capture_unsigned = dict(mutated_capture)
                mutated_capture_unsigned.pop("payload_sha256")
                mutated_capture["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_capture_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_capture_path.write_text(
                    json.dumps(mutated_capture), encoding="utf-8"
                )

                mutated_manifest_path = mutated_dir / "ref-manifest.json"
                mutated_manifest = json.loads(
                    mutated_manifest_path.read_text(encoding="utf-8")
                )
                mutated_manifest["files"]["ref-capture.json"] = {
                    "file_sha256": file_sha256(mutated_capture_path),
                    "payload_sha256": mutated_capture["payload_sha256"],
                }
                mutated_manifest_unsigned = dict(mutated_manifest)
                mutated_manifest_unsigned.pop("payload_sha256")
                mutated_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        mutated_manifest_unsigned,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                mutated_manifest_path.write_text(
                    json.dumps(mutated_manifest), encoding="utf-8"
                )
                rebound_validation = subprocess.run(
                    [
                        "python3",
                        str(MO02_EVIDENCE),
                        "validate-bound-manifest",
                        "--manifest",
                        str(mutated_manifest_path),
                        "--store",
                        "ref",
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert rebound_validation.returncode == 3
            elif name == "pre_authorization_missing":
                assert "exact authorization row count=0 want=1" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "post_authorization_retained":
                assert "exact authorization row count=1 want=0" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "parity_fail":
                assert "cross-store pre/post parity: FAIL" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "blocked":
                assert "Authorizations endpoint is unavailable." in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "capture_product_fail":
                assert "row capture operation failed" in result.stdout
                assert "[MO-02/target] deterministic verdict: FAIL" in result.stdout
            elif name == "capture_transport_blocked":
                assert "row capture operation blocked" in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "pass_log_blocked":
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout
                assert manifest["verdict_sources"] == ["log_assertion_blocked"]
            else:
                assert "Comparator emitted malformed or contradictory evidence." in result.stdout
                assert "[MO-02/target] deterministic verdict: BLOCKED" in result.stdout


def test_mo02_evidence_rejects_incomplete_fail_transient_http_and_expired_row() -> None:
    run_stamp = "20260716T143519Z-19751"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo02-adversarial-") as tmp:
        evidence_dir = Path(tmp)
        execution = {
            "schema": "woopayments_mo02_execution.v1",
            "status": "fail",
            "store": "ref",
            "run_stamp": run_stamp,
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "authorization_exit_code": 0,
            "authorization_order_id_present": True,
            "pre_state_exit_code": 0,
            "capture_exit_code": 0,
            "capture_classification": "pass",
            "post_state_exit_code": 1,
            "comparison_exit_code": None,
            "log_assertion_exit_code": 0,
        }
        execution["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(execution, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        execution_path = evidence_dir / "ref-execution.json"
        execution_path.write_text(json.dumps(execution), encoding="utf-8")
        manifest = {
            "schema": "woopayments_mo02_manifest.v1",
            "flow": "MO-02-manual-capture-uncaptured-tab",
            "run_stamp": run_stamp,
            "run_scope": "partial",
            "store": "ref",
            "status": "fail",
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "files": {
                "ref-execution.json": {
                    "file_sha256": file_sha256(execution_path),
                    "payload_sha256": execution["payload_sha256"],
                }
            },
        }
        manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest_path = evidence_dir / "ref-manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        incomplete_fail = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "validate-bound-manifest",
                "--manifest",
                str(manifest_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--expected-status",
                "fail",
                "--expected-exit-code",
                "1",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert incomplete_fail.returncode == 3

        transient_capture = mo02_capture_payload("ref", status="fail")
        transient_capture["http_status"] = 503
        transient_capture_result = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "normalize-capture",
                "--store",
                "ref",
                "--order-id",
                "101",
                "--intent-id",
                "pi_ref_uncaptured",
                "--charge-id",
                "ch_ref_uncaptured",
                "--expected-exit-code",
                "1",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(transient_capture),
            text=True,
            capture_output=True,
            check=False,
        )
        assert transient_capture_result.returncode == 3
        assert json.loads(transient_capture_result.stdout)["status"] == "blocked"

        expired_state = mo02_state_payload("ref", "pre")
        expired_state["order"]["created"] = 1784212519
        expired_state["authorizations"]["observed_at"] = 1784212519
        expired_state["authorizations"]["matched_rows"][0].update(
            {"status": "expired", "created": 1}
        )
        expired_state_result = subprocess.run(
            [
                "python3",
                str(MO02_EVIDENCE),
                "normalize-state",
                "--store",
                "ref",
                "--phase",
                "pre",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(expired_state),
            text=True,
            capture_output=True,
            check=False,
        )
        assert expired_state_result.returncode == 1
        expired_payload = json.loads(expired_state_result.stdout)
        assert "authorization status=expired want=succeeded" in expired_payload["errors"]
        assert "authorization created timestamp is outside the active window" in expired_payload[
            "errors"
        ]


def test_mo03_deterministic_flow_uses_provider_order_capture() -> None:
    cases = (
        {
            "name": "pass",
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "pre_authorization_missing",
            "pre_authorization_present": False,
            "expected_rc": 1,
            "target_capture_calls": 0,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "post_authorization_retained",
            "post_authorization_present": True,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "parity_fail",
            "target_total_minor": 5100,
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "state_blocked",
            "target_status": "blocked",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "owner_mismatch_blocked",
            "target_runtime_owner": "plugin",
            "expected_rc": 3,
            "target_capture_calls": 0,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "post_authorization_present": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail_post_blocked",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "target_post_state_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_product_fail_log_blocked",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_transport_blocked",
            "capture_status": "blocked",
            "capture_exit_code": 1,
            "post_authorization_present": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "capture_secret_diagnostic_blocked",
            "capture_status": "fail",
            "capture_exit_code": 1,
            "capture_error_code": "sk_test_mo03_must_not_escape",
            "capture_error_message": "Bearer merchant-private@example.test",
            "post_authorization_present": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "pass_log_blocked",
            "target_log_probe_exit_code": 3,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
        {
            "name": "pass_log_failed",
            "target_log_probe_status": "fail",
            "expected_rc": 1,
            "target_capture_calls": 1,
            "failed": 1,
            "blocked": 0,
        },
        {
            "name": "authorization_note_count_is_diagnostic",
            "target_authorization_note_count": 0,
            "expected_rc": 0,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 0,
        },
        {
            "name": "malformed_comparison_blocked",
            "malformed_comparison": True,
            "expected_rc": 3,
            "target_capture_calls": 1,
            "failed": 0,
            "blocked": 1,
        },
    )

    for case in cases:
        name = case["name"]
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-mo03-{name}-") as tmp:
            evidence_dir = Path(tmp)
            flow_driver = evidence_dir / "fake-flow-drive.sh"
            fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
            fake_target_wp = evidence_dir / "fake-target-wp.sh"
            calls = evidence_dir / "calls.txt"
            comparator = MO03_EVIDENCE

            capture_status = case.get("capture_status", "pass")
            capture_exit_code = case.get("capture_exit_code", 0)
            target_capture = mo03_capture_payload("target", status=capture_status)
            if "capture_error_code" in case:
                target_capture.update(
                    {
                        "error_code": case["capture_error_code"],
                        "error_message": case["capture_error_message"],
                    }
                )
            write_executable(
                flow_driver,
                mo03_fake_flow_driver_source(
                    target_capture,
                    capture_exit_code,
                    calls,
                ),
            )
            write_executable(
                fake_ref_wp,
                mo03_fake_wp_source(
                    "plugin",
                    "http://ref.mo03.fake.test",
                    mo03_state_payload("ref", "pre"),
                    mo03_state_payload("ref", "post"),
                ),
            )
            target_total = case.get("target_total_minor", 5000)
            target_post = mo03_state_payload(
                "target",
                "post",
                authorization_present=case.get("post_authorization_present"),
                total_minor=target_total,
                authorization_note_count=case.get("target_authorization_note_count", 1),
            )
            if capture_status != "pass":
                target_post = mo03_state_payload(
                    "target",
                    "pre",
                    authorization_present=True,
                    total_minor=target_total,
                    authorization_note_count=case.get("target_authorization_note_count", 1),
                )
                target_post["phase"] = "post"
            write_executable(
                fake_target_wp,
                mo03_fake_wp_source(
                    "native",
                    "http://target.mo03.fake.test",
                    mo03_state_payload(
                        "target",
                        "pre",
                        status=case.get("target_status", "raw"),
                        authorization_present=case.get("pre_authorization_present"),
                        total_minor=target_total,
                        runtime_owner=case.get("target_runtime_owner"),
                        authorization_note_count=case.get(
                            "target_authorization_note_count", 1
                        ),
                    ),
                    target_post,
                    post_state_exit_code=case.get("target_post_state_exit_code"),
                    log_probe_exit_code=case.get("target_log_probe_exit_code", 0),
                    log_probe_status=case.get("target_log_probe_status", "pass"),
                ),
            )
            if case.get("malformed_comparison"):
                comparator = evidence_dir / "malformed-mo03-evidence.py"
                write_executable(
                    comparator,
                    f"""#!/usr/bin/env python3
import os
import sys

if sys.argv[1] == "compare":
    print("Traceback: comparator crashed")
    raise SystemExit(1)
os.execv(sys.executable, [sys.executable, {json.dumps(str(MO03_EVIDENCE))}, *sys.argv[1:]])
""",
                )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MO-03",
                evidence_dir=evidence_dir,
                extra_env={
                    "MO03_FLOW_DRIVER": str(flow_driver),
                    "MO03_STATE_DRIVER": str(MO03_DRIVER),
                    "MO03_COMPARATOR": str(comparator),
                    "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                },
            )

            assert result.returncode == case["expected_rc"], result.stdout + result.stderr
            assert "MO-03-manual-capture-payment-details" in result.stdout
            assert "EXERCISER NOT WIRED" not in result.stdout
            assert "captured authorization order_id=301" in result.stdout
            call_lines = calls.read_text(encoding="utf-8").splitlines() if calls.exists() else []
            target_capture_lines = [
                line for line in call_lines if line.startswith("target:capture:")
            ]
            ref_capture_lines = [line for line in call_lines if line.startswith("ref:capture:")]
            assert len(ref_capture_lines) == 1
            assert "capture --deterministic --order-id 301" in ref_capture_lines[0]
            assert len(target_capture_lines) == case["target_capture_calls"]
            for line in target_capture_lines:
                assert "capture --deterministic --native --order-id 302" in line

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"] == {
                "blocked": case["blocked"],
                "failed": case["failed"],
                "passed": 2 - case["failed"] - case["blocked"],
                "queued_agent_specs": 0,
            }
            target_row = next(row for row in rollup["results"] if row["store"] == "target")
            assert "evidence_path" in target_row, (
                name,
                target_row,
                result.stdout,
                result.stderr,
            )
            manifest_path = Path(target_row["evidence_path"])
            assert target_row["evidence_sha256"] == file_sha256(manifest_path)
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            assert manifest["schema"] == "woopayments_mo03_manifest.v1"
            assert manifest["flow"] == "MO-03-manual-capture-payment-details"
            assert manifest["run_stamp"] == rollup["run_stamp"]
            target_log_path = manifest_path.parent / "target-log-scan.json"
            assert "target-log-scan.json" in manifest["files"]
            target_log = json.loads(target_log_path.read_text(encoding="utf-8"))
            assert target_log["schema"] == "woopayments_mo03_log_scan.v6"
            assert target_log["store"] == "target"
            assert target_log["run_stamp"] == rollup["run_stamp"]
            assert target_log["payload_sha256"] == (
                "sha256:"
                + hashlib.sha256(
                    json.dumps(
                        {
                            key: value
                            for key, value in target_log.items()
                            if key != "payload_sha256"
                        },
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
            )
            assert manifest["files"]["target-log-scan.json"]["file_sha256"] == file_sha256(
                target_log_path
            )

            if name == "pass":
                assert "provider/order capture transition: PASS" in result.stdout
                assert "cross-store pre/post financial parity: PASS" in result.stdout
                assert "does not prove the payment-details browser affordance" in result.stdout
                assert "[MO-03/target] deterministic verdict: PASS" in result.stdout
                assert set(manifest["files"]) == {
                    "ref-fixture.json",
                    "ref-pre.json",
                    "ref-capture.json",
                    "ref-post.json",
                    "ref-execution.json",
                    "ref-log-scan.json",
                    "target-fixture.json",
                    "target-pre.json",
                    "target-capture.json",
                    "target-post.json",
                    "target-execution.json",
                    "target-log-scan.json",
                    "comparison.json",
                }
                comparison = json.loads(
                    (manifest_path.parent / "comparison.json").read_text(encoding="utf-8")
                )
                masked_intent = comparison["reference"]["pre"]["identity"]["intent_id"]
                assert masked_intent.startswith("pi_…")
                assert masked_intent != "pi_ref_details"

                ref_row = next(
                    row
                    for row in rollup["results"]
                    if row["store"] == "ref"
                    and row["flow"] == "MO-03-manual-capture-payment-details"
                )
                rebound_dir = evidence_dir / "mo03-ref-coherent-rebind"
                shutil.copytree(Path(ref_row["evidence_path"]).parent, rebound_dir)
                changed_names = ("ref-pre.json", "ref-capture.json", "ref-post.json")
                for filename in changed_names:
                    artifact_path = rebound_dir / filename
                    artifact = json.loads(artifact_path.read_text(encoding="utf-8"))
                    if filename.endswith(("-pre.json", "-post.json")):
                        artifact["order"].update(
                            {
                                "id": 999,
                                "intent_id": "pi_ref_rebound",
                                "charge_id": "ch_ref_rebound",
                            }
                        )
                        artifact["provider"].update(
                            {
                                "intent_id": "pi_ref_rebound",
                                "charge_id": "ch_ref_rebound",
                            }
                        )
                        for row in artifact["authorizations"]["matched_rows"]:
                            row.update(
                                {
                                    "order_id": 999,
                                    "payment_intent_id": "pi_ref_rebound",
                                    "charge_id": "ch_ref_rebound",
                                }
                            )
                    else:
                        artifact.update(
                            {
                                "order_id": 999,
                                "intent_id": "pi_ref_rebound",
                                "charge_id": "ch_ref_rebound",
                            }
                        )
                    artifact.pop("payload_sha256")
                    artifact["payload_sha256"] = "sha256:" + hashlib.sha256(
                        json.dumps(
                            artifact,
                            sort_keys=True,
                            separators=(",", ":"),
                        ).encode("utf-8")
                    ).hexdigest()
                    artifact_path.write_text(json.dumps(artifact), encoding="utf-8")

                rebound_manifest_path = rebound_dir / "ref-manifest.json"
                rebound_manifest = json.loads(
                    rebound_manifest_path.read_text(encoding="utf-8")
                )
                for filename in changed_names:
                    artifact_path = rebound_dir / filename
                    artifact = json.loads(artifact_path.read_text(encoding="utf-8"))
                    rebound_manifest["files"][filename] = {
                        "file_sha256": file_sha256(artifact_path),
                        "payload_sha256": artifact["payload_sha256"],
                    }
                rebound_manifest.pop("payload_sha256")
                rebound_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        rebound_manifest,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                rebound_manifest_path.write_text(
                    json.dumps(rebound_manifest), encoding="utf-8"
                )
                rebound_validation = subprocess.run(
                    [
                        "python3",
                        str(MO03_EVIDENCE),
                        "validate-bound-manifest",
                        "--manifest",
                        str(rebound_manifest_path),
                        "--store",
                        "ref",
                        *MO03_LOG_CONTEXT_ARGS,
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert rebound_validation.returncode == 3

                log_rebind_dir = evidence_dir / "mo03-target-log-coherent-rebind"
                shutil.copytree(manifest_path.parent, log_rebind_dir)
                rebound_log_path = log_rebind_dir / "target-log-scan.json"
                rebound_log = json.loads(rebound_log_path.read_text(encoding="utf-8"))
                rebound_log["ignored_match_count"] = 101
                rebound_log.pop("payload_sha256")
                rebound_log["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        rebound_log,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                rebound_log_path.write_text(json.dumps(rebound_log), encoding="utf-8")
                rebound_manifest_path = log_rebind_dir / "target-manifest.json"
                rebound_manifest = json.loads(
                    rebound_manifest_path.read_text(encoding="utf-8")
                )
                rebound_manifest["files"]["target-log-scan.json"] = {
                    "file_sha256": file_sha256(rebound_log_path),
                    "payload_sha256": rebound_log["payload_sha256"],
                }
                rebound_manifest.pop("payload_sha256")
                rebound_manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
                    json.dumps(
                        rebound_manifest,
                        sort_keys=True,
                        separators=(",", ":"),
                    ).encode("utf-8")
                ).hexdigest()
                rebound_manifest_path.write_text(
                    json.dumps(rebound_manifest), encoding="utf-8"
                )
                log_rebind_validation = subprocess.run(
                    [
                        "python3",
                        str(MO03_EVIDENCE),
                        "validate-bound-manifest",
                        "--manifest",
                        str(rebound_manifest_path),
                        "--store",
                        "target",
                        *MO03_LOG_CONTEXT_ARGS,
                        "--run-stamp",
                        rollup["run_stamp"],
                        "--run-scope",
                        "partial",
                        "--expected-status",
                        "pass",
                        "--expected-exit-code",
                        "0",
                    ],
                    cwd=REPO,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                assert log_rebind_validation.returncode == 3
            elif name == "pre_authorization_missing":
                assert "exact authorization row count=0 want=1" in result.stdout
                assert "[MO-03/target] deterministic verdict: FAIL" in result.stdout
            elif name == "post_authorization_retained":
                assert "exact authorization row count=1 want=0" in result.stdout
                assert "[MO-03/target] deterministic verdict: FAIL" in result.stdout
            elif name == "parity_fail":
                assert "cross-store pre/post financial parity: FAIL" in result.stdout
                assert "[MO-03/target] deterministic verdict: FAIL" in result.stdout
            elif name == "state_blocked":
                assert "Payment details state is unavailable." in result.stdout
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "owner_mismatch_blocked":
                assert "runtime owner=plugin want=native" in result.stdout
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout
            elif name in {
                "capture_product_fail",
                "capture_product_fail_post_blocked",
                "capture_product_fail_log_blocked",
            }:
                assert "provider/order capture operation blocked" in result.stdout
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout
                assert "capture_operation_blocked" in manifest["verdict_sources"]
                if name == "capture_product_fail_log_blocked":
                    assert set(manifest["verdict_sources"]) >= {
                        "capture_operation_blocked",
                        "log_assertion_blocked",
                    }
            elif name == "capture_transport_blocked":
                assert "provider/order capture operation blocked" in result.stdout
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout
            elif name == "capture_secret_diagnostic_blocked":
                combined_output = result.stdout + result.stderr
                assert case["capture_error_code"] not in combined_output
                assert case["capture_error_message"] not in combined_output
                assert "provider/order capture operation blocked" in result.stdout
                capture_evidence = (
                    manifest_path.parent / "target-capture.json"
                ).read_text(encoding="utf-8")
                assert case["capture_error_code"] not in capture_evidence
                assert case["capture_error_message"] not in capture_evidence
            elif name == "pass_log_blocked":
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout
                assert manifest["verdict_sources"] == ["log_assertion_blocked"]
                assert target_log["status"] == "blocked"
                assert target_log["exit_code"] == 3
            elif name == "pass_log_failed":
                assert "[MO-03/target] deterministic verdict: FAIL" in result.stdout
                assert manifest["verdict_sources"] == ["log_assertion_failed"]
                assert target_log["status"] == "fail"
                assert target_log["exit_code"] == 1
                assert target_log["match_count"] == 1
            elif name == "authorization_note_count_is_diagnostic":
                assert "[MO-03/target] deterministic verdict: PASS" in result.stdout
            else:
                assert "Comparator emitted malformed or contradictory evidence." in result.stdout
                assert "[MO-03/target] deterministic verdict: BLOCKED" in result.stdout


def test_mo03_evidence_rejects_missing_fail_artifact_and_coherent_rebinding() -> None:
    run_stamp = "20260716T160000Z-30303"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-adversarial-") as tmp:
        evidence_dir = Path(tmp)
        execution = {
            "schema": "woopayments_mo03_execution.v1",
            "status": "fail",
            "store": "ref",
            "run_stamp": run_stamp,
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "authorization_exit_code": 0,
            "authorization_order_id_present": True,
            "pre_state_exit_code": 0,
            "capture_exit_code": 0,
            "capture_classification": "pass",
            "post_state_exit_code": 1,
            "comparison_exit_code": None,
            "log_assertion_exit_code": 0,
        }
        execution["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(execution, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        execution_path = evidence_dir / "ref-execution.json"
        execution_path.write_text(json.dumps(execution), encoding="utf-8")
        manifest = {
            "schema": "woopayments_mo03_manifest.v1",
            "flow": "MO-03-manual-capture-payment-details",
            "run_stamp": run_stamp,
            "run_scope": "partial",
            "store": "ref",
            "status": "fail",
            "exit_code": 1,
            "verdict_sources": ["post_state_failed"],
            "files": {
                "ref-execution.json": {
                    "file_sha256": file_sha256(execution_path),
                    "payload_sha256": execution["payload_sha256"],
                }
            },
        }
        manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest_path = evidence_dir / "ref-manifest.json"
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        incomplete = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "validate-bound-manifest",
                "--manifest",
                str(manifest_path),
                "--store",
                "ref",
                *MO03_LOG_CONTEXT_ARGS,
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--expected-status",
                "fail",
                "--expected-exit-code",
                "1",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert incomplete.returncode == 3


def test_mo03_capture_normalizer_blocks_untrusted_capture_output() -> None:
    run_stamp = "20260716T160000Z-30303"
    cases = (
        ("authentication_error", "Authentication failed.", {}),
        ("rest_no_route", "The capture route is unavailable.", {}),
        ("http_503", "Provider service temporarily unavailable.", {}),
        ("api_error", "An unexpected provider error occurred.", {}),
        ("capture_declined", "HTTP 503 service temporarily unavailable.", {}),
        ("capture_declined", "x" * 241, {}),
        (
            "capture_declined",
            "Capture was declined.",
            {
                "status": "processing",
                "intention_status": "succeeded",
                "provider_status": "succeeded",
            },
        ),
    )

    for error_code, error_message, overrides in cases:
        payload = mo03_capture_payload("ref", status="fail")
        payload.update(
            {"error_code": error_code, "error_message": error_message, **overrides}
        )
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-capture",
                "--store",
                "ref",
                "--order-id",
                "301",
                "--intent-id",
                "pi_ref_details",
                "--charge-id",
                "ch_ref_details",
                "--expected-exit-code",
                "1",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 3, (error_code, result.stdout, result.stderr)
        assert json.loads(result.stdout)["status"] == "blocked"

    malformed = mo03_capture_payload("ref")
    malformed.pop("provider_status")
    malformed_result = subprocess.run(
        [
            "python3",
            str(MO03_EVIDENCE),
            "normalize-capture",
            "--store",
            "ref",
            "--order-id",
            "301",
            "--intent-id",
            "pi_ref_details",
            "--charge-id",
            "ch_ref_details",
            "--expected-exit-code",
            "0",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=json.dumps(malformed),
        text=True,
        capture_output=True,
        check=False,
    )
    assert malformed_result.returncode == 3
    assert json.loads(malformed_result.stdout)["status"] == "blocked"


def test_mo03_capture_binds_http_rejects_extra_fields_and_redacts_diagnostics() -> None:
    run_stamp = "20260716T160000Z-30303"
    secret = "Bearer sk_test_DO_NOT_ARCHIVE customer@example.test"

    cases = []
    server_error = mo03_capture_payload("ref", status="fail")
    server_error.update(
        {
            "error_code": "card_declined",
            "error_message": secret,
            "http_code": 503,
        }
    )
    cases.append(("server_error", server_error, 1))
    extra_field = mo03_capture_payload("ref", status="fail")
    extra_field["unexpected_transport_hint"] = "http_503"
    cases.append(("extra_field", extra_field, 1))
    missing_http = mo03_capture_payload("ref", status="fail")
    missing_http.pop("http_code")
    cases.append(("missing_http", missing_http, 1))
    zero_http_success = mo03_capture_payload("ref")
    zero_http_success["http_code"] = 0
    cases.append(("zero_http_success", zero_http_success, 0))

    for name, payload, expected_exit_code in cases:
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-capture",
                "--store",
                "ref",
                "--order-id",
                "301",
                "--intent-id",
                "pi_ref_details",
                "--charge-id",
                "ch_ref_details",
                "--expected-exit-code",
                str(expected_exit_code),
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 3, (name, result.stdout, result.stderr)
        assert secret not in result.stdout
        evidence = json.loads(result.stdout)
        assert evidence["status"] == "blocked"
        assert "error_message" not in evidence
        if expected_exit_code == 1:
            assert evidence["error_fingerprint"].startswith("sha256:")
        if name == "server_error":
            assert evidence["provider_http_code"] == 503

    product_error = mo03_capture_payload("ref", status="fail")
    product_error["error_message"] = secret
    product_result = subprocess.run(
        [
            "python3",
            str(MO03_EVIDENCE),
            "normalize-capture",
            "--store",
            "ref",
            "--order-id",
            "301",
            "--intent-id",
            "pi_ref_details",
            "--charge-id",
            "ch_ref_details",
            "--expected-exit-code",
            "1",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=json.dumps(product_error),
        text=True,
        capture_output=True,
        check=False,
    )
    assert product_result.returncode == 1, product_result.stdout + product_result.stderr
    assert secret not in product_result.stdout
    product_evidence = json.loads(product_result.stdout)
    assert product_evidence["provider_http_code"] == 402
    assert product_evidence["error_category"] == "product_decline"
    assert "error_message" not in product_evidence

    source = FLOW_CAPTURE_DRIVER.read_text(encoding="utf-8")
    assert "$outcome_data" in source
    assert "'http_code'" in source
    assert "(int) ( $result['http_code'] ?? 0 )" in source


def test_mo03_native_capture_producer_and_normalizer_accept_real_completed_outcome() -> None:
    run_stamp = "20260716T160000Z-30303"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-native-outcome-") as tmp:
        evidence_dir = Path(tmp)
        wrapper = evidence_dir / "native-capture-wrapper.php"
        wrapper.write_text(native_capture_driver_php_source(), encoding="utf-8")

        producer = subprocess.run(
            ["php", str(wrapper), str(FLOW_CAPTURE_DRIVER)],
            cwd=REPO,
            env={**os.environ, "FAKE_OUTCOME_STATUS": "completed"},
            text=True,
            capture_output=True,
            check=False,
        )
        assert producer.returncode == 0, producer.stdout + producer.stderr
        raw = json.loads(producer.stdout)
        assert raw["transport_kind"] == "native_outcome"
        assert raw["provider_status"] == "completed"
        assert raw["http_code"] == 0

        normalized = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-capture",
                "--store",
                "target",
                "--order-id",
                "301",
                "--intent-id",
                "pi_ref_details",
                "--charge-id",
                "ch_ref_details",
                "--expected-exit-code",
                "0",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=producer.stdout,
            text=True,
            capture_output=True,
            check=False,
        )
        assert normalized.returncode == 0, normalized.stdout + normalized.stderr
        evidence = json.loads(normalized.stdout)
        assert evidence["schema"] == "woopayments_mo03_capture_evidence.v3"
        assert evidence["status"] == "pass"
        assert evidence["transport_kind"] == "native_outcome"
        assert evidence["provider_http_code"] == 0

        spec = importlib.util.spec_from_file_location(
            "mo03_capture_transport_validation_for_test", MO03_EVIDENCE
        )
        assert spec and spec.loader
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        rebound = dict(evidence)
        rebound.update(
            {
                "transport_kind": "plugin_http",
                "provider_status": "succeeded",
                "provider_http_code": 200,
            }
        )
        rebound["payload_sha256"] = module.payload_digest(rebound)
        assert (
            module.capture_validation_error(
                rebound,
                run_stamp,
                expected_store="target",
            )
            == "capture evidence has an invalid transport binding"
        )

        for error_code, expected_rc, expected_category in (
            ("api_error", 3, "untrusted"),
            ("capture_declined", 3, "untrusted"),
        ):
            failed_producer = subprocess.run(
                ["php", str(wrapper), str(FLOW_CAPTURE_DRIVER)],
                cwd=REPO,
                env={
                    **os.environ,
                    "FAKE_OUTCOME_STATUS": "failed",
                    "FAKE_ERROR_CODE": error_code,
                    "FAKE_ERROR_MESSAGE": "Capture was not completed.",
                },
                text=True,
                capture_output=True,
                check=False,
            )
            assert failed_producer.returncode == 1
            failed_raw = json.loads(failed_producer.stdout)
            assert failed_raw["transport_kind"] == "native_outcome"
            assert failed_raw["provider_status"] == "failed"
            assert failed_raw["http_code"] == 0
            failed_normalized = subprocess.run(
                [
                    "python3",
                    str(MO03_EVIDENCE),
                    "normalize-capture",
                    "--store",
                    "target",
                    "--order-id",
                    "301",
                    "--intent-id",
                    "pi_ref_details",
                    "--charge-id",
                    "ch_ref_details",
                    "--expected-exit-code",
                    "1",
                    "--run-stamp",
                    run_stamp,
                ],
                cwd=REPO,
                input=failed_producer.stdout,
                text=True,
                capture_output=True,
                check=False,
            )
            assert failed_normalized.returncode == expected_rc
            failed_evidence = json.loads(failed_normalized.stdout)
            assert failed_evidence["error_category"] == expected_category
            assert failed_evidence["status"] == ("fail" if expected_rc == 1 else "blocked")


def test_mo03_capture_redacts_unknown_syntax_valid_provider_code() -> None:
    run_stamp = "20260716T160000Z-30303"
    secret_code = "sk_test_do_not_archive"
    secret_message = "Bearer secret-message-do-not-archive@example.test"
    raw = mo03_capture_payload("ref", status="fail")
    raw.update(
        {
            "transport_kind": "plugin_http",
            "error_code": secret_code,
            "error_message": secret_message,
        }
    )

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-secret-code-") as tmp:
        archive = Path(tmp) / "capture-evidence.json"
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-capture",
                "--store",
                "ref",
                "--order-id",
                "301",
                "--intent-id",
                "pi_ref_details",
                "--charge-id",
                "ch_ref_details",
                "--expected-exit-code",
                "1",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(raw),
            text=True,
            capture_output=True,
            check=False,
        )
        archive.write_text(result.stdout, encoding="utf-8")

        assert result.returncode == 3, result.stdout + result.stderr
        assert secret_code not in result.stdout
        assert secret_message not in result.stdout
        assert secret_code not in archive.read_text(encoding="utf-8")
        assert secret_message not in archive.read_text(encoding="utf-8")
        evidence = json.loads(result.stdout)
        assert evidence["schema"] == "woopayments_mo03_capture_evidence.v3"
        assert evidence["status"] == "blocked"
        assert evidence["error_code"] == "untrusted_provider_error"
        assert evidence["error_fingerprint"].startswith("sha256:")


def test_mo03_real_native_http_503_codec_failure_blocks_without_provenance() -> None:
    run_stamp = "20260716T160000Z-30303"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-native-503-") as tmp:
        root = Path(tmp)
        wrapper = root / "native-transport-exception-codec.php"
        wrapper.write_text(native_transport_exception_codec_php_source(), encoding="utf-8")
        producer = subprocess.run(
            [
                "php",
                str(wrapper),
                str(REPO / "plugins/woocommerce/vendor/autoload.php"),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert producer.returncode == 0, producer.stdout + producer.stderr
        raw = json.loads(producer.stdout)
        assert raw["transport_kind"] == "native_outcome"
        assert raw["provider_status"] == "failed"
        assert raw["error_code"] == "card_declined"
        assert raw["http_code"] == 0

        normalized = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-capture",
                "--store",
                "target",
                "--order-id",
                "301",
                "--intent-id",
                "pi_ref_details",
                "--charge-id",
                "ch_ref_details",
                "--expected-exit-code",
                "1",
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=producer.stdout,
            text=True,
            capture_output=True,
            check=False,
        )
        assert normalized.returncode == 3, normalized.stdout + normalized.stderr
        evidence = json.loads(normalized.stdout)
        assert evidence["status"] == "blocked"
        assert evidence["error_category"] == "untrusted"
        assert evidence["blocker_code"] == "unproven_native_failure"


def test_mo03_state_timing_blocks_and_authorization_note_count_is_diagnostic() -> None:
    run_stamp = "20260716T160000Z-30303"
    run_epoch = calendar.timegm(time.strptime("20260716T160000Z", "%Y%m%dT%H%M%SZ"))

    stale = mo03_state_payload(
        "ref",
        "pre",
        order_created=run_epoch - 3600,
        observed_at=run_epoch,
        row_created=run_epoch - 3600,
    )
    stale_result = subprocess.run(
        [
            "python3",
            str(MO03_EVIDENCE),
            "normalize-state",
            "--store",
            "ref",
            "--phase",
            "pre",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=json.dumps(stale),
        text=True,
        capture_output=True,
        check=False,
    )
    assert stale_result.returncode == 3, stale_result.stdout + stale_result.stderr
    stale_evidence = json.loads(stale_result.stdout)
    assert stale_evidence["status"] == "blocked"
    assert "order creation time does not bind the runner invocation" in stale_evidence[
        "blockers"
    ]

    stale_row = mo03_state_payload(
        "ref",
        "pre",
        order_created=run_epoch,
        observed_at=run_epoch,
        row_created=run_epoch - (9 * 24 * 60 * 60),
    )
    stale_row_result = subprocess.run(
        [
            "python3",
            str(MO03_EVIDENCE),
            "normalize-state",
            "--store",
            "ref",
            "--phase",
            "pre",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=json.dumps(stale_row),
        text=True,
        capture_output=True,
        check=False,
    )
    assert stale_row_result.returncode == 3, (
        stale_row_result.stdout + stale_row_result.stderr
    )
    stale_row_evidence = json.loads(stale_row_result.stdout)
    assert "authorization created timestamp is outside the active window" in (
        stale_row_evidence["blockers"]
    )
    assert "authorization creation time does not bind the seeded order" in (
        stale_row_evidence["blockers"]
    )

    no_authorization_note = mo03_state_payload(
        "ref",
        "pre",
        authorization_note_count=0,
        order_created=run_epoch,
        observed_at=run_epoch,
        row_created=run_epoch,
    )
    note_result = subprocess.run(
        [
            "python3",
            str(MO03_EVIDENCE),
            "normalize-state",
            "--store",
            "ref",
            "--phase",
            "pre",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=json.dumps(no_authorization_note),
        text=True,
        capture_output=True,
        check=False,
    )
    assert note_result.returncode == 0, note_result.stdout + note_result.stderr
    note_evidence = json.loads(note_result.stdout)
    assert note_evidence["status"] == "pass"
    assert note_evidence["notes"]["authorization_count"] == 0


def test_mo03_manifest_requires_bound_log_scan_for_log_verdict() -> None:
    run_stamp = "20260716T160000Z-30303"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-adversarial-") as tmp:
        evidence_dir = Path(tmp)
        fixture = {
            "schema": "woopayments_mo03_fixture.v1",
            "status": "pass",
            "store": "ref",
            "run_stamp": run_stamp,
            "driver_exit_code": 0,
            "order_id": 301,
            "intent_id": "pi_ref_details",
            "charge_id": "ch_ref_details",
            "errors": [],
            "blockers": [],
        }
        fixture["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(fixture, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        fixture_path = evidence_dir / "ref-fixture.json"
        fixture_path.write_text(json.dumps(fixture), encoding="utf-8")
        execution = {
            "schema": "woopayments_mo03_execution.v1",
            "status": "fail",
            "store": "ref",
            "run_stamp": run_stamp,
            "exit_code": 1,
            "verdict_sources": ["log_assertion_failed"],
            "authorization_exit_code": 0,
            "authorization_order_id_present": True,
            "pre_state_exit_code": 0,
            "capture_exit_code": 0,
            "capture_classification": "pass",
            "post_state_exit_code": 0,
            "comparison_exit_code": None,
            "log_assertion_exit_code": 1,
        }
        execution["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(execution, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        execution_path = evidence_dir / "ref-execution.json"
        execution_path.write_text(json.dumps(execution), encoding="utf-8")
        manifest_path = evidence_dir / "ref-manifest.json"
        build = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "manifest",
                "--store",
                "ref",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--output",
                str(manifest_path),
                "--verdict-source",
                "log_assertion_failed",
                "--file",
                str(fixture_path),
                "--file",
                str(execution_path),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert build.returncode == 3, build.stdout + build.stderr

        manifest = {
            "schema": "woopayments_mo03_manifest.v1",
            "flow": "MO-03-manual-capture-payment-details",
            "run_stamp": run_stamp,
            "run_scope": "partial",
            "store": "ref",
            "status": "fail",
            "exit_code": 1,
            "verdict_sources": ["log_assertion_failed"],
            "files": {
                "ref-fixture.json": {
                    "file_sha256": file_sha256(fixture_path),
                    "payload_sha256": fixture["payload_sha256"],
                },
                "ref-execution.json": {
                    "file_sha256": file_sha256(execution_path),
                    "payload_sha256": execution["payload_sha256"],
                },
            },
        }
        manifest["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
        validation = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "validate-bound-manifest",
                "--manifest",
                str(manifest_path),
                "--store",
                "ref",
                *MO03_LOG_CONTEXT_ARGS,
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--expected-status",
                "fail",
                "--expected-exit-code",
                "1",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert validation.returncode == 3, validation.stdout + validation.stderr


def test_mo03_log_scan_normalizer_rejects_unobserved_pass() -> None:
    run_stamp = "20260716T160000Z-30303"

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-empty-log-") as tmp:
        evidence_dir = Path(tmp)
        raw_path = evidence_dir / "raw-log.json"
        output_path = evidence_dir / "ref-log-scan.json"
        raw_path.write_text(
            json.dumps(
                {
                    "schema": "woopayments_debug_log_scan.v1",
                    "store": "ref",
                    "scan": {
                        "status": "pass",
                        "paths": [],
                        "matches": [],
                        "ignored_matches": [],
                        "marker": {},
                    },
                }
            ),
            encoding="utf-8",
        )
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-log-scan",
                "--input",
                str(raw_path),
                "--output",
                str(output_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "0",
                *MO03_LOG_CONTEXT_ARGS,
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 3, result.stdout + result.stderr
        evidence = json.loads(output_path.read_text(encoding="utf-8"))
        assert evidence["status"] == "blocked"
        assert not evidence["scan_observed"]


def test_mo03_log_scan_v5_binds_fresh_anchors_and_redacts_diagnostics() -> None:
    run_stamp = "20260716T160000Z-30303"
    secret = "Authorization: Bearer sk_test_DO_NOT_ARCHIVE customer@example.test"
    safe_match = safe_log_record(line=5, diagnostic=secret)

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-v2-") as tmp:
        evidence_dir = Path(tmp)
        raw_path = evidence_dir / "raw-log.json"
        output_path = evidence_dir / "ref-log-scan.json"
        scan = mo03_ref_log_scan_v5(
            status="fail",
            run_stamp=run_stamp,
            end_line_count=5,
            end_byte_count=160,
            matches=[safe_match],
        )
        raw_path.write_text(
            json.dumps(common_log_evidence_v5(store="ref", scan=scan)), encoding="utf-8"
        )
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-log-scan",
                "--input",
                str(raw_path),
                "--output",
                str(output_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "1",
                *MO03_LOG_CONTEXT_ARGS,
            ],
            cwd=REPO,
            env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 1, result.stdout + result.stderr
        archived = output_path.read_text(encoding="utf-8")
        assert secret not in archived
        evidence = json.loads(archived)
        assert evidence["schema"] == "woopayments_mo03_log_scan.v6"
        assert evidence["marker_created_at"] == "2026-07-16T16:00:00Z"
        assert evidence["observations"] == scan["observations"]
        assert evidence["matches"] == [safe_match]


def test_mo03_log_scan_v5_blocks_stale_truncated_missing_and_downgraded_evidence() -> None:
    run_stamp = "20260716T160000Z-30303"
    cases = {
        "stale_marker": common_log_evidence_v5(
            store="ref",
            scan=mo03_ref_log_scan_v5(
                run_stamp=run_stamp, marker_created_at="2020-01-01T00:00:00Z"
            ),
        ),
        "log_truncated": common_log_evidence_v5(
            store="ref",
            scan=mo03_ref_log_scan_v5(
                run_stamp=run_stamp, start_line_count=9, end_line_count=8
            ),
        ),
        "missing_observation": common_log_evidence_v5(
            store="ref", scan=mo03_ref_log_scan_v5(run_stamp=run_stamp)
        ),
        "malformed_marker": common_log_evidence_v5(
            store="ref",
            scan=mo03_ref_log_scan_v5(
                run_stamp=run_stamp, marker_created_at="not-a-time"
            ),
        ),
        "schema_downgrade": {
            "schema": "woopayments_debug_log_scan.v1",
            "store": "ref",
            "scan": {
                "status": "pass",
                "paths": ["/tmp/fake-debug.log"],
                "matches": [],
                "ignored_matches": [],
                "marker": {
                    "created_at": "2026-07-16T16:00:00Z",
                    "paths": {"/tmp/fake-debug.log": 4},
                },
            },
        },
        "schema_downgrade_v3": {
            "schema": "woopayments_debug_log_scan.v3",
            "store": "ref",
            "scan": anchored_log_scan_v3(),
        },
        "schema_downgrade_v2": {
            "schema": "woopayments_debug_log_scan.v2",
            "store": "ref",
            "scan": log_scan_v2(run_stamp=run_stamp),
        },
        "schema_downgrade_v4": {
            "schema": "woopayments_debug_log_scan.v4",
            "store": "ref",
            "scan": canary_log_scan_v4(run_stamp=run_stamp),
        },
        "schema_downgrade_v5": {
            **common_log_evidence_v5(
                store="ref", scan=mo03_ref_log_scan_v5(run_stamp=run_stamp)
            ),
            "schema": "woopayments_debug_log_scan.v5",
        },
        "raw_diagnostic": {
            "schema": "woopayments_debug_log_scan.v6",
            "store": "ref",
            "scan": {
                **common_log_evidence_v5(
                    store="ref",
                    scan=mo03_ref_log_scan_v5(
                        status="fail",
                        run_stamp=run_stamp,
                        end_line_count=5,
                        end_byte_count=160,
                        matches=[safe_log_record(line=5)],
                    ),
                )["scan"],
                "matches": ["PHP Warning: Authorization: Bearer secret"],
            },
        },
        "unhashable_record": {
            "schema": "woopayments_debug_log_scan.v6",
            "store": "ref",
            "scan": {
                **common_log_evidence_v5(
                    store="ref",
                    scan=mo03_ref_log_scan_v5(
                        status="fail",
                        run_stamp=run_stamp,
                        end_line_count=5,
                        end_byte_count=160,
                        matches=[safe_log_record(line=5)],
                    ),
                )["scan"],
                "matches": [
                    {
                        **safe_log_record(line=5),
                        "path": ["fake-debug.log"],
                    }
                ],
            },
        },
    }
    cases["missing_observation"]["scan"]["observations"] = []

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-invalid-") as tmp:
        evidence_dir = Path(tmp)
        for name, raw in cases.items():
            raw_path = evidence_dir / f"{name}-raw.json"
            output_path = evidence_dir / f"{name}-normalized.json"
            raw_path.write_text(json.dumps(raw), encoding="utf-8")
            result = subprocess.run(
                [
                    "python3",
                    str(MO03_EVIDENCE),
                    "normalize-log-scan",
                    "--input",
                    str(raw_path),
                    "--output",
                    str(output_path),
                    "--store",
                    "ref",
                    "--run-stamp",
                    run_stamp,
                    "--expected-exit-code",
                    "1" if name == "raw_diagnostic" else "0",
                    *MO03_LOG_CONTEXT_ARGS,
                ],
                cwd=REPO,
                env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
                text=True,
                capture_output=True,
                check=False,
            )
            assert result.returncode == 3, (name, result.stdout, result.stderr)
            archived = output_path.read_text(encoding="utf-8")
            assert "Bearer secret" not in archived
            evidence = json.loads(archived)
            assert evidence["status"] == "blocked"
            assert evidence["blocker_code"] in {
                "stale_marker",
                "log_truncated",
                "missing_path_observation",
                "invalid_marker",
                "invalid_run_binding",
                "invalid_scan_evidence",
                "schema_downgrade",
            }


def test_mo03_log_scan_v5_requires_identity_and_prefix_continuity() -> None:
    run_stamp = "20260716T160000Z-30303"
    identity = "sha256:" + hashlib.sha256(b"replacement-log-identity").hexdigest()
    prefix = "sha256:" + hashlib.sha256(b"rewritten-log-prefix").hexdigest()
    cases = {
        "pass": mo03_ref_log_scan_v5(run_stamp=run_stamp),
        "identity_changed": mo03_ref_log_scan_v5(
            run_stamp=run_stamp,
            observed_identity_fingerprint=identity,
        ),
        "prefix_changed": mo03_ref_log_scan_v5(
            run_stamp=run_stamp,
            observed_prefix_fingerprint=prefix,
        ),
    }

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-v3-") as tmp:
        evidence_dir = Path(tmp)
        for name, scan in cases.items():
            raw_path = evidence_dir / f"{name}-raw.json"
            output_path = evidence_dir / f"{name}-normalized.json"
            raw_path.write_text(
                json.dumps(common_log_evidence_v5(store="ref", scan=scan)),
                encoding="utf-8",
            )
            result = subprocess.run(
                [
                    "python3",
                    str(MO03_EVIDENCE),
                    "normalize-log-scan",
                    "--input",
                    str(raw_path),
                    "--output",
                    str(output_path),
                    "--store",
                    "ref",
                    "--run-stamp",
                    run_stamp,
                    "--expected-exit-code",
                    "0",
                    *MO03_LOG_CONTEXT_ARGS,
                ],
                cwd=REPO,
                env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
                text=True,
                capture_output=True,
                check=False,
            )
            expected = 0 if name == "pass" else 3
            assert result.returncode == expected, (name, result.stdout, result.stderr)
            evidence = json.loads(output_path.read_text(encoding="utf-8"))
            assert evidence["schema"] == "woopayments_mo03_log_scan.v6"
            if name == "pass":
                assert evidence["status"] == "pass"
                assert evidence["observations"] == scan["observations"]
            else:
                assert evidence["status"] == "blocked"
                assert evidence["blocker_code"] == f"log_{name}"


def test_mo03_log_scan_v5_requires_exact_origin_and_canary_continuity() -> None:
    run_stamp = "20260716T160000Z-30303"
    scans = {
        "pass": mo03_ref_log_scan_v5(run_stamp=run_stamp),
        "prior_origin": mo03_ref_log_scan_v5(run_stamp="20260716T155000Z-30302"),
        "future_origin": mo03_ref_log_scan_v5(run_stamp="20260716T161000Z-30304"),
        "canary_changed": mo03_ref_log_scan_v5(
            run_stamp=run_stamp,
            observed_canary_fingerprint="sha256:"
            + hashlib.sha256(b"restored-pre-canary-content").hexdigest(),
        ),
    }

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-v4-") as tmp:
        root = Path(tmp)
        for name, scan in scans.items():
            raw_path = root / f"{name}-raw.json"
            output_path = root / f"{name}-normalized.json"
            raw_path.write_text(
                json.dumps(common_log_evidence_v5(store="ref", scan=scan)),
                encoding="utf-8",
            )
            result = subprocess.run(
                [
                    "python3",
                    str(MO03_EVIDENCE),
                    "normalize-log-scan",
                    "--input",
                    str(raw_path),
                    "--output",
                    str(output_path),
                    "--store",
                    "ref",
                    "--run-stamp",
                    run_stamp,
                    "--expected-exit-code",
                    "0",
                    *MO03_LOG_CONTEXT_ARGS,
                ],
                cwd=REPO,
                env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
                text=True,
                capture_output=True,
                check=False,
            )
            expected = 0 if name == "pass" else 3
            assert result.returncode == expected, (name, result.stdout, result.stderr)
            evidence = json.loads(output_path.read_text(encoding="utf-8"))
            assert evidence["schema"] == "woopayments_mo03_log_scan.v6"
            assert evidence["run_stamp"] == run_stamp
            if name == "pass":
                assert evidence["observations"] == scan["observations"]
            else:
                assert evidence["status"] == "blocked"


def test_mo03_log_scan_v5_binds_current_key_origin_and_observer_summary() -> None:
    run_stamp = TEST_RUN_STAMP
    scan = mo03_ref_log_scan_v5(
        run_stamp=run_stamp,
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    raw = common_log_evidence_v5(store="ref", scan=scan)
    with tempfile.TemporaryDirectory(prefix="critical-flows-mo03-log-v5-") as tmp:
        root = Path(tmp)
        input_path = root / "raw.json"
        output_path = root / "typed.json"
        input_path.write_text(json.dumps(raw), encoding="utf-8")
        result = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-log-scan",
                "--input",
                str(input_path),
                "--output",
                str(output_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "0",
                *MO03_LOG_CONTEXT_ARGS,
            ],
            cwd=REPO,
            env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        typed = json.loads(output_path.read_text(encoding="utf-8"))
        assert typed["schema"] == "woopayments_mo03_log_scan.v6"
        assert typed["origin_nonce"] == scan["origin_nonce"]
        assert typed["observer_id"] == scan["observer_id"]
        assert typed["key_fingerprint"] == scan["key_fingerprint"]
        assert typed["origin_binding"] == scan["origin_binding"]
        assert typed["observer_summary"] == raw["scan"]["observer_summary"]

        changed_nonce = json.loads(json.dumps(raw))
        changed_nonce["scan"]["origin_nonce"] = "00000000-0000-4000-8000-000000000999"
        changed_summary = json.loads(json.dumps(raw))
        changed_summary["scan"]["observer_summary"]["record_count"] += 1
        extra_field = json.loads(json.dumps(raw))
        extra_field["scan"]["unexpected"] = True
        prior = common_log_evidence_v5(
            store="ref",
            scan=mo03_ref_log_scan_v5(
                run_stamp="20260716T160000Z-30302",
                marker_created_at="2026-07-16T16:00:00Z",
            ),
        )
        prior["scan"]["run_stamp"] = run_stamp
        prior["scan"]["marker_created_at"] = TEST_MARKER_CREATED_AT
        for name, adversary in {
            "changed_nonce": changed_nonce,
            "changed_summary": changed_summary,
            "extra_field": extra_field,
            "coherently_relabelled": prior,
        }.items():
            input_path.write_text(json.dumps(adversary), encoding="utf-8")
            rejected = subprocess.run(
                [
                    "python3",
                    str(MO03_EVIDENCE),
                    "normalize-log-scan",
                    "--input",
                    str(input_path),
                    "--output",
                    str(output_path),
                    "--store",
                    "ref",
                    "--run-stamp",
                    run_stamp,
                    "--expected-exit-code",
                    "0",
                    *MO03_LOG_CONTEXT_ARGS,
                ],
                cwd=REPO,
                env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY},
                text=True,
                capture_output=True,
                check=False,
            )
            assert rejected.returncode == 3, (name, rejected.stdout, rejected.stderr)
            assert json.loads(output_path.read_text(encoding="utf-8"))["status"] == "blocked"

        input_path.write_text(json.dumps(raw), encoding="utf-8")
        wrong_key = subprocess.run(
            [
                "python3",
                str(MO03_EVIDENCE),
                "normalize-log-scan",
                "--input",
                str(input_path),
                "--output",
                str(output_path),
                "--store",
                "ref",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "0",
                *MO03_LOG_CONTEXT_ARGS,
            ],
            cwd=REPO,
            env={**os.environ, "CRITICAL_FLOWS_RUN_CONTEXT_KEY": "22" * 32},
            text=True,
            capture_output=True,
            check=False,
        )
        assert wrong_key.returncode == 3, wrong_key.stdout + wrong_key.stderr


def test_mo03_php_driver_keeps_bounded_observation_contract() -> None:
    source = MO03_DRIVER.read_text(encoding="utf-8")

    assert "private const STATE_SCHEMA = 'woopayments_mo03_state.v1';" in source
    assert "private const LIST_ROUTE   = '/wc/v3/payments/authorizations';" in source
    assert "private const PAGE_SIZE    = 100;" in source
    assert "private const MAX_PAGES    = 50;" in source
    assert "NativePaymentsRuntimeArbiter::class" in source
    assert "WooPaymentsApiClient::class" in source
    assert "get_payment_intention( $intent_id )" in source
    assert "get_payments_api_client()->get_intent( $intent_id )" in source
    assert "'successfully captured'" in source
    assert "'capture of'" in source and "'failed'" in source
    assert "private const NOTE_LIMIT" in source
    assert "'limit'    => self::NOTE_LIMIT + 1" in source
    assert "count( $notes ) > self::NOTE_LIMIT" in source


def test_sc02_driver_has_exact_modes_bounds_and_secret_safe_provider_projection() -> None:
    source = SC02_DRIVER.read_text(encoding="utf-8")

    assert "woopayments_sc02_state_raw.v1" in source
    assert "preflight" in source and "post" in source
    assert "CRITICAL_FLOWS_RUN_CONTEXT_KEY" in source
    assert "test-lab-beaker-001" in source
    assert "woocommerce_payments" in source
    assert "_intent_id" in source and "_charge_id" in source
    assert "https://api.stripe.com/v1/payment_intents/" in source
    assert "https://api.stripe.com/v1/charges/" in source
    assert "Authorization" in source and "Stripe-Account" in source
    assert "limit_response_size" in source
    assert "client_secret" not in source
    assert "charges?limit=1" not in source
    assert "wc_get_orders" not in source


def test_sc02_shell_owns_one_shot_store_api_and_bound_stage_evidence() -> None:
    source = SC02_FLOW.read_text(encoding="utf-8")

    assert 'HTTP_DRIVER="${SC02_HTTP_DRIVER:-$DIR/sc02-store-api.py}"' in source
    assert 'STATE_DRIVER="${SC02_STATE_DRIVER:-$DIR/class-woopaymentscriticalflowssc02driver.php}"' in source
    assert 'EVIDENCE_TOOL="${SC02_EVIDENCE_TOOL:-$DIR/sc02-evidence.py}"' in source
    assert 'RUN_TOKEN="sc02-$RUN_STAMP-$S"' in source
    assert source.count('"$HTTP_DRIVER"') == 2  # Dependency check plus one invocation.
    assert "validate-http" in source
    assert "evaluate-store" in source
    assert "normalize-log-scan" in source
    assert "comparison_failed" in source and "comparison_blocked" in source
    assert "--stage" in source and "--verdict-source" in source
    assert "wc_get_orders" not in source
    assert "retry" not in source.lower()


def test_sc02_approved_target_container_uses_direct_noise_free_wp_cli() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-approved-target-") as tmp:
        root = Path(tmp)
        fake_docker = root / "docker"
        calls = root / "docker-calls.txt"
        write_executable(
            fake_docker,
            f"""#!/usr/bin/env bash
printf '%s\n' "$*" > {shlex.quote(str(calls))}
printf '%s\n' 'direct-target-output'
""",
        )

        result = subprocess.run(
            ["bash", "-c", f'source {shlex.quote(str(COMMON))}; wp_target option get home'],
            cwd=REPO,
            env={
                **os.environ,
                "PATH": f"{root}:{os.environ['PATH']}",
                "TARGET_WP_COMMAND": "",
                "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "approved-target-cli-1",
            },
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert result.stdout.strip() == "direct-target-output"
        assert calls.read_text(encoding="utf-8").strip() == (
            "exec -i -u www-data approved-target-cli-1 wp option get home"
        )

        unsafe = subprocess.run(
            [
                "bash",
                "-c",
                f'source {shlex.quote(str(COMMON))}; printf "<%s>\\n" "$TARGET_WP_COMMAND"',
            ],
            cwd=REPO,
            env={
                **os.environ,
                "TARGET_WP_COMMAND": "",
                "WOOPAYMENTS_APPROVED_TARGET_CONTAINER": "target;touch-pwned",
            },
            text=True,
            capture_output=True,
            check=False,
        )
        assert unsafe.returncode == 0
        assert unsafe.stdout.strip() == "<>"


def test_mo01_comparator_rejects_swapped_and_malformed_normalized_evidence() -> None:
    run_stamp = "20260716T120000Z-12345"

    def normalize(payload: dict, store: str, phase: str) -> tuple[subprocess.CompletedProcess[str], dict]:
        result = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "normalize",
                "--store",
                store,
                "--phase",
                phase,
                "--run-stamp",
                run_stamp,
            ],
            cwd=REPO,
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            check=False,
        )
        return result, json.loads(result.stdout)

    wrong_owner, wrong_owner_payload = normalize(
        mo01_state_payload("target", "pre", runtime_owner="plugin"),
        "target",
        "pre",
    )
    assert wrong_owner.returncode == 3
    assert wrong_owner_payload["status"] == "blocked"
    assert "runtime owner=plugin want=native" in wrong_owner_payload["blockers"]

    raw_blocker, raw_blocker_payload = normalize(
        mo01_state_payload(
            "target",
            "pre",
            blockers=["Provider state is unavailable."],
        ),
        "target",
        "pre",
    )
    assert raw_blocker.returncode == 3
    assert raw_blocker_payload["status"] == "blocked"
    assert raw_blocker_payload["blockers"] == ["Provider state is unavailable."]

    currency_mismatch, currency_payload = normalize(
        mo01_state_payload("target", "pre", provider_currency="EUR"),
        "target",
        "pre",
    )
    assert currency_mismatch.returncode == 1
    assert "provider intent currency=EUR want=USD" in currency_payload["errors"]
    assert "provider charge currency=EUR want=USD" in currency_payload["errors"]

    duplicate_raw = subprocess.run(
        [
            "python3",
            str(MO01_COMPARATOR),
            "normalize",
            "--store",
            "target",
            "--phase",
            "pre",
            "--run-stamp",
            run_stamp,
        ],
        cwd=REPO,
        input=(
            json.dumps(mo01_state_payload("target", "pre"))
            + "\n"
            + json.dumps(
                mo01_state_payload("target", "pre", intent_status="succeeded")
            )
        ),
        text=True,
        capture_output=True,
        check=False,
    )
    duplicate_payload = json.loads(duplicate_raw.stdout)
    assert duplicate_raw.returncode == 3
    assert duplicate_payload["status"] == "blocked"
    assert duplicate_payload["blockers"] == [
        "State driver must emit exactly one MO-01 payload; observed 2."
    ]

    missing_intent = mo01_state_payload("target", "pre")
    missing_intent["order"]["intent_id"] = ""
    missing_intent["provider"].update(
        {
            "intent_id": "",
            "intent_status": "",
            "intent_amount_minor": 0,
            "intent_currency": "",
            "charge_id": "",
            "charge_amount_minor": 0,
            "charge_amount_captured_minor": 0,
            "charge_captured": False,
            "charge_currency": "",
        }
    )
    missing_intent_result, missing_intent_payload = normalize(
        missing_intent,
        "target",
        "pre",
    )
    assert missing_intent_result.returncode == 1
    assert missing_intent_payload["status"] == "fail"
    assert "order intent id is not provider-backed" in missing_intent_payload["errors"]

    with tempfile.TemporaryDirectory(prefix="critical-flows-mo01-compare-") as tmp:
        root = Path(tmp)
        empty_fail_manifest = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "manifest",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--output",
                str(root / "empty-fail-manifest.json"),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert empty_fail_manifest.returncode == 3
        unbound_operational_fail = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "manifest",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--verdict-source",
                "capture_operation_failed",
                "--run-stamp",
                run_stamp,
                "--run-scope",
                "partial",
                "--output",
                str(root / "unbound-operational-fail.json"),
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert unbound_operational_fail.returncode == 3
        capture_block_mislabeled_fail = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "execution",
                "--store",
                "target",
                "--status",
                "fail",
                "--exit-code",
                "1",
                "--verdict-source",
                "capture_operation_failed",
                "--run-stamp",
                run_stamp,
                "--output",
                str(root / "capture-block-mislabeled-fail.json"),
                "--authorization-exit-code",
                "0",
                "--authorization-order-id-present",
                "true",
                "--pre-state-exit-code",
                "0",
                "--capture-exit-code",
                "3",
                "--capture-classification",
                "blocked",
                "--post-state-exit-code",
                "1",
                "--log-assertion-exit-code",
                "0",
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert capture_block_mislabeled_fail.returncode == 3

        normalized: dict[tuple[str, str], dict] = {}
        paths: dict[tuple[str, str], Path] = {}
        for store in ("ref", "target"):
            for phase in ("pre", "post"):
                result, payload = normalize(mo01_state_payload(store, phase), store, phase)
                assert result.returncode == 0
                assert payload["payload_sha256"].startswith("sha256:")
                normalized[(store, phase)] = payload
                path = root / f"{store}-{phase}.json"
                path.write_text(json.dumps(payload), encoding="utf-8")
                paths[(store, phase)] = path

        def compare(target_pre: Path, target_post: Path) -> tuple[subprocess.CompletedProcess[str], dict]:
            result = subprocess.run(
                [
                    "python3",
                    str(MO01_COMPARATOR),
                    "compare",
                    "--reference-pre",
                    str(paths[("ref", "pre")]),
                    "--reference-post",
                    str(paths[("ref", "post")]),
                    "--target-pre",
                    str(target_pre),
                    "--target-post",
                    str(target_post),
                    "--run-stamp",
                    run_stamp,
                ],
                cwd=REPO,
                text=True,
                capture_output=True,
                check=False,
            )
            return result, json.loads(result.stdout)

        valid, valid_payload = compare(
            paths[("target", "pre")],
            paths[("target", "post")],
        )
        assert valid.returncode == 0
        assert valid_payload["status"] == "pass"
        assert valid_payload["inputs"] == {
            "ref_pre": normalized[("ref", "pre")]["payload_sha256"],
            "ref_post": normalized[("ref", "post")]["payload_sha256"],
            "target_pre": normalized[("target", "pre")]["payload_sha256"],
            "target_post": normalized[("target", "post")]["payload_sha256"],
        }
        valid_unsigned = dict(valid_payload)
        valid_unsigned.pop("payload_sha256")
        assert valid_payload["payload_sha256"] == "sha256:" + hashlib.sha256(
            json.dumps(valid_unsigned, sort_keys=True, separators=(",", ":")).encode(
                "utf-8"
            )
        ).hexdigest()

        forged = json.loads(json.dumps(valid_payload))
        forged["inputs"] = {
            "ref_pre": "sha256:" + "1" * 64,
            "ref_post": "sha256:" + "2" * 64,
            "target_pre": "sha256:" + "3" * 64,
            "target_post": "sha256:" + "4" * 64,
        }
        forged["reference"] = {"pre": {}, "post": {}}
        forged["target"] = {"pre": {}, "post": {}}
        forged_unsigned = dict(forged)
        forged_unsigned.pop("payload_sha256")
        forged["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(forged_unsigned, sort_keys=True, separators=(",", ":")).encode(
                "utf-8"
            )
        ).hexdigest()
        forged_validation = subprocess.run(
            [
                "python3",
                str(MO01_COMPARATOR),
                "validate-comparison",
                "--run-stamp",
                run_stamp,
                "--expected-exit-code",
                "0",
            ],
            cwd=REPO,
            input=json.dumps(forged),
            text=True,
            capture_output=True,
            check=False,
        )
        assert forged_validation.returncode == 3

        swapped, swapped_payload = compare(paths[("ref", "pre")], paths[("ref", "post")])
        assert swapped.returncode == 3
        assert swapped_payload["status"] == "blocked"
        assert any(
            "target_pre" in reason and "store=ref want=target" in reason
            for reason in swapped_payload["blockers"]
        )
        assert any(
            "target_post" in reason and "runtime owner=plugin want=native" in reason
            for reason in swapped_payload["blockers"]
        )

        malformed_path = root / "target-pre-malformed.json"
        malformed_path.write_text(
            json.dumps(
                {
                    "schema": "woopayments_mo01_normalized.v1",
                    "status": "pass",
                    "store": "target",
                    "phase": "pre",
                    "runtime_owner": "native",
                    "run_stamp": run_stamp,
                    "errors": [],
                    "blockers": [],
                }
            ),
            encoding="utf-8",
        )
        malformed, malformed_payload = compare(malformed_path, paths[("target", "post")])
        assert malformed.returncode == 3
        assert malformed_payload["status"] == "blocked"
        assert any("target-pre-malformed.json" in reason for reason in malformed_payload["blockers"])

        invalid_utf8_path = root / "target-pre-invalid-utf8.json"
        invalid_utf8_path.write_bytes(b"\xff\xfe{not utf8}")
        invalid_utf8, invalid_utf8_payload = compare(
            invalid_utf8_path,
            paths[("target", "post")],
        )
        assert invalid_utf8.returncode == 3
        assert invalid_utf8_payload["status"] == "blocked"
        assert any(
            "target-pre-invalid-utf8.json" in reason
            for reason in invalid_utf8_payload["blockers"]
        )

        contradictory_path = root / "target-pre-contradictory.json"
        contradictory = dict(normalized[("target", "pre")])
        contradictory["errors"] = ["Smuggled error."]
        contradictory["blockers"] = ["Smuggled blocker."]
        contradictory_path.write_text(json.dumps(contradictory), encoding="utf-8")
        contradiction, contradiction_payload = compare(
            contradictory_path,
            paths[("target", "post")],
        )
        assert contradiction.returncode == 3
        assert contradiction_payload["status"] == "blocked"
        assert any("status/error/blocker fields are inconsistent" in reason for reason in contradiction_payload["blockers"])

        unexpected_path = root / "target-pre-unexpected.json"
        unexpected = json.loads(json.dumps(normalized[("target", "pre")]))
        unexpected["unexpected"] = "smuggled"
        unexpected["order"]["unexpected_status"] = "failed"
        unexpected["provider"]["unexpected_currency"] = "EUR"
        unsigned = dict(unexpected)
        unsigned.pop("payload_sha256")
        unexpected["payload_sha256"] = "sha256:" + hashlib.sha256(
            json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
        ).hexdigest()
        unexpected_path.write_text(json.dumps(unexpected), encoding="utf-8")
        unexpected_result, unexpected_payload = compare(
            unexpected_path,
            paths[("target", "post")],
        )
        assert unexpected_result.returncode == 3
        assert unexpected_payload["status"] == "blocked"
        assert any("unexpected normalized fields" in reason for reason in unexpected_payload["blockers"])
        assert any("order has unexpected fields" in reason for reason in unexpected_payload["blockers"])
        assert any("provider has unexpected fields" in reason for reason in unexpected_payload["blockers"])


def test_mo01_runner_rejects_an_invalid_deterministic_manifest() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-mo01-invalid-manifest-") as tmp:
        evidence_dir = Path(tmp)
        flows_dir = evidence_dir / "flows"
        flows_dir.mkdir()
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(
            fake_target_wp,
            probe_only_fake_wp_source(
                "native",
                "http://target.fake.test",
                flow_id="MO-01-manual-capture-order",
            ),
        )
        write_executable(
            flows_dir / "MO-01-manual-capture-order.sh",
            """#!/usr/bin/env bash
manifest_dir="$EVIDENCE_DIR/runs/$CRITICAL_FLOWS_RUN_STAMP-$CRITICAL_FLOWS_RUN_SCOPE/MO-01-manual-capture-order"
mkdir -p "$manifest_dir"
printf '%s\n' '{"schema":"woopayments_mo01_manifest.v1","flow":"MO-01-manual-capture-order","run_stamp":"wrong","run_scope":"partial","store":"target","status":"pass","exit_code":0,"verdict_sources":[],"files":{},"payload_sha256":"sha256:0000000000000000000000000000000000000000000000000000000000000000"}' > "$manifest_dir/target-manifest.json"
exit 0
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MO-01",
            evidence_dir=evidence_dir,
            extra_env={
                "FLOWS_DIR": str(flows_dir),
                "TARGET_WP_COMMAND": str(fake_target_wp),
            },
        )

        assert result.returncode == 3
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["summary"] == {
            "blocked": 1,
            "failed": 0,
            "passed": 0,
            "queued_agent_specs": 0,
        }
        row = rollup["results"][0]
        assert row["status"] == "BLOCKED"
        assert row["exit_code"] == 3
        assert "different runner invocation" in row["reason"]
        assert "evidence_path" not in row
        assert "evidence_sha256" not in row


def test_sp01_deterministic_flow_classifies_pass_fail_and_blocked() -> None:
    passing_checks = {
        "exactly_one_woopayments_token": True,
        "visa_4242_token": True,
        "provider_payment_method_id": True,
        "customer_binding": True,
        "setup_intent_succeeded": True,
        "no_orders_created": True,
        "no_charge_created": True,
    }
    cases = (
        (
            "pass",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "pass",
                "checks": passing_checks,
                "fixture": {
                    "user_id": 91,
                    "token_id": 17,
                    "payment_method_id": "pm_unitvisa4242",
                    "setup_intent_id": "seti_unitvisa4242",
                    "wcpay_customer_id": "cus_unitvisa4242",
                },
                "errors": [],
                "blockers": [],
            },
            0,
            "deterministic verdict: PASS",
            0,
        ),
        (
            "fail",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "fail",
                "checks": {**passing_checks, "visa_4242_token": False},
                "fixture": {"user_id": 92, "token_id": 18},
                "errors": ["Saved token last four did not match 4242."],
                "blockers": [],
            },
            1,
            "deterministic verdict: FAIL",
            0,
        ),
        (
            "blocked",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "blocked",
                "checks": {},
                "fixture": {},
                "errors": [],
                "blockers": ["Stripe test key is unavailable."],
            },
            3,
            "deterministic verdict: BLOCKED",
            0,
        ),
        (
            "fail-with-log-probe-blocked",
            {
                "schema": "woopayments_sp01_deterministic.v1",
                "status": "fail",
                "checks": {**passing_checks, "customer_binding": False},
                "fixture": {"user_id": 93, "token_id": 19},
                "errors": ["Provider customer binding did not match."],
                "blockers": [],
            },
            3,
            "deterministic verdict: BLOCKED",
            2,
        ),
    )

    for case_name, payload, expected_rc, expected_verdict, log_probe_exit_code in cases:
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-sp01-{case_name}-") as tmp:
            evidence_dir = Path(tmp)
            fake_wp = evidence_dir / "fake-wp.sh"
            write_executable(
                fake_wp,
                sp01_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    payload,
                    log_probe_exit_code=log_probe_exit_code,
                ),
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "deterministic",
                "--flow",
                "SP-01",
                evidence_dir=evidence_dir,
                extra_env={"TARGET_WP_COMMAND": str(fake_wp)},
            )

            assert result.returncode == expected_rc, result.stdout + result.stderr
            assert "SP-01-add-payment-method-card" in result.stdout
            assert expected_verdict in result.stdout
            assert "run archived ->" in result.stdout

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            expected_status = "pass" if 0 == expected_rc else "blocked" if 3 == expected_rc else "fail"
            assert rollup["status"] == expected_status
            assert strip_recorded_at(rollup) == [
                {
                    "flow": "SP-01-add-payment-method-card",
                    "layer": "deterministic",
                    "store": "target",
                    "status": expected_status.upper(),
                    "exit_code": expected_rc,
                }
            ]


def test_ma09_deterministic_flow_compares_exact_datasets_and_timings() -> None:
    invalid_median = ma09_payload("target")
    invalid_median["timings"]["page_1"]["measured_median_seconds"] = 9.0
    empty_filter_failure = ma09_payload(
        "target",
        status="fail",
        check_overrides={"type_filter_exact": False},
    )
    empty_filter_failure["type_filter_count"] = 0
    cases = (
        (
            "pass",
            ma09_payload("ref"),
            ma09_payload("target", measured_seconds=(0.8, 0.9)),
            0,
            "[MA-09/target] deterministic verdict: PASS",
            0,
            False,
        ),
        (
            "dataset-mismatch",
            ma09_payload("ref"),
            ma09_payload("target", dataset_count=501),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "relative-performance-failure",
            ma09_payload("ref", measured_seconds=(0.5, 0.5)),
            ma09_payload("target", measured_seconds=(1.01, 1.02)),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "pagination-failure",
            ma09_payload("ref"),
            ma09_payload(
                "target",
                status="fail",
                check_overrides={"pagination_exact": False},
            ),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "empty-filter-failure",
            ma09_payload("ref"),
            empty_filter_failure,
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "absolute-performance-failure",
            ma09_payload("ref"),
            ma09_payload("target", measured_seconds=(10.01, 0.9)),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            False,
        ),
        (
            "malformed-median",
            ma09_payload("ref"),
            invalid_median,
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "unseeded",
            ma09_payload("ref"),
            ma09_payload("target", dataset_count=499, status="blocked"),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            0,
            False,
        ),
        (
            "product-failure-with-log-probe-blocked",
            ma09_payload("ref"),
            ma09_payload(
                "target",
                status="fail",
                check_overrides={"date_filter_exact": False},
            ),
            3,
            "[MA-09/target] deterministic verdict: BLOCKED",
            2,
            False,
        ),
        (
            "dirty-log",
            ma09_payload("ref"),
            ma09_payload("target"),
            1,
            "[MA-09/target] deterministic verdict: FAIL",
            0,
            True,
        ),
    )

    for case_name, ref_payload, target_payload, expected_rc, target_verdict, target_log_rc, dirty_log in cases:
        with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma09-{case_name}-") as tmp:
            evidence_dir = Path(tmp)
            fake_ref = evidence_dir / "fake-ref-wp.sh"
            fake_target = evidence_dir / "fake-target-wp.sh"
            fake_driver = evidence_dir / "fake-ma09-driver.php"
            fake_driver.write_text("<?php // Test seam.\n", encoding="utf-8")
            write_executable(
                fake_ref,
                ma09_fake_wp_source("plugin", "http://ref.fake.test", ref_payload),
            )
            write_executable(
                fake_target,
                ma09_fake_wp_source(
                    "native",
                    "http://target.fake.test",
                    target_payload,
                    log_probe_exit_code=target_log_rc,
                    dirty_log=dirty_log,
                ),
            )

            result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MA-09",
                evidence_dir=evidence_dir,
                extra_env={
                    "MA09_STATE_DRIVER": str(fake_driver),
                    "REF_WP_COMMAND": str(fake_ref),
                    "TARGET_WP_COMMAND": str(fake_target),
                },
            )

            assert result.returncode == expected_rc, result.stdout + result.stderr
            assert "MA-09-large-dataset-perf" in result.stdout
            assert target_verdict in result.stdout
            assert result.stdout.count("[MA-09/ref] deterministic verdict:") == 1
            assert result.stdout.count("[MA-09/target] deterministic verdict:") == 1

            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert len(rollup["results"]) == 2
            assert {row["store"] for row in rollup["results"]} == {"ref", "target"}
            if case_name == "pass":
                run_dir = evidence_dir / "runs" / f"{rollup['run_stamp']}-{rollup['scope']}" / "MA-09-large-dataset-perf"
                assert {path.name for path in run_dir.iterdir()} == {"comparison.json", "ref.json", "target.json"}
                ref_result = json.loads((run_dir / "ref.json").read_text(encoding="utf-8"))
                target_result = json.loads((run_dir / "target.json").read_text(encoding="utf-8"))
                comparison = json.loads((run_dir / "comparison.json").read_text(encoding="utf-8"))
                assert comparison["runner_run_stamp"] == rollup["run_stamp"]
                assert comparison["reference_normalized_sha256"] == ref_result["normalized_sha256"]
                assert comparison["target_normalized_sha256"] == target_result["normalized_sha256"]


def test_ma09_php_driver_classifies_registered_endpoint_exception_as_failure() -> None:
    for failure_mode in ("timed", "type_filter", "date_filter"):
        result = run_ma09_php_driver_with_endpoint_exception(failure_mode)

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout.strip().splitlines()[-1])
        assert payload["status"] == "fail"
        assert payload["blockers"] == []
        assert "The internal REST request threw before returning a response." in payload["errors"]
        if failure_mode == "timed":
            assert "Timed page_20 did not produce three complete samples." in payload["errors"]
            assert payload["timings"]["page_20"]["samples_seconds"][1] is None
            assert payload["timings"]["page_20"]["measured_median_seconds"] is None
        elif failure_mode == "type_filter":
            assert payload["type_filter_count"] == 0
            assert payload["checks"]["type_filter_exact"] is False
        else:
            assert payload["date_filter_count"] == 0
            assert payload["checks"]["date_filter_exact"] is False

        with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma09-{failure_mode}-") as tmp:
            evidence_dir = Path(tmp)
            fake_ref = evidence_dir / "fake-ref-wp.sh"
            fake_target = evidence_dir / "fake-target-wp.sh"
            fake_driver = evidence_dir / "fake-ma09-driver.php"
            fake_driver.write_text("<?php // Test seam.\n", encoding="utf-8")
            write_executable(
                fake_ref,
                ma09_fake_wp_source("plugin", "http://ref.fake.test", ma09_payload("ref")),
            )
            write_executable(
                fake_target,
                ma09_fake_wp_source("native", "http://target.fake.test", payload),
            )

            runner_result = run_runner(
                "--store",
                "both",
                "--layer",
                "deterministic",
                "--flow",
                "MA-09",
                evidence_dir=evidence_dir,
                extra_env={
                    "MA09_STATE_DRIVER": str(fake_driver),
                    "REF_WP_COMMAND": str(fake_ref),
                    "TARGET_WP_COMMAND": str(fake_target),
                },
            )

            assert runner_result.returncode == 1, runner_result.stdout + runner_result.stderr
            assert "[MA-09/target] deterministic verdict: FAIL" in runner_result.stdout
            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            target_result = next(row for row in rollup["results"] if row["store"] == "target")
            assert target_result["status"] == "FAIL"


def test_card_checkout_flow_passes_on_reference_with_empty_native_flags() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":456,"charge_id":"ch_ref","intent_id":"pi_ref"}'
""",
        )
        write_executable(
            fake_wp,
            sc01_fake_wp_source(
                "plugin", "http://ref.fake.test", "pi_ref", "ch_ref"
            ),
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0
        assert "captured order_id=456" in result.stdout
        assert "native_flag[@]: unbound variable" not in result.stdout


def test_flow_drive_parses_wp_env_json_before_success_footer() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "${1:-}" = "eval" ]; then
  # flow-drive's native live-money guard probes test mode before driving.
  printf 'WCPAY_NATIVE_TEST_MODE:yes\\n'
  exit 0
fi
cat <<'OUT'
ℹ Starting 'wp eval-file - test-lab-beaker-001 2 pm_card_visa 0 ' on the cli container.
{"order_id":137,"charge_id":"ch_fake","intent_id":"pi_fake","status":"processing"}
✔ Ran `wp eval-file - test-lab-beaker-001 2 pm_card_visa 0 ` in 'cli'. (in 7s 723ms)
OUT
""",
        )
        # flow-drive validates $WP as a local-only runner, so the bare delegate path is
        # wrapped in the same Docker-shaped test transport the merge harness tests use.
        runner, env = adapt_single_wp_runner(str(fake_wp), os.environ.copy())
        env["WP"] = runner

        result = subprocess.run(
            [
                "bash",
                str(FLOW_DRIVE),
                "charge",
                "--deterministic",
                "--native",
                "--sku",
                "test-lab-beaker-001",
                "--quantity",
                "2",
                "--type",
                "success",
            ],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=env,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["op"] == "charge"
        assert payload["order_id"] == 137
        assert payload["charge_id"] == "ch_fake"


def test_flow_drive_rejects_remote_wp_runner_with_exit_2() -> None:
    result = subprocess.run(
        ["bash", str(FLOW_DRIVE), "charge", "--deterministic"],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env={**os.environ, "WP": "wp --ssh=user@remote.example"},
        check=False,
    )

    assert result.returncode == 2
    assert "unsafe WP-CLI command" in result.stderr


def test_common_wp_wrappers_accept_command_strings_with_args() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-command-string-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
printf '%s\\n' "$*"
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
REF_WP_COMMAND={shlex.quote(str(fake_wp) + " --runner-flag")}
wp_ref option get home
"""

        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

        assert result.returncode == 0
        assert result.stdout.strip() == "--runner-flag option get home"


def test_card_checkout_flow_exports_command_string_helper_to_flow_driver() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
set -e
"$WP" option get home >/dev/null
printf '%s\\n' '{"op":"charge","order_id":789,"charge_id":"ch_export","intent_id":"pi_export"}'
""",
        )
        write_executable(
            fake_wp,
            sc01_fake_wp_source(
                "plugin",
                "http://ref.fake.test",
                "pi_export",
                "ch_export",
                command_prefix="--runner-flag",
            ),
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": f"{fake_wp} --runner-flag",
            },
        )

        assert result.returncode == 0
        assert "captured order_id=789" in result.stdout
        assert "run_wp_command_string: command not found" not in result.stdout


def test_card_checkout_flow_blocks_when_exerciser_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' "FLOW-DRIVE FAIL (charge): account is not connected" >&2
exit 1
""",
        )
        write_executable(fake_wp, probe_only_fake_wp_source("native", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3
        assert "SC-01-card-checkout" in result.stdout
        assert "BLOCKED" in result.stdout
        assert "deterministic charge exerciser failed" in result.stdout
        assert "account is not connected" in result.stdout
        assert "EXERCISER NOT WIRED" not in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-01-card-checkout",
                "layer": "deterministic",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
            }
        ]


def test_agent_layer_queued_specs_are_blocked_until_executed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert "[BLOCKED] SC-14-lpm-wave-1-checkout on target" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_critical_flows_rollup.v1"
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
                "reason": "agent spec queued; no result file",
            }
        ]


def test_agent_layer_skips_specs_that_require_no_browser_layer() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 0
        assert "MA-10-i18n-order-notes on target" not in result.stdout
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert rollup["results"] == []


def test_deterministic_layer_runs_no_browser_specs_through_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "i18n-gate-calls.log"

        write_executable(
            fake_gate,
            ma10_fake_gate_source(),
        )
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 0
        assert "MA-10-i18n-order-notes" in result.stdout
        assert "deterministic verdict: PASS" in result.stdout
        calls_lines = calls.read_text(encoding="utf-8").splitlines()
        assert calls_lines[0] == "marker"
        assert calls_lines[1].startswith(f"gate --target {fake_wp} --out-dir ")
        assert calls_lines[2] == "scan"

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        run_dir = evidence_dir / "runs" / f"{rollup['run_stamp']}-{rollup['scope']}"
        manifest = run_dir / "MA-10-i18n-order-notes/manifest.json"
        assert manifest.exists()
        manifest_payload = json.loads(manifest.read_text(encoding="utf-8"))
        assert manifest_payload["schema"] == "woopayments_ma10_evidence_manifest.v2"
        assert manifest_payload["run_stamp"] == rollup["run_stamp"]
        manifest_sha256 = f"sha256:{hashlib.sha256(manifest.read_bytes()).hexdigest()}"
        rows = strip_recorded_at(rollup)
        assert rows == [
            {
                "flow": "MA-10-i18n-order-notes",
                "layer": "deterministic",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "evidence_path": str(manifest),
                "evidence_sha256": manifest_sha256,
                "reason": "validated MA-10 evidence manifest",
            }
        ]

        log_scan_path = run_dir / "MA-10-i18n-order-notes/debug-log-scan.json"
        replayed_log_scan = json.loads(log_scan_path.read_text(encoding="utf-8"))
        replayed_log_scan["schema"] = "woopayments_debug_log_scan.v2"
        replayed_log_scan["scan"]["marker_created_at"] = "2020-01-01T00:00:00Z"
        log_scan_path.write_text(
            json.dumps(replayed_log_scan, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        replayed = subprocess.run(
            [
                "python3",
                str(MA10_VALIDATOR),
                "--evidence-dir",
                str(manifest.parent),
                "--gate-exit",
                "0",
                "--run-stamp",
                rollup["run_stamp"],
                *MA10_LOG_CONTEXT_ARGS,
            ],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert replayed.returncode == 3, replayed.stdout + replayed.stderr
        assert "debug-log scan schema is invalid" in replayed.stdout
        assert not manifest.exists()


def test_ma10_reference_is_blocked_without_manufacturing_an_oracle() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-ref-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-ref-wp.sh"
        gate_calls = evidence_dir / "gate-calls.log"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "eval" ] && [[ "$2" == *"store_identity_owner"* ]]; then
  printf '%s\\n' 'store_identity_owner=plugin'
  printf '%s\\n' 'store_identity_home=http://reference.fake.test'
  exit 0
fi
exit 2
""",
        )

        result = run_runner(
            "--store",
            "ref",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "REF_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(gate_calls),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "reference extension same-note-family oracle is not wired" in result.stdout
        assert not gate_calls.exists()
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert strip_recorded_at(rollup) == [
            {
                "flow": "MA-10-i18n-order-notes",
                "layer": "deterministic",
                "store": "ref",
                "status": "BLOCKED",
                "exit_code": 3,
                "reason": "reference extension same-note-family oracle is not wired",
            }
        ]


def test_ma10_validator_requires_secret_safe_log_scan_v5() -> None:
    spec = importlib.util.spec_from_file_location("ma10_validator_for_test", MA10_VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    run_stamp = "20260716T160000Z-30303"

    legacy = {
        "schema": "woopayments_debug_log_scan.v1",
        "store": "target",
        "scan": {
            "status": "pass",
            "paths": ["/tmp/fake-debug.log"],
            "matches": [],
            "marker": {
                "created_at": "2026-07-16T16:00:00Z",
                "paths": {"/tmp/fake-debug.log": 4},
            },
        },
    }
    try:
        validate_ma10_log_scan_for_test(module, legacy, run_stamp)
    except module.EvidenceError:
        pass
    else:
        raise AssertionError("MA-10 accepted downgraded v1 log evidence")

    current = common_log_evidence_v5(scan=ma10_log_scan_v5(run_stamp=run_stamp))
    assert validate_ma10_log_scan_for_test(module, current, run_stamp) == "pass"

    fatal_match = safe_log_record(
        line=5,
        category="fatal_error",
        diagnostic="PHP Fatal error: deterministic fake failure",
    )
    shared_fail = common_log_evidence_v5(
        scan=ma10_log_scan_v5(
            status="fail",
            run_stamp=run_stamp,
            end_line_count=5,
            end_byte_count=160,
            matches=[fatal_match],
        ),
    )
    assert validate_ma10_log_scan_for_test(module, shared_fail, run_stamp) == "fail"

    shared_blocked = unobserved_common_log_evidence_v5(
        run_stamp=run_stamp, blocker_code="missing_marked_path"
    )
    assert validate_ma10_log_scan_for_test(module, shared_blocked, run_stamp) == "blocked"

    malformed_record = common_log_evidence_v5(
        scan=ma10_log_scan_v5(
            status="fail",
            run_stamp=run_stamp,
            end_line_count=5,
            end_byte_count=160,
            matches=[safe_log_record(line=5)],
        )
    )
    malformed_record["scan"]["matches"][0]["category"] = ["warning"]
    try:
        validate_ma10_log_scan_for_test(module, malformed_record, run_stamp)
    except module.EvidenceError:
        pass
    else:
        raise AssertionError("MA-10 accepted a malformed nested log record")


def test_ma10_validator_requires_run_bound_anchored_log_scan_v5() -> None:
    spec = importlib.util.spec_from_file_location("ma10_validator_v4_anchor_for_test", MA10_VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    run_stamp = "20260716T160000Z-30303"

    current = common_log_evidence_v5(scan=ma10_log_scan_v5(run_stamp=run_stamp))
    assert validate_ma10_log_scan_for_test(module, current, run_stamp) == "pass"

    adversaries = {
        "replayed_v2": {
            "schema": "woopayments_debug_log_scan.v2",
            "store": "target",
            "scan": log_scan_v2(marker_created_at="2020-01-01T00:00:00Z"),
        },
        "replayed_v3": {
            "schema": "woopayments_debug_log_scan.v3",
            "store": "target",
            "scan": anchored_log_scan_v3(),
        },
        "replayed_v4": {
            "schema": "woopayments_debug_log_scan.v4",
            "store": "target",
            "scan": canary_log_scan_v4(run_stamp=run_stamp),
        },
        "replayed_v5": {
            **common_log_evidence_v5(
                scan=ma10_log_scan_v5(run_stamp=run_stamp)
            ),
            "schema": "woopayments_debug_log_scan.v5",
        },
        "stale_v5": common_log_evidence_v5(
            scan=ma10_log_scan_v5(
                run_stamp=run_stamp,
                marker_created_at="2020-01-01T00:00:00Z",
            ),
        ),
        "identity_changed": common_log_evidence_v5(
            scan=ma10_log_scan_v5(
                run_stamp=run_stamp,
                observed_identity_fingerprint="sha256:"
                + hashlib.sha256(b"replacement-log").hexdigest(),
            ),
        ),
    }
    for name, payload in adversaries.items():
        try:
            validate_ma10_log_scan_for_test(module, payload, run_stamp)
        except module.EvidenceError:
            pass
        else:
            raise AssertionError(f"MA-10 accepted {name} log evidence")


def test_ma10_validator_requires_exact_origin_canary_log_scan_v5() -> None:
    spec = importlib.util.spec_from_file_location("ma10_validator_v4_for_test", MA10_VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    run_stamp = "20260716T161000Z-30303"

    current = common_log_evidence_v5(
        scan=ma10_log_scan_v5(
            run_stamp=run_stamp,
            marker_created_at="2026-07-16T16:10:00Z",
        ),
    )
    assert validate_ma10_log_scan_for_test(module, current, run_stamp) == "pass"

    for origin in ("20260716T160000Z-30302", "20260716T162000Z-30304"):
        adjacent = common_log_evidence_v5(
            scan=ma10_log_scan_v5(
                run_stamp=origin,
                marker_created_at="2026-07-16T16:10:00Z",
            ),
        )
        try:
            validate_ma10_log_scan_for_test(module, adjacent, run_stamp)
        except module.EvidenceError as error:
            assert "current invocation" in str(error) or "run stamp" in str(error)
        else:
            raise AssertionError(f"MA-10 accepted adjacent originating run {origin}")


def test_ma10_validator_rejects_coherently_relabelled_prior_log_packet() -> None:
    spec = importlib.util.spec_from_file_location("ma10_validator_relabel_for_test", MA10_VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    current_run_stamp = "20260716T161000Z-30303"
    prior = ma10_log_scan_v5(
        run_stamp="20260716T160000Z-30302",
        marker_created_at="2026-07-16T16:00:00Z",
    )
    prior["run_stamp"] = current_run_stamp
    prior["marker_created_at"] = "2026-07-16T16:10:00Z"
    relabelled = common_log_evidence_v5(scan=prior)

    try:
        validate_ma10_log_scan_for_test(module, relabelled, current_run_stamp)
    except module.EvidenceError as error:
        assert "origin" in str(error) or "binding" in str(error)
    else:
        raise AssertionError("MA-10 accepted a coherently relabelled prior-run log packet")


def test_log_origin_rejects_ref_to_target_relabel_with_unchanged_hmac() -> None:
    spec = importlib.util.spec_from_file_location(
        "ma10_validator_store_relabel_for_test", MA10_VALIDATOR
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    reference = common_log_evidence_v5(
        store="ref",
        scan=common_log_scan_v5(
            store="ref",
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
        ),
    )
    assert run_common_archived_log_validator(reference).returncode == 0
    source_mo03, _ = run_mo03_log_normalizer(
        reference, store="ref", expected_exit_code=0
    )
    assert source_mo03.returncode == 0, source_mo03.stdout + source_mo03.stderr

    relabelled = json.loads(json.dumps(reference))
    relabelled["store"] = "target"
    assert (
        relabelled["scan"]["origin_binding"]
        == reference["scan"]["origin_binding"]
    )
    accepted_by: list[str] = []
    if run_common_archived_log_validator(relabelled).returncode == 0:
        accepted_by.append("common")
    target_mo03, _ = run_mo03_log_normalizer(
        relabelled, store="target", expected_exit_code=0
    )
    if target_mo03.returncode == 0:
        accepted_by.append("MO-03")
    try:
        if validate_ma10_log_scan_for_test(module, relabelled, TEST_RUN_STAMP) == "pass":
            accepted_by.append("MA-10")
    except module.EvidenceError:
        pass
    assert not accepted_by, (
        "unchanged origin HMAC survived ref->target relabel at: "
        + ", ".join(accepted_by)
    )


def test_common_log_origin_rejects_ref_to_target_relabel_with_unchanged_hmac() -> None:
    reference = common_log_evidence_v5(
        store="ref",
        scan=common_log_scan_v5(
            store="ref",
            flow_id="MO-03-manual-capture-payment-details",
            purpose="clean-debug-log",
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
        ),
    )
    source = run_common_archived_log_validator(reference)
    assert source.returncode == 0, source.stdout + source.stderr

    relabelled = json.loads(json.dumps(reference))
    relabelled["store"] = "target"
    assert relabelled["scan"] == reference["scan"]
    rejected = run_common_archived_log_validator(relabelled)
    assert rejected.returncode == 3, rejected.stdout + rejected.stderr
    assert "invalid_run_binding" in rejected.stdout


def test_log_origin_rejects_same_run_cross_flow_and_full_path_relabel() -> None:
    spec = importlib.util.spec_from_file_location(
        "ma10_validator_flow_path_relabel_for_test", MA10_VALIDATOR
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    packet = common_log_evidence_v5(
        store="target",
        scan=common_log_scan_v5(
            run_stamp=TEST_RUN_STAMP,
            marker_created_at=TEST_MARKER_CREATED_AT,
            path="debug.log",
        ),
    )
    observation = packet["scan"]["observations"][0]
    path_state = {
        "line_count": observation["start_line_count"],
        "byte_count": observation["start_byte_count"],
        "identity_fingerprint": observation["marker_identity_fingerprint"],
        "prefix_fingerprint": observation["marker_prefix_fingerprint"],
        "canary_fingerprint": observation["marker_canary_fingerprint"],
        "owner": observation["marker_owner"],
        "group": observation["marker_group"],
        "mode": observation["marker_mode"],
    }
    marker_base = {
        "schema": "woopayments_debug_log_marker.v6",
        "created_at": packet["scan"]["marker_created_at"],
        "run_stamp": packet["scan"]["run_stamp"],
        "store": "target",
        "purpose": "clean-debug-log",
        "origin_nonce": packet["scan"]["origin_nonce"],
        "observer_id": packet["scan"]["observer_id"],
        "key_fingerprint": packet["scan"]["key_fingerprint"],
        "origin_binding": "",
    }
    mo03_path = "/srv/mo03/wp-content/debug.log"
    ma10_path = "/different/ma10/wp-content/debug.log"
    mo03_path_state = {
        **path_state,
        "path_id": "hmac-sha256:"
        + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            b"woopayments_debug_log_path.v1\0" + mo03_path.encode(),
            hashlib.sha256,
        ).hexdigest(),
    }
    ma10_path_state = {
        **path_state,
        "path_id": "hmac-sha256:"
        + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            b"woopayments_debug_log_path.v1\0" + ma10_path.encode(),
            hashlib.sha256,
        ).hexdigest(),
    }
    mo03_marker = {
        **marker_base,
        "flow_id": "MO-03-manual-capture-payment-details",
        "paths": {mo03_path: mo03_path_state},
    }
    ma10_marker = {
        **marker_base,
        "flow_id": "MA-10-i18n-order-notes",
        "paths": {ma10_path: ma10_path_state},
    }
    assert log_observer_origin_material(mo03_marker) != log_observer_origin_material(
        ma10_marker
    ), "flow and keyed canonical full path must change origin material"

    mo03_result, _ = run_mo03_log_normalizer(
        packet, store="target", expected_exit_code=0
    )
    assert mo03_result.returncode == 0, mo03_result.stdout + mo03_result.stderr
    try:
        ma10_status = validate_ma10_log_scan_for_test(module, packet, TEST_RUN_STAMP)
    except module.EvidenceError:
        ma10_status = "blocked"
    assert ma10_status == "blocked", (
        "the same run/key/HMAC and basename were accepted after relabelling "
        "MO-03 to MA-10 and changing the configured full path"
    )


def test_ma10_validator_requires_authenticated_log_scan_v5() -> None:
    spec = importlib.util.spec_from_file_location("ma10_validator_v5_for_test", MA10_VALIDATOR)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    scan = ma10_log_scan_v5(
        run_stamp=TEST_RUN_STAMP,
        marker_created_at=TEST_MARKER_CREATED_AT,
    )
    current = common_log_evidence_v5(scan=scan)
    prior_key = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY")
    os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = TEST_RUN_CONTEXT_KEY
    try:
        assert module.validate_log_scan(current, TEST_RUN_STAMP) == "pass"

        adversaries = []
        changed_nonce = json.loads(json.dumps(current))
        changed_nonce["scan"]["origin_nonce"] = "00000000-0000-4000-8000-000000000999"
        adversaries.append(changed_nonce)
        extra_field = json.loads(json.dumps(current))
        extra_field["scan"]["unexpected"] = True
        adversaries.append(extra_field)
        adversaries.append(
            {
                "schema": "woopayments_debug_log_scan.v4",
                "store": "target",
                "scan": canary_log_scan_v4(run_stamp=TEST_RUN_STAMP),
            }
        )
        for adversary in adversaries:
            try:
                module.validate_log_scan(adversary, TEST_RUN_STAMP)
            except module.EvidenceError:
                pass
            else:
                raise AssertionError("MA-10 accepted mutated/downgraded v5 log evidence")

        os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = "22" * 32
        try:
            module.validate_log_scan(current, TEST_RUN_STAMP)
        except module.EvidenceError:
            pass
        else:
            raise AssertionError("MA-10 accepted log evidence authenticated by another key")
    finally:
        if prior_key is None:
            os.environ.pop("CRITICAL_FLOWS_RUN_CONTEXT_KEY", None)
        else:
            os.environ["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = prior_key


def test_ma10_deterministic_flow_fails_when_debug_log_is_dirty() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-dirty-log-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(log_status="fail"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL log-clean target" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_deterministic_flow_blocks_when_gate_evidence_is_malformed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-malformed-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(fake_gate, ma10_fake_gate_source(malformed=True))
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "BLOCKED: MA-10 gate evidence is invalid" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert strip_recorded_at(rollup)[0]["reason"] == "MA-10 evidence validation did not complete"


def test_ma10_blocks_when_one_required_packet_file_is_missing() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-missing-file-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_REMOVE_FILE": "refund-flow.json",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "required evidence files are missing: refund-flow.json" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout


def test_ma10_blocks_when_result_and_state_files_contradict_each_other() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-contradict-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CONTRADICT_STATE": "1",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "gate result state does not match i18n-notes-state.json" in result.stdout


def test_ma10_blocks_when_completed_log_scan_contains_no_observations() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-empty-log-scan-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(scanned_paths=[]))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "missing_path_observation" in result.stdout


def test_ma10_blocks_when_evidence_validator_crashes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-validator-crash-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        fake_validator = evidence_dir / "fake-validator.py"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())
        fake_validator.write_text(
            """#!/usr/bin/env python3
import pathlib
import sys

evidence_dir = pathlib.Path(sys.argv[sys.argv.index('--evidence-dir') + 1])
(evidence_dir / 'manifest.json').write_text('{\"stale\":true}\\n', encoding='utf-8')
raise RuntimeError('validator crash')
""",
            encoding="utf-8",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "MA10_EVIDENCE_VALIDATOR": str(fake_validator),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "RuntimeError: validator crash" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        row = strip_recorded_at(rollup)[0]
        assert row["reason"] == "MA-10 evidence validation did not complete"
        assert "evidence_path" not in row
        assert "evidence_sha256" not in row


def test_ma10_fixed_runner_verifier_rejects_incomplete_forged_pass_manifest() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-forged-manifest-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        fake_validator = evidence_dir / "fake-validator.py"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())
        fake_validator.write_text(
            """#!/usr/bin/env python3
import hashlib
import json
import pathlib
import sys

evidence_dir = pathlib.Path(sys.argv[sys.argv.index('--evidence-dir') + 1])
gate_exit = int(sys.argv[sys.argv.index('--gate-exit') + 1])
run_stamp = sys.argv[sys.argv.index('--run-stamp') + 1]
charge = evidence_dir / 'charge-flow.json'
manifest = {
    'schema': 'woopayments_ma10_evidence_manifest.v2',
    'run_stamp': run_stamp,
    'gate_exit': gate_exit,
    'gate_status': 'pass',
    'log_status': 'pass',
    'product_errors': [],
    'status': 'pass',
    'files': {
        charge.name: 'sha256:' + hashlib.sha256(charge.read_bytes()).hexdigest(),
    },
}
(evidence_dir / 'manifest.json').write_text(
    json.dumps(manifest, indent=2, sort_keys=True) + '\\n', encoding='utf-8'
)
""",
            encoding="utf-8",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "MA10_EVIDENCE_VALIDATOR": str(fake_validator),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        row = strip_recorded_at(rollup)[0]
        assert row["status"] == "BLOCKED"
        assert row["reason"] == "MA-10 evidence validation did not complete"
        assert "evidence_path" not in row
        assert "validated MA-10 evidence manifest" not in result.stdout


def test_ma10_fixed_verifier_is_non_mutating_and_revalidates_exact_normal_packet() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-fixed-normal-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )
        assert result.returncode == 0, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        packet_dir = Path(strip_recorded_at(rollup)[0]["evidence_path"]).parent
        original_bytes = {
            path.relative_to(packet_dir): path.read_bytes()
            for path in packet_dir.rglob("*")
            if path.is_file()
        }

        verified = run_fixed_ma10_verifier(packet_dir, gate_exit=0)
        assert verified.returncode == 0, verified.stdout + verified.stderr
        assert original_bytes == {
            path.relative_to(packet_dir): path.read_bytes()
            for path in packet_dir.rglob("*")
            if path.is_file()
        }

        for name in ("extra_file", "wrong_digest", "wrong_status", "changed_source"):
            attack_dir = evidence_dir / f"fixed-normal-{name}"
            shutil.copytree(packet_dir, attack_dir)
            manifest_path = attack_dir / "manifest.json"
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            if name == "extra_file":
                manifest["files"]["unexpected.json"] = "sha256:" + "0" * 64
            elif name == "wrong_digest":
                manifest["files"]["charge-flow.json"] = "sha256:" + "0" * 64
            elif name == "wrong_status":
                manifest["status"] = "fail"
            else:
                charge_path = attack_dir / "charge-flow.json"
                charge = json.loads(charge_path.read_text(encoding="utf-8"))
                charge["order_id"] = 999
                charge_path.write_text(json.dumps(charge) + "\n", encoding="utf-8")
                manifest["files"]["charge-flow.json"] = file_sha256(charge_path)
            manifest_path.write_text(
                json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
            )
            before = {
                path.relative_to(attack_dir): path.read_bytes()
                for path in attack_dir.rglob("*")
                if path.is_file()
            }
            rejected = run_fixed_ma10_verifier(attack_dir, gate_exit=0)
            assert rejected.returncode == 3, (name, rejected.stdout, rejected.stderr)
            assert before == {
                path.relative_to(attack_dir): path.read_bytes()
                for path in attack_dir.rglob("*")
                if path.is_file()
            }

        wrong_key = run_fixed_ma10_verifier(
            packet_dir, gate_exit=0, context_key="22" * 32
        )
        assert wrong_key.returncode == 3, wrong_key.stdout + wrong_key.stderr


def test_ma10_fixed_verifier_rejects_forged_pass_summary_with_retained_origin() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-fixed-summary-rewrite-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(log_status="fail"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )
        assert result.returncode == 1, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        packet_dir = Path(strip_recorded_at(rollup)[0]["evidence_path"]).parent
        authentic_failure = run_fixed_ma10_verifier(packet_dir, gate_exit=0)
        assert authentic_failure.returncode == 1, (
            authentic_failure.stdout + authentic_failure.stderr
        )

        log_path = packet_dir / "debug-log-scan.json"
        failing_packet = json.loads(log_path.read_text(encoding="utf-8"))
        retained_origin = failing_packet["scan"]["origin_binding"]
        forged_packet = rewrite_warning_fail_to_pass_log_evidence(failing_packet)
        assert forged_packet["scan"]["origin_binding"] == retained_origin
        log_path.write_text(
            json.dumps(forged_packet, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        manifest_path = packet_dir / "manifest.json"
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["log_status"] = "pass"
        manifest["status"] = "pass"
        manifest["files"]["debug-log-scan.json"] = file_sha256(log_path)
        manifest_path.write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )

        rewritten_pass = run_fixed_ma10_verifier(packet_dir, gate_exit=0)
        assert rewritten_pass.returncode == 3, (
            "the fixed verifier accepted a warning FAIL->PASS rewrite with a "
            "fabricated chain head and retained origin; "
            + rewritten_pass.stdout
            + rewritten_pass.stderr
        )


def test_runner_archives_only_context_key_fingerprint_and_authenticated_chain() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-context-key-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        archived_text = result.stdout + result.stderr
        for path in evidence_dir.rglob("*"):
            if path.is_file():
                archived_text += path.read_text(encoding="utf-8", errors="replace")
        key_fingerprint = "sha256:" + hashlib.sha256(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY)
        ).hexdigest()
        assert TEST_RUN_CONTEXT_KEY not in archived_text
        assert key_fingerprint in archived_text
        assert "hmac-sha256:" in archived_text


def test_ma10_blocks_before_gate_when_current_log_marker_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-marker-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "calls.log"
        write_executable(fake_gate, ma10_fake_gate_source())
        write_executable(fake_wp, ma10_fake_wp_source(marker_ok=False))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_I18N_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == ["marker"]
        assert "log-clean marker" in result.stdout


def test_ma10_fails_when_refund_marker_only_comes_from_unrelated_email_note() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-english-refund-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source("blocked", english_refund=True))
        write_executable(fake_wp, ma10_fake_wp_source())

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL: MA-10 captured English merchant note" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_gate_cleanup_failure_is_blocked_not_product_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-cleanup-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source(exit_code_override=70))
        write_executable(fake_wp, ma10_fake_wp_source(log_status="fail"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: BLOCKED" in result.stdout


def test_ma10_preserves_valid_gate_failure_when_log_scan_is_unavailable() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-fail-log-block-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_wp = evidence_dir / "fake-target-wp.sh"
        write_executable(fake_gate, ma10_fake_gate_source("fail"))
        write_executable(fake_wp, ma10_fake_wp_source(log_status="blocked"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                "I18N_NOTES_GATE": str(fake_gate),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "BLOCKED log-clean check for target" in result.stdout
        assert "[MA-10-i18n-order-notes/target] deterministic verdict: FAIL" in result.stdout


def test_ma10_real_gate_preserves_early_flow_driver_product_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-real-gate-fail-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-target-wp.sh"
        fake_flow = evidence_dir / "fake-flow.sh"
        write_executable(fake_wp, ma10_live_failure_wp_source())
        write_executable(
            fake_flow,
            """#!/usr/bin/env bash
printf '%s\n' '{"op":"charge","result":"fail","reason":"declined"}'
exit 1
""",
        )
        target_runner, adapted_env = adapt_single_wp_runner(
            str(fake_wp), os.environ.copy(), role="target"
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-10",
            evidence_dir=evidence_dir,
            extra_env={
                **adapted_env,
                "I18N_NOTES_GATE": str(REPO / "tools/woopayments-merge/i18n-notes-gate.sh"),
                "I18N_NOTES_FLOW_DRIVE": str(fake_flow),
                "TARGET_WP_COMMAND": target_runner,
            },
        )

        assert result.returncode == 1, result.stdout + result.stderr
        assert "FAIL: charge flow failed." in result.stderr
        assert "FAIL: MA-10 flow driver reported product failure: charge" in result.stdout
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        row = strip_recorded_at(rollup)[0]
        manifest = Path(row["evidence_path"])
        assert row["status"] == "FAIL"
        assert row["evidence_sha256"] == f"sha256:{hashlib.sha256(manifest.read_bytes()).hexdigest()}"
        manifest_payload = json.loads(manifest.read_text(encoding="utf-8"))
        assert manifest_payload["status"] == "fail"
        assert "i18n-flow-failure.json" in manifest_payload["files"]
        assert "i18n-notes-gate.json" not in manifest_payload["files"]
        before = {
            path.relative_to(manifest.parent): path.read_bytes()
            for path in manifest.parent.rglob("*")
            if path.is_file()
        }
        verified = run_fixed_ma10_verifier(manifest.parent, gate_exit=1)
        assert verified.returncode == 1, verified.stdout + verified.stderr
        assert before == {
            path.relative_to(manifest.parent): path.read_bytes()
            for path in manifest.parent.rglob("*")
            if path.is_file()
        }

        manifest_payload["files"]["i18n-notes-gate.json"] = "sha256:" + "0" * 64
        manifest.write_text(
            json.dumps(manifest_payload, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        mutated = manifest.read_bytes()
        rejected = run_fixed_ma10_verifier(manifest.parent, gate_exit=1)
        assert rejected.returncode == 3, rejected.stdout + rejected.stderr
        assert manifest.read_bytes() == mutated


def test_ma10_early_later_flow_failure_requires_successful_prefix_packets() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma10-prefix-") as tmp:
        root = Path(tmp)
        for flow, missing_names in (
            ("refund", "charge-flow.json"),
            ("dispute", "charge-flow.json, refund-flow.json"),
        ):
            evidence_dir = root / flow
            evidence_dir.mkdir()
            failed_payload = {"op": flow, "result": "fail", "reason": "fixture failure"}
            support = {
                f"{flow}-flow.json": failed_payload,
                "i18n-flow-failure.json": {
                    "schema": "woopayments_i18n_flow_failure.v1",
                    "flow": flow,
                    "driver_exit": 1,
                    "payload": failed_payload,
                },
                "i18n-language-snapshot.json": {
                    "schema": "woopayments_i18n_language_snapshot.v1",
                    "success": True,
                    "exists": True,
                    "value": "en_US",
                    "autoload": "auto",
                },
                "i18n-language-restore.json": {
                    "schema": "woopayments_i18n_language_restore.v1",
                    "success": True,
                    "restored_snapshot_exact": True,
                    "errors": [],
                },
                "i18n-probe-cleanup.json": {
                    "schema": "woopayments_i18n_probe_cleanup.v1",
                    "success": True,
                    "errors": [],
                },
                "i18n-catalog-evidence.json": ma10_gate_payload()["state"]["catalog_evidence"],
                "debug-log-scan.json": {
                    "schema": "woopayments_debug_log_scan.v4",
                    "store": "target",
                    "scan": log_scan_v2(),
                },
            }
            for name, payload in support.items():
                (evidence_dir / name).write_text(
                    json.dumps(payload) + "\n", encoding="utf-8"
                )

            result = subprocess.run(
                [
                    "python3",
                    str(MA10_VALIDATOR),
                    "--evidence-dir",
                    str(evidence_dir),
                    "--gate-exit",
                    "1",
                    "--run-stamp",
                    "20260716T160000Z-30303",
                    *MA10_LOG_CONTEXT_ARGS,
                ],
                cwd=REPO,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
            )

            assert result.returncode == 3, result.stdout + result.stderr
            assert f"required early-failure evidence files are missing: {missing_names}" in result.stdout


def test_mc06_forwards_explicit_store_urls_to_rates_gate() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_gate = evidence_dir / "fake-mc-rates-gate.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        calls = evidence_dir / "mc-rates-gate-args.log"
        ref_url = "http://reference.localhost:8082"
        target_url = "http://target.localhost:8889"

        write_executable(
            fake_gate,
            """#!/usr/bin/env bash
printf '%s\\n' "$@" > "$FAKE_MC_RATES_GATE_CALLS"
exit 0
""",
        )
        # Only the target store is in scope, so only the target command must answer
        # the identity probe. The eval PHP arrives as $3 because of the --flag suffix.
        write_executable(
            fake_target_wp,
            """#!/usr/bin/env bash
if [ "$1" = "--flag" ] && [ "$2" = "eval" ] && [[ "$3" == *"store_identity_owner"* ]]; then
  printf '%s\\n' "store_identity_owner=native"
  printf '%s\\n' "store_identity_home=http://target.fake.test"
  exit 0
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MC-06",
            "--ref-url",
            ref_url,
            "--target-url",
            target_url,
            evidence_dir=evidence_dir,
            extra_env={
                "MC_RATES_GATE": str(fake_gate),
                "REF_WP_COMMAND": "fake-ref-wp --flag",
                "TARGET_WP_COMMAND": f"{fake_target_wp} --flag",
                "FAKE_MC_RATES_GATE_CALLS": str(calls),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert calls.read_text(encoding="utf-8").splitlines() == [
            "--ref",
            "fake-ref-wp --flag",
            "--target",
            f"{fake_target_wp} --flag",
            "--ref-url",
            ref_url,
            "--target-url",
            target_url,
            "--currency-from",
            "USD",
            "--currencies-to",
            "GBP,EUR",
            "--out-dir",
            str(evidence_dir / "MC-06-automatic-rates-refresh"),
        ]


def test_mc06_blocks_before_rates_gate_when_an_explicit_url_is_missing() -> None:
    cases = (
        (("--target-url", "http://target.localhost:8889"), "--ref-url"),
        (("--ref-url", "http://reference.localhost:8082"), "--target-url"),
    )

    for provided_args, missing_flag in cases:
        with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
            evidence_dir = Path(tmp)
            fake_gate = evidence_dir / "fake-mc-rates-gate.sh"
            calls = evidence_dir / "mc-rates-gate-invoked"

            write_executable(
                fake_gate,
                """#!/usr/bin/env bash
touch "$FAKE_MC_RATES_GATE_CALLS"
exit 0
""",
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "deterministic",
                "--flow",
                "MC-06",
                *provided_args,
                evidence_dir=evidence_dir,
                extra_env={
                    "MC_RATES_GATE": str(fake_gate),
                    "REF_WP_COMMAND": "fake-ref-wp",
                    "TARGET_WP_COMMAND": "fake-target-wp",
                    "REF_URL": "http://ambient-reference.invalid",
                    "TARGET_URL": "http://ambient-target.invalid",
                    "FAKE_MC_RATES_GATE_CALLS": str(calls),
                },
            )

            assert result.returncode == 3, result.stdout + result.stderr
            assert "BLOCKED" in result.stderr
            assert missing_flag in result.stderr
            assert not calls.exists()


def test_full_layer_blocks_when_agent_specs_are_only_queued() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        fake_wp = evidence_dir / "fake-wp.sh"
        write_executable(fake_wp, probe_only_fake_wp_source("native", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "all",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"TARGET_WP_COMMAND": str(fake_wp)},
        )

        assert result.returncode == 3
        assert "Layer D: running deterministic flow scripts" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1


def test_runner_blocks_before_flows_when_target_runtime_owner_is_wrong() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        driver_invoked = evidence_dir / "flow-driver-invoked"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
touch "$FLOW_DRIVER_INVOKED"
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        # The target store answers the probe as the plugin runtime: verdicts recorded
        # against it would be attributed to the wrong runtime, so the run must block.
        write_executable(fake_wp, probe_only_fake_wp_source("plugin", "http://target.fake.test"))

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FLOW_DRIVER_INVOKED": str(driver_invoked),
            },
        )

        assert result.returncode == 3
        assert "runtime owner is 'plugin', expected 'native'" in result.stderr
        # No flow may execute and no result row may be recorded against the wrong runtime.
        assert not driver_invoked.exists()
        assert "deterministic verdict" not in result.stdout
        results_jsonl = evidence_dir / "rollup-results.jsonl"
        assert not results_jsonl.exists() or results_jsonl.read_text(encoding="utf-8") == ""
        assert not (evidence_dir / "rollup.json").exists()


def test_runner_blocks_when_both_stores_resolve_to_the_same_home() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        driver_invoked = evidence_dir / "flow-driver-invoked"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
touch "$FLOW_DRIVER_INVOKED"
printf '%s\\n' '{"op":"charge","order_id":123,"charge_id":"ch_fake","intent_id":"pi_fake"}'
""",
        )
        # Both stores report the expected owners but the same home URL: the dual-store
        # parity oracle would compare a store against itself, so the run must block.
        write_executable(fake_ref_wp, probe_only_fake_wp_source("plugin", "http://same.fake.test"))
        write_executable(fake_target_wp, probe_only_fake_wp_source("native", "http://same.fake.test"))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "FLOW_DRIVER_INVOKED": str(driver_invoked),
            },
        )

        assert result.returncode == 3
        assert "resolve to the same store" in result.stderr
        assert "http://same.fake.test" in result.stderr
        assert not driver_invoked.exists()
        assert not (evidence_dir / "rollup.json").exists()


def test_full_scope_run_reports_matrix_coverage_and_refuses_green() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"
        fake_i18n_gate = evidence_dir / "fake-i18n-gate.sh"
        fake_mc_gate = evidence_dir / "fake-mc-rates-gate.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":777,"charge_id":"ch_full","intent_id":"pi_full"}'
""",
        )
        write_executable(fake_ref_wp, sc01_fake_wp_source("plugin", "http://ref.fake.test", "pi_full", "ch_full"))
        write_executable(fake_target_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_full", "ch_full"))
        write_executable(fake_i18n_gate, "#!/usr/bin/env bash\nexit 0\n")
        write_executable(fake_mc_gate, "#!/usr/bin/env bash\nexit 0\n")

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "all",
            "--ref-url",
            "http://ref.fake.test",
            "--target-url",
            "http://target.fake.test",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "I18N_NOTES_GATE": str(fake_i18n_gate),
                "MC_RATES_GATE": str(fake_mc_gate),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "scope=full" in result.stdout
        assert "[ref] store identity: owner=plugin home=http://ref.fake.test" in result.stdout
        assert "[target] store identity: owner=native home=http://target.fake.test" in result.stdout
        assert "Matrix coverage:" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["scope"] == "full"
        assert rollup["run_stamp"]
        matrix = rollup["matrix"]
        assert matrix["total"] == 76
        # Coverage milestone (2026-07-14): every matrix row now has a spec, so a
        # full-scope run leaves nothing uncovered — rows without evidence are
        # BLOCKED/queued, which still refuses green below.
        assert matrix["uncovered"] == 0
        assert matrix["covered"] == matrix["total"] - matrix["uncovered"]
        assert len(matrix["uncovered_ids"]) == matrix["uncovered"]
        # A full-scope run may not claim the suite green while rows lack evidence.
        assert rollup["status"] != "pass"
        assert rollup["summary"]["blocked"] > 0


def test_full_scope_run_refuses_green_when_matrix_rows_are_unspecced() -> None:
    # The uncovered->refuse path must stay pinned in ISOLATION now that the real
    # matrix is fully spec'd: an otherwise-green full-scope run (a minimal flows dir
    # whose single flow passes on both stores — zero failed, zero blocked) must
    # still land blocked/exit 3 purely because a matrix row has no spec at all.
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flows_dir = evidence_dir / "flows"
        flows_dir.mkdir()
        shutil.copy2(RUNNER.parent / "flows/SC-01-card-checkout.sh", flows_dir / "SC-01-card-checkout.sh")
        shutil.copy2(LOG_OBSERVER_DRIVER, flows_dir / LOG_OBSERVER_DRIVER.name)
        # The flow sources ../lib/common.sh relative to its own location.
        (evidence_dir / "lib").mkdir()
        shutil.copy2(RUNNER.parent / "lib/common.sh", evidence_dir / "lib/common.sh")
        matrix_tsv = evidence_dir / "matrix.tsv"
        matrix_tsv.write_text(
            "id\ttitle\tlayers\toracle\tstatus\n"
            "SC-01\tCard checkout, shortcode (new card)\tD+A\tcomparable\tPENDING\n"
            "ZZ-99\tSynthetic uncovered row\tD+A\tcomparable\tPENDING\n",
            encoding="utf-8",
        )
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_ref_wp = evidence_dir / "fake-ref-wp.sh"
        fake_target_wp = evidence_dir / "fake-target-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":778,"charge_id":"ch_syn","intent_id":"pi_syn"}'
""",
        )
        write_executable(fake_ref_wp, sc01_fake_wp_source("plugin", "http://ref.fake.test", "pi_syn", "ch_syn"))
        write_executable(fake_target_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_syn", "ch_syn"))

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "all",
            "--ref-url",
            "http://ref.fake.test",
            "--target-url",
            "http://target.fake.test",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "REF_WP_COMMAND": str(fake_ref_wp),
                    "TARGET_WP_COMMAND": str(fake_target_wp),
                    "MATRIX_TSV": str(matrix_tsv),
                    "FLOWS_DIR": str(flows_dir),
                },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        # Otherwise green: the only flow passed on both stores, nothing failed or blocked.
        assert rollup["summary"]["passed"] == 2, result.stdout + result.stderr
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        matrix = rollup["matrix"]
        assert matrix["total"] == 2
        assert matrix["uncovered_ids"] == ["ZZ-99"]
        # The uncovered row ALONE forces the refusal.
        assert rollup["status"] == "blocked"


def test_partial_run_rollup_is_marked_partial_and_keeps_status_semantics() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":555,"charge_id":"ch_partial","intent_id":"pi_partial"}'
""",
        )
        write_executable(fake_wp, sc01_fake_wp_source("native", "http://target.fake.test", "pi_partial", "ch_partial"))

        result = run_runner(
            "--flow",
            "SC-01",
            "--store",
            "target",
            "--layer",
            "deterministic",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert "scope=partial" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["scope"] == "partial"
        # Partial runs keep the per-run status semantics: uncovered matrix rows do not
        # force a partial run to "blocked" — only a full-scope run refuses green.
        assert rollup["matrix"]["uncovered"] > 0
        assert rollup["status"] == "pass"


def test_consecutive_runs_are_archived_append_only() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        runs_dir = evidence_dir / "runs"

        first = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"CRITICAL_FLOWS_RUN_STAMP": "20260716T160000Z-30303"},
        )
        assert first.returncode == 3
        assert "run archived ->" in first.stdout

        first_dirs = sorted(runs_dir.iterdir())
        assert len(first_dirs) == 1
        first_run_dir = first_dirs[0]
        assert first_run_dir.name.endswith("-partial")
        first_rollup_bytes = (first_run_dir / "rollup.json").read_bytes()
        assert (first_run_dir / "rollup-results.jsonl").exists()
        assert (first_run_dir / "agent-queue.txt").exists()

        # The archive stamp has one-second resolution; make sure the second run
        # lands in a distinct stamp instead of silently reusing the first one.
        time.sleep(1.1)

        second = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"CRITICAL_FLOWS_RUN_STAMP": "20260716T160001Z-30304"},
        )
        assert second.returncode == 3

        run_dirs = sorted(runs_dir.iterdir())
        assert len(run_dirs) == 2
        assert (first_run_dir / "rollup.json").read_bytes() == first_rollup_bytes


def test_runner_creates_missing_evidence_directory() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp) / "nested" / "evidence"

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert (evidence_dir / "rollup.json").exists()
        assert (evidence_dir / "agent-queue.txt").exists()


def test_agent_layer_accepts_completed_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "PASS",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 0
        assert "agent result accepted" in result.stdout
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent result accepted: PASS",
            }
        ]


def test_agent_layer_preserves_target_only_pass_without_requeueing() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result_payload(
            agent_results_dir,
            "SS-10-sepa-token-renewal-cutover",
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "oracle_mode": "target-only",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "BLOCKED",
                        "end_state": "not run - no WooPayments 10.8 reference equivalent",
                        "ux_observations": [],
                        "visual_diffs": [],
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                        "end_state": "SEPA token remained visible and renewed",
                        "ux_observations": ["The saved token remained discoverable."],
                        "visual_diffs": [],
                    },
                ],
                "parity_verdict": "BLOCKED",
                "regression_note": "Target-only continuity passed; parity is not comparable.",
            },
        )

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "agent",
            "--flow",
            "SS-10",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "queued 0 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["summary"] == {
            "passed": 1,
            "failed": 0,
            "blocked": 1,
            "queued_agent_specs": 0,
        }
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "layer": "agent",
                "store": "ref",
                "status": "BLOCKED",
                "exit_code": 3,
                "agent_verdict": "BLOCKED",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "target-only reference is intentionally not comparable",
            },
            {
                "flow": "SS-10-sepa-token-renewal-cutover",
                "layer": "agent",
                "store": "target",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "target-only agent result accepted: PASS; parity not comparable",
            },
        ]


def test_agent_layer_requeues_invalid_target_only_contracts() -> None:
    cases = {
        "wrong oracle mode": ("comparable", "BLOCKED"),
        "fabricated parity pass": ("target-only", "PASS"),
    }

    for case, (oracle_mode, parity_verdict) in cases.items():
        with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
            evidence_dir = Path(tmp)
            agent_results_dir = evidence_dir / "agent-results"
            write_agent_result_payload(
                agent_results_dir,
                "SS-10-sepa-token-renewal-cutover",
                {
                    "flow": "SS-10-sepa-token-renewal-cutover",
                    "oracle_mode": oracle_mode,
                    "store_results": [
                        {
                            "store": "ref",
                            "verdict": "BLOCKED",
                            "end_state": "not run - no reference equivalent",
                            "ux_observations": [],
                            "visual_diffs": [],
                        },
                        {
                            "store": "target",
                            "verdict": "PASS",
                            "end_state": "SEPA continuity passed",
                            "ux_observations": [],
                            "visual_diffs": [],
                        },
                    ],
                    "parity_verdict": parity_verdict,
                    "regression_note": case,
                },
            )

            result = run_runner(
                "--store",
                "target",
                "--layer",
                "agent",
                "--flow",
                "SS-10",
                evidence_dir=evidence_dir,
                extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
            )

            assert result.returncode == 3, case
            assert "queued 1 agent-driven flow specs" in result.stdout, case
            rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
            assert rollup["summary"]["blocked"] == 1, case
            assert rollup["summary"]["passed"] == 0, case


def test_agent_layer_fails_on_functional_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "FAIL - functional",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 1
        assert "agent verdict: FAIL - functional" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "FAIL",
                "exit_code": 1,
                "agent_verdict": "FAIL - functional",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent verdict: FAIL - functional",
            }
        ]


def test_agent_layer_preserves_blocked_agent_result_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            "target",
            "BLOCKED - redirect provider unavailable",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "agent verdict: BLOCKED - redirect provider unavailable" in result.stdout
        assert "unknown agent verdict" not in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["passed"] == 0
        assert rollup["summary"]["failed"] == 0
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "BLOCKED",
                "exit_code": 3,
                "agent_verdict": "BLOCKED - redirect provider unavailable",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent verdict: BLOCKED - redirect provider unavailable",
            }
        ]


def test_agent_layer_fails_target_when_parity_verdict_fails() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result_payload(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "PASS",
                        "end_state": "order paid",
                        "ux_observations": [],
                        "visual_diffs": [],
                        "evidence_paths": ["evidence/SC-14-lpm-wave-1-checkout/ref.png"],
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                        "end_state": "order paid",
                        "ux_observations": ["target missing the reference affordance"],
                        "visual_diffs": [],
                        "evidence_paths": ["evidence/SC-14-lpm-wave-1-checkout/target.png"],
                    },
                ],
                "parity_verdict": "FAIL - UX",
                "regression_note": "Target payment method is completable but not discoverable.",
            },
        )

        result = run_runner(
            "--store",
            "both",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 1
        assert "agent parity verdict: FAIL - UX" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["summary"]["passed"] == 1
        assert rollup["summary"]["failed"] == 1
        assert rollup["summary"]["blocked"] == 0
        assert rollup["summary"]["queued_agent_specs"] == 0
        assert strip_recorded_at(rollup) == [
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "ref",
                "status": "PASS",
                "exit_code": 0,
                "agent_verdict": "PASS",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent result accepted: PASS",
            },
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "layer": "agent",
                "store": "target",
                "status": "FAIL",
                "exit_code": 1,
                "agent_verdict": "FAIL - UX",
                "evidence_path": str(result_path),
                "evidence_sha256": file_sha256(result_path),
                "reason": "agent parity verdict: FAIL - UX",
            },
        ]


def test_agent_layer_blocks_when_result_lacks_requested_store() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        write_agent_result_payload(
            agent_results_dir,
            "SC-14-lpm-wave-1-checkout",
            {
                "flow": "SC-14-lpm-wave-1-checkout",
                "store_results": [{"store": "ref", "verdict": "PASS", "evidence_paths": []}],
                "parity_verdict": "PASS",
                "regression_note": "missing target",
            },
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "queued 1 agent-driven flow specs" in result.stdout
        assert "evidence_store_set_mismatch" in result.stdout

        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["summary"]["queued_agent_specs"] == 1
        assert rollup["summary"]["blocked"] == 1
        assert rollup["summary"]["failed"] == 0


def test_agent_layer_blocks_result_when_context_is_not_supplied() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        write_agent_result(agent_results_dir, "SC-14-lpm-wave-1-checkout", "target", "PASS")

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
            with_context=False,
        )

        assert result.returncode == 3
        assert "evidence_context_missing" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout


def test_agent_layer_blocks_result_when_hashed_artifact_changes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        agent_results_dir = evidence_dir / "agent-results"
        result_path = write_agent_result(agent_results_dir, "SC-14-lpm-wave-1-checkout", "target", "PASS")
        payload = json.loads(result_path.read_text(encoding="utf-8"))
        artifact_path = Path(payload["store_results"][0]["evidence"][0]["path"])
        artifact_path.write_bytes(b"changed after synthesis")

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "agent",
            "--flow",
            "SC-14",
            evidence_dir=evidence_dir,
            extra_env={"AGENT_RESULTS_DIR": str(agent_results_dir)},
        )

        assert result.returncode == 3
        assert "evidence_artifact_mismatch" in result.stdout
        assert "queued 1 agent-driven flow specs" in result.stdout


def run_log_clean_assertion(
    fake_wp_source: str,
    *,
    run_stamp: str = "20260716T160000Z-30303",
    evidence_path: Path | None = None,
    context_key: str = TEST_RUN_CONTEXT_KEY,
    origin_binding: str | None = None,
    observer_categories: tuple[str, ...] = (),
    observer_id: str = "00000000-0000-4000-8000-000000000777",
    observer_path: str = "fake-debug.log",
    observer_terminal_line: int | None = None,
    observer_scan: dict | None = None,
) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-clean-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(
            fake_wp,
            fake_authenticated_log_observer_source() + "\n" + fake_wp_source,
        )
        if origin_binding is None:
            origin_binding = common_log_scan_v5()["origin_binding"]
        context_scan = common_log_scan_v5() if observer_scan is None else observer_scan
        context_observation = context_scan["observations"][0]
        script = f"""
source {shlex.quote(str(COMMON))}
TARGET_WP_COMMAND={shlex.quote(str(fake_wp))}
RUN_STAMP={shlex.quote(run_stamp)}
CRITICAL_FLOWS_RUN_STAMP={shlex.quote(run_stamp)}
CRITICAL_FLOWS_RUN_CONTEXT_KEY={shlex.quote(context_key)}
CRITICAL_FLOWS_FLOW_ID=MO-03-manual-capture-payment-details
CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log
CRITICAL_FLOWS_RUN_CONTEXT_BINDING={shlex.quote(origin_binding)}
FAKE_OBSERVER_CATEGORIES={shlex.quote(','.join(observer_categories))}
FAKE_OBSERVER_ID={shlex.quote(observer_id)}
FAKE_OBSERVER_PATH={shlex.quote(observer_path)}
FAKE_OBSERVER_PATH_ID={shlex.quote(context_observation['path_id'])}
FAKE_OBSERVER_STORE={shlex.quote(context_scan['store'])}
FAKE_OBSERVER_FLOW_ID={shlex.quote(context_scan['flow_id'])}
FAKE_OBSERVER_PURPOSE={shlex.quote(context_scan['purpose'])}
FAKE_OBSERVER_MARKER_CREATED_AT={shlex.quote(context_scan['marker_created_at'])}
FAKE_OBSERVER_TERMINAL_LINE={shlex.quote(str(observer_terminal_line if observer_terminal_line is not None else (5 if observer_categories else 4)))}
LOG_SCAN_EVIDENCE_FILE={shlex.quote(str(evidence_path) if evidence_path else '')}
TMPDIR={shlex.quote(tmp)}
EVIDENCE_DIR={shlex.quote(tmp)}
export CRITICAL_FLOWS_RUN_STAMP CRITICAL_FLOWS_RUN_CONTEXT_KEY CRITICAL_FLOWS_FLOW_ID
export CRITICAL_FLOWS_LOG_PURPOSE
export CRITICAL_FLOWS_RUN_CONTEXT_BINDING FAKE_OBSERVER_CATEGORIES FAKE_OBSERVER_ID
export FAKE_OBSERVER_PATH FAKE_OBSERVER_PATH_ID FAKE_OBSERVER_TERMINAL_LINE
export FAKE_OBSERVER_STORE FAKE_OBSERVER_FLOW_ID FAKE_OBSERVER_PURPOSE
export FAKE_OBSERVER_MARKER_CREATED_AT
export TMPDIR EVIDENCE_DIR
critical_flows_log_observer_start target || exit 3
assert_log_clean target
"""

        return subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


def run_log_clean_marker(
    fake_wp_source: str,
    *,
    run_stamp: str = "20260716T160000Z-30303",
) -> subprocess.CompletedProcess[str]:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-marker-") as tmp:
        fake_wp = Path(tmp) / "fake-wp.sh"
        write_executable(fake_wp, fake_wp_source)
        script = f"""
source {shlex.quote(str(COMMON))}
TARGET_WP_COMMAND={shlex.quote(str(fake_wp))}
export CRITICAL_FLOWS_RUN_STAMP={shlex.quote(run_stamp)}
mark_log_clean_start target
"""
        return subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )


def test_log_clean_assertion_passes_when_scan_is_clean() -> None:
    payload = json.dumps(common_log_scan_v5(), separators=(",", ":"))
    result = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{payload}'
"""
    )

    assert result.returncode == 0
    assert "PASS log-clean target" in result.stdout


def test_log_clean_assertion_fails_when_php_errors_are_found() -> None:
    match = safe_log_record(line=5)
    payload = json.dumps(
        common_log_scan_v5(
            status="fail",
            end_line_count=5,
            end_byte_count=160,
            matches=[match],
        ),
        separators=(",", ":"),
    )
    result = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{payload}'
""",
        observer_categories=("warning",),
    )

    assert result.returncode == 1
    assert "FAIL log-clean target" in result.stdout
    assert match["fingerprint"] in result.stdout
    assert "PHP Warning: deterministic fake warning" not in result.stdout


def test_log_clean_assertion_blocks_when_scan_cannot_run() -> None:
    result = run_log_clean_assertion(
        """#!/usr/bin/env bash
printf '%s\\n' "wp unavailable" >&2
exit 2
"""
    )

    assert result.returncode == 3
    assert "BLOCKED log-clean check for target" in result.stdout
    assert "wp unavailable" not in result.stdout


def test_common_log_producer_blocks_replacement_and_truncate_regrow() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-anchor-") as tmp:
        evidence_dir = Path(tmp)
        wrapper = evidence_dir / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")

        for mutation, blocker_code in (
            ("same_count_replacement", "log_identity_changed"),
            ("truncate_regrow", "log_prefix_changed"),
        ):
            case_dir = evidence_dir / mutation
            case_dir.mkdir()
            debug_log = case_dir / "debug.log"
            debug_log.write_text("one\ntwo\nthree\nfour\n", encoding="utf-8")
            result = subprocess.run(
                ["php", str(wrapper), str(debug_log), mutation],
                cwd=REPO,
                env={
                    **os.environ,
                    "CRITICAL_FLOWS_RUN_STAMP": "20260716T160000Z-30303",
                },
                text=True,
                capture_output=True,
                check=False,
            )
            assert result.returncode == 0, (mutation, result.stdout, result.stderr)
            payload = json.loads(result.stdout)
            assert payload["status"] == "blocked", (mutation, payload)
            assert payload["blocker_code"] == blocker_code
            assert payload["observations"]
            observation = payload["observations"][0]
            assert observation["marker_identity_fingerprint"].startswith("sha256:")
            assert observation["observed_identity_fingerprint"].startswith("sha256:")
            assert observation["marker_prefix_fingerprint"].startswith("sha256:")
            assert observation["observed_prefix_fingerprint"].startswith("sha256:")


def test_common_log_producer_canary_blocks_exact_restore_but_allows_append() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-canary-") as tmp:
        root = Path(tmp)
        wrapper = root / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")
        environment = {
            **os.environ,
            "CRITICAL_FLOWS_RUN_STAMP": TEST_RUN_STAMP,
        }

        restored_dir = root / "restored"
        restored_dir.mkdir()
        restored_log = restored_dir / "debug.log"
        restored_log.write_text("one\ntwo\nthree\nfour\n", encoding="utf-8")
        restored = subprocess.run(
            ["php", str(wrapper), str(restored_log), "exact_content_restore"],
            cwd=REPO,
            env=environment,
            text=True,
            capture_output=True,
            check=False,
        )
        assert restored.returncode == 0, restored.stdout + restored.stderr
        restored_payload = json.loads(restored.stdout)
        assert restored_payload["status"] == "blocked"
        assert restored_payload["blocker_code"] in {
            "log_truncated",
            "log_prefix_changed",
            "log_canary_changed",
        }

        append_dir = root / "append"
        append_dir.mkdir()
        append_log = append_dir / "debug.log"
        append_log.write_text("one\ntwo\nthree\nfour\n", encoding="utf-8")
        appended = subprocess.run(
            ["php", str(wrapper), str(append_log), "ordinary_append"],
            cwd=REPO,
            env=environment,
            text=True,
            capture_output=True,
            check=False,
        )
        assert appended.returncode == 0, appended.stdout + appended.stderr
        appended_payload = json.loads(appended.stdout)
        assert appended_payload["status"] == "pass"
        observation = appended_payload["observations"][0]
        assert observation["start_line_count"] == 5
        assert observation["end_line_count"] == 6
        assert observation["marker_canary_fingerprint"].startswith("sha256:")
        assert (
            observation["marker_canary_fingerprint"]
            == observation["observed_canary_fingerprint"]
        )


def run_common_post_canary_restore_attack(mutation: str) -> dict:
    """Run one terminal-snapshot attack against the exact current common producer."""
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-history-") as tmp:
        root = Path(tmp)
        wrapper = root / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")
        environment = {
            **os.environ,
            "CRITICAL_FLOWS_RUN_STAMP": TEST_RUN_STAMP,
        }

        debug_log = root / "debug.log"
        debug_log.write_text("one\ntwo\nthree\nfour\n", encoding="utf-8")
        result = subprocess.run(
            ["php", str(wrapper), str(debug_log), mutation],
            cwd=REPO,
            env=environment,
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert "erased after" not in result.stdout
        assert "erased before" not in result.stdout
        return json.loads(result.stdout)


def assert_common_log_history_packet_blocks(payload: dict) -> None:
    assert payload["status"] == "pass", payload
    encoded = json.dumps(payload, separators=(",", ":"))
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-history-evidence-") as tmp:
        evidence_path = Path(tmp) / "debug-log-scan.json"
        result = run_log_clean_assertion(
            f"""#!/usr/bin/env bash
printf '%s\\n' '{encoded}'
""",
            evidence_path=evidence_path,
            run_stamp=payload["run_stamp"],
            origin_binding=payload["origin_binding"],
            observer_categories=("warning",),
            observer_id=payload["observer_id"],
            observer_path=payload["observations"][0]["path"],
            observer_terminal_line=payload["observations"][0]["end_line_count"],
            observer_scan=payload,
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert "log_history_changed" in result.stdout
        evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        assert evidence["schema"] == "woopayments_debug_log_scan.v6"
        assert evidence["scan"]["blocker_code"] == "log_history_changed"
        summary = evidence["scan"]["observer_summary"]
        assert summary["origin_binding"] == payload["origin_binding"]
        assert summary["category_counts"]["warning"] == 1
        assert summary["chain_head"].startswith("hmac-sha256:")
        assert TEST_RUN_CONTEXT_KEY not in evidence_path.read_text(encoding="utf-8")


def test_common_log_history_blocks_post_canary_snapshot_restore() -> None:
    assert_common_log_history_packet_blocks(
        run_common_post_canary_restore_attack("post_canary_snapshot_restore")
    )


def test_common_log_history_blocks_restore_followed_by_safe_append() -> None:
    assert_common_log_history_packet_blocks(
        run_common_post_canary_restore_attack("post_canary_restore_safe_append")
    )


def test_common_log_prefix_hash_binds_exact_crlf_bytes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-crlf-prefix-") as tmp:
        root = Path(tmp)
        wrapper = root / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")
        debug_log = root / "debug.log"
        debug_log.write_bytes(b"one\r\ntwo\r\nthree\r\nfour\r\n")
        result = subprocess.run(
            ["php", str(wrapper), str(debug_log), "crlf_to_lf_rewrite"],
            cwd=REPO,
            env={
                **os.environ,
                "CRITICAL_FLOWS_RUN_STAMP": "20260716T160000Z-30303",
            },
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["status"] == "blocked"
        assert payload["blocker_code"] == "log_prefix_changed"


def test_common_log_marker_v6_binds_exact_bytes_and_current_key() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-marker-v6-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        marker_wrapper = root / "common-log-marker.php"
        marker_wrapper.write_text(common_log_marker_php_source(), encoding="utf-8")
        initial = b"one\r\ntwo\r\nthree\r\nfour\r\n"
        case["debug_log"].write_bytes(initial)
        case["debug_log"].chmod(0o640)

        produced = subprocess.run(
            ["php", str(marker_wrapper), str(case["debug_log"])],
            cwd=REPO,
            env={
                **os.environ,
                "CRITICAL_FLOWS_RUN_STAMP": case["run_stamp"],
                "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
            },
            text=True,
            capture_output=True,
            check=False,
        )
        assert produced.returncode == 0, produced.stdout + produced.stderr
        marker = json.loads(produced.stdout)
        final_bytes = case["debug_log"].read_bytes()
        observation = marker["paths"][str(case["debug_log"])]

        assert marker["schema"] == "woopayments_debug_log_marker.v6"
        assert observation["byte_count"] == len(final_bytes)
        assert observation["line_count"] == 5
        assert observation["prefix_fingerprint"] == "sha256:" + hashlib.sha256(
            final_bytes
        ).hexdigest()
        assert marker["key_fingerprint"] == "sha256:" + hashlib.sha256(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY)
        ).hexdigest()
        assert marker["origin_binding"] == "hmac-sha256:" + hmac.new(
            bytes.fromhex(TEST_RUN_CONTEXT_KEY),
            log_observer_origin_material(marker),
            hashlib.sha256,
        ).hexdigest()

        case["marker"] = marker
        case["original"] = final_bytes
        case["initial_stat"] = case["debug_log"].stat()
        case["observer_id"] = marker["observer_id"]
        case["backing_path"] = case["debug_log"].with_name(
            f"{case['debug_log'].name}.woopayments-critical-flows.{marker['observer_id']}.backing"
        )
        wrong_key_process = launch_log_observer(case, context_key="22" * 32)
        try:
            blocked, _ = wait_log_observer_record(wrong_key_process, "blocked")
            assert blocked["blocker_code"] == "invalid_marker_origin"
            assert wrong_key_process.wait(timeout=2) != 0
        finally:
            stop_observer_process(wrong_key_process)


def test_log_observer_happy_path_preserves_bytes_metadata_and_store_usability() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-happy-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case)
        records: list[str] = []
        try:
            ready, encoded = wait_log_observer_record(process, "ready")
            records.extend(encoded)
            assert ready["origin_binding"] == case["marker"]["origin_binding"]
            safe_line = b"ordinary safe application line\n"
            write_observer_fifo(case["debug_log"], safe_line)
            event, encoded = wait_log_observer_record(process, "line", category="other")
            records.extend(encoded)
            assert event["fingerprint"] == "sha256:" + hashlib.sha256(
                safe_line.rstrip(b"\r\n")
            ).hexdigest()
            write_observer_fifo(
                case["debug_log"],
                observer_terminal_line(case),
            )
            complete, encoded = wait_log_observer_record(process, "complete")
            records.extend(encoded)
            assert complete["status"] == "pass"
            assert process.wait(timeout=2) == 0
            assert_log_observer_cleaned(case, safe_line)
        finally:
            stop_observer_process(process)


def test_log_forwarder_postfork_eight_path_attack_preserves_all_nodes_and_reaps_child() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-eight-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        debug_logs = add_log_observer_paths(case, count=8)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={"TEST_LEASE_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            for debug_log in debug_logs:
                assert stat.S_ISFIFO(debug_log.lstat().st_mode)
            for path in artifacts.values():
                assert path.is_file()

            journal_deadline = time.monotonic() + 0.5
            while (
                time.monotonic() < journal_deadline
                and not artifacts["journal"].read_bytes()
            ):
                time.sleep(0.005)
            precommit_journal = artifacts["journal"].read_bytes()

            authentic_fifo_stat = case["debug_log"].lstat()
            parked_fifo = root / "authenticated-first.fifo.parked"
            case["debug_log"].rename(parked_fifo)
            os.mkfifo(case["debug_log"], 0o600)
            foreign_fifo_stat = case["debug_log"].lstat()

            authentic_lease_stat = artifacts["lease"].lstat()
            parked_lease = root / "authenticated.lease.parked"
            artifacts["lease"].rename(parked_lease)
            artifacts["lease"].mkdir(mode=0o700)
            foreign_lease_stat = artifacts["lease"].lstat()
            backing_stats = {
                debug_log: debug_log.with_name(
                    f"{debug_log.name}.woopayments-critical-flows."
                    f"{case['observer_id']}.backing"
                ).lstat()
                for debug_log in debug_logs
            }

            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")
            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            assert precommit_journal == b""

            current_fifo = case["debug_log"].lstat()
            assert stat.S_ISFIFO(current_fifo.st_mode)
            assert (current_fifo.st_dev, current_fifo.st_ino) == (
                foreign_fifo_stat.st_dev,
                foreign_fifo_stat.st_ino,
            )
            parked_fifo_stat = parked_fifo.lstat()
            assert (parked_fifo_stat.st_dev, parked_fifo_stat.st_ino) == (
                authentic_fifo_stat.st_dev,
                authentic_fifo_stat.st_ino,
            )
            current_lease = artifacts["lease"].lstat()
            assert stat.S_ISDIR(current_lease.st_mode)
            assert (current_lease.st_dev, current_lease.st_ino) == (
                foreign_lease_stat.st_dev,
                foreign_lease_stat.st_ino,
            )
            parked_lease_stat = parked_lease.lstat()
            assert (parked_lease_stat.st_dev, parked_lease_stat.st_ino) == (
                authentic_lease_stat.st_dev,
                authentic_lease_stat.st_ino,
            )
            assert artifacts["journal"].is_file()
            assert artifacts["control"].is_file()
            for debug_log, expected in backing_stats.items():
                backing = debug_log.with_name(
                    f"{debug_log.name}.woopayments-critical-flows."
                    f"{case['observer_id']}.backing"
                )
                current = backing.lstat()
                assert (current.st_dev, current.st_ino) == (
                    expected.st_dev,
                    expected.st_ino,
                )
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_postfork_encode_failure_reaps_child_and_strictly_restores_intact_paths() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-encode-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        child_pid = 0
        application_bytes = b"application bytes queued before lease failure\n"
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            journal_deadline = time.monotonic() + 0.5
            while (
                time.monotonic() < journal_deadline
                and not artifacts["journal"].read_bytes()
            ):
                time.sleep(0.005)
            precommit_journal = artifacts["journal"].read_bytes()
            write_observer_fifo(case["debug_log"], application_bytes)
            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            assert precommit_journal == b""
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert case["debug_log"].read_bytes() == case["original"] + application_bytes
            assert stat.S_IMODE(case["debug_log"].stat().st_mode) == stat.S_IMODE(
                case["initial_stat"].st_mode
            )
            assert all(not path.exists() for path in artifacts.values())
            assert application_bytes.decode().strip() not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_postfork_failure_kills_and_reaps_stopped_exact_child() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-stopped-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            os.kill(child_pid, signal.SIGSTOP)
            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert case["debug_log"].read_bytes() == case["original"]
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_parent_death_before_commit_closes_gate_and_child_exits() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-parent-death-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={"TEST_LEASE_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            assert artifacts["journal"].read_bytes() == b""
            process.kill()
            assert process.wait(timeout=2) != 0
            assert wait_for_no_process(child_pid)
            assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
            assert case["backing_path"].read_bytes() == case["original"]
            assert all(path.is_file() for path in artifacts.values())
            assert artifacts["journal"].read_bytes() == b""
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_unowned_reap_result_preserves_every_startup_node() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-unowned-reap-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
                "TEST_REAP_STARTUP_CHILD_EARLY": "1",
            },
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            fifo_stat = case["debug_log"].lstat()
            backing_stat = case["backing_path"].lstat()
            artifacts = log_forwarder_artifact_paths(case)
            artifact_stats = {kind: path.lstat() for kind, path in artifacts.items()}
            os.kill(child_pid, signal.SIGSTOP)
            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            current_fifo = case["debug_log"].lstat()
            current_backing = case["backing_path"].lstat()
            assert stat.S_ISFIFO(current_fifo.st_mode)
            assert (current_fifo.st_dev, current_fifo.st_ino) == (
                fifo_stat.st_dev,
                fifo_stat.st_ino,
            )
            assert (current_backing.st_dev, current_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            for kind, path in artifacts.items():
                current = path.lstat()
                expected = artifact_stats[kind]
                assert (current.st_dev, current.st_ino) == (
                    expected.st_dev,
                    expected.st_ino,
                )
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


@pytest.mark.parametrize("kind", ["fifo", "backing", "lease", "journal", "control"])
def test_log_forwarder_postfork_failure_preserves_substituted_startup_identity(
    kind: str,
) -> None:
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-log-start-substitute-{kind}-"
    ) as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            paths = {
                "fifo": case["debug_log"],
                "backing": case["backing_path"],
                **artifacts,
            }
            original_stats = {name: path.lstat() for name, path in paths.items()}
            application_bytes = f"queued before {kind} substitution\n".encode()
            write_observer_fifo(case["debug_log"], application_bytes)

            target = paths[kind]
            parked = root / f"authenticated-{kind}.startup.parked"
            target.rename(parked)
            if kind == "fifo":
                os.mkfifo(target, 0o600)
            else:
                target.write_bytes(f"foreign {kind}\n".encode())
                target.chmod(0o600)
            foreign_stat = target.lstat()

            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")
            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)

            current_target = target.lstat()
            assert (current_target.st_dev, current_target.st_ino) == (
                foreign_stat.st_dev,
                foreign_stat.st_ino,
            )
            parked_stat = parked.lstat()
            assert (parked_stat.st_dev, parked_stat.st_ino) == (
                original_stats[kind].st_dev,
                original_stats[kind].st_ino,
            )
            for other_kind, other_path in paths.items():
                if other_kind == kind:
                    continue
                current = other_path.lstat()
                expected = original_stats[other_kind]
                assert (current.st_dev, current.st_ino) == (
                    expected.st_dev,
                    expected.st_ino,
                )
            authentic_backing = parked if kind == "backing" else case["backing_path"]
            assert authentic_backing.read_bytes() == case["original"] + application_bytes
            assert application_bytes.decode().strip() not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


@pytest.mark.parametrize("kind", ["journal", "control"])
def test_log_forwarder_postfork_failure_preserves_in_place_artifact_mutation(
    kind: str,
) -> None:
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-log-start-mutate-{kind}-"
    ) as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        child_pid = 0
        mutation = f"in-place {kind} mutation\n".encode()
        application_bytes = b"queued before in-place artifact mutation\n"
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            artifact_stat = artifacts[kind].lstat()
            artifacts[kind].write_bytes(mutation)
            write_observer_fifo(case["debug_log"], application_bytes)
            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            current_artifact = artifacts[kind].lstat()
            assert (current_artifact.st_dev, current_artifact.st_ino) == (
                artifact_stat.st_dev,
                artifact_stat.st_ino,
            )
            assert artifacts[kind].read_bytes() == mutation
            assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
            assert case["backing_path"].read_bytes() == case["original"] + application_bytes
            assert all(path.exists() for path in artifacts.values())
            assert mutation.decode().strip() not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_postfork_mutated_lease_pid_cannot_redirect_abort() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-lease-pid-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-validation-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="1",
            extra_environment={"TEST_LEASE_VALIDATION_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        application_bytes = b"queued before coherent lease PID mutation\n"
        try:
            wait_for_test_path(barrier / "lease-validation-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            lease_stat = artifacts["lease"].lstat()
            lease = json.loads(artifacts["lease"].read_text(encoding="utf-8"))
            assert lease["child_pid"] == child_pid
            lease["child_pid"] = process.pid
            write_signed_log_forwarder_lease(artifacts["lease"], lease)
            write_observer_fifo(case["debug_log"], application_bytes)
            (barrier / "lease-validation-release").write_text(
                "release", encoding="utf-8"
            )

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            current_lease = artifacts["lease"].lstat()
            assert (current_lease.st_dev, current_lease.st_ino) == (
                lease_stat.st_dev,
                lease_stat.st_ino,
            )
            assert json.loads(artifacts["lease"].read_text(encoding="utf-8"))[
                "child_pid"
            ] == process.pid
            assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
            assert case["backing_path"].read_bytes() == case["original"] + application_bytes
            assert all(path.exists() for path in artifacts.values())
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_valid_eight_path_startup_completes_and_cleans() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-valid-eight-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        debug_logs = add_log_observer_paths(case, count=8)
        process = launch_log_observer(case, maximum_seconds="5")
        try:
            ready, _ = wait_log_observer_record(process, "ready")
            assert ready["path_count"] == 8
            _, lease = wait_log_forwarder_lease(case)
            child_pid = lease["child_pid"]
            additions = {}
            for index, debug_log in enumerate(debug_logs, start=1):
                addition = f"safe startup control path {index}\n".encode()
                additions[debug_log] = addition
                write_observer_fifo(debug_log, addition)
                write_observer_fifo(
                    debug_log,
                    observer_terminal_line_for_path(case, debug_log),
                )
            complete, _ = wait_log_observer_record(process, "complete", timeout=6)
            assert complete["status"] == "pass"
            assert process.wait(timeout=3) == 0
            assert wait_for_no_process(child_pid)
            for debug_log, addition in additions.items():
                assert stat.S_ISREG(debug_log.stat().st_mode)
                assert debug_log.read_bytes() == case["original"] + addition
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            stop_observer_process(process)


def test_log_forwarder_postfork_simultaneous_substitution_preserves_every_inode() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-substitute-all-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            artifacts = log_forwarder_artifact_paths(case)
            paths = {
                "fifo": case["debug_log"],
                "backing": case["backing_path"],
                **artifacts,
            }
            application_bytes = b"queued before simultaneous substitution\n"
            write_observer_fifo(case["debug_log"], application_bytes)
            parked = {}
            authentic_stats = {}
            foreign_stats = {}
            for kind, path in paths.items():
                authentic_stats[kind] = path.lstat()
                parked[kind] = root / f"authenticated-{kind}.all.parked"
                path.rename(parked[kind])
                if kind == "fifo":
                    os.mkfifo(path, 0o600)
                else:
                    path.write_bytes(f"foreign simultaneous {kind}\n".encode())
                    path.chmod(0o600)
                foreign_stats[kind] = path.lstat()

            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")
            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            for kind, path in paths.items():
                current = path.lstat()
                assert (current.st_dev, current.st_ino) == (
                    foreign_stats[kind].st_dev,
                    foreign_stats[kind].st_ino,
                )
                authentic = parked[kind].lstat()
                assert (authentic.st_dev, authentic.st_ino) == (
                    authentic_stats[kind].st_dev,
                    authentic_stats[kind].st_ino,
                )
            assert parked["backing"].read_bytes() == case["original"] + application_bytes
            assert application_bytes.decode().strip() not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


@pytest.mark.parametrize(
    "total_bytes,pause_after,pause_seconds",
    [
        pytest.param(
            STARTUP_DRAIN_MAX_BYTES + 16_384,
            0,
            0.0,
            id="reviewer-cap-plus-full-chunk",
        ),
        pytest.param(
            STARTUP_DRAIN_MAX_BYTES,
            0,
            0.0,
            id="exact-cap-pre-ready",
        ),
        pytest.param(
            STARTUP_DRAIN_MAX_BYTES + 4_096,
            0,
            0.0,
            id="cap-plus-partial-chunk",
        ),
        pytest.param(
            STARTUP_DRAIN_MAX_BYTES + 8_192,
            STARTUP_DRAIN_MAX_BYTES,
            0.07,
            id="resuming-pre-ready-writer",
        ),
    ],
)
def test_log_forwarder_pre_ready_cap_is_blocked_without_archival_claim_or_keeper(
    total_bytes: int,
    pause_after: int,
    pause_seconds: float,
) -> None:
    with tempfile.TemporaryDirectory(
        prefix="critical-flows-log-pre-ready-production-lifecycle-"
    ) as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        writer: subprocess.Popen[str] | None = None
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            writer = launch_counted_fifo_writer(
                case["debug_log"],
                total_bytes,
                pause_after=pause_after,
                pause_seconds=pause_seconds,
            )
            queued_deadline = time.monotonic() + 2
            while (
                time.monotonic() < queued_deadline
                and fifo_bytes_available(case["debug_log"]) == 0
            ):
                time.sleep(0.005)
            assert fifo_bytes_available(case["debug_log"]) > 0
            (barrier / "lease-encode-release").write_text(
                "release", encoding="utf-8"
            )

            stdout, stderr = process.communicate(timeout=10)
            successfully_written = wait_counted_fifo_writer(writer)
            writer = None
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert successfully_written > 0
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert not any(record["kind"] == "complete" for record in records)
            assert not any(record.get("status") == "pass" for record in records)
            assert wait_for_no_process(child_pid)
            assert "x" * 64 not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr

            # Intentionally make no accepted=backing+FIFO assertion. After the
            # production descriptors close there is no archival claim.
            assert case["debug_log"].exists() or case["backing_path"].exists()
        finally:
            if writer is not None:
                stop_observer_process(writer)
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_observed_pre_ready_input_can_never_support_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-pre-ready-block-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-validation-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={"TEST_LEASE_VALIDATION_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        hostile = b"hostile-pre-ready-input-must-not-appear\n"
        try:
            wait_for_test_path(barrier / "lease-validation-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            write_observer_fifo(case["debug_log"], hostile)
            (barrier / "lease-validation-release").write_text(
                "release", encoding="utf-8"
            )

            # Old bytes commit and emit ready; give that implementation an exact
            # terminal so its incorrect PASS is deterministic instead of hanging.
            ready_deadline = time.monotonic() + 1
            while process.poll() is None and time.monotonic() < ready_deadline:
                try:
                    journal = validated_log_forwarder_journal(case)
                except (
                    AssertionError,
                    FileNotFoundError,
                    json.JSONDecodeError,
                    UnicodeError,
                ):
                    time.sleep(0.01)
                    continue
                if any(record["kind"] == "ready" for record in journal):
                    write_observer_fifo(
                        case["debug_log"], observer_terminal_line(case)
                    )
                    break
                time.sleep(0.01)

            stdout, stderr = process.communicate(timeout=5)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert not any(record["kind"] == "complete" for record in records)
            assert hostile.decode().strip() not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
            assert wait_for_no_process(child_pid)
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_post_read_append_failure_blocks_without_archival_claim() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-post-read-rlimit-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        payload = b"post-read-rlimit-secret-must-not-be-disclosed"
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
                "TEST_IGNORE_SIGXFSZ": "1",
                "TEST_RLIMIT_FSIZE_AFTER_BARRIER": str(len(case["original"])),
            },
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            write_observer_fifo(case["debug_log"], payload)
            (barrier / "lease-encode-release").write_text(
                "release", encoding="utf-8"
            )

            stdout, stderr = process.communicate(timeout=5)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert not any(record["kind"] == "complete" for record in records)
            assert payload.decode() not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
            assert wait_for_no_process(child_pid)

            # Do not assert FIFO remainder, backing delta, or byte conservation:
            # the real post-read failure is exactly outside archival scope.
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_authorized_ready_gated_write_restores_exact_bytes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-ready-gated-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        payload = b"authorized-ready-gated-line\n"
        try:
            ready, _ = wait_log_observer_record(coordinator, "ready")
            assert ready["status"] == "pass"
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)

            write_observer_fifo(case["debug_log"], payload)
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            complete, _ = wait_log_observer_record(coordinator, "complete")
            assert complete["status"] == "pass"
            assert coordinator.wait(timeout=3) == 0
            wait_process_absent(child_pid)

            assert case["debug_log"].read_bytes() == case["original"] + payload
            assert_log_observer_cleaned(case, payload)
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_postfork_drain_preflights_failures_without_fifo_consumption() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-drain-failure-") as tmp:
        root = Path(tmp)
        wrapper = root / "drain-failure-harness.php"
        wrapper.write_text(
            r"""<?php
$source = file_get_contents( getenv( 'TEST_LOG_OBSERVER_DRIVER' ) );
$source = preg_replace( '/\nWooPaymentsCriticalFlowsLogObserver::run\( \$args \);\s*$/', "\n", $source );
eval( '?>' . $source );
$paths = new ReflectionProperty( WooPaymentsCriticalFlowsLogObserver::class, 'paths' );
$drain = new ReflectionMethod( WooPaymentsCriticalFlowsLogObserver::class, 'drain_pre_ready_bytes' );
if ( PHP_VERSION_ID < 80100 ) {
    $paths->setAccessible( true );
    $drain->setAccessible( true );
}
function run_case( string $root, string $kind, ReflectionProperty $paths, ReflectionMethod $drain ): array {
    $directory = $root . DIRECTORY_SEPARATOR . $kind;
    mkdir( $directory, 0700 );
    $fifo_path = $directory . DIRECTORY_SEPARATOR . 'debug.log';
    $backing_path = $directory . DIRECTORY_SEPARATOR . 'debug.backing';
    posix_mkfifo( $fifo_path, 0600 );
    file_put_contents( $backing_path, 'original' );
    chmod( $backing_path, 0600 );
    $reader = fopen( $fifo_path, 'r+' );
    stream_set_blocking( $reader, false );
    stream_set_read_buffer( $reader, 0 );
    $fifo = 'unreadable_fifo' === $kind ? fopen( $fifo_path, 'wb' ) : $reader;
    stream_set_blocking( $fifo, false );
    $backing = fopen( $backing_path, 'unwritable_sink' === $kind ? 'rb' : 'r+b' );
    fseek( $backing, 0, SEEK_END );
    $fifo_stat = fstat( $fifo );
    $backing_stat = fstat( $backing );
    $state = array(
        'path_id' => 'hmac-sha256:' . str_repeat( '1', 64 ),
        'backing_path' => $backing_path,
        'backing_stream' => $backing,
        'fifo_stream' => $fifo,
        'fifo_dev' => (int) $fifo_stat['dev'],
        'fifo_ino' => (int) $fifo_stat['ino'],
        'dev' => (int) $backing_stat['dev'],
        'ino' => (int) $backing_stat['ino'],
        'byte_count' => (int) $backing_stat['size'],
    );
    $paths->setValue( null, array( $fifo_path => $state ) );
    $writer = fopen( $fifo_path, 'wb' );
    stream_set_blocking( $writer, false );
    $payload = 'queued-before-' . $kind;
    $written = fwrite( $writer, $payload );
    fclose( $writer );
    $result = $drain->invoke( null );
    $remaining = fread( $reader, 8192 );
    if ( $fifo !== $reader ) {
        fclose( $fifo );
    }
    fclose( $reader );
    fclose( $backing );
    return array(
        'failed' => false === $result,
        'written' => $written,
        'remaining' => $remaining,
        'payload' => $payload,
        'backing' => file_get_contents( $backing_path ),
    );
}
echo json_encode(
    array(
        'unwritable_sink' => run_case( $argv[1], 'unwritable_sink', $paths, $drain ),
        'unreadable_fifo' => run_case( $argv[1], 'unreadable_fifo', $paths, $drain ),
    )
), "\n";
""",
            encoding="utf-8",
        )
        result = subprocess.run(
            ["php", str(wrapper), str(root)],
            cwd=REPO,
            env={**os.environ, "TEST_LOG_OBSERVER_DRIVER": str(LOG_OBSERVER_DRIVER)},
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 0, result.stdout + result.stderr
        cases = json.loads(result.stdout)
        for outcome in cases.values():
            assert outcome["failed"] is True
            assert outcome["written"] == len(outcome["payload"])
            assert outcome["remaining"] == outcome["payload"]
            assert outcome["backing"] == "original"

    source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
    begin = source.index("private static function drain_pre_ready_bytes(): bool")
    end = source.index("\n\t/**", begin)
    drain = source[begin:end]
    assert "usort(" in drain
    assert "min( self::READ_BYTES, $remaining )" in drain
    assert drain.index("retained_backing_sink_ready") < drain.index("@fread(")
    assert drain.index("self::sync_stream") < drain.index("$total_bytes += strlen")


def test_log_forwarder_postfork_drain_overflow_preserves_fifo_backing_and_artifacts() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-drain-overflow-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={
                "TEST_LEASE_BARRIER_DIR": str(barrier),
                "TEST_FAIL_LEASE_ENCODE": "1",
            },
        )
        writer = None
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-encode-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            fifo_stat = case["debug_log"].lstat()
            backing_stat = case["backing_path"].lstat()
            artifacts = log_forwarder_artifact_paths(case)
            writer = subprocess.Popen(
                [
                    "python3",
                    "-c",
                    (
                        "import os,select,sys,time;"
                        "fd=os.open(sys.argv[1],os.O_WRONLY|os.O_NONBLOCK);"
                        "data=b'x'*9437184;view=memoryview(data);end=time.monotonic()+5;"
                        "\nwhile view and time.monotonic()<end:\n"
                        " try:\n  view=view[os.write(fd,view):]\n"
                        " except BlockingIOError:\n  select.select([],[fd],[],0.05)\n"
                        " except BrokenPipeError:\n  break\n"
                        "os.close(fd)"
                    ),
                    str(case["debug_log"]),
                ],
                cwd=REPO,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            queued_deadline = time.monotonic() + 2
            while time.monotonic() < queued_deadline and fifo_bytes_available(
                case["debug_log"]
            ) == 0:
                time.sleep(0.005)
            assert fifo_bytes_available(case["debug_log"]) > 0
            (barrier / "lease-encode-release").write_text("release", encoding="utf-8")

            stdout, stderr = process.communicate(timeout=8)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            current_fifo = case["debug_log"].lstat()
            current_backing = case["backing_path"].lstat()
            assert stat.S_ISFIFO(current_fifo.st_mode)
            assert (current_fifo.st_dev, current_fifo.st_ino) == (
                fifo_stat.st_dev,
                fifo_stat.st_ino,
            )
            assert (current_backing.st_dev, current_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            assert case["backing_path"].stat().st_size > len(case["original"])
            assert all(path.is_file() for path in artifacts.values())
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if writer is not None and writer.poll() is None:
                writer.terminate()
                try:
                    writer.wait(timeout=2)
                except subprocess.TimeoutExpired:
                    writer.kill()
                    writer.wait(timeout=2)
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_commit_peer_exit_uses_active_side_abort_and_drain() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-commit-exit-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-validation-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={"TEST_LEASE_VALIDATION_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        application_bytes = b"queued before commit peer exit\n"
        try:
            wait_for_test_path(barrier / "lease-validation-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            write_observer_fifo(case["debug_log"], application_bytes)
            os.kill(child_pid, signal.SIGKILL)
            (barrier / "lease-validation-release").write_text(
                "release", encoding="utf-8"
            )

            stdout, stderr = process.communicate(timeout=6)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "forwarder_start_failed"
            assert wait_for_no_process(child_pid)
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert case["debug_log"].read_bytes() == case["original"] + application_bytes
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_full_commit_then_stopped_child_death_recovers_boundedly() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-full-commit-child-death-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        barrier = root / "lease-validation-barrier"
        barrier.mkdir()
        process = launch_log_observer(
            case,
            maximum_seconds="5",
            extra_environment={"TEST_LEASE_VALIDATION_BARRIER_DIR": str(barrier)},
        )
        child_pid = 0
        try:
            wait_for_test_path(barrier / "lease-validation-reached")
            children = direct_child_pids(process.pid)
            assert len(children) == 1
            child_pid = children[0]
            os.kill(child_pid, signal.SIGSTOP)
            (barrier / "lease-validation-release").write_text(
                "release", encoding="utf-8"
            )
            time.sleep(0.15)
            assert process.poll() is None
            assert log_forwarder_artifact_paths(case)["journal"].read_bytes() == b""
            os.kill(child_pid, signal.SIGKILL)
            assert wait_for_no_process(child_pid)

            try:
                stdout, stderr = process.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                process.kill()
                process.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not leave terminal-less exact-reap state"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert process.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert records[-1]["blocker_code"] == "observer_forced_recovery"
            assert case["debug_log"].read_bytes() == case["original"]
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if child_pid:
                stop_test_owned_observer_child(child_pid, case["wrapper"])
            stop_observer_process(process)


def test_log_forwarder_coordinator_recovers_exact_child_death_after_consumed_fragment() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-coordinator-child-fragment-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        fragment = b"PHP Warning: exact child died after consuming this unterminated fragment"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            write_observer_fifo(case["debug_log"], fragment)
            wait_fifo_bytes_available(case["debug_log"], 0)
            os.kill(child_pid, signal.SIGKILL)
            assert wait_for_no_process(child_pid)

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not recover exact child death after raw durability"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"
            assert case["debug_log"].read_bytes() == case["original"] + fragment
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_coordinator_recovers_exact_child_death_after_ready() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-coordinator-child-ready-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            os.kill(child_pid, signal.SIGKILL)
            assert wait_for_no_process(child_pid)

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not recover exact child death after ready"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"
            assert case["debug_log"].read_bytes() == case["original"]
            assert stat.S_ISREG(case["debug_log"].stat().st_mode)
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_child_death_recovery_filters_split_authenticated_sentinel() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-child-death-split-stop-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        terminal = observer_terminal_line(case)
        durable_prefix = terminal[:-13]
        unread_suffix = terminal[-13:]
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            write_observer_fifo(case["debug_log"], durable_prefix)
            wait_fifo_bytes_available(case["debug_log"], 0)
            backing_deadline = time.monotonic() + 2
            while (
                time.monotonic() < backing_deadline
                and not case["backing_path"].read_bytes().endswith(durable_prefix)
            ):
                time.sleep(0.01)
            assert case["backing_path"].read_bytes().endswith(durable_prefix)
            os.kill(child_pid, signal.SIGSTOP)
            write_observer_fifo(case["debug_log"], unread_suffix)
            assert fifo_bytes_available(case["debug_log"]) == len(unread_suffix)
            os.kill(child_pid, signal.SIGKILL)
            assert wait_for_no_process(child_pid)

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not filter a split terminal after exact reap"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"
            assert case["debug_log"].read_bytes() == case["original"]
            assert terminal not in case["debug_log"].read_bytes()
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


@pytest.mark.parametrize("kind", ["fifo", "backing", "lease", "journal", "control"])
def test_log_forwarder_child_death_recovery_preserves_substituted_identity(
    kind: str,
) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-log-child-death-{kind}-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        coordinator_stopped = False
        parked = root / f"authenticated-{kind}"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            paths = {
                "fifo": case["debug_log"],
                "backing": case["backing_path"],
                **log_forwarder_artifact_paths(case),
            }
            target = paths[kind]
            authentic_stat = target.lstat()
            authentic_bytes = b"" if kind == "fifo" else target.read_bytes()
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            os.kill(child_pid, signal.SIGKILL)
            target.rename(parked)
            if kind == "fifo":
                os.mkfifo(target, 0o600)
            else:
                target.write_bytes(f"foreign-{kind}".encode())
                target.chmod(0o600)
            foreign_stat = target.lstat()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    f"coordinator did not bound {kind} substitution after exact reap"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            current_foreign = target.lstat()
            current_authentic = parked.lstat()
            assert (current_foreign.st_dev, current_foreign.st_ino) == (
                foreign_stat.st_dev,
                foreign_stat.st_ino,
            )
            assert (current_authentic.st_dev, current_authentic.st_ino) == (
                authentic_stat.st_dev,
                authentic_stat.st_ino,
            )
            if kind != "fifo":
                assert parked.read_bytes() == authentic_bytes
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_child_death_recovery_preserves_simultaneous_substitutions() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-child-death-all-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        coordinator_stopped = False
        parked: dict[str, Path] = {}
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            paths = {
                "fifo": case["debug_log"],
                "backing": case["backing_path"],
                **log_forwarder_artifact_paths(case),
            }
            authentic_stats = {kind: path.lstat() for kind, path in paths.items()}
            authentic_bytes = {
                kind: path.read_bytes() for kind, path in paths.items() if kind != "fifo"
            }
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            os.kill(child_pid, signal.SIGKILL)
            foreign_stats = {}
            for kind, path in paths.items():
                parked[kind] = root / f"authenticated-{kind}"
                path.rename(parked[kind])
                if kind == "fifo":
                    os.mkfifo(path, 0o600)
                else:
                    path.write_bytes(f"foreign-all-{kind}".encode())
                    path.chmod(0o600)
                foreign_stats[kind] = path.lstat()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not bound simultaneous substitutions after exact reap"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            for kind, path in paths.items():
                current = path.lstat()
                authentic = parked[kind].lstat()
                assert (current.st_dev, current.st_ino) == (
                    foreign_stats[kind].st_dev,
                    foreign_stats[kind].st_ino,
                )
                assert (authentic.st_dev, authentic.st_ino) == (
                    authentic_stats[kind].st_dev,
                    authentic_stats[kind].st_ino,
                )
                if kind != "fifo":
                    assert parked[kind].read_bytes() == authentic_bytes[kind]
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


@pytest.mark.parametrize("kind", ["journal", "control"])
def test_log_forwarder_child_death_recovery_preserves_in_place_artifact_mutation(
    kind: str,
) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-log-child-death-in-place-{kind}-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        coordinator_stopped = False
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            target = log_forwarder_artifact_paths(case)[kind]
            identity = target.lstat()
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            os.kill(child_pid, signal.SIGKILL)
            if kind == "journal":
                mutated = bytearray(target.read_bytes())
                assert mutated
                mutated[0] ^= 1
                mutation = bytes(mutated)
            else:
                mutation = b"foreign-control-content"
            target.write_bytes(mutation)
            target.chmod(0o600)
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            try:
                stdout, stderr = coordinator.communicate(timeout=3)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    f"coordinator did not bound in-place {kind} mutation"
                ) from error
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            current = target.lstat()
            assert (current.st_dev, current.st_ino) == (identity.st_dev, identity.st_ino)
            assert target.read_bytes() == mutation
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_child_death_recovery_bounds_continuing_fifo_writer() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-child-death-writer-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        writer: subprocess.Popen[str] | None = None
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            os.kill(child_pid, signal.SIGSTOP)
            writer = subprocess.Popen(
                [
                    "python3",
                    "-c",
                    (
                        "import os,select,sys,time;"
                        "fd=os.open(sys.argv[1],os.O_WRONLY|os.O_NONBLOCK);"
                        "end=time.monotonic()+3;payload=b'continuing-writer\\n';"
                        "\nwhile time.monotonic()<end:\n"
                        " try:\n  os.write(fd,payload);time.sleep(0.01)\n"
                        " except BlockingIOError:\n  select.select([],[fd],[],0.01)\n"
                        " except BrokenPipeError:\n  break\n"
                        "os.close(fd)"
                    ),
                    str(case["debug_log"]),
                ],
                cwd=REPO,
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
            write_deadline = time.monotonic() + 1
            while time.monotonic() < write_deadline and fifo_bytes_available(
                case["debug_log"]
            ) == 0:
                time.sleep(0.005)
            assert fifo_bytes_available(case["debug_log"]) > 0
            os.kill(child_pid, signal.SIGKILL)
            assert wait_for_no_process(child_pid)

            started = time.monotonic()
            try:
                stdout, stderr = coordinator.communicate(timeout=4)
            except subprocess.TimeoutExpired as error:
                coordinator.kill()
                coordinator.wait(timeout=2)
                raise AssertionError(
                    "coordinator did not bound a continuing retained-FIFO writer"
                ) from error
            elapsed = time.monotonic() - started
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert elapsed < 4
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["kind"] == "blocked"
            assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
            assert all(
                path.is_file() for path in log_forwarder_artifact_paths(case).values()
            )
            assert "continuing-writer" not in stdout + stderr
            assert TEST_RUN_CONTEXT_KEY not in stdout + stderr
        finally:
            if writer is not None:
                stop_observer_process(writer)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_terminal_write_race_honors_authenticated_terminal() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-terminal-race-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid = 0
        coordinator_stopped = False
        warning = b"PHP Warning: terminal race retains this exact warning\n"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            write_observer_fifo(case["debug_log"], warning + observer_terminal_line(case))
            terminal, _ = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "complete"
            try:
                os.kill(child_pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            stdout, stderr = coordinator.communicate(timeout=3)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 0, stdout + stderr
            assert records[-1]["kind"] == "complete"
            assert case["debug_log"].read_bytes() == case["original"] + warning
            assert all(
                not path.exists() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_suppresses_raw_pcntl_fork_warning() -> None:
    source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
    start = source.index("private static function start_forwarder_foundation(): bool")
    end = source.index("\n\t/**", start)
    foundation = source[start:end]
    assert "$child_pid = @pcntl_fork();" in foundation
    assert "$child_pid = pcntl_fork();" not in foundation


def test_mo03_fifo_witness_contract_is_ready_gated_and_fail_closed() -> None:
    contract = MO03_CONTRACT.read_text(encoding="utf-8")
    assert "evidence-integrity mechanism, not a general durable message queue" in contract
    assert "after authenticated `ready` and before the coordinated terminal" in contract
    assert "successful backing append and PHP flush" in contract
    assert "does not claim stable-media durability on PHP 7.4" in contract
    assert "does not claim archival preservation" in contract
    assert "durably appends accepted bytes" not in contract

    source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
    foundation_start = source.index(
        "private static function start_forwarder_foundation(): bool"
    )
    foundation_end = source.index("\n\t/**", foundation_start)
    foundation = source[foundation_start:foundation_end]
    assert "self::startup_fifos_are_quiet()" in foundation
    assert foundation.index("self::startup_fifos_are_quiet()") < foundation.index(
        "self::write_startup_frame( $gates[0], 'COMMIT'"
    )

    forwarder_start = source.index("private static function run_forwarder(): void")
    forwarder_end = source.index("\n\t/**", forwarder_start)
    forwarder = source[forwarder_start:forwarder_end]
    assert "$blocker_code = 'backing_write_failed';" in forwarder
    assert forwarder.index("self::forward_bytes(") < forwarder.index(
        "$blocker_code = 'backing_write_failed';"
    )

    sync_start = source.index("private static function sync_stream( $stream ): bool")
    sync_end = source.index("\n\t/**", sync_start)
    sync_source = source[sync_start:sync_end]
    assert "if ( ! function_exists( 'fsync' ) )" in sync_source
    assert "return true;" in sync_source
    assert "return true === @call_user_func( 'fsync', $stream );" in sync_source


def test_log_forwarder_startup_frames_reject_partial_oversized_and_wrong_tokens() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-start-frames-") as tmp:
        wrapper = Path(tmp) / "startup-frame-harness.php"
        wrapper.write_text(
            """<?php
$source = file_get_contents( getenv( 'TEST_LOG_OBSERVER_DRIVER' ) );
$source = preg_replace( '/\\nWooPaymentsCriticalFlowsLogObserver::run\\( \\$args \\);\\s*$/', "\\n", $source );
eval( '?>' . $source );
$read = new ReflectionMethod( WooPaymentsCriticalFlowsLogObserver::class, 'read_startup_frame' );
$write = new ReflectionMethod( WooPaymentsCriticalFlowsLogObserver::class, 'write_startup_frame' );
if ( PHP_VERSION_ID < 80100 ) {
    $read->setAccessible( true );
    $write->setAccessible( true );
}
$cases = array(
    'partial_header' => "\\x00\\x00",
    'oversized' => pack( 'N', 17 ) . str_repeat( 'A', 17 ),
    'partial_payload' => pack( 'N', 6 ) . 'COM',
    'wrong_token' => pack( 'N', 4 ) . 'NOPE',
);
$results = array();
foreach ( $cases as $name => $bytes ) {
    $pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
    stream_set_blocking( $pair[0], false );
    stream_set_blocking( $pair[1], false );
    fwrite( $pair[0], $bytes );
    fclose( $pair[0] );
    $results[ $name ] = false === $read->invoke( null, $pair[1], array( 'COMMIT' ), hrtime( true ) + 200000000 );
    fclose( $pair[1] );
}
$pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
stream_set_blocking( $pair[0], false );
stream_set_blocking( $pair[1], false );
$results['valid_write'] = true === $write->invoke( null, $pair[0], 'COMMIT', hrtime( true ) + 200000000 );
$results['valid_read'] = 'COMMIT' === $read->invoke( null, $pair[1], array( 'COMMIT' ), hrtime( true ) + 200000000 );
$results['invalid_write'] = false === $write->invoke( null, $pair[0], 'UNKNOWN', hrtime( true ) + 200000000 );
fclose( $pair[0] );
fclose( $pair[1] );
echo json_encode( $results ), "\\n";
""",
            encoding="utf-8",
        )
        result = subprocess.run(
            ["php", str(wrapper)],
            cwd=REPO,
            env={**os.environ, "TEST_LOG_OBSERVER_DRIVER": str(LOG_OBSERVER_DRIVER)},
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert json.loads(result.stdout) == {
            "partial_header": True,
            "oversized": True,
            "partial_payload": True,
            "wrong_token": True,
            "valid_write": True,
            "valid_read": True,
            "invalid_write": True,
        }


def test_log_observer_context_key_is_not_disclosed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-secret-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case)
        encoded_records: list[str] = []
        try:
            assert all(TEST_RUN_CONTEXT_KEY not in str(argument) for argument in process.args)
            _, encoded = wait_log_observer_record(process, "ready")
            encoded_records.extend(encoded)
            write_observer_fifo(
                case["debug_log"],
                observer_terminal_line(case),
            )
            _, encoded = wait_log_observer_record(process, "complete")
            encoded_records.extend(encoded)
            assert process.wait(timeout=2) == 0
            assert process.stderr is not None
            stderr = process.stderr.read()
            archived = json.dumps(case["marker"], sort_keys=True) + "".join(encoded_records)
            assert TEST_RUN_CONTEXT_KEY not in archived
            assert TEST_RUN_CONTEXT_KEY not in stderr
            assert TEST_RUN_CONTEXT_KEY.encode() not in case["debug_log"].read_bytes()
            assert case["marker"]["key_fingerprint"].startswith("sha256:")
            assert case["marker"]["origin_binding"].startswith("hmac-sha256:")
        finally:
            stop_observer_process(process)


def test_log_observer_refuses_preexisting_backing_collision_without_data_loss() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-collision-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        collision = b"do-not-overwrite-collision\n"
        case["backing_path"].write_bytes(collision)
        process = launch_log_observer(case)
        try:
            blocked, _ = wait_log_observer_record(process, "blocked")
            assert blocked["blocker_code"] == "backing_collision"
            assert process.wait(timeout=2) != 0
            assert case["debug_log"].read_bytes() == case["original"]
            assert case["backing_path"].read_bytes() == collision
            with case["debug_log"].open("ab") as stream:
                stream.write(b"store-still-usable\n")
        finally:
            stop_observer_process(process)


def test_log_observer_blocks_fifo_setup_failure_without_replacing_regular_log() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-setup-") as tmp:
        root = Path(tmp)
        case = prepare_log_observer_case(root)
        root.chmod(0o500)
        process = launch_log_observer(case)
        try:
            blocked, _ = wait_log_observer_record(process, "blocked")
            assert blocked["blocker_code"] == "fifo_setup_failed"
            assert process.wait(timeout=2) != 0
        finally:
            root.chmod(0o700)
            stop_observer_process(process)
        assert case["debug_log"].read_bytes() == case["original"]
        assert stat.S_ISREG(case["debug_log"].stat().st_mode)
        with case["debug_log"].open("ab") as stream:
            stream.write(b"store-still-usable-after-setup-failure\n")


def test_common_marker_blocks_when_observer_never_reports_ready_within_deadline() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-ready-timeout-") as tmp:
        root = Path(tmp)
        fake_wp = root / "fake-wp.sh"
        write_executable(
            fake_wp,
            """#!/usr/bin/env bash
if [ "$1" = "eval" ]; then
  printf '%s\n' '{"status":"pass","run_stamp":"20260716T160000Z-30303","paths":["/tmp/fake-debug.log"],"markers":{}}'
  exit 0
fi
if [ "$1" = "eval-file" ]; then
  exit 0
fi
exit 2
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
TARGET_WP_COMMAND={shlex.quote(str(fake_wp))}
CRITICAL_FLOWS_RUN_STAMP=20260716T160000Z-30303
CRITICAL_FLOWS_RUN_CONTEXT_KEY={TEST_RUN_CONTEXT_KEY}
CRITICAL_FLOWS_FLOW_ID=SC-01-card-checkout
CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log
CRITICAL_FLOWS_LOG_OBSERVER_READY_TIMEOUT=1
TMPDIR={shlex.quote(str(root))}
export CRITICAL_FLOWS_RUN_STAMP CRITICAL_FLOWS_RUN_CONTEXT_KEY
export CRITICAL_FLOWS_FLOW_ID CRITICAL_FLOWS_LOG_PURPOSE
export CRITICAL_FLOWS_LOG_OBSERVER_READY_TIMEOUT TMPDIR
mark_log_clean_start target
"""
        started = time.monotonic()
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
            timeout=4,
        )
        elapsed = time.monotonic() - started

        assert result.returncode == 3, result.stdout + result.stderr
        assert elapsed < 3
        assert "observer_readiness_timeout" in result.stdout
        assert TEST_RUN_CONTEXT_KEY not in result.stdout + result.stderr
        assert not list(root.glob("*observer*sidecar*"))


def test_common_observer_cleanup_ignores_untrusted_pid_sidecar() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-observer-cleanup-pid-") as tmp:
        root = Path(tmp)
        sidecar = root / "observer-sidecar"
        script = f"""
source {shlex.quote(str(COMMON))}
sidecar={shlex.quote(str(sidecar))}
: > "$sidecar"
sleep 30 &
unrelated_pid=$!
sleep 30 &
observer_pid=$!
cleanup_processes() {{
  kill "$unrelated_pid" "$observer_pid" 2>/dev/null || true
  wait "$unrelated_pid" "$observer_pid" 2>/dev/null || true
}}
trap cleanup_processes EXIT
printf '%s\n' "$unrelated_pid" > "$sidecar.pid"
printf '143\n' > "$sidecar.exit"
CRITICAL_FLOWS_LOG_OBSERVER_PID="$observer_pid"
CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR="$sidecar"
CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE="$sidecar.exit"
critical_flows_log_observer_cleanup
kill -0 "$unrelated_pid" 2>/dev/null || exit 41
if kill -0 "$observer_pid" 2>/dev/null; then
  exit 42
fi
kill "$unrelated_pid" 2>/dev/null || true
wait "$unrelated_pid" 2>/dev/null || true
trap - EXIT
"""
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
            timeout=5,
        )

        assert result.returncode == 0, result.stdout + result.stderr


def test_common_observer_recovery_and_cleanup_propagate_recovery_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-observer-recovery-failure-") as tmp:
        root = Path(tmp)
        script = f"""
source {shlex.quote(str(COMMON))}
CRITICAL_FLOWS_LOG_OBSERVER_STORE=target
LOG_OBSERVER_DRIVER={shlex.quote(str(COMMON))}
critical_flows_log_observer_action() {{ return 3; }}
critical_flows_log_observer_recover
[ "$?" -ne 0 ] || exit 51

CRITICAL_FLOWS_LOG_OBSERVER_PID=999999
critical_flows_log_observer_job_is_owned() {{ return 1; }}
critical_flows_log_observer_recover() {{ return 1; }}
critical_flows_log_observer_clear_state() {{ return 0; }}
critical_flows_log_observer_cleanup
[ "$?" -ne 0 ] || exit 52

CRITICAL_FLOWS_LOG_OBSERVER_PID=999999
critical_flows_log_observer_action() {{ return 1; }}
critical_flows_log_observer_cleanup() {{ return 1; }}
critical_flows_log_observer_finish target
[ "$?" -eq 70 ] || exit 53

critical_flows_log_observer_finish() {{ return 70; }}
CRITICAL_FLOWS_RUN_CONTEXT_KEY={TEST_RUN_CONTEXT_KEY}
CRITICAL_FLOWS_FLOW_ID=MD-03-winning-dispute
CRITICAL_FLOWS_LOG_PURPOSE=clean-debug-log
assert_log_clean target
[ "$?" -eq 70 ] || exit 54
"""
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            env={**os.environ, "TMPDIR": str(root)},
            text=True,
            capture_output=True,
            check=False,
            timeout=5,
        )

        assert result.returncode == 0, result.stdout + result.stderr


def test_common_observer_start_requires_tmpdir_without_tmp_fallback() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-observer-tmpdir-") as tmp:
        root = Path(tmp)
        fake_bin = root / "bin"
        fake_bin.mkdir()
        probe = root / "mktemp-invoked"
        write_executable(
            fake_bin / "mktemp",
            f"""#!/usr/bin/env bash
printf '%s\n' "$*" > {shlex.quote(str(probe))}
exit 1
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
unset TMPDIR
PATH={shlex.quote(str(fake_bin))}:$PATH
critical_flows_log_observer_start target
rc=$?
[ "$rc" -eq 1 ] || exit 51
[ ! -e {shlex.quote(str(probe))} ] || exit 52
"""
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr


def test_log_observer_maximum_lifetime_restores_and_leaves_no_orphans() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-lifetime-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case, maximum_seconds="1")
        try:
            wait_log_observer_record(process, "ready")
            blocked, _ = wait_log_observer_record(process, "blocked", timeout=3)
            assert blocked["blocker_code"] == "observer_lifetime_exceeded"
            assert process.wait(timeout=2) != 0
            assert_log_observer_cleaned(case)
        finally:
            stop_observer_process(process)


def test_log_observer_rejects_unauthenticated_sentinel_then_restores() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-sentinel-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case, maximum_seconds="1")
        try:
            wait_log_observer_record(process, "ready")
            write_observer_fifo(
                case["debug_log"],
                b"[woopayments-critical-flows-log-observer-stop] hmac-sha256:"
                + b"0" * 64
                + b"\n",
            )
            blocked, _ = wait_log_observer_record(process, "blocked", timeout=3)
            assert blocked["blocker_code"] == "sentinel_timeout"
            assert process.wait(timeout=2) != 0
            assert_log_observer_cleaned(case)
        finally:
            stop_observer_process(process)


def run_log_observer_signal_restoration(signum: int) -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-signal-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case, maximum_seconds="5")
        try:
            wait_log_observer_record(process, "ready")
            proxied = b"write-before-signal\n"
            write_observer_fifo(case["debug_log"], proxied)
            wait_log_observer_record(process, "line", category="other")
            process.send_signal(signum)
            blocked, _ = wait_log_observer_record(process, "blocked")
            assert blocked["blocker_code"] == "observer_interrupted"
            assert process.wait(timeout=2) != 0
            assert_log_observer_cleaned(case, proxied)
        finally:
            stop_observer_process(process)


def test_log_observer_int_handler_restores_regular_log() -> None:
    run_log_observer_signal_restoration(signal.SIGINT)


def test_log_observer_term_handler_restores_regular_log() -> None:
    run_log_observer_signal_restoration(signal.SIGTERM)


def run_log_observer_snapshot_replacement_attack(*, safe_append: bool) -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-replace-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case, maximum_seconds="1")
        warning = b"PHP Warning: must survive snapshot restoration\n"
        try:
            wait_log_observer_record(process, "ready")
            assert stat.S_ISFIFO(case["debug_log"].stat().st_mode)
            write_observer_fifo(case["debug_log"], warning)
            observed, encoded = wait_log_observer_record(process, "line", category="warning")
            assert observed["fingerprint"] == "sha256:" + hashlib.sha256(
                warning.rstrip(b"\r\n")
            ).hexdigest()
            assert warning.decode().strip() not in "".join(encoded)
            artifacts = log_forwarder_artifact_paths(case)
            backing_stat = case["backing_path"].stat()
            backing_bytes = case["backing_path"].read_bytes()
            assert backing_bytes == case["original"] + warning
            case["debug_log"].unlink()
            replacement = case["original"]
            if safe_append:
                replacement += b"ordinary safe line after restoration\n"
            case["debug_log"].write_bytes(replacement)
            replacement_stat = case["debug_log"].stat()
            blocked, _ = wait_log_observer_record(process, "blocked", timeout=3)
            assert blocked["blocker_code"] in {
                "observer_path_replaced",
                "sentinel_timeout",
            }
            assert process.wait(timeout=2) != 0
            preserved_replacement = case["debug_log"].stat()
            assert (
                preserved_replacement.st_dev,
                preserved_replacement.st_ino,
            ) == (replacement_stat.st_dev, replacement_stat.st_ino)
            assert case["debug_log"].read_bytes() == replacement
            preserved_backing = case["backing_path"].stat()
            assert (preserved_backing.st_dev, preserved_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            assert case["backing_path"].read_bytes() == backing_bytes
            assert all(path.is_file() for path in artifacts.values())
        finally:
            stop_observer_process(process)


def test_log_observer_blocks_exact_post_readiness_snapshot_restore() -> None:
    run_log_observer_snapshot_replacement_attack(safe_append=False)


def test_log_observer_blocks_snapshot_restore_followed_by_safe_append() -> None:
    run_log_observer_snapshot_replacement_attack(safe_append=True)


def test_log_observer_blocked_terminal_preserves_fifo_when_backing_name_disappears() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-backing-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case)
        proxied = b"write-before-backing-disappears\n"
        try:
            wait_log_observer_record(process, "ready")
            fifo_stat = case["debug_log"].lstat()
            artifacts = log_forwarder_artifact_paths(case)
            assert case["backing_path"].is_file()
            case["backing_path"].unlink()
            write_observer_fifo(case["debug_log"], proxied)
            wait_log_observer_record(process, "line", category="other")
            write_observer_fifo(
                case["debug_log"],
                observer_terminal_line(case),
            )
            blocked, _ = wait_log_observer_record(process, "blocked")
            assert blocked["blocker_code"] == "backing_path_changed"
            assert process.wait(timeout=2) != 0
            preserved_fifo = case["debug_log"].lstat()
            assert stat.S_ISFIFO(preserved_fifo.st_mode)
            assert (preserved_fifo.st_dev, preserved_fifo.st_ino) == (
                fifo_stat.st_dev,
                fifo_stat.st_ino,
            )
            assert not case["backing_path"].exists()
            assert all(path.is_file() for path in artifacts.values())
        finally:
            stop_observer_process(process)


def test_log_observer_recovery_mode_restores_after_forced_crash() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-observer-crash-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        process = launch_log_observer(case, maximum_seconds="5")
        proxied = b"write-before-forced-crash\n"
        try:
            wait_log_observer_record(process, "ready")
            write_observer_fifo(case["debug_log"], proxied)
            wait_log_observer_record(process, "line", category="other")
            process.kill()
            assert process.wait(timeout=2) != 0
            assert stat.S_ISFIFO(case["debug_log"].stat().st_mode)
            recovery = launch_log_observer(case, action="recover")
            try:
                recovered, _ = wait_log_observer_record(recovery, "recovered")
                assert recovered["status"] == "blocked"
                assert recovery.wait(timeout=2) == 3
            finally:
                stop_observer_process(recovery)
            assert_log_observer_cleaned(case, proxied)
        finally:
            stop_observer_process(process)


def test_log_forwarder_rejects_unauthenticated_complete_before_restore() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-journal-terminal-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        warning = b"PHP Warning: forged journal terminal must not erase this line\n"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            assert [record["kind"] for record in validated_log_forwarder_journal(case)] == [
                "ready"
            ]
            os.kill(child_pid, signal.SIGSTOP)
            write_observer_fifo(case["debug_log"], warning)

            journal_path = log_forwarder_artifact_paths(case)["journal"]
            forged_terminal = b'{"kind":"complete"}\n'
            with journal_path.open("ab", buffering=0) as stream:
                assert stream.write(forged_terminal) == len(forged_terminal)
                stream.flush()
                os.fsync(stream.fileno())
            os.kill(child_pid, signal.SIGKILL)

            try:
                coordinator_exit: int | None = coordinator.wait(timeout=1)
            except subprocess.TimeoutExpired:
                coordinator_exit = None
            assert coordinator_exit != 0
            assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
            assert case["backing_path"].is_file()
            durable_bytes = case["backing_path"].read_bytes()
            assert warning in durable_bytes or fifo_bytes_available(
                case["debug_log"]
            ) >= len(warning)
            assert all(
                path.is_file() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_child_sigkill_preserves_consumed_non_line_bytes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-consumed-fragment-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        fragment = b"PHP Warning: child-consumed fragment without a line terminator"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            write_observer_fifo(case["debug_log"], fragment)
            wait_fifo_bytes_available(case["debug_log"], 0)
            os.kill(child_pid, signal.SIGKILL)
            wait_process_absent(child_pid)

            stdout, stderr = coordinator.communicate(timeout=3)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"

            assert case["debug_log"].read_bytes() == case["original"] + fragment
            assert_log_observer_cleaned(case, fragment)
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_recovery_preserves_foreign_fifo_in_final_unlink_window() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-final-inode-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        coordinator_stopped = False
        parked_fifo = case["debug_log"].with_name("authenticated-final-window.fifo")
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            os.kill(child_pid, signal.SIGKILL)

            original_fifo_stat = case["debug_log"].lstat()
            case["debug_log"].rename(parked_fifo)
            os.mkfifo(case["debug_log"], 0o600)
            foreign_stat = case["debug_log"].lstat()
            backing_stat = case["backing_path"].stat()
            backing_bytes = case["backing_path"].read_bytes()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            blocked, _ = wait_log_observer_record(coordinator, "blocked")
            assert blocked["blocker_code"] == "observer_path_replaced"
            assert coordinator.wait(timeout=3) == 3
            wait_process_absent(child_pid)

            preserved_foreign = case["debug_log"].lstat()
            assert stat.S_ISFIFO(preserved_foreign.st_mode)
            assert (preserved_foreign.st_dev, preserved_foreign.st_ino) == (
                foreign_stat.st_dev,
                foreign_stat.st_ino,
            )
            authentic_fifo = parked_fifo.lstat()
            assert (authentic_fifo.st_dev, authentic_fifo.st_ino) == (
                original_fifo_stat.st_dev,
                original_fifo_stat.st_ino,
            )
            preserved_backing = case["backing_path"].stat()
            assert (preserved_backing.st_dev, preserved_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            assert case["backing_path"].read_bytes() == backing_bytes
            assert all(
                path.is_file() for path in log_forwarder_artifact_paths(case).values()
            )
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_emergency_drain_filters_split_authenticated_sentinel() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-split-sentinel-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        warning = b"PHP Warning: emergency split sentinel warning must survive\n"
        sentinel = observer_terminal_line(case)
        split_at = len(sentinel) // 2
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            first_chunk = warning + sentinel[:split_at]
            write_observer_fifo(case["debug_log"], first_chunk)
            wait_fifo_bytes_available(case["debug_log"], 0)
            backing_deadline = time.monotonic() + 2
            while (
                time.monotonic() < backing_deadline
                and not case["backing_path"].read_bytes().endswith(first_chunk)
            ):
                time.sleep(0.01)
            assert case["backing_path"].read_bytes().endswith(first_chunk)
            os.kill(child_pid, signal.SIGSTOP)
            write_observer_fifo(case["debug_log"], sentinel[split_at:])
            os.kill(child_pid, signal.SIGKILL)
            wait_process_absent(child_pid)

            stdout, stderr = coordinator.communicate(timeout=3)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"

            assert case["debug_log"].read_bytes() == case["original"] + warning
            assert sentinel not in case["debug_log"].read_bytes()
            assert_log_observer_cleaned(case, warning)
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_signed_terminal_preserves_foreign_fifo_before_ordinary_restore() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-ordinary-inode-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        coordinator_stopped = False
        parked_fifo = case["debug_log"].with_name("authenticated-observer.fifo")
        try:
            wait_log_observer_record(coordinator, "ready")
            artifacts = log_forwarder_artifact_paths(case)
            original_fifo_stat = case["debug_log"].lstat()
            backing_stat = case["backing_path"].stat()
            backing_bytes = case["backing_path"].read_bytes()

            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            terminal, _ = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "complete"

            case["debug_log"].rename(parked_fifo)
            os.mkfifo(case["debug_log"], 0o600)
            foreign_fifo_stat = case["debug_log"].lstat()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            blocked, _ = wait_log_observer_record(coordinator, "blocked")
            assert blocked["blocker_code"] == "observer_path_replaced"
            assert coordinator.wait(timeout=3) == 3

            current_foreign = case["debug_log"].lstat()
            assert stat.S_ISFIFO(current_foreign.st_mode)
            assert (current_foreign.st_dev, current_foreign.st_ino) == (
                foreign_fifo_stat.st_dev,
                foreign_fifo_stat.st_ino,
            )
            current_authenticated = parked_fifo.lstat()
            assert stat.S_ISFIFO(current_authenticated.st_mode)
            assert (current_authenticated.st_dev, current_authenticated.st_ino) == (
                original_fifo_stat.st_dev,
                original_fifo_stat.st_ino,
            )
            current_backing = case["backing_path"].stat()
            assert (current_backing.st_dev, current_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            assert case["backing_path"].read_bytes() == backing_bytes
            assert all(path.is_file() for path in artifacts.values())
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)


def test_log_forwarder_signed_terminal_preserves_foreign_backing_before_ordinary_restore() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-ordinary-backing-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        coordinator_stopped = False
        parked_backing = case["backing_path"].with_name("authenticated-backing.parked")
        try:
            wait_log_observer_record(coordinator, "ready")
            artifacts = log_forwarder_artifact_paths(case)
            fifo_stat = case["debug_log"].lstat()
            original_backing_stat = case["backing_path"].stat()

            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            terminal, _ = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "complete"

            backing_bytes = case["backing_path"].read_bytes()
            backing_mode = stat.S_IMODE(case["backing_path"].stat().st_mode)
            case["backing_path"].rename(parked_backing)
            case["backing_path"].write_bytes(backing_bytes)
            case["backing_path"].chmod(backing_mode)
            foreign_backing_stat = case["backing_path"].stat()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            blocked, _ = wait_log_observer_record(coordinator, "blocked")
            assert blocked["blocker_code"] == "backing_path_changed"
            assert coordinator.wait(timeout=3) == 3

            current_fifo = case["debug_log"].lstat()
            assert stat.S_ISFIFO(current_fifo.st_mode)
            assert (current_fifo.st_dev, current_fifo.st_ino) == (
                fifo_stat.st_dev,
                fifo_stat.st_ino,
            )
            current_foreign = case["backing_path"].stat()
            assert (current_foreign.st_dev, current_foreign.st_ino) == (
                foreign_backing_stat.st_dev,
                foreign_backing_stat.st_ino,
            )
            current_authenticated = parked_backing.stat()
            assert (
                current_authenticated.st_dev,
                current_authenticated.st_ino,
            ) == (original_backing_stat.st_dev, original_backing_stat.st_ino)
            assert all(path.is_file() for path in artifacts.values())
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)


def run_log_forwarder_signed_terminal_artifact_substitution_attack(kind: str) -> None:
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-log-{kind}-identity-"
    ) as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        coordinator_stopped = False
        artifact = log_forwarder_artifact_paths(case)[kind]
        parked_artifact = artifact.with_name(f"authenticated-{kind}.parked")
        try:
            wait_log_observer_record(coordinator, "ready")
            original_artifact_stat = artifact.stat()
            fifo_stat = case["debug_log"].lstat()
            backing_stat = case["backing_path"].stat()
            backing_bytes = case["backing_path"].read_bytes()

            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            terminal, _ = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "complete"

            copied_bytes = artifact.read_bytes()
            artifact.rename(parked_artifact)
            artifact.write_bytes(copied_bytes)
            artifact.chmod(0o600)
            foreign_artifact_stat = artifact.stat()
            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False

            blocked, _ = wait_log_observer_record(coordinator, "blocked")
            assert blocked["blocker_code"] == "forwarder_artifact_changed"
            assert coordinator.wait(timeout=3) == 3

            current_foreign = artifact.stat()
            assert (current_foreign.st_dev, current_foreign.st_ino) == (
                foreign_artifact_stat.st_dev,
                foreign_artifact_stat.st_ino,
            )
            current_authenticated = parked_artifact.stat()
            assert (
                current_authenticated.st_dev,
                current_authenticated.st_ino,
            ) == (original_artifact_stat.st_dev, original_artifact_stat.st_ino)
            current_fifo = case["debug_log"].lstat()
            assert stat.S_ISFIFO(current_fifo.st_mode)
            assert (current_fifo.st_dev, current_fifo.st_ino) == (
                fifo_stat.st_dev,
                fifo_stat.st_ino,
            )
            current_backing = case["backing_path"].stat()
            assert (current_backing.st_dev, current_backing.st_ino) == (
                backing_stat.st_dev,
                backing_stat.st_ino,
            )
            assert case["backing_path"].read_bytes() == backing_bytes
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)


def test_log_forwarder_signed_terminal_preserves_substituted_lease_inode() -> None:
    run_log_forwarder_signed_terminal_artifact_substitution_attack("lease")


def test_log_forwarder_signed_terminal_preserves_substituted_journal_inode() -> None:
    run_log_forwarder_signed_terminal_artifact_substitution_attack("journal")


def test_log_forwarder_signed_terminal_preserves_substituted_control_inode() -> None:
    run_log_forwarder_signed_terminal_artifact_substitution_attack("control")


def run_log_forwarder_blocked_terminal_substitution_attack(
    kinds: tuple[str, ...],
) -> None:
    valid_kinds = {"fifo", "backing", "lease", "journal", "control"}
    assert kinds and len(kinds) == len(set(kinds))
    assert set(kinds) <= valid_kinds
    prefix = "-".join(kinds)
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-log-blocked-{prefix}-"
    ) as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        coordinator_stopped = False
        parked: dict[str, Path] = {}
        original_stats: dict[str, os.stat_result] = {}
        foreign_stats: dict[str, os.stat_result] = {}
        try:
            wait_log_observer_record(coordinator, "ready")
            artifacts = log_forwarder_artifact_paths(case)
            paths = {
                "fifo": case["debug_log"],
                "backing": case["backing_path"],
                **artifacts,
            }
            for kind, path in paths.items():
                original_stats[kind] = path.lstat()

            source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
            match = re.search(
                r"private const MAX_PARSER_LINE\s*=\s*([0-9]+);", source
            )
            assert match is not None
            parser_cap = int(match.group(1))
            payload = b"blocked-terminal-parser-line:" + (b"x" * (parser_cap + 1))

            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True
            write_observer_fifo_fully(case["debug_log"], payload)
            write_observer_fifo(
                case["debug_log"],
                observer_terminal_line(case),
            )
            terminal, _ = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "blocked"
            assert terminal["blocker_code"] == "observer_line_too_long"
            backing_bytes = case["backing_path"].read_bytes()
            assert backing_bytes == case["original"] + payload

            for kind in kinds:
                path = paths[kind]
                parked_path = path.with_name(f"authenticated-{kind}.blocked.parked")
                copied_bytes = b"" if kind == "fifo" else path.read_bytes()
                copied_mode = stat.S_IMODE(path.lstat().st_mode)
                path.rename(parked_path)
                if kind == "fifo":
                    os.mkfifo(path, copied_mode)
                else:
                    path.write_bytes(copied_bytes)
                    path.chmod(copied_mode)
                parked[kind] = parked_path
                foreign_stats[kind] = path.lstat()

            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False
            blocked, _ = wait_log_observer_record(coordinator, "blocked")
            if set(kinds) & {"lease", "journal", "control"}:
                expected_blocker = "forwarder_artifact_changed"
            elif "fifo" in kinds:
                expected_blocker = "observer_path_replaced"
            else:
                expected_blocker = "backing_path_changed"
            assert blocked["blocker_code"] == expected_blocker
            assert coordinator.wait(timeout=3) == 3

            for kind, path in paths.items():
                current_stat = path.lstat()
                if kind in kinds:
                    expected_foreign = foreign_stats[kind]
                    assert (current_stat.st_dev, current_stat.st_ino) == (
                        expected_foreign.st_dev,
                        expected_foreign.st_ino,
                    )
                    parked_stat = parked[kind].lstat()
                    expected_original = original_stats[kind]
                    assert (parked_stat.st_dev, parked_stat.st_ino) == (
                        expected_original.st_dev,
                        expected_original.st_ino,
                    )
                else:
                    expected_original = original_stats[kind]
                    assert (current_stat.st_dev, current_stat.st_ino) == (
                        expected_original.st_dev,
                        expected_original.st_ino,
                    )
            assert case["backing_path"].read_bytes() == backing_bytes
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)


def test_log_forwarder_blocked_terminal_preserves_foreign_fifo_before_ordinary_restore() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(("fifo",))


def test_log_forwarder_blocked_terminal_preserves_foreign_backing_before_ordinary_restore() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(("backing",))


def test_log_forwarder_blocked_terminal_preserves_substituted_lease_inode() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(("lease",))


def test_log_forwarder_blocked_terminal_preserves_substituted_journal_inode() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(("journal",))


def test_log_forwarder_blocked_terminal_preserves_substituted_control_inode() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(("control",))


def test_log_forwarder_blocked_terminal_preserves_simultaneous_substitutions() -> None:
    run_log_forwarder_blocked_terminal_substitution_attack(
        ("fifo", "backing", "lease", "journal", "control")
    )


def test_log_forwarder_live_terminal_always_uses_strict_identity_restore() -> None:
    source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
    coordinate_start = source.index("private static function coordinate_forwarder(): void")
    coordinate_end = source.index("\n\t/**", coordinate_start)
    coordinate_source = source[coordinate_start:coordinate_end]
    assert coordinate_source.count("self::restore_all( true )") == 1
    assert "self::restore_all()" not in coordinate_source
    assert "self::restore_all( false )" not in coordinate_source
    assert "$strict_identity" not in coordinate_source

    restore_calls = re.findall(r"self::restore_all\(([^)]*)\)", source)
    assert restore_calls.count("") == 2
    assert restore_calls.count(" true ") == 3
    assert len(restore_calls) == 5
    start_begin = source.index("private static function start_forwarder_foundation(): bool")
    start_end = source.index("\n\t/**", start_begin)
    start_source = source[start_begin:start_end]
    assert start_source.count("return false;") == 6
    assert start_source.count("self::cleanup_failed_forwarder_start") == 6
    assert "self::restore_all()" not in start_source
    cleanup_begin = source.index(
        "private static function cleanup_failed_forwarder_start("
    )
    cleanup_end = source.index("\n\t/**", cleanup_begin)
    cleanup_source = source[cleanup_begin:cleanup_end]
    assert "self::abort_and_reap_startup_child" in cleanup_source
    assert "self::drain_pre_ready_bytes()" in cleanup_source
    assert "self::complete_startup_identities_match()" in cleanup_source
    assert "self::restore_all( true )" in cleanup_source
    assert "self::restore_all()" not in cleanup_source
    shutdown_start = source.index("public static function shutdown_restore(): void")
    shutdown_end = source.index("\n\t/**", shutdown_start)
    shutdown_source = source[shutdown_start:shutdown_end]
    assert "! self::$durable_forwarder_active" in shutdown_source
    assert "self::restore_all()" in shutdown_source


def test_log_forwarder_long_unterminated_line_is_bounded_blocked_and_lossless() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-parser-bound-") as tmp:
        source = LOG_OBSERVER_DRIVER.read_text(encoding="utf-8")
        match = re.search(r"private const MAX_PARSER_LINE\s*=\s*([0-9]+);", source)
        parser_cap = int(match.group(1)) if match is not None else 65536
        assert parser_cap == 65536
        payload = b"unterminated-parser-line:" + (b"x" * (parser_cap * 3 + 1))

        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        coordinator_stopped = False
        try:
            ready, ready_encoded = wait_log_observer_record(coordinator, "ready")
            assert ready["status"] == "pass"
            os.kill(coordinator.pid, signal.SIGSTOP)
            coordinator_stopped = True

            write_observer_fifo_fully(case["debug_log"], payload)
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            terminal, records = wait_log_forwarder_terminal(case)
            assert terminal["kind"] == "blocked"
            assert terminal["blocker_code"] == "observer_line_too_long"
            assert [record["kind"] for record in records][-2:] == ["line", "blocked"]

            os.kill(coordinator.pid, signal.SIGCONT)
            coordinator_stopped = False
            blocked, blocked_encoded = wait_log_observer_record(coordinator, "blocked")
            assert blocked["blocker_code"] == "observer_line_too_long"
            assert coordinator.wait(timeout=3) == 3
            assert case["debug_log"].read_bytes() == case["original"] + payload
            assert payload[:64].decode() not in "".join(
                ready_encoded + blocked_encoded
            )
            assert match is not None
            assert_log_observer_cleaned(case, payload)
        finally:
            if coordinator_stopped and coordinator.poll() is None:
                os.kill(coordinator.pid, signal.SIGCONT)
            stop_observer_process(coordinator)


def test_log_forwarder_recovery_rejects_coherent_lease_downgrade_partial_and_mismatch() -> None:
    def downgrade(lease: dict) -> None:
        lease["schema"] = "woopayments_debug_log_forwarder_lease.v1"

    def remove_control_identity(lease: dict) -> None:
        lease["artifacts"].pop("control")

    def mismatch_journal_identity(lease: dict) -> None:
        lease["artifacts"]["journal"]["ino"] += 1

    for name, mutate in (
        ("downgrade", downgrade),
        ("partial", remove_control_identity),
        ("mismatch", mismatch_journal_identity),
    ):
        with tempfile.TemporaryDirectory(
            prefix=f"critical-flows-log-lease-{name}-"
        ) as tmp:
            case = prepare_log_observer_case(Path(tmp))
            coordinator = launch_log_observer(case, maximum_seconds="5")
            recovery: subprocess.Popen[str] | None = None
            child_pid: int | None = None
            try:
                wait_log_observer_record(coordinator, "ready")
                lease_path, lease = wait_log_forwarder_lease(case)
                assert lease["schema"] == "woopayments_debug_log_forwarder_lease.v2"
                child_pid = forwarder_child_pid(lease)
                coordinator.kill()
                assert coordinator.wait(timeout=2) != 0

                mutated = json.loads(json.dumps(lease))
                mutate(mutated)
                write_signed_log_forwarder_lease(lease_path, mutated)
                recovery = launch_log_observer(case, action="recover")
                recovered, _ = wait_log_observer_record(recovery, "recovered")
                assert recovered["status"] == "blocked"
                assert recovered["blocker_code"] == "observer_recovery_failed"
                assert recovery.wait(timeout=3) == 3
                assert stat.S_ISFIFO(case["debug_log"].lstat().st_mode)
                assert case["backing_path"].is_file()
                assert all(
                    path.is_file()
                    for path in log_forwarder_artifact_paths(case).values()
                )
            finally:
                if recovery is not None:
                    stop_observer_process(recovery)
                stop_observer_process(coordinator)
                terminate_forwarder_for_test(child_pid)


def test_log_forwarder_valid_authenticated_terminal_journal_restores() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-valid-journal-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        warning = b"PHP Warning: valid journal control preserves this warning\n"
        try:
            wait_log_observer_record(coordinator, "ready")
            _, lease = wait_log_forwarder_lease(case)
            assert lease["schema"] == "woopayments_debug_log_forwarder_lease.v2"
            assert tuple(lease["artifacts"]) == ("lease", "journal", "control")
            for kind, identity in lease["artifacts"].items():
                artifact_stat = log_forwarder_artifact_paths(case)[kind].stat()
                assert identity == {
                    "kind": kind,
                    "dev": artifact_stat.st_dev,
                    "ino": artifact_stat.st_ino,
                    "mode": 0o600,
                }
            assert forwarder_child_pid(lease) > 1
            assert validated_log_forwarder_journal(case)[-1]["kind"] == "ready"

            write_observer_fifo(case["debug_log"], warning)
            wait_log_observer_record(coordinator, "line", category="warning")
            records = validated_log_forwarder_journal(case)
            assert [record["kind"] for record in records] == ["ready", "line"]
            write_observer_fifo(case["debug_log"], observer_terminal_line(case))
            complete, _ = wait_log_observer_record(coordinator, "complete")
            assert complete["status"] == "pass"
            assert coordinator.wait(timeout=3) == 0

            assert case["debug_log"].read_bytes() == case["original"] + warning
            assert_log_observer_cleaned(case, warning)
        finally:
            stop_observer_process(coordinator)


def test_log_forwarder_survives_coordinator_sigkill_before_fifo_read() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-forwarder-parent-kill-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        warning = b"PHP Warning: accepted before coordinator SIGKILL must survive\n"
        try:
            wait_log_observer_record(coordinator, "ready")
            lease_path, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            assert lease_path.stat().st_mode & 0o077 == 0
            os.kill(child_pid, signal.SIGSTOP)
            write_observer_fifo(case["debug_log"], warning)
            coordinator.kill()
            assert coordinator.wait(timeout=2) != 0
            os.kill(child_pid, signal.SIGCONT)

            recovery = launch_log_observer(case, action="recover")
            try:
                recovered, _ = wait_log_observer_record(recovery, "recovered")
                assert recovered["status"] == "blocked"
                assert recovery.wait(timeout=3) == 3
            finally:
                stop_observer_process(recovery)

            wait_process_absent(child_pid)
            assert_log_observer_cleaned(case, warning)
            assert b"[woopayments-critical-flows-log-observer-stop]" not in case[
                "debug_log"
            ].read_bytes()
        finally:
            if child_pid is not None and process_exists(child_pid):
                try:
                    os.kill(child_pid, signal.SIGCONT)
                except ProcessLookupError:
                    pass
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_log_forwarder_child_sigkill_emergency_recovery_preserves_fifo_bytes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-forwarder-child-kill-") as tmp:
        case = prepare_log_observer_case(Path(tmp))
        coordinator = launch_log_observer(case, maximum_seconds="5")
        child_pid: int | None = None
        warning = b"PHP Warning: kernel-buffered before forwarder SIGKILL must survive\n"
        try:
            wait_log_observer_record(coordinator, "ready")
            lease_path, lease = wait_log_forwarder_lease(case)
            child_pid = forwarder_child_pid(lease)
            assert lease_path.stat().st_mode & 0o077 == 0
            os.kill(child_pid, signal.SIGSTOP)
            write_observer_fifo(case["debug_log"], warning)
            os.kill(child_pid, signal.SIGKILL)
            wait_process_absent(child_pid)

            stdout, stderr = coordinator.communicate(timeout=3)
            records = [json.loads(line) for line in stdout.splitlines() if line]
            assert coordinator.returncode == 3, stdout + stderr
            assert records[-1]["blocker_code"] == "observer_forced_recovery"

            assert_log_observer_cleaned(case, warning)
            assert b"[woopayments-critical-flows-log-observer-stop]" not in case[
                "debug_log"
            ].read_bytes()
        finally:
            stop_observer_process(coordinator)
            terminate_forwarder_for_test(child_pid)


def test_common_log_prefix_hash_distinguishes_invalid_utf8_bytes() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-binary-prefix-") as tmp:
        root = Path(tmp)
        wrapper = root / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")
        debug_log = root / "debug.log"
        debug_log.write_bytes(b"one\n\xff original invalid byte\nthree\nfour\n")
        result = subprocess.run(
            ["php", str(wrapper), str(debug_log), "invalid_utf8_rewrite"],
            cwd=REPO,
            env={
                **os.environ,
                "CRITICAL_FLOWS_RUN_STAMP": "20260716T160000Z-30303",
            },
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(result.stdout)
        assert payload["status"] == "blocked"
        assert payload["blocker_code"] == "log_prefix_changed"


def test_common_log_marker_blocks_when_canary_cannot_be_appended() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-read-only-") as tmp:
        root = Path(tmp)
        wrapper = root / "common-log-producer.php"
        wrapper.write_text(common_log_producer_php_source(), encoding="utf-8")
        debug_log = root / "debug.log"
        debug_log.write_text("one\ntwo\n", encoding="utf-8")
        debug_log.chmod(0o444)
        try:
            result = subprocess.run(
                ["php", str(wrapper), str(debug_log), "ordinary_append"],
                cwd=REPO,
                env={
                    **os.environ,
                    "CRITICAL_FLOWS_RUN_STAMP": "20260716T160000Z-30303",
                },
                text=True,
                capture_output=True,
                check=False,
            )
        finally:
            debug_log.chmod(0o644)
        assert result.returncode != 0


def test_common_log_marker_does_not_forward_raw_wp_cli_failure_output() -> None:
    secret = "Authorization: Bearer sk_test_marker customer@example.test"
    result = run_log_clean_marker(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{secret}' >&2
exit 2
"""
    )

    assert result.returncode == 3
    assert result.stdout.strip() == "BLOCKED log-clean marker for target: marker_command_failed"
    assert secret not in result.stdout
    assert secret not in result.stderr


def test_common_log_marker_injects_exact_run_stamp_into_wp_eval() -> None:
    run_stamp = "20260716T160000Z-30303"
    scan = common_log_scan_v5()
    with tempfile.TemporaryDirectory(prefix="critical-flows-log-marker-stamp-") as tmp:
        root = Path(tmp)
        fake_wp = root / "fake-wp.sh"
        call_log = root / "eval-source.txt"
        write_executable(
            fake_wp,
            fake_authenticated_log_observer_source()
            + """#!/usr/bin/env bash
body="$(cat)"
printf '%s' "$body" > "$FAKE_MARKER_EVAL_SOURCE"
printf '%s\n' '{"status":"pass"}'
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
TARGET_WP_COMMAND={shlex.quote(str(fake_wp))}
CRITICAL_FLOWS_RUN_STAMP={shlex.quote(run_stamp)}
CRITICAL_FLOWS_RUN_CONTEXT_KEY={TEST_RUN_CONTEXT_KEY}
CRITICAL_FLOWS_FLOW_ID={scan['flow_id']}
CRITICAL_FLOWS_LOG_PURPOSE={scan['purpose']}
CRITICAL_FLOWS_RUN_CONTEXT_BINDING={scan['origin_binding']}
FAKE_OBSERVER_STORE={scan['store']}
FAKE_OBSERVER_FLOW_ID={scan['flow_id']}
FAKE_OBSERVER_PURPOSE={scan['purpose']}
FAKE_OBSERVER_MARKER_CREATED_AT={scan['marker_created_at']}
FAKE_OBSERVER_PATH={scan['observations'][0]['path']}
FAKE_OBSERVER_PATH_ID={scan['observations'][0]['path_id']}
FAKE_MARKER_EVAL_SOURCE={shlex.quote(str(call_log))}
EVIDENCE_DIR={shlex.quote(str(root))}
TMPDIR={shlex.quote(str(root))}
export CRITICAL_FLOWS_RUN_CONTEXT_KEY CRITICAL_FLOWS_RUN_CONTEXT_BINDING
export CRITICAL_FLOWS_FLOW_ID CRITICAL_FLOWS_LOG_PURPOSE
export FAKE_OBSERVER_STORE FAKE_OBSERVER_FLOW_ID FAKE_OBSERVER_PURPOSE
export FAKE_OBSERVER_MARKER_CREATED_AT FAKE_OBSERVER_PATH FAKE_OBSERVER_PATH_ID
export FAKE_MARKER_EVAL_SOURCE EVIDENCE_DIR TMPDIR
mark_log_clean_start target
"""
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        source = call_log.read_text(encoding="utf-8")
        assert f"$run_stamp = '{run_stamp}';" in source
        assert 'putenv( "CRITICAL_FLOWS_RUN_CONTEXT_KEY=' in source


def test_common_log_validator_requires_authenticated_v5_observations() -> None:
    current_payload = common_log_scan_v5()
    current = json.dumps(current_payload, separators=(",", ":"))
    passed = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{current}'
"""
    )
    assert passed.returncode == 0, passed.stdout + passed.stderr

    changed = json.dumps(
        common_log_scan_v5(
            observed_prefix_fingerprint="sha256:"
            + hashlib.sha256(b"rewritten-prefix").hexdigest(),
        ),
        separators=(",", ":"),
    )
    blocked = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{changed}'
"""
    )
    assert blocked.returncode == 3, blocked.stdout + blocked.stderr
    assert "BLOCKED log-clean check for target" in blocked.stdout

    changed_metadata_payload = json.loads(json.dumps(current_payload))
    changed_metadata_payload["observations"][0]["observed_mode"] = 0o600
    changed_metadata = json.dumps(changed_metadata_payload, separators=(",", ":"))
    metadata_blocked = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{changed_metadata}'
"""
    )
    assert metadata_blocked.returncode == 3, (
        metadata_blocked.stdout + metadata_blocked.stderr
    )
    assert "BLOCKED log-clean check for target" in metadata_blocked.stdout

    wrong_key = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{current}'
""",
        context_key="22" * 32,
        origin_binding=current_payload["origin_binding"],
    )
    assert wrong_key.returncode == 3, wrong_key.stdout + wrong_key.stderr

    changed_nonce_payload = {**current_payload, "origin_nonce": "00000000-0000-4000-8000-000000000999"}
    changed_nonce = json.dumps(changed_nonce_payload, separators=(",", ":"))
    relabeled_origin = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{changed_nonce}'
""",
        origin_binding=current_payload["origin_binding"],
    )
    assert relabeled_origin.returncode == 3, relabeled_origin.stdout + relabeled_origin.stderr

    downgraded = json.dumps(canary_log_scan_v4(), separators=(",", ":"))
    downgrade = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' '{downgraded}'
"""
    )
    assert downgrade.returncode == 3, downgrade.stdout + downgrade.stderr


def test_deterministic_runner_records_log_marker_before_scanning_logs() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-runner-") as tmp:
        evidence_dir = Path(tmp)
        flow_driver = evidence_dir / "fake-flow-drive.sh"
        fake_wp = evidence_dir / "fake-wp.sh"
        call_log = evidence_dir / "fake-wp-calls.log"
        marker_file = evidence_dir / "marker-created"
        clean_log_scan = common_log_scan_v5(
            run_stamp=TEST_RUN_STAMP,
            flow_id="SC-01-card-checkout",
            purpose="clean-debug-log",
            marker_created_at=TEST_MARKER_CREATED_AT,
        )
        clean_log_json = json.dumps(clean_log_scan, separators=(",", ":"))
        dirty_log_scan = common_log_scan_v5(
            status="fail",
            run_stamp=TEST_RUN_STAMP,
            flow_id="SC-01-card-checkout",
            purpose="clean-debug-log",
            marker_created_at=TEST_MARKER_CREATED_AT,
            end_line_count=5,
            end_byte_count=160,
            matches=[safe_log_record(line=5, diagnostic="PHP Warning: stale warning")],
        )
        dirty_log_json = json.dumps(dirty_log_scan, separators=(",", ":"))

        write_executable(
            flow_driver,
            """#!/usr/bin/env bash
printf '%s\\n' '{"op":"charge","order_id":321,"charge_id":"ch_marker","intent_id":"pi_marker"}'
""",
        )
        write_executable(
            fake_wp,
            f"""#!/usr/bin/env bash
{authenticated_log_fake_prelude(clean_log_scan)}
if [ "$1" = "eval-file" ]; then
  body="$(cat)"
  printf '%s\n' "---CALL---" "$body" >> "$FAKE_WP_CALL_LOG"
  if [[ "$body" == "<?php"* && "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    touch "$FAKE_MARKER_FILE"
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == "<?php"* && "$body" == *"ignored_matches"* ]]; then
    if [ -f "$FAKE_MARKER_FILE" ]; then
      printf '%s\n' '{clean_log_json}'
    else
      printf '%s\n' '{dirty_log_json}'
    fi
    exit 0
  fi
fi
if [ "$1" = "wc" ] && [ "$2" = "shop_order" ] && [ "$3" = "get" ]; then
  printf '%s\\n' "processing"
  exit 0
fi
if [ "$1" = "post" ] && [ "$2" = "meta" ] && [ "$3" = "get" ]; then
  case "$5" in
    _intent_id) printf '%s\\n' "pi_marker"; exit 0 ;;
    _charge_id) printf '%s\\n' "ch_marker"; exit 0 ;;
  esac
fi
if [ "$1" = "eval" ]; then
  printf '%s\\n' "---CALL---" "$2" >> "$FAKE_WP_CALL_LOG"
  if [[ "$2" == *"store_identity_owner"* ]]; then
    printf '%s\\n' "store_identity_owner=native"
    printf '%s\\n' "store_identity_home=http://target.fake.test"
    exit 0
  fi
  if [[ "$2" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    touch "$FAKE_MARKER_FILE"
    printf '%s\\n' '{clean_log_json}'
    exit 0
  fi
  if [[ "$2" == *"get_status"* ]]; then
    printf '%s\\n' "order_status=processing"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_intent_id"* ]]; then
    printf '%s\\n' "order_meta_value=pi_marker"
    exit 0
  fi
  if [[ "$2" == *"wc_get_order"* && "$2" == *"_charge_id"* ]]; then
    printf '%s\\n' "order_meta_value=ch_marker"
    exit 0
  fi
  if [[ "$2" == *"debug.log"* ]]; then
    if [ -f "$FAKE_MARKER_FILE" ]; then
      printf '%s\\n' '{clean_log_json}'
    else
      printf '%s\\n' '{dirty_log_json}'
    fi
    exit 0
  fi
fi
printf 'unexpected fake wp call: %s\\n' "$*" >&2
exit 2
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "SC-01",
            evidence_dir=evidence_dir,
            extra_env={
                "SC01_FLOW_DRIVER": str(flow_driver),
                "TARGET_WP_COMMAND": str(fake_wp),
                "FAKE_WP_CALL_LOG": str(call_log),
                "FAKE_MARKER_FILE": str(marker_file),
            },
        )

        assert result.returncode == 0
        assert "deterministic verdict: PASS" in result.stdout
        calls = [
            chunk.strip()
            for chunk in call_log.read_text(encoding="utf-8").split("---CALL---")
            if chunk.strip()
        ]
        marker_call = next(
            index
            for index, call in enumerate(calls)
            if "woopayments_critical_flows_debug_log_marker" in call
            and "update_option" in call
        )
        scan_call = next(
            index
            for index, call in enumerate(calls)
            if "matches" in call and "debug.log" in call
        )
        assert marker_call < scan_call


def test_deterministic_runner_owns_observer_and_sourced_flow_in_one_subshell() -> None:
    source = RUNNER.read_text(encoding="utf-8")
    layer_d_start = source.index('echo "Layer D: running deterministic flow scripts')
    layer_d_end = source.index(
        'if [ "$LAYER" != "deterministic" ]', layer_d_start
    )
    layer_d = source[layer_d_start:layer_d_end]

    assert re.search(
        r"for s in \$\(stores\); do\s+\(\s+mark_log_clean_start \"\$s\""
        r".*?source \"\$f\"\s+\)\s+rc=\$\?",
        layer_d,
        flags=re.DOTALL,
    )
    assert 'bash "$f"' not in layer_d


def test_sourced_flow_exit_reaps_observer_before_next_flow_and_preserves_unrelated() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-sourced-flow-cleanup-") as tmp:
        root = Path(tmp)
        first_flow = root / "first-flow.sh"
        second_flow = root / "second-flow.sh"
        first_pid_file = root / "first-observer.pid"
        second_pid_file = root / "second-observer.pid"
        write_executable(first_flow, "#!/usr/bin/env bash\nexit 3\n")
        write_executable(
            second_flow,
            f"""#!/usr/bin/env bash
first_pid="$(cat {shlex.quote(str(first_pid_file))})"
if kill -0 "$first_pid" 2>/dev/null; then
  exit 61
fi
exit 0
""",
        )
        script = f"""
source {shlex.quote(str(COMMON))}
root={shlex.quote(str(root))}
sleep 30 &
unrelated_pid=$!
cleanup_unrelated() {{
  kill "$unrelated_pid" 2>/dev/null || true
  wait "$unrelated_pid" 2>/dev/null || true
}}
trap cleanup_unrelated EXIT

run_sourced_flow() (
  flow="$1"
  pid_file="$2"
  sidecar="$root/$(basename "$pid_file").sidecar"
  : > "$sidecar"
  printf '143\n' > "$sidecar.exit"
  printf '%s\n' "$unrelated_pid" > "$sidecar.pid"
  sleep 30 &
  CRITICAL_FLOWS_LOG_OBSERVER_PID=$!
  CRITICAL_FLOWS_LOG_OBSERVER_SIDECAR="$sidecar"
  CRITICAL_FLOWS_LOG_OBSERVER_EXIT_FILE="$sidecar.exit"
  printf '%s\n' "$CRITICAL_FLOWS_LOG_OBSERVER_PID" > "$pid_file"
  critical_flows_log_observer_install_trap
  source "$flow"
)

run_sourced_flow {shlex.quote(str(first_flow))} {shlex.quote(str(first_pid_file))}
first_rc=$?
[ "$first_rc" -eq 3 ] || exit 71
first_pid="$(cat {shlex.quote(str(first_pid_file))})"
if kill -0 "$first_pid" 2>/dev/null; then
  exit 72
fi
kill -0 "$unrelated_pid" 2>/dev/null || exit 73

run_sourced_flow {shlex.quote(str(second_flow))} {shlex.quote(str(second_pid_file))}
second_rc=$?
[ "$second_rc" -eq 0 ] || exit 74
second_pid="$(cat {shlex.quote(str(second_pid_file))})"
if kill -0 "$second_pid" 2>/dev/null; then
  exit 75
fi
kill -0 "$unrelated_pid" 2>/dev/null || exit 76

cleanup_unrelated
trap - EXIT
"""
        result = subprocess.run(
            ["bash", "-c", script],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
            timeout=5,
        )

        assert result.returncode == 0, result.stdout + result.stderr


def test_log_clean_parser_skips_wrapper_braces_before_payload() -> None:
    payload = json.dumps(common_log_scan_v5(), separators=(",", ":"))
    result = run_log_clean_assertion(
        f"""#!/usr/bin/env bash
printf '%s\\n' "ℹ Starting wp eval {{ not json"
printf '%s\\n' '{payload}'
printf '%s\\n' "✔ Ran wp eval"
"""
    )

    assert result.returncode == 0
    assert "PASS log-clean target" in result.stdout


def test_log_clean_assertion_blocks_stale_truncated_missing_malformed_and_v1() -> None:
    cases = {
        "stale": common_log_scan_v5(marker_created_at="2020-01-01T00:00:00Z"),
        "truncated": common_log_scan_v5(
            start_line_count=9,
            end_line_count=8,
            start_byte_count=256,
            end_byte_count=128,
        ),
        "missing": {**common_log_scan_v5(), "observations": []},
        "malformed": {**common_log_scan_v5(), "marker_created_at": "not-a-time"},
        "unhashable_record": {
            **common_log_scan_v5(
                status="fail",
                end_line_count=5,
                end_byte_count=160,
            ),
            "matches": [
                {
                    **safe_log_record(line=5),
                    "path": ["fake-debug.log"],
                }
            ],
        },
        "v1": {
            "status": "pass",
            "paths": ["/tmp/fake-debug.log"],
            "matches": [],
            "marker": {
                "created_at": "2026-07-16T16:00:00Z",
                "paths": {"/tmp/fake-debug.log": 4},
            },
        },
    }
    for name, payload in cases.items():
        encoded = json.dumps(payload, separators=(",", ":"))
        result = run_log_clean_assertion(
            f"""#!/usr/bin/env bash
printf '%s\\n' '{encoded}'
"""
        )
        assert result.returncode == 3, (name, result.stdout, result.stderr)
        assert "BLOCKED log-clean check for target" in result.stdout


def test_log_clean_assertion_rejects_and_does_not_archive_raw_diagnostics() -> None:
    secret = "Authorization: Bearer sk_test_DO_NOT_ARCHIVE customer@example.test"
    payload = {
        **common_log_scan_v5(
            status="fail",
            end_line_count=5,
            end_byte_count=160,
        ),
        "matches": [f"fake-debug.log:5: PHP Warning: {secret}"],
    }
    encoded = json.dumps(payload, separators=(",", ":"))

    with tempfile.TemporaryDirectory(prefix="critical-flows-log-secret-") as tmp:
        evidence_path = Path(tmp) / "debug-log-scan.json"
        result = run_log_clean_assertion(
            f"""#!/usr/bin/env bash
printf '%s\\n' '{encoded}'
""",
            evidence_path=evidence_path,
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert secret not in result.stdout
        if evidence_path.exists():
            assert secret not in evidence_path.read_text(encoding="utf-8")


def test_log_clean_scan_ignores_known_wp67_textdomain_notice() -> None:
    source = COMMON.read_text(encoding="utf-8")

    assert "_load_textdomain_just_in_time" in source
    assert "ignored_matches" in source


def test_log_clean_scan_ignores_known_reference_wpcom_zoho_noise() -> None:
    source = COMMON.read_text(encoding="utf-8")

    assert "sopreda/archi/zoho/class-zoho-integration.php" in source
    assert "ignored_matches" in source


MA01_RUN_STAMP = "20260718T120000Z-10101"
MA01_ROUTES = {
    "transactions": "/wc/v3/payments/transactions",
    "deposits": "/wc/v3/payments/deposits",
    "disputes": "/wc/v3/payments/disputes",
}


def ma01_raw_probe(store: str, *, run_stamp: str = MA01_RUN_STAMP) -> dict:
    requested_path = (
        "/wp-admin/admin.php?page=wc-admin&path=/payments/overview"
        if store == "ref"
        else "/wp-admin/admin.php?page=wc-admin&path=/woopayments/overview"
    )
    return {
        "schema": "woopayments_ma01_probe.v1",
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "fixture": {
            "customer_login": "ma01-customer",
            "customer_roles": ["customer"],
            "customer_preexisting": True,
            "customer_created": False,
            "customer_manage_woocommerce": False,
            "admin_manage_woocommerce": True,
        },
        "routes": {
            name: {
                "path": path,
                "customer": {
                    "status": 403,
                    "code": "rest_forbidden",
                    "standard_error": True,
                    "financial_list_absent": True,
                },
                "admin": {
                    "status": 200,
                    "well_formed_data_list": True,
                    "list_envelope": True,
                },
            }
            for name, path in MA01_ROUTES.items()
        },
        "http": {
            "requested_path": requested_path,
            "final_path": requested_path,
            "requested_status": 403,
            "final_status": 403,
            "redirect_count": 0,
            "transport_errors": [],
            "logged_in_marker": True,
            "logout_marker": True,
            "login_form_marker": False,
            "permission_marker": True,
            "app_marker": False,
        },
        "exact_session_cleanup": True,
        "blockers": [],
    }


def ma01_clone(payload: dict) -> dict:
    return json.loads(json.dumps(payload))


def run_ma01(
    *args: str,
    input_text: str | None = None,
    context_key: str | None = TEST_RUN_CONTEXT_KEY,
) -> subprocess.CompletedProcess[str]:
    env = dict(os.environ)
    if context_key is None:
        env.pop("CRITICAL_FLOWS_RUN_CONTEXT_KEY", None)
    else:
        env["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = context_key
    return subprocess.run(
        ["python3", str(MA01_EVIDENCE), *args],
        cwd=REPO,
        input=input_text,
        text=True,
        capture_output=True,
        check=False,
        env=env,
    )


def normalize_ma01(
    raw: dict,
    *,
    store: str | None = None,
    run_stamp: str = MA01_RUN_STAMP,
    raw_text: str | None = None,
    context_key: str | None = TEST_RUN_CONTEXT_KEY,
) -> tuple[subprocess.CompletedProcess[str], dict]:
    bound_store = store or str(raw.get("store", "ref"))
    result = run_ma01(
        "normalize-probe",
        "--store",
        bound_store,
        "--run-stamp",
        run_stamp,
        input_text=raw_text if raw_text is not None else json.dumps(raw),
        context_key=context_key,
    )
    return result, json.loads(result.stdout)


def ma01_payload_digest(payload: dict) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(
        json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def ma01_context_hmac(payload: dict, key: str = TEST_RUN_CONTEXT_KEY) -> str:
    fields = (
        "schema",
        "store",
        "run_stamp",
        "runtime_owner",
        "status",
        "evidence_complete",
        "facts",
        "contract",
        "errors",
        "blockers",
    )
    semantics = {field: payload[field] for field in fields}
    message = b"woopayments-ma01-normalized-probe-context-v1\0" + json.dumps(
        semantics, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return "hmac-sha256:" + hmac.new(
        bytes.fromhex(key), message, hashlib.sha256
    ).hexdigest()


def ma01_rehash(path: Path, payload: dict) -> None:
    payload["payload_sha256"] = ma01_payload_digest(payload)
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def write_ma01_probe(root: Path, raw: dict) -> tuple[Path, dict, int]:
    root = root.resolve()
    result, normalized = normalize_ma01(raw, run_stamp=raw["run_stamp"])
    path = root / f"{raw['store']}-probe.json"
    path.write_text(json.dumps(normalized, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path, normalized, result.returncode


def compare_ma01(
    reference: Path,
    target: Path,
    *,
    run_stamp: str = MA01_RUN_STAMP,
) -> tuple[subprocess.CompletedProcess[str], dict]:
    result = run_ma01(
        "compare",
        "--reference",
        str(reference),
        "--target",
        str(target),
        "--run-stamp",
        run_stamp,
    )
    return result, json.loads(result.stdout)


def build_ma01_pass_chain(root: Path) -> dict[str, Path]:
    root = root.resolve()
    ref_path, _, ref_rc = write_ma01_probe(root, ma01_raw_probe("ref"))
    target_raw = ma01_raw_probe("target")
    for route in target_raw["routes"].values():
        route["customer"]["status"] = 401
        route["customer"]["code"] = "rest_forbidden_context"
    target_raw["http"].update(
        {
            "requested_status": 302,
            "final_status": 200,
            "redirect_count": 1,
            "final_path": "/wp-admin/",
            "permission_marker": False,
        }
    )
    target_path, _, target_rc = write_ma01_probe(root, target_raw)
    assert ref_rc == target_rc == 0
    comparison_result, comparison = compare_ma01(ref_path, target_path)
    assert comparison_result.returncode == 0, comparison_result.stderr
    comparison_path = root / "comparison.json"
    comparison_path.write_text(
        json.dumps(comparison, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )

    paths = {
        "ref-probe.json": ref_path,
        "target-probe.json": target_path,
        "comparison.json": comparison_path,
    }
    for store in ("ref", "target"):
        execution_path = root / f"{store}-execution.json"
        command = [
            "execution",
            "--store",
            store,
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--output",
            str(execution_path),
            "--probe",
            str(paths[f"{store}-probe.json"]),
            "--probe-exit-code",
            "0",
            "--log-assertion-exit-code",
            "0",
        ]
        if store == "target":
            command.extend(
                [
                    "--comparison",
                    str(comparison_path),
                    "--comparison-exit-code",
                    "0",
                ]
            )
        result = run_ma01(*command)
        assert result.returncode == 0, result.stdout + result.stderr
        paths[f"{store}-execution.json"] = execution_path

    for store in ("ref", "target"):
        manifest_path = root / f"{store}-manifest.json"
        allowed = (
            ("ref-probe.json", "ref-execution.json")
            if store == "ref"
            else (
                "ref-probe.json",
                "target-probe.json",
                "comparison.json",
                "target-execution.json",
            )
        )
        command = [
            "manifest",
            "--store",
            store,
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--run-scope",
            "partial",
            "--output",
            str(manifest_path),
        ]
        for filename in allowed:
            command.extend(["--file", str(paths[filename])])
        result = run_ma01(*command)
        assert result.returncode == 0, result.stdout + result.stderr
        paths[f"{store}-manifest.json"] = manifest_path
    return paths


def build_ma01_fail_chain(root: Path) -> dict[str, Path]:
    root = root.resolve()
    ref_path, _, ref_rc = write_ma01_probe(root, ma01_raw_probe("ref"))
    target_raw = ma01_raw_probe("target")
    target_raw["routes"]["transactions"]["customer"].update(
        {
            "status": 200,
            "code": "",
            "standard_error": False,
            "financial_list_absent": False,
        }
    )
    target_path, _, target_rc = write_ma01_probe(root, target_raw)
    assert ref_rc == 0
    assert target_rc == 1
    comparison_result, comparison = compare_ma01(ref_path, target_path)
    assert comparison_result.returncode == 1
    comparison_path = root / "comparison.json"
    comparison_path.write_text(
        json.dumps(comparison, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    execution_path = root / "target-execution.json"
    execution_result = run_ma01(
        "execution",
        "--store",
        "target",
        "--status",
        "fail",
        "--exit-code",
        "1",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--output",
        str(execution_path),
        "--probe",
        str(target_path),
        "--probe-exit-code",
        "1",
        "--comparison",
        str(comparison_path),
        "--comparison-exit-code",
        "1",
        "--log-assertion-exit-code",
        "0",
        "--verdict-source",
        "probe_failed",
        "--verdict-source",
        "comparison_failed",
    )
    assert execution_result.returncode == 0, execution_result.stdout + execution_result.stderr
    paths = {
        "ref-probe.json": ref_path,
        "target-probe.json": target_path,
        "comparison.json": comparison_path,
        "target-execution.json": execution_path,
    }
    manifest_path = root / "target-manifest.json"
    command = [
        "manifest",
        "--store",
        "target",
        "--status",
        "fail",
        "--exit-code",
        "1",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--run-scope",
        "partial",
        "--output",
        str(manifest_path),
        "--verdict-source",
        "probe_failed",
        "--verdict-source",
        "comparison_failed",
    ]
    for path in paths.values():
        command.extend(["--file", str(path)])
    manifest_result = run_ma01(*command)
    assert manifest_result.returncode == 0, manifest_result.stdout + manifest_result.stderr
    paths["target-manifest.json"] = manifest_path
    return paths


def build_ma01_log_fail_chain(root: Path) -> dict[str, Path]:
    paths = build_ma01_pass_chain(root)
    execution_path = paths["target-execution.json"]
    execution_result = run_ma01(
        "execution",
        "--store",
        "target",
        "--status",
        "fail",
        "--exit-code",
        "1",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--output",
        str(execution_path),
        "--probe",
        str(paths["target-probe.json"]),
        "--probe-exit-code",
        "0",
        "--comparison",
        str(paths["comparison.json"]),
        "--comparison-exit-code",
        "0",
        "--log-assertion-exit-code",
        "1",
        "--verdict-source",
        "log_assertion_failed",
    )
    assert execution_result.returncode == 0, execution_result.stdout + execution_result.stderr

    manifest_path = paths["target-manifest.json"]
    command = [
        "manifest",
        "--store",
        "target",
        "--status",
        "fail",
        "--exit-code",
        "1",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--run-scope",
        "partial",
        "--output",
        str(manifest_path),
        "--verdict-source",
        "log_assertion_failed",
    ]
    for filename in (
        "ref-probe.json",
        "target-probe.json",
        "comparison.json",
        "target-execution.json",
    ):
        command.extend(["--file", str(paths[filename])])
    manifest_result = run_ma01(*command)
    assert manifest_result.returncode == 0, manifest_result.stdout + manifest_result.stderr
    return paths


def rewrite_ma01_fail_chain(
    paths: dict[str, Path], outcome: str
) -> tuple[str, int]:
    target_path = paths["target-probe.json"]
    original_target = json.loads(target_path.read_text(encoding="utf-8"))
    raw = ma01_raw_probe("target")
    if outcome == "blocked":
        raw["exact_session_cleanup"] = False
    result, replacement = normalize_ma01(raw)
    assert result.returncode == (0 if outcome == "pass" else 3)
    replacement["context_hmac"] = original_target.get(
        "context_hmac", "hmac-sha256:" + "0" * 64
    )
    ma01_rehash(target_path, replacement)

    ref_probe = json.loads(paths["ref-probe.json"].read_text(encoding="utf-8"))
    comparison_path = paths["comparison.json"]
    comparison = json.loads(comparison_path.read_text(encoding="utf-8"))
    comparison["inputs"] = {
        "ref_probe": ref_probe["payload_sha256"],
        "target_probe": replacement["payload_sha256"],
    }
    if outcome == "pass":
        comparison.update(
            {
                "status": "pass",
                "reference": {
                    key: ref_probe["contract"][key]
                    for key in ("capabilities", "routes", "http")
                },
                "target": {
                    key: replacement["contract"][key]
                    for key in ("capabilities", "routes", "http")
                },
                "errors": [],
                "blockers": [],
            }
        )
    else:
        comparison.update(
            {
                "status": "blocked",
                "reference": {},
                "target": {},
                "errors": [],
                "blockers": ["Target normalized probe is blocked."],
            }
        )
    ma01_rehash(comparison_path, comparison)

    execution_path = paths["target-execution.json"]
    execution = json.loads(execution_path.read_text(encoding="utf-8"))
    if outcome == "pass":
        execution.update(
            {
                "status": "pass",
                "exit_code": 0,
                "verdict_sources": [],
                "probe_exit_code": 0,
                "comparison_exit_code": 0,
            }
        )
    else:
        execution.update(
            {
                "status": "blocked",
                "exit_code": 3,
                "verdict_sources": ["comparison_blocked", "probe_blocked"],
                "probe_exit_code": 3,
                "comparison_exit_code": 3,
            }
        )
    execution["probe_payload_sha256"] = replacement["payload_sha256"]
    execution["comparison_payload_sha256"] = comparison["payload_sha256"]
    ma01_rehash(execution_path, execution)

    manifest_path = paths["target-manifest.json"]
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest.update(
        {
            "status": execution["status"],
            "exit_code": execution["exit_code"],
            "verdict_sources": execution["verdict_sources"],
        }
    )
    for filename, payload in (
        ("target-probe.json", replacement),
        ("comparison.json", comparison),
        ("target-execution.json", execution),
    ):
        manifest["files"][filename] = {
            "file_sha256": file_sha256(paths[filename]),
            "payload_sha256": payload["payload_sha256"],
        }
    ma01_rehash(manifest_path, manifest)
    return execution["status"], execution["exit_code"]


def rewrite_ma01_log_fail_chain(
    paths: dict[str, Path], outcome: str
) -> tuple[str, int]:
    execution_path = paths["target-execution.json"]
    execution = json.loads(execution_path.read_text(encoding="utf-8"))
    if outcome == "pass":
        execution.update(
            {
                "status": "pass",
                "exit_code": 0,
                "verdict_sources": [],
                "log_assertion_exit_code": 0,
            }
        )
    else:
        execution.update(
            {
                "status": "blocked",
                "exit_code": 3,
                "verdict_sources": ["log_assertion_blocked"],
                "log_assertion_exit_code": 3,
            }
        )
    ma01_rehash(execution_path, execution)

    manifest_path = paths["target-manifest.json"]
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest.update(
        {
            "status": execution["status"],
            "exit_code": execution["exit_code"],
            "verdict_sources": execution["verdict_sources"],
        }
    )
    manifest["files"]["target-execution.json"] = {
        "file_sha256": file_sha256(execution_path),
        "payload_sha256": execution["payload_sha256"],
    }
    ma01_rehash(manifest_path, manifest)
    return execution["status"], execution["exit_code"]


def validate_ma01_manifest(
    manifest: Path,
    *,
    store: str = "target",
    run_stamp: str = MA01_RUN_STAMP,
    status: str = "pass",
    exit_code: int = 0,
    context_key: str | None = TEST_RUN_CONTEXT_KEY,
) -> subprocess.CompletedProcess[str]:
    return run_ma01(
        "validate-bound-manifest",
        "--manifest",
        str(manifest),
        "--store",
        store,
        "--run-stamp",
        run_stamp,
        "--run-scope",
        "partial",
        "--expected-status",
        status,
        "--expected-exit-code",
        str(exit_code),
        context_key=context_key,
    )


def run_ma01_ref_execution_builder(
    output: Path, paths: dict[str, Path]
) -> subprocess.CompletedProcess[str]:
    return run_ma01(
        "execution",
        "--store",
        "ref",
        "--status",
        "pass",
        "--exit-code",
        "0",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--output",
        str(output),
        "--probe",
        str(paths["ref-probe.json"]),
        "--probe-exit-code",
        "0",
        "--log-assertion-exit-code",
        "0",
    )


def run_ma01_target_manifest_builder(
    output: Path, paths: dict[str, Path]
) -> subprocess.CompletedProcess[str]:
    command = [
        "manifest",
        "--store",
        "target",
        "--status",
        "pass",
        "--exit-code",
        "0",
        "--run-stamp",
        MA01_RUN_STAMP,
        "--run-scope",
        "partial",
        "--output",
        str(output),
    ]
    for filename in (
        "ref-probe.json",
        "target-probe.json",
        "comparison.json",
        "target-execution.json",
    ):
        command.extend(["--file", str(paths[filename])])
    return run_ma01(*command)


def test_ma01_normalizes_happy_reference_and_target_without_sensitive_evidence() -> None:
    for store in ("ref", "target"):
        raw = ma01_raw_probe(store)
        if store == "target":
            for route in raw["routes"].values():
                route["customer"].update(
                    {"status": 401, "code": "rest_forbidden_context"}
                )
            raw["http"].update(
                {
                    "requested_status": 302,
                    "final_status": 200,
                    "redirect_count": 1,
                    "final_path": "/wp-admin/",
                    "permission_marker": False,
                }
            )
        result, normalized = normalize_ma01(raw)

        assert result.returncode == 0, result.stdout + result.stderr
        assert normalized["schema"] == "woopayments_ma01_normalized.v1"
        assert normalized["status"] == "pass"
        assert normalized["store"] == store
        assert normalized["runtime_owner"] == ("plugin" if store == "ref" else "native")
        assert normalized["evidence_complete"] is True
        assert normalized["errors"] == []
        assert normalized["blockers"] == []
        assert normalized["contract"]["capabilities"] == {
            "admin_admitted": True,
            "customer_denied": True,
        }
        assert all(
            route == {
                "admin_admitted": True,
                "admin_data_list_well_formed": True,
                "customer_denied": True,
                "customer_financial_list_absent": True,
                "customer_standard_error": True,
            }
            for route in normalized["contract"]["routes"].values()
        )
        assert normalized["contract"]["http"] == {
            "access_denied": True,
            "app_absent": True,
            "authenticated": True,
            "never_login": True,
        }
        assert normalized["contract"]["cleanup_proven"] is True
        assert re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", normalized["context_hmac"])
        assert normalized["context_hmac"] == ma01_context_hmac(normalized)
        assert normalized["payload_sha256"] == ma01_payload_digest(normalized)
        encoded = json.dumps(normalized)
        assert TEST_RUN_CONTEXT_KEY not in encoded
        for forbidden in (
            "response_rows",
            "raw_html",
            "headers",
            "credentials",
            "cookies",
            "tokens",
            "password",
            "ma01-customer",
        ):
            assert forbidden not in encoded


@pytest.mark.parametrize(
    "customer_preexisting,customer_created",
    [(True, False), (False, True)],
)
def test_ma01_accepts_truthful_fixture_ensure_states(
    customer_preexisting: bool, customer_created: bool
) -> None:
    raw = ma01_raw_probe("ref")
    raw["fixture"].update(
        {
            "customer_preexisting": customer_preexisting,
            "customer_created": customer_created,
        }
    )

    result, normalized = normalize_ma01(raw)

    assert result.returncode == 0, result.stdout + result.stderr
    assert normalized["status"] == "pass"
    assert normalized["facts"]["fixture"]["customer_preexisting"] is customer_preexisting
    assert normalized["facts"]["fixture"]["customer_created"] is customer_created
    assert normalized["context_hmac"] == ma01_context_hmac(normalized)
    assert normalized["payload_sha256"] == ma01_payload_digest(normalized)


@pytest.mark.parametrize(
    "customer_preexisting,customer_created",
    [(False, False), (True, True)],
)
def test_ma01_blocks_unavailable_fixture_ensure_states(
    customer_preexisting: bool, customer_created: bool
) -> None:
    raw = ma01_raw_probe("ref")
    raw["fixture"].update(
        {
            "customer_preexisting": customer_preexisting,
            "customer_created": customer_created,
            "customer_roles": ["subscriber"],
            "customer_manage_woocommerce": True,
        }
    )

    result, normalized = normalize_ma01(raw)

    assert result.returncode == 3, result.stdout + result.stderr
    assert normalized["status"] == "blocked"
    assert normalized["evidence_complete"] is True
    assert normalized["errors"] == []
    assert any("fixture ensure state" in reason for reason in normalized["blockers"])
    assert normalized["facts"]["fixture"]["customer_preexisting"] is customer_preexisting
    assert normalized["facts"]["fixture"]["customer_created"] is customer_created
    assert normalized["context_hmac"] == ma01_context_hmac(normalized)
    assert normalized["payload_sha256"] == ma01_payload_digest(normalized)


def test_ma01_permission_page_affirmatively_authenticates_without_session_markers() -> None:
    raw = ma01_raw_probe("ref")
    raw["http"].update(
        {
            "logged_in_marker": False,
            "logout_marker": False,
        }
    )

    result, normalized = normalize_ma01(raw)

    assert result.returncode == 0, result.stdout + result.stderr
    assert normalized["status"] == "pass"
    assert normalized["errors"] == []
    assert normalized["blockers"] == []
    assert normalized["contract"]["http"] == {
        "access_denied": True,
        "app_absent": True,
        "authenticated": True,
        "never_login": True,
    }
    assert normalized["context_hmac"] == ma01_context_hmac(normalized)
    assert normalized["payload_sha256"] == ma01_payload_digest(normalized)


def test_ma01_context_key_is_required_for_normalization_and_bound_validation() -> None:
    raw = ma01_raw_probe("target")
    for context_key in (None, "A" * 64, "not-a-context-key"):
        result = run_ma01(
            "normalize-probe",
            "--store",
            "target",
            "--run-stamp",
            MA01_RUN_STAMP,
            input_text=json.dumps(raw),
            context_key=context_key,
        )
        transcript = result.stdout + result.stderr
        assert result.returncode == 3, transcript
        assert "Traceback" not in transcript
        assert str(REPO) not in transcript
        if context_key is not None:
            assert context_key not in transcript

    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-context-key-") as tmp:
        root = Path(tmp)
        paths = build_ma01_pass_chain(root)
        for context_key in (None, "22" * 32):
            validation = validate_ma01_manifest(
                paths["target-manifest.json"], context_key=context_key
            )
            transcript = validation.stdout + validation.stderr
            assert validation.returncode == 3, transcript
            assert "Traceback" not in transcript
            assert str(REPO) not in transcript
            if context_key is not None:
                assert context_key not in transcript


@pytest.mark.parametrize(
    "name,mutate,error_fragment",
    [
        (
            "wrong_role",
            lambda raw: raw["fixture"].update({"customer_roles": ["subscriber"]}),
            "customer role",
        ),
        (
            "customer_capability",
            lambda raw: raw["fixture"].update({"customer_manage_woocommerce": True}),
            "customer capability",
        ),
        (
            "admin_capability",
            lambda raw: raw["fixture"].update({"admin_manage_woocommerce": False}),
            "admin capability",
        ),
        (
            "customer_data_leak",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {
                    "status": 200,
                    "code": "",
                    "standard_error": False,
                    "financial_list_absent": False,
                }
            ),
            "transactions customer",
        ),
        (
            "admin_denial",
            lambda raw: raw["routes"]["deposits"]["admin"].update({"status": 403}),
            "deposits admin status",
        ),
        (
            "admin_numeric_503",
            lambda raw: raw["routes"]["deposits"]["admin"].update({"status": 503}),
            "deposits admin status",
        ),
        (
            "customer_numeric_502_data_leak",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {
                    "status": 502,
                    "code": "",
                    "standard_error": False,
                    "financial_list_absent": False,
                }
            ),
            "transactions customer",
        ),
        (
            "admin_malformed_list",
            lambda raw: raw["routes"]["disputes"]["admin"].update(
                {"well_formed_data_list": False}
            ),
            "disputes admin data list",
        ),
        (
            "app_rendered",
            lambda raw: raw["http"].update({"app_marker": True}),
            "Payments app rendered",
        ),
        (
            "authenticated_500_app_rendered",
            lambda raw: raw["http"].update(
                {
                    "requested_status": 500,
                    "final_status": 500,
                    "app_marker": True,
                }
            ),
            "Payments app rendered",
        ),
        (
            "payments_path_retained",
            lambda raw: raw["http"].update({"permission_marker": False}),
            "remained on the Payments path",
        ),
        (
            "authenticated_503_payments_path_retained",
            lambda raw: raw["http"].update(
                {
                    "requested_status": 503,
                    "final_status": 503,
                    "permission_marker": False,
                }
            ),
            "remained on the Payments path",
        ),
    ],
)
def test_ma01_completed_contract_mismatches_are_functional_failures(
    name: str, mutate, error_fragment: str
) -> None:
    raw = ma01_raw_probe("target")
    mutate(raw)
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 1, (name, result.stdout, result.stderr)
    assert normalized["status"] == "fail"
    assert normalized["errors"]
    assert any(error_fragment in error for error in normalized["errors"])


def test_ma01_missing_malformed_and_incomplete_probes_block_fail_closed() -> None:
    raw = ma01_raw_probe("target")
    cases: list[tuple[str, str]] = []
    cases.append(("missing", "probe produced no MA-01 payload"))
    cases.append(
        (
            json.dumps(raw) + "\n" + json.dumps(raw),
            "exactly one MA-01 payload",
        )
    )
    malformed = ma01_clone(raw)
    malformed["unexpected"] = "Authorization: Bearer secret@example.test"
    cases.append((json.dumps(malformed), "invalid field set"))
    missing_ensure_state = ma01_clone(raw)
    missing_ensure_state["fixture"].pop("customer_preexisting", None)
    cases.append((json.dumps(missing_ensure_state), "fixture has an invalid field set"))

    for raw_text, blocker_fragment in cases:
        result, normalized = normalize_ma01(
            raw,
            store="target",
            raw_text=raw_text,
        )
        assert result.returncode == 3, result.stdout + result.stderr
        assert normalized["status"] == "blocked"
        assert normalized["errors"] == []
        assert any(blocker_fragment in reason for reason in normalized["blockers"])
        assert "secret@example.test" not in json.dumps(normalized)

    blockers = (
        (
            lambda candidate: candidate["http"].update(
                {"transport_errors": ["timeout cookie=secret@example.test"]}
            ),
            "HTTP transport failed",
        ),
        (
            lambda candidate: candidate["http"].update(
                {
                    "final_path": "/wp-login.php?redirect_to=secret@example.test",
                    "final_status": 200,
                    "redirect_count": 1,
                    "logged_in_marker": False,
                    "logout_marker": False,
                    "login_form_marker": True,
                    "permission_marker": True,
                }
            ),
            "login",
        ),
        (
            lambda candidate: candidate["http"].update(
                {
                    "permission_marker": False,
                    "logged_in_marker": False,
                    "logout_marker": False,
                }
            ),
            "affirmatively authenticated",
        ),
        (
            lambda candidate: candidate.update({"exact_session_cleanup": False}),
            "cleanup",
        ),
        (
            lambda candidate: candidate.update(
                {"blockers": ["password=DO_NOT_ARCHIVE secret@example.test"]}
            ),
            "reported a blocker",
        ),
    )
    for mutate, blocker_fragment in blockers:
        candidate = ma01_raw_probe("target")
        mutate(candidate)
        result, normalized = normalize_ma01(candidate)
        assert result.returncode == 3, result.stdout + result.stderr
        assert normalized["status"] == "blocked"
        assert any(blocker_fragment in reason for reason in normalized["blockers"])
        assert "secret@example.test" not in json.dumps(normalized)
        assert "DO_NOT_ARCHIVE" not in json.dumps(normalized)


@pytest.mark.parametrize(
    "name,mutate",
    [
        (
            "customer_zero",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {"status": 0}
            ),
        ),
        (
            "customer_negative",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {"status": -1}
            ),
        ),
        (
            "customer_700_with_false_list_fact",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {
                    "status": 700,
                    "code": "",
                    "standard_error": False,
                    "financial_list_absent": False,
                }
            ),
        ),
        (
            "admin_zero",
            lambda raw: raw["routes"]["deposits"]["admin"].update(
                {"status": 0}
            ),
        ),
        (
            "admin_700",
            lambda raw: raw["routes"]["deposits"]["admin"].update(
                {
                    "status": 700,
                    "well_formed_data_list": False,
                    "list_envelope": False,
                }
            ),
        ),
    ],
)
def test_ma01_invalid_route_statuses_are_unavailable_without_functional_inference(
    name: str, mutate
) -> None:
    raw = ma01_raw_probe("target")
    mutate(raw)
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 3, (name, result.stdout, result.stderr)
    assert normalized["status"] == "blocked"
    assert normalized["errors"] == []
    assert any(
        "route observation was unavailable" in blocker
        for blocker in normalized["blockers"]
    )


def test_ma01_completed_failure_precedes_invalid_route_status_without_inference() -> None:
    raw = ma01_raw_probe("target")
    raw["routes"]["transactions"]["customer"].update(
        {
            "status": 700,
            "code": "",
            "standard_error": False,
            "financial_list_absent": False,
        }
    )
    raw["routes"]["deposits"]["admin"]["status"] = 403
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 1
    assert normalized["status"] == "fail"
    assert normalized["errors"] == ["deposits admin status was 403, not 200."]
    assert any(
        "transactions customer route observation was unavailable" in blocker
        for blocker in normalized["blockers"]
    )


@pytest.mark.parametrize(
    "name,status_field,status",
    [
        ("requested_zero", "requested_status", 0),
        ("final_700", "final_status", 700),
    ],
)
def test_ma01_invalid_page_statuses_block_without_functional_inference(
    name: str, status_field: str, status: int
) -> None:
    raw = ma01_raw_probe("target")
    raw["http"].update(
        {
            status_field: status,
            "app_marker": True,
            "permission_marker": False,
        }
    )
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 3, (name, result.stdout, result.stderr)
    assert normalized["status"] == "blocked"
    assert normalized["errors"] == []
    assert any(
        "HTTP status observation was unavailable" in blocker
        for blocker in normalized["blockers"]
    )


def test_ma01_completed_failure_precedes_invalid_page_status_without_inference() -> None:
    raw = ma01_raw_probe("target")
    raw["http"].update(
        {
            "final_status": 700,
            "app_marker": True,
            "permission_marker": False,
        }
    )
    raw["routes"]["deposits"]["admin"]["status"] = 403
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 1
    assert normalized["status"] == "fail"
    assert normalized["errors"] == ["deposits admin status was 403, not 200."]
    assert any(
        "HTTP status observation was unavailable" in blocker
        for blocker in normalized["blockers"]
    )


def test_ma01_functional_failure_takes_precedence_over_cleanup_and_transport_blocks() -> None:
    raw = ma01_raw_probe("target")
    raw["routes"]["transactions"]["customer"].update(
        {
            "status": 200,
            "code": "",
            "standard_error": False,
            "financial_list_absent": False,
        }
    )
    raw["http"]["transport_errors"] = ["connection reset token=DO_NOT_ARCHIVE"]
    raw["exact_session_cleanup"] = False
    result, normalized = normalize_ma01(raw)

    assert result.returncode == 1
    assert normalized["status"] == "fail"
    assert any("transactions customer" in error for error in normalized["errors"])
    assert any("transport failed" in blocker for blocker in normalized["blockers"])
    assert any("cleanup" in blocker for blocker in normalized["blockers"])
    assert "DO_NOT_ARCHIVE" not in json.dumps(normalized)


def test_ma01_comparison_binds_inputs_allows_presentation_and_rejects_mismatch_or_stale() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-compare-") as tmp:
        root = Path(tmp)
        ref_raw = ma01_raw_probe("ref")
        ref_raw["http"].update(
            {
                "logged_in_marker": False,
                "logout_marker": False,
            }
        )
        ref_path, ref, ref_rc = write_ma01_probe(root, ref_raw)
        target_raw = ma01_raw_probe("target")
        for route in target_raw["routes"].values():
            route["customer"].update(
                {"status": 401, "code": "rest_forbidden_context"}
            )
        target_raw["http"].update(
            {
                "requested_status": 302,
                "final_status": 200,
                "redirect_count": 1,
                "final_path": "/wp-admin/",
                "permission_marker": False,
            }
        )
        target_path, target, target_rc = write_ma01_probe(root, target_raw)
        assert ref_rc == target_rc == 0

        equivalent, comparison = compare_ma01(ref_path, target_path)
        assert equivalent.returncode == 0, equivalent.stdout + equivalent.stderr
        assert comparison["status"] == "pass"
        assert comparison["inputs"] == {
            "ref_probe": ref["payload_sha256"],
            "target_probe": target["payload_sha256"],
        }
        assert comparison["reference"] == comparison["target"]

        mismatch_raw = ma01_clone(target_raw)
        mismatch_raw["fixture"]["customer_manage_woocommerce"] = True
        mismatch_path, _, mismatch_rc = write_ma01_probe(root, mismatch_raw)
        assert mismatch_rc == 1
        mismatch, mismatch_payload = compare_ma01(ref_path, mismatch_path)
        assert mismatch.returncode == 1
        assert mismatch_payload["status"] == "fail"
        assert mismatch_payload["errors"]

        stale_raw = ma01_raw_probe("target", run_stamp="20260718T110000Z-10100")
        stale_path, _, stale_rc = write_ma01_probe(root, stale_raw)
        assert stale_rc == 0
        stale, stale_payload = compare_ma01(ref_path, stale_path)
        assert stale.returncode == 3
        assert stale_payload["status"] == "blocked"
        assert any("run binding" in blocker for blocker in stale_payload["blockers"])

        wrong_store, wrong_store_payload = compare_ma01(ref_path, ref_path)
        assert wrong_store.returncode == 3
        assert wrong_store_payload["status"] == "blocked"
        assert any("store binding" in blocker for blocker in wrong_store_payload["blockers"])


def test_ma01_execution_and_manifests_enforce_vocabulary_bindings_and_allowlists() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-chain-") as tmp:
        root = Path(tmp).resolve()
        paths = build_ma01_pass_chain(root)

        invalid_execution = run_ma01(
            "execution",
            "--store",
            "ref",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--output",
            str(root / "invalid-execution.json"),
            "--probe",
            str(paths["ref-probe.json"]),
            "--probe-exit-code",
            "0",
            "--log-assertion-exit-code",
            "0",
            "--verdict-source",
            "invented_source",
        )
        assert invalid_execution.returncode == 3

        ref_manifest = json.loads(paths["ref-manifest.json"].read_text(encoding="utf-8"))
        target_manifest = json.loads(
            paths["target-manifest.json"].read_text(encoding="utf-8")
        )
        assert ref_manifest["schema"] == "woopayments_ma01_manifest.v1"
        assert ref_manifest["flow"] == "MA-01-open-admin-as-non-admin"
        assert set(ref_manifest["files"]) == {
            "ref-probe.json",
            "ref-execution.json",
        }
        assert set(target_manifest["files"]) == {
            "ref-probe.json",
            "target-probe.json",
            "comparison.json",
            "target-execution.json",
        }
        for manifest in (ref_manifest, target_manifest):
            assert manifest["status"] == "pass"
            assert manifest["exit_code"] == 0
            assert manifest["verdict_sources"] == []
            assert manifest["payload_sha256"] == ma01_payload_digest(manifest)
            for filename, binding in manifest["files"].items():
                assert binding == {
                    "file_sha256": file_sha256(root / filename),
                    "payload_sha256": json.loads(
                        (root / filename).read_text(encoding="utf-8")
                    )["payload_sha256"],
                }

        unexpected = root / "unexpected.json"
        unexpected.write_text(
            paths["target-probe.json"].read_text(encoding="utf-8"), encoding="utf-8"
        )
        rejected_allowlist = run_ma01(
            "manifest",
            "--store",
            "target",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--run-scope",
            "partial",
            "--output",
            str(root / "invalid-manifest.json"),
            "--file",
            str(paths["ref-probe.json"]),
            "--file",
            str(paths["target-probe.json"]),
            "--file",
            str(paths["comparison.json"]),
            "--file",
            str(paths["target-execution.json"]),
            "--file",
            str(unexpected),
        )
        assert rejected_allowlist.returncode == 3

        failure_root = root / "failure-chain"
        failure_root.mkdir()
        failure_ref_path, _, failure_ref_rc = write_ma01_probe(
            failure_root, ma01_raw_probe("ref")
        )
        failure_target_raw = ma01_raw_probe("target")
        failure_target_raw["routes"]["transactions"]["customer"].update(
            {
                "status": 200,
                "code": "",
                "standard_error": False,
                "financial_list_absent": False,
            }
        )
        failure_target_path, _, failure_target_rc = write_ma01_probe(
            failure_root, failure_target_raw
        )
        assert failure_ref_rc == 0
        assert failure_target_rc == 1
        failure_comparison_result, failure_comparison = compare_ma01(
            failure_ref_path, failure_target_path
        )
        assert failure_comparison_result.returncode == 1
        failure_comparison_path = failure_root / "comparison.json"
        failure_comparison_path.write_text(
            json.dumps(failure_comparison, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        failure_execution_path = failure_root / "target-execution.json"
        failure_execution_result = run_ma01(
            "execution",
            "--store",
            "target",
            "--status",
            "fail",
            "--exit-code",
            "1",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--output",
            str(failure_execution_path),
            "--probe",
            str(failure_target_path),
            "--probe-exit-code",
            "1",
            "--comparison",
            str(failure_comparison_path),
            "--comparison-exit-code",
            "1",
            "--log-assertion-exit-code",
            "3",
            "--verdict-source",
            "probe_failed",
            "--verdict-source",
            "comparison_failed",
        )
        assert failure_execution_result.returncode == 0, (
            failure_execution_result.stdout + failure_execution_result.stderr
        )
        failure_execution = json.loads(
            failure_execution_path.read_text(encoding="utf-8")
        )
        assert failure_execution["status"] == "fail"
        assert failure_execution["verdict_sources"] == [
            "comparison_failed",
            "probe_failed",
        ]
        failure_manifest_path = failure_root / "target-manifest.json"
        failure_manifest_command = [
            "manifest",
            "--store",
            "target",
            "--status",
            "fail",
            "--exit-code",
            "1",
            "--run-stamp",
            MA01_RUN_STAMP,
            "--run-scope",
            "partial",
            "--output",
            str(failure_manifest_path),
            "--verdict-source",
            "probe_failed",
            "--verdict-source",
            "comparison_failed",
        ]
        for path in (
            failure_ref_path,
            failure_target_path,
            failure_comparison_path,
            failure_execution_path,
        ):
            failure_manifest_command.extend(["--file", str(path)])
        failure_manifest_result = run_ma01(*failure_manifest_command)
        assert failure_manifest_result.returncode == 0, (
            failure_manifest_result.stdout + failure_manifest_result.stderr
        )
        failure_validation = validate_ma01_manifest(
            failure_manifest_path, status="fail", exit_code=1
        )
        assert failure_validation.returncode == 0, (
            failure_validation.stdout + failure_validation.stderr
        )


def test_ma01_bound_manifest_validator_fails_closed_against_artifact_attacks() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-attacks-") as tmp:
        root = Path(tmp).resolve()
        base = root / "base"
        base.mkdir()
        paths = build_ma01_pass_chain(base)
        valid = validate_ma01_manifest(paths["target-manifest.json"])
        assert valid.returncode == 0, valid.stdout + valid.stderr
        assert re.fullmatch(r"sha256:[0-9a-f]{64}\n?", valid.stdout)

        wrong_run = validate_ma01_manifest(
            paths["target-manifest.json"], run_stamp="20260718T120001Z-10102"
        )
        assert wrong_run.returncode == 3

        malformed_dir = root / "malformed"
        shutil.copytree(base, malformed_dir)
        malformed_manifest = malformed_dir / "target-manifest.json"
        malformed_manifest.write_text("{not-json", encoding="utf-8")
        assert validate_ma01_manifest(malformed_manifest).returncode == 3

        missing_dir = root / "missing"
        shutil.copytree(base, missing_dir)
        (missing_dir / "target-probe.json").unlink()
        assert validate_ma01_manifest(missing_dir / "target-manifest.json").returncode == 3

        tampered_dir = root / "tampered"
        shutil.copytree(base, tampered_dir)
        with (tampered_dir / "target-probe.json").open("a", encoding="utf-8") as stream:
            stream.write(" ")
        assert validate_ma01_manifest(tampered_dir / "target-manifest.json").returncode == 3

        symlink_dir = root / "symlink"
        shutil.copytree(base, symlink_dir)
        outside = root / "outside-probe.json"
        outside.write_text(
            (symlink_dir / "target-probe.json").read_text(encoding="utf-8"),
            encoding="utf-8",
        )
        (symlink_dir / "target-probe.json").unlink()
        (symlink_dir / "target-probe.json").symlink_to(outside)
        assert validate_ma01_manifest(symlink_dir / "target-manifest.json").returncode == 3

        escape_dir = root / "escape"
        shutil.copytree(base, escape_dir)
        escape_manifest_path = escape_dir / "target-manifest.json"
        escape_manifest = json.loads(escape_manifest_path.read_text(encoding="utf-8"))
        escape_manifest["files"]["../outside-probe.json"] = escape_manifest["files"].pop(
            "target-probe.json"
        )
        ma01_rehash(escape_manifest_path, escape_manifest)
        assert validate_ma01_manifest(escape_manifest_path).returncode == 3

        forged_dir = root / "forged"
        shutil.copytree(base, forged_dir)
        forged_probe_path = forged_dir / "target-probe.json"
        forged_probe = json.loads(forged_probe_path.read_text(encoding="utf-8"))
        forged_probe["facts"]["routes"]["transactions"]["customer_status"] = 200
        ma01_rehash(forged_probe_path, forged_probe)

        forged_comparison_path = forged_dir / "comparison.json"
        forged_comparison = json.loads(
            forged_comparison_path.read_text(encoding="utf-8")
        )
        forged_comparison["inputs"]["target_probe"] = forged_probe["payload_sha256"]
        ma01_rehash(forged_comparison_path, forged_comparison)

        forged_execution_path = forged_dir / "target-execution.json"
        forged_execution = json.loads(
            forged_execution_path.read_text(encoding="utf-8")
        )
        forged_execution["probe_payload_sha256"] = forged_probe["payload_sha256"]
        forged_execution["comparison_payload_sha256"] = forged_comparison[
            "payload_sha256"
        ]
        ma01_rehash(forged_execution_path, forged_execution)

        forged_manifest_path = forged_dir / "target-manifest.json"
        forged_manifest = json.loads(forged_manifest_path.read_text(encoding="utf-8"))
        for filename, payload in (
            ("target-probe.json", forged_probe),
            ("comparison.json", forged_comparison),
            ("target-execution.json", forged_execution),
        ):
            forged_manifest["files"][filename] = {
                "file_sha256": file_sha256(forged_dir / filename),
                "payload_sha256": payload["payload_sha256"],
            }
        ma01_rehash(forged_manifest_path, forged_manifest)
        forged = validate_ma01_manifest(forged_manifest_path)
        assert forged.returncode == 3, forged.stdout + forged.stderr


def test_ma01_bound_manifest_rejects_symlinked_evidence_directory_components() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-parent-link-") as tmp:
        root = Path(tmp).resolve()
        backing_parent = root / "private-backing-parent"
        evidence_dir = backing_parent / "evidence"
        evidence_dir.mkdir(parents=True)
        paths = build_ma01_pass_chain(evidence_dir)

        linked_evidence = root / "linked-evidence"
        linked_evidence.symlink_to(evidence_dir, target_is_directory=True)
        linked_parent = root / "linked-parent"
        linked_parent.symlink_to(backing_parent, target_is_directory=True)

        for manifest in (
            linked_evidence / "target-manifest.json",
            linked_parent / "evidence" / "target-manifest.json",
        ):
            validation = validate_ma01_manifest(manifest)
            transcript = validation.stdout + validation.stderr
            assert validation.returncode == 3, transcript
            assert "symlinked directory" in transcript
            assert "Traceback" not in transcript
            assert str(backing_parent) not in transcript
            assert re.fullmatch(r"[^/]*\n?", transcript)

        assert validate_ma01_manifest(paths["target-manifest.json"]).returncode == 0


def test_ma01_builders_reject_symlinked_relative_and_missing_output_parents() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-builder-paths-") as tmp:
        root = Path(tmp).resolve()
        evidence_dir = root / "private-evidence"
        evidence_dir.mkdir()
        paths = build_ma01_pass_chain(evidence_dir)
        linked_evidence = root / "linked-evidence"
        linked_evidence.symlink_to(evidence_dir, target_is_directory=True)

        relative_execution = Path(
            os.path.relpath(evidence_dir / "relative-execution.json", REPO)
        )
        relative_manifest = Path(
            os.path.relpath(evidence_dir / "relative-manifest.json", REPO)
        )
        missing_parent = root / "private-missing-parent"
        cases = (
            (
                run_ma01_ref_execution_builder(
                    linked_evidence / "escaped-execution.json", paths
                ),
                evidence_dir / "escaped-execution.json",
            ),
            (
                run_ma01_target_manifest_builder(
                    linked_evidence / "escaped-manifest.json", paths
                ),
                evidence_dir / "escaped-manifest.json",
            ),
            (
                run_ma01_ref_execution_builder(relative_execution, paths),
                evidence_dir / "relative-execution.json",
            ),
            (
                run_ma01_target_manifest_builder(relative_manifest, paths),
                evidence_dir / "relative-manifest.json",
            ),
            (
                run_ma01_ref_execution_builder(
                    missing_parent / "missing-execution.json", paths
                ),
                missing_parent / "missing-execution.json",
            ),
            (
                run_ma01_target_manifest_builder(
                    missing_parent / "missing-manifest.json", paths
                ),
                missing_parent / "missing-manifest.json",
            ),
        )

        for result, escaped_output in cases:
            transcript = result.stdout + result.stderr
            assert result.returncode == 3, transcript
            assert "Traceback" not in transcript
            assert str(root) not in transcript
            assert not escaped_output.exists()


def test_ma01_bound_manifest_rejects_coherently_rehashed_fail_to_blocked_downgrade() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-downgrade-") as tmp:
        root = Path(tmp)
        paths = build_ma01_fail_chain(root)
        valid = validate_ma01_manifest(
            paths["target-manifest.json"], status="fail", exit_code=1
        )
        assert valid.returncode == 0, valid.stdout + valid.stderr

        execution_path = paths["target-execution.json"]
        execution = json.loads(execution_path.read_text(encoding="utf-8"))
        execution.update(
            {
                "status": "blocked",
                "exit_code": 3,
                "verdict_sources": ["comparison_blocked", "probe_blocked"],
                "probe_exit_code": 3,
                "comparison_exit_code": 3,
            }
        )
        ma01_rehash(execution_path, execution)

        manifest_path = paths["target-manifest.json"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest.update(
            {
                "status": "blocked",
                "exit_code": 3,
                "verdict_sources": ["comparison_blocked", "probe_blocked"],
            }
        )
        manifest["files"]["target-execution.json"] = {
            "file_sha256": file_sha256(execution_path),
            "payload_sha256": execution["payload_sha256"],
        }
        ma01_rehash(manifest_path, manifest)

        downgraded = validate_ma01_manifest(
            manifest_path, status="blocked", exit_code=3
        )
        assert downgraded.returncode == 3, downgraded.stdout + downgraded.stderr


@pytest.mark.parametrize("outcome", ["pass", "blocked"])
def test_ma01_context_hmac_rejects_full_chain_post_run_rewrites(outcome: str) -> None:
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-ma01-context-rewrite-{outcome}-"
    ) as tmp:
        root = Path(tmp)
        paths = build_ma01_fail_chain(root)
        status, exit_code = rewrite_ma01_fail_chain(paths, outcome)

        validation = validate_ma01_manifest(
            paths["target-manifest.json"], status=status, exit_code=exit_code
        )
        transcript = validation.stdout + validation.stderr
        assert validation.returncode == 3, transcript
        assert "Traceback" not in transcript
        assert str(REPO) not in transcript
        assert TEST_RUN_CONTEXT_KEY not in transcript


@pytest.mark.parametrize("outcome", ["pass", "blocked"])
def test_ma01_context_hmac_rejects_log_only_post_run_rewrites(outcome: str) -> None:
    with tempfile.TemporaryDirectory(
        prefix=f"critical-flows-ma01-log-context-rewrite-{outcome}-"
    ) as tmp:
        root = Path(tmp)
        paths = build_ma01_log_fail_chain(root)
        valid = validate_ma01_manifest(
            paths["target-manifest.json"], status="fail", exit_code=1
        )
        assert valid.returncode == 0, valid.stdout + valid.stderr

        status, exit_code = rewrite_ma01_log_fail_chain(paths, outcome)
        validation = validate_ma01_manifest(
            paths["target-manifest.json"], status=status, exit_code=exit_code
        )
        transcript = validation.stdout + validation.stderr
        assert validation.returncode == 3, transcript
        assert "Traceback" not in transcript
        assert str(REPO) not in transcript
        assert TEST_RUN_CONTEXT_KEY not in transcript


def test_ma01_bound_validator_rejects_resigned_fixture_ensure_state_forgery() -> None:
    with tempfile.TemporaryDirectory(
        prefix="critical-flows-ma01-fixture-forgery-"
    ) as tmp:
        root = Path(tmp).resolve()
        paths = build_ma01_pass_chain(root)
        probe_path = paths["target-probe.json"]
        probe = json.loads(probe_path.read_text(encoding="utf-8"))
        probe["facts"]["fixture"].update(
            {
                "customer_preexisting": True,
                "customer_created": True,
            }
        )
        probe["context_hmac"] = ma01_context_hmac(probe)
        ma01_rehash(probe_path, probe)

        manifest_path = paths["target-manifest.json"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["files"]["target-probe.json"] = {
            "file_sha256": file_sha256(probe_path),
            "payload_sha256": probe["payload_sha256"],
        }
        ma01_rehash(manifest_path, manifest)

        validation = validate_ma01_manifest(manifest_path)
        transcript = validation.stdout + validation.stderr
        assert validation.returncode == 3, transcript
        assert "Traceback" not in transcript
        assert str(root) not in transcript
        assert TEST_RUN_CONTEXT_KEY not in transcript


def test_ma01_bound_manifest_rejects_boolean_execution_exit_code() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-bool-exit-") as tmp:
        root = Path(tmp)
        paths = build_ma01_fail_chain(root)
        execution_path = paths["target-execution.json"]
        execution = json.loads(execution_path.read_text(encoding="utf-8"))
        execution["exit_code"] = True
        ma01_rehash(execution_path, execution)

        manifest_path = paths["target-manifest.json"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["files"]["target-execution.json"] = {
            "file_sha256": file_sha256(execution_path),
            "payload_sha256": execution["payload_sha256"],
        }
        ma01_rehash(manifest_path, manifest)

        validation = validate_ma01_manifest(
            manifest_path, status="fail", exit_code=1
        )
        assert validation.returncode == 3, validation.stdout + validation.stderr


def test_ma01_bound_manifest_rejects_float_manifest_exit_code() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-float-exit-") as tmp:
        root = Path(tmp)
        paths = build_ma01_fail_chain(root)
        manifest_path = paths["target-manifest.json"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["exit_code"] = 1.0
        ma01_rehash(manifest_path, manifest)

        validation = validate_ma01_manifest(
            manifest_path, status="fail", exit_code=1
        )
        assert validation.returncode == 3, validation.stdout + validation.stderr


def test_ma01_validate_comparison_rejects_malformed_typed_json_without_traceback() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-comparison-types-") as tmp:
        root = Path(tmp)
        paths = build_ma01_pass_chain(root)
        valid = json.loads(paths["comparison.json"].read_text(encoding="utf-8"))
        cases = (
            ("status_array", "status", []),
            ("reference_array", "reference", []),
            ("target_scalar", "target", "RAW_TYPED_MARKER"),
        )
        for name, field, value in cases:
            malformed = ma01_clone(valid)
            malformed[field] = value
            malformed["payload_sha256"] = ma01_payload_digest(malformed)
            result = run_ma01(
                "validate-comparison",
                "--run-stamp",
                MA01_RUN_STAMP,
                "--expected-exit-code",
                "0",
                input_text=json.dumps(malformed),
            )
            transcript = result.stdout + result.stderr
            assert result.returncode == 3, (name, transcript)
            assert "Traceback" not in transcript
            assert str(REPO) not in transcript
            assert "RAW_TYPED_MARKER" not in transcript


@pytest.mark.parametrize("artifact_name,field,value", [
    ("target-probe.json", "store", []),
    ("target-probe.json", "status", {}),
    ("target-execution.json", "status", []),
])
def test_ma01_bound_validator_rejects_malformed_typed_artifacts_without_traceback(
    artifact_name: str, field: str, value
) -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-bound-types-") as tmp:
        root = Path(tmp)
        paths = build_ma01_pass_chain(root)
        artifact_path = paths[artifact_name]
        artifact = json.loads(artifact_path.read_text(encoding="utf-8"))
        artifact[field] = value
        ma01_rehash(artifact_path, artifact)

        manifest_path = paths["target-manifest.json"]
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        manifest["files"][artifact_name] = {
            "file_sha256": file_sha256(artifact_path),
            "payload_sha256": artifact["payload_sha256"],
        }
        ma01_rehash(manifest_path, manifest)

        validation = validate_ma01_manifest(manifest_path)
        transcript = validation.stdout + validation.stderr
        assert validation.returncode == 3, transcript
        assert "Traceback" not in transcript
        assert str(REPO) not in transcript


MA01_DRIVER = (
    REPO
    / "tools/woopayments-critical-flows/flows/"
    "class-woopaymentscriticalflowsma01driver.php"
)
MA01_FLOW = (
    REPO
    / "tools/woopayments-critical-flows/flows/"
    "MA-01-open-admin-as-non-admin.sh"
)


def ma01_php_driver_harness_source() -> str:
    """Return isolated WordPress stubs that execute the real MA-01 driver entry."""
    return r'''
define( 'MINUTE_IN_SECONDS', 60 );
$config = json_decode( getenv( 'MA01_TEST_CONFIG' ) ?: '{}', true );
$config = is_array( $config ) ? $config : array();
$trace = array(
    'insert_count' => 0,
    'actor_switches' => array(),
    'rest_calls' => array(),
    'session_create_count' => 0,
    'session_destroy_tokens' => array(),
    'session_verify_tokens' => array(),
    'cookie_calls' => array(),
    'http_calls' => array(),
);
$current_user_id = 1;
$customer_inserted = false;

class WP_User {
    public int $ID;
    public array $roles;
    public function __construct( int $id, array $roles ) {
        $this->ID = $id;
        $this->roles = $roles;
    }
}
class WP_Error {}
class WP_REST_Request {
    public string $method;
    public string $route;
    public array $query = array();
    public function __construct( string $method, string $route ) {
        $this->method = $method;
        $this->route = $route;
    }
    public function set_query_params( array $query ): void { $this->query = $query; }
}
class WP_REST_Response {
    private int $status;
    private $data;
    public function __construct( $data, int $status ) {
        $this->data = $data;
        $this->status = $status;
    }
    public function get_status(): int { return $this->status; }
    public function get_data() { return $this->data; }
}
class FakeMa01SessionManager {
    private bool $destroyed = false;
    public function create( int $expiration ): string {
        ++$GLOBALS['trace']['session_create_count'];
        $GLOBALS['trace']['session_expiration'] = $expiration;
        return 'session-secret-token';
    }
    public function destroy( string $token ): void {
        $GLOBALS['trace']['session_destroy_tokens'][] = $token;
        if ( 'session-secret-token' === $token ) { $this->destroyed = true; }
    }
    public function verify( string $token ) {
        $GLOBALS['trace']['session_verify_tokens'][] = $token;
        return $this->destroyed && 'session-secret-token' === $token ? false : array( 'expiration' => 1 );
    }
}
class WP_Session_Tokens {
    public static function get_instance( int $user_id ): FakeMa01SessionManager {
        $GLOBALS['trace']['session_user_id'] = $user_id;
        if ( ! isset( $GLOBALS['session_manager'] ) ) {
            $GLOBALS['session_manager'] = new FakeMa01SessionManager();
        }
        return $GLOBALS['session_manager'];
    }
}

function get_user_by( string $field, $value ) {
    if ( 'id' === $field && 1 === (int) $value ) { return new WP_User( 1, array( 'administrator' ) ); }
    if ( 'id' === $field && 22 === (int) $value ) { return new WP_User( 22, array( 'customer' ) ); }
    if ( 'login' === $field && 'ma01-customer' === $value ) {
        if ( ! empty( $GLOBALS['config']['existing_customer'] ) || $GLOBALS['customer_inserted'] ) {
            return new WP_User( 22, array( 'customer' ) );
        }
    }
    return false;
}
function wp_generate_password( int $length, bool $special, bool $extra ): string {
    return 'generated-password-secret';
}
function wp_insert_user( array $user ) {
    ++$GLOBALS['trace']['insert_count'];
    $GLOBALS['trace']['insert_login'] = $user['user_login'] ?? '';
    $GLOBALS['trace']['insert_role'] = $user['role'] ?? '';
    $GLOBALS['customer_inserted'] = true;
    return 22;
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function user_can( WP_User $user, string $capability ): bool { return 1 === $user->ID; }
function get_woocommerce_currency(): string { return 'USD'; }
function get_option( string $name, $default = null ) {
    return 'active_plugins' === $name
        ? array( 'woocommerce-payments/woocommerce-payments.php' )
        : $default;
}
function is_multisite(): bool { return false; }
function get_site_option( string $name, $default = null ) { return $default; }
function get_current_user_id(): int { return $GLOBALS['current_user_id']; }
function wp_set_current_user( int $user_id ): void {
    $GLOBALS['current_user_id'] = $user_id;
    $GLOBALS['trace']['actor_switches'][] = $user_id;
}
function rest_do_request( WP_REST_Request $request ): WP_REST_Response {
    $GLOBALS['trace']['rest_calls'][] = array(
        'actor' => $GLOBALS['current_user_id'],
        'route' => $request->route,
        'query' => $request->query,
    );
    if ( 22 === $GLOBALS['current_user_id'] ) {
        $data = array_key_exists( 'customer_data', $GLOBALS['config'] )
            ? $GLOBALS['config']['customer_data']
            : array(
                'code' => 'rest_forbidden',
                'message' => 'Forbidden.',
                'data' => array( 'status' => 403 ),
            );
        return new WP_REST_Response( $data, (int) ( $GLOBALS['config']['customer_status'] ?? 403 ) );
    }
    return new WP_REST_Response( array( 'data' => array() ), 200 );
}
function wp_generate_auth_cookie( int $user_id, int $expiration, string $scheme, string $token ): string {
    $GLOBALS['trace']['cookie_calls'][] = array( 'scheme' => $scheme, 'token' => $token );
    return 'cookie-secret|' . $scheme . '|' . $token;
}
function wp_remote_get( string $url, array $arguments ) {
    $GLOBALS['trace']['http_calls'][] = array( 'url' => $url, 'arguments' => $arguments );
    $call = count( $GLOBALS['trace']['http_calls'] );
    $mode = (string) ( $GLOBALS['config']['http_mode'] ?? 'same_origin_redirect' );
    if ( 'exception' === $mode ) { throw new RuntimeException( 'test HTTP exception' ); }
    if ( 1 === $call && 'off_origin_redirect' === $mode ) {
        return array( 'status' => 302, 'headers' => array( 'location' => 'https://example.test/escape' ), 'body' => '' );
    }
    if ( 1 === $call && 'same_origin_redirect' === $mode ) {
        return array( 'status' => 302, 'headers' => array( 'location' => '/my-account/' ), 'body' => '' );
    }
    return array(
        'status' => 200,
        'headers' => array(),
        'body' => '<body class="logged-in"><a href="?customer-logout=1">Sign out</a></body>',
    );
}
function wp_remote_retrieve_response_code( array $response ): int { return $response['status']; }
function wp_remote_retrieve_header( array $response, string $name ): string {
    return (string) ( $response['headers'][strtolower( $name )] ?? '' );
}
function wp_remote_retrieve_body( array $response ): string { return $response['body']; }
function wp_parse_url( string $url, int $component = -1 ) {
    return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
}
function wp_json_encode( $value, int $flags = 0 ): string { return json_encode( $value, $flags ); }

$args = array( 'ref', 'probe', '20260718T120000Z-10101', 'http://localhost:8082' );
require $argv[1];
$trace['final_user_id'] = $current_user_id;
$trace['no_proxy_after'] = getenv( 'NO_PROXY' );
$trace['no_proxy_lower_after'] = getenv( 'no_proxy' );
echo '__TRACE__' . json_encode( $trace ) . "\n";
'''


def run_ma01_php_driver(config: dict | None = None) -> tuple[dict, dict, str]:
    """Execute the real PHP entry against deterministic isolated WordPress stubs."""
    result = subprocess.run(
        ["php", "-r", ma01_php_driver_harness_source(), str(MA01_DRIVER)],
        cwd=REPO,
        text=True,
        capture_output=True,
        check=False,
        env={
            **os.environ,
            "MA01_TEST_CONFIG": json.dumps(config or {}, separators=(",", ":")),
            "NO_PROXY": "original-upper",
            "no_proxy": "original-lower",
        },
    )
    assert result.returncode == 0, result.stdout + result.stderr
    lines = [line for line in result.stdout.splitlines() if line]
    assert len(lines) == 2, result.stdout + result.stderr
    assert lines[1].startswith("__TRACE__")
    return json.loads(lines[0]), json.loads(lines[1][len("__TRACE__") :]), result.stderr


def ma01_fake_wp_source(
    owner: str,
    home: str,
    raw_probe: dict,
    *,
    probe_exit_code: int = 0,
    log_probe_status: str = "pass",
    wrapper_noise: str = "",
) -> str:
    """Fake WP runner for one MA-01 probe plus shared identity/log observations."""
    store = "ref" if owner == "plugin" else "target"
    log_matches = (
        []
        if log_probe_status != "fail"
        else [safe_log_record(line=5, diagnostic="PHP Warning: fake MA-01 warning")]
    )
    log_scan = common_log_scan_v5(
        status=log_probe_status,
        run_stamp=TEST_RUN_STAMP,
        store=store,
        flow_id="MA-01-open-admin-as-non-admin",
        purpose="clean-debug-log",
        marker_created_at=TEST_MARKER_CREATED_AT,
        end_line_count=5 if log_matches else 4,
        end_byte_count=160 if log_matches else 128,
        matches=log_matches,
        blocker_code="no_configured_paths" if log_probe_status == "blocked" else "",
    )
    raw_json = json.dumps(raw_probe, separators=(",", ":"))
    log_json = json.dumps(log_scan, separators=(",", ":"))
    noise_command = (
        f"printf '%s\\n' {shlex.quote(wrapper_noise)}" if wrapper_noise else ":"
    )
    return f"""#!/usr/bin/env bash
set -u
{authenticated_log_fake_prelude(log_scan, observer_categories="warning" if log_matches else "")}
if [ "${{1:-}}" = "--user=1" ]; then shift; fi
if [ "${{1:-}}" = "eval-file" ]; then
  body="$(cat)"
  if [[ "$body" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass"}}'
    exit 0
  fi
  if [[ "$body" == *"ignored_matches"* ]]; then
    printf '%s\n' '{log_json}'
    exit 0
  fi
  if [[ "$body" == *"woopayments_ma01_probe.v1"* ]]; then
    {noise_command}
    printf '%s\n' '{raw_json}'
    exit {probe_exit_code}
  fi
fi
if [ "${{1:-}}" = "eval" ]; then
  if [[ "${{2:-}}" == *"store_identity_owner"* ]]; then
    printf '%s\n' 'store_identity_owner={owner}'
    printf '%s\n' 'store_identity_home={home}'
    exit 0
  fi
  if [[ "${{2:-}}" == *"update_option"*"woopayments_critical_flows_debug_log_marker"* ]]; then
    printf '%s\n' '{{"status":"pass","paths":["/tmp/fake-debug.log"],"markers":{{"/tmp/fake-debug.log":0}}}}'
    exit 0
  fi
  printf '%s\n' '{log_json}'
  exit 0
fi
printf 'unexpected fake WP call: %s\n' "$*" >&2
exit 2
"""


def run_ma01_runner_case(
    root: Path,
    *,
    store: str = "both",
    ref_raw: dict | None = None,
    target_raw: dict | None = None,
    ref_probe_exit_code: int = 0,
    target_probe_exit_code: int = 0,
    wrapper_noise: str = "",
) -> subprocess.CompletedProcess[str]:
    """Run the repository MA-01 flow through the real deterministic runner."""
    root = root.resolve()
    evidence_dir = root / "evidence"
    bin_dir = root / "bin"
    evidence_dir.mkdir()
    bin_dir.mkdir()
    fake_ref_wp = bin_dir / "fake-ref-wp.sh"
    fake_target_wp = bin_dir / "fake-target-wp.sh"
    write_executable(
        fake_ref_wp,
        ma01_fake_wp_source(
            "plugin",
            "http://localhost:8082",
            ref_raw or ma01_raw_probe("ref", run_stamp=TEST_RUN_STAMP),
            probe_exit_code=ref_probe_exit_code,
            wrapper_noise=wrapper_noise,
        ),
    )
    write_executable(
        fake_target_wp,
        ma01_fake_wp_source(
            "native",
            "http://store8889.localhost:8889",
            target_raw or ma01_raw_probe("target", run_stamp=TEST_RUN_STAMP),
            probe_exit_code=target_probe_exit_code,
            wrapper_noise=wrapper_noise,
        ),
    )
    return run_runner(
        "--store",
        store,
        "--layer",
        "deterministic",
        "--flow",
        "MA-01",
        "--ref-url",
        "http://localhost:8082",
        "--target-url",
        "http://store8889.localhost:8889",
        evidence_dir=evidence_dir,
        extra_env={
            "REF_WP_COMMAND": str(fake_ref_wp),
            "TARGET_WP_COMMAND": str(fake_target_wp),
        },
    )


def test_ma01_real_flow_and_runner_bind_dual_store_pass_without_raw_archive() -> None:
    raw_only_noise = (
        '<html><body>Authorization: Bearer ma01_raw_token Cookie: wordpress_logged_in_raw; '
        'password=ma01-secret customer@example.test financial_rows=[{"amount":999}]</body></html>'
    )
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-runner-pass-") as tmp:
        root = Path(tmp)
        result = run_ma01_runner_case(root, wrapper_noise=raw_only_noise)

        assert result.returncode == 0, result.stdout + result.stderr
        assert "[MA-01/ref] deterministic verdict: PASS" in result.stdout
        assert "[MA-01/target] deterministic verdict: PASS" in result.stdout
        assert "cross-store access-control parity: PASS" in result.stdout
        rollup = json.loads((root / "evidence/rollup.json").read_text(encoding="utf-8"))
        rows = [
            row
            for row in strip_recorded_at(rollup)
            if row["flow"] == "MA-01-open-admin-as-non-admin"
        ]
        assert len(rows) == 2, "the markdown fallback must not duplicate the wired shell flow"
        assert {row["store"]: row["status"] for row in rows} == {
            "ref": "PASS",
            "target": "PASS",
        }
        expected_files = {
            "ref": {"ref-probe.json", "ref-execution.json"},
            "target": {
                "ref-probe.json",
                "target-probe.json",
                "comparison.json",
                "target-execution.json",
            },
        }
        for row in rows:
            manifest_path = Path(row["evidence_path"])
            assert row["evidence_sha256"] == file_sha256(manifest_path)
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            assert manifest["schema"] == "woopayments_ma01_manifest.v1"
            assert manifest["run_stamp"] == TEST_RUN_STAMP
            assert manifest["run_scope"] == "partial"
            assert manifest["store"] == row["store"]
            assert manifest["status"] == "pass"
            assert set(manifest["files"]) == expected_files[row["store"]]

        archived_text = result.stdout + result.stderr
        for path in (root / "evidence").rglob("*"):
            if path.is_file():
                archived_text += path.read_text(encoding="utf-8", errors="replace")
        for forbidden in (
            raw_only_noise,
            "<html>",
            "Authorization:",
            "Cookie:",
            "wordpress_logged_in_raw",
            "ma01_raw_token",
            "ma01-secret",
            "customer@example.test",
            "financial_rows",
            '"amount":999',
        ):
            assert forbidden not in archived_text
        assert not list((root / "evidence").rglob("*raw*"))


def test_ma01_real_flow_accepts_specific_permission_denial_as_authentication_proof() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-permission-proof-") as tmp:
        root = Path(tmp)
        ref_raw = ma01_raw_probe("ref", run_stamp=TEST_RUN_STAMP)
        target_raw = ma01_raw_probe("target", run_stamp=TEST_RUN_STAMP)
        for raw in (ref_raw, target_raw):
            raw["http"].update(
                {
                    "logged_in_marker": False,
                    "logout_marker": False,
                    "permission_marker": True,
                }
            )

        result = run_ma01_runner_case(root, ref_raw=ref_raw, target_raw=target_raw)

        assert result.returncode == 0, result.stdout + result.stderr
        assert "[MA-01/ref] deterministic verdict: PASS" in result.stdout
        assert "[MA-01/target] deterministic verdict: PASS" in result.stdout


@pytest.mark.parametrize(
    "existing_customer,expected_preexisting,expected_created,expected_inserts",
    [(True, True, False, 0), (False, False, True, 1)],
)
def test_ma01_real_php_driver_reports_truthful_fixture_ensure_state(
    existing_customer: bool,
    expected_preexisting: bool,
    expected_created: bool,
    expected_inserts: int,
) -> None:
    payload, trace, stderr = run_ma01_php_driver(
        {"existing_customer": existing_customer}
    )

    assert stderr == ""
    assert payload["fixture"]["customer_preexisting"] is expected_preexisting
    assert payload["fixture"]["customer_created"] is expected_created
    assert payload["fixture"]["customer_preexisting"] != payload["fixture"][
        "customer_created"
    ]
    assert trace["insert_count"] == expected_inserts
    result, normalized = normalize_ma01(payload)
    assert result.returncode == 0, result.stdout + result.stderr
    assert normalized["status"] == "pass"


@pytest.mark.parametrize(
    "name,response_data,expected_standard,expected_absent,expected_rc",
    [
        (
            "exact-standard-403",
            {"code": "rest_forbidden", "message": "Forbidden.", "data": {"status": 403}},
            True,
            True,
            0,
        ),
        (
            "top-level-rows",
            {
                "code": "rest_forbidden",
                "message": "Forbidden.",
                "data": {"status": 403},
                "transactions": [],
            },
            False,
            False,
            1,
        ),
        (
            "nested-rows",
            {
                "code": "rest_forbidden",
                "message": "Forbidden.",
                "data": {"status": 403, "rows": []},
            },
            False,
            False,
            1,
        ),
        ("top-level-list", [], False, False, 1),
    ],
)
def test_ma01_real_php_driver_projects_customer_errors_fail_closed(
    name: str,
    response_data,
    expected_standard: bool,
    expected_absent: bool,
    expected_rc: int,
) -> None:
    payload, _, _ = run_ma01_php_driver(
        {"existing_customer": True, "customer_data": response_data}
    )

    for route in payload["routes"].values():
        assert route["customer"]["standard_error"] is expected_standard, name
        assert route["customer"]["financial_list_absent"] is expected_absent, name
    result, normalized = normalize_ma01(payload)
    assert result.returncode == expected_rc, (name, result.stdout, result.stderr)
    assert normalized["status"] == ("pass" if expected_rc == 0 else "fail")


def test_ma01_real_php_driver_exercises_actors_queries_session_and_local_http() -> None:
    payload, trace, _ = run_ma01_php_driver({"existing_customer": True})

    assert [call["actor"] for call in trace["rest_calls"]] == [22, 1] * 3
    assert [call["route"] for call in trace["rest_calls"]] == [
        MA01_ROUTES["transactions"],
        MA01_ROUTES["transactions"],
        MA01_ROUTES["deposits"],
        MA01_ROUTES["deposits"],
        MA01_ROUTES["disputes"],
        MA01_ROUTES["disputes"],
    ]
    list_query = {
        "page": 1,
        "pagesize": 1,
        "sort": "date",
        "direction": "desc",
        "store_currency_is": "usd",
    }
    assert trace["rest_calls"][0]["query"] == list_query
    assert trace["rest_calls"][2]["query"] == list_query
    assert trace["rest_calls"][4]["query"] == {"page": 1, "pagesize": 1}
    assert trace["final_user_id"] == 1
    assert trace["session_create_count"] == 1
    assert trace["session_destroy_tokens"] == ["session-secret-token"]
    assert trace["session_verify_tokens"] == ["session-secret-token"]
    assert trace["session_user_id"] == 22
    assert len(trace["http_calls"]) == 2
    assert trace["http_calls"][0]["url"].startswith("http://127.0.0.1/wp-admin/")
    assert trace["http_calls"][1]["url"] == "http://127.0.0.1/my-account/"
    request_arguments = trace["http_calls"][0]["arguments"]
    assert request_arguments["redirection"] == 0
    assert request_arguments["headers"]["Host"] == "localhost:8082"
    cookie_header = request_arguments["headers"]["Cookie"]
    cookie_hash = hashlib.md5(b"http://localhost:8082").hexdigest()
    assert f"wordpress_{cookie_hash}=" in cookie_header
    assert f"wordpress_sec_{cookie_hash}=" in cookie_header
    assert f"wordpress_logged_in_{cookie_hash}=" in cookie_header
    assert payload["http"]["redirect_count"] == 1
    assert payload["http"]["final_path"] == "/my-account/"
    assert payload["exact_session_cleanup"] is True
    assert trace["no_proxy_after"] == "original-upper"
    assert trace["no_proxy_lower_after"] == "original-lower"
    encoded_payload = json.dumps(payload)
    for forbidden in (
        "session-secret-token",
        "cookie-secret",
        "generated-password-secret",
        "ma01-customer@example.com",
        "Cookie",
        "response_body",
    ):
        assert forbidden not in encoded_payload


def test_ma01_real_php_driver_cleans_session_and_proxy_after_http_exception() -> None:
    payload, trace, _ = run_ma01_php_driver(
        {"existing_customer": True, "http_mode": "exception"}
    )

    assert payload["exact_session_cleanup"] is True
    assert payload["blockers"] == ["authenticated_http_probe_unavailable"]
    assert trace["session_create_count"] == 1
    assert trace["session_destroy_tokens"] == ["session-secret-token"]
    assert trace["session_verify_tokens"] == ["session-secret-token"]
    assert trace["no_proxy_after"] == "original-upper"
    assert trace["no_proxy_lower_after"] == "original-lower"


def test_ma01_real_php_driver_refuses_off_origin_redirect_and_cleans_session() -> None:
    payload, trace, _ = run_ma01_php_driver(
        {"existing_customer": True, "http_mode": "off_origin_redirect"}
    )

    assert len(trace["http_calls"]) == 1
    assert payload["http"]["transport_errors"] == ["redirect_not_same_origin"]
    assert payload["http"]["redirect_count"] == 0
    assert payload["exact_session_cleanup"] is True
    assert trace["session_destroy_tokens"] == ["session-secret-token"]
    assert trace["no_proxy_after"] == "original-upper"
    assert trace["no_proxy_lower_after"] == "original-lower"


@pytest.mark.parametrize("unsafe_kind", ["symlink", "traversal", "mkdir-failure"])
def test_ma01_flow_rejects_unsafe_archive_before_wp_probe(unsafe_kind: str) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma01-path-{unsafe_kind}-") as tmp:
        root = Path(tmp)
        outside = root / "outside"
        outside.mkdir()
        if unsafe_kind == "symlink":
            evidence_dir = root / "evidence-link"
            evidence_dir.symlink_to(outside, target_is_directory=True)
        elif unsafe_kind == "traversal":
            safe = root / "safe"
            safe.mkdir()
            evidence_dir = safe / ".." / "outside"
        else:
            non_directory = root / "not-a-directory"
            non_directory.write_text("sentinel", encoding="utf-8")
            evidence_dir = non_directory / "evidence"
        called = root / "wp-called"
        fake_wp = root / "fake-wp.sh"
        write_executable(
            fake_wp,
            f"#!/usr/bin/env bash\nprintf called > {shlex.quote(str(called))}\nexit 99\n",
        )
        result = subprocess.run(
            ["bash", str(MA01_FLOW)],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
            env={
                **os.environ,
                "STORE_NAME": "ref",
                "CRITICAL_FLOWS_RUN_STAMP": TEST_RUN_STAMP,
                "CRITICAL_FLOWS_RUN_SCOPE": "partial",
                "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
                "CRITICAL_FLOWS_FLOW_ID": "MA-01-open-admin-as-non-admin",
                "CRITICAL_FLOWS_LOG_PURPOSE": "clean-debug-log",
                "EVIDENCE_DIR": str(evidence_dir),
                "REF_WP_COMMAND": str(fake_wp),
                "REF_URL": "http://localhost:8082",
                "TARGET_URL": "http://store8889.localhost:8889",
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        assert not called.exists()
        assert list(outside.iterdir()) == []


@pytest.mark.parametrize(
    "outcome,expected_rc,expected_status,mutate",
    [
        (
            "functional-fail",
            1,
            "FAIL",
            lambda raw: raw["routes"]["transactions"]["customer"].update(
                {"status": 200, "code": "", "standard_error": False, "financial_list_absent": False}
            ),
        ),
        (
            "transport-blocked",
            3,
            "BLOCKED",
            lambda raw: raw["http"]["transport_errors"].append("http_request_failed"),
        ),
    ],
)
def test_ma01_real_flow_propagates_functional_fail_and_blocked(
    outcome: str, expected_rc: int, expected_status: str, mutate
) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma01-{outcome}-") as tmp:
        root = Path(tmp)
        target_raw = ma01_raw_probe("target", run_stamp=TEST_RUN_STAMP)
        mutate(target_raw)
        result = run_ma01_runner_case(root, target_raw=target_raw)

        assert result.returncode == expected_rc, result.stdout + result.stderr
        rollup = json.loads((root / "evidence/rollup.json").read_text(encoding="utf-8"))
        target_row = next(row for row in rollup["results"] if row["store"] == "target")
        assert target_row["status"] == expected_status
        assert target_row["exit_code"] == expected_rc
        assert f"[MA-01/target] deterministic verdict: {expected_status}" in result.stdout


def test_ma01_target_only_is_blocked_without_same_invocation_reference_probe() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-flows-ma01-target-only-") as tmp:
        root = Path(tmp)
        result = run_ma01_runner_case(root, store="target")

        assert result.returncode == 3, result.stdout + result.stderr
        assert "cross-store access-control parity: BLOCKED" in result.stdout
        comparison = (
            root
            / "evidence/runs"
            / f"{TEST_RUN_STAMP}-partial/MA-01-open-admin-as-non-admin/comparison.json"
        )
        payload = json.loads(comparison.read_text(encoding="utf-8"))
        assert payload["status"] == "blocked"
        assert any("Reference input is invalid" in blocker for blocker in payload["blockers"])
        row = json.loads((root / "evidence/rollup.json").read_text(encoding="utf-8"))[
            "results"
        ][0]
        assert row["status"] == "BLOCKED"
        assert row["exit_code"] == 3
        assert "evidence_path" not in row


@pytest.mark.parametrize(
    "attack",
    ["missing", "malformed", "stale", "forged", "contradictory"],
)
def test_ma01_runner_converts_apparent_pass_with_invalid_manifest_to_blocked(
    attack: str,
) -> None:
    with tempfile.TemporaryDirectory(prefix=f"critical-flows-ma01-manifest-{attack}-") as tmp:
        root = Path(tmp).resolve()
        evidence_dir = root / "evidence"
        flows_dir = root / "flows"
        bin_dir = root / "bin"
        evidence_dir.mkdir()
        flows_dir.mkdir()
        bin_dir.mkdir()
        fake_target_wp = bin_dir / "fake-target-wp.sh"
        write_executable(
            fake_target_wp,
            probe_only_fake_wp_source(
                "native",
                "http://store8889.localhost:8889",
                flow_id="MA-01-open-admin-as-non-admin",
            ),
        )
        manifest_source = root / "attack-manifest.json"
        if attack == "malformed":
            manifest_source.write_text("{not-json\n", encoding="utf-8")
        elif attack != "missing":
            manifest = {
                "schema": "woopayments_ma01_manifest.v1",
                "flow": "MA-01-open-admin-as-non-admin",
                "run_stamp": (
                    "20260718T110000Z-10000" if attack == "stale" else TEST_RUN_STAMP
                ),
                "run_scope": "partial",
                "store": "target",
                "status": "fail" if attack == "contradictory" else "pass",
                "exit_code": 1 if attack == "contradictory" else 0,
                "verdict_sources": ["probe_failed"] if attack == "contradictory" else [],
                "files": {},
            }
            manifest["payload_sha256"] = ma01_payload_digest(manifest)
            manifest_source.write_text(
                json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
            )
        flow_script = flows_dir / "MA-01-open-admin-as-non-admin.sh"
        write_executable(
            flow_script,
            """#!/usr/bin/env bash
manifest_dir="$EVIDENCE_DIR/runs/$CRITICAL_FLOWS_RUN_STAMP-$CRITICAL_FLOWS_RUN_SCOPE/MA-01-open-admin-as-non-admin"
mkdir -p "$manifest_dir"
if [ -n "${MA01_FAKE_MANIFEST:-}" ]; then
  cp "$MA01_FAKE_MANIFEST" "$manifest_dir/target-manifest.json"
fi
echo '[MA-01/target] deterministic verdict: PASS'
exit 0
""",
        )

        result = run_runner(
            "--store",
            "target",
            "--layer",
            "deterministic",
            "--flow",
            "MA-01",
            evidence_dir=evidence_dir,
            extra_env={
                "FLOWS_DIR": str(flows_dir),
                "TARGET_WP_COMMAND": str(fake_target_wp),
                "MA01_FAKE_MANIFEST": "" if attack == "missing" else str(manifest_source),
            },
        )

        assert result.returncode == 3, result.stdout + result.stderr
        rollup = json.loads((evidence_dir / "rollup.json").read_text(encoding="utf-8"))
        row = strip_recorded_at(rollup)[0]
        assert row["status"] == "BLOCKED"
        assert row["exit_code"] == 3
        assert "MA-01" in row["reason"]
        assert "evidence_path" not in row
        assert "evidence_sha256" not in row


def test_ma01_driver_source_contract_contains_exact_local_secret_safe_probe() -> None:
    source = MA01_DRIVER.read_text(encoding="utf-8")

    assert "finally {" in source
    assert "$session_manager->destroy( $session_token );" in source
    assert "false === $session_manager->verify( $session_token )" in source
    assert "destroy_all" not in source
    assert "delete_user" not in source
    assert "wp_delete_user" not in source
    assert "'ref'    => 'http://localhost:8082'" in source
    assert "'target' => 'http://store8889.localhost:8889'" in source
    assert "'ref'    => 'http://127.0.0.1'" in source
    assert "'target' => 'http://wordpress'" in source
    assert "md5( $external_origin )" in source
    assert "'wordpress_' . $cookie_hash" in source
    assert "'wordpress_sec_' . $cookie_hash" in source
    assert "'wordpress_logged_in_' . $cookie_hash" in source
    assert "wp_generate_auth_cookie" in source
    assert "rawurlencode( $cookie_value )" in source
    assert re.search(r"wp_remote_get\(\s*\$internal_url\s*,", source)
    assert "'redirection' => 0" in source
    assert re.search(r"'Host'\s*=>\s*\$external_host", source)
    assert "NO_PROXY" in source and "no_proxy" in source
    assert "customer-logout" in source
    assert "woocommerce-MyAccount-navigation-link--customer-logout" in source
    assert "action=logout" in source
    assert "You need a higher level of permission" in source
    assert "Sorry, you are not allowed to access this page" in source
    assert "wp-die-message" not in source
    assert "woopaymentsSettings" in source
    assert len(re.findall(r"'store_currency_is'\s*=>\s*\$store_currency", source)) == 2
    assert len(re.findall(r"'page'\s*=>\s*1", source)) == 3
    assert len(re.findall(r"'pagesize'\s*=>\s*1", source)) == 3
    assert len(re.findall(r"'sort'\s*=>\s*'date'", source)) == 2
    assert len(re.findall(r"'direction'\s*=>\s*'desc'", source)) == 2
    assert source.count("/wp-admin/admin.php?page=wc-admin&path=/payments/overview") == 1
    assert source.count("/wp-admin/admin.php?page=wc-admin&path=/woopayments/overview") == 1
    payload_start = source.index("private static function initial_payload")
    payload_end = source.index("private static function", payload_start + 1)
    payload_source = source[payload_start:payload_end]
    for forbidden_field in (
        "password",
        "email",
        "token",
        "cookie",
        "header",
        "body",
        "row",
        "count",
        "message",
        "id",
    ):
        assert f"'{forbidden_field}' =>" not in payload_source


def test_ma01_driver_projects_only_specific_auth_denial_and_app_markers() -> None:
    php_source = r'''
function wp_json_encode( $value, $flags = 0 ) {
    return json_encode( $value, $flags );
}
$args = array( 'invalid', 'invalid', 'invalid', 'invalid' );
ob_start();
require $argv[1];
ob_end_clean();
$markers = array();
$method = new ReflectionMethod( 'WooPaymentsCriticalFlowsMa01Driver', 'project_body_markers' );
$arguments = array( $argv[2], &$markers );
$method->invokeArgs( null, $arguments );
echo json_encode( $markers );
'''

    def project(body: str) -> dict:
        result = subprocess.run(
            ["php", "-r", php_source, str(MA01_DRIVER), body],
            cwd=REPO,
            text=True,
            capture_output=True,
            check=False,
        )
        assert result.returncode == 0, result.stdout + result.stderr
        return json.loads(result.stdout)

    assert project(
        '<body class="logged-in"><a href="?customer-logout=1">Sign out</a></body>'
    )["logout_marker"] is True
    assert project(
        '<body class="logged-in"><a class="woocommerce-MyAccount-navigation-link--customer-logout">Sign out</a></body>'
    )["logout_marker"] is True
    assert project('<body class="logged-in"><a href="?action=logout">Sign out</a></body>')[
        "logout_marker"
    ] is True
    assert project('<div class="wp-die-message">A database error occurred.</div>')[
        "permission_marker"
    ] is False
    assert project("You need a higher level of permission")["permission_marker"] is True
    assert project("Sorry, you are not allowed to access this page")[
        "permission_marker"
    ] is True
    assert project('<script>window.woopaymentsSettings = {};</script>')[
        "app_marker"
    ] is True


def test_ma01_shell_and_runner_source_contract_is_manifest_bound_and_raw_free() -> None:
    flow = MA01_FLOW.read_text(encoding="utf-8")
    runner = RUNNER.read_text(encoding="utf-8")

    assert 'source "$DIR/../lib/common.sh"' in flow
    assert 'wp_store "$S" --user=1 eval-file - "$S" probe "$RUN_STAMP" "$BASE_URL"' in flow
    assert "normalize-probe" in flow
    assert "assert_log_clean" in flow
    assert "ref-probe.json" in flow and "target-probe.json" in flow
    assert "comparison.json" in flow
    assert "raw-probe" not in flow
    assert "raw.html" not in flow
    assert "validate_ma01_manifest()" in runner
    assert 'python3 "$DIR/flows/ma01-evidence.py" validate-bound-manifest' in runner
    assert 'if [ "$base" = "MA-01-open-admin-as-non-admin" ]' in runner


def test_md01_shell_and_runner_source_contract_is_manifest_bound() -> None:
    flow = MD01_FLOW.read_text(encoding="utf-8")
    runner = RUNNER.read_text(encoding="utf-8")

    assert 'source "$DIR/../lib/common.sh"' in flow
    assert 'args=(dispute --deterministic' in flow
    assert 'wp_store "$S" --user=1 eval-file - "$S" probe "$RUN_STAMP" "$ORDER_ID" "$CHARGE_ID" "$INTENT_ID"' in flow
    assert 'WP="$RECONCILE_WP" bash "$RECONCILER" "$ORDER_ID"' in flow
    assert "normalize-probe" in flow and "compare" in flow
    assert "assert_log_clean" in flow
    assert "ref-probe.json" in flow and "target-probe.json" in flow
    assert "ref-webhook-order.json" in flow and "target-webhook-order.json" in flow
    assert "wpcom-local --json jobs run-one --id" in flow
    assert "comparison.json" in flow
    assert "validate_md01_manifest()" in runner
    assert 'python3 "$DIR/flows/md01-evidence.py" validate-bound-manifest' in runner
    assert 'elif [ "$base" = "MD-01-created-note-on-hold-notify" ]' in runner


def test_md02_shell_and_runner_source_contract_is_manifest_bound() -> None:
    flow = MD02_FLOW.read_text(encoding="utf-8")
    runner = RUNNER.read_text(encoding="utf-8")

    assert 'source "$DIR/../lib/common.sh"' in flow
    assert 'python3 "$GATE"' in flow
    assert "--browser-runner playwright" in flow
    assert "assert_md02_check" in flow
    assert "assert_log_clean" in flow
    assert "compare" in flow
    assert "validate-bound-manifest" in flow
    assert "validate_md02_manifest()" in runner
    assert 'python3 "$DIR/flows/md02-evidence.py" validate-bound-manifest' in runner
    assert 'elif [ "$base" = "MD-02-save-evidence" ]; then' in runner
    assert '$base/$s/$s-manifest.json' in runner


def test_md_resolution_runner_source_contract_revalidates_each_outcome_manifest() -> None:
    runner = RUNNER.read_text(encoding="utf-8")

    assert "validate_md_resolution_manifest()" in runner
    assert (
        'python3 "$DIR/flows/md-resolution-evidence.py" validate-bound-manifest'
        in runner
    )
    assert '--outcome "$expected_outcome"' in runner
    assert (
        'elif [ "$base" = "MD-03-winning-dispute" ] || '
        '[ "$base" = "MD-04-losing-dispute" ]; then'
        in runner
    )
    assert 'MD-03-winning-dispute) expected_outcome="won"' in runner
    assert 'MD-04-losing-dispute) expected_outcome="lost"' in runner
    assert '$base/$s/$s-manifest.json' in runner
    assert "resolution deterministic evidence manifest is missing" in runner
    assert 'result_reason="manifest-bound deterministic resolution evidence"' in runner
    assert 'if [ "$rc" -eq 70 ]; then' in runner
    assert "resolution cleanup-fatal" in runner
    assert 'validate_md_resolution_manifest "$manifest_path"' in runner
    assert '2>&1)' in runner


def load_md_resolution_evidence():
    spec = importlib.util.spec_from_file_location(
        "runner_md_resolution_evidence", MD_RESOLUTION_EVIDENCE
    )
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def md_resolution_packet(module, *, store: str, outcome: str) -> dict:
    profile = module.outcome_profile(outcome)
    suffix = f"{store}_{outcome}"
    identity = {
        "order_id": 101 if store == "ref" else 202,
        "charge_id": f"ch_{suffix}",
        "intent_id": f"pi_{suffix}",
        "dispute_id": f"dp_{suffix}",
    }
    journal = [
        module.build_journal_record(
            outcome, store, TEST_RUN_STAMP, 1, "fresh_dispute_create_armed", {}
        ),
        module.build_journal_record(
            outcome, store, TEST_RUN_STAMP, 2, "fresh_dispute_created", identity
        ),
        module.build_journal_record(
            outcome,
            store,
            TEST_RUN_STAMP,
            3,
            "evidence_submit_armed",
            identity,
            marker=profile["evidence_marker"],
        ),
        module.build_journal_record(
            outcome,
            store,
            TEST_RUN_STAMP,
            4,
            "evidence_submit_observed",
            identity,
            marker=profile["evidence_marker"],
            response_class="trusted_success",
        ),
    ]
    submission = {
        "trusted": True,
        "response_class": "trusted_success",
        "submit": True,
        "marker": profile["evidence_marker"],
        "http_status": 200,
        "dispute_id": identity["dispute_id"],
    }
    store_facts = {
        "available": True,
        "dispute_id": identity["dispute_id"],
        "dispute_status": profile["terminal_status"],
        "charge_id": identity["charge_id"],
        "intent_id": identity["intent_id"],
        "order_id": identity["order_id"],
        "order_status": "completed" if outcome == "won" else "refunded",
        "order_total_minor": 5000,
        "currency": "usd",
        "notes": {
            "created": True,
            "evidence_submitted": True,
            "funds_reinstated": outcome == "won",
            "fees_deducted": outcome == "lost",
        },
        "refunds": []
        if outcome == "won"
        else [{"amount_minor": 5000, "reason_family": "dispute"}],
    }
    transactions = [
        {
            "id": f"txn_debit_{suffix}",
            "amount": -5000,
            "fee": 1500,
            "net": -6500,
        }
    ]
    if outcome == "won":
        transactions.append(
            {
                "id": f"txn_reversal_{suffix}",
                "amount": 5000,
                "fee": -1500,
                "net": 6500,
            }
        )
    provider_facts = {
        "available": True,
        "livemode": False,
        "dispute_id": identity["dispute_id"],
        "dispute_status": profile["terminal_status"],
        "charge_id": identity["charge_id"],
        "intent_id": identity["intent_id"],
        "amount": 5000,
        "currency": "usd",
        "balance_transactions": transactions,
    }
    return module.build_store_packet(
        outcome=outcome,
        store=store,
        run_stamp=TEST_RUN_STAMP,
        runtime_owner="plugin" if store == "ref" else "native",
        drive={
            "op": "dispute",
            "order_id": identity["order_id"],
            "charge_id": identity["charge_id"],
            "intent_id": identity["intent_id"],
            "status": "on-hold",
            "order_currency": "USD",
        },
        journal=journal,
        submission=submission,
        store_facts=store_facts,
        provider_facts=provider_facts,
        blockers=[],
    )


def write_json_artifact(path: Path, payload: dict) -> None:
    path.write_text(
        json.dumps(payload, sort_keys=True, separators=(",", ":")) + "\n",
        encoding="utf-8",
    )


def build_md_resolution_manifests(root: Path, *, outcome: str) -> dict[str, Path]:
    module = load_md_resolution_evidence()
    profile = module.outcome_profile(outcome)
    packets = {
        store: md_resolution_packet(module, store=store, outcome=outcome)
        for store in ("ref", "target")
    }
    paths: dict[str, Path] = {}
    for store in ("ref", "target"):
        store_dir = root / profile["flow"] / store
        store_dir.mkdir(parents=True)
        packet_path = store_dir / f"{store}-store-packet.json"
        write_json_artifact(packet_path, packets[store])
        log_scan = module.build_log_scan(
            {
                "schema": "woopayments_debug_log_scan.v6",
                "store": store,
                "scan": {
                    "status": "pass",
                    "run_stamp": TEST_RUN_STAMP,
                    "store": store,
                    "flow_id": profile["flow"],
                    "purpose": "clean-debug-log",
                    "matches": [],
                    "blocker_code": "",
                },
            },
            outcome=outcome,
            store=store,
            run_stamp=TEST_RUN_STAMP,
            expected_exit_code=0,
        )
        log_path = store_dir / f"{store}-log-scan.json"
        write_json_artifact(log_path, log_scan)
        comparison = None
        artifact_paths = [packet_path, log_path]
        if store == "target":
            comparison = module.build_comparison(
                packets["ref"],
                packets["target"],
                outcome=outcome,
                run_stamp=TEST_RUN_STAMP,
            )
            comparison_path = store_dir / "comparison.json"
            write_json_artifact(comparison_path, comparison)
            artifact_paths.append(comparison_path)
        execution = module.build_execution(
            packets[store],
            log_scan,
            comparison=comparison,
            outcome=outcome,
            store=store,
            run_stamp=TEST_RUN_STAMP,
        )
        execution_path = store_dir / f"{store}-execution.json"
        write_json_artifact(execution_path, execution)
        artifact_paths.append(execution_path)
        manifest = module.build_manifest(
            artifact_paths,
            outcome=outcome,
            store=store,
            run_stamp=TEST_RUN_STAMP,
            run_scope="partial",
            status="pass",
            exit_code=0,
        )
        manifest_path = store_dir / f"{store}-manifest.json"
        write_json_artifact(manifest_path, manifest)
        paths[store] = manifest_path
    return paths


def run_md_resolution_runner_validator(
    manifest: Path,
    *,
    store: str,
    outcome: str,
    status: str = "pass",
    exit_code: int = 0,
) -> subprocess.CompletedProcess[str]:
    source = RUNNER.read_text(encoding="utf-8")
    start = source.index("validate_md_resolution_manifest()")
    end = source.index("\nagent_result_verdict()", start)
    function_source = source[start:end]
    script = (
        function_source
        + '\nvalidate_md_resolution_manifest "$1" "$2" "$3" "$4" "$5"\n'
    )
    return subprocess.run(
        ["bash", "-c", script, "bash", str(manifest), store, outcome, status, str(exit_code)],
        cwd=REPO,
        env={
            **os.environ,
            "DIR": str(RUNNER.parent),
            "RUN_STAMP": TEST_RUN_STAMP,
            "RUN_SCOPE": "partial",
            "CRITICAL_FLOWS_RUN_CONTEXT_KEY": TEST_RUN_CONTEXT_KEY,
        },
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


@pytest.mark.parametrize("outcome", ("won", "lost"))
def test_md_resolution_runner_validator_accepts_only_exact_current_manifests(
    monkeypatch, tmp_path: Path, outcome: str
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", TEST_RUN_CONTEXT_KEY)
    manifests = build_md_resolution_manifests(tmp_path, outcome=outcome)

    for store, manifest in manifests.items():
        result = run_md_resolution_runner_validator(
            manifest, store=store, outcome=outcome
        )
        assert result.returncode == 0, result.stdout + result.stderr
        assert re.fullmatch(r"sha256:[0-9a-f]{64}\n", result.stdout)


@pytest.mark.parametrize(
    "corruption",
    (
        "stale_run",
        "wrong_store",
        "wrong_outcome",
        "status_exit",
        "artifact_tamper",
        "missing_artifact",
        "reference_tamper",
    ),
)
def test_md_resolution_runner_validator_rejects_corrupt_or_misbound_evidence(
    monkeypatch, tmp_path: Path, corruption: str
) -> None:
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", TEST_RUN_CONTEXT_KEY)
    manifest_path = build_md_resolution_manifests(tmp_path, outcome="won")["target"]
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if corruption == "stale_run":
        manifest["run_stamp"] = "20260719T000000Z-1"
        write_json_artifact(manifest_path, manifest)
    elif corruption == "wrong_store":
        manifest["store"] = "ref"
        write_json_artifact(manifest_path, manifest)
    elif corruption == "wrong_outcome":
        manifest["outcome"] = "lost"
        write_json_artifact(manifest_path, manifest)
    elif corruption == "status_exit":
        manifest.update(status="blocked", exit_code=0)
        write_json_artifact(manifest_path, manifest)
    elif corruption == "artifact_tamper":
        packet = manifest_path.parent / "target-store-packet.json"
        packet.write_text(packet.read_text(encoding="utf-8") + "\n", encoding="utf-8")
    elif corruption == "missing_artifact":
        (manifest_path.parent / "target-execution.json").unlink()
    else:
        reference_packet = manifest_path.parent.parent / "ref/ref-store-packet.json"
        reference_packet.write_text(
            reference_packet.read_text(encoding="utf-8") + "\n",
            encoding="utf-8",
        )

    result = run_md_resolution_runner_validator(
        manifest_path, store="target", outcome="won"
    )
    assert result.returncode == 3
    assert "BLOCKED:" in result.stderr


def main() -> None:
    tests = [
        test_card_checkout_flow_passes_with_clean_exercised_order,
        test_card_checkout_flow_blocks_when_exerciser_fails,
        test_mc06_forwards_explicit_store_urls_to_rates_gate,
        test_mc06_blocks_before_rates_gate_when_an_explicit_url_is_missing,
        test_agent_layer_queued_specs_are_blocked_until_executed,
        test_full_layer_blocks_when_agent_specs_are_only_queued,
        test_runner_blocks_before_flows_when_target_runtime_owner_is_wrong,
        test_runner_blocks_when_both_stores_resolve_to_the_same_home,
        test_full_scope_run_reports_matrix_coverage_and_refuses_green,
        test_full_scope_run_refuses_green_when_matrix_rows_are_unspecced,
        test_partial_run_rollup_is_marked_partial_and_keeps_status_semantics,
        test_consecutive_runs_are_archived_append_only,
        test_runner_creates_missing_evidence_directory,
        test_agent_layer_accepts_completed_agent_result,
        test_agent_layer_fails_on_functional_agent_result,
        test_agent_layer_preserves_blocked_agent_result_evidence,
        test_agent_layer_fails_target_when_parity_verdict_fails,
        test_agent_layer_blocks_when_result_lacks_requested_store,
        test_log_clean_assertion_passes_when_scan_is_clean,
        test_log_clean_assertion_fails_when_php_errors_are_found,
        test_log_clean_assertion_blocks_when_scan_cannot_run,
        test_log_clean_scan_ignores_known_reference_wpcom_zoho_noise,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
