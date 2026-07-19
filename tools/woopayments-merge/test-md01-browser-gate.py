#!/usr/bin/env python3
"""Focused safety regressions for the MD-01 Playwright producer."""

from __future__ import annotations

import importlib.util
import json
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
GATE = ROOT / "tools/woopayments-merge/md01-browser-gate.py"
SCENARIO = ROOT / "tools/woopayments-merge/md01-created.playwright.mjs"
ASSERTIONS = ROOT / "tools/woopayments-merge/md01-browser-assertions.cjs"
RUNNER = ROOT / "tools/woopayments-merge/playwright-script-runner.mjs"


def load_gate():
    spec = importlib.util.spec_from_file_location("md01_browser_gate", GATE)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def valid_expected(store: str = "ref") -> dict:
    return {
        "store": store,
        "run_stamp": "20260719T140000Z-4242",
        "runtime_owner": "plugin" if store == "ref" else "native",
        "manifest_sha256": "sha256:" + "1" * 64,
        "base_url": "http://localhost:8082" if store == "ref" else "http://store8889.localhost:8889",
        "order_path": "/wp-admin/admin.php?page=wc-orders&action=edit&id=101",
        "disputes_path": "/wp-admin/admin.php?page=wc-admin&path=/payments/disputes"
        if store == "ref"
        else "/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/disputes",
        "order_id": 101,
        "charge_id": f"ch_{store}_md01",
        "intent_id": f"pi_{store}_md01",
        "dispute_id": f"dp_{store}_md01",
        "amount_minor": 5000,
        "currency": "USD",
        "reason": "fraudulent",
        "reason_label": "Transaction unauthorized",
        "awaiting_response_count": 8,
    }


def valid_browser_payload(store: str = "ref") -> dict:
    expected = valid_expected(store)
    return {
        "schema": "woopayments_md01_browser_raw.v1",
        "status": "pass",
        "store": store,
        "run_stamp": expected["run_stamp"],
        "runtime_owner": expected["runtime_owner"],
        "deterministic_manifest_sha256": expected["manifest_sha256"],
        "identity": {
            "order_id": expected["order_id"],
            "charge_id": expected["charge_id"],
            "intent_id": expected["intent_id"],
            "dispute_id": expected["dispute_id"],
        },
        "assertions": {
            "authenticated_admin": True,
            "order_on_hold": True,
            "created_note": True,
            "note_reason_context": True,
            "note_response_due_context": True,
            "exact_dispute_row": True,
            "needs_response": True,
            "amount": True,
            "reason": True,
            "respond_action": True,
            "badge_count": True,
        },
        "failed_responses": [],
        "console_errors": [],
        "page_errors": [],
        "screenshots": [f"{store}-order.png", f"{store}-disputes.png"],
        "errors": [],
        "blockers": [],
    }


def test_gate_and_scenario_are_direct_playwright_only() -> None:
    gate = GATE.read_text(encoding="utf-8")
    scenario = SCENARIO.read_text(encoding="utf-8")
    assert "playwright-script-runner.mjs" in gate
    assert "PLAYWRIGHT_SCRIPT_RUNNER_BIN" not in gate
    assert "choices=(\"playwright\",)" in gate
    assert "playwriter" not in gate.lower()
    assert "playwriter" not in scenario.lower()
    assert "context.newPage()" in scenario
    assert "context.addCookies" in scenario
    assert "page.close()" in scenario


def test_state_driver_parser_returns_exact_action_envelope_not_nested_cookie() -> None:
    module = load_gate()
    envelope = {
        "success": True,
        "mode": "create-auth-session",
        "user_id": 1,
        "auth_cookies": [
            {"scheme": "auth", "name": "wordpress_hash", "value": "secret-auth-value"},
            {"scheme": "secure_auth", "name": "wordpress_sec_hash", "value": "secret-secure-value"},
            {"scheme": "logged_in", "name": "wordpress_logged_in_hash", "value": "secret-logged-in-value"},
        ],
        "errors": [],
    }
    output = "WP-CLI notice\n" + json.dumps(envelope) + "\n"
    assert module.parse_last_json(output, "create-auth-session") == envelope

    with pytest.raises(module.GateBlocked):
        module.parse_last_json(output, "destroy-auth-session")
    with pytest.raises(module.GateBlocked):
        module.parse_last_json("x" * (2 * 1024 * 1024 + 1), "create-auth-session")


