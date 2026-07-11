#!/usr/bin/env python3
"""Build Layer-A critical-flow result JSON from authoritative gate rollups.

The critical-flow runner intentionally does not drive browsers itself. This
adapter preserves that boundary while letting final-evidence gates feed the
runner's `{flow, store_results, parity_verdict, regression_note}` contract.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

from evidence_context import (
    EvidenceContextError,
    import_captured_result,
    stamp_generated_result,
    validate_context,
)


FLOW_MA11 = "MA-11-plugin-active-settings-screen"
FLOW_MS07 = "MS-07-admin-change-method"
FLOW_SC04 = "SC-04-saved-card"
FLOW_SC14 = "SC-14-lpm-wave-1-checkout"
FLOW_SS10 = "SS-10-sepa-token-renewal-cutover"
SC04_SURFACES = ("classic", "blocks", "sca_classic", "sca_blocks")


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_result(out_dir: Path, flow: str, payload: dict[str, Any], context: dict[str, Any]) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / f"{flow}.json").write_text(
        json.dumps(stamp_generated_result(payload, context), indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def existing_path(path: str | None) -> Path | None:
    if not path:
        return None
    candidate = Path(path)
    return candidate if candidate.exists() else None


def clean_list(values: Any) -> list[str]:
    if not isinstance(values, list):
        return []
    return [str(value) for value in values if str(value)]


def verdict_for_status(status: str, *, pass_verdict: str = "PASS") -> str:
    normalized = status.lower()
    if normalized == "pass":
        return pass_verdict
    if normalized == "fail":
        return "FAIL - functional"
    return "BLOCKED"


def plugin_active_store_result(store: str, gate_path: Path | None) -> dict[str, Any]:
    if gate_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No plugin-active settings gate rollup was available for {store}.",
            "ux_observations": ["Plugin-active settings UX evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    payload = load_json(gate_path)
    status = str(payload.get("status", "blocked"))
    evidence = payload.get("evidence", {}) if isinstance(payload.get("evidence"), dict) else {}
    page = evidence.get("page", {}) if isinstance(evidence.get("page"), dict) else {}
    blockers = clean_list(payload.get("blockers")) + clean_list(evidence.get("blockers"))
    failures = clean_list(payload.get("failures")) + clean_list(evidence.get("failures"))
    screenshot = evidence.get("screenshot_path")
    evidence_paths = [str(gate_path)]
    if screenshot:
        evidence_paths.append(str(screenshot))

    observations = [
        "WooPayments plugin-active settings screen rendered in authenticated wp-admin."
        if status == "pass"
        else "WooPayments plugin-active settings screen did not produce passing browser evidence.",
        "Duplicate wc/payments/settings store registration errors were absent."
        if not evidence.get("duplicate_store_errors")
        else "Duplicate wc/payments/settings store registration errors were captured.",
        "Fatal console errors and failed browser responses were absent."
        if not evidence.get("fatal_console_errors") and not evidence.get("failed_responses")
        else "Fatal console errors or failed browser responses were captured.",
    ]
    observations.extend(blockers)
    observations.extend(failures)

    return {
        "store": store,
        "verdict": verdict_for_status(status),
        "end_state": (
            f"status={status}; final_url={page.get('finalUrl') or payload.get('settings_url') or '<unknown>'}; "
            f"settings_screen_present={bool(evidence.get('settings_screen_present'))}"
        ),
        "ux_observations": observations,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }


def build_plugin_active_result(reference_gate: Path | None, target_gate: Path | None) -> dict[str, Any]:
    ref = plugin_active_store_result("ref", reference_gate)
    target = plugin_active_store_result("target", target_gate)
    if ref["verdict"].startswith("PASS") and target["verdict"].startswith("PASS"):
        parity = "PASS"
        note = "Reference and target plugin-active WooPayments settings screens rendered without native settings-store collisions."
    elif ref["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - UX"
        note = "At least one plugin-active WooPayments settings screen gate failed."
    else:
        parity = "BLOCKED"
        note = "Plugin-active settings evidence is incomplete."

    return {
        "flow": FLOW_MA11,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def lpm_result_for_store(store: str, gate_path: Path | None, payload: dict[str, Any] | None) -> dict[str, Any]:
    if gate_path is None or payload is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No LPM checkout gate rollup was available for {store}.",
            "ux_observations": ["LPM checkout evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    role = "reference" if store == "ref" else "target"
    status = str(payload.get("status", "blocked"))
    results = [result for result in payload.get("results", []) if isinstance(result, dict) and result.get("role") == role]
    blockers = [blocker for blocker in clean_list(payload.get("blockers")) if blocker.startswith(f"{role}/")]
    failures = [failure for failure in clean_list(payload.get("failures")) if failure.startswith(f"{role}/")]
    passed_methods = [
        str(result.get("method"))
        for result in results
        if str(result.get("status", "")).lower() == "pass" and result.get("method")
    ]

    if status == "pass":
        verdict = "PASS"
    elif failures:
        verdict = "FAIL - functional"
    else:
        verdict = "BLOCKED"

    observations = []
    if passed_methods:
        observations.append(f"Runnable methods passed: {', '.join(passed_methods)}.")
    observations.extend(blockers)
    observations.extend(failures)

    return {
        "store": store,
        "verdict": verdict,
        "end_state": (
            f"status={status}; passed_methods={len(passed_methods)}; "
            f"blockers={len(blockers)}; failures={len(failures)}"
        ),
        "ux_observations": observations or ["No per-method LPM evidence was recorded."],
        "visual_diffs": [],
        "evidence_paths": [str(gate_path)],
    }


def build_lpm_result(gate_path: Path | None) -> dict[str, Any]:
    payload = load_json(gate_path) if gate_path else None
    ref = lpm_result_for_store("ref", gate_path, payload)
    target = lpm_result_for_store("target", gate_path, payload)
    status = str(payload.get("status", "blocked")) if payload else "blocked"

    if status == "pass":
        parity = "PASS"
        note = "LPM checkout gate recorded passing reference and target evidence for every requested method."
    elif status == "fail":
        parity = "FAIL - functional"
        note = "LPM checkout gate recorded one or more failures."
    else:
        parity = "BLOCKED"
        note = "LPM checkout gate is blocked on fixture/account prerequisites; runnable methods remain represented in evidence."

    return {
        "flow": FLOW_SC14,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def token_continuity_evidence_gaps(payload: dict[str, Any]) -> list[str]:
    token_id = payload.get("token_id")
    source_token = payload.get("source_token")
    native_token = payload.get("native_token_loader")
    render = payload.get("render_payment_methods")
    renewal = payload.get("renewal")
    gaps = []

    if (
        payload.get("source_flow") != "provider_setup_intent"
        or not isinstance(source_token, dict)
        or source_token.get("success") is not True
        or source_token.get("source_payment_method_customer_ready") is not True
        or not str(source_token.get("payment_method_id", "")).startswith("pm_")
    ):
        gaps.append("source token evidence is not a customer-ready provider setup-intent result")

    if (
        not isinstance(token_id, int)
        or token_id <= 0
        or not isinstance(native_token, dict)
        or native_token.get("success") is not True
        or native_token.get("token_id") != token_id
        or native_token.get("gateway_id") != "woocommerce_payments_sepa_debit"
        or native_token.get("token_type") != "wcpay_sepa"
        or not str(native_token.get("token_class", "")).endswith("WooPaymentsSepaToken")
    ):
        gaps.append("native token loader evidence is missing or inconsistent")

    render_page = render.get("page", {}) if isinstance(render, dict) else {}
    payment_methods = render_page.get("payment_methods", {}) if isinstance(render_page, dict) else {}
    if (
        not isinstance(render, dict)
        or render.get("status") != "pass"
        or render.get("token_id") != token_id
        or render.get("token_visible") is not True
        or not isinstance(payment_methods, dict)
        or payment_methods.get("token_visible") is not True
    ):
        gaps.append("My Account token rendering evidence is missing or inconsistent")

    if (
        not isinstance(renewal, dict)
        or renewal.get("success") is not True
        or renewal.get("renewal_processing_model") != "asynchronous_processing"
        or renewal.get("success_checks_failed") != []
        or not isinstance(renewal.get("renewal_order_id"), int)
        or renewal.get("renewal_order_id", 0) <= 0
    ):
        gaps.append("SEPA renewal evidence is missing or inconsistent")

    return gaps


def build_token_continuity_result(gate_path: Path | None) -> dict[str, Any]:
    if gate_path is None:
        store_results = [
            {
                "store": "ref",
                "verdict": "BLOCKED",
                "end_state": "No token-continuity gate rollup was available.",
                "ux_observations": ["SEPA token continuity evidence is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            },
            {
                "store": "target",
                "verdict": "BLOCKED",
                "end_state": "No token-continuity gate rollup was available.",
                "ux_observations": ["SEPA token continuity evidence is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            },
        ]
        parity = "BLOCKED"
        note = "Token-continuity evidence is missing."
        return {
            "flow": FLOW_SS10,
            "oracle_mode": "target-only",
            "store_results": store_results,
            "parity_verdict": parity,
            "regression_note": note,
        }

    payload = load_json(gate_path)
    status = str(payload.get("status", "blocked"))
    failures = clean_list(payload.get("failures"))
    blockers = clean_list(payload.get("blockers"))
    evidence_gaps = token_continuity_evidence_gaps(payload) if status == "pass" else []
    source_token = payload.get("source_token", {}) if isinstance(payload.get("source_token"), dict) else {}
    native_token = (
        payload.get("native_token_loader", {}) if isinstance(payload.get("native_token_loader"), dict) else {}
    )
    render = payload.get("render_payment_methods", {}) if isinstance(payload.get("render_payment_methods"), dict) else {}
    renewal = payload.get("renewal", {}) if isinstance(payload.get("renewal"), dict) else {}
    render_page = render.get("page", {}) if isinstance(render.get("page"), dict) else {}
    screenshot = render_page.get("screenshot_path")
    evidence_paths = [str(gate_path)]
    if screenshot:
        evidence_paths.append(str(screenshot))

    if status == "pass" and not evidence_gaps:
        verdict = "PASS"
        parity = "BLOCKED"
        note = "Target-only SEPA cutover continuity passed; cross-store parity is not claimed."
    elif status == "pass":
        verdict = "BLOCKED"
        parity = "BLOCKED"
        note = "Token-continuity rollup claimed pass without complete target evidence."
    elif status == "fail":
        verdict = "FAIL - functional"
        parity = "BLOCKED"
        note = "Target token-continuity gate recorded failures."
    else:
        verdict = "BLOCKED"
        parity = "BLOCKED"
        note = "Target token-continuity gate is blocked."

    reference_result = {
        "store": "ref",
        "verdict": "BLOCKED",
        "end_state": "No equivalent reference-store SEPA scheduled-renewal run is available.",
        "ux_observations": [
            "The token-continuity gate runs before and after cutover on the target store only.",
            "WooPayments 10.8 does not provide an equivalent SEPA scheduled-renewal callback for cross-store parity.",
        ],
        "visual_diffs": [],
        "evidence_paths": [],
    }

    target_result = {
        "store": "target",
        "verdict": verdict,
        "end_state": (
            f"status={status}; native_token_loaded={native_token.get('success')}; "
            f"token_visible={render_page.get('payment_methods', {}).get('token_visible')}; "
            f"renewal_order={renewal.get('renewal_order_id')}; "
            f"renewal_status={renewal.get('renewal_order_status')}; "
            f"subscription_status={renewal.get('subscription_status')}"
        ),
        "ux_observations": [
            "The provider source token was customer-ready before target cutover."
            if source_token.get("source_payment_method_customer_ready")
            else "The provider source token was not proven customer-ready before target cutover.",
            "The native WooCommerce token loader resolved the saved SEPA token."
            if native_token.get("success")
            else "The native WooCommerce token loader did not prove the saved SEPA token.",
            "Native My Account payment methods page showed the migrated SEPA token."
            if render_page.get("payment_methods", {}).get("token_visible")
            else "Native My Account payment methods page did not prove token visibility.",
            f"Renewal processing model={renewal.get('renewal_processing_model') or '<unknown>'}.",
            f"Renewal success checks failed={renewal.get('success_checks_failed') or []}.",
        ]
        + evidence_gaps
        + blockers
        + failures,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }

    return {
        "flow": FLOW_SS10,
        "oracle_mode": "target-only",
        "store_results": [reference_result, target_result],
        "parity_verdict": parity,
        "regression_note": note,
    }


def sc04_store_result(
    store: str,
    browser_path: Path | None,
    state_path: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    if browser_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No SC-04 browser evidence was available for {store}.",
            "ux_observations": ["Saved-card Classic and Blocks browser evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    browser = load_json(browser_path)
    evidence_paths = [str(browser_path)]
    observations: list[str] = []
    binding_gaps: list[str] = []
    evidence_gaps: list[str] = []
    behavior_failures: list[str] = []

    browser_surfaces = browser.get("surfaces") if isinstance(browser.get("surfaces"), dict) else {}
    for surface in SC04_SURFACES:
        browser_surface = browser_surfaces.get(surface)
        if not isinstance(browser_surface, dict):
            evidence_gaps.append(f"{surface} browser evidence is missing")
            continue
        screenshot_paths = browser_surface.get("screenshot_paths")
        if not isinstance(screenshot_paths, list) or not screenshot_paths:
            evidence_gaps.append(f"{surface} browser evidence has no screenshot")
            continue
        for value in screenshot_paths:
            screenshot = Path(str(value))
            if screenshot.is_file():
                evidence_paths.append(str(screenshot))
            else:
                evidence_gaps.append(f"{surface} screenshot is missing: {screenshot}")

    if state_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": "SC-04 saved-card browser evidence exists, but matching order/token state evidence is missing.",
            "ux_observations": evidence_gaps + ["Saved-card order/token state evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state = load_json(state_path)
    evidence_paths.append(str(state_path))
    state_orders = state.get("orders") if isinstance(state.get("orders"), dict) else {}
    token = state.get("token") if isinstance(state.get("token"), dict) else {}
    sca_token = state.get("sca_token") if isinstance(state.get("sca_token"), dict) else {}
    browser_customer_id = str(browser.get("customer_id") or "")
    state_customer_id = str(state.get("customer_id") or "")
    browser_token_id = str(browser.get("token_id") or "")
    browser_sca_token_id = str(browser.get("sca_token_id") or "")
    state_token_id = str(token.get("id") or "")
    state_sca_token_id = str(sca_token.get("id") or "")
    payment_method_id = str(token.get("payment_method_id") or "")
    sca_payment_method_id = str(sca_token.get("payment_method_id") or "")
    expected_context_binding = {
        "aggregate_run_id": context.get("aggregate_run_id"),
        "context_sha256": context.get("context_sha256"),
    }

    if browser.get("schema") != "woopayments_sc04_browser_evidence.v1":
        binding_gaps.append("browser evidence schema is invalid")
    if state.get("schema") != "woopayments_sc04_state_evidence.v1":
        binding_gaps.append("state evidence schema is invalid")
    if browser.get("context_binding") != expected_context_binding:
        binding_gaps.append("browser context binding does not match")
    if state.get("context_binding") != expected_context_binding:
        binding_gaps.append("state context binding does not match")
    if str(browser.get("store") or "") != store:
        binding_gaps.append(f"browser store does not match {store}")
    if str(state.get("store") or "") != store:
        binding_gaps.append(f"state store does not match {store}")
    if not browser_customer_id or browser_customer_id != state_customer_id:
        binding_gaps.append("browser and state customer fixtures do not match")
    if not browser_token_id or browser_token_id != state_token_id:
        binding_gaps.append("browser and state token fixtures do not match")
    if not browser_sca_token_id or browser_sca_token_id != state_sca_token_id:
        binding_gaps.append("browser and state SCA token fixtures do not match")
    if str(token.get("user_id") or "") != state_customer_id:
        binding_gaps.append("saved token does not belong to the evidence customer")
    if token.get("gateway_id") != "woocommerce_payments":
        binding_gaps.append("saved token is not owned by the WooPayments card gateway")
    if not payment_method_id.startswith("pm_"):
        binding_gaps.append("saved token provider payment method id is invalid")
    if str(sca_token.get("user_id") or "") != state_customer_id:
        binding_gaps.append("saved SCA token does not belong to the evidence customer")
    if sca_token.get("gateway_id") != "woocommerce_payments":
        binding_gaps.append("saved SCA token is not owned by the WooPayments card gateway")
    if not sca_payment_method_id.startswith("pm_"):
        binding_gaps.append("saved SCA token provider payment method id is invalid")
    if str(browser.get("home_url") or "").rstrip("/") != str(state.get("home_url") or "").rstrip("/"):
        binding_gaps.append("browser and state home URLs do not match")
    if state.get("source") != context.get("source"):
        binding_gaps.append("source snapshot does not match")
    if state.get("store_context") != context.get("stores", {}).get(store):
        binding_gaps.append("store identity or account state does not match")

    behavior_failures.extend(clean_list(browser.get("errors")))
    behavior_failures.extend(clean_list(browser.get("fatal_console_errors")))
    behavior_failures.extend(clean_list(browser.get("fatal_response_errors")))
    behavior_failures.extend(clean_list(state.get("errors")))

    order_summaries = []
    surface_outcomes: dict[str, dict[str, str]] = {}
    for surface in SC04_SURFACES:
        browser_surface = browser_surfaces.get(surface)
        order = state_orders.get(surface)
        if not isinstance(browser_surface, dict) or not isinstance(order, dict):
            if not isinstance(order, dict):
                evidence_gaps.append(f"{surface} order state evidence is missing")
            continue

        browser_order_id = str(browser_surface.get("order_id") or "")
        state_order_id = str(order.get("order_id") or "")
        requires_sca = surface.startswith("sca_")
        expected_token_id = state_sca_token_id if requires_sca else state_token_id
        expected_payment_method_id = sca_payment_method_id if requires_sca else payment_method_id
        order_summaries.append(f"{surface}_order={state_order_id or '<missing>'}")
        if not browser_order_id or browser_order_id != state_order_id:
            binding_gaps.append(f"{surface} browser and state order ids do not match")
        if str(browser_surface.get("selected_token_id") or "") != expected_token_id:
            binding_gaps.append(f"{surface} selected token does not match the saved-token fixture")
        if str(order.get("customer_id") or "") != state_customer_id:
            binding_gaps.append(f"{surface} order customer does not match the saved-token fixture")

        for field in (
            "success",
            "saved_card_visible",
            "saved_card_selected",
            "new_card_fields_forced",
            "final_url",
            "expected_amount",
            "expected_currency",
        ):
            if field not in browser_surface:
                evidence_gaps.append(f"{surface}: {field} assertion is missing")
        for field in ("success", "status", "payment_method", "payment_method_id", "amount", "currency"):
            if field not in order:
                evidence_gaps.append(f"{surface}: order {field} assertion is missing")

        surface_errors = clean_list(browser_surface.get("errors")) + clean_list(order.get("errors"))
        behavior_failures.extend(surface_errors)
        if "success" in browser_surface and browser_surface.get("success") is not True:
            behavior_failures.append(f"{surface}: browser checkout did not succeed")
        if "saved_card_visible" in browser_surface and browser_surface.get("saved_card_visible") is not True:
            behavior_failures.append(f"{surface}: saved-card affordance was not visible")
        if "saved_card_selected" in browser_surface and browser_surface.get("saved_card_selected") is not True:
            behavior_failures.append(f"{surface}: saved card was not selected")
        if "new_card_fields_forced" in browser_surface and browser_surface.get("new_card_fields_forced") is not False:
            behavior_failures.append(f"{surface}: empty new-card fields were forced")
        if requires_sca:
            for field in ("sca_challenge_present", "sca_challenge_completed"):
                if field not in browser_surface:
                    evidence_gaps.append(f"{surface}: {field} assertion is missing")
            if "sca_challenge_present" in browser_surface and browser_surface.get("sca_challenge_present") is not True:
                behavior_failures.append(f"{surface}: saved-token SCA challenge did not render")
            if "sca_challenge_completed" in browser_surface and browser_surface.get("sca_challenge_completed") is not True:
                behavior_failures.append(f"{surface}: saved-token SCA challenge did not complete")
        expected_final_prefix = str(browser.get("home_url") or "").rstrip("/")
        final_url = str(browser_surface.get("final_url") or "")
        if "final_url" in browser_surface and (
            not final_url.startswith(expected_final_prefix) or "/order-received/" not in final_url
        ):
            behavior_failures.append(f"{surface}: browser did not finish on the local order-received page")
        if "success" in order and order.get("success") is not True:
            behavior_failures.append(f"{surface}: order state assertion did not succeed")
        if "status" in order and order.get("status") not in {"processing", "completed"}:
            behavior_failures.append(f"{surface}: order status is not processing/completed")
        if "payment_method" in order and order.get("payment_method") != "woocommerce_payments":
            behavior_failures.append(f"{surface}: order payment method is not WooPayments card")
        if "payment_method_id" in order and str(order.get("payment_method_id") or "") != expected_payment_method_id:
            behavior_failures.append(f"{surface}: order did not charge the saved provider payment method")
        expected_amount = str(browser_surface.get("expected_amount") or "")
        expected_currency = str(browser_surface.get("expected_currency") or "").upper()
        actual_amount = str(order.get("amount") or "")
        actual_currency = str(order.get("currency") or "").upper()
        if "expected_amount" in browser_surface and "amount" in order and expected_amount != actual_amount:
            behavior_failures.append(f"{surface}: amount mismatch")
        if "expected_currency" in browser_surface and "currency" in order and expected_currency != actual_currency:
            behavior_failures.append(f"{surface}: currency mismatch")
        surface_outcomes[surface] = {
            "amount": actual_amount,
            "currency": actual_currency,
        }
        meta_presence = order.get("meta_presence") if isinstance(order.get("meta_presence"), dict) else {}
        for meta_key in ("_intent_id", "_charge_id"):
            if meta_key not in meta_presence:
                evidence_gaps.append(f"{surface}: {meta_key} assertion is missing")
            elif meta_presence.get(meta_key) is not True:
                behavior_failures.append(f"{surface}: {meta_key} is missing")

        if not surface_errors and browser_surface.get("success") is True:
            observations.append(
                f"{surface.replace('_', ' ').title()} checkout offered and selected saved token {expected_token_id}, then reached order {state_order_id}."
            )

    end_state = "; ".join(
        [
            f"customer={state_customer_id or '<missing>'}",
            f"token={state_token_id or '<missing>'}",
            f"sca_token={state_sca_token_id or '<missing>'}",
            *order_summaries,
        ]
    )
    if binding_gaps or evidence_gaps:
        verdict = "BLOCKED"
    elif behavior_failures:
        verdict = "FAIL - functional"
    else:
        verdict = "PASS"

    return {
        "store": store,
        "verdict": verdict,
        "end_state": end_state,
        "ux_observations": observations + binding_gaps + evidence_gaps + behavior_failures,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
        "surface_outcomes": surface_outcomes,
    }


def build_sc04_result(
    reference_browser: Path | None,
    reference_state: Path | None,
    target_browser: Path | None,
    target_state: Path | None,
    context: dict[str, Any],
) -> dict[str, Any]:
    reference = sc04_store_result("ref", reference_browser, reference_state, context)
    target = sc04_store_result("target", target_browser, target_state, context)

    if reference["verdict"] == "PASS" and target["verdict"] == "PASS":
        if reference.get("surface_outcomes") == target.get("surface_outcomes"):
            parity = "PASS"
            note = "Reference and target Classic and Blocks checkouts selected and charged the saved card token."
        else:
            parity = "FAIL - functional"
            note = "Reference and target SC-04 amount/currency outcomes differ."
    elif reference["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - functional"
        note = "At least one store failed the SC-04 saved-card browser or order-state assertion."
    else:
        parity = "BLOCKED"
        note = "SC-04 saved-card evidence is incomplete or does not match the current source/store context."

    return {
        "flow": FLOW_SC04,
        "store_results": [reference, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def ms07_screenshot_paths(browser_payload: dict[str, Any]) -> list[str]:
    evidence_dir = browser_payload.get("evidence_dir")
    if not evidence_dir:
        return []

    base = Path(str(evidence_dir))
    return [str(path) for path in (base / "before.png", base / "selected.png", base / "after.png") if path.exists()]


def ms07_store_result(
    store: str,
    browser_path: Path | None,
    state_path: Path | None,
    expected_subscription_id: int | None,
) -> dict[str, Any]:
    evidence_paths: list[str] = []
    observations: list[str] = []

    if browser_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"No MS-07 browser evidence was available for {store}.",
            "ux_observations": ["Subscription admin change-payment browser evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": [],
        }

    browser = load_json(browser_path)
    evidence_paths.append(str(browser_path))
    evidence_paths.extend(ms07_screenshot_paths(browser))

    selected_token_id = str(browser.get("selected_token_id") or "")
    token_after_select = str(browser.get("token_value_after_select") or "")
    token_options = browser.get("token_options_before") if isinstance(browser.get("token_options_before"), list) else []
    method_options = browser.get("method_options") if isinstance(browser.get("method_options"), list) else []
    has_requested_token = any(str(option.get("value", "")) == selected_token_id for option in token_options if isinstance(option, dict))
    has_woopayments_method = any(str(option.get("value", "")) == "woocommerce_payments" for option in method_options if isinstance(option, dict))
    browser_errors = clean_list(browser.get("errors"))

    observations.append(
        "WooPayments was selectable in the subscription payment method dropdown."
        if has_woopayments_method
        else "WooPayments was not proven selectable in the subscription payment method dropdown."
    )
    observations.append(
        f"Saved token {selected_token_id} was available and selected in the admin UI."
        if has_requested_token and token_after_select == selected_token_id
        else f"Saved token selection was not proven; requested={selected_token_id}, selected={token_after_select or '<missing>'}."
    )
    observations.extend(browser_errors)

    if state_path is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": (
                f"subscription={browser.get('subscription_id')}; selected_token={selected_token_id}; "
                "renewal state evidence is missing"
            ),
            "ux_observations": observations + ["MS-07 renewal/state assertion evidence is missing."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state = load_json(state_path)
    evidence_paths.append(str(state_path))

    browser_store = str(browser.get("store") or "")
    state_store = str(state.get("store") or "")
    browser_subscription_id = str(browser.get("subscription_id") or "")
    state_subscription_id = str(state.get("subscription_id") or "")
    expected_subscription = str(expected_subscription_id or "")
    binding_summary = (
        f"expected subscription={expected_subscription or '<missing>'}; "
        f"browser subscription={browser_subscription_id or '<missing>'}; "
        f"state subscription={state_subscription_id or '<missing>'}"
    )

    if expected_subscription_id is None:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"MS-07 evidence is not bound to an expected subscription fixture for {store}; {binding_summary}",
            "ux_observations": observations + ["Supply the current MS-07 subscription fixture ID before accepting this evidence."],
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    binding_failures = []
    if browser_store != store:
        binding_failures.append(f"browser store={browser_store or '<missing>'}, expected {store}")
    if state_store != store:
        binding_failures.append(f"state store={state_store or '<missing>'}, expected {store}")
    if browser_subscription_id != expected_subscription:
        binding_failures.append("browser subscription does not match the expected fixture")
    if state_subscription_id != expected_subscription:
        binding_failures.append("state subscription does not match the expected fixture")
    if binding_failures:
        return {
            "store": store,
            "verdict": "BLOCKED",
            "end_state": f"MS-07 evidence fixture binding failed for {store}; {binding_summary}",
            "ux_observations": observations + binding_failures,
            "visual_diffs": [],
            "evidence_paths": evidence_paths,
        }

    state_success = bool(state.get("success"))
    renewal_status = state.get("renewal_status")
    subscription_status = state.get("subscription_status")
    meta_presence = state.get("renewal_meta_presence") if isinstance(state.get("renewal_meta_presence"), dict) else {}
    token_matches = str(state.get("last_token_id") or "") == selected_token_id
    pm_matches = str(state.get("last_token_pm") or "") == str(state.get("subscription_payment_method_id") or "")
    customer_present = bool(state.get("subscription_stripe_customer_id_present"))
    browser_pass = has_woopayments_method and has_requested_token and token_after_select == selected_token_id and not browser_errors

    observations.append(
        f"Renewal order {state.get('renewal_order_id') or '<unknown>'} finished with status {renewal_status or '<unknown>'}."
    )
    observations.append(
        f"Renewal payment meta presence={meta_presence}."
    )
    observations.append(
        f"Subscription customer meta present={customer_present}; token_matches={token_matches}; payment_method_matches_token={pm_matches}."
    )

    if browser_pass and state_success and token_matches and pm_matches and customer_present:
        verdict = "PASS"
    elif not browser_pass or not state_success:
        verdict = "FAIL - functional"
    else:
        verdict = "BLOCKED"

    return {
        "store": store,
        "verdict": verdict,
        "end_state": (
            f"subscription={state.get('subscription_id')}; selected_token={selected_token_id}; "
            f"last_token={state.get('last_token_id')}; renewal_order={state.get('renewal_order_id')}; "
            f"renewal_status={renewal_status}; subscription_status={subscription_status}"
        ),
        "ux_observations": observations,
        "visual_diffs": [],
        "evidence_paths": evidence_paths,
    }


def build_ms07_result(
    reference_browser: Path | None,
    reference_state: Path | None,
    target_browser: Path | None,
    target_state: Path | None,
    reference_subscription_id: int | None,
    target_subscription_id: int | None,
) -> dict[str, Any]:
    ref = ms07_store_result("ref", reference_browser, reference_state, reference_subscription_id)
    target = ms07_store_result("target", target_browser, target_state, target_subscription_id)

    if ref["verdict"].startswith("PASS") and target["verdict"].startswith("PASS"):
        parity = "PASS"
        note = "Reference and target admin subscription payment-method edits rendered saved-token controls, persisted the selected token, and renewed successfully."
    elif ref["verdict"].startswith("FAIL") or target["verdict"].startswith("FAIL"):
        parity = "FAIL - functional"
        note = "At least one store failed the MS-07 admin save or renewal state assertion."
    else:
        parity = "BLOCKED"
        note = "MS-07 admin-change evidence is incomplete."

    return {
        "flow": FLOW_MS07,
        "store_results": [ref, target],
        "parity_verdict": parity,
        "regression_note": note,
    }


def build_missing_agent_result(flow: str) -> dict[str, Any]:
    store_results = []
    for store in ("ref", "target"):
        store_results.append(
            {
                "store": store,
                "verdict": "BLOCKED",
                "end_state": f"No Layer-A evidence was available for {flow} on {store}.",
                "ux_observations": [f"Layer-A evidence for {flow} is missing."],
                "visual_diffs": [],
                "evidence_paths": [],
            }
        )

    return {
        "flow": flow,
        "store_results": store_results,
        "parity_verdict": "BLOCKED",
        "regression_note": f"Layer-A evidence for {flow} is missing.",
    }


def copy_agent_result(path: Path, out_dir: Path, context: dict[str, Any]) -> str:
    payload = load_json(path)
    flow = payload.get("flow")
    if not isinstance(flow, str) or not flow:
        raise ValueError(f"{path} does not contain a flow name")
    if not isinstance(payload.get("store_results"), list):
        raise ValueError(f"{path} does not contain store_results")
    if "parity_verdict" not in payload:
        raise ValueError(f"{path} does not contain parity_verdict")
    out_dir.mkdir(parents=True, exist_ok=True)
    try:
        imported = import_captured_result(payload, context, path)
    except EvidenceContextError as exc:
        blocked = build_missing_agent_result(flow)
        blocked["provenance_error"] = {
            "code": exc.code,
            "message": str(exc),
            "source_path": str(path),
        }
        blocked["regression_note"] = f"Layer-A evidence for {flow} is non-gating: {exc.code}."
        imported = stamp_generated_result(blocked, context)
    (out_dir / f"{flow}.json").write_text(
        json.dumps(imported, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return flow


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--context-file", required=True, help="Current critical-flow aggregate context JSON.")
    parser.add_argument("--out-dir", required=True, help="Directory for generated Layer-A JSON files.")
    parser.add_argument("--plugin-active-reference-gate", help="Reference plugin-active settings gate JSON.")
    parser.add_argument("--plugin-active-target-gate", help="Target plugin-active settings gate JSON.")
    parser.add_argument("--lpm-gate", help="LPM checkout gate rollup JSON.")
    parser.add_argument("--sc04-reference-browser", help="Reference SC-04 browser evidence JSON.")
    parser.add_argument("--sc04-reference-state", help="Reference SC-04 saved-token/order state JSON.")
    parser.add_argument("--sc04-target-browser", help="Target SC-04 browser evidence JSON.")
    parser.add_argument("--sc04-target-state", help="Target SC-04 saved-token/order state JSON.")
    parser.add_argument(
        "--require-sc04",
        action="store_true",
        help="Write a blocked SC-04 result when raw or prebuilt evidence is unavailable.",
    )
    parser.add_argument("--ms07-reference-browser", help="Reference MS-07 browser result JSON.")
    parser.add_argument("--ms07-reference-state", help="Reference MS-07 state assertion JSON.")
    parser.add_argument("--ms07-target-browser", help="Target MS-07 browser result JSON.")
    parser.add_argument("--ms07-target-state", help="Target MS-07 state assertion JSON.")
    parser.add_argument(
        "--require-ms07",
        action="store_true",
        help="Write a blocked MS-07 result when source evidence is incomplete instead of leaving the flow queued.",
    )
    parser.add_argument("--token-continuity-gate", help="Token continuity gate rollup JSON.")
    parser.add_argument(
        "--copy-agent-result",
        action="append",
        default=[],
        help="Existing Layer-A JSON result to validate and copy into --out-dir.",
    )
    parser.add_argument(
        "--require-agent-flow",
        action="append",
        default=[],
        help="Flow ID that must have Layer-A JSON; writes a blocked result when no generated/copied result exists.",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    out_dir = Path(args.out_dir)
    context = load_json(Path(args.context_file))
    validate_context(context)
    written_flows: set[str] = set()

    for source in args.copy_agent_result:
        written_flows.add(copy_agent_result(Path(source), out_dir, context))

    if args.plugin_active_reference_gate or args.plugin_active_target_gate:
        write_result(
            out_dir,
            FLOW_MA11,
            build_plugin_active_result(
                existing_path(args.plugin_active_reference_gate),
                existing_path(args.plugin_active_target_gate),
            ),
            context,
        )
        written_flows.add(FLOW_MA11)

    if args.lpm_gate:
        write_result(out_dir, FLOW_SC14, build_lpm_result(existing_path(args.lpm_gate)), context)
        written_flows.add(FLOW_SC14)

    has_sc04_source = bool(
        args.sc04_reference_browser
        or args.sc04_reference_state
        or args.sc04_target_browser
        or args.sc04_target_state
    )
    if has_sc04_source:
        write_result(
            out_dir,
            FLOW_SC04,
            build_sc04_result(
                existing_path(args.sc04_reference_browser),
                existing_path(args.sc04_reference_state),
                existing_path(args.sc04_target_browser),
                existing_path(args.sc04_target_state),
                context,
            ),
            context,
        )
        written_flows.add(FLOW_SC04)
    elif args.require_sc04 and FLOW_SC04 not in written_flows:
        write_result(
            out_dir,
            FLOW_SC04,
            build_sc04_result(None, None, None, None, context),
            context,
        )
        written_flows.add(FLOW_SC04)

    has_ms07_source = bool(
        args.ms07_reference_browser
        or args.ms07_reference_state
        or args.ms07_target_browser
        or args.ms07_target_state
    )
    if has_ms07_source or (args.require_ms07 and FLOW_MS07 not in written_flows):
        write_result(
            out_dir,
            FLOW_MS07,
            build_ms07_result(
                existing_path(args.ms07_reference_browser),
                existing_path(args.ms07_reference_state),
                existing_path(args.ms07_target_browser),
                existing_path(args.ms07_target_state),
                int(context["fixtures"]["ref"]["subscription_id"]),
                int(context["fixtures"]["target"]["subscription_id"]),
            ),
            context,
        )
        written_flows.add(FLOW_MS07)

    if args.token_continuity_gate:
        write_result(out_dir, FLOW_SS10, build_token_continuity_result(existing_path(args.token_continuity_gate)), context)
        written_flows.add(FLOW_SS10)

    for flow in args.require_agent_flow:
        if flow not in written_flows:
            write_result(out_dir, flow, build_missing_agent_result(flow), context)
            written_flows.add(flow)

    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"build-agent-results.py: {exc}", file=sys.stderr)
        raise SystemExit(1)
