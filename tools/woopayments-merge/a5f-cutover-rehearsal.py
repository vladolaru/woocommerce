#!/usr/bin/env python3
"""Run the post-A4as WooPayments cutover rehearsal against the local target store."""

from __future__ import annotations

import argparse
import base64
import copy
import json
import os
import pathlib
import shlex
import shutil
import subprocess
import sys
import time
from datetime import datetime, timezone
from typing import Any


REPO = pathlib.Path(__file__).resolve().parents[2]
TOOLS_DIR = REPO / "tools/woopayments-merge"
DEFAULT_OUT_DIR = REPO / ".agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5f-post-a4as-cutover"
ROLLUP_NAME = "a5f-cutover-rehearsal.json"
PLUGIN_SLUG = "woocommerce-payments"
MANDATORY_HELPER_FILE = "a5f-mandatory-cutover.php"
BLOCKER_HELPER_FILE = "a5f-preflight-blocker.php"
DEBUG_LOG_PATH = "/var/www/html/wp-content/debug.log"
LPM_WAVE_1_METHODS = [
    "sepa_debit",
    "ideal",
    "bancontact",
    "klarna",
    "affirm",
    "afterpay_clearpay",
]
LPM_WAVE_1_FIXTURES = {
    "sepa_debit": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_sepa_debit",
        "stripe_payment_method_type": "sepa_debit",
        "family": "debit",
    },
    "ideal": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_ideal",
        "stripe_payment_method_type": "ideal",
        "family": "redirect",
    },
    "bancontact": {
        "currency": "EUR",
        "country": "BE",
        "gateway_id": "woocommerce_payments_bancontact",
        "stripe_payment_method_type": "bancontact",
        "family": "redirect",
    },
    "klarna": {
        "currency": "EUR",
        "country": "NL",
        "gateway_id": "woocommerce_payments_klarna",
        "stripe_payment_method_type": "klarna",
        "family": "bnpl",
    },
    "affirm": {
        "currency": "USD",
        "country": "US",
        "gateway_id": "woocommerce_payments_affirm",
        "stripe_payment_method_type": "affirm",
        "family": "bnpl",
    },
    "afterpay_clearpay": {
        "currency": "USD",
        "country": "US",
        "gateway_id": "woocommerce_payments_afterpay_clearpay",
        "stripe_payment_method_type": "afterpay_clearpay",
        "family": "bnpl",
    },
}
REQUIRED_STORE_PROFILES = [
    {
        "id": "lpm-wave-1-checkout",
        "label": "LPM-enabled checkout store",
        "gate": "lpm-checkout-gate.sh",
        "gate_plan_schema": "woopayments_lpm_checkout_gate_plan.v1",
        "evidence_rollup": "lpm-checkout-gate.json",
        "surface": "classic",
        "methods": LPM_WAVE_1_METHODS,
        "fixtures": LPM_WAVE_1_FIXTURES,
    },
    {
        "id": "multi-currency-rates",
        "label": "MC-enabled store",
        "gate": "mc-rates-gate.sh",
        "gate_plan_schema": "woopayments_mc_rates_gate_plan.v1",
        "evidence_rollup": "mc-rates-gate.json",
        "currency_from": "USD",
        "currencies_to": ["GBP", "EUR"],
    },
    {
        "id": "sepa-token-continuity",
        "label": "SEPA-token store",
        "gate": "token-continuity-gate.sh",
        "gate_plan_schema": "woopayments_token_continuity_gate_plan.v1",
        "evidence_rollup": "token-continuity-gate.json",
        "method": "sepa_debit",
        "gateway_id": "woocommerce_payments_sepa_debit",
        "stripe_payment_method_type": "sepa_debit",
        "token_type": "wcpay_sepa",
        "required_fixture_inputs": ["customer_id", "subscription_id"],
        "checks": [
            "plugin_checkout_saves_sepa_token",
            "native_cutover_cli_lists_token",
            "native_my_account_renders_token",
            "native_sepa_subscription_renewal_succeeds",
        ],
    },
]
REQUIRED_STORE_PROFILE_IDS = {profile["id"] for profile in REQUIRED_STORE_PROFILES}


