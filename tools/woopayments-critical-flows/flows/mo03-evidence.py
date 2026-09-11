#!/usr/bin/env python3
"""Normalize, compare, and bind deterministic MO-03 Layer-D evidence."""

from __future__ import annotations

import argparse
from collections import Counter
import contextlib
import hashlib
import hmac
import io
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


RAW_STATE_SCHEMA = "woopayments_mo03_state.v1"
FIXTURE_SCHEMA = "woopayments_mo03_fixture.v1"
STATE_SCHEMA = "woopayments_mo03_normalized.v1"
CAPTURE_SCHEMA = "woopayments_mo03_capture_evidence.v3"
LOG_SCAN_SCHEMA = "woopayments_mo03_log_scan.v6"
COMPARISON_SCHEMA = "woopayments_mo03_comparison.v1"
EXECUTION_SCHEMA = "woopayments_mo03_execution.v1"
MANIFEST_SCHEMA = "woopayments_mo03_manifest.v1"
FLOW = "MO-03-manual-capture-payment-details"
LOG_PURPOSE = "clean-debug-log"
LIST_ROUTE = "/wc/v3/payments/authorizations"

ORDER_FIELDS = {
    "id": int,
    "created": int,
    "status": str,
    "paid": bool,
    "currency": str,
    "total_minor": int,
    "payment_method": str,
    "intent_id": str,
    "charge_id": str,
    "intention_status": str,
}
PROVIDER_FIELDS = {
    "intent_id": str,
    "intent_status": str,
    "intent_amount_minor": int,
    "intent_currency": str,
    "charge_id": str,
    "charge_amount_minor": int,
    "charge_amount_captured_minor": int,
    "charge_captured": bool,
    "charge_currency": str,
}
AUTHORIZATION_FIELDS = {
    "route": str,
    "http_status": int,
    "pages_scanned": int,
    "rows_scanned": int,
    "observed_at": int,
    "exact_match_count": int,
    "matched_rows": list,
}
AUTHORIZATION_ROW_FIELDS = {
    "charge_id": str,
    "payment_intent_id": str,
    "order_id": int,
    "amount_minor": int,
    "amount_captured_minor": int,
    "currency": str,
    "status": str,
    "created": int,
}
NOTE_FIELDS = {
    "authorization_count": int,
    "capture_success_count": int,
    "capture_failure_count": int,
}
FIXTURE_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "driver_exit_code",
    "order_id",
    "intent_id",
    "charge_id",
    "errors",
    "blockers",
    "payload_sha256",
}
STATE_COMMON_FIELDS = {
    "schema",
    "status",
    "store",
    "phase",
    "run_stamp",
    "runtime_owner",
    "errors",
    "blockers",
    "payload_sha256",
}
STATE_DATA_FIELDS = {"order", "provider", "authorizations", "notes"}
CAPTURE_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "driver_operation",
    "driver_exit_code",
    "order_id",
    "intent_id",
    "charge_id",
    "order_status",
    "intention_status",
    "success",
    "transport_kind",
    "provider_status",
    "provider_http_code",
    "error_code",
    "error_category",
    "error_fingerprint",
    "blocker_code",
    "payload_sha256",
}
LOG_SCAN_FIELDS = {
    "schema",
    "status",
    "store",
    "flow_id",
    "purpose",
    "run_stamp",
    "exit_code",
    "scan_observed",
    "marker_created_at",
    "origin_nonce",
    "observer_id",
    "key_fingerprint",
    "origin_binding",
    "observations",
    "match_count",
    "matches",
    "ignored_match_count",
    "ignored_matches",
    "blocker_code",
    "observer_summary",
    "payload_sha256",
}
DECISIVE_FAIL_SOURCES = {
    "pre_state_failed",
    "post_state_failed",
    "comparison_failed",
    "capture_operation_failed",
    "log_assertion_failed",
}
ANCILLARY_FAIL_BLOCK_SOURCES = {
    "post_state_blocked",
    "log_assertion_blocked",
}
VERDICT_SOURCES = {
    "pass": set(),
    "fail": DECISIVE_FAIL_SOURCES | ANCILLARY_FAIL_BLOCK_SOURCES,
    "blocked": {
        "authorization_driver_blocked",
        "authorization_order_id_missing",
        "pre_state_blocked",
        "post_state_blocked",
        "capture_operation_blocked",
        "comparison_blocked",
        "log_assertion_blocked",
    },
}
EXECUTION_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "exit_code",
    "verdict_sources",
    "authorization_exit_code",
    "authorization_order_id_present",
    "pre_state_exit_code",
    "capture_exit_code",
    "capture_classification",
    "post_state_exit_code",
    "comparison_exit_code",
    "log_assertion_exit_code",
    "payload_sha256",
}
DECISIVE_CAPTURE_ERROR_CODES = {
    "amount_too_large",
    "amount_too_small",
    "capture_declined",
    "card_declined",
}
SAFE_CAPTURE_ERROR_CODES = DECISIVE_CAPTURE_ERROR_CODES | {
    "",
    "invalid_capture_evidence",
    "invalid_provider_error",
    "transport_error",
    "unproven_native_failure",
    "untrusted_provider_error",
}
INFRASTRUCTURE_CAPTURE_PATTERN = re.compile(
    r"(?:"
    r"(?:http|status)[_-]?\s*(?:401|403|404|408|423|425|429|5\d\d)"
    r"|rest_no_route|authentication|unauthori[sz]ed|forbidden"
    r"|timed?_?out|timeout|rate[_ -]?limit|temporar|transport|network|connection"
    r"|service[_ -]?unavailable|server[_ -]?error|api[_ -]?connection"
    r"|http[_ -]?request[_ -]?failed"
    r")",
    re.IGNORECASE,
)
CAPTURE_ERROR_CATEGORIES = {
    "none",
    "product_decline",
    "transport",
    "untrusted",
    "invalid",
}
CAPTURE_BLOCKER_CODES = {
    "",
    "invalid_capture_count",
    "invalid_capture_shape",
    "fixture_mismatch",
    "invalid_error_fields",
    "transport_error",
    "unproven_native_failure",
    "untrusted_provider_error",
    "contradictory_capture_state",
}
CAPTURE_TRANSPORT_KINDS = {"plugin_http", "native_outcome"}
TRANSIENT_HTTP_CODES = {401, 403, 404, 408, 423, 425, 429}
LOG_MATCH_CATEGORIES = {
    "fatal_error",
    "parse_error",
    "warning",
    "notice",
    "deprecated",
    "strict_standards",
}
LOG_BLOCKER_CODES = {
    "ambiguous_path",
    "invalid_marker",
    "invalid_marker_origin",
    "invalid_observer_summary",
    "invalid_run_binding",
    "invalid_scan_evidence",
    "log_canary_changed",
    "log_history_changed",
    "log_identity_changed",
    "log_metadata_changed",
    "log_prefix_changed",
    "log_read_failed",
    "log_truncated",
    "marker_path_mismatch",
    "missing_marked_path",
    "missing_marker_path",
    "missing_path_observation",
    "no_configured_paths",
    "scan_command_failed",
    "schema_downgrade",
    "stale_marker",
}
COMMON_LOG_SCAN_FIELDS = {
    "status",
    "run_stamp",
    "store",
    "flow_id",
    "purpose",
    "marker_created_at",
    "origin_nonce",
    "observer_id",
    "key_fingerprint",
    "origin_binding",
    "observations",
    "matches",
    "ignored_matches",
    "blocker_code",
    "observer_summary",
}
LOG_OBSERVATION_FIELDS = {
    "path",
    "path_id",
    "start_line_count",
    "end_line_count",
    "start_byte_count",
    "end_byte_count",
    "marker_identity_fingerprint",
    "observed_identity_fingerprint",
    "marker_prefix_fingerprint",
    "observed_prefix_fingerprint",
    "marker_canary_fingerprint",
    "observed_canary_fingerprint",
    "marker_owner",
    "observed_owner",
    "marker_group",
    "observed_group",
    "marker_mode",
    "observed_mode",
}
LOG_OBSERVER_SUMMARY_FIELDS = {
    "schema",
    "store",
    "flow_id",
    "purpose",
    "marker_created_at",
    "observer_id",
    "paths",
    "key_fingerprint",
    "origin_binding",
    "records",
    "record_count",
    "line_event_count",
    "category_counts",
    "chain_head",
}


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def emit(payload: dict[str, Any], exit_code: int) -> int:
    signed = dict(payload)
    signed["payload_sha256"] = payload_digest(signed)
    print(json.dumps(signed, indent=2, sort_keys=True))
    return exit_code


def extract_payloads(raw: str, predicate: Any) -> list[dict[str, Any]]:
    decoder = json.JSONDecoder()
    matches: list[dict[str, Any]] = []
    for index, character in enumerate(raw):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(raw[index:])
        except (json.JSONDecodeError, ValueError):
            continue
        if isinstance(candidate, dict) and predicate(candidate):
            matches.append(candidate)
    return matches


def is_string_list(value: Any, *, require_nonempty: bool = False) -> bool:
    return (
        isinstance(value, list)
        and (not require_nonempty or bool(value))
        and all(isinstance(item, str) and bool(item) for item in value)
    )


def exact_typed_object(value: Any, fields: dict[str, type]) -> bool:
    if not isinstance(value, dict) or set(value) != set(fields):
        return False
    for field, expected_type in fields.items():
        observed = value[field]
        if expected_type is int:
            if not isinstance(observed, int) or isinstance(observed, bool):
                return False
        elif not isinstance(observed, expected_type):
            return False
    return True


def mismatch(errors: list[str], label: str, observed: Any, expected: Any) -> None:
    if observed != expected:
        errors.append(f"{label}={observed} want={expected}")


def run_epoch(run_stamp: str) -> int | None:
    try:
        parsed = datetime.strptime(run_stamp.split("-", 1)[0], "%Y%m%dT%H%M%SZ")
    except ValueError:
        return None
    return int(parsed.replace(tzinfo=timezone.utc).timestamp())


def valid_provider_ids(intent_id: Any, charge_id: Any) -> bool:
    return bool(
        isinstance(intent_id, str)
        and re.fullmatch(r"pi_[A-Za-z0-9_]+", intent_id)
        and isinstance(charge_id, str)
        and re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", charge_id)
    )


def normalize_fixture(args: argparse.Namespace) -> int:
    candidates = extract_payloads(sys.stdin.read(), lambda payload: payload.get("op") == "charge")
    base: dict[str, Any] = {
        "schema": FIXTURE_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "run_stamp": args.run_stamp,
        "driver_exit_code": args.expected_exit_code,
        "order_id": 0,
        "intent_id": "",
        "charge_id": "",
        "errors": [],
        "blockers": [],
    }
    if args.expected_exit_code != 0:
        base["blockers"] = ["Authorization driver did not complete successfully."]
        return emit(base, 3)
    if len(candidates) != 1:
        base["blockers"] = [
            f"Authorization driver must emit exactly one charge payload; observed {len(candidates)}."
        ]
        return emit(base, 3)
    raw = candidates[0]
    try:
        order_id = int(raw.get("order_id") or 0)
    except (TypeError, ValueError):
        order_id = 0
    intent_id = raw.get("intent_id")
    charge_id = raw.get("charge_id")
    if order_id <= 0 or not valid_provider_ids(intent_id, charge_id):
        base["blockers"] = ["Authorization driver did not bind a provider-backed order fixture."]
        return emit(base, 3)
    base.update(
        {
            "status": "pass",
            "order_id": order_id,
            "intent_id": intent_id,
            "charge_id": charge_id,
        }
    )
    return emit(base, 0)


