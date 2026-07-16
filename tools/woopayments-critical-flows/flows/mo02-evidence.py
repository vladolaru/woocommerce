#!/usr/bin/env python3
"""Normalize, compare, and bind deterministic MO-02 evidence."""

from __future__ import annotations

import argparse
import contextlib
import hashlib
import io
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


RAW_STATE_SCHEMA = "woopayments_mo02_state.v1"
RAW_CAPTURE_SCHEMA = "woopayments_mo02_capture.v1"
STATE_SCHEMA = "woopayments_mo02_normalized.v1"
CAPTURE_SCHEMA = "woopayments_mo02_capture_evidence.v1"
COMPARISON_SCHEMA = "woopayments_mo02_comparison.v1"
EXECUTION_SCHEMA = "woopayments_mo02_execution.v1"
MANIFEST_SCHEMA = "woopayments_mo02_manifest.v1"
FLOW = "MO-02-manual-capture-uncaptured-tab"
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
    "route",
    "order_id",
    "intent_id",
    "charge_id",
    "http_status",
    "success",
    "error_code",
    "error_message",
    "payload_sha256",
}
VERDICT_SOURCES = {
    "pass": set(),
    "fail": {
        "pre_state_failed",
        "post_state_failed",
        "comparison_failed",
        "capture_operation_failed",
        "log_assertion_failed",
    },
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


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def emit(payload: dict[str, Any], exit_code: int) -> int:
    payload = dict(payload)
    payload["payload_sha256"] = payload_digest(payload)
    print(json.dumps(payload, indent=2, sort_keys=True))
    return exit_code


def extract_payloads(raw: str, schema: str) -> list[dict[str, Any]]:
    decoder = json.JSONDecoder()
    matches: list[dict[str, Any]] = []
    for index, character in enumerate(raw):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(raw[index:])
        except (json.JSONDecodeError, ValueError):
            continue
        if isinstance(candidate, dict) and candidate.get("schema") == schema:
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


def mismatch(errors: list[str], label: str, observed: Any, expected: Any) -> None:
    if observed != expected:
        errors.append(f"{label}={observed} want={expected}")


def run_epoch(run_stamp: str) -> int | None:
    try:
        parsed = datetime.strptime(run_stamp.split("-", 1)[0], "%Y%m%dT%H%M%SZ")
    except ValueError:
        return None
    return int(parsed.replace(tzinfo=timezone.utc).timestamp())


def state_errors(
    order: dict[str, Any],
    provider: dict[str, Any],
    authorizations: dict[str, Any],
    notes: dict[str, Any],
    phase: str,
    run_stamp: str,
) -> list[str]:
    expected = {
        "pre": {
            "order_status": "on-hold",
            "paid": False,
            "intent_status": "requires_capture",
            "captured_minor": 0,
            "captured": False,
            "row_count": 1,
            "capture_success": 0,
        },
        "post": {
            "order_status": "processing",
            "paid": True,
            "intent_status": "succeeded",
            "captured_minor": order.get("total_minor"),
            "captured": True,
            "row_count": 0,
            "capture_success": 1,
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
    if not re.fullmatch(r"pi_[A-Za-z0-9_]+", order["intent_id"]):
        errors.append("order intent id is not provider-backed")
    if not re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", order["charge_id"]):
        errors.append("order charge id is not provider-backed")
    if authorizations["pages_scanned"] < 1:
        errors.append("authorizations endpoint was not scanned")
    if authorizations["rows_scanned"] < authorizations["exact_match_count"]:
        errors.append("authorizations scan counts are inconsistent")
    invocation_epoch = run_epoch(run_stamp)
    if (
        invocation_epoch is None
        or abs(authorizations["observed_at"] - invocation_epoch) > 900
    ):
        errors.append("authorizations observation time does not bind the runner invocation")
    if invocation_epoch is None or abs(order["created"] - invocation_epoch) > 900:
        errors.append("order creation time does not bind the runner invocation")
    if phase == "pre" and len(authorizations["matched_rows"]) == 1:
        row = authorizations["matched_rows"][0]
        mismatch(errors, "authorization/order charge id", row["charge_id"], order["charge_id"])
        mismatch(
            errors,
            "authorization/order intent id",
            row["payment_intent_id"],
            order["intent_id"],
        )
        mismatch(errors, "authorization/order id", row["order_id"], order["id"])
        mismatch(errors, "authorization amount", row["amount_minor"], order["total_minor"])
        mismatch(errors, "authorization captured amount", row["amount_captured_minor"], 0)
        mismatch(errors, "authorization currency", row["currency"], order["currency"])
        mismatch(errors, "authorization status", row["status"], "succeeded")
        if not (
            authorizations["observed_at"] - (8 * 24 * 60 * 60)
            <= row["created"]
            <= authorizations["observed_at"] + 300
        ):
            errors.append("authorization created timestamp is outside the active window")
        if abs(row["created"] - order["created"]) > 600:
            errors.append("authorization creation time does not bind the seeded order")
    if notes["authorization_count"] < 1:
        errors.append("authorization order note is missing")
    if notes["capture_failure_count"] != 0:
        errors.append("capture failure order note is present")
    if phase == "pre" and notes["capture_success_count"] != 0:
        errors.append("capture success order note exists before capture")
    if phase == "post" and notes["capture_success_count"] < expected["capture_success"]:
        errors.append("capture success order note is missing")
    return errors


def normalize_state(args: argparse.Namespace) -> int:
    candidates = extract_payloads(sys.stdin.read(), RAW_STATE_SCHEMA)
    base: dict[str, Any] = {
        "schema": STATE_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "phase": args.phase,
        "run_stamp": args.run_stamp,
        "runtime_owner": "",
        "errors": [],
        "blockers": [],
    }
    if len(candidates) != 1:
        base["blockers"] = [
            f"State driver must emit exactly one MO-02 payload; observed {len(candidates)}."
        ]
        return emit(base, 3)
    payload = candidates[0]
    blockers: list[str] = []
    expected_owner = "plugin" if args.store == "ref" else "native"
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
    errors = state_errors(
        order,
        provider,
        authorizations,
        notes,
        args.phase,
        args.run_stamp,
    )
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


def normalize_capture(args: argparse.Namespace) -> int:
    candidates = extract_payloads(sys.stdin.read(), RAW_CAPTURE_SCHEMA)
    if len(candidates) != 1:
        return emit(
            {
                "schema": CAPTURE_SCHEMA,
                "status": "blocked",
                "store": args.store,
                "run_stamp": args.run_stamp,
                "route": "",
                "order_id": args.order_id,
                "intent_id": args.intent_id,
                "charge_id": args.charge_id,
                "http_status": 0,
                "success": False,
                "error_code": "capture_evidence_missing",
                "error_message": "Capture driver did not emit exactly one MO-02 capture record.",
            },
            3,
        )
    raw = candidates[0]
    required = set(CAPTURE_FIELDS) - {"run_stamp", "payload_sha256"}
    if set(raw) != required:
        status = "blocked"
    else:
        status = str(raw.get("status", "blocked"))
    payload = {
        "schema": CAPTURE_SCHEMA,
        "status": status,
        "store": raw.get("store", ""),
        "run_stamp": args.run_stamp,
        "route": raw.get("route", ""),
        "order_id": raw.get("order_id", 0),
        "intent_id": raw.get("intent_id", ""),
        "charge_id": raw.get("charge_id", ""),
        "http_status": raw.get("http_status", 0),
        "success": raw.get("success", False),
        "error_code": raw.get("error_code", ""),
        "error_message": raw.get("error_message", ""),
    }
    error = capture_validation_error(
        payload,
        args.run_stamp,
        expected_store=args.store,
        expected_order_id=args.order_id,
        expected_intent_id=args.intent_id,
        expected_charge_id=args.charge_id,
        expected_exit_code=args.expected_exit_code,
        require_digest=False,
    )
    if error:
        payload.update(
            {
                "status": "blocked",
                "success": False,
                "error_code": "invalid_capture_evidence",
                "error_message": error,
            }
        )
        return emit(payload, 3)
    return emit(payload, {"pass": 0, "fail": 1, "blocked": 3}[status])


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
        or payload.get("runtime_owner") != expected_owner
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
        return payload
    shape_blockers: list[str] = []
    order, provider, authorizations, notes = project_state(payload, shape_blockers)
    recomputed = (
        state_errors(order, provider, authorizations, notes, phase, run_stamp)
        if not shape_blockers
        else []
    )
    expected_status = "fail" if recomputed else "pass"
    if (
        shape_blockers
        or payload["status"] != expected_status
        or payload["errors"] != recomputed
        or payload["blockers"]
    ):
        blockers.append(f"{path.name} fails semantic recomputation.")
        return {}
    return payload


def capture_status_from_http(http_status: int, success: bool) -> str:
    if 200 <= http_status < 300 and success:
        return "pass"
    if (
        http_status in {0, 401, 403, 404, 408, 423, 425, 429}
        or http_status >= 500
        or http_status < 400
    ):
        return "blocked"
    if http_status < 500:
        return "fail"
    return "blocked"


def capture_validation_error(
    payload: Any,
    run_stamp: str,
    *,
    expected_store: str | None = None,
    expected_order_id: int | None = None,
    expected_intent_id: str | None = None,
    expected_charge_id: str | None = None,
    expected_exit_code: int | None = None,
    require_digest: bool = True,
) -> str | None:
    expected_fields = CAPTURE_FIELDS if require_digest else CAPTURE_FIELDS - {"payload_sha256"}
    if not isinstance(payload, dict) or set(payload) != expected_fields:
        return "capture evidence has an invalid field set"
    typed = {
        "schema": str,
        "status": str,
        "store": str,
        "run_stamp": str,
        "route": str,
        "order_id": int,
        "intent_id": str,
        "charge_id": str,
        "http_status": int,
        "success": bool,
        "error_code": str,
        "error_message": str,
    }
    if require_digest:
        typed["payload_sha256"] = str
    if not exact_typed_object(payload, typed):
        return "capture evidence has invalid field types"
    if payload["schema"] != CAPTURE_SCHEMA or payload["run_stamp"] != run_stamp:
        return "capture evidence has an invalid schema/run binding"
    if require_digest and payload["payload_sha256"] != payload_digest(payload):
        return "capture evidence payload digest does not match"
    if expected_store is not None and payload["store"] != expected_store:
        return "capture evidence has an invalid store binding"
    if expected_order_id is not None and payload["order_id"] != expected_order_id:
        return "capture evidence has an invalid order binding"
    if expected_intent_id is not None and payload["intent_id"] != expected_intent_id:
        return "capture evidence has an invalid intent binding"
    if expected_charge_id is not None and payload["charge_id"] != expected_charge_id:
        return "capture evidence has an invalid charge binding"
    route = f"/wc/v3/payments/orders/{payload['order_id']}/capture_authorization"
    if payload["route"] != route:
        return "capture evidence has an invalid route binding"
    status = payload["status"]
    trusted_status = capture_status_from_http(payload["http_status"], payload["success"])
    expected_status = None
    if expected_exit_code is not None:
        expected_status = "pass" if expected_exit_code == 0 else "fail" if expected_exit_code == 1 else "blocked"
    if status not in {"pass", "fail", "blocked"} or status != trusted_status or (
        expected_status is not None and status != expected_status
    ):
        return "capture evidence status contradicts trusted HTTP semantics or the driver exit"
    if status == "pass":
        if not (200 <= payload["http_status"] < 300 and payload["success"]):
            return "passing capture evidence does not record a successful HTTP response"
        if payload["error_code"] or payload["error_message"]:
            return "passing capture evidence contains an error"
    else:
        if payload["success"] or not (payload["error_code"] or payload["error_message"]):
            return "non-passing capture evidence lacks a bounded error"
    return None


def load_capture(path: Path, store: str, run_stamp: str, blockers: list[str]) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        blockers.append(f"{path.name} could not be read as JSON.")
        return {}
    error = capture_validation_error(payload, run_stamp, expected_store=store)
    if error:
        blockers.append(f"{path.name}: {error}.")
        return {}
    return payload


def canonical_state(payload: dict[str, Any]) -> dict[str, Any]:
    order = payload["order"]
    provider = payload["provider"]
    authorizations = payload["authorizations"]
    notes = payload["notes"]
    row = authorizations["matched_rows"][0] if authorizations["matched_rows"] else None
    return {
        "order": {
            key: order[key]
            for key in (
                "status",
                "paid",
                "currency",
                "total_minor",
                "payment_method",
                "intention_status",
            )
        },
        "provider": {
            key: provider[key]
            for key in (
                "intent_status",
                "intent_amount_minor",
                "intent_currency",
                "charge_amount_minor",
                "charge_amount_captured_minor",
                "charge_captured",
                "charge_currency",
            )
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
        "notes": {
            "authorization_present": notes["authorization_count"] > 0,
            "capture_success_present": notes["capture_success_count"] > 0,
            "capture_failure_present": notes["capture_failure_count"] > 0,
        },
    }


def canonical_capture(payload: dict[str, Any]) -> dict[str, Any]:
    return {
        "status": payload["status"],
        "http_status": payload["http_status"],
        "success": payload["success"],
    }


def compare(args: argparse.Namespace) -> int:
    blockers: list[str] = []
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
        "ref_capture": load_capture(Path(args.reference_capture), "ref", args.run_stamp, blockers),
        "target_capture": load_capture(
            Path(args.target_capture), "target", args.run_stamp, blockers
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
    for label, payload in {**states, **captures}.items():
        if payload["status"] == "blocked":
            blockers.append(f"{label} is blocked.")
        elif payload["status"] != "pass":
            errors.append(f"{label} did not pass its assertions.")
    if blockers:
        result["blockers"] = blockers
        return emit(result, 3)
    for store in ("ref", "target"):
        before = states[f"{store}_pre"]
        after = states[f"{store}_post"]
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
                errors.append(f"{store} order field {field} changed across row capture.")
        capture = captures[f"{store}_capture"]
        if (
            capture["order_id"] != before["order"]["id"]
            or capture["intent_id"] != before["order"]["intent_id"]
            or capture["charge_id"] != before["order"]["charge_id"]
        ):
            errors.append(f"{store} row capture does not bind the seeded authorization.")
    for phase in ("pre", "post"):
        if canonical_state(states[f"ref_{phase}"]) != canonical_state(states[f"target_{phase}"]):
            errors.append(f"{phase}-capture canonical state differs between reference and target.")
    if canonical_capture(captures["ref_capture"]) != canonical_capture(captures["target_capture"]):
        errors.append("row-capture canonical response differs between reference and target.")
    all_payloads = {**states, **captures}
    result.update(
        {
            "status": "fail" if errors else "pass",
            "errors": errors,
            "blockers": [],
            "inputs": {label: payload["payload_sha256"] for label, payload in all_payloads.items()},
            "reference": {
                "pre": canonical_state(states["ref_pre"]),
                "capture": canonical_capture(captures["ref_capture"]),
                "post": canonical_state(states["ref_post"]),
            },
            "target": {
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
        if not isinstance(payload.get("inputs"), dict) or set(payload["inputs"]) != {
            "ref_pre",
            "ref_capture",
            "ref_post",
            "target_pre",
            "target_capture",
            "target_post",
        }:
            return 3
        if not all(re.fullmatch(r"sha256:[0-9a-f]{64}", str(value)) for value in payload["inputs"].values()):
            return 3
        for store in ("reference", "target"):
            projection = payload.get(store)
            if not isinstance(projection, dict) or set(projection) != {"pre", "capture", "post"}:
                return 3
        if expected_status == "pass" and payload["reference"] != payload["target"]:
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
    ):
        return "execution record has invalid verdict sources"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "execution record payload digest does not match"
    int_fields = ("authorization_exit_code", "log_assertion_exit_code")
    nullable_int_fields = (
        "pre_state_exit_code",
        "capture_exit_code",
        "post_state_exit_code",
        "comparison_exit_code",
    )
    if any(not isinstance(payload.get(field), int) for field in int_fields):
        return "execution record contains an invalid required exit code"
    if any(
        payload.get(field) is not None and not isinstance(payload.get(field), int)
        for field in nullable_int_fields
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
        or (classification == "blocked" and (capture_rc is None or capture_rc == 0))
    ):
        return "execution record capture exit/classification is inconsistent"
    source_checks = {
        "authorization_driver_blocked": payload["authorization_exit_code"] != 0,
        "authorization_order_id_missing": payload["authorization_exit_code"] == 0
        and not payload["authorization_order_id_present"],
        "pre_state_failed": payload["pre_state_exit_code"] == 1,
        "pre_state_blocked": payload["pre_state_exit_code"] == 3,
        "capture_operation_failed": capture_rc == 1 and classification == "product_failure",
        "capture_operation_blocked": capture_rc not in {None, 0} and classification == "blocked",
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
    reference = {"ref-pre.json", "ref-capture.json", "ref-post.json", "ref-execution.json"}
    if store == "ref":
        return reference
    return reference | {
        "target-pre.json",
        "target-capture.json",
        "target-post.json",
        "target-execution.json",
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
            if path.parent != output.parent or path.name not in allowed_files(args.store) or path.name in bindings:
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
    required_failure_artifacts = {
        "pre_state_failed": f"{args.store}-pre.json",
        "post_state_failed": f"{args.store}-post.json",
        "capture_operation_failed": f"{args.store}-capture.json",
        "comparison_failed": "comparison.json",
    }
    for source, filename in required_failure_artifacts.items():
        if source in sources and filename not in payloads:
            print(
                f"Manifest verdict source {source} lacks {filename}.",
                file=sys.stderr,
            )
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

    candidate = Path(args.manifest)
    if candidate.is_symlink() or not candidate.is_file():
        return refuse("MO-02 evidence manifest must be a regular non-symlinked file.")
    path = candidate.resolve()
    try:
        raw = path.read_bytes()
        manifest = json.loads(raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        return refuse(f"MO-02 evidence manifest is unreadable: {exc}")
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
        return refuse("MO-02 evidence manifest has an invalid field set.")
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
        return refuse("MO-02 evidence manifest has an invalid run/verdict binding.")
    sources = manifest.get("verdict_sources")
    if (
        not is_string_list(sources)
        or len(sources) != len(set(sources))
        or not set(sources).issubset(VERDICT_SOURCES[args.expected_status])
        or (args.expected_status == "pass" and sources)
        or (args.expected_status != "pass" and not sources)
    ):
        return refuse("MO-02 evidence manifest has invalid verdict sources.")
    files = manifest.get("files")
    if not isinstance(files, dict) or not set(files).issubset(allowed_files(args.store)):
        return refuse("MO-02 evidence manifest contains invalid artifacts.")
    if args.expected_status == "pass" and set(files) != allowed_files(args.store):
        return refuse("Passing MO-02 evidence manifest is incomplete.")
    payloads: dict[str, dict[str, Any]] = {}
    for filename, binding in files.items():
        payload, error = load_bound_artifact(path, filename, binding)
        if error:
            return refuse(error)
        payloads[filename] = payload
    states: dict[str, dict[str, Any]] = {}
    captures: dict[str, dict[str, Any]] = {}
    executions: dict[str, dict[str, Any]] = {}
    comparison_payload = payloads.get("comparison.json")
    for filename, payload in payloads.items():
        if filename.endswith("-execution.json"):
            store = filename.removesuffix("-execution.json")
            error = execution_validation_error(payload, args.run_stamp)
            if error or payload.get("store") != store:
                return refuse(error or f"execution store binding mismatch: {filename}")
            executions[filename] = payload
        elif filename.endswith("-capture.json"):
            store = filename.removesuffix("-capture.json")
            error = capture_validation_error(payload, args.run_stamp, expected_store=store)
            if error:
                return refuse(f"{filename}: {error}")
            captures[filename] = payload
        elif filename != "comparison.json":
            store, phase = filename.removesuffix(".json").split("-")
            blockers: list[str] = []
            state = load_state(path.parent / filename, store, phase, args.run_stamp, blockers)
            if blockers:
                return refuse(" ".join(blockers))
            states[filename] = state
    for store in ("ref", "target"):
        pre = states.get(f"{store}-pre.json")
        post = states.get(f"{store}-post.json")
        capture_payload = captures.get(f"{store}-capture.json")
        if pre is not None and post is not None:
            for field in (
                "id",
                "created",
                "intent_id",
                "charge_id",
                "currency",
                "total_minor",
            ):
                if pre["order"][field] != post["order"][field]:
                    return refuse(
                        f"MO-02 {store} order field {field} changed across bound state."
                    )
        if pre is not None and capture_payload is not None:
            if (
                capture_payload["order_id"] != pre["order"]["id"]
                or capture_payload["intent_id"] != pre["order"]["intent_id"]
                or capture_payload["charge_id"] != pre["order"]["charge_id"]
            ):
                return refuse(
                    f"MO-02 {store} capture does not bind the seeded authorization."
                )
    if comparison_payload is not None:
        required_inputs = {
            "ref-pre.json",
            "ref-capture.json",
            "ref-post.json",
            "target-pre.json",
            "target-capture.json",
            "target-post.json",
        }
        if not required_inputs.issubset(payloads):
            return refuse("MO-02 comparison lacks its complete input set.")
        comparison_args = argparse.Namespace(
            reference_pre=str(path.parent / "ref-pre.json"),
            reference_capture=str(path.parent / "ref-capture.json"),
            reference_post=str(path.parent / "ref-post.json"),
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
            return refuse("Trusted MO-02 comparison recomputation emitted invalid JSON.")
        if recomputed != comparison_payload:
            return refuse("MO-02 comparison does not equal its bound evidence.")
        if args.expected_status == "pass" and comparison_rc != 0:
            return refuse("Passing MO-02 manifest recomputes to non-PASS comparison.")
    own_execution = executions.get(f"{args.store}-execution.json")
    if own_execution is None:
        return refuse("MO-02 manifest does not bind its store execution record.")
    if (
        own_execution["status"] != args.expected_status
        or own_execution["exit_code"] != args.expected_exit_code
        or own_execution["verdict_sources"] != sources
    ):
        return refuse("MO-02 manifest contradicts its store execution record.")
    if args.expected_status == "pass" and any(
        payload.get("status") != "pass"
        for payload in [*states.values(), *captures.values(), *executions.values()]
    ):
        return refuse("Passing MO-02 manifest binds non-passing evidence.")
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
        if required_status == "fail" and filename not in states:
            return refuse(f"MO-02 verdict source {source} lacks {filename}.")
        if filename in states and states[filename]["status"] != required_status:
            return refuse(f"MO-02 verdict source {source} contradicts {filename}.")
    capture_name = f"{args.store}-capture.json"
    if "capture_operation_failed" in sources and (
        capture_name not in captures or captures[capture_name]["status"] != "fail"
    ):
        return refuse("MO-02 capture failure lacks bound failure evidence.")
    if "capture_operation_blocked" in sources and capture_name in captures and captures[capture_name]["status"] != "blocked":
        return refuse("MO-02 capture block contradicts bound capture evidence.")
    if "comparison_failed" in sources and (
        comparison_payload is None or comparison_payload.get("status") != "fail"
    ):
        return refuse("MO-02 comparison failure lacks bound failure evidence.")
    print("sha256:" + hashlib.sha256(raw).hexdigest())
    return 0


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser()
    commands = root.add_subparsers(dest="command", required=True)

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

    comparison = commands.add_parser("compare")
    comparison.add_argument("--reference-pre", required=True)
    comparison.add_argument("--reference-capture", required=True)
    comparison.add_argument("--reference-post", required=True)
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
    execution.add_argument("--authorization-order-id-present", choices=("true", "false"), required=True)
    execution.add_argument("--pre-state-exit-code", type=int)
    execution.add_argument("--capture-exit-code", type=int)
    execution.add_argument("--capture-classification", choices=("pass", "product_failure", "blocked"))
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
    bound.set_defaults(handler=validate_bound_manifest)
    return root


def main() -> int:
    args = parser().parse_args()
    return args.handler(args)


if __name__ == "__main__":
    raise SystemExit(main())
