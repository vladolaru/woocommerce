#!/usr/bin/env python3
"""Run the post-A4as WooPayments cutover rehearsal against the local target store."""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import pathlib
import secrets
import shlex
import signal
import subprocess
import sys
import time
from datetime import datetime, timezone
from typing import Any

from local_runner_safety import (
    LocalRunnerError,
    validate_local_wp_command as validate_approved_local_wp_command,
)


REPO = pathlib.Path(__file__).resolve().parents[2]
TOOLS_DIR = REPO / "tools/woopayments-merge"
DEFAULT_OUT_DIR = REPO / ".agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5f-post-a4as-cutover"
ROLLUP_NAME = "a5f-cutover-rehearsal.json"
PLUGIN_SLUG = "woocommerce-payments"
MANDATORY_HELPER_FILE = "a5f-mandatory-cutover.php"
BLOCKER_HELPER_FILE = "a5f-preflight-blocker.php"
DEBUG_LOG_PATH = "/var/www/html/wp-content/debug.log"
HELPER_OWNERSHIP_PREFIX = "woopayments-a5f-owner:"
EVIDENCE_SCOPE = {
    "owned_by_a5f": [
        "runtime_ownership_transitions",
        "soft_cutover",
        "mandatory_cutover",
        "plugin_activation_guard",
        "local_transport_continuity",
    ],
    "orchestrated_by_final_evidence": [
        "lpm-checkout-gate.sh",
        "mc-rates-gate.sh",
        "token-continuity-gate.sh",
    ],
}


class HarnessError(RuntimeError):
    """Raised for fail-closed harness errors."""


class HarnessCleanupError(HarnessError):
    """Raised when the harness cannot verify exact runtime restoration."""


