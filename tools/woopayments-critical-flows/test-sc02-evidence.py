#!/usr/bin/env python3
"""Regression checks for deterministic SC-02 evidence."""

from __future__ import annotations

import json
import hashlib
import hmac
import os
import subprocess
import tempfile
from pathlib import Path

import pytest


REPO = Path(__file__).resolve().parents[2]
TOOL = REPO / "tools/woopayments-critical-flows/flows/sc02-evidence.py"
RUN_STAMP = "20260718T235900Z-20202"
KEY = "22" * 32
HTTP_DOMAIN = b"woopayments-sc02-http-v1\0"
RESULT_DOMAIN = b"woopayments-sc02-result-v1\0"
LOG_DOMAIN = b"woopayments-sc02-log-v1\0"
TARGET_PASS_FILES = (
    "ref-preflight.json",
    "ref-http.json",
    "ref-post.json",
    "ref-result.json",
    "ref-log-scan.json",
    "ref-execution.json",
    "target-preflight.json",
    "target-http.json",
    "target-post.json",
    "target-result.json",
    "target-log-scan.json",
    "target-execution.json",
    "comparison.json",
)


def run_tool(*args: str, input_text: str | None = None, key: str | None = KEY):
    """Run the SC-02 evidence CLI with an isolated environment."""
    env = dict(os.environ)
    if key is None:
        env.pop("CRITICAL_FLOWS_RUN_CONTEXT_KEY", None)
    else:
        env["CRITICAL_FLOWS_RUN_CONTEXT_KEY"] = key
    return subprocess.run(
        ["python3", str(TOOL), *args],
        cwd=REPO,
        input=input_text,
        text=True,
        capture_output=True,
        check=False,
        env=env,
    )


def canonical(value: object) -> bytes:
    return json.dumps(
        value, sort_keys=True, separators=(",", ":"), ensure_ascii=False
    ).encode("utf-8")


def payload_digest(payload: dict) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def seal_payload(payload: dict, domain: bytes) -> dict:
    sealed = dict(payload)
    key_bytes = bytes.fromhex(KEY)
    sealed["key_fingerprint"] = "sha256:" + hashlib.sha256(key_bytes).hexdigest()
    unsigned = dict(sealed)
    unsigned.pop("context_hmac", None)
    unsigned.pop("payload_sha256", None)
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(
        key_bytes, domain + canonical(unsigned), hashlib.sha256
    ).hexdigest()
    sealed["payload_sha256"] = payload_digest(sealed)
    return sealed


def happy_http(store: str, product_id: int, order_id: int) -> dict:
    return seal_payload(
        {
            "schema": "woopayments_sc02_http.v1",
            "status": "pass",
            "store": store,
            "run_stamp": RUN_STAMP,
            "product_id": product_id,
            "order_id": order_id,
            "cart_token_fingerprint": "hmac-sha256:"
            + ("1" if store == "ref" else "2") * 64,
            "origin_fingerprint": "hmac-sha256:"
            + ("3" if store == "ref" else "4") * 64,
            "request_sequence": [
                "GET /wc/store/v1/cart",
                "POST /wc/store/v1/cart/add-item",
                "POST /wc/store/v1/cart/update-customer",
                "POST /wc/store/v1/cart/select-shipping-rate",
                "GET /wc/store/v1/cart",
                "POST /wc/store/v1/checkout",
            ],
            "cart_token_lineage": True,
            "local_origin": True,
            "redirect_count": 0,
            "cart": {
                "started_empty": True,
                "item_count": 1,
                "product_id": product_id,
                "sku": "test-lab-beaker-001",
                "quantity": 1,
                "selected_shipping_rate_count": 1,
                "selected_shipping_rate_cost": "0",
                "total_price": "2500",
                "currency_code": "USD",
                "currency_minor_unit": 2,
                "payment_methods": ["woocommerce_payments"],
            },
            "checkout": {
                "http_status": 200,
                "payment_method": "woocommerce_payments",
                "payment_data_keys": ["wcpay-payment-method"],
                "payment_status": "success",
                "order_status": "processing",
                "structured_error": False,
            },
            "errors": [],
            "blockers": [],
        },
        HTTP_DOMAIN,
    )


def reseal_http(payload: dict) -> dict:
    unsigned = dict(payload)
    for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
        unsigned.pop(field, None)
    return seal_payload(unsigned, HTTP_DOMAIN)


