#!/usr/bin/env python3
"""Validate and bind one archived MA-10 i18n notes evidence packet."""

from __future__ import annotations

import argparse
from collections import Counter
import hashlib
import hmac
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


RESULT_SCHEMA = "woopayments_i18n_notes_gate_result.v1"
STATE_SCHEMA = "woopayments_i18n_notes_capture.v1"
CATALOG_SCHEMA = "woopayments_i18n_catalog_evidence.v1"
MANIFEST_SCHEMA = "woopayments_ma10_evidence_manifest.v2"
EXPECTED_STORE = "target"
FLOW_ID = "MA-10-i18n-order-notes"
LOG_PURPOSE = "clean-debug-log"
MANIFEST_FIELDS = {
    "schema",
    "store",
    "flow_id",
    "purpose",
    "run_stamp",
    "marker_created_at",
    "path_ids",
    "gate_exit",
    "gate_status",
    "log_status",
    "product_errors",
    "status",
    "files",
}
REQUIRED_FLOWS = ("charge", "refund", "dispute")
REQUIRED_FILES = (
    "charge-flow.json",
    "refund-flow.json",
    "dispute-flow.json",
    "i18n-language-snapshot.json",
    "i18n-language-restore.json",
    "i18n-probe-cleanup.json",
    "i18n-catalog-evidence.json",
    "i18n-notes-state.json",
    "i18n-notes-gate.json",
    "debug-log-scan.json",
)
FLOW_MARKERS = {
    "charge": "[wcpay-i18n:charge]",
    "refund": "[wcpay-i18n:refund]",
    "dispute": "[wcpay-i18n:dispute]",
}
CATALOG_MESSAGE_IDS = {
    "charge": "A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).",
    "refund": "A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)",
    "dispute": 'Payment has been disputed for %1$s with reason "%2$s". <a href="%4$s" target="_blank" rel="noopener noreferrer">Response due by %3$s</a>.',
}
ENGLISH_SENTINELS = (
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
)
ENGLISH_MERCHANT_PATTERNS = {
    "charge": re.compile(r"\bA payment of\b.*\busing WooPayments\b", re.IGNORECASE | re.DOTALL),
    "refund": re.compile(r"\bA refund of\b.*\busing WooPayments\b", re.IGNORECASE | re.DOTALL),
    "dispute": re.compile(
        r"\b(?:Payment has been disputed|Payment inquiry has been raised|Payment dispute (?:and fees|funds|has))\b",
        re.IGNORECASE | re.DOTALL,
    ),
}
LOG_SCAN_FIELDS = {
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
    "stale_marker",
}


class EvidenceError(ValueError):
    """Raised when archived MA-10 evidence is incomplete or contradictory."""


def require(condition: bool, message: str) -> None:
    """Reject a malformed evidence condition."""
    if not condition:
        raise EvidenceError(message)


def read_object(path: Path) -> dict[str, Any]:
    """Read one required JSON object."""
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise EvidenceError(f"could not read {path.name}: {error}") from error
    require(isinstance(payload, dict), f"{path.name} must contain an object")
    return payload


def strict_string_list(payload: dict[str, Any], key: str) -> list[str]:
    """Return one strict string list."""
    value = payload.get(key)
    require(isinstance(value, list), f"{key} must be a list")
    require(all(isinstance(item, str) and item for item in value), f"{key} must contain strings")
    return value


def positive_int(payload: dict[str, Any], key: str, context: str) -> int:
    """Return a positive, non-boolean integer."""
    value = payload.get(key)
    require(isinstance(value, int) and not isinstance(value, bool) and value > 0, f"{context} {key} is invalid")
    return value


def prefixed_string(payload: dict[str, Any], key: str, prefix: str, context: str) -> str:
    """Return a non-empty identifier with the expected provider prefix."""
    value = payload.get(key)
    require(isinstance(value, str) and value.startswith(prefix), f"{context} {key} is invalid")
    return value


def validate_cleanup(evidence: dict[str, dict[str, Any]]) -> None:
    """Require exact language and owned-probe cleanup evidence."""
    snapshot = evidence["i18n-language-snapshot.json"]
    require(snapshot.get("schema") == "woopayments_i18n_language_snapshot.v1", "language snapshot schema is invalid")
    require(snapshot.get("success") is True, "language snapshot did not pass")
    require(isinstance(snapshot.get("exists"), bool), "language snapshot exists flag is invalid")
    require(snapshot.get("autoload") is None or isinstance(snapshot.get("autoload"), str), "language snapshot autoload is invalid")

    language = evidence["i18n-language-restore.json"]
    require(language.get("schema") == "woopayments_i18n_language_restore.v1", "language restore schema is invalid")
    require(language.get("success") is True, "language restoration did not pass")
    require(language.get("restored_snapshot_exact") is True, "language snapshot was not restored exactly")
    require(language.get("errors") == [], "language restoration contains errors")

    probe = evidence["i18n-probe-cleanup.json"]
    require(probe.get("schema") == "woopayments_i18n_probe_cleanup.v1", "probe cleanup schema is invalid")
    require(probe.get("success") is True, "translation probe cleanup did not pass")
    require(probe.get("errors") == [], "translation probe cleanup contains errors")


