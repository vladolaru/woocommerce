#!/usr/bin/env python3
"""Validate, compare, and seal MD-02 draft-evidence facts."""

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


DESCRIPTION = "MD02 evidence product description"
CONTEXT_KEY_RE = re.compile(r"[0-9a-f]{64}")
HMAC_RE = re.compile(r"hmac-sha256:[0-9a-f]{64}")
RUN_STAMP_RE = re.compile(r"[0-9]{8}T[0-9]{6}Z-[0-9]+")
OWNERS = {"ref": "plugin", "target": "native"}
STATE_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "phase",
    "runtime_owner",
    "identity",
    "lifecycle",
    "evidence",
    "metadata_keys",
    "blockers",
}
IDENTITY_FIELDS = {"order_id", "charge_id", "intent_id", "dispute_id"}
LIFECYCLE_FIELDS = {"status", "due_by", "past_due", "has_evidence", "submission_count"}
EVIDENCE_FIELDS = {
    "product_description",
    "customer_name_present",
    "customer_name_matches_order",
    "customer_name_hmac",
    "file_evidence_hmac",
    "decisive_state_hmac",
}
DETERMINISTIC_ASSERTIONS = {
    "exact_identity",
    "description_was_new",
    "description_persisted",
    "customer_name_preserved",
    "no_files_attached",
    "draft_exists",
    "not_submitted",
    "deadline_unchanged",
    "delayed_state_stable",
}
COMPARISON_ASSERTIONS = {
    "run_binding",
    "runtime_owners",
    "both_persisted",
    "both_unsubmitted",
    "both_deadlines_unchanged",
    "both_delayed_stable",
}
FUNCTIONAL_ASSERTIONS = {
    "authenticated_admin",
    "exact_dispute_row",
    "response_action_discovered",
    "exact_submit_false_post",
    "customer_name_preserved",
    "no_files_attached",
    "save_feedback",
    "description_reloaded",
    "description_editable",
}
UX_ASSERTIONS = {"save_for_later_copy", "customer_name_visible"}
BROWSER_FACT_BOOLEAN_FIELDS = {
    "authenticatedAdmin",
    "exactDisputeRow",
    "responseActionDiscovered",
    "requestSeen",
    "requestPathMatches",
    "submitFalse",
    "descriptionMatches",
    "payloadCustomerNameMatches",
    "noFilesAttached",
    "responseOk",
    "saveFeedback",
    "reloadedDescriptionMatches",
    "descriptionEditable",
    "customerNameVisible",
}
BROWSER_FACT_FIELDS = BROWSER_FACT_BOOLEAN_FIELDS | {"requestMethod", "saveButtonLabel"}
BROWSER_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "runtime_owner",
    "phase",
    "identity",
    "facts",
    "functional_assertions",
    "ux_assertions",
    "request",
    "response",
    "failed_responses",
    "diagnostics",
    "console_errors",
    "page_errors",
    "screenshots",
    "errors",
    "blockers",
}
BROWSER_PHASES = {"initialized", "list", "details", "armed", "request_seen", "response_seen", "reloaded", "unavailable"}
FLOW = "MD-02-save-evidence"
STATUS_EXIT = {"pass": 0, "fail": 1, "blocked": 3}
DIGEST_RE = re.compile(r"sha256:[0-9a-f]{64}")
STORE_PACKET_DOMAIN = b"woopayments-md02-store-packet-context-v1\0"
COMPARISON_DOMAIN = b"woopayments-md02-comparison-context-v1\0"
LOG_DOMAIN = b"woopayments-md02-log-context-v1\0"
EXECUTION_DOMAIN = b"woopayments-md02-execution-context-v1\0"
MANIFEST_DOMAIN = b"woopayments-md02-manifest-context-v1\0"


class EvidenceError(ValueError):
    """Evidence is malformed, unsafe, or not bound to this run."""


def canonical(payload: Any) -> bytes:
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def context_key() -> bytes:
    value = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if CONTEXT_KEY_RE.fullmatch(value) is None:
        raise EvidenceError("runner evidence context key is unavailable")
    return bytes.fromhex(value)


def seal(payload: dict[str, Any], domain: bytes) -> dict[str, Any]:
    sealed = dict(payload)
    sealed.pop("payload_sha256", None)
    sealed.pop("context_hmac", None)
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(context_key(), domain + canonical(sealed), hashlib.sha256).hexdigest()
    sealed["payload_sha256"] = payload_digest(sealed)
    return sealed


def validate_seal(payload: dict[str, Any], domain: bytes) -> None:
    if payload.get("payload_sha256") != payload_digest(payload):
        raise EvidenceError("payload digest does not match")
    material = dict(payload)
    material.pop("payload_sha256", None)
    actual_hmac = material.pop("context_hmac", None)
    expected_hmac = "hmac-sha256:" + hmac.new(context_key(), domain + canonical(material), hashlib.sha256).hexdigest()
    if actual_hmac != expected_hmac:
        raise EvidenceError("context HMAC does not match")