def write_normalized_state(path: Path, raw: dict, phase: str, store: str) -> dict:
    result, payload = normalize_state(raw, phase, store)
    assert result.returncode in {0, 1, 3}, result.stdout + result.stderr
    path.write_text(result.stdout, encoding="utf-8")
    return payload


def build_store_result(root: Path, store: str, product_id: int, order_id: int) -> Path:
    preflight_path = root / f"{store}-preflight.json"
    post_path = root / f"{store}-post.json"
    http_path = root / f"{store}-http.json"
    output_path = root / f"{store}-result.json"
    write_normalized_state(preflight_path, raw_preflight(store), "preflight", store)
    write_normalized_state(
        post_path, raw_post(store, product_id, order_id), "post", store
    )
    http_path.write_text(
        json.dumps(happy_http(store, product_id, order_id)), encoding="utf-8"
    )
    result = run_tool(
        "evaluate-store",
        "--preflight",
        str(preflight_path),
        "--http",
        str(http_path),
        "--post",
        str(post_path),
        "--output",
        str(output_path),
        "--store",
        store,
        "--run-stamp",
        RUN_STAMP,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    return output_path


def clean_log(store: str) -> dict:
    return seal_payload(
        {
            "schema": "woopayments_sc02_log_scan.v1",
            "status": "pass",
            "store": store,
            "run_stamp": RUN_STAMP,
            "flow_id": "SC-02-blocks-card-checkout",
            "purpose": "clean-debug-log",
            "exit_code": 0,
            "scan_observed": True,
            "match_count": 0,
            "blocker_code": "",
            "source_payload_sha256": "sha256:" + "a" * 64,
        },
        LOG_DOMAIN,
    )


def compare_store_results(
    root: Path, reference: Path, target: Path, output: Path
) -> subprocess.CompletedProcess[str]:
    ref_log = root / "ref-log-scan.json"
    target_log = root / "target-log-scan.json"
    if not ref_log.exists():
        ref_log.write_text(json.dumps(clean_log("ref")), encoding="utf-8")
    if not target_log.exists():
        target_log.write_text(json.dumps(clean_log("target")), encoding="utf-8")
    return run_tool(
        "compare",
        "--reference-result",
        str(reference),
        "--target-result",
        str(target),
        "--reference-log",
        str(ref_log),
        "--target-log",
        str(target_log),
        "--output",
        str(output),
        "--run-stamp",
        RUN_STAMP,
    )


def build_execution(
    root: Path,
    store: str,
    result_path: Path,
    log_path: Path,
    comparison_path: Path | None = None,
) -> Path:
    output = root / f"{store}-execution.json"
    arguments = [
        "execution",
        "--store",
        store,
        "--status",
        "pass",
        "--exit-code",
        "0",
        "--stage",
        "post",
        "--run-stamp",
        RUN_STAMP,
        "--output",
        str(output),
        "--preflight-exit-code",
        "0",
        "--http-exit-code",
        "0",
        "--post-exit-code",
        "0",
        "--result-exit-code",
        "0",
        "--log-exit-code",
        "0",
        "--result",
        str(result_path),
        "--log-scan",
        str(log_path),
    ]
    if comparison_path is not None:
        arguments.extend(
            [
                "--comparison-exit-code",
                "0",
                "--comparison",
                str(comparison_path),
            ]
        )
    created = run_tool(*arguments)
    assert created.returncode == 0, created.stdout + created.stderr
    return output


def build_complete_pass_packet(root: Path) -> dict[str, Path]:
    reference = build_store_result(root, "ref", 58, 1441)
    target = build_store_result(root, "target", 24, 1581)
    comparison = root / "comparison.json"
    compared = compare_store_results(root, reference, target, comparison)
    assert compared.returncode == 0
    ref_log = root / "ref-log-scan.json"
    target_log = root / "target-log-scan.json"
    ref_log.write_text(json.dumps(clean_log("ref")), encoding="utf-8")
    target_log.write_text(json.dumps(clean_log("target")), encoding="utf-8")
    ref_execution = build_execution(root, "ref", reference, ref_log)
    target_execution = build_execution(
        root, "target", target, target_log, comparison
    )
    paths = {path.name: path for path in root.iterdir() if path.is_file()}
    paths[ref_execution.name] = ref_execution
    paths[target_execution.name] = target_execution
    return paths


def target_manifest_arguments(
    paths: dict[str, Path], manifest: Path, names: tuple[str, ...] = TARGET_PASS_FILES
) -> list[str]:
    arguments = [
        "manifest",
        "--store",
        "target",
        "--status",
        "pass",
        "--exit-code",
        "0",
        "--stage",
        "post",
        "--run-stamp",
        RUN_STAMP,
        "--run-scope",
        "partial",
        "--output",
        str(manifest),
    ]
    for name in names:
        arguments.extend(["--file", str(paths[name])])
    return arguments


def raw_preflight(store: str) -> dict:
    """Return a complete raw preflight observation for one store."""
    return {
        "schema": "woopayments_sc02_state_raw.v1",
        "phase": "preflight",
        "store": store,
        "run_stamp": RUN_STAMP,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "gateway": {
            "id": "woocommerce_payments",
            "class": (
                "WC_Payment_Gateway_WCPay"
                if store == "ref"
                else "NativeWooPaymentsGateway"
            ),
            "available": True,
            "test_mode": True,
            "connected": True,
        },
        "product": {
            "id": 58 if store == "ref" else 24,
            "sku": "test-lab-beaker-001",
            "price": "25.00",
            "purchasable": True,
            "in_stock": True,
        },
        "store_currency": "USD",
        "connected_account_id": (
            "acct_ref_fixture" if store == "ref" else "acct_target_fixture"
        ),
        "blockers": [],
    }


def raw_post(store: str, product_id: int, order_id: int) -> dict:
    """Return a complete raw post-checkout observation for one store."""
    intent_id = f"pi_{store}_fixture"
    charge_id = f"ch_{store}_fixture"
    payment_method = f"pm_{store}_fixture"
    return {
        "schema": "woopayments_sc02_state_raw.v1",
        "phase": "post",
        "store": store,
        "run_stamp": RUN_STAMP,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "order": {
            "id": order_id,
            "status": "processing",
            "created_via": "store-api",
            "payment_method": "woocommerce_payments",
            "customer_id": 0,
            "customer_note": f"sc02-{RUN_STAMP}-{store}",
            "total": "25.00",
            "currency": "USD",
            "date_paid_present": True,
            "transaction_id": intent_id,
            "intent_id": intent_id,
            "charge_id": charge_id,
            "line_items": [
                {
                    "product_id": product_id,
                    "sku": "test-lab-beaker-001",
                    "quantity": 1,
                    "total": "25.00",
                }
            ],
        },
        "provider": {
            "intent": {
                "id": intent_id,
                "object": "payment_intent",
                "status": "succeeded",
                "amount": 2500,
                "currency": "usd",
                "latest_charge": charge_id,
                "payment_method": payment_method,
            },
            "charge": {
                "id": charge_id,
                "object": "charge",
                "status": "succeeded",
                "paid": True,
                "amount": 2500,
                "amount_captured": 2500,
                "currency": "usd",
                "payment_intent": intent_id,
                "payment_method": payment_method,
            },
        },
        "blockers": [],
    }


def normalize_state(raw: dict, phase: str, store: str):
    """Normalize a raw state observation through the public CLI."""
    result = run_tool(
        "normalize-state",
        "--phase",
        phase,
        "--store",
        store,
        "--run-stamp",
        RUN_STAMP,
        input_text=json.dumps(raw),
    )
    payload = json.loads(result.stdout) if result.stdout else {}
    return result, payload


def test_state_normalization_requires_context_key_and_exact_payload() -> None:
    raw = raw_preflight("ref")
    missing_key = run_tool(
        "normalize-state",
        "--phase",
        "preflight",
        "--store",
        "ref",
        "--run-stamp",
        RUN_STAMP,
        input_text=json.dumps(raw),
        key=None,
    )
    assert missing_key.returncode == 3
    assert "Traceback" not in missing_key.stderr

    malformed = dict(raw)
    malformed["unexpected"] = True
    result, payload = normalize_state(malformed, "preflight", "ref")
    assert result.returncode == 3
    assert payload["status"] == "blocked"


def test_state_normalization_rejects_wrong_bindings_and_multiple_raw_objects() -> None:
    wrong_store = raw_preflight("ref")
    result, payload = normalize_state(wrong_store, "preflight", "target")
    assert result.returncode == 3
    assert payload["status"] == "blocked"

    wrong_run = raw_preflight("target")
    wrong_run["run_stamp"] = "20260718T000000Z-1"
    result, payload = normalize_state(wrong_run, "preflight", "target")
    assert result.returncode == 3
    assert payload["status"] == "blocked"

    multiple = run_tool(
        "normalize-state",
        "--phase",
        "preflight",
        "--store",
        "target",
        "--run-stamp",
        RUN_STAMP,
        input_text=json.dumps(raw_preflight("target")) + "\n{}",
    )
    assert multiple.returncode == 3
    assert json.loads(multiple.stdout)["status"] == "blocked"


def test_contradictory_or_missing_preflight_prerequisites_block_before_mutation() -> None:
    wrong_price = raw_preflight("target")
    wrong_price["product"]["price"] = "26.00"
    blocked_price, price_blocker = normalize_state(wrong_price, "preflight", "target")
    assert blocked_price.returncode == 3
    assert price_blocker["status"] == "blocked"
    assert "Fixture product price was not USD 25.00." in price_blocker["blockers"]

    disconnected = raw_preflight("target")
    disconnected["gateway"]["connected"] = False
    blocked, blocker = normalize_state(disconnected, "preflight", "target")
    assert blocked.returncode == 3
    assert blocker["status"] == "blocked"
    assert "WooPayments connection was unavailable." in blocker["blockers"]


def test_complete_post_checkout_evidence_passes() -> None:
    result, payload = normalize_state(raw_post("target", 24, 1581), "post", "target")

    assert result.returncode == 0
    assert payload["status"] == "pass"
    assert payload["evidence_complete"] is True


def test_post_oracle_rejects_wrong_created_via() -> None:
    raw = raw_post("target", 24, 1581)
    raw["order"]["created_via"] = "checkout"

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 1
    assert "Order was not created through the Store API." in payload["errors"]


def test_post_oracle_rejects_wrong_provider_currency() -> None:
    raw = raw_post("target", 24, 1581)
    raw["provider"]["charge"]["currency"] = "eur"

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 1
    assert "Provider charge currency did not match USD." in payload["errors"]


def test_post_oracle_rejects_non_guest_and_wrong_run_note() -> None:
    raw = raw_post("target", 24, 1581)
    raw["order"]["customer_id"] = 42
    raw["order"]["customer_note"] = "another-run"

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 1
    assert "Store API checkout was not a guest order." in payload["errors"]
    assert "Order customer note did not bind the current run." in payload["errors"]


def test_post_oracle_treats_extra_line_item_as_product_failure() -> None:
    raw = raw_post("target", 24, 1581)
    raw["order"]["line_items"].append(
        {
            "product_id": 25,
            "sku": "unexpected",
            "quantity": 1,
            "total": "1.00",
        }
    )

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 1
    assert "Order line item did not match the fixed fixture product." in payload[
        "errors"
    ]


def test_post_oracle_treats_missing_local_payment_metadata_as_product_failure() -> None:
    raw = raw_post("target", 24, 1581)
    raw["order"].update(
        {"transaction_id": "", "intent_id": "", "charge_id": ""}
    )
    raw["provider"] = {
        "intent": {
            "id": "",
            "object": "",
            "status": "",
            "amount": 0,
            "currency": "",
            "latest_charge": "",
            "payment_method": "",
        },
        "charge": {
            "id": "",
            "object": "",
            "status": "",
            "paid": False,
            "amount": 0,
            "amount_captured": 0,
            "currency": "",
            "payment_intent": "",
            "payment_method": "",
        },
    }

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 1
    assert "Order PaymentIntent identity was absent or malformed." in payload["errors"]
    assert "Order Charge identity was absent or malformed." in payload["errors"]


def test_post_oracle_preserves_provider_observation_failure_as_blocked() -> None:
    raw = raw_post("target", 24, 1581)
    raw["blockers"] = ["Provider observation failed."]

    result, payload = normalize_state(raw, "post", "target")

    assert result.returncode == 3
    assert payload["status"] == "blocked"
    assert payload["blockers"] == ["Provider observation failed."]


def test_validate_http_accepts_complete_authenticated_transcript() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-http-") as tmp:
        path = Path(tmp) / "target-http.json"
        payload = happy_http("target", 24, 1581)
        path.write_text(json.dumps(payload), encoding="utf-8")

        result = run_tool(
            "validate-http",
            "--input",
            str(path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )

        assert result.returncode == 0, result.stdout + result.stderr
        assert result.stdout.strip() == payload["payload_sha256"]


def test_validate_http_rejects_plainly_rehashed_status_rewrite() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-http-tamper-") as tmp:
        path = Path(tmp) / "target-http.json"
        payload = happy_http("target", 24, 1581)
        payload["status"] = "fail"
        payload["payload_sha256"] = payload_digest(payload)
        path.write_text(json.dumps(payload), encoding="utf-8")

        result = run_tool(
            "validate-http",
            "--input",
            str(path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-status",
            "fail",
            "--expected-exit-code",
            "1",
        )

        assert result.returncode == 3
        assert "Traceback" not in result.stderr


def test_validate_http_rejects_duplicate_keys_and_parent_traversal() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-http-path-") as tmp:
        root = Path(tmp)
        path = root / "http.json"
        path.write_text('{"schema":"one","schema":"two"}', encoding="utf-8")
        duplicated = run_tool(
            "validate-http",
            "--input",
            str(path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )
        assert duplicated.returncode == 3

        path.write_text(json.dumps(happy_http("target", 24, 1581)), encoding="utf-8")
        traversed = run_tool(
            "validate-http",
            "--input",
            str(root / "nested" / ".." / "http.json"),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )
        assert traversed.returncode == 3


def test_evaluate_store_accepts_complete_checkout_packet() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-store-result-") as tmp:
        root = Path(tmp)
        preflight_path = root / "target-preflight.json"
        post_path = root / "target-post.json"
        http_path = root / "target-http.json"
        output_path = root / "target-result.json"
        write_normalized_state(
            preflight_path, raw_preflight("target"), "preflight", "target"
        )
        write_normalized_state(
            post_path, raw_post("target", 24, 1581), "post", "target"
        )
        http_path.write_text(
            json.dumps(happy_http("target", 24, 1581)), encoding="utf-8"
        )

        result = run_tool(
            "evaluate-store",
            "--preflight",
            str(preflight_path),
            "--http",
            str(http_path),
            "--post",
            str(post_path),
            "--output",
            str(output_path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(output_path.read_text(encoding="utf-8"))
        assert payload["status"] == "pass"
        assert payload["contract"]["created_via_store_api"] is True
        assert payload["contract"]["provider_linkage"] is True


def test_evaluate_store_accepts_blocked_preflight_without_http_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-preflight-result-") as tmp:
        root = Path(tmp)
        preflight_path = root / "target-preflight.json"
        output_path = root / "target-result.json"
        raw = raw_preflight("target")
        raw["gateway"]["connected"] = False
        write_normalized_state(preflight_path, raw, "preflight", "target")

        result = run_tool(
            "evaluate-store",
            "--preflight",
            str(preflight_path),
            "--output",
            str(output_path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
        )

        assert result.returncode == 3, result.stdout + result.stderr
        payload = json.loads(output_path.read_text(encoding="utf-8"))
        assert payload["status"] == "blocked"
        assert payload["inputs"]["preflight"] is not None
        assert payload["inputs"]["http"] is None
        assert payload["inputs"]["post"] is None
        assert "WooPayments connection was unavailable." in payload["blockers"]


def test_evaluate_store_rejects_plainly_rehashed_normalized_state_tamper() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-state-tamper-") as tmp:
        root = Path(tmp)
        preflight_path = root / "target-preflight.json"
        output_path = root / "target-result.json"
        write_normalized_state(
            preflight_path, raw_preflight("target"), "preflight", "target"
        )
        payload = json.loads(preflight_path.read_text(encoding="utf-8"))
        payload["facts"]["product"]["price"] = "0.01"
        payload["payload_sha256"] = payload_digest(payload)
        preflight_path.write_text(json.dumps(payload), encoding="utf-8")

        result = run_tool(
            "evaluate-store",
            "--preflight",
            str(preflight_path),
            "--output",
            str(output_path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
        )

        assert result.returncode == 3
        rejected = json.loads(output_path.read_text(encoding="utf-8"))
        assert rejected["status"] == "blocked"
        assert rejected["inputs"]["preflight"] is None
        assert rejected["blockers"] == ["Store evidence packet was invalid."]


def test_evaluate_store_blocks_cross_phase_order_identity_mismatch() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-store-identity-") as tmp:
        root = Path(tmp)
        preflight_path = root / "target-preflight.json"
        post_path = root / "target-post.json"
        http_path = root / "target-http.json"
        output_path = root / "target-result.json"
        write_normalized_state(
            preflight_path, raw_preflight("target"), "preflight", "target"
        )
        write_normalized_state(
            post_path, raw_post("target", 24, 1582), "post", "target"
        )
        http_path.write_text(
            json.dumps(happy_http("target", 24, 1581)), encoding="utf-8"
        )

        result = run_tool(
            "evaluate-store",
            "--preflight",
            str(preflight_path),
            "--http",
            str(http_path),
            "--post",
            str(post_path),
            "--output",
            str(output_path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
        )

        assert result.returncode == 3
        payload = json.loads(output_path.read_text(encoding="utf-8"))
        assert "Checkout and collected order identities did not match." in payload[
            "blockers"
        ]


def test_evaluate_store_classifies_structured_rejection_without_order_as_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-store-rejection-") as tmp:
        root = Path(tmp)
        preflight_path = root / "target-preflight.json"
        http_path = root / "target-http.json"
        output_path = root / "target-result.json"
        write_normalized_state(
            preflight_path, raw_preflight("target"), "preflight", "target"
        )
        http_payload = happy_http("target", 24, 1581)
        http_payload.update({"status": "fail", "order_id": 0})
        http_payload["checkout"].update(
            {
                "http_status": 400,
                "payment_status": "",
                "order_status": "",
                "structured_error": True,
            }
        )
        http_payload["errors"] = ["Checkout returned a structured payment rejection."]
        http_path.write_text(json.dumps(reseal_http(http_payload)), encoding="utf-8")

        result = run_tool(
            "evaluate-store",
            "--preflight",
            str(preflight_path),
            "--http",
            str(http_path),
            "--output",
            str(output_path),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
        )

        assert result.returncode == 1, result.stdout + result.stderr
        payload = json.loads(output_path.read_text(encoding="utf-8"))
        assert payload["status"] == "fail"
        assert payload["identities"]["order_id"] == 0


def test_compare_accepts_distinct_contract_equivalent_store_results() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-comparison-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        output = root / "comparison.json"

        result = compare_store_results(root, reference, target, output)

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert payload["status"] == "pass"
        assert all(payload["parity"].values())


def test_compare_blocks_reused_cross_store_order_identity() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-reused-identity-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        target_payload = json.loads(target.read_text(encoding="utf-8"))
        target_payload["identities"]["order_id"] = 1441
        unsigned = dict(target_payload)
        for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
            unsigned.pop(field, None)
        target.write_text(
            json.dumps(seal_payload(unsigned, RESULT_DOMAIN)), encoding="utf-8"
        )
        output = root / "comparison.json"

        result = compare_store_results(root, reference, target, output)

        assert result.returncode == 3
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert "Reference and target reused an order identity." in payload["blockers"]


@pytest.mark.parametrize(
    "identity,reference_value,expected",
    [
        pytest.param(
            "cart_token_fingerprint",
            "hmac-sha256:" + "1" * 64,
            "Reference and target reused a Cart Token lineage.",
            id="cart-token",
        ),
        pytest.param(
            "origin_fingerprint",
            "hmac-sha256:" + "3" * 64,
            "Reference and target reused a store origin.",
            id="store-origin",
        ),
    ],
)
def test_compare_blocks_reused_cross_store_transport_identity(
    identity: str, reference_value: str, expected: str
) -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-reused-transport-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        target_payload = json.loads(target.read_text(encoding="utf-8"))
        target_payload["identities"][identity] = reference_value
        unsigned = dict(target_payload)
        for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
            unsigned.pop(field, None)
        target.write_text(
            json.dumps(seal_payload(unsigned, RESULT_DOMAIN)), encoding="utf-8"
        )
        output = root / "comparison.json"

        result = compare_store_results(root, reference, target, output)

        assert result.returncode == 3
        assert expected in json.loads(output.read_text(encoding="utf-8"))["blockers"]


@pytest.mark.parametrize(
    "identity,reference_value,expected",
    [
        pytest.param(
            "intent_id",
            "pi_ref_fixture",
            "Reference and target reused a PaymentIntent identity.",
            id="payment-intent",
        ),
        pytest.param(
            "charge_id",
            "ch_ref_fixture",
            "Reference and target reused a Charge identity.",
            id="charge",
        ),
    ],
)
def test_compare_blocks_reused_cross_store_provider_identity(
    identity: str, reference_value: str, expected: str
) -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-reused-provider-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        target_payload = json.loads(target.read_text(encoding="utf-8"))
        target_payload["identities"][identity] = reference_value
        unsigned = dict(target_payload)
        for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
            unsigned.pop(field, None)
        target.write_text(
            json.dumps(seal_payload(unsigned, RESULT_DOMAIN)), encoding="utf-8"
        )
        output = root / "comparison.json"

        result = compare_store_results(root, reference, target, output)

        assert result.returncode == 3
        assert expected in json.loads(output.read_text(encoding="utf-8"))["blockers"]


def test_compare_reports_authenticated_contract_mismatch_as_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-parity-fail-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        target_payload = json.loads(target.read_text(encoding="utf-8"))
        target_payload["contract"]["created_via_store_api"] = False
        target_payload["status"] = "fail"
        target_payload["errors"] = ["Store API attribution contract failed."]
        unsigned = dict(target_payload)
        for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
            unsigned.pop(field, None)
        target.write_text(
            json.dumps(seal_payload(unsigned, RESULT_DOMAIN)), encoding="utf-8"
        )
        output = root / "comparison.json"

        result = compare_store_results(root, reference, target, output)

        assert result.returncode == 1
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert payload["status"] == "fail"
        assert "Store contracts did not have parity." in payload["errors"]


def test_execution_binds_store_result_comparison_and_clean_log() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-execution-") as tmp:
        root = Path(tmp)
        reference = build_store_result(root, "ref", 58, 1441)
        target = build_store_result(root, "target", 24, 1581)
        comparison = root / "comparison.json"
        compared = compare_store_results(root, reference, target, comparison)
        assert compared.returncode == 0
        log = root / "target-log-scan.json"
        log.write_text(json.dumps(clean_log("target")), encoding="utf-8")
        output = root / "target-execution.json"

        result = run_tool(
            "execution",
            "--store",
            "target",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--stage",
            "post",
            "--run-stamp",
            RUN_STAMP,
            "--output",
            str(output),
            "--preflight-exit-code",
            "0",
            "--http-exit-code",
            "0",
            "--post-exit-code",
            "0",
            "--result-exit-code",
            "0",
            "--comparison-exit-code",
            "0",
            "--log-exit-code",
            "0",
            "--result",
            str(target),
            "--comparison",
            str(comparison),
            "--log-scan",
            str(log),
        )

        assert result.returncode == 0, result.stdout + result.stderr
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert payload["status"] == "pass"
        assert payload["verdict_sources"] == []
        assert payload["result_payload_sha256"].startswith("sha256:")


def test_log_normalizer_seals_malformed_raw_scan_as_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-log-blocked-") as tmp:
        root = Path(tmp)
        raw = root / "raw-log.json"
        output = root / "target-log-scan.json"
        raw.write_text('{"schema":"obsolete"}', encoding="utf-8")

        result = run_tool(
            "normalize-log-scan",
            "--input",
            str(raw),
            "--output",
            str(output),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--expected-exit-code",
            "0",
            "--flow-id",
            "SC-02-blocks-card-checkout",
            "--purpose",
            "clean-debug-log",
        )

        assert result.returncode == 3
        payload = json.loads(output.read_text(encoding="utf-8"))
        assert payload["status"] == "blocked"
        assert payload["blocker_code"] == "invalid_scan_evidence"
        assert payload["context_hmac"].startswith("hmac-sha256:")


def test_execution_rejects_pass_relabel_when_result_failed() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-execution-relabel-") as tmp:
        root = Path(tmp)
        target = build_store_result(root, "target", 24, 1581)
        target_payload = json.loads(target.read_text(encoding="utf-8"))
        target_payload["status"] = "fail"
        target_payload["errors"] = ["Checkout contract failed."]
        unsigned = dict(target_payload)
        for field in ("key_fingerprint", "context_hmac", "payload_sha256"):
            unsigned.pop(field, None)
        target.write_text(
            json.dumps(seal_payload(unsigned, RESULT_DOMAIN)), encoding="utf-8"
        )
        log = root / "target-log-scan.json"
        log.write_text(json.dumps(clean_log("target")), encoding="utf-8")

        result = run_tool(
            "execution",
            "--store",
            "target",
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--stage",
            "post",
            "--run-stamp",
            RUN_STAMP,
            "--output",
            str(root / "target-execution.json"),
            "--preflight-exit-code",
            "0",
            "--http-exit-code",
            "0",
            "--post-exit-code",
            "1",
            "--result-exit-code",
            "1",
            "--log-exit-code",
            "0",
            "--result",
            str(target),
            "--log-scan",
            str(log),
        )

        assert result.returncode == 3
        assert not (root / "target-execution.json").exists()


def test_target_pass_manifest_binds_complete_recomputed_dual_store_packet() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-manifest-") as tmp:
        root = Path(tmp)
        paths = build_complete_pass_packet(root)
        manifest = root / "target-manifest.json"
        arguments = target_manifest_arguments(paths, manifest)

        created = run_tool(*arguments)
        assert created.returncode == 0, created.stdout + created.stderr
        validated = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )
        assert validated.returncode == 0, validated.stdout + validated.stderr
        assert validated.stdout.strip() == "sha256:" + hashlib.sha256(
            manifest.read_bytes()
        ).hexdigest()


def test_bound_manifest_rejects_plainly_rehashed_result_rewrite() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-manifest-rewrite-") as tmp:
        root = Path(tmp)
        paths = build_complete_pass_packet(root)
        manifest = root / "target-manifest.json"
        arguments = target_manifest_arguments(paths, manifest)
        assert run_tool(*arguments).returncode == 0

        result_path = paths["target-result.json"]
        result_payload = json.loads(result_path.read_text(encoding="utf-8"))
        result_payload["status"] = "fail"
        result_payload["errors"] = ["Rewritten result."]
        result_payload["payload_sha256"] = payload_digest(result_payload)
        result_path.write_text(json.dumps(result_payload), encoding="utf-8")
        manifest_payload = json.loads(manifest.read_text(encoding="utf-8"))
        manifest_payload["files"]["target-result.json"] = {
            "file_sha256": "sha256:"
            + hashlib.sha256(result_path.read_bytes()).hexdigest(),
            "payload_sha256": result_payload["payload_sha256"],
        }
        manifest_payload["payload_sha256"] = payload_digest(manifest_payload)
        manifest.write_text(json.dumps(manifest_payload), encoding="utf-8")

        validated = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )

        assert validated.returncode == 3
        assert "Traceback" not in validated.stderr


def test_manifest_builder_rejects_missing_extra_and_swapped_store_artifacts() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-manifest-shape-") as tmp:
        root = Path(tmp)
        paths = build_complete_pass_packet(root)

        missing_names = tuple(
            name for name in TARGET_PASS_FILES if name != "target-post.json"
        )
        missing = run_tool(
            *target_manifest_arguments(
                paths, root / "missing-manifest.json", missing_names
            )
        )
        assert missing.returncode == 3

        extra = root / "unexpected.json"
        extra.write_text("{}", encoding="utf-8")
        paths[extra.name] = extra
        extra_names = TARGET_PASS_FILES + (extra.name,)
        extra_result = run_tool(
            *target_manifest_arguments(
                paths, root / "extra-manifest.json", extra_names
            )
        )
        assert extra_result.returncode == 3

        paths["target-result.json"].write_bytes(paths["ref-result.json"].read_bytes())
        swapped = run_tool(
            *target_manifest_arguments(paths, root / "swapped-manifest.json")
        )
        assert swapped.returncode == 3


def test_bound_manifest_rejects_symlinked_artifact_and_wrong_scope() -> None:
    with tempfile.TemporaryDirectory(prefix="sc02-manifest-symlink-") as tmp:
        root = Path(tmp)
        paths = build_complete_pass_packet(root)
        manifest = root / "target-manifest.json"
        assert run_tool(*target_manifest_arguments(paths, manifest)).returncode == 0

        wrong_scope = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "full",
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )
        assert wrong_scope.returncode == 3

        target_post = paths["target-post.json"]
        target_post.unlink()
        target_post.symlink_to(paths["ref-post.json"])
        symlinked = run_tool(
            "validate-bound-manifest",
            "--manifest",
            str(manifest),
            "--store",
            "target",
            "--run-stamp",
            RUN_STAMP,
            "--run-scope",
            "partial",
            "--expected-status",
            "pass",
            "--expected-exit-code",
            "0",
        )
        assert symlinked.returncode == 3
