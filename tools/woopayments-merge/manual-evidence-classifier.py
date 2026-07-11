#!/usr/bin/env python3
"""Classify explicitly acknowledged WooPayments manual-evidence blockers."""

from __future__ import annotations

import json
import sys
from collections import Counter
from pathlib import Path
from typing import Any


out_dir = Path(sys.argv[1])
summary_path = Path(sys.argv[2])
expected_lpm_methods = tuple(method.strip() for method in sys.argv[3].split(",") if method.strip())
repo_root = Path(sys.argv[4])
labels = sys.argv[5:]
sys.path.insert(0, str(repo_root / "tools" / "woopayments-merge"))
from lpm_evidence import (  # noqa: E402
    MANUAL_BLOCKER_CODE,
    MANUAL_METHOD_CONTRACTS,
    context_from_payload,
    validate_manual_completion,
    validate_manual_pair,
)

accepted_lpm_profiles = {
    "p24": {"country": "PL", "capability": "p24_payments", "provisionable": True},
    "au_becs_debit": {"country": "AU", "capability": "au_becs_debit_payments", "provisionable": False},
    "grabpay": {"country": "SG", "capability": "grabpay_payments", "provisionable": False},
}
accepted_capability_statuses = {"missing", "unrequested", "pending", "inactive", "restricted", "rejected"}
accepted_critical_flows = {
    "SC-14-lpm-wave-1-checkout",
    "SS-10-sepa-token-renewal-cutover",
}


def read_json(path: Path) -> dict[str, Any] | None:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    return value if isinstance(value, dict) else None


def has_failures(payload: dict[str, Any]) -> bool:
    failures = payload.get("failures")
    return isinstance(failures, list) and bool(failures)


def has_empty_failure_list(payload: dict[str, Any]) -> bool:
    failures = payload.get("failures")
    return isinstance(failures, list) and not failures


def resolve_lpm_artifact(value: Any, expected: Path) -> Path | None:
    if not isinstance(value, str) or not value:
        return None

    path = Path(value)
    if not path.is_absolute():
        path = out_dir / "lpm-all-methods" / path

    try:
        return path if path.resolve() == expected.resolve() else None
    except OSError:
        return None


def valid_sepa_capability_blocker(detail: dict[str, Any]) -> bool:
    role = detail.get("role")
    expected = out_dir / "lpm-all-methods" / "lpm-fixtures" / f"{role}-sepa_debit-stage.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    payload = read_json(path) if path else None
    if not payload:
        return False

    previous = payload.get("previous") if isinstance(payload.get("previous"), dict) else {}
    capability_key = "sepa_debit_payments"
    capability_status = str(previous.get("capability_status") or "missing").lower()
    errors = payload.get("errors") if isinstance(payload.get("errors"), list) else []
    capabilities = (
        previous.get("account_cache", {}).get("data", {}).get("capabilities", {})
        if isinstance(previous.get("account_cache"), dict)
        else {}
    )

    return (
        payload.get("success") is False
        and payload.get("mode") == "stage-lpm-fixture"
        and payload.get("method") == "sepa_debit"
        and previous.get("capability_key") == capability_key
        and capability_status in accepted_capability_statuses
        and (
            capability_status == "missing"
            or (isinstance(capabilities, dict) and str(capabilities.get(capability_key) or "").lower() == capability_status)
        )
        and any(
            isinstance(error, str)
            and "connected WooPayments account capability" in error
            and capability_key in error
            for error in errors
        )
    )


def valid_account_profile_blocker(detail: dict[str, Any]) -> bool:
    role = detail.get("role")
    method = detail.get("method")
    rule = accepted_lpm_profiles.get(str(method))
    if not rule:
        return False

    expected = out_dir / "lpm-all-methods" / f"{role}-{method}-account-profile.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    payload = read_json(path) if path else None
    if not payload:
        return False

    profile = payload.get("profile") if isinstance(payload.get("profile"), dict) else {}
    checks = payload.get("checks") if isinstance(payload.get("checks"), dict) else {}
    country_check = checks.get("country") if isinstance(checks.get("country"), dict) else {}
    capability_check = checks.get(rule["capability"]) if isinstance(checks.get(rule["capability"]), dict) else {}
    capability_status = str(
        capability_check.get("status") or capability_check.get("capability_status") or ""
    ).lower()
    has_exact_account_blocker = (
        country_check.get("status") == "wrong_country"
        or capability_status in accepted_capability_statuses
    )

    return (
        payload.get("success") is True
        and payload.get("ready") is False
        and payload.get("status") == "blocked"
        and profile.get("id") == method
        and profile.get("country") == rule["country"]
        and profile.get("test_lab_provisionable") is rule["provisionable"]
        and has_exact_account_blocker
    )


