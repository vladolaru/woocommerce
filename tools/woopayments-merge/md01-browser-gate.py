#!/usr/bin/env python3
"""Produce exact-manifest-bound MD-01 evidence with isolated Playwright."""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import re
import secrets
import signal
import subprocess
import sys
import time
from pathlib import Path
from typing import Any
from urllib.parse import urlsplit


SELF_DIR = Path(__file__).resolve().parent
REPO_ROOT = SELF_DIR.parents[1]
CRITICAL_FLOWS_DIR = REPO_ROOT / "tools" / "woopayments-critical-flows"
EVIDENCE_TOOL = CRITICAL_FLOWS_DIR / "flows" / "md01-evidence.py"
STATE_DRIVER = CRITICAL_FLOWS_DIR / "flows" / "class-woopaymentscriticalflowsmd01driver.php"
SCENARIO = SELF_DIR / "md01-created.playwright.mjs"
PLAYWRIGHT_RUNNER = SELF_DIR / "playwright-script-runner.mjs"
EXIT_CLEANUP = 70
ASSERTIONS = {
    "authenticated_admin",
    "order_on_hold",
    "created_note",
    "note_reason_context",
    "note_response_due_context",
    "exact_dispute_row",
    "needs_response",
    "amount",
    "reason",
    "respond_action",
    "badge_count",
}
RAW_FIELDS = {
    "schema",
    "status",
    "store",
    "run_stamp",
    "runtime_owner",
    "deterministic_manifest_sha256",
    "identity",
    "assertions",
    "failed_responses",
    "console_errors",
    "page_errors",
    "screenshots",
    "errors",
    "blockers",
}
IDENTITY_FIELDS = {"order_id", "charge_id", "intent_id", "dispute_id"}
CONTEXT_KEY_RE = re.compile(r"[0-9a-f]{64}")
DIGEST_RE = re.compile(r"sha256:[0-9a-f]{64}")

sys.path.insert(0, str(CRITICAL_FLOWS_DIR))
from evidence_context import (  # noqa: E402
    EvidenceContextError,
    capture_store_state,
    source_snapshot,
    validate_context,
    validate_local_wp_command,
)


class GateBlocked(RuntimeError):
    """Infrastructure prevented authoritative evidence."""


class GateFailure(RuntimeError):
    """The observed browser contract failed."""


class GateSignal(GateBlocked):
    """The producer was interrupted but must still clean up."""

    def __init__(self, signum: int):
        super().__init__(f"interrupted by signal {signum}")
        self.signum = signum


class GateCleanupError(RuntimeError):
    """Exact caller-owned session cleanup failed."""

    def __init__(self, cleanup_failures: list[str], primary_error: BaseException | None):
        super().__init__("; ".join(cleanup_failures))
        self.cleanup_failures = cleanup_failures
        self.primary_error = primary_error


def canonical(payload: Any) -> bytes:
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def context_key() -> bytes:
    raw = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if CONTEXT_KEY_RE.fullmatch(raw) is None:
        raise GateBlocked("runner evidence context key is unavailable")
    return bytes.fromhex(raw)


def seal(payload: dict[str, Any], domain: bytes) -> dict[str, Any]:
    sealed = dict(payload)
    sealed["context_hmac"] = ""
    material = dict(sealed)
    material.pop("context_hmac", None)
    sealed["context_hmac"] = "hmac-sha256:" + hmac.new(context_key(), domain + canonical(material), hashlib.sha256).hexdigest()
    sealed["payload_sha256"] = payload_digest(sealed)
    return sealed


def lexical_absolute(value: str | os.PathLike[str]) -> Path:
    return Path(os.path.abspath(os.fspath(value)))


def has_symlink_component(path: Path) -> bool:
    lexical = path
    if sys.platform == "darwin" and len(path.parts) > 1 and path.parts[1] == "var":
        lexical = Path("/private") / Path(*path.parts[1:])
    return any(component.is_symlink() for component in (lexical, *lexical.parents))


