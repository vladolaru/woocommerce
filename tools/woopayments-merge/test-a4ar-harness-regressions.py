#!/usr/bin/env python3
"""Focused regression checks for A4ar harness hardening."""

from __future__ import annotations

import importlib.util
import json
import os
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
        browser_runner="playwright",
        playwriter_session="",
        out_dir=tempfile.mkdtemp(prefix="a4ar-harness-test-"),
        skip_admin_browser=False,
        skip_optional_admin_scenario=False,
        skip_checkout_browser=True,
        skip_perf=True,
        skip_perf_fixtures=True,
        skip_bundle=True,
    )
    return module.Gate(args)


def test_parser_defaults_to_direct_playwright(module, monkeypatch):
    monkeypatch.delenv("BROWSER_RUNNER", raising=False)
    monkeypatch.setattr(
        sys,
        "argv",
        [
            str(MODULE_PATH),
            "--repo",
            str(REPO),
            "--plugin-repo",
            str(REPO.parent / "woocommerce-payments"),
            "--ref-wp",
            "ref wp",
            "--target-wp",
            "target wp",
        ],
    )

    assert module.parse_args().browser_runner == "playwright"


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


def test_browser_checks_use_default_playwright_without_persistent_state(module):
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
        REPO / "tools/woopayments-merge/a4-admin-browser-gate.playwright.mjs",
        REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs",
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


def test_check_timeout_expiry_is_recorded_incomplete_not_pass_or_hang(module):
    gate = make_gate(module)
    os.environ["A4AQ_TIMEOUT_OVERRIDE_SECONDS"] = "0.5"
    try:
        result = gate.run(
            "checkout-browser",
            "checkout",
            [sys.executable, "-c", "import time; time.sleep(30)"],
        )
    finally:
        os.environ.pop("A4AQ_TIMEOUT_OVERRIDE_SECONDS", None)

    assert result["exit_code"] == module.EXIT_TIMEOUT
    assert result["status"] == "incomplete"
    assert result["failures"] == []
    assert gate.failures == []
    assert any("timed out after 0.5s" in reason for reason in result["incomplete_reasons"])
    assert gate.incomplete == ["checkout-browser: timed out after 0.5s"]


def test_check_categories_have_bounded_timeouts(module):
    assert module.BROWSER_CHECK_TIMEOUT_SECONDS == 1800
    assert module.PERF_BUNDLE_CHECK_TIMEOUT_SECONDS == 900
    assert module.LOG_SCAN_TIMEOUT_SECONDS == 300
    for category in ("admin", "browser", "checkout"):
        assert module.CHECK_CATEGORY_TIMEOUT_SECONDS[category] == module.BROWSER_CHECK_TIMEOUT_SECONDS
    for category in ("perf", "bundle"):
        assert module.CHECK_CATEGORY_TIMEOUT_SECONDS[category] == module.PERF_BUNDLE_CHECK_TIMEOUT_SECONDS
    assert module.CHECK_CATEGORY_TIMEOUT_SECONDS["logs"] == module.LOG_SCAN_TIMEOUT_SECONDS
    # Unknown categories fall back to the most conservative bound; nothing runs untimed.
    assert module.check_timeout_seconds("unknown-category") == module.BROWSER_CHECK_TIMEOUT_SECONDS


def test_hard_timeout_kills_the_whole_process_group(module):
    # subprocess.run's timeout kills only the direct child; a wedged docker exec or
    # node->chromium tree could orphan past a hard timeout. The group-kill helper must
    # take the grandchildren down with the child.
    import signal as signal_module
    import subprocess as subprocess_module
    import time

    marker = f"a4aq-group-kill-test-{os.getpid()}"
    command = ["bash", "-c", f"sleep 300 & sleep 300 # {marker}"]

    start = time.monotonic()
    try:
        module.run_subprocess_group(
            command,
            timeout=0.5,
            text=True,
            stdout=subprocess_module.PIPE,
            stderr=subprocess_module.PIPE,
        )
        raise AssertionError("expected TimeoutExpired")
    except subprocess_module.TimeoutExpired:
        pass
    elapsed = time.monotonic() - start
    assert elapsed < 15, f"group kill took {elapsed:.1f}s (SIGTERM drain must not stall)"

    # Neither the bash child nor its backgrounded sleep grandchild may survive.
    survivors = subprocess_module.run(
        ["pgrep", "-f", marker],
        text=True,
        stdout=subprocess_module.PIPE,
        stderr=subprocess_module.PIPE,
        check=False,
    )
    assert survivors.returncode != 0, f"orphaned processes survived: {survivors.stdout}"
    del signal_module


def test_no_bare_timeout_subprocess_run_remains(module):
    # Every timeout-carrying subprocess in the gate must go through the group-kill
    # helper; a bare subprocess.run(timeout=...) reintroduces the orphaning channel.
    source = (REPO / "tools/woopayments-merge/a4aq-accumulated-gate.py").read_text(encoding="utf-8")
    for match_start in _iter_call_sites(source, "subprocess.run("):
        call = source[match_start : match_start + 600]
        assert "timeout=" not in call.split(")\n")[0], (
            "bare subprocess.run with timeout= found; use run_subprocess_group: "
            + call[:160]
        )
    assert source.count("run_subprocess_group(") >= 8  # definition + 7 call sites