def fixture_validation_error(
    payload: Any,
    run_stamp: str,
    *,
    expected_store: str | None = None,
) -> str | None:
    if not isinstance(payload, dict) or set(payload) != FIXTURE_FIELDS:
        return "fixture evidence has an invalid field set"
    typed = {
        "schema": str,
        "status": str,
        "store": str,
        "run_stamp": str,
        "driver_exit_code": int,
        "order_id": int,
        "intent_id": str,
        "charge_id": str,
        "errors": list,
        "blockers": list,
        "payload_sha256": str,
    }
    if not exact_typed_object(payload, typed):
        return "fixture evidence has invalid field types"
    if payload["schema"] != FIXTURE_SCHEMA or payload["run_stamp"] != run_stamp:
        return "fixture evidence has an invalid schema/run binding"
    if expected_store is not None and payload["store"] != expected_store:
        return "fixture evidence has an invalid store binding"
    if payload["payload_sha256"] != payload_digest(payload):
        return "fixture evidence payload digest does not match"
    if payload["status"] != "pass" or payload["driver_exit_code"] != 0:
        return "fixture evidence is not a successful authorization"
    if payload["order_id"] <= 0 or not valid_provider_ids(payload["intent_id"], payload["charge_id"]):
        return "fixture evidence lacks a provider-backed identity"
    if payload["errors"] or payload["blockers"]:
        return "passing fixture evidence contains errors or blockers"
    return None


def project_state(
    payload: dict[str, Any], blockers: list[str]
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    sections = (
        ("order", ORDER_FIELDS),
        ("provider", PROVIDER_FIELDS),
        ("authorizations", AUTHORIZATION_FIELDS),
        ("notes", NOTE_FIELDS),
    )
    projected: list[dict[str, Any]] = []
    for name, fields in sections:
        value = payload.get(name)
        if not exact_typed_object(value, fields):
            blockers.append(f"State payload field {name} has an invalid shape.")
            projected.append({})
        else:
            projected.append(dict(value))
    order, provider, authorizations, notes = projected
    if blockers:
        return order, provider, authorizations, notes
    for row in authorizations["matched_rows"]:
        if not exact_typed_object(row, AUTHORIZATION_ROW_FIELDS):
            blockers.append("State payload contains an invalid authorization row.")
            break
    return order, provider, authorizations, notes


def state_errors(
    order: dict[str, Any],
    provider: dict[str, Any],
    authorizations: dict[str, Any],
    notes: dict[str, Any],
    phase: str,
) -> list[str]:
    expected = {
        "pre": {
            "order_status": "on-hold",
            "paid": False,
            "intent_status": "requires_capture",
            "captured_minor": 0,
            "captured": False,
            "row_count": 1,
            "capture_success_count": 0,
        },
        "post": {
            "order_status": "processing",
            "paid": True,
            "intent_status": "succeeded",
            "captured_minor": order.get("total_minor"),
            "captured": True,
            "row_count": 0,
            "capture_success_count": 1,
        },
    }[phase]
    errors: list[str] = []
    mismatch(errors, "order status", order["status"], expected["order_status"])
    mismatch(errors, "order paid", order["paid"], expected["paid"])
    mismatch(errors, "order intention status", order["intention_status"], expected["intent_status"])
    mismatch(errors, "provider intent status", provider["intent_status"], expected["intent_status"])
    mismatch(errors, "provider charge captured", provider["charge_captured"], expected["captured"])
    mismatch(
        errors,
        "provider captured amount",
        provider["charge_amount_captured_minor"],
        expected["captured_minor"],
    )
    mismatch(errors, "payment method", order["payment_method"], "woocommerce_payments")
    mismatch(errors, "provider intent amount", provider["intent_amount_minor"], order["total_minor"])
    mismatch(errors, "provider charge amount", provider["charge_amount_minor"], order["total_minor"])
    mismatch(errors, "provider intent currency", provider["intent_currency"], order["currency"])
    mismatch(errors, "provider charge currency", provider["charge_currency"], order["currency"])
    mismatch(errors, "provider/order intent id", provider["intent_id"], order["intent_id"])
    mismatch(errors, "provider/order charge id", provider["charge_id"], order["charge_id"])
    mismatch(errors, "authorizations route", authorizations["route"], LIST_ROUTE)
    mismatch(errors, "authorizations HTTP status", authorizations["http_status"], 200)
    mismatch(
        errors,
        "exact authorization row count",
        authorizations["exact_match_count"],
        expected["row_count"],
    )
    mismatch(
        errors,
        "authorization row projection count",
        len(authorizations["matched_rows"]),
        authorizations["exact_match_count"],
    )
    if order["id"] <= 0:
        errors.append("order id must be positive")
    if order["total_minor"] <= 0:
        errors.append("order total must be positive")
    if not re.fullmatch(r"[A-Z]{3}", order["currency"]):
        errors.append("order currency is not an uppercase ISO code")
    if not valid_provider_ids(order["intent_id"], order["charge_id"]):
        errors.append("order identity is not provider-backed")
    if authorizations["pages_scanned"] < 1:
        errors.append("authorizations endpoint was not scanned")
    if authorizations["rows_scanned"] < authorizations["exact_match_count"]:
        errors.append("authorizations scan counts are inconsistent")
    if phase == "pre" and len(authorizations["matched_rows"]) == 1:
        row = authorizations["matched_rows"][0]
        mismatch(errors, "authorization/order charge id", row["charge_id"], order["charge_id"])
        mismatch(errors, "authorization/order intent id", row["payment_intent_id"], order["intent_id"])
        mismatch(errors, "authorization/order id", row["order_id"], order["id"])
        mismatch(errors, "authorization amount", row["amount_minor"], order["total_minor"])
        mismatch(errors, "authorization captured amount", row["amount_captured_minor"], 0)
        mismatch(errors, "authorization currency", row["currency"], order["currency"])
        mismatch(errors, "authorization status", row["status"], "succeeded")
    mismatch(
        errors,
        "capture success note count",
        notes["capture_success_count"],
        expected["capture_success_count"],
    )
    mismatch(errors, "capture failure note count", notes["capture_failure_count"], 0)
    return errors


def state_evidence_blockers(
    order: dict[str, Any],
    authorizations: dict[str, Any],
    phase: str,
    run_stamp: str,
) -> list[str]:
    blockers: list[str] = []
    invocation_epoch = run_epoch(run_stamp)
    if invocation_epoch is None or abs(authorizations["observed_at"] - invocation_epoch) > 900:
        blockers.append("authorizations observation time does not bind the runner invocation")
    if invocation_epoch is None or abs(order["created"] - invocation_epoch) > 900:
        blockers.append("order creation time does not bind the runner invocation")
    if phase == "pre" and len(authorizations["matched_rows"]) == 1:
        row = authorizations["matched_rows"][0]
        if not (
            authorizations["observed_at"] - (8 * 24 * 60 * 60)
            <= row["created"]
            <= authorizations["observed_at"] + 300
        ):
            blockers.append("authorization created timestamp is outside the active window")
        if abs(row["created"] - order["created"]) > 600:
            blockers.append("authorization creation time does not bind the seeded order")
    return blockers


def normalize_state(args: argparse.Namespace) -> int:
    candidates = extract_payloads(
        sys.stdin.read(), lambda payload: payload.get("schema") == RAW_STATE_SCHEMA
    )
    expected_owner = "plugin" if args.store == "ref" else "native"
    base: dict[str, Any] = {
        "schema": STATE_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "phase": args.phase,
        "run_stamp": args.run_stamp,
        "runtime_owner": expected_owner,
        "errors": [],
        "blockers": [],
    }
    if len(candidates) != 1:
        base["blockers"] = [
            f"State driver must emit exactly one MO-03 payload; observed {len(candidates)}."
        ]
        return emit(base, 3)
    payload = candidates[0]
    blockers: list[str] = []
    for field in ("store", "phase", "runtime_owner"):
        if not isinstance(payload.get(field), str):
            blockers.append(f"State payload field {field} has the wrong type.")
    runtime_owner = payload.get("runtime_owner", "")
    base["runtime_owner"] = runtime_owner
    if payload.get("store") != args.store:
        blockers.append(f"store={payload.get('store')} want={args.store}")
    if payload.get("phase") != args.phase:
        blockers.append(f"phase={payload.get('phase')} want={args.phase}")
    if runtime_owner != expected_owner:
        blockers.append(f"runtime owner={runtime_owner} want={expected_owner}")
    raw_blockers = payload.get("blockers")
    if not is_string_list(raw_blockers):
        blockers.append("State driver blockers field must contain only non-empty strings.")
    raw_status = payload.get("status")
    if raw_status == "blocked":
        if is_string_list(raw_blockers, require_nonempty=True):
            blockers.extend(raw_blockers)
        else:
            blockers.append("State driver reported blocked without a valid reason.")
    elif raw_status != "raw":
        blockers.append(f"State driver emitted invalid status={raw_status}.")
    elif raw_blockers:
        blockers.extend(raw_blockers)
    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)
    order, provider, authorizations, notes = project_state(payload, blockers)
    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)
    blockers = state_evidence_blockers(order, authorizations, args.phase, args.run_stamp)
    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)
    errors = state_errors(order, provider, authorizations, notes, args.phase)
    base.update(
        {
            "status": "fail" if errors else "pass",
            "order": order,
            "provider": provider,
            "authorizations": authorizations,
            "notes": notes,
            "errors": errors,
            "blockers": [],
        }
    )
    return emit(base, 1 if errors else 0)


def bounded_capture_error(error_code: str, error_message: str) -> bool:
    return (
        len(error_code) <= 100
        and len(error_message) <= 240
        and bool(re.fullmatch(r"[a-z0-9]+(?:_[a-z0-9]+)*", error_code))
        and all(character >= " " for character in error_code)
        and all(character >= " " for character in error_message)
    )


def diagnostic_fingerprint(error_code: str, error_message: str) -> str:
    if not error_code and not error_message:
        return ""
    raw = f"{error_code}\0{error_message}".encode("utf-8")
    return "sha256:" + hashlib.sha256(raw).hexdigest()


def valid_fingerprint(value: str, *, allow_empty: bool = False) -> bool:
    return (allow_empty and not value) or bool(re.fullmatch(r"sha256:[0-9a-f]{64}", value))


