#!/usr/bin/env python3
"""Focused regression checks for the plugin-active settings browser gate."""

from __future__ import annotations

import hashlib
import json
import os
import signal
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO))

from tools.woopayments_test_runner import adapt_wp_runner_arguments


SCRIPT = REPO / "tools/woopayments-merge/plugin-active-settings-gate.sh"
BROWSER_DRIVER = REPO / "tools/woopayments-merge/plugin-active-settings.playwriter.mjs"
TARGET_WP = "docker exec -i target-cli-1 wp --allow-root --user=1"
TARGET_URL = "http://store8889.localhost:8889"
SETTINGS_URL = f"{TARGET_URL}/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments"
MU_PLUGIN_SUFFIX = ".disabled-by-woopayments-merge"
NATIVE_MU_PLUGIN_A = "/wp-content/mu-plugins/native-payments-a.php"
NATIVE_MU_PLUGIN_B = "/wp-content/mu-plugins/native-payments-b.php"


def native_mu_plugin_contents(label: str) -> str:
    return f"<?php // {label}: woocommerce_native_payments_enabled"


def prepare_gate_command(
    args: tuple[str, ...], env: dict[str, str] | None
) -> tuple[list[str], dict[str, str]]:
    process_env = os.environ.copy()
    if env:
        process_env.update(env)
    runner_role = "target"
    for index, argument in enumerate(args):
        if argument == "--runner-role" and index + 1 < len(args):
            runner_role = args[index + 1]
        elif argument.startswith("--runner-role="):
            runner_role = argument.partition("=")[2]
    command_args, process_env = adapt_wp_runner_arguments(
        list(args),
        process_env,
        ref_flag="--target" if runner_role == "reference" else "--unused-ref",
        target_flag="--target" if runner_role == "target" else "--unused-target",
    )
    return ["bash", str(SCRIPT), *command_args], process_env


def run_gate(
    *args: str,
    env: dict[str, str] | None = None,
    start_new_session: bool = False,
) -> subprocess.CompletedProcess[str]:
    command, process_env = prepare_gate_command(args, env)
    return subprocess.run(
        command,
        cwd=REPO,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        env=process_env,
        check=False,
        start_new_session=start_new_session,
    )


def write_executable(path: Path, source: str) -> None:
    path.write_text(source, encoding="utf-8")
    path.chmod(0o755)


def make_fake_wp(path: Path, *, active: bool = True, home_url: str = TARGET_URL) -> None:
    active_exit = 0 if active else 1
    write_executable(
        path,
        f"""#!/usr/bin/env bash
set -euo pipefail
if [ -n "${{FAKE_WP_INVOCATIONS:-}}" ]; then
\tprintf '%s\\n' "$*" >> "$FAKE_WP_INVOCATIONS"
fi
if [ "$1" = "plugin" ] && [ "$2" = "is-active" ] && [ "$3" = "woocommerce-payments" ]; then
\texit {active_exit}
fi
if [ "$1" = "option" ] && [ "$2" = "get" ] && [ "$3" = "home" ]; then
\tprintf '%s\\n' {json.dumps(home_url)}
\texit 0
fi
printf 'unexpected fake wp args: %s\\n' "$*" >&2
exit 1
""",
    )


def make_transactional_fake_wp(
    path: Path,
    *,
    initial_state: dict[str, object],
    mutation_mode: str = "success",
    cleanup_mode: str = "success",
    tamper_disabled_after_mutation: bool = False,
    staged_runtime_owner: str = "plugin",
    restored_runtime_owner: str = "native",
) -> None:
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import base64
import hashlib
import json
import os
import pathlib
import sys

INITIAL_STATE = {initial_state!r}
MUTATION_MODE = {mutation_mode!r}
CLEANUP_MODE = {cleanup_mode!r}
TAMPER_DISABLED_AFTER_MUTATION = {tamper_disabled_after_mutation!r}
STAGED_RUNTIME_OWNER = {staged_runtime_owner!r}
RESTORED_RUNTIME_OWNER = {restored_runtime_owner!r}
SUFFIX = ".disabled-by-woopayments-merge"
MARKER = "woocommerce_native_payments_enabled"

state_path = pathlib.Path(os.environ["FAKE_WP_STATE"])
invocations_path = pathlib.Path(os.environ["FAKE_WP_INVOCATIONS"])


def write_state(state):
    state_path.write_text(json.dumps(state, indent=2, sort_keys=True) + "\\n", encoding="utf-8")


def load_state():
    if not state_path.exists():
        write_state(INITIAL_STATE)
    return json.loads(state_path.read_text(encoding="utf-8"))


def decode_payload(value):
    return json.loads(base64.b64decode(value).decode("utf-8"))


def file_hash(contents):
    return hashlib.sha256(contents.encode("utf-8")).hexdigest()