def _is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def validate_state(payload: dict[str, Any], *, store: str, run_stamp: str, phase: str) -> None:
    if set(payload) != STATE_FIELDS or payload.get("schema") != "woopayments_md02_state.v1":
        raise EvidenceError("state field set or schema is invalid")
    if store not in OWNERS or payload.get("store") != store or payload.get("runtime_owner") != OWNERS[store]:
        raise EvidenceError("state store or runtime owner is invalid")
    if RUN_STAMP_RE.fullmatch(run_stamp) is None or payload.get("run_stamp") != run_stamp or payload.get("phase") != phase:
        raise EvidenceError("state run or phase binding is invalid")
    identity = payload.get("identity")
    if (
        not isinstance(identity, dict)
        or set(identity) != IDENTITY_FIELDS
        or not _is_int(identity.get("order_id"))
        or identity["order_id"] <= 0
        or re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(identity.get("charge_id", ""))) is None
        or re.fullmatch(r"pi_[A-Za-z0-9_]+", str(identity.get("intent_id", ""))) is None
        or re.fullmatch(r"[A-Za-z]{2,4}_[A-Za-z0-9_]+", str(identity.get("dispute_id", ""))) is None
    ):
        raise EvidenceError("state exact identity is invalid")
    lifecycle = payload.get("lifecycle")
    if (
        not isinstance(lifecycle, dict)
        or set(lifecycle) != LIFECYCLE_FIELDS
        or not isinstance(lifecycle.get("status"), str)
        or not _is_int(lifecycle.get("due_by"))
        or lifecycle["due_by"] <= 0
        or not isinstance(lifecycle.get("past_due"), bool)
        or not isinstance(lifecycle.get("has_evidence"), bool)
        or not _is_int(lifecycle.get("submission_count"))
        or lifecycle["submission_count"] < 0
    ):
        raise EvidenceError("state lifecycle is invalid")
    evidence = payload.get("evidence")
    if (
        not isinstance(evidence, dict)
        or set(evidence) != EVIDENCE_FIELDS
        or not isinstance(evidence.get("product_description"), str)
        or not isinstance(evidence.get("customer_name_present"), bool)
        or not isinstance(evidence.get("customer_name_matches_order"), bool)
        or HMAC_RE.fullmatch(str(evidence.get("customer_name_hmac", ""))) is None
        or HMAC_RE.fullmatch(str(evidence.get("file_evidence_hmac", ""))) is None
        or HMAC_RE.fullmatch(str(evidence.get("decisive_state_hmac", ""))) is None
    ):
        raise EvidenceError("state evidence projection is invalid")
    if not isinstance(payload.get("metadata_keys"), list) or any(not isinstance(item, str) for item in payload["metadata_keys"]):
        raise EvidenceError("state metadata keys are invalid")
    if not isinstance(payload.get("blockers"), list) or any(not isinstance(item, str) for item in payload["blockers"]):
        raise EvidenceError("state blockers are invalid")


def derive_deterministic_assertions(
    pre: dict[str, Any],
    post: dict[str, Any],
    delayed: dict[str, Any],
    expected_description: str = DESCRIPTION,
) -> dict[str, bool]:
    identities = [state.get("identity") for state in (pre, post, delayed)]
    stores = [state.get("store") for state in (pre, post, delayed)]
    run_stamps = [state.get("run_stamp") for state in (pre, post, delayed)]
    owners = [state.get("runtime_owner") for state in (pre, post, delayed)]
    evidence = [state.get("evidence", {}) for state in (pre, post, delayed)]
    lifecycle = [state.get("lifecycle", {}) for state in (pre, post, delayed)]
    customer_hmacs = [item.get("customer_name_hmac") for item in evidence]
    file_hmacs = [item.get("file_evidence_hmac") for item in evidence]
    deadlines = [item.get("due_by") for item in lifecycle]
    expected_owner = OWNERS.get(str(stores[0])) if stores else None
    assertions = {
        "exact_identity": bool(
            identities[0]
            and identities.count(identities[0]) == 3
            and stores.count(stores[0]) == 3
            and run_stamps.count(run_stamps[0]) == 3
            and owners.count(owners[0]) == 3
            and owners[0] == expected_owner
        ),
        "description_was_new": evidence[0].get("product_description") != expected_description,
        "description_persisted": evidence[1].get("product_description") == expected_description
        and evidence[2].get("product_description") == expected_description,
        "customer_name_preserved": all(
            item.get("customer_name_present") is True and item.get("customer_name_matches_order") is True for item in evidence
        )
        and bool(customer_hmacs[0])
        and customer_hmacs.count(customer_hmacs[0]) == 3,
        "no_files_attached": bool(file_hmacs[0]) and file_hmacs.count(file_hmacs[0]) == 3,
        "draft_exists": lifecycle[1].get("has_evidence") is True and lifecycle[2].get("has_evidence") is True,
        "not_submitted": all(
            item.get("status") == "needs_response"
            and item.get("past_due") is False
            and item.get("submission_count") == 0
            for item in lifecycle
        ),
        "deadline_unchanged": _is_int(deadlines[0]) and deadlines[0] > 0 and deadlines.count(deadlines[0]) == 3,
        "delayed_state_stable": bool(evidence[1].get("decisive_state_hmac"))
        and evidence[1].get("decisive_state_hmac") == evidence[2].get("decisive_state_hmac"),
    }
    return assertions


def derive_browser_verdict(browser: dict[str, Any]) -> tuple[str, list[str]]:
    blockers = browser.get("blockers")
    if not isinstance(blockers, list):
        return "blocked", ["browser_blockers_malformed"]
    functional = browser.get("functional_assertions")
    ux = browser.get("ux_assertions")
    if (
        not isinstance(functional, dict)
        or set(functional) != FUNCTIONAL_ASSERTIONS
        or not isinstance(ux, dict)
        or set(ux) != UX_ASSERTIONS
    ):
        return "blocked", ["browser_assertions_malformed"]
    failed_functional = sorted(name for name, passed in functional.items() if passed is not True)
    errors = browser.get("errors")
    failed_responses = browser.get("failed_responses")
    console_errors = browser.get("console_errors")
    page_errors = browser.get("page_errors")
    if not all(isinstance(value, list) for value in (errors, failed_responses, console_errors, page_errors)):
        return "blocked", ["browser_diagnostics_malformed"]
    authoritative_failures: list[str] = []
    if failed_responses:
        authoritative_failures.append("failed_responses")
    if console_errors:
        authoritative_failures.append("console_errors")
    if page_errors:
        authoritative_failures.append("page_errors")
    if authoritative_failures:
        return "fail_functional", authoritative_failures
    if blockers:
        return "blocked", [str(item) for item in blockers]
    failed_functional.extend(str(item) for item in errors)
    if failed_functional:
        return "fail_functional", list(dict.fromkeys(failed_functional))
    failed_ux = sorted(name for name, passed in ux.items() if passed is not True)
    if failed_ux:
        return "fail_ux", failed_ux
    return "pass", []