def trusted_capture_semantics(payload: dict[str, Any]) -> tuple[str, str, str]:
    exit_code = payload["driver_exit_code"]
    http_code = payload["provider_http_code"]
    transport_kind = payload["transport_kind"]
    category = payload["error_category"]
    blocker_code = payload["blocker_code"]
    if category == "invalid" and blocker_code in {
        "invalid_capture_count",
        "invalid_capture_shape",
        "fixture_mismatch",
        "invalid_error_fields",
    }:
        return "blocked", category, blocker_code
    if exit_code == 0:
        transport_succeeded = (
            transport_kind == "plugin_http"
            and payload["provider_status"] == "succeeded"
            and 200 <= http_code < 300
        ) or (
            transport_kind == "native_outcome"
            and payload["provider_status"] == "completed"
            and (http_code == 0 or 200 <= http_code < 300)
        )
        if (
            payload["success"]
            and payload["order_status"] == "processing"
            and payload["intention_status"] == "succeeded"
            and transport_succeeded
            and not payload["error_code"]
            and category == "none"
            and not payload["error_fingerprint"]
            and not blocker_code
        ):
            return "pass", "none", ""
        return "blocked", "invalid", "contradictory_capture_state"
    if exit_code != 1 or payload["success"]:
        return "blocked", "invalid", "contradictory_capture_state"
    if not payload["error_code"] or not valid_fingerprint(payload["error_fingerprint"]):
        return "blocked", "invalid", "invalid_error_fields"
    if transport_kind == "native_outcome" and http_code == 0:
        return "blocked", "untrusted", "unproven_native_failure"
    if (
        category == "transport"
        or http_code in TRANSIENT_HTTP_CODES
        or 500 <= http_code < 600
        or INFRASTRUCTURE_CAPTURE_PATTERN.search(payload["error_code"])
    ):
        return "blocked", "transport", "transport_error"
    if payload["error_code"] not in DECISIVE_CAPTURE_ERROR_CODES:
        return "blocked", "untrusted", "untrusted_provider_error"
    if (
        (transport_kind == "plugin_http" and http_code not in {400, 402})
        or (transport_kind == "native_outcome" and http_code not in {400, 402})
    ):
        return "blocked", "untrusted", "untrusted_provider_error"
    if (
        payload["order_status"] != "on-hold"
        or payload["intention_status"] != "requires_capture"
        or payload["provider_status"] != "failed"
    ):
        return "blocked", "invalid", "contradictory_capture_state"
    return "fail", "product_decline", ""


def normalize_capture(args: argparse.Namespace) -> int:
    candidates = extract_payloads(sys.stdin.read(), lambda payload: payload.get("op") == "capture")
    raw = candidates[0] if len(candidates) == 1 else {}
    raw_error_code = raw.get("error_code", "")
    raw_error_message = raw.get("error_message", "")
    raw_transport_kind = raw.get("transport_kind")
    expected_transport_kind = "plugin_http" if args.store == "ref" else "native_outcome"
    raw_error_code_is_bounded = bool(
        isinstance(raw_error_code, str)
        and re.fullmatch(r"[a-z0-9]+(?:_[a-z0-9]+)*", raw_error_code)
        and len(raw_error_code) <= 100
    )
    raw_error_text = (
        f"{raw_error_code} {raw_error_message}"
        if isinstance(raw_error_code, str) and isinstance(raw_error_message, str)
        else ""
    )
    if not raw_error_code:
        safe_error_code = ""
    elif raw_error_code in DECISIVE_CAPTURE_ERROR_CODES:
        safe_error_code = raw_error_code
    elif not raw_error_code_is_bounded:
        safe_error_code = "invalid_provider_error"
    elif INFRASTRUCTURE_CAPTURE_PATTERN.search(raw_error_text):
        safe_error_code = "transport_error"
    else:
        safe_error_code = "untrusted_provider_error"
    payload: dict[str, Any] = {
        "schema": CAPTURE_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "run_stamp": args.run_stamp,
        "driver_operation": raw.get("op", "capture"),
        "driver_exit_code": args.expected_exit_code,
        "order_id": raw.get("order_id", args.order_id),
        "intent_id": raw.get("intent_id", args.intent_id),
        "charge_id": raw.get("charge_id", args.charge_id),
        "order_status": raw.get("status", ""),
        "intention_status": raw.get("intention_status", ""),
        "success": raw.get("success", False),
        "transport_kind": (
            raw_transport_kind
            if raw_transport_kind in CAPTURE_TRANSPORT_KINDS
            else expected_transport_kind
        ),
        "provider_status": raw.get("provider_status", ""),
        "provider_http_code": raw.get("http_code", 0)
        if isinstance(raw.get("http_code", 0), int)
        and not isinstance(raw.get("http_code", 0), bool)
        else 0,
        "error_code": safe_error_code,
        "error_category": "none",
        "error_fingerprint": diagnostic_fingerprint(
            raw_error_code if isinstance(raw_error_code, str) else "",
            raw_error_message if isinstance(raw_error_message, str) else "",
        ),
        "blocker_code": "",
    }
    if len(candidates) != 1:
        payload.update(
            {
                "error_code": "invalid_capture_evidence",
                "error_category": "invalid",
                "error_fingerprint": "",
                "blocker_code": "invalid_capture_count",
            }
        )
        return emit(payload, 3)
    base_fields = {
        "op",
        "order_id",
        "intent_id",
        "charge_id",
        "status",
        "intention_status",
        "success",
        "transport_kind",
        "provider_status",
        "http_code",
    }
    expected_fields = base_fields if args.expected_exit_code == 0 else base_fields | {
        "error_code",
        "error_message",
    }
    if set(raw) != expected_fields or not all(
        isinstance(payload[field], expected_type)
        for field, expected_type in {
            "driver_operation": str,
            "driver_exit_code": int,
            "order_id": int,
            "intent_id": str,
            "charge_id": str,
            "order_status": str,
            "intention_status": str,
            "success": bool,
            "transport_kind": str,
            "provider_status": str,
            "provider_http_code": int,
            "error_code": str,
            "error_category": str,
            "error_fingerprint": str,
            "blocker_code": str,
        }.items()
    ) or not 0 <= payload["provider_http_code"] <= 599 or raw_transport_kind not in (
        CAPTURE_TRANSPORT_KINDS
    ) or raw_transport_kind != expected_transport_kind:
        payload.update(
            {
                "status": "blocked",
                "success": False,
                "error_code": "invalid_capture_evidence",
                "error_category": "invalid",
                "blocker_code": "invalid_capture_shape",
            }
        )
        return emit(payload, 3)
    if (
        payload["driver_operation"] != "capture"
        or payload["order_id"] != args.order_id
        or payload["intent_id"] != args.intent_id
        or payload["charge_id"] != args.charge_id
    ):
        payload.update(
            {
                "status": "blocked",
                "success": False,
                "error_code": "invalid_capture_evidence",
                "error_category": "invalid",
                "blocker_code": "fixture_mismatch",
            }
        )
        return emit(payload, 3)
    if args.expected_exit_code != 0 and (
        not isinstance(raw_error_code, str)
        or not isinstance(raw_error_message, str)
        or not bounded_capture_error(raw_error_code, raw_error_message)
    ):
        payload.update(
            {
                "status": "blocked",
                "success": False,
                "error_code": "invalid_capture_evidence",
                "error_category": "invalid",
                "blocker_code": "invalid_error_fields",
            }
        )
        return emit(payload, 3)
    if args.expected_exit_code != 0 and INFRASTRUCTURE_CAPTURE_PATTERN.search(raw_error_text):
        payload["error_category"] = "transport"
        payload["blocker_code"] = "transport_error"
    status, category, blocker_code = trusted_capture_semantics(payload)
    payload.update(
        {
            "status": status,
            "error_category": category,
            "blocker_code": blocker_code,
        }
    )
    return emit(payload, {"pass": 0, "fail": 1, "blocked": 3}[payload["status"]])


def blocked_log_scan(store: str, run_stamp: str, blocker_code: str) -> dict[str, Any]:
    return {
        "schema": LOG_SCAN_SCHEMA,
        "status": "blocked",
        "store": store,
        "flow_id": FLOW,
        "purpose": LOG_PURPOSE,
        "run_stamp": run_stamp,
        "exit_code": 3,
        "scan_observed": False,
        "marker_created_at": "",
        "origin_nonce": "",
        "observer_id": "",
        "key_fingerprint": "",
        "origin_binding": "",
        "observations": [],
        "match_count": 0,
        "matches": [],
        "ignored_match_count": 0,
        "ignored_matches": [],
        "blocker_code": blocker_code,
        "observer_summary": {},
    }