def safe_json(path: Path, *, maximum: int = 2 * 1024 * 1024) -> dict[str, Any]:
    if not path.is_absolute() or ".." in path.parts or has_symlink_component(path) or not path.is_file():
        raise GateBlocked(f"unsafe or missing JSON artifact: {path}")
    stat = path.stat()
    if stat.st_size <= 0 or stat.st_size > maximum:
        raise GateBlocked(f"unbounded JSON artifact: {path.name}")
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise GateBlocked(f"unreadable JSON artifact: {path.name}") from exc
    if not isinstance(payload, dict):
        raise GateBlocked(f"JSON artifact is not an object: {path.name}")
    return payload


def local_url(value: str, label: str) -> str:
    parsed = urlsplit(value)
    host = parsed.hostname or ""
    if parsed.scheme not in {"http", "https"} or (
        host not in {"localhost", "127.0.0.1"} and not host.endswith(".localhost")
    ):
        raise GateBlocked(f"{label} must be a local HTTP(S) URL")
    if parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise GateBlocked(f"{label} must be a bare local origin")
    return value.rstrip("/")


def merchant_reason_label(reason: str) -> str:
    labels = {
        "fraudulent": "Transaction unauthorized",
    }
    try:
        return labels[reason]
    except KeyError as exc:
        raise GateBlocked("MD-01 dispute reason has no established merchant label") from exc


def screenshot_error(path: Path, parent: Path, started_ns: int) -> str | None:
    try:
        if has_symlink_component(path) or not path.is_file() or path.parent != parent:
            return "screenshot is missing, symlinked, or outside its archive"
        stat = path.stat()
        if stat.st_size < 16 or stat.st_size > 10 * 1024 * 1024:
            return "screenshot size is outside the bounded evidence range"
        if stat.st_mtime_ns < started_ns:
            return "screenshot predates the browser execution"
        if path.read_bytes()[:8] != b"\x89PNG\r\n\x1a\n":
            return "screenshot is not a PNG"
    except OSError:
        return "screenshot could not be inspected"
    return None