def validate_successful_charge(charge: dict[str, Any]) -> dict[str, Any]:
    """Validate a successful charge prefix payload."""
    require(charge.get("op") == "charge" and charge.get("result") == "success", "charge flow did not succeed")
    charge_order_id = positive_int(charge, "order_id", "charge flow")
    charge_id = prefixed_string(charge, "charge_id", "ch_", "charge flow")
    charge_intent_id = prefixed_string(charge, "intent_id", "pi_", "charge flow")
    require(charge.get("transaction_id") == charge_intent_id, "charge transaction does not bind its intent")
    return {
        "charge_order_id": charge_order_id,
        "charge_id": charge_id,
        "charge_intent_id": charge_intent_id,
    }


def validate_successful_refund(refund: dict[str, Any], charge_order_id: int) -> str:
    """Validate a successful refund prefix payload."""
    require(refund.get("op") == "refund" and refund.get("success") is True, "refund flow did not succeed")
    require(positive_int(refund, "order_id", "refund flow") == charge_order_id, "refund does not bind the charge order")
    positive_int(refund, "refund_id", "refund flow")
    return prefixed_string(refund, "provider_refund_id", "re_", "refund flow")


def validate_successful_dispute(dispute: dict[str, Any], charge_order_id: int) -> dict[str, Any]:
    """Validate a successful dispute payload."""
    require(dispute.get("op") == "dispute" and dispute.get("result") == "success", "dispute flow did not succeed")
    dispute_order_id = positive_int(dispute, "order_id", "dispute flow")
    require(dispute_order_id != charge_order_id, "dispute flow reused the charge/refund order")
    dispute_charge_id = prefixed_string(dispute, "charge_id", "ch_", "dispute flow")
    dispute_intent_id = prefixed_string(dispute, "intent_id", "pi_", "dispute flow")
    require(dispute.get("transaction_id") == dispute_intent_id, "dispute transaction does not bind its intent")
    return {
        "dispute_order_id": dispute_order_id,
        "dispute_charge_id": dispute_charge_id,
        "dispute_intent_id": dispute_intent_id,
    }


def validate_flow_outputs(evidence: dict[str, dict[str, Any]]) -> dict[str, Any]:
    """Validate the three raw flow outputs and return their join identifiers."""
    joins = validate_successful_charge(evidence["charge-flow.json"])
    joins["refund_id"] = validate_successful_refund(
        evidence["refund-flow.json"], joins["charge_order_id"]
    )
    joins.update(
        validate_successful_dispute(
            evidence["dispute-flow.json"], joins["charge_order_id"]
        )
    )

    return joins


def validate_catalog(catalog: dict[str, Any], catalog_status: str | None) -> None:
    """Validate exact catalog message IDs and translation flags."""
    require(catalog.get("schema") == CATALOG_SCHEMA, "catalog evidence schema is invalid")
    require(catalog.get("locale") == "de_DE", "catalog locale is invalid")
    require(catalog.get("textdomain") == "woocommerce", "catalog text domain is invalid")
    require(catalog.get("textdomain_loaded") is True, "WooCommerce text domain was not loaded")
    messages = catalog.get("messages")
    require(isinstance(messages, dict) and set(messages) == set(REQUIRED_FLOWS), "catalog message set is invalid")
    translated: list[bool] = []
    for flow, message_id in CATALOG_MESSAGE_IDS.items():
        message = messages.get(flow)
        require(isinstance(message, dict), f"catalog {flow} message is invalid")
        require(message.get("message_id") == message_id, f"catalog {flow} message ID is invalid")
        translation = message.get("translation")
        is_translated = message.get("translated")
        require(isinstance(translation, str) and translation, f"catalog {flow} translation is invalid")
        require(isinstance(is_translated, bool), f"catalog {flow} translated flag is invalid")
        require(is_translated == (translation != message_id), f"catalog {flow} translated flag contradicts its text")
        translated.append(is_translated)
    require(catalog_status != "pass" or all(translated), "passing catalog contains untranslated messages")
    require(catalog_status != "blocked" or not all(translated), "blocked catalog has no untranslated message")


