#!/usr/bin/env python3
"""Regression checks for critical-flow Layer-A result generation."""

from __future__ import annotations

import importlib.util
import json
import subprocess
import tempfile
from pathlib import Path


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-critical-flows/build-agent-results.py"
CONTEXT_MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"


def load_context_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", CONTEXT_MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTEXT_MODULE = load_context_module()


def builder_context(out_dir: Path, subscriptions: tuple[str, str] = ("862", "366")) -> tuple[Path, dict]:
    path = out_dir.parent / "critical-flow-context.json"
    context = CONTEXT_MODULE.build_context(
        aggregate_run_id="test-run",
        source={"head_sha": "a" * 40, "worktree_sha256": "sha256:" + "b" * 64},
        stores={
            "ref": {
                "store_fingerprint": "sha256:" + "c" * 64,
                "runtime_owner": "plugin",
                "account_state_sha256": "sha256:" + "d" * 64,
            },
            "target": {
                "store_fingerprint": "sha256:" + "e" * 64,
                "runtime_owner": "native",
                "account_state_sha256": "sha256:" + "f" * 64,
            },
        },
        fixtures={
            "ref": {"subscription_id": subscriptions[0]},
            "target": {"subscription_id": subscriptions[1]},
        },
    )
    write_json(path, context)
    return path, context


