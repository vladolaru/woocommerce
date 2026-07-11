#!/usr/bin/env python3
"""Focused regression checks for the SC-04 saved-card Playwright gate."""

from __future__ import annotations

import importlib.util
import json
import os
import signal
import subprocess
import tempfile
from pathlib import Path

from tools.woopayments_test_runner import adapt_wp_runner_arguments


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "tools/woopayments-merge/sc04-saved-card-gate.py"
DRIVER = REPO / "tools/woopayments-merge/sc04-saved-card.playwriter.mjs"
CONTEXT_MODULE_PATH = REPO / "tools/woopayments-critical-flows/evidence_context.py"
REF_WP = "docker exec -i wcpay_wp_default wp"
TARGET_WP = "docker exec -i unit-target-cli-1 wp"


def load_context_module():
    spec = importlib.util.spec_from_file_location("critical_flow_evidence_context", CONTEXT_MODULE_PATH)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


CONTEXT_MODULE = load_context_module()


def load_gate_module():
    spec = importlib.util.spec_from_file_location("sc04_saved_card_gate", SCRIPT)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


GATE_MODULE = load_gate_module()


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def probe(role: str) -> dict:
    return {
        "ready": True,
        "blog_id": 2 if role == "ref" else 4,
        "home_url": "http://localhost" if role == "ref" else "http://store8889.localhost:8889",
        "jetpack_blog_id_sha256": "sha256:" + ("1" if role == "ref" else "2") * 64,
        "runtime_owner": "plugin" if role == "ref" else "native",
        "account": {
            "account_id_sha256": "sha256:" + ("3" if role == "ref" else "4") * 64,
            "country": "US",
            "test_mode": True,
            "business_type": "company",
            "capabilities": {"card_payments": "active"},
        },
        "failures": [],
    }


def write_context(path: Path) -> dict:
    context = CONTEXT_MODULE.build_context(
        aggregate_run_id="sc04-test-run",
        source=CONTEXT_MODULE.source_snapshot(REPO),
        stores={
            "ref": CONTEXT_MODULE.normalize_store_probe(probe("ref"), "ref", "plugin"),
            "target": CONTEXT_MODULE.normalize_store_probe(probe("target"), "target", "native"),
        },
        fixtures={
            "ref": {"subscription_id": "1283"},
            "target": {"subscription_id": "874"},
        },
    )
    path.write_text(json.dumps(context, sort_keys=True) + "\n", encoding="utf-8")
    return context


