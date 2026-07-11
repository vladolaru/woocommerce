#!/usr/bin/env python3
"""Focused regression checks for the A5f cutover rehearsal harness."""

from __future__ import annotations

import importlib.util
import os
import sys
import tempfile
from pathlib import Path
from types import SimpleNamespace

import pytest


REPO = Path(__file__).resolve().parents[2]
MODULE_PATH = REPO / "tools/woopayments-merge/a5f-cutover-rehearsal.py"


@pytest.fixture(autouse=True)
def approve_target_container(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", "target-cli-1")


def load_module():
    assert MODULE_PATH.exists()
    spec = importlib.util.spec_from_file_location("a5f_cutover_rehearsal", MODULE_PATH)
    module = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    sys.path.insert(0, str(MODULE_PATH.parent))
    try:
        spec.loader.exec_module(module)
    finally:
        sys.path.pop(0)
    return module


def make_args(out_dir: str):
    return SimpleNamespace(
        target_wp="docker exec -i target-cli-1 wp --allow-root --user=1",
        target_url="http://store8889.localhost:8889",
        store_dir=str(REPO),
        playwriter_session="unit",
        out_dir=out_dir,
        skip_wpcom_readiness=False,
    )


def make_args_without_user(out_dir: str):
    return SimpleNamespace(
        target_wp="docker exec -i target-cli-1 wp --allow-root",
        target_url="http://store8889.localhost:8889",
        store_dir=str(REPO),
        playwriter_session="unit",
        out_dir=out_dir,
        skip_wpcom_readiness=False,
    )


def assert_raises(fn, expected: str):
    try:
        fn()
    except Exception as exc:
        assert expected in str(exc)
    else:
        raise AssertionError(f"Expected exception containing {expected!r}")


def test_validate_local_wp_command_rejects_shell_and_remote_transports():
    module = load_module()

    invalid_commands = [
        "docker exec -i target-cli-1 wp --allow-root --user=1; rm -rf .",
        "docker exec -i target-cli-1 wp --allow-root --user=1 && wp option get siteurl",
        "wp --http=https://example.com option get siteurl",
        "ssh example wp option get siteurl",
        "wpcom-local wp option get siteurl",
    ]

    for command in invalid_commands:
        assert_raises(lambda command=command: module.validate_local_wp_command(command), "unsafe or remote")


def test_validate_local_wp_command_accepts_local_docker_wp():
    module = load_module()

    assert module.validate_local_wp_command("docker exec -i target-cli-1 wp --allow-root --user=1") == [
        "docker",
        "exec",
        "-i",
        "target-cli-1",
        "wp",
        "--allow-root",
        "--user=1",
    ]


def test_validate_local_wp_command_rejects_unapproved_standalone_target():
    module = load_module()
    previous = os.environ.pop("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", None)
    try:
        assert_raises(
            lambda: module.validate_local_wp_command("docker exec -i arbitrary-cli-1 wp --allow-root --user=1"),
            "approved target container",
        )
    finally:
        if previous is not None:
            os.environ["WOOPAYMENTS_APPROVED_TARGET_CONTAINER"] = previous


def test_rollup_is_written_after_failed_phase():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        rehearsal = module.Rehearsal(make_args(out_dir))
        rehearsal.record_phase("baseline-state", {"status": "pass", "ready": True})
        rehearsal.record_failure("soft-cutover", "missing notice")

        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert payload["status"] == "fail"
    assert payload["pass"] is False
    assert payload["phase_results"][0]["id"] == "baseline-state"
    assert payload["failures"] == [{"phase": "soft-cutover", "message": "missing notice"}]


def test_rollup_declares_cutover_scope_without_claiming_external_profile_execution():
    module = load_module()

    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        module.Rehearsal(make_args(out_dir))
        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert "required_store_profiles" not in payload
    assert payload["evidence_scope"]["owned_by_a5f"] == [
        "runtime_ownership_transitions",
        "soft_cutover",
        "mandatory_cutover",
        "plugin_activation_guard",
        "local_transport_continuity",
    ]
    assert payload["evidence_scope"]["orchestrated_by_final_evidence"] == [
        "lpm-checkout-gate.sh",
        "mc-rates-gate.sh",
        "token-continuity-gate.sh",
    ]


def test_state_probe_runs_as_admin_when_target_wp_omits_user():
    module = load_module()

    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        rehearsal = module.Rehearsal(make_args_without_user(out_dir))
        captured = {}

        def fake_run_wp(phase_id, wp_args, **kwargs):
            captured["phase_id"] = phase_id
            captured["wp_args"] = wp_args
            captured["kwargs"] = kwargs
            return {"json": {"ready": True}}

        rehearsal.run_wp = fake_run_wp
        state = rehearsal.run_state_probe("default-off-plugin-state")

    assert state == {"ready": True}
    assert captured["phase_id"] == "default-off-plugin-state"
    assert captured["wp_args"][:2] == ["--user=1", "eval-file"]
    assert captured["wp_args"][-1] == "-"
    assert captured["kwargs"]["parse_json"] is True
    assert "WooPayments native cutover state probe" in captured["kwargs"]["input_text"]


def test_parse_json_prefers_top_level_probe_payload():
    module = load_module()

    output = """
{
  "ready": true,
  "failures": [],
  "captured_requests": [
    {
      "body": {
        "statement_descriptor": "A5 LOCAL PROBE"
      }
    }
  ]
}
""".strip()

    payload = module.parse_json_from_output(output)

    assert payload["ready"] is True
    assert payload["captured_requests"][0]["body"]["statement_descriptor"] == "A5 LOCAL PROBE"


def test_debug_log_scan_ignores_known_wpcli_textdomain_notices():
    module = load_module()

    source = module.build_debug_log_scan()

    assert "_load_textdomain_just_in_time" in source
    assert "ignored_matches" in source
    assert "wp67_early_textdomain_notice" in source
    assert "PHP Notice|Notice:" in source


def test_expected_failure_command_records_pass_phase():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        rehearsal = module.Rehearsal(make_args(out_dir))
        result = rehearsal.run_command(
            "activation-guard-blocks-under-mandatory",
            [
                sys.executable,
                "-c",
                "import sys; print('now included in WooCommerce core'); sys.exit(1)",
            ],
            expected_failure_message="now included in WooCommerce core",
        )

        payload = module.read_json(Path(out_dir) / "a5f-cutover-rehearsal.json")

    assert result["status"] == "pass"
    assert result["expected_failure"] is True
    assert result["observed_exit_code"] == 1
    assert payload["phase_results"][0]["status"] == "pass"
    assert payload["phase_results"][0]["expected_failure"] is True


def test_orchestrator_uses_browser_for_blocked_mandatory_gate():
    source = MODULE_PATH.read_text(encoding="utf-8")

    assert "a5-blocked-mandatory-browser-gate.playwriter.mjs" in source
    assert "trigger-blocked-mandatory-admin-init" not in source


def test_playwriter_gate_copies_failed_source_evidence():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        out_path = Path(out_dir)
        source_evidence = out_path.parent / "a5e-failing-gate.json"
        try:
            rehearsal = module.Rehearsal(make_args(out_dir))

            def fail_command(*args, **kwargs):
                source_evidence.write_text('{"status":"failed"}\n', encoding="utf-8")
                raise module.HarnessError("browser gate failed")

            rehearsal.run_command = fail_command
            assert_raises(
                lambda: rehearsal.run_playwriter_gate("failing-gate", MODULE_PATH, source_evidence),
                "browser gate failed",
            )

            copied = out_path / "a5f-failing-gate.json"
            assert copied.exists()
            assert module.read_json(copied)["status"] == "failed"
            assert str(copied) in rehearsal.browser_evidence_paths
        finally:
            source_evidence.unlink(missing_ok=True)


def test_playwriter_gate_passes_portable_browser_environment():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        out_path = Path(out_dir)
        source_evidence = out_path / "a5e-env-gate.json"
        rehearsal = module.Rehearsal(make_args(out_dir))
        captured_env = {}

        def pass_command(*args, **kwargs):
            captured_env.update(kwargs["env"])
            source_evidence.write_text('{"status":"pass","updated":true}\n', encoding="utf-8")
            return {"status": "pass"}

        rehearsal.run_command = pass_command
        rehearsal.run_playwriter_gate("env-gate", MODULE_PATH, source_evidence)

    assert captured_env["A5_GATE_TARGET_URL"] == "http://store8889.localhost:8889"
    assert captured_env["A5_GATE_PLUGINS_URL"] == "http://store8889.localhost:8889/wp-admin/plugins.php"
    assert captured_env["A5_GATE_DATA_DIR"] == str(source_evidence.parent)
    assert captured_env["A5_GATE_EVIDENCE_PATH"] == str(source_evidence)


def test_browser_gate_can_use_playwright_runner_without_playwriter_session():
    module = load_module()
    with tempfile.TemporaryDirectory(prefix="a5f-rehearsal-test-") as out_dir:
        out_path = Path(out_dir)
        source_evidence = out_path / "a5e-playwright-gate.json"
        args = make_args(out_dir)
        args.playwriter_session = ""
        rehearsal = module.Rehearsal(args)
        captured_command = []
        old_browser_runner = os.environ.get("BROWSER_RUNNER")
        old_playwright_bin = os.environ.get("PLAYWRIGHT_SCRIPT_RUNNER_BIN")

        def pass_command(*args, **kwargs):
            captured_command.extend(args[1])
            source_evidence.write_text('{"status":"pass","updated":true}\n', encoding="utf-8")
            return {"status": "pass"}

        try:
            os.environ["BROWSER_RUNNER"] = "playwright"
            os.environ["PLAYWRIGHT_SCRIPT_RUNNER_BIN"] = "/fake/playwright-script-runner.mjs"
            rehearsal.run_command = pass_command
            rehearsal.run_playwriter_gate("playwright-gate", MODULE_PATH, source_evidence)
        finally:
            if old_browser_runner is None:
                os.environ.pop("BROWSER_RUNNER", None)
            else:
                os.environ["BROWSER_RUNNER"] = old_browser_runner
            if old_playwright_bin is None:
                os.environ.pop("PLAYWRIGHT_SCRIPT_RUNNER_BIN", None)
            else:
                os.environ["PLAYWRIGHT_SCRIPT_RUNNER_BIN"] = old_playwright_bin

    assert captured_command[0] == "/fake/playwright-script-runner.mjs"
    assert str(MODULE_PATH) in captured_command
    assert "-s" not in captured_command


def test_browser_gates_use_isolated_pages_and_close_them():
    scripts = [
        REPO / "tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-mandatory-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-blocked-mandatory-browser-gate.playwriter.mjs",
    ]

    for script in scripts:
        source = script.read_text(encoding="utf-8")
        assert "state.page = await context.newPage();" in source
        assert "await page.close(" in source
        assert "context.pages().find" not in source


def test_browser_gates_are_portable_and_env_driven():
    scripts = [
        REPO / "tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-mandatory-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-blocked-mandatory-browser-gate.playwriter.mjs",
    ]

    for script in scripts:
        source = script.read_text(encoding="utf-8")
        assert "/Users/vladolaru/" not in source
        assert "A5_GATE_PLUGINS_URL" in source
        assert "A5_GATE_DATA_DIR" in source
        assert "A5_GATE_EVIDENCE_PATH" in source


def test_cutover_screenshot_capture_is_non_fatal_evidence():
    scripts = [
        REPO / "tools/woopayments-merge/a5-cutover-browser-gate.playwriter.mjs",
        REPO / "tools/woopayments-merge/a5-mandatory-browser-gate.playwriter.mjs",
    ]

    for script in scripts:
        source = script.read_text(encoding="utf-8")
        assert "const screenshotFailures = [];" in source
        assert "timeout: 10000" in source
        assert "screenshotFailures.push" in source
        assert "screenshotFailures," in source


def main() -> None:
    tests = [
        test_validate_local_wp_command_rejects_shell_and_remote_transports,
        test_validate_local_wp_command_accepts_local_docker_wp,
        test_validate_local_wp_command_rejects_unapproved_standalone_target,
        test_rollup_is_written_after_failed_phase,
        test_rollup_declares_cutover_scope_without_claiming_external_profile_execution,
        test_state_probe_runs_as_admin_when_target_wp_omits_user,
        test_parse_json_prefers_top_level_probe_payload,
        test_debug_log_scan_ignores_known_wpcli_textdomain_notices,
        test_expected_failure_command_records_pass_phase,
        test_orchestrator_uses_browser_for_blocked_mandatory_gate,
        test_playwriter_gate_copies_failed_source_evidence,
        test_playwriter_gate_passes_portable_browser_environment,
        test_browser_gates_use_isolated_pages_and_close_them,
        test_browser_gates_are_portable_and_env_driven,
        test_cutover_screenshot_capture_is_non_fatal_evidence,
    ]
    previous = os.environ.get("WOOPAYMENTS_APPROVED_TARGET_CONTAINER")
    os.environ["WOOPAYMENTS_APPROVED_TARGET_CONTAINER"] = "target-cli-1"
    try:
        for test in tests:
            test()
            print(f"PASS {test.__name__}")
    finally:
        if previous is None:
            os.environ.pop("WOOPAYMENTS_APPROVED_TARGET_CONTAINER", None)
        else:
            os.environ["WOOPAYMENTS_APPROVED_TARGET_CONTAINER"] = previous


if __name__ == "__main__":
    main()