def write_signed_payload(payload: dict[str, Any], output: Path) -> int:
    signed = dict(payload)
    signed["payload_sha256"] = payload_digest(signed)
    try:
        output.write_text(json.dumps(signed, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    except OSError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return {"pass": 0, "fail": 1, "blocked": 3}[signed["status"]]


def parse_marker_epoch(value: Any) -> int | None:
    if not isinstance(value, str) or not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        return None
    return int(parsed.timestamp())


def valid_log_basename(value: Any) -> bool:
    return bool(
        isinstance(value, str)
        and value
        and len(value) <= 255
        and Path(value).name == value
        and value not in {".", ".."}
    )


def valid_log_hmac(value: Any) -> bool:
    return bool(
        isinstance(value, str)
        and re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", value)
    )


def valid_log_uuid(value: Any) -> bool:
    return bool(
        isinstance(value, str)
        and re.fullmatch(
            r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}",
            value,
        )
    )


def exact_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def validated_log_observer_counts(
    summary: Any,
    scan: dict[str, Any],
    key: bytes,
    by_path: dict[str, dict[str, Any]],
) -> Counter[str] | None:
    """Return recomputed v2 observer counts, or None for any chain defect."""
    if not isinstance(summary, dict) or set(summary) != LOG_OBSERVER_SUMMARY_FIELDS:
        return None
    records = summary.get("records")
    counts = summary.get("category_counts")
    expected_paths = sorted(
        ({"path": item["path"], "path_id": item["path_id"]} for item in by_path.values()),
        key=lambda item: item["path_id"],
    )
    observer_categories = LOG_MATCH_CATEGORIES | {
        "allowlisted_noise",
        "other",
        "terminal",
    }
    if not (
        summary.get("schema") == "woopayments_debug_log_observer_summary.v2"
        and summary.get("store") == scan["store"]
        and summary.get("flow_id") == scan["flow_id"]
        and summary.get("purpose") == scan["purpose"]
        and summary.get("marker_created_at") == scan["marker_created_at"]
        and summary.get("observer_id") == scan["observer_id"]
        and summary.get("paths") == expected_paths
        and summary.get("key_fingerprint") == scan["key_fingerprint"]
        and summary.get("origin_binding") == scan["origin_binding"]
        and isinstance(records, list)
        and 3 <= len(records) <= 1000
        and exact_int(summary.get("record_count"))
        and summary["record_count"] == len(records)
        and exact_int(summary.get("line_event_count"))
        and isinstance(counts, dict)
        and set(counts).issubset(observer_categories)
        and all(exact_int(count) and count > 0 for count in counts.values())
        and sum(counts.values()) == summary["line_event_count"]
        and summary["record_count"] == summary["line_event_count"] + 2
        and counts.get("terminal", 0) == len(by_path)
        and valid_log_hmac(summary.get("chain_head"))
        and len(json.dumps(summary, sort_keys=True, separators=(",", ":")).encode("utf-8"))
        <= 1024 * 1024
    ):
        return None

    previous = "0" * 64
    derived: Counter[str] = Counter()
    ready_seen = False
    complete_seen = False
    common_fields = {
        "schema",
        "sequence",
        "kind",
        "run_stamp",
        "store",
        "flow_id",
        "purpose",
        "marker_created_at",
        "observer_id",
        "paths",
        "previous_hmac",
        "hmac",
    }
    for sequence, signed_record in enumerate(records, start=1):
        if not isinstance(signed_record, dict):
            return None
        record = dict(signed_record)
        signature = record.pop("hmac", None)
        kind = record.get("kind")
        expected_fields = {
            "ready": common_fields
            | {"status", "path_count", "key_fingerprint", "origin_binding"},
            "line": common_fields | {"path", "line", "category", "fingerprint"},
            "complete": common_fields | {"status"},
        }.get(kind)
        canonical = json.dumps(
            record, sort_keys=True, separators=(",", ":"), ensure_ascii=True
        )
        expected = hmac.new(
            key,
            (previous + "\0" + canonical).encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        if not (
            expected_fields is not None
            and set(signed_record) == expected_fields
            and record.get("schema") == "woopayments_debug_log_observer_record.v2"
            and record.get("sequence") == sequence
            and record.get("run_stamp") == scan["run_stamp"]
            and record.get("store") == scan["store"]
            and record.get("flow_id") == scan["flow_id"]
            and record.get("purpose") == scan["purpose"]
            and record.get("marker_created_at") == scan["marker_created_at"]
            and record.get("observer_id") == scan["observer_id"]
            and record.get("paths") == expected_paths
            and record.get("previous_hmac") == "hmac-sha256:" + previous
            and signature == "hmac-sha256:" + expected
        ):
            return None
        previous = expected
        if kind == "ready":
            if not (
                not ready_seen
                and sequence == 1
                and record.get("status") == "pass"
                and record.get("path_count") == len(by_path)
                and record.get("key_fingerprint") == scan["key_fingerprint"]
                and record.get("origin_binding") == scan["origin_binding"]
            ):
                return None
            ready_seen = True
        elif kind == "line":
            path = record.get("path")
            line = record.get("line")
            category = record.get("category")
            observation = by_path.get(path)
            if not (
                observation is not None
                and exact_int(line)
                and (
                    line == observation["end_line_count"]
                    if category == "terminal"
                    else line >= 1
                )
                and category in observer_categories
                and valid_fingerprint(record.get("fingerprint", ""))
            ):
                return None
            derived[str(category)] += 1
        else:
            if not (
                not complete_seen
                and sequence == len(records)
                and record.get("status") == "pass"
            ):
                return None
            complete_seen = True
    if not (
        ready_seen
        and complete_seen
        and dict(sorted(derived.items())) == counts
        and sum(derived.values()) == summary["line_event_count"]
        and summary["chain_head"] == "hmac-sha256:" + previous
        and sum(derived.get(category, 0) for category in LOG_MATCH_CATEGORIES) <= 20
        and derived.get("allowlisted_noise", 0) <= 100
    ):
        return None
    return derived


def log_projection_error(
    scan: dict[str, Any],
    run_stamp: str,
    *,
    expected_store: str,
    expected_flow_id: str = FLOW,
    expected_purpose: str = LOG_PURPOSE,
    require_observation: bool,
) -> str | None:
    marker_created_at = scan.get("marker_created_at")
    observations = scan.get("observations")
    matches = scan.get("matches")
    ignored_matches = scan.get("ignored_matches")
    if (
        scan.get("store") != expected_store
        or scan.get("flow_id") != expected_flow_id
        or scan.get("purpose") != expected_purpose
    ):
        return "invalid_run_binding"
    if not isinstance(observations, list) or len(observations) > 8:
        return "invalid_scan_evidence"
    if require_observation and not observations:
        return "missing_path_observation"
    if not observations:
        if any(
            scan.get(field)
            for field in (
                "marker_created_at",
                "origin_nonce",
                "observer_id",
                "key_fingerprint",
                "origin_binding",
                "observations",
                "matches",
                "ignored_matches",
                "observer_summary",
            )
        ):
            return "invalid_scan_evidence"
        return None

    if not valid_log_uuid(scan.get("origin_nonce")) or not valid_log_uuid(
        scan.get("observer_id")
    ):
        return "invalid_marker_origin"
    if not valid_fingerprint(scan.get("key_fingerprint", "")) or not valid_log_hmac(
        scan.get("origin_binding")
    ):
        return "invalid_marker_origin"
    key_hex = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if re.fullmatch(r"[0-9a-f]{64}", key_hex) is None:
        return "invalid_marker_origin"
    key = bytes.fromhex(key_hex)
    if scan["key_fingerprint"] != "sha256:" + hashlib.sha256(key).hexdigest():
        return "invalid_marker_origin"

    seen: set[str] = set()
    by_path: dict[str, dict[str, Any]] = {}
    origin_fields = [
        "woopayments_debug_log_origin.v2",
        run_stamp,
        scan["store"],
        scan["flow_id"],
        scan["purpose"],
        scan["marker_created_at"],
        scan["origin_nonce"],
        scan["observer_id"],
        scan["key_fingerprint"],
    ]
    for observation in sorted(
        observations,
        key=lambda item: str(item.get("path_id")) if isinstance(item, dict) else "",
    ):
        if (
            not isinstance(observation, dict)
            or set(observation) != LOG_OBSERVATION_FIELDS
            or not valid_log_basename(observation.get("path"))
            or not valid_log_hmac(observation.get("path_id"))
            or observation["path"] in seen
            or not all(
                exact_int(observation.get(field))
                for field in {
                    "start_line_count",
                    "end_line_count",
                    "start_byte_count",
                    "end_byte_count",
                    "marker_owner",
                    "observed_owner",
                    "marker_group",
                    "observed_group",
                    "marker_mode",
                    "observed_mode",
                }
            )
            or observation["start_line_count"] < 1
            or observation["start_byte_count"] < 1
            or not 0 <= observation["marker_mode"] <= 0o777
            or not 0 <= observation["observed_mode"] <= 0o777
            or not all(
                valid_fingerprint(observation.get(field, ""))
                for field in {
                    "marker_identity_fingerprint",
                    "observed_identity_fingerprint",
                    "marker_prefix_fingerprint",
                    "observed_prefix_fingerprint",
                    "marker_canary_fingerprint",
                    "observed_canary_fingerprint",
                }
            )
        ):
            return "invalid_scan_evidence"
        if observation["end_line_count"] < observation["start_line_count"]:
            return "log_truncated"
        if observation["end_byte_count"] < observation["start_byte_count"]:
            return "log_truncated"
        if observation["marker_identity_fingerprint"] != observation["observed_identity_fingerprint"]:
            return "log_identity_changed"
        if (
            observation["marker_prefix_fingerprint"]
            != observation["observed_prefix_fingerprint"]
        ):
            return "log_prefix_changed"
        if (
            observation["marker_canary_fingerprint"]
            != observation["observed_canary_fingerprint"]
        ):
            return "log_canary_changed"
        if any(
            observation[f"marker_{field}"] != observation[f"observed_{field}"]
            for field in ("owner", "group", "mode")
        ):
            return "log_metadata_changed"
        seen.add(observation["path"])
        by_path[observation["path"]] = observation
        origin_fields.extend(
            [
                observation["path_id"],
                observation["path"],
                str(observation["start_line_count"]),
                str(observation["start_byte_count"]),
                observation["marker_identity_fingerprint"],
                observation["marker_prefix_fingerprint"],
                observation["marker_canary_fingerprint"],
                str(observation["marker_owner"]),
                str(observation["marker_group"]),
                str(observation["marker_mode"]),
            ]
        )

    expected_binding = "hmac-sha256:" + hmac.new(
        key, "\0".join(origin_fields).encode("utf-8"), hashlib.sha256
    ).hexdigest()
    if not hmac.compare_digest(scan["origin_binding"], expected_binding):
        return "invalid_marker_origin"

    for records, categories, maximum in (
        (matches, LOG_MATCH_CATEGORIES, 20),
        (ignored_matches, {"allowlisted_noise"}, 100),
    ):
        if not isinstance(records, list) or len(records) > maximum:
            return "invalid_scan_evidence"
        for record in records:
            if (
                not isinstance(record, dict)
                or set(record) != {"path", "line", "category", "fingerprint"}
                or not valid_log_basename(record.get("path"))
                or record.get("path") not in by_path
                or not exact_int(record.get("line"))
                or not isinstance(record.get("category"), str)
                or record.get("category") not in categories
                or not valid_fingerprint(record.get("fingerprint", ""))
            ):
                return "invalid_scan_evidence"
            observation = by_path[record["path"]]
            if not (
                observation["start_line_count"]
                < record["line"]
                <= observation["end_line_count"]
            ):
                return "invalid_scan_evidence"

    summary = scan.get("observer_summary")
    counts = validated_log_observer_counts(summary, scan, key, by_path)
    if counts is None:
        return "invalid_observer_summary"

    terminal_counts = Counter(record["category"] for record in matches)
    status = scan.get("status")
    blocker_code = scan.get("blocker_code")
    for category in LOG_MATCH_CATEGORIES:
        observed_count = counts.get(category, 0)
        terminal_count = terminal_counts.get(category, 0)
        if status == "blocked" and blocker_code == "log_history_changed":
            if observed_count < terminal_count:
                return "invalid_observer_summary"
        elif observed_count != terminal_count:
            return "invalid_observer_summary"
    if status == "blocked" and blocker_code == "log_history_changed":
        if not (
            any(
                counts.get(category, 0) > terminal_counts.get(category, 0)
                for category in LOG_MATCH_CATEGORIES
            )
            or counts.get("allowlisted_noise", 0) > len(ignored_matches)
        ):
            return "invalid_observer_summary"
    elif counts.get("allowlisted_noise", 0) != len(ignored_matches):
        return "invalid_observer_summary"

    invocation_epoch = run_epoch(run_stamp)
    marker_epoch = parse_marker_epoch(marker_created_at)
    if invocation_epoch is None:
        return "invalid_run_binding"
    if marker_epoch is None:
        return "invalid_marker"
    if abs(marker_epoch - invocation_epoch) > 900:
        return "stale_marker"
    return None


def normalize_log_scan(args: argparse.Namespace) -> int:
    input_path = Path(args.input)
    output = Path(args.output)
    try:
        raw = json.loads(input_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        return write_signed_payload(
            blocked_log_scan(
                args.store,
                args.run_stamp,
                "scan_command_failed",
            ),
            output,
        )
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}[args.expected_exit_code]
    if args.flow_id != FLOW or args.purpose != LOG_PURPOSE:
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "invalid_run_binding"), output
        )
    if not isinstance(raw, dict) or raw.get("schema") in {
        "woopayments_debug_log_scan.v1",
        "woopayments_debug_log_scan.v2",
        "woopayments_debug_log_scan.v3",
        "woopayments_debug_log_scan.v4",
        "woopayments_debug_log_scan.v5",
    }:
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "schema_downgrade"),
            output,
        )
    if (
        set(raw) != {"schema", "store", "scan"}
        or raw.get("schema") != "woopayments_debug_log_scan.v6"
        or raw.get("store") != args.store
        or not isinstance(raw.get("scan"), dict)
    ):
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "invalid_scan_evidence"), output
        )
    scan = raw["scan"]
    if set(scan) != COMMON_LOG_SCAN_FIELDS:
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "invalid_scan_evidence"), output
        )
    status = scan.get("status")
    blocker_code = scan.get("blocker_code")
    if scan.get("run_stamp") != args.run_stamp:
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "invalid_run_binding"), output
        )
    projection_error = log_projection_error(
        scan,
        args.run_stamp,
        expected_store=args.store,
        expected_flow_id=args.flow_id,
        expected_purpose=args.purpose,
        require_observation=status in {"pass", "fail"},
    )
    if projection_error:
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, projection_error), output
        )
    if (
        status != expected_status
        or (status == "pass" and (scan["matches"] or blocker_code))
        or (status == "fail" and (not scan["matches"] or blocker_code))
        or (status == "blocked" and blocker_code not in LOG_BLOCKER_CODES)
    ):
        return write_signed_payload(
            blocked_log_scan(args.store, args.run_stamp, "invalid_scan_evidence"), output
        )
    payload = {
        "schema": LOG_SCAN_SCHEMA,
        "status": expected_status,
        "store": args.store,
        "flow_id": args.flow_id,
        "purpose": args.purpose,
        "run_stamp": args.run_stamp,
        "exit_code": args.expected_exit_code,
        "scan_observed": bool(scan["observations"]),
        "marker_created_at": scan["marker_created_at"],
        "origin_nonce": scan["origin_nonce"],
        "observer_id": scan["observer_id"],
        "key_fingerprint": scan["key_fingerprint"],
        "origin_binding": scan["origin_binding"],
        "observations": scan["observations"],
        "match_count": len(scan["matches"]),
        "matches": scan["matches"],
        "ignored_match_count": len(scan["ignored_matches"]),
        "ignored_matches": scan["ignored_matches"],
        "blocker_code": blocker_code,
        "observer_summary": scan["observer_summary"],
    }
    return write_signed_payload(payload, output)


