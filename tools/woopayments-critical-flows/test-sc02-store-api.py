#!/usr/bin/env python3
"""Hermetic Store API regressions for deterministic SC-02 checkout."""

from __future__ import annotations

import json
import os
import subprocess
import threading
import time
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

import pytest


REPO = Path(__file__).resolve().parents[2]
CLIENT = REPO / "tools/woopayments-critical-flows/flows/sc02-store-api.py"
EVIDENCE = REPO / "tools/woopayments-critical-flows/flows/sc02-evidence.py"
RUN_STAMP = "20260718T235900Z-20202"
KEY = "22" * 32


def empty_cart() -> dict:
    return {
        "items": [],
        "shipping_rates": [],
        "totals": {
            "total_price": "0",
            "currency_code": "USD",
            "currency_minor_unit": 2,
        },
        "payment_methods": [],
    }


def final_cart() -> dict:
    return {
        "items": [
            {
                "id": StoreApiHandler.product_id,
                "quantity": 1,
                "sku": StoreApiHandler.product_sku,
            }
        ],
        "shipping_rates": [
            {
                "package_id": 0,
                "shipping_rates": [
                    {
                        "rate_id": "free_shipping:1",
                        "selected": True,
                        "price": StoreApiHandler.shipping_price,
                    }
                ],
            }
        ],
        "totals": {
            "total_price": StoreApiHandler.total_price,
            "currency_code": StoreApiHandler.currency_code,
            "currency_minor_unit": 2,
        },
        "payment_methods": list(StoreApiHandler.payment_methods),
    }


class StoreApiHandler(BaseHTTPRequestHandler):
    tokens = [f"fixture-cart-token-{index}-not-for-output" for index in range(8)]
    response_index = 0
    cart_ready = False
    requests: list[dict] = []
    checkout_response = (
        200,
        {
            "order_id": 2167,
            "status": "processing",
            "payment_result": {
                "payment_status": "success",
                "payment_details": [],
                "redirect_url": "http://localhost/order-received/2167/",
            },
        },
    )
    delay_checkout_seconds = 0.0
    omit_token_at: int | None = None
    redirect_at: int | None = None
    duplicate_json_at: int | None = None
    oversize_at: int | None = None
    invalid_utf8_at: int | None = None
    initial_cart = empty_cart()
    product_id = 24
    product_sku = "test-lab-beaker-001"
    shipping_price = "0"
    total_price = "2500"
    currency_code = "USD"
    payment_methods = ["woocommerce_payments"]
    checkout_finished = threading.Event()

    @classmethod
    def reset(cls) -> None:
        cls.response_index = 0
        cls.cart_ready = False
        cls.requests = []
        cls.checkout_response = (
            200,
            {
                "order_id": 2167,
                "status": "processing",
                "payment_result": {
                    "payment_status": "success",
                    "payment_details": [],
                    "redirect_url": "http://localhost/order-received/2167/",
                },
            },
        )
        cls.delay_checkout_seconds = 0.0
        cls.omit_token_at = None
        cls.redirect_at = None
        cls.duplicate_json_at = None
        cls.oversize_at = None
        cls.invalid_utf8_at = None
        cls.initial_cart = empty_cart()
        cls.product_id = 24
        cls.product_sku = "test-lab-beaker-001"
        cls.shipping_price = "0"
        cls.total_price = "2500"
        cls.currency_code = "USD"
        cls.payment_methods = ["woocommerce_payments"]
        cls.checkout_finished = threading.Event()

    def log_message(self, format: str, *args: object) -> None:
        return

    def _send(self, status: int, payload: dict) -> None:
        index = type(self).response_index
        if type(self).redirect_at == index:
            type(self).response_index += 1
            self.send_response(302)
            self.send_header("Location", "http://example.test/stolen")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        if type(self).oversize_at == index:
            body = b"x" * (1024 * 1024 + 1)
        elif type(self).invalid_utf8_at == index:
            body = b"\xff\xfe"
        elif type(self).duplicate_json_at == index:
            body = b'{"items":[],"items":[]}'
        else:
            body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        if type(self).omit_token_at != index:
            self.send_header("Cart-Token", type(self).tokens[index])
        type(self).response_index += 1
        self.end_headers()
        try:
            self.wfile.write(body)
        except BrokenPipeError:
            pass

    def do_GET(self) -> None:
        type(self).requests.append(
            {
                "method": "GET",
                "path": self.path,
                "cart_token": self.headers.get("Cart-Token"),
            }
        )
        payload = final_cart() if type(self).cart_ready else type(self).initial_cart
        self._send(200, payload)

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        payload = json.loads(self.rfile.read(length) or b"{}")
        type(self).requests.append(
            {
                "method": "POST",
                "path": self.path,
                "cart_token": self.headers.get("Cart-Token"),
                "payload": payload,
            }
        )
        type(self).cart_ready = True
        if self.path.endswith("/checkout"):
            try:
                if type(self).delay_checkout_seconds:
                    time.sleep(type(self).delay_checkout_seconds)
                status, response = type(self).checkout_response
                self._send(status, response)
            finally:
                type(self).checkout_finished.set()
        else:
            self._send(200, final_cart())


