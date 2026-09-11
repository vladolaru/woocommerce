#!/usr/bin/env python3
"""Run one local-only guest WooPayments checkout through the Store API."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import socket
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


SCHEMA = "woopayments_sc02_http.v1"
KEY_ENV = "CRITICAL_FLOWS_RUN_CONTEXT_KEY"
KEY_RE = re.compile(r"[0-9a-f]{64}")
RUN_RE = re.compile(r"[0-9]{8}T[0-9]{6}Z-[0-9]+")
HTTP_DOMAIN = b"woopayments-sc02-http-v1\0"
TOKEN_DOMAIN = b"woopayments-sc02-cart-token-v1\0"
ORIGIN_DOMAIN = b"woopayments-sc02-origin-v1\0"
STATUS_EXIT = {"pass": 0, "fail": 1, "blocked": 3}
MAX_BODY_BYTES = 1024 * 1024
API_PREFIX = "/wp-json"
EXPECTED_SEQUENCE = [
    "GET /wc/store/v1/cart",
    "POST /wc/store/v1/cart/add-item",
    "POST /wc/store/v1/cart/update-customer",
    "POST /wc/store/v1/cart/select-shipping-rate",
    "GET /wc/store/v1/cart",
    "POST /wc/store/v1/checkout",
]


class Blocked(Exception):
    """Raised when checkout cannot be observed safely or unambiguously."""


class RefuseRedirects(urllib.request.HTTPRedirectHandler):
    """Expose redirects to the caller instead of following them."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def canonical(value: Any) -> bytes:
    return json.dumps(
        value,
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=False,
    ).encode("utf-8")


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def seal(payload: dict[str, Any], key: bytes) -> dict[str, Any]:
    sealed = dict(payload)
    sealed["key_fingerprint"] = "sha256:" + hashlib.sha256(key).hexdigest()
    unsigned = dict(sealed)
    unsigned.pop("context_hmac", None)
    unsigned.pop("payload_sha256", None)
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(
        key,
        HTTP_DOMAIN + canonical(unsigned),
        hashlib.sha256,
    ).hexdigest()
    sealed["payload_sha256"] = payload_digest(sealed)
    return sealed


