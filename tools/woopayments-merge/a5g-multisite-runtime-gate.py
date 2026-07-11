#!/usr/bin/env python3
"""Run the A5g WooPayments multisite runtime ownership gate in a disposable wp-env."""

from __future__ import annotations

import argparse
import json
import os
import pathlib
import shlex
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import uuid
from datetime import datetime, timezone
from typing import Any


REPO = pathlib.Path(__file__).resolve().parents[2]
TOOLS_DIR = REPO / "tools/woopayments-merge"
DEFAULT_OUT_DIR = REPO / ".agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a5g-multisite-runtime"
ROLLUP_NAME = "a5g-multisite-runtime-gate.json"
PLUGIN_FILE = "woocommerce-payments/woocommerce-payments.php"


class GateError(RuntimeError):
    """Raised for fail-closed gate errors."""


class GateSignal(GateError):
    """Raised when a signal requests disposable-environment cleanup."""

    def __init__(self, signum: int) -> None:
        self.signum = signum
        super().__init__(f"received signal {signum}")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def write_json(path: pathlib.Path, payload: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(f"{json.dumps(payload, indent=2, sort_keys=True)}\n", encoding="utf-8")


def command_tail(text: str, limit: int = 4000) -> str:
    text = text.strip()
    if len(text) <= limit:
        return text
    return text[-limit:]


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
        raise GateError("Command did not emit a JSON object.")
    for candidate in candidates:
        if any(key in candidate for key in ("ready", "runtime_owner", "pass", "status")):
            return candidate
    return candidates[-1]


def is_tcp_port_available(port: int) -> bool:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        try:
            sock.bind(("127.0.0.1", port))
        except OSError:
            return False
    return True


def resolve_port_pair(start_port: int, *, max_attempts: int = 50) -> int:
    port = start_port
    for _ in range(max_attempts):
        if is_tcp_port_available(port) and is_tcp_port_available(port + 1):
            return port
        port += 2
    raise GateError(f"Could not find a free wp-env port pair starting at {start_port}.")


def make_docker_reference_safe_temp_dir(prefix: str, root: pathlib.Path) -> pathlib.Path:
    for _ in range(50):
        path = root / f"{prefix}{uuid.uuid4().hex[:12]}"
        try:
            path.mkdir(mode=0o700)
            return path
        except FileExistsError:
            continue
    raise GateError(f"Could not create a unique temp directory under {root}.")


class MultisiteRuntimeGate:
    def __init__(self, args: argparse.Namespace) -> None:
        self.args = args
        if args.runtime_mode == "disposable":
            self.args.port = resolve_port_pair(int(args.port))
        self.repo = pathlib.Path(args.repo).resolve()
        self.wcpay_repo = pathlib.Path(args.wcpay_repo).resolve()
        self.existing_wp_env_dir = pathlib.Path(args.existing_wp_env_dir).resolve() if args.existing_wp_env_dir else self.repo / "plugins/woocommerce"
        self.wp_env_bin = self.existing_wp_env_dir / "node_modules/.bin/wp-env"
        self.out_dir = pathlib.Path(args.out_dir)
        if not self.out_dir.is_absolute():
            self.out_dir = self.repo / self.out_dir
        self.rollup_path = self.out_dir / ROLLUP_NAME
        self.work_dir: pathlib.Path | None = None
        self.status = "running"
        self.phase_results: list[dict[str, Any]] = []
        self.state_snapshots: dict[str, dict[str, Any]] = {}
        self.failures: list[dict[str, str]] = []
        self.wp_env_started = False
        self.wp_container = "tests-cli" if args.runtime_mode == "existing-tests" else "cli"
        self.main_url = f"http://localhost:{args.port}"
        self.second_url = f"{self.main_url}/a5g-two/"
        self.write_rollup()

    def progress(self, message: str) -> None:
        print(f"==> {message}", flush=True)

    def rollup(self) -> dict[str, Any]:
        return {
            "gate": "a5g-multisite-runtime-gate",
            "status": self.status,
            "lastUpdatedAt": utc_now(),
            "repo": str(self.repo),
            "wcpay_repo": str(self.wcpay_repo),
            "runtime_mode": self.args.runtime_mode,
            "wp_env_bin": str(self.wp_env_bin),
            "wp_env_cwd": str(self.command_cwd()),
            "work_dir": str(self.work_dir) if self.work_dir else None,
            "main_url": self.main_url,
            "second_url": self.second_url,
            "phase_results": self.phase_results,
            "state_snapshots": self.state_snapshots,
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

    def command_env(self) -> dict[str, str]:
        env = os.environ.copy()
        env["WP_ENV_PORT"] = str(self.args.port)
        env["WP_ENV_TESTS_PORT"] = str(self.args.port + 1)
        return env

    def command_cwd(self) -> pathlib.Path:
        if self.args.runtime_mode == "existing-tests":
            return self.existing_wp_env_dir
        return self.work_dir or self.repo

    def run_command(
        self,
        phase_id: str,
        command: list[str],
        *,
        input_text: str | None = None,
        parse_json: bool = False,
        timeout_seconds: int = 300,
        allow_failure: bool = False,
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
                cwd=str(self.command_cwd()),
                env=self.command_env(),
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
                raise GateError(result["error"])
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
        if parse_json:
            try:
                result["json"] = parse_json_from_output(completed.stdout)
            except Exception as exc:
                result["status"] = "fail"
                result["error"] = str(exc)
        self.record_phase(phase_id, result)
        if result["status"] == "fail" and not allow_failure:
            raise GateError(result.get("error") or f"{phase_id} failed with exit {completed.returncode}")
        return result

    def run_wp_env(self, phase_id: str, args: list[str], **kwargs: Any) -> dict[str, Any]:
        return self.run_command(phase_id, [str(self.wp_env_bin)] + args, **kwargs)

    def run_wp(self, phase_id: str, args: list[str], **kwargs: Any) -> dict[str, Any]:
        return self.run_wp_env(phase_id, ["run", self.wp_container, "wp"] + args, **kwargs)

    def discover_existing_tests_urls(self) -> None:
        result = self.run_wp(
            "discover-existing-tests-url",
            ["eval", "WP_CLI::line( wp_json_encode( array( 'home' => home_url() ) ) );"],
            parse_json=True,
            timeout_seconds=180,
        )
        home = str(result.get("json", {}).get("home", "")).rstrip("/")
        if not home:
            raise GateError("Could not discover existing tests environment home URL.")
        self.main_url = home
        self.second_url = f"{self.main_url}/a5g-two/"
        self.write_rollup()

    def reset_existing_tests_env(self, phase_id: str) -> None:
        install_url = str(self.args.existing_tests_url).rstrip("/")
        self.delete_existing_tests_multisite_constants(f"{phase_id}-delete-multisite-constants")
        self.run_wp_env(
            f"{phase_id}-db-reset",
            ["run", self.wp_container, "wp", "db", "reset", "--yes"],
            timeout_seconds=300,
        )
        self.run_wp_env(
            f"{phase_id}-core-install",
            [
                "run",
                self.wp_container,
                "wp",
                "core",
                "install",
                f"--url={install_url}",
                "--title=A5g Native Payments Tests",
                "--admin_user=admin",
                "--admin_password=password",
                "--admin_email=admin@example.test",
                "--skip-email",
            ],
            timeout_seconds=300,
        )
        self.main_url = install_url
        self.second_url = f"{self.main_url}/a5g-two/"
        self.write_rollup()

    def delete_existing_tests_multisite_constants(self, phase_id: str) -> None:
        constants = [
            "WP_ALLOW_MULTISITE",
            "MULTISITE",
            "SUBDOMAIN_INSTALL",
            "DOMAIN_CURRENT_SITE",
            "PATH_CURRENT_SITE",
            "SITE_ID_CURRENT_SITE",
            "BLOG_ID_CURRENT_SITE",
        ]
        started = utc_now()
        results: list[dict[str, Any]] = []
        failures: list[str] = []
        for constant in constants:
            command = [str(self.wp_env_bin), "run", self.wp_container, "wp", "config", "delete", constant, "--type=constant"]
            completed = subprocess.run(
                command,
                text=True,
                capture_output=True,
                timeout=120,
                cwd=str(self.command_cwd()),
                env=self.command_env(),
                check=False,
            )
            stderr_tail = command_tail(completed.stderr)
            ok = completed.returncode == 0 or "is not defined in the 'wp-config.php' file" in stderr_tail
            results.append(
                {
                    "constant": constant,
                    "exit_code": completed.returncode,
                    "status": "pass" if ok else "fail",
                    "stdout_tail": command_tail(completed.stdout),
                    "stderr_tail": stderr_tail,
                }
            )
            if not ok:
                failures.append(constant)

        self.record_phase(
            phase_id,
            {
                "status": "pass" if not failures else "fail",
                "started_at": started,
                "finished_at": utc_now(),
                "results": results,
            },
        )
        if failures:
            raise GateError(f"Failed to delete multisite constants: {', '.join(failures)}")

    def start_wp_env(self) -> None:
        phase_id = "start-disposable-wp-env"
        attempts: list[dict[str, Any]] = []
        started = utc_now()
        command = [str(self.wp_env_bin), "start", "--update"]
        for attempt in range(1, self.args.start_attempts + 1):
            self.progress(f"{phase_id} attempt {attempt}/{self.args.start_attempts}")
            try:
                completed = subprocess.run(
                    command,
                    text=True,
                    capture_output=True,
                    timeout=900,
                    cwd=str(self.command_cwd()),
                    env=self.command_env(),
                    check=False,
                )
            except subprocess.TimeoutExpired as exc:
                attempts.append(
                    {
                        "attempt": attempt,
                        "exit_code": 124,
                        "stdout_tail": command_tail(exc.stdout or ""),
                        "stderr_tail": command_tail(exc.stderr or ""),
                        "error": "timed out after 900s",
                    }
                )
                break

            stderr_tail = command_tail(completed.stderr)
            attempts.append(
                {
                    "attempt": attempt,
                    "exit_code": completed.returncode,
                    "stdout_tail": command_tail(completed.stdout),
                    "stderr_tail": stderr_tail,
                }
            )
            if completed.returncode == 0:
                self.wp_env_started = True
                self.record_phase(
                    phase_id,
                    {
                        "status": "pass",
                        "exit_code": 0,
                        "started_at": started,
                        "finished_at": utc_now(),
                        "command": shlex.join(command),
                        "attempts": attempts,
                    },
                )
                return

            if attempt >= self.args.start_attempts or not self.is_retryable_start_failure(stderr_tail):
                break
            time.sleep(2)

        self.record_phase(
            phase_id,
            {
                "status": "fail",
                "exit_code": attempts[-1].get("exit_code") if attempts else 1,
                "started_at": started,
                "finished_at": utc_now(),
                "command": shlex.join(command),
                "attempts": attempts,
                "error": "wp-env start failed",
            },
        )
        raise GateError(f"{phase_id} failed with exit {attempts[-1].get('exit_code') if attempts else 1}")

    @staticmethod
    def is_retryable_start_failure(stderr_tail: str) -> bool:
        return "failed to register layer" in stderr_tail and "file exists" in stderr_tail

    def run_state_probe(self, phase_id: str, url: str) -> dict[str, Any]:
        result = self.run_wp(
            phase_id,
            [f"--url={url}", "eval-file", "-"],
            input_text=(TOOLS_DIR / "a5g-multisite-runtime-state.php").read_text(encoding="utf-8"),
            parse_json=True,
            timeout_seconds=180,
        )
        payload = result["json"]
        self.state_snapshots[phase_id] = payload
        self.write_rollup()
        if not payload.get("ready"):
            raise GateError(f"{phase_id} probe reported failures: {payload.get('failures')}")
        return payload

    def assert_state(
        self,
        phase_id: str,
        state: dict[str, Any],
        *,
        owner: str,
        site_active: bool,
        network_active: bool,
    ) -> None:
        expected = {
            "is_multisite": True,
            "runtime_owner": owner,
            "native_runtime_enabled": True,
            "plugin_runtime_active": owner == "plugin",
            "should_native_register": owner == "native",
        }
        failures: list[str] = []
        for key, value in expected.items():
            if state.get(key) != value:
                failures.append(f"{key}: expected {value!r}, got {state.get(key)!r}")

        active_site_plugins = state.get("active_site_plugins", [])
        network_active_plugins = state.get("network_active_plugins", [])
        if (PLUGIN_FILE in active_site_plugins) != site_active:
            failures.append(f"site active plugin expected {site_active!r}, got {active_site_plugins!r}")
        if (PLUGIN_FILE in network_active_plugins) != network_active:
            failures.append(f"network active plugin expected {network_active!r}, got {network_active_plugins!r}")

        if failures:
            raise GateError(f"{phase_id} state mismatch: {'; '.join(failures)}")

    def write_wp_env_config(self) -> None:
        if not self.wp_env_bin.exists():
            raise GateError(f"wp-env binary not found at {self.wp_env_bin}")
        if not (self.repo / "plugins/woocommerce/woocommerce.php").exists():
            raise GateError(f"WooCommerce plugin not found in {self.repo}")
        if not (self.wcpay_repo / "woocommerce-payments.php").exists():
            raise GateError(f"WooPayments plugin not found in {self.wcpay_repo}")

        if self.args.runtime_mode == "existing-tests":
            self.record_phase(
                "use-existing-tests-wp-env",
                {
                    "status": "pass",
                    "path": str(self.existing_wp_env_dir),
                    "container": self.wp_container,
                    "reason": "fresh disposable wp-env can be blocked by local Docker phpmyadmin image pull; tests env is reset before and after the gate",
                },
            )
            return

        tmp_root = pathlib.Path(os.environ.get("TMPDIR") or tempfile.gettempdir()).resolve()
        self.work_dir = make_docker_reference_safe_temp_dir("a5g-wp-env-", tmp_root)
        config = {
            "core": "https://wordpress.org/wordpress-latest.zip",
            "phpVersion": "8.1",
            "port": self.args.port,
            "plugins": [
                str(self.repo / "plugins/woocommerce"),
                str(self.wcpay_repo),
            ],
            "config": {
                "JETPACK_AUTOLOAD_DEV": True,
                "WP_DEBUG_LOG": True,
                "WP_DEBUG_DISPLAY": False,
            },
            "env": {
                "tests": {
                    "port": self.args.port + 1,
                },
            },
        }
        write_json(self.work_dir / ".wp-env.json", config)
        self.record_phase("write-disposable-wp-env-config", {"status": "pass", "path": str(self.work_dir / ".wp-env.json")})

    def prepare_multisite(self) -> None:
        if self.args.runtime_mode == "existing-tests":
            self.reset_existing_tests_env("reset-existing-tests-wp-env-before-multisite")
            self.discover_existing_tests_urls()
        else:
            self.start_wp_env()
        self.run_wp(
            "convert-to-multisite",
            [
                "core",
                "multisite-convert",
                "--title=A5g Native Payments Network",
                "--base=/",
            ],
            timeout_seconds=240,
        )
        self.run_wp("activate-woocommerce-network", ["plugin", "activate", "woocommerce", "--network"], timeout_seconds=180)
        self.run_wp(
            "create-second-site",
            [
                "site",
                "create",
                "--slug=a5g-two",
                "--title=A5g Site Two",
                "--email=admin@example.test",
            ],
            timeout_seconds=180,
        )
        self.run_wp("ensure-woopayments-main-inactive", [f"--url={self.main_url}", "plugin", "deactivate", "woocommerce-payments"], timeout_seconds=180)
        self.run_wp("ensure-woopayments-second-inactive", [f"--url={self.second_url}", "plugin", "deactivate", "woocommerce-payments"], timeout_seconds=180)
        self.run_wp("ensure-woopayments-network-inactive", ["plugin", "deactivate", "woocommerce-payments", "--network"], timeout_seconds=180)

    def run_matrix(self) -> None:
        main = self.run_state_probe("baseline-main-native-owned", self.main_url)
        second = self.run_state_probe("baseline-second-native-owned", self.second_url)
        self.assert_state("baseline-main-native-owned", main, owner="native", site_active=False, network_active=False)
        self.assert_state("baseline-second-native-owned", second, owner="native", site_active=False, network_active=False)

        self.run_wp("activate-woopayments-main-site", [f"--url={self.main_url}", "plugin", "activate", "woocommerce-payments"], timeout_seconds=180)
        main = self.run_state_probe("site-active-main-plugin-owned", self.main_url)
        second = self.run_state_probe("site-active-second-native-owned", self.second_url)
        self.assert_state("site-active-main-plugin-owned", main, owner="plugin", site_active=True, network_active=False)
        self.assert_state("site-active-second-native-owned", second, owner="native", site_active=False, network_active=False)

        self.run_wp("deactivate-woopayments-main-site", [f"--url={self.main_url}", "plugin", "deactivate", "woocommerce-payments"], timeout_seconds=180)
        main = self.run_state_probe("after-site-deactivate-main-native-owned", self.main_url)
        second = self.run_state_probe("after-site-deactivate-second-native-owned", self.second_url)
        self.assert_state("after-site-deactivate-main-native-owned", main, owner="native", site_active=False, network_active=False)
        self.assert_state("after-site-deactivate-second-native-owned", second, owner="native", site_active=False, network_active=False)

        self.run_wp("activate-woopayments-network", ["plugin", "activate", "woocommerce-payments", "--network"], timeout_seconds=180)
        main = self.run_state_probe("network-active-main-plugin-owned", self.main_url)
        second = self.run_state_probe("network-active-second-plugin-owned", self.second_url)
        self.assert_state("network-active-main-plugin-owned", main, owner="plugin", site_active=False, network_active=True)
        self.assert_state("network-active-second-plugin-owned", second, owner="plugin", site_active=False, network_active=True)

        self.run_wp("deactivate-woopayments-network", ["plugin", "deactivate", "woocommerce-payments", "--network"], timeout_seconds=180)
        main = self.run_state_probe("after-network-deactivate-main-native-owned", self.main_url)
        second = self.run_state_probe("after-network-deactivate-second-native-owned", self.second_url)
        self.assert_state("after-network-deactivate-main-native-owned", main, owner="native", site_active=False, network_active=False)
        self.assert_state("after-network-deactivate-second-native-owned", second, owner="native", site_active=False, network_active=False)

    def cleanup(self) -> None:
        if self.args.keep_env:
            return
        cleanup_failed = False
        cleanup_error = "wp-env cleanup failed"
        if self.args.runtime_mode == "existing-tests":
            try:
                self.reset_existing_tests_env("reset-existing-tests-wp-env-after-multisite")
            except Exception as exc:
                cleanup_failed = True
                cleanup_error = str(exc)
        elif self.work_dir is not None and self.wp_env_started:
            result = self.run_wp_env("destroy-disposable-wp-env", ["destroy"], input_text="y\n", timeout_seconds=600, allow_failure=True)
            cleanup_failed = result.get("status") == "fail"
            cleanup_error = str(result.get("error") or "wp-env cleanup failed")
        if self.work_dir is not None:
            shutil.rmtree(self.work_dir, ignore_errors=True)
            self.work_dir = None
        self.write_rollup()
        if cleanup_failed:
            self.record_failure("cleanup", cleanup_error)
            raise GateError(cleanup_error)

    def run(self) -> None:
        run_error: Exception | None = None
        cleanup_error: Exception | None = None
        try:
            self.write_wp_env_config()
            self.prepare_multisite()
            self.run_matrix()
            self.status = "cleanup_pending"
            self.write_rollup()
        except Exception as exc:
            run_error = exc
            self.record_failure("a5g-multisite-runtime-gate", str(exc))
        try:
            self.cleanup()
        except Exception as exc:
            cleanup_error = exc
        if run_error is not None:
            if cleanup_error is not None:
                self.progress(f"cleanup also failed: {cleanup_error}")
            raise run_error
        if cleanup_error is not None:
            raise cleanup_error
        self.status = "pass"
        self.write_rollup()
        self.progress(f"A5g multisite runtime gate passed after cleanup: {self.rollup_path}")


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=str(REPO), help="WooCommerce working repo.")
    parser.add_argument("--wcpay-repo", required=True, help="Local WooPayments plugin repo.")
    parser.add_argument("--port", type=int, default=8891, help="Disposable wp-env development port.")
    parser.add_argument("--out-dir", default=str(DEFAULT_OUT_DIR), help="Evidence output directory.")
    parser.add_argument("--keep-env", action="store_true", help="Keep the disposable wp-env for debugging.")
    parser.add_argument("--start-attempts", type=int, default=2, help="Retry count for transient Docker startup failures.")
    parser.add_argument("--runtime-mode", choices=("disposable", "existing-tests"), default="disposable", help="Substrate for the multisite proof.")
    parser.add_argument("--existing-wp-env-dir", default=None, help="WooCommerce wp-env directory for --runtime-mode=existing-tests.")
    parser.add_argument("--existing-tests-url", default="http://store8889.localhost:8087", help="URL to reinstall for --runtime-mode=existing-tests.")
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(argv or sys.argv[1:])
    gate = MultisiteRuntimeGate(args)
    handled_signals = (signal.SIGHUP, signal.SIGINT, signal.SIGTERM)
    previous_handlers = {signum: signal.getsignal(signum) for signum in handled_signals}

    def handle_signal(signum: int, _frame: Any) -> None:
        raise GateSignal(signum)

    for signum in handled_signals:
        signal.signal(signum, handle_signal)
    try:
        gate.run()
    except GateSignal as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 128 + exc.signum
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr, flush=True)
        return 1
    finally:
        for signum, previous_handler in previous_handlers.items():
            signal.signal(signum, previous_handler)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
