#!/usr/bin/env python3
"""Validate and bind one archived MA-10 i18n notes evidence packet."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path
from typing import Any


RESULT_SCHEMA = "woopayments_i18n_notes_gate_result.v1"
STATE_SCHEMA = "woopayments_i18n_notes_capture.v1"
CATALOG_SCHEMA = "woopayments_i18n_catalog_evidence.v1"
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


def validate_log_scan(payload: dict[str, Any]) -> str:
    """Validate the archived, current-marker-bounded debug-log scan."""
    require(payload.get("schema") == "woopayments_debug_log_scan.v1", "debug-log scan schema is invalid")
    require(payload.get("store") == "target", "debug-log scan store is invalid")
    scan = payload.get("scan")
    require(isinstance(scan, dict), "debug-log scan payload is invalid")
    status = scan.get("status")
    require(status in {"pass", "fail", "blocked"}, "debug-log scan status is invalid")
    marker = scan.get("marker")
    require(isinstance(marker, dict), "debug-log scan marker is missing")
    require(isinstance(marker.get("created_at"), str) and marker["created_at"], "debug-log marker timestamp is missing")
    marker_paths = marker.get("paths")
    require(isinstance(marker_paths, dict) and marker_paths, "debug-log marker paths are missing")
    require(
        all(isinstance(path, str) and path and isinstance(lines, int) and lines >= 0 for path, lines in marker_paths.items()),
        "debug-log marker path counts are invalid",
    )
    paths = scan.get("paths")
    require(isinstance(paths, list) and all(isinstance(path, str) and path for path in paths), "debug-log scan paths are invalid")
    matches = scan.get("matches", [])
    require(isinstance(matches, list) and all(isinstance(match, str) and match for match in matches), "debug-log matches are invalid")
    require(status != "pass" or not matches, "passing debug-log scan contains matches")
    require(status != "fail" or bool(matches), "failed debug-log scan has no matches")
    require(status != "blocked" or isinstance(scan.get("reason"), str), "blocked debug-log scan has no reason")
    if status in {"pass", "fail"}:
        require(bool(paths), "completed debug-log scan contains no scanned paths")
        require(set(marker_paths) <= set(paths), "completed debug-log scan did not cover every marker path")
    return str(status)


def validate_early_flow_failure(evidence_dir: Path) -> tuple[list[str], str, str, tuple[str, ...]]:
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
    log_status = validate_log_scan(evidence["debug-log-scan.json"])
    return [f"flow driver reported product failure: {flow}"], "fail", log_status, file_names


def validate_result(evidence_dir: Path, gate_exit: int) -> tuple[list[str], str, str, tuple[str, ...]]:
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
    log_status = validate_log_scan(evidence["debug-log-scan.json"])
    return sorted(set(product_errors)), expected_status, log_status, REQUIRED_FILES


def write_manifest(
    evidence_dir: Path,
    gate_exit: int,
    gate_status: str,
    log_status: str,
    product_errors: list[str],
    file_names: tuple[str, ...],
) -> Path:
    """Bind the exact validated packet bytes in a deterministic manifest."""
    if product_errors or gate_status == "fail" or log_status == "fail":
        supervisor_status = "fail"
    elif gate_status == "blocked" or log_status == "blocked":
        supervisor_status = "blocked"
    else:
        supervisor_status = "pass"
    files = {}
    for name in file_names:
        files[name] = f"sha256:{hashlib.sha256((evidence_dir / name).read_bytes()).hexdigest()}"
    manifest = {
        "schema": "woopayments_ma10_evidence_manifest.v1",
        "gate_exit": gate_exit,
        "gate_status": gate_status,
        "log_status": log_status,
        "product_errors": product_errors,
        "status": supervisor_status,
        "files": files,
    }
    path = evidence_dir / "manifest.json"
    path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    return path


def main() -> int:
    """Validate CLI arguments and report a fail-closed evidence verdict."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--evidence-dir", type=Path, required=True)
    parser.add_argument("--gate-exit", type=int, required=True)
    args = parser.parse_args()
    try:
        if args.gate_exit == 1 and (args.evidence_dir / "i18n-flow-failure.json").is_file():
            product_errors, gate_status, log_status, file_names = validate_early_flow_failure(args.evidence_dir)
        else:
            product_errors, gate_status, log_status, file_names = validate_result(args.evidence_dir, args.gate_exit)
        write_manifest(args.evidence_dir, args.gate_exit, gate_status, log_status, product_errors, file_names)
    except EvidenceError as error:
        print(f"BLOCKED: MA-10 gate evidence is invalid: {error}")
        return 3
    if product_errors:
        for error in product_errors:
            print(f"FAIL: MA-10 {error}")
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