def make_fake_docker(path: Path) -> None:
    write_executable(
        path,
        """#!/usr/bin/env python3
import base64
import hashlib
import json
import os
import sys
from pathlib import Path

args = sys.argv[1:]
if args[:2] == ["context", "show"]:
    print("default")
    raise SystemExit(0)
if args[:2] == ["context", "inspect"]:
    print(json.dumps([{"Endpoints": {"docker": {"Host": "unix:///woopayments-test/docker.sock"}}}]))
    raise SystemExit(0)
container = args[2] if len(args) > 2 else ""
role = "ref" if container == "wcpay_wp_default" else "target"
joined = " ".join(args)
code = sys.stdin.read() if "eval-file" in args else joined
Path(os.environ["SC04_WP_LOG"]).open("a", encoding="utf-8").write(
    role + "|" + joined + "\\n" + code + "\\n"
)

sc04_probe = any(
    marker in code
    for marker in (
        "SC04_FIXTURE_PROBE",
        "SC04_AUTH_SESSION_CREATE",
        "SC04_STATE_PROBE",
        "SC04_SESSION_DESTROY",
        "SC04_TOKEN_CLEANUP",
    )
)
if sc04_probe and ("eval-file" not in args or "-" not in args):
    print("SC-04 probes must use stdin-backed wp eval-file -", file=sys.stderr)
    raise SystemExit(1)
if sc04_probe and not code.lstrip().startswith("<?php"):
    print(code)
    raise SystemExit(0)

if "SC04_FIXTURE_PROBE" in code:
    if os.environ.get("SC04_FAKE_FIXTURE_FAILURE") == "1":
        print("wordpress_logged_in_test=cookie-secret-must-not-leak")
        raise SystemExit(1)
    payload = {
        "success": True,
        "store": role,
        "subscription_id": 1283 if role == "ref" else 874,
        "customer_id": 17 if role == "ref" else 3,
        "normal_token_id": 37 if role == "ref" else 16,
        "normal_payment_method_id": "pm_ref_37" if role == "ref" else "pm_target_16",
        "product_id": 688 if role == "ref" else 113,
        "classic_url": "http://localhost/codex-reference-classic-checkout/" if role == "ref" else "http://store8889.localhost:8889/codex-classic-checkout/",
        "blocks_url": "http://localhost/checkout/" if role == "ref" else "http://store8889.localhost:8889/checkout/",
        "auth_cookie": {"name": "wordpress_logged_in_test", "value": "cookie-" + role},
        "session_token": "session-" + role,
        "baseline_token_ids": [37 if role == "ref" else 16],
        "existing_sca_token_id": 0,
        "errors": [],
    }
    print(json.dumps(payload))
    raise SystemExit(0)

if "SC04_AUTH_SESSION_CREATE" in code:
    if os.environ.get("SC04_FAKE_AUTH_CREATE_MALFORMED") == "1":
        print("session was created but the response was malformed")
        raise SystemExit(0)
    print(json.dumps({
        "success": True,
        "auth_cookie": {
            "name": "wordpress_logged_in_" + hashlib.md5(
                ("http://localhost" if role == "ref" else "http://store8889.localhost:8889").encode()
            ).hexdigest(),
            "value": "cookie-" + role,
        },
        "errors": [],
    }))
    raise SystemExit(0)

if "SC04_STATE_PROBE" in code:
    encoded = args[-1]
    browser = json.loads(base64.b64decode(encoded).decode("utf-8"))
    normal_token = int(browser["token_id"])
    sca_token = int(browser["sca_token_id"])
    orders = {}
    for surface, evidence in browser["surfaces"].items():
        token_id = sca_token if surface.startswith("sca_") else normal_token
        orders[surface] = {
            "success": True,
            "order_id": evidence["order_id"],
            "customer_id": browser["customer_id"],
            "status": "processing",
            "payment_method": "woocommerce_payments",
            "payment_method_id": f"pm_{role}_{token_id}",
            "amount": evidence["expected_amount"],
            "currency": evidence["expected_currency"],
            "meta_presence": {"_intent_id": True, "_charge_id": True},
            "errors": [],
        }
    if os.environ.get("SC04_FAKE_NESTED_ORDER_FAILURE") == "1":
        orders["classic"]["success"] = False
        orders["classic"]["errors"] = ["order is unavailable"]
    print(json.dumps({
        "success": True,
        "customer_id": browser["customer_id"],
        "token": {
            "id": normal_token,
            "user_id": browser["customer_id"],
            "gateway_id": "woocommerce_payments",
            "payment_method_id": f"pm_{role}_{normal_token}",
        },
        "sca_token": {
            "id": sca_token,
            "user_id": browser["customer_id"],
            "gateway_id": "woocommerce_payments",
            "payment_method_id": f"pm_{role}_{sca_token}",
        },
        "orders": orders,
        "errors": [],
    }))
    raise SystemExit(0)

if "SC04_SESSION_DESTROY" in code:
    print(json.dumps({"success": os.environ.get("SC04_FAKE_SESSION_DESTROY_FAILURE") != "1"}))
    raise SystemExit(0)

if "SC04_TOKEN_CLEANUP" in code:
    print(json.dumps({
        "success": os.environ.get("SC04_FAKE_TOKEN_CLEANUP_FAILURE") != "1",
        "deleted_token_ids": [],
    }))
    raise SystemExit(0)

if "jetpack_blog_id_sha256" in code:
    probe = {
        "ready": True,
        "blog_id": 2 if role == "ref" else 4,
        "home_url": "http://localhost" if role == "ref" else "http://store8889.localhost:8889",
        "jetpack_blog_id_sha256": "sha256:" + ("1" if role == "ref" else "2") * 64,
        "runtime_owner": "plugin" if role == "ref" else "native",
        "account": {
            "account_id_sha256": "sha256:" + ("3" if role == "ref" else "4") * 64,
            "country": "US",
            "test_mode": True,
            "business_type": "company",
            "capabilities": {"card_payments": "active"},
        },
        "failures": [],
    }
    print(json.dumps(probe))
    raise SystemExit(0)

print("unexpected fake docker invocation: " + joined, file=sys.stderr)
raise SystemExit(1)
""",
    )