def browser_payload_errors(payload: dict[str, Any], expected: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if set(payload) != RAW_FIELDS or payload.get("schema") != "woopayments_md01_browser_raw.v1":
        return ["browser payload field set or schema is invalid"]
    for field in ("store", "run_stamp", "runtime_owner"):
        if payload.get(field) != expected[field]:
            errors.append(f"browser {field} binding is invalid")
    if payload.get("deterministic_manifest_sha256") != expected["manifest_sha256"]:
        errors.append("browser deterministic manifest binding is invalid")
    identity = payload.get("identity")
    expected_identity = {
        "order_id": expected["order_id"],
        "charge_id": expected["charge_id"],
        "intent_id": expected["intent_id"],
        "dispute_id": expected["dispute_id"],
    }
    if not isinstance(identity, dict) or set(identity) != IDENTITY_FIELDS or identity != expected_identity:
        errors.append("browser exact identity binding is invalid")
    assertions = payload.get("assertions")
    if not isinstance(assertions, dict) or set(assertions) != ASSERTIONS:
        errors.append("browser assertion field set is invalid")
    else:
        errors.extend(f"browser assertion failed: {name}" for name, passed in assertions.items() if passed is not True)
    for field in ("failed_responses", "console_errors", "page_errors", "errors", "blockers"):
        value = payload.get(field)
        if not isinstance(value, list):
            errors.append(f"browser {field} is malformed")
        elif value:
            errors.append(f"browser {field} is not clean")
    screenshots = payload.get("screenshots")
    expected_screenshots = {f"{expected['store']}-order.png", f"{expected['store']}-disputes.png"}
    if not isinstance(screenshots, list) or set(screenshots) != expected_screenshots or len(screenshots) != 2:
        errors.append("browser screenshot list is incomplete")
    if payload.get("status") != "pass" or errors:
        if payload.get("status") == "pass" and errors:
            errors.append("passing browser payload contradicts its evidence")
        elif payload.get("status") != "pass":
            errors.append("browser payload did not pass")
    forbidden = {"cookie", "auth_cookie", "password", "secret", "token_value"}
    if any(str(key).lower() in forbidden for key in payload):
        errors.append("browser payload retained a secret-bearing field")
    return errors


def parse_last_json(output: str, expected_mode: str) -> dict[str, Any]:
    if len(output.encode("utf-8")) > 2 * 1024 * 1024:
        raise GateBlocked("WP-CLI driver output exceeds the bounded evidence limit")
    decoder = json.JSONDecoder()
    candidates: list[dict[str, Any]] = []
    for index, character in enumerate(output):
        if character != "{":
            continue
        try:
            candidate, _ = decoder.raw_decode(output[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(candidate, dict) and candidate.get("mode") == expected_mode:
            candidates.append(candidate)
    if not candidates:
        raise GateBlocked("WP-CLI driver emitted no exact action envelope")
    return max(candidates, key=lambda candidate: len(canonical(candidate)))


def session_auth_cookies(session: dict[str, Any]) -> list[dict[str, str]]:
    raw = session.get("auth_cookies")
    expected_schemes = ["auth", "secure_auth", "logged_in"]
    if not isinstance(raw, list) or len(raw) != len(expected_schemes):
        raise GateBlocked("short-lived administrator cookie set is incomplete")
    cookies: list[dict[str, str]] = []
    names: set[str] = set()
    for expected_scheme, cookie in zip(expected_schemes, raw, strict=True):
        if (
            not isinstance(cookie, dict)
            or set(cookie) != {"scheme", "name", "value"}
            or cookie.get("scheme") != expected_scheme
            or not isinstance(cookie.get("name"), str)
            or not isinstance(cookie.get("value"), str)
            or not cookie["name"]
            or not cookie["value"]
            or cookie["name"] in names
        ):
            raise GateBlocked("short-lived administrator cookie set is invalid")
        names.add(cookie["name"])
        cookies.append({"name": cookie["name"], "value": cookie["value"]})
    return cookies


def wp_parts(command: str, role: str) -> list[str]:
    parts = validate_local_wp_command(command, role)
    if "--allow-root" not in parts:
        parts.append("--allow-root")
    if not any(part == "--user" or part.startswith("--user=") for part in parts):
        parts.append("--user=1")
    return parts


def run_state_driver(command: str, role: str, *arguments: str) -> dict[str, Any]:
    completed = subprocess.run(
        [*wp_parts(command, role), "eval-file", "-", *arguments],
        input=STATE_DRIVER.read_text(encoding="utf-8"),
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=180,
    )
    if completed.returncode != 0:
        detail = completed.stderr.strip() or f"exit {completed.returncode}"
        raise GateBlocked(f"{role} state driver failed: {detail}")
    expected_mode = str(arguments[1]) if len(arguments) > 1 else ""
    return parse_last_json(completed.stdout, expected_mode)


def validate_manifest(path: Path, store: str) -> tuple[dict[str, Any], dict[str, Any]]:
    manifest = safe_json(path)
    if manifest.get("store") != store or manifest.get("status") != "pass" or manifest.get("exit_code") != 0:
        raise GateBlocked(f"{store} deterministic manifest is not a passing current-run artifact")
    run_stamp = str(manifest.get("run_stamp") or "")
    run_scope = str(manifest.get("run_scope") or "")
    completed = subprocess.run(
        [
            sys.executable,
            str(EVIDENCE_TOOL),
            "validate-bound-manifest",
            "--manifest",
            str(path),
            "--store",
            store,
            "--status",
            "pass",
            "--exit-code",
            "0",
            "--run-stamp",
            run_stamp,
            "--run-scope",
            run_scope,
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=30,
        env=os.environ.copy(),
    )
    if completed.returncode != 0 or completed.stdout.strip() != file_digest(path):
        raise GateBlocked(f"{store} deterministic manifest failed semantic/context validation")
    probe_path = path.parent / f"{store}-probe.json"
    probe = safe_json(probe_path)
    manifest_binding = manifest.get("files", {}).get(probe_path.name, {})
    if manifest_binding.get("sha256") != file_digest(probe_path):
        raise GateBlocked(f"{store} normalized probe is not bound by its manifest")
    return manifest, probe


def store_expectations(
    store: str,
    base_url: str,
    manifest_path: Path,
    manifest: dict[str, Any],
    probe: dict[str, Any],
) -> dict[str, Any]:
    identity = probe["identity"]
    facts = probe["facts"]
    disputes_path = (
        "/wp-admin/admin.php?page=wc-admin&path=/payments/disputes"
        if store == "ref"
        else "/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/disputes"
    )
    return {
        "store": store,
        "run_stamp": manifest["run_stamp"],
        "runtime_owner": "plugin" if store == "ref" else "native",
        "manifest_sha256": file_digest(manifest_path),
        "base_url": base_url,
        "order_path": facts["order"]["edit_path"],
        "disputes_path": disputes_path,
        "order_id": identity["order_id"],
        "charge_id": identity["charge_id"],
        "intent_id": identity["intent_id"],
        "dispute_id": identity["dispute_id"],
        "amount_minor": facts["dispute"]["amount"],
        "currency": facts["order"]["currency"],
        "reason": facts["dispute"]["reason"],
        "reason_label": merchant_reason_label(facts["dispute"]["reason"]),
        "awaiting_response_count": facts["aggregates"]["awaiting_response_post"],
    }


def run_browser(config: dict[str, Any], raw_path: Path, log_path: Path) -> int:
    env = os.environ.copy()
    env["PLAYWRIGHT_RUNNER_STATE_JSON"] = json.dumps(
        {"md01CreatedConfig": {**config, "evidencePath": str(raw_path), "screenshotDir": str(raw_path.parent)}},
        separators=(",", ":"),
    )
    completed = subprocess.run(
        [str(PLAYWRIGHT_RUNNER), str(SCENARIO), "--timeout", "180000"],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
        timeout=210,
        env=env,
    )
    output = completed.stdout
    cookie_values = [
        cookie.get("value", "")
        for cookie in config.get("authCookies", [])
        if isinstance(cookie, dict)
    ]
    if any(cookie and cookie in output for cookie in cookie_values):
        log_path.write_text("BLOCKED: browser runner output contained a secret and was redacted.\n", encoding="utf-8")
        raise GateBlocked("browser runner output retained the short-lived auth cookie")
    log_path.write_text(output[-1024 * 1024 :], encoding="utf-8")
    return completed.returncode


def run_store(
    *,
    role: str,
    wp: str,
    expected: dict[str, Any],
    out_dir: Path,
) -> dict[str, Any]:
    raw_path = out_dir / f"{role}-browser.raw.json"
    sealed_path = out_dir / f"{role}-browser.json"
    log_path = out_dir / f"{role}-browser.log"
    session_token = secrets.token_hex(32)
    primary_error: BaseException | None = None
    cleanup_failures: list[str] = []
    result: dict[str, Any] | None = None
    started_ns = 0
    try:
        session = run_state_driver(
            wp,
            role,
            role,
            "create-auth-session",
            expected["run_stamp"],
            "1",
            session_token,
            expected["base_url"],
        )
        if session.get("success") is not True:
            raise GateBlocked(f"{role} short-lived administrator session is unavailable")
        auth_cookies = session_auth_cookies(session)
        config = {
            "store": role,
            "runStamp": expected["run_stamp"],
            "runtimeOwner": expected["runtime_owner"],
            "manifestSha256": expected["manifest_sha256"],
            "baseUrl": expected["base_url"],
            "orderPath": expected["order_path"],
            "disputesPath": expected["disputes_path"],
            "orderId": expected["order_id"],
            "chargeId": expected["charge_id"],
            "intentId": expected["intent_id"],
            "disputeId": expected["dispute_id"],
            "amountMinor": expected["amount_minor"],
            "currency": expected["currency"],
            "reasonLabel": expected["reason_label"],
            "awaitingResponseCount": expected["awaiting_response_count"],
            "authCookies": auth_cookies,
        }
        started_ns = time.time_ns()
        browser_rc = run_browser(config, raw_path, log_path)
        if not raw_path.is_file():
            raise GateBlocked(f"{role} Playwright runner emitted no browser artifact")
        raw = safe_json(raw_path)
        errors = browser_payload_errors(raw, expected)
        if browser_rc != 0 or errors:
            raise GateFailure(f"{role} browser contract failed: {', '.join(errors) or f'exit {browser_rc}'}")
        screenshot_bindings: dict[str, str] = {}
        for name in raw["screenshots"]:
            screenshot = out_dir / name
            issue = screenshot_error(screenshot, out_dir, started_ns)
            if issue:
                raise GateFailure(f"{role} {name}: {issue}")
            screenshot_bindings[name] = file_digest(screenshot)
        normalized = seal(
            {
                "schema": "woopayments_md01_browser.v1",
                **{field: raw[field] for field in RAW_FIELDS if field not in {"schema", "screenshots"}},
                "screenshots": screenshot_bindings,
                "raw_sha256": file_digest(raw_path),
            },
            b"woopayments-md01-browser-context-v1\0",
        )
        sealed_path.write_text(json.dumps(normalized, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        result = {
            "store": role,
            "status": "pass",
            "browser_path": str(sealed_path),
            "browser_sha256": file_digest(sealed_path),
            "log_path": str(log_path),
        }
    except Exception as exc:
        primary_error = exc
    finally:
        try:
            cleanup = run_state_driver(
                wp,
                role,
                role,
                "destroy-auth-session",
                expected["run_stamp"],
                "1",
                session_token,
            )
            if cleanup.get("success") is not True or cleanup.get("destroyed") is not True:
                cleanup_failures.append(f"{role} exact short-lived administrator session was not destroyed")
        except Exception as exc:
            cleanup_failures.append(f"{role} exact short-lived administrator session cleanup failed: {exc}")
    if cleanup_failures:
        raise GateCleanupError(cleanup_failures, primary_error)
    if primary_error is not None:
        raise primary_error
    if result is None:
        raise GateBlocked(f"{role} browser producer completed without a result")
    return result


def context_binding(context: dict[str, Any]) -> dict[str, str]:
    return {
        "aggregate_run_id": str(context["aggregate_run_id"]),
        "context_sha256": str(context["context_sha256"]),
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=str(REPO_ROOT))
    parser.add_argument("--context-file", required=True)
    parser.add_argument("--ref-manifest", required=True)
    parser.add_argument("--target-manifest", required=True)
    parser.add_argument("--ref-wp", required=True)
    parser.add_argument("--target-wp", required=True)
    parser.add_argument("--ref-url", required=True)
    parser.add_argument("--target-url", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--browser-runner", default="playwright", choices=("playwright",))
    return parser.parse_args()


def run_gate_main() -> int:
    args = parse_args()
    repo = lexical_absolute(args.repo)
    context_path = lexical_absolute(args.context_file)
    ref_manifest_path = lexical_absolute(args.ref_manifest)
    target_manifest_path = lexical_absolute(args.target_manifest)
    out_dir = lexical_absolute(args.out_dir)
    for dependency in (EVIDENCE_TOOL, STATE_DRIVER, SCENARIO, PLAYWRIGHT_RUNNER):
        if not dependency.is_file() or dependency.is_symlink():
            raise GateBlocked(f"required producer dependency is unavailable: {dependency}")
    if has_symlink_component(out_dir):
        raise GateBlocked("MD-01 browser evidence path contains a symlink")
    if out_dir.exists() and any(out_dir.iterdir()):
        raise GateBlocked("MD-01 browser evidence directory must be empty")
    out_dir.mkdir(parents=True, exist_ok=True)
    if has_symlink_component(out_dir):
        raise GateBlocked("MD-01 browser evidence directory is unsafe")
    context = safe_json(context_path)
    validate_context(context)
    if source_snapshot(repo) != context["source"]:
        raise GateBlocked("source snapshot changed after the critical-flow context was created")
    ref_url = local_url(args.ref_url, "reference URL")
    target_url = local_url(args.target_url, "target URL")
    if ref_url == target_url:
        raise GateBlocked("reference and target browser origins must be distinct")
    ref_manifest, ref_probe = validate_manifest(ref_manifest_path, "ref")
    target_manifest, target_probe = validate_manifest(target_manifest_path, "target")
    if ref_manifest["run_stamp"] != target_manifest["run_stamp"] or ref_manifest["run_scope"] != target_manifest["run_scope"]:
        raise GateBlocked("deterministic manifests belong to different runner invocations")
    expectations = {
        "ref": store_expectations("ref", ref_url, ref_manifest_path, ref_manifest, ref_probe),
        "target": store_expectations("target", target_url, target_manifest_path, target_manifest, target_probe),
    }
    for role, wp, owner in (("ref", args.ref_wp, "plugin"), ("target", args.target_wp, "native")):
        current_store = capture_store_state(wp, role, owner)
        if current_store != context["stores"][role]:
            raise GateBlocked(f"{role} store/account identity changed before browser capture")

    results: list[dict[str, Any]] = []
    failures: list[str] = []
    blockers: list[str] = []
    cleanup_failures: list[str] = []
    for role, wp in (("ref", args.ref_wp), ("target", args.target_wp)):
        try:
            results.append(run_store(role=role, wp=wp, expected=expectations[role], out_dir=out_dir))
        except GateCleanupError as exc:
            if isinstance(exc.primary_error, GateFailure):
                failures.append(str(exc.primary_error))
            elif exc.primary_error is not None:
                blockers.append(str(exc.primary_error))
            cleanup_failures.extend(exc.cleanup_failures)
            results.append({"store": role, "status": "fail", "browser_path": "", "browser_sha256": "", "log_path": ""})
            break
        except GateFailure as exc:
            failures.append(str(exc))
            results.append({"store": role, "status": "fail", "browser_path": "", "browser_sha256": "", "log_path": ""})
        except (GateBlocked, EvidenceContextError, OSError, subprocess.SubprocessError) as exc:
            blockers.append(str(exc))
            results.append({"store": role, "status": "blocked", "browser_path": "", "browser_sha256": "", "log_path": ""})
    status = "fail" if failures or cleanup_failures else "blocked" if blockers else "pass"
    rollup = seal(
        {
            "schema": "woopayments_md01_browser_gate.v1",
            "status": status,
            "run_stamp": ref_manifest["run_stamp"],
            "context_binding": context_binding(context),
            "deterministic_manifests": {
                "ref": {"path": str(ref_manifest_path), "sha256": file_digest(ref_manifest_path)},
                "target": {"path": str(target_manifest_path), "sha256": file_digest(target_manifest_path)},
            },
            "results": results,
            "failures": failures,
            "blockers": blockers,
            "cleanup_failures": cleanup_failures,
        },
        b"woopayments-md01-browser-gate-context-v1\0",
    )
    rollup_path = out_dir / "md01-browser-gate.json"
    rollup_path.write_text(json.dumps(rollup, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if cleanup_failures:
        return EXIT_CLEANUP
    if failures:
        return 1
    if blockers:
        return 3
    print(f"MD-01 browser gate: wrote passing Playwright evidence to {rollup_path}")
    return 0


def main() -> int:
    handled = (signal.SIGHUP, signal.SIGINT, signal.SIGTERM)
    previous = {signum: signal.getsignal(signum) for signum in handled}

    def handle_signal(signum: int, _frame: Any) -> None:
        for item in handled:
            signal.signal(item, signal.SIG_IGN)
        raise GateSignal(signum)

    for signum in handled:
        signal.signal(signum, handle_signal)
    try:
        return run_gate_main()
    finally:
        for signum, handler in previous.items():
            signal.signal(signum, handler)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except GateCleanupError as exc:
        print(f"CLEANUP FAILED: {exc}", file=sys.stderr)
        raise SystemExit(EXIT_CLEANUP)
    except GateFailure as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        raise SystemExit(1)
    except (GateBlocked, GateSignal, EvidenceContextError, OSError, subprocess.SubprocessError) as exc:
        print(f"BLOCKED: {exc}", file=sys.stderr)
        raise SystemExit(3)
