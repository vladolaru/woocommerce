#!/usr/bin/env python3
"""Normalize and compare deterministic MO-01 authorization/capture state."""

from __future__ import annotations

import argparse
import contextlib
import hashlib
import io
import json
import re
import sys
from pathlib import Path
from typing import Any


RAW_SCHEMA = "woopayments_mo01_state.v1"
NORMALIZED_SCHEMA = "woopayments_mo01_normalized.v1"
COMPARISON_SCHEMA = "woopayments_mo01_comparison.v1"
MANIFEST_SCHEMA = "woopayments_mo01_manifest.v1"
EXECUTION_SCHEMA = "woopayments_mo01_execution.v1"
ORDER_FIELDS = {
    "id": int,
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
NOTE_FIELDS = {
    "authorization_count": int,
    "capture_success_count": int,
    "capture_failure_count": int,
}
NORMALIZED_COMMON_FIELDS = {
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
NORMALIZED_STATE_FIELDS = {"order", "provider", "notes"}
CANONICAL_ORDER_FIELDS = {
    "status": str,
    "paid": bool,
    "currency": str,
    "total_minor": int,
    "payment_method": str,
    "intention_status": str,
}
CANONICAL_PROVIDER_FIELDS = {
    "intent_status": str,
    "intent_amount_minor": int,
    "intent_currency": str,
    "charge_amount_minor": int,
    "charge_amount_captured_minor": int,
    "charge_captured": bool,
    "charge_currency": str,
}
CANONICAL_NOTE_FIELDS = {
    "authorization_present": bool,
    "capture_success_present": bool,
    "capture_failure_present": bool,
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
    canonical = dict(payload)
    canonical.pop("payload_sha256", None)
    serialized = json.dumps(canonical, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(serialized).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def emit(payload: dict[str, Any], exit_code: int) -> int:
    if payload.get("schema") in {NORMALIZED_SCHEMA, COMPARISON_SCHEMA}:
        payload = dict(payload)
        payload["payload_sha256"] = payload_digest(payload)
    print(json.dumps(payload, indent=2, sort_keys=True))
    return exit_code


def extract_payloads(raw: str) -> list[dict[str, Any]]:
    decoder = json.JSONDecoder()
    matches: list[dict[str, Any]] = []
    for index, character in enumerate(raw):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(raw[index:])
        except (json.JSONDecodeError, ValueError):
            continue
        if isinstance(candidate, dict) and candidate.get("schema") == RAW_SCHEMA:
            matches.append(candidate)
    return matches


def is_string_list(value: Any, *, require_nonempty: bool = False) -> bool:
    return (
        isinstance(value, list)
        and (not require_nonempty or bool(value))
        and all(isinstance(item, str) and bool(item) for item in value)
    )


def is_exact_typed_object(value: Any, fields: dict[str, type]) -> bool:
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


def canonical_projection_is_valid(value: Any, phase: str, *, require_pass: bool) -> bool:
    if not isinstance(value, dict) or set(value) != {"order", "provider", "notes"}:
        return False
    order = value["order"]
    provider = value["provider"]
    notes = value["notes"]
    if not is_exact_typed_object(order, CANONICAL_ORDER_FIELDS):
        return False
    if not is_exact_typed_object(provider, CANONICAL_PROVIDER_FIELDS):
        return False
    if not is_exact_typed_object(notes, CANONICAL_NOTE_FIELDS):
        return False
    if not require_pass:
        return True

    expected = {
        "pre": {
            "status": "on-hold",
            "paid": False,
            "intention_status": "requires_capture",
            "intent_status": "requires_capture",
            "captured_minor": 0,
            "captured": False,
            "capture_success": False,
        },
        "post": {
            "status": "processing",
            "paid": True,
            "intention_status": "succeeded",
            "intent_status": "succeeded",
            "captured_minor": order["total_minor"],
            "captured": True,
            "capture_success": True,
        },
    }[phase]
    return (
        order["status"] == expected["status"]
        and order["paid"] is expected["paid"]
        and order["intention_status"] == expected["intention_status"]
        and order["payment_method"] == "woocommerce_payments"
        and order["total_minor"] > 0
        and bool(re.fullmatch(r"[A-Z]{3}", order["currency"]))
        and provider["intent_status"] == expected["intent_status"]
        and provider["intent_amount_minor"] == order["total_minor"]
        and provider["charge_amount_minor"] == order["total_minor"]
        and provider["charge_amount_captured_minor"] == expected["captured_minor"]
        and provider["charge_captured"] is expected["captured"]
        and provider["intent_currency"] == order["currency"]
        and provider["charge_currency"] == order["currency"]
        and notes["authorization_present"] is True
        and notes["capture_success_present"] is expected["capture_success"]
        and notes["capture_failure_present"] is False
    )


def execution_validation_error(payload: Any, run_stamp: str) -> str | None:
    if not isinstance(payload, dict) or set(payload) != EXECUTION_FIELDS:
        return "execution record has an invalid field set"
    if payload.get("schema") != EXECUTION_SCHEMA or payload.get("run_stamp") != run_stamp:
        return "execution record has an invalid schema/run binding"
    if payload.get("store") not in {"ref", "target"}:
        return "execution record has an invalid store"
    status = payload.get("status")
    exit_code = payload.get("exit_code")
    if status not in VERDICT_SOURCES or exit_code != {"pass": 0, "fail": 1, "blocked": 3}.get(status):
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

    for field in ("authorization_exit_code", "log_assertion_exit_code"):
        if not isinstance(payload.get(field), int) or isinstance(payload.get(field), bool):
            return f"execution record field {field} has the wrong type"
    for field in (
        "pre_state_exit_code",
        "capture_exit_code",
        "post_state_exit_code",
        "comparison_exit_code",
    ):
        value = payload.get(field)
        if value is not None and (not isinstance(value, int) or isinstance(value, bool)):
            return f"execution record field {field} has the wrong type"
    if payload.get("capture_classification") not in {
        None,
        "pass",
        "product_failure",
        "blocked",
    }:
        return "execution record capture classification is invalid"
    capture_exit_code = payload["capture_exit_code"]
    capture_classification = payload["capture_classification"]
    if (
        (capture_exit_code is None and capture_classification is not None)
        or (capture_exit_code is not None and capture_classification is None)
        or (capture_exit_code == 0 and capture_classification != "pass")
        or (capture_classification == "product_failure" and capture_exit_code != 1)
        or (
            capture_classification == "blocked"
            and (capture_exit_code is None or capture_exit_code == 0)
        )
        or (
            capture_exit_code is not None
            and capture_exit_code != 0
            and capture_classification not in {"product_failure", "blocked"}
        )
    ):
        return "execution record capture exit/classification is inconsistent"
    if not isinstance(payload.get("authorization_order_id_present"), bool):
        return "execution record order-id presence has the wrong type"

    if status == "pass":
        expected = {
            "authorization_exit_code": 0,
            "authorization_order_id_present": True,
            "pre_state_exit_code": 0,
            "capture_exit_code": 0,
            "capture_classification": "pass",
            "post_state_exit_code": 0,
            "comparison_exit_code": 0 if payload["store"] == "target" else None,
            "log_assertion_exit_code": 0,
        }
        if any(payload[field] != value for field, value in expected.items()):
            return "passing execution record contains a non-passing operation"

    source_checks = {
        "authorization_driver_blocked": payload["authorization_exit_code"] != 0,
        "authorization_order_id_missing": (
            payload["authorization_exit_code"] == 0
            and payload["authorization_order_id_present"] is False
        ),
        "pre_state_failed": payload["pre_state_exit_code"] == 1,
        "pre_state_blocked": payload["pre_state_exit_code"] == 3,
        "capture_operation_failed": (
            payload["capture_exit_code"] == 1
            and payload["capture_classification"] == "product_failure"
        ),
        "capture_operation_blocked": (
            payload["capture_exit_code"] is not None
            and payload["capture_exit_code"] != 0
            and payload["capture_classification"] == "blocked"
        ),
        "post_state_failed": payload["post_state_exit_code"] == 1,
        "post_state_blocked": payload["post_state_exit_code"] == 3,
        "comparison_failed": payload["comparison_exit_code"] == 1,
        "comparison_blocked": payload["comparison_exit_code"] == 3,
        "log_assertion_failed": payload["log_assertion_exit_code"] == 1,
        "log_assertion_blocked": payload["log_assertion_exit_code"] not in {0, 1},
    }
    for source in sources:
        if not source_checks[source]:
            return f"execution record does not support verdict source {source}"
    return None


def nested(payload: dict[str, Any], path: str, expected_type: type, blockers: list[str]) -> Any:
    value: Any = payload
    for part in path.split("."):
        if not isinstance(value, dict) or part not in value:
            blockers.append(f"State payload is missing {path}.")
            return None
        value = value[part]

    type_matches = isinstance(value, expected_type)
    if expected_type is int and isinstance(value, bool):
        type_matches = False
    if not type_matches:
        blockers.append(f"State payload field {path} has the wrong type.")
        return None
    return value


def project_state(
    payload: dict[str, Any],
    blockers: list[str],
) -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    for section in ("order", "provider", "notes"):
        if not isinstance(payload.get(section), dict):
            blockers.append(f"State payload field {section} has the wrong type.")

    if blockers:
        return {}, {}, {}

    for section, fields in (
        ("order", ORDER_FIELDS),
        ("provider", PROVIDER_FIELDS),
        ("notes", NOTE_FIELDS),
    ):
        unexpected = sorted(set(payload[section]) - set(fields))
        if unexpected:
            blockers.append(
                f"State payload {section} has unexpected fields: {', '.join(unexpected)}."
            )

    order = {
        key: nested(payload, f"order.{key}", kind, blockers)
        for key, kind in ORDER_FIELDS.items()
    }
    provider = {
        key: nested(payload, f"provider.{key}", kind, blockers)
        for key, kind in PROVIDER_FIELDS.items()
    }
    notes = {
        key: nested(payload, f"notes.{key}", kind, blockers)
        for key, kind in NOTE_FIELDS.items()
    }
    return order, provider, notes


def mismatch(errors: list[str], label: str, observed: Any, expected: Any) -> None:
    if observed != expected:
        errors.append(f"{label}={observed} want={expected}")


def state_assertion_errors(
    order: dict[str, Any],
    provider: dict[str, Any],
    notes: dict[str, Any],
    phase: str,
) -> list[str]:
    errors: list[str] = []
    expected = {
        "pre": {
            "order_status": "on-hold",
            "paid": False,
            "intention_status": "requires_capture",
            "intent_status": "requires_capture",
            "captured_minor": 0,
            "captured": False,
        },
        "post": {
            "order_status": "processing",
            "paid": True,
            "intention_status": "succeeded",
            "intent_status": "succeeded",
            "captured_minor": order["total_minor"],
            "captured": True,
        },
    }[phase]

    mismatch(errors, "order status", order["status"], expected["order_status"])
    mismatch(errors, "order paid", order["paid"], expected["paid"])
    mismatch(errors, "order intention status", order["intention_status"], expected["intention_status"])
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

    if order["id"] <= 0:
        errors.append("order id must be positive")
    if order["total_minor"] <= 0:
        errors.append("order total must be positive")
    if not re.fullmatch(r"[A-Z]{3}", order["currency"]):
        errors.append("order currency is not an uppercase ISO code")
    if not re.fullmatch(r"[A-Z]{3}", provider["intent_currency"]):
        errors.append("provider intent currency is not an uppercase ISO code")
    if not re.fullmatch(r"[A-Z]{3}", provider["charge_currency"]):
        errors.append("provider charge currency is not an uppercase ISO code")
    if not re.fullmatch(r"pi_[A-Za-z0-9_]+", order["intent_id"]):
        errors.append("order intent id is not provider-backed")
    if not re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", order["charge_id"]):
        errors.append("order charge id is not provider-backed")
    if notes["authorization_count"] < 1:
        errors.append("authorization order note is missing")
    if notes["capture_failure_count"] != 0:
        errors.append("capture failure order note is present")
    if phase == "pre" and notes["capture_success_count"] != 0:
        errors.append("capture success order note exists before capture")
    if phase == "post" and notes["capture_success_count"] < 1:
        errors.append("capture success order note is missing")
    return errors


def normalize(args: argparse.Namespace) -> int:
    candidates = extract_payloads(sys.stdin.read())
    base: dict[str, Any] = {
        "schema": NORMALIZED_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "phase": args.phase,
        "run_stamp": args.run_stamp,
        "errors": [],
        "blockers": [],
    }
    if len(candidates) != 1:
        base["blockers"] = [
            f"State driver must emit exactly one MO-01 payload; observed {len(candidates)}."
        ]
        return emit(base, 3)
    payload = candidates[0]

    blockers: list[str] = []
    store = nested(payload, "store", str, blockers)
    phase = nested(payload, "phase", str, blockers)
    runtime_owner = nested(payload, "runtime_owner", str, blockers)
    expected_owner = "plugin" if args.store == "ref" else "native"
    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)

    base["runtime_owner"] = runtime_owner
    if store != args.store:
        blockers.append(f"store={store} want={args.store}")
    if phase != args.phase:
        blockers.append(f"phase={phase} want={args.phase}")
    if runtime_owner != expected_owner:
        blockers.append(f"runtime owner={runtime_owner} want={expected_owner}")

    raw_blockers = payload.get("blockers")
    if not is_string_list(raw_blockers):
        blockers.append("State driver blockers field must be a list of non-empty strings.")

    raw_status = payload.get("status")
    if raw_status == "blocked":
        if not is_string_list(raw_blockers, require_nonempty=True):
            blockers.append("State driver reported blocked without a valid reason.")
        elif isinstance(raw_blockers, list):
            blockers.extend(raw_blockers)
        base["blockers"] = blockers
        return emit(base, 3)
    if raw_status != "raw":
        blockers.append(f"State driver emitted invalid status={raw_status}.")
    elif isinstance(raw_blockers, list) and raw_blockers:
        blockers.extend(raw_blockers)

    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)

    order, provider, notes = project_state(payload, blockers)
    if blockers:
        base["blockers"] = blockers
        return emit(base, 3)

    errors = state_assertion_errors(order, provider, notes, args.phase)
    base.update(
        {
            "status": "fail" if errors else "pass",
            "runtime_owner": runtime_owner,
            "order": order,
            "provider": provider,
            "notes": notes,
            "errors": errors,
            "blockers": [],
        }
    )
    return emit(base, 1 if errors else 0)


def load_normalized(
    path: Path,
    label: str,
    expected_store: str,
    expected_phase: str,
    run_stamp: str,
    blockers: list[str],
) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        blockers.append(f"{label} ({path.name}) could not be read as JSON.")
        return {}
    if not isinstance(payload, dict) or payload.get("schema") != NORMALIZED_SCHEMA:
        blockers.append(f"{label} ({path.name}) has an invalid normalized schema.")
        return {}

    local: list[str] = []
    for field, kind in {
        "status": str,
        "store": str,
        "phase": str,
        "run_stamp": str,
        "runtime_owner": str,
        "errors": list,
        "blockers": list,
        "payload_sha256": str,
    }.items():
        value = payload.get(field)
        if not isinstance(value, kind):
            local.append(f"field {field} has the wrong type")

    if local:
        blockers.extend(f"{label} ({path.name}): {reason}." for reason in local)
        return {}

    status = payload["status"]
    expected_fields = set(NORMALIZED_COMMON_FIELDS)
    if status in {"pass", "fail"}:
        expected_fields.update(NORMALIZED_STATE_FIELDS)
    unexpected_fields = sorted(set(payload) - expected_fields)
    if unexpected_fields:
        local.append(
            f"unexpected normalized fields: {', '.join(unexpected_fields)}"
        )

    if payload["payload_sha256"] != payload_digest(payload):
        local.append("payload digest does not match its normalized content")
    expected_owner = "plugin" if expected_store == "ref" else "native"
    if payload["store"] != expected_store:
        local.append(f"store={payload['store']} want={expected_store}")
    if payload["phase"] != expected_phase:
        local.append(f"phase={payload['phase']} want={expected_phase}")
    if payload["runtime_owner"] != expected_owner:
        local.append(f"runtime owner={payload['runtime_owner']} want={expected_owner}")
    if payload["run_stamp"] != run_stamp:
        local.append("belongs to a different runner invocation")
    if not is_string_list(payload["errors"]):
        local.append("errors must contain only non-empty strings")
    if not is_string_list(payload["blockers"]):
        local.append("blockers must contain only non-empty strings")

    if status == "blocked":
        if payload["errors"] or not payload["blockers"]:
            local.append("status/error/blocker fields are inconsistent")
    elif status in {"pass", "fail"}:
        shape_blockers: list[str] = []
        order, provider, notes = project_state(payload, shape_blockers)
        if shape_blockers:
            local.extend(shape_blockers)
        else:
            recomputed = state_assertion_errors(order, provider, notes, expected_phase)
            expected_status = "fail" if recomputed else "pass"
            if (
                status != expected_status
                or payload["errors"] != recomputed
                or payload["blockers"]
            ):
                local.append("status/error/blocker fields are inconsistent")
    else:
        local.append(f"status={status} is invalid")

    if local:
        blockers.extend(f"{label} ({path.name}): {reason}." for reason in local)
        return {}
    return payload


def canonical(payload: dict[str, Any]) -> dict[str, Any]:
    order = payload["order"]
    provider = payload["provider"]
    notes = payload["notes"]
    return {
        "order": {
            key: order[key]
            for key in ("status", "paid", "currency", "total_minor", "payment_method", "intention_status")
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
        "notes": {
            "authorization_present": notes["authorization_count"] > 0,
            "capture_success_present": notes["capture_success_count"] > 0,
            "capture_failure_present": notes["capture_failure_count"] > 0,
        },
    }


def compare(args: argparse.Namespace) -> int:
    blockers: list[str] = []
    specifications = {
        "ref_pre": (Path(args.reference_pre), "ref", "pre"),
        "ref_post": (Path(args.reference_post), "ref", "post"),
        "target_pre": (Path(args.target_pre), "target", "pre"),
        "target_post": (Path(args.target_post), "target", "post"),
    }
    payloads = {
        label: load_normalized(path, label, store, phase, args.run_stamp, blockers)
        for label, (path, store, phase) in specifications.items()
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

    for name, payload in payloads.items():
        if payload["status"] == "blocked":
            blockers.append(f"{name} is blocked.")
    if blockers:
        result["blockers"] = blockers
        return emit(result, 3)

    errors: list[str] = []
    for name, payload in payloads.items():
        if payload["status"] != "pass":
            errors.append(f"{name} did not pass its phase assertions.")

    for store in ("ref", "target"):
        before = payloads[f"{store}_pre"]
        after = payloads[f"{store}_post"]
        for field in ("id", "currency", "total_minor", "payment_method", "intent_id", "charge_id"):
            if before["order"][field] != after["order"][field]:
                errors.append(f"{store} order field {field} changed across capture.")
        for field in (
            "intent_id",
            "intent_amount_minor",
            "intent_currency",
            "charge_id",
            "charge_amount_minor",
            "charge_currency",
        ):
            if before["provider"][field] != after["provider"][field]:
                errors.append(f"{store} provider field {field} changed across capture.")

    for phase in ("pre", "post"):
        reference = canonical(payloads[f"ref_{phase}"])
        target = canonical(payloads[f"target_{phase}"])
        if reference != target:
            errors.append(f"{phase}-capture canonical state differs between reference and target.")

    result.update(
        {
            "status": "fail" if errors else "pass",
            "errors": errors,
            "blockers": [],
            "inputs": {
                label: payload["payload_sha256"]
                for label, payload in payloads.items()
            },
            "reference": {
                "pre": canonical(payloads["ref_pre"]),
                "post": canonical(payloads["ref_post"]),
            },
            "target": {
                "pre": canonical(payloads["target_pre"]),
                "post": canonical(payloads["target_post"]),
            },
        }
    )
    return emit(result, 1 if errors else 0)


def validate_comparison(args: argparse.Namespace) -> int:
    try:
        payload = json.loads(sys.stdin.read())
    except (UnicodeError, ValueError):
        return 3
    if not isinstance(payload, dict):
        return 3

    status = payload.get("status")
    expected_fields = {
        "schema",
        "status",
        "run_stamp",
        "errors",
        "blockers",
        "payload_sha256",
    }
    if status in {"pass", "fail"}:
        expected_fields.update({"inputs", "reference", "target"})
    if set(payload) != expected_fields:
        return 3
    if payload.get("schema") != COMPARISON_SCHEMA:
        return 3
    if payload.get("run_stamp") != args.run_stamp:
        return 3
    if payload.get("payload_sha256") != payload_digest(payload):
        return 3
    if not is_string_list(payload.get("errors")) or not is_string_list(
        payload.get("blockers")
    ):
        return 3

    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(args.expected_exit_code)
    if status != expected_status:
        return 3
    if status == "pass" and (payload["errors"] or payload["blockers"]):
        return 3
    if status == "fail" and (not payload["errors"] or payload["blockers"]):
        return 3
    if status == "blocked" and (payload["errors"] or not payload["blockers"]):
        return 3

    if status in {"pass", "fail"}:
        inputs = payload.get("inputs")
        if not isinstance(inputs, dict) or set(inputs) != {
            "ref_pre",
            "ref_post",
            "target_pre",
            "target_post",
        }:
            return 3
        if not all(
            isinstance(value, str) and re.fullmatch(r"sha256:[0-9a-f]{64}", value)
            for value in inputs.values()
        ):
            return 3
        for store in ("reference", "target"):
            projection = payload.get(store)
            if not isinstance(projection, dict) or set(projection) != {"pre", "post"}:
                return 3
            for phase in ("pre", "post"):
                if not canonical_projection_is_valid(
                    projection[phase],
                    phase,
                    require_pass=status == "pass",
                ):
                    return 3
            if status == "pass":
                for field in ("currency", "total_minor", "payment_method"):
                    if projection["pre"]["order"][field] != projection["post"]["order"][field]:
                        return 3
        if status == "pass" and (
            payload["reference"]["pre"] != payload["target"]["pre"]
            or payload["reference"]["post"] != payload["target"]["post"]
        ):
            return 3
    return 0


def validate_bound_manifest(args: argparse.Namespace) -> int:
    manifest_candidate = Path(args.manifest)

    def refuse(reason: str) -> int:
        print(reason)
        return 3

    if manifest_candidate.is_symlink() or not manifest_candidate.is_file():
        return refuse("MO-01 evidence manifest must be a regular non-symlinked file.")
    manifest_path = manifest_candidate.resolve()

    try:
        manifest_raw = manifest_path.read_bytes()
        manifest = json.loads(manifest_raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError) as exc:
        return refuse(f"MO-01 evidence manifest is unreadable: {exc}")

    required_fields = {
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
    if not isinstance(manifest, dict) or set(manifest) != required_fields:
        return refuse("MO-01 evidence manifest has an invalid field set.")
    if manifest.get("schema") != MANIFEST_SCHEMA:
        return refuse("MO-01 evidence manifest has an invalid schema.")
    if manifest.get("flow") != "MO-01-manual-capture-order":
        return refuse("MO-01 evidence manifest has an invalid flow binding.")
    if manifest.get("run_stamp") != args.run_stamp or manifest.get("run_scope") != args.run_scope:
        return refuse("MO-01 evidence manifest belongs to a different runner invocation.")
    if manifest.get("store") != args.store:
        return refuse("MO-01 evidence manifest has an invalid store binding.")
    if (
        manifest.get("status") != args.expected_status
        or manifest.get("exit_code") != args.expected_exit_code
    ):
        return refuse("MO-01 evidence manifest contradicts the deterministic verdict.")
    if manifest.get("payload_sha256") != payload_digest(manifest):
        return refuse("MO-01 evidence manifest payload digest does not match.")
    verdict_sources = manifest.get("verdict_sources")
    if (
        not is_string_list(verdict_sources)
        or len(verdict_sources) != len(set(verdict_sources))
        or not set(verdict_sources).issubset(VERDICT_SOURCES[args.expected_status])
        or (args.expected_status == "pass" and verdict_sources)
        or (args.expected_status != "pass" and not verdict_sources)
    ):
        return refuse("MO-01 evidence manifest has invalid verdict sources.")

    files = manifest.get("files")
    if not isinstance(files, dict):
        return refuse("MO-01 evidence manifest files must be an object.")
    allowed = (
        {"ref-pre.json", "ref-post.json", "ref-execution.json"}
        if args.store == "ref"
        else {
            "ref-pre.json",
            "ref-post.json",
            "ref-execution.json",
            "target-pre.json",
            "target-post.json",
            "target-execution.json",
            "comparison.json",
        }
    )
    if not set(files).issubset(allowed):
        return refuse("MO-01 evidence manifest contains an unexpected artifact.")
    if args.expected_status == "pass" and set(files) != allowed:
        return refuse("Passing MO-01 evidence manifest is incomplete.")

    normalized: dict[str, dict[str, Any]] = {}
    executions: dict[str, dict[str, Any]] = {}
    comparison_payload: dict[str, Any] | None = None
    for filename, binding in files.items():
        if not isinstance(binding, dict) or set(binding) != {
            "file_sha256",
            "payload_sha256",
        }:
            return refuse(f"MO-01 manifest binding is malformed: {filename}.")
        if not all(
            isinstance(binding.get(field), str)
            and re.fullmatch(r"sha256:[0-9a-f]{64}", binding[field])
            for field in ("file_sha256", "payload_sha256")
        ):
            return refuse(f"MO-01 manifest binding has an invalid digest: {filename}.")

        artifact = manifest_path.parent / filename
        if (
            artifact.is_symlink()
            or not artifact.is_file()
            or artifact.resolve().parent != manifest_path.parent
        ):
            return refuse(
                f"MO-01 manifest artifact escapes the archive or is not a regular file: {filename}."
            )
        try:
            artifact_raw = artifact.read_bytes()
            artifact_payload = json.loads(artifact_raw.decode("utf-8"))
        except (OSError, UnicodeError, ValueError) as exc:
            return refuse(f"MO-01 manifest artifact is unreadable ({filename}): {exc}")
        if not isinstance(artifact_payload, dict):
            return refuse(f"MO-01 manifest artifact is not an object: {filename}.")
        if binding["file_sha256"] != "sha256:" + hashlib.sha256(artifact_raw).hexdigest():
            return refuse(f"MO-01 manifest artifact byte digest does not match: {filename}.")
        if binding["payload_sha256"] != artifact_payload.get("payload_sha256"):
            return refuse(f"MO-01 manifest artifact payload binding does not match: {filename}.")
        if artifact_payload.get("payload_sha256") != payload_digest(artifact_payload):
            return refuse(f"MO-01 manifest artifact payload digest does not match: {filename}.")

        if filename == "comparison.json":
            comparison_payload = artifact_payload
            continue
        if filename.endswith("-execution.json"):
            execution_error = execution_validation_error(artifact_payload, args.run_stamp)
            expected_execution_store = filename.removesuffix("-execution.json")
            if execution_error or artifact_payload.get("store") != expected_execution_store:
                return refuse(
                    f"MO-01 execution artifact failed validation ({filename}): "
                    f"{execution_error or 'store binding mismatch'}"
                )
            executions[filename] = artifact_payload
            continue

        store, phase = filename.removesuffix(".json").split("-")
        semantic_blockers: list[str] = []
        normalized_payload = load_normalized(
            artifact,
            filename,
            store,
            phase,
            args.run_stamp,
            semantic_blockers,
        )
        if semantic_blockers:
            return refuse(
                "MO-01 normalized artifact failed semantic validation: "
                + " ".join(semantic_blockers)
            )
        normalized[filename] = normalized_payload

    if comparison_payload is not None:
        state_names = {
            "ref-pre.json",
            "ref-post.json",
            "target-pre.json",
            "target-post.json",
        }
        if not state_names.issubset(normalized):
            return refuse("MO-01 comparison is not accompanied by all normalized inputs.")
        comparison_args = argparse.Namespace(
            reference_pre=str(manifest_path.parent / "ref-pre.json"),
            reference_post=str(manifest_path.parent / "ref-post.json"),
            target_pre=str(manifest_path.parent / "target-pre.json"),
            target_post=str(manifest_path.parent / "target-post.json"),
            run_stamp=args.run_stamp,
        )
        recomputed_output = io.StringIO()
        with contextlib.redirect_stdout(recomputed_output):
            comparison_rc = compare(comparison_args)
        try:
            recomputed = json.loads(recomputed_output.getvalue())
        except ValueError:
            return refuse("Trusted MO-01 comparison recomputation emitted invalid JSON.")
        if comparison_payload != recomputed:
            return refuse("MO-01 comparison does not equal the bound normalized evidence.")
        if args.expected_status == "pass" and comparison_rc != 0:
            return refuse("Passing MO-01 manifest recomputes to a non-passing comparison.")

    if args.expected_status == "pass" and any(
        payload.get("status") != "pass" for payload in normalized.values()
    ):
        return refuse("Passing MO-01 manifest binds a non-passing normalized artifact.")
    own_execution = executions.get(f"{args.store}-execution.json")
    if own_execution is None:
        return refuse("MO-01 manifest does not bind its store execution record.")
    if (
        own_execution.get("status") != args.expected_status
        or own_execution.get("exit_code") != args.expected_exit_code
        or own_execution.get("verdict_sources") != verdict_sources
    ):
        return refuse("MO-01 manifest contradicts its store execution record.")
    if args.expected_status == "pass" and any(
        payload.get("status") != "pass" for payload in executions.values()
    ):
        return refuse("Passing MO-01 manifest binds a non-passing execution artifact.")

    source_requirements = {
        "pre_state_failed": ("target-pre.json", "fail"),
        "post_state_failed": ("target-post.json", "fail"),
        "pre_state_blocked": ("target-pre.json", "blocked"),
        "post_state_blocked": ("target-post.json", "blocked"),
    }
    if args.store == "ref":
        source_requirements = {
            key: (filename.replace("target-", "ref-"), status)
            for key, (filename, status) in source_requirements.items()
        }
    for source, (filename, required_status) in source_requirements.items():
        if source not in verdict_sources:
            continue
        artifact = normalized.get(filename)
        if artifact is None and required_status == "blocked":
            # Transport-level state probe failures have no normalizable payload;
            # the validated execution record binds the exact blocked exit code.
            continue
        if artifact is None or artifact.get("status") != required_status:
            return refuse(f"MO-01 verdict source {source} is not supported by {filename}.")
    if "comparison_failed" in verdict_sources and (
        comparison_payload is None or comparison_payload.get("status") != "fail"
    ):
        return refuse("MO-01 comparison_failed source is not supported by comparison evidence.")
    if (
        "comparison_blocked" in verdict_sources
        and comparison_payload is not None
        and comparison_payload.get("status") != "blocked"
    ):
        return refuse("MO-01 comparison_blocked source is not supported by comparison evidence.")

    print("sha256:" + hashlib.sha256(manifest_raw).hexdigest())
    return 0


def build_execution(args: argparse.Namespace) -> int:
    payload: dict[str, Any] = {
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
        Path(args.output).write_text(
            json.dumps(payload, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
    except OSError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return 0


def build_manifest(args: argparse.Namespace) -> int:
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(args.exit_code)
    if expected_status != args.status:
        print("Manifest status and exit code are inconsistent.", file=sys.stderr)
        return 3
    if (
        len(args.verdict_source) != len(set(args.verdict_source))
        or not set(args.verdict_source).issubset(VERDICT_SOURCES[args.status])
        or (args.status == "pass" and args.verdict_source)
        or (args.status != "pass" and not args.verdict_source)
    ):
        print("Manifest verdict sources are invalid or incomplete.", file=sys.stderr)
        return 3

    output = Path(args.output).resolve()
    allowed = (
        {"ref-pre.json", "ref-post.json", "ref-execution.json"}
        if args.store == "ref"
        else {
            "ref-pre.json",
            "ref-post.json",
            "ref-execution.json",
            "target-pre.json",
            "target-post.json",
            "target-execution.json",
            "comparison.json",
        }
    )
    required = allowed if args.status == "pass" else set()
    bindings: dict[str, dict[str, str]] = {}
    payloads: dict[str, dict[str, Any]] = {}
    try:
        for raw_path in args.file:
            candidate = Path(raw_path)
            if candidate.is_symlink() or not candidate.is_file():
                raise ValueError(f"Manifest evidence must be a regular non-symlinked file: {raw_path}")
            path = candidate.resolve()
            if path.parent != output.parent or path.name not in allowed or path.name in bindings:
                raise ValueError(f"Invalid manifest evidence path: {raw_path}")
            payload = json.loads(path.read_text(encoding="utf-8"))
            if not isinstance(payload, dict):
                raise ValueError(f"Manifest evidence is not an object: {path.name}")
            semantic_digest = payload.get("payload_sha256")
            if (
                not isinstance(semantic_digest, str)
                or not re.fullmatch(r"sha256:[0-9a-f]{64}", semantic_digest)
                or semantic_digest != payload_digest(payload)
            ):
                raise ValueError(f"Manifest evidence has an invalid payload digest: {path.name}")
            bindings[path.name] = {
                "file_sha256": file_digest(path),
                "payload_sha256": semantic_digest,
            }
            payloads[path.name] = payload
    except (OSError, UnicodeError, ValueError) as exc:
        print(str(exc), file=sys.stderr)
        return 3

    if set(bindings) != required and args.status == "pass":
        print("Passing manifest does not bind the complete evidence set.", file=sys.stderr)
        return 3
    own_execution = payloads.get(f"{args.store}-execution.json")
    if own_execution is None:
        print("Manifest does not bind its store execution record.", file=sys.stderr)
        return 3
    execution_error = execution_validation_error(own_execution, args.run_stamp)
    if execution_error:
        print(execution_error, file=sys.stderr)
        return 3
    if (
        own_execution.get("status") != args.status
        or own_execution.get("exit_code") != args.exit_code
        or own_execution.get("verdict_sources") != sorted(args.verdict_source)
    ):
        print("Manifest contradicts its store execution record.", file=sys.stderr)
        return 3
    if "comparison.json" in payloads:
        expected_inputs = {
            "ref_pre": payloads.get("ref-pre.json", {}).get("payload_sha256"),
            "ref_post": payloads.get("ref-post.json", {}).get("payload_sha256"),
            "target_pre": payloads.get("target-pre.json", {}).get("payload_sha256"),
            "target_post": payloads.get("target-post.json", {}).get("payload_sha256"),
        }
        if payloads["comparison.json"].get("inputs") != expected_inputs:
            print("Comparison inputs do not bind the normalized evidence set.", file=sys.stderr)
            return 3

    manifest: dict[str, Any] = {
        "schema": MANIFEST_SCHEMA,
        "flow": "MO-01-manual-capture-order",
        "run_stamp": args.run_stamp,
        "run_scope": args.run_scope,
        "store": args.store,
        "status": args.status,
        "exit_code": args.exit_code,
        "verdict_sources": sorted(args.verdict_source),
        "files": dict(sorted(bindings.items())),
    }
    manifest["payload_sha256"] = payload_digest(manifest)
    try:
        output.write_text(
            json.dumps(manifest, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
    except OSError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return 0


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser()
    commands = root.add_subparsers(dest="command", required=True)

    normalize_parser = commands.add_parser("normalize")
    normalize_parser.add_argument("--store", choices=("ref", "target"), required=True)
    normalize_parser.add_argument("--phase", choices=("pre", "post"), required=True)
    normalize_parser.add_argument("--run-stamp", required=True)
    normalize_parser.set_defaults(handler=normalize)

    compare_parser = commands.add_parser("compare")
    compare_parser.add_argument("--reference-pre", required=True)
    compare_parser.add_argument("--reference-post", required=True)
    compare_parser.add_argument("--target-pre", required=True)
    compare_parser.add_argument("--target-post", required=True)
    compare_parser.add_argument("--run-stamp", required=True)
    compare_parser.set_defaults(handler=compare)

    validate_parser = commands.add_parser("validate-comparison")
    validate_parser.add_argument("--run-stamp", required=True)
    validate_parser.add_argument(
        "--expected-exit-code",
        type=int,
        choices=(0, 1, 3),
        required=True,
    )
    validate_parser.set_defaults(handler=validate_comparison)

    manifest_parser = commands.add_parser("manifest")
    manifest_parser.add_argument("--store", choices=("ref", "target"), required=True)
    manifest_parser.add_argument("--status", choices=("pass", "fail", "blocked"), required=True)
    manifest_parser.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    manifest_parser.add_argument("--run-stamp", required=True)
    manifest_parser.add_argument("--run-scope", choices=("partial", "full"), required=True)
    manifest_parser.add_argument("--output", required=True)
    manifest_parser.add_argument("--file", action="append", default=[])
    manifest_parser.add_argument("--verdict-source", action="append", default=[])
    manifest_parser.set_defaults(handler=build_manifest)

    execution_parser = commands.add_parser("execution")
    execution_parser.add_argument("--store", choices=("ref", "target"), required=True)
    execution_parser.add_argument("--status", choices=("pass", "fail", "blocked"), required=True)
    execution_parser.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    execution_parser.add_argument("--run-stamp", required=True)
    execution_parser.add_argument("--output", required=True)
    execution_parser.add_argument("--verdict-source", action="append", default=[])
    execution_parser.add_argument("--authorization-exit-code", type=int, required=True)
    execution_parser.add_argument(
        "--authorization-order-id-present",
        choices=("true", "false"),
        required=True,
    )
    execution_parser.add_argument("--pre-state-exit-code", type=int)
    execution_parser.add_argument("--capture-exit-code", type=int)
    execution_parser.add_argument(
        "--capture-classification",
        choices=("pass", "product_failure", "blocked"),
    )
    execution_parser.add_argument("--post-state-exit-code", type=int)
    execution_parser.add_argument("--comparison-exit-code", type=int)
    execution_parser.add_argument("--log-assertion-exit-code", type=int, required=True)
    execution_parser.set_defaults(handler=build_execution)

    bound_manifest_parser = commands.add_parser("validate-bound-manifest")
    bound_manifest_parser.add_argument("--manifest", required=True)
    bound_manifest_parser.add_argument("--store", choices=("ref", "target"), required=True)
    bound_manifest_parser.add_argument("--run-stamp", required=True)
    bound_manifest_parser.add_argument(
        "--run-scope",
        choices=("partial", "full"),
        required=True,
    )
    bound_manifest_parser.add_argument(
        "--expected-status",
        choices=("pass", "fail", "blocked"),
        required=True,
    )
    bound_manifest_parser.add_argument(
        "--expected-exit-code",
        type=int,
        choices=(0, 1, 3),
        required=True,
    )
    bound_manifest_parser.set_defaults(handler=validate_bound_manifest)
    return root


def main() -> int:
    args = parser().parse_args()
    return args.handler(args)


if __name__ == "__main__":
    raise SystemExit(main())