def derive_browser_assertions(facts: dict[str, Any]) -> tuple[dict[str, bool], dict[str, bool]]:
    functional = {
        "authenticated_admin": facts.get("authenticatedAdmin") is True,
        "exact_dispute_row": facts.get("exactDisputeRow") is True,
        "response_action_discovered": facts.get("responseActionDiscovered") is True,
        "exact_submit_false_post": facts.get("requestSeen") is True
        and facts.get("requestMethod") == "POST"
        and facts.get("requestPathMatches") is True
        and facts.get("submitFalse") is True
        and facts.get("descriptionMatches") is True,
        "customer_name_preserved": facts.get("payloadCustomerNameMatches") is True,
        "no_files_attached": facts.get("noFilesAttached") is True,
        "save_feedback": facts.get("responseOk") is True and facts.get("saveFeedback") is True,
        "description_reloaded": facts.get("reloadedDescriptionMatches") is True,
        "description_editable": facts.get("descriptionEditable") is True,
    }
    ux = {
        "save_for_later_copy": facts.get("saveButtonLabel") == "Save for later",
        "customer_name_visible": facts.get("customerNameVisible") is True,
    }
    return functional, ux


def validate_browser(payload: dict[str, Any], *, store: str, run_stamp: str, identity: dict[str, Any]) -> None:
    if set(payload) != BROWSER_FIELDS or payload.get("schema") != "woopayments_md02_browser.v1":
        raise EvidenceError("browser field set or schema is invalid")
    expected_identity = {
        "order_id": identity["order_id"],
        "charge_id": identity["charge_id"],
        "dispute_id": identity["dispute_id"],
    }
    if (
        payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("runtime_owner") != OWNERS[store]
        or payload.get("phase") not in BROWSER_PHASES
        or payload.get("identity") != expected_identity
    ):
        raise EvidenceError("browser run or exact identity binding is invalid")
    facts = payload.get("facts")
    if (
        not isinstance(facts, dict)
        or set(facts) != BROWSER_FACT_FIELDS
        or any(not isinstance(facts.get(field), bool) for field in BROWSER_FACT_BOOLEAN_FIELDS)
        or not isinstance(facts.get("requestMethod"), str)
        or not isinstance(facts.get("saveButtonLabel"), str)
    ):
        raise EvidenceError("browser fact field set is invalid")
    functional = payload.get("functional_assertions")
    ux = payload.get("ux_assertions")
    if (
        not isinstance(functional, dict)
        or set(functional) != FUNCTIONAL_ASSERTIONS
        or any(not isinstance(value, bool) for value in functional.values())
        or not isinstance(ux, dict)
        or set(ux) != UX_ASSERTIONS
        or any(not isinstance(value, bool) for value in ux.values())
    ):
        raise EvidenceError("browser assertion field set is invalid")
    expected_functional, expected_ux = derive_browser_assertions(facts)
    if functional != expected_functional or ux != expected_ux:
        raise EvidenceError("browser assertions were not recomputed from exact facts")
    request = payload.get("request")
    response = payload.get("response")
    if (
        not isinstance(request, dict)
        or set(request)
        != {"method", "path", "submit_false", "description_matches", "customer_name_matches", "files_selected"}
        or not isinstance(request.get("method"), str)
        or not isinstance(request.get("path"), str)
        or any(
            not isinstance(request.get(field), bool)
            for field in ("submit_false", "description_matches", "customer_name_matches")
        )
        or not _is_int(request.get("files_selected"))
        or request["files_selected"] < -1
        or not isinstance(response, dict)
        or set(response) != {"status", "ok"}
        or not _is_int(response.get("status"))
        or response["status"] < 0
        or not isinstance(response.get("ok"), bool)
    ):
        raise EvidenceError("browser request or response evidence is malformed")
    exact_request = (
        request["method"] == "POST"
        and request["path"] == f"/wp-json/wc/v3/payments/disputes/{identity['dispute_id']}"
        and request["submit_false"] is True
        and request["description_matches"] is True
    )
    if (
        request["method"] != facts["requestMethod"]
        or (request["path"] == f"/wp-json/wc/v3/payments/disputes/{identity['dispute_id']}")
        != facts["requestPathMatches"]
        or request["submit_false"] != facts["submitFalse"]
        or request["description_matches"] != facts["descriptionMatches"]
        or request["customer_name_matches"] != facts["payloadCustomerNameMatches"]
        or (request["files_selected"] == 0) != facts["noFilesAttached"]
        or response["ok"] != facts["responseOk"]
        or (200 <= response["status"] < 300) != facts["responseOk"]
    ):
        raise EvidenceError("browser request/response evidence contradicts exact facts")
    if functional["exact_submit_false_post"] != exact_request:
        raise EvidenceError("browser exact request assertion is contradictory")
    if functional["customer_name_preserved"] != request["customer_name_matches"]:
        raise EvidenceError("browser customer-name assertion is contradictory")
    if not payload.get("blockers") and functional["no_files_attached"] != (request["files_selected"] == 0):
        raise EvidenceError("browser file assertion is contradictory")
    if functional["save_feedback"] is True and response["ok"] is not True:
        raise EvidenceError("browser save-feedback assertion is contradictory")
    for field in ("failed_responses", "diagnostics", "console_errors", "page_errors"):
        if not isinstance(payload.get(field), list) or any(not isinstance(item, dict) for item in payload[field]):
            raise EvidenceError(f"browser {field} is malformed")
    for field in ("errors", "blockers"):
        if not isinstance(payload.get(field), list) or any(not isinstance(item, str) or not item for item in payload[field]):
            raise EvidenceError(f"browser {field} is malformed")
    screenshots = payload.get("screenshots")
    if not isinstance(screenshots, dict) or any(
        Path(str(name)).name != name or DIGEST_RE.fullmatch(str(digest)) is None for name, digest in screenshots.items()
    ):
        raise EvidenceError("browser screenshot binding is malformed")
    verdict, _ = derive_browser_verdict(payload)
    expected_screenshots = {
        f"{store}-md02-form.png",
        f"{store}-md02-saved.png",
        f"{store}-md02-reloaded.png",
    }
    if verdict in {"pass", "fail_ux"} and (
        payload["phase"] != "reloaded" or set(screenshots) != expected_screenshots
    ):
        raise EvidenceError("completed browser evidence is incomplete")
    if verdict == "pass" and not (all(functional.values()) and all(ux.values())):
        raise EvidenceError("passing browser verdict is contradictory")