def run_builder(
    *args: str,
    context_subscriptions: tuple[str, str] = ("862", "366"),
) -> subprocess.CompletedProcess[str]:
    out_dir = Path(args[args.index("--out-dir") + 1])
    context_path, _ = builder_context(out_dir, context_subscriptions)
    return subprocess.run(
        ["python3", str(SCRIPT), "--context-file", str(context_path), *args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )


def write_json(path: Path, payload: dict) -> None:
    path.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def read_json(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


def plugin_active_rollup(store_url: str, screenshot: str) -> dict:
    return {
        "schema": "woopayments_plugin_active_settings_gate_rollup.v1",
        "status": "pass",
        "settings_url": f"{store_url}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments",
        "target_url": store_url,
        "blockers": [],
        "failures": [],
        "evidence": {
            "settings_screen_present": True,
            "duplicate_store_errors": [],
            "fatal_console_errors": [],
            "failed_responses": [],
            "blockers": [],
            "failures": [],
            "screenshot_path": screenshot,
            "page": {
                "finalUrl": f"{store_url}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments",
            },
        },
    }


def token_continuity_rollup(screenshot: Path) -> dict:
    return {
        "schema": "woopayments_token_continuity_gate_rollup.v1",
        "status": "pass",
        "source_flow": "provider_setup_intent",
        "token_id": 13,
        "failures": [],
        "blockers": [],
        "source_token": {
            "success": True,
            "payment_method_id": "pm_unit",
            "source_payment_method_customer_ready": True,
        },
        "native_token_loader": {
            "success": True,
            "token_id": 13,
            "gateway_id": "woocommerce_payments_sepa_debit",
            "token_type": "wcpay_sepa",
            "token_class": "WooPaymentsSepaToken",
        },
        "render_payment_methods": {
            "status": "pass",
            "token_id": 13,
            "token_visible": True,
            "page": {
                "screenshot_path": str(screenshot),
                "payment_methods": {
                    "token_visible": True,
                },
            },
        },
        "renewal": {
            "success": True,
            "renewal_order_id": 261,
            "renewal_order_status": "pending",
            "renewal_processing_model": "asynchronous_processing",
            "subscription_status": "on-hold",
            "success_checks_failed": [],
        },
    }


def ms07_browser_result(store: str, evidence_dir: str, subscription_id: int, before: str, selected: str) -> dict:
    return {
        "store": store,
        "subscription_id": subscription_id,
        "selected_token_id": selected,
        "evidence_dir": evidence_dir,
        "errors": [],
        "method_options": [
            {"value": "manual", "text": "Manual Renewal", "selected": False},
            {"value": "woocommerce_payments", "text": "Card", "selected": True},
        ],
        "token_options_before": [
            {"value": before, "text": "Visa ending in 4242", "selected": True},
            {"value": selected, "text": "Visa ending in 4242", "selected": False},
        ],
        "token_value_before": before,
        "token_value_after_select": selected,
        "notice_text": ["Subscription updated."],
    }


def ms07_state_result(store: str, subscription_id: int, token_id: int, renewal_order_id: int) -> dict:
    return {
        "store": store,
        "success": True,
        "subscription_id": subscription_id,
        "expected_token_id": token_id,
        "renewal_order_id": renewal_order_id,
        "subscription_status": "active",
        "subscription_payment_method": "woocommerce_payments",
        "subscription_payment_method_id": f"pm_{store}_{token_id}",
        "subscription_stripe_customer_id_present": True,
        "subscription_token_ids": [token_id - 1, token_id],
        "last_token_id": token_id,
        "last_token_pm": f"pm_{store}_{token_id}",
        "renewal_status": "processing",
        "renewal_payment_method": "woocommerce_payments",
        "renewal_meta_presence": {
            "_intent_id": True,
            "_charge_id": True,
            "_payment_method_id": True,
            "_stripe_customer_id": True,
        },
    }


def sc04_browser_result(
    context: dict,
    store: str,
    home_url: str,
    customer_id: int,
    token_id: int,
    order_ids: tuple[int, int, int, int],
    screenshots: tuple[Path, Path, Path, Path],
) -> dict:
    surfaces = {}
    surface_names = ("classic", "blocks", "sca_classic", "sca_blocks")
    sca_token_id = token_id + 1
    for surface, order_id, screenshot in zip(surface_names, order_ids, screenshots, strict=True):
        requires_sca = surface.startswith("sca_")
        surfaces[surface] = {
            "success": True,
            "order_id": order_id,
            "selected_token_id": sca_token_id if requires_sca else token_id,
            "final_url": f"{home_url}/checkout/order-received/{order_id}/?key=wc_order_test",
            "saved_card_visible": True,
            "saved_card_selected": True,
            "new_card_fields_forced": False,
            "sca_challenge_present": requires_sca,
            "sca_challenge_completed": requires_sca,
            "expected_amount": "20.00",
            "expected_currency": "USD",
            "screenshot_paths": [str(screenshot)],
            "errors": [],
        }
    return {
        "schema": "woopayments_sc04_browser_evidence.v1",
        "context_binding": {
            "aggregate_run_id": context["aggregate_run_id"],
            "context_sha256": context["context_sha256"],
        },
        "store": store,
        "home_url": home_url,
        "customer_id": customer_id,
        "token_id": token_id,
        "sca_token_id": sca_token_id,
        "surfaces": surfaces,
        "fatal_console_errors": [],
        "fatal_response_errors": [],
        "errors": [],
    }


def sc04_state_result(
    context: dict,
    store: str,
    home_url: str,
    customer_id: int,
    token_id: int,
    order_ids: tuple[int, int, int, int],
) -> dict:
    payment_method_id = f"pm_{store}_{token_id}"
    sca_token_id = token_id + 1
    sca_payment_method_id = f"pm_{store}_{sca_token_id}"
    orders = {}
    for surface, order_id in zip(("classic", "blocks", "sca_classic", "sca_blocks"), order_ids, strict=True):
        requires_sca = surface.startswith("sca_")
        orders[surface] = {
            "success": True,
            "order_id": order_id,
            "customer_id": customer_id,
            "status": "processing",
            "payment_method": "woocommerce_payments",
            "payment_method_id": sca_payment_method_id if requires_sca else payment_method_id,
            "amount": "20.00",
            "currency": "USD",
            "meta_presence": {
                "_intent_id": True,
                "_charge_id": True,
            },
            "errors": [],
        }
    return {
        "schema": "woopayments_sc04_state_evidence.v1",
        "context_binding": {
            "aggregate_run_id": context["aggregate_run_id"],
            "context_sha256": context["context_sha256"],
        },
        "store": store,
        "home_url": home_url,
        "source": context["source"],
        "store_context": context["stores"][store],
        "customer_id": customer_id,
        "token": {
            "id": token_id,
            "user_id": customer_id,
            "gateway_id": "woocommerce_payments",
            "payment_method_id": payment_method_id,
        },
        "sca_token": {
            "id": sca_token_id,
            "user_id": customer_id,
            "gateway_id": "woocommerce_payments",
            "payment_method_id": sca_payment_method_id,
        },
        "orders": orders,
        "errors": [],
    }


def test_builds_plugin_active_agent_result_from_reference_and_target_gates() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        ref_gate = tmp_path / "ref-plugin-active.json"
        target_gate = tmp_path / "target-plugin-active.json"
        (tmp_path / "ref.png").write_bytes(b"reference screenshot")
        (tmp_path / "target.png").write_bytes(b"target screenshot")
        write_json(ref_gate, plugin_active_rollup("http://localhost:8082", str(tmp_path / "ref.png")))
        write_json(target_gate, plugin_active_rollup("http://store8889.localhost:8889", str(tmp_path / "target.png")))

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--plugin-active-reference-gate",
            str(ref_gate),
            "--plugin-active-target-gate",
            str(target_gate),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MA-11-plugin-active-settings-screen.json")
        assert payload["flow"] == "MA-11-plugin-active-settings-screen"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert all(store["evidence"] for store in payload["store_results"])


def test_lpm_gate_blocked_result_stays_blocked_with_method_context() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        lpm_gate = tmp_path / "lpm-checkout-gate.json"
        write_json(
            lpm_gate,
            {
                "schema": "woopayments_lpm_checkout_gate.v1",
                "status": "blocked",
                "failures": [],
                "blockers": [
                    "reference/p24: could not stage LPM fixture",
                    "target/p24: could not stage LPM fixture",
                ],
                "results": [
                    {
                        "role": "reference",
                        "method": "ideal",
                        "status": "pass",
                        "gateway_id": "woocommerce_payments_ideal",
                    },
                    {
                        "role": "target",
                        "method": "ideal",
                        "status": "pass",
                        "gateway_id": "woocommerce_payments_ideal",
                    },
                ],
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--lpm-gate", str(lpm_gate))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-14-lpm-wave-1-checkout.json")
        assert payload["flow"] == "SC-14-lpm-wave-1-checkout"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "Runnable methods passed: ideal." in payload["store_results"][0]["ux_observations"]
        assert "target/p24: could not stage LPM fixture" in payload["store_results"][1]["ux_observations"]


def test_token_continuity_pass_maps_to_ss10_agent_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        token_gate = tmp_path / "token-continuity-gate.json"
        screenshot = tmp_path / "payment-methods.png"
        screenshot.write_bytes(b"payment methods screenshot")
        write_json(token_gate, token_continuity_rollup(screenshot))

        result = run_builder("--out-dir", str(out_dir), "--token-continuity-gate", str(token_gate))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SS-10-sepa-token-renewal-cutover.json")
        assert payload["flow"] == "SS-10-sepa-token-renewal-cutover"
        assert payload["oracle_mode"] == "target-only"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "PASS"]
        assert "target-only" in payload["regression_note"].lower()
        assert "token_visible=True" in payload["store_results"][1]["end_state"]
        assert str(screenshot) in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_token_continuity_nominal_pass_requires_each_target_proof() -> None:
    mutations = {
        "source token": lambda payload: payload["source_token"].update(
            source_payment_method_customer_ready=False
        ),
        "native token loader": lambda payload: payload["native_token_loader"].update(success=False),
        "My Account token rendering": lambda payload: payload["render_payment_methods"].update(
            token_visible=False
        ),
        "SEPA renewal": lambda payload: payload["renewal"].update(success=False),
    }

    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        screenshot = tmp_path / "payment-methods.png"
        screenshot.write_bytes(b"payment methods screenshot")

        for case, mutate in mutations.items():
            case_dir = tmp_path / case.replace(" ", "-")
            case_dir.mkdir()
            token_gate = case_dir / "token-continuity-gate.json"
            payload = token_continuity_rollup(screenshot)
            mutate(payload)
            write_json(token_gate, payload)

            result = run_builder(
                "--out-dir",
                str(case_dir / "agent-results"),
                "--token-continuity-gate",
                str(token_gate),
            )

            assert result.returncode == 0, result.stderr
            built = read_json(case_dir / "agent-results" / "SS-10-sepa-token-renewal-cutover.json")
            assert built["oracle_mode"] == "target-only", case
            assert built["parity_verdict"] == "BLOCKED", case
            assert [store["verdict"] for store in built["store_results"]] == ["BLOCKED", "BLOCKED"], case
            assert any(
                case in observation for observation in built["store_results"][1]["ux_observations"]
            ), case


def test_ms07_browser_and_state_results_map_to_agent_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        ref_browser = tmp_path / "ref-browser.json"
        ref_state = tmp_path / "ref-state.json"
        target_browser = tmp_path / "target-browser.json"
        target_state = tmp_path / "target-state.json"
        ref_evidence = tmp_path / "ref-evidence"
        target_evidence = tmp_path / "target-evidence"
        ref_evidence.mkdir()
        target_evidence.mkdir()
        (target_evidence / "selected.png").write_text("fake", encoding="utf-8")
        write_json(ref_browser, ms07_browser_result("ref", str(ref_evidence), 862, "31", "36"))
        write_json(ref_state, ms07_state_result("ref", 862, 36, 863))
        write_json(target_browser, ms07_browser_result("target", str(target_evidence), 366, "14", "15"))
        write_json(target_state, ms07_state_result("target", 366, 15, 367))

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--ms07-reference-browser",
            str(ref_browser),
            "--ms07-reference-state",
            str(ref_state),
            "--ms07-target-browser",
            str(target_browser),
            "--ms07-target-state",
            str(target_state),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["flow"] == "MS-07-admin-change-method"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert "selected_token=15" in payload["store_results"][1]["end_state"]
        assert str(target_evidence / "selected.png") in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_ms07_stale_subscription_sources_are_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        ref_browser = tmp_path / "ref-browser.json"
        ref_state = tmp_path / "ref-state.json"
        target_browser = tmp_path / "target-browser.json"
        target_state = tmp_path / "target-state.json"
        write_json(ref_browser, ms07_browser_result("ref", str(tmp_path / "ref"), 862, "31", "36"))
        write_json(ref_state, ms07_state_result("ref", 862, 36, 863))
        write_json(target_browser, ms07_browser_result("target", str(tmp_path / "target"), 366, "14", "15"))
        write_json(target_state, ms07_state_result("target", 366, 15, 367))

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--ms07-reference-browser",
            str(ref_browser),
            "--ms07-reference-state",
            str(ref_state),
            "--ms07-target-browser",
            str(target_browser),
            "--ms07-target-state",
            str(target_state),
            context_subscriptions=("1283", "874"),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "expected subscription=1283" in payload["store_results"][0]["end_state"]
        assert "browser subscription=862" in payload["store_results"][0]["end_state"]
        assert "expected subscription=874" in payload["store_results"][1]["end_state"]
        assert "state subscription=366" in payload["store_results"][1]["end_state"]


def test_required_ms07_without_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder("--out-dir", str(out_dir), "--require-ms07")

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "MS-07-admin-change-method.json")
        assert payload["flow"] == "MS-07-admin-change-method"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "browser evidence" in payload["store_results"][0]["end_state"]


def test_sc04_browser_and_state_results_map_to_agent_pass() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        sources = {}
        fixtures = {
            "ref": ("http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304)),
            "target": ("http://store8889.localhost:8889", 3, 16, (901, 902, 903, 904)),
        }
        for store, (home_url, customer_id, token_id, order_ids) in fixtures.items():
            browser = tmp_path / f"{store}-sc04-browser.json"
            state = tmp_path / f"{store}-sc04-state.json"
            screenshots = (
                tmp_path / f"{store}-classic-selected.png",
                tmp_path / f"{store}-blocks-selected.png",
                tmp_path / f"{store}-sca-classic-selected.png",
                tmp_path / f"{store}-sca-blocks-selected.png",
            )
            for screenshot in screenshots:
                screenshot.write_bytes(f"{store} screenshot".encode())
            write_json(
                browser,
                sc04_browser_result(context, store, home_url, customer_id, token_id, order_ids, screenshots),
            )
            write_json(
                state,
                sc04_state_result(context, store, home_url, customer_id, token_id, order_ids),
            )
            sources[store] = (browser, state)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(sources["ref"][0]),
            "--sc04-reference-state",
            str(sources["ref"][1]),
            "--sc04-target-browser",
            str(sources["target"][0]),
            "--sc04-target-state",
            str(sources["target"][1]),
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "PASS"
        assert [store["verdict"] for store in payload["store_results"]] == ["PASS", "PASS"]
        assert "classic_order=901" in payload["store_results"][1]["end_state"]
        assert "blocks_order=902" in payload["store_results"][1]["end_state"]
        assert str(tmp_path / "target-blocks-selected.png") in {
            artifact["path"] for artifact in payload["store_results"][1]["evidence"]
        }


def test_sc04_stale_store_or_source_context_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "ref-sc04-browser.json"
        state = tmp_path / "ref-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(browser, sc04_browser_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304), screenshots))
        stale = sc04_state_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304))
        stale["source"] = {**stale["source"], "worktree_sha256": "sha256:" + "0" * 64}
        write_json(state, stale)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(browser),
            "--sc04-reference-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["store_results"][0]["verdict"] == "BLOCKED"
        assert "source snapshot does not match" in payload["store_results"][0]["ux_observations"]