@contextmanager
def running_store_api():
    StoreApiHandler.reset()
    server = ThreadingHTTPServer(("127.0.0.1", 0), StoreApiHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        host, port = server.server_address
        yield f"http://{host}:{port}"
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=5)


@pytest.fixture
def fake_store_api():
    with running_store_api() as url:
        yield url


def run_client(
    base_url: str,
    output: Path,
    *,
    timeout: str = "1",
    store: str = "target",
    product_id: int = 24,
):
    env = dict(os.environ)
    env["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = KEY
    return subprocess.run(
        [
            "python3",
            str(CLIENT),
            "--base-url",
            base_url,
            "--store",
            store,
            "--run-stamp",
            RUN_STAMP,
            "--run-token",
            f"sc02-{RUN_STAMP}-{store}",
            "--product-id",
            str(product_id),
            "--timeout",
            timeout,
            "--output",
            str(output),
        ],
        cwd=REPO,
        text=True,
        capture_output=True,
        check=False,
        env=env,
    )


def validate_transcript(path: Path, status: str, exit_code: int):
    env = dict(os.environ)
    env["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = KEY
    return subprocess.run(
        [
            "python3",
            str(EVIDENCE),
            "validate-http",
            "--input",
            str(path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-status",
            status,
            "--expected-exit-code",
            str(exit_code),
        ],
        cwd=REPO,
        text=True,
        capture_output=True,
        check=False,
        env=env,
    )


def test_guest_checkout_uses_rotating_token_lineage_without_archiving_secrets(
    fake_store_api: str, tmp_path: Path
) -> None:
    output = tmp_path / "http.json"

    result = run_client(fake_store_api, output)

    assert result.returncode == 0, result.stdout + result.stderr
    payload = json.loads(output.read_text(encoding="utf-8"))
    assert payload["status"] == "pass"
    assert [request["path"] for request in StoreApiHandler.requests] == [
        "/wp-json/wc/store/v1/cart",
        "/wp-json/wc/store/v1/cart/add-item",
        "/wp-json/wc/store/v1/cart/update-customer",
        "/wp-json/wc/store/v1/cart/select-shipping-rate",
        "/wp-json/wc/store/v1/cart",
        "/wp-json/wc/store/v1/checkout",
    ]
    assert [request["cart_token"] for request in StoreApiHandler.requests[1:]] == (
        StoreApiHandler.tokens[:5]
    )
    archived = output.read_text(encoding="utf-8")
    assert all(token not in archived for token in StoreApiHandler.tokens)
    assert "pm_card_visa" not in archived
    assert "60 29th Street" not in archived
    assert "sc02-target@example.test" not in archived
    assert payload["checkout"]["payment_data_keys"] == ["wcpay-payment-method"]
    assert payload["cart_token_fingerprint"].startswith("hmac-sha256:")
    assert validate_transcript(output, "pass", 0).returncode == 0


def test_structured_checkout_rejection_is_fail_but_timeout_is_blocked(
    fake_store_api: str, tmp_path: Path
) -> None:
    StoreApiHandler.checkout_response = (
        400,
        {
            "code": "woocommerce_rest_checkout_process_payment_error",
            "message": "Payment failed",
        },
    )
    rejected = run_client(fake_store_api, tmp_path / "rejected.json")
    assert rejected.returncode == 1
    assert json.loads((tmp_path / "rejected.json").read_text())["status"] == "fail"

    StoreApiHandler.reset()
    StoreApiHandler.delay_checkout_seconds = 0.5
    timed_out = run_client(
        fake_store_api, tmp_path / "timeout.json", timeout="0.05"
    )
    assert timed_out.returncode == 3
    assert json.loads((tmp_path / "timeout.json").read_text())["status"] == "blocked"
    assert len(StoreApiHandler.requests) == 6
    assert StoreApiHandler.checkout_finished.wait(timeout=2)


def test_missing_token_redirect_and_duplicate_json_block_without_retry(
    fake_store_api: str, tmp_path: Path
) -> None:
    StoreApiHandler.omit_token_at = 1
    missing_path = tmp_path / "missing-token.json"
    missing = run_client(fake_store_api, missing_path)
    assert missing.returncode == 3
    assert len(StoreApiHandler.requests) == 2
    validated = validate_transcript(missing_path, "blocked", 3)
    assert validated.returncode == 0, validated.stdout + validated.stderr

    StoreApiHandler.reset()
    StoreApiHandler.redirect_at = 2
    redirected = run_client(fake_store_api, tmp_path / "redirect.json")
    assert redirected.returncode == 3
    assert len(StoreApiHandler.requests) == 3

    StoreApiHandler.reset()
    StoreApiHandler.duplicate_json_at = 0
    duplicated = run_client(fake_store_api, tmp_path / "duplicate.json")
    assert duplicated.returncode == 3
    assert len(StoreApiHandler.requests) == 1


def test_nonlocal_url_and_existing_output_are_blocked(tmp_path: Path) -> None:
    nonlocal_output = tmp_path / "nonlocal.json"
    nonlocal_result = run_client("https://example.test", nonlocal_output)
    assert nonlocal_result.returncode == 3
    assert json.loads(nonlocal_output.read_text())["status"] == "blocked"

    with running_store_api() as url:
        existing = tmp_path / "existing.json"
        existing.write_text("do not overwrite", encoding="utf-8")
        existing_result = run_client(url, existing)
        assert existing_result.returncode == 3
        assert existing.read_text(encoding="utf-8") == "do not overwrite"
        assert StoreApiHandler.requests == []


def test_complete_cart_and_checkout_business_mismatches_fail(
    fake_store_api: str, tmp_path: Path
) -> None:
    StoreApiHandler.product_sku = "wrong-sku"
    wrong_product = run_client(fake_store_api, tmp_path / "wrong-product.json")
    assert wrong_product.returncode == 1
    assert len(StoreApiHandler.requests) == 6

    StoreApiHandler.reset()
    StoreApiHandler.payment_methods = []
    unavailable_gateway = run_client(
        fake_store_api, tmp_path / "unavailable-gateway.json"
    )
    assert unavailable_gateway.returncode == 1
    assert len(StoreApiHandler.requests) == 6

    StoreApiHandler.reset()
    StoreApiHandler.checkout_response[1]["status"] = "pending"
    unpaid_order = run_client(fake_store_api, tmp_path / "unpaid-order.json")
    assert unpaid_order.returncode == 1
    assert len(StoreApiHandler.requests) == 6


def test_preexisting_cart_and_ambiguous_shipping_block_before_checkout(
    fake_store_api: str, tmp_path: Path
) -> None:
    StoreApiHandler.initial_cart = final_cart()
    preexisting = run_client(fake_store_api, tmp_path / "preexisting.json")
    assert preexisting.returncode == 3
    assert len(StoreApiHandler.requests) == 1

    StoreApiHandler.reset()
    StoreApiHandler.shipping_price = "5"
    ambiguous = run_client(fake_store_api, tmp_path / "shipping.json")
    assert ambiguous.returncode == 3
    assert len(StoreApiHandler.requests) == 3


@pytest.mark.parametrize("fault", ["oversize_at", "invalid_utf8_at"])
def test_unbounded_or_invalid_response_blocks(
    fake_store_api: str, tmp_path: Path, fault: str
) -> None:
    setattr(StoreApiHandler, fault, 0)
    result = run_client(fake_store_api, tmp_path / f"{fault}.json")
    assert result.returncode == 3
    assert len(StoreApiHandler.requests) == 1


def test_symlinked_output_file_or_parent_is_rejected_before_http(
    tmp_path: Path,
) -> None:
    with running_store_api() as url:
        real_file = tmp_path / "real.json"
        real_file.write_text("preserve", encoding="utf-8")
        linked_file = tmp_path / "linked.json"
        linked_file.symlink_to(real_file)
        linked_result = run_client(url, linked_file)
        assert linked_result.returncode == 3
        assert StoreApiHandler.requests == []

    with running_store_api() as url:
        real_parent = tmp_path / "real-parent"
        real_parent.mkdir()
        linked_parent = tmp_path / "linked-parent"
        linked_parent.symlink_to(real_parent, target_is_directory=True)
        parent_result = run_client(url, linked_parent / "output.json")
        assert parent_result.returncode == 3
        assert StoreApiHandler.requests == []