def test_session_auth_cookies_require_all_three_exact_schemes() -> None:
    module = load_gate()
    session = {
        "auth_cookies": [
            {"scheme": "auth", "name": "wordpress_hash", "value": "auth-secret"},
            {"scheme": "secure_auth", "name": "wordpress_sec_hash", "value": "secure-secret"},
            {"scheme": "logged_in", "name": "wordpress_logged_in_hash", "value": "logged-in-secret"},
        ]
    }
    assert module.session_auth_cookies(session) == [
        {"name": "wordpress_hash", "value": "auth-secret"},
        {"name": "wordpress_sec_hash", "value": "secure-secret"},
        {"name": "wordpress_logged_in_hash", "value": "logged-in-secret"},
    ]

    for invalid in (
        {"auth_cookies": session["auth_cookies"][:2]},
        {"auth_cookies": [*session["auth_cookies"][:2], dict(session["auth_cookies"][1])]},
        {"auth_cookies": [dict(session["auth_cookies"][0], value=""), *session["auth_cookies"][1:]]},
    ):
        with pytest.raises(module.GateBlocked):
            module.session_auth_cookies(invalid)


def test_browser_payload_requires_every_ui_assertion_and_exact_binding() -> None:
    module = load_gate()
    expected = valid_expected()
    assert module.browser_payload_errors(valid_browser_payload(), expected) == []

    for assertion in valid_browser_payload()["assertions"]:
        payload = valid_browser_payload()
        payload["assertions"][assertion] = False
        assert module.browser_payload_errors(payload, expected), assertion

    for field in ("store", "run_stamp", "runtime_owner", "deterministic_manifest_sha256"):
        payload = valid_browser_payload()
        payload[field] = "wrong"
        assert module.browser_payload_errors(payload, expected), field


def test_browser_payload_rejects_browser_diagnostics_and_secret_retention() -> None:
    module = load_gate()
    expected = valid_expected()
    for field, value in (
        ("failed_responses", [{"status": 500, "url": "http://localhost/error"}]),
        ("console_errors", [{"type": "error", "text_sha256": "sha256:" + "2" * 64}]),
        ("page_errors", [{"message_sha256": "sha256:" + "3" * 64}]),
        ("errors", ["failure"]),
        ("blockers", ["login redirect"]),
    ):
        payload = valid_browser_payload()
        payload[field] = value
        assert module.browser_payload_errors(payload, expected), field

    payload = valid_browser_payload()
    payload["cookie"] = "wordpress_logged_in_secret"
    assert module.browser_payload_errors(payload, expected)
    assert "auth_cookie" not in json.dumps(valid_browser_payload()).lower()


def test_screenshot_validation_is_current_bounded_and_non_symlinked(tmp_path: Path) -> None:
    module = load_gate()
    screenshot = tmp_path / "ref-order.png"
    screenshot.write_bytes(b"\x89PNG\r\n\x1a\n" + b"x" * 32)
    assert module.screenshot_error(screenshot, tmp_path, 0) is None

    link = tmp_path / "ref-disputes.png"
    try:
        link.symlink_to(screenshot)
    except OSError:
        pytest.skip("symlinks unavailable")
    assert module.screenshot_error(link, tmp_path, 0)

    oversized = tmp_path / "large.png"
    oversized.write_bytes(b"x" * (10 * 1024 * 1024 + 1))
    assert module.screenshot_error(oversized, tmp_path, 0)

    parent_link = tmp_path / "linked-parent"
    try:
        parent_link.symlink_to(tmp_path, target_is_directory=True)
    except OSError:
        pytest.skip("directory symlinks unavailable")
    (tmp_path / "artifact.json").write_text("{}\n", encoding="utf-8")
    assert module.has_symlink_component(parent_link / "artifact.json") is True
    with pytest.raises(module.GateBlocked):
        module.safe_json(parent_link / "artifact.json")


def test_session_cleanup_and_signal_paths_are_explicit() -> None:
    source = GATE.read_text(encoding="utf-8")
    for required in (
        "create-auth-session",
        "destroy-auth-session",
        "EXIT_CLEANUP = 70",
        "finally:",
        "signal.SIGHUP",
        "signal.SIGINT",
        "signal.SIGTERM",
        "cleanup_failures",
        "session_auth_cookies",
    ):
        assert required in source
    assert "delete_all" not in source
    assert "destroy_all" not in source
    assert "wp user delete" not in source


def test_scenario_proves_order_dispute_status_badge_and_screenshots() -> None:
    source = SCENARIO.read_text(encoding="utf-8")
    for required in (
        "authenticated_admin",
        "order_on_hold",
        "created_note",
        "note_reason_context",
        "note_response_due_context",
        "exact_dispute_row",
        "needs_response",
        "respond_action",
        "badge_count",
        "page.screenshot",
        "failed_responses",
        "console_errors",
        "page_errors",
        "statusControls",
        "selectedOptions",
        "authCookies",
    ):
        assert required in source
    assert "assertions.order_on_hold = /\\bon[ -]?hold\\b/i.test( orderText )" not in source