def test_sc04_complete_saved_card_regression_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        browser_payload["surfaces"]["blocks"]["success"] = False
        browser_payload["surfaces"]["blocks"]["new_card_fields_forced"] = True
        browser_payload["surfaces"]["blocks"]["errors"] = ["empty new-card Element was forced"]
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
            ),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "FAIL - functional"
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"
        assert "empty new-card Element was forced" in payload["store_results"][1]["ux_observations"]


def test_sc04_context_binding_mismatch_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "ref-sc04-browser.json"
        state = tmp_path / "ref-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "ref",
            "http://localhost:8082",
            17,
            37,
            (1301, 1302, 1303, 1304),
            screenshots,
        )
        browser_payload["context_binding"]["aggregate_run_id"] = "older-run"
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(context, "ref", "http://localhost:8082", 17, 37, (1301, 1302, 1303, 1304)),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-reference-browser",
            str(browser),
            "--sc04-reference-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert "browser context binding does not match" in payload["store_results"][0]["ux_observations"]


def test_sc04_amount_or_currency_mismatch_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = (
            tmp_path / "classic.png",
            tmp_path / "blocks.png",
            tmp_path / "sca-classic.png",
            tmp_path / "sca-blocks.png",
        )
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(
            browser,
            sc04_browser_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
                screenshots,
            ),
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        state_payload["orders"]["blocks"]["amount"] = "21.00"
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "FAIL - functional"
        assert "blocks: amount mismatch" in payload["store_results"][1]["ux_observations"]


