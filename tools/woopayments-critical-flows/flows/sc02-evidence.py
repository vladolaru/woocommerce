#!/usr/bin/env python3
"""Normalize and authenticate deterministic SC-02 checkout evidence."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import importlib.util
import json
import os
import re
import sys
import tempfile
from pathlib import Path
from typing import Any


RAW_STATE_SCHEMA = "woopayments_sc02_state_raw.v1"
STATE_SCHEMA = "woopayments_sc02_state.v1"
KEY_ENV = "CRITICAL_FLOWS_RUN_CONTEXT_KEY"
KEY_RE = re.compile(r"[0-9a-f]{64}")
STATUS_EXIT = {"pass": 0, "fail": 1, "blocked": 3}
DOMAINS = {
    "state": b"woopayments-sc02-state-v1\0",
    "http": b"woopayments-sc02-http-v1\0",
    "result": b"woopayments-sc02-result-v1\0",
    "comparison": b"woopayments-sc02-comparison-v1\0",
    "log": b"woopayments-sc02-log-v1\0",
    "execution": b"woopayments-sc02-execution-v1\0",
    "manifest": b"woopayments-sc02-manifest-v1\0",
}
MAX_JSON_BYTES = 1024 * 1024
MAX_LINE_ITEMS = 20

RAW_PREFLIGHT_FIELDS = {
    "schema",
    "phase",
    "store",
    "run_stamp",
    "runtime_owner",
    "gateway",
    "product",
    "store_currency",
    "connected_account_id",
    "blockers",
}
GATEWAY_FIELDS = {
    "id",
    "class",
    "available",
    "test_mode",
    "connected",
}
PRODUCT_FIELDS = {
    "id",
    "sku",
    "price",
    "purchasable",
    "in_stock",
}
RAW_POST_FIELDS = {
    "schema",
    "phase",
    "store",
    "run_stamp",
    "runtime_owner",
    "order",
    "provider",
    "blockers",
}
ORDER_FIELDS = {
    "id",
    "status",
    "created_via",
    "payment_method",
    "customer_id",
    "customer_note",
    "total",
    "currency",
    "date_paid_present",
    "transaction_id",
    "intent_id",
    "charge_id",
    "line_items",
}
LINE_ITEM_FIELDS = {"product_id", "sku", "quantity", "total"}
PROVIDER_FIELDS = {"intent", "charge"}
INTENT_FIELDS = {
    "id",
    "object",
    "status",
    "amount",
    "currency",
    "latest_charge",
    "payment_method",
}
CHARGE_FIELDS = {
    "id",
    "object",
    "status",
    "paid",
    "amount",
    "amount_captured",
    "currency",
    "payment_intent",
    "payment_method",
}
INTENT_RE = re.compile(r"pi_[A-Za-z0-9_]+")
CHARGE_RE = re.compile(r"ch_[A-Za-z0-9_]+")
PAYMENT_METHOD_RE = re.compile(r"pm_[A-Za-z0-9_]+")
HTTP_SCHEMA = "woopayments_sc02_http.v1"
HTTP_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "product_id",
    "order_id",
    "cart_token_fingerprint",
    "origin_fingerprint",
    "request_sequence",
    "cart_token_lineage",
    "local_origin",
    "redirect_count",
    "cart",
    "checkout",
    "errors",
    "blockers",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
HTTP_CART_FIELDS = {
    "started_empty",
    "item_count",
    "product_id",
    "sku",
    "quantity",
    "selected_shipping_rate_count",
    "selected_shipping_rate_cost",
    "total_price",
    "currency_code",
    "currency_minor_unit",
    "payment_methods",
}
HTTP_CHECKOUT_FIELDS = {
    "http_status",
    "payment_method",
    "payment_data_keys",
    "payment_status",
    "order_status",
    "structured_error",
}
EXPECTED_REQUEST_SEQUENCE = [
    "GET /wc/store/v1/cart",
    "POST /wc/store/v1/cart/add-item",
    "POST /wc/store/v1/cart/update-customer",
    "POST /wc/store/v1/cart/select-shipping-rate",
    "GET /wc/store/v1/cart",
    "POST /wc/store/v1/checkout",
]
STATE_FIELDS = {
    "schema",
    "status",
    "phase",
    "store",
    "run_stamp",
    "runtime_owner",
    "evidence_complete",
    "facts",
    "errors",
    "blockers",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
RESULT_SCHEMA = "woopayments_sc02_result.v1"
RESULT_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "runtime_owner",
    "inputs",
    "contract",
    "identities",
    "errors",
    "blockers",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
RESULT_INPUT_FIELDS = {"preflight", "http", "post"}
RESULT_CONTRACT_FIELDS = {
    "created_via_store_api",
    "guest_customer",
    "gateway",
    "fixture_product",
    "amount_currency",
    "paid",
    "provider_success",
    "provider_linkage",
}
RESULT_IDENTITY_FIELDS = {
    "product_id",
    "order_id",
    "cart_token_fingerprint",
    "origin_fingerprint",
    "intent_id",
    "charge_id",
    "payment_method_id",
}
COMPARISON_SCHEMA = "woopayments_sc02_comparison.v1"
COMPARISON_FIELDS = {
    "schema",
    "status",
    "run_stamp",
    "inputs",
    "reference",
    "target",
    "parity",
    "errors",
    "blockers",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
COMPARISON_INPUT_FIELDS = {
    "reference_result",
    "target_result",
    "reference_log",
    "target_log",
}
COMPARISON_STORE_FIELDS = {
    "status",
    "runtime_owner",
    "contract",
    "diagnostic_status",
}
COMPARISON_PARITY_FIELDS = RESULT_CONTRACT_FIELDS | {"clean_diagnostics"}
LOG_SCHEMA = "woopayments_sc02_log_scan.v1"
LOG_FIELDS = {
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
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
FLOW = "SC-02-blocks-card-checkout"
LOG_PURPOSE = "clean-debug-log"
EXECUTION_SCHEMA = "woopayments_sc02_execution.v1"
EXECUTION_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "stage",
    "exit_code",
    "verdict_sources",
    "preflight_exit_code",
    "http_exit_code",
    "post_exit_code",
    "result_exit_code",
    "comparison_exit_code",
    "log_exit_code",
    "result_payload_sha256",
    "comparison_payload_sha256",
    "log_payload_sha256",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
VERDICT_SOURCE_CODES = {
    "preflight_blocked": ("preflight", 3),
    "http_failed": ("http", 1),
    "http_blocked": ("http", 3),
    "post_failed": ("post", 1),
    "post_blocked": ("post", 3),
    "result_failed": ("result", 1),
    "result_blocked": ("result", 3),
    "comparison_failed": ("comparison", 1),
    "comparison_blocked": ("comparison", 3),
    "log_assertion_failed": ("log", 1),
    "log_assertion_blocked": ("log", 3),
    "evidence_contract_blocked": ("result", 3),
}
MANIFEST_SCHEMA = "woopayments_sc02_manifest.v1"
MANIFEST_FIELDS = {
    "schema",
    "flow",
    "run_stamp",
    "run_scope",
    "store",
    "status",
    "exit_code",
    "stage",
    "verdict_sources",
    "files",
    "key_fingerprint",
    "context_hmac",
    "payload_sha256",
}
FILE_BINDING_FIELDS = {"file_sha256", "payload_sha256"}


def canonical(value: Any) -> bytes:
    """Encode a value using the harness's canonical JSON representation."""
    return json.dumps(
        value,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")


def context_key() -> bytes | None:
    """Return the private per-run context key when it is well formed."""
    raw = os.environ.get(KEY_ENV, "")
    return bytes.fromhex(raw) if KEY_RE.fullmatch(raw) else None


def payload_digest(payload: dict[str, Any]) -> str:
    """Digest a payload without its self-referential digest field."""
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def file_digest(path: Path) -> str:
    """Return the SHA-256 binding for exact archived bytes."""
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def context_hmac(kind: str, payload: dict[str, Any], key: bytes) -> str:
    """Authenticate semantic evidence in a command-specific domain."""
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    unsigned.pop("context_hmac", None)
    digest = hmac.new(key, DOMAINS[kind] + canonical(unsigned), hashlib.sha256)
    return "hmac-sha256:" + digest.hexdigest()


def seal(kind: str, payload: dict[str, Any], key: bytes) -> dict[str, Any]:
    """Add authenticated and plain-digest bindings to evidence."""
    sealed = dict(payload)
    sealed["key_fingerprint"] = "sha256:" + hashlib.sha256(key).hexdigest()
    sealed["context_hmac"] = context_hmac(kind, sealed, key)
    sealed["payload_sha256"] = payload_digest(sealed)
    return sealed


def load_unique_json(raw: str) -> Any:
    """Parse one JSON value while rejecting duplicate object keys."""

    def reject_duplicate(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for name, value in pairs:
            if name in result:
                raise ValueError("duplicate JSON key")
            result[name] = value
        return result

    return json.loads(raw, object_pairs_hook=reject_duplicate)


def is_int(value: Any) -> bool:
    """Return whether a value is an integer but not a boolean."""
    return isinstance(value, int) and not isinstance(value, bool)


def is_nonempty_string(value: Any) -> bool:
    """Return whether a value is a non-empty string."""
    return isinstance(value, str) and bool(value)


def is_string_list(value: Any) -> bool:
    """Return whether a value is a list of non-empty strings."""
    return isinstance(value, list) and all(is_nonempty_string(item) for item in value)


def safe_input_file(path: Path) -> tuple[Path | None, str | None]:
    """Reject lexical traversal, symlinks, and non-regular input files."""
    if ".." in path.parts:
        return None, "path contains parent traversal"
    try:
        absolute = Path(os.path.abspath(os.fspath(path)))
        if absolute.is_symlink() or not absolute.is_file():
            return None, "path is not a regular non-symlinked file"
        temp_root = Path(os.path.abspath(tempfile.gettempdir()))
        parents_to_check: list[Path] = []
        for parent in (absolute.parent, *absolute.parent.parents):
            parents_to_check.append(parent)
            if parent == temp_root:
                break
        for parent in parents_to_check:
            if parent.is_symlink():
                return None, "path contains a symlinked directory component"
        if absolute.stat().st_size > MAX_JSON_BYTES:
            return None, "file exceeded the evidence size limit"
    except (OSError, RuntimeError, TypeError, ValueError):
        return None, "path is unavailable"
    return absolute, None


def load_strict_file(path: Path) -> tuple[Any | None, str | None]:
    """Read one bounded, duplicate-key-free JSON file."""
    safe, error = safe_input_file(path)
    if error or safe is None:
        return None, error
    try:
        raw = safe.read_bytes()
        payload = load_unique_json(raw.decode("utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError, ValueError):
        return None, "file was not one strict JSON value"
    return payload, None


def safe_output_path(path: Path) -> tuple[Path | None, str | None]:
    """Validate a new evidence output without following user-controlled links."""
    if ".." in path.parts:
        return None, "output path contains parent traversal"
    try:
        absolute = Path(os.path.abspath(os.fspath(path)))
        if absolute.exists() or absolute.is_symlink():
            return None, "output path already exists"
        parent = absolute.parent
        if not parent.is_dir() or parent.is_symlink():
            return None, "output parent is unavailable or symlinked"
        temp_root = Path(os.path.abspath(tempfile.gettempdir()))
        for ancestor in (parent, *parent.parents):
            if ancestor.is_symlink():
                return None, "output path contains a symlinked directory component"
            if ancestor == temp_root:
                break
    except (OSError, RuntimeError, TypeError, ValueError):
        return None, "output path is unavailable"
    return absolute, None


def write_sealed_payload(
    kind: str,
    payload: dict[str, Any],
    output: Path,
    key: bytes,
) -> int:
    """Seal and atomically create one evidence file."""
    safe, error = safe_output_path(output)
    if error or safe is None:
        print(f"SC-02 evidence output was blocked: {error}.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    sealed = seal(kind, payload, key)
    temporary_name = ""
    try:
        descriptor, temporary_name = tempfile.mkstemp(
            prefix=f".{safe.name}.", suffix=".tmp", dir=safe.parent
        )
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            json.dump(sealed, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_name, safe)
    except (OSError, UnicodeError, ValueError):
        if temporary_name:
            try:
                os.unlink(temporary_name)
            except OSError:
                pass
        print("SC-02 evidence output could not be written.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    return STATUS_EXIT[sealed["status"]]


def sealed_payload_error(
    kind: str,
    payload: Any,
    fields: set[str],
    key: bytes,
) -> str | None:
    """Validate exact fields plus the current run's digest and HMAC."""
    if not isinstance(payload, dict) or set(payload) != fields:
        return "evidence had an invalid field set"
    expected_fingerprint = "sha256:" + hashlib.sha256(key).hexdigest()
    if payload.get("key_fingerprint") != expected_fingerprint:
        return "evidence key fingerprint did not match"
    if payload.get("payload_sha256") != payload_digest(payload):
        return "evidence payload digest did not match"
    expected_hmac = context_hmac(kind, payload, key)
    actual_hmac = payload.get("context_hmac")
    if not isinstance(actual_hmac, str) or not hmac.compare_digest(
        actual_hmac, expected_hmac
    ):
        return "evidence context HMAC did not match"
    return None


def http_derived_verdict(payload: dict[str, Any]) -> tuple[str, list[str], list[str]]:
    """Recompute SC-02's HTTP business and provenance assertions."""
    errors: list[str] = []
    blockers: list[str] = []
    cart = payload["cart"]
    checkout = payload["checkout"]

    if payload["request_sequence"] != EXPECTED_REQUEST_SEQUENCE:
        blockers.append("Store API request sequence was incomplete or unexpected.")
    if payload["cart_token_lineage"] is not True:
        blockers.append("Cart Token lineage was incomplete.")
    if payload["local_origin"] is not True:
        blockers.append("Store API request origin was not local and stable.")
    if payload["redirect_count"] != 0:
        blockers.append("Store API endpoint redirected unexpectedly.")

    if cart["started_empty"] is not True:
        errors.append("Cart was not empty before the fixed fixture was added.")
    if (
        cart["item_count"] != 1
        or cart["product_id"] != payload["product_id"]
        or cart["sku"] != "test-lab-beaker-001"
        or cart["quantity"] != 1
    ):
        errors.append("Cart did not contain exactly one fixed fixture product.")
    if (
        cart["selected_shipping_rate_count"] != 1
        or cart["selected_shipping_rate_cost"] != "0"
    ):
        errors.append("Cart did not have exactly one selected free shipping rate.")
    if (
        cart["total_price"] != "2500"
        or cart["currency_code"] != "USD"
        or cart["currency_minor_unit"] != 2
    ):
        errors.append("Cart total did not match USD 25.00.")
    if "woocommerce_payments" not in cart["payment_methods"]:
        errors.append("WooPayments was unavailable through the Store API.")
    if checkout["payment_method"] != "woocommerce_payments":
        errors.append("Checkout did not submit the WooPayments gateway.")
    if checkout["payment_data_keys"] != ["wcpay-payment-method"]:
        errors.append("Checkout payment data did not use the shared WooPayments field.")

    if checkout["http_status"] == 200:
        if not is_int(payload["order_id"]) or payload["order_id"] <= 0:
            blockers.append("Checkout response order identity was unavailable.")
        if checkout["payment_status"] != "success":
            errors.append("Checkout payment did not report success.")
        if checkout["order_status"] not in {"processing", "completed"}:
            errors.append("Checkout response did not report a paid order status.")
        if checkout["structured_error"] is not False:
            blockers.append("Checkout response semantics were contradictory.")
    elif checkout["structured_error"] is True and 400 <= checkout["http_status"] < 500:
        errors.append("Checkout returned a structured payment rejection.")
    else:
        blockers.append("Checkout result was transport-ambiguous.")

    blockers = list(dict.fromkeys(blockers))
    errors = list(dict.fromkeys(errors))
    status = "blocked" if blockers else "fail" if errors else "pass"
    return status, errors, blockers


def http_validation_error(
    payload: Any,
    *,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate and independently recompute a signed HTTP transcript."""
    error = sealed_payload_error("http", payload, HTTP_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    if (
        payload["schema"] != HTTP_SCHEMA
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
    ):
        return "HTTP evidence had an invalid schema or run binding"
    if payload["status"] not in STATUS_EXIT:
        return "HTTP evidence had an invalid status"
    if not is_int(payload["product_id"]) or payload["product_id"] <= 0:
        return "HTTP evidence had an invalid product identity"
    if not is_int(payload["order_id"]) or payload["order_id"] < 0:
        return "HTTP evidence had an invalid order identity"
    if any(
        not isinstance(payload[field], str)
        or re.fullmatch(r"hmac-sha256:[0-9a-f]{64}", payload[field]) is None
        for field in ("cart_token_fingerprint", "origin_fingerprint")
    ):
        return "HTTP evidence had malformed transport identities"
    if (
        not is_string_list(payload["request_sequence"])
        or not isinstance(payload["cart_token_lineage"], bool)
        or not isinstance(payload["local_origin"], bool)
        or not is_int(payload["redirect_count"])
        or payload["redirect_count"] < 0
        or not is_string_list(payload["errors"])
        or not is_string_list(payload["blockers"])
    ):
        return "HTTP evidence had malformed scalar fields"
    cart = payload["cart"]
    checkout = payload["checkout"]
    if not isinstance(cart, dict) or set(cart) != HTTP_CART_FIELDS:
        return "HTTP cart evidence had an invalid field set"
    if not isinstance(checkout, dict) or set(checkout) != HTTP_CHECKOUT_FIELDS:
        return "HTTP checkout evidence had an invalid field set"
    if (
        not isinstance(cart["started_empty"], bool)
        or not is_int(cart["item_count"])
        or not is_int(cart["product_id"])
        or not isinstance(cart["sku"], str)
        or not is_int(cart["quantity"])
        or not is_int(cart["selected_shipping_rate_count"])
        or not isinstance(cart["selected_shipping_rate_cost"], str)
        or not isinstance(cart["total_price"], str)
        or not isinstance(cart["currency_code"], str)
        or not is_int(cart["currency_minor_unit"])
        or not is_string_list(cart["payment_methods"])
    ):
        return "HTTP cart evidence had malformed values"
    if (
        not is_int(checkout["http_status"])
        or not isinstance(checkout["payment_method"], str)
        or not is_string_list(checkout["payment_data_keys"])
        or not isinstance(checkout["payment_status"], str)
        or not isinstance(checkout["order_status"], str)
        or not isinstance(checkout["structured_error"], bool)
    ):
        return "HTTP checkout evidence had malformed values"
    expected_status, expected_errors, expected_blockers = http_derived_verdict(payload)
    if (
        payload["status"] != expected_status
        or payload["errors"] != expected_errors
        or payload["blockers"] != expected_blockers
    ):
        return "HTTP verdict was not reproducible from its facts"
    return None


def state_validation_error(
    payload: Any,
    *,
    phase: str,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate one normalized WordPress state artifact."""
    error = sealed_payload_error("state", payload, STATE_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        payload["schema"] != STATE_SCHEMA
        or payload["phase"] != phase
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
        or payload["runtime_owner"] != expected_owner
    ):
        return "state evidence had an invalid schema or run binding"
    if (
        payload["status"] not in STATUS_EXIT
        or not isinstance(payload["evidence_complete"], bool)
        or not isinstance(payload["facts"], dict)
        or not is_string_list(payload["errors"])
        or not is_string_list(payload["blockers"])
    ):
        return "state evidence had malformed values"
    expected_status = (
        "blocked"
        if payload["blockers"]
        else "fail"
        if payload["errors"]
        else "pass"
    )
    if payload["status"] != expected_status:
        return "state verdict was not reproducible"
    if payload["evidence_complete"] != (not payload["blockers"]):
        return "state completeness was contradictory"
    if phase == "preflight":
        allowed_fields = {
            "gateway",
            "product",
            "store_currency",
            "connected_account_id",
        }
        if set(payload["facts"]) not in (set(), allowed_fields):
            return "preflight facts had an invalid field set"
    elif set(payload["facts"]) not in (set(), {"order", "provider"}):
        return "post facts had an invalid field set"
    return None


def evaluate_store_payload(
    preflight: dict[str, Any],
    http: dict[str, Any] | None,
    post: dict[str, Any] | None,
    *,
    store: str,
    run_stamp: str,
) -> dict[str, Any]:
    """Recompute one store's public checkout contract from bound evidence."""
    expected_owner = "plugin" if store == "ref" else "native"
    inputs = {
        "preflight": preflight["payload_sha256"],
        "http": http["payload_sha256"] if http is not None else None,
        "post": post["payload_sha256"] if post is not None else None,
    }
    contract = {name: False for name in RESULT_CONTRACT_FIELDS}
    pre_product = preflight["facts"].get("product", {})
    product_id = (
        http["product_id"]
        if http is not None
        else pre_product.get("id", 0)
        if is_int(pre_product.get("id"))
        else 0
    )
    identities: dict[str, Any] = {
        "product_id": product_id,
        "order_id": http["order_id"] if http is not None else 0,
        "cart_token_fingerprint": (
            http["cart_token_fingerprint"] if http is not None else ""
        ),
        "origin_fingerprint": http["origin_fingerprint"] if http is not None else "",
        "intent_id": "",
        "charge_id": "",
        "payment_method_id": "",
    }
    errors: list[str] = []
    blockers: list[str] = []

    if preflight["status"] != "pass":
        blockers.extend(preflight["blockers"] or ["Preflight prerequisites did not pass."])
    if http is None:
        if preflight["status"] == "pass":
            blockers.append("Store API evidence was unavailable.")
        return {
            "schema": RESULT_SCHEMA,
            "status": "blocked",
            "store": store,
            "run_stamp": run_stamp,
            "runtime_owner": expected_owner,
            "inputs": inputs,
            "contract": contract,
            "identities": identities,
            "errors": [],
            "blockers": list(dict.fromkeys(blockers)),
        }
    if http["status"] == "blocked":
        blockers.extend(http["blockers"] or ["Store API evidence was blocked."])
    elif http["status"] == "fail":
        errors.extend(http["errors"] or ["Store API checkout failed."])

    if pre_product.get("id") != http["product_id"]:
        blockers.append("Preflight and HTTP product identities did not match.")

    if http["status"] == "pass":
        if post is None:
            blockers.append("Post-checkout evidence was unavailable.")
        else:
            if post["status"] == "blocked":
                blockers.extend(post["blockers"] or ["Post-checkout evidence was blocked."])
            elif post["status"] == "fail":
                errors.extend(post["errors"] or ["Post-checkout contract failed."])
            if set(post["facts"]) != {"order", "provider"}:
                blockers.append("Post-checkout facts were unavailable.")
                post = None

        if post is not None:
            order = post["facts"]["order"]
            provider = post["facts"]["provider"]
            intent = provider["intent"]
            charge = provider["charge"]
            line_items = order["line_items"]
            line_item = line_items[0] if len(line_items) == 1 else None
            identities.update(
                {
                    "order_id": order["id"],
                    "intent_id": order["intent_id"],
                    "charge_id": order["charge_id"],
                    "payment_method_id": intent["payment_method"],
                }
            )
            if order["id"] != http["order_id"]:
                blockers.append("Checkout and collected order identities did not match.")
            if line_item is not None and line_item["product_id"] != http["product_id"]:
                blockers.append("HTTP and collected product identities did not match.")
            contract.update(
                {
                    "created_via_store_api": order["created_via"] == "store-api",
                    "guest_customer": order["customer_id"] == 0,
                    "gateway": order["payment_method"] == "woocommerce_payments",
                    "fixture_product": (
                        line_item is not None
                        and line_item["sku"] == "test-lab-beaker-001"
                        and line_item["quantity"] == 1
                    ),
                    "amount_currency": (
                        order["total"] == "25.00"
                        and order["currency"] == "USD"
                        and intent["amount"] == 2500
                        and intent["currency"] == "usd"
                        and charge["amount"] == 2500
                        and charge["amount_captured"] == 2500
                        and charge["currency"] == "usd"
                    ),
                    "paid": (
                        order["status"] in {"processing", "completed"}
                        and order["date_paid_present"] is True
                    ),
                    "provider_success": (
                        intent["status"] == "succeeded"
                        and charge["status"] == "succeeded"
                        and charge["paid"] is True
                    ),
                    "provider_linkage": (
                        order["transaction_id"] == order["intent_id"] == intent["id"]
                        and order["charge_id"]
                        == intent["latest_charge"]
                        == charge["id"]
                        and charge["payment_intent"] == intent["id"]
                        and charge["payment_method"] == intent["payment_method"]
                    ),
                }
            )

    blockers = list(dict.fromkeys(blockers))
    errors = list(dict.fromkeys(errors))
    status = "blocked" if blockers else "fail" if errors else "pass"
    return {
        "schema": RESULT_SCHEMA,
        "status": status,
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": expected_owner,
        "inputs": inputs,
        "contract": contract,
        "identities": identities,
        "errors": errors,
        "blockers": blockers,
    }


def result_validation_error(
    payload: Any,
    *,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate one authenticated store-level result."""
    error = sealed_payload_error("result", payload, RESULT_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        payload["schema"] != RESULT_SCHEMA
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
        or payload["runtime_owner"] != expected_owner
    ):
        return "store result had an invalid schema or run binding"
    if (
        payload["status"] not in STATUS_EXIT
        or not isinstance(payload["inputs"], dict)
        or set(payload["inputs"]) != RESULT_INPUT_FIELDS
        or not isinstance(payload["contract"], dict)
        or set(payload["contract"]) != RESULT_CONTRACT_FIELDS
        or not all(isinstance(value, bool) for value in payload["contract"].values())
        or not isinstance(payload["identities"], dict)
        or set(payload["identities"]) != RESULT_IDENTITY_FIELDS
        or not is_string_list(payload["errors"])
        or not is_string_list(payload["blockers"])
    ):
        return "store result had malformed values"
    for digest in payload["inputs"].values():
        if digest is not None and not (
            isinstance(digest, str) and re.fullmatch(r"sha256:[0-9a-f]{64}", digest)
        ):
            return "store result had a malformed input digest"
    identities = payload["identities"]
    if (
        not is_int(identities["product_id"])
        or not is_int(identities["order_id"])
        or not all(
            isinstance(identities[name], str)
            for name in (
                "cart_token_fingerprint",
                "origin_fingerprint",
                "intent_id",
                "charge_id",
                "payment_method_id",
            )
        )
    ):
        return "store result had malformed identities"
    expected_status = (
        "blocked"
        if payload["blockers"]
        else "fail"
        if payload["errors"]
        else "pass"
    )
    if payload["status"] != expected_status:
        return "store result verdict was not reproducible"
    if payload["status"] == "pass" and not all(payload["contract"].values()):
        return "passing store result had an incomplete contract"
    return None


def compare_payloads(
    reference: dict[str, Any],
    target: dict[str, Any],
    reference_log: dict[str, Any],
    target_log: dict[str, Any],
    *,
    run_stamp: str,
) -> dict[str, Any]:
    """Compare reference and target public contract semantics."""
    parity = {
        name: reference["contract"][name] == target["contract"][name]
        for name in sorted(RESULT_CONTRACT_FIELDS)
    }
    parity["clean_diagnostics"] = (
        reference_log["status"] == target_log["status"] == "pass"
    )
    errors: list[str] = []
    blockers: list[str] = []
    reference_ids = reference["identities"]
    target_ids = target["identities"]

    for field, label in (
        ("cart_token_fingerprint", "a Cart Token lineage"),
        ("origin_fingerprint", "a store origin"),
        ("order_id", "an order identity"),
        ("intent_id", "a PaymentIntent identity"),
        ("charge_id", "a Charge identity"),
    ):
        reference_value = reference_ids[field]
        target_value = target_ids[field]
        if reference_value and reference_value == target_value:
            blockers.append(f"Reference and target reused {label}.")

    if (
        reference["status"] == "blocked"
        or target["status"] == "blocked"
        or reference_log["status"] == "blocked"
        or target_log["status"] == "blocked"
    ):
        blockers.append("At least one store result or diagnostic was blocked.")
    else:
        if reference["status"] != target["status"] or not all(parity.values()):
            errors.append("Store contracts did not have parity.")
        elif reference["status"] != "pass" or reference_log["status"] != "pass":
            errors.append("Both store checkouts did not pass the required contract.")

    blockers = list(dict.fromkeys(blockers))
    errors = list(dict.fromkeys(errors))
    status = "blocked" if blockers else "fail" if errors else "pass"
    return {
        "schema": COMPARISON_SCHEMA,
        "status": status,
        "run_stamp": run_stamp,
        "inputs": {
            "reference_result": reference["payload_sha256"],
            "target_result": target["payload_sha256"],
            "reference_log": reference_log["payload_sha256"],
            "target_log": target_log["payload_sha256"],
        },
        "reference": {
            "status": reference["status"],
            "runtime_owner": reference["runtime_owner"],
            "contract": reference["contract"],
            "diagnostic_status": reference_log["status"],
        },
        "target": {
            "status": target["status"],
            "runtime_owner": target["runtime_owner"],
            "contract": target["contract"],
            "diagnostic_status": target_log["status"],
        },
        "parity": parity,
        "errors": errors,
        "blockers": blockers,
    }


def comparison_validation_error(
    payload: Any,
    *,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate an authenticated cross-store comparison."""
    error = sealed_payload_error("comparison", payload, COMPARISON_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    if payload["schema"] != COMPARISON_SCHEMA or payload["run_stamp"] != run_stamp:
        return "comparison had an invalid schema or run binding"
    if (
        payload["status"] not in STATUS_EXIT
        or not isinstance(payload["inputs"], dict)
        or set(payload["inputs"]) != COMPARISON_INPUT_FIELDS
        or not isinstance(payload["reference"], dict)
        or set(payload["reference"]) != COMPARISON_STORE_FIELDS
        or not isinstance(payload["target"], dict)
        or set(payload["target"]) != COMPARISON_STORE_FIELDS
        or not isinstance(payload["parity"], dict)
        or set(payload["parity"]) != COMPARISON_PARITY_FIELDS
        or not all(isinstance(value, bool) for value in payload["parity"].values())
        or not is_string_list(payload["errors"])
        or not is_string_list(payload["blockers"])
    ):
        return "comparison had malformed values"
    for digest in payload["inputs"].values():
        if not isinstance(digest, str) or re.fullmatch(
            r"sha256:[0-9a-f]{64}", digest
        ) is None:
            return "comparison had malformed input digests"
    for role, owner in (("reference", "plugin"), ("target", "native")):
        projection = payload[role]
        if (
            projection["status"] not in STATUS_EXIT
            or projection["runtime_owner"] != owner
            or projection["diagnostic_status"] not in STATUS_EXIT
            or not isinstance(projection["contract"], dict)
            or set(projection["contract"]) != RESULT_CONTRACT_FIELDS
            or not all(
                isinstance(value, bool) for value in projection["contract"].values()
            )
        ):
            return "comparison store projection was malformed"
    expected_status = (
        "blocked"
        if payload["blockers"]
        else "fail"
        if payload["errors"]
        else "pass"
    )
    if payload["status"] != expected_status:
        return "comparison verdict was not reproducible"
    expected_parity = {
        name: payload["reference"]["contract"][name]
        == payload["target"]["contract"][name]
        for name in RESULT_CONTRACT_FIELDS
    }
    expected_parity["clean_diagnostics"] = (
        payload["reference"]["diagnostic_status"]
        == payload["target"]["diagnostic_status"]
        == "pass"
    )
    if payload["parity"] != expected_parity:
        return "comparison parity projection was not reproducible"
    return None


def log_validation_error(
    payload: Any,
    *,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate an authenticated normalized debug-log verdict."""
    error = sealed_payload_error("log", payload, LOG_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    if (
        payload["schema"] != LOG_SCHEMA
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
        or payload["flow_id"] != FLOW
        or payload["purpose"] != LOG_PURPOSE
    ):
        return "log evidence had an invalid schema or run binding"
    if (
        payload["status"] not in STATUS_EXIT
        or payload["exit_code"] != STATUS_EXIT[payload["status"]]
        or not isinstance(payload["scan_observed"], bool)
        or not is_int(payload["match_count"])
        or payload["match_count"] < 0
        or not isinstance(payload["blocker_code"], str)
        or not isinstance(payload["source_payload_sha256"], str)
        or re.fullmatch(
            r"sha256:[0-9a-f]{64}", payload["source_payload_sha256"]
        )
        is None
    ):
        return "log evidence had malformed values"
    if payload["status"] == "pass" and (
        not payload["scan_observed"]
        or payload["match_count"] != 0
        or payload["blocker_code"]
    ):
        return "passing log evidence was contradictory"
    if payload["status"] == "fail" and (
        not payload["scan_observed"]
        or payload["match_count"] < 1
        or payload["blocker_code"]
    ):
        return "failing log evidence was contradictory"
    if payload["status"] == "blocked" and not payload["blocker_code"]:
        return "blocked log evidence lacked a blocker"
    return None


def derived_execution_status(
    result_status: str,
    comparison_status: str | None,
    log_status: str,
    *,
    requires_comparison: bool,
) -> str:
    """Apply the flow's final verdict precedence without upgrading evidence."""
    status = result_status
    if comparison_status == "blocked" or (
        requires_comparison and comparison_status is None and status == "pass"
    ):
        status = "blocked"
    elif comparison_status == "fail" and status != "blocked":
        status = "fail"
    if log_status == "fail" and status != "blocked":
        status = "fail"
    elif log_status == "blocked" and status == "pass":
        status = "blocked"
    return status


def execution_validation_error(
    payload: Any,
    *,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Validate an authenticated execution record's internal semantics."""
    error = sealed_payload_error("execution", payload, EXECUTION_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    if (
        payload["schema"] != EXECUTION_SCHEMA
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
        or payload["status"] not in STATUS_EXIT
        or payload["exit_code"] != STATUS_EXIT[payload["status"]]
        or payload["stage"] not in {"preflight", "http", "post"}
        or not is_string_list(payload["verdict_sources"])
        or len(payload["verdict_sources"]) != len(set(payload["verdict_sources"]))
    ):
        return "execution evidence had an invalid binding or verdict"
    code_fields = {
        "preflight": payload["preflight_exit_code"],
        "http": payload["http_exit_code"],
        "post": payload["post_exit_code"],
        "result": payload["result_exit_code"],
        "comparison": payload["comparison_exit_code"],
        "log": payload["log_exit_code"],
    }
    if any(
        value is not None and (not is_int(value) or value not in {0, 1, 3})
        for value in code_fields.values()
    ):
        return "execution evidence had malformed exit codes"
    if payload["preflight_exit_code"] not in {0, 3}:
        return "execution preflight exit code was invalid"
    if payload["stage"] == "preflight" and (
        payload["http_exit_code"] is not None or payload["post_exit_code"] is not None
    ):
        return "execution stage contradicted its exit-code prefix"
    if payload["stage"] == "http" and (
        payload["http_exit_code"] is None or payload["post_exit_code"] is not None
    ):
        return "execution stage contradicted its exit-code prefix"
    if payload["stage"] == "post" and (
        payload["http_exit_code"] != 0 or payload["post_exit_code"] is None
    ):
        return "execution stage contradicted its exit-code prefix"
    for source in payload["verdict_sources"]:
        binding = VERDICT_SOURCE_CODES.get(source)
        if binding is None or code_fields[binding[0]] != binding[1]:
            return "execution verdict source was not bound to its stage"
    if payload["status"] == "pass" and payload["verdict_sources"]:
        return "passing execution carried verdict sources"
    if payload["status"] != "pass" and not payload["verdict_sources"]:
        return "non-passing execution lacked a verdict source"
    for field in (
        "result_payload_sha256",
        "comparison_payload_sha256",
        "log_payload_sha256",
    ):
        value = payload[field]
        if value is not None and (
            not isinstance(value, str)
            or re.fullmatch(r"sha256:[0-9a-f]{64}", value) is None
        ):
            return "execution evidence had malformed payload bindings"
    if payload["result_payload_sha256"] is None or payload["log_payload_sha256"] is None:
        return "execution evidence omitted required payload bindings"
    if (payload["comparison_exit_code"] is None) != (
        payload["comparison_payload_sha256"] is None
    ):
        return "execution comparison bindings were contradictory"
    return None


def expected_manifest_files(
    store: str,
    status: str,
    stage: str,
    verdict_sources: list[str],
    reference_stage: str | None = None,
) -> set[str]:
    """Return the exact artifact prefix allowed for a final manifest."""

    def store_files(role: str, packet_stage: str) -> set[str]:
        files = {
            f"{role}-preflight.json",
            f"{role}-result.json",
            f"{role}-log-scan.json",
            f"{role}-execution.json",
        }
        if packet_stage in {"http", "post"}:
            files.add(f"{role}-http.json")
        if packet_stage == "post":
            files.add(f"{role}-post.json")
        return files

    files = store_files(store, stage)
    comparison_bound = any(source.startswith("comparison_") for source in verdict_sources)
    if store == "target" and (status == "pass" or comparison_bound):
        if reference_stage not in {"preflight", "http", "post"}:
            return files | {"__invalid-reference-stage__"}
        files |= store_files("ref", reference_stage)
        files.add("comparison.json")
    return files


def reference_stage_from_names(names: set[str]) -> str | None:
    """Infer the bound reference prefix from exact packet basenames."""
    if "ref-post.json" in names:
        return "post"
    if "ref-http.json" in names:
        return "http"
    if "ref-preflight.json" in names:
        return "preflight"
    return None


def recompute_store_result(
    payloads: dict[str, dict[str, Any]],
    *,
    store: str,
    run_stamp: str,
    key: bytes,
) -> str | None:
    """Recompute a complete store packet and compare the sealed result bytes."""
    preflight = payloads[f"{store}-preflight.json"]
    http = payloads.get(f"{store}-http.json")
    post = payloads.get(f"{store}-post.json")
    result = payloads[f"{store}-result.json"]
    error = state_validation_error(
        preflight,
        phase="preflight",
        store=store,
        run_stamp=run_stamp,
        key=key,
    )
    if error:
        return error
    if http is not None:
        error = http_validation_error(http, store=store, run_stamp=run_stamp, key=key)
        if error:
            return error
    if post is not None:
        error = state_validation_error(
            post,
            phase="post",
            store=store,
            run_stamp=run_stamp,
            key=key,
        )
        if error:
            return error
    error = result_validation_error(result, store=store, run_stamp=run_stamp, key=key)
    if error:
        return error
    expected = seal(
        "result",
        evaluate_store_payload(
            preflight,
            http,
            post,
            store=store,
            run_stamp=run_stamp,
        ),
        key,
    )
    if result != expected:
        return "store result was not reproducible from bound evidence"
    return None


def validate_packet_payloads(
    payloads: dict[str, dict[str, Any]],
    *,
    store: str,
    status: str,
    exit_code: int,
    stage: str,
    run_stamp: str,
    verdict_sources: list[str],
    key: bytes,
) -> str | None:
    """Recompute every artifact represented by a manifest packet."""
    reference_stage = reference_stage_from_names(set(payloads))
    expected_names = expected_manifest_files(
        store, status, stage, verdict_sources, reference_stage
    )
    if set(payloads) != expected_names:
        return "manifest packet did not have the exact stage artifact set"
    roles = [store]
    if "comparison.json" in payloads:
        roles = ["ref", "target"]
    for role in roles:
        error = recompute_store_result(
            payloads,
            store=role,
            run_stamp=run_stamp,
            key=key,
        )
        if error:
            return error
        log_scan = payloads[f"{role}-log-scan.json"]
        execution = payloads[f"{role}-execution.json"]
        result = payloads[f"{role}-result.json"]
        error = log_validation_error(
            log_scan,
            store=role,
            run_stamp=run_stamp,
            key=key,
        ) or execution_validation_error(
            execution,
            store=role,
            run_stamp=run_stamp,
            key=key,
        )
        if error:
            return error
        if (
            execution["result_payload_sha256"] != result["payload_sha256"]
            or execution["log_payload_sha256"] != log_scan["payload_sha256"]
        ):
            return "execution payload bindings did not match store artifacts"
        comparison_status = None
        if role == "target" and "comparison.json" in payloads:
            comparison_status = payloads["comparison.json"]["status"]
        expected_execution_status = derived_execution_status(
            result["status"],
            comparison_status,
            log_scan["status"],
            requires_comparison=role == "target" and result["status"] == "pass",
        )
        if execution["status"] != expected_execution_status:
            return "execution verdict was not reproducible from bound artifacts"
    if "comparison.json" in payloads:
        comparison = payloads["comparison.json"]
        error = comparison_validation_error(comparison, run_stamp=run_stamp, key=key)
        if error:
            return error
        expected_comparison = seal(
            "comparison",
            compare_payloads(
                payloads["ref-result.json"],
                payloads["target-result.json"],
                payloads["ref-log-scan.json"],
                payloads["target-log-scan.json"],
                run_stamp=run_stamp,
            ),
            key,
        )
        if comparison != expected_comparison:
            return "comparison was not reproducible from bound store results"
        target_execution = payloads["target-execution.json"]
        if target_execution["comparison_payload_sha256"] != comparison["payload_sha256"]:
            return "target execution did not bind the comparison"
    final_execution = payloads[f"{store}-execution.json"]
    if (
        final_execution["status"] != status
        or final_execution["exit_code"] != exit_code
        or final_execution["stage"] != stage
        or final_execution["verdict_sources"] != sorted(set(verdict_sources))
    ):
        return "manifest verdict did not match its execution record"
    return None


def blocked_state(
    *,
    phase: str,
    store: str,
    run_stamp: str,
    runtime_owner: str,
    blockers: list[str],
    facts: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Build a normalized state that cannot safely support mutation."""
    return {
        "schema": STATE_SCHEMA,
        "status": "blocked",
        "phase": phase,
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": runtime_owner,
        "evidence_complete": False,
        "facts": facts or {},
        "errors": [],
        "blockers": blockers,
    }


def normalize_preflight(
    raw: Any,
    *,
    store: str,
    run_stamp: str,
) -> dict[str, Any]:
    """Normalize the immutable facts required before checkout mutation."""
    expected_owner = "plugin" if store == "ref" else "native"
    structural_blockers: list[str] = []

    if not isinstance(raw, dict) or set(raw) != RAW_PREFLIGHT_FIELDS:
        return blocked_state(
            phase="preflight",
            store=store,
            run_stamp=run_stamp,
            runtime_owner=expected_owner,
            blockers=["Raw preflight payload did not have the exact expected shape."],
        )

    if raw["schema"] != RAW_STATE_SCHEMA:
        structural_blockers.append("Raw preflight schema was invalid.")
    if raw["phase"] != "preflight":
        structural_blockers.append("Raw preflight phase was invalid.")
    if raw["store"] != store:
        structural_blockers.append("Raw preflight store binding was invalid.")
    if raw["run_stamp"] != run_stamp:
        structural_blockers.append("Raw preflight run binding was invalid.")
    if raw["runtime_owner"] != expected_owner:
        structural_blockers.append("WooPayments runtime ownership was invalid.")

    gateway = raw["gateway"]
    product = raw["product"]
    if not isinstance(gateway, dict) or set(gateway) != GATEWAY_FIELDS:
        structural_blockers.append("Gateway evidence did not have the exact expected shape.")
    if not isinstance(product, dict) or set(product) != PRODUCT_FIELDS:
        structural_blockers.append("Product evidence did not have the exact expected shape.")
    if not is_string_list(raw["blockers"]):
        structural_blockers.append("Collector blockers were malformed.")

    if structural_blockers:
        return blocked_state(
            phase="preflight",
            store=store,
            run_stamp=run_stamp,
            runtime_owner=expected_owner,
            blockers=structural_blockers,
        )

    assert isinstance(gateway, dict)
    assert isinstance(product, dict)
    facts = {
        "gateway": dict(gateway),
        "product": dict(product),
        "store_currency": raw["store_currency"],
        "connected_account_id": raw["connected_account_id"],
    }
    blockers = list(raw["blockers"])

    if gateway["id"] != "woocommerce_payments":
        blockers.append("WooPayments gateway ID was unavailable.")
    if not is_nonempty_string(gateway["class"]):
        blockers.append("WooPayments gateway class was unavailable.")
    for name in ("available", "test_mode", "connected"):
        if not isinstance(gateway[name], bool):
            blockers.append("WooPayments gateway readiness evidence was malformed.")
            break
    if gateway["available"] is not True:
        blockers.append("WooPayments gateway was unavailable.")
    if gateway["test_mode"] is not True:
        blockers.append("WooPayments test mode was unavailable.")
    if gateway["connected"] is not True:
        blockers.append("WooPayments connection was unavailable.")

    if not is_int(product["id"]) or product["id"] <= 0:
        blockers.append("Fixture product ID was unavailable.")
    if product["sku"] != "test-lab-beaker-001":
        blockers.append("Fixture product SKU was unavailable.")
    if product["price"] != "25.00":
        blockers.append("Fixture product price was not USD 25.00.")
    if product["purchasable"] is not True:
        blockers.append("Fixture product was not purchasable.")
    if product["in_stock"] is not True:
        blockers.append("Fixture product was not in stock.")
    if raw["store_currency"] != "USD":
        blockers.append("Store currency was not USD.")
    if not is_nonempty_string(raw["connected_account_id"]):
        blockers.append("Connected provider account was unavailable.")

    blockers = list(dict.fromkeys(blockers))
    return {
        "schema": STATE_SCHEMA,
        "status": "blocked" if blockers else "pass",
        "phase": "preflight",
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": expected_owner,
        "evidence_complete": not blockers,
        "facts": facts,
        "errors": [],
        "blockers": blockers,
    }


def normalize_post(
    raw: Any,
    *,
    store: str,
    run_stamp: str,
) -> dict[str, Any]:
    """Normalize the exact order and bounded provider observations."""
    expected_owner = "plugin" if store == "ref" else "native"
    structural_blockers: list[str] = []
    if not isinstance(raw, dict) or set(raw) != RAW_POST_FIELDS:
        return blocked_state(
            phase="post",
            store=store,
            run_stamp=run_stamp,
            runtime_owner=expected_owner,
            blockers=["Raw post payload did not have the exact expected shape."],
        )

    if raw["schema"] != RAW_STATE_SCHEMA:
        structural_blockers.append("Raw post schema was invalid.")
    if raw["phase"] != "post":
        structural_blockers.append("Raw post phase was invalid.")
    if raw["store"] != store:
        structural_blockers.append("Raw post store binding was invalid.")
    if raw["run_stamp"] != run_stamp:
        structural_blockers.append("Raw post run binding was invalid.")
    if raw["runtime_owner"] != expected_owner:
        structural_blockers.append("WooPayments runtime ownership was invalid.")
    if not is_string_list(raw["blockers"]):
        structural_blockers.append("Collector blockers were malformed.")

    order = raw["order"]
    provider = raw["provider"]
    if not isinstance(order, dict) or set(order) != ORDER_FIELDS:
        structural_blockers.append("Order evidence did not have the exact expected shape.")
    if not isinstance(provider, dict) or set(provider) != PROVIDER_FIELDS:
        structural_blockers.append("Provider evidence did not have the exact expected shape.")

    if structural_blockers:
        return blocked_state(
            phase="post",
            store=store,
            run_stamp=run_stamp,
            runtime_owner=expected_owner,
            blockers=structural_blockers,
        )

    assert isinstance(order, dict)
    assert isinstance(provider, dict)
    line_items = order["line_items"]
    intent = provider["intent"]
    charge = provider["charge"]
    if (
        not isinstance(line_items, list)
        or len(line_items) > MAX_LINE_ITEMS
        or any(
            not isinstance(line_item, dict)
            or set(line_item) != LINE_ITEM_FIELDS
            for line_item in line_items
        )
    ):
        structural_blockers.append("Line-item evidence did not have the exact expected shape.")
    if not isinstance(intent, dict) or set(intent) != INTENT_FIELDS:
        structural_blockers.append("PaymentIntent evidence did not have the exact expected shape.")
    if not isinstance(charge, dict) or set(charge) != CHARGE_FIELDS:
        structural_blockers.append("Charge evidence did not have the exact expected shape.")

    order_string_fields = {
        "status",
        "created_via",
        "payment_method",
        "customer_note",
        "total",
        "currency",
        "transaction_id",
        "intent_id",
        "charge_id",
    }
    if not all(isinstance(order[name], str) for name in order_string_fields):
        structural_blockers.append("Order scalar evidence was malformed.")
    if not is_int(order["id"]) or not is_int(order["customer_id"]):
        structural_blockers.append("Order identity evidence was malformed.")
    if not isinstance(order["date_paid_present"], bool):
        structural_blockers.append("Order paid-time evidence was malformed.")

    if not structural_blockers:
        assert isinstance(intent, dict)
        assert isinstance(charge, dict)
        if any(
            not is_int(line_item["product_id"])
            or not is_int(line_item["quantity"])
            or not isinstance(line_item["sku"], str)
            or not isinstance(line_item["total"], str)
            for line_item in line_items
        ):
            structural_blockers.append("Line-item scalar evidence was malformed.")
        if (
            not all(
                isinstance(intent[name], str)
                for name in INTENT_FIELDS - {"amount"}
            )
            or not is_int(intent["amount"])
        ):
            structural_blockers.append("PaymentIntent scalar evidence was malformed.")
        if (
            not all(
                isinstance(charge[name], str)
                for name in CHARGE_FIELDS - {"paid", "amount", "amount_captured"}
            )
            or not isinstance(charge["paid"], bool)
            or not is_int(charge["amount"])
            or not is_int(charge["amount_captured"])
        ):
            structural_blockers.append("Charge scalar evidence was malformed.")

    if structural_blockers:
        return blocked_state(
            phase="post",
            store=store,
            run_stamp=run_stamp,
            runtime_owner=expected_owner,
            blockers=structural_blockers,
        )

    line_item = line_items[0] if len(line_items) == 1 else None
    assert isinstance(intent, dict)
    assert isinstance(charge, dict)
    facts = {
        "order": dict(order),
        "provider": {"intent": dict(intent), "charge": dict(charge)},
    }
    blockers = list(raw["blockers"])
    errors: list[str] = []

    if order["id"] <= 0:
        blockers.append("Response order identity was unavailable.")
    if not INTENT_RE.fullmatch(order["intent_id"]):
        errors.append("Order PaymentIntent identity was absent or malformed.")
    if not CHARGE_RE.fullmatch(order["charge_id"]):
        errors.append("Order Charge identity was absent or malformed.")
    if not PAYMENT_METHOD_RE.fullmatch(intent["payment_method"]):
        errors.append("Provider Payment Method identity was absent or malformed.")

    if order["status"] not in {"processing", "completed"}:
        errors.append("Order did not reach a paid status.")
    if order["created_via"] != "store-api":
        errors.append("Order was not created through the Store API.")
    if order["payment_method"] != "woocommerce_payments":
        errors.append("Order did not use the WooPayments gateway.")
    if order["customer_id"] != 0:
        errors.append("Store API checkout was not a guest order.")
    if order["customer_note"] != f"sc02-{run_stamp}-{store}":
        errors.append("Order customer note did not bind the current run.")
    if order["total"] != "25.00" or order["currency"] != "USD":
        errors.append("Order total did not match USD 25.00.")
    if order["date_paid_present"] is not True:
        errors.append("Order paid timestamp was absent.")
    if order["transaction_id"] != order["intent_id"]:
        errors.append("Order transaction ID did not match its PaymentIntent.")
    if (
        line_item is None
        or line_item["product_id"] <= 0
        or line_item["sku"] != "test-lab-beaker-001"
        or line_item["quantity"] != 1
        or line_item["total"] != "25.00"
    ):
        errors.append("Order line item did not match the fixed fixture product.")

    if intent["id"] != order["intent_id"]:
        errors.append("Provider PaymentIntent did not match the order.")
    if intent["object"] != "payment_intent":
        errors.append("Provider PaymentIntent object type was invalid.")
    if intent["status"] != "succeeded":
        errors.append("Provider PaymentIntent did not succeed.")
    if intent["amount"] != 2500 or intent["currency"] != "usd":
        errors.append("Provider PaymentIntent amount did not match USD 25.00.")
    if intent["latest_charge"] != order["charge_id"]:
        errors.append("Provider PaymentIntent latest Charge did not match the order.")

    if charge["id"] != order["charge_id"]:
        errors.append("Provider Charge did not match the order.")
    if charge["object"] != "charge":
        errors.append("Provider Charge object type was invalid.")
    if charge["status"] != "succeeded" or charge["paid"] is not True:
        errors.append("Provider Charge did not succeed.")
    if charge["amount"] != 2500 or charge["amount_captured"] != 2500:
        errors.append("Provider Charge amount did not match USD 25.00.")
    if charge["currency"] != "usd":
        errors.append("Provider charge currency did not match USD.")
    if charge["payment_intent"] != order["intent_id"]:
        errors.append("Provider Charge PaymentIntent did not match the order.")
    if charge["payment_method"] != intent["payment_method"]:
        errors.append("Provider objects did not agree on Payment Method identity.")

    blockers = list(dict.fromkeys(blockers))
    errors = list(dict.fromkeys(errors))
    status = "blocked" if blockers else "fail" if errors else "pass"
    return {
        "schema": STATE_SCHEMA,
        "status": status,
        "phase": "post",
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": expected_owner,
        "evidence_complete": not blockers,
        "facts": facts,
        "errors": errors,
        "blockers": blockers,
    }


def emit(payload: dict[str, Any], key: bytes) -> int:
    """Print one sealed payload and return its verdict exit code."""
    sealed = seal("state", payload, key)
    print(json.dumps(sealed, indent=2, sort_keys=True))
    return STATUS_EXIT[sealed["status"]]


def command_normalize_state(args: argparse.Namespace) -> int:
    """Implement the normalize-state command."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]

    raw_input = sys.stdin.read(MAX_JSON_BYTES + 1)
    if len(raw_input.encode("utf-8")) > MAX_JSON_BYTES:
        payload = blocked_state(
            phase=args.phase,
            store=args.store,
            run_stamp=args.run_stamp,
            runtime_owner="plugin" if args.store == "ref" else "native",
            blockers=["Raw state payload exceeded the size limit."],
        )
        return emit(payload, key)
    try:
        raw = load_unique_json(raw_input)
    except (json.JSONDecodeError, UnicodeError, ValueError):
        payload = blocked_state(
            phase=args.phase,
            store=args.store,
            run_stamp=args.run_stamp,
            runtime_owner="plugin" if args.store == "ref" else "native",
            blockers=["Raw state payload was not one strict JSON value."],
        )
        return emit(payload, key)

    if args.phase == "preflight":
        payload = normalize_preflight(raw, store=args.store, run_stamp=args.run_stamp)
    else:
        payload = normalize_post(raw, store=args.store, run_stamp=args.run_stamp)
    return emit(payload, key)


def command_validate_http(args: argparse.Namespace) -> int:
    """Validate a signed HTTP transcript against the current run context."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    payload, read_error = load_strict_file(Path(args.input))
    if read_error:
        print(f"SC-02 HTTP evidence was blocked: {read_error}.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    error = http_validation_error(
        payload,
        store=args.store,
        run_stamp=args.run_stamp,
        key=key,
    )
    expected_status = args.expected_status
    expected_exit = STATUS_EXIT[expected_status]
    if (
        error
        or not isinstance(payload, dict)
        or args.expected_exit_code != expected_exit
        or payload["status"] != expected_status
    ):
        print(
            f"SC-02 HTTP evidence was blocked: {error or 'verdict binding was invalid'}.",
            file=sys.stderr,
        )
        return STATUS_EXIT["blocked"]
    print(payload["payload_sha256"])
    return 0


def command_evaluate_store(args: argparse.Namespace) -> int:
    """Evaluate a store packet and atomically write its authenticated result."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]

    preflight, preflight_read_error = load_strict_file(Path(args.preflight))
    http: Any | None = None
    http_read_error: str | None = None
    if args.http:
        http, http_read_error = load_strict_file(Path(args.http))
    post: Any | None = None
    post_read_error: str | None = None
    if args.post:
        post, post_read_error = load_strict_file(Path(args.post))

    validation_errors = [
        error
        for error in (
            preflight_read_error,
            http_read_error,
            post_read_error,
            state_validation_error(
                preflight,
                phase="preflight",
                store=args.store,
                run_stamp=args.run_stamp,
                key=key,
            )
            if preflight_read_error is None
            else None,
            http_validation_error(
                http,
                store=args.store,
                run_stamp=args.run_stamp,
                key=key,
            )
            if args.http and http_read_error is None
            else None,
            state_validation_error(
                post,
                phase="post",
                store=args.store,
                run_stamp=args.run_stamp,
                key=key,
            )
            if args.post and post_read_error is None
            else None,
        )
        if error
    ]
    if validation_errors:
        payload = {
            "schema": RESULT_SCHEMA,
            "status": "blocked",
            "store": args.store,
            "run_stamp": args.run_stamp,
            "runtime_owner": "plugin" if args.store == "ref" else "native",
            "inputs": {"preflight": None, "http": None, "post": None},
            "contract": {name: False for name in RESULT_CONTRACT_FIELDS},
            "identities": {
                "product_id": 0,
                "order_id": 0,
                "cart_token_fingerprint": "",
                "origin_fingerprint": "",
                "intent_id": "",
                "charge_id": "",
                "payment_method_id": "",
            },
            "errors": [],
            "blockers": ["Store evidence packet was invalid."],
        }
    else:
        assert isinstance(preflight, dict)
        assert http is None or isinstance(http, dict)
        assert post is None or isinstance(post, dict)
        payload = evaluate_store_payload(
            preflight,
            http,
            post,
            store=args.store,
            run_stamp=args.run_stamp,
        )
    return write_sealed_payload("result", payload, Path(args.output), key)


def command_compare(args: argparse.Namespace) -> int:
    """Compare authenticated reference and target results and diagnostics."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    reference, reference_read_error = load_strict_file(Path(args.reference_result))
    target, target_read_error = load_strict_file(Path(args.target_result))
    reference_log, reference_log_read_error = load_strict_file(
        Path(args.reference_log)
    )
    target_log, target_log_read_error = load_strict_file(Path(args.target_log))
    validation_errors = [
        error
        for error in (
            reference_read_error,
            target_read_error,
            reference_log_read_error,
            target_log_read_error,
            result_validation_error(
                reference,
                store="ref",
                run_stamp=args.run_stamp,
                key=key,
            )
            if reference_read_error is None
            else None,
            result_validation_error(
                target,
                store="target",
                run_stamp=args.run_stamp,
                key=key,
            )
            if target_read_error is None
            else None,
            log_validation_error(
                reference_log,
                store="ref",
                run_stamp=args.run_stamp,
                key=key,
            )
            if reference_log_read_error is None
            else None,
            log_validation_error(
                target_log,
                store="target",
                run_stamp=args.run_stamp,
                key=key,
            )
            if target_log_read_error is None
            else None,
        )
        if error
    ]
    if validation_errors:
        payload = {
            "schema": COMPARISON_SCHEMA,
            "status": "blocked",
            "run_stamp": args.run_stamp,
            "inputs": {
                "reference_result": None,
                "target_result": None,
                "reference_log": None,
                "target_log": None,
            },
            "reference": {
                "status": "blocked",
                "runtime_owner": "plugin",
                "contract": {name: False for name in RESULT_CONTRACT_FIELDS},
                "diagnostic_status": "blocked",
            },
            "target": {
                "status": "blocked",
                "runtime_owner": "native",
                "contract": {name: False for name in RESULT_CONTRACT_FIELDS},
                "diagnostic_status": "blocked",
            },
            "parity": {name: False for name in COMPARISON_PARITY_FIELDS},
            "errors": [],
            "blockers": ["Store result or diagnostic packet was invalid."],
        }
    else:
        assert isinstance(reference, dict)
        assert isinstance(target, dict)
        assert isinstance(reference_log, dict)
        assert isinstance(target_log, dict)
        payload = compare_payloads(
            reference,
            target,
            reference_log,
            target_log,
            run_stamp=args.run_stamp,
        )
    return write_sealed_payload("comparison", payload, Path(args.output), key)


def command_execution(args: argparse.Namespace) -> int:
    """Bind the final shell verdict to all decisive stage evidence."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    result, result_read_error = load_strict_file(Path(args.result))
    log_scan, log_read_error = load_strict_file(Path(args.log_scan))
    comparison: Any | None = None
    comparison_read_error: str | None = None
    if args.comparison:
        comparison, comparison_read_error = load_strict_file(Path(args.comparison))

    errors = [
        error
        for error in (
            result_read_error,
            log_read_error,
            comparison_read_error,
            result_validation_error(
                result,
                store=args.store,
                run_stamp=args.run_stamp,
                key=key,
            )
            if result_read_error is None
            else None,
            log_validation_error(
                log_scan,
                store=args.store,
                run_stamp=args.run_stamp,
                key=key,
            )
            if log_read_error is None
            else None,
            comparison_validation_error(
                comparison,
                run_stamp=args.run_stamp,
                key=key,
            )
            if args.comparison and comparison_read_error is None
            else None,
        )
        if error
    ]
    if errors:
        print("SC-02 execution inputs were invalid.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    assert isinstance(result, dict)
    assert isinstance(log_scan, dict)
    assert comparison is None or isinstance(comparison, dict)

    stage_codes = {
        "preflight": args.preflight_exit_code,
        "http": args.http_exit_code,
        "post": args.post_exit_code,
        "result": args.result_exit_code,
        "comparison": args.comparison_exit_code,
        "log": args.log_exit_code,
    }
    expected_stage_shape = {
        "preflight": (args.http_exit_code is None and args.post_exit_code is None),
        "http": (args.http_exit_code is not None and args.post_exit_code is None),
        "post": (args.http_exit_code == 0 and args.post_exit_code is not None),
    }
    valid_sources = bool(args.verdict_source) or args.status == "pass"
    for source in args.verdict_source:
        binding = VERDICT_SOURCE_CODES.get(source)
        if binding is None or stage_codes[binding[0]] != binding[1]:
            valid_sources = False
            break
    expected_status = derived_execution_status(
        result["status"],
        comparison["status"] if comparison is not None else None,
        log_scan["status"],
        requires_comparison=args.store == "target" and result["status"] == "pass",
    )
    if (
        args.exit_code != STATUS_EXIT[args.status]
        or args.status != expected_status
        or not expected_stage_shape[args.stage]
        or args.preflight_exit_code not in {0, 3}
        or args.result_exit_code != STATUS_EXIT[result["status"]]
        or args.log_exit_code != STATUS_EXIT[log_scan["status"]]
        or (comparison is None) != (args.comparison_exit_code is None)
        or (
            comparison is not None
            and args.comparison_exit_code != STATUS_EXIT[comparison["status"]]
        )
        or (args.status == "pass" and args.verdict_source)
        or not valid_sources
    ):
        print("SC-02 execution verdict was contradictory.", file=sys.stderr)
        return STATUS_EXIT["blocked"]

    payload = {
        "schema": EXECUTION_SCHEMA,
        "status": args.status,
        "store": args.store,
        "run_stamp": args.run_stamp,
        "stage": args.stage,
        "exit_code": args.exit_code,
        "verdict_sources": sorted(set(args.verdict_source)),
        "preflight_exit_code": args.preflight_exit_code,
        "http_exit_code": args.http_exit_code,
        "post_exit_code": args.post_exit_code,
        "result_exit_code": args.result_exit_code,
        "comparison_exit_code": args.comparison_exit_code,
        "log_exit_code": args.log_exit_code,
        "result_payload_sha256": result["payload_sha256"],
        "comparison_payload_sha256": (
            comparison["payload_sha256"] if comparison is not None else None
        ),
        "log_payload_sha256": log_scan["payload_sha256"],
    }
    return write_sealed_payload("execution", payload, Path(args.output), key)


def blocked_log_payload(
    store: str,
    run_stamp: str,
    blocker_code: str,
    source_payload_sha256: str,
) -> dict[str, Any]:
    """Build a fixed-shape normalized log blocker without raw diagnostics."""
    return {
        "schema": LOG_SCHEMA,
        "status": "blocked",
        "store": store,
        "run_stamp": run_stamp,
        "flow_id": FLOW,
        "purpose": LOG_PURPOSE,
        "exit_code": 3,
        "scan_observed": False,
        "match_count": 0,
        "blocker_code": blocker_code,
        "source_payload_sha256": source_payload_sha256,
    }


def command_normalize_log_scan(args: argparse.Namespace) -> int:
    """Validate common v6 log provenance and seal a bounded SC-02 verdict."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    raw, read_error = load_strict_file(Path(args.input))
    source_digest = "sha256:" + hashlib.sha256(
        canonical(raw) if read_error is None else b""
    ).hexdigest()
    blocker_code = "invalid_scan_evidence"
    scan: Any = None
    if (
        read_error is None
        and isinstance(raw, dict)
        and set(raw) == {"schema", "store", "scan"}
        and raw["schema"] == "woopayments_debug_log_scan.v6"
        and raw["store"] == args.store
        and args.flow_id == FLOW
        and args.purpose == LOG_PURPOSE
    ):
        scan = raw["scan"]
        validator_path = Path(__file__).with_name("mo03-evidence.py")
        try:
            spec = importlib.util.spec_from_file_location(
                "woopayments_sc02_log_validator", validator_path
            )
            if spec is None or spec.loader is None:
                raise ImportError("log validator loader unavailable")
            validator = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(validator)
            if not isinstance(scan, dict) or set(scan) != validator.COMMON_LOG_SCAN_FIELDS:
                blocker_code = "invalid_scan_evidence"
            else:
                projection_error = validator.log_projection_error(
                    scan,
                    args.run_stamp,
                    expected_store=args.store,
                    expected_flow_id=FLOW,
                    expected_purpose=LOG_PURPOSE,
                    require_observation=scan.get("status") in {"pass", "fail"},
                )
                expected_status = {0: "pass", 1: "fail", 3: "blocked"}[
                    args.expected_exit_code
                ]
                if projection_error:
                    blocker_code = projection_error
                elif (
                    scan.get("status") != expected_status
                    or (
                        expected_status == "pass"
                        and (scan.get("matches") or scan.get("blocker_code"))
                    )
                    or (
                        expected_status == "fail"
                        and (not scan.get("matches") or scan.get("blocker_code"))
                    )
                    or (
                        expected_status == "blocked"
                        and scan.get("blocker_code") not in validator.LOG_BLOCKER_CODES
                    )
                ):
                    blocker_code = "invalid_scan_evidence"
                else:
                    payload = {
                        "schema": LOG_SCHEMA,
                        "status": expected_status,
                        "store": args.store,
                        "run_stamp": args.run_stamp,
                        "flow_id": FLOW,
                        "purpose": LOG_PURPOSE,
                        "exit_code": args.expected_exit_code,
                        "scan_observed": bool(scan["observations"]),
                        "match_count": len(scan["matches"]),
                        "blocker_code": scan["blocker_code"],
                        "source_payload_sha256": source_digest,
                    }
                    return write_sealed_payload("log", payload, Path(args.output), key)
        except (AttributeError, ImportError, OSError, RuntimeError, ValueError):
            blocker_code = "invalid_scan_evidence"
    payload = blocked_log_payload(
        args.store,
        args.run_stamp,
        blocker_code,
        source_digest,
    )
    return write_sealed_payload("log", payload, Path(args.output), key)


def load_packet_files(
    paths: list[Path],
    *,
    expected_names: set[str],
    parent: Path,
) -> tuple[dict[str, dict[str, Any]] | None, str | None]:
    """Load an exact same-directory artifact packet by basename."""
    payloads: dict[str, dict[str, Any]] = {}
    for supplied in paths:
        if supplied.name in payloads or supplied.name not in expected_names:
            return None, "packet contained a duplicate or unexpected artifact"
        try:
            if Path(os.path.abspath(os.fspath(supplied.parent))) != parent:
                return None, "packet artifact was outside the manifest directory"
        except (OSError, RuntimeError, TypeError, ValueError):
            return None, "packet artifact path was invalid"
        payload, error = load_strict_file(supplied)
        if error or not isinstance(payload, dict):
            return None, error or "packet artifact was not a JSON object"
        payloads[supplied.name] = payload
    if set(payloads) != expected_names:
        return None, "packet did not contain the exact expected artifacts"
    return payloads, None


def command_manifest(args: argparse.Namespace) -> int:
    """Build a final authenticated manifest from an exact artifact packet."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    if (
        args.exit_code != STATUS_EXIT[args.status]
        or len(args.verdict_source) != len(set(args.verdict_source))
        or any(source not in VERDICT_SOURCE_CODES for source in args.verdict_source)
        or (args.status == "pass" and args.verdict_source)
        or (args.status != "pass" and not args.verdict_source)
        or (args.status == "pass" and args.stage != "post")
    ):
        print("SC-02 manifest verdict arguments were contradictory.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    output = Path(args.output)
    try:
        output_parent = Path(os.path.abspath(os.fspath(output.parent)))
    except (OSError, RuntimeError, TypeError, ValueError):
        print("SC-02 manifest output path was invalid.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    verdict_sources = sorted(args.verdict_source)
    supplied_paths = [Path(value) for value in args.file]
    reference_stage = reference_stage_from_names({path.name for path in supplied_paths})
    expected_names = expected_manifest_files(
        args.store,
        args.status,
        args.stage,
        verdict_sources,
        reference_stage,
    )
    payloads, error = load_packet_files(
        supplied_paths,
        expected_names=expected_names,
        parent=output_parent,
    )
    if error or payloads is None:
        print(f"SC-02 manifest packet was blocked: {error}.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    error = validate_packet_payloads(
        payloads,
        store=args.store,
        status=args.status,
        exit_code=args.exit_code,
        stage=args.stage,
        run_stamp=args.run_stamp,
        verdict_sources=verdict_sources,
        key=key,
    )
    if error:
        print(f"SC-02 manifest packet was blocked: {error}.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    by_name = {path.name: path for path in supplied_paths}
    files = {
        name: {
            "file_sha256": file_digest(by_name[name]),
            "payload_sha256": payloads[name]["payload_sha256"],
        }
        for name in sorted(expected_names)
    }
    payload = {
        "schema": MANIFEST_SCHEMA,
        "flow": FLOW,
        "run_stamp": args.run_stamp,
        "run_scope": args.run_scope,
        "store": args.store,
        "status": args.status,
        "exit_code": args.exit_code,
        "stage": args.stage,
        "verdict_sources": verdict_sources,
        "files": files,
    }
    return write_sealed_payload("manifest", payload, output, key)


def manifest_validation_error(
    payload: Any,
    *,
    store: str,
    run_stamp: str,
    run_scope: str,
    expected_status: str,
    expected_exit_code: int,
    key: bytes,
) -> str | None:
    """Validate a manifest's own authenticated final bindings."""
    error = sealed_payload_error("manifest", payload, MANIFEST_FIELDS, key)
    if error or not isinstance(payload, dict):
        return error
    if (
        payload["schema"] != MANIFEST_SCHEMA
        or payload["flow"] != FLOW
        or payload["store"] != store
        or payload["run_stamp"] != run_stamp
        or payload["run_scope"] != run_scope
        or payload["status"] != expected_status
        or payload["exit_code"] != expected_exit_code
        or expected_exit_code != STATUS_EXIT[expected_status]
        or payload["stage"] not in {"preflight", "http", "post"}
        or not is_string_list(payload["verdict_sources"])
        or len(payload["verdict_sources"])
        != len(set(payload["verdict_sources"]))
        or not isinstance(payload["files"], dict)
    ):
        return "manifest had an invalid run or verdict binding"
    if any(source not in VERDICT_SOURCE_CODES for source in payload["verdict_sources"]):
        return "manifest used an unknown verdict source"
    if payload["status"] == "pass" and payload["verdict_sources"]:
        return "passing manifest carried verdict sources"
    if payload["status"] != "pass" and not payload["verdict_sources"]:
        return "non-passing manifest lacked a verdict source"
    reference_stage = reference_stage_from_names(set(payload["files"]))
    expected_names = expected_manifest_files(
        store,
        expected_status,
        payload["stage"],
        payload["verdict_sources"],
        reference_stage,
    )
    if set(payload["files"]) != expected_names:
        return "manifest file set did not match its stage"
    for name, binding in payload["files"].items():
        if (
            Path(name).name != name
            or name in {".", ".."}
            or not isinstance(binding, dict)
            or set(binding) != FILE_BINDING_FIELDS
            or any(
                not isinstance(value, str)
                or re.fullmatch(r"sha256:[0-9a-f]{64}", value) is None
                for value in binding.values()
            )
        ):
            return "manifest contained an invalid file binding"
    return None


def command_validate_bound_manifest(args: argparse.Namespace) -> int:
    """Independently revalidate a current-run final manifest and packet."""
    key = context_key()
    if key is None:
        print("SC-02 evidence context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    manifest_path = Path(args.manifest)
    manifest, read_error = load_strict_file(manifest_path)
    error = read_error or manifest_validation_error(
        manifest,
        store=args.store,
        run_stamp=args.run_stamp,
        run_scope=args.run_scope,
        expected_status=args.expected_status,
        expected_exit_code=args.expected_exit_code,
        key=key,
    )
    if error or not isinstance(manifest, dict):
        print(f"SC-02 manifest validation was blocked: {error}.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    paths = [manifest_path.parent / name for name in manifest["files"]]
    payloads, packet_error = load_packet_files(
        paths,
        expected_names=set(manifest["files"]),
        parent=Path(os.path.abspath(os.fspath(manifest_path.parent))),
    )
    if packet_error or payloads is None:
        print(
            f"SC-02 manifest validation was blocked: {packet_error}.",
            file=sys.stderr,
        )
        return STATUS_EXIT["blocked"]
    for path in paths:
        binding = manifest["files"][path.name]
        if (
            binding["file_sha256"] != file_digest(path)
            or binding["payload_sha256"] != payloads[path.name].get("payload_sha256")
        ):
            print("SC-02 manifest file binding did not match.", file=sys.stderr)
            return STATUS_EXIT["blocked"]
    packet_error = validate_packet_payloads(
        payloads,
        store=args.store,
        status=args.expected_status,
        exit_code=args.expected_exit_code,
        stage=manifest["stage"],
        run_stamp=args.run_stamp,
        verdict_sources=manifest["verdict_sources"],
        key=key,
    )
    if packet_error:
        print(
            f"SC-02 manifest validation was blocked: {packet_error}.",
            file=sys.stderr,
        )
        return STATUS_EXIT["blocked"]
    print(file_digest(manifest_path))
    return 0


def build_parser() -> argparse.ArgumentParser:
    """Build the SC-02 evidence command-line parser."""
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest="command", required=True)

    normalize = commands.add_parser("normalize-state")
    normalize.add_argument("--phase", choices=("preflight", "post"), required=True)
    normalize.add_argument("--store", choices=("ref", "target"), required=True)
    normalize.add_argument("--run-stamp", required=True)
    normalize.set_defaults(handler=command_normalize_state)

    validate_http = commands.add_parser("validate-http")
    validate_http.add_argument("--input", required=True)
    validate_http.add_argument("--store", choices=("ref", "target"), required=True)
    validate_http.add_argument("--run-stamp", required=True)
    validate_http.add_argument(
        "--expected-status", choices=("pass", "fail", "blocked"), required=True
    )
    validate_http.add_argument(
        "--expected-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    validate_http.set_defaults(handler=command_validate_http)

    evaluate = commands.add_parser("evaluate-store")
    evaluate.add_argument("--preflight", required=True)
    evaluate.add_argument("--http")
    evaluate.add_argument("--post")
    evaluate.add_argument("--output", required=True)
    evaluate.add_argument("--store", choices=("ref", "target"), required=True)
    evaluate.add_argument("--run-stamp", required=True)
    evaluate.set_defaults(handler=command_evaluate_store)

    compare = commands.add_parser("compare")
    compare.add_argument("--reference-result", required=True)
    compare.add_argument("--target-result", required=True)
    compare.add_argument("--reference-log", required=True)
    compare.add_argument("--target-log", required=True)
    compare.add_argument("--output", required=True)
    compare.add_argument("--run-stamp", required=True)
    compare.set_defaults(handler=command_compare)

    execution = commands.add_parser("execution")
    execution.add_argument("--store", choices=("ref", "target"), required=True)
    execution.add_argument(
        "--status", choices=("pass", "fail", "blocked"), required=True
    )
    execution.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    execution.add_argument(
        "--stage", choices=("preflight", "http", "post"), required=True
    )
    execution.add_argument("--run-stamp", required=True)
    execution.add_argument("--output", required=True)
    execution.add_argument("--verdict-source", action="append", default=[])
    execution.add_argument(
        "--preflight-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    execution.add_argument("--http-exit-code", type=int, choices=(0, 1, 3))
    execution.add_argument("--post-exit-code", type=int, choices=(0, 1, 3))
    execution.add_argument(
        "--result-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    execution.add_argument("--comparison-exit-code", type=int, choices=(0, 1, 3))
    execution.add_argument(
        "--log-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    execution.add_argument("--result", required=True)
    execution.add_argument("--comparison")
    execution.add_argument("--log-scan", required=True)
    execution.set_defaults(handler=command_execution)

    log_scan = commands.add_parser("normalize-log-scan")
    log_scan.add_argument("--input", required=True)
    log_scan.add_argument("--output", required=True)
    log_scan.add_argument("--store", choices=("ref", "target"), required=True)
    log_scan.add_argument("--run-stamp", required=True)
    log_scan.add_argument(
        "--expected-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    log_scan.add_argument("--flow-id", required=True)
    log_scan.add_argument("--purpose", required=True)
    log_scan.set_defaults(handler=command_normalize_log_scan)

    manifest = commands.add_parser("manifest")
    manifest.add_argument("--store", choices=("ref", "target"), required=True)
    manifest.add_argument(
        "--status", choices=("pass", "fail", "blocked"), required=True
    )
    manifest.add_argument("--exit-code", type=int, choices=(0, 1, 3), required=True)
    manifest.add_argument(
        "--stage", choices=("preflight", "http", "post"), required=True
    )
    manifest.add_argument("--run-stamp", required=True)
    manifest.add_argument("--run-scope", choices=("full", "partial"), required=True)
    manifest.add_argument("--output", required=True)
    manifest.add_argument("--verdict-source", action="append", default=[])
    manifest.add_argument("--file", action="append", default=[])
    manifest.set_defaults(handler=command_manifest)

    validate_manifest = commands.add_parser("validate-bound-manifest")
    validate_manifest.add_argument("--manifest", required=True)
    validate_manifest.add_argument(
        "--store", choices=("ref", "target"), required=True
    )
    validate_manifest.add_argument("--run-stamp", required=True)
    validate_manifest.add_argument(
        "--run-scope", choices=("full", "partial"), required=True
    )
    validate_manifest.add_argument(
        "--expected-status", choices=("pass", "fail", "blocked"), required=True
    )
    validate_manifest.add_argument(
        "--expected-exit-code", type=int, choices=(0, 1, 3), required=True
    )
    validate_manifest.set_defaults(handler=command_validate_bound_manifest)
    return parser


def main() -> int:
    """Run the requested evidence command without exposing tracebacks."""
    try:
        args = build_parser().parse_args()
        return args.handler(args)
    except (BrokenPipeError, OSError, UnicodeError, ValueError):
        print("SC-02 evidence processing was blocked.", file=sys.stderr)
        return STATUS_EXIT["blocked"]


if __name__ == "__main__":
    raise SystemExit(main())