def make_fake_browser_runner(path: Path) -> None:
    write_executable(
        path,
        """#!/usr/bin/env python3
import json
import hashlib
import os
import sys
from pathlib import Path

config = json.loads(os.environ["PLAYWRIGHT_RUNNER_STATE_JSON"])["sc04SavedCardConfig"]
evidence_path = Path(config["evidencePath"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
role = config["store"]
expected_cookie_name = "wordpress_logged_in_" + hashlib.md5(config["baseUrl"].encode()).hexdigest()
if config["authCookie"]["name"] != expected_cookie_name:
    print("browser auth cookie name did not match the external store URL", file=sys.stderr)
    raise SystemExit(1)
normal_token = int(config["normalTokenId"])
sca_token = int(config.get("existingScaTokenId") or 0) or normal_token + 1
base_order = 1300 if role == "ref" else 900
surfaces = {}
for offset, surface in enumerate(("classic", "blocks", "sca_classic", "sca_blocks"), start=1):
    screenshot = evidence_path.parent / f"{role}-{surface}.png"
    screenshot.write_bytes(b"screenshot")
    requires_sca = surface.startswith("sca_")
    surfaces[surface] = {
        "success": True,
        "order_id": base_order + offset,
        "selected_token_id": sca_token if requires_sca else normal_token,
        "final_url": f"{config['baseUrl']}/checkout/order-received/{base_order + offset}/?key=test",
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
payload = {
    "schema": "woopayments_sc04_browser_evidence.v1",
    "context_binding": config["contextBinding"],
    "store": role,
    "home_url": config["baseUrl"],
    "customer_id": config["customerId"],
    "token_id": normal_token,
    "sca_token_id": sca_token,
    "surfaces": surfaces,
    "fatal_console_errors": [],
    "fatal_response_errors": [],
    "errors": [],
}
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
Path(os.environ["SC04_BROWSER_LOG"]).open("a", encoding="utf-8").write(role + "|" + " ".join(sys.argv[1:]) + "\\n")
""",
    )


def run_gate(*args: str, env: dict[str, str]) -> subprocess.CompletedProcess[str]:
    command_args, process_env = adapt_wp_runner_arguments(
        list(args),
        env,
        ref_flag="--ref-wp",
        target_flag="--target-wp",
    )
    return subprocess.run(
        ["python3", str(SCRIPT), *command_args],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
        check=False,
    )


def test_print_plan_describes_context_bound_dual_store_playwright_gate() -> None:
    result = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--repo",
            str(REPO),
            "--context-file",
            "context.json",
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            "evidence",
            "--print-plan",
        ],
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)
    assert payload["schema"] == "woopayments_sc04_saved_card_gate_plan.v1"
    assert payload["browser_runner"] == "playwright"
    assert payload["surfaces"] == ["classic", "blocks", "sca_classic", "sca_blocks"]
    assert payload["context_file"] == "context.json"


def test_fixture_probe_is_read_only_and_session_creation_uses_a_caller_known_token() -> None:
    assert "wp_generate_auth_cookie" not in GATE_MODULE.FIXTURE_PROBE_PHP
    assert "SC04_AUTH_SESSION_CREATE" in GATE_MODULE.AUTH_SESSION_CREATE_PHP
    assert "wp_generate_auth_cookie" in GATE_MODULE.AUTH_SESSION_CREATE_PHP
    assert "WP_Session_Tokens::get_instance" in GATE_MODULE.AUTH_SESSION_CREATE_PHP


def test_driver_sanitizes_successful_order_received_urls() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    assert "result.final_url = safeUrl( order.finalUrl );" in source