class HarnessSignal(HarnessError):
    """Raised when a process signal requests bounded harness cleanup."""

    def __init__(self, signum: int) -> None:
        self.signum = signum
        super().__init__(f"received signal {signum}")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_json(path: pathlib.Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: pathlib.Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(f"{json.dumps(payload, indent=2, sort_keys=True)}\n", encoding="utf-8")


def validate_local_wp_command(command: str) -> list[str]:
    try:
        return validate_approved_local_wp_command("target-wp", command)
    except LocalRunnerError as exc:
        raise HarnessError(f"Refusing unsafe or remote target WP command: {exc}") from exc


def parse_json_from_output(output: str) -> dict[str, Any]:
    stripped = output.strip()
    if stripped:
        try:
            value = json.loads(stripped)
            if isinstance(value, dict):
                return value
        except json.JSONDecodeError:
            pass

    decoder = json.JSONDecoder()
    candidates: list[dict[str, Any]] = []
    for index, char in enumerate(output):
        if char != "{":
            continue
        try:
            value, _ = decoder.raw_decode(output[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            candidates.append(value)
    if not candidates:
        raise HarnessError("Command did not emit a JSON object.")
    for candidate in candidates:
        if any(key in candidate for key in ("ready", "status", "gate", "pass")):
            return candidate
    return candidates[-1]


def command_tail(text: str, limit: int = 4000) -> str:
    text = text.strip()
    if len(text) <= limit:
        return text
    return text[-limit:]


def validate_mu_helper_name(path: str) -> None:
    if not path or pathlib.PurePosixPath(path).name != path or "\\" in path:
        raise HarnessError(f"Invalid MU helper filename: {path!r}")


def validate_ownership_token(ownership_token: str) -> None:
    if len(ownership_token) != 32 or any(char not in "0123456789abcdef" for char in ownership_token):
        raise HarnessError("MU helper ownership token must be 32 lowercase hexadecimal characters.")


def add_helper_ownership_marker(contents: str, ownership_token: str) -> str:
    validate_ownership_token(ownership_token)
    if not contents.startswith("<?php"):
        raise HarnessError("MU helper contents must start with an opening PHP tag.")
    marker = f"// {HELPER_OWNERSHIP_PREFIX}{ownership_token}\n"
    return f"<?php\n{marker}{contents[len('<?php') :]}"


def build_php_writer(path: str, contents: str, ownership_token: str) -> str:
    validate_mu_helper_name(path)
    owned_contents = add_helper_ownership_marker(contents, ownership_token)
    encoded = base64.b64encode(owned_contents.encode("utf-8")).decode("ascii")
    expected_hash = hashlib.sha256(owned_contents.encode("utf-8")).hexdigest()
    return f"""
$path = WPMU_PLUGIN_DIR . '/{path}';
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {{
\twp_mkdir_p( WPMU_PLUGIN_DIR );
}}
if ( file_exists( $path ) || is_link( $path ) ) {{
\tWP_CLI::error( 'helper_path_already_exists:{path}' );
}}
$data = base64_decode( '{encoded}', true );
if ( false === $data ) {{
\tWP_CLI::error( 'failed_to_decode_mu_helper:{path}' );
}}
$handle = fopen( $path, 'x' );
if ( false === $handle ) {{
\tWP_CLI::error( 'failed_to_write_mu_helper:{path}' );
}}
$written = 0;
$length = strlen( $data );
while ( $written < $length ) {{
\t$count = fwrite( $handle, substr( $data, $written ) );
\tif ( false === $count || 0 === $count ) {{
\t\tfclose( $handle );
\t\tWP_CLI::error( 'failed_to_write_mu_helper:{path}' );
\t}}
\t$written += $count;
}}
if ( ! fflush( $handle ) || ! fclose( $handle ) ) {{
\tWP_CLI::error( 'failed_to_flush_mu_helper:{path}' );
}}
$actual_hash = hash_file( 'sha256', $path );
if ( '{expected_hash}' !== $actual_hash ) {{
\tWP_CLI::error( 'helper_hash_mismatch_after_write:{path}' );
}}
WP_CLI::line(
\twp_json_encode(
\t\tarray(
\t\t\t'path' => $path,
\t\t\t'bytes' => $written,
\t\t\t'sha256' => $actual_hash,
\t\t\t'ownership_token' => '{ownership_token}',
\t\t\t'owned' => true,
\t\t)
\t)
);
"""


def build_php_remover(path: str, expected_hash: str, ownership_token: str, *, allow_partial: bool) -> str:
    validate_mu_helper_name(path)
    validate_ownership_token(ownership_token)
    require_hash = "false" if allow_partial else "true"
    encoded_marker = base64.b64encode(
        f"// {HELPER_OWNERSHIP_PREFIX}{ownership_token}\n".encode("utf-8")
    ).decode("ascii")
    return f"""
$path = WPMU_PLUGIN_DIR . '/{path}';
$removed = false;
if ( file_exists( $path ) || is_link( $path ) ) {{
\t$contents = file_get_contents( $path );
\t$ownership_marker = base64_decode( '{encoded_marker}', true );
\tif ( false === $contents || false === $ownership_marker || false === strpos( $contents, $ownership_marker ) ) {{
\t\tWP_CLI::error( 'helper_ownership_mismatch:{path}' );
\t}}
\t$actual_hash = hash_file( 'sha256', $path );
\tif ( {require_hash} && ( ! is_string( $actual_hash ) || ! hash_equals( '{expected_hash}', $actual_hash ) ) ) {{
\t\tWP_CLI::error( 'helper_ownership_mismatch:{path}' );
\t}}
\t$removed = unlink( $path );
}}
$exists = file_exists( $path ) || is_link( $path );
WP_CLI::line(
\twp_json_encode(
\t\tarray(
\t\t\t'path' => $path,
\t\t\t'removed' => (bool) $removed,
\t\t\t'exists' => $exists,
\t\t\t'ownership_token' => '{ownership_token}',
\t\t\t'success' => ! $exists,
\t\t)
\t)
);
"""


def build_debug_log_marker() -> str:
    return f"""
$path = '{DEBUG_LOG_PATH}';
$exists = file_exists( $path );
$stat = $exists ? stat( $path ) : false;
WP_CLI::line(
\twp_json_encode(
\t\tarray(
\t\t\t'path' => $path,
\t\t\t'exists' => $exists,
\t\t\t'device' => is_array( $stat ) ? (int) $stat['dev'] : null,
\t\t\t'inode' => $exists ? (int) fileinode( $path ) : null,
\t\t\t'offset' => $exists ? (int) filesize( $path ) : 0,
\t\t)
\t)
);
"""


def build_debug_log_scan(marker: dict[str, Any]) -> str:
    marker_exists = "true" if marker.get("exists") else "false"
    marker_device = int(marker.get("device") or 0)
    marker_inode = int(marker.get("inode") or 0)
    marker_offset = int(marker.get("offset") or 0)
    return f"""
$path = '{DEBUG_LOG_PATH}';
$marker_exists = {marker_exists};
$marker_device = {marker_device};
$marker_inode = {marker_inode};
$marker_offset = {marker_offset};
$exists = file_exists( $path );
$stat = $exists ? stat( $path ) : false;
$changed_reason = '';
if ( $marker_exists ) {{
\tif ( ! $exists ) {{
\t\t$changed_reason = 'missing';
\t}} elseif ( ! is_array( $stat ) || $marker_device !== (int) $stat['dev'] || $marker_inode !== (int) fileinode( $path ) ) {{
\t\t$changed_reason = 'identity';
\t}} elseif ( (int) filesize( $path ) < $marker_offset ) {{
\t\t$changed_reason = 'truncated';
\t}}
}}
if ( '' !== $changed_reason ) {{
\tWP_CLI::line( wp_json_encode( array( 'path' => $path, 'ready' => false, 'error' => 'debug_log_changed_since_marker', 'reason' => $changed_reason ) ) );
\treturn;
}}
$raw_content = '';
if ( $exists ) {{
\t$stream = fopen( $path, 'rb' );
\tif ( false === $stream || 0 !== fseek( $stream, {marker_offset} ) ) {{
\t\tWP_CLI::line( wp_json_encode( array( 'path' => $path, 'ready' => false, 'error' => 'debug_log_changed_since_marker', 'reason' => 'read_failed' ) ) );
\t\treturn;
\t}}
\t$read_content = stream_get_contents( $stream );
\tfclose( $stream );
\t$raw_content = false === $read_content ? '' : $read_content;
}}
$content = (string) $raw_content;
$ignored_patterns = array(
\t'wp67_early_textdomain_notice' => '/^\\[[^\\]]+\\] PHP Notice:\\s+Function _load_textdomain_just_in_time was called.*(?:\\R|$)/m',
);
$ignored_matches = array();
foreach ( $ignored_patterns as $id => $pattern ) {{
\tif ( preg_match_all( $pattern, $content, $found ) ) {{
\t\t$ignored_matches[ $id ] = count( $found[0] );
\t\t$filtered_content = preg_replace( $pattern, '', $content );
\t\tif ( is_string( $filtered_content ) ) {{
\t\t\t$content = $filtered_content;
\t\t}}
\t}}
}}
$patterns = array(
\t'php_notice' => '/PHP Notice|Notice:/i',
\t'php_warning' => '/PHP Warning|Warning:/i',
\t'php_deprecated' => '/Deprecated:/i',
\t'php_fatal' => '/PHP Fatal|Fatal error/i',
\t'uncaught' => '/Uncaught/i',
\t'database' => '/database error|wpdb/i',
\t'stack_trace' => '/Stack trace:/i',
);
$matches = array();
foreach ( $patterns as $id => $pattern ) {{
\tif ( preg_match_all( $pattern, (string) $content, $found ) ) {{
\t\t$matches[ $id ] = count( $found[0] );
\t}}
}}
WP_CLI::line(
\twp_json_encode(
\t\tarray(
\t\t\t'path' => $path,
\t\t\t'bytes' => strlen( (string) $content ),
\t\t\t'raw_bytes' => strlen( (string) $raw_content ),
\t\t\t'start_offset' => $marker_offset,
\t\t\t'total_bytes' => $exists ? (int) filesize( $path ) : 0,
\t\t\t'ignored_matches' => $ignored_matches,
\t\t\t'matches' => $matches,
\t\t\t'ready' => empty( $matches ),
\t\t)
\t)
);
"""


class Rehearsal:
    def __init__(self, args: argparse.Namespace | Any) -> None:
        self.args = args
        self.target_wp = validate_local_wp_command(args.target_wp)
        self.target_url = str(args.target_url).rstrip("/")
        self.browser_runner = str(
            getattr(args, "browser_runner", os.environ.get("BROWSER_RUNNER", "playwright"))
        )
        self.repo = REPO
        self.out_dir = pathlib.Path(args.out_dir)
        if not self.out_dir.is_absolute():
            self.out_dir = self.repo / self.out_dir
        self.rollup_path = self.out_dir / ROLLUP_NAME
        self.phase_results: list[dict[str, Any]] = []
        self.state_snapshots: dict[str, dict[str, Any]] = {}
        self.browser_evidence_paths: list[str] = []
        self.failures: list[dict[str, str]] = []
        self.pending_mu_helpers: dict[str, dict[str, Any]] = {}
        self.owned_mu_helpers: dict[str, dict[str, Any]] = {}
        self.plugin_restore_required = False
        self.cleanup_in_progress = False
        self.cleanup_failed = False
        self.cleanup_signals: list[int] = []
        self.debug_log_marker: dict[str, Any] | None = None
        self.log_window = {"started_at": utc_now(), "debug_log": DEBUG_LOG_PATH}
        self.status = "running"
        self.write_rollup()

    def progress(self, message: str) -> None:
        print(f"==> {message}", flush=True)

    def rollup(self) -> dict[str, Any]:
        return {
            "gate": "a5f-post-a4as-cutover-rehearsal",
            "status": self.status,
            "lastUpdatedAt": utc_now(),
            "target_url": self.target_url,
            "target_wp": shlex.join(self.target_wp),
            "browser_runner": self.browser_runner,
            "phase_results": self.phase_results,
            "state_snapshots": self.state_snapshots,
            "browser_evidence_paths": self.browser_evidence_paths,
            "evidence_scope": EVIDENCE_SCOPE,
            "log_window": self.log_window,
            "cleanup": {
                "in_progress": self.cleanup_in_progress,
                "failed": self.cleanup_failed,
                "deferred_signals": self.cleanup_signals,
            },
            "failures": self.failures,
            "pass": self.status == "pass" and not self.failures and all(result.get("status") == "pass" for result in self.phase_results),
        }

    def write_rollup(self) -> None:
        write_json(self.rollup_path, self.rollup())

    def record_phase(self, phase_id: str, result: dict[str, Any]) -> dict[str, Any]:
        payload = {"id": phase_id, "status": result.get("status", "pass"), **result}
        self.phase_results.append(payload)
        self.write_rollup()
        return payload

    def record_failure(self, phase: str, message: str) -> None:
        self.status = "fail"
        self.failures.append({"phase": phase, "message": message})
        self.write_rollup()

    def run_command(
        self,
        phase_id: str,
        command: list[str],
        *,
        input_text: str | None = None,
        parse_json: bool = False,
        timeout_seconds: int = 180,
        allow_failure: bool = False,
        expected_failure_message: str | None = None,
        env: dict[str, str] | None = None,
    ) -> dict[str, Any]:
        self.progress(phase_id)
        started = utc_now()
        command_env = None
        if env is not None:
            command_env = {**os.environ, **env}
        try:
            completed = subprocess.run(
                command,
                input=input_text,
                text=True,
                capture_output=True,
                timeout=timeout_seconds,
                cwd=str(self.repo),
                check=False,
                env=command_env,
            )
        except subprocess.TimeoutExpired as exc:
            result = {
                "status": "fail",
                "exit_code": 124,
                "started_at": started,
                "finished_at": utc_now(),
                "command": shlex.join(command),
                "stdout_tail": command_tail(exc.stdout or ""),
                "stderr_tail": command_tail(exc.stderr or ""),
                "error": f"timed out after {timeout_seconds}s",
            }
            self.record_phase(phase_id, result)
            if not allow_failure:
                raise HarnessError(result["error"])
            return result

        result: dict[str, Any] = {
            "status": "pass" if completed.returncode == 0 else "fail",
            "exit_code": completed.returncode,
            "started_at": started,
            "finished_at": utc_now(),
            "command": shlex.join(command),
            "stdout_tail": command_tail(completed.stdout),
            "stderr_tail": command_tail(completed.stderr),
        }
        if expected_failure_message is not None:
            output = f"{completed.stdout}\n{completed.stderr}"
            result["expected_failure"] = True
            result["required_message"] = expected_failure_message
            if completed.returncode != 0 and expected_failure_message in output:
                result["status"] = "pass"
                result["observed_exit_code"] = completed.returncode
            elif completed.returncode == 0:
                result["status"] = "fail"
                result["error"] = "expected command to fail, but it exited successfully"
            else:
                result["status"] = "fail"
                result["error"] = "expected failure message was not found"
        if parse_json and completed.returncode == 0:
            result["json"] = parse_json_from_output(completed.stdout)
        self.record_phase(phase_id, result)
        if result["status"] == "fail" and not allow_failure:
            raise HarnessError(result.get("error") or f"{phase_id} failed with exit {completed.returncode}")
        return result

    def run_wp(self, phase_id: str, wp_args: list[str], **kwargs: Any) -> dict[str, Any]:
        return self.run_command(phase_id, self.target_wp + wp_args, **kwargs)

    def as_admin_wp_args(self, wp_args: list[str]) -> list[str]:
        if any(arg == "--user" or arg.startswith("--user=") for arg in self.target_wp):
            return wp_args
        return ["--user=1", *wp_args]

    def run_wp_eval(self, phase_id: str, php: str, **kwargs: Any) -> dict[str, Any]:
        return self.run_wp(phase_id, ["eval", php], parse_json=True, **kwargs)

    def run_wp_eval_file(self, phase_id: str, php_path: pathlib.Path, **kwargs: Any) -> dict[str, Any]:
        return self.run_wp(
            phase_id,
            ["eval-file", "-"],
            input_text=php_path.read_text(encoding="utf-8"),
            parse_json=True,
            **kwargs,
        )

    def install_mu_helper(self, phase_id: str, helper_path: pathlib.Path, helper_name: str) -> None:
        contents = helper_path.read_text(encoding="utf-8")
        ownership_token = secrets.token_hex(16)
        owned_contents = add_helper_ownership_marker(contents, ownership_token)
        ownership = {
            "ownership_token": ownership_token,
            "sha256": hashlib.sha256(owned_contents.encode("utf-8")).hexdigest(),
            "bytes": len(owned_contents.encode("utf-8")),
        }
        self.pending_mu_helpers[helper_name] = ownership
        result = self.run_wp_eval(
            phase_id,
            build_php_writer(helper_name, contents, ownership_token),
        )
        payload = result.get("json", {})
        if (
            payload.get("owned") is not True
            or payload.get("ownership_token") != ownership_token
            or payload.get("sha256") != ownership["sha256"]
            or payload.get("bytes") != ownership["bytes"]
        ):
            raise HarnessError(f"MU helper writer did not confirm exact ownership for {helper_name}")
        self.owned_mu_helpers[helper_name] = ownership
        self.pending_mu_helpers.pop(helper_name, None)

    def remove_mu_helper(self, phase_id: str, helper_name: str) -> None:
        ownership = self.owned_mu_helpers.get(helper_name)
        allow_partial = False
        if ownership is None:
            ownership = self.pending_mu_helpers.get(helper_name)
            allow_partial = True
        if ownership is None:
            return
        result = self.run_wp_eval(
            phase_id,
            build_php_remover(
                helper_name,
                ownership["sha256"],
                ownership["ownership_token"],
                allow_partial=allow_partial,
            ),
        )
        payload = result.get("json", {})
        if (
            not payload.get("success")
            or payload.get("exists")
            or payload.get("ownership_token") != ownership["ownership_token"]
        ):
            raise HarnessError(f"failed to remove owned MU helper {helper_name}")
        self.owned_mu_helpers.pop(helper_name, None)
        self.pending_mu_helpers.pop(helper_name, None)

    def defer_cleanup_signal(self, signum: int) -> bool:
        if not self.cleanup_in_progress and not self.cleanup_failed:
            return False
        self.cleanup_signals.append(signum)
        self.cleanup_failed = True
        return True

    def begin_cleanup(self) -> None:
        if self.cleanup_in_progress:
            return
        self.cleanup_in_progress = True
        self.cleanup_failed = False
        self.cleanup_signals = []

    def cleanup_runtime_state(self) -> bool:
        self.begin_cleanup()
        cleanup_succeeded = not self.cleanup_failed

        def deactivate_plugin_for_cleanup() -> None:
            if not self.plugin_restore_required:
                return
            result = self.deactivate_plugin("cleanup-restore-native-ownership", allow_failure=True)
            if result.get("status") != "pass":
                raise HarnessError(
                    "WooPayments plugin deactivation failed with "
                    f"exit {result.get('exit_code')}"
                )

        try:
            cleanup_actions = (
                (
                    "cleanup-synthetic-preflight-blocker",
                    lambda: self.remove_mu_helper("cleanup-synthetic-preflight-blocker", BLOCKER_HELPER_FILE),
                ),
                (
                    "cleanup-mandatory-helper",
                    lambda: self.remove_mu_helper("cleanup-mandatory-helper", MANDATORY_HELPER_FILE),
                ),
                (
                    "cleanup-restore-native-ownership",
                    deactivate_plugin_for_cleanup,
                ),
            )
            for phase_id, action in cleanup_actions:
                try:
                    action()
                except Exception as cleanup_exc:
                    cleanup_succeeded = False
                    self.record_failure(phase_id, str(cleanup_exc))

            try:
                restored = self.run_state_probe("cleanup-verify-native-ownership")
                self.assert_state(
                    "cleanup-verify-native-ownership",
                    restored,
                    {
                        "runtime_owner": "native",
                        "native_runtime_enabled": True,
                        "plugin_runtime_active": False,
                        "should_native_register": True,
                        "soft_notice": False,
                        "ready": True,
                    },
                )
                self.assert_preflight_empty("cleanup-verify-native-ownership", restored)
            except Exception as cleanup_exc:
                cleanup_succeeded = False
                self.record_failure("cleanup-verify-native-ownership", str(cleanup_exc))

            if self.cleanup_signals:
                cleanup_succeeded = False
                signal_list = ", ".join(str(signum) for signum in self.cleanup_signals)
                self.record_failure(
                    "cleanup-signal",
                    f"deferred signal(s) during runtime restoration: {signal_list}",
                )

            if cleanup_succeeded:
                self.plugin_restore_required = False
            self.cleanup_failed = self.cleanup_failed or not cleanup_succeeded
            return not self.cleanup_failed
        except Exception:
            self.cleanup_failed = True
            raise
        finally:
            self.cleanup_in_progress = False

    def mark_debug_log(self) -> None:
        result = self.run_wp_eval("mark-target-debug-log", build_debug_log_marker())
        marker = result.get("json", {})
        if not isinstance(marker.get("exists"), bool) or not isinstance(marker.get("offset"), int):
            raise HarnessError("target debug.log marker was incomplete")
        if marker["exists"] and (not isinstance(marker.get("device"), int) or not isinstance(marker.get("inode"), int)):
            raise HarnessError("target debug.log identity marker was incomplete")
        self.debug_log_marker = marker
        self.log_window["started_at"] = utc_now()
        self.log_window["start_offset"] = marker["offset"]
        self.log_window["file_existed_at_start"] = marker["exists"]
        self.write_rollup()

    def scan_debug_log(self) -> None:
        if self.debug_log_marker is None:
            raise HarnessError("target debug.log was not marked before scanning")
        result = self.run_wp_eval("scan-target-debug-log", build_debug_log_scan(self.debug_log_marker))
        payload = result.get("json", {})
        if not payload.get("ready"):
            raise HarnessError(f"target debug.log has diagnostics: {payload.get('matches')}")

    def run_state_probe(self, phase_id: str) -> dict[str, Any]:
        result = self.run_wp(
            phase_id,
            self.as_admin_wp_args(["eval-file", "-"]),
            input_text=(TOOLS_DIR / "a5-cutover-state.php").read_text(encoding="utf-8"),
            parse_json=True,
        )
        payload = result["json"]
        self.state_snapshots[phase_id] = payload
        self.write_rollup()
        return payload

    def assert_state(self, phase_id: str, state: dict[str, Any], expected: dict[str, Any]) -> None:
        failures: list[str] = []
        for key, value in expected.items():
            if state.get(key) != value:
                failures.append(f"{key}: expected {value!r}, got {state.get(key)!r}")
        if failures:
            raise HarnessError(f"{phase_id} state mismatch: {'; '.join(failures)}")

    def assert_preflight_empty(self, phase_id: str, state: dict[str, Any]) -> None:
        if state.get("preflight_failures") or state.get("failures"):
            raise HarnessError(f"{phase_id} expected empty preflight/failures, got {state.get('preflight_failures')} / {state.get('failures')}")

    def copy_browser_evidence(self, source_evidence: pathlib.Path) -> pathlib.Path:
        copied = self.out_dir / source_evidence.name.replace("a5e", "a5f")
        copied.write_text(source_evidence.read_text(encoding="utf-8"), encoding="utf-8")
        copied_path = str(copied)
        if copied_path not in self.browser_evidence_paths:
            self.browser_evidence_paths.append(copied_path)
        self.write_rollup()
        return copied

    def run_browser_gate(self, phase_id: str, script: pathlib.Path, source_evidence: pathlib.Path) -> None:
        browser_runner = self.browser_runner
        if browser_runner != "playwright":
            raise HarnessError(f"unsupported browser runner: {browser_runner}")

        runner = os.environ.get("PLAYWRIGHT_SCRIPT_RUNNER_BIN") or str(TOOLS_DIR / "playwright-script-runner.mjs")
        command = shlex.split(runner)
        runner_args = command + [str(script), "--timeout", "300000"]

        previous_mtime = source_evidence.stat().st_mtime_ns if source_evidence.exists() else None
        command_error: Exception | None = None
        browser_env = {
            "A5_GATE_TARGET_URL": self.target_url,
            "A5_GATE_PLUGINS_URL": f"{self.target_url}/wp-admin/plugins.php",
            "A5_GATE_DATA_DIR": str(source_evidence.parent),
            "A5_GATE_EVIDENCE_PATH": str(source_evidence),
            "WP_ADMIN_USER": os.environ.get("WP_ADMIN_USER", "admin"),
            "WP_ADMIN_PASSWORD": os.environ.get("WP_ADMIN_PASSWORD", "password"),
        }
        try:
            self.run_command(
                phase_id,
                runner_args,
                timeout_seconds=360,
                env=browser_env,
            )
        except Exception as exc:
            command_error = exc

        if not source_evidence.exists():
            raise HarnessError(f"{phase_id} did not write expected browser evidence {source_evidence}")
        if previous_mtime is not None and source_evidence.stat().st_mtime_ns == previous_mtime:
            raise HarnessError(f"{phase_id} did not update expected browser evidence {source_evidence}")

        self.copy_browser_evidence(source_evidence)
        if command_error is not None:
            raise command_error

    def activate_plugin(self, phase_id: str, allow_failure: bool = False) -> dict[str, Any]:
        self.plugin_restore_required = True
        return self.run_wp(phase_id, ["plugin", "activate", PLUGIN_SLUG], allow_failure=allow_failure, timeout_seconds=180)

    def deactivate_plugin(self, phase_id: str, allow_failure: bool = False) -> dict[str, Any]:
        return self.run_wp(phase_id, ["plugin", "deactivate", PLUGIN_SLUG], allow_failure=allow_failure, timeout_seconds=180)

    def run_wpcom_readiness(self) -> None:
        if getattr(self.args, "skip_wpcom_readiness", False):
            self.record_phase("local-wpcom-readiness", {"status": "skipped", "reason": "explicitly skipped"})
            return
        self.run_command(
            "local-wpcom-readiness",
            [str(TOOLS_DIR / "a5-local-wpcom-readiness.sh"), str(self.args.store_dir)],
            timeout_seconds=240,
        )

    def run_transport_probes(self) -> None:
        user_token = self.run_wp_eval_file("owner-user-token-readiness", TOOLS_DIR / "a5-user-token-readiness.php")
        if not user_token["json"].get("ready"):
            raise HarnessError(f"owner user-token readiness failed: {user_token['json'].get('failures')}")
        transport = self.run_wp_eval_file("transport-continuity", TOOLS_DIR / "a5-transport-continuity.php")
        if not transport["json"].get("ready"):
            raise HarnessError(f"transport continuity failed: {transport['json'].get('failures')}")

    def run(self) -> None:
        try:
            self.mark_debug_log()

            baseline = self.run_state_probe("baseline-native-state")
            self.assert_state(
                "baseline-native-state",
                baseline,
                {
                    "runtime_owner": "native",
                    "native_runtime_enabled": True,
                    "plugin_runtime_active": False,
                    "should_native_register": True,
                    "soft_notice": False,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("baseline-native-state", baseline)

            self.activate_plugin("activate-plugin-for-default-off-proof")
            default_off = self.run_state_probe("default-off-plugin-state")
            self.assert_state(
                "default-off-plugin-state",
                default_off,
                {
                    "runtime_owner": "plugin",
                    "native_runtime_enabled": True,
                    "plugin_runtime_active": True,
                    "should_native_register": False,
                    "soft_notice": True,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("default-off-plugin-state", default_off)

            self.run_browser_gate(
                "soft-cutover-browser-gate",
                TOOLS_DIR / "a5-cutover-browser-gate.playwright.mjs",
                DEFAULT_OUT_DIR.parent / "a5e-soft-cutover-browser-gate.json",
            )
            post_soft = self.run_state_probe("post-soft-native-state")
            self.assert_state(
                "post-soft-native-state",
                post_soft,
                {
                    "runtime_owner": "native",
                    "plugin_runtime_active": False,
                    "should_native_register": True,
                    "soft_notice": False,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("post-soft-native-state", post_soft)

            self.activate_plugin("reactivate-plugin-default-off-after-soft")
            default_off_after_soft = self.run_state_probe("default-off-reactivation-state")
            self.assert_state(
                "default-off-reactivation-state",
                default_off_after_soft,
                {
                    "runtime_owner": "plugin",
                    "plugin_runtime_active": True,
                    "should_native_register": False,
                    "soft_notice": True,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("default-off-reactivation-state", default_off_after_soft)

            self.install_mu_helper(
                "install-mandatory-helper",
                TOOLS_DIR / "a5-mandatory-cutover-mu-plugin.php",
                MANDATORY_HELPER_FILE,
            )
            self.run_browser_gate(
                "mandatory-browser-gate",
                TOOLS_DIR / "a5-mandatory-browser-gate.playwright.mjs",
                DEFAULT_OUT_DIR.parent / "a5e-mandatory-auto-deactivation-browser-check.json",
            )
            mandatory_post = self.run_state_probe("post-mandatory-native-state")
            self.assert_state(
                "post-mandatory-native-state",
                mandatory_post,
                {
                    "runtime_owner": "native",
                    "plugin_runtime_active": False,
                    "should_native_register": True,
                    "soft_notice": False,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("post-mandatory-native-state", mandatory_post)

            self.run_wp(
                "activation-guard-blocks-under-mandatory",
                ["plugin", "activate", PLUGIN_SLUG],
                expected_failure_message="now included in WooCommerce core",
                timeout_seconds=180,
            )

            self.remove_mu_helper("remove-mandatory-helper-before-blocker-proof", MANDATORY_HELPER_FILE)
            self.install_mu_helper(
                "install-mandatory-helper-for-blocker",
                TOOLS_DIR / "a5-mandatory-cutover-mu-plugin.php",
                MANDATORY_HELPER_FILE,
            )
            self.install_mu_helper(
                "install-synthetic-preflight-blocker",
                TOOLS_DIR / "a5-preflight-blocker-mu-plugin.php",
                BLOCKER_HELPER_FILE,
            )
            self.activate_plugin("activate-plugin-for-blocked-mandatory")
            blocked_state = self.run_state_probe("blocked-mandatory-plugin-state")
            if "a5_synthetic_preflight_blocker" not in blocked_state.get("preflight_failures", []):
                raise HarnessError(f"synthetic blocker missing from preflight failures: {blocked_state.get('preflight_failures')}")
            self.run_browser_gate(
                "blocked-mandatory-browser-gate",
                TOOLS_DIR / "a5-blocked-mandatory-browser-gate.playwright.mjs",
                DEFAULT_OUT_DIR.parent / "a5f-blocked-mandatory-browser-gate.json",
            )
            blocked_after = self.run_state_probe("blocked-mandatory-after-browser-state")
            self.assert_state(
                "blocked-mandatory-after-browser-state",
                blocked_after,
                {
                    "runtime_owner": "plugin",
                    "plugin_runtime_active": True,
                    "should_native_register": False,
                    "soft_notice": False,
                    "ready": False,
                },
            )

            self.remove_mu_helper("remove-synthetic-preflight-blocker", BLOCKER_HELPER_FILE)
            self.remove_mu_helper("remove-mandatory-helper", MANDATORY_HELPER_FILE)
            self.deactivate_plugin("restore-native-ownership")
            restored = self.run_state_probe("restored-native-state")
            self.assert_state(
                "restored-native-state",
                restored,
                {
                    "runtime_owner": "native",
                    "plugin_runtime_active": False,
                    "should_native_register": True,
                    "soft_notice": False,
                    "ready": True,
                },
            )
            self.assert_preflight_empty("restored-native-state", restored)

            self.run_wpcom_readiness()
            self.run_transport_probes()
            self.scan_debug_log()
            self.status = "pass"
            self.write_rollup()
            self.progress(f"A5f cutover rehearsal passed: {self.rollup_path}")
        except Exception as exc:
            self.begin_cleanup()
            failure_record_error: Exception | None = None
            try:
                self.record_failure("a5f", str(exc))
            except Exception as record_exc:
                self.cleanup_failed = True
                failure_record_error = record_exc
            try:
                cleanup_succeeded = self.cleanup_runtime_state()
            except Exception as cleanup_exc:
                self.cleanup_failed = True
                raise HarnessCleanupError("runtime restoration did not complete") from cleanup_exc
            self.write_rollup()
            if failure_record_error is not None:
                raise HarnessCleanupError("runtime failure evidence could not be recorded") from failure_record_error
            if not cleanup_succeeded or self.cleanup_failed:
                raise HarnessCleanupError("runtime restoration was not verified") from exc
            raise


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--target-wp", required=True, help="Local target WP-CLI command.")
    parser.add_argument("--target-url", default="http://store8889.localhost:8889")
    parser.add_argument("--store-dir", default=str(REPO))
    parser.add_argument(
        "--browser-runner",
        choices=("playwright",),
        default=os.environ.get("BROWSER_RUNNER", "playwright"),
        help="Browser runner for rehearsal evidence; only direct Playwright is supported.",
    )
    parser.add_argument("--out-dir", default=str(DEFAULT_OUT_DIR))
    parser.add_argument("--skip-wpcom-readiness", action="store_true")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    rehearsal = Rehearsal(args)
    handled_signals = (signal.SIGHUP, signal.SIGINT, signal.SIGTERM)
    previous_handlers = {signum: signal.getsignal(signum) for signum in handled_signals}

    def handle_signal(signum: int, _frame: Any) -> None:
        if rehearsal.defer_cleanup_signal(signum):
            return
        raise HarnessSignal(signum)

    for signum in handled_signals:
        signal.signal(signum, handle_signal)
    try:
        rehearsal.run()
    except HarnessCleanupError as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 70
    except HarnessSignal as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 128 + exc.signum
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 70 if rehearsal.cleanup_failed else 1
    finally:
        for signum, previous_handler in previous_handlers.items():
            signal.signal(signum, previous_handler)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