def valid_manual_completion_blocker(
    detail: dict[str, Any], result: dict[str, Any], surface: str
) -> bool:
    role = str(detail.get("role") or "")
    method = str(detail.get("method") or "")
    if method not in MANUAL_METHOD_CONTRACTS:
        return False

    expected = out_dir / "lpm-all-methods" / f"{role}-{method}-{surface}.json"
    path = resolve_lpm_artifact(detail.get("artifact"), expected)
    artifact = read_json(path) if path else None
    if not artifact or artifact != result:
        return False

    context = context_from_payload(artifact)
    return (
        detail.get("code") == MANUAL_BLOCKER_CODE
        and detail.get("provenance") == artifact.get("manual_completion")
        and context.role == role
        and context.method == method
        and not validate_manual_completion(artifact, context)
    )


def accept_lpm_all_methods() -> str | None:
    payload = read_json(out_dir / "lpm-all-methods" / "lpm-checkout-gate.json")
    if (
        not payload
        or payload.get("schema") != "woopayments_lpm_checkout_gate_rollup.v1"
        or payload.get("status") not in {"blocked", "incomplete"}
        or not has_empty_failure_list(payload)
        or len(expected_lpm_methods) != len(set(expected_lpm_methods))
    ):
        return None

    cleanup_restore = payload.get("cleanup_restore")
    cleanup_artifact = read_json(out_dir / "lpm-all-methods" / "lpm-cleanup-restore.json")
    if (
        not isinstance(cleanup_restore, dict)
        or cleanup_restore.get("schema") != "woopayments_lpm_cleanup_restore.v1"
        or cleanup_restore.get("status") != "pass"
        or cleanup_artifact != cleanup_restore
    ):
        return None

    expected_identities = {
        (role, method)
        for role in ("reference", "target")
        for method in expected_lpm_methods
    }
    results = payload.get("results")
    if not isinstance(results, list):
        return None

    result_identities = set()
    passing_result_identities = set()
    manual_result_identities = set()
    results_by_identity: dict[tuple[Any, Any], dict[str, Any]] = {}
    for result in results:
        if not isinstance(result, dict) or not has_empty_failure_list(result):
            return None
        identity = (result.get("role"), result.get("method"))
        if (
            identity not in expected_identities
            or identity in result_identities
        ):
            return None
        result_identities.add(identity)
        results_by_identity[identity] = result
        if result.get("status") == "pass":
            passing_result_identities.add(identity)
        elif (
            result.get("status") == "blocked"
            and result.get("blocker_code") == MANUAL_BLOCKER_CODE
            and result.get("method") in MANUAL_METHOD_CONTRACTS
        ):
            manual_result_identities.add(identity)
        else:
            return None

    blockers = payload.get("blockers")
    if not isinstance(blockers, list) or not blockers:
        return None

    blocker_details = payload.get("blocker_details")
    if not isinstance(blocker_details, list) or len(blocker_details) != len(blockers):
        return None
    if Counter(blockers) != Counter(
        detail.get("message") for detail in blocker_details if isinstance(detail, dict)
    ):
        return None

    blocker_identities = set()
    account_blocker_identities = set()
    manual_blocker_identities = set()
    surface = str(payload.get("surface") or "")
    if surface not in {"classic", "blocks"}:
        return None
    for detail in blocker_details:
        if not isinstance(detail, dict):
            return None
        role = detail.get("role")
        method = detail.get("method")
        identity = (role, method)
        if identity not in expected_identities or identity in blocker_identities:
            return None
        blocker_identities.add(identity)

        code = detail.get("code")
        if code == "account_capability_unavailable" and method == "sepa_debit":
            if not valid_sepa_capability_blocker(detail):
                return None
            account_blocker_identities.add(identity)
        elif code == "account_profile_ineligible" and method in accepted_lpm_profiles:
            if not valid_account_profile_blocker(detail):
                return None
            account_blocker_identities.add(identity)
        elif code == MANUAL_BLOCKER_CODE and method in MANUAL_METHOD_CONTRACTS:
            result = results_by_identity.get(identity)
            if not result or not valid_manual_completion_blocker(detail, result, surface):
                return None
            manual_blocker_identities.add(identity)
        else:
            return None

    if account_blocker_identities & result_identities:
        return None
    if manual_blocker_identities != manual_result_identities:
        return None
    if passing_result_identities & blocker_identities:
        return None
    if passing_result_identities | account_blocker_identities | manual_blocker_identities != expected_identities:
        return None

    for method in MANUAL_METHOD_CONTRACTS:
        method_identities = {
            ("reference", method),
            ("target", method),
        }
        classified_identities = manual_blocker_identities & method_identities
        if classified_identities and classified_identities != method_identities:
            return None
        if classified_identities:
            if validate_manual_pair(
                results_by_identity[("reference", method)],
                results_by_identity[("target", method)],
                method,
            ):
                return None

    return "LPM blockers are limited to validated payment-method account/profile prerequisites and symmetric customer authorization that requires manual completion."