def log_scan_validation_error(
    payload: Any,
    run_stamp: str,
    *,
    expected_store: str | None = None,
    expected_exit_code: int | None = None,
) -> str | None:
    if not isinstance(payload, dict) or set(payload) != LOG_SCAN_FIELDS:
        return "log scan evidence has an invalid field set"
    typed = {
        "schema": str,
        "status": str,
        "store": str,
        "flow_id": str,
        "purpose": str,
        "run_stamp": str,
        "exit_code": int,
        "scan_observed": bool,
        "marker_created_at": str,
        "origin_nonce": str,
        "observer_id": str,
        "key_fingerprint": str,
        "origin_binding": str,
        "observations": list,
        "match_count": int,
        "matches": list,
        "ignored_match_count": int,
        "ignored_matches": list,
        "blocker_code": str,
        "observer_summary": dict,
        "payload_sha256": str,
    }
    if not exact_typed_object(payload, typed):
        return "log scan evidence has invalid field types"
    if payload["schema"] != LOG_SCAN_SCHEMA or payload["run_stamp"] != run_stamp:
        return "log scan evidence has an invalid schema/run binding"
    if expected_store is not None and payload["store"] != expected_store:
        return "log scan evidence has an invalid store binding"
    if expected_exit_code is not None and payload["exit_code"] != expected_exit_code:
        return "log scan evidence contradicts the execution exit code"
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(payload["exit_code"])
    if payload["status"] != expected_status:
        return "log scan evidence has inconsistent status semantics"
    if payload["payload_sha256"] != payload_digest(payload):
        return "log scan evidence payload digest does not match"
    projection_error = log_projection_error(
        payload,
        run_stamp,
        expected_store=expected_store or payload["store"],
        require_observation=payload["scan_observed"],
    )
    if (
        projection_error
        or payload["match_count"] != len(payload["matches"])
        or payload["ignored_match_count"] != len(payload["ignored_matches"])
    ):
        return "log scan evidence has invalid bounded projections"
    if payload["scan_observed"]:
        if payload["status"] == "pass" and (
            payload["matches"] or payload["blocker_code"]
        ):
            return "passing log scan evidence contains matches"
        if payload["status"] == "fail" and (
            not payload["matches"] or payload["blocker_code"]
        ):
            return "failing log scan evidence contains no match"
    elif (
        payload["status"] != "blocked"
        or payload["exit_code"] != 3
        or payload["observations"]
        or payload["marker_created_at"]
        or payload["origin_nonce"]
        or payload["observer_id"]
        or payload["key_fingerprint"]
        or payload["origin_binding"]
        or payload["matches"]
        or payload["ignored_matches"]
        or payload["observer_summary"]
        or payload["blocker_code"] not in LOG_BLOCKER_CODES
    ):
        return "unobserved log scan evidence is not fail closed"
    return None


def load_fixture(path: Path, store: str, run_stamp: str, blockers: list[str]) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        blockers.append(f"{path.name} could not be read as JSON.")
        return {}
    error = fixture_validation_error(payload, run_stamp, expected_store=store)
    if error:
        blockers.append(f"{path.name}: {error}.")
        return {}
    return payload


def load_state(
    path: Path,
    store: str,
    phase: str,
    run_stamp: str,
    blockers: list[str],
) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        blockers.append(f"{path.name} could not be read as JSON.")
        return {}
    if not isinstance(payload, dict) or payload.get("schema") != STATE_SCHEMA:
        blockers.append(f"{path.name} has an invalid normalized schema.")
        return {}
    expected_fields = set(STATE_COMMON_FIELDS)
    if payload.get("status") in {"pass", "fail"}:
        expected_fields.update(STATE_DATA_FIELDS)
    if set(payload) != expected_fields:
        blockers.append(f"{path.name} has an invalid normalized field set.")
        return {}
    if payload.get("payload_sha256") != payload_digest(payload):
        blockers.append(f"{path.name} has an invalid payload digest.")
        return {}
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        payload.get("store") != store
        or payload.get("phase") != phase
        or payload.get("run_stamp") != run_stamp
    ):
        blockers.append(f"{path.name} has an invalid role/run binding.")
        return {}
    if not is_string_list(payload.get("errors")) or not is_string_list(payload.get("blockers")):
        blockers.append(f"{path.name} has malformed errors or blockers.")
        return {}
    if payload["status"] == "blocked":
        if payload["errors"] or not payload["blockers"]:
            blockers.append(f"{path.name} has inconsistent blocked semantics.")
            return {}
        runtime_owner = payload.get("runtime_owner")
        if runtime_owner != expected_owner and (
            not isinstance(runtime_owner, str)
            or f"runtime owner={runtime_owner} want={expected_owner}" not in payload["blockers"]
        ):
            blockers.append(f"{path.name} has an unsupported runtime-owner block.")
            return {}
        return payload
    if payload.get("runtime_owner") != expected_owner:
        blockers.append(f"{path.name} has an invalid runtime-owner binding.")
        return {}
    shape_blockers: list[str] = []
    order, provider, authorizations, notes = project_state(payload, shape_blockers)
    evidence_blockers = (
        state_evidence_blockers(order, authorizations, phase, run_stamp)
        if not shape_blockers
        else []
    )
    recomputed = (
        state_errors(order, provider, authorizations, notes, phase)
        if not shape_blockers and not evidence_blockers
        else []
    )
    expected_status = "fail" if recomputed else "pass"
    if (
        shape_blockers
        or evidence_blockers
        or payload["status"] != expected_status
        or payload["errors"] != recomputed
        or payload["blockers"]
    ):
        blockers.append(f"{path.name} fails semantic recomputation.")
        return {}
    return payload


def capture_validation_error(
    payload: Any,
    run_stamp: str,
    *,
    expected_store: str | None = None,
    expected_fixture: dict[str, Any] | None = None,
) -> str | None:
    if not isinstance(payload, dict) or set(payload) != CAPTURE_FIELDS:
        return "capture evidence has an invalid field set"
    typed = {
        "schema": str,
        "status": str,
        "store": str,
        "run_stamp": str,
        "driver_operation": str,
        "driver_exit_code": int,
        "order_id": int,
        "intent_id": str,
        "charge_id": str,
        "order_status": str,
        "intention_status": str,
        "success": bool,
        "transport_kind": str,
        "provider_status": str,
        "provider_http_code": int,
        "error_code": str,
        "error_category": str,
        "error_fingerprint": str,
        "blocker_code": str,
        "payload_sha256": str,
    }
    if not exact_typed_object(payload, typed):
        return "capture evidence has invalid field types"
    if payload["schema"] != CAPTURE_SCHEMA or payload["run_stamp"] != run_stamp:
        return "capture evidence has an invalid schema/run binding"
    if payload["payload_sha256"] != payload_digest(payload):
        return "capture evidence payload digest does not match"
    if expected_store is not None and payload["store"] != expected_store:
        return "capture evidence has an invalid store binding"
    expected_transport_kind = "plugin_http" if expected_store == "ref" else "native_outcome"
    if expected_store is not None and payload["transport_kind"] != expected_transport_kind:
        return "capture evidence has an invalid transport binding"
    if payload["driver_operation"] != "capture":
        return "capture evidence has an invalid operation binding"
    if expected_fixture is not None and (
        payload["order_id"] != expected_fixture["order_id"]
        or payload["intent_id"] != expected_fixture["intent_id"]
        or payload["charge_id"] != expected_fixture["charge_id"]
    ):
        return "capture evidence does not bind the seeded fixture"
    if payload["status"] not in {"pass", "fail", "blocked"}:
        return "capture evidence has an invalid status"
    if (
        not 0 <= payload["provider_http_code"] <= 599
        or payload["transport_kind"] not in CAPTURE_TRANSPORT_KINDS
        or payload["error_category"] not in CAPTURE_ERROR_CATEGORIES
        or payload["blocker_code"] not in CAPTURE_BLOCKER_CODES
        or payload["error_code"] not in SAFE_CAPTURE_ERROR_CODES
        or not valid_fingerprint(
            payload["error_fingerprint"], allow_empty=payload["driver_exit_code"] == 0
        )
    ):
        return "capture evidence contains invalid safe error metadata"
    status, category, blocker_code = trusted_capture_semantics(payload)
    if (
        payload["status"] != status
        or payload["error_category"] != category
        or payload["blocker_code"] != blocker_code
    ):
        return "capture evidence status contradicts independently recomputed semantics"
    if payload["status"] == "pass" and not valid_provider_ids(
        payload["intent_id"], payload["charge_id"]
    ):
        return "passing capture evidence lacks provider-backed identity"
    return None


def load_capture(
    path: Path,
    store: str,
    run_stamp: str,
    blockers: list[str],
    *,
    fixture: dict[str, Any] | None = None,
) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        blockers.append(f"{path.name} could not be read as JSON.")
        return {}
    error = capture_validation_error(
        payload,
        run_stamp,
        expected_store=store,
        expected_fixture=fixture,
    )
    if error:
        blockers.append(f"{path.name}: {error}.")
        return {}
    return payload


def mask_provider_id(value: str) -> str:
    prefix = value.split("_", 1)[0] + "_" if "_" in value else ""
    return f"{prefix}…{value[-4:]}"