def candidates(state):
    result = []
    for candidate_path, contents in sorted(state["files"].items()):
        if not candidate_path.endswith(".php") or MARKER not in contents:
            continue
        result.append(
            {{
                "path": candidate_path,
                "disabled_path": candidate_path + SUFFIX,
                "sha256": file_hash(contents),
            }}
        )
    return result


args = sys.argv[1:]
php_source = sys.stdin.read() if args[:2] == ["eval-file", "-"] else ""
operation = args[2] if len(args) > 2 and args[:2] == ["eval-file", "-"] else ""
payload = decode_payload(args[3]) if operation in {{"mutate-plugin-active", "restore-plugin-active"}} else None
with invocations_path.open("a", encoding="utf-8") as stream:
    stream.write(
        json.dumps(
            {{
                "argv": args,
                "operation": operation,
                "payload": payload,
                "php_source": php_source,
            }},
            sort_keys=True,
        )
        + "\\n"
    )

state = load_state()

if args[:3] == ["plugin", "is-active", "woocommerce-payments"]:
    raise SystemExit(0 if state["plugin_active"] else 1)

if args[:3] == ["option", "get", "home"]:
    print({TARGET_URL!r})
    raise SystemExit(0)

if operation == "probe-runtime-owner":
    runtime_owner = STAGED_RUNTIME_OWNER if state["plugin_active"] else RESTORED_RUNTIME_OWNER
    print(
        json.dumps(
            {{
                "success": True,
                "mode": operation,
                "errors": [],
                "runtime_owner": runtime_owner,
            }},
            sort_keys=True,
        )
    )
    raise SystemExit(0)

if operation == "snapshot-plugin-active":
    candidate_entries = candidates(state)
    errors = [
        "Native payments mu-plugin destination already exists: " + entry["disabled_path"]
        for entry in candidate_entries
        if entry["disabled_path"] in state["files"]
    ]
    if not candidate_entries:
        errors.append("No native payments mu-plugin candidates were found for staging.")
    print(
        json.dumps(
            {{
                "schema": "woopayments_plugin_active_fixture_snapshot.v1",
                "success": not errors,
                "mode": "snapshot-plugin-active",
                "errors": errors,
                "was_plugin_active": state["plugin_active"],
                "candidate_mu_plugins": candidate_entries,
            }},
            sort_keys=True,
        )
    )
    raise SystemExit(0)

if operation == "mutate-plugin-active":
    errors = []
    for entry in payload.get("candidate_mu_plugins", []):
        source = entry["path"]
        destination = entry["disabled_path"]
        if source not in state["files"]:
            errors.append("Native payments mu-plugin disappeared before mutation: " + source)
        elif file_hash(state["files"][source]) != entry["sha256"]:
            errors.append("Native payments mu-plugin changed before mutation: " + source)
        elif destination in state["files"]:
            errors.append("Native payments mu-plugin destination already exists: " + destination)
    if errors:
        print(json.dumps({{"success": False, "mode": operation, "errors": errors}}, sort_keys=True))
        raise SystemExit(0)

    for entry in payload.get("candidate_mu_plugins", []):
        state["files"][entry["disabled_path"]] = state["files"].pop(entry["path"])
    state["plugin_active"] = True
    if TAMPER_DISABLED_AFTER_MUTATION and payload.get("candidate_mu_plugins"):
        first = payload["candidate_mu_plugins"][0]
        state["files"][first["disabled_path"]] += "\\npost-mutation tamper"
    write_state(state)

    if MUTATION_MODE == "command_failure":
        print("mutation command failed after changing state", file=sys.stderr)
        raise SystemExit(19)
    if MUTATION_MODE == "no_json":
        print("mutation completed without JSON")
        raise SystemExit(0)
    print(json.dumps({{"success": True, "mode": operation, "errors": [], "wcpay_plugin_active": True}}, sort_keys=True))
    raise SystemExit(0)

if operation == "restore-plugin-active":
    if CLEANUP_MODE == "command_failure":
        print("restore command failed", file=sys.stderr)
        raise SystemExit(23)
    if CLEANUP_MODE == "reported_failure":
        print(json.dumps({{"success": False, "mode": operation, "errors": ["injected cleanup failure"]}}))
        raise SystemExit(0)

    errors = []
    for entry in reversed(payload.get("candidate_mu_plugins", [])):
        source = entry["path"]
        destination = entry["disabled_path"]
        source_exists = source in state["files"]
        destination_exists = destination in state["files"]
        if source_exists and destination_exists:
            errors.append("Both native payments mu-plugin paths exist: " + source)
        elif not source_exists and not destination_exists:
            errors.append("Native payments mu-plugin is missing from both paths: " + source)
        elif source_exists:
            if file_hash(state["files"][source]) != entry["sha256"]:
                errors.append("Restored native payments mu-plugin hash mismatch: " + source)
        elif file_hash(state["files"][destination]) != entry["sha256"]:
            errors.append("Disabled native payments mu-plugin hash mismatch: " + destination)
        else:
            state["files"][source] = state["files"].pop(destination)

    state["plugin_active"] = bool(payload.get("was_plugin_active"))
    for entry in payload.get("candidate_mu_plugins", []):
        source = entry["path"]
        destination = entry["disabled_path"]
        if source not in state["files"]:
            errors.append("Native payments mu-plugin was not restored: " + source)
        elif file_hash(state["files"][source]) != entry["sha256"]:
            errors.append("Native payments mu-plugin final hash mismatch: " + source)
        if destination in state["files"]:
            errors.append("Disabled native payments mu-plugin path remains: " + destination)
    write_state(state)
    print(json.dumps({{"success": not errors, "mode": operation, "errors": errors, "wcpay_plugin_active": state["plugin_active"]}}, sort_keys=True))
    raise SystemExit(0)

