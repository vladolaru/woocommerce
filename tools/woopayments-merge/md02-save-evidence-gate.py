#!/usr/bin/env python3
"""Produce mutation-safe MD-02 dispute draft evidence with isolated Playwright."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
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
EVIDENCE_TOOL = CRITICAL_FLOWS_DIR / "flows" / "md02-evidence.py"
STATE_DRIVER = CRITICAL_FLOWS_DIR / "flows" / "class-woopaymentscriticalflowsmd02driver.php"
SCENARIO = SELF_DIR / "md02-save-evidence.playwright.mjs"
PLAYWRIGHT_RUNNER = SELF_DIR / "playwright-script-runner.mjs"
EXIT_CLEANUP = 70
CONTEXT_KEY_RE = re.compile(r"[0-9a-f]{64}")
RUN_STAMP_RE = re.compile(r"[0-9]{8}T[0-9]{6}Z-[0-9]+")
RAW_BROWSER_FIELDS = {
    "schema",
    "store",
    "run_stamp",
    "runtime_owner",
    "phase",
    "identity",
    "facts",
    "functional_assertions",
    "ux_assertions",
    "request",
    "response",
    "failed_responses",
    "diagnostics",
    "console_errors",
    "page_errors",
    "screenshots",
    "errors",
    "blockers",
}

sys.path.insert(0, str(CRITICAL_FLOWS_DIR))
from evidence_context import (  # noqa: E402
    EvidenceContextError,
    capture_store_state,
    source_snapshot,
    validate_context,
    validate_local_wp_command,
)


def _load_evidence_module():
    spec = importlib.util.spec_from_file_location("md02_evidence", EVIDENCE_TOOL)
    if spec is None or spec.loader is None:
        raise RuntimeError("MD-02 evidence core could not be loaded")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


EVIDENCE = _load_evidence_module()


class GateBlocked(RuntimeError):
    """Infrastructure or ambiguity prevented authoritative evidence."""


class GateFailure(RuntimeError):
    """The observed MD-02 contract failed authoritatively."""


class GateSignal(GateBlocked):
    """The producer was interrupted but must still clean up."""

    def __init__(self, signum: int):
        super().__init__(f"interrupted by signal {signum}")
        self.signum = signum


class GateCleanupError(RuntimeError):
    """The exact caller-owned administrator session was not destroyed."""


def canonical(payload: Any) -> bytes:
    return json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")


def payload_digest(payload: dict[str, Any]) -> str:
    unsigned = dict(payload)
    unsigned.pop("payload_sha256", None)
    return "sha256:" + hashlib.sha256(canonical(unsigned)).hexdigest()


def file_digest(path: Path) -> str:
    return "sha256:" + hashlib.sha256(path.read_bytes()).hexdigest()


def context_key_hex() -> str:
    value = os.environ.get("CRITICAL_FLOWS_RUN_CONTEXT_KEY", "")
    if CONTEXT_KEY_RE.fullmatch(value) is None:
        raise GateBlocked("runner evidence context key is unavailable")
    return value


def lexical_absolute(value: str | os.PathLike[str]) -> Path:
    return Path(os.path.abspath(os.fspath(value)))


def has_symlink_component(path: Path) -> bool:
    lexical = path
    if sys.platform == "darwin" and len(path.parts) > 1 and path.parts[1] == "var":
        lexical = Path("/private") / Path(*path.parts[1:])
    return any(component.is_symlink() for component in (lexical, *lexical.parents))


def safe_json(path: Path, *, maximum: int = 2 * 1024 * 1024) -> dict[str, Any]:
    if not path.is_absolute():
        path = lexical_absolute(path)
    if ".." in path.parts or has_symlink_component(path) or not path.is_file():
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
    if parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in {"", "/"}:
        raise GateBlocked(f"{label} must be a bare local origin")
    return value.rstrip("/")


def parse_last_json(output: str, *, mode: str | None = None, schema: str | None = None) -> dict[str, Any]:
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
        if not isinstance(candidate, dict):
            continue
        if mode is not None and candidate.get("mode") != mode:
            continue
        if schema is not None and candidate.get("schema") != schema:
            continue
        candidates.append(candidate)
    if not candidates:
        raise GateBlocked("WP-CLI driver emitted no exact action envelope")
    return max(candidates, key=lambda candidate: len(canonical(candidate)))


def session_auth_cookies(session: dict[str, Any]) -> list[dict[str, str]]:
    raw = session.get("auth_cookies")
    schemes = ["auth", "secure_auth", "logged_in"]
    if not isinstance(raw, list) or len(raw) != len(schemes):
        raise GateBlocked("short-lived administrator cookie set is incomplete")
    cookies: list[dict[str, str]] = []
    names: set[str] = set()
    for scheme, cookie in zip(schemes, raw, strict=True):
        if (
            not isinstance(cookie, dict)
            or set(cookie) != {"scheme", "name", "value"}
            or cookie.get("scheme") != scheme
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


def run_state_driver(command: str, role: str, action: str, run_stamp: str, *arguments: str) -> dict[str, Any]:
    key = context_key_hex()
    driver = STATE_DRIVER.read_text(encoding="utf-8")
    injection = "<?php\nputenv('CRITICAL_FLOWS_RUN_CONTEXT_KEY=" + key + "');"
    source = driver.replace("<?php", injection, 1)
    completed = subprocess.run(
        [*wp_parts(command, role), "eval-file", "-", role, action, run_stamp, *arguments],
        input=source,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
        timeout=180,
    )
    if completed.returncode != 0:
        detail = completed.stderr.strip() or f"exit {completed.returncode}"
        raise GateBlocked(f"{role} state driver failed: {detail}")
    if action == "probe":
        return parse_last_json(completed.stdout, schema="woopayments_md02_state_raw.v1")
    return parse_last_json(completed.stdout, mode=action)


def _valid_identity(identity: Any) -> bool:
    return (
        isinstance(identity, dict)
        and set(identity) == {"order_id", "charge_id", "intent_id", "dispute_id"}
        and isinstance(identity.get("order_id"), int)
        and identity["order_id"] > 0
        and re.fullmatch(r"(?:ch|py)_[A-Za-z0-9_]+", str(identity.get("charge_id", ""))) is not None
        and re.fullmatch(r"pi_[A-Za-z0-9_]+", str(identity.get("intent_id", ""))) is not None
        and re.fullmatch(r"[A-Za-z]{2,4}_[A-Za-z0-9_]+", str(identity.get("dispute_id", ""))) is not None
    )


def validate_source_manifest(path: Path, store: str) -> tuple[dict[str, Any], dict[str, Any]]:
    manifest = safe_json(path)
    if (
        manifest.get("schema") != "woopayments_md01_manifest.v1"
        or manifest.get("store") != store
        or manifest.get("flow") != "MD-01-created-note-on-hold-notify"
        or manifest.get("status") != "pass"
        or manifest.get("exit_code") != 0
        or manifest.get("payload_sha256") != payload_digest(manifest)
    ):
        raise GateBlocked(f"{store} MD-01 source manifest is not a valid passing artifact")
    probe_path = path.parent / f"{store}-probe.json"
    probe = safe_json(probe_path)
    binding = manifest.get("files", {}).get(probe_path.name, {})
    if (
        not isinstance(binding, dict)
        or binding.get("sha256") != file_digest(probe_path)
        or binding.get("schema") != probe.get("schema")
        or binding.get("payload_sha256") != probe.get("payload_sha256")
        or probe.get("payload_sha256") != payload_digest(probe)
    ):
        raise GateBlocked(f"{store} MD-01 source probe digest binding is invalid")
    expected_owner = "plugin" if store == "ref" else "native"
    dispute = probe.get("facts", {}).get("dispute", {})
    if (
        probe.get("schema") != "woopayments_md01_normalized.v1"
        or probe.get("store") != store
        or probe.get("runtime_owner") != expected_owner
        or probe.get("run_stamp") != manifest.get("run_stamp")
        or probe.get("status") != "pass"
        or probe.get("blockers") != []
        or probe.get("errors") != []
        or not _valid_identity(probe.get("identity"))
        or dispute.get("status") != "needs_response"
        or not isinstance(dispute.get("due_by"), int)
        or dispute["due_by"] <= 0
    ):
        raise GateBlocked(f"{store} MD-01 source probe semantics are invalid")
    return manifest, probe


def normalize_state(raw: dict[str, Any], *, store: str, run_stamp: str, phase: str) -> dict[str, Any]:
    normalized = dict(raw)
    normalized["schema"] = "woopayments_md02_state.v1"
    EVIDENCE.validate_state(normalized, store=store, run_stamp=run_stamp, phase=phase)
    if normalized["blockers"]:
        raise GateBlocked(f"{store} {phase} state probe is blocked: {', '.join(normalized['blockers'])}")
    return normalized


def require_fresh_pre_state(pre: dict[str, Any]) -> None:
    if pre.get("evidence", {}).get("product_description") == EVIDENCE.DESCRIPTION:
        raise GateBlocked("exact dispute already contains the MD-02 description; mutation replay refused")


def validate_pre_state(pre: dict[str, Any], probe: dict[str, Any], *, minimum_due_by: int | None = None) -> None:
    if pre.get("identity") != probe.get("identity"):
        raise GateBlocked("live MD-02 fixture identity differs from its MD-01 source")
    lifecycle = pre.get("lifecycle", {})
    source_dispute = probe.get("facts", {}).get("dispute", {})
    deadline_floor = int(time.time()) + 60 if minimum_due_by is None else minimum_due_by
    if (
        lifecycle.get("status") != "needs_response"
        or lifecycle.get("due_by") != source_dispute.get("due_by")
        or not isinstance(lifecycle.get("due_by"), int)
        or isinstance(lifecycle.get("due_by"), bool)
        or lifecycle["due_by"] <= deadline_floor
        or lifecycle.get("past_due") is not False
        or lifecycle.get("submission_count") != 0
        or pre.get("evidence", {}).get("customer_name_present") is not True
        or pre.get("evidence", {}).get("customer_name_matches_order") is not True
    ):
        raise GateBlocked("live MD-02 pre-state is not a safe future unsubmitted exact fixture")
    require_fresh_pre_state(pre)


def trusted_request(browser: dict[str, Any], dispute_id: str) -> bool:
    request = browser.get("request", {})
    return bool(
        browser.get("phase") in {"request_seen", "response_seen", "reloaded"}
        and request.get("method") == "POST"
        and request.get("path") == f"/wp-json/wc/v3/payments/disputes/{dispute_id}"
        and request.get("submit_false") is True
        and request.get("description_matches") is True
        and request.get("customer_name_matches") is True
        and request.get("files_selected") == 0
    )


def assess_mutation_boundary(
    pre: dict[str, Any], browser: dict[str, Any], post: dict[str, Any], dispute_id: str
) -> str:
    require_fresh_pre_state(pre)
    before = pre.get("evidence", {}).get("product_description")
    after = post.get("evidence", {}).get("product_description")
    captured = trusted_request(browser, dispute_id)
    if captured:
        return "trusted_mutation" if after == EVIDENCE.DESCRIPTION else "trusted_mutation_mismatch"
    return "unchanged_safe_failure" if after == before else "ambiguous_mutation"


def screenshot_error(path: Path, parent: Path, started_ns: int) -> str | None:
    try:
        if has_symlink_component(path) or not path.is_file() or path.parent != parent:
            return "screenshot is missing, symlinked, or outside its archive"
        stat = path.stat()
        if stat.st_size < 16 or stat.st_size > 10 * 1024 * 1024 or stat.st_mtime_ns < started_ns:
            return "screenshot is stale or outside the bounded evidence range"
        if path.read_bytes()[:8] != b"\x89PNG\r\n\x1a\n":
            return "screenshot is not a PNG"
    except OSError:
        return "screenshot could not be inspected"
    return None


def normalize_browser(
    raw: dict[str, Any], *, store: str, run_stamp: str, identity: dict[str, Any], out_dir: Path, started_ns: int
) -> dict[str, Any]:
    if set(raw) != RAW_BROWSER_FIELDS or raw.get("schema") != "woopayments_md02_browser_raw.v1":
        raise GateBlocked("browser payload field set or schema is invalid")
    expected_owner = "plugin" if store == "ref" else "native"
    if (
        raw.get("store") != store
        or raw.get("run_stamp") != run_stamp
        or raw.get("runtime_owner") != expected_owner
        or raw.get("identity")
        != {
            "order_id": identity["order_id"],
            "charge_id": identity["charge_id"],
            "dispute_id": identity["dispute_id"],
        }
    ):
        raise GateBlocked("browser payload exact run or identity binding is invalid")
    for field in (
        "functional_assertions",
        "ux_assertions",
        "request",
        "response",
    ):
        if not isinstance(raw.get(field), dict):
            raise GateBlocked(f"browser {field} is malformed")
    for field in (
        "failed_responses",
        "diagnostics",
        "console_errors",
        "page_errors",
        "screenshots",
        "errors",
        "blockers",
    ):
        if not isinstance(raw.get(field), list):
            raise GateBlocked(f"browser {field} is malformed")
    screenshots: dict[str, str] = {}
    expected_names = {
        f"{store}-md02-form.png",
        f"{store}-md02-saved.png",
        f"{store}-md02-reloaded.png",
    }
    if set(raw["screenshots"]) == expected_names:
        for name in raw["screenshots"]:
            path = out_dir / name
            issue = screenshot_error(path, out_dir, started_ns)
            if issue:
                raw["errors"].append(f"screenshot_invalid:{name}:{issue}")
            else:
                screenshots[name] = file_digest(path)
    else:
        raw["errors"].append("screenshot_set_incomplete")
    return {
        **{key: value for key, value in raw.items() if key not in {"schema", "screenshots"}},
        "schema": "woopayments_md02_browser.v1",
        "screenshots": screenshots,
    }


def run_browser(config: dict[str, Any], raw_path: Path, log_path: Path) -> tuple[int, int]:
    env = os.environ.copy()
    env["PLAYWRIGHT_RUNNER_STATE_JSON"] = json.dumps(
        {"md02SaveEvidenceConfig": {**config, "evidencePath": str(raw_path), "screenshotDir": str(raw_path.parent)}},
        separators=(",", ":"),
    )
    started_ns = time.time_ns()
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
    secrets_in_use = [cookie.get("value", "") for cookie in config.get("authCookies", []) if isinstance(cookie, dict)]
    if any(secret and secret in output for secret in secrets_in_use):
        log_path.write_text("BLOCKED: browser output contained a secret and was redacted.\n", encoding="utf-8")
        raise GateBlocked("browser output retained a short-lived auth cookie")
    log_path.write_text(output[-1024 * 1024 :], encoding="utf-8")
    return completed.returncode, started_ns


def probe_state(
    wp: str,
    store: str,
    run_stamp: str,
    phase: str,
    identity: dict[str, Any],
    out_dir: Path,
) -> dict[str, Any]:
    raw = run_state_driver(
        wp,
        store,
        "probe",
        run_stamp,
        phase,
        str(identity["order_id"]),
        identity["charge_id"],
        identity["intent_id"],
        identity["dispute_id"],
    )
    normalized = normalize_state(raw, store=store, run_stamp=run_stamp, phase=phase)
    (out_dir / f"{store}-{phase}-state.json").write_text(
        json.dumps(normalized, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    return normalized


def synthetic_browser(store: str, run_stamp: str, identity: dict[str, Any], blocker: str) -> dict[str, Any]:
    return {
        "schema": "woopayments_md02_browser.v1",
        "store": store,
        "run_stamp": run_stamp,
        "runtime_owner": "plugin" if store == "ref" else "native",
        "phase": "unavailable",
        "identity": {
            "order_id": identity["order_id"],
            "charge_id": identity["charge_id"],
            "dispute_id": identity["dispute_id"],
        },
        "facts": {
            **{name: False for name in EVIDENCE.BROWSER_FACT_BOOLEAN_FIELDS},
            "requestMethod": "",
            "saveButtonLabel": "",
        },
        "functional_assertions": {name: False for name in EVIDENCE.FUNCTIONAL_ASSERTIONS},
        "ux_assertions": {name: False for name in EVIDENCE.UX_ASSERTIONS},
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
        "screenshots": {},
        "errors": [],
        "blockers": [blocker],
    }


def context_binding(context: dict[str, Any]) -> dict[str, str]:
    return {
        "aggregate_run_id": str(context["aggregate_run_id"]),
        "context_sha256": str(context["context_sha256"]),
    }


def run_store(
    *,
    store: str,
    wp: str,
    base_url: str,
    run_stamp: str,
    source_manifest_path: Path,
    source_probe: dict[str, Any],
    context: dict[str, Any],
    out_dir: Path,
    delay_seconds: int,
    repo_root: Path = REPO_ROOT,
) -> tuple[int, Path]:
    identity = source_probe["identity"]
    try:
        source_manifest_reference = source_manifest_path.relative_to(repo_root).as_posix()
    except ValueError as exc:
        raise GateBlocked("MD-02 source manifest must stay inside the local repository") from exc
    pre = probe_state(wp, store, run_stamp, phase="pre", identity=identity, out_dir=out_dir)
    validate_pre_state(pre, source_probe, minimum_due_by=int(time.time()) + delay_seconds + 60)

    raw_path = out_dir / f"{store}-browser.raw.json"
    log_path = out_dir / f"{store}-browser.log"
    session_token = secrets.token_hex(32)
    browser: dict[str, Any] = synthetic_browser(store, run_stamp, identity, "browser_not_started")
    browser_error: BaseException | None = None
    cleanup_failures: list[str] = []
    browser_rc = 3
    started_ns = 0
    try:
        session = run_state_driver(wp, store, "create-auth-session", run_stamp, "1", session_token, base_url)
        if session.get("success") is not True:
            raise GateBlocked(f"{store} short-lived administrator session is unavailable")
        auth_cookies = session_auth_cookies(session)
        config = {
            "store": store,
            "runStamp": run_stamp,
            "runtimeOwner": "plugin" if store == "ref" else "native",
            "baseUrl": base_url,
            "disputesPath": "/wp-admin/admin.php?page=wc-admin&path=/payments/disputes"
            if store == "ref"
            else "/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/disputes",
            "disputeId": identity["dispute_id"],
            "chargeId": identity["charge_id"],
            "orderId": identity["order_id"],
            "expectedDescription": EVIDENCE.DESCRIPTION,
            "expectedCustomerNameHmac": pre["evidence"]["customer_name_hmac"],
            "authCookies": auth_cookies,
        }
        browser_rc, started_ns = run_browser(config, raw_path, log_path)
        if not raw_path.is_file():
            raise GateBlocked(f"{store} Playwright runner emitted no incremental browser artifact")
        browser = normalize_browser(
            safe_json(raw_path),
            store=store,
            run_stamp=run_stamp,
            identity=identity,
            out_dir=out_dir,
            started_ns=started_ns,
        )
    except BaseException as exc:  # cleanup and post-state proof must still run.
        browser_error = exc
        if raw_path.is_file():
            try:
                browser = normalize_browser(
                    safe_json(raw_path),
                    store=store,
                    run_stamp=run_stamp,
                    identity=identity,
                    out_dir=out_dir,
                    started_ns=started_ns,
                )
            except BaseException as normalization_error:
                browser = synthetic_browser(store, run_stamp, identity, f"browser_artifact_invalid:{type(normalization_error).__name__}")
    finally:
        try:
            cleanup = run_state_driver(wp, store, "destroy-auth-session", run_stamp, "1", session_token)
            if cleanup.get("success") is not True or cleanup.get("destroyed") is not True:
                cleanup_failures.append(f"{store} exact short-lived administrator session was not destroyed")
        except BaseException as exc:
            cleanup_failures.append(f"{store} exact short-lived administrator session cleanup failed: {type(exc).__name__}")

    try:
        post = probe_state(wp, store, run_stamp, phase="post", identity=identity, out_dir=out_dir)
        if delay_seconds:
            time.sleep(delay_seconds)
        delayed = probe_state(wp, store, run_stamp, phase="delayed", identity=identity, out_dir=out_dir)
    except BaseException as exc:
        if cleanup_failures:
            raise GateCleanupError("; ".join(cleanup_failures)) from exc
        raise
    boundary = assess_mutation_boundary(pre, browser, post, identity["dispute_id"])
    assertions = EVIDENCE.derive_deterministic_assertions(pre, post, delayed, EVIDENCE.DESCRIPTION)
    trusted_boundary = boundary in {"trusted_mutation", "trusted_mutation_mismatch"}
    deterministic_status = "pass" if trusted_boundary and all(assertions.values()) else "fail" if trusted_boundary else "blocked"
    browser_status, browser_diagnostics = EVIDENCE.derive_browser_verdict(browser)
    if browser_rc != 0 and browser_status == "pass":
        browser["blockers"].append(f"browser_runner_exit_{browser_rc}")
        browser_status, browser_diagnostics = EVIDENCE.derive_browser_verdict(browser)
    if browser_error is not None and browser_status == "pass":
        browser["blockers"].append(f"browser_exception:{type(browser_error).__name__}")
        browser_status, browser_diagnostics = EVIDENCE.derive_browser_verdict(browser)
    overall = (
        "blocked"
        if not trusted_boundary or browser_status == "blocked" or cleanup_failures
        else "fail"
        if deterministic_status == "fail"
        or boundary == "trusted_mutation_mismatch"
        or browser_status in {"fail_functional", "fail_ux"}
        else "pass"
    )
    packet = EVIDENCE.seal(
        {
            "schema": "woopayments_md02_store_packet.v1",
            "status": overall,
            "store": store,
            "run_stamp": run_stamp,
            "runtime_owner": "plugin" if store == "ref" else "native",
            "source_manifest": {
                "path": source_manifest_reference,
                "sha256": file_digest(source_manifest_path),
                "historical_context_hmac": "unverifiable_without_original_run_key",
            },
            "context_binding": context_binding(context),
            "pre_state": pre,
            "post_state": post,
            "delayed_state": delayed,
            "mutation_boundary": boundary,
            "deterministic_assertions": assertions,
            "deterministic_status": deterministic_status,
            "browser": browser,
            "browser_status": browser_status,
            "diagnostics": browser_diagnostics,
            "cleanup_failures": cleanup_failures,
        },
        b"woopayments-md02-store-packet-context-v1\0",
    )
    EVIDENCE.validate_store_packet(packet, store=store, run_stamp=run_stamp)
    packet_path = out_dir / f"{store}-md02-store-packet.json"
    packet_path.write_text(json.dumps(packet, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    if cleanup_failures:
        return EXIT_CLEANUP, packet_path
    if overall == "fail":
        return 1, packet_path
    if overall == "blocked":
        return 3, packet_path
    return 0, packet_path


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=str(REPO_ROOT))
    parser.add_argument("--context-file", required=True)
    parser.add_argument("--source-manifest", required=True)
    parser.add_argument("--store", required=True, choices=("ref", "target"))
    parser.add_argument("--wp", required=True)
    parser.add_argument("--url", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--run-stamp", required=True)
    parser.add_argument("--delay-seconds", type=int, default=30)
    parser.add_argument("--browser-runner", default="playwright", choices=("playwright",))
    return parser.parse_args()


def run_gate_main() -> int:
    args = parse_args()
    repo = lexical_absolute(args.repo)
    context_path = lexical_absolute(args.context_file)
    source_manifest_path = lexical_absolute(args.source_manifest)
    out_dir = lexical_absolute(args.out_dir)
    if RUN_STAMP_RE.fullmatch(args.run_stamp) is None:
        raise GateBlocked("MD-02 run stamp is invalid")
    if args.delay_seconds < 0 or args.delay_seconds > 300:
        raise GateBlocked("MD-02 delayed-probe interval is unsafe")
    for dependency in (EVIDENCE_TOOL, STATE_DRIVER, SCENARIO, PLAYWRIGHT_RUNNER):
        if not dependency.is_file() or dependency.is_symlink():
            raise GateBlocked(f"required MD-02 dependency is unavailable: {dependency}")
    if has_symlink_component(out_dir):
        raise GateBlocked("MD-02 evidence path contains a symlink")
    if out_dir.exists() and any(out_dir.iterdir()):
        raise GateBlocked("MD-02 evidence directory must be empty")
    out_dir.mkdir(parents=True, exist_ok=True)
    if has_symlink_component(out_dir):
        raise GateBlocked("MD-02 evidence directory is unsafe")
    context = safe_json(context_path)
    validate_context(context)
    if source_snapshot(repo) != context["source"]:
        raise GateBlocked("source snapshot changed after the critical-flow context was created")
    expected_owner = "plugin" if args.store == "ref" else "native"
    if capture_store_state(args.wp, args.store, expected_owner) != context["stores"][args.store]:
        raise GateBlocked(f"{args.store} store/account identity changed before MD-02 capture")
    _, source_probe = validate_source_manifest(source_manifest_path, args.store)
    return_code, packet_path = run_store(
        store=args.store,
        wp=args.wp,
        base_url=local_url(args.url, f"{args.store} URL"),
        run_stamp=args.run_stamp,
        source_manifest_path=source_manifest_path,
        source_probe=source_probe,
        context=context,
        out_dir=out_dir,
        delay_seconds=args.delay_seconds,
        repo_root=repo,
    )
    print(f"MD-02 {args.store} gate: wrote {packet_path}")
    return return_code


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
