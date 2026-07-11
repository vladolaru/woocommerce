#!/usr/bin/env python3
"""Validate and classify WooPayments LPM browser evidence."""

from __future__ import annotations

import argparse
import json
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any
from urllib.parse import urlparse


BROWSER_EVIDENCE_SCHEMA = "woopayments_lpm_checkout_browser_evidence.v1"
MANUAL_COMPLETION_SCHEMA = "woopayments_lpm_manual_completion.v1"
PROVIDER_OBSERVATION_SCHEMA = "woopayments_lpm_provider_observation.v1"
MANUAL_AUTOMATION_DISPOSITION = "manual_customer_action"
MANUAL_BLOCKER_CODE = "manual_payment_authorization_required"
MANUAL_METHOD_CONTRACTS = {
    "klarna": {
        "gateway_id": "woocommerce_payments_klarna",
        "stripe_type": "klarna",
        "method_family": "hosted_action",
    },
    "wechat_pay": {
        "gateway_id": "woocommerce_payments_wechat_pay",
        "stripe_type": "wechat_pay",
        "method_family": "customer_action",
    },
}


@dataclass(frozen=True)
class EvidenceContext:
    role: str
    method: str
    base_url: str
    gateway_id: str
    stripe_type: str
    method_family: str
    automation_disposition: str