def compare_store_packets(reference: dict[str, Any], target: dict[str, Any], run_stamp: str) -> dict[str, Any]:
    ref_assertions = reference.get("deterministic_assertions", {})
    target_assertions = target.get("deterministic_assertions", {})
    assertions = {
        "run_binding": reference.get("run_stamp") == run_stamp == target.get("run_stamp"),
        "runtime_owners": reference.get("runtime_owner") == "plugin" and target.get("runtime_owner") == "native",
        "both_persisted": ref_assertions.get("description_persisted") is True
        and target_assertions.get("description_persisted") is True,
        "both_unsubmitted": ref_assertions.get("not_submitted") is True and target_assertions.get("not_submitted") is True,
        "both_deadlines_unchanged": ref_assertions.get("deadline_unchanged") is True
        and target_assertions.get("deadline_unchanged") is True,
        "both_delayed_stable": ref_assertions.get("delayed_state_stable") is True
        and target_assertions.get("delayed_state_stable") is True,
    }
    return {
        "schema": "woopayments_md02_comparison.v1",
        "run_stamp": run_stamp,
        "status": "pass" if all(assertions.values()) else "fail",
        "assertions": assertions,
    }


def _string_list(value: Any) -> bool:
    return isinstance(value, list) and all(isinstance(item, str) and item for item in value)


def validate_store_packet(payload: dict[str, Any], *, store: str, run_stamp: str) -> None:
    fields = {
        "schema",
        "status",
        "store",
        "run_stamp",
        "runtime_owner",
        "source_manifest",
        "context_binding",
        "pre_state",
        "post_state",
        "delayed_state",
        "mutation_boundary",
        "deterministic_assertions",
        "deterministic_status",
        "browser",
        "browser_status",
        "diagnostics",
        "cleanup_failures",
        "context_hmac",
        "payload_sha256",
    }
    if set(payload) != fields or payload.get("schema") != "woopayments_md02_store_packet.v1":
        raise EvidenceError("store packet field set or schema is invalid")
    validate_seal(payload, STORE_PACKET_DOMAIN)
    if (
        store not in OWNERS
        or payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("runtime_owner") != OWNERS[store]
        or payload.get("status") not in STATUS_EXIT
        or payload.get("mutation_boundary")
        not in {"trusted_mutation", "trusted_mutation_mismatch", "unchanged_safe_failure", "ambiguous_mutation"}
        or not isinstance(payload.get("source_manifest"), dict)
        or set(payload["source_manifest"]) != {"path", "sha256", "historical_context_hmac"}
        or not str(payload["source_manifest"].get("path", ""))
        or Path(str(payload["source_manifest"].get("path", ""))).is_absolute()
        or ".." in Path(str(payload["source_manifest"].get("path", ""))).parts
        or DIGEST_RE.fullmatch(str(payload["source_manifest"].get("sha256", ""))) is None
        or payload["source_manifest"].get("historical_context_hmac")
        != "unverifiable_without_original_run_key"
        or not isinstance(payload.get("context_binding"), dict)
        or not str(payload["context_binding"].get("aggregate_run_id", ""))
        or DIGEST_RE.fullmatch(str(payload["context_binding"].get("context_sha256", ""))) is None
        or not isinstance(payload.get("diagnostics"), list)
        or not isinstance(payload.get("cleanup_failures"), list)
    ):
        raise EvidenceError("store packet run or provenance binding is invalid")
    for phase in ("pre", "post", "delayed"):
        validate_state(payload[f"{phase}_state"], store=store, run_stamp=run_stamp, phase=phase)
    validate_browser(
        payload.get("browser", {}),
        store=store,
        run_stamp=run_stamp,
        identity=payload["pre_state"]["identity"],
    )
    expected_assertions = derive_deterministic_assertions(
        payload["pre_state"], payload["post_state"], payload["delayed_state"], DESCRIPTION
    )
    if payload.get("deterministic_assertions") != expected_assertions:
        raise EvidenceError("store packet deterministic assertions were not recomputed")
    trusted_boundary = payload["mutation_boundary"] in {"trusted_mutation", "trusted_mutation_mismatch"}
    expected_deterministic = (
        "pass" if trusted_boundary and all(expected_assertions.values()) else "fail" if trusted_boundary else "blocked"
    )
    if payload.get("deterministic_status") != expected_deterministic:
        raise EvidenceError("store packet deterministic verdict is contradictory")
    browser_status, _ = derive_browser_verdict(payload.get("browser", {}))
    if payload.get("browser_status") != browser_status:
        raise EvidenceError("store packet browser verdict is contradictory")
    boundary_blocked = payload["mutation_boundary"] in {"unchanged_safe_failure", "ambiguous_mutation"}
    expected_status = (
        "blocked"
        if boundary_blocked or browser_status == "blocked" or payload["cleanup_failures"]
        else "fail"
        if expected_deterministic == "fail"
        or payload["mutation_boundary"] == "trusted_mutation_mismatch"
        or browser_status in {"fail_functional", "fail_ux"}
        else "pass"
    )
    if payload.get("status") != expected_status:
        raise EvidenceError("store packet overall verdict is contradictory")


