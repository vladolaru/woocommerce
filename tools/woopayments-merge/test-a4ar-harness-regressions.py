#!/usr/bin/env python3
"""Focused regression checks for A4ar harness hardening."""

from __future__ import annotations

import importlib.util
import json
import signal
import subprocess
import sys
import tempfile
from pathlib import Path
from types import SimpleNamespace

try:
    import pytest
except ImportError:  # pragma: no cover - keeps direct script execution dependency-light.
    pytest = None


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py"
COMPARE_SCRIPT = REPO / "tools/woopayments-merge/compare-measured-gates.py"


def load_module():
    spec = importlib.util.spec_from_file_location("a4aq_accumulated_gate", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(module)
    return module


if pytest is not None:

    @pytest.fixture(scope="module")
    def module():
        return load_module()


def make_gate(module):
    args = SimpleNamespace(
        repo=str(REPO),
        plugin_repo=str(REPO.parent / "woocommerce-payments"),
        ref_wp="docker exec -i wcpay_wp_default wp --allow-root --user=1",
        target_wp="docker exec -i example-cli-1 wp --allow-root --user=1",
        browser_runner="playwriter",
        playwriter_session="unit",
        out_dir=tempfile.mkdtemp(prefix="a4ar-harness-test-"),
        skip_admin_browser=False,
        skip_optional_admin_scenario=False,
        skip_checkout_browser=True,
        skip_perf=True,
        skip_perf_fixtures=True,
        skip_bundle=True,
    )
    return module.Gate(args)


def assert_raises(fn, expected: str):
    try:
        fn()
    except Exception as exc:
        assert expected in str(exc), f"Expected {expected!r} in {exc!r}"
    else:
        raise AssertionError(f"Expected exception containing {expected!r}")


def test_optional_admin_unavailable_set_is_strict(module):
    gate = make_gate(module)
    result = {"id": "admin-browser", "status": "pass", "failures": [], "incomplete_reasons": []}
    evidence_path = Path(gate.out_dir) / "admin.json"
    evidence_path.write_text(
        """
{
  "stores": [ "target" ],
  "results": [
    { "store": "target", "surface": "admin-navigation", "viewport": "desktop", "passed": true },
    { "store": "target", "surface": "documents", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": false, "coverageStatus": "unavailable-guard-pass" },
    { "store": "target", "surface": "future-route", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": false, "coverageStatus": "unavailable-guard-pass" }
  ]
}
""".strip(),
        encoding="utf-8",
    )

    gate.validate_browser_evidence("admin-browser", evidence_path, result)

    assert result["status"] == "fail"
    assert any("unexpected unavailable target admin routes" in failure for failure in result["failures"])


def test_optional_admin_available_evidence_is_required(module):
    gate = make_gate(module)
    gate.required_optional_admin_checks = {("documents", "desktop"), ("documents", "mobile")}
    result = {"id": "admin-browser-optional-account", "status": "pass", "failures": [], "incomplete_reasons": []}
    evidence_path = Path(gate.out_dir) / "optional.json"
    evidence_path.write_text(
        """
{
  "stores": [ "target" ],
  "results": [
    { "store": "target", "surface": "admin-navigation", "viewport": "desktop", "passed": true },
    { "store": "target", "surface": "documents", "viewport": "desktop", "passed": true, "targetSurfaceAvailable": true, "coverageStatus": "available-route-pass" }
  ]
}
""".strip(),
        encoding="utf-8",
    )

    gate.validate_browser_evidence("admin-browser-optional-account", evidence_path, result)
    gate.validate_optional_admin_coverage(evidence_path, result)

    assert result["status"] == "fail"
    assert any("missing available-route proof" in failure for failure in result["failures"])


def test_reports_fee_surface_is_part_of_optional_admin_scenario(module):
    assert ("reports-fees", "desktop") in module.OPTIONAL_ADMIN_CHECKS
    assert ("reports-fees", "mobile") in module.OPTIONAL_ADMIN_CHECKS
    assert "is_reports_enabled" in module.OPTIONAL_ADMIN_RESTORE_KEYS

    source = (REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py").read_text(encoding="utf-8")
    assert "state.surfaceIds = [ 'documents', 'reports-fees', 'card-readers', 'capital' ];" in source


def test_browser_evidence_paths_stay_under_aggregate_out_dir(module):
    gate = make_gate(module)
    gate.args.skip_admin_browser = False
    gate.args.skip_checkout_browser = False
    checks = gate.build_checks()
    admin_check = next(check for check in checks if check[0] == "admin-browser")
    checkout_check = next(check for check in checks if check[0] == "checkout-browser")

    expected_admin_path = Path(gate.out_dir) / f"{gate.gate_slug}-admin-browser-gate.json"
    expected_checkout_path = Path(gate.out_dir) / f"{gate.gate_slug}-checkout-browser-gate.json"
    assert admin_check[4] == expected_admin_path
    assert checkout_check[4] == expected_checkout_path
    assert admin_check[3]["WOOPAYMENTS_BROWSER_EVIDENCE_PATH"] == str(expected_admin_path)
    assert checkout_check[3]["WOOPAYMENTS_BROWSER_EVIDENCE_PATH"] == str(expected_checkout_path)
    assert admin_check[3]["WOOPAYMENTS_BROWSER_DATA_DIR"].startswith(str(Path(gate.out_dir)))
    assert checkout_check[3]["WOOPAYMENTS_BROWSER_DATA_DIR"].startswith(str(Path(gate.out_dir)))


def test_browser_checks_can_use_playwright_without_persistent_playwriter_state(module):
    gate = make_gate(module)
    gate.browser_runner = "playwright"
    gate.playwriter_session = ""
    gate.args.skip_checkout_browser = False

    checks = gate.build_checks()
    ids = [check[0] for check in checks]
    admin_check = next(check for check in checks if check[0] == "admin-browser")
    checkout_check = next(check for check in checks if check[0] == "checkout-browser")
    admin_env = admin_check[3] or {}

    assert "playwriter-state" not in ids
    assert "playwright-script-runner.mjs" in admin_check[2][0]
    assert "playwright-script-runner.mjs" in checkout_check[2][0]
    assert "PLAYWRIGHT_RUNNER_STATE_JSON" in admin_env
    state = json.loads(admin_env["PLAYWRIGHT_RUNNER_STATE_JSON"])
    assert state["gateSlug"] == gate.gate_slug
    assert state["targetBase"] == "http://store8889.localhost:8889"
    assert state["referenceBase"] == "http://localhost:8082"
    credentials = json.loads(admin_env["WP_ADMIN_CREDENTIALS_JSON"])
    assert credentials["localhost:8082"] == {"user": "admin", "password": "admin"}
    assert credentials["store8889.localhost:8889"] == {"user": "admin", "password": "password"}


def test_playwright_checkout_route_state_is_process_local(module):
    gate = make_gate(module)
    gate.browser_runner = "playwright"
    routes = {key: f"/{key}" for key in module.CHECKOUT_ROUTE_KEYS}
    gate.collect_checkout_fixture_routes = lambda: {"routes": routes, "fixtures": {"target": {}, "reference": {}}}

    result = gate.set_checkout_browser_route_state()

    assert result["exit_code"] == 0
    assert result["command"] == []
    assert result["routes"] == routes
    assert "PLAYWRIGHT_RUNNER_STATE_JSON" in result["stdout_tail"]


def test_playwright_runner_handles_frontend_account_login_and_browser_noise():
    source = (REPO / "tools/woopayments-merge/playwright-script-runner.mjs").read_text(encoding="utf-8")

    assert "WP_ADMIN_CREDENTIALS_JSON" in source
    assert "function maybeLoginToWooAccount" in source
    assert "add-payment-method=1" in source
    assert 'input[name="username"]' in source
    assert "isIgnoredBrowserConsoleMessage" in source
    assert "ReadPixels" in source


def test_accumulated_harness_sources_do_not_embed_machine_specific_paths():
    sources = (
        REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py",
        REPO / "tools/woopayments-merge/a4-admin-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs",
    )

    for source_path in sources:
        source = source_path.read_text(encoding="utf-8")
        assert "/Users/" not in source, source_path
        assert "TMPDIR" in source, source_path


def test_checkout_incomplete_evidence_marks_aggregate_incomplete(module):
    gate = make_gate(module)
    result = {"id": "checkout-browser", "status": "pass", "failures": [], "incomplete_reasons": []}
    evidence_path = Path(gate.out_dir) / "checkout.json"
    evidence_path.write_text(
        """
{
  "status": "incomplete",
  "stores": [ "target", "reference" ],
  "results": [],
  "blockers": [
    { "store": "target", "surface": "blocks-checkout-express", "viewport": "desktop", "blockerReason": "shared local express prerequisite" }
  ],
  "failures": []
}
""".strip(),
        encoding="utf-8",
    )

    gate.validate_browser_evidence("checkout-browser", evidence_path, result)

    assert result["status"] == "incomplete"
    assert any("checkout-browser: browser evidence is incomplete" in reason for reason in result["incomplete_reasons"])


def test_perf_compare_incomplete_exit_is_classified_as_incomplete(module):
    gate = make_gate(module)
    with tempfile.TemporaryDirectory(prefix="a4aq-perf-incomplete-") as temp_dir:
        capture = {
            "schema": "woopayments_measured_gate.v1",
            "mode": "perf",
            "probes": {
                "gateway_initialization": {
                    "status": "incomplete",
                    "reason": "gateway registry was already initialized before the MU probe loaded",
                }
            },
        }
        ref = Path(temp_dir) / "ref.json"
        target = Path(temp_dir) / "target.json"
        ref.write_text(json.dumps(capture), encoding="utf-8")
        target.write_text(json.dumps(capture), encoding="utf-8")

        result = gate.run(
            "perf-compare",
            "perf",
            [
                "bash",
                str(REPO / "tools/woopayments-merge/perf-surface-gate.sh"),
                "compare",
                "--ref",
                str(ref),
                "--target",
                str(target),
            ],
        )

    assert result["exit_code"] == module.EXIT_INCOMPLETE
    assert result["status"] == "incomplete"
    assert result["failures"] == []
    assert gate.failures == []
    assert gate.incomplete == ["perf-compare: exited incomplete"]


def test_perf_capture_infrastructure_exit_is_classified_as_incomplete(module):
    gate = make_gate(module)

    result = gate.run(
        "perf-reference",
        "perf",
        [sys.executable, "-c", "raise SystemExit(2)"],
    )

    assert result["exit_code"] == module.EXIT_USAGE
    assert result["status"] == "incomplete"
    assert result["failures"] == []
    assert gate.failures == []
    assert gate.incomplete == ["perf-reference: exited incomplete"]


def test_checkout_browser_route_state_uses_plain_permalink_overrides(module):
    assert "blocksCheckoutCard" in module.CHECKOUT_ROUTE_KEYS
    assert "referenceBlocksCheckoutCard" in module.CHECKOUT_ROUTE_KEYS
    assert "classicAddPaymentMethod" in module.CHECKOUT_ROUTE_KEYS
    assert "productExpress" in module.CHECKOUT_ROUTE_KEYS

    source = (REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py").read_text(encoding="utf-8")
    fixture_source = (REPO / "tools/woopayments-merge/a4-checkout-fixture-state.php").read_text(encoding="utf-8")
    assert "def set_checkout_browser_route_state" in source
    assert "def collect_checkout_fixture_routes" in source
    assert "state.allowRouteOverrides = true" in source
    assert "add-to-cart" in fixture_source
    assert "h20-ece-probe-product" in fixture_source


def test_a4_checkout_driver_uses_store_api_rest_route_fallback():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "for ( const cartEndpoint of [ '/wp-json/wc/store/v1/cart', '/?rest_route=/wc/store/v1/cart' ] )" in source
    assert "endpoint: cartEndpoint" in source
    assert "isStoreApiPrettyCartFallbackFailure" in source
    assert "failure.status === 404" in source


def test_a4_checkout_driver_allows_current_local_stripe_http_warning_count():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "id: 'stripe-local-http-warning'" in source
    assert "maxCount: 6" in source


def test_a4_checkout_driver_ignores_chromium_webgl_readpixels_warning():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "id: 'chromium-webgl-readpixels-warning'" in source
    assert "ReadPixels" in source


def test_a4_checkout_driver_ignores_shared_local_checkout_console_noise():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "id: 'wc-blocks-useselect-validation'" in source
    assert "/\\/wp-includes\\/js\\/dist\\/data\\.js/" in source
    assert "id: 'target-store-api-pretty-cart-fallback-resource'" in source
    assert "wp-json\\/wc\\/store\\/v1\\/cart" in source
    assert "sourceUrl: /.*/" in source


def test_a4_checkout_driver_ignores_local_placeholder_media_noise():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "function isLocalWooCommercePlaceholderMediaFailure" in source
    assert "woocommerce-placeholder" in source
    assert "id: 'local-woocommerce-placeholder-media-resource'" in source


def test_a4_checkout_driver_classifies_shared_express_failures_as_incomplete():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "function isSharedExpressSurfaceBlocker" in source
    assert "blockers" in source
    assert "status = 'incomplete'" in source
    assert "referenceFailureKeys.has" in source


def test_a4_checkout_driver_surfaces_structured_express_blocker_diagnostics():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwriter.mjs").read_text(encoding="utf-8")

    assert "function collectExpressBlockerDiagnostics" in source
    assert "function describeExpressBlockerDiagnostics" in source
    assert "blockerSummary" in source
    assert "blockerDiagnostics" in source
    assert "referenceBlockerPresent" in source
    assert "expressCheckoutMethods" in source
    assert "missingSelectorGroups" in source
    assert "missingResources" in source
    assert "missingSettings" in source


def test_account_scenario_snapshots_reports_flag():
    source = (REPO / "tools/woopayments-merge/a4-account-scenario.php").read_text(encoding="utf-8")

    assert "_wcpay_feature_reports_area" in source
    assert "is_reports_enabled" in source


def test_log_status_ignores_known_wp67_textdomain_notice(module):
    gate = make_gate(module)
    target = {
        "diagnostics": [
            "[09-Jul-2026 05:26:21 UTC] PHP Notice:  Function _load_textdomain_just_in_time was called incorrectly. Translation loading for the woocommerce domain was triggered too early."
        ],
        "server_errors": [],
        "debug_diagnostics": [],
    }
    reference = {"diagnostics": [], "server_errors": [], "debug_diagnostics": []}

    assert gate.evaluate_log_status(target, reference, []) == "pass"


def test_restore_flags_must_match_apply_before(module):
    gate = make_gate(module)
    apply_payload = {
        "before": {
            "card_present_eligible": True,
            "has_card_readers_available": False,
            "has_previous_capital_loans": False,
            "is_documents_enabled": False,
        }
    }
    restore_payload = {
        "restored_snapshot_exact": True,
        "after": {
            "card_present_eligible": True,
            "has_card_readers_available": True,
            "has_previous_capital_loans": False,
            "is_documents_enabled": False,
        }
    }

    assert_raises(
        lambda: gate.validate_optional_admin_restore("target", apply_payload, restore_payload),
        "restore flags do not match",
    )


def test_optional_admin_snapshot_is_armed_before_apply(module):
    source = MODULE_PATH.read_text(encoding="utf-8")
    scenario = source[
        source.index("    def run_optional_admin_browser_scenario") : source.index(
            "    def run_all"
        )
    ]

    snapshot_call = "self.snapshot_optional_admin_scenario"
    apply_call = "self.apply_optional_admin_scenario"
    assert snapshot_call in scenario
    assert apply_call in scenario
    assert scenario.index(snapshot_call) < scenario.index(apply_call)
    assert "except GateSignal:\n            raise" in scenario


def test_main_installs_and_restores_cleanup_signal_handlers(module, monkeypatch):
    registrations = []
    assert issubclass(module.GateSignal, BaseException)
    assert not issubclass(module.GateSignal, Exception)

    class FakeGate:
        def __init__(self, _args):
            pass

        def preflight(self):
            return None

        def run_all(self):
            return 0

    monkeypatch.setattr(module, "parse_args", lambda: SimpleNamespace())
    monkeypatch.setattr(module, "Gate", FakeGate)
    monkeypatch.setattr(module.signal, "getsignal", lambda signum: f"old-{signum}")
    monkeypatch.setattr(
        module.signal,
        "signal",
        lambda signum, handler: registrations.append((signum, handler)),
    )

    assert module.main() == 0
    assert [item[0] for item in registrations[:3]] == [
        signal.SIGHUP,
        signal.SIGINT,
        signal.SIGTERM,
    ]
    assert all(callable(item[1]) for item in registrations[:3])
    assert [item[1] for item in registrations[3:]] == [
        f"old-{signal.SIGHUP}",
        f"old-{signal.SIGINT}",
        f"old-{signal.SIGTERM}",
    ]


def test_perf_refund_fixture_requires_provider_ids(module):
    gate = make_gate(module)
    order = {
        "order_id": 10,
        "exists": True,
        "gateway_id": "woocommerce_payments",
        "payment_method": "woocommerce_payments",
        "remaining_refund_amount": "10.00",
        "transaction_id": "ch_fake",
        "total": "10.00",
        "refunded_amount": "0",
        "intent_id": "",
        "charge_id": "",
        "status": "processing",
    }

    assert_raises(
        lambda: gate.validate_perf_order_fixture("target", "refund", order),
        "missing intent id",
    )


def test_perf_capture_fixture_requires_charge_id(module):
    gate = make_gate(module)
    order = {
        "order_id": 11,
        "exists": True,
        "gateway_id": "woocommerce_payments",
        "payment_method": "woocommerce_payments",
        "remaining_refund_amount": "10.00",
        "transaction_id": "ch_fake",
        "total": "10.00",
        "refunded_amount": "0",
        "intention_status": "requires_capture",
        "intent_id": "pi_fake",
        "charge_id": "",
    }

    assert_raises(
        lambda: gate.validate_perf_order_fixture("target", "capture", order),
        "missing charge id",
    )


def test_perf_surface_gate_uses_provider_native_gateway_class():
    script = REPO / "tools/woopayments-merge/perf-surface-gate.sh"
    source = script.read_text(encoding="utf-8")

    assert "Internal\\Payments\\Providers\\WooPayments\\NativeWooPaymentsGateway" in source
    assert "Internal\\Payments\\NativeWooPaymentsGateway" not in source


def test_perf_surface_gate_emits_sanitized_money_query_groups():
    script = REPO / "tools/woopayments-merge/perf-surface-gate.sh"
    source = script.read_text(encoding="utf-8")

    assert "top_query_groups" in source
    assert "summarize_query_groups" in source
    assert "summarize_query_caller" in source
    assert "EvalFile_Command::{closure}" in source
    assert "preg_replace( \"/'[^']*'/\", \"'?'\"" in source
    assert "preg_replace( '/\\b\\d+\\b/', '?'" in source


def test_perf_compare_surfaces_query_groups_when_money_query_limit_fails():
    def measured_probe(queries, groups=None):
        return {
            "status": "measured",
            "metrics": {
                "queries": queries,
                "external_requests": 1,
                "median_ms": 1,
                "top_query_groups": groups or [],
            },
        }

    def capture(capture_queries, capture_groups):
        return {
            "schema": "woopayments_measured_gate.v1",
            "mode": "perf",
            "probes": {
                "process_payment": measured_probe(10),
                "refund": measured_probe(8),
                "capture": measured_probe(capture_queries, capture_groups),
                "gateway_registration": {
                    "status": "measured",
                    "metrics": {
                        "gateway_count": 1,
                        "action_callback_count": 1,
                        "external_requests": 0,
                        "median_ms": 1,
                    },
                },
                    "rest_boot": {
                        "status": "measured",
                        "metrics": {
                            "queries": 0,
                            "external_requests": 0,
                            "controller_instantiation_count": 1,
                            "controller_instantiation_status": "measured",
                            "route_registration_status": "measured",
                            "elapsed_ms": 1,
                            "timing_sample_count": 1,
                            "measurement_mode": "single_invocation_rest_api_init",
                        },
                    },
                "autoload_options": {
                    "status": "measured",
                    "metrics": {"autoload_bytes": 100},
                },
                "wcpay_account_data": {
                    "status": "measured",
                    "metrics": {"autoload": "off"},
                },
            },
        }

    with tempfile.TemporaryDirectory(prefix="perf-compare-query-groups-") as temp_dir:
        ref = Path(temp_dir) / "ref.json"
        target = Path(temp_dir) / "target.json"
        ref.write_text(
            json.dumps(
                capture(
                    30,
                    [
                        {
                            "count": 2,
                            "sql": "SELECT * FROM wp_posts WHERE ID = ? LIMIT ?",
                            "top_callers": ["WC_Order_Data_Store_CPT->read"],
                        }
                    ],
                )
            ),
            encoding="utf-8",
        )
        target.write_text(
            json.dumps(
                capture(
                    39,
                    [
                        {
                            "count": 5,
                            "sql": "SELECT * FROM wp_posts WHERE ID = ? LIMIT ?",
                            "top_callers": ["OrderPaymentLifecycleService->apply_unlocked"],
                        }
                    ],
                )
            ),
            encoding="utf-8",
        )

        result = subprocess.run(
            ["python3", str(COMPARE_SCRIPT), "perf", "--ref", str(ref), "--target", str(target)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 1
    assert "note  capture: target top query groups" in result.stdout
    assert "5x SELECT * FROM wp_posts WHERE ID = ? LIMIT ?" in result.stdout
    assert "OrderPaymentLifecycleService->apply_unlocked" in result.stdout


def test_perf_compare_treats_single_invocation_money_timing_as_diagnostic():
    def money_probe(elapsed_ms):
        return {
            "status": "measured",
            "metrics": {
                "queries": 5,
                "external_requests": 1,
                "median_ms": elapsed_ms,
                "measurement_mode": "single_invocation_blocked_http",
            },
        }

    def capture(money_elapsed_ms):
        return {
            "schema": "woopayments_measured_gate.v1",
            "mode": "perf",
            "probes": {
                "process_payment": money_probe(money_elapsed_ms),
                "refund": money_probe(money_elapsed_ms),
                "capture": money_probe(money_elapsed_ms),
                "gateway_registration": {
                    "status": "measured",
                    "metrics": {
                        "gateway_count": 1,
                        "action_callback_count": 1,
                        "external_requests": 0,
                        "median_ms": 1,
                    },
                },
                "rest_boot": {
                    "status": "measured",
                    "metrics": {
                        "queries": 0,
                        "external_requests": 0,
                        "controller_instantiation_count": 1,
                        "controller_instantiation_status": "measured",
                        "route_registration_status": "measured",
                        "elapsed_ms": 1,
                        "timing_sample_count": 1,
                        "measurement_mode": "single_invocation_rest_api_init",
                    },
                },
                "autoload_options": {
                    "status": "measured",
                    "metrics": {"autoload_bytes": 100},
                },
                "wcpay_account_data": {
                    "status": "measured",
                    "metrics": {"autoload": "off"},
                },
            },
        }

    with tempfile.TemporaryDirectory(prefix="perf-compare-single-sample-") as temp_dir:
        ref = Path(temp_dir) / "ref.json"
        target = Path(temp_dir) / "target.json"
        ref.write_text(json.dumps(capture(10)), encoding="utf-8")
        target.write_text(json.dumps(capture(1000)), encoding="utf-8")

        result = subprocess.run(
            ["python3", str(COMPARE_SCRIPT), "perf", "--ref", str(ref), "--target", str(target)],
            cwd=REPO,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )

    assert result.returncode == 0
    assert "note  process_payment: elapsed_ms 10 -> 1000 (single invocation; diagnostic only)" in result.stdout
    assert "note  refund: elapsed_ms 10 -> 1000 (single invocation; diagnostic only)" in result.stdout
    assert "note  capture: elapsed_ms 10 -> 1000 (single invocation; diagnostic only)" in result.stdout


def test_reference_severe_logs_fail(module):
    gate = make_gate(module)
    target = {"diagnostics": [], "server_errors": [], "debug_diagnostics": []}
    reference = {
        "diagnostics": ["PHP Fatal error: boom"],
        "server_errors": [],
        "debug_diagnostics": [],
    }

    assert gate.evaluate_log_status(target, reference, []) == "fail"


def test_wp_eval_file_timeout_is_reported(module, monkeypatch):
    gate = make_gate(module)
    original_run = module.subprocess.run
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", "example-cli-1")

    def fake_run(*args, **kwargs):
        raise subprocess.TimeoutExpired(cmd=args[0], timeout=kwargs.get("timeout"), output="partial stdout", stderr="partial stderr")

    module.subprocess.run = fake_run
    try:
        result = gate.run_wp_eval_file(
            "target-wp",
            "docker exec -i example-cli-1 wp --allow-root --user=1",
            Path(__file__),
            [],
            timeout_seconds=0.001,
        )
    finally:
        module.subprocess.run = original_run

    assert result["exit_code"] == module.EXIT_TIMEOUT
    assert "timed out" in result["stderr_tail"]


def test_account_restore_preserves_autoload_state(module):
    script = REPO / "tools/woopayments-merge/a4-account-scenario.php"
    initial_cache = {
        "data": {
            "account_id": "acct_test",
            "status": "restricted_soon",
            "card_present_eligible": True,
            "has_card_readers_available": False,
            "is_documents_enabled": False,
            "capital": {"has_previous_loans": False},
        },
        "fetched": 123,
        "errored": False,
    }
    php_wrapper = r"""<?php
$store_file = $argv[1];
$script = $argv[2];
$args = array_slice( $argv, 3 );

function a4_test_read_store() {
	$store_file = $GLOBALS['store_file'];
	return json_decode( file_get_contents( $store_file ), true );
}

function a4_test_write_store( $store ) {
	$store_file = $GLOBALS['store_file'];
	file_put_contents( $store_file, json_encode( $store ) );
}

function get_option( $option, $default = false ) {
	$store = a4_test_read_store();
	if ( ! isset( $store[ $option ] ) || empty( $store[ $option ]['exists'] ) ) {
		return $default;
	}
	return $store[ $option ]['value'];
}

function update_option( $option, $value, $autoload = null ) {
	$store = a4_test_read_store();
	if ( ! isset( $store[ $option ] ) ) {
		$store[ $option ] = array( 'exists' => true, 'autoload' => null, 'value' => null );
	}
	$store[ $option ]['exists'] = true;
	$store[ $option ]['value'] = $value;
	if ( null !== $autoload ) {
		$store[ $option ]['autoload'] = $autoload;
	}
	a4_test_write_store( $store );
	return true;
}

function delete_option( $option ) {
	$store = a4_test_read_store();
	unset( $store[ $option ] );
	a4_test_write_store( $store );
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

class A4_Test_DB {
	public $options = 'wp_options';

	public function prepare( $query, $option ) {
		return $option;
	}

	public function get_var( $option ) {
		$store = a4_test_read_store();
		return $store[ $option ]['autoload'] ?? null;
	}

	public function update( $table, $data, $where ) {
		$store = a4_test_read_store();
		$option = $where['option_name'];
		if ( isset( $store[ $option ] ) && array_key_exists( 'autoload', $data ) ) {
			$store[ $option ]['autoload'] = $data['autoload'];
		}
		a4_test_write_store( $store );
		return true;
	}
}

$wpdb = new A4_Test_DB();

class WP_CLI {
	public static function line( $line ) {
		echo $line . PHP_EOL;
	}

	public static function error( $message ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

include $script;
"""

    with tempfile.TemporaryDirectory(prefix="a4ar-account-autoload-") as tmp:
        tmp_path = Path(tmp)
        wrapper_path = tmp_path / "wp-cli-wrapper.php"
        store_path = tmp_path / "store.json"
        wrapper_path.write_text(php_wrapper, encoding="utf-8")
        store_path.write_text(
            json.dumps(
                {
                    "wcpay_account_data": {
                        "exists": True,
                        "autoload": "yes",
                        "value": initial_cache,
                    },
                    "_wcpay_feature_reports_area": {
                        "exists": True,
                        "autoload": "no",
                        "value": "0",
                    },
                }
            ),
            encoding="utf-8",
        )

        def run_scenario(*args: str) -> subprocess.CompletedProcess:
            return subprocess.run(
                ["php", str(wrapper_path), str(store_path), str(script), *args],
                check=True,
                capture_output=True,
                text=True,
            )

        snapshot = json.loads(run_scenario("snapshot").stdout)["snapshot"]
        drifted_store = json.loads(store_path.read_text(encoding="utf-8"))
        drifted_store["wcpay_account_data"]["value"]["data"]["status"] = "changed-after-snapshot"
        store_path.write_text(json.dumps(drifted_store), encoding="utf-8")
        stale_apply = subprocess.run(
            [
                "php",
                str(wrapper_path),
                str(store_path),
                str(script),
                "apply-optional-admin",
                snapshot,
            ],
            check=False,
            capture_output=True,
            text=True,
        )
        assert stale_apply.returncode == 1
        assert "changed after snapshot" in stale_apply.stderr
        unchanged_store = json.loads(store_path.read_text(encoding="utf-8"))
        assert unchanged_store["wcpay_account_data"]["value"]["data"]["status"] == "changed-after-snapshot"
        assert unchanged_store["_wcpay_feature_reports_area"]["value"] == "0"

        run_scenario("restore", snapshot)
        snapshot = json.loads(run_scenario("snapshot").stdout)["snapshot"]
        run_scenario("apply-optional-admin", snapshot)
        applied_store = json.loads(store_path.read_text(encoding="utf-8"))
        assert applied_store["_wcpay_feature_reports_area"]["value"] == "1"
        run_scenario("restore", snapshot)
        restored_store = json.loads(store_path.read_text(encoding="utf-8"))

    assert restored_store["wcpay_account_data"]["autoload"] == "yes"
    assert restored_store["_wcpay_feature_reports_area"]["autoload"] == "no"
    assert restored_store["_wcpay_feature_reports_area"]["value"] == "0"


def main() -> None:
    module = load_module()
    tests = [
        test_optional_admin_unavailable_set_is_strict,
        test_optional_admin_available_evidence_is_required,
        test_reports_fee_surface_is_part_of_optional_admin_scenario,
        test_checkout_browser_evidence_path_matches_driver_data_dir,
        test_browser_checks_can_use_playwright_without_persistent_playwriter_state,
        test_playwright_checkout_route_state_is_process_local,
        test_playwright_runner_handles_frontend_account_login_and_browser_noise,
        test_checkout_incomplete_evidence_marks_aggregate_incomplete,
        test_perf_compare_incomplete_exit_is_classified_as_incomplete,
        test_perf_capture_infrastructure_exit_is_classified_as_incomplete,
        test_checkout_browser_route_state_uses_plain_permalink_overrides,
        test_a4_checkout_driver_uses_store_api_rest_route_fallback,
        test_a4_checkout_driver_allows_current_local_stripe_http_warning_count,
        test_a4_checkout_driver_ignores_chromium_webgl_readpixels_warning,
        test_a4_checkout_driver_ignores_shared_local_checkout_console_noise,
        test_a4_checkout_driver_ignores_local_placeholder_media_noise,
        test_a4_checkout_driver_classifies_shared_express_failures_as_incomplete,
        test_account_scenario_snapshots_reports_flag,
        test_log_status_ignores_known_wp67_textdomain_notice,
        test_restore_flags_must_match_apply_before,
        test_perf_refund_fixture_requires_provider_ids,
        test_perf_capture_fixture_requires_charge_id,
        test_reference_severe_logs_fail,
        test_wp_eval_file_timeout_is_reported,
        test_account_restore_preserves_autoload_state,
    ]
    for test in tests:
        if test.__code__.co_argcount:
            test(module)
        else:
            test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