def accept_token_continuity() -> str | None:
    rollup_path = out_dir / "token-continuity" / "token-continuity-gate.json"
    if rollup_path.exists():
        return None

    stage = read_json(out_dir / "token-continuity" / "sepa-fixture-stage.json")
    if not stage or stage.get("success") is not False or stage.get("method") != "sepa_debit":
        return None

    previous = stage.get("previous") if isinstance(stage.get("previous"), dict) else {}
    capability_key = "sepa_debit_payments"
    capability_status = str(previous.get("capability_status") or "missing").lower()
    capabilities = (
        previous.get("account_cache", {}).get("data", {}).get("capabilities", {})
        if isinstance(previous.get("account_cache"), dict)
        else {}
    )
    errors = stage.get("errors")
    if not isinstance(errors, list):
        return None
    if (
        stage.get("mode") != "stage-lpm-fixture"
        or previous.get("capability_key") != capability_key
        or capability_status not in accepted_capability_statuses
        or (
            capability_status != "missing"
            and (
                not isinstance(capabilities, dict)
                or str(capabilities.get(capability_key) or "").lower() != capability_status
            )
        )
        or not any(
            isinstance(error, str)
            and "connected WooPayments account capability" in error
            and capability_key in error
            for error in errors
        )
    ):
        return None

    return "Token continuity is blocked only by the accepted SEPA fixture/account prerequisite."


def valid_target_only_token_continuity_pass() -> bool:
    payload = read_json(out_dir / "token-continuity" / "token-continuity-gate.json")
    if (
        not payload
        or payload.get("schema") != "woopayments_token_continuity_gate_rollup.v1"
        or payload.get("status") != "pass"
        or payload.get("failures") != []
        or payload.get("blockers") != []
    ):
        return False

    token_id = payload.get("token_id")
    source = payload.get("source_token") if isinstance(payload.get("source_token"), dict) else {}
    native = (
        payload.get("native_token_loader")
        if isinstance(payload.get("native_token_loader"), dict)
        else {}
    )
    render = (
        payload.get("render_payment_methods")
        if isinstance(payload.get("render_payment_methods"), dict)
        else {}
    )
    render_page = render.get("page") if isinstance(render.get("page"), dict) else {}
    methods = (
        render_page.get("payment_methods")
        if isinstance(render_page.get("payment_methods"), dict)
        else {}
    )
    renewal = payload.get("renewal") if isinstance(payload.get("renewal"), dict) else {}

    return (
        payload.get("source_flow") == "provider_setup_intent"
        and isinstance(token_id, int)
        and token_id > 0
        and source.get("success") is True
        and source.get("source_payment_method_customer_ready") is True
        and str(source.get("payment_method_id") or "").startswith("pm_")
        and native.get("success") is True
        and native.get("token_id") == token_id
        and native.get("gateway_id") == "woocommerce_payments_sepa_debit"
        and native.get("token_type") == "wcpay_sepa"
        and str(native.get("token_class") or "").endswith("WooPaymentsSepaToken")
        and render.get("status") == "pass"
        and render.get("token_id") == token_id
        and render.get("token_visible") is True
        and methods.get("token_visible") is True
        and renewal.get("success") is True
        and renewal.get("renewal_processing_model") == "asynchronous_processing"
        and renewal.get("success_checks_failed") == []
        and isinstance(renewal.get("renewal_order_id"), int)
        and renewal.get("renewal_order_id", 0) > 0
    )