print("unexpected fake wp args: " + " ".join(args), file=sys.stderr)
raise SystemExit(1)
""",
    )


def read_json_lines(path: Path) -> list[dict[str, object]]:
    return [
        json.loads(line)
        for line in path.read_text(encoding="utf-8").splitlines()
        if line
    ]


def read_fake_wp_state(path: Path) -> dict[str, object]:
    return json.loads(path.read_text(encoding="utf-8"))


def make_fake_playwriter(
    path: Path,
    *,
    duplicate_store_error: bool = False,
    plugin_provenance: bool = True,
    status: str = "pass",
    failed_responses: list[dict[str, object]] | None = None,
    blocked_responses: list[dict[str, object]] | None = None,
    blockers: list[str] | None = None,
    failures: list[str] | None = None,
    native_settings_assets: bool = False,
) -> None:
    duplicate_errors = (
        '[{"type":"error","text":"Store \\"wc/payments/settings\\" is already registered"}]'
        if duplicate_store_error
        else "[]"
    )
    if failures is None:
        failures = []
        if status == "fail":
            failures.append("failed browser responses were captured")
    if native_settings_assets:
        status = "fail"
        failures.append("native WooPayments settings assets were observed")
    write_executable(
        path,
        f"""#!/usr/bin/env python3
import json
import os
import pathlib
import sys

invocation_path = pathlib.Path(os.environ["FAKE_PLAYWRITER_INVOCATIONS"])
with invocation_path.open("a", encoding="utf-8") as stream:
    stream.write(json.dumps({{
        "argv": sys.argv[1:],
        "env": {{
            "target_url": os.environ.get("PLUGIN_SETTINGS_TARGET_URL", ""),
            "settings_url": os.environ.get("PLUGIN_SETTINGS_SETTINGS_URL", ""),
            "evidence_path": os.environ.get("PLUGIN_SETTINGS_EVIDENCE_PATH", ""),
        }},
    }}, sort_keys=True) + "\\n")

if "-e" in sys.argv:
    sys.exit(0)

if os.environ.get("FAKE_GATE_SIGNAL"):
    os.killpg(os.getpgrp(), int(os.environ["FAKE_GATE_SIGNAL"]))
if os.environ.get("FAKE_CONTEXT_FILE") and os.environ.get("FAKE_CONTEXT_REPLACEMENT_SHA256"):
    pathlib.Path(os.environ["FAKE_CONTEXT_FILE"]).write_text(
        json.dumps({{"context_sha256": os.environ["FAKE_CONTEXT_REPLACEMENT_SHA256"]}}) + "\\n",
        encoding="utf-8",
    )

payload = {{
    "schema": "woopayments_plugin_active_settings_browser_evidence.v1",
    "status": {json.dumps(status)},
    "target_url": os.environ["PLUGIN_SETTINGS_TARGET_URL"],
    "settings_url": os.environ["PLUGIN_SETTINGS_SETTINGS_URL"],
    "plugin_active": True,
    "authenticated_wp_admin": True,
    "settings_screen_present": True,
    "plugin_settings_assets_present": {plugin_provenance!r},
    "plugin_settings_global_present": {plugin_provenance!r},
    "plugin_settings_script_urls": {json.dumps([f"{TARGET_URL}/wp-content/plugins/woocommerce-payments/dist/settings.js"] if plugin_provenance else [])},
    "plugin_settings_style_urls": {json.dumps([f"{TARGET_URL}/wp-content/plugins/woocommerce-payments/dist/settings.css"] if plugin_provenance else [])},
    "native_settings_asset_urls": {json.dumps([f"{TARGET_URL}/wp-content/plugins/woocommerce/assets/client/admin/chunks/settings-payments-woopayments.js"] if native_settings_assets else [])},
    "duplicate_store_errors": {duplicate_errors},
    "fatal_console_errors": [],
    "failed_responses": {json.dumps(failed_responses or [])},
    "blocked_responses": {json.dumps(blocked_responses or [])},
    "failures": {json.dumps(failures)},
    "blockers": {json.dumps(blockers or [])},
}}

