#!/usr/bin/env python3
"""A4aq accumulated native WooPayments gate.

Local-only orchestration for the reopened A4/N12 boundary. This script does not
change product state by itself. It runs the existing source/chunk, browser,
bundle, perf, and log checks, writing an aggregate evidence file after every
step so long runs are inspectable while they proceed.
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import os
import re
import shlex
import subprocess
import sys
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

from perf_fixtures import PerfFixtureError, create_perf_fixtures as create_perf_fixture_summary
from perf_fixtures import validate_perf_order_fixture as validate_shared_perf_order_fixture
from local_runner_safety import LocalRunnerError
from local_runner_safety import validate_local_wp_command as validate_shared_local_wp_command


EXIT_FAIL = 1
EXIT_USAGE = 2
EXIT_INCOMPLETE = 3
EXIT_TIMEOUT = 124
SCHEMA = "woopayments_a4aq_accumulated_gate.v1"
WP_EVAL_TIMEOUT_SECONDS = 180
SCRIPT_DIR = Path(__file__).resolve().parent
DEFAULT_OUT_ROOT = Path(os.environ.get("TMPDIR", str(SCRIPT_DIR / ".tmp")))
DEFAULT_OUT_DIR = DEFAULT_OUT_ROOT / "woopayments-merge/a4aq"
LOCAL_ONLY_FORBIDDEN = ("wpcom.com", "wordpress.com", "a8c.com")
LOCAL_URL_HOSTS = {"localhost", "127.0.0.1", "store8889.localhost", "woopay.localhost"}
EXPECTED_BROWSER_HOSTS = {
    "target": ("store8889.localhost", 8889),
    "reference": ("localhost", 8082),
}
DIAGNOSTIC_RE = re.compile(
    r"PHP (?:Fatal|Warning|Notice|Deprecated|Parse)|Fatal error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace|Parse error|TypeError|ReferenceError|Database error",
    re.IGNORECASE,
)
SEVERE_DIAGNOSTIC_RE = re.compile(
    r"PHP (?:Fatal|Parse)|Fatal error|Uncaught|Stack trace|Parse error|TypeError|ReferenceError|Database error",
    re.IGNORECASE,
)
ACTUAL_5XX_RE = re.compile(r'"\s(?:5\d\d)\s')
WP67_EARLY_TEXTDOMAIN_NOTICE_RE = re.compile(
    r"_load_textdomain_just_in_time.*woocommerce|woocommerce.*triggered too early",
    re.IGNORECASE,
)
OPTIONAL_ADMIN_SURFACES = ("documents", "reports-fees", "card-readers", "capital")
OPTIONAL_ADMIN_VIEWPORTS = ("desktop", "mobile")
OPTIONAL_ADMIN_CHECKS = frozenset((surface, viewport) for surface in OPTIONAL_ADMIN_SURFACES for viewport in OPTIONAL_ADMIN_VIEWPORTS)
OPTIONAL_ADMIN_RESTORE_KEYS = (
    "card_present_eligible",
    "has_card_readers_available",
    "has_previous_capital_loans",
    "is_documents_enabled",
    "is_reports_enabled",
)
CHECKOUT_ROUTE_KEYS = (
    "blocksCheckoutCard",
    "blocksCheckoutExpress",
    "blocksCartExpress",
    "classicCheckoutCard",
    "classicAddPaymentMethod",
    "productExpress",
    "referenceBlocksCheckoutCard",
    "referenceBlocksCheckoutExpress",
    "referenceBlocksCartExpress",
    "referenceClassicCheckoutCard",
    "referenceClassicAddPaymentMethod",
    "referenceProductExpress",
)


def now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the A4aq accumulated WooPayments gate.")
    parser.add_argument("--repo", required=True, help="WooCommerce target repo path.")
    parser.add_argument("--plugin-repo", required=True, help="WooPayments plugin reference repo path.")
    parser.add_argument("--ref-wp", required=True, help="Reference WP-CLI command string.")
    parser.add_argument("--target-wp", required=True, help="Target WP-CLI command string.")
    parser.add_argument(
        "--browser-runner",
        choices=("playwriter", "playwright"),
        default=os.environ.get("BROWSER_RUNNER", "playwriter"),
        help="Browser runner for admin/checkout evidence gates.",
    )
    parser.add_argument(
        "--playwriter-session",
        default=os.environ.get("PLAYWRITER_SESSION", ""),
        help="Playwriter session id. Required only when --browser-runner=playwriter.",
    )
    parser.add_argument("--out-dir", default=str(DEFAULT_OUT_DIR), help="Evidence output directory.")
    parser.add_argument("--skip-admin-browser", action="store_true", help="Skip admin Playwriter gate.")
    parser.add_argument("--skip-optional-admin-scenario", action="store_true", help="Skip the optional account-state admin Playwriter scenario.")
    parser.add_argument("--skip-checkout-browser", action="store_true", help="Skip checkout Playwriter gate.")
    parser.add_argument("--skip-perf", action="store_true", help="Skip measured perf gate.")
    parser.add_argument("--skip-perf-fixtures", action="store_true", help="Skip local money fixture creation for perf probes.")
    parser.add_argument("--skip-bundle", action="store_true", help="Skip measured bundle gate.")
    return parser.parse_args()


def validate_local_wp_command(label: str, value: str) -> None:
    try:
        validate_shared_local_wp_command(label, value)
    except LocalRunnerError as exc:
        raise SystemExit(f"ERROR: {exc}") from exc


class Gate:
    def __init__(self, args: argparse.Namespace) -> None:
        self.repo = Path(args.repo).resolve()
        self.plugin_repo = Path(args.plugin_repo).resolve()
        self.ref_wp = args.ref_wp
        self.target_wp = args.target_wp
        self.browser_runner = str(args.browser_runner)
        self.playwriter_session = str(args.playwriter_session)
        self.out_dir = Path(args.out_dir).resolve()
        self.gate_slug = self.out_dir.name
        self.evidence_path = self.out_dir / "a4aq-accumulated-gate.json"
        self.started_at = now()
        self.checks: list[dict[str, Any]] = []
        self.failures: list[str] = []
        self.incomplete: list[str] = []
        self.limitations: list[str] = []
        self.log_baselines: dict[str, dict[str, Any]] = {}
        self.scripts_dir = self.repo / "tools/woopayments-merge"
        self.perf_fixtures: dict[str, dict[str, Any]] = {}
        self.required_optional_admin_checks: set[tuple[str, str]] = set()
        self.args = args

    def evidence(self, status: str = "running") -> dict[str, Any]:
        return {
            "schema": SCHEMA,
            "gate": "a4aq-accumulated-gate",
            "status": status,
            "started_at": self.started_at,
            "last_updated_at": now(),
            "environment": {
                "repo": str(self.repo),
                "plugin_repo": str(self.plugin_repo),
                "reference_url": "http://localhost:8082",
                "target_url": "http://store8889.localhost:8889",
                "browser_runner": self.browser_runner,
            },
            "checks": self.checks,
            "failures": self.failures,
            "incomplete": self.incomplete,
            "limitations": self.limitations,
        }

    def write_evidence(self, status: str = "running") -> None:
        self.out_dir.mkdir(parents=True, exist_ok=True)
        self.evidence_path.write_text(json.dumps(self.evidence(status), indent=2, sort_keys=True) + "\n", encoding="utf-8")

    def progress(self, index: int, total: int, check_id: str, state: str, **extra: Any) -> None:
        print(f"[a4aq] {index:02d}/{total:02d} {state.upper()} {check_id}", flush=True)
        payload = {"event": f"check_{state}", "id": check_id, "index": index, "total": total}
        payload.update(extra)
        print(json.dumps(payload, sort_keys=True), flush=True)

    def preflight(self) -> None:
        if not self.repo.is_dir():
            raise SystemExit(f"ERROR: repo not found: {self.repo}")
        if not self.plugin_repo.is_dir():
            raise SystemExit(f"ERROR: plugin repo not found: {self.plugin_repo}")
        if not self.scripts_dir.is_dir():
            raise SystemExit(f"ERROR: tools/woopayments-merge missing under {self.repo}")
        for label, value in (("repo", str(self.repo)), ("plugin-repo", str(self.plugin_repo))):
            lowered = value.lower()
            if any(token in lowered for token in LOCAL_ONLY_FORBIDDEN):
                raise SystemExit(f"ERROR: refusing non-local WPCOM/Automattic host in {label}")
        validate_local_wp_command("ref-wp", self.ref_wp)
        validate_local_wp_command("target-wp", self.target_wp)
        if self.browser_runner not in {"playwriter", "playwright"}:
            raise SystemExit(f"ERROR: unsupported browser runner: {self.browser_runner}")
        needs_browser = not self.args.skip_admin_browser or not self.args.skip_checkout_browser
        if needs_browser and self.browser_runner == "playwriter" and not self.playwriter_session:
            raise SystemExit("ERROR: --playwriter-session is required when --browser-runner=playwriter")
        self.out_dir.mkdir(parents=True, exist_ok=True)
        self.validate_runtime_containers()
        self.capture_log_baselines()
        self.write_evidence()

    def base_browser_state(self, gate_slug: str | None = None) -> dict[str, Any]:
        return {
            "gateSlug": gate_slug or self.gate_slug,
            "targetBase": "http://store8889.localhost:8889",
            "referenceBase": "http://localhost:8082",
        }

    def browser_state_env(
        self,
        state: dict[str, Any] | None = None,
        gate_slug: str | None = None,
        evidence_path: Path | None = None,
        data_dir: Path | None = None,
    ) -> dict[str, str]:
        merged_state = self.base_browser_state(gate_slug)
        merged_state.update(state or {})
        env = {"WOOPAYMENTS_GATE_SLUG": str(merged_state["gateSlug"])}
        if data_dir is not None:
            env["WOOPAYMENTS_BROWSER_DATA_DIR"] = str(data_dir)
            merged_state["dataDir"] = str(data_dir)
        if evidence_path is not None:
            env["WOOPAYMENTS_BROWSER_EVIDENCE_PATH"] = str(evidence_path)
            merged_state["evidencePath"] = str(evidence_path)
        if self.browser_runner == "playwright":
            env["PLAYWRIGHT_RUNNER_STATE_JSON"] = json.dumps(merged_state, sort_keys=True)
            env["WP_ADMIN_CREDENTIALS_JSON"] = json.dumps(
                {
                    "localhost:8082": {
                        "user": os.environ.get("REF_WP_ADMIN_USER", "admin"),
                        "password": os.environ.get("REF_WP_ADMIN_PASSWORD", "admin"),
                    },
                    "http://localhost:8082": {
                        "user": os.environ.get("REF_WP_ADMIN_USER", "admin"),
                        "password": os.environ.get("REF_WP_ADMIN_PASSWORD", "admin"),
                    },
                    "store8889.localhost:8889": {
                        "user": os.environ.get("TARGET_WP_ADMIN_USER", "admin"),
                        "password": os.environ.get("TARGET_WP_ADMIN_PASSWORD", "password"),
                    },
                    "http://store8889.localhost:8889": {
                        "user": os.environ.get("TARGET_WP_ADMIN_USER", "admin"),
                        "password": os.environ.get("TARGET_WP_ADMIN_PASSWORD", "password"),
                    },
                },
                sort_keys=True,
            )
        return env

    def browser_command(self, script: Path, timeout: int) -> list[str]:
        if self.browser_runner == "playwright":
            return [
                str(self.scripts_dir / "playwright-script-runner.mjs"),
                str(script),
                "--timeout",
                str(timeout),
            ]
        return [
            "npx",
            "playwriter@latest",
            "-s",
            self.playwriter_session,
            "--timeout",
            str(timeout),
            "-f",
            str(script),
        ]

    def browser_data_dir(self, check_id: str, gate_slug: str | None = None) -> Path:
        return self.out_dir / "browser" / (gate_slug or check_id)

    def admin_browser_evidence_path(self, gate_slug: str | None = None) -> Path:
        slug = gate_slug or self.gate_slug
        return self.out_dir / f"{slug}-admin-browser-gate.json"

    def checkout_browser_evidence_path(self) -> Path:
        return self.out_dir / f"{self.gate_slug}-checkout-browser-gate.json"

    def run(self, check_id: str, category: str, command: list[str], env: dict[str, str] | None = None) -> dict[str, Any]:
        started_at = now()
        result: dict[str, Any] = {
            "id": check_id,
            "category": category,
            "status": "running",
            "command": command,
            "started_at": started_at,
            "completed_at": None,
            "exit_code": None,
            "stdout_tail": "",
            "stderr_tail": "",
            "evidence_path": None,
            "failures": [],
            "incomplete_reasons": [],
        }
        self.checks.append(result)
        self.write_evidence()
        completed = subprocess.run(
            command,
            cwd=str(self.repo),
            env={**os.environ, **(env or {})},
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        result["exit_code"] = completed.returncode
        result["completed_at"] = now()
        result["stdout_tail"] = tail(completed.stdout)
        result["stderr_tail"] = tail(completed.stderr)
        if completed.returncode == 0:
            result["status"] = "pass"
        elif completed.returncode == EXIT_INCOMPLETE or (
            category == "perf" and completed.returncode == EXIT_USAGE
        ):
            result["status"] = "incomplete"
            reason = f"{check_id}: exited incomplete"
            result["incomplete_reasons"].append(reason)
            self.incomplete.append(reason)
        else:
            result["status"] = "fail"
            reason = f"{check_id}: exit {completed.returncode}"
            result["failures"].append(reason)
            self.failures.append(reason)
        self.write_evidence()
        return result

    def record_incomplete_check(
        self, check_id: str, category: str, command: list[str], reason: str
    ) -> dict[str, Any]:
        timestamp = now()
        result: dict[str, Any] = {
            "id": check_id,
            "category": category,
            "status": "incomplete",
            "command": command,
            "started_at": timestamp,
            "completed_at": timestamp,
            "exit_code": EXIT_INCOMPLETE,
            "stdout_tail": "",
            "stderr_tail": "",
            "evidence_path": None,
            "failures": [],
            "incomplete_reasons": [reason],
        }
        self.checks.append(result)
        self.incomplete.append(reason)
        self.write_evidence()
        return result

    def run_wp_eval_file(
        self,
        label: str,
        wp_cmd: str,
        script: Path,
        args: list[str] | None = None,
        timeout_seconds: float = WP_EVAL_TIMEOUT_SECONDS,
    ) -> dict[str, Any]:
        validate_local_wp_command(label, wp_cmd)
        if not script.is_file():
            raise RuntimeError(f"WP-CLI driver missing: {script}")
        command = shlex.split(wp_cmd) + ["eval-file", "-"] + [str(arg) for arg in (args or [])]
        try:
            completed = subprocess.run(
                command,
                cwd=str(self.repo),
                input=script.read_text(encoding="utf-8"),
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
                timeout=timeout_seconds,
            )
        except subprocess.TimeoutExpired as exc:
            return {
                "label": label,
                "command": command[:],
                "script": str(script),
                "args": args or [],
                "exit_code": EXIT_TIMEOUT,
                "stdout_tail": tail(exc.stdout),
                "stderr_tail": tail(f"{tail(exc.stderr)}\nWP-CLI eval-file timed out after {timeout_seconds:g}s."),
                "payload": None,
            }
        payload = parse_last_json(completed.stdout)
        return {
            "label": label,
            "command": command[:],
            "script": str(script),
            "args": args or [],
            "exit_code": completed.returncode,
            "stdout_tail": tail(completed.stdout),
            "stderr_tail": tail(completed.stderr),
            "payload": payload,
        }

    def apply_optional_admin_scenario(self, label: str, wp_cmd: str) -> dict[str, Any]:
        result = self.run_wp_eval_file(
            label,
            wp_cmd,
            self.scripts_dir / "a4-account-scenario.php",
            ["apply-optional-admin"],
        )
        if result["exit_code"] != 0:
            raise RuntimeError(f"{label} optional-admin scenario failed: {result['stderr_tail'] or result['stdout_tail']}")
        payload = result.get("payload")
        if not isinstance(payload, dict) or not payload.get("snapshot"):
            raise RuntimeError(f"{label} optional-admin scenario did not return a restorable snapshot")
        return result

    def restore_optional_admin_scenario(self, label: str, wp_cmd: str, snapshot: str) -> dict[str, Any]:
        return self.run_wp_eval_file(
            label,
            wp_cmd,
            self.scripts_dir / "a4-account-scenario.php",
            ["restore", snapshot],
        )

    def validate_optional_admin_restore(self, store: str, apply_payload: dict[str, Any], restore_payload: dict[str, Any]) -> None:
        if restore_payload.get("restored_snapshot_exact") is not True:
            raise RuntimeError(f"{store} optional-admin restore did not confirm exact snapshot restoration")
        before = apply_payload.get("before") if isinstance(apply_payload, dict) else None
        after = restore_payload.get("after") if isinstance(restore_payload, dict) else None
        if not isinstance(before, dict) or not isinstance(after, dict):
            raise RuntimeError(f"{store} optional-admin restore payload is missing before/after flags")
        mismatches = [
            key
            for key in OPTIONAL_ADMIN_RESTORE_KEYS
            if before.get(key) != after.get(key)
        ]
        if mismatches:
            raise RuntimeError(
                f"{store} optional-admin restore flags do not match the original snapshot for {', '.join(mismatches)}"
            )

    def reset_browser_selection_state(self) -> dict[str, Any]:
        if self.browser_runner == "playwright":
            return {
                "command": [],
                "exit_code": 0,
                "stdout_tail": "Playwright runner state is per-process; no persistent selection state reset needed.",
                "stderr_tail": "",
            }
        command = [
            "npx",
            "playwriter@latest",
            "-s",
            self.playwriter_session,
            "--timeout",
            "120000",
            "-e",
            (
                f"state.gateSlug = {json.dumps(self.gate_slug)}; "
                "state.targetBase = 'http://store8889.localhost:8889'; "
                "state.referenceBase = 'http://localhost:8082'; "
                "delete state.routes; delete state.skipNavigation; "
                "delete state.allowBaseOverrides; delete state.allowRouteOverrides; "
                "delete state.surfaceIds; delete state.viewportIds; delete state.storeIds; "
                "delete state.strictReferenceOptional; delete state.page;"
            ),
        ]
        result = subprocess.run(
            command,
            cwd=str(self.repo),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        return {
            "command": command,
            "exit_code": result.returncode,
            "stdout_tail": tail(result.stdout),
            "stderr_tail": tail(result.stderr),
        }

    def collect_checkout_fixture_routes(self) -> dict[str, Any]:
        routes: dict[str, str] = {}
        fixtures: dict[str, dict[str, Any]] = {}
        script = self.scripts_dir / "a4-checkout-fixture-state.php"
        for store, wp_cmd in (("target", self.target_wp), ("reference", self.ref_wp)):
            result = self.run_wp_eval_file(f"{store}-wp", wp_cmd, script, [store])
            payload = result.get("payload")
            if result["exit_code"] != 0:
                raise RuntimeError(f"{store} checkout fixture setup failed: {result['stderr_tail'] or result['stdout_tail']}")
            if not isinstance(payload, dict) or not isinstance(payload.get("routes"), dict):
                raise RuntimeError(f"{store} checkout fixture setup did not return routes")
            store_routes = {str(key): str(value) for key, value in payload["routes"].items()}
            routes.update(store_routes)
            fixtures[store] = {
                key: value
                for key, value in payload.items()
                if key != "routes"
            }

        missing = sorted(set(CHECKOUT_ROUTE_KEYS) - set(routes))
        if missing:
            raise RuntimeError(f"checkout fixture setup did not return required routes: {missing}")

        return {
            "routes": {key: routes[key] for key in CHECKOUT_ROUTE_KEYS},
            "fixtures": fixtures,
        }

    def set_checkout_browser_route_state(self) -> dict[str, Any]:
        fixture_state = self.collect_checkout_fixture_routes()
        if self.browser_runner == "playwright":
            return {
                "command": [],
                "exit_code": 0,
                "stdout_tail": "Checkout route overrides will be passed through PLAYWRIGHT_RUNNER_STATE_JSON.",
                "stderr_tail": "",
                "fixtures": fixture_state["fixtures"],
                "routes": fixture_state["routes"],
            }
        command = [
            "npx",
            "playwriter@latest",
            "-s",
            self.playwriter_session,
            "--timeout",
            "120000",
            "-e",
            (
                f"state.gateSlug = {json.dumps(self.gate_slug)}; "
                "state.targetBase = 'http://store8889.localhost:8889'; "
                "state.referenceBase = 'http://localhost:8082'; "
                f"state.routes = {json.dumps(fixture_state['routes'], sort_keys=True)}; "
                "state.allowRouteOverrides = true; "
                "delete state.skipNavigation; delete state.allowBaseOverrides; "
                "delete state.surfaceIds; delete state.viewportIds; delete state.storeIds; "
                "delete state.strictReferenceOptional; delete state.page;"
            ),
        ]
        result = subprocess.run(
            command,
            cwd=str(self.repo),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
        )
        return {
            "command": command,
            "exit_code": result.returncode,
            "stdout_tail": tail(result.stdout),
            "stderr_tail": tail(result.stderr),
            "fixtures": fixture_state["fixtures"],
            "routes": fixture_state["routes"],
        }

    def create_perf_fixtures(self, index: int, total: int) -> None:
        check_id = "perf-fixtures"
        summary: dict[str, Any] = {"stores": {}, "failures": [], "incomplete_reasons": []}
        entry = {
            "id": check_id,
            "category": "perf",
            "status": "running",
            "command": [],
            "started_at": now(),
            "completed_at": None,
            "exit_code": None,
            "summary": summary,
            "failures": [],
            "incomplete_reasons": [],
        }
        self.checks.append(entry)
        self.write_evidence()

        try:
            staged = create_perf_fixture_summary(self.repo, self.scripts_dir, self.ref_wp, self.target_wp)
            summary["stores"] = staged["stores"]
            self.perf_fixtures = summary["stores"]
            entry["status"] = "pass"
            entry["exit_code"] = 0
        except PerfFixtureError as exc:
            entry["status"] = "incomplete"
            reason = f"{check_id}: {exc}"
            entry.setdefault("incomplete_reasons", []).append(reason)
            self.incomplete.append(reason)
        finally:
            entry["completed_at"] = now()
            self.write_evidence()
            self.progress(index, total, check_id, "end", status=entry["status"], exit_code=entry["exit_code"])

    def validate_perf_order_fixture(self, store: str, kind: str, order: dict[str, Any]) -> None:
        validate_shared_perf_order_fixture(store, kind, order)

    def with_perf_fixture_args(self, check_id: str, command: list[str]) -> list[str]:
        store = "reference" if check_id == "perf-reference" else "target" if check_id == "perf-target" else ""
        fixtures = self.perf_fixtures.get(store)
        if not fixtures:
            return command
        order_ids = fixtures.get("order_ids", {})
        return command + [
            "--process-order-id",
            str(order_ids.get("process_payment") or ""),
            "--refund-order-id",
            str(order_ids.get("refund") or ""),
            "--capture-order-id",
            str(order_ids.get("capture") or ""),
        ]

    def record_manual(self, check_id: str, category: str, status: str, summary: dict[str, Any]) -> None:
        entry = {
            "id": check_id,
            "category": category,
            "status": status,
            "command": [],
            "started_at": now(),
            "completed_at": now(),
            "exit_code": 0 if status == "pass" else None,
            "summary": summary,
            "failures": summary.get("failures", []),
            "incomplete_reasons": summary.get("incomplete_reasons", []),
        }
        self.checks.append(entry)
        for failure in entry["failures"]:
            self.failures.append(f"{check_id}: {failure}")
        for reason in entry["incomplete_reasons"]:
            self.incomplete.append(f"{check_id}: {reason}")
        self.write_evidence()

    def run_optional_admin_browser_scenario(self, index: int, total: int) -> None:
        check_id = "admin-browser-optional-account"
        evidence_slug = f"{self.gate_slug}-optional-admin"
        evidence_path = self.admin_browser_evidence_path(evidence_slug)
        data_dir = self.browser_data_dir(check_id, evidence_slug)
        summary: dict[str, Any] = {
            "scenario": "optional-admin-account-capabilities",
            "evidence_path": str(evidence_path),
            "applies": {},
            "restores": {},
            "reference_control": "not mutated; this scenario closes target protected-route coverage only because local cache flags cannot synthesize real reference Capital loan platform data",
            "failures": [],
            "incomplete_reasons": [],
        }
        snapshots: dict[str, str] = {}
        entry = {
            "id": check_id,
            "category": "admin",
            "status": "running",
            "command": [],
            "started_at": now(),
            "completed_at": None,
            "exit_code": None,
            "summary": summary,
            "evidence_path": str(evidence_path),
            "failures": [],
            "incomplete_reasons": [],
        }
        self.checks.append(entry)
        self.write_evidence()

        try:
            for store, wp_cmd in (("target", self.target_wp),):
                apply_result = self.apply_optional_admin_scenario(f"{store}-wp", wp_cmd)
                payload = apply_result["payload"]
                snapshots[store] = str(payload["snapshot"])
                summary["applies"][store] = redact_snapshot_payload(payload)

            optional_state = {
                "surfaceIds": ["documents", "reports-fees", "card-readers", "capital"],
                "storeIds": ["target"],
            }
            if self.browser_runner == "playwriter":
                state_command = [
                    "npx",
                    "playwriter@latest",
                    "-s",
                    self.playwriter_session,
                    "--timeout",
                    "120000",
                    "-e",
                    (
                        f"state.gateSlug = {json.dumps(evidence_slug)}; "
                        f"state.dataDir = {json.dumps(str(data_dir))}; "
                        f"state.evidencePath = {json.dumps(str(evidence_path))}; "
                        "state.targetBase = 'http://store8889.localhost:8889'; "
                        "state.referenceBase = 'http://localhost:8082'; "
                        "state.surfaceIds = [ 'documents', 'reports-fees', 'card-readers', 'capital' ]; "
                        "state.storeIds = [ 'target' ]; "
                        "delete state.viewportIds; delete state.routes; delete state.skipNavigation; "
                        "delete state.allowBaseOverrides; delete state.allowRouteOverrides; "
                        "delete state.strictReferenceOptional; delete state.page;"
                    ),
                ]
            else:
                state_command = []
            browser_command = self.browser_command(self.scripts_dir / "a4-admin-browser-gate.playwriter.mjs", 900000)
            entry["command"] = [state_command, browser_command]

            if self.browser_runner == "playwriter":
                state_result = subprocess.run(
                    state_command,
                    cwd=str(self.repo),
                    text=True,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    check=False,
                )
                summary["state_stdout_tail"] = tail(state_result.stdout)
                summary["state_stderr_tail"] = tail(state_result.stderr)
                if state_result.returncode != 0:
                    entry["exit_code"] = state_result.returncode
                    raise RuntimeError(f"optional admin Playwriter state setup failed: {state_result.stderr or state_result.stdout}")
            else:
                state_result = subprocess.CompletedProcess(state_command, 0, stdout="", stderr="")
                summary["state_stdout_tail"] = "Playwright runner state is passed through PLAYWRIGHT_RUNNER_STATE_JSON."
                summary["state_stderr_tail"] = ""

            browser_result = subprocess.run(
                browser_command,
                cwd=str(self.repo),
                env={
                    **os.environ,
                    **self.browser_state_env(
                        optional_state,
                        gate_slug=evidence_slug,
                        evidence_path=evidence_path,
                        data_dir=data_dir,
                    ),
                },
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                check=False,
            )
            entry["exit_code"] = browser_result.returncode
            summary["browser_stdout_tail"] = tail(browser_result.stdout)
            summary["browser_stderr_tail"] = tail(browser_result.stderr)
            if browser_result.returncode != 0:
                entry["status"] = "fail"
                reason = f"{check_id}: exit {browser_result.returncode}"
                entry["failures"].append(reason)
                self.failures.append(reason)
            else:
                entry["status"] = "pass"
                self.validate_browser_evidence(check_id, evidence_path, entry)
                self.validate_optional_admin_coverage(evidence_path, entry)
        except Exception as exc:
            entry["status"] = "incomplete" if entry["status"] != "fail" else entry["status"]
            reason = f"{check_id}: {exc}"
            entry.setdefault("incomplete_reasons", []).append(reason)
            self.incomplete.append(reason)
        finally:
            for store, wp_cmd in (("target", self.target_wp),):
                snapshot = snapshots.get(store)
                if not snapshot:
                    continue
                restore_result = self.restore_optional_admin_scenario(f"{store}-wp", wp_cmd, snapshot)
                payload = restore_result.get("payload")
                summary["restores"][store] = redact_snapshot_payload(payload) if isinstance(payload, dict) else {
                    "exit_code": restore_result["exit_code"],
                    "stdout_tail": restore_result["stdout_tail"],
                    "stderr_tail": restore_result["stderr_tail"],
                }
                if restore_result["exit_code"] != 0:
                    entry["status"] = "fail"
                    reason = f"{check_id}: failed to restore {store} account cache"
                    entry.setdefault("failures", []).append(reason)
                    self.failures.append(reason)
                else:
                    try:
                        self.validate_optional_admin_restore(
                            store,
                            summary["applies"].get(store, {}),
                            payload if isinstance(payload, dict) else {},
                        )
                    except RuntimeError as exc:
                        entry["status"] = "fail"
                        reason = f"{check_id}: {exc}"
                        entry.setdefault("failures", []).append(reason)
                        self.failures.append(reason)

            state_reset_result = self.reset_browser_selection_state()
            summary["selection_state_reset"] = state_reset_result
            if state_reset_result["exit_code"] != 0:
                entry["status"] = "fail"
                reason = f"{check_id}: failed to reset Playwriter selection state"
                entry.setdefault("failures", []).append(reason)
                self.failures.append(reason)

            entry["completed_at"] = now()
            if entry["status"] == "running":
                entry["status"] = "pass"
            self.write_evidence()
            self.progress(index, total, check_id, "end", status=entry["status"], exit_code=entry["exit_code"])

    def run_all(self) -> int:
        checks = self.build_checks()
        total = len(checks) + 1
        if not self.args.skip_admin_browser and not self.args.skip_optional_admin_scenario:
            total += 1
        if not self.args.skip_perf and not self.args.skip_perf_fixtures:
            total += 1
        index = 0
        completed_checks: dict[str, dict[str, Any]] = {}
        for check_id, category, command, env, evidence_path in checks:
            if check_id == "perf-reference" and not self.args.skip_perf_fixtures and not self.perf_fixtures:
                index += 1
                self.progress(index, total, "perf-fixtures", "start")
                self.create_perf_fixtures(index, total)

            index += 1
            if check_id in {"perf-reference", "perf-target"}:
                command = self.with_perf_fixture_args(check_id, command)
            self.progress(index, total, check_id, "start", evidence_path=str(evidence_path) if evidence_path else None)
            checkout_route_state = None
            if check_id == "checkout-browser":
                try:
                    checkout_route_state = self.set_checkout_browser_route_state()
                    if self.browser_runner == "playwright" and checkout_route_state["exit_code"] == 0:
                        env = {
                            **(env or {}),
                            **self.browser_state_env(
                                {
                                    "routes": checkout_route_state["routes"],
                                    "allowRouteOverrides": True,
                                },
                                evidence_path=evidence_path,
                                data_dir=self.browser_data_dir(check_id),
                            ),
                        }
                except Exception as exc:
                    checkout_route_state = {
                        "command": [],
                        "exit_code": EXIT_INCOMPLETE,
                        "stdout_tail": "",
                        "stderr_tail": str(exc),
                    }
            if check_id == "perf-compare" and (
                any(
                    completed_checks.get(capture_id, {}).get("status") != "pass"
                    for capture_id in ("perf-reference", "perf-target")
                )
                or any(
                    not path.is_file() or path.stat().st_size == 0
                    for path in (
                        self.out_dir / "perf-reference.json",
                        self.out_dir / "perf-target.json",
                    )
                )
            ):
                result = self.record_incomplete_check(
                    check_id,
                    category,
                    command,
                    "perf-compare: requires two successful nonempty captures",
                )
            else:
                result = self.run(check_id, category, command, env)
            if checkout_route_state is not None:
                result["route_state"] = checkout_route_state
                if checkout_route_state["exit_code"] != 0:
                    self.mark_check_failure(result, f"{check_id}: failed to set checkout route overrides")
            if evidence_path is not None:
                result["evidence_path"] = str(evidence_path)
            if check_id in {"admin-browser", "checkout-browser"} and evidence_path is not None:
                self.validate_browser_evidence(check_id, evidence_path, result)
            self.write_evidence()
            self.progress(index, total, check_id, "end", status=result["status"], exit_code=result["exit_code"])
            completed_checks[check_id] = result

            if check_id == "admin-browser" and not self.args.skip_optional_admin_scenario:
                index += 1
                self.progress(index, total, "admin-browser-optional-account", "start", evidence_path=str(self.admin_browser_evidence_path(f"{self.gate_slug}-optional-admin")))
                self.run_optional_admin_browser_scenario(index, total)

        self.progress(total, total, "log-scan", "start")
        log_status = self.scan_logs()
        self.progress(total, total, "log-scan", "end", status=log_status)

        status = "pass"
        exit_code = 0
        if self.failures:
            status = "fail"
            exit_code = EXIT_FAIL
        elif self.incomplete:
            status = "incomplete"
            exit_code = EXIT_INCOMPLETE
        self.write_evidence(status)
        print(json.dumps({"evidence_path": str(self.evidence_path), "status": status, "failures": len(self.failures), "incomplete": len(self.incomplete)}, sort_keys=True))
        return exit_code

    def build_checks(self) -> list[tuple[str, str, list[str], dict[str, str] | None, Path | None]]:
        checks: list[tuple[str, str, list[str], dict[str, str] | None, Path | None]] = []
        admin_source = self.out_dir / "admin-source.json"
        checks.append(
            (
                "admin-source",
                "admin",
                [
                    sys.executable,
                    str(self.scripts_dir / "a4-admin-surface-gate.py"),
                    "--repo",
                    str(self.repo),
                    "--plugin-repo",
                    str(self.plugin_repo),
                    "--out",
                    str(admin_source),
                ],
                None,
                admin_source,
            )
        )
        if self.browser_runner == "playwriter" and (not self.args.skip_admin_browser or not self.args.skip_checkout_browser):
            checks.append(
                (
                    "playwriter-state",
                    "browser",
                    [
                        "npx",
                        "playwriter@latest",
                        "-s",
                        self.playwriter_session,
                        "--timeout",
                        "120000",
                        "-e",
                        (
                            f"state.gateSlug = {json.dumps(self.gate_slug)}; "
                            "state.targetBase = 'http://store8889.localhost:8889'; "
                            "state.referenceBase = 'http://localhost:8082'; "
                            "delete state.routes; delete state.skipNavigation; "
                            "delete state.allowBaseOverrides; delete state.allowRouteOverrides; "
                            "delete state.surfaceIds; delete state.viewportIds; delete state.storeIds; "
                            "delete state.strictReferenceOptional; delete state.page;"
                        ),
                    ],
                    None,
                    None,
                )
            )
        if not self.args.skip_admin_browser:
            evidence_path = self.admin_browser_evidence_path()
            checks.append(
                (
                    "admin-browser",
                    "admin",
                    self.browser_command(self.scripts_dir / "a4-admin-browser-gate.playwriter.mjs", 900000),
                    self.browser_state_env(
                        evidence_path=evidence_path,
                        data_dir=self.browser_data_dir("admin-browser"),
                    ),
                    evidence_path,
                )
            )
        if not self.args.skip_checkout_browser:
            evidence_path = self.checkout_browser_evidence_path()
            checks.append(
                (
                    "checkout-browser",
                    "checkout",
                    self.browser_command(self.scripts_dir / "a4-checkout-browser-gate.playwriter.mjs", 900000),
                    self.browser_state_env(
                        evidence_path=evidence_path,
                        data_dir=self.browser_data_dir("checkout-browser"),
                    ),
                    evidence_path,
                )
            )
        if not self.args.skip_bundle:
            ref_bundle = self.out_dir / "bundle-reference.json"
            target_bundle = self.out_dir / "bundle-target.json"
            budget = self.scripts_dir / "a4aq-bundle-budget.json"
            checks.extend(
                [
                    (
                        "bundle-reference",
                        "bundle",
                        [
                            "bash",
                            str(self.scripts_dir / "bundle-size-gate.sh"),
                            "capture",
                            "--repo",
                            str(self.plugin_repo),
                            "--profile",
                            "wcpay-plugin",
                            "--out",
                            str(ref_bundle),
                        ],
                        None,
                        ref_bundle,
                    ),
                    (
                        "bundle-target",
                        "bundle",
                        [
                            "bash",
                            str(self.scripts_dir / "bundle-size-gate.sh"),
                            "capture",
                            "--repo",
                            str(self.repo),
                            "--profile",
                            "wc-core",
                            "--out",
                            str(target_bundle),
                        ],
                        None,
                        target_bundle,
                    ),
                    (
                        "bundle-compare",
                        "bundle",
                        [
                            "bash",
                            str(self.scripts_dir / "bundle-size-gate.sh"),
                            "compare",
                            "--ref",
                            str(ref_bundle),
                            "--target",
                            str(target_bundle),
                            "--budget",
                            str(budget),
                        ],
                        None,
                        None,
                    ),
                ]
            )
        if not self.args.skip_perf:
            ref_perf = self.out_dir / "perf-reference.json"
            target_perf = self.out_dir / "perf-target.json"
            checks.extend(
                [
                    (
                        "perf-reference",
                        "perf",
                        [
                            "bash",
                            str(self.scripts_dir / "perf-surface-gate.sh"),
                            "capture",
                            "--wp",
                            self.ref_wp,
                            "--out",
                            str(ref_perf),
                        ],
                        None,
                        ref_perf,
                    ),
                    (
                        "perf-target",
                        "perf",
                        [
                            "bash",
                            str(self.scripts_dir / "perf-surface-gate.sh"),
                            "capture",
                            "--wp",
                            self.target_wp,
                            "--out",
                            str(target_perf),
                        ],
                        None,
                        target_perf,
                    ),
                    (
                        "perf-compare",
                        "perf",
                        [
                            "bash",
                            str(self.scripts_dir / "perf-surface-gate.sh"),
                            "compare",
                            "--ref",
                            str(ref_perf),
                            "--target",
                            str(target_perf),
                        ],
                        None,
                        None,
                    ),
                ]
            )
        return checks

    def scan_logs(self) -> str:
        started_since = dt.datetime.fromisoformat(self.started_at).astimezone(dt.timezone.utc)
        since_arg = started_since.strftime("%Y-%m-%dT%H:%M:%SZ")
        findings: list[dict[str, Any]] = []
        target_diagnostics: list[str] = []
        reference_severe_diagnostics: list[str] = []
        missing_logs: list[str] = []
        for label, fallback in (("target", self.find_container(port="8889")), ("reference", self.find_container(name="wcpay_wp_default"))):
            baseline = self.log_baselines.get(label, {})
            container = baseline.get("container") or fallback
            if not container:
                findings.append({"label": label, "status": "incomplete", "reason": "container not found"})
                missing_logs.append(f"{label} container not found")
                continue
            docker_logs = run_text_result(["docker", "logs", "--since", since_arg, container])
            diagnostics = matching_lines(docker_logs["output"], DIAGNOSTIC_RE)
            server_errors = matching_lines(docker_logs["output"], ACTUAL_5XX_RE)
            debug_log = self.read_debug_log_since(container, int(baseline.get("debug_bytes") or 0))
            if docker_logs["returncode"] != 0:
                missing_logs.append(f"{label} docker logs failed: {docker_logs['output'][:300]}")
            if debug_log["returncode"] != 0:
                missing_logs.append(f"{label} debug.log read failed: {debug_log['output'][:300]}")
            debug_diagnostics = matching_lines(debug_log["output"], DIAGNOSTIC_RE)
            severe_debug_diagnostics = matching_lines(debug_log["output"], SEVERE_DIAGNOSTIC_RE)
            severe_docker_diagnostics = matching_lines(docker_logs["output"], SEVERE_DIAGNOSTIC_RE)
            active_diagnostics, ignored_diagnostics = filter_ignored_diagnostics(diagnostics)
            active_debug_diagnostics, ignored_debug_diagnostics = filter_ignored_diagnostics(debug_diagnostics)
            active_severe_docker_diagnostics, ignored_severe_docker_diagnostics = filter_ignored_diagnostics(severe_docker_diagnostics)
            active_severe_debug_diagnostics, ignored_severe_debug_diagnostics = filter_ignored_diagnostics(severe_debug_diagnostics)
            entry = {
                "label": label,
                "container": container,
                "docker_logs_returncode": docker_logs["returncode"],
                "debug_log_returncode": debug_log["returncode"],
                "debug_log_baseline_bytes": baseline.get("debug_bytes"),
                "docker_diagnostics": active_diagnostics,
                "docker_5xx": server_errors,
                "docker_severe_diagnostics": active_severe_docker_diagnostics,
                "debug_severe_diagnostics": active_severe_debug_diagnostics,
                "debug_diagnostics": active_debug_diagnostics,
                "ignored_diagnostics": merge_ignored_diagnostics(
                    ignored_diagnostics,
                    ignored_debug_diagnostics,
                    ignored_severe_docker_diagnostics,
                    ignored_severe_debug_diagnostics,
                ),
            }
            findings.append(entry)
            if label == "target":
                for line in active_diagnostics + server_errors + active_debug_diagnostics:
                    target_diagnostics.append(line[:300])
            elif active_diagnostics or server_errors or active_debug_diagnostics:
                for line in active_severe_docker_diagnostics + server_errors + active_severe_debug_diagnostics:
                    reference_severe_diagnostics.append(line[:300])
                self.limitations.append(f"reference log scan has {len(active_diagnostics) + len(server_errors) + len(active_debug_diagnostics)} diagnostic lines; inspect log-scan evidence")
        target_state = {"diagnostics": target_diagnostics, "server_errors": [], "debug_diagnostics": []}
        reference_state = {"diagnostics": reference_severe_diagnostics, "server_errors": [], "debug_diagnostics": []}
        status = self.evaluate_log_status(target_state, reference_state, missing_logs)
        self.record_manual(
            "log-scan",
            "logs",
            status,
            {
                "since": since_arg,
                "findings": findings,
                "failures": [f"target diagnostic: {line}" for line in target_diagnostics]
                + [f"reference severe diagnostic: {line}" for line in reference_severe_diagnostics],
                "incomplete_reasons": missing_logs,
            },
        )
        return status

    def evaluate_log_status(self, target: dict[str, Any], reference: dict[str, Any], missing_logs: list[str]) -> str:
        target_diagnostics, _target_ignored = filter_ignored_diagnostics(
            list(target.get("diagnostics", [])) + list(target.get("server_errors", [])) + list(target.get("debug_diagnostics", []))
        )
        reference_severe, _reference_ignored = filter_ignored_diagnostics(
            list(reference.get("diagnostics", [])) + list(reference.get("server_errors", [])) + list(reference.get("debug_diagnostics", []))
        )
        if target_diagnostics or reference_severe:
            return "fail"
        if missing_logs:
            return "incomplete"
        return "pass"

    def validate_browser_evidence(self, check_id: str, evidence_path: Path, result: dict[str, Any]) -> None:
        try:
            evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            self.mark_check_failure(result, f"{check_id}: cannot read browser evidence {evidence_path}: {exc}")
            return

        failures: list[str] = []
        unavailable_guard_checks: set[tuple[str, str]] = set()
        results = evidence.get("results", [])
        if not isinstance(results, list):
            self.mark_check_failure(result, f"{check_id}: browser evidence has no results array")
            return

        if check_id == "checkout-browser":
            evidence_failures = evidence.get("failures", [])
            if isinstance(evidence_failures, list):
                if evidence_failures:
                    failures.append(f"{check_id}: browser evidence has {len(evidence_failures)} actionable failures")
            elif evidence_failures is not None:
                failures.append(f"{check_id}: browser evidence failures field is not an array")

            if evidence.get("status") == "incomplete":
                blockers = evidence.get("blockers", [])
                blocker_count = len(blockers) if isinstance(blockers, list) else 0
                self.mark_check_incomplete(
                    result,
                    f"{check_id}: browser evidence is incomplete; blockers={blocker_count}",
                )

        for item in results:
            if not isinstance(item, dict):
                failures.append(f"{check_id}: invalid result item")
                continue
            store = item.get("store")
            if store in EXPECTED_BROWSER_HOSTS:
                for key in ("requestedUrl", "finalUrl"):
                    url = item.get(key)
                    if isinstance(url, str) and not browser_url_is_expected(store, url):
                        failures.append(f"{check_id}: {store} {key} is outside expected local host: {url}")
            if check_id in {"admin-browser", "admin-browser-optional-account"} and item.get("store") == "target" and item.get("coverageStatus") == "unavailable-guard-pass":
                surface = item.get("surface")
                viewport = item.get("viewport")
                unavailable_guard_checks.add((str(surface), str(viewport)))

        if check_id in {"admin-browser", "admin-browser-optional-account"} and "target" in evidence.get("stores", []):
            has_navigation = any(item.get("store") == "target" and item.get("surface") == "admin-navigation" for item in results if isinstance(item, dict))
            if not has_navigation:
                failures.append("admin-browser: target admin-navigation proof is missing")

        if unavailable_guard_checks:
            reason = f"{check_id}: {len(unavailable_guard_checks)} target protected route checks were unavailable-guard passes, not available-route parity passes"
            self.limitations.append(reason)
            result.setdefault("coverage_gaps", []).append(reason)
            if check_id == "admin-browser" and not self.args.skip_optional_admin_scenario:
                unexpected = sorted(unavailable_guard_checks - OPTIONAL_ADMIN_CHECKS)
                missing = sorted(OPTIONAL_ADMIN_CHECKS - unavailable_guard_checks)
                if unexpected or missing:
                    failures.append(
                        "admin-browser: unexpected unavailable target admin routes "
                        f"unexpected={unexpected} missing_expected={missing}"
                    )
                else:
                    self.required_optional_admin_checks = set(OPTIONAL_ADMIN_CHECKS)
            else:
                self.mark_check_incomplete(result, reason)

        for failure in failures:
            self.mark_check_failure(result, failure)

    def validate_optional_admin_coverage(self, evidence_path: Path, result: dict[str, Any]) -> None:
        required = self.required_optional_admin_checks or set(OPTIONAL_ADMIN_CHECKS)
        try:
            evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            self.mark_check_failure(result, f"admin-browser-optional-account: cannot read optional evidence {evidence_path}: {exc}")
            return

        available_checks: set[tuple[str, str]] = set()
        for item in evidence.get("results", []):
            if not isinstance(item, dict) or item.get("store") != "target":
                continue
            key = (str(item.get("surface")), str(item.get("viewport")))
            if key in required and item.get("passed") is True and item.get("coverageStatus") == "available-route-pass" and item.get("targetSurfaceAvailable") is not False:
                available_checks.add(key)

        missing = sorted(required - available_checks)
        if missing:
            self.mark_check_failure(
                result,
                f"admin-browser-optional-account: missing available-route proof for {missing}",
            )

    def mark_check_failure(self, result: dict[str, Any], failure: str) -> None:
        result["status"] = "fail"
        result.setdefault("failures", []).append(failure)
        self.failures.append(failure)

    def mark_check_incomplete(self, result: dict[str, Any], reason: str) -> None:
        if result.get("status") != "fail":
            result["status"] = "incomplete"
        result.setdefault("incomplete_reasons", []).append(reason)
        self.incomplete.append(reason)

    def capture_log_baselines(self) -> None:
        for label in ("target", "reference"):
            container = self.resolve_web_container(label)
            if not container:
                self.log_baselines[label] = {"container": None, "debug_bytes": None, "status": "container_not_found"}
                continue
            self.log_baselines[label] = {
                "container": container,
                "debug_bytes": self.debug_log_size(container),
                "status": "captured",
            }

    def resolve_web_container(self, label: str) -> str | None:
        if label == "target":
            cli_container = docker_exec_container(self.target_wp)
            if cli_container and cli_container.endswith("-cli-1"):
                candidate = f"{cli_container[:-len('-cli-1')]}-wordpress-1"
                if self.container_has_port(candidate, "8889"):
                    return candidate
            return self.find_container(port="8889")

        cli_container = docker_exec_container(self.ref_wp)
        if cli_container and self.container_has_port(cli_container, "8082"):
            return cli_container
        return self.find_container(name="wcpay_wp_default")

    def validate_runtime_containers(self) -> None:
        running_containers = set(run_text(["docker", "ps", "--format", "{{.Names}}"]).splitlines())
        ref_container = docker_exec_container(self.ref_wp)
        target_cli_container = docker_exec_container(self.target_wp)
        if not ref_container or ref_container not in running_containers:
            raise SystemExit(f"ERROR: ref-wp container is not running: {ref_container or '<unknown>'}")
        if not target_cli_container or target_cli_container not in running_containers:
            raise SystemExit(f"ERROR: target-wp container is not running: {target_cli_container or '<unknown>'}")
        if not self.container_has_port(ref_container, "8082"):
            raise SystemExit(f"ERROR: ref-wp container must expose the local reference store on :8082, got {ref_container}")

        target_web_container = f"{target_cli_container[:-len('-cli-1')]}-wordpress-1"
        if target_web_container not in running_containers:
            raise SystemExit(f"ERROR: target-wp web container is not running: {target_web_container}")
        if not self.container_has_port(target_web_container, "8889"):
            raise SystemExit(
                f"ERROR: target-wp must pair with the local target store on :8889, got {target_cli_container}"
            )

    def container_has_port(self, container_name: str, port: str) -> bool:
        output = run_text(["docker", "ps", "--format", "{{.Names}}\t{{.Ports}}"])
        for line in output.splitlines():
            parts = line.split("\t", 1)
            if parts[0] == container_name and f":{port}->" in (parts[1] if len(parts) > 1 else ""):
                return True
        return False

    def debug_log_size(self, container: str) -> int | None:
        result = run_text_result(
            [
                "docker",
                "exec",
                container,
                "sh",
                "-lc",
                "if [ -f /var/www/html/wp-content/debug.log ]; then wc -c < /var/www/html/wp-content/debug.log; else echo 0; fi",
            ]
        )
        if result["returncode"] != 0:
            return None
        try:
            return int(result["output"].strip() or "0")
        except ValueError:
            return None

    def read_debug_log_since(self, container: str, offset: int) -> dict[str, Any]:
        script = (
            "file=/var/www/html/wp-content/debug.log; "
            "if [ -f \"$file\" ]; then "
            f"size=$(wc -c < \"$file\"); if [ \"$size\" -lt {offset} ]; then start=1; else start=$(({offset}+1)); fi; "
            "tail -c +\"$start\" \"$file\"; "
            "fi"
        )
        return run_text_result(["docker", "exec", container, "sh", "-lc", script])

    def find_container(self, port: str | None = None, name: str | None = None) -> str | None:
        output = run_text(["docker", "ps", "--format", "{{.Names}}\t{{.Ports}}"])
        for line in output.splitlines():
            parts = line.split("\t", 1)
            container = parts[0]
            ports = parts[1] if len(parts) > 1 else ""
            if name and container == name:
                return container
            if port and f":{port}->" in ports:
                return container
        return None


def tail(text: str | bytes | None, limit: int = 6000) -> str:
    if text is None:
        return ""
    if isinstance(text, bytes):
        text = text.decode("utf-8", errors="replace")
    if len(text) <= limit:
        return text
    return text[-limit:]


def filter_ignored_diagnostics(lines: list[str]) -> tuple[list[str], dict[str, list[str]]]:
    active: list[str] = []
    ignored: dict[str, list[str]] = {}
    for line in lines:
        if WP67_EARLY_TEXTDOMAIN_NOTICE_RE.search(line):
            ignored.setdefault("wp67_early_textdomain_notice", []).append(line)
            continue
        active.append(line)
    return active, ignored


def merge_ignored_diagnostics(*groups: dict[str, list[str]]) -> dict[str, list[str]]:
    merged: dict[str, list[str]] = {}
    for group in groups:
        for key, lines in group.items():
            merged.setdefault(key, []).extend(lines)
    return merged


def parse_last_json(text: str) -> Any:
    for line in reversed(text.splitlines()):
        stripped = line.strip()
        if not stripped or stripped[0] not in "[{":
            continue
        try:
            return json.loads(stripped)
        except json.JSONDecodeError:
            continue

    decoder = json.JSONDecoder()
    payload: Any = None
    for index, char in enumerate(text):
        if char not in "[{":
            continue
        try:
            candidate, end = decoder.raw_decode(text[index:])
        except json.JSONDecodeError:
            continue
        if text[index + end :].strip():
            continue
        payload = candidate
    return payload


def redact_snapshot_payload(payload: dict[str, Any]) -> dict[str, Any]:
    redacted = {key: value for key, value in payload.items() if key != "snapshot"}
    if "snapshot" in payload:
        redacted["snapshot"] = "<redacted>"
    return redacted


def run_text(command: list[str]) -> str:
    return run_text_result(command)["output"]


def run_text_result(command: list[str]) -> dict[str, Any]:
    completed = subprocess.run(command, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False)
    return {"returncode": completed.returncode, "output": completed.stdout or ""}


def docker_exec_container(command: str) -> str | None:
    try:
        parts = shlex.split(command)
    except ValueError:
        return None
    if len(parts) < 3 or parts[0] != "docker" or parts[1] != "exec":
        return None
    index = 2
    options_with_values = {"-e", "--env", "-u", "--user", "-w", "--workdir"}
    while index < len(parts) and parts[index].startswith("-"):
        option = parts[index]
        index += 1
        if option in options_with_values and index < len(parts):
            index += 1
    return parts[index] if index < len(parts) else None


def browser_url_is_expected(store: str, url: str) -> bool:
    expected = EXPECTED_BROWSER_HOSTS.get(store)
    if not expected:
        return False
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        return False
    host, port = expected
    actual_port = parsed.port or (443 if parsed.scheme == "https" else 80)
    return parsed.hostname == host and actual_port == port


def matching_lines(text: str, pattern: re.Pattern[str]) -> list[str]:
    return [line for line in text.splitlines() if pattern.search(line)]


def main() -> int:
    args = parse_args()
    gate = Gate(args)
    try:
        gate.preflight()
    except SystemExit:
        raise
    except Exception as exc:
        print(f"ERROR: preflight failed: {exc}", file=sys.stderr)
        return EXIT_USAGE
    return gate.run_all()


if __name__ == "__main__":
    raise SystemExit(main())