def exact_int(value: Any) -> bool:
    """Return whether a value is an exact non-boolean integer."""
    return isinstance(value, int) and not isinstance(value, bool)


def valid_log_fingerprint(value: Any) -> bool:
    """Return whether a value is one canonical SHA-256 fingerprint."""
    return isinstance(value, str) and re.fullmatch(r"sha256:[0-9a-f]{64}", value) is not None


def valid_log_hmac(value: Any) -> bool:
    """Return whether a value is one canonical HMAC-SHA-256 authenticator."""
    return isinstance(value, str) and re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", value) is not None


def valid_log_uuid(value: Any) -> bool:
    """Return whether a value is one lowercase RFC-4122 UUID."""
    return isinstance(value, str) and re.fullmatch(
        r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}",
        value,
    ) is not None


def validate_log_observer_summary(
    summary: Any,
    scan: dict[str, Any],
    key: bytes,
    observations_by_path: dict[str, dict[str, Any]],
) -> Counter[str]:
    """Recompute one complete bounded v2 observer record chain."""
    require(
        isinstance(summary, dict) and set(summary) == LOG_OBSERVER_SUMMARY_FIELDS,
        "debug-log observer summary shape is invalid",
    )
    records = summary.get("records")
    counts = summary.get("category_counts")
    expected_paths = sorted(
        (
            {"path": item["path"], "path_id": item["path_id"]}
            for item in observations_by_path.values()
        ),
        key=lambda item: item["path_id"],
    )
    observer_categories = LOG_MATCH_CATEGORIES | {
        "allowlisted_noise",
        "other",
        "terminal",
    }
    require(
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
        and counts.get("terminal", 0) == len(observations_by_path)
        and valid_log_hmac(summary.get("chain_head"))
        and len(json.dumps(summary, sort_keys=True, separators=(",", ":")).encode("utf-8"))
        <= 1024 * 1024,
        "debug-log observer summary is invalid",
    )

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
        require(isinstance(signed_record, dict), "debug-log observer record is invalid")
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
        require(
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
            and signature == "hmac-sha256:" + expected,
            "debug-log observer record chain is invalid",
        )
        previous = expected
        if kind == "ready":
            require(
                not ready_seen
                and sequence == 1
                and record.get("status") == "pass"
                and record.get("path_count") == len(observations_by_path)
                and record.get("key_fingerprint") == scan["key_fingerprint"]
                and record.get("origin_binding") == scan["origin_binding"],
                "debug-log observer ready record is invalid",
            )
            ready_seen = True
        elif kind == "line":
            path = record.get("path")
            line = record.get("line")
            category = record.get("category")
            observation = observations_by_path.get(path)
            require(
                observation is not None
                and exact_int(line)
                and (
                    line == observation["end_line_count"]
                    if category == "terminal"
                    else line >= 1
                )
                and category in observer_categories
                and valid_log_fingerprint(record.get("fingerprint")),
                "debug-log observer line record is invalid",
            )
            derived[str(category)] += 1
        else:
            require(
                not complete_seen
                and sequence == len(records)
                and record.get("status") == "pass",
                "debug-log observer complete record is invalid",
            )
            complete_seen = True

    require(ready_seen and complete_seen, "debug-log observer lifecycle is incomplete")
    require(
        dict(sorted(derived.items())) == counts
        and sum(derived.values()) == summary["line_event_count"]
        and summary["chain_head"] == "hmac-sha256:" + previous
        and sum(derived.get(category, 0) for category in LOG_MATCH_CATEGORIES) <= 20
        and derived.get("allowlisted_noise", 0) <= 100,
        "debug-log observer summary does not match retained records",
    )
    return derived