def canonical_fixture(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "order_present": payload["order_id"] > 0,
        "intent_id": mask_provider_id(payload["intent_id"]),
        "charge_id": mask_provider_id(payload["charge_id"]),
    }


def canonical_state(payload: dict[str, Any]) -> dict[str, Any]:
    order = payload["order"]
    provider = payload["provider"]
    authorizations = payload["authorizations"]
    notes = payload["notes"]
    row = authorizations["matched_rows"][0] if authorizations["matched_rows"] else None
    return {
        "identity": {
            "intent_id": mask_provider_id(order["intent_id"]),
            "charge_id": mask_provider_id(order["charge_id"]),
        },
        "financial": {
            "order_status": order["status"],
            "paid": order["paid"],
            "currency": order["currency"],
            "total_minor": order["total_minor"],
            "payment_method": order["payment_method"],
            "intention_status": order["intention_status"],
            "intent_status": provider["intent_status"],
            "intent_amount_minor": provider["intent_amount_minor"],
            "intent_currency": provider["intent_currency"],
            "charge_amount_minor": provider["charge_amount_minor"],
            "charge_amount_captured_minor": provider["charge_amount_captured_minor"],
            "charge_captured": provider["charge_captured"],
            "charge_currency": provider["charge_currency"],
        },
        "authorizations": {
            "route": authorizations["route"],
            "http_status": authorizations["http_status"],
            "exact_match_count": authorizations["exact_match_count"],
            "row": None
            if row is None
            else {
                "amount_minor": row["amount_minor"],
                "amount_captured_minor": row["amount_captured_minor"],
                "currency": row["currency"],
                "status": row["status"],
                "created_valid": row["created"] > 0,
            },
        },
        "notes": dict(notes),
    }


def parity_state(payload: dict[str, Any]) -> dict[str, Any]:
    projection = canonical_state(payload)
    projection.pop("identity")
    projection["notes"].pop("authorization_count")
    return projection


def canonical_capture(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "status": payload["status"],
        "success": payload["success"],
        "provider_status": "completed" if payload["success"] else payload["provider_status"],
        "order_status": payload["order_status"],
        "intention_status": payload["intention_status"],
    }


def compare(args: argparse.Namespace) -> int:
    blockers: list[str] = []
    fixtures = {
        "ref_fixture": load_fixture(
            Path(args.reference_fixture), "ref", args.run_stamp, blockers
        ),
        "target_fixture": load_fixture(
            Path(args.target_fixture), "target", args.run_stamp, blockers
        ),
    }
    state_specs = {
        "ref_pre": (Path(args.reference_pre), "ref", "pre"),
        "ref_post": (Path(args.reference_post), "ref", "post"),
        "target_pre": (Path(args.target_pre), "target", "pre"),
        "target_post": (Path(args.target_post), "target", "post"),
    }
    states = {
        label: load_state(path, store, phase, args.run_stamp, blockers)
        for label, (path, store, phase) in state_specs.items()
    }
    captures = {
        "ref_capture": load_capture(
            Path(args.reference_capture),
            "ref",
            args.run_stamp,
            blockers,
            fixture=fixtures["ref_fixture"] or None,
        ),
        "target_capture": load_capture(
            Path(args.target_capture),
            "target",
            args.run_stamp,
            blockers,
            fixture=fixtures["target_fixture"] or None,
        ),
    }
    result: dict[str, Any] = {
        "schema": COMPARISON_SCHEMA,
        "status": "blocked",
        "run_stamp": args.run_stamp,
        "errors": [],
        "blockers": blockers,
    }
    if blockers:
        return emit(result, 3)
    errors: list[str] = []
    for label, payload in {**fixtures, **states, **captures}.items():
        if payload["status"] == "blocked":
            blockers.append(f"{label} is blocked.")
        elif payload["status"] != "pass":
            errors.append(f"{label} did not pass its assertions.")
    if blockers:
        result["blockers"] = blockers
        return emit(result, 3)
    for store in ("ref", "target"):
        fixture = fixtures[f"{store}_fixture"]
        before = states[f"{store}_pre"]
        after = states[f"{store}_post"]
        for state_label, state in (("pre", before), ("post", after)):
            if (
                state["order"]["id"] != fixture["order_id"]
                or state["order"]["intent_id"] != fixture["intent_id"]
                or state["order"]["charge_id"] != fixture["charge_id"]
            ):
                errors.append(f"{store} {state_label} state does not bind the seeded fixture.")
        for field in (
            "id",
            "created",
            "currency",
            "total_minor",
            "payment_method",
            "intent_id",
            "charge_id",
        ):
            if before["order"][field] != after["order"][field]:
                errors.append(f"{store} order field {field} changed across provider/order capture.")
        capture = captures[f"{store}_capture"]
        if (
            capture["order_id"] != fixture["order_id"]
            or capture["intent_id"] != fixture["intent_id"]
            or capture["charge_id"] != fixture["charge_id"]
        ):
            errors.append(f"{store} provider/order capture does not bind the seeded fixture.")
    for phase in ("pre", "post"):
        if parity_state(states[f"ref_{phase}"]) != parity_state(states[f"target_{phase}"]):
            errors.append(f"{phase}-capture financial state differs between reference and target.")
    if canonical_capture(captures["ref_capture"]) != canonical_capture(captures["target_capture"]):
        errors.append("provider/order capture result differs between reference and target.")
    all_payloads = {**fixtures, **states, **captures}
    result.update(
        {
            "status": "fail" if errors else "pass",
            "errors": errors,
            "blockers": [],
            "inputs": {label: payload["payload_sha256"] for label, payload in all_payloads.items()},
            "reference": {
                "fixture": canonical_fixture(fixtures["ref_fixture"]),
                "pre": canonical_state(states["ref_pre"]),
                "capture": canonical_capture(captures["ref_capture"]),
                "post": canonical_state(states["ref_post"]),
            },
            "target": {
                "fixture": canonical_fixture(fixtures["target_fixture"]),
                "pre": canonical_state(states["target_pre"]),
                "capture": canonical_capture(captures["target_capture"]),
                "post": canonical_state(states["target_post"]),
            },
        }
    )
    return emit(result, 1 if errors else 0)


def validate_comparison(args: argparse.Namespace) -> int:
    try:
        payload = json.loads(sys.stdin.read())
    except (UnicodeError, ValueError):
        return 3
    if not isinstance(payload, dict) or payload.get("schema") != COMPARISON_SCHEMA:
        return 3
    expected_fields = {"schema", "status", "run_stamp", "errors", "blockers", "payload_sha256"}
    if payload.get("status") in {"pass", "fail"}:
        expected_fields.update({"inputs", "reference", "target"})
    if set(payload) != expected_fields or payload.get("run_stamp") != args.run_stamp:
        return 3
    if payload.get("payload_sha256") != payload_digest(payload):
        return 3
    if not is_string_list(payload.get("errors")) or not is_string_list(payload.get("blockers")):
        return 3
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(args.expected_exit_code)
    if payload.get("status") != expected_status:
        return 3
    if expected_status == "pass" and (payload["errors"] or payload["blockers"]):
        return 3
    if expected_status == "fail" and (not payload["errors"] or payload["blockers"]):
        return 3
    if expected_status == "blocked" and (payload["errors"] or not payload["blockers"]):
        return 3
    if expected_status in {"pass", "fail"}:
        expected_inputs = {
            "ref_fixture",
            "ref_pre",
            "ref_capture",
            "ref_post",
            "target_fixture",
            "target_pre",
            "target_capture",
            "target_post",
        }
        if not isinstance(payload.get("inputs"), dict) or set(payload["inputs"]) != expected_inputs:
            return 3
        if not all(
            re.fullmatch(r"sha256:[0-9a-f]{64}", str(value))
            for value in payload["inputs"].values()
        ):
            return 3
        for store in ("reference", "target"):
            projection = payload.get(store)
            if not isinstance(projection, dict) or set(projection) != {
                "fixture",
                "pre",
                "capture",
                "post",
            }:
                return 3
        if expected_status == "pass":
            for phase in ("pre", "capture", "post"):
                reference = dict(payload["reference"][phase])
                target = dict(payload["target"][phase])
                if phase in {"pre", "post"}:
                    reference.pop("identity", None)
                    target.pop("identity", None)
                    reference["notes"] = dict(reference["notes"])
                    target["notes"] = dict(target["notes"])
                    reference["notes"].pop("authorization_count", None)
                    target["notes"].pop("authorization_count", None)
                if reference != target:
                    return 3
    return 0


def execution_validation_error(payload: Any, run_stamp: str) -> str | None:
    if not isinstance(payload, dict) or set(payload) != EXECUTION_FIELDS:
        return "execution record has an invalid field set"
    if payload.get("schema") != EXECUTION_SCHEMA or payload.get("run_stamp") != run_stamp:
        return "execution record has an invalid schema/run binding"
    status = payload.get("status")
    if payload.get("store") not in {"ref", "target"} or status not in VERDICT_SOURCES:
        return "execution record has an invalid role or status"
    if payload.get("exit_code") != {"pass": 0, "fail": 1, "blocked": 3}[status]:
        return "execution record has an inconsistent verdict"
    sources = payload.get("verdict_sources")
    if (
        not is_string_list(sources)
        or len(sources) != len(set(sources))
        or not set(sources).issubset(VERDICT_SOURCES[status])
        or (status == "pass" and sources)
        or (status != "pass" and not sources)
        or (status == "fail" and not set(sources).intersection(DECISIVE_FAIL_SOURCES))
    ):
        return "execution record has invalid verdict sources"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "execution record payload digest does not match"
    if any(
        not isinstance(payload.get(field), int)
        for field in ("authorization_exit_code", "log_assertion_exit_code")
    ):
        return "execution record contains an invalid required exit code"
    if any(
        payload.get(field) is not None and not isinstance(payload.get(field), int)
        for field in (
            "pre_state_exit_code",
            "capture_exit_code",
            "post_state_exit_code",
            "comparison_exit_code",
        )
    ):
        return "execution record contains an invalid optional exit code"
    if not isinstance(payload.get("authorization_order_id_present"), bool):
        return "execution record order-id presence has the wrong type"
    capture_rc = payload.get("capture_exit_code")
    classification = payload.get("capture_classification")
    if classification not in {None, "pass", "product_failure", "blocked"}:
        return "execution record capture classification is invalid"
    if (
        (capture_rc is None) != (classification is None)
        or (capture_rc == 0 and classification != "pass")
        or (classification == "product_failure" and capture_rc != 1)
        or (classification == "blocked" and capture_rc is None)
    ):
        return "execution record capture exit/classification is inconsistent"
    source_checks = {
        "authorization_driver_blocked": payload["authorization_exit_code"] != 0,
        "authorization_order_id_missing": payload["authorization_exit_code"] == 0
        and not payload["authorization_order_id_present"],
        "pre_state_failed": payload["pre_state_exit_code"] == 1,
        "pre_state_blocked": payload["pre_state_exit_code"] == 3,
        "capture_operation_failed": capture_rc == 1 and classification == "product_failure",
        "capture_operation_blocked": classification == "blocked",
        "post_state_failed": payload["post_state_exit_code"] == 1,
        "post_state_blocked": payload["post_state_exit_code"] == 3,
        "comparison_failed": payload["comparison_exit_code"] == 1,
        "comparison_blocked": payload["comparison_exit_code"] == 3,
        "log_assertion_failed": payload["log_assertion_exit_code"] == 1,
        "log_assertion_blocked": payload["log_assertion_exit_code"] not in {0, 1},
    }
    if any(not source_checks[source] for source in sources):
        return "execution record does not support every verdict source"
    if status == "pass" and any(
        (
            payload["authorization_exit_code"] != 0,
            not payload["authorization_order_id_present"],
            payload["pre_state_exit_code"] != 0,
            capture_rc != 0,
            classification != "pass",
            payload["post_state_exit_code"] != 0,
            payload["comparison_exit_code"] != (0 if payload["store"] == "target" else None),
            payload["log_assertion_exit_code"] != 0,
        )
    ):
        return "passing execution record contains a non-passing operation"
    return None