def validate_comparison(payload: dict[str, Any], run_stamp: str) -> None:
    fields = {"schema", "run_stamp", "status", "assertions", "context_hmac", "payload_sha256"}
    if set(payload) != fields or payload.get("schema") != "woopayments_md02_comparison.v1":
        raise EvidenceError("comparison field set or schema is invalid")
    validate_seal(payload, COMPARISON_DOMAIN)
    assertions = payload.get("assertions")
    if (
        payload.get("run_stamp") != run_stamp
        or payload.get("status") not in {"pass", "fail"}
        or not isinstance(assertions, dict)
        or set(assertions) != COMPARISON_ASSERTIONS
        or any(not isinstance(value, bool) for value in assertions.values())
        or payload["status"] != ("pass" if all(assertions.values()) else "fail")
    ):
        raise EvidenceError("comparison binding or verdict is invalid")


def validate_log_scan(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    fields = {
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
        "context_hmac",
        "payload_sha256",
    }
    if set(payload) != fields or payload.get("schema") != "woopayments_md02_log_scan.v1":
        raise EvidenceError("log evidence field set or schema is invalid")
    validate_seal(payload, LOG_DOMAIN)
    status = payload.get("status")
    if (
        payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("flow_id") != FLOW
        or payload.get("purpose") != "clean-debug-log"
        or status not in STATUS_EXIT
        or payload.get("exit_code") != STATUS_EXIT[status]
        or not isinstance(payload.get("scan_observed"), bool)
        or not _is_int(payload.get("match_count"))
        or payload["match_count"] < 0
        or not isinstance(payload.get("blocker_code"), str)
        or DIGEST_RE.fullmatch(str(payload.get("source_payload_sha256", ""))) is None
    ):
        raise EvidenceError("log evidence binding is invalid")
    if status == "pass" and (not payload["scan_observed"] or payload["match_count"] != 0 or payload["blocker_code"]):
        raise EvidenceError("passing log evidence is contradictory")
    if status == "fail" and (not payload["scan_observed"] or payload["match_count"] <= 0 or payload["blocker_code"]):
        raise EvidenceError("failing log evidence is contradictory")
    if status == "blocked" and (payload["scan_observed"] or payload["match_count"] != 0 or not payload["blocker_code"]):
        raise EvidenceError("blocked log evidence is contradictory")


def build_execution(
    packet: dict[str, Any],
    log_scan: dict[str, Any],
    *,
    status: str,
    exit_code: int,
    verdict_sources: list[str],
    comparison: dict[str, Any] | None = None,
) -> dict[str, Any]:
    store = str(packet.get("store", ""))
    run_stamp = str(packet.get("run_stamp", ""))
    validate_store_packet(packet, store=store, run_stamp=run_stamp)
    validate_log_scan(log_scan, store, run_stamp)
    observed = [STATUS_EXIT[packet["status"]], log_scan["exit_code"]]
    payload: dict[str, Any] = {
        "schema": "woopayments_md02_execution.v1",
        "status": status,
        "store": store,
        "run_stamp": run_stamp,
        "exit_code": exit_code,
        "verdict_sources": sorted(set(verdict_sources)),
        "store_packet_exit_code": STATUS_EXIT[packet["status"]],
        "store_packet_payload_sha256": packet["payload_sha256"],
        "log_assertion_exit_code": log_scan["exit_code"],
        "log_payload_sha256": log_scan["payload_sha256"],
    }
    if store == "target":
        if comparison is None:
            raise EvidenceError("target execution requires comparison evidence")
        validate_comparison(comparison, run_stamp)
        comparison_exit = 0 if comparison["status"] == "pass" else 1
        observed.append(comparison_exit)
        payload["comparison_exit_code"] = comparison_exit
        payload["comparison_payload_sha256"] = comparison["payload_sha256"]
    expected_exit = 3 if 3 in observed else 1 if any(code != 0 for code in observed) else 0
    if status not in STATUS_EXIT or exit_code != STATUS_EXIT[status] or exit_code != expected_exit or not _string_list(verdict_sources) and verdict_sources:
        raise EvidenceError("execution verdict contradicts its sources")
    return payload


def validate_execution(payload: dict[str, Any], store: str, run_stamp: str) -> None:
    fields = {
        "schema",
        "status",
        "store",
        "run_stamp",
        "exit_code",
        "verdict_sources",
        "store_packet_exit_code",
        "store_packet_payload_sha256",
        "log_assertion_exit_code",
        "log_payload_sha256",
        "context_hmac",
        "payload_sha256",
    }
    if store == "target":
        fields.update({"comparison_exit_code", "comparison_payload_sha256"})
    if set(payload) != fields or payload.get("schema") != "woopayments_md02_execution.v1":
        raise EvidenceError("execution field set or schema is invalid")
    validate_seal(payload, EXECUTION_DOMAIN)
    observed = [payload.get("store_packet_exit_code"), payload.get("log_assertion_exit_code")]
    if store == "target":
        observed.append(payload.get("comparison_exit_code"))
    expected_exit = 3 if 3 in observed else 1 if any(code != 0 for code in observed) else 0
    if (
        payload.get("store") != store
        or payload.get("run_stamp") != run_stamp
        or payload.get("status") not in STATUS_EXIT
        or payload.get("exit_code") != STATUS_EXIT[payload["status"]]
        or payload.get("exit_code") != expected_exit
        or not isinstance(payload.get("verdict_sources"), list)
        or any(not isinstance(item, str) or not item for item in payload["verdict_sources"])
        or DIGEST_RE.fullmatch(str(payload.get("store_packet_payload_sha256", ""))) is None
        or DIGEST_RE.fullmatch(str(payload.get("log_payload_sha256", ""))) is None
        or (store == "target" and DIGEST_RE.fullmatch(str(payload.get("comparison_payload_sha256", ""))) is None)
    ):
        raise EvidenceError("execution binding or verdict is invalid")


def validate_packet_screenshots(packet: dict[str, Any], artifact_dir: Path) -> None:
    screenshots = packet.get("browser", {}).get("screenshots", {})
    browser_status = packet.get("browser_status")
    expected = {
        f"{packet['store']}-md02-form.png",
        f"{packet['store']}-md02-saved.png",
        f"{packet['store']}-md02-reloaded.png",
    }
    if not isinstance(screenshots, dict) or (
        not set(screenshots).issubset(expected)
        if browser_status in {"blocked", "fail_functional"}
        else set(screenshots) != expected
    ):
        raise EvidenceError("store packet screenshot set is incomplete")
    for name, digest in screenshots.items():
        path = artifact_dir / name
        if (
            path.parent != artifact_dir
            or _has_symlink_component(path)
            or not path.is_file()
            or path.stat().st_size < 16
            or path.stat().st_size > 10 * 1024 * 1024
            or path.read_bytes()[:8] != b"\x89PNG\r\n\x1a\n"
            or "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest() != digest
        ):
            raise EvidenceError(f"store packet screenshot digest is invalid: {name}")


def build_manifest(
    *,
    store: str,
    status: str,
    exit_code: int,
    run_stamp: str,
    run_scope: str,
    verdict_sources: list[str],
    paths: list[Path],
) -> dict[str, Any]:
    if (
        store not in OWNERS
        or status not in STATUS_EXIT
        or exit_code != STATUS_EXIT[status]
        or RUN_STAMP_RE.fullmatch(run_stamp) is None
        or run_scope not in {"partial", "full"}
        or (not _string_list(verdict_sources) and verdict_sources)
    ):
        raise EvidenceError("manifest verdict or run binding is invalid")
    files: dict[str, Any] = {}
    parent: Path | None = None
    artifacts: dict[str, dict[str, Any]] = {}
    for raw_path in paths:
        path = Path(os.path.abspath(raw_path))
        artifact = load_json(path)
        if parent is None:
            parent = path.parent
        elif path.parent != parent:
            raise EvidenceError("manifest artifacts do not share one archive directory")
        if path.name in files:
            raise EvidenceError("manifest artifact is duplicated")
        if artifact.get("payload_sha256") != payload_digest(artifact):
            raise EvidenceError("manifest artifact payload digest does not match")
        files[path.name] = {
            "schema": artifact.get("schema", ""),
            "sha256": "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest(),
            "payload_sha256": artifact["payload_sha256"],
        }
        artifacts[path.name] = artifact
    packet_name = f"{store}-md02-store-packet.json"
    log_name = f"{store}-log-scan.json"
    execution_name = f"{store}-execution.json"
    required = {packet_name, log_name, execution_name}
    if store == "target":
        required.add("comparison.json")
    if not required.issubset(files):
        raise EvidenceError("manifest artifact set is incomplete")
    validate_store_packet(artifacts[packet_name], store=store, run_stamp=run_stamp)
    validate_packet_screenshots(artifacts[packet_name], parent or Path("."))
    validate_log_scan(artifacts[log_name], store, run_stamp)
    validate_execution(artifacts[execution_name], store, run_stamp)
    execution = artifacts[execution_name]
    if execution["status"] != status or execution["exit_code"] != exit_code:
        raise EvidenceError("manifest verdict contradicts its execution artifact")
    if (
        execution["store_packet_payload_sha256"] != artifacts[packet_name]["payload_sha256"]
        or execution["log_payload_sha256"] != artifacts[log_name]["payload_sha256"]
    ):
        raise EvidenceError("manifest execution artifact bindings are incomplete")
    if store == "target":
        validate_comparison(artifacts["comparison.json"], run_stamp)
        if execution["comparison_payload_sha256"] != artifacts["comparison.json"]["payload_sha256"]:
            raise EvidenceError("manifest comparison binding is incomplete")
    if set(files) != required:
        raise EvidenceError("manifest contains an unexpected artifact")
    return {
        "schema": "woopayments_md02_manifest.v1",
        "flow": FLOW,
        "run_stamp": run_stamp,
        "run_scope": run_scope,
        "store": store,
        "status": status,
        "exit_code": exit_code,
        "verdict_sources": sorted(set(verdict_sources)),
        "files": files,
    }


def validate_manifest(
    path: Path,
    *,
    store: str,
    status: str,
    exit_code: int,
    run_stamp: str,
    run_scope: str,
) -> None:
    payload = load_json(path)
    fields = {
        "schema",
        "flow",
        "run_stamp",
        "run_scope",
        "store",
        "status",
        "exit_code",
        "verdict_sources",
        "files",
        "context_hmac",
        "payload_sha256",
    }
    if set(payload) != fields or payload.get("schema") != "woopayments_md02_manifest.v1" or payload.get("flow") != FLOW:
        raise EvidenceError("manifest field set or schema is invalid")
    validate_seal(payload, MANIFEST_DOMAIN)
    if (
        payload.get("store") != store
        or payload.get("status") != status
        or payload.get("exit_code") != exit_code
        or payload.get("run_stamp") != run_stamp
        or payload.get("run_scope") != run_scope
        or not isinstance(payload.get("files"), dict)
    ):
        raise EvidenceError("manifest runner binding is invalid")
    paths: list[Path] = []
    for name, binding in payload["files"].items():
        if Path(name).name != name or not isinstance(binding, dict) or set(binding) != {"schema", "sha256", "payload_sha256"}:
            raise EvidenceError("manifest artifact binding is malformed")
        artifact_path = path.parent / name
        artifact = load_json(artifact_path)
        if (
            binding["sha256"] != "sha256:" + hashlib.sha256(artifact_path.read_bytes()).hexdigest()
            or binding["schema"] != artifact.get("schema")
            or binding["payload_sha256"] != artifact.get("payload_sha256")
        ):
            raise EvidenceError("manifest artifact digest does not match")
        paths.append(artifact_path)
    rebuilt = build_manifest(
        store=store,
        status=status,
        exit_code=exit_code,
        run_stamp=run_stamp,
        run_scope=run_scope,
        verdict_sources=payload.get("verdict_sources", []),
        paths=paths,
    )
    if {key: payload[key] for key in rebuilt} != rebuilt:
        raise EvidenceError("manifest semantics do not match its artifacts")


def _has_symlink_component(path: Path) -> bool:
    lexical = path
    if sys.platform == "darwin" and len(path.parts) > 1 and path.parts[1] == "var":
        lexical = Path("/private") / Path(*path.parts[1:])
    return any(component.is_symlink() for component in (lexical, *lexical.parents))


def load_json(path: Path, *, maximum: int = 2 * 1024 * 1024) -> dict[str, Any]:
    if not path.is_absolute():
        path = Path(os.path.abspath(path))
    if ".." in path.parts or _has_symlink_component(path) or not path.is_file():
        raise EvidenceError("JSON path is missing or unsafe")
    stat = path.stat()
    if stat.st_size <= 0 or stat.st_size > maximum:
        raise EvidenceError("JSON artifact size is invalid")
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise EvidenceError("JSON artifact is unreadable") from exc
    if not isinstance(payload, dict):
        raise EvidenceError("JSON artifact is not an object")
    return payload


def emit(payload: dict[str, Any], exit_code: int = 0) -> int:
    if "payload_sha256" not in payload:
        payload["payload_sha256"] = payload_digest(payload)
    print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
    return exit_code


def extract_json_object(args: argparse.Namespace) -> int:
    raw = sys.stdin.read(2 * 1024 * 1024 + 1)
    if len(raw.encode("utf-8")) > 2 * 1024 * 1024:
        raise EvidenceError("input exceeds the bounded evidence limit")
    decoder = json.JSONDecoder()
    candidates: list[dict[str, Any]] = []
    for index, character in enumerate(raw):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(raw[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(candidate, dict) and candidate.get(args.discriminator) == args.expected:
            candidates.append(candidate)
    if not candidates:
        raise EvidenceError("input contains no exact JSON evidence envelope")
    print(json.dumps(max(candidates, key=lambda item: len(canonical(item))), sort_keys=True, separators=(",", ":")))
    return 0


def normalize_log_scan_payload(raw: dict[str, Any], store: str, run_stamp: str, expected_exit_code: int) -> dict[str, Any]:
    source_digest = "sha256:" + hashlib.sha256(canonical(raw)).hexdigest()
    if (
        set(raw) != {"schema", "store", "scan"}
        or raw.get("schema") != "woopayments_debug_log_scan.v6"
        or raw.get("store") != store
        or not isinstance(raw.get("scan"), dict)
    ):
        raise EvidenceError("common log scan envelope is invalid")
    validator_path = Path(__file__).with_name("mo03-evidence.py")
    spec = importlib.util.spec_from_file_location("woopayments_md02_log_validator", validator_path)
    if spec is None or spec.loader is None:
        raise EvidenceError("common log validator is unavailable")
    validator = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(validator)
    scan = raw["scan"]
    if set(scan) != validator.COMMON_LOG_SCAN_FIELDS:
        raise EvidenceError("common log scan field set is invalid")
    projection_error = validator.log_projection_error(
        scan,
        run_stamp,
        expected_store=store,
        expected_flow_id=FLOW,
        expected_purpose="clean-debug-log",
        require_observation=scan.get("status") in {"pass", "fail"},
    )
    if projection_error:
        raise EvidenceError(projection_error)
    expected_status = {0: "pass", 1: "fail", 3: "blocked"}.get(expected_exit_code)
    if (
        expected_status is None
        or scan.get("status") != expected_status
        or (expected_status == "pass" and (scan.get("matches") or scan.get("blocker_code")))
        or (expected_status == "fail" and (not scan.get("matches") or scan.get("blocker_code")))
        or (expected_status == "blocked" and scan.get("blocker_code") not in validator.LOG_BLOCKER_CODES)
    ):
        raise EvidenceError("common log scan verdict is invalid")
    return seal(
        {
            "schema": "woopayments_md02_log_scan.v1",
            "status": expected_status,
            "store": store,
            "run_stamp": run_stamp,
            "flow_id": FLOW,
            "purpose": "clean-debug-log",
            "exit_code": expected_exit_code,
            "scan_observed": bool(scan["observations"]),
            "match_count": len(scan["matches"]),
            "blocker_code": scan["blocker_code"],
            "source_payload_sha256": source_digest,
        },
        LOG_DOMAIN,
    )


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser(description=__doc__)
    commands = root.add_subparsers(dest="command", required=True)

    extract = commands.add_parser("extract-json-object")
    extract.add_argument("--discriminator", required=True, choices=("schema", "mode"))
    extract.add_argument("--expected", required=True)

    normalize = commands.add_parser("normalize-state")
    normalize.add_argument("--input", required=True)
    normalize.add_argument("--store", required=True, choices=tuple(OWNERS))
    normalize.add_argument("--run-stamp", required=True)
    normalize.add_argument("--phase", required=True, choices=("pre", "post", "delayed"))

    state = commands.add_parser("validate-state")
    state.add_argument("--state", required=True)
    state.add_argument("--store", required=True, choices=tuple(OWNERS))
    state.add_argument("--run-stamp", required=True)
    state.add_argument("--phase", required=True, choices=("pre", "post", "delayed"))

    packet = commands.add_parser("seal-store-packet")
    packet.add_argument("--input", required=True)
    packet.add_argument("--store", required=True, choices=tuple(OWNERS))
    packet.add_argument("--run-stamp", required=True)

    comparison = commands.add_parser("compare")
    comparison.add_argument("--reference", required=True)
    comparison.add_argument("--target", required=True)
    comparison.add_argument("--run-stamp", required=True)

    log = commands.add_parser("normalize-log-scan")
    log.add_argument("--input", required=True)
    log.add_argument("--store", required=True, choices=tuple(OWNERS))
    log.add_argument("--run-stamp", required=True)
    log.add_argument("--expected-exit-code", required=True, type=int, choices=(0, 1, 3))

    execution = commands.add_parser("execution")
    execution.add_argument("--store-packet", required=True)
    execution.add_argument("--log-scan", required=True)
    execution.add_argument("--comparison")
    execution.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    execution.add_argument("--exit-code", required=True, type=int)
    execution.add_argument("--verdict-source", action="append", default=[])

    manifest = commands.add_parser("manifest")
    manifest.add_argument("--store", required=True, choices=tuple(OWNERS))
    manifest.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    manifest.add_argument("--exit-code", required=True, type=int)
    manifest.add_argument("--run-stamp", required=True)
    manifest.add_argument("--run-scope", required=True, choices=("partial", "full"))
    manifest.add_argument("--verdict-source", action="append", default=[])
    manifest.add_argument("--file", action="append", default=[], required=True)

    bound = commands.add_parser("validate-bound-manifest")
    bound.add_argument("--manifest", required=True)
    bound.add_argument("--store", required=True, choices=tuple(OWNERS))
    bound.add_argument("--status", required=True, choices=tuple(STATUS_EXIT))
    bound.add_argument("--exit-code", required=True, type=int)
    bound.add_argument("--run-stamp", required=True)
    bound.add_argument("--run-scope", required=True, choices=("partial", "full"))
    return root


def main() -> int:
    args = parser().parse_args()
    try:
        if args.command == "extract-json-object":
            return extract_json_object(args)
        if args.command == "normalize-state":
            payload = load_json(Path(args.input))
            payload["schema"] = "woopayments_md02_state.v1"
            validate_state(payload, store=args.store, run_stamp=args.run_stamp, phase=args.phase)
            print(json.dumps(payload, sort_keys=True, separators=(",", ":")))
            return 0
        if args.command == "validate-state":
            payload = load_json(Path(args.state))
            validate_state(payload, store=args.store, run_stamp=args.run_stamp, phase=args.phase)
            print("PASS: MD-02 state evidence is valid")
            return 0
        if args.command == "seal-store-packet":
            payload = load_json(Path(args.input))
            payload.pop("context_hmac", None)
            payload.pop("payload_sha256", None)
            sealed = seal(payload, STORE_PACKET_DOMAIN)
            validate_store_packet(sealed, store=args.store, run_stamp=args.run_stamp)
            print(json.dumps(sealed, sort_keys=True, separators=(",", ":")))
            return STATUS_EXIT[sealed["status"]]
        if args.command == "compare":
            reference = load_json(Path(args.reference))
            target = load_json(Path(args.target))
            validate_store_packet(reference, store="ref", run_stamp=args.run_stamp)
            validate_store_packet(target, store="target", run_stamp=args.run_stamp)
            result = seal(compare_store_packets(reference, target, args.run_stamp), COMPARISON_DOMAIN)
            validate_comparison(result, args.run_stamp)
            return emit(result, 0 if result["status"] == "pass" else 1)
        if args.command == "normalize-log-scan":
            result = normalize_log_scan_payload(load_json(Path(args.input)), args.store, args.run_stamp, args.expected_exit_code)
            validate_log_scan(result, args.store, args.run_stamp)
            return emit(result, args.expected_exit_code)
        if args.command == "execution":
            packet = load_json(Path(args.store_packet))
            log_scan = load_json(Path(args.log_scan))
            comparison = load_json(Path(args.comparison)) if args.comparison else None
            result = seal(
                build_execution(
                    packet,
                    log_scan,
                    status=args.status,
                    exit_code=args.exit_code,
                    verdict_sources=args.verdict_source,
                    comparison=comparison,
                ),
                EXECUTION_DOMAIN,
            )
            validate_execution(result, packet["store"], packet["run_stamp"])
            return emit(result, 0)
        if args.command == "manifest":
            result = seal(
                build_manifest(
                    store=args.store,
                    status=args.status,
                    exit_code=args.exit_code,
                    run_stamp=args.run_stamp,
                    run_scope=args.run_scope,
                    verdict_sources=args.verdict_source,
                    paths=[Path(path) for path in args.file],
                ),
                MANIFEST_DOMAIN,
            )
            return emit(result, 0)
        if args.command == "validate-bound-manifest":
            path = Path(args.manifest)
            validate_manifest(
                path,
                store=args.store,
                status=args.status,
                exit_code=args.exit_code,
                run_stamp=args.run_stamp,
                run_scope=args.run_scope,
            )
            print("sha256:" + hashlib.sha256(path.read_bytes()).hexdigest())
            return 0
    except EvidenceError as exc:
        print(f"BLOCKED: MD-02 evidence is unavailable: {exc}")
        return 3
    print("BLOCKED: unsupported MD-02 evidence command")
    return 3


if __name__ == "__main__":
    raise SystemExit(main())