def test_browser_contract_uses_real_provider_route_and_merchant_reason_copy() -> None:
    module = load_gate()
    source = SCENARIO.read_text(encoding="utf-8")

    assert module.merchant_reason_label("fraudulent") == "Transaction unauthorized"
    with pytest.raises(module.GateBlocked):
        module.merchant_reason_label("unknown_reason")

    target = valid_expected("target")
    assert "page=wc-settings&tab=checkout" in target["disputes_path"]
    assert "page=wc-admin" not in target["disputes_path"]
    assert "innerText ||" in source
    assert "config.reasonLabel" in source
    assert "response needed|needs response" in source
    assert "summary_count" not in source
    assert "summary_count" not in GATE.read_text(encoding="utf-8")


def test_scenario_amount_and_badge_assertions_are_exact() -> None:
    source = SCENARIO.read_text(encoding="utf-8")
    script = """
const assertions = require( process.argv[ 1 ] );
console.log( JSON.stringify( {
    exactAmount: assertions.rowHasExpectedAmount( '$25.00', 2500 ),
    prefixedAmount: assertions.rowHasExpectedAmount( 'Total USD 25.00 disputed', 2500 ),
    superstringAmount: assertions.rowHasExpectedAmount( '$125.00', 2500 ),
    extraPrecisionAmount: assertions.rowHasExpectedAmount( '$25.000', 2500 ),
    exactBadge: assertions.badgeHasExpectedCount( '13', 13 ),
    suffixedBadge: assertions.badgeHasExpectedCount( '13 stale', 13 ),
    exactOrder: assertions.rowHasExpectedOrderId( {
        links: [ { text: '123', href: 'http://localhost/wp-admin/admin.php?page=wc-orders&action=edit&id=123' } ],
    }, 123 ),
    superstringOrderText: assertions.rowHasExpectedOrderId( {
        links: [ { text: '1234', href: 'http://localhost/wp-admin/admin.php?page=wc-orders&action=edit&id=123' } ],
    }, 123 ),
    superstringOrderHref: assertions.rowHasExpectedOrderId( {
        links: [ { text: '123', href: 'http://localhost/wp-admin/admin.php?page=wc-orders&action=edit&id=1234' } ],
    }, 123 ),
    exactDisputeId: assertions.textHasExpectedIdentifier( 'Respond to dispute dp_123 now', 'dp_123' ),
    superstringDisputeId: assertions.textHasExpectedIdentifier( 'Respond to dispute dp_1234 now', 'dp_123' ),
} ) );
"""
    result = subprocess.run(
        ["node", "-e", script, str(ASSERTIONS)],
        cwd=ROOT,
        text=True,
        capture_output=True,
        check=False,
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert json.loads(result.stdout) == {
        "exactAmount": True,
        "prefixedAmount": True,
        "superstringAmount": False,
        "extraPrecisionAmount": False,
        "exactBadge": True,
        "suffixedBadge": False,
        "exactOrder": True,
        "superstringOrderText": False,
        "superstringOrderHref": False,
        "exactDisputeId": True,
        "superstringDisputeId": False,
    }
    assert "rowHasExpectedAmount" in source
    assert "badgeHasExpectedCount" in source
    assert "rowHasExpectedOrderId" in source
    assert "textHasExpectedIdentifier" in source
    assert "rowText.includes( visibleAmount" not in source
    assert "Number.parseInt( value" not in source
    assert "text.includes( String( expected.orderId ) )" not in source


def test_browser_runner_redacts_every_short_lived_cookie_value() -> None:
    source = GATE.read_text(encoding="utf-8")
    assert 'config.get("authCookie"' not in source
    assert 'config.get("authCookies"' in source


def test_scenario_ignores_only_exact_order_sample_permalink_noise() -> None:
    source = SCENARIO.read_text(encoding="utf-8")
    for required in (
        "isExactSamplePermalinkFailure",
        "response.status() !== 403",
        "request.method() !== 'POST'",
        "parsed.pathname !== '/wp-admin/admin-ajax.php'",
        "fields.get( 'action' ) !== 'sample-permalink'",
        "fields.get( 'post_id' ) !== String( config.orderId )",
        "fields.size !== 2",
        "ignoredSamplePermalinkConsoleAllowances",
        "Failed to load resource: the server responded with a status of 403 (Forbidden)",
    ):
        assert required in source