class HarnessError(RuntimeError):
    """Raised for fail-closed harness errors."""


def required_store_profiles() -> list[dict[str, Any]]:
    return copy.deepcopy(REQUIRED_STORE_PROFILES)


def validate_required_store_profiles(profiles: list[dict[str, Any]]) -> None:
    ids = {str(profile.get("id", "")) for profile in profiles}
    if ids != REQUIRED_STORE_PROFILE_IDS:
        missing = sorted(REQUIRED_STORE_PROFILE_IDS - ids)
        extra = sorted(ids - REQUIRED_STORE_PROFILE_IDS)
        raise HarnessError(f"required store profiles are incomplete: missing={missing}, extra={extra}")

    for profile in profiles:
        profile_id = str(profile["id"])
        gate = str(profile.get("gate", ""))
        if not gate or "/" in gate:
            raise HarnessError(f"{profile_id} has an invalid gate file: {gate!r}")
        if not (TOOLS_DIR / gate).is_file():
            raise HarnessError(f"{profile_id} gate file is missing: {gate}")
        if not profile.get("gate_plan_schema"):
            raise HarnessError(f"{profile_id} is missing gate_plan_schema")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_json(path: pathlib.Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: pathlib.Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(f"{json.dumps(payload, indent=2, sort_keys=True)}\n", encoding="utf-8")


def validate_local_wp_command(command: str) -> list[str]:
    if any(token in command for token in (";", "&&", "||", "`", "$(", "\n", "\r")):
        raise HarnessError("Refusing unsafe or remote target WP command.")

    parts = shlex.split(command)
    if not parts:
        raise HarnessError("Refusing unsafe or remote target WP command.")

    lowered = [part.lower() for part in parts]
    if any(part.startswith("--http") for part in lowered):
        raise HarnessError("Refusing unsafe or remote target WP command.")

    if any(part in {"ssh", "wpcom", "wpcom-local"} for part in lowered):
        raise HarnessError("Refusing unsafe or remote target WP command.")

    if lowered[:2] != ["docker", "exec"] or "wp" not in lowered:
        raise HarnessError("Refusing unsafe or remote target WP command.")

    return parts


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


def build_php_writer(path: str, contents: str) -> str:
    encoded = base64.b64encode(contents.encode("utf-8")).decode("ascii")
    return f"""
$path = WPMU_PLUGIN_DIR . '/{path}';
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {{
\twp_mkdir_p( WPMU_PLUGIN_DIR );
}}
$written = file_put_contents( $path, base64_decode( '{encoded}' ) );
if ( false === $written ) {{
\tWP_CLI::error( 'failed_to_write_mu_helper:{path}' );
}}
WP_CLI::line( wp_json_encode( array( 'path' => $path, 'bytes' => $written ) ) );
"""


def build_php_remover(path: str) -> str:
    return f"""
$path = WPMU_PLUGIN_DIR . '/{path}';
$removed = false;
if ( file_exists( $path ) ) {{
\t$removed = unlink( $path );
}}
WP_CLI::line( wp_json_encode( array( 'path' => $path, 'removed' => (bool) $removed, 'exists' => file_exists( $path ) ) ) );
"""


def build_clear_debug_log() -> str:
    return f"""
$path = '{DEBUG_LOG_PATH}';
$result = false;
if ( file_exists( $path ) ) {{
\t$result = file_put_contents( $path, '' );
}} else {{
\t$dir = dirname( $path );
\tif ( is_dir( $dir ) ) {{
\t\t$result = file_put_contents( $path, '' );
\t}}
}}
WP_CLI::line( wp_json_encode( array( 'path' => $path, 'cleared' => false !== $result, 'bytes' => false === $result ? null : $result ) ) );
"""


def build_debug_log_scan() -> str:
    return f"""
$path = '{DEBUG_LOG_PATH}';
$content = file_exists( $path ) ? file_get_contents( $path ) : '';
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
        self.repo = REPO
        self.out_dir = pathlib.Path(args.out_dir)
        if not self.out_dir.is_absolute():
            self.out_dir = self.repo / self.out_dir
        self.rollup_path = self.out_dir / ROLLUP_NAME
        self.phase_results: list[dict[str, Any]] = []
        self.state_snapshots: dict[str, dict[str, Any]] = {}
        self.browser_evidence_paths: list[str] = []
        self.failures: list[dict[str, str]] = []
        self.log_window = {"started_at": utc_now(), "debug_log": DEBUG_LOG_PATH}
        self.required_store_profiles = required_store_profiles()
        validate_required_store_profiles(self.required_store_profiles)
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
            "phase_results": self.phase_results,
            "state_snapshots": self.state_snapshots,
            "browser_evidence_paths": self.browser_evidence_paths,
            "required_store_profiles": self.required_store_profiles,
            "log_window": self.log_window,
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
    ) -> dict[str, Any]:
        self.progress(phase_id)
        started = utc_now()
        try:
            completed = subprocess.run(
                command,
                input=input_text,
                text=True,
                capture_output=True,
                timeout=timeout_seconds,
                cwd=str(self.repo),
                check=False,
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
        self.run_wp_eval(phase_id, build_php_writer(helper_name, contents))

    def remove_mu_helper(self, phase_id: str, helper_name: str) -> None:
        self.run_wp_eval(phase_id, build_php_remover(helper_name))

    def clear_debug_log(self) -> None:
        self.run_wp_eval("clear-target-debug-log", build_clear_debug_log())
        self.log_window["started_at"] = utc_now()
        self.write_rollup()

    def scan_debug_log(self) -> None:
        result = self.run_wp_eval("scan-target-debug-log", build_debug_log_scan())
        payload = result.get("json", {})
        if not payload.get("ready"):
            raise HarnessError(f"target debug.log has diagnostics: {payload.get('matches')}")

    def run_state_probe(self, phase_id: str) -> dict[str, Any]:
        result = self.run_wp_eval_file(phase_id, TOOLS_DIR / "a5-cutover-state.php")
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

    def run_playwriter_gate(self, phase_id: str, script: pathlib.Path, source_evidence: pathlib.Path) -> None:
        playwriter = os.environ.get("PLAYWRITER_BIN")
        if playwriter:
            command = shlex.split(playwriter)
        elif shutil.which("playwriter"):
            command = ["playwriter"]
        else:
            command = ["npx", "--yes", "playwriter@latest"]

        previous_mtime = source_evidence.stat().st_mtime_ns if source_evidence.exists() else None
        command_error: Exception | None = None
        try:
            self.run_command(
                phase_id,
                command + ["-s", str(self.args.playwriter_session), "-f", str(script), "--timeout", "300000"],
                timeout_seconds=360,
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
            self.clear_debug_log()

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

            self.run_playwriter_gate(
                "soft-cutover-browser-gate",
                TOOLS_DIR / "a5-cutover-browser-gate.playwriter.mjs",
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
            self.run_playwriter_gate(
                "mandatory-browser-gate",
                TOOLS_DIR / "a5-mandatory-browser-gate.playwriter.mjs",
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
            self.run_playwriter_gate(
                "blocked-mandatory-browser-gate",
                TOOLS_DIR / "a5-blocked-mandatory-browser-gate.playwriter.mjs",
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
            self.record_failure("a5f", str(exc))
            try:
                self.remove_mu_helper("cleanup-synthetic-preflight-blocker", BLOCKER_HELPER_FILE)
                self.remove_mu_helper("cleanup-mandatory-helper", MANDATORY_HELPER_FILE)
                self.deactivate_plugin("cleanup-restore-native-ownership", allow_failure=True)
            except Exception as cleanup_exc:
                self.record_failure("cleanup", str(cleanup_exc))
            self.write_rollup()
            raise


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--target-wp", required=True, help="Local target WP-CLI command.")
    parser.add_argument("--target-url", default="http://store8889.localhost:8889")
    parser.add_argument("--store-dir", default=str(REPO))
    parser.add_argument("--playwriter-session", required=True)
    parser.add_argument("--out-dir", default=str(DEFAULT_OUT_DIR))
    parser.add_argument("--skip-wpcom-readiness", action="store_true")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    rehearsal = Rehearsal(args)
    try:
        rehearsal.run()
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