def test_sc04_missing_saved_token_sca_surface_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        browser_payload["surfaces"].pop("sca_blocks")
        state_payload["orders"].pop("sca_blocks")
        write_json(browser, browser_payload)
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "sca_blocks browser evidence is missing" in payload["store_results"][1]["ux_observations"]


def test_sc04_incomplete_saved_token_sca_challenge_is_a_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        browser_payload["surfaces"]["sca_classic"]["sca_challenge_completed"] = False
        write_json(browser, browser_payload)
        write_json(
            state,
            sc04_state_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
            ),
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "FAIL - functional"
        assert "sca_classic: saved-token SCA challenge did not complete" in payload["store_results"][1]["ux_observations"]


def test_sc04_omitted_required_assertion_is_blocked_not_failed() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        browser_payload = sc04_browser_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
            screenshots,
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        browser_payload["surfaces"]["blocks"].pop("saved_card_selected")
        state_payload["orders"]["blocks"]["meta_presence"].pop("_charge_id")
        write_json(browser, browser_payload)
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "blocks: saved_card_selected assertion is missing" in payload["store_results"][1]["ux_observations"]
        assert "blocks: _charge_id assertion is missing" in payload["store_results"][1]["ux_observations"]


def test_sc04_non_provider_payment_method_fixture_is_blocked() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        _, context = builder_context(out_dir)
        browser = tmp_path / "target-sc04-browser.json"
        state = tmp_path / "target-sc04-state.json"
        screenshots = tuple(tmp_path / f"surface-{index}.png" for index in range(4))
        for screenshot in screenshots:
            screenshot.write_bytes(b"screenshot")
        write_json(
            browser,
            sc04_browser_result(
                context,
                "target",
                "http://store8889.localhost:8889",
                3,
                16,
                (901, 902, 903, 904),
                screenshots,
            ),
        )
        state_payload = sc04_state_result(
            context,
            "target",
            "http://store8889.localhost:8889",
            3,
            16,
            (901, 902, 903, 904),
        )
        state_payload["token"]["payment_method_id"] = "not-a-provider-payment-method"
        state_payload["sca_token"]["payment_method_id"] = "also-not-a-provider-payment-method"
        for order in state_payload["orders"].values():
            order["payment_method_id"] = (
                state_payload["sca_token"]["payment_method_id"]
                if order["order_id"] in {903, 904}
                else state_payload["token"]["payment_method_id"]
            )
        write_json(state, state_payload)

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--sc04-target-browser",
            str(browser),
            "--sc04-target-state",
            str(state),
            "--require-sc04",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["store_results"][1]["verdict"] == "BLOCKED"
        assert "saved token provider payment method id is invalid" in payload["store_results"][1]["ux_observations"]
        assert "saved SCA token provider payment method id is invalid" in payload["store_results"][1]["ux_observations"]