def _iter_call_sites(source: str, needle: str):
    index = source.find(needle)
    while index != -1:
        yield index
        index = source.find(needle, index + 1)


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
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "for ( const cartEndpoint of [ '/wp-json/wc/store/v1/cart', '/?rest_route=/wc/store/v1/cart' ] )" in source
    assert "endpoint: cartEndpoint" in source
    assert "isStoreApiPrettyCartFallbackFailure" in source
    assert "failure.status === 404" in source


def test_a4_checkout_driver_allows_current_local_stripe_http_warning_count():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "id: 'stripe-local-http-warning'" in source
    assert "maxCount: 6" in source


def test_a4_checkout_driver_ignores_chromium_webgl_readpixels_warning():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "id: 'chromium-webgl-readpixels-warning'" in source
    assert "ReadPixels" in source


def test_a4_checkout_driver_ignores_shared_local_checkout_console_noise():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "id: 'wc-blocks-useselect-validation'" in source
    assert "/\\/wp-includes\\/js\\/dist\\/data\\.js/" in source
    assert "id: 'target-store-api-pretty-cart-fallback-resource'" in source
    assert "wp-json\\/wc\\/store\\/v1\\/cart" in source
    assert "sourceUrl: /.*/" in source


def test_a4_checkout_driver_ignores_local_placeholder_media_noise():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "function isLocalWooCommercePlaceholderMediaFailure" in source
    assert "woocommerce-placeholder" in source
    assert "id: 'local-woocommerce-placeholder-media-resource'" in source


def test_a4_checkout_driver_classifies_shared_express_failures_as_incomplete():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "function isSharedExpressSurfaceBlocker" in source
    assert "blockers" in source
    assert "status = 'incomplete'" in source
    assert "referenceFailureKeys.has" in source


def test_a4_checkout_driver_only_demotes_target_failures_without_own_defects():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    assert "function hasTargetOwnDefects" in source
    assert "( result.pageErrors || [] ).length > 0" in source
    assert "( result.consoleIssues || [] ).length > 0" in source
    assert "( result.failedResponses || [] ).length > 0" in source
    assert "referenceFailureKeys.has( resultKey( result ) ) && ! hasTargetOwnDefects( result )" in source
    # The demotion diagnostics stay intact.
    assert "function summarizeBlockedFailure" in source
    assert "blockerDiagnostics" in source


def test_a4_checkout_driver_blockers_only_run_exits_incomplete_not_zero():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

    incomplete_block = source[source.index("if ( finalEvidence.blockers.length > 0 ) {") :]
    assert "process.exitCode = 3;" in incomplete_block
    # The evidence file is written before the exit code is set so
    # a4aq-accumulated-gate.py evidence recovery still sees status incomplete.
    assert source.index("const finalEvidence = writeEvidence( status );") < source.index("process.exitCode = 3;")

    runner = (REPO / "tools/woopayments-merge/playwright-script-runner.mjs").read_text(encoding="utf-8")
    # The runner passes the real `process` into the script sandbox and does not
    # force process.exit(0) on success, so exitCode 3 propagates.
    assert "return fn(\n\t\tscriptRequire,\n\t\tprocess," in runner
    assert "process.exit( 0 )" not in runner


def test_a4_checkout_driver_surfaces_structured_express_blocker_diagnostics():
    source = (REPO / "tools/woopayments-merge/a4-checkout-browser-gate.playwright.mjs").read_text(encoding="utf-8")

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
    assert "except GateSignal as exc:" in scenario
    assert "signal_error = exc" in scenario
    assert "if signal_error is not None:\n                raise signal_error" in scenario


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


def test_main_maps_signal_cleanup_failure_to_safety_exit(module, monkeypatch):
    def raise_signal_with_cleanup_failure():
        error = module.GateSignal(signal.SIGTERM)
        error.cleanup_errors.append("failed to restore target account cache")
        raise error

    monkeypatch.setattr(module, "run_gate_main", raise_signal_with_cleanup_failure)
    monkeypatch.setattr(module.signal, "getsignal", lambda signum: f"old-{signum}")
    monkeypatch.setattr(module.signal, "signal", lambda signum, handler: None)

    assert module.main() == 70


def test_recorded_cleanup_failure_takes_precedence_over_gate_failure(module):
    gate = make_gate(module)
    gate.build_checks = lambda: []
    gate.scan_logs = lambda: "pass"
    gate.failures.append("ordinary gate failure")
    gate.cleanup_failures = ["failed to restore target account cache"]

    assert gate.run_all() == 70


