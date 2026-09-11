#!/usr/bin/env python3
"""Normalize, compare, and bind MD-01 dispute-created evidence."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import importlib.util
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


FLOW = "MD-01-created-note-on-hold-notify"
BASELINE_SCHEMA = "woopayments_md01_baseline.v1"
RAW_SCHEMA = "woopayments_md01_probe.v1"
PROBE_SCHEMA = "woopayments_md01_normalized.v1"
COMPARISON_SCHEMA = "woopayments_md01_comparison.v1"
EXECUTION_SCHEMA = "woopayments_md01_execution.v1"
MANIFEST_SCHEMA = "woopayments_md01_manifest.v1"
LOG_SCHEMA = "woopayments_md01_log_scan.v1"
WEBHOOK_ORDER_SCHEMA = "woopayments_md01_webhook_order.v1"
RAW_WPCOM_JOBS_SCHEMA = "woopayments_md01_wpcom_jobs_raw.v1"
OWNERS = {"ref": "plugin", "target": "native"}
EXIT_STATUS = {0: "pass", 1: "fail", 3: "blocked"}
STATUS_EXIT = {value: key for key, value in EXIT_STATUS.items()}
RUN_STAMP_RE = re.compile(r"[0-9]{8}T[0-9]{6}Z-[0-9]+")
PROVIDER_ID_RE = re.compile(r"[A-Za-z]{2,4}_[A-Za-z0-9_]+")
DIGEST_RE = re.compile(r"sha256:[0-9a-f]{64}")
HMAC_RE = re.compile(r"hmac-sha256:[0-9a-f]{64}")
KEY_RE = re.compile(r"[0-9a-f]{64}")
PROBE_HMAC_DOMAIN = b"woopayments-md01-normalized-context-v1\0"
MANIFEST_HMAC_DOMAIN = b"woopayments-md01-manifest-context-v1\0"
LOG_HMAC_DOMAIN = b"woopayments-md01-log-context-v1\0"
WEBHOOK_ORDER_HMAC_DOMAIN = b"woopayments-md01-webhook-order-context-v1\0"
PROBE_HMAC_FIELDS = (
    "schema",
    "status",
    "store",
    "run_stamp",
    "runtime_owner",
    "evidence_complete",
    "identity",
    "facts",
    "contract",
    "errors",
    "blockers",
)
MANIFEST_HMAC_FIELDS = (
    "schema",
    "flow",
    "run_stamp",
    "run_scope",
    "store",
    "status",
    "exit_code",
    "verdict_sources",
    "files",
)
LOG_HMAC_FIELDS = (
    "schema",
    "status",
    "store",
    "run_stamp",
    "flow_id",
    "purpose",
    "exit_code",
    "scan_observed",
    "match_count",
    "blocker_code",
    "source_payload_sha256",
)
WEBHOOK_ORDER_HMAC_FIELDS = (
    "schema",
    "status",
    "store",
    "run_stamp",
    "wpcom_blog_id",
    "original_jobs_mode",
    "restored_jobs_mode",
    "dispatches",
)

BASELINE_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "runtime_owner",
    "summary",
    "status_counts",
    "blockers",
}
RAW_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "runtime_owner",
    "order",
    "dispute",
    "summary",
    "status_counts",
    "blockers",
}
SUMMARY_FIELDS = {"http_status", "count", "currencies"}
STATUS_COUNT_FIELDS = {
    "needs_response",
    "warning_needs_response",
    "awaiting_response",
}
ORDER_FIELDS = {
    "id",
    "exists",
    "status",
    "currency",
    "total_minor",
    "charge_id",
    "intent_id",
    "edit_path",
    "notes",
}
NOTE_FIELDS = {
    "created_dispute",
    "reason_context",
    "response_due_context",
    "on_hold_transition",
    "created_note_count",
    "safe_excerpt",
}
DISPUTE_FIELDS = {
    "found",
    "match_count",
    "pages_scanned",
    "id",
    "charge_id",
    "status",
    "amount",
    "currency",
    "reason",
    "due_by",
    "order_number",
}
PROBE_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "runtime_owner",
    "evidence_complete",
    "identity",
    "facts",
    "contract",
    "errors",
    "blockers",
    "context_hmac",
    "payload_sha256",
}
IDENTITY_FIELDS = {"order_id", "intent_id", "charge_id", "dispute_id"}
FACT_FIELDS = {"order", "dispute", "aggregates", "financial_reconciliation"}
CONTRACT_FIELDS = {
    "runtime_owner",
    "order_identity",
    "order_on_hold",
    "created_note",
    "note_reason_context",
    "note_response_due_context",
    "on_hold_transition",
    "exact_dispute",
    "dispute_needs_response",
    "amount_currency",
    "reason_due_by",
    "summary_increment",
    "status_count_increment",
    "financial_reconciliation",
}
COMPARISON_FIELDS = {
    "schema",
    "status",
    "run_stamp",
    "reference",
    "target",
    "parity",
    "errors",
    "blockers",
    "payload_sha256",
}
EXECUTION_BASE_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "exit_code",
    "verdict_sources",
    "probe_exit_code",
    "probe_payload_sha256",
    "log_assertion_exit_code",
    "log_payload_sha256",
    "webhook_order_exit_code",
    "webhook_order_payload_sha256",
    "payload_sha256",
}
LOG_FIELDS = set(LOG_HMAC_FIELDS) | {"context_hmac", "payload_sha256"}
WEBHOOK_ORDER_FIELDS = set(WEBHOOK_ORDER_HMAC_FIELDS) | {"context_hmac", "payload_sha256"}
RAW_WPCOM_JOB_FIELDS = {
    "job_id",
    "event_id",
    "event_type",
    "object_id",
    "charge_id",
    "blog_id",
}
WEBHOOK_DISPATCH_FIELDS = {
    "sequence",
    "event_type",
    "event_id",
    "job_id",
    "object_id",
    "charge_id",
    "run_exit_code",
}


class EvidenceError(ValueError):
    """Raised when evidence cannot be trusted structurally."""


def is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def canonical(payload: Any) -> bytes:
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def context_key() -> bytes:
    value = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if KEY_RE.fullmatch(value) is None:
        raise EvidenceError("runner evidence context is unavailable")
    return bytes.fromhex(value)


def object_hmac(payload: dict[str, Any], fields: tuple[str, ...], domain: bytes) -> str:
    semantics = {field: payload[field] for field in fields}
    return "hmac-sha256:" + hmac.new(context_key(), domain + canonical(semantics), hashlib.sha256).hexdigest()


def emit(payload: dict[str, Any], exit_code: int) -> int:
    result = dict(payload)
    result["payload_sha256"] = payload_digest(result)
    print(json.dumps(result, indent=2, sort_keys=True))
    return exit_code


def extract_json_object(args: argparse.Namespace) -> int:
    raw = sys.stdin.read(2 * 1024 * 1024 + 1)
    if len(raw) > 2 * 1024 * 1024 or not args.expected or len(args.expected) > 128:
        return 3
    decoder = json.JSONDecoder()
    candidates: list[tuple[int, int, dict[str, Any]]] = []
    for index, character in enumerate(raw):
        if character != "{":
            continue
        try:
            candidate, consumed = decoder.raw_decode(raw[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(candidate, dict) and candidate.get(args.discriminator) == args.expected:
            candidates.append((consumed, index, candidate))
    if not candidates:
        return 3
    payload = max(candidates, key=lambda item: (item[0], item[1]))[2]
    print(json.dumps(payload, separators=(",", ":"), sort_keys=True))
    return 0


def load_json(path: Path, *, maximum: int = 1024 * 1024) -> dict[str, Any]:
    safe = safe_existing_file(path, maximum=maximum)
    try:
        payload = json.loads(safe.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise EvidenceError(f"JSON artifact is unreadable: {safe.name}") from exc
    if not isinstance(payload, dict):
        raise EvidenceError(f"JSON artifact is not an object: {safe.name}")
    return payload


def safe_existing_file(path: Path, *, maximum: int = 1024 * 1024) -> Path:
    if not path.is_absolute() or ".." in path.parts:
        raise EvidenceError("artifact path must be absolute without parent traversal")
    lexical = path
    # macOS exposes /var as the stable spelling of /private/var. Normalize that
    # operating-system alias before rejecting caller-controlled symlinked parents.
    if sys.platform == "darwin" and len(path.parts) > 1 and path.parts[1] == "var":
        lexical = Path("/private") / Path(*path.parts[1:])
    for component in (lexical, *lexical.parents):
        if component.is_symlink():
            raise EvidenceError("artifact path contains a symlink")
    try:
        resolved = path.resolve(strict=True)
        stat = resolved.stat()
    except OSError as exc:
        raise EvidenceError("artifact is unavailable") from exc
    if not resolved.is_file() or stat.st_size <= 0 or stat.st_size > maximum:
        raise EvidenceError("artifact must be a bounded regular file")
    return resolved


def validate_string_list(value: Any, *, lower: bool = False) -> bool:
    return isinstance(value, list) and all(
        isinstance(item, str) and bool(item) and (not lower or item == item.lower())
        for item in value
    )


def validate_summary(value: Any) -> None:
    if (
        not isinstance(value, dict)
        or set(value) != SUMMARY_FIELDS
        or value.get("http_status") != 200
        or not is_int(value.get("count"))
        or value["count"] < 0
        or not validate_string_list(value.get("currencies"), lower=True)
        or len(set(value["currencies"])) != len(value["currencies"])
    ):
        raise EvidenceError("disputes summary shape is invalid")


def validate_status_counts(value: Any) -> None:
    if not isinstance(value, dict) or set(value) != STATUS_COUNT_FIELDS:
        raise EvidenceError("dispute status-count shape is invalid")
    if any(not is_int(value[field]) or value[field] < 0 for field in STATUS_COUNT_FIELDS):
        raise EvidenceError("dispute status counts must be non-negative integers")
    if value["awaiting_response"] != value["needs_response"] + value["warning_needs_response"]:
        raise EvidenceError("awaiting-response count is internally inconsistent")


def validate_common_binding(payload: dict[str, Any], schema: str, store: str, run_stamp: str) -> None:
    if store not in OWNERS or RUN_STAMP_RE.fullmatch(run_stamp) is None:
        raise EvidenceError("requested store/run binding is invalid")
    if (
        payload.get("schema") != schema
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("runtime_owner") != OWNERS[store]
    ):
        raise EvidenceError("evidence belongs to a different store, run, or runtime owner")


def validate_baseline(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    if set(payload) != BASELINE_FIELDS:
        raise EvidenceError("baseline field set is invalid")
    validate_common_binding(payload, BASELINE_SCHEMA, store, run_stamp)
    validate_summary(payload["summary"])
    validate_status_counts(payload["status_counts"])
    if not validate_string_list(payload.get("blockers")):
        raise EvidenceError("baseline blockers are invalid")


def validate_baseline_command(args: argparse.Namespace) -> int:
    try:
        payload = load_json(Path(args.baseline))
        validate_baseline(payload, args.store, args.run_stamp)
    except EvidenceError as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return 0


def validate_drive(payload: dict[str, Any], store: str) -> None:
    if payload.get("op") != "dispute" or not is_int(payload.get("order_id")) or payload["order_id"] <= 0:
        raise EvidenceError("deterministic drive identity is invalid")
    for field, prefix in (("intent_id", "pi_"), ("charge_id", ("ch_", "py_"))):
        value = payload.get(field)
        prefixes = (prefix,) if isinstance(prefix, str) else prefix
        if not isinstance(value, str) or not value.startswith(prefixes) or PROVIDER_ID_RE.fullmatch(value) is None:
            raise EvidenceError(f"deterministic drive {field} is invalid")
    if payload.get("status") == "trash":
        raise EvidenceError("deterministic drive order is invalid")
    currency = payload.get("order_currency")
    if currency not in (None, "") and (not isinstance(currency, str) or re.fullmatch(r"[A-Z]{3}", currency) is None):
        raise EvidenceError("deterministic drive currency is invalid")


def validate_raw_probe(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    if set(payload) != RAW_FIELDS:
        raise EvidenceError("raw probe field set is invalid")
    validate_common_binding(payload, RAW_SCHEMA, store, run_stamp)
    if not validate_string_list(payload.get("blockers")):
        raise EvidenceError("raw probe blockers are invalid")

    order = payload.get("order")
    if not isinstance(order, dict) or set(order) != ORDER_FIELDS:
        raise EvidenceError("order projection is invalid")
    if (
        not is_int(order.get("id"))
        or order["id"] <= 0
        or not isinstance(order.get("exists"), bool)
        or not isinstance(order.get("status"), str)
        or re.fullmatch(r"[A-Z]{3}", order.get("currency", "")) is None
        or not is_int(order.get("total_minor"))
        or order["total_minor"] < 0
        or not isinstance(order.get("charge_id"), str)
        or not isinstance(order.get("intent_id"), str)
        or not isinstance(order.get("edit_path"), str)
        or not order["edit_path"].startswith("/wp-admin/")
        or len(order["edit_path"]) > 512
    ):
        raise EvidenceError("order projection values are invalid")
    notes = order.get("notes")
    if not isinstance(notes, dict) or set(notes) != NOTE_FIELDS:
        raise EvidenceError("order-note projection is invalid")
    if any(not isinstance(notes[field], bool) for field in NOTE_FIELDS if field not in {"created_note_count", "safe_excerpt"}):
        raise EvidenceError("order-note facts are invalid")
    if not is_int(notes.get("created_note_count")) or notes["created_note_count"] < 0 or notes["created_note_count"] > 20:
        raise EvidenceError("created-note count is invalid")
    if (
        not isinstance(notes.get("safe_excerpt"), str)
        or len(notes["safe_excerpt"]) > 512
        or any(character in notes["safe_excerpt"] for character in ("\r", "\n", "\x00"))
    ):
        raise EvidenceError("order-note excerpt is invalid")

    dispute = payload.get("dispute")
    if not isinstance(dispute, dict) or set(dispute) != DISPUTE_FIELDS:
        raise EvidenceError("dispute projection is invalid")
    if (
        not isinstance(dispute.get("found"), bool)
        or not is_int(dispute.get("match_count"))
        or dispute["match_count"] not in {0, 1}
        or dispute["found"] != (dispute["match_count"] == 1)
        or not is_int(dispute.get("pages_scanned"))
        or not 1 <= dispute["pages_scanned"] <= 20
        or not isinstance(dispute.get("id"), str)
        or not isinstance(dispute.get("charge_id"), str)
        or not isinstance(dispute.get("status"), str)
        or not is_int(dispute.get("amount"))
        or dispute["amount"] < 0
        or not isinstance(dispute.get("currency"), str)
        or dispute["currency"] != dispute["currency"].lower()
        or not isinstance(dispute.get("reason"), str)
        or not isinstance(dispute.get("due_by"), str)
        or not is_int(dispute.get("order_number"))
        or dispute["order_number"] < 0
    ):
        raise EvidenceError("dispute projection values are invalid")
    if dispute["found"] and (
        PROVIDER_ID_RE.fullmatch(dispute["id"]) is None
        or PROVIDER_ID_RE.fullmatch(dispute["charge_id"]) is None
    ):
        raise EvidenceError("exact dispute provider identity is invalid")
    if not dispute["found"] and any(
        dispute[field] not in {"", 0} for field in ("id", "charge_id", "status", "amount", "currency", "reason", "due_by", "order_number")
    ):
        raise EvidenceError("missing dispute projection retains contradictory values")
    validate_summary(payload["summary"])
    validate_status_counts(payload["status_counts"])


def normalize_probe(args: argparse.Namespace) -> int:
    try:
        key = context_key()
        del key
        baseline = load_json(Path(args.baseline))
        drive = load_json(Path(args.drive))
        reconcile_path = safe_existing_file(Path(args.reconciliation), maximum=2 * 1024 * 1024)
        raw = json.load(sys.stdin)
        if not isinstance(raw, dict):
            raise EvidenceError("raw probe is not an object")
        validate_baseline(baseline, args.store, args.run_stamp)
        validate_drive(drive, args.store)
        validate_raw_probe(raw, args.store, args.run_stamp)
        if args.reconciliation_exit_code not in EXIT_STATUS:
            raise EvidenceError("reconciliation exit code is invalid")
    except (EvidenceError, OSError, UnicodeError, json.JSONDecodeError) as exc:
        payload = empty_probe(args.store, args.run_stamp, "blocked")
        payload["blockers"] = [f"invalid_evidence: {exc}"]
        payload["context_hmac"] = object_hmac(payload, PROBE_HMAC_FIELDS, PROBE_HMAC_DOMAIN)
        return emit(payload, 3)

    order = raw["order"]
    notes = order["notes"]
    dispute = raw["dispute"]
    baseline_summary = baseline["summary"]["count"]
    post_summary = raw["summary"]["count"]
    baseline_status = baseline["status_counts"]["awaiting_response"]
    post_status = raw["status_counts"]["awaiting_response"]
    expected_currency = order["currency"].lower()
    identity = {
        "order_id": drive["order_id"],
        "intent_id": drive["intent_id"],
        "charge_id": drive["charge_id"],
        "dispute_id": dispute["id"],
    }
    contract = {
        "runtime_owner": raw["runtime_owner"] == OWNERS[args.store],
        "order_identity": bool(
            order["exists"]
            and order["id"] == drive["order_id"]
            and order["intent_id"] == drive["intent_id"]
            and order["charge_id"] == drive["charge_id"]
        ),
        "order_on_hold": order["status"] == "on-hold",
        "created_note": notes["created_dispute"] and notes["created_note_count"] == 1,
        "note_reason_context": notes["reason_context"],
        "note_response_due_context": notes["response_due_context"],
        "on_hold_transition": notes["on_hold_transition"],
        "exact_dispute": bool(
            dispute["found"]
            and dispute["match_count"] == 1
            and dispute["charge_id"] == drive["charge_id"]
            and dispute["order_number"] == drive["order_id"]
        ),
        "dispute_needs_response": dispute["status"] == "needs_response",
        "amount_currency": bool(
            dispute["amount"] == order["total_minor"]
            and dispute["currency"] == expected_currency
        ),
        "reason_due_by": bool(dispute["reason"] and dispute["due_by"]),
        "summary_increment": post_summary == baseline_summary + 1,
        "status_count_increment": post_status == baseline_status + 1,
        "financial_reconciliation": args.reconciliation_exit_code == 0,
    }
    facts = {
        "order": {
            "exists": order["exists"],
            "status": order["status"],
            "currency": order["currency"],
            "total_minor": order["total_minor"],
            "edit_path": order["edit_path"],
            "notes": notes,
        },
        "dispute": {
            "found": dispute["found"],
            "match_count": dispute["match_count"],
            "pages_scanned": dispute["pages_scanned"],
            "status": dispute["status"],
            "amount": dispute["amount"],
            "currency": dispute["currency"],
            "reason": dispute["reason"],
            "due_by": dispute["due_by"],
            "order_number": dispute["order_number"],
        },
        "aggregates": {
            "summary_baseline": baseline_summary,
            "summary_post": post_summary,
            "summary_delta": post_summary - baseline_summary,
            "awaiting_response_baseline": baseline_status,
            "awaiting_response_post": post_status,
            "awaiting_response_delta": post_status - baseline_status,
            "summary_currencies": raw["summary"]["currencies"],
        },
        "financial_reconciliation": {
            "exit_code": args.reconciliation_exit_code,
            "log_sha256": file_digest(reconcile_path),
        },
    }
    errors: list[str] = []
    blockers = [*baseline["blockers"], *raw["blockers"]]
    if args.reconciliation_exit_code == 1:
        errors.append("financial_reconciliation_failed")
    elif args.reconciliation_exit_code == 3:
        blockers.append("financial_reconciliation_blocked")
    errors.extend(name for name, passed in contract.items() if not passed and name != "financial_reconciliation")
    if blockers:
        status, exit_code = "blocked", 3
    elif errors:
        status, exit_code = "fail", 1
    else:
        status, exit_code = "pass", 0
    payload = {
        "schema": PROBE_SCHEMA,
        "status": status,
        "store": args.store,
        "run_stamp": args.run_stamp,
        "runtime_owner": raw["runtime_owner"],
        "evidence_complete": status == "pass",
        "identity": identity,
        "facts": facts,
        "contract": contract,
        "errors": sorted(set(errors)),
        "blockers": sorted(set(blockers)),
        "context_hmac": "",
    }
    payload["context_hmac"] = object_hmac(payload, PROBE_HMAC_FIELDS, PROBE_HMAC_DOMAIN)
    return emit(payload, exit_code)


def empty_probe(store: str, run_stamp: str, status: str) -> dict[str, Any]:
    return {
        "schema": PROBE_SCHEMA,
        "status": status,
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": OWNERS.get(store, ""),
        "evidence_complete": False,
        "identity": {field: 0 if field == "order_id" else "" for field in IDENTITY_FIELDS},
        "facts": {},
        "contract": {field: False for field in CONTRACT_FIELDS},
        "errors": [],
        "blockers": [],
        "context_hmac": "",
    }


def validate_probe(payload: dict[str, Any], store: str, run_stamp: str, expected_exit_code: int | None = None) -> None:
    if set(payload) != PROBE_FIELDS:
        raise EvidenceError("normalized probe field set is invalid")
    validate_common_binding(payload, PROBE_SCHEMA, store, run_stamp)
    status = payload.get("status")
    if status not in STATUS_EXIT or payload.get("evidence_complete") != (status == "pass"):
        raise EvidenceError("normalized probe status is invalid")
    if expected_exit_code is not None and STATUS_EXIT[status] != expected_exit_code:
        raise EvidenceError("normalized probe contradicts its process exit code")
    if payload.get("payload_sha256") != payload_digest(payload):
        raise EvidenceError("normalized probe payload digest does not match")
    if payload.get("context_hmac") != object_hmac(payload, PROBE_HMAC_FIELDS, PROBE_HMAC_DOMAIN):
        raise EvidenceError("normalized probe context HMAC does not match")
    if not isinstance(payload.get("identity"), dict) or set(payload["identity"]) != IDENTITY_FIELDS:
        raise EvidenceError("normalized identity is invalid")
    if not isinstance(payload.get("contract"), dict) or set(payload["contract"]) != CONTRACT_FIELDS:
        raise EvidenceError("normalized contract is invalid")
    if any(not isinstance(value, bool) for value in payload["contract"].values()):
        raise EvidenceError("normalized contract values are invalid")
    if not validate_string_list(payload.get("errors")) or not validate_string_list(payload.get("blockers")):
        raise EvidenceError("normalized diagnostics are invalid")
    if status == "pass":
        if set(payload.get("facts", {})) != FACT_FIELDS or not all(payload["contract"].values()) or payload["errors"] or payload["blockers"]:
            raise EvidenceError("passing normalized probe is incomplete")


def validate_probe_command(args: argparse.Namespace) -> int:
    try:
        payload = load_json(Path(args.probe))
        validate_probe(payload, args.store, args.run_stamp, args.expected_exit_code)
    except EvidenceError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print(payload["payload_sha256"])
    return 0


def compare(args: argparse.Namespace) -> int:
    try:
        reference = load_json(Path(args.reference))
        target = load_json(Path(args.target))
        validate_probe(reference, "ref", args.run_stamp)
        validate_probe(target, "target", args.run_stamp)
    except EvidenceError as exc:
        payload = {
            "schema": COMPARISON_SCHEMA,
            "status": "blocked",
            "run_stamp": args.run_stamp,
            "reference": {},
            "target": {},
            "parity": {},
            "errors": [],
            "blockers": [f"invalid_probe: {exc}"],
        }
        return emit(payload, 3)

    reference_view = comparison_view(reference)
    target_view = comparison_view(target)
    parity = {
        "semantic_end_state": reference_view["semantics"] == target_view["semantics"],
        "both_authoritative": reference["status"] != "blocked" and target["status"] != "blocked",
        "both_pass": reference["status"] == "pass" and target["status"] == "pass",
    }
    errors: list[str] = []
    blockers: list[str] = []
    if reference["status"] == "blocked" or target["status"] == "blocked":
        blockers.append("store_probe_blocked")
    if reference["status"] == "fail" or target["status"] == "fail":
        errors.append("store_probe_failed")
    if not parity["semantic_end_state"]:
        errors.append("semantic_end_state_mismatch")
    if blockers:
        status, exit_code = "blocked", 3
    elif errors:
        status, exit_code = "fail", 1
    else:
        status, exit_code = "pass", 0
    payload = {
        "schema": COMPARISON_SCHEMA,
        "status": status,
        "run_stamp": args.run_stamp,
        "reference": reference_view,
        "target": target_view,
        "parity": parity,
        "errors": errors,
        "blockers": blockers,
    }
    return emit(payload, exit_code)


def validate_comparison(payload: dict[str, Any], run_stamp: str, expected_exit_code: int | None = None) -> None:
    if set(payload) != COMPARISON_FIELDS or payload.get("schema") != COMPARISON_SCHEMA:
        raise EvidenceError("comparison field set is invalid")
    status = payload.get("status")
    if payload.get("run_stamp") != run_stamp or status not in STATUS_EXIT:
        raise EvidenceError("comparison run or status binding is invalid")
    if expected_exit_code is not None and STATUS_EXIT[status] != expected_exit_code:
        raise EvidenceError("comparison contradicts its process exit code")
    if payload.get("payload_sha256") != payload_digest(payload):
        raise EvidenceError("comparison payload digest does not match")
    if not isinstance(payload.get("parity"), dict) or set(payload["parity"]) != {
        "semantic_end_state",
        "both_authoritative",
        "both_pass",
    }:
        raise EvidenceError("comparison parity shape is invalid")
    if any(not isinstance(value, bool) for value in payload["parity"].values()):
        raise EvidenceError("comparison parity values are invalid")
    if not validate_string_list(payload.get("errors")) or not validate_string_list(payload.get("blockers")):
        raise EvidenceError("comparison diagnostics are invalid")
    if status == "pass" and (not all(payload["parity"].values()) or payload["errors"] or payload["blockers"]):
        raise EvidenceError("passing comparison is incomplete")


def validate_log_scan(
    payload: dict[str, Any],
    store: str,
    run_stamp: str,
    expected_exit_code: int | None = None,
) -> None:
    if set(payload) != LOG_FIELDS or payload.get("schema") != LOG_SCHEMA:
        raise EvidenceError("log evidence field set is invalid")
    status = payload.get("status")
    if (
        payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("flow_id") != FLOW
        or payload.get("purpose") != "clean-debug-log"
        or status not in STATUS_EXIT
        or payload.get("exit_code") != STATUS_EXIT[status]
        or (expected_exit_code is not None and payload["exit_code"] != expected_exit_code)
        or not isinstance(payload.get("scan_observed"), bool)
        or not is_int(payload.get("match_count"))
        or payload["match_count"] < 0
        or not isinstance(payload.get("blocker_code"), str)
        or DIGEST_RE.fullmatch(str(payload.get("source_payload_sha256", ""))) is None
        or payload.get("context_hmac") != object_hmac(payload, LOG_HMAC_FIELDS, LOG_HMAC_DOMAIN)
        or payload.get("payload_sha256") != payload_digest(payload)
    ):
        raise EvidenceError("log evidence binding is invalid")
    if status == "pass" and (
        not payload["scan_observed"] or payload["match_count"] != 0 or payload["blocker_code"]
    ):
        raise EvidenceError("passing log evidence is contradictory")
    if status == "fail" and (
        not payload["scan_observed"] or payload["match_count"] <= 0 or payload["blocker_code"]
    ):
        raise EvidenceError("failing log evidence is contradictory")
    if status == "blocked" and (
        payload["scan_observed"] or payload["match_count"] != 0 or not payload["blocker_code"]
    ):
        raise EvidenceError("blocked log evidence is contradictory")


def normalize_log_scan(args: argparse.Namespace) -> int:
    source_digest = "sha256:" + hashlib.sha256(b"").hexdigest()
    try:
        raw = load_json(Path(args.input))
        source_digest = "sha256:" + hashlib.sha256(canonical(raw)).hexdigest()
        if (
            set(raw) != {"schema", "store", "scan"}
            or raw.get("schema") != "woopayments_debug_log_scan.v6"
            or raw.get("store") != args.store
            or not isinstance(raw.get("scan"), dict)
        ):
            raise EvidenceError("common log scan envelope is invalid")
        validator_path = Path(__file__).with_name("mo03-evidence.py")
        spec = importlib.util.spec_from_file_location("woopayments_md01_log_validator", validator_path)
        if spec is None or spec.loader is None:
            raise EvidenceError("common log validator is unavailable")
        validator = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(validator)
        scan = raw["scan"]
        if set(scan) != validator.COMMON_LOG_SCAN_FIELDS:
            raise EvidenceError("common log scan field set is invalid")
        projection_error = validator.log_projection_error(
            scan,
            args.run_stamp,
            expected_store=args.store,
            expected_flow_id=FLOW,
            expected_purpose="clean-debug-log",
            require_observation=scan.get("status") in {"pass", "fail"},
        )
        if projection_error:
            raise EvidenceError(projection_error)
        expected_status = EXIT_STATUS[args.expected_exit_code]
        if (
            scan.get("status") != expected_status
            or (expected_status == "pass" and (scan.get("matches") or scan.get("blocker_code")))
            or (expected_status == "fail" and (not scan.get("matches") or scan.get("blocker_code")))
            or (
                expected_status == "blocked"
                and scan.get("blocker_code") not in validator.LOG_BLOCKER_CODES
            )
        ):
            raise EvidenceError("common log scan verdict is invalid")
        payload = {
            "schema": LOG_SCHEMA,
            "status": expected_status,
            "store": args.store,
            "run_stamp": args.run_stamp,
            "flow_id": FLOW,
            "purpose": "clean-debug-log",
            "exit_code": args.expected_exit_code,
            "scan_observed": bool(scan["observations"]),
            "match_count": len(scan["matches"]),
            "blocker_code": scan["blocker_code"],
            "source_payload_sha256": source_digest,
            "context_hmac": "",
        }
        payload["context_hmac"] = object_hmac(payload, LOG_HMAC_FIELDS, LOG_HMAC_DOMAIN)
        return emit(payload, args.expected_exit_code)
    except (AttributeError, EvidenceError, ImportError, OSError, RuntimeError, ValueError):
        payload = {
            "schema": LOG_SCHEMA,
            "status": "blocked",
            "store": args.store,
            "run_stamp": args.run_stamp,
            "flow_id": FLOW,
            "purpose": "clean-debug-log",
            "exit_code": 3,
            "scan_observed": False,
            "match_count": 0,
            "blocker_code": "invalid_scan_evidence",
            "source_payload_sha256": source_digest,
            "context_hmac": "",
        }
        try:
            payload["context_hmac"] = object_hmac(payload, LOG_HMAC_FIELDS, LOG_HMAC_DOMAIN)
        except EvidenceError:
            print("runner evidence context is unavailable", file=sys.stderr)
            return 3
        return emit(payload, 3)


def validate_jobs_mode_result(payload: dict[str, Any], expected_mode: str) -> None:
    context = payload.get("context")
    if (
        payload.get("command") != "jobs mode"
        or payload.get("status") != "success"
        or payload.get("exit_code") != 0
        or not isinstance(context, dict)
        or context.get("jobs_mode") != expected_mode
    ):
        raise EvidenceError("WPCOM jobs mode evidence is invalid")


def validate_job_run_result(payload: dict[str, Any], expected_job_id: int) -> None:
    context = payload.get("context")
    if (
        payload.get("command") != "jobs run-one"
        or payload.get("status") != "success"
        or payload.get("exit_code") != 0
        or not isinstance(context, dict)
        or str(context.get("id", "")) != str(expected_job_id)
        or str(context.get("exit_code", "")) != "0"
        or context.get("jobs_mode") != "manual"
        or context.get("prefix") != "wpj_"
    ):
        raise EvidenceError("exact WPCOM job dispatch evidence is invalid")


def validate_raw_wpcom_job(value: Any, expected_type: str) -> dict[str, Any]:
    if not isinstance(value, dict) or set(value) != RAW_WPCOM_JOB_FIELDS:
        raise EvidenceError("WPCOM job projection shape is invalid")
    if (
        not is_int(value.get("job_id"))
        or value["job_id"] <= 0
        or not is_int(value.get("blog_id"))
        or value["blog_id"] <= 0
        or value.get("event_type") != expected_type
        or PROVIDER_ID_RE.fullmatch(str(value.get("event_id", ""))) is None
        or PROVIDER_ID_RE.fullmatch(str(value.get("object_id", ""))) is None
        or PROVIDER_ID_RE.fullmatch(str(value.get("charge_id", ""))) is None
    ):
        raise EvidenceError("WPCOM job projection values are invalid")
    return value


def seal_webhook_order(args: argparse.Namespace) -> int:
    try:
        drive = load_json(Path(args.drive))
        validate_drive(drive, args.store)
        jobs = load_json(Path(args.jobs))
        if set(jobs) != {"schema", "payment_intent", "dispute_created"} or jobs.get("schema") != RAW_WPCOM_JOBS_SCHEMA:
            raise EvidenceError("WPCOM jobs projection envelope is invalid")
        if not isinstance(jobs["payment_intent"], list) or len(jobs["payment_intent"]) != 1:
            raise EvidenceError("exact payment-intent forwarding job is ambiguous")
        if not isinstance(jobs["dispute_created"], list) or len(jobs["dispute_created"]) != 1:
            raise EvidenceError("exact dispute-created forwarding job is ambiguous")
        payment = validate_raw_wpcom_job(jobs["payment_intent"][0], "payment_intent.succeeded")
        dispute = validate_raw_wpcom_job(jobs["dispute_created"][0], "charge.dispute.created")
        if (
            payment["object_id"] != drive["intent_id"]
            or payment["charge_id"] != drive["charge_id"]
            or dispute["charge_id"] != drive["charge_id"]
            or payment["blog_id"] != dispute["blog_id"]
            or payment["job_id"] == dispute["job_id"]
            or payment["event_id"] == dispute["event_id"]
        ):
            raise EvidenceError("WPCOM forwarding jobs do not bind the exact driven payment")
        original_mode = load_json(Path(args.original_mode))
        restored_mode = load_json(Path(args.restored_mode))
        validate_jobs_mode_result(original_mode, "automatic")
        validate_jobs_mode_result(restored_mode, "automatic")
        payment_result = load_json(Path(args.payment_result))
        dispute_result = load_json(Path(args.dispute_result))
        validate_job_run_result(payment_result, payment["job_id"])
        validate_job_run_result(dispute_result, dispute["job_id"])
        payload = {
            "schema": WEBHOOK_ORDER_SCHEMA,
            "status": "pass",
            "store": args.store,
            "run_stamp": args.run_stamp,
            "wpcom_blog_id": payment["blog_id"],
            "original_jobs_mode": "automatic",
            "restored_jobs_mode": "automatic",
            "dispatches": [
                {
                    "sequence": 1,
                    "event_type": payment["event_type"],
                    "event_id": payment["event_id"],
                    "job_id": payment["job_id"],
                    "object_id": payment["object_id"],
                    "charge_id": payment["charge_id"],
                    "run_exit_code": 0,
                },
                {
                    "sequence": 2,
                    "event_type": dispute["event_type"],
                    "event_id": dispute["event_id"],
                    "job_id": dispute["job_id"],
                    "object_id": dispute["object_id"],
                    "charge_id": dispute["charge_id"],
                    "run_exit_code": 0,
                },
            ],
            "context_hmac": "",
        }
        payload["context_hmac"] = object_hmac(
            payload,
            WEBHOOK_ORDER_HMAC_FIELDS,
            WEBHOOK_ORDER_HMAC_DOMAIN,
        )
        validate_webhook_order(payload | {"payload_sha256": payload_digest(payload)}, args.store, args.run_stamp)
    except (EvidenceError, OSError, UnicodeError, json.JSONDecodeError) as exc:
        print(str(exc), file=sys.stderr)
        return 3
    return emit(payload, 0)


def validate_webhook_order(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    if set(payload) != WEBHOOK_ORDER_FIELDS or payload.get("schema") != WEBHOOK_ORDER_SCHEMA:
        raise EvidenceError("webhook-order evidence field set is invalid")
    dispatches = payload.get("dispatches")
    if (
        payload.get("status") != "pass"
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("original_jobs_mode") != "automatic"
        or payload.get("restored_jobs_mode") != "automatic"
        or not is_int(payload.get("wpcom_blog_id"))
        or payload["wpcom_blog_id"] <= 0
        or not isinstance(dispatches, list)
        or len(dispatches) != 2
        or payload.get("context_hmac")
        != object_hmac(payload, WEBHOOK_ORDER_HMAC_FIELDS, WEBHOOK_ORDER_HMAC_DOMAIN)
        or payload.get("payload_sha256") != payload_digest(payload)
    ):
        raise EvidenceError("webhook-order evidence binding is invalid")
    expected_types = ("payment_intent.succeeded", "charge.dispute.created")
    for index, dispatch in enumerate(dispatches):
        if (
            not isinstance(dispatch, dict)
            or set(dispatch) != WEBHOOK_DISPATCH_FIELDS
            or dispatch.get("sequence") != index + 1
            or dispatch.get("event_type") != expected_types[index]
            or dispatch.get("run_exit_code") != 0
            or not is_int(dispatch.get("job_id"))
            or dispatch["job_id"] <= 0
            or PROVIDER_ID_RE.fullmatch(str(dispatch.get("event_id", ""))) is None
            or PROVIDER_ID_RE.fullmatch(str(dispatch.get("object_id", ""))) is None
            or PROVIDER_ID_RE.fullmatch(str(dispatch.get("charge_id", ""))) is None
        ):
            raise EvidenceError("webhook-order dispatch is invalid")
    if (
        dispatches[0]["job_id"] == dispatches[1]["job_id"]
        or dispatches[0]["event_id"] == dispatches[1]["event_id"]
        or dispatches[0]["charge_id"] != dispatches[1]["charge_id"]
    ):
        raise EvidenceError("webhook-order dispatch identities are contradictory")


def execution(args: argparse.Namespace) -> int:
    try:
        if args.store not in OWNERS or args.status not in STATUS_EXIT or args.exit_code != STATUS_EXIT[args.status]:
            raise EvidenceError("execution verdict binding is invalid")
        if (
            args.probe_exit_code not in EXIT_STATUS
            or args.log_assertion_exit_code not in EXIT_STATUS
            or args.webhook_order_exit_code != 0
        ):
            raise EvidenceError("execution source exit code is invalid")
        probe = load_json(Path(args.probe))
        validate_probe(probe, args.store, args.run_stamp, args.probe_exit_code)
        log_scan = load_json(Path(args.log_scan))
        validate_log_scan(log_scan, args.store, args.run_stamp, args.log_assertion_exit_code)
        webhook_order = load_json(Path(args.webhook_order))
        validate_webhook_order(webhook_order, args.store, args.run_stamp)
        dispatches = webhook_order["dispatches"]
        if (
            dispatches[0]["object_id"] != probe["identity"]["intent_id"]
            or dispatches[0]["charge_id"] != probe["identity"]["charge_id"]
            or dispatches[1]["charge_id"] != probe["identity"]["charge_id"]
            or dispatches[1]["object_id"] != probe["identity"]["dispute_id"]
        ):
            raise EvidenceError("webhook-order evidence does not bind the normalized probe")
        if args.store == "ref" and (args.comparison or args.comparison_exit_code is not None):
            raise EvidenceError("reference execution cannot bind a cross-store comparison")
        comparison_payload_digest = None
        if args.store == "target":
            if not args.comparison or args.comparison_exit_code not in EXIT_STATUS:
                raise EvidenceError("target execution requires a valid comparison binding")
            comparison = load_json(Path(args.comparison))
            validate_comparison(comparison, args.run_stamp, args.comparison_exit_code)
            comparison_payload_digest = comparison["payload_sha256"]
        sources = sorted(set(args.verdict_source))
        if not validate_string_list(sources):
            raise EvidenceError("execution verdict sources are invalid")
        observed_codes = [args.probe_exit_code, args.log_assertion_exit_code, args.webhook_order_exit_code]
        if args.store == "target":
            observed_codes.append(args.comparison_exit_code)
        expected_exit_code = 1 if 1 in observed_codes else 3 if any(code != 0 for code in observed_codes) else 0
        if args.exit_code != expected_exit_code:
            raise EvidenceError("execution verdict contradicts its source exit codes")
        payload = {
            "schema": EXECUTION_SCHEMA,
            "status": args.status,
            "store": args.store,
            "run_stamp": args.run_stamp,
            "exit_code": args.exit_code,
            "verdict_sources": sources,
            "probe_exit_code": args.probe_exit_code,
            "probe_payload_sha256": probe["payload_sha256"],
            "log_assertion_exit_code": args.log_assertion_exit_code,
            "log_payload_sha256": log_scan["payload_sha256"],
            "webhook_order_exit_code": args.webhook_order_exit_code,
            "webhook_order_payload_sha256": webhook_order["payload_sha256"],
        }
        if args.store == "target":
            payload["comparison_exit_code"] = args.comparison_exit_code
            payload["comparison_payload_sha256"] = comparison_payload_digest
    except EvidenceError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return emit(payload, 0)


def validate_execution(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    expected_fields = set(EXECUTION_BASE_FIELDS)
    if store == "target":
        expected_fields.update({"comparison_exit_code", "comparison_payload_sha256"})
    if set(payload) != expected_fields or payload.get("schema") != EXECUTION_SCHEMA:
        raise EvidenceError("execution field set is invalid")
    if (
        payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("status") not in STATUS_EXIT
        or payload.get("exit_code") != STATUS_EXIT[payload["status"]]
        or payload.get("probe_exit_code") not in EXIT_STATUS
        or payload.get("log_assertion_exit_code") not in EXIT_STATUS
        or payload.get("webhook_order_exit_code") != 0
        or payload.get("payload_sha256") != payload_digest(payload)
        or DIGEST_RE.fullmatch(str(payload.get("probe_payload_sha256", ""))) is None
        or DIGEST_RE.fullmatch(str(payload.get("log_payload_sha256", ""))) is None
        or DIGEST_RE.fullmatch(str(payload.get("webhook_order_payload_sha256", ""))) is None
        or not validate_string_list(payload.get("verdict_sources"))
    ):
        raise EvidenceError("execution binding is invalid")
    if store == "target" and (
        payload.get("comparison_exit_code") not in EXIT_STATUS
        or DIGEST_RE.fullmatch(str(payload.get("comparison_payload_sha256", ""))) is None
    ):
        raise EvidenceError("target execution comparison binding is invalid")
    observed_codes = [payload["probe_exit_code"], payload["log_assertion_exit_code"], payload["webhook_order_exit_code"]]
    if store == "target":
        observed_codes.append(payload["comparison_exit_code"])
    expected_exit_code = 1 if 1 in observed_codes else 3 if any(code != 0 for code in observed_codes) else 0
    if payload["exit_code"] != expected_exit_code:
        raise EvidenceError("execution contradicts its source verdicts")


def comparison_view(payload: dict[str, Any]) -> dict[str, Any]:
    facts = payload.get("facts", {})
    dispute = facts.get("dispute", {})
    order = facts.get("order", {})
    aggregates = facts.get("aggregates", {})
    return {
        "status": payload["status"],
        "runtime_owner": payload["runtime_owner"],
        "order_id": payload["identity"]["order_id"],
        "charge_id": payload["identity"]["charge_id"],
        "dispute_id": payload["identity"]["dispute_id"],
        "payload_sha256": payload["payload_sha256"],
        "semantics": {
            "order_status": order.get("status"),
            "currency": order.get("currency"),
            "total_minor": order.get("total_minor"),
            "notes": order.get("notes"),
            "dispute_status": dispute.get("status"),
            "dispute_amount": dispute.get("amount"),
            "dispute_currency": dispute.get("currency"),
            "dispute_reason": dispute.get("reason"),
            "summary_delta": aggregates.get("summary_delta"),
            "awaiting_response_delta": aggregates.get("awaiting_response_delta"),
            "contract": payload.get("contract"),
        },
    }


def validate_execution_artifact_bindings(
    artifacts: dict[str, dict[str, Any]],
    store: str,
) -> None:
    execution_name = f"{store}-execution.json"
    if execution_name not in artifacts:
        return
    probe_name = f"{store}-probe.json"
    log_name = f"{store}-log-scan.json"
    webhook_name = f"{store}-webhook-order.json"
    execution_artifact = artifacts[execution_name]
    if (
        probe_name not in artifacts
        or log_name not in artifacts
        or webhook_name not in artifacts
        or execution_artifact["probe_payload_sha256"] != artifacts[probe_name]["payload_sha256"]
        or execution_artifact["log_payload_sha256"] != artifacts[log_name]["payload_sha256"]
        or execution_artifact["webhook_order_payload_sha256"]
        != artifacts[webhook_name]["payload_sha256"]
    ):
        raise EvidenceError("execution artifact bindings are incomplete")
    identity = artifacts[probe_name]["identity"]
    dispatches = artifacts[webhook_name]["dispatches"]
    if (
        dispatches[0]["object_id"] != identity["intent_id"]
        or dispatches[0]["charge_id"] != identity["charge_id"]
        or dispatches[1]["charge_id"] != identity["charge_id"]
        or dispatches[1]["object_id"] != identity["dispute_id"]
    ):
        raise EvidenceError("webhook-order artifact does not bind the store probe")


def manifest(args: argparse.Namespace) -> int:
    try:
        if args.store not in OWNERS or args.status not in STATUS_EXIT or args.exit_code != STATUS_EXIT[args.status]:
            raise EvidenceError("manifest verdict binding is invalid")
        if RUN_STAMP_RE.fullmatch(args.run_stamp) is None or args.run_scope not in {"partial", "full"}:
            raise EvidenceError("manifest run binding is invalid")
        files: dict[str, Any] = {}
        artifacts: dict[str, dict[str, Any]] = {}
        parent: Path | None = None
        for raw_path in args.file:
            path = safe_existing_file(Path(raw_path))
            if parent is None:
                parent = path.parent
            elif path.parent != parent:
                raise EvidenceError("manifest artifacts do not share one archive directory")
            if path.name in files:
                raise EvidenceError("manifest artifact is duplicated")
            artifact = load_json(path)
            artifact_payload_digest = artifact.get("payload_sha256")
            if not isinstance(artifact_payload_digest, str) or DIGEST_RE.fullmatch(artifact_payload_digest) is None:
                raise EvidenceError("manifest artifact has no payload digest")
            if artifact_payload_digest != payload_digest(artifact):
                raise EvidenceError("manifest artifact payload digest does not match")
            if path.name == f"{args.store}-execution.json":
                validate_execution(artifact, args.store, args.run_stamp)
            elif path.name == "comparison.json":
                validate_comparison(artifact, args.run_stamp)
            elif path.name.endswith("-probe.json"):
                artifact_store = path.name.removesuffix("-probe.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest probe role is invalid")
                validate_probe(artifact, artifact_store, args.run_stamp)
            elif path.name.endswith("-log-scan.json"):
                artifact_store = path.name.removesuffix("-log-scan.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest log role is invalid")
                validate_log_scan(artifact, artifact_store, args.run_stamp)
            elif path.name.endswith("-webhook-order.json"):
                artifact_store = path.name.removesuffix("-webhook-order.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest webhook-order role is invalid")
                validate_webhook_order(artifact, artifact_store, args.run_stamp)
            files[path.name] = {
                "schema": artifact.get("schema", ""),
                "sha256": file_digest(path),
                "payload_sha256": artifact_payload_digest,
            }
            artifacts[path.name] = artifact
        required = {
            f"{args.store}-probe.json",
            f"{args.store}-log-scan.json",
            f"{args.store}-webhook-order.json",
            f"{args.store}-execution.json",
        }
        if args.store == "target":
            required = {
                "ref-probe.json",
                "ref-log-scan.json",
                "ref-webhook-order.json",
                "target-probe.json",
                "target-log-scan.json",
                "target-webhook-order.json",
                "comparison.json",
                "target-execution.json",
            }
        if args.status == "pass" and not required.issubset(files):
            raise EvidenceError("passing manifest is incomplete")
        execution_name = f"{args.store}-execution.json"
        if execution_name in artifacts:
            execution_artifact = artifacts[execution_name]
            validate_execution_artifact_bindings(artifacts, args.store)
            if args.store == "target" and (
                "comparison.json" not in artifacts
                or execution_artifact["comparison_payload_sha256"]
                != artifacts["comparison.json"]["payload_sha256"]
            ):
                raise EvidenceError("target execution comparison binding is incomplete")
        payload = {
            "schema": MANIFEST_SCHEMA,
            "flow": FLOW,
            "run_stamp": args.run_stamp,
            "run_scope": args.run_scope,
            "store": args.store,
            "status": args.status,
            "exit_code": args.exit_code,
            "verdict_sources": sorted(set(args.verdict_source)),
            "files": files,
            "context_hmac": "",
        }
        payload["context_hmac"] = object_hmac(payload, MANIFEST_HMAC_FIELDS, MANIFEST_HMAC_DOMAIN)
    except EvidenceError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    return emit(payload, 0)


def validate_manifest(args: argparse.Namespace) -> int:
    try:
        path = safe_existing_file(Path(args.manifest))
        payload = load_json(path)
        required_fields = set(MANIFEST_HMAC_FIELDS) | {"context_hmac", "payload_sha256"}
        if set(payload) != required_fields or payload.get("schema") != MANIFEST_SCHEMA or payload.get("flow") != FLOW:
            raise EvidenceError("manifest shape is invalid")
        if (
            payload.get("store") != args.store
            or payload.get("status") != args.status
            or payload.get("exit_code") != args.exit_code
            or payload.get("run_stamp") != args.run_stamp
            or payload.get("run_scope") != args.run_scope
        ):
            raise EvidenceError("manifest binding contradicts the runner verdict")
        if payload.get("payload_sha256") != payload_digest(payload):
            raise EvidenceError("manifest payload digest does not match")
        if payload.get("context_hmac") != object_hmac(payload, MANIFEST_HMAC_FIELDS, MANIFEST_HMAC_DOMAIN):
            raise EvidenceError("manifest context HMAC does not match")
        files = payload.get("files")
        if not isinstance(files, dict) or not files:
            raise EvidenceError("manifest files are invalid")
        required = {
            f"{args.store}-probe.json",
            f"{args.store}-log-scan.json",
            f"{args.store}-webhook-order.json",
            f"{args.store}-execution.json",
        }
        if args.store == "target":
            required = {
                "ref-probe.json",
                "ref-log-scan.json",
                "ref-webhook-order.json",
                "target-probe.json",
                "target-log-scan.json",
                "target-webhook-order.json",
                "comparison.json",
                "target-execution.json",
            }
        if args.status == "pass" and not required.issubset(files):
            raise EvidenceError("passing manifest is incomplete")
        artifacts: dict[str, dict[str, Any]] = {}
        for name, binding in files.items():
            if Path(name).name != name or not isinstance(binding, dict) or set(binding) != {"schema", "sha256", "payload_sha256"}:
                raise EvidenceError("manifest artifact binding is malformed")
            artifact_path = path.parent / name
            artifact = load_json(artifact_path)
            if binding["sha256"] != file_digest(artifact_path) or binding["payload_sha256"] != artifact.get("payload_sha256"):
                raise EvidenceError("manifest artifact digest does not match")
            if binding["schema"] != artifact.get("schema") or artifact.get("payload_sha256") != payload_digest(artifact):
                raise EvidenceError("manifest artifact payload binding does not match")
            if name == f"{args.store}-execution.json":
                validate_execution(artifact, args.store, args.run_stamp)
            elif name == "comparison.json":
                validate_comparison(artifact, args.run_stamp)
            elif name.endswith("-probe.json"):
                artifact_store = name.removesuffix("-probe.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest probe role is invalid")
                validate_probe(artifact, artifact_store, args.run_stamp)
            elif name.endswith("-log-scan.json"):
                artifact_store = name.removesuffix("-log-scan.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest log role is invalid")
                validate_log_scan(artifact, artifact_store, args.run_stamp)
            elif name.endswith("-webhook-order.json"):
                artifact_store = name.removesuffix("-webhook-order.json")
                if artifact_store not in OWNERS:
                    raise EvidenceError("manifest webhook-order role is invalid")
                validate_webhook_order(artifact, artifact_store, args.run_stamp)
            artifacts[name] = artifact
        execution_name = f"{args.store}-execution.json"
        if execution_name in artifacts:
            execution_artifact = artifacts[execution_name]
            validate_execution_artifact_bindings(artifacts, args.store)
            if args.store == "target" and (
                "comparison.json" not in artifacts
                or execution_artifact["comparison_payload_sha256"]
                != artifacts["comparison.json"]["payload_sha256"]
            ):
                raise EvidenceError("target execution comparison binding is incomplete")
        digest = file_digest(path)
    except EvidenceError as exc:
        print(str(exc), file=sys.stderr)
        return 1
    print(digest)
    return 0


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    commands = root.add_subparsers(dest="command", required=True)

    extract = commands.add_parser("extract-json-object")
    extract.add_argument("--discriminator", required=True, choices=("schema", "op"))
    extract.add_argument("--expected", required=True)
    extract.set_defaults(handler=extract_json_object)

    normalize = commands.add_parser("normalize-probe")
    normalize.add_argument("--store", required=True, choices=tuple(OWNERS))
    normalize.add_argument("--run-stamp", required=True)
    normalize.add_argument("--baseline", required=True)
    normalize.add_argument("--drive", required=True)
    normalize.add_argument("--reconciliation", required=True)
    normalize.add_argument("--reconciliation-exit-code", required=True, type=int)
    normalize.set_defaults(handler=normalize_probe)

    baseline_parser = commands.add_parser("validate-baseline")
    baseline_parser.add_argument("--baseline", required=True)
    baseline_parser.add_argument("--store", required=True, choices=tuple(OWNERS))
    baseline_parser.add_argument("--run-stamp", required=True)
    baseline_parser.set_defaults(handler=validate_baseline_command)

    validate = commands.add_parser("validate-probe")
    validate.add_argument("--probe", required=True)
    validate.add_argument("--store", required=True, choices=tuple(OWNERS))
    validate.add_argument("--run-stamp", required=True)
    validate.add_argument("--expected-exit-code", required=True, type=int)
    validate.set_defaults(handler=validate_probe_command)

    compare_parser = commands.add_parser("compare")
    compare_parser.add_argument("--reference", required=True)
    compare_parser.add_argument("--target", required=True)
    compare_parser.add_argument("--run-stamp", required=True)
    compare_parser.set_defaults(handler=compare)

    log_parser = commands.add_parser("normalize-log-scan")
    log_parser.add_argument("--input", required=True)
    log_parser.add_argument("--store", required=True, choices=tuple(OWNERS))
    log_parser.add_argument("--run-stamp", required=True)
    log_parser.add_argument("--expected-exit-code", required=True, type=int, choices=tuple(EXIT_STATUS))
    log_parser.set_defaults(handler=normalize_log_scan)

    webhook_order_parser = commands.add_parser("seal-webhook-order")
    webhook_order_parser.add_argument("--store", required=True, choices=tuple(OWNERS))
    webhook_order_parser.add_argument("--run-stamp", required=True)
    webhook_order_parser.add_argument("--drive", required=True)
    webhook_order_parser.add_argument("--jobs", required=True)
    webhook_order_parser.add_argument("--original-mode", required=True)
    webhook_order_parser.add_argument("--payment-result", required=True)
    webhook_order_parser.add_argument("--dispute-result", required=True)
    webhook_order_parser.add_argument("--restored-mode", required=True)
    webhook_order_parser.set_defaults(handler=seal_webhook_order)

    execution_parser = commands.add_parser("execution")
    execution_parser.add_argument("--store", required=True, choices=tuple(OWNERS))
    execution_parser.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    execution_parser.add_argument("--exit-code", required=True, type=int)
    execution_parser.add_argument("--run-stamp", required=True)
    execution_parser.add_argument("--verdict-source", action="append", default=[])
    execution_parser.add_argument("--probe", required=True)
    execution_parser.add_argument("--probe-exit-code", required=True, type=int)
    execution_parser.add_argument("--comparison")
    execution_parser.add_argument("--comparison-exit-code", type=int)
    execution_parser.add_argument("--log-assertion-exit-code", required=True, type=int)
    execution_parser.add_argument("--log-scan", required=True)
    execution_parser.add_argument("--webhook-order-exit-code", required=True, type=int)
    execution_parser.add_argument("--webhook-order", required=True)
    execution_parser.set_defaults(handler=execution)

    manifest_parser = commands.add_parser("manifest")
    manifest_parser.add_argument("--store", required=True, choices=tuple(OWNERS))
    manifest_parser.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    manifest_parser.add_argument("--exit-code", required=True, type=int)
    manifest_parser.add_argument("--run-stamp", required=True)
    manifest_parser.add_argument("--run-scope", required=True, choices=("partial", "full"))
    manifest_parser.add_argument("--verdict-source", action="append", default=[])
    manifest_parser.add_argument("--file", action="append", default=[], required=True)
    manifest_parser.set_defaults(handler=manifest)

    bound = commands.add_parser("validate-bound-manifest")
    bound.add_argument("--manifest", required=True)
    bound.add_argument("--store", required=True, choices=tuple(OWNERS))
    bound.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    bound.add_argument("--exit-code", required=True, type=int)
    bound.add_argument("--run-stamp", required=True)
    bound.add_argument("--run-scope", required=True, choices=("partial", "full"))
    bound.set_defaults(handler=validate_manifest)
    return root


def main() -> int:
    args = parser().parse_args()
    return int(args.handler(args))


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"md01-evidence.py: {exc}", file=sys.stderr)
        raise SystemExit(2)