evidence_path = pathlib.Path(os.environ["PLUGIN_SETTINGS_EVIDENCE_PATH"])
evidence_path.parent.mkdir(parents=True, exist_ok=True)
payload["screenshot_path"] = str(evidence_path.parent / "plugin-active-settings.png")
(evidence_path.parent / "plugin-active-settings.png").write_bytes(b"fake screenshot")
evidence_path.write_text(json.dumps(payload, sort_keys=True) + "\\n", encoding="utf-8")
""",
    )


def run_fixture_gate(
    tmp_path: Path,
    *,
    initial_state: dict[str, object],
    mutation_mode: str = "success",
    cleanup_mode: str = "success",
    tamper_disabled_after_mutation: bool = False,
    staged_runtime_owner: str = "plugin",
    restored_runtime_owner: str = "native",
    duplicate_store_error: bool = False,
    preflight_only: bool = False,
    gate_signal: int | None = None,
    context_file: Path | None = None,
    context_replacement_sha256: str | None = None,
) -> tuple[subprocess.CompletedProcess[str], dict[str, Path]]:
    fake_wp = tmp_path / "target-wp"
    fake_playwriter = tmp_path / "fake-playwriter"
    paths = {
        "wp_invocations": tmp_path / "wp-invocations.jsonl",
        "wp_state": tmp_path / "wp-state.json",
        "playwriter_invocations": tmp_path / "playwriter-invocations.jsonl",
        "out_dir": tmp_path / "evidence",
    }
    make_transactional_fake_wp(
        fake_wp,
        initial_state=initial_state,
        mutation_mode=mutation_mode,
        cleanup_mode=cleanup_mode,
        tamper_disabled_after_mutation=tamper_disabled_after_mutation,
        staged_runtime_owner=staged_runtime_owner,
        restored_runtime_owner=restored_runtime_owner,
    )
    make_fake_playwriter(fake_playwriter, duplicate_store_error=duplicate_store_error)

    args = [
        "--target",
        str(fake_wp),
        "--target-url",
        TARGET_URL,
        "--playwriter-session",
        "unit",
        "--stage-plugin-active-fixture",
        "--out-dir",
        str(paths["out_dir"]),
    ]
    if preflight_only:
        args.append("--preflight-only")
    if context_file:
        args.extend(("--context-file", str(context_file)))

    env = {
        **os.environ,
        "PLAYWRITER_BIN": str(fake_playwriter),
        "FAKE_PLAYWRITER_INVOCATIONS": str(paths["playwriter_invocations"]),
        "FAKE_WP_INVOCATIONS": str(paths["wp_invocations"]),
        "FAKE_WP_STATE": str(paths["wp_state"]),
    }
    if gate_signal is not None:
        env["FAKE_GATE_SIGNAL"] = str(gate_signal)
    if context_file and context_replacement_sha256:
        env["FAKE_CONTEXT_FILE"] = str(context_file)
        env["FAKE_CONTEXT_REPLACEMENT_SHA256"] = context_replacement_sha256

    result = run_gate(
        *args,
        env=env,
        start_new_session=gate_signal is not None,
    )
    return result, paths


def test_usage_requires_target_and_target_url() -> None:
    result = run_gate()

    assert result.returncode == 2
    assert "usage:" in result.stderr
    assert "--target" in result.stderr
    assert "--target-url" in result.stderr


def test_print_plan_describes_settings_regression_gate() -> None:
    result = run_gate(
        "--target",
        TARGET_WP,
        "--target-url",
        TARGET_URL,
        "--print-plan",
    )

    assert result.returncode == 0, result.stderr
    payload = json.loads(result.stdout)

    assert payload["schema"] == "woopayments_plugin_active_settings_gate_plan.v1"
    assert payload["target_wp"] == TARGET_WP
    assert payload["target_url"] == TARGET_URL
    assert payload["settings_url"] == SETTINGS_URL
    assert payload["browser_driver"].endswith("plugin-active-settings.playwriter.mjs")
    assert payload["checks"] == [
        "woocommerce-payments plugin is active before browser run",
        "authenticated wp-admin settings page renders",
        "WooPayments settings screen is present",
        "standalone WooPayments settings script and localized global are present",
        "no duplicate wc/payments/settings store registration error",
    ]


def test_browser_driver_checks_fatal_tokens_before_benign_log_allowances() -> None:
    source = BROWSER_DRIVER.read_text(encoding="utf-8")
    classifier = source[
        source.index("function isFatalConsoleError") : source.index("function decodedUrlText")
    ]

    assert classifier.index("/uncaught|fatal|exception|typeerror|referenceerror/i") < classifier.index(
        "/JQMIGRATE|Permissions policy violation: unload/i"
    )


def test_print_plan_rejects_remote_target_runner_before_invocation() -> None:
    result = run_gate(
        "--target",
        TARGET_WP + " --http=https://store.wordpress.com",
        "--target-url",
        TARGET_URL,
        "--print-plan",
    )

    assert result.returncode == 2
    assert "unsafe target WP runner" in result.stderr


def test_reference_runner_role_accepts_the_aggregate_approved_reference() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-reference-gate-test-") as tmp:
        fake_wp = Path(tmp) / "reference-wp"
        make_fake_wp(fake_wp, home_url="http://localhost:8082")

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            "http://localhost:8082",
            "--runner-role",
            "reference",
            "--preflight-only",
        )

        assert result.returncode == 0, result.stderr


def test_full_gate_can_use_playwright_runner_without_playwriter_session() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_runner = tmp_path / "fake-playwright-runner"
        invocations_path = tmp_path / "playwright-runner-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_runner)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "BROWSER_RUNNER": "playwright",
                "PLAYWRIGHT_SCRIPT_RUNNER_BIN": str(fake_runner),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            },
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 1
        assert str(REPO / "tools/woopayments-merge/plugin-active-settings.playwriter.mjs") in invocations[0]["argv"]
        assert "-s" not in invocations[0]["argv"]
        assert "-e" not in invocations[0]["argv"]
        assert invocations[0]["env"]["target_url"] == TARGET_URL
        assert invocations[0]["env"]["settings_url"] == SETTINGS_URL

        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "pass"


def test_full_gate_invokes_playwriter_driver_and_validates_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter)

        env = {
            **os.environ,
            "PLAYWRITER_BIN": str(fake_playwriter),
            "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
        }

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env=env,
        )

        assert result.returncode == 0, result.stderr
        invocations = [
            json.loads(line)
            for line in invocations_path.read_text(encoding="utf-8").splitlines()
            if line
        ]
        assert len(invocations) == 2
        assert "-e" in invocations[0]["argv"]
        assert "state.pluginActiveSettingsConfig" in " ".join(invocations[0]["argv"])
        assert TARGET_URL in " ".join(invocations[0]["argv"])
        assert SETTINGS_URL in " ".join(invocations[0]["argv"])
        assert "-s" in invocations[1]["argv"]
        assert "unit" in invocations[1]["argv"]
        assert str(REPO / "tools/woopayments-merge/plugin-active-settings.playwriter.mjs") in invocations[1]["argv"]
        assert invocations[1]["env"]["target_url"] == TARGET_URL
        assert invocations[1]["env"]["settings_url"] == SETTINGS_URL

        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["schema"] == "woopayments_plugin_active_settings_gate_rollup.v1"
        assert rollup["status"] == "pass"
        assert rollup["evidence"]["settings_screen_present"] is True
        assert rollup["evidence"]["duplicate_store_errors"] == []


def test_full_gate_can_stage_and_restore_plugin_active_fixture() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        contents_a = native_mu_plugin_contents("fixture-a")
        contents_b = native_mu_plugin_contents("fixture-b")
        unrelated_path = "/wp-content/mu-plugins/unrelated.php"
        unrelated_disabled_path = "/wp-content/mu-plugins/orphan.php" + MU_PLUGIN_SUFFIX
        initial_state = {
            "plugin_active": False,
            "files": {
                NATIVE_MU_PLUGIN_A: contents_a,
                NATIVE_MU_PLUGIN_B: contents_b,
                unrelated_path: "<?php // unrelated",
                unrelated_disabled_path: native_mu_plugin_contents("not-owned"),
            },
        }

        result, paths = run_fixture_gate(tmp_path, initial_state=initial_state)

        assert result.returncode == 0, result.stderr
        invocations = read_json_lines(paths["wp_invocations"])
        lifecycle_invocations = [item for item in invocations if item["operation"]]
        operations = [item["operation"] for item in lifecycle_invocations]
        assert operations == [
            "snapshot-plugin-active",
            "mutate-plugin-active",
            "probe-runtime-owner",
            "restore-plugin-active",
            "probe-runtime-owner",
        ]
        snapshot = json.loads(
            (paths["out_dir"] / "plugin-active-settings-snapshot.json").read_text(encoding="utf-8")
        )
        assert snapshot["was_plugin_active"] is False
        assert snapshot["candidate_mu_plugins"] == [
            {
                "path": NATIVE_MU_PLUGIN_A,
                "disabled_path": NATIVE_MU_PLUGIN_A + MU_PLUGIN_SUFFIX,
                "sha256": hashlib.sha256(contents_a.encode("utf-8")).hexdigest(),
            },
            {
                "path": NATIVE_MU_PLUGIN_B,
                "disabled_path": NATIVE_MU_PLUGIN_B + MU_PLUGIN_SUFFIX,
                "sha256": hashlib.sha256(contents_b.encode("utf-8")).hexdigest(),
            },
        ]
        assert lifecycle_invocations[1]["payload"] == snapshot
        assert lifecycle_invocations[3]["payload"] == snapshot
        assert read_fake_wp_state(paths["wp_state"]) == initial_state

        mutation_source = lifecycle_invocations[1]["php_source"]
        restore_source = lifecycle_invocations[3]["php_source"]
        assert "glob(" not in mutation_source
        assert "glob(" not in restore_source
        assert "woocommerce_native_payments_enabled" not in mutation_source
        assert "woocommerce_native_payments_enabled" not in restore_source
        assert "hash_file" in mutation_source
        assert "hash_file" in restore_source
        assert "wp_normalize_path" in mutation_source
        assert "wp_normalize_path" in restore_source
        assert "dirname( $normalized_path ) !== $mu_dir" in mutation_source
        assert "dirname( $normalized_path ) !== $mu_dir" in restore_source

        rollup = json.loads(
            (paths["out_dir"] / "plugin-active-settings-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["status"] == "pass"
        assert rollup["evidence"]["settings_screen_present"] is True


def test_fixture_blocks_wrong_staged_runtime_owner_and_restores() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("wrong-owner")},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            staged_runtime_owner="native",
        )

        assert result.returncode == 3
        assert "Payments runtime owner is native; expected plugin." in result.stderr
        stage = json.loads(
            (paths["out_dir"] / "plugin-active-settings-stage.json").read_text(encoding="utf-8")
        )
        assert stage["success"] is False
        assert stage["runtime_owner"] == "native"
        assert read_fake_wp_state(paths["wp_state"]) == initial_state
        assert not paths["playwriter_invocations"].exists()


def test_staged_gate_finalizes_context_bound_packet_after_restore() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        context_sha256 = "sha256:" + "a" * 64
        context_file = tmp_path / "critical-flow-context.json"
        context_file.write_text(json.dumps({"context_sha256": context_sha256}) + "\n", encoding="utf-8")
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("context-packet")},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            context_file=context_file,
        )

        assert result.returncode == 0, result.stderr
        rollup = json.loads(
            (paths["out_dir"] / "plugin-active-settings-gate.json").read_text(encoding="utf-8")
        )
        assert rollup["context_sha256"] == context_sha256
        artifacts = {Path(item["path"]).name: item for item in rollup["artifacts"]}
        assert set(artifacts) == {
            "plugin-active-settings-blockers.txt",
            "plugin-active-settings-failures.txt",
            "plugin-active-settings-restore.json",
            "plugin-active-settings-snapshot.json",
            "plugin-active-settings-stage.json",
            "plugin-active-settings.json",
            "plugin-active-settings.playwriter.log",
            "plugin-active-settings.png",
        }
        for artifact in artifacts.values():
            artifact_path = Path(artifact["path"])
            assert artifact["sha256"] == f"sha256:{hashlib.sha256(artifact_path.read_bytes()).hexdigest()}"
        restore = json.loads(
            (paths["out_dir"] / "plugin-active-settings-restore.json").read_text(encoding="utf-8")
        )
        snapshot_sha256 = "sha256:" + hashlib.sha256(
            (paths["out_dir"] / "plugin-active-settings-snapshot.json").read_bytes()
        ).hexdigest()
        stage = json.loads(
            (paths["out_dir"] / "plugin-active-settings-stage.json").read_text(encoding="utf-8")
        )
        assert stage == {
            "success": True,
            "mode": "mutate-plugin-active",
            "errors": [],
            "wcpay_plugin_active": True,
            "runtime_owner": "plugin",
            "snapshot_sha256": snapshot_sha256,
        }
        assert restore == {
            "success": True,
            "mode": "restore-plugin-active",
            "errors": [],
            "wcpay_plugin_active": False,
            "runtime_owner": "native",
            "snapshot_sha256": snapshot_sha256,
        }
        assert read_fake_wp_state(paths["wp_state"]) == initial_state


def test_staged_gate_rejects_context_changed_during_browser_capture() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        context_file = tmp_path / "critical-flow-context.json"
        context_file.write_text(
            json.dumps({"context_sha256": "sha256:" + "a" * 64}) + "\n", encoding="utf-8"
        )
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("context-drift")},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            context_file=context_file,
            context_replacement_sha256="sha256:" + "b" * 64,
        )

        assert result.returncode == 70
        assert "BLOCKED: plugin-active evidence packet finalization failed" in result.stderr
        assert "aggregate context changed during plugin-active capture" in result.stderr
        assert read_fake_wp_state(paths["wp_state"]) == initial_state


def test_fixture_refuses_preexisting_destination_collision_without_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        destination = NATIVE_MU_PLUGIN_A + MU_PLUGIN_SUFFIX
        initial_state = {
            "plugin_active": False,
            "files": {
                NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("candidate"),
                destination: native_mu_plugin_contents("preexisting-destination"),
            },
        }

        result, paths = run_fixture_gate(tmp_path, initial_state=initial_state)

        assert result.returncode == 3
        assert f"destination already exists: {destination}" in result.stderr
        operations = [
            item["operation"]
            for item in read_json_lines(paths["wp_invocations"])
            if item["operation"]
        ]
        assert operations == ["snapshot-plugin-active"]
        assert read_fake_wp_state(paths["wp_state"]) == initial_state
        assert not paths["playwriter_invocations"].exists()


def test_fixture_blocks_empty_native_candidate_set_before_mutation() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": False,
            "files": {"/wp-content/mu-plugins/unrelated.php": "<?php // unrelated"},
        }

        result, paths = run_fixture_gate(tmp_path, initial_state=initial_state)

        assert result.returncode == 3
        assert "No native payments mu-plugin candidates were found" in result.stderr
        operations = [
            item["operation"]
            for item in read_json_lines(paths["wp_invocations"])
            if item["operation"]
        ]
        assert operations == ["snapshot-plugin-active"]
        assert read_fake_wp_state(paths["wp_state"]) == initial_state
        assert not paths["playwriter_invocations"].exists()


def assert_mutation_failure_is_recovered(mutation_mode: str) -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents(mutation_mode)},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            mutation_mode=mutation_mode,
        )

        assert result.returncode == 3
        assert "could not mutate plugin-active fixture" in result.stderr
        operations = [
            item["operation"]
            for item in read_json_lines(paths["wp_invocations"])
            if item["operation"]
        ]
        assert operations == [
            "snapshot-plugin-active",
            "mutate-plugin-active",
            "restore-plugin-active",
            "probe-runtime-owner",
        ]
        assert read_fake_wp_state(paths["wp_state"]) == initial_state
        assert not paths["playwriter_invocations"].exists()


def test_fixture_recovers_when_mutation_command_fails_after_mutating() -> None:
    assert_mutation_failure_is_recovered("command_failure")


def test_fixture_recovers_when_mutation_emits_no_json_after_mutating() -> None:
    assert_mutation_failure_is_recovered("no_json")


def test_fixture_restores_original_active_state_after_browser_failure() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": True,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("active-before")},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            duplicate_store_error=True,
        )

        assert result.returncode == 1
        assert "duplicate wc/payments/settings store registration error" in result.stderr
        assert read_fake_wp_state(paths["wp_state"]) == initial_state


def assert_cleanup_failure_exits_70(cleanup_mode: str) -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents(cleanup_mode)},
        }

        result, _paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            cleanup_mode=cleanup_mode,
            preflight_only=True,
        )

        assert result.returncode == 70
        assert "BLOCKED: plugin-active fixture cleanup failed" in result.stderr
        assert "cleanup failed" in result.stderr
        assert "restore warning" not in result.stderr


def test_fixture_cleanup_command_failure_exits_70() -> None:
    assert_cleanup_failure_exits_70("command_failure")


def test_fixture_cleanup_reported_failure_exits_70() -> None:
    assert_cleanup_failure_exits_70("reported_failure")


def test_fixture_hash_drift_during_cleanup_fails_closed_without_deleting_files() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        unrelated_path = "/wp-content/mu-plugins/unrelated.php"
        initial_state = {
            "plugin_active": False,
            "files": {
                NATIVE_MU_PLUGIN_A: native_mu_plugin_contents("hash-drift"),
                unrelated_path: "<?php // unrelated",
            },
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            tamper_disabled_after_mutation=True,
            preflight_only=True,
        )

        assert result.returncode == 70
        assert "hash mismatch" in result.stderr
        final_state = read_fake_wp_state(paths["wp_state"])
        assert NATIVE_MU_PLUGIN_A not in final_state["files"]
        assert NATIVE_MU_PLUGIN_A + MU_PLUGIN_SUFFIX in final_state["files"]
        assert final_state["files"][unrelated_path] == initial_state["files"][unrelated_path]


def assert_fixture_restores_after_signal(signal_number: int) -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        initial_state = {
            "plugin_active": False,
            "files": {NATIVE_MU_PLUGIN_A: native_mu_plugin_contents(str(signal_number))},
        }

        result, paths = run_fixture_gate(
            tmp_path,
            initial_state=initial_state,
            gate_signal=signal_number,
        )

        assert result.returncode == 128 + signal_number, result.stderr
        assert read_fake_wp_state(paths["wp_state"]) == initial_state
        operations = [
            item["operation"]
            for item in read_json_lines(paths["wp_invocations"])
            if item["operation"]
        ]
        assert operations[-2:] == ["restore-plugin-active", "probe-runtime-owner"]


def test_fixture_restores_after_hup() -> None:
    assert_fixture_restores_after_signal(signal.SIGHUP)


def test_fixture_restores_after_int() -> None:
    assert_fixture_restores_after_signal(signal.SIGINT)


def test_fixture_restores_after_term() -> None:
    assert_fixture_restores_after_signal(signal.SIGTERM)


def test_gate_blocks_when_woopayments_plugin_is_not_active() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        invocations_path = tmp_path / "playwriter-invocations.jsonl"

        make_fake_wp(fake_wp, active=False)
        make_fake_playwriter(fake_playwriter)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(tmp_path / "evidence"),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(invocations_path),
            },
        )

        assert result.returncode == 3
        assert "WooPayments plugin is not active on the target store" in result.stderr
        assert not invocations_path.exists()


def test_gate_fails_duplicate_settings_store_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter, duplicate_store_error=True)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "duplicate wc/payments/settings store registration error" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert any("duplicate wc/payments/settings store registration error" in item for item in rollup["failures"])


def test_gate_rejects_native_only_settings_evidence_without_plugin_assets() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter, plugin_provenance=False)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "standalone WooPayments settings assets were not observed" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"


def test_gate_fails_native_settings_asset_evidence() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter, native_settings_assets=True)

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "native WooPayments settings assets were observed" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert "native WooPayments settings assets were observed" in rollup["failures"]
        assert "browser failure: native WooPayments settings assets were observed" not in rollup["failures"]
        assert "native WooPayments settings assets were observed" in BROWSER_DRIVER.read_text(encoding="utf-8")


def test_gate_fails_generic_failed_browser_responses() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(
            fake_playwriter,
            status="fail",
            failed_responses=[{"status": 500, "url": f"{TARGET_URL}/wp-json/custom/fatal"}],
        )

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "failed browser responses were captured" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["evidence"]["failed_responses"] == [{"status": 500, "url": f"{TARGET_URL}/wp-json/custom/fatal"}]


def test_gate_rejects_failed_status_without_failure_details() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"

        make_fake_wp(fake_wp)
        make_fake_playwriter(fake_playwriter, status="fail", failures=[])

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 1
        assert "browser evidence reported failure status" in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "fail"
        assert rollup["evidence"]["status"] == "fail"


def test_gate_blocks_optional_deposits_overview_wiring_response_after_settings_render() -> None:
    with tempfile.TemporaryDirectory(prefix="plugin-settings-gate-test-") as tmp:
        tmp_path = Path(tmp)
        fake_wp = tmp_path / "target-wp"
        fake_playwriter = tmp_path / "fake-playwriter"
        out_dir = tmp_path / "evidence"
        blocker = "optional WooPayments deposits overview request was unavailable in the local plugin-active fixture"
        blocked_response = {
            "status": 500,
            "url": f"{TARGET_URL}/index.php?rest_route=%2Fwc%2Fv3%2Fpayments%2Fdeposits%2Foverview-all&_locale=user",
        }

        make_fake_wp(fake_wp)
        make_fake_playwriter(
            fake_playwriter,
            status="blocked",
            blocked_responses=[blocked_response],
            blockers=[blocker],
        )

        result = run_gate(
            "--target",
            str(fake_wp),
            "--target-url",
            TARGET_URL,
            "--playwriter-session",
            "unit",
            "--out-dir",
            str(out_dir),
            env={
                **os.environ,
                "PLAYWRITER_BIN": str(fake_playwriter),
                "FAKE_PLAYWRITER_INVOCATIONS": str(tmp_path / "playwriter-invocations.jsonl"),
            },
        )

        assert result.returncode == 3
        assert f"BLOCKED: {blocker}" in result.stderr
        assert "FAIL:" not in result.stderr
        rollup = json.loads((out_dir / "plugin-active-settings-gate.json").read_text(encoding="utf-8"))
        assert rollup["status"] == "blocked"
        assert rollup["blockers"] == [blocker]
        assert rollup["evidence"]["status"] == "blocked"
        assert rollup["evidence"]["blocked_responses"] == [blocked_response]
        assert rollup["evidence"]["settings_screen_present"] is True
        assert rollup["evidence"]["duplicate_store_errors"] == []


def main() -> None:
    tests = [
        test_usage_requires_target_and_target_url,
        test_print_plan_describes_settings_regression_gate,
        test_print_plan_rejects_remote_target_runner_before_invocation,
        test_reference_runner_role_accepts_the_aggregate_approved_reference,
        test_full_gate_can_use_playwright_runner_without_playwriter_session,
        test_full_gate_invokes_playwriter_driver_and_validates_evidence,
        test_full_gate_can_stage_and_restore_plugin_active_fixture,
        test_fixture_blocks_wrong_staged_runtime_owner_and_restores,
        test_fixture_refuses_preexisting_destination_collision_without_mutation,
        test_fixture_recovers_when_mutation_command_fails_after_mutating,
        test_fixture_recovers_when_mutation_emits_no_json_after_mutating,
        test_fixture_restores_original_active_state_after_browser_failure,
        test_fixture_cleanup_command_failure_exits_70,
        test_fixture_cleanup_reported_failure_exits_70,
        test_fixture_hash_drift_during_cleanup_fails_closed_without_deleting_files,
        test_fixture_restores_after_hup,
        test_fixture_restores_after_int,
        test_fixture_restores_after_term,
        test_gate_blocks_when_woopayments_plugin_is_not_active,
        test_gate_fails_duplicate_settings_store_evidence,
        test_gate_rejects_native_only_settings_evidence_without_plugin_assets,
        test_gate_fails_generic_failed_browser_responses,
        test_gate_rejects_failed_status_without_failure_details,
        test_gate_blocks_optional_deposits_overview_wiring_response_after_settings_render,
    ]
    for test in tests:
        test()
        print(f"PASS {test.__name__}")


if __name__ == "__main__":
    main()