def accept_critical_flows() -> str | None:
    payload = read_json(out_dir / "critical-flows" / "rollup.json")
    context = read_json(out_dir / "critical-flow-context.json")
    flow_dir = repo_root / "tools" / "woopayments-critical-flows" / "flows"
    flow_paths = sorted(flow_dir.glob("*.sh")) + sorted(flow_dir.glob("*.md"))
    expected_flows = {path.stem for path in flow_paths}
    if (
        not payload
        or not context
        or payload.get("schema") != "woopayments_critical_flows_rollup.v1"
        or payload.get("status") != "blocked"
        or not expected_flows
        or len(expected_flows) != len(flow_paths)
        or not accepted_critical_flows <= expected_flows
    ):
        return None
    if payload.get("context_sha256") != context.get("context_sha256"):
        return None
    if payload.get("aggregate_run_id") != context.get("aggregate_run_id"):
        return None

    summary = payload.get("summary")
    if not isinstance(summary, dict):
        return None

    results = payload.get("results")
    if not isinstance(results, list):
        return None

    expected_identities = {
        (flow, store)
        for flow in expected_flows
        for store in ("ref", "target")
    }
    seen_identities = set()
    status_counts: Counter[str] = Counter()
    flow_statuses: dict[str, dict[str, str]] = {}
    for result in results:
        if not isinstance(result, dict):
            return None
        status = str(result.get("status") or "").upper()
        flow = str(result.get("flow") or "")
        store = str(result.get("store") or "")
        identity = (flow, store)
        if identity not in expected_identities or identity in seen_identities:
            return None
        if status not in {"PASS", "BLOCKED"}:
            return None
        seen_identities.add(identity)
        status_counts[status] += 1
        if flow not in accepted_critical_flows:
            if status != "PASS":
                return None
            continue
        if store in flow_statuses.setdefault(flow, {}):
            return None
        flow_statuses[flow][store] = status

    if seen_identities != expected_identities:
        return None
    if (
        summary.get("passed") != status_counts["PASS"]
        or summary.get("failed") != 0
        or summary.get("blocked") != status_counts["BLOCKED"]
    ):
        return None

    sc14 = flow_statuses.get("SC-14-lpm-wave-1-checkout")
    if sc14 != {"ref": "BLOCKED", "target": "BLOCKED"} or accept_lpm_all_methods() is None:
        return None

    ss10 = flow_statuses.get("SS-10-sepa-token-renewal-cutover")
    if ss10 == {"ref": "BLOCKED", "target": "BLOCKED"}:
        if accept_token_continuity() is None:
            return None
    elif ss10 == {"ref": "BLOCKED", "target": "PASS"}:
        if not valid_target_only_token_continuity_pass():
            return None
    else:
        return None

    return "Critical-flow blockers are inherited only from accepted LPM/SEPA manual evidence rows."


def classify(label: str) -> str | None:
    if label == "LPM all-method checkout":
        return accept_lpm_all_methods()
    if label == "token continuity cutover":
        return accept_token_continuity()
    if label == "critical flows full run":
        return accept_critical_flows()
    return None


def shell_field(value: str) -> str:
    return value.replace("\t", " ").replace("\n", " ")


accepted: list[dict[str, str]] = []
blocked: list[dict[str, str]] = []
for blocked_label in labels:
    accepted_reason = classify(blocked_label)
    entry = {
        "label": blocked_label,
        "reason": accepted_reason or "No accepted manual-evidence classification matched.",
    }
    if accepted_reason:
        accepted.append(entry)
        print(f"ACCEPTED\t{shell_field(blocked_label)}\t{shell_field(accepted_reason)}")
    else:
        blocked.append(entry)
        print(f"BLOCKED\t{shell_field(blocked_label)}\t{shell_field(entry['reason'])}")

summary_path.parent.mkdir(parents=True, exist_ok=True)
summary_path.write_text(
    json.dumps(
        {
            "schema": "woopayments_manual_evidence_limitations.v1",
            "accepted": accepted,
            "blocked": blocked,
        },
        indent=2,
        sort_keys=True,
    )
    + "\n",
    encoding="utf-8",
)