def build_execution(args: argparse.Namespace) -> int:
    payload = {
        "schema": EXECUTION_SCHEMA,
        "status": args.status,
        "store": args.store,
        "run_stamp": args.run_stamp,
        "exit_code": args.exit_code,
        "verdict_sources": sorted(args.verdict_source),
        "authorization_exit_code": args.authorization_exit_code,
        "authorization_order_id_present": args.authorization_order_id_present == "true",
        "pre_state_exit_code": args.pre_state_exit_code,
        "capture_exit_code": args.capture_exit_code,
        "capture_classification": args.capture_classification,
        "post_state_exit_code": args.post_state_exit_code,
        "comparison_exit_code": args.comparison_exit_code,
        "log_assertion_exit_code": args.log_assertion_exit_code,
    }
    payload["payload_sha256"] = payload_digest(payload)
    error = execution_validation_error(payload, args.run_stamp)
    if error:
        print(error, file=sys.stderr)
        return 3
    try:
        Path(args.output).write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n")
    except OSError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return 0


def allowed_files(store: str) -> set[str]:
    reference = {
        "ref-fixture.json",
        "ref-pre.json",
        "ref-capture.json",
        "ref-post.json",
        "ref-execution.json",
        "ref-log-scan.json",
    }
    if store == "ref":
        return reference
    return reference | {
        "target-fixture.json",
        "target-pre.json",
        "target-capture.json",
        "target-post.json",
        "target-execution.json",
        "target-log-scan.json",
        "comparison.json",
    }


