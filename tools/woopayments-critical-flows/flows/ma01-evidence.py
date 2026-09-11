#!/usr/bin/env python3
"""Normalize, compare, and bind deterministic MA-01 access-control evidence."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import sys
from pathlib import Path
from typing import Any


RAW_SCHEMA = "woopayments_ma01_probe.v1"
PROBE_SCHEMA = "woopayments_ma01_normalized.v1"
COMPARISON_SCHEMA = "woopayments_ma01_comparison.v1"
EXECUTION_SCHEMA = "woopayments_ma01_execution.v1"
MANIFEST_SCHEMA = "woopayments_ma01_manifest.v1"
FLOW = "MA-01-open-admin-as-non-admin"

ROUTES = {
    "transactions": "/wc/v3/payments/transactions",
    "deposits": "/wc/v3/payments/deposits",
    "disputes": "/wc/v3/payments/disputes",
}
REQUESTED_PATHS = {
    "ref": "/wp-admin/admin.php?page=wc-admin&path=/payments/overview",
    "target": "/wp-admin/admin.php?page=wc-admin&path=/woopayments/overview",
}
OWNERS = {"ref": "plugin", "target": "native"}
STATUS_EXIT = {"pass": 0, "fail": 1, "blocked": 3}

RAW_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "runtime_owner",
    "fixture",
    "routes",
    "http",
    "exact_session_cleanup",
    "blockers",
}
FIXTURE_FIELDS = {
    "customer_login",
    "customer_roles",
    "customer_preexisting",
    "customer_created",
    "customer_manage_woocommerce",
    "admin_manage_woocommerce",
}
RAW_ROUTE_FIELDS = {"path", "customer", "admin"}
RAW_CUSTOMER_FIELDS = {
    "status",
    "code",
    "standard_error",
    "financial_list_absent",
}
RAW_ADMIN_FIELDS = {"status", "well_formed_data_list", "list_envelope"}
RAW_HTTP_FIELDS = {
    "requested_path",
    "final_path",
    "requested_status",
    "final_status",
    "redirect_count",
    "transport_errors",
    "logged_in_marker",
    "logout_marker",
    "login_form_marker",
    "permission_marker",
    "app_marker",
}
PROBE_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "runtime_owner",
    "evidence_complete",
    "facts",
    "contract",
    "errors",
    "blockers",
    "context_hmac",
    "payload_sha256",
}
FACT_FIELDS = {
    "binding",
    "fixture",
    "routes",
    "http",
    "exact_session_cleanup",
    "reported_blocker",
}
BINDING_FACT_FIELDS = {"store_valid", "run_stamp_valid", "runtime_owner_valid"}
FIXTURE_FACT_FIELDS = {
    "customer_login_exact",
    "customer_role_exact",
    "customer_preexisting",
    "customer_created",
    "customer_manage_woocommerce",
    "admin_manage_woocommerce",
}
ROUTE_FACT_FIELDS = {
    "route_exact",
    "customer_status",
    "customer_code_forbidden_family",
    "customer_standard_error",
    "customer_financial_list_absent",
    "admin_status",
    "admin_well_formed_data_list",
    "admin_list_envelope",
}
HTTP_FACT_FIELDS = {
    "requested_path_exact",
    "final_path_is_payments",
    "final_path_is_login",
    "requested_status",
    "final_status",
    "redirect_count",
    "transport_error_count",
    "logged_in_marker",
    "logout_marker",
    "login_form_marker",
    "permission_marker",
    "app_marker",
}
CONTRACT_FIELDS = {"capabilities", "routes", "http", "cleanup_proven"}
CAPABILITY_CONTRACT_FIELDS = {"customer_denied", "admin_admitted"}
ROUTE_CONTRACT_FIELDS = {
    "customer_denied",
    "customer_standard_error",
    "customer_financial_list_absent",
    "admin_admitted",
    "admin_data_list_well_formed",
}
HTTP_CONTRACT_FIELDS = {"authenticated", "never_login", "app_absent", "access_denied"}
PARITY_CONTRACT_FIELDS = {"capabilities", "routes", "http"}
COMPARISON_FIELDS = {
    "schema",
    "status",
    "run_stamp",
    "inputs",
    "reference",
    "target",
    "errors",
    "blockers",
    "payload_sha256",
}
EXECUTION_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "exit_code",
    "verdict_sources",
    "probe_exit_code",
    "probe_payload_sha256",
    "comparison_exit_code",
    "comparison_payload_sha256",
    "log_assertion_exit_code",
    "payload_sha256",
}
MANIFEST_FIELDS = {
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
VERDICT_SOURCES = {
    "pass": set(),
    "fail": {"probe_failed", "comparison_failed", "log_assertion_failed"},
    "blocked": {"probe_blocked", "comparison_blocked", "log_assertion_blocked"},
}
DIGEST_RE = re.compile(r"sha256:[0-9a-f]{64}")
CONTEXT_HMAC_RE = re.compile(r"hmac-sha256:[0-9a-f]{64}")
CONTEXT_KEY_RE = re.compile(r"[0-9a-f]{64}")
FORBIDDEN_CODE_RE = re.compile(r"rest_forbidden(?:[_-][a-z0-9_-]+)?")
CONTEXT_KEY_ENV = "CRITICAL_FLOWS_RUN_CONTEXT_KEY"
CONTEXT_HMAC_DOMAIN = b"woopayments-ma01-normalized-probe-context-v1\0"
MANIFEST_CONTEXT_HMAC_DOMAIN = b"woopayments-ma01-manifest-context-v1\0"
CONTEXT_HMAC_FIELDS = (
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
MANIFEST_CONTEXT_HMAC_FIELDS = (
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


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    encoded = json.dumps(unsigned, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return "sha256:" + hashlib.sha256(encoded).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def emit(payload: dict[str, Any], exit_code: int) -> int:
    result = dict(payload)
    result["payload_sha256"] = payload_digest(result)
    print(json.dumps(result, indent=2, sort_keys=True))
    return exit_code


def context_key() -> bytes | None:
    raw = os.environ.get(CONTEXT_KEY_ENV, "")
    if not CONTEXT_KEY_RE.fullmatch(raw):
        return None
    return bytes.fromhex(raw)


def probe_context_hmac(payload: dict[str, Any], key: bytes) -> str:
    semantics = {field: payload[field] for field in CONTEXT_HMAC_FIELDS}
    message = CONTEXT_HMAC_DOMAIN + json.dumps(
        semantics, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return "hmac-sha256:" + hmac.new(key, message, hashlib.sha256).hexdigest()


def manifest_context_hmac(payload: dict[str, Any], key: bytes) -> str:
    semantics = {field: payload[field] for field in MANIFEST_CONTEXT_HMAC_FIELDS}
    message = MANIFEST_CONTEXT_HMAC_DOMAIN + json.dumps(
        semantics, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return "hmac-sha256:" + hmac.new(key, message, hashlib.sha256).hexdigest()


def emit_probe(payload: dict[str, Any], key: bytes, exit_code: int) -> int:
    sealed = dict(payload)
    sealed["context_hmac"] = probe_context_hmac(sealed, key)
    return emit(sealed, exit_code)


def is_int(value: Any) -> bool:
    return isinstance(value, int) and not isinstance(value, bool)


def is_bool_object(value: Any, fields: set[str]) -> bool:
    return (
        isinstance(value, dict)
        and set(value) == fields
        and all(isinstance(value[field], bool) for field in fields)
    )


def is_string_list(value: Any, *, nonempty: bool = False) -> bool:
    return (
        isinstance(value, list)
        and (not nonempty or bool(value))
        and all(isinstance(item, str) and bool(item) for item in value)
    )


def lexical_absolute_path(path: Path) -> tuple[Path | None, str | None]:
    if ".." in path.parts:
        return None, "path must not contain parent traversal"
    try:
        return Path(os.path.abspath(os.fspath(path))), None
    except (OSError, RuntimeError, TypeError, ValueError):
        return None, "path is invalid"


def safe_directory_error(directory: Path) -> str | None:
    try:
        for component in (directory, *directory.parents):
            if component.is_symlink():
                return "path contains a symlinked directory component"
        if not directory.is_dir():
            return "parent directory does not exist"
    except (OSError, RuntimeError):
        return "parent directory is unavailable"
    return None


def safe_existing_file(path: Path) -> tuple[Path | None, str | None]:
    absolute, error = lexical_absolute_path(path)
    if error or absolute is None:
        return None, error
    error = safe_directory_error(absolute.parent)
    if error:
        return None, error
    try:
        if absolute.is_symlink() or not absolute.is_file():
            return None, "path is not a regular non-symlinked file"
    except (OSError, RuntimeError):
        return None, "path is not a regular non-symlinked file"
    return absolute, None


def safe_output_file(path: Path) -> tuple[Path | None, str | None]:
    absolute, error = lexical_absolute_path(path)
    if error or absolute is None:
        return None, error
    error = safe_directory_error(absolute.parent)
    if error:
        return None, error
    try:
        if absolute.is_symlink():
            return None, "output must not be a symlink"
    except (OSError, RuntimeError):
        return None, "output path is unavailable"
    return absolute, None


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


def raw_shape_error(payload: Any) -> str | None:
    if not isinstance(payload, dict) or set(payload) != RAW_FIELDS:
        return "Probe payload has an invalid field set."
    if not all(
        isinstance(payload.get(field), str)
        for field in ("schema", "store", "run_stamp", "runtime_owner")
    ):
        return "Probe payload bindings have invalid types."
    fixture = payload.get("fixture")
    if not isinstance(fixture, dict) or set(fixture) != FIXTURE_FIELDS:
        return "Probe fixture has an invalid field set."
    if not isinstance(fixture.get("customer_login"), str) or not is_string_list(
        fixture.get("customer_roles")
    ):
        return "Probe fixture identity facts have invalid types."
    if not all(
        isinstance(fixture.get(field), bool)
        for field in (
            "customer_preexisting",
            "customer_created",
            "customer_manage_woocommerce",
            "admin_manage_woocommerce",
        )
    ):
        return "Probe fixture capability facts have invalid types."
    routes = payload.get("routes")
    if not isinstance(routes, dict) or set(routes) != set(ROUTES):
        return "Probe routes have an invalid field set."
    for name, route in routes.items():
        if not isinstance(route, dict) or set(route) != RAW_ROUTE_FIELDS:
            return f"Probe {name} route has an invalid field set."
        if not isinstance(route.get("path"), str):
            return f"Probe {name} path has an invalid type."
        customer = route.get("customer")
        admin = route.get("admin")
        if not isinstance(customer, dict) or set(customer) != RAW_CUSTOMER_FIELDS:
            return f"Probe {name} customer result has an invalid field set."
        if not isinstance(admin, dict) or set(admin) != RAW_ADMIN_FIELDS:
            return f"Probe {name} admin result has an invalid field set."
        if not is_int(customer.get("status")) or not isinstance(customer.get("code"), str):
            return f"Probe {name} customer status facts have invalid types."
        if not all(
            isinstance(customer.get(field), bool)
            for field in ("standard_error", "financial_list_absent")
        ):
            return f"Probe {name} customer response facts have invalid types."
        if not is_int(admin.get("status")) or not all(
            isinstance(admin.get(field), bool)
            for field in ("well_formed_data_list", "list_envelope")
        ):
            return f"Probe {name} admin response facts have invalid types."
    http = payload.get("http")
    if not isinstance(http, dict) or set(http) != RAW_HTTP_FIELDS:
        return "Probe HTTP result has an invalid field set."
    if not all(isinstance(http.get(field), str) for field in ("requested_path", "final_path")):
        return "Probe HTTP paths have invalid types."
    if not all(
        is_int(http.get(field))
        for field in ("requested_status", "final_status", "redirect_count")
    ):
        return "Probe HTTP status facts have invalid types."
    if not is_string_list(http.get("transport_errors")):
        return "Probe HTTP transport facts have invalid types."
    if not all(
        isinstance(http.get(field), bool)
        for field in (
            "logged_in_marker",
            "logout_marker",
            "login_form_marker",
            "permission_marker",
            "app_marker",
        )
    ):
        return "Probe HTTP marker facts have invalid types."
    if not isinstance(payload.get("exact_session_cleanup"), bool):
        return "Probe cleanup fact has an invalid type."
    if not is_string_list(payload.get("blockers")):
        return "Probe blockers have an invalid type."
    return None


def project_facts(payload: dict[str, Any], store: str, run_stamp: str) -> dict[str, Any]:
    fixture = payload["fixture"]
    raw_http = payload["http"]
    requested_path = REQUESTED_PATHS[store]
    route_facts: dict[str, dict[str, Any]] = {}
    for name, expected_path in ROUTES.items():
        raw_route = payload["routes"][name]
        customer = raw_route["customer"]
        admin = raw_route["admin"]
        route_facts[name] = {
            "route_exact": raw_route["path"] == expected_path,
            "customer_status": customer["status"],
            "customer_code_forbidden_family": bool(
                FORBIDDEN_CODE_RE.fullmatch(customer["code"])
            ),
            "customer_standard_error": customer["standard_error"],
            "customer_financial_list_absent": customer["financial_list_absent"],
            "admin_status": admin["status"],
            "admin_well_formed_data_list": admin["well_formed_data_list"],
            "admin_list_envelope": admin["list_envelope"],
        }
    return {
        "binding": {
            "store_valid": payload["store"] == store,
            "run_stamp_valid": payload["run_stamp"] == run_stamp,
            "runtime_owner_valid": payload["runtime_owner"] == OWNERS[store],
        },
        "fixture": {
            "customer_login_exact": fixture["customer_login"] == "ma01-customer",
            "customer_role_exact": fixture["customer_roles"] == ["customer"],
            "customer_preexisting": fixture["customer_preexisting"],
            "customer_created": fixture["customer_created"],
            "customer_manage_woocommerce": fixture["customer_manage_woocommerce"],
            "admin_manage_woocommerce": fixture["admin_manage_woocommerce"],
        },
        "routes": route_facts,
        "http": {
            "requested_path_exact": raw_http["requested_path"] == requested_path,
            "final_path_is_payments": (
                "page=wc-admin" in raw_http["final_path"]
                and (
                    "path=/payments" in raw_http["final_path"]
                    or "path=/woopayments" in raw_http["final_path"]
                )
            ),
            "final_path_is_login": "/wp-login.php" in raw_http["final_path"],
            "requested_status": raw_http["requested_status"],
            "final_status": raw_http["final_status"],
            "redirect_count": raw_http["redirect_count"],
            "transport_error_count": len(raw_http["transport_errors"]),
            "logged_in_marker": raw_http["logged_in_marker"],
            "logout_marker": raw_http["logout_marker"],
            "login_form_marker": raw_http["login_form_marker"],
            "permission_marker": raw_http["permission_marker"],
            "app_marker": raw_http["app_marker"],
        },
        "exact_session_cleanup": payload["exact_session_cleanup"],
        "reported_blocker": bool(payload["blockers"]),
    }


def evaluate_facts(
    facts: dict[str, Any], store: str, runtime_owner: str
) -> tuple[dict[str, Any], list[str], list[str]]:
    errors: list[str] = []
    blockers: list[str] = []
    binding = facts["binding"]
    fixture = facts["fixture"]
    http = facts["http"]

    if not binding["store_valid"]:
        blockers.append("Probe has an invalid store binding.")
    if not binding["run_stamp_valid"]:
        blockers.append("Probe has an invalid run binding.")
    if not binding["runtime_owner_valid"] or runtime_owner != OWNERS[store]:
        blockers.append("Probe has an invalid runtime-owner binding.")
    if not fixture["customer_login_exact"]:
        blockers.append("Probe did not exercise the exact customer fixture.")
    fixture_ensure_valid = (
        fixture["customer_preexisting"] != fixture["customer_created"]
    )
    if not fixture_ensure_valid:
        blockers.append("Probe fixture ensure state was unavailable.")
    else:
        if not fixture["customer_role_exact"]:
            errors.append("Fixture customer role was not exactly customer.")
        if fixture["customer_manage_woocommerce"]:
            errors.append(
                "Fixture customer capability unexpectedly admitted manage_woocommerce."
            )
        if not fixture["admin_manage_woocommerce"]:
            errors.append("Fixture admin capability did not admit manage_woocommerce.")

    route_contracts: dict[str, dict[str, bool]] = {}
    for name in ROUTES:
        route = facts["routes"][name]
        if not route["route_exact"]:
            blockers.append(f"{name} route binding was not exact.")
        customer_status = route["customer_status"]
        admin_status = route["admin_status"]
        customer_observed = 100 <= customer_status <= 599
        admin_observed = 100 <= admin_status <= 599
        if not customer_observed:
            blockers.append(f"{name} customer route observation was unavailable.")
        else:
            if customer_status not in {401, 403}:
                errors.append(
                    f"{name} customer status was {customer_status}, not 401/403."
                )
            else:
                if not route["customer_code_forbidden_family"]:
                    errors.append(
                        f"{name} customer error code was not rest_forbidden-family."
                    )
                if not route["customer_standard_error"]:
                    errors.append(
                        f"{name} customer response was not a standard REST error."
                    )
            if not route["customer_financial_list_absent"]:
                errors.append(f"{name} customer response leaked a financial data list.")
        if not admin_observed:
            blockers.append(f"{name} admin route observation was unavailable.")
        else:
            if admin_status != 200:
                errors.append(f"{name} admin status was {admin_status}, not 200.")
            else:
                if not route["admin_well_formed_data_list"]:
                    errors.append(f"{name} admin data list was malformed.")
                if not route["admin_list_envelope"]:
                    errors.append(f"{name} admin list envelope was malformed.")
        route_contracts[name] = {
            "customer_denied": (
                route["route_exact"]
                and customer_status in {401, 403}
                and route["customer_code_forbidden_family"]
                and route["customer_standard_error"]
                and route["customer_financial_list_absent"]
            ),
            "customer_standard_error": (
                customer_status in {401, 403}
                and route["customer_code_forbidden_family"]
                and route["customer_standard_error"]
            ),
            "customer_financial_list_absent": route["customer_financial_list_absent"],
            "admin_admitted": route["route_exact"] and admin_status == 200,
            "admin_data_list_well_formed": (
                admin_status == 200
                and route["admin_well_formed_data_list"]
                and route["admin_list_envelope"]
            ),
        }

    if not http["requested_path_exact"]:
        blockers.append("HTTP probe did not request the exact Payments path.")
    if http["redirect_count"] < 0:
        blockers.append("HTTP probe recorded an invalid redirect count.")
    if http["transport_error_count"]:
        blockers.append("Authenticated HTTP transport failed.")
    requested_status_observed = 100 <= http["requested_status"] <= 599
    final_status_observed = 100 <= http["final_status"] <= 599
    if not requested_status_observed or not final_status_observed:
        blockers.append("Authenticated HTTP status observation was unavailable.")
    authenticated = http["permission_marker"] or (
        http["logged_in_marker"] and http["logout_marker"]
    )
    never_login = not http["login_form_marker"] and not http["final_path_is_login"]
    if not never_login:
        blockers.append("Authenticated HTTP probe redirected to or rendered login.")
    if not authenticated:
        blockers.append("HTTP response was not affirmatively authenticated.")
    http_complete = (
        http["requested_path_exact"]
        and http["transport_error_count"] == 0
        and requested_status_observed
        and final_status_observed
        and http["redirect_count"] >= 0
        and authenticated
        and never_login
    )
    access_denied = (
        not http["app_marker"]
        and (http["permission_marker"] or not http["final_path_is_payments"])
    )
    if http_complete:
        if http["app_marker"]:
            errors.append("Payments app rendered for the customer fixture.")
        if not access_denied:
            errors.append("Customer remained on the Payments path without a permission denial.")
    if not facts["exact_session_cleanup"]:
        blockers.append("Exact-session cleanup was not proven.")
    if facts["reported_blocker"]:
        blockers.append("Probe reported a blocker.")

    contract = {
        "capabilities": {
            "customer_denied": not fixture["customer_manage_woocommerce"],
            "admin_admitted": fixture["admin_manage_woocommerce"],
        },
        "routes": route_contracts,
        "http": {
            "authenticated": authenticated,
            "never_login": never_login,
            "app_absent": not http["app_marker"],
            "access_denied": access_denied,
        },
        "cleanup_proven": facts["exact_session_cleanup"],
    }
    return contract, errors, blockers


def facts_shape_valid(facts: Any) -> bool:
    if not isinstance(facts, dict) or set(facts) != FACT_FIELDS:
        return False
    if not is_bool_object(facts.get("binding"), BINDING_FACT_FIELDS):
        return False
    if not is_bool_object(facts.get("fixture"), FIXTURE_FACT_FIELDS):
        return False
    routes = facts.get("routes")
    if not isinstance(routes, dict) or set(routes) != set(ROUTES):
        return False
    for route in routes.values():
        if not isinstance(route, dict) or set(route) != ROUTE_FACT_FIELDS:
            return False
        for field in ROUTE_FACT_FIELDS - {"customer_status", "admin_status"}:
            if not isinstance(route[field], bool):
                return False
        if not is_int(route["customer_status"]) or not is_int(route["admin_status"]):
            return False
    http = facts.get("http")
    if not isinstance(http, dict) or set(http) != HTTP_FACT_FIELDS:
        return False
    for field in HTTP_FACT_FIELDS - {
        "requested_status",
        "final_status",
        "redirect_count",
        "transport_error_count",
    }:
        if not isinstance(http[field], bool):
            return False
    for field in ("requested_status", "final_status", "redirect_count", "transport_error_count"):
        if not is_int(http[field]):
            return False
    return isinstance(facts.get("exact_session_cleanup"), bool) and isinstance(
        facts.get("reported_blocker"), bool
    )


def contract_shape_valid(contract: Any) -> bool:
    if not isinstance(contract, dict) or set(contract) != CONTRACT_FIELDS:
        return False
    if not is_bool_object(contract.get("capabilities"), CAPABILITY_CONTRACT_FIELDS):
        return False
    routes = contract.get("routes")
    if not isinstance(routes, dict) or set(routes) != set(ROUTES):
        return False
    if any(not is_bool_object(route, ROUTE_CONTRACT_FIELDS) for route in routes.values()):
        return False
    return is_bool_object(contract.get("http"), HTTP_CONTRACT_FIELDS) and isinstance(
        contract.get("cleanup_proven"), bool
    )


def parity_contract_shape_valid(contract: Any) -> bool:
    if not isinstance(contract, dict) or set(contract) != PARITY_CONTRACT_FIELDS:
        return False
    complete = dict(contract)
    complete["cleanup_proven"] = True
    return contract_shape_valid(complete)


def normalized_validation_error(
    payload: Any,
    *,
    expected_store: str | None = None,
    run_stamp: str | None = None,
) -> str | None:
    if not isinstance(payload, dict) or set(payload) != PROBE_FIELDS:
        return "normalized probe has an invalid field set"
    if payload.get("schema") != PROBE_SCHEMA:
        return "normalized probe has an invalid schema"
    store = payload.get("store")
    status = payload.get("status")
    if (
        not isinstance(store, str)
        or store not in OWNERS
        or not isinstance(status, str)
        or status not in STATUS_EXIT
    ):
        return "normalized probe has an invalid store or status"
    if not isinstance(payload.get("run_stamp"), str) or not isinstance(
        payload.get("runtime_owner"), str
    ):
        return "normalized probe has invalid binding types"
    if expected_store is not None and payload["store"] != expected_store:
        return "normalized probe has an invalid store binding"
    if run_stamp is not None and payload["run_stamp"] != run_stamp:
        return "normalized probe has an invalid run binding"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "normalized probe payload digest does not match"
    key = context_key()
    if key is None:
        return "normalized probe run context is unavailable"
    observed_hmac = payload.get("context_hmac")
    if not isinstance(observed_hmac, str) or not CONTEXT_HMAC_RE.fullmatch(
        observed_hmac
    ):
        return "normalized probe has an invalid context witness"
    expected_hmac = probe_context_hmac(payload, key)
    if not hmac.compare_digest(observed_hmac, expected_hmac):
        return "normalized probe context witness does not match"
    if not isinstance(payload.get("evidence_complete"), bool) or not is_string_list(
        payload.get("errors")
    ) or not is_string_list(payload.get("blockers")):
        return "normalized probe has malformed status facts"
    if not payload["evidence_complete"]:
        if (
            payload["status"] != "blocked"
            or payload["runtime_owner"]
            or payload["facts"] != {}
            or payload["contract"] != {}
            or payload["errors"]
            or not payload["blockers"]
        ):
            return "incomplete normalized probe has inconsistent semantics"
        return None
    if not facts_shape_valid(payload["facts"]) or not contract_shape_valid(payload["contract"]):
        return "normalized probe has malformed projected facts"
    contract, errors, blockers = evaluate_facts(
        payload["facts"], payload["store"], payload["runtime_owner"]
    )
    status = "fail" if errors else "blocked" if blockers else "pass"
    if (
        payload["contract"] != contract
        or payload["errors"] != errors
        or payload["blockers"] != blockers
        or payload["status"] != status
    ):
        return "normalized probe fails semantic recomputation"
    return None


def normalize_probe(args: argparse.Namespace) -> int:
    key = context_key()
    if key is None:
        print("MA-01 run context is unavailable.", file=sys.stderr)
        return 3
    candidates = extract_payloads(sys.stdin.read())
    base: dict[str, Any] = {
        "schema": PROBE_SCHEMA,
        "status": "blocked",
        "store": args.store,
        "run_stamp": args.run_stamp,
        "runtime_owner": "",
        "evidence_complete": False,
        "facts": {},
        "contract": {},
        "errors": [],
        "blockers": [],
    }
    if not candidates:
        base["blockers"] = ["probe produced no MA-01 payload."]
        return emit_probe(base, key, 3)
    if len(candidates) != 1:
        base["blockers"] = [
            f"Probe must emit exactly one MA-01 payload; observed {len(candidates)}."
        ]
        return emit_probe(base, key, 3)
    raw = candidates[0]
    shape_error = raw_shape_error(raw)
    if shape_error:
        base["blockers"] = [shape_error]
        return emit_probe(base, key, 3)
    runtime_owner = raw["runtime_owner"] if raw["runtime_owner"] in set(OWNERS.values()) else ""
    facts = project_facts(raw, args.store, args.run_stamp)
    contract, errors, blockers = evaluate_facts(facts, args.store, runtime_owner)
    status = "fail" if errors else "blocked" if blockers else "pass"
    base.update(
        {
            "status": status,
            "runtime_owner": runtime_owner,
            "evidence_complete": True,
            "facts": facts,
            "contract": contract,
            "errors": errors,
            "blockers": blockers,
        }
    )
    return emit_probe(base, key, STATUS_EXIT[status])


def read_json_object(path: Path) -> tuple[dict[str, Any], str | None]:
    safe_path, error = safe_existing_file(path)
    if error or safe_path is None:
        return {}, f"Evidence input {error or 'path is invalid'}"
    try:
        payload = json.loads(safe_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, ValueError):
        return {}, "Evidence input is not readable JSON"
    if not isinstance(payload, dict):
        return {}, "Evidence input is not a JSON object"
    return payload, None


def load_normalized_probe(
    path: Path, store: str, run_stamp: str
) -> tuple[dict[str, Any], str | None]:
    payload, error = read_json_object(path)
    if error:
        return {}, error
    error = normalized_validation_error(
        payload, expected_store=store, run_stamp=run_stamp
    )
    if error:
        return {}, f"{path.name}: {error}"
    return payload, None


def parity_contract(payload: dict[str, Any]) -> dict[str, Any]:
    contract = payload["contract"]
    return {
        "capabilities": contract["capabilities"],
        "routes": contract["routes"],
        "http": contract["http"],
    }


def make_comparison(
    reference: dict[str, Any] | None,
    target: dict[str, Any] | None,
    run_stamp: str,
    input_blockers: list[str],
) -> tuple[dict[str, Any], int]:
    base: dict[str, Any] = {
        "schema": COMPARISON_SCHEMA,
        "status": "blocked",
        "run_stamp": run_stamp,
        "inputs": {},
        "reference": {},
        "target": {},
        "errors": [],
        "blockers": list(input_blockers),
    }
    if reference is None or target is None:
        return base, 3
    base["inputs"] = {
        "ref_probe": reference["payload_sha256"],
        "target_probe": target["payload_sha256"],
    }
    if reference["status"] == "blocked":
        base["blockers"].append("Reference normalized probe is blocked.")
    if target["status"] == "blocked":
        base["blockers"].append("Target normalized probe is blocked.")
    if base["blockers"]:
        return base, 3
    base["reference"] = parity_contract(reference)
    base["target"] = parity_contract(target)
    if base["reference"] != base["target"]:
        base["status"] = "fail"
        base["errors"] = ["Target access-control outcome differs from reference."]
        return base, 1
    base["status"] = "pass"
    return base, 0


def compare(args: argparse.Namespace) -> int:
    blockers: list[str] = []
    reference, error = load_normalized_probe(
        Path(args.reference), "ref", args.run_stamp
    )
    if error:
        blockers.append(f"Reference input is invalid: {error}.")
        reference = None
    target, error = load_normalized_probe(Path(args.target), "target", args.run_stamp)
    if error:
        blockers.append(f"Target input is invalid: {error}.")
        target = None
    payload, exit_code = make_comparison(reference, target, args.run_stamp, blockers)
    return emit(payload, exit_code)


def comparison_validation_error(payload: Any, run_stamp: str) -> str | None:
    if not isinstance(payload, dict) or set(payload) != COMPARISON_FIELDS:
        return "comparison has an invalid field set"
    status = payload.get("status")
    if (
        not isinstance(payload.get("schema"), str)
        or payload.get("schema") != COMPARISON_SCHEMA
        or not isinstance(payload.get("run_stamp"), str)
        or payload.get("run_stamp") != run_stamp
        or not isinstance(status, str)
        or status not in STATUS_EXIT
    ):
        return "comparison has an invalid schema/run/status binding"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "comparison payload digest does not match"
    if not is_string_list(payload.get("errors")) or not is_string_list(payload.get("blockers")):
        return "comparison has malformed diagnostics"
    inputs = payload.get("inputs")
    if not isinstance(inputs, dict) or not set(inputs).issubset({"ref_probe", "target_probe"}):
        return "comparison has malformed input bindings"
    if any(
        not isinstance(value, str) or not DIGEST_RE.fullmatch(value)
        for value in inputs.values()
    ):
        return "comparison has malformed input digests"
    if status == "blocked":
        if payload["errors"] or not payload["blockers"]:
            return "blocked comparison has inconsistent diagnostics"
        if payload["reference"] != {} or payload["target"] != {}:
            return "blocked comparison exposes an untrusted outcome"
        return None
    if set(inputs) != {"ref_probe", "target_probe"}:
        return "completed comparison lacks input bindings"
    if not parity_contract_shape_valid(
        payload.get("reference")
    ) or not parity_contract_shape_valid(payload.get("target")):
        return "comparison has malformed access-control outcomes"
    differs = payload["reference"] != payload["target"]
    expected_errors = ["Target access-control outcome differs from reference."] if differs else []
    expected_status = "fail" if differs else "pass"
    if (
        payload["status"] != expected_status
        or payload["errors"] != expected_errors
        or payload["blockers"]
    ):
        return "comparison fails semantic recomputation"
    return None


def validate_comparison(args: argparse.Namespace) -> int:
    if context_key() is None:
        return 3
    try:
        payload = json.loads(sys.stdin.read())
    except (UnicodeError, ValueError):
        return 3
    error = comparison_validation_error(payload, args.run_stamp)
    if error or payload.get("status") != {0: "pass", 1: "fail", 3: "blocked"}.get(
        args.expected_exit_code
    ):
        return 3
    return 0


def expected_execution(
    store: str,
    probe_exit_code: int,
    comparison_exit_code: int | None,
    log_exit_code: int,
) -> tuple[str, int, list[str]]:
    operations: list[tuple[str, int]] = [("probe", probe_exit_code)]
    if store == "target" and comparison_exit_code is not None:
        operations.append(("comparison", comparison_exit_code))
    operations.append(("log_assertion", log_exit_code))
    failed = [(name, code) for name, code in operations if code == 1]
    if failed:
        return "fail", 1, sorted(f"{name}_failed" for name, _ in failed)
    blocked = [(name, code) for name, code in operations if code != 0]
    if blocked:
        return "blocked", 3, sorted(f"{name}_blocked" for name, _ in blocked)
    return "pass", 0, []


def execution_validation_error(payload: Any, run_stamp: str) -> str | None:
    if not isinstance(payload, dict) or set(payload) != EXECUTION_FIELDS:
        return "execution record has an invalid field set"
    store = payload.get("store")
    status = payload.get("status")
    if (
        not isinstance(payload.get("schema"), str)
        or payload.get("schema") != EXECUTION_SCHEMA
        or not isinstance(payload.get("run_stamp"), str)
        or payload.get("run_stamp") != run_stamp
        or not isinstance(store, str)
        or store not in OWNERS
        or not isinstance(status, str)
        or status not in STATUS_EXIT
    ):
        return "execution record has an invalid schema/run/store/status binding"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "execution record payload digest does not match"
    if not is_int(payload.get("exit_code")):
        return "execution record has an invalid final exit code type"
    if not is_int(payload.get("probe_exit_code")) or payload["probe_exit_code"] not in {0, 1, 3}:
        return "execution record has an invalid probe exit code"
    comparison_exit = payload.get("comparison_exit_code")
    if comparison_exit is not None and (
        not is_int(comparison_exit) or comparison_exit not in {0, 1, 3}
    ):
        return "execution record has an invalid comparison exit code"
    if not is_int(payload.get("log_assertion_exit_code")):
        return "execution record has an invalid log assertion exit code"
    if not isinstance(payload.get("probe_payload_sha256"), str) or not DIGEST_RE.fullmatch(
        payload["probe_payload_sha256"]
    ):
        return "execution record has an invalid probe digest"
    comparison_digest = payload.get("comparison_payload_sha256")
    if payload["store"] == "ref":
        if comparison_exit is not None or comparison_digest is not None:
            return "reference execution record contains a comparison binding"
    elif (
        comparison_exit is None
        or not isinstance(comparison_digest, str)
        or not DIGEST_RE.fullmatch(comparison_digest)
    ):
        return "target execution record lacks a comparison binding"
    sources = payload.get("verdict_sources")
    if not is_string_list(sources) or len(sources) != len(set(sources)):
        return "execution record has malformed verdict sources"
    expected_status, expected_exit, expected_sources = expected_execution(
        payload["store"],
        payload["probe_exit_code"],
        comparison_exit,
        payload["log_assertion_exit_code"],
    )
    if (
        payload["status"] != expected_status
        or payload.get("exit_code") != expected_exit
        or sources != expected_sources
        or not set(sources).issubset(VERDICT_SOURCES[expected_status])
    ):
        return "execution record has inconsistent verdict semantics"
    return None


def build_execution(args: argparse.Namespace) -> int:
    if not is_int(args.exit_code):
        print("Execution exit code must be an integer.", file=sys.stderr)
        return 3
    probe, error = load_normalized_probe(Path(args.probe), args.store, args.run_stamp)
    if error:
        print(error, file=sys.stderr)
        return 3
    if STATUS_EXIT[probe["status"]] != args.probe_exit_code:
        print("Probe exit code contradicts normalized evidence.", file=sys.stderr)
        return 3
    comparison: dict[str, Any] | None = None
    if args.store == "target":
        if args.comparison is None or args.comparison_exit_code is None:
            print("Target execution requires comparison evidence.", file=sys.stderr)
            return 3
        comparison, error = read_json_object(Path(args.comparison))
        if error or comparison_validation_error(comparison, args.run_stamp):
            print(error or "Comparison evidence is invalid.", file=sys.stderr)
            return 3
        if STATUS_EXIT[comparison["status"]] != args.comparison_exit_code:
            print("Comparison exit code contradicts comparison evidence.", file=sys.stderr)
            return 3
        if comparison.get("inputs", {}).get("target_probe") != probe["payload_sha256"]:
            print("Comparison does not bind the target probe.", file=sys.stderr)
            return 3
    elif args.comparison is not None or args.comparison_exit_code is not None:
        print("Reference execution must not bind comparison evidence.", file=sys.stderr)
        return 3
    payload = {
        "schema": EXECUTION_SCHEMA,
        "status": args.status,
        "store": args.store,
        "run_stamp": args.run_stamp,
        "exit_code": args.exit_code,
        "verdict_sources": sorted(args.verdict_source),
        "probe_exit_code": args.probe_exit_code,
        "probe_payload_sha256": probe["payload_sha256"],
        "comparison_exit_code": args.comparison_exit_code,
        "comparison_payload_sha256": comparison["payload_sha256"] if comparison else None,
        "log_assertion_exit_code": args.log_assertion_exit_code,
    }
    payload["payload_sha256"] = payload_digest(payload)
    error = execution_validation_error(payload, args.run_stamp)
    if error:
        print(error, file=sys.stderr)
        return 3
    output, path_error = safe_output_file(Path(args.output))
    if path_error or output is None:
        print(f"Execution output {path_error or 'path is invalid'}.", file=sys.stderr)
        return 3
    try:
        output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    except (OSError, RuntimeError):
        print("Execution output could not be written.", file=sys.stderr)
        return 3
    return 0


def allowed_files(store: str) -> set[str]:
    if store == "ref":
        return {"ref-probe.json", "ref-execution.json"}
    return {
        "ref-probe.json",
        "target-probe.json",
        "comparison.json",
        "target-execution.json",
    }


def load_bound_artifact(
    manifest_path: Path, filename: str, binding: Any
) -> tuple[dict[str, Any], str | None]:
    if filename not in allowed_files("target") | {"ref-execution.json"}:
        return {}, f"manifest artifact name is not allowed: {filename}"
    if not isinstance(binding, dict) or set(binding) != {"file_sha256", "payload_sha256"}:
        return {}, f"manifest binding is malformed: {filename}"
    artifact, path_error = safe_existing_file(manifest_path.parent / filename)
    if path_error or artifact is None:
        return {}, f"manifest artifact is not a regular file: {filename}"
    if artifact.parent != manifest_path.parent:
        return {}, f"manifest artifact escapes its evidence directory: {filename}"
    try:
        raw = artifact.read_bytes()
        payload = json.loads(raw.decode("utf-8"))
    except (OSError, UnicodeError, ValueError):
        return {}, f"manifest artifact is unreadable: {filename}"
    if not isinstance(payload, dict):
        return {}, f"manifest artifact is not an object: {filename}"
    if binding.get("file_sha256") != "sha256:" + hashlib.sha256(raw).hexdigest():
        return {}, f"manifest artifact byte digest does not match: {filename}"
    if binding.get("payload_sha256") != payload.get("payload_sha256"):
        return {}, f"manifest artifact payload binding does not match: {filename}"
    if payload.get("payload_sha256") != payload_digest(payload):
        return {}, f"manifest artifact payload digest does not match: {filename}"
    return payload, None


def chain_validation_error(
    payloads: dict[str, dict[str, Any]],
    store: str,
    run_stamp: str,
    status: str,
    exit_code: int,
    sources: list[str],
) -> str | None:
    if not is_int(exit_code):
        return "manifest final exit code has an invalid type"
    if set(payloads) != allowed_files(store):
        return "manifest does not bind the exact evidence allowlist"
    ref_probe = payloads.get("ref-probe.json")
    error = normalized_validation_error(ref_probe, expected_store="ref", run_stamp=run_stamp)
    if error:
        return f"ref-probe.json: {error}"
    target_probe: dict[str, Any] | None = None
    comparison: dict[str, Any] | None = None
    if store == "target":
        target_probe = payloads.get("target-probe.json")
        error = normalized_validation_error(
            target_probe, expected_store="target", run_stamp=run_stamp
        )
        if error:
            return f"target-probe.json: {error}"
        comparison = payloads.get("comparison.json")
        error = comparison_validation_error(comparison, run_stamp)
        if error:
            return f"comparison.json: {error}"
        recomputed, _ = make_comparison(ref_probe, target_probe, run_stamp, [])
        recomputed["payload_sha256"] = payload_digest(recomputed)
        if comparison != recomputed:
            return "comparison does not equal semantic recomputation of bound probes"
    execution = payloads.get(f"{store}-execution.json")
    error = execution_validation_error(execution, run_stamp)
    if error:
        return error
    own_probe = ref_probe if store == "ref" else target_probe
    if execution["probe_payload_sha256"] != own_probe["payload_sha256"]:
        return "execution record does not bind its normalized probe"
    if execution["probe_exit_code"] != STATUS_EXIT[own_probe["status"]]:
        return "execution probe exit code contradicts its bound normalized probe"
    if store == "target" and execution["comparison_payload_sha256"] != comparison[
        "payload_sha256"
    ]:
        return "execution record does not bind its comparison"
    if store == "target" and execution["comparison_exit_code"] != STATUS_EXIT[
        comparison["status"]
    ]:
        return "execution comparison exit code contradicts its bound comparison"
    if (
        execution["status"] != status
        or execution["exit_code"] != exit_code
        or execution["verdict_sources"] != sources
    ):
        return "manifest verdict contradicts its execution record"
    if status == "pass":
        if ref_probe["status"] != "pass":
            return "passing manifest binds a non-passing reference probe"
        if store == "target" and (
            target_probe["status"] != "pass" or comparison["status"] != "pass"
        ):
            return "passing manifest binds non-passing target evidence"
    return None


def verdict_sources_valid(status: str, sources: Any) -> bool:
    return (
        is_string_list(sources)
        and len(sources) == len(set(sources))
        and sources == sorted(sources)
        and set(sources).issubset(VERDICT_SOURCES[status])
        and ((status == "pass" and not sources) or (status != "pass" and bool(sources)))
    )


def build_manifest(args: argparse.Namespace) -> int:
    if not is_int(args.exit_code) or args.exit_code != STATUS_EXIT[args.status]:
        print("Manifest status and exit code are inconsistent.", file=sys.stderr)
        return 3
    key = context_key()
    if key is None:
        print("Manifest context key is missing or invalid.", file=sys.stderr)
        return 3
    sources = sorted(args.verdict_source)
    if not verdict_sources_valid(args.status, sources):
        print("Manifest verdict sources are invalid.", file=sys.stderr)
        return 3
    output, path_error = safe_output_file(Path(args.output))
    if path_error or output is None:
        print(f"Manifest output {path_error or 'path is invalid'}.", file=sys.stderr)
        return 3
    output_parent = output.parent
    bindings: dict[str, dict[str, str]] = {}
    payloads: dict[str, dict[str, Any]] = {}
    try:
        for raw_path in args.file:
            path, evidence_path_error = safe_existing_file(Path(raw_path))
            if evidence_path_error or path is None:
                raise ValueError("Manifest evidence path is unsafe.")
            if (
                path.parent != output_parent
                or path.name not in allowed_files(args.store)
                or path.name in bindings
            ):
                raise ValueError("Manifest evidence path is invalid.")
            payload = json.loads(path.read_text(encoding="utf-8"))
            if not isinstance(payload, dict) or payload.get("payload_sha256") != payload_digest(
                payload
            ):
                raise ValueError(f"Evidence payload digest is invalid: {path.name}")
            bindings[path.name] = {
                "file_sha256": file_digest(path),
                "payload_sha256": payload["payload_sha256"],
            }
            payloads[path.name] = payload
    except (OSError, RuntimeError, UnicodeError, ValueError):
        print("Manifest evidence is invalid or unreadable.", file=sys.stderr)
        return 3
    error = chain_validation_error(
        payloads, args.store, args.run_stamp, args.status, args.exit_code, sources
    )
    if error:
        print(error, file=sys.stderr)
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
    manifest["context_hmac"] = manifest_context_hmac(manifest, key)
    manifest["payload_sha256"] = payload_digest(manifest)
    try:
        output.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    except (OSError, RuntimeError):
        print("Manifest output could not be written.", file=sys.stderr)
        return 3
    return 0


def validate_bound_manifest(args: argparse.Namespace) -> int:
    def refuse(reason: str) -> int:
        print(reason)
        return 3

    path, path_error = safe_existing_file(Path(args.manifest))
    if path_error or path is None:
        if path_error == "path contains a symlinked directory component":
            return refuse(
                "MA-01 evidence manifest path contains a symlinked directory component."
            )
        return refuse("MA-01 evidence manifest path is unsafe or unavailable.")
    try:
        raw = path.read_bytes()
        manifest = json.loads(raw.decode("utf-8"))
    except (OSError, RuntimeError, UnicodeError, ValueError):
        return refuse("MA-01 evidence manifest is unreadable.")
    if not isinstance(manifest, dict) or set(manifest) != MANIFEST_FIELDS:
        return refuse("MA-01 evidence manifest has an invalid field set.")
    if (
        not is_int(manifest.get("exit_code"))
        or manifest.get("schema") != MANIFEST_SCHEMA
        or manifest.get("flow") != FLOW
        or manifest.get("run_stamp") != args.run_stamp
        or manifest.get("run_scope") != args.run_scope
        or manifest.get("store") != args.store
        or manifest.get("status") != args.expected_status
        or manifest.get("exit_code") != args.expected_exit_code
        or manifest.get("exit_code") != STATUS_EXIT[args.expected_status]
        or manifest.get("payload_sha256") != payload_digest(manifest)
    ):
        return refuse("MA-01 evidence manifest has an invalid run/verdict binding.")
    key = context_key()
    observed_hmac = manifest.get("context_hmac")
    if (
        key is None
        or not isinstance(observed_hmac, str)
        or not CONTEXT_HMAC_RE.fullmatch(observed_hmac)
        or not hmac.compare_digest(observed_hmac, manifest_context_hmac(manifest, key))
    ):
        return refuse("MA-01 evidence manifest has an invalid context witness.")
    sources = manifest.get("verdict_sources")
    if not verdict_sources_valid(args.expected_status, sources):
        return refuse("MA-01 evidence manifest has invalid verdict sources.")
    files = manifest.get("files")
    if not isinstance(files, dict) or set(files) != allowed_files(args.store):
        return refuse("MA-01 evidence manifest has an invalid artifact allowlist.")
    payloads: dict[str, dict[str, Any]] = {}
    for filename, binding in files.items():
        payload, error = load_bound_artifact(path, filename, binding)
        if error:
            return refuse(error)
        payloads[filename] = payload
    error = chain_validation_error(
        payloads,
        args.store,
        args.run_stamp,
        args.expected_status,
        args.expected_exit_code,
        sources,
    )
    if error:
        return refuse(error)
    print("sha256:" + hashlib.sha256(raw).hexdigest())
    return 0


def parser() -> argparse.ArgumentParser:
    root = argparse.ArgumentParser()
    commands = root.add_subparsers(dest="command", required=True)

    normalize = commands.add_parser("normalize-probe")
    normalize.add_argument("--store", choices=("ref", "target"), required=True)
    normalize.add_argument("--run-stamp", required=True)
    normalize.set_defaults(handler=normalize_probe)

    comparison = commands.add_parser("compare")
    comparison.add_argument("--reference", required=True)
    comparison.add_argument("--target", required=True)
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
    execution.add_argument("--probe", required=True)
    execution.add_argument("--probe-exit-code", type=int, choices=(0, 1, 3), required=True)
    execution.add_argument("--comparison")
    execution.add_argument("--comparison-exit-code", type=int, choices=(0, 1, 3))
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