def read_evidence(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise ValueError("evidence root must be an object")
    return value


def write_evidence(path: Path, payload: dict[str, Any]) -> None:
    path.write_text(
        json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )


def _positive_int(value: Any) -> int | None:
    try:
        parsed = int(value)
    except (TypeError, ValueError):
        return None
    return parsed if parsed > 0 else None


def _is_payment_intent(value: Any) -> bool:
    return isinstance(value, str) and value.startswith("pi_")


def _is_payment_method(value: Any) -> bool:
    return isinstance(value, str) and value.startswith("pm_")


def _expected_runtime_owner(role: str) -> str:
    return {"reference": "plugin", "target": "native"}.get(role, "")


def _expected_adapter(role: str) -> str:
    return {
        "reference": "legacy_woopayments_api_client",
        "target": "native_woocommerce_api_client",
    }.get(role, "")


def _context_errors(context: EvidenceContext) -> list[str]:
    errors: list[str] = []
    contract = MANUAL_METHOD_CONTRACTS.get(context.method)
    if not contract:
        errors.append(f"{context.method!r} is not an accepted manual-completion method")
        return errors
    if context.role not in {"reference", "target"}:
        errors.append(f"invalid evidence role: {context.role!r}")
    if context.automation_disposition != MANUAL_AUTOMATION_DISPOSITION:
        errors.append(
            "automation disposition is not the explicit manual customer-action disposition"
        )
    for field, actual, expected in (
        ("gateway_id", context.gateway_id, contract["gateway_id"]),
        ("stripe_type", context.stripe_type, contract["stripe_type"]),
        ("method_family", context.method_family, contract["method_family"]),
    ):
        if actual != expected:
            errors.append(f"{field} mismatch: expected {expected!r}, got {actual!r}")
    try:
        parsed = urlparse(context.base_url)
    except ValueError:
        parsed = None
    if not parsed or parsed.scheme not in {"http", "https"} or not parsed.hostname:
        errors.append("base_url must be an explicit HTTP(S) URL")
    elif (
        parsed.hostname not in {"localhost", "127.0.0.1"}
        and not parsed.hostname.endswith(".localhost")
    ):
        errors.append("base_url must identify a local store")
    return errors


def _provider_observation_errors(
    observation: Any,
    context: EvidenceContext,
    *,
    order_id: int,
    payment_intent_id: str,
) -> list[str]:
    errors: list[str] = []
    if not isinstance(observation, dict):
        return ["missing provider_observation"]

    expected_values = {
        "schema": PROVIDER_OBSERVATION_SCHEMA,
        "status": "pass",
        "role": context.role,
        "method": context.method,
        "order_id": order_id,
        "payment_intent_id": payment_intent_id,
        "expected_payment_method_type": context.stripe_type,
        "provider_payment_intent_id": payment_intent_id,
        "observed_payment_method_type": context.stripe_type,
        "runtime_owner": _expected_runtime_owner(context.role),
        "adapter": _expected_adapter(context.role),
    }
    for field, expected in expected_values.items():
        if observation.get(field) != expected:
            errors.append(
                f"provider_observation {field} mismatch: expected {expected!r}, "
                f"got {observation.get(field)!r}"
            )

    request_id = observation.get("observation_request_id")
    if not isinstance(request_id, str) or not request_id.startswith("lpm-provider-"):
        errors.append("provider_observation lacks a fresh gate-owned request id")

    charge_intent_id = observation.get("provider_charge_payment_intent_id")
    if charge_intent_id not in (None, "", payment_intent_id):
        errors.append("provider charge PaymentIntent does not match the persisted intent")

    for field in ("fetched", "matches_expected", "transport_available", "test_mode"):
        if observation.get(field) is not True:
            errors.append(f"provider_observation {field} must be true")

    for field in ("api_client_class", "account_id", "source"):
        if observation.get(field) in (None, "", 0):
            errors.append(f"provider_observation missing {field}")

    if observation.get("blocker_code") not in (None, ""):
        errors.append("passing provider_observation must not carry a blocker code")
    provider_errors = observation.get("errors")
    if provider_errors not in (None, []):
        errors.append("passing provider_observation must not carry errors")
    return errors


def _checkout_request_proven(page: dict[str, Any], context: EvidenceContext) -> bool:
    checkout_requests = page.get("checkout_requests")
    if not isinstance(checkout_requests, list):
        return False
    for request in checkout_requests:
        if not isinstance(request, dict):
            continue
        credentials = request.get("credentials")
        payment_method = (
            credentials.get("wcpay-payment-method")
            if isinstance(credentials, dict)
            else None
        )
        request_url = str(request.get("url") or "")
        if (
            request.get("method") == "POST"
            and request.get("payment_method") == context.gateway_id
            and request.get("payment_method_error_code") in (None, "")
            and request.get("payment_method_error_message") in (None, "")
            and _positive_int(request.get("field_count")) is not None
            and request_url.startswith(context.base_url.rstrip("/") + "/")
            and "wc-ajax=checkout" in request_url
            and isinstance(payment_method, dict)
            and payment_method.get("present") is True
            and payment_method.get("credential_prefix") == "pm_"
            and _positive_int(payment_method.get("length")) is not None
        ):
            return True
    return False


def _checkout_response_proven(page: dict[str, Any], order_id: int | None) -> bool:
    checkout_responses = page.get("checkout_responses")
    if not isinstance(checkout_responses, list) or order_id is None:
        return False
    return any(
        isinstance(response, dict)
        and isinstance(response.get("status"), int)
        and 200 <= response["status"] < 300
        and response.get("result") == "success"
        and _positive_int(response.get("order_id")) == order_id
        for response in checkout_responses
    )


def manual_candidate_errors(
    payload: dict[str, Any], context: EvidenceContext
) -> list[str]:
    errors = _context_errors(context)
    expected_values = {
        "schema": BROWSER_EVIDENCE_SCHEMA,
        "status": "fail",
        "role": context.role,
        "method": context.method,
        "base_url": context.base_url,
        "gateway_id": context.gateway_id,
        "stripe_payment_method_type": context.stripe_type,
        "method_family": context.method_family,
        "automation_disposition": context.automation_disposition,
        "selected_gateway_id": context.gateway_id,
        "order_payment_method": context.gateway_id,
        "used_base_card_gateway": False,
    }
    for field, expected in expected_values.items():
        if payload.get(field) != expected:
            errors.append(
                f"{field} mismatch: expected {expected!r}, got {payload.get(field)!r}"
            )

    if payload.get("surface") not in {"classic", "blocks"}:
        errors.append("surface must be classic or blocks")

    order_id = _positive_int(payload.get("order_id"))
    if order_id is None:
        errors.append("missing positive order_id")
    payment_intent_id = payload.get("payment_intent_id")
    if not _is_payment_intent(payment_intent_id):
        errors.append("payment_intent_id must be a Stripe PaymentIntent id")
        payment_intent_id = ""
    if payload.get("order_received_url") not in (None, "", 0):
        errors.append("manual candidate unexpectedly reached an order-received URL")

    browser_limitation = "checkout did not reach an order-received URL with an order id"
    wrapper_limitation = (
        f"LPM checkout failed for {context.role}/{context.method}: {browser_limitation}"
    )
    failures = payload.get("failures")
    if failures not in ([browser_limitation], [browser_limitation, wrapper_limitation]):
        errors.append("browser failures are not limited to the expected customer-action boundary")

    browser_error = payload.get("error")
    if isinstance(browser_error, dict) and browser_error.get("message") != wrapper_limitation:
        errors.append("browser error is not the expected customer-action boundary")
    elif browser_error not in (None, {}) and not isinstance(browser_error, dict):
        errors.append("browser error must be an object")

    conflicts = payload.get("order_enrichment_conflicts")
    if conflicts not in (None, {}):
        errors.append("browser and persisted order identities conflict")

    enrichment = payload.get("order_enrichment")
    if not isinstance(enrichment, dict):
        errors.append("missing order_enrichment")
    else:
        enrichment_expected = {
            "order_status": "pending",
            "order_payment_method": context.gateway_id,
            "payment_intent_id": payment_intent_id,
            "intention_status": "requires_action",
            "transaction_id": payment_intent_id,
        }
        for field, expected in enrichment_expected.items():
            if enrichment.get(field) != expected:
                errors.append(
                    f"order_enrichment {field} mismatch: expected {expected!r}, "
                    f"got {enrichment.get(field)!r}"
                )
        if not _is_payment_method(enrichment.get("payment_method_id")):
            errors.append("order_enrichment payment_method_id must be a Stripe PaymentMethod id")
        if enrichment.get("resolved_by") not in {"order_id", "payment_intent_id"}:
            errors.append("order_enrichment has an invalid resolution source")

    page = payload.get("page")
    if not isinstance(page, dict):
        errors.append("missing browser page evidence")
    else:
        failed_responses = page.get("failed_responses")
        if failed_responses != []:
            errors.append("failed HTTP responses prevent manual-completion classification")
        if page.get("fatal_console_errors") != []:
            errors.append("fatal browser console errors prevent manual-completion classification")
        if not _checkout_request_proven(page, context):
            errors.append("checkout request did not prove the exact split-gateway submission")
        checkout_responses = page.get("checkout_responses")
        if not isinstance(checkout_responses, list):
            errors.append("checkout_responses must be a list")
        elif checkout_responses:
            if not _checkout_response_proven(page, order_id):
                errors.append("checkout response did not prove successful order persistence")
            if any(
                not isinstance(response, dict)
                or not isinstance(response.get("status"), int)
                or response["status"] >= 400
                or response.get("result") == "failure"
                for response in checkout_responses
            ):
                errors.append("checkout response evidence contains an HTTP or checkout failure")

    if order_id is not None and payment_intent_id:
        errors.extend(
            _provider_observation_errors(
                payload.get("provider_observation"),
                context,
                order_id=order_id,
                payment_intent_id=payment_intent_id,
            )
        )
    return errors


def classify_manual_completion(
    payload: dict[str, Any], context: EvidenceContext
) -> tuple[dict[str, Any], list[str]]:
    errors = manual_candidate_errors(payload, context)
    if errors:
        return payload, errors

    limitations = list(payload["failures"])
    enrichment = payload["order_enrichment"]
    observation = payload["provider_observation"]
    page = payload["page"]
    order_id = _positive_int(payload.get("order_id"))
    payload = dict(payload)
    payload.pop("error", None)
    payload["status"] = "blocked"
    payload["blocker_code"] = MANUAL_BLOCKER_CODE
    payload["failures"] = []
    payload["manual_limitations"] = limitations
    payload["manual_completion"] = {
        "schema": MANUAL_COMPLETION_SCHEMA,
        "status": "blocked",
        "blocker_code": MANUAL_BLOCKER_CODE,
        "automation_disposition": MANUAL_AUTOMATION_DISPOSITION,
        "checkout_request_proven": True,
        "checkout_response_proven": _checkout_response_proven(page, order_id),
        "persisted_order_proven": True,
        "provider_identity_proven": True,
        "no_failed_http_responses": True,
        "order_received_url_observed": False,
        "order_status": enrichment["order_status"],
        "intention_status": enrichment["intention_status"],
        "provider_payment_intent_id": observation["provider_payment_intent_id"],
        "provider_payment_method_type": observation["observed_payment_method_type"],
        "runtime_owner": observation["runtime_owner"],
        "test_mode": observation["test_mode"],
    }
    return payload, []


def validate_manual_completion(
    payload: dict[str, Any], context: EvidenceContext
) -> list[str]:
    errors: list[str] = []
    limitations = payload.get("manual_limitations")
    candidate = dict(payload)
    candidate.pop("blocker_code", None)
    candidate.pop("manual_completion", None)
    candidate.pop("manual_limitations", None)
    candidate["status"] = "fail"
    candidate["failures"] = limitations
    errors.extend(manual_candidate_errors(candidate, context))

    if payload.get("status") != "blocked":
        errors.append("classified manual evidence status must be blocked")
    if payload.get("blocker_code") != MANUAL_BLOCKER_CODE:
        errors.append("classified manual evidence has the wrong blocker code")
    if payload.get("failures") != []:
        errors.append("classified manual evidence must have an empty failure list")

    completion = payload.get("manual_completion")
    if not isinstance(completion, dict):
        errors.append("missing manual_completion proof")
        return errors
    expected_completion = {
        "schema": MANUAL_COMPLETION_SCHEMA,
        "status": "blocked",
        "blocker_code": MANUAL_BLOCKER_CODE,
        "automation_disposition": MANUAL_AUTOMATION_DISPOSITION,
        "checkout_request_proven": True,
        "checkout_response_proven": _checkout_response_proven(
            payload.get("page") if isinstance(payload.get("page"), dict) else {},
            _positive_int(payload.get("order_id")),
        ),
        "persisted_order_proven": True,
        "provider_identity_proven": True,
        "no_failed_http_responses": True,
        "order_received_url_observed": False,
        "order_status": "pending",
        "intention_status": "requires_action",
        "provider_payment_intent_id": payload.get("payment_intent_id"),
        "provider_payment_method_type": context.stripe_type,
        "runtime_owner": _expected_runtime_owner(context.role),
        "test_mode": True,
    }
    for field, expected in expected_completion.items():
        if completion.get(field) != expected:
            errors.append(
                f"manual_completion {field} mismatch: expected {expected!r}, "
                f"got {completion.get(field)!r}"
            )
    return errors


def context_from_payload(payload: dict[str, Any]) -> EvidenceContext:
    return EvidenceContext(
        role=str(payload.get("role") or ""),
        method=str(payload.get("method") or ""),
        base_url=str(payload.get("base_url") or ""),
        gateway_id=str(payload.get("gateway_id") or ""),
        stripe_type=str(payload.get("stripe_payment_method_type") or ""),
        method_family=str(payload.get("method_family") or ""),
        automation_disposition=str(payload.get("automation_disposition") or ""),
    )


def validate_manual_pair(
    reference: dict[str, Any], target: dict[str, Any], method: str
) -> list[str]:
    errors: list[str] = []
    classified = {
        "reference": reference.get("status") == "blocked"
        and reference.get("blocker_code") == MANUAL_BLOCKER_CODE,
        "target": target.get("status") == "blocked"
        and target.get("blocker_code") == MANUAL_BLOCKER_CODE,
    }
    if classified["reference"] != classified["target"]:
        errors.append(
            f"{method} manual-completion evidence must be symmetric across reference and target"
        )
        return errors
    if classified["reference"]:
        for role, payload in (("reference", reference), ("target", target)):
            context = context_from_payload(payload)
            if context.role != role or context.method != method:
                errors.append(f"{role}/{method} evidence identity mismatch")
                continue
            errors.extend(
                f"{role}/{method}: {error}"
                for error in validate_manual_completion(payload, context)
            )
    return errors


def _context_from_args(args: argparse.Namespace) -> EvidenceContext:
    return EvidenceContext(
        role=args.role,
        method=args.method,
        base_url=args.base_url,
        gateway_id=args.gateway_id,
        stripe_type=args.stripe_type,
        method_family=args.method_family,
        automation_disposition=args.automation_disposition,
    )


def _emit(status: str, errors: list[str] | None = None, **extra: Any) -> None:
    print(
        json.dumps(
            {"status": status, "errors": errors or [], **extra},
            separators=(",", ":"),
            sort_keys=True,
        )
    )


def _add_context_arguments(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--evidence", type=Path, required=True)
    parser.add_argument("--role", required=True)
    parser.add_argument("--method", required=True)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--gateway-id", required=True)
    parser.add_argument("--stripe-type", required=True)
    parser.add_argument("--method-family", required=True)
    parser.add_argument("--automation-disposition", required=True)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="operation", required=True)
    classify_parser = subparsers.add_parser("classify-manual")
    _add_context_arguments(classify_parser)
    validate_parser = subparsers.add_parser("validate-manual")
    _add_context_arguments(validate_parser)
    pair_parser = subparsers.add_parser("validate-pair")
    pair_parser.add_argument("--reference-evidence", type=Path, required=True)
    pair_parser.add_argument("--target-evidence", type=Path, required=True)
    pair_parser.add_argument("--method", required=True)
    pair_parser.add_argument("--automation-disposition", required=True)
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.operation == "validate-pair":
            if args.automation_disposition != MANUAL_AUTOMATION_DISPOSITION:
                _emit("not_applicable")
                return 0
            reference = read_evidence(args.reference_evidence)
            target = read_evidence(args.target_evidence)
            errors = validate_manual_pair(reference, target, args.method)
            if errors:
                _emit("fail", errors)
                return 1
            _emit("pass")
            return 0

        payload = read_evidence(args.evidence)
        context = _context_from_args(args)
        if args.operation == "validate-manual":
            errors = validate_manual_completion(payload, context)
            if errors:
                _emit("fail", errors)
                return 1
            _emit("pass")
            return 0

        if payload.get("status") == "pass":
            _emit("automated_completion")
            return 0
        if payload.get("status") == "blocked":
            errors = validate_manual_completion(payload, context)
            if errors:
                _emit("fail", errors)
                return 1
            _emit("blocked", blocker_code=MANUAL_BLOCKER_CODE)
            return 3
        classified, errors = classify_manual_completion(payload, context)
        if errors:
            _emit("fail", errors)
            return 1
        write_evidence(args.evidence, classified)
        _emit(
            "blocked",
            blocker_code=MANUAL_BLOCKER_CODE,
            provenance=classified["manual_completion"],
        )
        return 3
    except (OSError, ValueError, json.JSONDecodeError) as error:
        _emit("fail", [str(error)])
        return 1


if __name__ == "__main__":
    sys.exit(main())