def test_required_sc04_without_raw_or_prebuilt_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder("--out-dir", str(out_dir), "--require-sc04")

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "browser evidence" in payload["store_results"][0]["end_state"]


def test_copies_existing_agent_result_after_contract_validation() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        _, context = builder_context(out_dir)
        write_json(
            source,
            {
                "schema": "woopayments_critical_flow_result.v2",
                "flow": "SC-04-saved-card",
                "store_results": [
                    {
                        "store": "ref",
                        "verdict": "PASS",
                    },
                    {
                        "store": "target",
                        "verdict": "PASS",
                    },
                ],
                "parity_verdict": "PASS",
                "regression_note": "copied",
                "provenance": {"capture": CONTEXT_MODULE.build_capture(context, "capture-sc04")},
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--copy-agent-result", str(source))

        assert result.returncode == 0, result.stderr
        assert read_json(out_dir / "SC-04-saved-card.json")["regression_note"] == "copied"


def test_legacy_agent_result_is_preserved_as_non_gating_blocked_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        write_json(
            source,
            {
                "flow": "SC-04-saved-card",
                "store_results": [{"store": "ref", "verdict": "PASS"}, {"store": "target", "verdict": "PASS"}],
                "parity_verdict": "PASS",
                "regression_note": "legacy pass",
            },
        )

        result = run_builder("--out-dir", str(out_dir), "--copy-agent-result", str(source))

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "BLOCKED"
        assert payload["provenance_error"]["code"] == "evidence_provenance_missing"
        assert payload["schema"] == "woopayments_critical_flow_result.v2"


def test_required_agent_flow_without_source_writes_blocked_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--require-agent-flow",
            "SC-04-saved-card",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["flow"] == "SC-04-saved-card"
        assert payload["parity_verdict"] == "BLOCKED"
        assert [store["verdict"] for store in payload["store_results"]] == ["BLOCKED", "BLOCKED"]
        assert "Layer-A evidence for SC-04-saved-card is missing." in payload["regression_note"]


def test_required_agent_flow_does_not_overwrite_copied_result() -> None:
    with tempfile.TemporaryDirectory(prefix="critical-agent-results-") as tmp:
        tmp_path = Path(tmp)
        out_dir = tmp_path / "agent-results"
        source = tmp_path / "SC-04-saved-card.json"
        _, context = builder_context(out_dir)
        write_json(
            source,
            {
                "schema": "woopayments_critical_flow_result.v2",
                "flow": "SC-04-saved-card",
                "store_results": [{"store": "ref", "verdict": "PASS"}, {"store": "target", "verdict": "PASS"}],
                "parity_verdict": "PASS",
                "regression_note": "copied-pass",
                "provenance": {"capture": CONTEXT_MODULE.build_capture(context, "capture-sc04")},
            },
        )

        result = run_builder(
            "--out-dir",
            str(out_dir),
            "--copy-agent-result",
            str(source),
            "--require-agent-flow",
            "SC-04-saved-card",
        )

        assert result.returncode == 0, result.stderr
        payload = read_json(out_dir / "SC-04-saved-card.json")
        assert payload["parity_verdict"] == "PASS"
        assert payload["regression_note"] == "copied-pass"


def main() -> None:
    tests = [
        test_builds_plugin_active_agent_result_from_reference_and_target_gates,
        test_lpm_gate_blocked_result_stays_blocked_with_method_context,
        test_token_continuity_pass_maps_to_ss10_agent_result,
        test_ms07_browser_and_state_results_map_to_agent_pass,
        test_ms07_stale_subscription_sources_are_blocked,
        test_required_ms07_without_source_writes_blocked_result,
        test_sc04_browser_and_state_results_map_to_agent_pass,
        test_sc04_stale_store_or_source_context_is_blocked,
        test_sc04_complete_saved_card_regression_is_a_failure,
        test_sc04_context_binding_mismatch_is_blocked,
        test_sc04_amount_or_currency_mismatch_is_a_failure,
        test_sc04_missing_saved_token_sca_surface_is_blocked,
        test_sc04_incomplete_saved_token_sca_challenge_is_a_failure,
        test_sc04_omitted_required_assertion_is_blocked_not_failed,
        test_sc04_non_provider_payment_method_fixture_is_blocked,
        test_required_sc04_without_raw_or_prebuilt_source_writes_blocked_result,
        test_copies_existing_agent_result_after_contract_validation,
        test_legacy_agent_result_is_preserved_as_non_gating_blocked_evidence,
        test_required_agent_flow_without_source_writes_blocked_result,
        test_required_agent_flow_does_not_overwrite_copied_result,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