def load_bound_artifact(
    manifest_path: Path, filename: str, binding: Any
) -> tuple[dict[str, Any], str | None]:
    if not isinstance(binding, dict) or set(binding) != {"file_sha256", "payload_sha256"}:
        return {}, f"manifest binding is malformed: {filename}"
    artifact = manifest_path.parent / filename
    if artifact.is_symlink() or not artifact.is_file() or artifact.resolve().parent != manifest_path.parent:
        return {}, f"manifest artifact is not a contained regular file: {filename}"
    try:
        raw = artifact.read_bytes()
        payload = json.loads(raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        return {}, f"manifest artifact is unreadable ({filename}): {exc}"
    if not isinstance(payload, dict):
        return {}, f"manifest artifact is not an object: {filename}"
    if binding.get("file_sha256") != "sha256:" + hashlib.sha256(raw).hexdigest():
        return {}, f"manifest artifact byte digest does not match: {filename}"
    if binding.get("payload_sha256") != payload.get("payload_sha256"):
        return {}, f"manifest artifact payload binding does not match: {filename}"
    if payload.get("payload_sha256") != payload_digest(payload):
        return {}, f"manifest artifact payload digest does not match: {filename}"
    return payload, None


def build_manifest(args: argparse.Namespace) -> int:
    if args.status != {0: "pass", 1: "fail", 3: "blocked"}[args.exit_code]:
        print("Manifest status and exit code are inconsistent.", file=sys.stderr)
        return 3
    sources = sorted(args.verdict_source)
    if (
        len(sources) != len(set(sources))
        or not set(sources).issubset(VERDICT_SOURCES[args.status])
        or (args.status == "pass" and sources)
        or (args.status != "pass" and not sources)
        or (
            args.status == "fail"
            and not set(sources).intersection(DECISIVE_FAIL_SOURCES)
        )
    ):
        print("Manifest verdict sources are invalid.", file=sys.stderr)
        return 3
    output = Path(args.output).resolve()
    bindings: dict[str, dict[str, str]] = {}
    payloads: dict[str, dict[str, Any]] = {}
    try:
        for raw_path in args.file:
            candidate = Path(raw_path)
            if candidate.is_symlink() or not candidate.is_file():
                raise ValueError(f"Evidence is not a regular file: {raw_path}")
            path = candidate.resolve()
            if (
                path.parent != output.parent
                or path.name not in allowed_files(args.store)
                or path.name in bindings
            ):
                raise ValueError(f"Invalid manifest evidence path: {raw_path}")
            payload = json.loads(path.read_text(encoding="utf-8"))
            if not isinstance(payload, dict) or payload.get("payload_sha256") != payload_digest(payload):
                raise ValueError(f"Evidence payload digest is invalid: {path.name}")
            bindings[path.name] = {
                "file_sha256": file_digest(path),
                "payload_sha256": payload["payload_sha256"],
            }
            payloads[path.name] = payload
    except (OSError, UnicodeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 3
    if args.status == "pass" and set(bindings) != allowed_files(args.store):
        print("Passing manifest does not bind the complete evidence set.", file=sys.stderr)
        return 3
    own_execution = payloads.get(f"{args.store}-execution.json")
    error = execution_validation_error(own_execution, args.run_stamp)
    if error:
        print(error, file=sys.stderr)
        return 3
    if (
        own_execution["status"] != args.status
        or own_execution["exit_code"] != args.exit_code
        or own_execution["verdict_sources"] != sources
    ):
        print("Manifest contradicts its execution record.", file=sys.stderr)
        return 3
    for store in ("ref", "target"):
        execution = payloads.get(f"{store}-execution.json")
        if execution is None:
            continue
        log_scan = payloads.get(f"{store}-log-scan.json")
        error = log_scan_validation_error(
            log_scan,
            args.run_stamp,
            expected_store=store,
            expected_exit_code=execution["log_assertion_exit_code"],
        )
        if error:
            print(error, file=sys.stderr)
            return 3
    if own_execution["authorization_exit_code"] == 0 and own_execution[
        "authorization_order_id_present"
    ] and f"{args.store}-fixture.json" not in payloads:
        print("Manifest lacks the successful authorization fixture.", file=sys.stderr)
        return 3
    required_failure_artifacts = {
        "pre_state_failed": f"{args.store}-pre.json",
        "post_state_failed": f"{args.store}-post.json",
        "capture_operation_failed": f"{args.store}-capture.json",
        "comparison_failed": "comparison.json",
        "log_assertion_failed": f"{args.store}-log-scan.json",
        "log_assertion_blocked": f"{args.store}-log-scan.json",
    }
    for source, filename in required_failure_artifacts.items():
        if source in sources and filename not in payloads:
            print(f"Manifest verdict source {source} lacks {filename}.", file=sys.stderr)
            return 3
    manifest = {
        "schema": MANIFEST_SCHEMA,
        "flow": FLOW,
        "run_stamp": args.run_stamp,
        "run_scope": args.run_scope,
        "store": args.store,
        "status": args.status,
        "exit_code": args.exit_code,
        "verdict_sources": sources,
        "files": dict(sorted(bindings.items())),
    }
    manifest["payload_sha256"] = payload_digest(manifest)
    try:
        output.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n")
    except OSError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return 0


def validate_bound_manifest(args: argparse.Namespace) -> int:
    def refuse(reason: str) -> int:
        print(reason)
        return 3

    if args.flow_id != FLOW or args.purpose != LOG_PURPOSE:
        return refuse("MO-03 evidence manifest context is invalid.")

    candidate = Path(args.manifest)
    if candidate.is_symlink() or not candidate.is_file():
        return refuse("MO-03 evidence manifest must be a regular non-symlinked file.")
    path = candidate.resolve()
    try:
        raw = path.read_bytes()
        manifest = json.loads(raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        return refuse(f"MO-03 evidence manifest is unreadable: {exc}")
    required = {
        "schema",
        "flow",
        "run_stamp",
        "run_scope",
        "store",
        "status",
        "exit_code",
        "verdict_sources",
        "files",
        "payload_sha256",
    }
    if not isinstance(manifest, dict) or set(manifest) != required:
        return refuse("MO-03 evidence manifest has an invalid field set.")
    if (
        manifest.get("schema") != MANIFEST_SCHEMA
        or manifest.get("flow") != FLOW
        or manifest.get("run_stamp") != args.run_stamp
        or manifest.get("run_scope") != args.run_scope
        or manifest.get("store") != args.store
        or manifest.get("status") != args.expected_status
        or manifest.get("exit_code") != args.expected_exit_code
        or manifest.get("payload_sha256") != payload_digest(manifest)
    ):
        return refuse("MO-03 evidence manifest has an invalid run/verdict binding.")
    sources = manifest.get("verdict_sources")
    if (
        not is_string_list(sources)
        or len(sources) != len(set(sources))
        or not set(sources).issubset(VERDICT_SOURCES[args.expected_status])
        or (args.expected_status == "pass" and sources)
        or (args.expected_status != "pass" and not sources)
        or (
            args.expected_status == "fail"
            and not set(sources).intersection(DECISIVE_FAIL_SOURCES)
        )
    ):
        return refuse("MO-03 evidence manifest has invalid verdict sources.")
    files = manifest.get("files")
    if not isinstance(files, dict) or not set(files).issubset(allowed_files(args.store)):
        return refuse("MO-03 evidence manifest contains invalid artifacts.")
    if args.expected_status == "pass" and set(files) != allowed_files(args.store):
        return refuse("Passing MO-03 evidence manifest is incomplete.")
    payloads: dict[str, dict[str, Any]] = {}
    for filename, binding in files.items():
        payload, error = load_bound_artifact(path, filename, binding)
        if error:
            return refuse(error)
        payloads[filename] = payload

    fixtures: dict[str, dict[str, Any]] = {}
    states: dict[str, dict[str, Any]] = {}
    captures: dict[str, dict[str, Any]] = {}
    executions: dict[str, dict[str, Any]] = {}
    log_scans: dict[str, dict[str, Any]] = {}
    comparison_payload = payloads.get("comparison.json")
    for filename, payload in payloads.items():
        if filename.endswith("-fixture.json"):
            store = filename.removesuffix("-fixture.json")
            error = fixture_validation_error(payload, args.run_stamp, expected_store=store)
            if error:
                return refuse(f"{filename}: {error}")
            fixtures[filename] = payload
        elif filename.endswith("-execution.json"):
            store = filename.removesuffix("-execution.json")
            error = execution_validation_error(payload, args.run_stamp)
            if error or payload.get("store") != store:
                return refuse(error or f"execution store binding mismatch: {filename}")
            executions[filename] = payload
        elif filename.endswith("-capture.json"):
            store = filename.removesuffix("-capture.json")
            fixture = fixtures.get(f"{store}-fixture.json")
            error = capture_validation_error(
                payload,
                args.run_stamp,
                expected_store=store,
                expected_fixture=fixture,
            )
            if error:
                return refuse(f"{filename}: {error}")
            captures[filename] = payload
        elif filename.endswith("-log-scan.json"):
            store = filename.removesuffix("-log-scan.json")
            error = log_scan_validation_error(
                payload,
                args.run_stamp,
                expected_store=store,
            )
            if error:
                return refuse(f"{filename}: {error}")
            log_scans[filename] = payload
        elif filename != "comparison.json":
            store, phase = filename.removesuffix(".json").split("-")
            state_blockers: list[str] = []
            state = load_state(path.parent / filename, store, phase, args.run_stamp, state_blockers)
            if state_blockers:
                return refuse(" ".join(state_blockers))
            states[filename] = state

    for store in ("ref", "target"):
        fixture = fixtures.get(f"{store}-fixture.json")
        pre = states.get(f"{store}-pre.json")
        post = states.get(f"{store}-post.json")
        capture_payload = captures.get(f"{store}-capture.json")
        execution = executions.get(f"{store}-execution.json")
        log_scan = log_scans.get(f"{store}-log-scan.json")
        if execution is not None:
            error = log_scan_validation_error(
                log_scan,
                args.run_stamp,
                expected_store=store,
                expected_exit_code=execution["log_assertion_exit_code"],
            )
            if error:
                return refuse(f"MO-03 {store} execution lacks matching log evidence: {error}.")
        if execution is not None and execution["authorization_exit_code"] == 0 and execution[
            "authorization_order_id_present"
        ] and fixture is None:
            return refuse(f"MO-03 {store} successful authorization lacks fixture evidence.")
        for state_name, state in (("pre", pre), ("post", post)):
            if fixture is not None and state is not None and state["status"] != "blocked" and (
                state["order"]["id"] != fixture["order_id"]
                or state["order"]["intent_id"] != fixture["intent_id"]
                or state["order"]["charge_id"] != fixture["charge_id"]
            ):
                return refuse(f"MO-03 {store} {state_name} state does not bind its fixture.")
        if pre is not None and post is not None and pre["status"] != "blocked" and post[
            "status"
        ] != "blocked":
            for field in ("id", "created", "intent_id", "charge_id", "currency", "total_minor"):
                if pre["order"][field] != post["order"][field]:
                    return refuse(f"MO-03 {store} order field {field} changed across bound state.")
        if fixture is not None and capture_payload is not None and (
            capture_payload["order_id"] != fixture["order_id"]
            or capture_payload["intent_id"] != fixture["intent_id"]
            or capture_payload["charge_id"] != fixture["charge_id"]
        ):
            return refuse(f"MO-03 {store} capture does not bind its fixture.")

    if comparison_payload is not None:
        required_inputs = {
            "ref-fixture.json",
            "ref-pre.json",
            "ref-capture.json",
            "ref-post.json",
            "target-fixture.json",
            "target-pre.json",
            "target-capture.json",
            "target-post.json",
        }
        if not required_inputs.issubset(payloads):
            return refuse("MO-03 comparison lacks its complete input set.")
        comparison_args = argparse.Namespace(
            reference_fixture=str(path.parent / "ref-fixture.json"),
            reference_pre=str(path.parent / "ref-pre.json"),
            reference_capture=str(path.parent / "ref-capture.json"),
            reference_post=str(path.parent / "ref-post.json"),
            target_fixture=str(path.parent / "target-fixture.json"),
            target_pre=str(path.parent / "target-pre.json"),
            target_capture=str(path.parent / "target-capture.json"),
            target_post=str(path.parent / "target-post.json"),
            run_stamp=args.run_stamp,
        )
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            comparison_rc = compare(comparison_args)
        try:
            recomputed = json.loads(output.getvalue())
        except ValueError:
            return refuse("Trusted MO-03 comparison recomputation emitted invalid JSON.")
        if recomputed != comparison_payload:
            return refuse("MO-03 comparison does not equal its bound evidence.")
        if args.expected_status == "pass" and comparison_rc != 0:
            return refuse("Passing MO-03 manifest recomputes to non-PASS comparison.")

    own_execution = executions.get(f"{args.store}-execution.json")
    if own_execution is None:
        return refuse("MO-03 manifest does not bind its store execution record.")
    if (
        own_execution["status"] != args.expected_status
        or own_execution["exit_code"] != args.expected_exit_code
        or own_execution["verdict_sources"] != sources
    ):
        return refuse("MO-03 manifest contradicts its store execution record.")
    if args.expected_status == "pass" and any(
        payload.get("status") != "pass"
        for payload in [
            *fixtures.values(),
            *states.values(),
            *captures.values(),
            *executions.values(),
            *log_scans.values(),
        ]
    ):
        return refuse("Passing MO-03 manifest binds non-passing evidence.")
    state_requirements = {
        "pre_state_failed": f"{args.store}-pre.json",
        "post_state_failed": f"{args.store}-post.json",
        "pre_state_blocked": f"{args.store}-pre.json",
        "post_state_blocked": f"{args.store}-post.json",
    }
    for source, filename in state_requirements.items():
        if source not in sources:
            continue
        required_status = "blocked" if source.endswith("blocked") else "fail"
        if filename not in states or states[filename]["status"] != required_status:
            return refuse(f"MO-03 verdict source {source} lacks matching {filename}.")
    capture_name = f"{args.store}-capture.json"
    if "capture_operation_failed" in sources and (
        capture_name not in captures or captures[capture_name]["status"] != "fail"
    ):
        return refuse("MO-03 capture failure lacks bound failure evidence.")
    if "capture_operation_blocked" in sources and (
        capture_name not in captures or captures[capture_name]["status"] != "blocked"
    ):
        return refuse("MO-03 capture block lacks bound blocked evidence.")
    if "comparison_failed" in sources and (
        comparison_payload is None or comparison_payload.get("status") != "fail"
    ):
        return refuse("MO-03 comparison failure lacks bound failure evidence.")
    own_log_scan = log_scans.get(f"{args.store}-log-scan.json")
    if "log_assertion_failed" in sources and (
        own_log_scan is None or own_log_scan["status"] != "fail"
    ):
        return refuse("MO-03 log failure lacks bound failure evidence.")
    if "log_assertion_blocked" in sources and (
        own_log_scan is None or own_log_scan["status"] != "blocked"
    ):
        return refuse("MO-03 log block lacks bound blocked evidence.")
    print("sha256:" + hashlib.sha256(raw).hexdigest())
    return 0


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser()
    commands = root.add_subparsers(dest="command", required=True)

    fixture = commands.add_parser("normalize-fixture")
    fixture.add_argument("--store", choices=("ref", "target"), required=True)
    fixture.add_argument("--expected-exit-code", type=int, required=True)
    fixture.add_argument("--run-stamp", required=True)
    fixture.set_defaults(handler=normalize_fixture)

    state = commands.add_parser("normalize-state")
    state.add_argument("--store", choices=("ref", "target"), required=True)
    state.add_argument("--phase", choices=("pre", "post"), required=True)
    state.add_argument("--run-stamp", required=True)
    state.set_defaults(handler=normalize_state)

    capture = commands.add_parser("normalize-capture")
    capture.add_argument("--store", choices=("ref", "target"), required=True)
    capture.add_argument("--order-id", type=int, required=True)
    capture.add_argument("--intent-id", required=True)
    capture.add_argument("--charge-id", required=True)
    capture.add_argument("--expected-exit-code", type=int, required=True)
    capture.add_argument("--run-stamp", required=True)
    capture.set_defaults(handler=normalize_capture)

    log_scan = commands.add_parser("normalize-log-scan")
    log_scan.add_argument("--input", required=True)
    log_scan.add_argument("--output", required=True)
    log_scan.add_argument("--store", choices=("ref", "target"), required=True)
    log_scan.add_argument("--run-stamp", required=True)
    log_scan.add_argument("--expected-exit-code", type=int, choices=(0, 1, 3), required=True)
    log_scan.add_argument("--flow-id", required=True)
    log_scan.add_argument("--purpose", required=True)
    log_scan.set_defaults(handler=normalize_log_scan)

    comparison = commands.add_parser("compare")
    comparison.add_argument("--reference-fixture", required=True)
    comparison.add_argument("--reference-pre", required=True)
    comparison.add_argument("--reference-capture", required=True)
    comparison.add_argument("--reference-post", required=True)
    comparison.add_argument("--target-fixture", required=True)
    comparison.add_argument("--target-pre", required=True)
    comparison.add_argument("--target-capture", required=True)
    comparison.add_argument("--target-post", required=True)
    comparison.add_argument("--run-stamp", required=True)
    comparison.set_defaults(handler=compare)

    validate = commands.add_parser("validate-comparison")
    validate.add_argument("--run-stamp", required=True)
    validate.add_argument("--expected-exit-code", type=int, choices=(0, 1, 3), required=True)
    validate.set_defaults(handler=validate_comparison)

    execution = commands.add_parser("execution")
    execution.add_argument("--store", choices=("ref", "target"), required=True)
    execution.add_argument("--status", choices=("pass", "fail", "blocked"), required=True)
    execution.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    execution.add_argument("--run-stamp", required=True)
    execution.add_argument("--output", required=True)
    execution.add_argument("--verdict-source", action="append", default=[])
    execution.add_argument("--authorization-exit-code", type=int, required=True)
    execution.add_argument(
        "--authorization-order-id-present", choices=("true", "false"), required=True
    )
    execution.add_argument("--pre-state-exit-code", type=int)
    execution.add_argument("--capture-exit-code", type=int)
    execution.add_argument(
        "--capture-classification", choices=("pass", "product_failure", "blocked")
    )
    execution.add_argument("--post-state-exit-code", type=int)
    execution.add_argument("--comparison-exit-code", type=int)
    execution.add_argument("--log-assertion-exit-code", type=int, required=True)
    execution.set_defaults(handler=build_execution)

    manifest = commands.add_parser("manifest")
    manifest.add_argument("--store", choices=("ref", "target"), required=True)
    manifest.add_argument("--status", choices=("pass", "fail", "blocked"), required=True)
    manifest.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    manifest.add_argument("--run-stamp", required=True)
    manifest.add_argument("--run-scope", choices=("partial", "full"), required=True)
    manifest.add_argument("--output", required=True)
    manifest.add_argument("--file", action="append", default=[])
    manifest.add_argument("--verdict-source", action="append", default=[])
    manifest.set_defaults(handler=build_manifest)

    bound = commands.add_parser("validate-bound-manifest")
    bound.add_argument("--manifest", required=True)
    bound.add_argument("--store", choices=("ref", "target"), required=True)
    bound.add_argument("--run-stamp", required=True)
    bound.add_argument("--run-scope", choices=("partial", "full"), required=True)
    bound.add_argument("--expected-status", choices=("pass", "fail", "blocked"), required=True)
    bound.add_argument("--expected-exit-code", type=int, choices=(0, 1, 3), required=True)
    bound.add_argument("--flow-id", required=True)
    bound.add_argument("--purpose", required=True)
    bound.set_defaults(handler=validate_bound_manifest)
    return root


def main() -> int:
    args = parser().parse_args()
    return args.handler(args)


if __name__ == "__main__":
    raise SystemExit(main())