def load_unique_json(raw: str) -> Any:
    def reject_duplicate(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for name, value in pairs:
            if name in result:
                raise ValueError("duplicate JSON key")
            result[name] = value
        return result

    return json.loads(raw, object_pairs_hook=reject_duplicate)


def allowed_base_url(raw: str) -> tuple[str, str]:
    parsed = urllib.parse.urlsplit(raw.rstrip("/"))
    host = (parsed.hostname or "").lower()
    if parsed.scheme not in {"http", "https"}:
        raise Blocked("Store API base URL must use HTTP or HTTPS.")
    if host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost"):
        raise Blocked("Store API base URL must stay local.")
    if (
        parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or parsed.path not in {"", "/"}
    ):
        raise Blocked("Store API base URL contains forbidden URL components.")
    origin = f"{parsed.scheme}://{parsed.netloc}"
    return origin, origin


def safe_output_path(path: Path) -> Path:
    if ".." in path.parts:
        raise Blocked("Output path contains parent traversal.")
    absolute = Path(os.path.abspath(os.fspath(path)))
    if absolute.exists() or absolute.is_symlink():
        raise Blocked("Output path already exists.")
    parent = absolute.parent
    if not parent.is_dir() or parent.is_symlink():
        raise Blocked("Output parent is unavailable or symlinked.")
    temp_root = Path(os.path.abspath(tempfile.gettempdir()))
    for ancestor in (parent, *parent.parents):
        if ancestor.is_symlink():
            raise Blocked("Output path contains a symlinked directory component.")
        if ancestor == temp_root:
            break
    return absolute


def write_payload(payload: dict[str, Any], output: Path, key: bytes) -> int:
    safe = safe_output_path(output)
    sealed = seal(payload, key)
    descriptor = -1
    temporary_name = ""
    try:
        descriptor, temporary_name = tempfile.mkstemp(
            prefix=f".{safe.name}.", suffix=".tmp", dir=safe.parent
        )
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            descriptor = -1
            json.dump(sealed, handle, indent=2, sort_keys=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary_name, safe)
    except (OSError, UnicodeError, ValueError):
        if descriptor >= 0:
            try:
                os.close(descriptor)
            except OSError:
                pass
        if temporary_name:
            try:
                os.unlink(temporary_name)
            except OSError:
                pass
        raise Blocked("Store API evidence output could not be written.")
    return STATUS_EXIT[sealed["status"]]


def empty_transcript(
    store: str,
    run_stamp: str,
    product_id: int,
    origin_fingerprint: str,
) -> dict[str, Any]:
    return {
        "schema": SCHEMA,
        "status": "blocked",
        "store": store,
        "run_stamp": run_stamp,
        "product_id": product_id,
        "order_id": 0,
        "cart_token_fingerprint": "hmac-sha256:" + "0" * 64,
        "origin_fingerprint": origin_fingerprint,
        "request_sequence": [],
        "cart_token_lineage": False,
        "local_origin": False,
        "redirect_count": 0,
        "cart": {
            "started_empty": False,
            "item_count": 0,
            "product_id": 0,
            "sku": "",
            "quantity": 0,
            "selected_shipping_rate_count": 0,
            "selected_shipping_rate_cost": "",
            "total_price": "",
            "currency_code": "",
            "currency_minor_unit": 0,
            "payment_methods": [],
        },
        "checkout": {
            "http_status": 0,
            "payment_method": "woocommerce_payments",
            "payment_data_keys": ["wcpay-payment-method"],
            "payment_status": "",
            "order_status": "",
            "structured_error": False,
        },
        "errors": [],
        "blockers": [],
    }


def derive_verdict(payload: dict[str, Any]) -> None:
    errors: list[str] = []
    blockers: list[str] = []
    cart = payload["cart"]
    checkout = payload["checkout"]
    if payload["request_sequence"] != EXPECTED_SEQUENCE:
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
        if not isinstance(payload["order_id"], int) or payload["order_id"] <= 0:
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
    payload["errors"] = list(dict.fromkeys(errors))
    payload["blockers"] = list(dict.fromkeys(blockers))
    payload["status"] = (
        "blocked" if payload["blockers"] else "fail" if payload["errors"] else "pass"
    )


def project_cart(payload: Any, product_id: int, *, started_empty: bool) -> dict[str, Any]:
    if not isinstance(payload, dict):
        raise Blocked("Store API cart response was not an object.")
    items = payload.get("items")
    totals = payload.get("totals")
    shipping_packages = payload.get("shipping_rates")
    payment_methods = payload.get("payment_methods")
    if (
        not isinstance(items, list)
        or not isinstance(totals, dict)
        or not isinstance(shipping_packages, list)
        or not isinstance(payment_methods, list)
        or not all(isinstance(method, str) and method for method in payment_methods)
    ):
        raise Blocked("Store API cart response had an invalid schema.")
    item = items[0] if len(items) == 1 and isinstance(items[0], dict) else {}
    rates: list[dict[str, Any]] = []
    for package in shipping_packages:
        if not isinstance(package, dict) or not isinstance(
            package.get("shipping_rates"), list
        ):
            raise Blocked("Store API shipping response had an invalid schema.")
        for rate in package["shipping_rates"]:
            if not isinstance(rate, dict):
                raise Blocked("Store API shipping response had an invalid schema.")
            rates.append(rate)
    selected_rates = [rate for rate in rates if rate.get("selected") is True]
    selected = selected_rates[0] if len(selected_rates) == 1 else {}
    return {
        "started_empty": started_empty,
        "item_count": len(items),
        "product_id": item.get("id", 0),
        "sku": item.get("sku", ""),
        "quantity": item.get("quantity", 0),
        "selected_shipping_rate_count": len(selected_rates),
        "selected_shipping_rate_cost": selected.get("price", ""),
        "total_price": totals.get("total_price", ""),
        "currency_code": totals.get("currency_code", ""),
        "currency_minor_unit": totals.get("currency_minor_unit", 0),
        "payment_methods": payment_methods,
    }


class StoreApiClient:
    def __init__(self, base_url: str, timeout: float, key: bytes, transcript: dict[str, Any]):
        self.origin, self.base_url = allowed_base_url(base_url)
        self.timeout = timeout
        self.key = key
        self.transcript = transcript
        self.opener = urllib.request.build_opener(RefuseRedirects())
        self.token: str | None = None

    def request(
        self,
        method: str,
        path: str,
        body: dict[str, Any] | None = None,
    ) -> tuple[int, dict[str, Any]]:
        headers = {"Accept": "application/json"}
        encoded = None
        if body is not None:
            headers["Content-Type"] = "application/json"
            encoded = canonical(body)
        if self.token is not None:
            headers["Cart-Token"] = self.token
        request = urllib.request.Request(
            self.base_url + API_PREFIX + path,
            data=encoded,
            headers=headers,
            method=method,
        )
        self.transcript["request_sequence"].append(f"{method} {path}")
        try:
            response = self.opener.open(request, timeout=self.timeout)
        except urllib.error.HTTPError as error:
            response = error
        except (TimeoutError, socket.timeout, urllib.error.URLError, OSError) as error:
            raise Blocked("Store API transport was unavailable.") from error
        if 300 <= response.status < 400:
            self.transcript["redirect_count"] += 1
            raise Blocked("Store API endpoint redirected unexpectedly.")
        try:
            raw = response.read(MAX_BODY_BYTES + 1)
        except (TimeoutError, socket.timeout, OSError) as error:
            raise Blocked("Store API response was transport-ambiguous.") from error
        if len(raw) > MAX_BODY_BYTES:
            raise Blocked("Store API response exceeded the evidence bound.")
        try:
            payload = load_unique_json(raw.decode("utf-8"))
        except (UnicodeError, json.JSONDecodeError, ValueError) as error:
            raise Blocked("Store API response was not strict JSON.") from error
        if not isinstance(payload, dict):
            raise Blocked("Store API response was not a JSON object.")
        next_token = response.headers.get("Cart-Token")
        if not isinstance(next_token, str) or not next_token:
            self.transcript["cart_token_lineage"] = False
            raise Blocked("Store API response omitted its Cart Token.")
        if self.token is None:
            self.transcript["cart_token_fingerprint"] = "hmac-sha256:" + hmac.new(
                self.key,
                TOKEN_DOMAIN + next_token.encode("utf-8"),
                hashlib.sha256,
            ).hexdigest()
        self.token = next_token
        self.transcript["cart_token_lineage"] = True
        return response.status, payload


def fixed_addresses(store: str) -> tuple[dict[str, str], dict[str, str]]:
    common = {
        "first_name": "SC02",
        "last_name": "Guest",
        "company": "",
        "address_1": "60 29th Street",
        "address_2": "",
        "city": "San Francisco",
        "state": "CA",
        "postcode": "94110",
        "country": "US",
    }
    billing = dict(common)
    billing.update({"email": f"sc02-{store}@example.test", "phone": ""})
    return billing, dict(common)


def run_checkout(client: StoreApiClient, args: argparse.Namespace) -> None:
    transcript = client.transcript
    _, initial = client.request("GET", "/wc/store/v1/cart")
    initial_items = initial.get("items")
    if not isinstance(initial_items, list):
        raise Blocked("Initial Store API cart had an invalid schema.")
    transcript["cart"] = project_cart(
        initial,
        args.product_id,
        started_empty=len(initial_items) == 0,
    )
    if initial_items:
        raise Blocked("Anonymous Store API cart was not empty.")

    _, added = client.request(
        "POST",
        "/wc/store/v1/cart/add-item",
        {"id": args.product_id, "quantity": 1},
    )
    transcript["cart"] = project_cart(added, args.product_id, started_empty=True)
    billing, shipping = fixed_addresses(args.store)
    _, customer = client.request(
        "POST",
        "/wc/store/v1/cart/update-customer",
        {"billing_address": billing, "shipping_address": shipping},
    )
    transcript["cart"] = project_cart(customer, args.product_id, started_empty=True)
    packages = customer.get("shipping_rates")
    rates: list[tuple[Any, Any, Any]] = []
    if isinstance(packages, list):
        for package in packages:
            if isinstance(package, dict) and isinstance(
                package.get("shipping_rates"), list
            ):
                for rate in package["shipping_rates"]:
                    if isinstance(rate, dict):
                        rates.append(
                            (package.get("package_id"), rate.get("rate_id"), rate.get("price"))
                        )
    if len(rates) != 1 or rates[0][2] != "0" or not isinstance(rates[0][1], str):
        raise Blocked("Store API shipping rate was missing, ambiguous, or non-zero.")
    _, selected = client.request(
        "POST",
        "/wc/store/v1/cart/select-shipping-rate",
        {"package_id": rates[0][0], "rate_id": rates[0][1]},
    )
    transcript["cart"] = project_cart(selected, args.product_id, started_empty=True)
    _, final = client.request("GET", "/wc/store/v1/cart")
    transcript["cart"] = project_cart(final, args.product_id, started_empty=True)

    status, checkout = client.request(
        "POST",
        "/wc/store/v1/checkout",
        {
            "billing_address": billing,
            "shipping_address": shipping,
            "customer_note": args.run_token,
            "payment_method": "woocommerce_payments",
            "payment_data": [
                {"key": "wcpay-payment-method", "value": "pm_card_visa"}
            ],
        },
    )
    transcript["checkout"]["http_status"] = status
    if status == 200:
        payment_result = checkout.get("payment_result")
        order_id = checkout.get("order_id")
        order_status = checkout.get("status")
        if (
            not isinstance(payment_result, dict)
            or not isinstance(order_id, int)
            or isinstance(order_id, bool)
            or order_id <= 0
            or not isinstance(order_status, str)
        ):
            raise Blocked("Checkout response did not contain a trustworthy order identity.")
        transcript["order_id"] = order_id
        transcript["checkout"]["payment_status"] = payment_result.get(
            "payment_status", ""
        )
        transcript["checkout"]["order_status"] = order_status
    else:
        transcript["checkout"]["structured_error"] = (
            400 <= status < 500
            and isinstance(checkout.get("code"), str)
            and isinstance(checkout.get("message"), str)
        )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--store", choices=("ref", "target"), required=True)
    parser.add_argument("--run-stamp", required=True)
    parser.add_argument("--run-token", required=True)
    parser.add_argument("--product-id", type=int, required=True)
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument("--output", required=True)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    key_hex = os.environ.get(KEY_ENV, "")
    if KEY_RE.fullmatch(key_hex) is None:
        print("SC-02 Store API context key is unavailable.", file=sys.stderr)
        return STATUS_EXIT["blocked"]
    key = bytes.fromhex(key_hex)
    try:
        output = safe_output_path(Path(args.output))
    except (Blocked, OSError, RuntimeError, TypeError, ValueError) as error:
        print(str(error), file=sys.stderr)
        return STATUS_EXIT["blocked"]
    if (
        RUN_RE.fullmatch(args.run_stamp) is None
        or args.run_token != f"sc02-{args.run_stamp}-{args.store}"
        or args.product_id <= 0
        or not 0 < args.timeout <= 60
    ):
        origin_fingerprint = "hmac-sha256:" + hmac.new(
            key, ORIGIN_DOMAIN + args.base_url.encode("utf-8"), hashlib.sha256
        ).hexdigest()
        payload = empty_transcript(
            args.store, args.run_stamp, max(args.product_id, 0), origin_fingerprint
        )
        derive_verdict(payload)
        return write_payload(payload, output, key)

    origin_fingerprint = "hmac-sha256:" + hmac.new(
        key, ORIGIN_DOMAIN + args.base_url.rstrip("/").encode("utf-8"), hashlib.sha256
    ).hexdigest()
    transcript = empty_transcript(
        args.store, args.run_stamp, args.product_id, origin_fingerprint
    )
    try:
        client = StoreApiClient(args.base_url, args.timeout, key, transcript)
        transcript["local_origin"] = True
        transcript["origin_fingerprint"] = "hmac-sha256:" + hmac.new(
            key, ORIGIN_DOMAIN + client.origin.encode("utf-8"), hashlib.sha256
        ).hexdigest()
        run_checkout(client, args)
    except (Blocked, OSError, RuntimeError, UnicodeError, ValueError):
        pass
    derive_verdict(transcript)
    try:
        return write_payload(transcript, output, key)
    except (Blocked, OSError, RuntimeError, TypeError, ValueError) as error:
        print(str(error), file=sys.stderr)
        return STATUS_EXIT["blocked"]


if __name__ == "__main__":
    raise SystemExit(main())