def validate_log_scan(payload: dict[str, Any], run_stamp: str) -> str:
    """Validate one strict current-key common debug-log scan v6 packet."""
    require(set(payload) == {"schema", "store", "scan"}, "debug-log scan field set is invalid")
    require(payload.get("schema") == "woopayments_debug_log_scan.v6", "debug-log scan schema is invalid")
    require(payload.get("store") == EXPECTED_STORE, "debug-log scan store is invalid")
    scan = payload.get("scan")
    require(isinstance(scan, dict) and set(scan) == LOG_SCAN_FIELDS, "debug-log scan field set is invalid")
    status = scan.get("status")
    blocker_code = scan.get("blocker_code")
    require(status in {"pass", "fail", "blocked"}, "debug-log scan status is invalid")
    require(isinstance(blocker_code, str), "debug-log blocker code is invalid")
    require(scan.get("run_stamp") == run_stamp, "debug-log run stamp does not bind the current invocation")
    require(scan.get("store") == EXPECTED_STORE, "debug-log scan store context is invalid")
    require(scan.get("flow_id") == FLOW_ID, "debug-log scan flow context is invalid")
    require(scan.get("purpose") == LOG_PURPOSE, "debug-log scan purpose is invalid")

    observations = scan.get("observations")
    matches = scan.get("matches")
    ignored_matches = scan.get("ignored_matches")
    require(isinstance(observations, list) and len(observations) <= 8, "debug-log observations are invalid")
    require(isinstance(matches, list) and len(matches) <= 20, "debug-log matches are invalid")
    require(isinstance(ignored_matches, list) and len(ignored_matches) <= 100, "debug-log ignored matches are invalid")

    # A common producer may emit only this empty authenticated-boundary projection
    # when the origin itself could not be trusted. It can only preserve BLOCKED.
    unobserved_block = (
        status == "blocked"
        and not observations
        and not matches
        and not ignored_matches
        and scan.get("marker_created_at") == ""
        and scan.get("origin_nonce") == ""
        and scan.get("observer_id") == ""
        and scan.get("key_fingerprint") == ""
        and scan.get("origin_binding") == ""
        and scan.get("observer_summary") == {}
        and blocker_code in LOG_BLOCKER_CODES
    )
    if unobserved_block:
        return "blocked"

    require(bool(observations), "completed debug-log scan contains no observations")
    require(valid_log_uuid(scan.get("origin_nonce")), "debug-log origin nonce is invalid")
    require(valid_log_uuid(scan.get("observer_id")), "debug-log observer ID is invalid")
    require(valid_log_fingerprint(scan.get("key_fingerprint")), "debug-log key fingerprint is invalid")
    require(valid_log_hmac(scan.get("origin_binding")), "debug-log origin binding is invalid")
    key_hex = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    require(re.fullmatch(r"[0-9a-f]{64}", key_hex) is not None, "debug-log current context key is invalid")
    key = bytes.fromhex(key_hex)
    require(
        scan["key_fingerprint"] == "sha256:" + hashlib.sha256(key).hexdigest(),
        "debug-log key fingerprint does not bind the current invocation",
    )

    marker_created_at = scan.get("marker_created_at")
    require(isinstance(marker_created_at, str) and marker_created_at, "debug-log marker timestamp is invalid")
    try:
        invocation_time = datetime.strptime(run_stamp.split("-", 1)[0], "%Y%m%dT%H%M%SZ").replace(tzinfo=timezone.utc)
        marker_time = datetime.fromisoformat(marker_created_at.replace("Z", "+00:00"))
    except (AttributeError, ValueError) as error:
        raise EvidenceError("debug-log marker timestamp is invalid") from error
    require(marker_time.tzinfo is not None, "debug-log marker timestamp is invalid")
    require(
        abs((marker_time - invocation_time).total_seconds()) <= 900,
        "debug-log marker does not bind the current invocation",
    )

    observations_by_path: dict[str, dict[str, Any]] = {}
    origin_fields = [
        "woopayments_debug_log_origin.v2",
        run_stamp,
        scan["store"],
        scan["flow_id"],
        scan["purpose"],
        marker_created_at,
        scan["origin_nonce"],
        scan["observer_id"],
        scan["key_fingerprint"],
    ]
    for observation in sorted(observations, key=lambda item: str(item.get("path_id")) if isinstance(item, dict) else ""):
        require(
            isinstance(observation, dict) and set(observation) == LOG_OBSERVATION_FIELDS,
            "debug-log observation shape is invalid",
        )
        path = observation.get("path")
        path_id = observation.get("path_id")
        require(
            isinstance(path, str)
            and bool(path)
            and len(path) <= 255
            and Path(path).name == path
            and path not in {".", ".."}
            and path not in observations_by_path,
            "debug-log observation path is invalid",
        )
        require(valid_log_hmac(path_id), "debug-log observation path ID is invalid")
        integer_fields = {
            "start_line_count", "end_line_count", "start_byte_count", "end_byte_count",
            "marker_owner", "observed_owner", "marker_group", "observed_group",
            "marker_mode", "observed_mode",
        }
        require(all(exact_int(observation.get(field)) for field in integer_fields), "debug-log observation counts are invalid")
        require(observation["start_line_count"] >= 1 and observation["start_byte_count"] >= 1, "debug-log observation counts are invalid")
        require(observation["end_line_count"] >= observation["start_line_count"], "debug-log observation reports truncation")
        require(observation["end_byte_count"] >= observation["start_byte_count"], "debug-log observation reports byte truncation")
        require(0 <= observation["marker_mode"] <= 0o777 and 0 <= observation["observed_mode"] <= 0o777, "debug-log observation mode is invalid")
        fingerprint_fields = {
            "marker_identity_fingerprint", "observed_identity_fingerprint",
            "marker_prefix_fingerprint", "observed_prefix_fingerprint",
            "marker_canary_fingerprint", "observed_canary_fingerprint",
        }
        require(all(valid_log_fingerprint(observation.get(field)) for field in fingerprint_fields), "debug-log observation fingerprints are invalid")
        require(observation["marker_identity_fingerprint"] == observation["observed_identity_fingerprint"], "debug-log identity changed after the marker")
        require(observation["marker_prefix_fingerprint"] == observation["observed_prefix_fingerprint"], "debug-log prefix changed after the marker")
        require(observation["marker_canary_fingerprint"] == observation["observed_canary_fingerprint"], "debug-log canary changed after the marker")
        require(
            all(observation[f"marker_{field}"] == observation[f"observed_{field}"] for field in ("owner", "group", "mode")),
            "debug-log metadata changed after the marker",
        )
        origin_fields.extend(
            [
                path_id,
                path,
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
        observations_by_path[path] = observation

    expected_binding = "hmac-sha256:" + hmac.new(
        key, "\0".join(origin_fields).encode("utf-8"), hashlib.sha256
    ).hexdigest()
    require(hmac.compare_digest(scan["origin_binding"], expected_binding), "debug-log origin binding is invalid")

    def validate_records(records: list[Any], categories: set[str], label: str) -> None:
        for record in records:
            require(isinstance(record, dict) and set(record) == {"path", "line", "category", "fingerprint"}, f"debug-log {label} record shape is invalid")
            path = record.get("path")
            line = record.get("line")
            require(isinstance(path, str) and path in observations_by_path, f"debug-log {label} path is invalid")
            require(exact_int(line) and observations_by_path[path]["start_line_count"] < line <= observations_by_path[path]["end_line_count"], f"debug-log {label} line is invalid")
            require(
                isinstance(record.get("category"), str)
                and record.get("category") in categories,
                f"debug-log {label} category is invalid",
            )
            require(valid_log_fingerprint(record.get("fingerprint")), f"debug-log {label} fingerprint is invalid")

    validate_records(matches, LOG_MATCH_CATEGORIES, "matches")
    validate_records(ignored_matches, {"allowlisted_noise"}, "ignored matches")

    summary = scan.get("observer_summary")
    counts = validate_log_observer_summary(
        summary, scan, key, observations_by_path
    )

    terminal_counts = Counter(record["category"] for record in matches)
    for category in LOG_MATCH_CATEGORIES:
        if status == "blocked" and blocker_code == "log_history_changed":
            require(counts.get(category, 0) >= terminal_counts.get(category, 0), "debug-log observer summary contradicts terminal matches")
        else:
            require(counts.get(category, 0) == terminal_counts.get(category, 0), "debug-log observer summary contradicts terminal matches")
    if status == "blocked" and blocker_code == "log_history_changed":
        require(
            any(counts.get(category, 0) > terminal_counts.get(category, 0) for category in LOG_MATCH_CATEGORIES)
            or counts.get("allowlisted_noise", 0) > len(ignored_matches),
            "debug-log history blocker has no observer-only event",
        )
    else:
        require(counts.get("allowlisted_noise", 0) == len(ignored_matches), "debug-log observer summary contradicts ignored matches")

    require(status != "pass" or (not matches and not blocker_code), "passing debug-log scan is contradictory")
    require(status != "fail" or (bool(matches) and not blocker_code), "failed debug-log scan is contradictory")
    require(status != "blocked" or blocker_code in LOG_BLOCKER_CODES, "blocked debug-log scan has no trusted blocker")
    return str(status)


def validate_early_flow_failure(
    evidence_dir: Path, run_stamp: str
) -> tuple[list[str], str, str, tuple[str, ...]]:
    """Validate a gate exit-1 packet emitted before all three flows could complete."""
    failure = read_object(evidence_dir / "i18n-flow-failure.json")
    require(failure.get("schema") == "woopayments_i18n_flow_failure.v1", "early flow failure schema is invalid")
    require(failure.get("driver_exit") == 1, "early flow failure exit is invalid")
    flow = failure.get("flow")
    require(flow in REQUIRED_FLOWS, "early flow failure name is invalid")
    flow_index = REQUIRED_FLOWS.index(flow)
    raw_names = tuple(f"{name}-flow.json" for name in REQUIRED_FLOWS[: flow_index + 1])
    raw_name = raw_names[-1]
    file_names = (
        *raw_names,
        "i18n-flow-failure.json",
        "i18n-language-snapshot.json",
        "i18n-language-restore.json",
        "i18n-probe-cleanup.json",
        "i18n-catalog-evidence.json",
        "debug-log-scan.json",
    )
    missing = [name for name in file_names if not (evidence_dir / name).is_file()]
    require(not missing, f"required early-failure evidence files are missing: {', '.join(missing)}")
    evidence = {name: read_object(evidence_dir / name) for name in file_names}
    raw = evidence[raw_name]
    require(failure.get("payload") == raw, "early flow failure payload does not match its raw flow output")
    require(raw.get("op") == flow, "early flow failure operation is invalid")
    is_failure = (
        raw.get("success") is False
        or raw.get("result") in {"fail", "failure", "error"}
        or raw.get("status") in {"failed", "error"}
    )
    require(is_failure, "early flow output does not contain a product failure")
    if flow_index >= 1:
        charge_joins = validate_successful_charge(evidence["charge-flow.json"])
        if flow_index >= 2:
            validate_successful_refund(
                evidence["refund-flow.json"], charge_joins["charge_order_id"]
            )
    validate_catalog(evidence["i18n-catalog-evidence.json"], None)
    validate_cleanup(evidence)
    log_status = validate_log_scan(evidence["debug-log-scan.json"], run_stamp)
    return [f"flow driver reported product failure: {flow}"], "fail", log_status, file_names


def validate_result(
    evidence_dir: Path, gate_exit: int, run_stamp: str
) -> tuple[list[str], str, str, tuple[str, ...]]:
    """Validate the complete packet and return product diagnostics plus statuses."""
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(gate_exit)
    require(expected_status is not None, f"unsupported gate exit code: {gate_exit}")
    missing = [name for name in REQUIRED_FILES if not (evidence_dir / name).is_file()]
    require(not missing, f"required evidence files are missing: {', '.join(missing)}")
    evidence = {name: read_object(evidence_dir / name) for name in REQUIRED_FILES}

    result = evidence["i18n-notes-gate.json"]
    require(result.get("schema") == RESULT_SCHEMA, "gate result schema is invalid")
    require(result.get("status") == expected_status, "gate result status contradicts its exit code")
    require(result.get("expected_locale") == "de_DE", "gate did not capture the German fixture")
    require(result.get("required_flows") == list(REQUIRED_FLOWS), "gate required-flow set is invalid")
    require(result.get("english_sentinels") == list(ENGLISH_SENTINELS), "gate English sentinel contract is invalid")
    failures = strict_string_list(result, "failures")
    blockers = strict_string_list(result, "blockers")
    implementation_status = result.get("implementation_status")
    catalog_status = result.get("catalog_status")
    if expected_status == "pass":
        require(implementation_status == "pass" and catalog_status == "pass", "pass evidence has a failed component")
        require(not failures and not blockers, "pass evidence contains diagnostics")
    elif expected_status == "fail":
        require(implementation_status == "fail" and bool(failures), "fail evidence has no implementation failure")
        require(catalog_status in {"pass", "blocked"}, "fail evidence catalog status is invalid")
    else:
        require(implementation_status == "pass", "blocked evidence contains an implementation failure")
        require(catalog_status == "blocked" and bool(blockers), "blocked evidence has no catalog blocker")
        require(not failures, "blocked evidence contains product failures")

    state = evidence["i18n-notes-state.json"]
    catalog = evidence["i18n-catalog-evidence.json"]
    require(result.get("state") == state, "gate result state does not match i18n-notes-state.json")
    require(state.get("schema") == STATE_SCHEMA, "captured state schema is invalid")
    require(state.get("locale") == "de_DE", "captured state locale is invalid")
    require(state.get("translation_source") == "deterministic_gettext_probe", "translation source is not the controlled probe")
    require(state.get("catalog_evidence") == catalog, "captured state catalog does not match i18n-catalog-evidence.json")
    validate_catalog(catalog, str(catalog_status))
    joins = validate_flow_outputs(evidence)

    orders = state.get("orders")
    require(isinstance(orders, list) and len(orders) == len(REQUIRED_FLOWS), "captured orders must contain exactly three flows")
    orders_by_flow: dict[str, dict[str, Any]] = {}
    for order in orders:
        require(isinstance(order, dict), "captured order must be an object")
        flow = order.get("flow")
        require(flow in REQUIRED_FLOWS and flow not in orders_by_flow, "captured order flow set is invalid")
        order_id = positive_int(order, "order_id", f"{flow} captured order")
        notes = order.get("notes")
        require(isinstance(notes, list) and notes, f"{flow} notes must be a non-empty list")
        require(all(isinstance(note, str) and note for note in notes), f"{flow} notes must contain strings")
        orders_by_flow[str(flow)] = {"order_id": order_id, "notes": notes}
    require(set(orders_by_flow) == set(REQUIRED_FLOWS), "captured order flow set is incomplete")
    require(orders_by_flow["charge"]["order_id"] == joins["charge_order_id"], "charge state order ID does not match its flow")
    require(orders_by_flow["refund"]["order_id"] == joins["charge_order_id"], "refund state order ID does not match its flow")
    require(orders_by_flow["dispute"]["order_id"] == joins["dispute_order_id"], "dispute state order ID does not match its flow")

    product_errors: list[str] = []
    merchant_join = {
        "charge": joins["charge_intent_id"],
        "refund": joins["refund_id"],
        "dispute": joins["dispute_charge_id"],
    }
    for flow in REQUIRED_FLOWS:
        notes = orders_by_flow[flow]["notes"]
        if len(notes) != len(set(notes)):
            product_errors.append(f"duplicate captured order note: {flow}")
        merchant_notes = [
            note for note in notes if FLOW_MARKERS[flow] in note and merchant_join[flow] in note
        ]
        if not merchant_notes:
            product_errors.append(f"missing deterministic merchant-note marker for {flow}")
        for note in notes:
            if ENGLISH_MERCHANT_PATTERNS[flow].search(note):
                product_errors.append(f"captured English merchant note: {flow}")
            for sentinel in ENGLISH_SENTINELS:
                if sentinel in note:
                    product_errors.append(f"captured English sentinel for {flow}: {sentinel}")

    validate_cleanup(evidence)
    log_status = validate_log_scan(evidence["debug-log-scan.json"], run_stamp)
    return sorted(set(product_errors)), expected_status, log_status, REQUIRED_FILES


def validate_source_packet(
    evidence_dir: Path, gate_exit: int, run_stamp: str
) -> tuple[list[str], str, str, tuple[str, ...]]:
    """Revalidate one exact normal or early-failure packet from source evidence."""
    if gate_exit == 1 and (evidence_dir / "i18n-flow-failure.json").is_file():
        return validate_early_flow_failure(evidence_dir, run_stamp)
    return validate_result(evidence_dir, gate_exit, run_stamp)


def supervisor_status(
    product_errors: list[str], gate_status: str, log_status: str
) -> str:
    """Return the supervisor verdict implied by validated source semantics."""
    if product_errors or gate_status == "fail" or log_status == "fail":
        return "fail"
    if gate_status == "blocked" or log_status == "blocked":
        return "blocked"
    return "pass"


def manifest_log_context(evidence_dir: Path) -> tuple[str, list[str]]:
    """Return the exact validated marker timestamp and sorted keyed path IDs."""
    packet = read_object(evidence_dir / "debug-log-scan.json")
    scan = packet.get("scan")
    require(isinstance(scan, dict), "debug-log scan context is invalid")
    observations = scan.get("observations")
    require(isinstance(observations, list), "debug-log path context is invalid")
    path_ids = sorted(
        observation.get("path_id", "")
        for observation in observations
        if isinstance(observation, dict)
    )
    require(
        len(path_ids) == len(observations)
        and len(path_ids) == len(set(path_ids))
        and all(valid_log_hmac(path_id) for path_id in path_ids),
        "debug-log path context is invalid",
    )
    marker_created_at = scan.get("marker_created_at")
    require(isinstance(marker_created_at, str), "debug-log marker context is invalid")
    return marker_created_at, path_ids


def validate_manifest(
    evidence_dir: Path, run_stamp: str, gate_exit: int
) -> tuple[list[str], str, str]:
    """Verify an existing manifest without modifying any packet bytes."""
    manifest = read_object(evidence_dir / "manifest.json")
    require(set(manifest) == MANIFEST_FIELDS, "manifest field set is invalid")
    require(manifest.get("schema") == MANIFEST_SCHEMA, "manifest schema is invalid")
    require(manifest.get("store") == EXPECTED_STORE, "manifest store is invalid")
    require(manifest.get("flow_id") == FLOW_ID, "manifest flow is invalid")
    require(manifest.get("purpose") == LOG_PURPOSE, "manifest purpose is invalid")
    require(manifest.get("run_stamp") == run_stamp, "manifest run stamp is invalid")
    require(
        exact_int(manifest.get("gate_exit")) and manifest.get("gate_exit") == gate_exit,
        "manifest gate exit is invalid",
    )

    product_errors, gate_status, log_status, file_names = validate_source_packet(
        evidence_dir, gate_exit, run_stamp
    )
    marker_created_at, path_ids = manifest_log_context(evidence_dir)
    require(
        manifest.get("marker_created_at") == marker_created_at,
        "manifest marker timestamp is invalid",
    )
    require(manifest.get("path_ids") == path_ids, "manifest path IDs are invalid")
    require(manifest.get("gate_status") == gate_status, "manifest gate status is invalid")
    require(manifest.get("log_status") == log_status, "manifest log status is invalid")
    require(
        manifest.get("product_errors") == product_errors,
        "manifest product errors do not match source evidence",
    )
    require(
        manifest.get("status")
        == supervisor_status(product_errors, gate_status, log_status),
        "manifest supervisor status is invalid",
    )

    files = manifest.get("files")
    require(
        isinstance(files, dict) and set(files) == set(file_names),
        "manifest evidence file set is invalid",
    )
    for name in file_names:
        expected_digest = files.get(name)
        require(
            isinstance(expected_digest, str)
            and re.fullmatch(r"sha256:[0-9a-f]{64}", expected_digest) is not None,
            f"manifest digest is invalid: {name}",
        )
        try:
            actual_digest = "sha256:" + hashlib.sha256(
                (evidence_dir / name).read_bytes()
            ).hexdigest()
        except OSError as error:
            raise EvidenceError(f"could not hash {name}: {error}") from error
        require(actual_digest == expected_digest, f"manifest digest does not match: {name}")
    return product_errors, gate_status, log_status


def write_manifest(
    evidence_dir: Path,
    run_stamp: str,
    gate_exit: int,
    gate_status: str,
    log_status: str,
    product_errors: list[str],
    file_names: tuple[str, ...],
) -> Path:
    """Bind the exact validated packet bytes in a deterministic manifest."""
    status = supervisor_status(product_errors, gate_status, log_status)
    marker_created_at, path_ids = manifest_log_context(evidence_dir)
    files = {}
    for name in file_names:
        files[name] = f"sha256:{hashlib.sha256((evidence_dir / name).read_bytes()).hexdigest()}"
    manifest = {
        "schema": MANIFEST_SCHEMA,
        "store": EXPECTED_STORE,
        "flow_id": FLOW_ID,
        "purpose": LOG_PURPOSE,
        "run_stamp": run_stamp,
        "marker_created_at": marker_created_at,
        "path_ids": path_ids,
        "gate_exit": gate_exit,
        "gate_status": gate_status,
        "log_status": log_status,
        "product_errors": product_errors,
        "status": status,
        "files": files,
    }
    path = evidence_dir / "manifest.json"
    temporary_path = evidence_dir / "manifest.json.tmp"
    temporary_path.write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    temporary_path.replace(path)
    return path


def main() -> int:
    """Validate CLI arguments and report a fail-closed evidence verdict."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--evidence-dir", type=Path, required=True)
    parser.add_argument("--gate-exit", type=int, required=True)
    parser.add_argument("--run-stamp", required=True)
    parser.add_argument("--store", required=True)
    parser.add_argument("--flow-id", required=True)
    parser.add_argument("--purpose", required=True)
    parser.add_argument("--verify-manifest", action="store_true")
    args = parser.parse_args()
    if (
        args.store != EXPECTED_STORE
        or args.flow_id != FLOW_ID
        or args.purpose != LOG_PURPOSE
    ):
        print("BLOCKED: MA-10 evidence context is invalid")
        return 3
    manifest_path = args.evidence_dir / "manifest.json"
    temporary_manifest_path = args.evidence_dir / "manifest.json.tmp"
    if args.verify_manifest:
        try:
            product_errors, gate_status, log_status = validate_manifest(
                args.evidence_dir, args.run_stamp, args.gate_exit
            )
        except (EvidenceError, OSError) as error:
            print(f"BLOCKED: MA-10 manifest verification failed: {error}")
            return 3
        if product_errors or gate_status == "fail" or log_status == "fail":
            return 1
        return 0
    try:
        manifest_path.unlink(missing_ok=True)
        temporary_manifest_path.unlink(missing_ok=True)
        product_errors, gate_status, log_status, file_names = validate_source_packet(
            args.evidence_dir, args.gate_exit, args.run_stamp
        )
        write_manifest(
            args.evidence_dir,
            args.run_stamp,
            args.gate_exit,
            gate_status,
            log_status,
            product_errors,
            file_names,
        )
    except (EvidenceError, OSError) as error:
        for stale_path in (manifest_path, temporary_manifest_path):
            try:
                stale_path.unlink(missing_ok=True)
            except OSError:
                pass
        print(f"BLOCKED: MA-10 gate evidence is invalid: {error}")
        return 3
    if product_errors:
        for error in product_errors:
            print(f"FAIL: MA-10 {error}")
        return 1
    if gate_status == "fail" or log_status == "fail":
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