def test_external_store_url_controls_the_standard_logged_in_cookie_hash() -> None:
    assert GATE_MODULE.external_logged_in_cookie_name(
        "wordpress_logged_in_86a9106ae65537651a8e456835b316ab",
        "http://localhost:8082",
    ) == "wordpress_logged_in_042655d3118d29bc7d0a3b641e5b22c2"


def test_driver_resets_the_woocommerce_session_between_surfaces() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    assert "async function resetShopperSession()" in source
    checkout_surface = source[source.index("async function runCheckoutSurface") : source.index("const paymentFrameSelectors")]
    assert "await resetShopperSession();" in checkout_surface


def test_driver_ignores_only_the_stripe_test_acs_pixel_csp_diagnostic() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    assert "isBenignStripeTestAcsCspError" in source
    assert "testmode-acs.stripe.com" in source
    assert "data:image/png;base64,iVBORw0KGgo=" in source


def test_driver_applies_the_critical_resource_policy_to_console_load_errors() -> None:
    source = DRIVER.read_text(encoding="utf-8")
    assert "isNonCriticalResourceConsoleError" in source
    assert "Failed to load resource" in source
    assert "! isCriticalResourceUrl" in source


def test_gate_runs_both_stores_and_destroys_short_lived_sessions() -> None:
    with tempfile.TemporaryDirectory(prefix="sc04-saved-card-gate-") as tmp:
        tmp_path = Path(tmp)
        fake_bin = tmp_path / "bin"
        fake_bin.mkdir()
        context_path = tmp_path / "context.json"
        context = write_context(context_path)
        make_fake_docker(fake_bin / "docker")
        fake_browser = tmp_path / "fake-browser-runner"
        make_fake_browser_runner(fake_browser)
        wp_log = tmp_path / "wp.log"
        browser_log = tmp_path / "browser.log"
        out_dir = tmp_path / "evidence"

        result = run_gate(
            "--repo",
            str(REPO),
            "--context-file",
            str(context_path),
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PATH": str(fake_bin) + os.pathsep + os.environ.get("PATH", ""),
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_browser),
                "SC04_WP_LOG": str(wp_log),
                "SC04_BROWSER_LOG": str(browser_log),
            },
        )

        assert result.returncode == 0, result.stdout + result.stderr
        rollup = json.loads((out_dir / "sc04-saved-card-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"
        assert rollup["context_binding"] == {
            "aggregate_run_id": context["aggregate_run_id"],
            "context_sha256": context["context_sha256"],
        }
        assert (out_dir / "reference-browser.json").is_file()
        assert (out_dir / "reference-state.json").is_file()
        assert (out_dir / "target-browser.json").is_file()
        assert (out_dir / "target-state.json").is_file()
        assert browser_log.read_text(encoding="utf-8").count("sc04-saved-card.playwriter.mjs") == 2
        wp_invocations = wp_log.read_text(encoding="utf-8")
        assert wp_invocations.count(" eval-file - ") == 10
        assert wp_invocations.count("SC04_FIXTURE_PROBE") == 2
        assert wp_invocations.count("SC04_AUTH_SESSION_CREATE") == 2
        assert wp_invocations.count("SC04_STATE_PROBE") == 2
        assert wp_invocations.count("SC04_TOKEN_CLEANUP") == 2
        assert wp_invocations.count("SC04_SESSION_DESTROY") == 2
        cursor = 0
        for _role in ("ref", "target"):
            state_index = wp_invocations.index("SC04_STATE_PROBE", cursor)
            cleanup_index = wp_invocations.index("SC04_TOKEN_CLEANUP", state_index)
            assert state_index < cleanup_index
            cursor = cleanup_index + len("SC04_TOKEN_CLEANUP")


def test_wp_probe_failure_does_not_leak_auth_cookie_output() -> None:
    with tempfile.TemporaryDirectory(prefix="sc04-saved-card-gate-") as tmp:
        tmp_path = Path(tmp)
        fake_bin = tmp_path / "bin"
        fake_bin.mkdir()
        context_path = tmp_path / "context.json"
        write_context(context_path)
        make_fake_docker(fake_bin / "docker")
        fake_browser = tmp_path / "fake-browser-runner"
        make_fake_browser_runner(fake_browser)

        result = run_gate(
            "--repo",
            str(REPO),
            "--context-file",
            str(context_path),
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            str(tmp_path / "evidence"),
            env={
                **os.environ,
                "PATH": str(fake_bin) + os.pathsep + os.environ.get("PATH", ""),
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_browser),
                "SC04_WP_LOG": str(tmp_path / "wp.log"),
                "SC04_BROWSER_LOG": str(tmp_path / "browser.log"),
                "SC04_FAKE_FIXTURE_FAILURE": "1",
            },
        )

        combined_output = result.stdout + result.stderr
        assert result.returncode == 3
        assert "cookie-secret-must-not-leak" not in combined_output
        assert "wordpress_logged_in_test" not in combined_output
        rollup = json.loads((tmp_path / "evidence" / "sc04-saved-card-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"


def test_auth_session_parse_failure_still_destroys_the_caller_known_session() -> None:
    with tempfile.TemporaryDirectory(prefix="sc04-saved-card-gate-") as tmp:
        tmp_path = Path(tmp)
        fake_bin = tmp_path / "bin"
        fake_bin.mkdir()
        context_path = tmp_path / "context.json"
        write_context(context_path)
        make_fake_docker(fake_bin / "docker")
        fake_browser = tmp_path / "fake-browser-runner"
        make_fake_browser_runner(fake_browser)
        wp_log = tmp_path / "wp.log"
        out_dir = tmp_path / "evidence"

        result = run_gate(
            "--repo",
            str(REPO),
            "--context-file",
            str(context_path),
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PATH": str(fake_bin) + os.pathsep + os.environ.get("PATH", ""),
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_browser),
                "SC04_WP_LOG": str(wp_log),
                "SC04_BROWSER_LOG": str(tmp_path / "browser.log"),
                "SC04_FAKE_AUTH_CREATE_MALFORMED": "1",
            },
        )

        assert result.returncode == 3
        invocations = wp_log.read_text(encoding="utf-8")
        assert invocations.count("SC04_AUTH_SESSION_CREATE") == 2
        assert invocations.count("SC04_SESSION_DESTROY") == 2
        rollup = json.loads((out_dir / "sc04-saved-card-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"


def test_nested_order_failure_makes_the_gate_fail() -> None:
    with tempfile.TemporaryDirectory(prefix="sc04-saved-card-gate-") as tmp:
        tmp_path = Path(tmp)
        fake_bin = tmp_path / "bin"
        fake_bin.mkdir()
        context_path = tmp_path / "context.json"
        write_context(context_path)
        make_fake_docker(fake_bin / "docker")
        fake_browser = tmp_path / "fake-browser-runner"
        make_fake_browser_runner(fake_browser)
        out_dir = tmp_path / "evidence"

        result = run_gate(
            "--repo",
            str(REPO),
            "--context-file",
            str(context_path),
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PATH": str(fake_bin) + os.pathsep + os.environ.get("PATH", ""),
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_browser),
                "SC04_WP_LOG": str(tmp_path / "wp.log"),
                "SC04_BROWSER_LOG": str(tmp_path / "browser.log"),
                "SC04_FAKE_NESTED_ORDER_FAILURE": "1",
            },
        )

        assert result.returncode == 1
        rollup = json.loads((out_dir / "sc04-saved-card-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_session_cleanup_failure_makes_gate_non_passing() -> None:
    with tempfile.TemporaryDirectory(prefix="sc04-saved-card-gate-") as tmp:
        tmp_path = Path(tmp)
        fake_bin = tmp_path / "bin"
        fake_bin.mkdir()
        context_path = tmp_path / "context.json"
        write_context(context_path)
        make_fake_docker(fake_bin / "docker")
        fake_browser = tmp_path / "fake-browser-runner"
        make_fake_browser_runner(fake_browser)
        out_dir = tmp_path / "evidence"

        result = run_gate(
            "--repo",
            str(REPO),
            "--context-file",
            str(context_path),
            "--ref-wp",
            REF_WP,
            "--target-wp",
            TARGET_WP,
            "--ref-url",
            "http://localhost:8082",
            "--target-url",
            "http://store8889.localhost:8889",
            "--ref-subscription-id",
            "1283",
            "--target-subscription-id",
            "874",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PATH": str(fake_bin) + os.pathsep + os.environ.get("PATH", ""),
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_browser),
                "SC04_WP_LOG": str(tmp_path / "wp.log"),
                "SC04_BROWSER_LOG": str(tmp_path / "browser.log"),
                "SC04_FAKE_SESSION_DESTROY_FAILURE": "1",
            },
        )

        assert result.returncode == 3
        assert "could not destroy" in result.stderr
        rollup = json.loads((out_dir / "sc04-saved-card-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"


def test_signal_during_browser_flow_still_restores_tokens_and_session(
    monkeypatch, tmp_path: Path
) -> None:
    cleanup_calls: list[str] = []

    def fake_wp_eval(_wp, _role, code, *_args):
        if code == GATE_MODULE.FIXTURE_PROBE_PHP:
            return {
                "success": True,
                "subscription_id": 1283,
                "customer_id": 17,
                "normal_token_id": 37,
                "existing_sca_token_id": 0,
                "baseline_token_ids": [37],
                "product_id": 688,
                "classic_url": "http://localhost/classic/",
                "blocks_url": "http://localhost/blocks/",
                "errors": [],
            }
        if code == GATE_MODULE.AUTH_SESSION_CREATE_PHP:
            return {
                "success": True,
                "auth_cookie": {"name": "wordpress_logged_in_test", "value": "secret"},
            }
        if code == GATE_MODULE.TOKEN_CLEANUP_PHP:
            cleanup_calls.append("tokens")
            return {"success": False}
        if code == GATE_MODULE.SESSION_DESTROY_PHP:
            cleanup_calls.append("session")
            return {"success": True}
        raise AssertionError("unexpected WP probe")

    monkeypatch.setattr(GATE_MODULE, "run_wp_eval", fake_wp_eval)
    monkeypatch.setattr(
        GATE_MODULE,
        "run_browser",
        lambda **_kwargs: (_ for _ in ()).throw(GATE_MODULE.GateSignal(signal.SIGTERM)),
    )

    try:
        GATE_MODULE.run_store(
            repo=REPO,
            context={
                "aggregate_run_id": "signal-test",
                "context_sha256": "sha256:test",
                "fixtures": {"ref": {"subscription_id": "1283"}},
            },
            role="ref",
            wp=REF_WP,
            base_url="http://localhost:8082",
            subscription_id="1283",
            out_dir=tmp_path,
            browser_runner=tmp_path / "runner",
            browser_driver=tmp_path / "driver",
        )
    except GATE_MODULE.GateSignal as exc:
        assert exc.signum == signal.SIGTERM
        assert exc.cleanup_errors == ["could not restore ref saved-card token baseline"]
    else:
        raise AssertionError("the signal must abort the store flow")

    assert cleanup_calls == ["tokens", "session"]
    assert issubclass(GATE_MODULE.GateSignal, BaseException)
    assert not issubclass(GATE_MODULE.GateSignal, Exception)


def test_main_installs_and_restores_cleanup_signal_handlers(monkeypatch) -> None:
    registrations = []
    monkeypatch.setattr(GATE_MODULE, "run_gate_main", lambda: 0)
    monkeypatch.setattr(GATE_MODULE.signal, "getsignal", lambda signum: f"old-{signum}")
    monkeypatch.setattr(
        GATE_MODULE.signal,
        "signal",
        lambda signum, handler: registrations.append((signum, handler)),
    )

    assert GATE_MODULE.main() == 0
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


if __name__ == "__main__":
    test_print_plan_describes_context_bound_dual_store_playwright_gate()
    test_gate_runs_both_stores_and_destroys_short_lived_sessions()
    test_wp_probe_failure_does_not_leak_auth_cookie_output()
    test_session_cleanup_failure_makes_gate_non_passing()
    print("PASS test_print_plan_describes_context_bound_dual_store_playwright_gate")
    print("PASS test_gate_runs_both_stores_and_destroys_short_lived_sessions")
    print("PASS test_wp_probe_failure_does_not_leak_auth_cookie_output")
    print("PASS test_session_cleanup_failure_makes_gate_non_passing")