def test_cleanup_failure_stops_before_later_internal_checks(module):
    gate = make_gate(module)
    gate.args.skip_perf_fixtures = False
    started = []
    gate.build_checks = lambda: [
        ("admin-browser", "admin", ["admin"], None, None),
        ("checkout-browser", "checkout", ["checkout"], None, None),
        ("perf-reference", "perf", ["perf"], None, None),
    ]

    def run_check(check_id, category, command, env):
        started.append(check_id)
        return {
            "id": check_id,
            "status": "pass",
            "exit_code": 0,
            "failures": [],
            "incomplete_reasons": [],
        }

    def fail_cleanup(index, total):
        reason = "admin-browser-optional-account: failed to restore target account cache"
        gate.failures.append(reason)
        gate.cleanup_failures.append(reason)

    gate.run = run_check
    gate.run_optional_admin_browser_scenario = fail_cleanup
    gate.set_checkout_browser_route_state = lambda: {"exit_code": 0, "routes": {}}
    gate.create_perf_fixtures = lambda index, total: started.append("perf-fixtures")
    gate.scan_logs = lambda: started.append("log-scan") or "pass"

    assert gate.run_all() == 70
    assert started == ["admin-browser"]


def test_optional_admin_signal_carries_restore_failure(module, monkeypatch):
    gate = make_gate(module)
    gate.browser_runner = "playwright"
    gate.snapshot_optional_admin_scenario = lambda label, wp: {
        "payload": {"snapshot": "exact-snapshot"}
    }
    gate.apply_optional_admin_scenario = lambda label, wp, snapshot: {
        "payload": {"before": {}}
    }
    gate.restore_optional_admin_scenario = lambda label, wp, snapshot: {
        "exit_code": 1,
        "payload": None,
        "stdout_tail": "",
        "stderr_tail": "forced restore failure",
    }
    gate.reset_browser_selection_state = lambda: {
        "exit_code": 0,
        "stdout_tail": "",
        "stderr_tail": "",
    }
    gate.browser_command = lambda script, timeout: ["fake-browser"]
    gate.browser_state_env = lambda state, **kwargs: {}
    # The browser subprocess now runs through the group-kill helper; patch that seam.
    monkeypatch.setattr(
        module,
        "run_subprocess_group",
        lambda *args, **kwargs: (_ for _ in ()).throw(module.GateSignal(signal.SIGTERM)),
    )

    with pytest.raises(module.GateSignal) as exc_info:
        gate.run_optional_admin_browser_scenario(1, 1)

    assert exc_info.value.cleanup_errors == [
        "admin-browser-optional-account: failed to restore target account cache"
    ]
    assert gate.cleanup_failures == exc_info.value.cleanup_errors


def test_signal_during_optional_admin_restore_still_resets_state(module, monkeypatch):
    gate = make_gate(module)
    gate.browser_runner = "playwright"
    reset_calls = []
    gate.snapshot_optional_admin_scenario = lambda label, wp: {
        "payload": {"snapshot": "exact-snapshot"}
    }
    gate.apply_optional_admin_scenario = lambda label, wp, snapshot: {
        "payload": {"before": {}}
    }
    gate.restore_optional_admin_scenario = lambda label, wp, snapshot: (
        (_ for _ in ()).throw(module.GateSignal(signal.SIGTERM))
    )
    gate.reset_browser_selection_state = lambda: reset_calls.append(True) or {
        "exit_code": 0,
        "stdout_tail": "",
        "stderr_tail": "",
    }
    gate.browser_command = lambda script, timeout: ["fake-browser"]
    gate.browser_state_env = lambda state, **kwargs: {}
    monkeypatch.setattr(
        module.subprocess,
        "run",
        lambda *args, **kwargs: subprocess.CompletedProcess(args[0], 1, "", "browser failed"),
    )

    with pytest.raises(module.GateSignal) as exc_info:
        gate.run_optional_admin_browser_scenario(1, 1)

    assert reset_calls == [True]
    assert exc_info.value.cleanup_errors == [
        "admin-browser-optional-account: interrupted while restoring target account cache"
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
        test_browser_checks_use_default_playwright_without_persistent_state,
        test_playwright_checkout_route_state_is_process_local,
        test_playwright_runner_handles_frontend_account_login_and_browser_noise,
        test_checkout_incomplete_evidence_marks_aggregate_incomplete,
        test_perf_compare_incomplete_exit_is_classified_as_incomplete,
        test_perf_capture_infrastructure_exit_is_classified_as_incomplete,
        test_check_timeout_expiry_is_recorded_incomplete_not_pass_or_hang,
        test_check_categories_have_bounded_timeouts,
        test_checkout_browser_route_state_uses_plain_permalink_overrides,
        test_a4_checkout_driver_uses_store_api_rest_route_fallback,
        test_a4_checkout_driver_allows_current_local_stripe_http_warning_count,
        test_a4_checkout_driver_ignores_chromium_webgl_readpixels_warning,
        test_a4_checkout_driver_ignores_shared_local_checkout_console_noise,
        test_a4_checkout_driver_ignores_local_placeholder_media_noise,
        test_a4_checkout_driver_classifies_shared_express_failures_as_incomplete,
        test_a4_checkout_driver_only_demotes_target_failures_without_own_defects,
        test_a4_checkout_driver_blockers_only_run_exits_incomplete_not_zero,
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
