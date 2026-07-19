#!/usr/bin/env python3
"""Focused direct-browser and mutation-boundary regressions for MD-02."""

from __future__ import annotations

import importlib.util
import json
import subprocess
from pathlib import Path

import pytest


ROOT = Path(__file__).resolve().parents[2]
ASSERTIONS = ROOT / "tools/woopayments-merge/md02-browser-assertions.cjs"
SCENARIO = ROOT / "tools/woopayments-merge/md02-save-evidence.playwright.mjs"
GATE = ROOT / "tools/woopayments-merge/md02-save-evidence-gate.py"
RUNNER = ROOT / "tools/woopayments-merge/playwright-script-runner.mjs"


def run_assertions(facts: dict) -> dict:
    script = """
const helper = require( process.argv[ 1 ] );
const facts = JSON.parse( process.argv[ 2 ] );
process.stdout.write( JSON.stringify( helper.deriveAssertions( facts ) ) );
"""
    completed = subprocess.run(
        ["node", "-e", script, str(ASSERTIONS), json.dumps(facts)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout)


def run_timeline_console(fact: dict) -> bool:
    script = """
const helper = require( process.argv[ 1 ] );
process.stdout.write( JSON.stringify( helper.isAllowedTimelineConsole( JSON.parse( process.argv[ 2 ] ) ) ) );
"""
    completed = subprocess.run(
        ["node", "-e", script, str(ASSERTIONS), json.dumps(fact)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout)


def run_timeline_failure(fact: dict) -> bool:
    script = """
const helper = require( process.argv[ 1 ] );
process.stdout.write( JSON.stringify( helper.isAllowedTimelineFailure( JSON.parse( process.argv[ 2 ] ) ) ) );
"""
    completed = subprocess.run(
        ["node", "-e", script, str(ASSERTIONS), json.dumps(fact)],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout)


def run_challenge_route(url: str) -> bool:
    script = """
const helper = require( process.argv[ 1 ] );
process.stdout.write( JSON.stringify( helper.isChallengeRoute( process.argv[ 2 ] ) ) );
"""
    completed = subprocess.run(
        ["node", "-e", script, str(ASSERTIONS), url],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert completed.returncode == 0, completed.stderr
    return json.loads(completed.stdout)


def valid_facts() -> dict:
    return {
        "authenticatedAdmin": True,
        "exactDisputeRow": True,
        "responseActionDiscovered": True,
        "requestSeen": True,
        "requestMethod": "POST",
        "requestPathMatches": True,
        "submitFalse": True,
        "descriptionMatches": True,
        "payloadCustomerNameMatches": True,
        "noFilesAttached": True,
        "responseOk": True,
        "saveFeedback": True,
        "reloadedDescriptionMatches": True,
        "descriptionEditable": True,
        "saveButtonLabel": "Save for later",
        "customerNameVisible": True,
    }


def test_browser_adapter_files_exist() -> None:
    assert ASSERTIONS.is_file()
    assert SCENARIO.is_file()


def test_assertions_separate_functional_behavior_from_exact_ux() -> None:
    assertions = run_assertions(valid_facts())
    assert assertions == {
        "functional": {
            "authenticated_admin": True,
            "exact_dispute_row": True,
            "response_action_discovered": True,
            "exact_submit_false_post": True,
            "customer_name_preserved": True,
            "no_files_attached": True,
            "save_feedback": True,
            "description_reloaded": True,
            "description_editable": True,
        },
        "ux": {"save_for_later_copy": True, "customer_name_visible": True},
    }

    target_facts = valid_facts()
    target_facts["saveButtonLabel"] = "Save draft"
    target_facts["customerNameVisible"] = False
    target = run_assertions(target_facts)
    assert all(target["functional"].values())
    assert target["ux"] == {"save_for_later_copy": False, "customer_name_visible": False}


@pytest.mark.parametrize(
    "url",
    (
        "http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fdisputes%2Fchallenge&id=du_ref",
        "http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fchallenge&id=du_target",
        "http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fnew-evidence&id=du_ref",
    ),
)
def test_challenge_route_decodes_the_wc_admin_path_parameter(url: str) -> None:
    assert run_challenge_route(url) is True


def test_challenge_route_rejects_transaction_details() -> None:
    assert (
        run_challenge_route(
            "http://localhost:8082/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Ftransactions%2Fdetails&id=pi_ref"
        )
        is False
    )

@pytest.mark.parametrize(
    "field,assertion",
    [
        ("requestSeen", "exact_submit_false_post"),
        ("submitFalse", "exact_submit_false_post"),
        ("descriptionMatches", "exact_submit_false_post"),
        ("payloadCustomerNameMatches", "customer_name_preserved"),
        ("noFilesAttached", "no_files_attached"),
        ("responseOk", "save_feedback"),
        ("saveFeedback", "save_feedback"),
        ("reloadedDescriptionMatches", "description_reloaded"),
        ("descriptionEditable", "description_editable"),
    ],
)
def test_functional_assertions_fail_on_missing_mutation_fact(field: str, assertion: str) -> None:
    facts = valid_facts()
    facts[field] = False
    result = run_assertions(facts)
    assert result["functional"][assertion] is False


def test_only_the_exact_reference_timeline_console_failure_is_dispositioned() -> None:
    exact = {
        "store": "ref",
        "phase": "details",
        "chargeId": "ch_fixture",
        "type": "error",
        "text": "Failed to load resource: the server responded with a status of 500 (Internal Server Error)",
        "url": "http://localhost:8082/wp-json/wc/v3/payments/timeline/ch_fixture",
        "expectedOrigin": "http://localhost:8082",
    }
    assert run_timeline_console(exact) is True
    for field, value in (
        ("store", "target"),
        ("chargeId", "ch_other"),
        ("phase", "response_seen"),
        ("phase", "reloaded"),
        ("text", "TypeError: application failed"),
        ("url", "http://localhost:8082/wp-json/wc/v3/payments/disputes/du_fixture"),
        ("url", "https://not-the-store.example/wp-json/wc/v3/payments/timeline/ch_fixture"),
    ):
        changed = dict(exact)
        changed[field] = value
        assert run_timeline_console(changed) is False

    exact_failure = {
        "store": "ref",
        "phase": "details",
        "chargeId": "ch_fixture",
        "status": 500,
        "method": "GET",
        "path": "/wp-json/wc/v3/payments/timeline/ch_fixture",
        "origin": "http://localhost:8082",
        "expectedOrigin": "http://localhost:8082",
    }
    assert run_timeline_failure(exact_failure) is True
    foreign_failure = dict(exact_failure, origin="https://not-the-store.example")
    assert run_timeline_failure(foreign_failure) is False


@pytest.mark.parametrize(
    "customer_name",
    ("José / Shop", "Jose\u0301 / Shop", "  José / Shop  ", "商店 / José"),
)
def test_php_and_browser_customer_name_canonicalization_match(customer_name: str) -> None:
    node = subprocess.run(
        [
            "node",
            "-e",
            "const h=require(process.argv[1]);process.stdout.write(h.canonicalCustomerNameJson(process.argv[2]));",
            str(ASSERTIONS),
            customer_name,
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    php = subprocess.run(
        [
            "php",
            "-r",
            "echo json_encode(trim($argv[1]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);",
            customer_name,
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    assert node.returncode == 0, node.stderr
    assert php.returncode == 0, php.stderr
    assert node.stdout == php.stdout


def test_scenario_is_incremental_direct_playwright_only_and_masks_pii() -> None:
    source = SCENARIO.read_text(encoding="utf-8")
    legacy_runner = "play" + "writer"
    assert legacy_runner not in source.lower()
    assert "context.addCookies" in source
    assert "context.newPage()" in source
    assert "page.close()" in source
    assert "writeEvidence" in source
    for phase in ("armed", "request_seen", "response_seen", "reloaded"):
        assert f"'{phase}'" in source
    assert source.index("'armed'") < source.index("saveButton.click")
    assert "waitForResponse" in source
    assert "submit === false" in source or "payload.submit === false" in source
    assert "mask:" in source
    assert "mask: [ piiMasks ]" in source
    assert "exactChallenge" in source
    assert "challengeDisclosure" in source
    assert "customerNameHmac" in source
    assert "authCookies" in source
    assert "sameOriginUrl" in source
    assert "/wp-admin/" in source
    assert "Save for later" in source
    assert "Save draft" in source
    assert "unrelated_reference_timeline_console" in source
    assert "files_selected: -1" in source


def test_gate_module_exists() -> None:
    assert GATE.is_file()


def load_gate():
    spec = importlib.util.spec_from_file_location("md02_gate", GATE)
    assert spec and spec.loader
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_gate_is_wired_to_direct_runner_only() -> None:
    source = GATE.read_text(encoding="utf-8")
    legacy_runner = "play" + "writer"
    assert legacy_runner not in source.lower()
    assert str(RUNNER.name) in source
    assert 'choices=("playwright",)' in source


def test_gate_parses_only_the_exact_driver_envelope() -> None:
    module = load_gate()
    envelope = {
        "success": True,
        "mode": "create-auth-session",
        "auth_cookies": [
            {"scheme": "auth", "name": "wordpress_hash", "value": "auth-secret"},
            {"scheme": "secure_auth", "name": "wordpress_sec_hash", "value": "secure-secret"},
            {"scheme": "logged_in", "name": "wordpress_logged_in_hash", "value": "logged-in-secret"},
        ],
        "errors": [],
    }
    output = "WP-CLI notice\n" + json.dumps(envelope) + "\n"
    assert module.parse_last_json(output, mode="create-auth-session") == envelope

    with pytest.raises(module.GateBlocked):
        module.parse_last_json(output, mode="destroy-auth-session")
    with pytest.raises(module.GateBlocked):
        module.parse_last_json("x" * (2 * 1024 * 1024 + 1), mode="create-auth-session")


def test_gate_requires_the_complete_caller_owned_cookie_set() -> None:
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
    with pytest.raises(module.GateBlocked):
        module.session_auth_cookies({"auth_cookies": session["auth_cookies"][:2]})


def test_write_ahead_boundary_blocks_ambiguous_mutation_and_replay() -> None:
    module = load_gate()
    pre = {"evidence": {"product_description": "old description"}}
    unchanged = {"evidence": {"product_description": "old description"}}
    changed = {"evidence": {"product_description": "MD02 evidence product description"}}
    no_request = {"phase": "armed", "request": {"submit_false": False, "description_matches": False}}
    trusted_request = {
        "phase": "request_seen",
        "request": {
            "method": "POST",
            "path": "/wp-json/wc/v3/payments/disputes/du_fixture",
            "submit_false": True,
            "description_matches": True,
            "customer_name_matches": True,
            "files_selected": 0,
        },
    }

    assert module.assess_mutation_boundary(pre, no_request, unchanged, "du_fixture") == "unchanged_safe_failure"
    assert module.assess_mutation_boundary(pre, trusted_request, changed, "du_fixture") == "trusted_mutation"
    assert module.assess_mutation_boundary(pre, no_request, changed, "du_fixture") == "ambiguous_mutation"
    assert (
        module.assess_mutation_boundary(pre, trusted_request, unchanged, "du_fixture")
        == "trusted_mutation_mismatch"
    )

    already_saved = {"evidence": {"product_description": "MD02 evidence product description"}}
    with pytest.raises(module.GateBlocked, match="already contains"):
        module.require_fresh_pre_state(already_saved)


def test_source_manifest_validation_binds_the_exact_md01_probe(tmp_path: Path) -> None:
    module = load_gate()
    probe_path = tmp_path / "ref-probe.json"
    probe = {
        "schema": "woopayments_md01_normalized.v1",
        "store": "ref",
        "run_stamp": "20260719T191058Z-64015",
        "runtime_owner": "plugin",
        "status": "pass",
        "identity": {
            "order_id": 2250,
            "charge_id": "ch_ref_md02",
            "intent_id": "pi_ref_md02",
            "dispute_id": "du_ref_md02",
        },
        "facts": {"dispute": {"status": "needs_response", "due_by": "2026-07-27 23:59:59"}},
        "blockers": [],
        "errors": [],
        "context_hmac": "hmac-sha256:" + "1" * 64,
    }
    probe["payload_sha256"] = module.payload_digest(probe)
    probe_path.write_text(json.dumps(probe), encoding="utf-8")
    manifest_path = tmp_path / "ref-manifest.json"
    manifest = {
        "schema": "woopayments_md01_manifest.v1",
        "store": "ref",
        "run_stamp": probe["run_stamp"],
        "run_scope": "partial",
        "flow": "MD-01-created-note-on-hold-notify",
        "status": "pass",
        "exit_code": 0,
        "files": {
            probe_path.name: {
                "schema": probe["schema"],
                "sha256": module.file_digest(probe_path),
                "payload_sha256": probe["payload_sha256"],
            }
        },
        "verdict_sources": [probe_path.name],
        "context_hmac": "hmac-sha256:" + "2" * 64,
    }
    manifest["payload_sha256"] = module.payload_digest(manifest)
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

    loaded_manifest, loaded_probe = module.validate_source_manifest(manifest_path, "ref")
    assert loaded_manifest["run_stamp"] == probe["run_stamp"]
    assert loaded_probe["identity"] == probe["identity"]
    assert module.source_due_by_epoch(loaded_probe["facts"]["dispute"]["due_by"]) == 1785196799

    rewritten = json.loads(probe_path.read_text(encoding="utf-8"))
    rewritten["identity"]["dispute_id"] = "du_other"
    rewritten["payload_sha256"] = module.payload_digest(rewritten)
    probe_path.write_text(json.dumps(rewritten), encoding="utf-8")
    with pytest.raises(module.GateBlocked, match="digest"):
        module.validate_source_manifest(manifest_path, "ref")


def test_state_driver_injects_context_key_over_stdin_not_argv() -> None:
    source = GATE.read_text(encoding="utf-8")
    assert "putenv('CRITICAL_FLOWS_RUN_CONTEXT_KEY=" in source
    assert "STATE_DRIVER.read_text" in source
    assert "CRITICAL_FLOWS_RUN_CONTEXT_KEY", "context key must be consumed from the environment"
    assert "auto_replay" not in source
    assert "replay_mutation" not in source


def test_gate_cleanup_signal_and_delayed_probe_are_explicit() -> None:
    source = GATE.read_text(encoding="utf-8")
    for required in (
        "create-auth-session",
        "destroy-auth-session",
        "EXIT_CLEANUP = 70",
        "finally:",
        "signal.SIGHUP",
        "signal.SIGINT",
        "signal.SIGTERM",
        "delay_seconds",
        "phase=\"post\"",
        "phase=\"delayed\"",
    ):
        assert required in source


@pytest.mark.parametrize(
    "cleanup_fails,failing_probe_call,expected_exception",
    ((True, 2, "cleanup"), (False, 3, "blocked")),
)
def test_probe_errors_preserve_cleanup_precedence(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    cleanup_fails: bool,
    failing_probe_call: int,
    expected_exception: str,
) -> None:
    module = load_gate()
    evidence = importlib.util.spec_from_file_location(
        "md02_evidence_for_gate_test",
        ROOT / "tools/woopayments-critical-flows/flows/md02-evidence.py",
    )
    assert evidence and evidence.loader
    evidence_module = importlib.util.module_from_spec(evidence)
    evidence.loader.exec_module(evidence_module)
    pre = {
        "schema": "woopayments_md02_state.v1",
        "store": "ref",
        "run_stamp": "20260719T220000Z-4242",
        "phase": "pre",
        "runtime_owner": "plugin",
        "identity": {
            "order_id": 2250,
            "charge_id": "ch_ref_md02",
            "intent_id": "pi_ref_md02",
            "dispute_id": "du_ref_md02",
        },
        "lifecycle": {
            "status": "needs_response",
            "due_by": 2785196799,
            "past_due": False,
            "has_evidence": False,
            "submission_count": 0,
        },
        "evidence": {
            "product_description": "prior",
            "customer_name_present": True,
            "customer_name_matches_order": True,
            "customer_name_hmac": "hmac-sha256:" + "1" * 64,
            "file_evidence_hmac": "hmac-sha256:" + "2" * 64,
            "decisive_state_hmac": "hmac-sha256:" + "3" * 64,
        },
        "metadata_keys": [],
        "blockers": [],
    }
    probe_calls = 0

    def fake_probe(*_args, **_kwargs):
        nonlocal probe_calls
        probe_calls += 1
        if probe_calls < failing_probe_call:
            return pre
        raise module.GateBlocked("simulated post/delayed-probe outage")

    def fake_state_driver(_wp, _store, action, _stamp, *_args):
        if action == "create-auth-session":
            return {
                "success": True,
                "auth_cookies": [
                    {"scheme": "auth", "name": "a", "value": "one"},
                    {"scheme": "secure_auth", "name": "b", "value": "two"},
                    {"scheme": "logged_in", "name": "c", "value": "three"},
                ],
            }
        if action == "destroy-auth-session":
            if cleanup_fails:
                raise module.GateBlocked("simulated cleanup outage")
            return {"success": True, "destroyed": True}
        raise AssertionError(action)

    monkeypatch.setattr(module, "probe_state", fake_probe)
    monkeypatch.setattr(module, "run_state_driver", fake_state_driver)
    monkeypatch.setattr(module, "run_browser", lambda *_args, **_kwargs: (_ for _ in ()).throw(module.GateBlocked("browser down")))
    monkeypatch.setattr(module, "validate_pre_state", lambda *_args, **_kwargs: None)
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    source_manifest = tmp_path / "ref-manifest.json"
    source_manifest.write_text("{}", encoding="utf-8")
    error_type = module.GateCleanupError if expected_exception == "cleanup" else module.GateBlocked
    with pytest.raises(error_type):
        module.run_store(
            store="ref",
            wp="fake-wp",
            base_url="http://localhost:8082",
            run_stamp=pre["run_stamp"],
            source_manifest_path=source_manifest,
            source_probe={"identity": pre["identity"]},
            context={"aggregate_run_id": "run", "context_sha256": "sha256:" + "6" * 64},
            out_dir=tmp_path,
            delay_seconds=0,
            repo_root=tmp_path,
        )


def test_gate_refuses_a_nonempty_output_directory_before_any_mutation(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    module = load_gate()
    out_dir = tmp_path / "existing"
    out_dir.mkdir()
    (out_dir / "retained.json").write_text("{}\n", encoding="utf-8")
    context = tmp_path / "context.json"
    source_manifest = tmp_path / "source.json"
    context.write_text("{}\n", encoding="utf-8")
    source_manifest.write_text("{}\n", encoding="utf-8")
    monkeypatch.setattr(
        module,
        "parse_args",
        lambda: __import__("argparse").Namespace(
            repo=str(ROOT),
            context_file=str(context),
            source_manifest=str(source_manifest),
            store="ref",
            wp="fake-wp",
            url="http://localhost:8082",
            out_dir=str(out_dir),
            run_stamp="20260719T220000Z-4242",
            delay_seconds=0,
            browser_runner="playwright",
        ),
    )
    with pytest.raises(module.GateBlocked, match="must be empty"):
        module.run_gate_main()
    assert (out_dir / "retained.json").read_text(encoding="utf-8") == "{}\n"


def test_pre_state_deadline_must_outlive_the_delayed_probe() -> None:
    module = load_gate()
    pre = {
        "identity": {"order_id": 1},
        "lifecycle": {"status": "needs_response", "due_by": 100, "past_due": False, "submission_count": 0},
        "evidence": {
            "product_description": "prior",
            "customer_name_present": True,
            "customer_name_matches_order": True,
        },
    }
    probe = {"identity": {"order_id": 1}, "facts": {"dispute": {"due_by": 100}}}
    with pytest.raises(module.GateBlocked, match="future"):
        module.validate_pre_state(pre, probe, minimum_due_by=160)


def test_pre_state_accepts_the_archived_md01_utc_deadline_shape() -> None:
    module = load_gate()
    identity = {"order_id": 1}
    pre = {
        "identity": identity,
        "lifecycle": {
            "status": "needs_response",
            "due_by": 1785196799,
            "past_due": False,
            "submission_count": 0,
        },
        "evidence": {
            "product_description": "prior",
            "customer_name_present": True,
            "customer_name_matches_order": True,
        },
    }
    probe = {
        "identity": identity,
        "facts": {"dispute": {"due_by": "2026-07-27 23:59:59"}},
    }

    module.validate_pre_state(pre, probe, minimum_due_by=1785196700)


@pytest.mark.parametrize(
    "due_by",
    ("", "2026-07-27", "2026-7-27 23:59:59", "2026-07-27 3:59:59", "not-a-date", True, None),
)
def test_source_deadline_rejects_malformed_archive_values(due_by: object) -> None:
    module = load_gate()

    with pytest.raises(module.GateBlocked, match="deadline"):
        module.source_due_by_epoch(due_by)


@pytest.mark.parametrize(
    "post_saved,browser_completed,response_failed,expected_rc,expected_boundary,expected_deterministic,expected_status",
    (
        (False, True, False, 1, "trusted_mutation_mismatch", "fail", "fail"),
        (True, False, False, 3, "trusted_mutation", "pass", "blocked"),
        (False, False, True, 1, "trusted_mutation_mismatch", "fail", "fail"),
    ),
)
def test_run_store_records_trusted_request_outcomes(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    post_saved: bool,
    browser_completed: bool,
    response_failed: bool,
    expected_rc: int,
    expected_boundary: str,
    expected_deterministic: str,
    expected_status: str,
) -> None:
    module = load_gate()
    run_stamp = "20260719T220000Z-4242"
    identity = {
        "order_id": 2250,
        "charge_id": "ch_ref_md02",
        "intent_id": "pi_ref_md02",
        "dispute_id": "du_ref_md02",
    }

    def state(phase: str, saved: bool) -> dict:
        return {
            "schema": "woopayments_md02_state.v1",
            "store": "ref",
            "run_stamp": run_stamp,
            "phase": phase,
            "runtime_owner": "plugin",
            "identity": identity,
            "lifecycle": {
                "status": "needs_response",
                "due_by": 2785196799,
                "past_due": False,
                "has_evidence": saved,
                "submission_count": 0,
            },
            "evidence": {
                "product_description": module.EVIDENCE.DESCRIPTION if saved else "prior",
                "customer_name_present": True,
                "customer_name_matches_order": True,
                "customer_name_hmac": "hmac-sha256:" + "1" * 64,
                "file_evidence_hmac": "hmac-sha256:" + "2" * 64,
                "decisive_state_hmac": "hmac-sha256:" + ("4" if saved else "3") * 64,
            },
            "metadata_keys": [],
            "blockers": [],
        }

    states = iter((state("pre", False), state("post", post_saved), state("delayed", post_saved)))
    monkeypatch.setattr(module, "probe_state", lambda *_args, **_kwargs: next(states))
    monkeypatch.setattr(module, "validate_pre_state", lambda *_args, **_kwargs: None)

    def fake_state_driver(_wp, _store, action, _stamp, *_args):
        if action == "create-auth-session":
            return {
                "success": True,
                "auth_cookies": [
                    {"scheme": "auth", "name": "a", "value": "one"},
                    {"scheme": "secure_auth", "name": "b", "value": "two"},
                    {"scheme": "logged_in", "name": "c", "value": "three"},
                ],
            }
        if action == "destroy-auth-session":
            return {"success": True, "destroyed": True}
        raise AssertionError(action)

    def fake_browser(config, raw_path, _log_path):
        screenshot_names = (
            [f"ref-md02-{surface}.png" for surface in ("form", "saved", "reloaded")]
            if browser_completed
            else []
        )
        for name in screenshot_names:
            (raw_path.parent / name).write_bytes(b"\x89PNG\r\n\x1a\n" + name.encode("utf-8"))
        raw_path.write_text(
            json.dumps(
                {
                    "schema": "woopayments_md02_browser_raw.v1",
                    "store": "ref",
                    "run_stamp": run_stamp,
                    "runtime_owner": "plugin",
                    "phase": "reloaded" if browser_completed else "response_seen" if response_failed else "request_seen",
                    "identity": {
                        "order_id": identity["order_id"],
                        "charge_id": identity["charge_id"],
                        "dispute_id": identity["dispute_id"],
                    },
                    "facts": {
                        "authenticatedAdmin": browser_completed,
                        "exactDisputeRow": browser_completed,
                        "responseActionDiscovered": browser_completed,
                        "requestSeen": True,
                        "requestMethod": "POST",
                        "requestPathMatches": True,
                        "submitFalse": True,
                        "descriptionMatches": True,
                        "payloadCustomerNameMatches": True,
                        "noFilesAttached": True,
                        "responseOk": browser_completed,
                        "saveFeedback": browser_completed,
                        "reloadedDescriptionMatches": browser_completed,
                        "descriptionEditable": browser_completed,
                        "saveButtonLabel": "Save for later" if browser_completed else "",
                        "customerNameVisible": browser_completed,
                    },
                    "functional_assertions": {
                        name: browser_completed
                        or name in {"exact_submit_false_post", "customer_name_preserved", "no_files_attached"}
                        for name in module.EVIDENCE.FUNCTIONAL_ASSERTIONS
                    },
                    "ux_assertions": {
                        name: browser_completed for name in module.EVIDENCE.UX_ASSERTIONS
                    },
                    "request": {
                        "method": "POST",
                        "path": f"/wp-json/wc/v3/payments/disputes/{identity['dispute_id']}",
                        "submit_false": True,
                        "description_matches": True,
                        "customer_name_matches": True,
                        "files_selected": 0,
                    },
                    "response": {
                        "status": 200 if browser_completed else 500 if response_failed else 0,
                        "ok": browser_completed,
                    },
                    "failed_responses": [
                        {
                            "store": "ref",
                            "status": 500,
                            "method": "POST",
                            "path": f"/wp-json/wc/v3/payments/disputes/{identity['dispute_id']}",
                            "phase": "response_seen",
                        }
                    ]
                    if response_failed
                    else [],
                    "diagnostics": [],
                    "console_errors": [],
                    "page_errors": [],
                    "screenshots": screenshot_names,
                    "errors": [],
                    "blockers": [] if browser_completed else ["browser_interrupted_after_request"],
                }
            ),
            encoding="utf-8",
        )
        return (0 if browser_completed else 3), 0

    monkeypatch.setattr(module, "run_state_driver", fake_state_driver)
    monkeypatch.setattr(module, "run_browser", fake_browser)
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    source_manifest = tmp_path / "ref-manifest.json"
    source_manifest.write_text("{}", encoding="utf-8")
    out_dir = tmp_path / "out"
    out_dir.mkdir()

    return_code, packet_path = module.run_store(
        store="ref",
        wp="fake-wp",
        base_url="http://localhost:8082",
        run_stamp=run_stamp,
        source_manifest_path=source_manifest,
        source_probe={"identity": identity},
        context={"aggregate_run_id": "run", "context_sha256": "sha256:" + "6" * 64},
        out_dir=out_dir,
        delay_seconds=0,
        repo_root=tmp_path,
    )
    packet = json.loads(packet_path.read_text(encoding="utf-8"))
    assert return_code == expected_rc
    assert packet["mutation_boundary"] == expected_boundary
    assert packet["deterministic_status"] == expected_deterministic
    assert packet["status"] == expected_status


@pytest.mark.parametrize(
    "state_changed,emit_early_artifact,form_no_files_proven,expected_boundary",
    (
        (False, False, False, "unchanged_safe_failure"),
        (True, False, False, "ambiguous_mutation"),
        (False, True, False, "unchanged_safe_failure"),
        (False, True, True, "unchanged_safe_failure"),
    ),
)
def test_run_store_records_untrusted_outcome_as_blocked_packet(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
    state_changed: bool,
    emit_early_artifact: bool,
    form_no_files_proven: bool,
    expected_boundary: str,
) -> None:
    module = load_gate()
    run_stamp = "20260719T220000Z-4242"
    identity = {
        "order_id": 2250,
        "charge_id": "ch_ref_md02",
        "intent_id": "pi_ref_md02",
        "dispute_id": "du_ref_md02",
    }

    def state(phase: str, saved: bool) -> dict:
        return {
            "schema": "woopayments_md02_state.v1",
            "store": "ref",
            "run_stamp": run_stamp,
            "phase": phase,
            "runtime_owner": "plugin",
            "identity": identity,
            "lifecycle": {
                "status": "needs_response",
                "due_by": 2785196799,
                "past_due": False,
                "has_evidence": saved,
                "submission_count": 0,
            },
            "evidence": {
                "product_description": module.EVIDENCE.DESCRIPTION if saved else "prior",
                "customer_name_present": True,
                "customer_name_matches_order": True,
                "customer_name_hmac": "hmac-sha256:" + "1" * 64,
                "file_evidence_hmac": "hmac-sha256:" + "2" * 64,
                "decisive_state_hmac": "hmac-sha256:" + ("4" if saved else "3") * 64,
            },
            "metadata_keys": [],
            "blockers": [],
        }

    states = iter((state("pre", False), state("post", state_changed), state("delayed", state_changed)))
    monkeypatch.setattr(module, "probe_state", lambda *_args, **_kwargs: next(states))
    monkeypatch.setattr(module, "validate_pre_state", lambda *_args, **_kwargs: None)

    def fake_state_driver(_wp, _store, action, _stamp, *_args):
        if action == "create-auth-session":
            return {
                "success": True,
                "auth_cookies": [
                    {"scheme": "auth", "name": "a", "value": "one"},
                    {"scheme": "secure_auth", "name": "b", "value": "two"},
                    {"scheme": "logged_in", "name": "c", "value": "three"},
                ],
            }
        if action == "destroy-auth-session":
            return {"success": True, "destroyed": True}
        raise AssertionError(action)

    monkeypatch.setattr(module, "run_state_driver", fake_state_driver)
    def fake_browser(_config, raw_path, _log_path):
        if not emit_early_artifact:
            raise module.GateBlocked("browser did not reach request")
        raw_path.write_text(
            json.dumps(
                {
                    "schema": "woopayments_md02_browser_raw.v1",
                    "store": "ref",
                    "run_stamp": run_stamp,
                    "runtime_owner": "plugin",
                    "phase": "list",
                    "identity": {
                        "order_id": identity["order_id"],
                        "charge_id": identity["charge_id"],
                        "dispute_id": identity["dispute_id"],
                    },
                    "facts": {
                        **{name: False for name in module.EVIDENCE.BROWSER_FACT_BOOLEAN_FIELDS},
                        "noFilesAttached": form_no_files_proven,
                        "requestMethod": "",
                        "saveButtonLabel": "",
                    },
                    "functional_assertions": {
                        name: form_no_files_proven if name == "no_files_attached" else False
                        for name in module.EVIDENCE.FUNCTIONAL_ASSERTIONS
                    },
                    "ux_assertions": {name: False for name in module.EVIDENCE.UX_ASSERTIONS},
                    "request": {
                        "method": "",
                        "path": "",
                        "submit_false": False,
                        "description_matches": False,
                        "customer_name_matches": False,
                        "files_selected": -1,
                    },
                    "response": {"status": 0, "ok": False},
                    "failed_responses": [],
                    "diagnostics": [],
                    "console_errors": [],
                    "page_errors": [],
                    "screenshots": [],
                    "errors": [],
                    "blockers": ["browser_execution_blocked:early"],
                }
            ),
            encoding="utf-8",
        )
        return 3, 0

    monkeypatch.setattr(module, "run_browser", fake_browser)
    monkeypatch.setenv("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "42" * 32)
    source_manifest = tmp_path / "ref-manifest.json"
    source_manifest.write_text("{}", encoding="utf-8")
    out_dir = tmp_path / "out"
    out_dir.mkdir()

    return_code, packet_path = module.run_store(
        store="ref",
        wp="fake-wp",
        base_url="http://localhost:8082",
        run_stamp=run_stamp,
        source_manifest_path=source_manifest,
        source_probe={"identity": identity},
        context={"aggregate_run_id": "run", "context_sha256": "sha256:" + "6" * 64},
        out_dir=out_dir,
        delay_seconds=0,
        repo_root=tmp_path,
    )
    packet = json.loads(packet_path.read_text(encoding="utf-8"))
    assert return_code == 3
    assert packet["mutation_boundary"] == expected_boundary
    assert packet["deterministic_status"] == "blocked"
    assert packet["status"] == "blocked"
